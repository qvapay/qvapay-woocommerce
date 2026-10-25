<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QvaPay_Webhook {

    const TIMESTAMP_TOLERANCE = 300;
    // Seconds a delivery waits for another one on the same order before asking QvaPay to retry
    const ORDER_LOCK_TIMEOUT = 10;

    public static function register_routes() {
        register_rest_route(
            'qvapay/v1',
            '/webhook',
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'handle' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public static function handle( WP_REST_Request $request ) {
        $raw       = $request->get_body();
        $signature = $request->get_header( 'x_qvapay_signature' );
        $timestamp = $request->get_header( 'x_qvapay_timestamp' );

        if ( empty( $raw ) || empty( $signature ) || empty( $timestamp ) ) {
            return self::reject( 'missing_headers', 401 );
        }

        $now  = time();
        $diff = abs( $now - (int) $timestamp );
        if ( $diff > self::TIMESTAMP_TOLERANCE ) {
            return self::reject( 'timestamp_out_of_range', 401 );
        }

        $settings = get_option( 'woocommerce_qvapay_settings', array() );
        $secret   = isset( $settings['app_secret'] ) ? $settings['app_secret'] : '';

        if ( empty( $secret ) ) {
            return self::reject( 'secret_not_configured', 503 );
        }

        if ( 0 !== strpos( $signature, 'sha256=' ) ) {
            return self::reject( 'bad_signature_format', 401 );
        }

        $expected = 'sha256=' . hash_hmac( 'sha256', $raw, $secret );
        if ( ! hash_equals( $expected, $signature ) ) {
            return self::reject( 'signature_mismatch', 401 );
        }

        $payload = json_decode( $raw, true );
        if ( ! is_array( $payload ) ) {
            return self::reject( 'invalid_json', 400 );
        }

        $uuid      = isset( $payload['transaction_uuid'] ) ? sanitize_text_field( $payload['transaction_uuid'] ) : '';
        $remote_id = isset( $payload['remote_id'] ) ? sanitize_text_field( $payload['remote_id'] ) : '';
        $status    = isset( $payload['status'] ) ? sanitize_text_field( $payload['status'] ) : '';
        $amount    = isset( $payload['amount'] ) ? sanitize_text_field( (string) $payload['amount'] ) : '';

        if ( '' === $uuid || '' === $remote_id || '' === $status ) {
            return self::reject( 'missing_payload_fields', 400 );
        }

        // One delivery at a time per order: overlapping webhooks (two invoices of the same
        // order, or a redelivery) must not interleave their "already paid?" checks with
        // each other's status changes
        $lock = self::lock_order( (int) $remote_id );
        if ( false === $lock ) {
            QvaPay_Logger::error( sprintf( 'order %s busy, webhook for %s deferred', $remote_id, $uuid ) );
            return self::reject( 'order_busy', 503 );
        }

        try {
            return self::apply_to_order( $uuid, $remote_id, $status, $amount );
        } finally {
            if ( null !== $lock ) {
                self::unlock_order( $lock );
            }
        }
    }

    // Runs under the order's lock, and the order is loaded here (not before), so it reflects
    // everything a concurrent delivery for it has already saved.
    private static function apply_to_order( $uuid, $remote_id, $status, $amount ) {
        $order = wc_get_order( (int) $remote_id );
        if ( ! $order ) {
            QvaPay_Logger::error( sprintf( 'webhook for unknown order %s (txn %s)', $remote_id, $uuid ) );
            return new WP_REST_Response( array( 'received' => true, 'order_found' => false ), 200 );
        }

        if ( $order->get_payment_method() !== 'qvapay' ) {
            return self::reject( 'wrong_payment_method', 400 );
        }

        $invoices    = $order->get_meta( QvaPay_Gateway::ORDER_META_INVOICES );
        $invoices    = is_array( $invoices ) ? $invoices : array();
        $stored_uuid = $order->get_meta( QvaPay_Gateway::ORDER_META_TXN_UUID );
        // Orders created before invoices were tracked only know their last uuid
        if ( $stored_uuid && ! array_key_exists( $stored_uuid, $invoices ) ) {
            $invoices[ $stored_uuid ] = null;
        }
        if ( $invoices && ! array_key_exists( $uuid, $invoices ) ) {
            QvaPay_Logger::error( sprintf( 'uuid mismatch order %s: issued=%s received=%s', $remote_id, implode( ',', array_keys( $invoices ) ), $uuid ) );
            return self::reject( 'uuid_mismatch', 400 );
        }

        if ( $order->is_paid() || in_array( $order->get_status(), array( 'processing', 'completed', 'refunded' ), true ) ) {
            $paid_with = $order->get_transaction_id();
            $reported  = $order->get_meta( QvaPay_Gateway::ORDER_META_EXTRA_PAYMENTS );
            $reported  = is_array( $reported ) ? $reported : array();
            // QvaPay can deliver the same webhook more than once: flag each extra payment once
            if ( 'paid' === $status && $paid_with && $paid_with !== $uuid && ! in_array( $uuid, $reported, true ) ) {
                $reported[] = $uuid;
                $order->update_meta_data( QvaPay_Gateway::ORDER_META_EXTRA_PAYMENTS, $reported );
                $order->save();
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: transaction UUID, 2: amount in USD, 3: UUID the order was already paid with */
                        __( 'QvaPay: se recibió otro pago (UUID: %1$s, %2$s USD) para este pedido, que ya estaba pagado con %3$s. Revisa si hay que devolverlo.', 'qvapay-for-woocommerce' ),
                        $uuid,
                        $amount,
                        $paid_with
                    )
                );
                QvaPay_Logger::error( sprintf( 'order %s already paid with %s, second payment %s', $remote_id, $paid_with, $uuid ) );
            }
            return new WP_REST_Response( array( 'received' => true, 'already_processed' => true ), 200 );
        }

        if ( 'paid' === $status ) {
            // An earlier invoice can be for less than the current total if the order changed since.
            // What we invoiced is the reference; an invoice issued before amounts were stored
            // (v1.0.0) falls back to the amount in the signed webhook, and with neither the
            // payment cannot be verified, so it is held instead of completing the order.
            if ( isset( $invoices[ $uuid ] ) ) {
                $invoiced = (float) $invoices[ $uuid ];
            } elseif ( is_numeric( $amount ) ) {
                $invoiced = (float) $amount;
            } else {
                $order->update_status(
                    'on-hold',
                    sprintf(
                        /* translators: %s: transaction UUID */
                        __( 'QvaPay: se pagó la factura %s, pero no se pudo verificar el monto pagado (el webhook no lo incluye). Revisa el pago en QvaPay antes de enviar el pedido.', 'qvapay-for-woocommerce' ),
                        $uuid
                    )
                );
                QvaPay_Logger::error( sprintf( 'order %s: invoice %s paid without a verifiable amount', $remote_id, $uuid ) );
                return new WP_REST_Response( array( 'received' => true, 'amount_unverified' => true ), 200 );
            }

            if ( round( $invoiced, 2 ) < round( (float) $order->get_total(), 2 ) ) {
                $order->update_status(
                    'on-hold',
                    sprintf(
                        /* translators: 1: transaction UUID, 2: invoiced amount in USD, 3: order total in USD */
                        __( 'QvaPay: se pagó la factura %1$s por %2$s USD, menos que el total actual del pedido (%3$s USD). Revísalo antes de enviarlo.', 'qvapay-for-woocommerce' ),
                        $uuid,
                        $invoiced,
                        $order->get_total()
                    )
                );
                QvaPay_Logger::error( sprintf( 'order %s: invoice %s paid %s USD, order total %s USD', $remote_id, $uuid, $invoiced, $order->get_total() ) );
                return new WP_REST_Response( array( 'received' => true, 'amount_short' => true ), 200 );
            }

            $order->payment_complete( $uuid );
            $order->add_order_note(
                sprintf(
                    /* translators: 1: transaction UUID, 2: amount in USD */
                    __( 'QvaPay: pago confirmado. UUID: %1$s. Monto: %2$s USD.', 'qvapay-for-woocommerce' ),
                    $uuid,
                    $amount
                )
            );

            QvaPay_Logger::log( sprintf( 'order %s marked paid (txn %s)', $remote_id, $uuid ) );

            return new WP_REST_Response( array( 'received' => true ), 200 );
        }

        QvaPay_Logger::log( sprintf( 'webhook status=%s for order %s ignored', $status, $remote_id ) );
        return new WP_REST_Response( array( 'received' => true, 'ignored_status' => $status ), 200 );
    }

    // MySQL/MariaDB named lock: released explicitly, or by the server if the PHP process dies
    // mid-request. Returns the lock name, false if another delivery held it past the timeout,
    // or null when the database offers no named locks (GET_LOCK yields NULL or an error, as
    // on some clusters and proxies): then the webhook runs unserialized rather than every
    // payment being refused.
    private static function lock_order( $order_id ) {
        global $wpdb;
        $name = self::order_lock_name( $order_id, $wpdb->dbname, $wpdb->prefix );
        $got  = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::ORDER_LOCK_TIMEOUT ) );
        if ( '1' === $got ) {
            return $name;
        }
        if ( '0' === $got ) {
            return false;
        }
        QvaPay_Logger::error( sprintf( 'named locks unavailable (%s), order %d processed unserialized', $wpdb->last_error ? $wpdb->last_error : 'GET_LOCK returned NULL', $order_id ) );
        return null;
    }

    // GET_LOCK names are server-wide, not per database: two stores on one MySQL server can
    // share the table prefix and the order ids, so the database is part of the name. Hashed
    // to stay well inside the 64-character limit.
    private static function order_lock_name( $order_id, $database, $prefix ) {
        return 'qvapay_' . substr( md5( $database . '|' . $prefix ), 0, 16 ) . '_' . (int) $order_id;
    }

    private static function unlock_order( $name ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
    }

    private static function reject( $code, $http_status ) {
        QvaPay_Logger::error( sprintf( 'webhook rejected: %s (HTTP %d)', $code, $http_status ) );
        return new WP_REST_Response( array( 'error' => $code ), $http_status );
    }
}

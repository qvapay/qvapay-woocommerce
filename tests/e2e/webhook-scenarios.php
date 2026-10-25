<?php
/**
 * E2E webhook scenarios. Run through tests/e2e/run.sh (wp eval-file inside the cli container).
 *
 * Orders are paid the way a store sees it: checkout runs the gateway's process_payment(),
 * "Pay for this order" runs WooCommerce's real WC_Form_Handler::pay_action(), and QvaPay's
 * webhook is POSTed over HTTP to /wp-json/qvapay/v1/webhook, signed as QvaPay signs it.
 */

// An Error, not an Exception: pay_action() catches Exception and would swallow it
class QvaPay_E2E_Redirect extends Error {}

// wp eval-file runs this file inside a function: shared state goes through $GLOBALS
$GLOBALS['qvapay_e2e_failures'] = array();

function qvapay_e2e_expect( $label, $actual, $expected ) {
    if ( $actual === $expected ) {
        WP_CLI::log( "   ok    {$label}: {$actual}" );
        return;
    }
    $GLOBALS['qvapay_e2e_failures'][] = $label;
    WP_CLI::log( "   FAIL  {$label}: got {$actual}, expected {$expected}" );
}

function qvapay_e2e_signed_body( $order_id, $uuid, $amount, $secret = null ) {
    $secret  = $secret ?? get_option( 'woocommerce_qvapay_settings' )['app_secret'];
    $payload = array(
        'transaction_uuid' => $uuid,
        'remote_id'        => (string) $order_id,
        'status'           => 'paid',
    );
    if ( null !== $amount ) {
        $payload['amount'] = $amount;
    }
    $raw = wp_json_encode( $payload );
    return array(
        'body'    => $raw,
        'headers' => array(
            'Content-Type'       => 'application/json',
            'x-qvapay-signature' => 'sha256=' . hash_hmac( 'sha256', $raw, $secret ),
            'x-qvapay-timestamp' => (string) time(),
        ),
    );
}

function qvapay_e2e_outcome( $code, $raw_body ) {
    $body = json_decode( $raw_body, true );
    $tag  = isset( $body['error'] ) ? $body['error'] : implode( ',', array_keys( array_filter( (array) $body ) ) );
    return $code . ' ' . $tag;
}

function qvapay_e2e_webhook( $order_id, $uuid, $amount, $secret = null, array $headers = array() ) {
    $req             = qvapay_e2e_signed_body( $order_id, $uuid, $amount, $secret );
    $req['headers'] += $headers;
    $res = wp_remote_post( 'http://wp/?rest_route=/qvapay/v1/webhook', array( 'timeout' => 20 ) + $req );
    if ( is_wp_error( $res ) ) {
        WP_CLI::error( 'webhook request failed: ' . $res->get_error_message() );
    }
    return qvapay_e2e_outcome( wp_remote_retrieve_response_code( $res ), wp_remote_retrieve_body( $res ) );
}

// Delivers several webhooks at the same time; $deliveries = [ [ order, uuid, amount, extra headers ], … ]
function qvapay_e2e_webhooks_in_parallel( array $deliveries ) {
    $requests = array();
    foreach ( $deliveries as $i => list( $order_id, $uuid, $amount, $headers ) ) {
        $req          = qvapay_e2e_signed_body( $order_id, $uuid, $amount );
        $requests[ $i ] = array(
            'url'     => 'http://wp/?rest_route=/qvapay/v1/webhook',
            'type'    => 'POST',
            'headers' => $req['headers'] + $headers,
            'data'    => $req['body'],
        );
    }
    $responses = WpOrg\Requests\Requests::request_multiple( $requests, array( 'timeout' => 30 ) );
    return array_map( function ( $res ) {
        return $res instanceof WpOrg\Requests\Response ? qvapay_e2e_outcome( $res->status_code, $res->body ) : 'ERR ' . $res->getMessage();
    }, $responses );
}

function qvapay_e2e_order( $total ) {
    $product = new WC_Product_Simple();
    $product->set_name( 'Widget' );
    $product->set_regular_price( $total );
    $product->save();

    $order = wc_create_order( array( 'customer_id' => $GLOBALS['qvapay_e2e_customer'] ) );
    $order->add_product( $product, 1 );
    $order->set_payment_method( 'qvapay' );
    $order->set_billing_email( 'buyer@example.test' );
    $order->calculate_totals();
    $order->save();
    return $order->get_id();
}

// An admin editing a pending order: adds a line and recalculates the total
function qvapay_e2e_raise_total( $order_id, $extra_price ) {
    $extra = new WC_Product_Simple();
    $extra->set_name( 'Extra' );
    $extra->set_regular_price( $extra_price );
    $extra->save();
    $order = wc_get_order( $order_id );
    $order->add_product( $extra, 1 );
    $order->calculate_totals();
    $order->save();
}

function qvapay_e2e_checkout( $order_id ) {
    WC()->payment_gateways()->payment_gateways()['qvapay']->process_payment( $order_id );
    return qvapay_e2e_last_invoice();
}

// The real handler behind the "Pay for this order" button; returns the invoice it issued
function qvapay_e2e_pay_for_order( $order_id ) {
    global $wp;
    wp_set_current_user( $GLOBALS['qvapay_e2e_customer'] );
    $order                          = wc_get_order( $order_id );
    $_GET['key']                    = $order->get_order_key();
    $_POST['woocommerce_pay']       = '1';
    $_POST['payment_method']        = 'qvapay';
    $_POST['woocommerce-pay-nonce'] = wp_create_nonce( 'woocommerce-pay' );
    $_REQUEST                       = array_merge( $_GET, $_POST );
    $wp->query_vars['order-pay']    = $order_id;

    $capture = function ( $location ) { throw new QvaPay_E2E_Redirect( $location ); };
    add_filter( 'wp_redirect', $capture, PHP_INT_MIN );
    $level    = ob_get_level();
    $redirect = '(none)';
    try {
        WC_Form_Handler::pay_action();
    } catch ( QvaPay_E2E_Redirect $e ) {
        $redirect = $e->getMessage();
    }
    remove_filter( 'wp_redirect', $capture, PHP_INT_MIN );
    while ( ob_get_level() > $level ) {
        ob_end_clean();
    }
    $_GET = $_POST = $_REQUEST = array();
    wp_set_current_user( 0 );

    $invoice = qvapay_e2e_last_invoice();
    qvapay_e2e_expect( '"Pay for this order" redirects to the new invoice', $redirect, 'https://www.qvapay.com/pay/' . $invoice['uuid'] );
    return $invoice;
}

function qvapay_e2e_last_invoice() {
    $invoices = get_option( 'qvapay_e2e_invoices', array() );
    return end( $invoices );
}

function qvapay_e2e_status( $order_id ) {
    // The webhook changed the order in the web container; drop this process's cached copy
    wp_cache_flush();
    $order = wc_get_order( $order_id );
    return $order->get_status() . ( $order->is_paid() ? ' (paid)' : '' );
}

function qvapay_e2e_notes( $order_id, $needle ) {
    wp_cache_flush();
    $count = 0;
    foreach ( wc_get_order_notes( array( 'order_id' => $order_id ) ) as $note ) {
        if ( false !== strpos( $note->content, $needle ) ) {
            $count++;
        }
    }
    return $count . ' note(s)';
}

WC()->frontend_includes();
WC()->session  = new WC_Session_Handler();
WC()->session->init();
WC()->customer = new WC_Customer( 0, true );
WC()->cart     = new WC_Cart();

$customer = wc_create_new_customer( 'buyer-' . wp_generate_password( 8, false ) . '@example.test', '', wp_generate_password() );
if ( is_wp_error( $customer ) ) {
    WP_CLI::error( $customer->get_error_message() );
}
$GLOBALS['qvapay_e2e_customer'] = $customer;

WP_CLI::log( '1. Customer pays the FIRST invoice after "Pay for this order" issued a second' );
$order = qvapay_e2e_order( '25.00' );
$a     = qvapay_e2e_checkout( $order );
qvapay_e2e_pay_for_order( $order );
qvapay_e2e_expect( 'webhook for invoice A', qvapay_e2e_webhook( $order, $a['uuid'], $a['amount'] ), '200 received' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'processing (paid)' );

WP_CLI::log( '2. Customer pays the latest invoice' );
$order = qvapay_e2e_order( '25.00' );
qvapay_e2e_checkout( $order );
$b = qvapay_e2e_pay_for_order( $order );
qvapay_e2e_expect( 'webhook for invoice B', qvapay_e2e_webhook( $order, $b['uuid'], $b['amount'] ), '200 received' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'processing (paid)' );

WP_CLI::log( '3. Customer pays both invoices' );
$order = qvapay_e2e_order( '25.00' );
$a     = qvapay_e2e_checkout( $order );
$b     = qvapay_e2e_pay_for_order( $order );
qvapay_e2e_expect( 'webhook for invoice A', qvapay_e2e_webhook( $order, $a['uuid'], $a['amount'] ), '200 received' );
qvapay_e2e_expect( 'webhook for invoice B', qvapay_e2e_webhook( $order, $b['uuid'], $b['amount'] ), '200 received,already_processed' );
qvapay_e2e_expect( 'webhook for invoice B, delivered again', qvapay_e2e_webhook( $order, $b['uuid'], $b['amount'] ), '200 received,already_processed' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'processing (paid)' );
qvapay_e2e_expect( 'refund-review note for invoice B', qvapay_e2e_notes( $order, $b['uuid'] . ', 25 USD) para este pedido, que ya estaba pagado con ' . $a['uuid'] ), '1 note(s)' );

WP_CLI::log( '4. Total raised from 25 to 40 between invoices; customer pays the 25 invoice' );
$order = qvapay_e2e_order( '25.00' );
$a     = qvapay_e2e_checkout( $order );
qvapay_e2e_raise_total( $order, '15.00' );
$b = qvapay_e2e_pay_for_order( $order );
qvapay_e2e_expect( 'webhook for the 25 invoice', qvapay_e2e_webhook( $order, $a['uuid'], $a['amount'] ), '200 received,amount_short' );
qvapay_e2e_expect( 'webhook for the 25 invoice, delivered again', qvapay_e2e_webhook( $order, $a['uuid'], $a['amount'] ), '200 received,amount_short' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'on-hold' );
qvapay_e2e_expect( 'note about the short invoice', qvapay_e2e_notes( $order, 'menos que el total actual del pedido (40.00 USD)' ), '1 note(s)' );
qvapay_e2e_expect( 'webhook for the 40 invoice', qvapay_e2e_webhook( $order, $b['uuid'], $b['amount'] ), '200 received' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'processing (paid)' );

WP_CLI::log( '5. Webhook with a uuid never issued for this order' );
$order = qvapay_e2e_order( '25.00' );
qvapay_e2e_checkout( $order );
qvapay_e2e_expect( 'webhook for a foreign uuid', qvapay_e2e_webhook( $order, 'ffffffff-0000-4000-8000-000000000999', 25 ), '400 uuid_mismatch' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'pending' );

// Orders still pending from v1.0.0 only have the single meta, written exactly like this
function qvapay_e2e_legacy_order( $uuid ) {
    $order_id = qvapay_e2e_order( '25.00' );
    $order    = wc_get_order( $order_id );
    $order->update_meta_data( '_qvapay_transaction_uuid', $uuid );
    $order->update_status( 'pending' );
    $order->save();
    return $order_id;
}

WP_CLI::log( '6. Order from v1.0.0 (only _qvapay_transaction_uuid) paid with its invoice' );
$order = qvapay_e2e_legacy_order( 'eeeeeeee-0000-4000-8000-000000000001' );
qvapay_e2e_expect( 'webhook for another uuid', qvapay_e2e_webhook( $order, 'eeeeeeee-0000-4000-8000-000000000002', 25 ), '400 uuid_mismatch' );
qvapay_e2e_expect( 'webhook for its uuid', qvapay_e2e_webhook( $order, 'eeeeeeee-0000-4000-8000-000000000001', 25 ), '200 received' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'processing (paid)' );

WP_CLI::log( '7. Order from v1.0.0, "Pay for this order" after upgrading, customer pays the pre-upgrade invoice' );
$order = qvapay_e2e_legacy_order( 'eeeeeeee-0000-4000-8000-000000000003' );
qvapay_e2e_pay_for_order( $order );
qvapay_e2e_expect( 'webhook for the pre-upgrade invoice', qvapay_e2e_webhook( $order, 'eeeeeeee-0000-4000-8000-000000000003', 25 ), '200 received' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'processing (paid)' );

// The legacy invoice's amount was never stored: only the signed webhook can vouch for it
WP_CLI::log( '8. Order from v1.0.0 for 25, raised to 40, "Pay for this order" after upgrading, customer pays the 25 pre-upgrade invoice' );
$order = qvapay_e2e_legacy_order( 'eeeeeeee-0000-4000-8000-000000000004' );
qvapay_e2e_raise_total( $order, '15.00' );
qvapay_e2e_pay_for_order( $order );
qvapay_e2e_expect( 'webhook for the 25 pre-upgrade invoice', qvapay_e2e_webhook( $order, 'eeeeeeee-0000-4000-8000-000000000004', 25 ), '200 received,amount_short' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'on-hold' );
qvapay_e2e_expect( 'note about the short invoice', qvapay_e2e_notes( $order, 'menos que el total actual del pedido (40.00 USD)' ), '1 note(s)' );

WP_CLI::log( '9. Order from v1.0.0, "Pay for this order" after upgrading, pre-upgrade invoice paid by a webhook without an amount' );
$order = qvapay_e2e_legacy_order( 'eeeeeeee-0000-4000-8000-000000000005' );
qvapay_e2e_pay_for_order( $order );
qvapay_e2e_expect( 'webhook without amount', qvapay_e2e_webhook( $order, 'eeeeeeee-0000-4000-8000-000000000005', null ), '200 received,amount_unverified' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'on-hold' );
qvapay_e2e_expect( 'note about the unverified amount', qvapay_e2e_notes( $order, 'no se pudo verificar el monto' ), '1 note(s)' );

WP_CLI::log( '10. Overlapping deliveries: the 25 invoice is mid-check when the 40 invoice completes the 40 order' );
$order = qvapay_e2e_order( '25.00' );
$a     = qvapay_e2e_checkout( $order );
qvapay_e2e_raise_total( $order, '15.00' );
$b = qvapay_e2e_pay_for_order( $order );
// A pauses 2.5 s right after its "already paid?" check; B starts 0.5 s later, inside that window
list( $short, $full ) = qvapay_e2e_webhooks_in_parallel( array(
    array( $order, $a['uuid'], $a['amount'], array( 'X-QvaPay-E2E-Amount-Delay' => '2500' ) ),
    array( $order, $b['uuid'], $b['amount'], array( 'X-QvaPay-E2E-Start-Delay' => '500' ) ),
) );
WP_CLI::log( "   (25 invoice: {$short}; 40 invoice: {$full})" );
qvapay_e2e_expect( 'order after both deliveries', qvapay_e2e_status( $order ), 'processing (paid)' );
qvapay_e2e_expect( 'paid with the 40 invoice', wc_get_order( $order )->get_transaction_id(), $b['uuid'] );

WP_CLI::log( '11. A delivery that waits longer than the order lock timeout is deferred with 503, and its retry succeeds' );
$order = qvapay_e2e_order( '25.00' );
$a     = qvapay_e2e_checkout( $order );
qvapay_e2e_raise_total( $order, '15.00' );
$b = qvapay_e2e_pay_for_order( $order );
// A holds the order for 12 s, past the 10 s lock timeout B is willing to wait
list( $short, $full ) = qvapay_e2e_webhooks_in_parallel( array(
    array( $order, $a['uuid'], $a['amount'], array( 'X-QvaPay-E2E-Amount-Delay' => '12000' ) ),
    array( $order, $b['uuid'], $b['amount'], array( 'X-QvaPay-E2E-Start-Delay' => '500' ) ),
) );
qvapay_e2e_expect( 'delivery holding the order', $short, '200 received,amount_short' );
qvapay_e2e_expect( 'delivery that timed out waiting', $full, '503 order_busy' );
qvapay_e2e_expect( 'QvaPay retry of the 40 invoice', qvapay_e2e_webhook( $order, $b['uuid'], $b['amount'] ), '200 received' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'processing (paid)' );

WP_CLI::log( '12. Another store on the same MySQL server (own database, same wp_ prefix) holds the lock for the same order id' );
$order = qvapay_e2e_order( '25.00' );
$a     = qvapay_e2e_checkout( $order );
// The name that store's copy of the plugin uses for this order id: same code, its own database
$name_for = new ReflectionMethod( 'QvaPay_Webhook', 'order_lock_name' );
$name_for->setAccessible( true );
global $wpdb;
$foreign_name = $name_for->invoke( null, $order, 'otherstore', $wpdb->prefix );
$foreign      = new mysqli( 'db', 'otherstore', 'otherstore', 'otherstore' );
$held         = $foreign->query( "SELECT GET_LOCK('" . $foreign->real_escape_string( $foreign_name ) . "', 0)" )->fetch_row()[0];
qvapay_e2e_expect( 'other store holds its lock', (string) $held, '1' );
qvapay_e2e_expect( 'webhook for this store', qvapay_e2e_webhook( $order, $a['uuid'], $a['amount'] ), '200 received' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'processing (paid)' );
$foreign->close();

WP_CLI::log( '13. Database without named locks (GET_LOCK returns NULL, or errors): payments still complete' );
foreach ( array( 'null', 'error' ) as $mode ) {
    $order = qvapay_e2e_order( '25.00' );
    $a     = qvapay_e2e_checkout( $order );
    qvapay_e2e_expect( "webhook, GET_LOCK {$mode}", qvapay_e2e_webhook( $order, $a['uuid'], $a['amount'], null, array( 'X-QvaPay-E2E-Named-Locks' => $mode ) ), '200 received' );
    qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'processing (paid)' );
}

WP_CLI::log( '14. Webhook signed with the wrong secret' );
$order = qvapay_e2e_order( '25.00' );
$a     = qvapay_e2e_checkout( $order );
qvapay_e2e_expect( 'forged webhook', qvapay_e2e_webhook( $order, $a['uuid'], $a['amount'], 'not-the-secret' ), '401 signature_mismatch' );
qvapay_e2e_expect( 'order', qvapay_e2e_status( $order ), 'pending' );

$failures = $GLOBALS['qvapay_e2e_failures'];
if ( $failures ) {
    WP_CLI::error( count( $failures ) . ' expectation(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'All webhook scenarios passed.' );

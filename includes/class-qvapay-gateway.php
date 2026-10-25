<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QvaPay_Gateway extends WC_Payment_Gateway {

    const ORDER_META_TXN_UUID = '_qvapay_transaction_uuid';
    // Every invoice issued for the order (uuid => amount). "Pay for this order" issues a
    // new one each time, and the customer may still pay an earlier one.
    const ORDER_META_INVOICES = '_qvapay_invoices';
    // Invoices paid after the order was already paid, already flagged with a note
    const ORDER_META_EXTRA_PAYMENTS = '_qvapay_extra_payments';
    const WEBHOOK_PATH        = '/wp-json/qvapay/v1/webhook';

    public function __construct() {
        $this->id                 = 'qvapay';
        $this->method_title       = __( 'QvaPay', 'qvapay-for-woocommerce' );
        $this->method_description = __( 'Acepta pagos en USD a través de QvaPay.', 'qvapay-for-woocommerce' );
        $this->has_fields         = false;
        $this->supports           = array( 'products' );
        $this->icon               = QVAPAY_WC_PLUGIN_URL . 'assets/images/icon.svg';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'QvaPay', 'qvapay-for-woocommerce' ) );
        $this->description = $this->get_option( 'description', __( 'Paga con tu balance de QvaPay.', 'qvapay-for-woocommerce' ) );
        $this->enabled     = $this->get_option( 'enabled', 'no' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'     => array(
                'title'   => __( 'Activar / Desactivar', 'qvapay-for-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Activar QvaPay como método de pago', 'qvapay-for-woocommerce' ),
                'default' => 'no',
            ),
            'title'       => array(
                'title'       => __( 'Título', 'qvapay-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Texto que verá el cliente en el checkout.', 'qvapay-for-woocommerce' ),
                'default'     => __( 'QvaPay', 'qvapay-for-woocommerce' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Descripción', 'qvapay-for-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Descripción mostrada bajo el título en el checkout.', 'qvapay-for-woocommerce' ),
                'default'     => __( 'Paga con tu balance de QvaPay.', 'qvapay-for-woocommerce' ),
            ),
            'app_id'      => array(
                'title'       => __( 'App ID', 'qvapay-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'UUID de tu app en https://www.qvapay.com/dev/app.', 'qvapay-for-woocommerce' ),
                'default'     => '',
            ),
            'app_secret'  => array(
                'title'       => __( 'App Secret', 'qvapay-for-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Clave secreta de tu app. Se usa también para verificar la firma del webhook.', 'qvapay-for-woocommerce' ),
                'default'     => '',
            ),
            'webhook_url_override' => array(
                'title'       => __( 'URL pública del webhook (override)', 'qvapay-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Opcional. Déjalo vacío para autodetectar a partir de la URL del sitio. Útil si tu WordPress está tras un proxy/túnel cuyo dominio público no coincide con la URL configurada (ej. Cloudflare Tunnel, ngrok). Acepta el dominio completo o sólo el host; el plugin añade /wp-json/qvapay/v1/webhook automáticamente.', 'qvapay-for-woocommerce' ),
                'default'     => '',
                'placeholder' => 'https://mi-tienda.trycloudflare.com',
            ),
            'webhook_url' => array(
                'title'             => __( 'URL de webhook efectiva', 'qvapay-for-woocommerce' ),
                'type'              => 'text',
                'description'       => __( 'Esta es la URL que se enviará a QvaPay y la que debes copiar al campo "Callback URL" de tu app. Se recalcula a partir del override (si está) o de la URL del sitio.', 'qvapay-for-woocommerce' ),
                'default'           => '',
                'custom_attributes' => array( 'readonly' => 'readonly' ),
            ),
            'debug'       => array(
                'title'   => __( 'Modo debug', 'qvapay-for-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Registrar peticiones y webhooks en WooCommerce → Status → Logs (source: qvapay).', 'qvapay-for-woocommerce' ),
                'default' => 'no',
            ),
        );

        $this->form_fields['webhook_url']['default'] = $this->get_webhook_url();
    }

    public function get_webhook_url() {
        $override = trim( (string) $this->get_option( 'webhook_url_override', '' ) );

        if ( '' === $override ) {
            return rest_url( 'qvapay/v1/webhook' );
        }

        return self::normalize_webhook_url( $override );
    }

    public static function normalize_webhook_url( $value ) {
        $value = trim( (string) $value );

        if ( '' === $value ) {
            return '';
        }

        if ( ! preg_match( '#^https?://#i', $value ) ) {
            $value = 'https://' . $value;
        }

        $value = rtrim( $value, '/' );

        if ( substr( $value, -strlen( self::WEBHOOK_PATH ) ) !== self::WEBHOOK_PATH ) {
            $value .= self::WEBHOOK_PATH;
        }

        return $value;
    }

    public function validate_webhook_url_field( $key, $value ) {
        // The effective URL is always derived from the override + site URL;
        // ignore anything the browser submits for the readonly display field.
        return $this->get_webhook_url();
    }

    public function admin_options() {
        parent::admin_options();

        if ( 'USD' !== get_woocommerce_currency() ) {
            echo '<div class="notice notice-warning inline"><p>';
            echo esc_html__( 'QvaPay solo opera en USD. La pasarela no estará disponible en el checkout mientras la moneda de la tienda no sea USD.', 'qvapay-for-woocommerce' );
            echo '</p></div>';
        }

        if ( ! is_ssl() ) {
            echo '<div class="notice notice-warning inline"><p>';
            echo esc_html__( 'Tu sitio no está servido por HTTPS. QvaPay no podrá entregar webhooks correctamente sin SSL.', 'qvapay-for-woocommerce' );
            echo '</p></div>';
        }
    }

    public function is_available() {
        if ( 'yes' !== $this->enabled ) {
            return false;
        }

        if ( 'USD' !== get_woocommerce_currency() ) {
            return false;
        }

        if ( empty( $this->get_option( 'app_id' ) ) || empty( $this->get_option( 'app_secret' ) ) ) {
            return false;
        }

        return parent::is_available();
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Pedido no encontrado.', 'qvapay-for-woocommerce' ), 'error' );
            return array( 'result' => 'fail' );
        }

        $amount = (float) $order->get_total();

        if ( $amount <= 0 || $amount > 100000 ) {
            wc_add_notice( __( 'El total del pedido está fuera del rango aceptado por QvaPay (0 - 100.000 USD).', 'qvapay-for-woocommerce' ), 'error' );
            return array( 'result' => 'fail' );
        }

        $description = sprintf(
            /* translators: 1: order number, 2: site name */
            __( 'Pedido #%1$s — %2$s', 'qvapay-for-woocommerce' ),
            $order->get_order_number(),
            get_bloginfo( 'name' )
        );
        $description = mb_substr( $description, 0, 255 );

        $payload = array(
            'amount'      => $amount,
            'description' => $description,
            'remote_id'   => (string) $order->get_id(),
            'webhook'     => $this->get_webhook_url(),
            'products'    => $this->build_products( $order ),
        );

        $credentials = array(
            'app_id'     => $this->get_option( 'app_id' ),
            'app_secret' => $this->get_option( 'app_secret' ),
        );

        $response = QvaPay_Api_Client::create_invoice( $payload, $credentials );

        if ( is_wp_error( $response ) ) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: error message */
                    __( 'No se pudo crear la factura en QvaPay: %s', 'qvapay-for-woocommerce' ),
                    $response->get_error_message()
                ),
                'error'
            );
            return array( 'result' => 'fail' );
        }

        if ( empty( $response['url'] ) || empty( $response['transaction_uuid'] ) ) {
            wc_add_notice( __( 'Respuesta inesperada de QvaPay. Inténtalo de nuevo.', 'qvapay-for-woocommerce' ), 'error' );
            QvaPay_Logger::error( array( 'unexpected_response' => $response ) );
            return array( 'result' => 'fail' );
        }

        $uuid     = sanitize_text_field( $response['transaction_uuid'] );
        $invoices = $order->get_meta( self::ORDER_META_INVOICES );
        $invoices = is_array( $invoices ) ? $invoices : array();
        // An invoice issued before the map existed lives only in the single field we are
        // about to overwrite: carry it over (amount unknown) so it stays payable
        $previous = $order->get_meta( self::ORDER_META_TXN_UUID );
        if ( $previous && ! array_key_exists( $previous, $invoices ) ) {
            $invoices[ $previous ] = null;
        }

        $invoices[ $uuid ] = $amount;
        $order->update_meta_data( self::ORDER_META_INVOICES, $invoices );
        $order->update_meta_data( self::ORDER_META_TXN_UUID, $uuid );
        $order->update_status(
            'pending',
            sprintf(
                /* translators: %s: transaction UUID */
                __( 'Factura QvaPay creada: %s', 'qvapay-for-woocommerce' ),
                $response['transaction_uuid']
            )
        );
        $order->save();

        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => esc_url_raw( $response['url'] ),
        );
    }

    private function build_products( WC_Order $order ) {
        $products = array();

        foreach ( $order->get_items() as $item ) {
            $name = preg_replace( '/[<>\'"();]/', '', (string) $item->get_name() );
            $name = trim( $name );

            if ( '' === $name ) {
                $name = 'Item';
            }

            $products[] = array(
                'name'     => mb_substr( $name, 0, 150 ),
                'price'    => (float) $order->get_item_subtotal( $item, true ),
                'quantity' => (int) $item->get_quantity(),
            );
        }

        return $products;
    }
}

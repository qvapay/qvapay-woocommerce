<?php
/**
 * E2E ONLY (never shipped): answers QvaPay's create_invoice locally so the real gateway
 * code runs without network access. Each call returns a fresh uuid and is recorded in
 * the `qvapay_e2e_invoices` option for the scenarios to read.
 */
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    if ( false === strpos( $url, 'qvapay.com/api/v2/create_invoice' ) ) {
        return $pre;
    }
    $seq = (int) get_option( 'qvapay_e2e_invoice_seq', 0 ) + 1;
    update_option( 'qvapay_e2e_invoice_seq', $seq );

    $uuid     = sprintf( '00000000-0000-4000-8000-%012d', $seq );
    $body     = json_decode( $args['body'], true );
    $invoices = get_option( 'qvapay_e2e_invoices', array() );
    $invoices[] = array( 'uuid' => $uuid, 'amount' => $body['amount'], 'remote_id' => $body['remote_id'] );
    update_option( 'qvapay_e2e_invoices', $invoices );

    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( array( 'transaction_uuid' => $uuid, 'url' => 'https://www.qvapay.com/pay/' . $uuid ) ),
        'response' => array( 'code' => 200, 'message' => 'OK' ),
        'cookies'  => array(),
        'filename' => null,
    );
}, 10, 3 );

/*
 * E2E only: lets a scenario overlap two webhook deliveries at a precise point.
 *  - X-QvaPay-E2E-Start-Delay: <ms>  pauses the request before the webhook handler runs.
 *  - X-QvaPay-E2E-Amount-Delay: <ms> pauses it the first time the order total is read,
 *    which in the handler is the amount check, after the "already paid?" check.
 */
add_filter( 'rest_pre_dispatch', function ( $result ) {
    if ( isset( $_SERVER['HTTP_X_QVAPAY_E2E_START_DELAY'] ) ) {
        usleep( 1000 * (int) $_SERVER['HTTP_X_QVAPAY_E2E_START_DELAY'] );
    }
    return $result;
} );
add_filter( 'woocommerce_order_get_total', function ( $total ) {
    static $paused = false;
    if ( ! $paused && isset( $_SERVER['HTTP_X_QVAPAY_E2E_AMOUNT_DELAY'] ) ) {
        $paused = true;
        usleep( 1000 * (int) $_SERVER['HTTP_X_QVAPAY_E2E_AMOUNT_DELAY'] );
    }
    return $total;
} );

/*
 * E2E only: X-QvaPay-E2E-Named-Locks: null|error makes GET_LOCK behave like a database
 * without named locks (some clusters and proxies): it returns NULL, or the query fails.
 */
add_filter( 'query', function ( $query ) {
    $mode = isset( $_SERVER['HTTP_X_QVAPAY_E2E_NAMED_LOCKS'] ) ? $_SERVER['HTTP_X_QVAPAY_E2E_NAMED_LOCKS'] : '';
    if ( '' === $mode || 0 !== strpos( ltrim( $query ), 'SELECT GET_LOCK(' ) ) {
        return $query;
    }
    return 'error' === $mode ? 'SELECT GET_LOCK_UNSUPPORTED()' : 'SELECT NULL';
} );

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One Grow payment method at checkout (grow_card, grow_bit, grow_applepay,
 * grow_googlepay). Settings live in Qwoo_Grow (the dashboard edits them),
 * not in WooCommerce's gateway settings. Loaded when WooCommerce builds its
 * list of payment methods.
 */
class Qwoo_Grow_Gateway extends WC_Payment_Gateway {

    /** card | bit | applepay | googlepay */
    public $grow_key;

    public function __construct( $key ) {
        $this->grow_key           = $key;
        $this->id                 = 'grow_' . $key;
        $this->method_title       = 'Grow: ' . Qwoo_Grow::METHODS[ $key ][1];
        $this->method_description = 'Payments through Grow (Meshulam). Set up in the YallaFlow dashboard: Settings → Payments.';
        $this->has_fields         = false;
        $this->supports           = [ 'products', 'refunds' ];

        $m                 = Qwoo_Grow::method( $key );
        $this->enabled     = $m['enabled'] ? 'yes' : 'no';
        $this->title       = $m['title'];
        $this->description = '';
    }

    /** Shown at checkout only when it can take the payment. */
    public function is_available() {
        if ( ! Qwoo_Grow::ready( $this->grow_key ) || Qwoo_Grow::storefront() === '' ) {
            return false;
        }
        if ( $this->grow_key === 'bit' && function_exists( 'WC' ) && WC()->cart && (float) WC()->cart->get_total( 'edit' ) > Qwoo_Grow::BIT_MAX ) {
            return false;
        }
        return parent::is_available();
    }

    /** Opens Grow's payment page; the Store API hands its address to the storefront (payment_result.redirect_url). */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return [ 'result' => 'failure' ];
        }
        $url = Qwoo_Grow::start_payment( $order, $this->grow_key );
        if ( is_wp_error( $url ) ) {
            wc_add_notice( $url->get_error_message(), 'error' );
            return [ 'result' => 'failure' ];
        }
        return [ 'result' => 'success', 'redirect' => $url ];
    }

    public function can_refund_order( $order ) {
        return $order && $order->get_meta( '_qwoo_grow_tx_id' ) !== '' && $order->get_meta( '_qwoo_grow_tx_token' ) !== '';
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $amount === null || (float) $amount <= 0 ) {
            return new WP_Error( 'qwoo_grow_refund', 'Enter the amount to refund.' );
        }
        return Qwoo_Grow::refund( $order, $amount );
    }
}

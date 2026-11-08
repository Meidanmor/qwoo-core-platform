<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One Cardcom payment method at checkout (cardcom_card, cardcom_bit,
 * cardcom_applepay, cardcom_googlepay). Settings live in Qwoo_Cardcom (the
 * dashboard edits them), not in WooCommerce's gateway settings.
 */
class Qwoo_Cardcom_Gateway extends WC_Payment_Gateway {

    /** card | bit | applepay | googlepay */
    public $cardcom_key;

    public function __construct( $key ) {
        $this->cardcom_key        = $key;
        $this->id                 = 'cardcom_' . $key;
        $this->method_title       = 'Cardcom: ' . Qwoo_Cardcom::METHODS[ $key ];
        $this->method_description = 'Payments through Cardcom. Set up in the YallaFlow dashboard: Settings → Payments.';
        $this->has_fields         = false;
        $this->supports           = [ 'products', 'refunds' ];

        $m                 = Qwoo_Cardcom::method( $key );
        $this->enabled     = $m['enabled'] ? 'yes' : 'no';
        $this->title       = $m['title'];
        $this->description = '';
    }

    /** Shown at checkout only when it can take the payment. */
    public function is_available() {
        if ( ! Qwoo_Cardcom::ready( $this->cardcom_key ) || Qwoo_Cardcom::storefront() === '' ) {
            return false;
        }
        return parent::is_available();
    }

    /** Opens Cardcom's payment page; the Store API hands its address to the storefront (payment_result.redirect_url). */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return [ 'result' => 'failure' ];
        }
        $url = Qwoo_Cardcom::start_payment( $order, $this->cardcom_key );
        if ( is_wp_error( $url ) ) {
            wc_add_notice( $url->get_error_message(), 'error' );
            return [ 'result' => 'failure' ];
        }
        return [ 'result' => 'success', 'redirect' => $url ];
    }

    public function can_refund_order( $order ) {
        return $order && (int) $order->get_meta( '_qwoo_cardcom_tx_id' ) > 0;
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $amount === null || (float) $amount <= 0 ) {
            return new WP_Error( 'qwoo_cardcom_refund', 'Enter the amount to refund.' );
        }
        return Qwoo_Cardcom::refund( $order, $amount );
    }
}

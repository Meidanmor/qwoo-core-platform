<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( class_exists( 'Qwoo_HFD_Pickup_Method' ) || ! class_exists( 'WC_Shipping_Method' ) ) {
    return;
}

/**
 * "Pickup point (HFD)": the owner adds it to a shipping zone like the other
 * methods (Settings → Shipping). At checkout the shopper picks one of HFD's
 * ePost points in their city (Qwoo_Couriers::checkout_point checks it); the
 * point goes to HFD as pudoCodeDestination when the order is sent.
 * Offered only while HFD is connected (Settings → Couriers).
 */
class Qwoo_HFD_Pickup_Method extends WC_Shipping_Method {

    public function __construct( $instance_id = 0 ) {
        $this->id                 = Qwoo_Couriers::METHOD;
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = Qwoo_Couriers::l( 'Pickup point (HFD)', 'נקודת איסוף (HFD)' );
        $this->method_description = Qwoo_Couriers::l( 'Customers choose an HFD ePost pickup point at checkout.', 'הלקוחות בוחרים נקודת איסוף של HFD בקופה.' );
        $this->supports           = [ 'shipping-zones', 'instance-settings', 'instance-settings-modal' ];
        $this->instance_form_fields = [
            'title' => [
                'title'   => 'Title',
                'type'    => 'text',
                'default' => Qwoo_Couriers::l( 'Pickup point', 'איסוף מנקודה קרובה' ),
            ],
            'cost'  => [
                'title'   => 'Cost',
                'type'    => 'text',
                'default' => '0',
            ],
        ];
        $this->init_settings();
        $this->title = $this->get_option( 'title', Qwoo_Couriers::l( 'Pickup point', 'איסוף מנקודה קרובה' ) );
    }

    public function is_available( $package ) {
        return Qwoo_Couriers::connected( 'hfd' ) && parent::is_available( $package );
    }

    public function calculate_shipping( $package = [] ) {
        $cost = (string) $this->get_option( 'cost', '0' );
        $cost = is_numeric( $cost ) ? max( 0, (float) $cost ) : 0;
        $this->add_rate( [
            'id'      => $this->get_rate_id(),
            'label'   => $this->title,
            'cost'    => $cost,
            'package' => $package,
        ] );
    }
}

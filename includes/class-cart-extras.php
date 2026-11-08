<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

/**
 * What the storefront's cart answers carry for the Store builder's cart blocks
 * (Store API cart: extensions.qwoo):
 *
 *   free_shipping { min, total, before_discount } — for the Free Shipping Bar:
 *     min    the lowest free shipping minimum of the shopper's shipping zone
 *            (Settings → Shipping), in the smallest currency unit like the
 *            Store API's prices; "0" when free shipping needs no minimum; null
 *            when the zone has no free shipping by amount (only by coupon, or none).
 *     total  what WooCommerce compares with it: the cart's shown subtotal, less
 *            discounts unless the method counts before them.
 */
class Qwoo_Cart_Extras {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( CartSchema::class ) ) {
            return;
        }
        woocommerce_store_api_register_endpoint_data( [
            'endpoint'        => CartSchema::IDENTIFIER,
            'namespace'       => 'qwoo',
            'data_callback'   => [ __CLASS__, 'data' ],
            'schema_callback' => static fn() => [
                'free_shipping' => [
                    'description' => 'The free shipping minimum of the shopper\'s zone and the cart amount it is compared with (smallest currency unit).',
                    'type'        => [ 'object', 'null' ],
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
            ],
        ] );
    }

    public static function data() {
        return [ 'free_shipping' => self::free_shipping() ];
    }

    private static function free_shipping() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! class_exists( 'WC_Shipping_Zones' ) ) {
            return null;
        }
        try {
            $packages = WC()->cart->get_shipping_packages();
            $package  = is_array( $packages ) && $packages ? reset( $packages ) : [ 'destination' => [] ];
            $zone     = WC_Shipping_Zones::get_zone_matching_package( $package );
        } catch ( Throwable $e ) {
            return null;
        }
        $min    = null;
        $before = false;
        foreach ( $zone->get_shipping_methods( true ) as $method ) {
            if ( ! $method instanceof WC_Shipping_Free_Shipping ) continue;
            $requires = (string) $method->get_option( 'requires', '' );
            if ( $requires === 'coupon' ) continue; // Only with a code: no amount to reach.
            $amount = $requires === '' ? 0.0 : (float) wc_format_decimal( $method->get_option( 'min_amount', 0 ) );
            if ( $requires !== '' && $amount <= 0 ) continue;
            if ( $min === null || $amount < $min ) {
                $min    = $amount;
                $before = $method->get_option( 'ignore_discounts', 'no' ) === 'yes';
            }
        }
        if ( $min === null ) return null;

        // WC_Shipping_Free_Shipping::is_available(): the shown subtotal, less discounts unless ignored.
        $cart  = WC()->cart;
        $total = (float) $cart->get_displayed_subtotal();
        if ( ! $before ) {
            $total -= (float) $cart->get_discount_total();
            if ( $cart->display_prices_including_tax() ) $total -= (float) $cart->get_discount_tax();
        }
        $unit = static fn( $v ) => (string) (int) round( max( 0, (float) $v ) * ( 10 ** wc_get_price_decimals() ) );
        return [ 'min' => $unit( $min ), 'total' => $unit( $total ), 'before_discount' => $before ];
    }
}

Qwoo_Cart_Extras::init();

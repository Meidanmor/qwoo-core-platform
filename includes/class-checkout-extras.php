<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

/**
 * The storefront checkout's extra choices (Store builder → Checkout):
 *
 * - "I agree to the terms and the privacy policy": required when the owner
 *   left it on (the default) and at least one of those pages is live. The
 *   storefront sends extensions.qwoo.terms; the store refuses the order
 *   without it and keeps the time of agreement on the order
 *   (_qwoo_terms_accepted), as proof.
 * - "Create an account": guests can tick it (Store API create_account).
 *   WooCommerce makes the account and emails a link to set a password.
 */
class Qwoo_Checkout_Extras {

    const TERMS_META = '_qwoo_terms_accepted';

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_schema' ] );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'check_terms' ], 10, 2 );
        add_action( 'rest_api_init', [ __CLASS__, 'sync_once' ] );
    }

    /** Stores that never saved the Checkout tab: WooCommerce's switch matches the default (on). */
    public static function sync_once() {
        if ( get_option( 'qwoo_checkout_signup_synced' ) ) {
            return;
        }
        update_option( 'qwoo_checkout_signup_synced', 1, true );
        $options = get_option( 'shop_builder_options', [] );
        self::sync_signup( (bool) ( $options['checkout']['allow_signup'] ?? true ) );
    }

    /** The checkout accepts { extensions: { qwoo: { terms: bool } } }. */
    public static function register_schema() {
        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
            return;
        }
        woocommerce_store_api_register_endpoint_data( [
            'endpoint'        => CheckoutSchema::IDENTIFIER,
            'namespace'       => 'qwoo',
            'schema_callback' => static fn() => [
                'terms' => [
                    'description' => 'The customer agreed to the terms and the privacy policy.',
                    'type'        => [ 'boolean', 'null' ],
                    'context'     => [ 'view', 'edit' ],
                    'optional'    => true,
                ],
                // Where the shopper came from, and the "send me offers" box.
                'source'    => [ 'description' => 'Traffic source.', 'type' => [ 'string', 'null' ], 'context' => [ 'view', 'edit' ], 'optional' => true ],
                'medium'    => [ 'description' => 'Traffic medium.', 'type' => [ 'string', 'null' ], 'context' => [ 'view', 'edit' ], 'optional' => true ],
                'campaign'  => [ 'description' => 'Campaign.', 'type' => [ 'string', 'null' ], 'context' => [ 'view', 'edit' ], 'optional' => true ],
                'marketing' => [ 'description' => 'The customer asked for marketing emails.', 'type' => [ 'boolean', 'null' ], 'context' => [ 'view', 'edit' ], 'optional' => true ],
            ],
        ] );
    }

    /** Whether customers must agree before ordering: the owner's switch, and a live terms or privacy page. */
    public static function terms_required() {
        $options = get_option( 'shop_builder_options', [] );
        if ( isset( $options['checkout']['require_terms'] ) && ! $options['checkout']['require_terms'] ) {
            return false;
        }
        foreach ( class_exists( 'Shop_Settings_Builder' ) ? Shop_Settings_Builder::published_pages() : [] as $page ) {
            if ( in_array( $page['role'] ?? '', [ 'terms', 'privacy' ], true ) ) {
                return true;
            }
        }
        return false;
    }

    public static function check_terms( WC_Order $order, WP_REST_Request $request ) {
        $agreed = ! empty( $request['extensions']['qwoo']['terms'] );
        if ( ! $agreed && self::terms_required() ) {
            throw new RouteException( 'qwoo_terms_required', "Please tick the box to agree to the store's terms before placing your order.", 400 );
        }
        if ( $agreed ) {
            $order->update_meta_data( self::TERMS_META, gmdate( 'c' ) );
        }
        $ext = (array) ( $request['extensions']['qwoo'] ?? [] );
        foreach ( [ 'source' => 60, 'medium' => 60, 'campaign' => 100 ] as $key => $max ) {
            $value = mb_substr( sanitize_text_field( (string) ( $ext[ $key ] ?? '' ) ), 0, $max );
            if ( $value !== '' ) $order->update_meta_data( '_qwoo_' . $key, $value );
        }
        // Asked at checkout: the box is never ticked for them, and its wording is kept as the record.
        if ( ! empty( $ext['marketing'] ) && Qwoo_Email_Marketing::checkout_optin()['on'] ) {
            $order->update_meta_data( '_qwoo_marketing_optin', 1 );
            add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'subscribe_from_order' ], 40 );
        }
    }

    public static function subscribe_from_order( $order ) {
        if ( ! $order || ! $order->get_meta( '_qwoo_marketing_optin' ) ) return;
        Qwoo_Email_Marketing::subscribe( (string) $order->get_billing_email(), trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), 'checkout', Qwoo_Email_Marketing::checkout_optin()['text'] );
    }

    /** WooCommerce's own switches for creating an account at checkout. */
    public static function sync_signup( $on ) {
        update_option( 'woocommerce_enable_signup_and_login_from_checkout', $on ? 'yes' : 'no' );
        // A link to set the password goes by email, so checkout doesn't ask for one.
        update_option( 'woocommerce_registration_generate_password', 'yes' );
        update_option( 'woocommerce_registration_generate_username', 'yes' );
    }
}

Qwoo_Checkout_Extras::init();

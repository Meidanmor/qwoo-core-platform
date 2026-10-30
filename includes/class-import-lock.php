<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * While a store copy file or a move from an old store is coming in (job migrate_store): the
 * storefront shows its pages under an "updating" overlay (qwoo/v1/site says `importing`), and
 * the store refuses new orders and cart changes, so nobody buys a product that's about to change.
 * A new store's template at setup doesn't count (the store isn't open yet).
 */
class Qwoo_Import_Lock {

    /** The Store API routes that change a cart or place an order. */
    const BLOCKED = '#^/wc/store(/v\d+)?/(checkout|cart/(add-item|update-item|apply-coupon|items))#';

    public static function init() {
        add_filter( 'rest_pre_dispatch', [ __CLASS__, 'guard' ], 5, 3 );
    }

    public static function running(): bool {
        $s = get_option( 'qwoo_migration' );
        return is_array( $s ) && ( $s['status'] ?? '' ) === 'running' && empty( $s['package_template'] );
    }

    /** For qwoo/v1/site and current.json. */
    public static function site_fields(): array {
        return self::running() ? [ 'importing' => true ] : [];
    }

    public static function guard( $result, $server, $request ) {
        if ( $result !== null || $request->get_method() === 'GET' || ! preg_match( self::BLOCKED, $request->get_route() ) ) {
            return $result;
        }
        if ( ! self::running() ) return $result;
        return new WP_Error( 'qwoo_store_updating', __( 'The store is being updated. Please try again in a few minutes.', 'qwoo-core' ), [ 'status' => 503 ] );
    }
}

Qwoo_Import_Lock::init();

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A store suspended by the platform (its wp-admin store screen).
 *
 * While suspended, the storefront shows everyone a plain "This store is
 * unavailable" page (no preview link opens it), the store refuses cart changes
 * and orders, sends no push notifications, and asks search engines not to
 * index it. Only the platform sets it (signed action suspension_set): the
 * owner's dashboard can't lift it. The reason stays on the platform.
 */
class Qwoo_Store_Suspension {

    const OPTION = 'qwoo_store_suspended'; // { on: bool, since: int }

    /** The Store API routes that change a cart or place an order. */
    const BLOCKED = '#^/wc/store(/v\d+)?/(checkout|cart/(add-item|update-item|apply-coupon|items))#';

    public static function init() {
        add_filter( 'rest_pre_dispatch', [ __CLASS__, 'guard' ], 4, 3 );
    }

    public static function suspended(): bool {
        $v = get_option( self::OPTION, [] );
        return is_array( $v ) && ! empty( $v['on'] );
    }

    public static function set( bool $on ): void {
        if ( $on === self::suspended() ) return;
        update_option( self::OPTION, [ 'on' => $on, 'since' => $on ? time() : 0 ], true );
    }

    /** For qwoo/v1/site and current.json (public: no reason in it). */
    public static function site_fields(): array {
        return self::suspended()
            ? [
                'suspended' => true,
                'name'      => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
                'lang'      => class_exists( 'Qwoo_Store_Language' ) ? Qwoo_Store_Language::get() : 'en',
            ]
            : [];
    }

    public static function guard( $result, $server, $request ) {
        if ( $result !== null || $request->get_method() === 'GET' || ! preg_match( self::BLOCKED, $request->get_route() ) ) {
            return $result;
        }
        if ( ! self::suspended() ) return $result;
        return new WP_Error( 'qwoo_store_suspended', __( 'This store is unavailable.', 'qwoo-core' ), [ 'status' => 403 ] );
    }
}

Qwoo_Store_Suspension::init();

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

/**
 * A hidden store ("Coming soon"), set from the platform dashboard.
 *
 * While hidden, the storefront shows visitors a Coming soon page and the
 * store takes no orders. A private preview link (storefront
 * /?preview={key}) sets the qwoo_preview cookie on that browser, which opens
 * the real store there (the owner, or someone they send it to) and lets it
 * order. The storefront learns the state from qwoo/v1/site, which the CDN
 * serves to anyone: it carries only a hash of the key.
 */
class Qwoo_Store_Visibility {

    const OPTION = 'qwoo_store_visibility'; // { hidden: bool, key: string, since: int }
    const COOKIE = 'qwoo_preview';

    public static function init() {
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'check_order' ], 5, 2 );
    }

    private static function get(): array {
        $v = get_option( self::OPTION, [] );
        return is_array( $v ) ? $v : [];
    }

    public static function hidden(): bool {
        return ! empty( self::get()['hidden'] );
    }

    /** The preview key, made the first time it's needed. */
    public static function key(): string {
        $v = self::get();
        if ( empty( $v['key'] ) || ! preg_match( '/^[a-f0-9]{32}$/', (string) $v['key'] ) ) {
            $v['key'] = bin2hex( random_bytes( 16 ) );
            update_option( self::OPTION, $v, true );
        }
        return (string) $v['key'];
    }

    public static function set_hidden( bool $hidden ): void {
        $v = self::get();
        if ( $hidden && empty( $v['hidden'] ) ) {
            $v['since'] = time();
        }
        $v['hidden'] = $hidden;
        update_option( self::OPTION, $v, true );
        self::key();
    }

    /** A new link: the old one stops working (browsers that opened it see Coming soon again). */
    public static function new_key(): void {
        $v        = self::get();
        $v['key'] = bin2hex( random_bytes( 16 ) );
        update_option( self::OPTION, $v, true );
    }

    /** This request comes from a browser that opened the preview link. */
    public static function previewing(): bool {
        $cookie = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
        return $cookie !== '' && hash_equals( self::key(), $cookie );
    }

    /** For qwoo/v1/site (public through the storefront's CDN): never the key itself. */
    public static function site_fields(): array {
        return self::hidden()
            ? [
                'hidden'  => true,
                'preview' => hash( 'sha256', self::key() ),
                // For the Coming soon page.
                'name'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
                'lang'    => class_exists( 'Qwoo_Store_Language' ) ? Qwoo_Store_Language::get() : 'en',
            ]
            : [ 'hidden' => false ];
    }

    /** Orders only from the preview link while the store is hidden. */
    public static function check_order( WC_Order $order, WP_REST_Request $request ) {
        if ( self::hidden() && ! self::previewing() ) {
            throw new RouteException( 'qwoo_store_hidden', 'This store isn\'t open yet, so it can\'t take orders.', 403 );
        }
    }

    /** For the dashboard (Settings → General). */
    public static function dashboard_state(): array {
        $v = self::get();
        return [
            'hidden' => ! empty( $v['hidden'] ),
            'since'  => (int) ( $v['since'] ?? 0 ),
            'key'    => self::key(),
        ];
    }
}

Qwoo_Store_Visibility::init();

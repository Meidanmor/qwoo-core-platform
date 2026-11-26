<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A template's demo store (owner, 2026-11-26): the platform's dashboard may show its public pages in a frame,
 * for "Start from a template". Only the platform sets it (signed action gallery_set, from its wp-admin
 * Templates list), and it is live at once through the storefront's pointer (qwoo/v1/site, current.json):
 * no storefront build. Removing the template turns it off again.
 *
 * The storefront (aura render.js) allows exactly this origin in frame-ancestors, never on the pages that
 * hold one shopper's data (cart, checkout, account).
 */
class Qwoo_Gallery_Demo {

    const OPTION = 'qwoo_gallery_origin'; // 'https://app.example' or ''

    public static function origin(): string {
        return self::clean( (string) get_option( self::OPTION, '' ) );
    }

    /** An https origin (scheme + host [+ port]), else ''. */
    public static function clean( string $value ): string {
        $p = wp_parse_url( trim( $value ) );
        if ( ! is_array( $p ) || ( $p['scheme'] ?? '' ) !== 'https' || empty( $p['host'] ) || ! preg_match( '/^[a-z0-9.-]+$/i', (string) $p['host'] ) ) {
            return '';
        }
        return 'https://' . strtolower( (string) $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
    }

    /** Turns it on (with the dashboard's origin) or off (''). */
    public static function set( string $origin ): void {
        $origin = self::clean( $origin );
        if ( $origin === self::origin() ) return;
        $origin === '' ? delete_option( self::OPTION ) : update_option( self::OPTION, $origin, true );
        if ( class_exists( 'Qwoo_Site_Content' ) ) Qwoo_Site_Content::write_pointer();
    }

    /** For qwoo/v1/site and current.json (public: only the dashboard's address). */
    public static function site_fields(): array {
        $origin = self::origin();
        return $origin !== '' ? [ 'gallery' => $origin ] : [];
    }
}

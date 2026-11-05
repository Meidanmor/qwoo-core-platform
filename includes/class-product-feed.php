<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A product feed for Meta (Instagram and Facebook shops and ads), TikTok and
 * Google Merchant Center: one private address the owner pastes into each
 * service, which reads it by itself. RSS 2.0 with Google's product fields,
 * the format all three read. No approval needed from any of them.
 *
 * The address holds a random key (a catalog isn't secret, but it shouldn't be
 * crawled); kept for an hour, since services can read it often.
 */
class Qwoo_Product_Feed {

    const OPTION = 'qwoo_feed_key';
    const CACHE  = 'qwoo_feed_xml';
    const MAX    = 5000;

    public static function init() {
        add_action( 'rest_api_init', static function () {
            register_rest_route( 'qwoo/v1', '/feed/(?P<key>[a-f0-9]{32})', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_feed' ], 'permission_callback' => '__return_true' ] );
        } );
    }

    /** The feed's address, or '' while it's off. */
    public static function url(): string {
        $key = (string) get_option( self::OPTION, '' );
        if ( $key === '' ) return '';
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? rtrim( Qwoo_Technical_Settings::get_primary_frontend_domain(), '/' ) : '';
        return $front !== '' ? $front . '/wp-json/qwoo/v1/feed/' . $key : rest_url( 'qwoo/v1/feed/' . $key );
    }

    /** Turns it on with a new address (the old one stops working), or off. */
    public static function set( bool $on ): string {
        delete_transient( self::CACHE );
        if ( $on ) {
            update_option( self::OPTION, bin2hex( random_bytes( 16 ) ), false );
        } else {
            delete_option( self::OPTION );
        }
        return self::url();
    }

    public static function rest_feed( WP_REST_Request $r ) {
        $key = (string) get_option( self::OPTION, '' );
        if ( $key === '' || ! hash_equals( $key, (string) $r['key'] ) ) {
            return new WP_Error( 'not_found', 'Not found.', [ 'status' => 404 ] );
        }
        $xml = get_transient( self::CACHE );
        if ( ! is_string( $xml ) || $xml === '' ) {
            $xml = self::build();
            set_transient( self::CACHE, $xml, HOUR_IN_SECONDS );
        }
        header( 'Content-Type: application/rss+xml; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );
        header( 'X-Robots-Tag: noindex' );
        echo $xml; // phpcs:ignore
        exit;
    }

    private static function x( $text ): string {
        return htmlspecialchars( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }

    public static function build(): string {
        $front    = class_exists( 'Qwoo_Technical_Settings' ) ? rtrim( Qwoo_Technical_Settings::get_primary_frontend_domain(), '/' ) : home_url();
        $currency = get_woocommerce_currency();
        $store    = get_bloginfo( 'name' );
        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>';
        $out .= '<title>' . self::x( $store ) . '</title><link>' . self::x( $front ) . '</link><description>' . self::x( get_bloginfo( 'description' ) ?: $store ) . '</description>';
        foreach ( wc_get_products( [ 'status' => 'publish', 'limit' => self::MAX, 'visibility' => 'visible' ] ) as $product ) {
            $image = wp_get_attachment_image_url( $product->get_image_id(), 'large' );
            // No photo or no price: every service would refuse the item.
            $regular = (float) ( $product->is_type( 'variable' ) ? $product->get_variation_regular_price( 'min' ) : $product->get_regular_price() );
            $price   = (float) $product->get_price();
            if ( ! $image || $price <= 0 ) continue;
            $text  = $product->get_short_description() ?: $product->get_description() ?: $product->get_name();
            $out  .= '<item><g:id>' . (int) $product->get_id() . '</g:id><g:title>' . self::x( mb_substr( $product->get_name(), 0, 150 ) ) . '</g:title>';
            $out  .= '<g:description>' . self::x( mb_substr( wp_strip_all_tags( $text ), 0, 4900 ) ) . '</g:description>';
            $out  .= '<g:link>' . self::x( $front . '/product/' . $product->get_slug() ) . '</g:link><g:image_link>' . self::x( $image ) . '</g:image_link>';
            $out  .= '<g:availability>' . ( $product->is_in_stock() ? 'in_stock' : 'out_of_stock' ) . '</g:availability><g:condition>new</g:condition><g:brand>' . self::x( $store ) . '</g:brand>';
            if ( $regular > $price ) {
                $out .= '<g:price>' . number_format( $regular, 2, '.', '' ) . ' ' . $currency . '</g:price><g:sale_price>' . number_format( $price, 2, '.', '' ) . ' ' . $currency . '</g:sale_price>';
            } else {
                $out .= '<g:price>' . number_format( $price, 2, '.', '' ) . ' ' . $currency . '</g:price>';
            }
            if ( $product->get_sku() !== '' ) $out .= '<g:mpn>' . self::x( $product->get_sku() ) . '</g:mpn>';
            $out .= '</item>';
        }
        return $out . '</channel></rss>';
    }
}

Qwoo_Product_Feed::init();

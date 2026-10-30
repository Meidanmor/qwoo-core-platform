<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Products with no price or a price of 0 (Settings → General → "Products
 * with no price"). The owner picks what shoppers get:
 * - buy:     sold like any product (a price of 0 is free; no price stays
 *            unbuyable, as in WooCommerce);
 * - replace: no Add to cart; a button of the owner's to the product's page
 *            or any link instead;
 * - hide:    no Add to cart and nothing in its place.
 * Plus an own price text per language ("Price on request"), '' = the normal
 * price. With replace / hide the store refuses to add these products to a
 * cart, so the rule holds whatever the storefront shows.
 *
 * Option `qwoo_zero_price` { mode, link: product | url, url, texts: { lang: { price, button } } }.
 * The storefront reads it per product: Store API `extensions.qwoo.zero_price`
 * { action, button, link, url } (null for priced products), and the price
 * HTML carries the own text.
 *
 * Default (never saved): replace, to the product's page, so a 0 price never shows Add to
 * cart by accident (user, 2026-10-30). A product on sale for 0 whose regular price is above 0
 * is a free promotion, not a price-0 product: it's always sold as usual.
 */
class Qwoo_Zero_Price {

    const OPTION = 'qwoo_zero_price';
    const MODES  = [ 'buy', 'replace', 'hide' ];
    const LINKS  = [ 'product', 'url' ];

    public static function init() {
        add_filter( 'woocommerce_is_purchasable', [ __CLASS__, 'purchasable' ], 20, 2 );
        add_filter( 'woocommerce_variation_is_purchasable', [ __CLASS__, 'purchasable' ], 20, 2 );
        add_filter( 'woocommerce_get_price_html', [ __CLASS__, 'price_html' ], 20, 2 );
        add_filter( 'woocommerce_empty_price_html', [ __CLASS__, 'price_html' ], 20, 2 );
    }

    /** The saved settings, always clean. */
    public static function settings(): array {
        $v     = (array) get_option( self::OPTION, [] );
        $texts = [];
        foreach ( (array) ( $v['texts'] ?? [] ) as $lang => $row ) {
            if ( ! is_array( $row ) || ! preg_match( '/^[a-z]{2}$/', (string) $lang ) ) continue;
            foreach ( [ 'price' => 60, 'button' => 40 ] as $key => $max ) {
                $text = trim( (string) ( $row[ $key ] ?? '' ) );
                if ( $text !== '' ) $texts[ $lang ][ $key ] = mb_substr( $text, 0, $max );
            }
        }
        $link = in_array( $v['link'] ?? '', self::LINKS, true ) ? $v['link'] : 'product';
        $url  = self::clean_url( (string) ( $v['url'] ?? '' ) );
        return [
            'mode'  => in_array( $v['mode'] ?? '', self::MODES, true ) ? $v['mode'] : 'replace',
            'link'  => $link === 'url' && $url === '' ? 'product' : $link,
            'url'   => $url,
            'texts' => $texts,
        ];
    }

    /** A path on the store ("/contact"), an https / http address, mailto: or tel:; else ''. */
    private static function clean_url( string $url ): string {
        $url = trim( $url );
        if ( $url === '' ) return '';
        if ( preg_match( '#^/(?!/)[^\s<>"]*$#', $url ) ) return mb_substr( $url, 0, 300 );
        if ( preg_match( '#^(https?://|mailto:|tel:)#i', $url ) ) return mb_substr( esc_url_raw( $url, [ 'http', 'https', 'mailto', 'tel' ] ), 0, 300 );
        return '';
    }

    /** For Settings: the settings plus the store's languages (main first). */
    public static function for_dashboard(): array {
        $langs = class_exists( 'Qwoo_Store_Language' ) ? array_values( array_unique( array_merge( [ Qwoo_Store_Language::get() ], Qwoo_Store_Language::extra() ) ) ) : [ 'en' ];
        return self::settings() + [ 'languages' => $langs ];
    }

    /** Settings save (section zero_price). Returns true or a WP_Error. */
    public static function save( array $v ) {
        $mode = (string) ( $v['mode'] ?? 'buy' );
        $link = (string) ( $v['link'] ?? 'product' );
        if ( ! in_array( $mode, self::MODES, true ) || ! in_array( $link, self::LINKS, true ) ) {
            return new WP_Error( 'qwoo_zero_price', 'Choose one of the options.' );
        }
        $url = self::clean_url( (string) ( $v['url'] ?? '' ) );
        if ( $mode === 'replace' && $link === 'url' && $url === '' ) {
            return new WP_Error( 'qwoo_zero_price', 'Enter the button\'s link: a page of your store (/contact) or a full address.' );
        }
        $texts = [];
        foreach ( (array) ( $v['texts'] ?? [] ) as $lang => $row ) {
            if ( ! is_array( $row ) || ! preg_match( '/^[a-z]{2}$/', (string) $lang ) ) continue;
            foreach ( [ 'price', 'button' ] as $key ) {
                $text = sanitize_text_field( (string) ( $row[ $key ] ?? '' ) );
                if ( $text !== '' ) $texts[ $lang ][ $key ] = $text;
            }
        }
        update_option( self::OPTION, [ 'mode' => $mode, 'link' => $link, 'url' => $url, 'texts' => $texts ], false );
        return true;
    }

    /** No price, or 0 (a product with options: every option), unless it's on sale from a real price. */
    public static function is_zero( $product ): bool {
        if ( ! $product instanceof WC_Product ) return false;
        if ( $product->is_type( 'variable' ) ) {
            $prices  = $product->get_variation_prices( true );
            $list    = array_map( 'floatval', array_values( $prices['price'] ?? [] ) );
            $regular = array_map( 'floatval', array_values( $prices['regular_price'] ?? [] ) );
            return ! $list || ( max( $list ) <= 0 && ( ! $regular || max( $regular ) <= 0 ) );
        }
        if ( $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) return false;
        if ( (float) $product->get_price() > 0 ) return false;
        // On sale for 0 from a real price: a free promotion.
        return ! ( $product->is_on_sale() && (float) $product->get_regular_price() > 0 );
    }

    /** The text of this language (the page's, else the store's). */
    private static function text( string $key ): string {
        $s    = self::settings();
        $lang = class_exists( 'Qwoo_Store_Language' ) ? ( Qwoo_Store_Language::request_lang() ?: Qwoo_Store_Language::get() ) : 'en';
        return (string) ( $s['texts'][ $lang ][ $key ] ?? '' );
    }

    public static function purchasable( $yes, $product ) {
        if ( ! $yes ) return $yes;
        if ( self::settings()['mode'] === 'buy' ) return $yes;
        $check = $product instanceof WC_Product_Variation ? wc_get_product( $product->get_parent_id() ) : $product;
        return self::is_zero( $check ) ? false : $yes;
    }

    public static function price_html( $html, $product ) {
        if ( ! self::is_zero( $product ) ) return $html;
        $own = self::text( 'price' );
        return $own !== '' ? '<span class="qwoo-price-text">' . esc_html( $own ) . '</span>' : $html;
    }

    /** Store API `extensions.qwoo.zero_price`. */
    public static function for_storefront( $product ) {
        if ( ! self::is_zero( $product ) ) return null;
        $s = self::settings();
        return [
            'action' => $s['mode'],
            'button' => self::text( 'button' ),
            'link'   => $s['link'],
            'url'    => $s['link'] === 'url' ? $s['url'] : '',
        ];
    }
}

Qwoo_Zero_Price::init();

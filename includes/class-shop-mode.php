<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sell online on / off (dashboard Settings → General) and Shopper accounts on / off
 * (Settings → Sign-in). Nothing is deleted: turning a switch back on brings everything back.
 *
 * Shop off:
 * - products: "catalog" (pages and lists stay, no price, no Add to cart; an optional text per
 *   language in the price's place) or "hide" (Store API product lists and searches answer only
 *   the services shown as products; the storefront sends product pages away);
 * - addresses: where the storefront sends shop pages (cart, checkout, wishlist, and product
 *   pages / lists when hidden): "home", "closed" (the owner's message) or "notfound";
 * - nothing but a booking can be bought: every other product is not purchasable, so the Store
 *   API refuses to add it and drops it from a cart; gift cards with it;
 * - appointments keep working: a paid booking still goes through the cart and the checkout
 *   (the storefront opens the checkout straight from the Booking block).
 *
 * Accounts off: the sign-in, sign-up, Google and password routes answer 403, checkout is guest
 * only, and the storefront's My account page follows the same "addresses" choice. Customers and
 * their orders stay; a booking is managed from the link in its email.
 *
 * Option `qwoo_shop_mode` { sell, products, addr, accounts, texts: { lang: { price, closed } } }.
 * The storefront reads it from the live pointer (qwoo/v1/site and current.json: `shop`), so a
 * change shows within seconds, with no publish; per product, catalog mode goes out as
 * `extensions.qwoo.zero_price` { action: hide, catalog: true }.
 */
class Qwoo_Shop_Mode {

    const OPTION   = 'qwoo_shop_mode';
    const PRODUCTS = [ 'catalog', 'hide' ];
    const ADDRS    = [ 'home', 'closed', 'notfound' ];

    /** Sign-in routes refused while shopper accounts are off. */
    const ACCOUNT_ROUTES = '#^/qwoo/v1/(login|register|google-login|google-login-redirect|forgot-password|reset-password)$#';

    private static $listing = false;

    public static function init() {
        add_filter( 'woocommerce_is_purchasable', [ __CLASS__, 'purchasable' ], 30, 2 );
        add_filter( 'woocommerce_variation_is_purchasable', [ __CLASS__, 'purchasable' ], 30, 2 );
        add_filter( 'woocommerce_get_price_html', [ __CLASS__, 'price_html' ], 30, 2 );
        add_filter( 'woocommerce_empty_price_html', [ __CLASS__, 'price_html' ], 30, 2 );
        add_filter( 'rest_pre_dispatch', [ __CLASS__, 'guard_accounts' ], 5, 3 );
        add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'mark_product_list' ], 10, 3 );
        add_action( 'pre_get_posts', [ __CLASS__, 'hide_from_lists' ], 20 );
        // Accounts off: guest checkout, no account made while paying, no sign-up on My account.
        foreach ( [
            'woocommerce_enable_guest_checkout'                 => 'yes',
            'woocommerce_enable_signup_and_login_from_checkout' => 'no',
            'woocommerce_enable_myaccount_registration'         => 'no',
        ] as $option => $value ) {
            add_filter( 'pre_option_' . $option, static function ( $pre ) use ( $value ) {
                return self::accounts_on() || is_admin() || self::dashboard_request() ? $pre : $value;
            } );
        }
    }

    /** The saved settings, always clean. Default (never saved): selling, accounts on. */
    public static function settings(): array {
        $v     = get_option( self::OPTION, [] );
        $v     = is_array( $v ) ? $v : [];
        $texts = [];
        foreach ( (array) ( $v['texts'] ?? [] ) as $lang => $row ) {
            if ( ! is_array( $row ) || ! preg_match( '/^[a-z]{2}$/', (string) $lang ) ) continue;
            foreach ( [ 'price' => 60, 'closed' => 200 ] as $key => $max ) {
                $text = trim( (string) ( $row[ $key ] ?? '' ) );
                if ( $text !== '' ) $texts[ $lang ][ $key ] = mb_substr( $text, 0, $max );
            }
        }
        return [
            'sell'     => ! array_key_exists( 'sell', $v ) || ! empty( $v['sell'] ),
            'products' => in_array( $v['products'] ?? '', self::PRODUCTS, true ) ? $v['products'] : 'catalog',
            'addr'     => in_array( $v['addr'] ?? '', self::ADDRS, true ) ? $v['addr'] : 'home',
            'accounts' => ! array_key_exists( 'accounts', $v ) || ! empty( $v['accounts'] ),
            'texts'    => $texts,
        ];
    }

    /** The owner's dashboard reads and saves the real values (they come back when accounts are on again). */
    private static function dashboard_request(): bool {
        return strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), 'platform/dashboard' ) !== false
            || strpos( (string) ( $_GET['rest_route'] ?? '' ), 'platform/dashboard' ) !== false;
    }

    public static function selling(): bool { return self::settings()['sell']; }
    public static function accounts_on(): bool { return self::settings()['accounts']; }

    /** For Settings: the settings plus the store's languages (main first). */
    public static function for_dashboard(): array {
        $langs = class_exists( 'Qwoo_Store_Language' ) ? array_values( array_unique( array_merge( [ Qwoo_Store_Language::get() ], Qwoo_Store_Language::extra() ) ) ) : [ 'en' ];
        return self::settings() + [ 'languages' => $langs ];
    }

    /** Settings save, section shop { sell, products, addr, texts } or accounts { accounts }. Returns true or a WP_Error. */
    public static function save( array $v, string $section = 'shop' ) {
        $s = self::settings();
        if ( $section === 'accounts' ) {
            $s['accounts'] = ! empty( $v['accounts'] );
        } else {
            $products = (string) ( $v['products'] ?? 'catalog' );
            $addr     = (string) ( $v['addr'] ?? 'home' );
            if ( ! in_array( $products, self::PRODUCTS, true ) || ! in_array( $addr, self::ADDRS, true ) ) {
                return new WP_Error( 'qwoo_shop_mode', 'Choose one of the options.' );
            }
            $texts = [];
            foreach ( (array) ( $v['texts'] ?? [] ) as $lang => $row ) {
                if ( ! is_array( $row ) || ! preg_match( '/^[a-z]{2}$/', (string) $lang ) ) continue;
                foreach ( [ 'price' => 60, 'closed' => 200 ] as $key => $max ) {
                    $text = mb_substr( sanitize_text_field( (string) ( $row[ $key ] ?? '' ) ), 0, $max );
                    if ( $text !== '' ) $texts[ $lang ][ $key ] = $text;
                }
            }
            $s = [ 'sell' => ! empty( $v['sell'] ), 'products' => $products, 'addr' => $addr, 'accounts' => $s['accounts'], 'texts' => $texts ];
        }
        update_option( self::OPTION, $s, true );
        return true;
    }

    /** A product that stays bookable with the shop off: a service's product (shown or hidden). */
    public static function is_service( $product ): bool {
        if ( ! class_exists( 'Qwoo_Appointments' ) ) return false;
        $id = $product instanceof WC_Product ? ( $product->get_parent_id() ?: $product->get_id() ) : (int) $product;
        return $id && in_array( $id, Qwoo_Appointments::product_ids(), true );
    }

    /** The text of this language (the page's, else the store's). */
    private static function text( string $key ): string {
        $lang = class_exists( 'Qwoo_Store_Language' ) ? ( Qwoo_Store_Language::request_lang() ?: Qwoo_Store_Language::get() ) : 'en';
        return (string) ( self::settings()['texts'][ $lang ][ $key ] ?? '' );
    }

    public static function purchasable( $yes, $product ) {
        if ( ! $yes || self::selling() ) return $yes;
        return self::is_service( $product ) ? $yes : false;
    }

    public static function price_html( $html, $product ) {
        if ( self::selling() || ! $product instanceof WC_Product || self::is_service( $product ) ) return $html;
        $own = self::text( 'price' );
        return $own !== '' ? '<span class="qwoo-price-text">' . esc_html( $own ) . '</span>' : '';
    }

    /** Store API `extensions.qwoo.zero_price` while the shop is off (null: the usual rules). */
    public static function for_storefront( $product ) {
        if ( self::selling() || ! $product instanceof WC_Product || self::is_service( $product ) ) return null;
        return [ 'action' => 'hide', 'button' => '', 'link' => 'product', 'url' => '', 'catalog' => true, 'price' => self::text( 'price' ) ];
    }

    /** For qwoo/v1/site and current.json (public). */
    public static function site_fields(): array {
        $s = self::settings();
        if ( $s['sell'] && $s['accounts'] ) return [];
        $closed = [];
        foreach ( $s['texts'] as $lang => $row ) {
            if ( ! empty( $row['closed'] ) ) $closed[ $lang ] = $row['closed'];
        }
        return [ 'shop' => [
            'sell'     => $s['sell'],
            'products' => $s['products'],
            'addr'     => $s['addr'],
            'accounts' => $s['accounts'],
            'closed'   => (object) $closed,
        ] ];
    }

    public static function guard_accounts( $result, $server, $request ) {
        if ( $result !== null || ! preg_match( self::ACCOUNT_ROUTES, $request->get_route() ) || self::accounts_on() ) return $result;
        $he = class_exists( 'Qwoo_Store_Language' ) && ( Qwoo_Store_Language::request_lang() ?: Qwoo_Store_Language::get() ) === 'he';
        return new WP_Error( 'qwoo_accounts_off', $he ? 'החנות לא משתמשת בחשבונות לקוח.' : 'This store doesn\'t use customer accounts.', [ 'status' => 403 ] );
    }

    public static function mark_product_list( $response, $handler, $request ) {
        if ( $request instanceof WP_REST_Request && preg_match( '#^/wc/store(/v\d+)?/products$#', $request->get_route() ) ) {
            self::$listing = true;
        }
        return $response;
    }

    /** "Hide them completely": the storefront's product lists and searches hold only services shown as products. */
    public static function hide_from_lists( $query ) {
        if ( ! $query instanceof WP_Query || is_admin() ) return;
        $type = $query->get( 'post_type' );
        if ( $type !== 'product' && ! in_array( 'product', (array) $type, true ) ) return;
        if ( ! self::$listing && ! $query->is_search() ) return;
        $s = self::settings();
        if ( $s['sell'] || $s['products'] !== 'hide' ) return;
        $keep = class_exists( 'Qwoo_Appointments' ) ? Qwoo_Appointments::product_ids() : [];
        $in   = (array) $query->get( 'post__in' );
        $keep = $in ? array_values( array_intersect( array_map( 'intval', $in ), $keep ) ) : $keep;
        $query->set( 'post__in', $keep ?: [ 0 ] );
    }
}

Qwoo_Shop_Mode::init();

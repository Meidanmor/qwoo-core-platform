<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema;

/**
 * Gift cards: a product the owner sells (a simple product with _qwoo_giftcard),
 * emailed to someone else on a chosen day, and spent over several orders.
 *
 * Buying: the storefront adds the product with qwoo_gift (amount, recipient,
 * from, message, send date, design, colour, image) through the Store API's
 * add-item; each card is its own cart line, quantity 1. When the order is
 * paid, a card is made for each line and its email goes out on the send date,
 * in the push engine's run (no cron). The expiry counts from that day.
 *
 * Spending: the code goes in the checkout's code field. The storefront sends
 * it to cart/extensions (namespace qwoo-giftcard); the card then pays part of
 * the order's total, after shipping and tax, like a payment (never a discount,
 * which would lower the tax). The amount is taken from the card when the order
 * is placed, and goes back when the order is cancelled, fails or is refunded
 * in full. A card never pays for another gift card.
 *
 * The owner sees every card in the dashboard (Marketing → Gift cards, through
 * the growth action's gc_* operations), with each use and email event.
 * Delivered and bounced come from Resend through the platform (the email's
 * tag gc-<id>); opened from a pixel, like marketing emails.
 */
class Qwoo_Gift_Cards {

    const META        = '_qwoo_giftcard';     // the product's settings
    const ITEM        = 'qwoo_gift';          // a cart line's gift details
    const ORDER_ITEM  = '_qwoo_gift';         // the same, on the order line
    const CARD_META   = '_qwoo_gc_card';      // the card made from an order line
    const USED_META   = '_qwoo_gc_used';      // [ [ id, last4, amount ] ] paid by cards
    const TAKEN_META  = '_qwoo_gc_taken';     // what was taken from the cards for the order
    const SESSION     = 'qwoo_giftcards';     // codes in the shopper's cart
    const RULES       = 'qwoo_gc_rules';
    const NS          = 'qwoo/v1';
    const ALPHABET    = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const CODE_RE     = '/^[A-HJ-NP-Z2-9]{4}(-[A-HJ-NP-Z2-9]{4}){3}$/';
    const MAX_CODES   = 5;
    const MAX_MESSAGE = 300;
    const MAX_AMOUNT  = 100000;
    const PHOTO_DIR   = 'qwoo-gc';
    const PHOTO_BYTES = 3145728; // 3 MB, after the storefront made it smaller
    const PHOTO_SIDE  = 1200;
    const COLORS      = [ '#2f4a3a', '#b4543a', '#3b4f8a', '#8a3b6b', '#1f1d1a' ];
    const EXPIRY      = [ 0, 1, 2, 3, 5, 10, 15 ];
    const SEND_BATCH  = 20;

    /** Worked out once per cart total: [ [ id, code, amount, balance ] ]. */
    private static $alloc = [];

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_store_api' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );

        add_filter( 'woocommerce_store_api_add_to_cart_data', [ __CLASS__, 'add_to_cart_data' ], 10, 2 );
        add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_add' ], 10, 6 );
        add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'set_prices' ], 20 );
        add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'item_data' ], 10, 2 );
        add_filter( 'woocommerce_store_api_product_quantity_maximum', [ __CLASS__, 'one_each' ], 10, 3 );
        // A gift card has no category (WooCommerce puts "Uncategorized" back on every save).
        add_action( 'woocommerce_new_product', [ __CLASS__, 'no_category' ], 20 );
        add_action( 'woocommerce_update_product', [ __CLASS__, 'no_category' ], 20 );
        // The owner can keep gift cards out of product lists (shop, grids, search).
        add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'mark_product_list' ], 10, 3 );
        add_action( 'pre_get_posts', [ __CLASS__, 'hide_from_lists' ] );
        // Product grids show "Choose options": a gift card is bought on its own page, with its details.
        add_filter( 'woocommerce_product_has_options', static fn( $has, $product = null ) => $has || ( $product instanceof WC_Product && self::is_gift( $product ) ), 10, 2 );
        add_filter( 'woocommerce_coupon_is_valid_for_product', [ __CLASS__, 'coupon_for_product' ], 20, 2 );
        add_filter( 'woocommerce_calculated_total', [ __CLASS__, 'cart_total' ], 99, 2 );
        add_action( 'woocommerce_cart_emptied', [ __CLASS__, 'forget_codes' ] );

        add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'order_line' ], 10, 3 );
        add_action( 'woocommerce_order_after_calculate_totals', [ __CLASS__, 'order_total' ], 20, 2 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'on_placed' ], 5 );
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_status' ], 20, 4 );
        add_filter( 'woocommerce_get_order_item_totals', [ __CLASS__, 'order_item_totals' ], 20, 2 );
        add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ __CLASS__, 'hide_item_meta' ], 20, 2 );

        add_action( 'qwoo_push_tick', [ __CLASS__, 'send_due' ] );
        add_filter( 'qwoo_push_next_due', [ __CLASS__, 'next_due' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
    }

    private static function l( string $en, string $he ): string {
        return class_exists( 'Qwoo_I18n' ) && Qwoo_I18n::default_code() === 'he' ? $he : $en;
    }

    private static function now(): string {
        return gmdate( 'Y-m-d H:i:s' );
    }

    private static function round( $v ): float {
        return round( (float) $v, wc_get_price_decimals() );
    }

    /* ---------------- tables ---------------- */

    public static function cards_table() { global $wpdb; return $wpdb->prefix . 'qwoo_giftcards'; }
    public static function log_table() { global $wpdb; return $wpdb->prefix . 'qwoo_giftcard_log'; }

    /** Called by Qwoo_Push_Engine::install_tables(). */
    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $cards   = self::cards_table();
        $log     = self::log_table();
        dbDelta( "CREATE TABLE $cards (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(20) NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'scheduled',
        amount DECIMAL(14,4) NOT NULL DEFAULT 0,
        balance DECIMAL(14,4) NOT NULL DEFAULT 0,
        currency VARCHAR(8) NOT NULL DEFAULT '',
        order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        buyer_name VARCHAR(190) NOT NULL DEFAULT '',
        buyer_email VARCHAR(190) NOT NULL DEFAULT '',
        to_name VARCHAR(190) NOT NULL DEFAULT '',
        to_email VARCHAR(190) NOT NULL DEFAULT '',
        from_name VARCHAR(190) NOT NULL DEFAULT '',
        message TEXT NULL,
        design VARCHAR(20) NOT NULL DEFAULT '',
        color VARCHAR(9) NOT NULL DEFAULT '',
        image VARCHAR(80) NOT NULL DEFAULT '',
        expiry_years TINYINT UNSIGNED NOT NULL DEFAULT 0,
        send_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        expires_at DATETIME NULL,
        email_status VARCHAR(12) NOT NULL DEFAULT 'scheduled',
        delivered_at DATETIME NULL,
        opened_at DATETIME NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        note VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY code (code),
        KEY due (status, send_at),
        KEY order_id (order_id),
        KEY to_email (to_email),
        KEY buyer_email (buyer_email)
        ) $charset;" );
        dbDelta( "CREATE TABLE $log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        card_id BIGINT UNSIGNED NOT NULL,
        type VARCHAR(16) NOT NULL,
        amount DECIMAL(14,4) NULL,
        order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        note VARCHAR(255) NOT NULL DEFAULT '',
        who VARCHAR(80) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY card_id (card_id),
        KEY order_id (order_id)
        ) $charset;" );
    }

    private static function log( int $card_id, string $type, ?float $amount = null, int $order_id = 0, string $note = '', string $who = '' ): void {
        global $wpdb;
        $wpdb->insert( self::log_table(), [
            'card_id'    => $card_id,
            'type'       => $type,
            'amount'     => $amount,
            'order_id'   => $order_id,
            'note'       => mb_substr( $note, 0, 255 ),
            'who'        => mb_substr( $who, 0, 80 ),
            'created_at' => self::now(),
        ] );
    }

    public static function card( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::cards_table() . ' WHERE id = %d', $id ), ARRAY_A );
        return $row ?: null;
    }

    public static function by_code( string $code ): ?array {
        global $wpdb;
        $code = self::normal_code( $code );
        if ( $code === '' ) return null;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::cards_table() . ' WHERE code = %s', $code ), ARRAY_A );
        return $row ?: null;
    }

    /** "k7qx 9dmp3rta-h2wn" → "K7QX-9DMP-3RTA-H2WN"; '' when it can't be a gift card code. */
    public static function normal_code( string $code ): string {
        $plain = strtoupper( preg_replace( '/[\s-]+/', '', $code ) );
        if ( strlen( $plain ) !== 16 ) return '';
        $code = implode( '-', str_split( $plain, 4 ) );
        return preg_match( self::CODE_RE, $code ) ? $code : '';
    }

    private static function new_code(): string {
        global $wpdb;
        for ( $try = 0; $try < 10; $try++ ) {
            $chars = '';
            for ( $i = 0; $i < 16; $i++ ) {
                $chars .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
            }
            $code = implode( '-', str_split( $chars, 4 ) );
            // Never one a discount code already uses.
            if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::cards_table() . ' WHERE code = %s', $code ) ) && ! wc_get_coupon_id_by_code( $code ) ) {
                return $code;
            }
        }
        throw new RuntimeException( 'No free gift card code.' );
    }

    /** What a card is now: scheduled, active, used, expired or off. */
    public static function state( array $c ): string {
        if ( $c['status'] === 'disabled' ) return 'off';
        if ( $c['status'] === 'scheduled' ) return 'scheduled';
        if ( ! empty( $c['expires_at'] ) && strtotime( $c['expires_at'] . ' UTC' ) <= time() ) return 'expired';
        if ( (float) $c['balance'] <= 0 ) return 'used';
        return 'active';
    }

    private static function usable( array $c ): bool {
        return self::state( $c ) === 'active';
    }

    /* ---------------- the product's settings ---------------- */

    public static function default_designs(): array {
        return [
            [ 'id' => 'birthday', 'name' => self::l( 'Birthday', 'יום הולדת' ), 'title' => self::l( 'Happy birthday!', 'יום הולדת שמח!' ), 'mark' => '🎉', 'on' => true ],
            [ 'id' => 'thanks', 'name' => self::l( 'Thank you', 'תודה' ), 'title' => self::l( 'A little thank you', 'תודה קטנה' ), 'mark' => '💐', 'on' => true ],
            [ 'id' => 'holiday', 'name' => self::l( 'Holiday', 'חג' ), 'title' => self::l( 'Happy holidays', 'חג שמח' ), 'mark' => '✨', 'on' => true ],
            [ 'id' => 'simple', 'name' => self::l( 'Simple', 'פשוט' ), 'title' => self::l( 'A gift for you', 'מתנה בשבילך' ), 'mark' => '🎁', 'on' => true ],
            [ 'id' => 'baby', 'name' => self::l( 'New baby', 'לידה' ), 'title' => self::l( 'Welcome, little one', 'ברוך הבא לעולם' ), 'mark' => '🍼', 'on' => false ],
        ];
    }

    public static function defaults(): array {
        return [
            'amounts'   => [ 100, 200, 300, 500 ],
            'custom'    => true,
            'min'       => 50,
            'max'       => 2000,
            'expiry'    => 5,
            'designs'   => self::default_designs(),
            'colors'    => true,
            'palette'   => self::COLORS,
            'images'    => [],
            'own_photo' => true,
            'preview'   => 'replace',
            'in_lists'  => true,
        ];
    }

    public static function is_gift( $product ): bool {
        if ( ! $product instanceof WC_Product ) $product = wc_get_product( $product );
        return $product instanceof WC_Product && $product->is_type( 'simple' ) && is_array( $product->get_meta( self::META ) );
    }

    public static function settings( $product ): ?array {
        if ( ! $product instanceof WC_Product ) $product = wc_get_product( $product );
        if ( ! $product instanceof WC_Product || ! self::is_gift( $product ) ) return null;
        $saved = (array) $product->get_meta( self::META );
        return array_merge( self::defaults(), $saved );
    }

    public static function rules(): array {
        $saved = get_option( self::RULES, [] );
        return array_merge( [ 'discounts' => false, 'shipping' => true ], is_array( $saved ) ? $saved : [] );
    }

    private static function amount( $v ): ?float {
        $v = trim( str_replace( ',', '.', (string) $v ) );
        if ( ! preg_match( '/^\d{1,6}(\.\d{1,2})?$/', $v ) ) return null;
        $n = (float) $v;
        return $n > 0 && $n <= self::MAX_AMOUNT ? $n : null;
    }

    /** The dashboard's settings, checked. WP_Error with a message the owner can act on. */
    public static function clean_settings( $in ) {
        $in = is_array( $in ) ? $in : [];
        $amounts = [];
        foreach ( array_slice( (array) ( $in['amounts'] ?? [] ), 0, 13 ) as $a ) {
            $n = self::amount( $a );
            if ( $n === null ) return new WP_Error( 'gc', self::l( 'Each amount must be a number, like 100.', 'כל סכום צריך להיות מספר, למשל 100.' ) );
            $amounts[] = $n;
        }
        $amounts = array_values( array_unique( $amounts ) );
        sort( $amounts );
        if ( count( $amounts ) > 12 ) return new WP_Error( 'gc', self::l( 'Up to 12 amounts.', 'עד 12 סכומים.' ) );
        $custom = ! empty( $in['custom'] );
        $min    = self::amount( $in['min'] ?? 1 ) ?? 1.0;
        $max    = self::amount( $in['max'] ?? self::MAX_AMOUNT ) ?? (float) self::MAX_AMOUNT;
        if ( $custom && $max < $min ) return new WP_Error( 'gc', self::l( 'The highest amount is lower than the lowest one.', 'הסכום הגבוה נמוך מהסכום הנמוך.' ) );
        if ( ! $amounts && ! $custom ) return new WP_Error( 'gc', self::l( 'Add at least one amount, or let shoppers choose their own.', 'צריך להוסיף לפחות סכום אחד, או לאפשר לקונים לבחור סכום.' ) );
        $expiry = (int) ( $in['expiry'] ?? 5 );
        if ( ! in_array( $expiry, self::EXPIRY, true ) ) $expiry = 5;

        $designs = [];
        $seen    = [];
        foreach ( array_slice( (array) ( $in['designs'] ?? [] ), 0, 8 ) as $d ) {
            $id = sanitize_key( (string) ( $d['id'] ?? '' ) );
            if ( $id === '' || isset( $seen[ $id ] ) ) continue;
            $seen[ $id ] = true;
            $designs[] = [
                'id'    => mb_substr( $id, 0, 20 ),
                'name'  => mb_substr( sanitize_text_field( (string) ( $d['name'] ?? '' ) ), 0, 40 ) ?: $id,
                'title' => mb_substr( sanitize_text_field( (string) ( $d['title'] ?? '' ) ), 0, 80 ),
                'mark'  => mb_substr( sanitize_text_field( (string) ( $d['mark'] ?? '' ) ), 0, 4 ),
                'on'    => ! empty( $d['on'] ),
            ];
        }
        if ( ! $designs ) $designs = self::default_designs();
        if ( ! array_filter( $designs, static fn( $d ) => $d['on'] ) ) return new WP_Error( 'gc', self::l( 'Turn on at least one design.', 'צריך להפעיל לפחות עיצוב אחד.' ) );

        $palette = [];
        foreach ( array_slice( (array) ( $in['palette'] ?? self::COLORS ), 0, 8 ) as $c ) {
            $c = sanitize_hex_color( (string) $c );
            if ( $c ) $palette[] = strtolower( $c );
        }
        $palette = array_values( array_unique( $palette ) ) ?: self::COLORS;

        $images = [];
        foreach ( array_slice( (array) ( $in['images'] ?? [] ), 0, 8 ) as $id ) {
            $id = absint( $id );
            if ( $id && wp_attachment_is_image( $id ) ) $images[] = $id;
        }

        return [
            'amounts'   => $amounts,
            'custom'    => $custom,
            'min'       => $min,
            'max'       => $max,
            'expiry'    => $expiry,
            'designs'   => $designs,
            'colors'    => ! empty( $in['colors'] ),
            'palette'   => $palette,
            'images'    => array_values( array_unique( $images ) ),
            'own_photo' => ! empty( $in['own_photo'] ),
            // Where the email's preview shows on computers: in place of the photos, or under them.
            'preview'   => ( $in['preview'] ?? '' ) === 'below' ? 'below' : 'replace',
            'in_lists'  => ! array_key_exists( 'in_lists', $in ) || ! empty( $in['in_lists'] ),
        ];
    }

    public static function clean_rules( $in ): array {
        $in = is_array( $in ) ? $in : [];
        return [ 'discounts' => ! empty( $in['discounts'] ), 'shipping' => ! array_key_exists( 'shipping', $in ) || ! empty( $in['shipping'] ) ];
    }

    /**
     * Called by the dashboard's product_save after the product is saved as
     * simple: makes it a gift card (virtual, no stock, no tax, its lowest
     * amount as the shown price) or stops it being one.
     */
    public static function save_product( WC_Product $product, ?array $settings, ?array $rules ): void {
        if ( $settings === null ) {
            if ( self::is_gift( $product ) ) {
                $product->delete_meta_data( self::META );
                $product->save();
            }
            return;
        }
        $product->update_meta_data( self::META, $settings );
        $product->set_virtual( true );
        $product->set_downloadable( false );
        $product->set_manage_stock( false );
        $product->set_stock_status( 'instock' );
        $product->set_sold_individually( false );
        // A gift card is paid for again when it's spent: the tax is on what it buys.
        $product->set_tax_status( 'none' );
        $lowest = $settings['amounts'] ? min( $settings['amounts'] ) : $settings['min'];
        $product->set_regular_price( wc_format_decimal( $lowest ) );
        $product->set_sale_price( '' );
        $product->save();
        if ( $rules !== null ) update_option( self::RULES, $rules, false );
        self::remember_hidden();
    }

    /** Gift card products kept out of product lists, for hide_from_lists(). */
    private static function remember_hidden(): void {
        $hidden = [];
        foreach ( self::gift_products() as $id ) {
            $s = self::settings( $id );
            if ( $s && empty( $s['in_lists'] ) ) $hidden[] = (int) $id;
        }
        update_option( 'qwoo_gc_hidden', $hidden, true );
    }

    public static function no_category( $product_id ) {
        if ( ! self::is_gift( $product_id ) || ! wp_get_object_terms( (int) $product_id, 'product_cat', [ 'fields' => 'ids' ] ) ) return;
        // WooCommerce puts the default category back whenever a product is left without one.
        $force = has_action( 'set_object_terms', [ 'WC_Post_Data', 'force_default_term' ] );
        if ( $force !== false ) remove_action( 'set_object_terms', [ 'WC_Post_Data', 'force_default_term' ], $force );
        wp_set_object_terms( (int) $product_id, [], 'product_cat' );
        if ( $force !== false ) add_action( 'set_object_terms', [ 'WC_Post_Data', 'force_default_term' ], $force, 5 );
        wc_delete_product_transients( (int) $product_id );
    }

    /** True while the Store API lists products (not one product, not chosen ids). */
    private static $listing = false;

    public static function mark_product_list( $response, $handler, $request ) {
        if ( $request instanceof WP_REST_Request && $request->get_route() === '/wc/store/v1/products' && ! $request->get_param( 'include' ) && ! $request->get_param( 'slug' ) ) {
            self::$listing = true;
        }
        return $response;
    }

    public static function hide_from_lists( $query ) {
        if ( ! self::$listing || ! $query instanceof WP_Query || ( $query->get( 'post_type' ) !== 'product' && ! in_array( 'product', (array) $query->get( 'post_type' ), true ) ) ) return;
        $hidden = array_filter( array_map( 'intval', (array) get_option( 'qwoo_gc_hidden', [] ) ) );
        if ( $hidden ) $query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $hidden ) );
    }

    /** For the product editor: the settings, the store-wide rules and the cards sold. */
    public static function for_editor( WC_Product $product ): ?array {
        $s = self::settings( $product );
        if ( $s === null ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN status = %s THEN balance ELSE 0 END), 0) AS open FROM ' . self::cards_table() . ' WHERE product_id = %d', 'active', $product->get_id() ), ARRAY_A );
        $s['images'] = array_map( static fn( $id ) => [ 'id' => (int) $id, 'url' => (string) wp_get_attachment_image_url( $id, 'medium' ) ], (array) $s['images'] );
        return [ 'settings' => $s, 'rules' => self::rules(), 'sold' => (int) ( $row['n'] ?? 0 ), 'open' => (float) ( $row['open'] ?? 0 ), 'country' => WC()->countries->get_base_country() ];
    }

    /* ---------------- Store API: products and cart ---------------- */

    public static function register_store_api() {
        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) return;
        woocommerce_store_api_register_endpoint_data( [
            'endpoint'        => ProductSchema::IDENTIFIER,
            'namespace'       => 'qwoo_giftcard',
            'data_callback'   => [ __CLASS__, 'product_data' ],
            'schema_callback' => static fn() => [],
        ] );
        woocommerce_store_api_register_endpoint_data( [
            'endpoint'        => CartItemSchema::IDENTIFIER,
            'namespace'       => 'qwoo_giftcard',
            'data_callback'   => [ __CLASS__, 'cart_item_ext' ],
            'schema_callback' => static fn() => [],
        ] );
        woocommerce_store_api_register_endpoint_data( [
            'endpoint'        => CartSchema::IDENTIFIER,
            'namespace'       => 'qwoo_giftcard',
            'data_callback'   => [ __CLASS__, 'cart_ext' ],
            'schema_callback' => static fn() => [],
        ] );
        if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
            woocommerce_store_api_register_update_callback( [ 'namespace' => 'qwoo-giftcard', 'callback' => [ __CLASS__, 'cart_update' ] ] );
        }
    }

    /** What the gift card page needs; [] for other products. */
    public static function product_data( $product ): array {
        $s = $product instanceof WC_Product ? self::settings( $product ) : null;
        if ( $s === null ) return [];
        $images = [];
        foreach ( (array) $s['images'] as $id ) {
            $url = wp_get_attachment_image_url( (int) $id, 'large' );
            if ( $url ) $images[] = [ 'id' => (int) $id, 'url' => self::public_url( (string) $url ) ];
        }
        return [
            'amounts'   => array_map( 'floatval', (array) $s['amounts'] ),
            'custom'    => (bool) $s['custom'],
            'min'       => (float) $s['min'],
            'max'       => (float) $s['max'],
            'expiry'    => (int) $s['expiry'],
            'designs'   => array_values( array_map( static fn( $d ) => [ 'id' => $d['id'], 'name' => $d['name'], 'title' => $d['title'], 'mark' => $d['mark'] ], array_filter( (array) $s['designs'], static fn( $d ) => ! empty( $d['on'] ) ) ) ),
            'palette'   => ! empty( $s['colors'] ) ? array_values( (array) $s['palette'] ) : array_slice( (array) $s['palette'], 0, 1 ),
            'images'    => $images,
            'own_photo' => (bool) $s['own_photo'],
            'max_message' => self::MAX_MESSAGE,
            'preview'   => $s['preview'] === 'below' ? 'below' : 'replace',
            'timezone'  => wp_timezone_string(),
        ];
    }

    /** A gift line's details, checked; WP_Error otherwise. */
    public static function clean_gift( $in, WC_Product $product ) {
        $s = self::settings( $product );
        if ( $s === null || ! is_array( $in ) ) return new WP_Error( 'gc', self::l( 'Choose the gift card\'s details.', 'צריך לבחור את פרטי כרטיס המתנה.' ) );
        $amount = self::amount( $in['amount'] ?? '' );
        $listed = $amount !== null && in_array( $amount, array_map( 'floatval', (array) $s['amounts'] ), true );
        if ( $amount === null || ( ! $listed && ( ! $s['custom'] || $amount < (float) $s['min'] || $amount > (float) $s['max'] ) ) ) {
            return new WP_Error( 'gc', self::l( 'Choose one of the amounts.', 'צריך לבחור אחד מהסכומים.' ) );
        }
        $to_email = sanitize_email( (string) ( $in['to_email'] ?? '' ) );
        if ( ! is_email( $to_email ) ) return new WP_Error( 'gc', self::l( 'Enter the recipient\'s email address.', 'צריך למלא את כתובת המייל של מקבל המתנה.' ) );
        $to_name = mb_substr( sanitize_text_field( (string) ( $in['to_name'] ?? '' ) ), 0, 80 );
        if ( $to_name === '' ) return new WP_Error( 'gc', self::l( 'Enter the recipient\'s name.', 'צריך למלא את שם מקבל המתנה.' ) );
        $from    = mb_substr( sanitize_text_field( (string) ( $in['from'] ?? '' ) ), 0, 80 );
        $message = mb_substr( sanitize_textarea_field( (string) ( $in['message'] ?? '' ) ), 0, self::MAX_MESSAGE );
        $send    = (string) ( $in['send_on'] ?? '' );
        $time    = (string) ( $in['send_time'] ?? '09:00' );
        if ( ! preg_match( '/^([01]\d|2[0-3]):(00|30)$/', $time ) ) $time = '09:00';
        if ( $send !== '' ) {
            $date  = DateTime::createFromFormat( '!Y-m-d', $send, wp_timezone() );
            $today = new DateTime( 'today', wp_timezone() );
            if ( ! $date || $date->format( 'Y-m-d' ) !== $send || $date < $today || $date > ( clone $today )->modify( '+1 year' ) ) {
                return new WP_Error( 'gc', self::l( 'Choose a send date from today to a year from now.', 'צריך לבחור תאריך שליחה מהיום ועד שנה מהיום.' ) );
            }
            // A time that already passed today: right away.
            if ( self::send_moment( $send, $time ) <= time() ) $send = '';
        }
        if ( $send === '' ) $time = '';
        $designs = array_column( array_filter( (array) $s['designs'], static fn( $d ) => ! empty( $d['on'] ) ), null, 'id' );
        $design  = (string) ( $in['design'] ?? '' );
        if ( ! isset( $designs[ $design ] ) ) $design = (string) array_key_first( $designs );
        $palette = array_map( 'strtolower', (array) $s['palette'] );
        $color   = strtolower( (string) sanitize_hex_color( (string) ( $in['color'] ?? '' ) ) );
        if ( ! in_array( $color, $palette, true ) || ( empty( $s['colors'] ) && $color !== ( $palette[0] ?? '' ) ) ) $color = $palette[0] ?? self::COLORS[0];
        $image = (string) ( $in['image'] ?? '' );
        if ( preg_match( '/^p:(\d+)$/', $image, $m ) ) {
            if ( ! in_array( (int) $m[1], array_map( 'intval', (array) $s['images'] ), true ) ) $image = '';
        } elseif ( preg_match( '/^u:([a-f0-9]{32})$/', $image, $m ) ) {
            if ( empty( $s['own_photo'] ) || ! is_file( self::photo_path( $m[1] ) ) ) $image = '';
        } else {
            $image = '';
        }
        return [
            'amount'   => $amount,
            'to_name'  => $to_name,
            'to_email' => $to_email,
            'from'     => $from,
            'message'  => $message,
            'send_on'  => $send,
            'send_time' => $time,
            'design'   => $design,
            'color'    => $color,
            'image'    => $image,
            'key'      => substr( preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $in['key'] ?? '' ) ) ), 0, 24 ) ?: bin2hex( random_bytes( 6 ) ),
        ];
    }

    /** add-item with qwoo_gift: the details go on the cart line. */
    public static function add_to_cart_data( $data, $request ) {
        $product = wc_get_product( (int) ( $data['id'] ?? 0 ) );
        if ( ! $product || ! self::is_gift( $product ) ) return $data;
        $gift = self::clean_gift( $request['qwoo_gift'] ?? null, $product );
        if ( is_wp_error( $gift ) ) {
            throw new RouteException( 'qwoo_giftcard_details', esc_html( $gift->get_error_message() ), 400 );
        }
        $data['quantity']                     = 1;
        $data['cart_item_data'][ self::ITEM ] = $gift;
        return $data;
    }

    /** A gift card without its details (an old link, another route) isn't added. */
    public static function validate_add( $ok, $product_id, $quantity, $variation_id = 0, $variations = [], $cart_item_data = [] ) {
        if ( $ok && self::is_gift( $product_id ) && empty( $cart_item_data[ self::ITEM ] ) ) {
            wc_add_notice( self::l( 'Choose the gift card\'s details on its page.', 'צריך לבחור את פרטי כרטיס המתנה בעמוד שלו.' ), 'error' );
            return false;
        }
        return $ok;
    }

    public static function set_prices( $cart ) {
        if ( ! $cart instanceof WC_Cart ) return;
        foreach ( $cart->get_cart() as $item ) {
            if ( ! empty( $item[ self::ITEM ]['amount'] ) && isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
                $item['data']->set_price( (float) $item[ self::ITEM ]['amount'] );
            }
        }
    }

    public static function one_each( $max, $product, $cart_item = null ) {
        return $product instanceof WC_Product && self::is_gift( $product ) ? 1 : $max;
    }

    /** When a card dated $date at $time (store time) goes out; 09:00 when no time was chosen. */
    public static function send_moment( string $date, string $time = '' ): int {
        $d = DateTime::createFromFormat( 'Y-m-d H:i', $date . ' ' . ( preg_match( '/^\d\d:\d\d$/', $time ) ? $time : '09:00' ), wp_timezone() );
        return $d ? $d->getTimestamp() : time();
    }

    private static function send_label( string $date, string $time = '' ): string {
        if ( $date === '' ) return self::l( 'Right after payment', 'מיד אחרי התשלום' );
        return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), self::send_moment( $date, $time ) );
    }

    private static function design_name( $product_id, string $id ): string {
        $s = self::settings( (int) $product_id ) ?? self::defaults();
        foreach ( (array) $s['designs'] as $d ) {
            if ( $d['id'] === $id ) return (string) $d['name'];
        }
        return $id;
    }

    public static function item_data( $data, $item ) {
        $g = $item[ self::ITEM ] ?? null;
        if ( ! is_array( $g ) ) return $data;
        $data[] = [ 'key' => self::l( 'To', 'אל' ), 'value' => $g['to_name'] . ' (' . $g['to_email'] . ')' ];
        if ( $g['from'] !== '' ) $data[] = [ 'key' => self::l( 'From', 'מאת' ), 'value' => $g['from'] ];
        $data[] = [ 'key' => self::l( 'Sends', 'נשלח' ), 'value' => self::send_label( (string) $g['send_on'], (string) ( $g['send_time'] ?? '' ) ) ];
        if ( ( $g['message'] ?? '' ) !== '' ) $data[] = [ 'key' => self::l( 'Message', 'הודעה' ), 'value' => $g['message'] ];
        return $data;
    }

    /** Lines show who the card is for; the storefront matches lines by key. */
    public static function cart_item_ext( $item ): array {
        $g = is_array( $item ) ? ( $item[ self::ITEM ] ?? null ) : null;
        if ( ! is_array( $g ) ) return [];
        return [
            'key' => $g['key'], 'to_name' => $g['to_name'], 'to_email' => $g['to_email'], 'from' => $g['from'], 'message' => (string) ( $g['message'] ?? '' ),
            'send_on' => $g['send_on'], 'send_time' => (string) ( $g['send_time'] ?? '' ), 'amount' => (float) $g['amount'],
            'design' => (string) $g['design'], 'design_name' => self::design_name( $item['product_id'] ?? 0, (string) $g['design'] ),
            'color' => (string) $g['color'], 'image' => (string) $g['image'], 'image_url' => self::image_url( (string) $g['image'] ),
        ];
    }

    /** The cards paying for the cart: { applied: [ { code, last4, amount, balance } ], total } in minor units. */
    public static function cart_ext(): array {
        if ( ! WC()->cart ) return [ 'applied' => [], 'total' => '0' ];
        $minor = static fn( $v ) => (string) (int) round( (float) $v * ( 10 ** wc_get_price_decimals() ) );
        $out   = [];
        $sum   = 0.0;
        foreach ( self::$alloc as $a ) {
            $out[] = [ 'code' => $a['code'], 'last4' => substr( $a['code'], -4 ), 'amount' => $minor( $a['amount'] ), 'balance' => $minor( $a['balance'] ) ];
            $sum  += $a['amount'];
        }
        return [ 'applied' => $out, 'total' => $minor( $sum ) ];
    }

    private static function codes(): array {
        $codes = WC()->session ? WC()->session->get( self::SESSION, [] ) : [];
        return is_array( $codes ) ? array_values( array_filter( array_map( 'strval', $codes ) ) ) : [];
    }

    private static function set_codes( array $codes ): void {
        if ( WC()->session ) WC()->session->set( self::SESSION, array_values( array_unique( $codes ) ) );
    }

    public static function forget_codes() {
        if ( WC()->session ) WC()->session->set( self::SESSION, null );
    }

    /** cart/extensions { namespace: qwoo-giftcard, data: { action: apply | remove, code } }. */
    public static function cart_update( $data ) {
        $data   = is_array( $data ) ? $data : [];
        $code   = self::normal_code( (string) ( $data['code'] ?? '' ) );
        $codes  = self::codes();
        if ( ( $data['action'] ?? '' ) === 'remove' ) {
            self::set_codes( array_diff( $codes, [ $code ] ) );
            return;
        }
        $limit = qwoo_rate_limit_check( 'gc_apply', 8, 600 );
        if ( is_wp_error( $limit ) ) {
            throw new RouteException( 'qwoo_giftcard_limit', esc_html( self::l( 'Too many tries. Wait a few minutes and try again.', 'יותר מדי ניסיונות. כדאי לחכות כמה דקות ולנסות שוב.' ) ), 429 );
        }
        $card = $code !== '' ? self::by_code( $code ) : null;
        if ( ! $card ) {
            throw new RouteException( 'qwoo_giftcard_unknown', esc_html( self::l( 'This gift card code isn\'t right. Check it and try again.', 'הקוד של כרטיס המתנה לא נכון. כדאי לבדוק ולנסות שוב.' ) ), 404 );
        }
        $state = self::state( $card );
        $why   = [
            'scheduled' => self::l( 'This gift card isn\'t active yet.', 'כרטיס המתנה עוד לא פעיל.' ),
            'expired'   => self::l( 'This gift card has expired.', 'תוקף כרטיס המתנה פג.' ),
            'used'      => self::l( 'This gift card has no balance left.', 'לא נשארה יתרה בכרטיס המתנה.' ),
            'off'       => self::l( 'This gift card can\'t be used.', 'אי אפשר להשתמש בכרטיס המתנה הזה.' ),
        ][ $state ] ?? '';
        if ( $why !== '' ) throw new RouteException( 'qwoo_giftcard_' . $state, esc_html( $why ), 400 );
        if ( in_array( $code, $codes, true ) ) return;
        if ( count( $codes ) >= self::MAX_CODES ) {
            throw new RouteException( 'qwoo_giftcard_many', esc_html( self::l( 'Up to 5 gift cards on one order.', 'עד 5 כרטיסי מתנה בהזמנה אחת.' ) ), 400 );
        }
        $codes[] = $code;
        self::set_codes( $codes );
    }

    /** What the cards may pay for: everything but gift cards (and shipping, when the owner says so). */
    private static function payable( float $total, float $gift_lines, float $shipping ): float {
        $rules = self::rules();
        $left  = $total - $gift_lines - ( $rules['shipping'] ? 0 : $shipping );
        return max( 0.0, self::round( $left ) );
    }

    /** The cards in the cart and what each pays, in order; drops codes that stopped working. */
    private static function allocate( float $payable ): array {
        $codes = self::codes();
        $keep  = [];
        $out   = [];
        foreach ( $codes as $code ) {
            $card = self::by_code( $code );
            if ( ! $card || ! self::usable( $card ) ) continue;
            $keep[] = $code;
            $use    = self::round( min( (float) $card['balance'], $payable ) );
            $payable = self::round( $payable - $use );
            $out[]  = [ 'id' => (int) $card['id'], 'code' => $card['code'], 'amount' => $use, 'balance' => (float) $card['balance'] ];
        }
        if ( count( $keep ) !== count( $codes ) ) self::set_codes( $keep );
        return $out;
    }

    private static function gift_lines_total( WC_Cart $cart ): float {
        $sum = 0.0;
        foreach ( $cart->get_cart() as $item ) {
            if ( ! empty( $item[ self::ITEM ] ) ) $sum += (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 );
        }
        return $sum;
    }

    public static function cart_total( $total, $cart ) {
        self::$alloc = [];
        if ( ! $cart instanceof WC_Cart || ! self::codes() ) return $total;
        $shipping    = (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
        self::$alloc = self::allocate( self::payable( (float) $total, self::gift_lines_total( $cart ), $shipping ) );
        $paid        = array_sum( array_column( self::$alloc, 'amount' ) );
        return max( 0, self::round( (float) $total - $paid ) );
    }

    /* ---------------- coupons ---------------- */

    public static function coupon_for_product( $valid, $product ) {
        if ( $valid && $product instanceof WC_Product && self::is_gift( $product->get_parent_id() ?: $product ) && ! self::rules()['discounts'] ) return false;
        return $valid;
    }

    /* ---------------- orders ---------------- */

    public static function order_line( $item, $cart_item_key, $values ) {
        $g = $values[ self::ITEM ] ?? null;
        if ( ! is_array( $g ) ) return;
        $item->add_meta_data( self::ORDER_ITEM, $g, true );
        $item->add_meta_data( self::l( 'To', 'אל' ), $g['to_name'] . ' (' . $g['to_email'] . ')', true );
        $item->add_meta_data( self::l( 'Sends', 'נשלח' ), self::send_label( (string) $g['send_on'], (string) ( $g['send_time'] ?? '' ) ), true );
        if ( ( $g['from'] ?? '' ) !== '' ) $item->add_meta_data( self::l( 'From', 'מאת' ), $g['from'], true );
        if ( ( $g['message'] ?? '' ) !== '' ) $item->add_meta_data( self::l( 'Message', 'הודעה' ), $g['message'], true );
        $item->add_meta_data( self::l( 'Design', 'עיצוב' ), self::design_name( $values['product_id'] ?? 0, (string) $g['design'] ), true );
    }

    public static function hide_item_meta( $meta, $item ) {
        return array_filter( (array) $meta, static fn( $m ) => ! in_array( $m->key ?? '', [ self::ORDER_ITEM, self::CARD_META ], true ) );
    }

    /**
     * After an order's totals: the cards' part comes off. While the Store API
     * builds the order from the cart (a checkout draft), the cards come from the
     * cart; afterwards from what the order saved, so recalculating keeps them.
     */
    public static function order_total( $and_taxes, $order ) {
        if ( ! $order instanceof WC_Order ) return;
        $used = $order->get_meta( self::USED_META );
        if ( $order->get_status() === 'checkout-draft' && WC()->cart ) {
            $gift = 0.0;
            foreach ( $order->get_items() as $item ) {
                if ( $item->get_meta( self::ORDER_ITEM ) ) $gift += (float) $item->get_total() + (float) $item->get_total_tax();
            }
            $shipping = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
            $used     = array_map( static fn( $a ) => [ 'id' => $a['id'], 'last4' => substr( $a['code'], -4 ), 'amount' => $a['amount'] ], self::allocate( self::payable( (float) $order->get_total(), $gift, $shipping ) ) );
            $used     = array_values( array_filter( $used, static fn( $a ) => $a['amount'] > 0 ) );
            if ( $used ) $order->update_meta_data( self::USED_META, $used );
            else $order->delete_meta_data( self::USED_META );
        }
        if ( ! is_array( $used ) || ! $used ) return;
        $paid = array_sum( array_column( $used, 'amount' ) );
        $order->set_total( max( 0, self::round( (float) $order->get_total() - $paid ) ) );
    }

    /** Placing the order takes the amounts from the cards (or stops the order when a balance changed). */
    public static function on_placed( $order ) {
        if ( ! $order instanceof WC_Order ) return;
        $ok = self::take_for( $order );
        if ( ! $ok ) {
            throw new RouteException( 'qwoo_giftcard_changed', esc_html( self::l( 'A gift card\'s balance changed. Check your order and try again.', 'היתרה של כרטיס מתנה השתנתה. כדאי לבדוק את ההזמנה ולנסות שוב.' ) ), 409 );
        }
    }

    /** Takes the order's card amounts once. False when a card can't pay its part any more (nothing is taken then). */
    private static function take_for( WC_Order $order ): bool {
        global $wpdb;
        $used  = (array) ( $order->get_meta( self::USED_META ) ?: [] );
        $taken = (array) ( $order->get_meta( self::TAKEN_META ) ?: [] );
        $want  = [];
        foreach ( $used as $u ) $want[ (int) $u['id'] ] = self::round( $u['amount'] );
        if ( $taken == $want ) return true;
        self::give_back( $order, self::l( 'The order changed', 'ההזמנה השתנתה' ) );
        $done = [];
        $now  = self::now();
        foreach ( $want as $id => $amount ) {
            if ( $amount <= 0 ) continue;
            $rows = $wpdb->query( $wpdb->prepare(
                'UPDATE ' . self::cards_table() . " SET balance = balance - %f, updated_at = %s WHERE id = %d AND status = 'active' AND balance >= %f AND (expires_at IS NULL OR expires_at > %s)",
                $amount, $now, $id, $amount - 0.00001, $now
            ) );
            if ( $rows !== 1 ) {
                foreach ( $done as $did => $back ) {
                    $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::cards_table() . ' SET balance = balance + %f, updated_at = %s WHERE id = %d', $back, $now, $did ) );
                }
                return false;
            }
            $done[ $id ] = $amount;
            self::log( $id, 'used', -$amount, $order->get_id() );
        }
        $order->update_meta_data( self::TAKEN_META, $done );
        $order->save_meta_data();
        return true;
    }

    /** The amounts taken for an order go back on the cards. */
    private static function give_back( WC_Order $order, string $why ): void {
        global $wpdb;
        $taken = (array) ( $order->get_meta( self::TAKEN_META ) ?: [] );
        if ( ! $taken ) return;
        $now = self::now();
        foreach ( $taken as $id => $amount ) {
            $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::cards_table() . ' SET balance = balance + %f, updated_at = %s WHERE id = %d', $amount, $now, (int) $id ) );
            self::log( (int) $id, 'returned', (float) $amount, $order->get_id(), $why );
        }
        $order->delete_meta_data( self::TAKEN_META );
        $order->save_meta_data();
    }

    public static function on_status( $order_id, $from, $to, $order ) {
        if ( ! $order instanceof WC_Order ) $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( in_array( $to, [ 'cancelled', 'failed', 'refunded' ], true ) ) {
            $why = [ 'cancelled' => self::l( 'Order cancelled', 'ההזמנה בוטלה' ), 'failed' => self::l( 'Payment failed', 'התשלום נכשל' ), 'refunded' => self::l( 'Order refunded', 'ההזמנה זוכתה' ) ][ $to ];
            self::give_back( $order, $why );
            if ( $to !== 'failed' ) self::stop_bought( $order, $why );
            return;
        }
        // Back from failed or cancelled (paid in the end): take again.
        if ( in_array( $to, [ 'processing', 'completed', 'on-hold' ], true ) && $order->get_meta( self::USED_META ) && ! $order->get_meta( self::TAKEN_META ) ) {
            if ( ! self::take_for( $order ) ) {
                $order->add_order_note( self::l( 'A gift card on this order no longer has the balance it paid. Check the gift cards in Marketing → Gift cards.', 'לכרטיס מתנה בהזמנה הזו כבר אין את היתרה ששילמה. כדאי לבדוק בשיווק ← כרטיסי מתנה.' ) );
            }
        }
        if ( in_array( $to, wc_get_is_paid_statuses(), true ) ) self::issue_bought( $order );
    }

    /** Totals in emails and order pages: a line for each card. */
    public static function order_item_totals( $rows, $order ) {
        $used = $order instanceof WC_Order ? $order->get_meta( self::USED_META ) : null;
        if ( ! is_array( $used ) || ! $used ) return $rows;
        $out = [];
        foreach ( (array) $rows as $key => $row ) {
            if ( $key === 'order_total' ) {
                foreach ( $used as $n => $u ) {
                    $out[ 'qwoo_gc_' . $n ] = [ 'label' => self::l( 'Gift card', 'כרטיס מתנה' ) . ' ••••' . $u['last4'] . ':', 'value' => '−' . wc_price( $u['amount'], [ 'currency' => $order->get_currency() ] ) ];
                }
            }
            $out[ $key ] = $row;
        }
        return $out;
    }

    /** For the dashboard's order view. */
    public static function for_order( WC_Order $order ): array {
        global $wpdb;
        $used  = (array) ( $order->get_meta( self::USED_META ) ?: [] );
        $bought = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, code, amount, balance, status, expires_at, to_name, to_email, send_at, sent_at, email_status FROM ' . self::cards_table() . ' WHERE order_id = %d', $order->get_id() ), ARRAY_A );
        return [
            'paid_with' => array_map( static fn( $u ) => [ 'id' => (int) $u['id'], 'last4' => (string) $u['last4'], 'amount' => (float) $u['amount'] ], $used ),
            'bought'    => array_map( static fn( $c ) => [ 'id' => (int) $c['id'], 'last4' => substr( $c['code'], -4 ), 'amount' => (float) $c['amount'], 'to_name' => $c['to_name'], 'to_email' => $c['to_email'], 'state' => self::state( $c ), 'email' => $c['email_status'] ], $bought ),
        ];
    }

    /** A paid order: a card for each gift line (once), sent on its day. */
    private static function issue_bought( WC_Order $order ): void {
        global $wpdb;
        $made = false;
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) continue;
            $g = $item->get_meta( self::ORDER_ITEM );
            if ( ! is_array( $g ) || $item->get_meta( self::CARD_META ) ) continue;
            $s       = self::settings( $item->get_product_id() ) ?? self::defaults();
            $send_at = self::now();
            if ( ! empty( $g['send_on'] ) ) {
                $at = self::send_moment( (string) $g['send_on'], (string) ( $g['send_time'] ?? '' ) );
                if ( $at > time() ) $send_at = gmdate( 'Y-m-d H:i:s', $at );
            }
            $id = self::insert( [
                'amount'       => (float) $g['amount'],
                'currency'     => $order->get_currency(),
                'order_id'     => $order->get_id(),
                'item_id'      => $item->get_id(),
                'product_id'   => $item->get_product_id(),
                'customer_id'  => $order->get_customer_id(),
                'buyer_name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'buyer_email'  => $order->get_billing_email(),
                'to_name'      => (string) $g['to_name'],
                'to_email'     => (string) $g['to_email'],
                'from_name'    => (string) $g['from'],
                'message'      => (string) $g['message'],
                'design'       => (string) $g['design'],
                'color'        => (string) $g['color'],
                'image'        => (string) $g['image'],
                'expiry_years' => (int) $s['expiry'],
                'send_at'      => $send_at,
            ], 'bought', '', $order->get_id() );
            if ( ! $id ) continue;
            $item->update_meta_data( self::CARD_META, $id );
            $item->save_meta_data();
            $made = true;
        }
        if ( $made ) self::wake();
    }

    /** Cards bought on a cancelled or refunded order: not sent, or turned off while unused. */
    private static function stop_bought( WC_Order $order, string $why ): void {
        global $wpdb;
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::cards_table() . " WHERE order_id = %d AND status <> 'disabled'", $order->get_id() ), ARRAY_A ) as $c ) {
            if ( $c['status'] === 'scheduled' || (float) $c['balance'] >= (float) $c['amount'] ) {
                $wpdb->update( self::cards_table(), [ 'status' => 'disabled', 'updated_at' => self::now() ], [ 'id' => (int) $c['id'] ] );
                self::log( (int) $c['id'], 'disabled', null, $order->get_id(), $why );
            } else {
                $order->add_order_note( sprintf( self::l( 'Gift card ••••%s was already used, so it stays on. You can turn it off in Marketing → Gift cards.', 'כרטיס המתנה ••••%s כבר נוצל, ולכן הוא נשאר פעיל. אפשר לכבות אותו בשיווק ← כרטיסי מתנה.' ), substr( $c['code'], -4 ) ) );
            }
        }
    }

    private static function insert( array $f, string $event, string $who = '', int $order_id = 0 ): int {
        global $wpdb;
        $now = self::now();
        $row = $f + [
            'code'         => self::new_code(),
            'status'       => 'scheduled',
            'email_status' => 'scheduled',
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
        $row['balance'] = (float) $f['amount'];
        if ( ! $wpdb->insert( self::cards_table(), $row ) ) return 0;
        $id = (int) $wpdb->insert_id;
        self::log( $id, $event, (float) $f['amount'], $order_id, (string) ( $f['note'] ?? '' ), $who );
        return $id;
    }

    /* ---------------- sending ---------------- */

    private static function wake(): void {
        if ( ! class_exists( 'Qwoo_Push_Engine' ) ) return;
        Qwoo_Push_Engine::note_due( time() );
        Qwoo_Push_Engine::spawn_soon();
    }

    public static function next_due( $due ) {
        global $wpdb;
        $next = $wpdb->get_var( 'SELECT MIN(send_at) FROM ' . self::cards_table() . " WHERE status = 'scheduled' AND attempts < 5" );
        $unix = $next ? strtotime( $next . ' UTC' ) : 0;
        if ( ! $unix ) return $due;
        return $due && $due < $unix ? $due : $unix;
    }

    public static function send_due( float $until ) {
        global $wpdb;
        self::cleanup_photos();
        $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::cards_table() . " WHERE status = 'scheduled' AND attempts < 5 AND send_at <= %s ORDER BY send_at LIMIT %d", self::now(), self::SEND_BATCH ), ARRAY_A );
        foreach ( $rows as $card ) {
            if ( microtime( true ) > $until ) break;
            self::deliver( $card, '' );
        }
    }

    /**
     * Sends the card's email. The first send makes it active and starts its
     * expiry. A failed send tries again later (up to 5 times).
     */
    private static function deliver( array $card, string $who ): bool {
        global $wpdb;
        $first = $card['status'] === 'scheduled';
        $now   = time();
        $ok    = self::send_email( $card );
        if ( ! $ok ) {
            $tries = (int) $card['attempts'] + 1;
            $wpdb->update( self::cards_table(), [ 'attempts' => $tries, 'send_at' => gmdate( 'Y-m-d H:i:s', $now + 900 * $tries ), 'email_status' => $tries >= 5 ? 'failed' : $card['email_status'], 'updated_at' => self::now() ], [ 'id' => (int) $card['id'] ] );
            if ( $tries >= 5 || ! $first ) self::log( (int) $card['id'], 'send_failed', null, 0, '', $who );
            return false;
        }
        $set = [ 'email_status' => 'sent', 'attempts' => 0, 'updated_at' => self::now(), 'delivered_at' => null, 'opened_at' => null ];
        if ( $first ) {
            $set['status']  = 'active';
            $set['sent_at'] = self::now();
            $years          = (int) $card['expiry_years'];
            $set['expires_at'] = $years > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( '+' . $years . ' years', $now ) ) : null;
        }
        $wpdb->update( self::cards_table(), $set, [ 'id' => (int) $card['id'] ] );
        self::log( (int) $card['id'], $first ? 'sent' : 'resent', null, 0, (string) $card['to_email'], $who );
        return true;
    }

    private static function sign( string $what ): string {
        return substr( hash_hmac( 'sha256', $what, wp_salt( 'auth' ) . 'qwoo-giftcard' ), 0, 24 );
    }

    private static function front(): string {
        return class_exists( 'Qwoo_Technical_Settings' ) ? rtrim( (string) Qwoo_Technical_Settings::get_primary_frontend_domain(), '/' ) : '';
    }

    /** An uploads address on the storefront's own domain (it serves the uploads folder). */
    public static function public_url( string $url ): string {
        $front = self::front();
        $base  = (string) ( wp_get_upload_dir()['baseurl'] ?? '' );
        if ( $front !== '' && $base !== '' && strpos( $url, $base ) === 0 ) {
            return $front . '/wp-content/uploads' . substr( $url, strlen( $base ) );
        }
        return $url;
    }

    private static function image_url( string $image ): string {
        if ( preg_match( '/^p:(\d+)$/', $image, $m ) ) {
            $url = wp_get_attachment_image_url( (int) $m[1], 'large' );
            return $url ? self::public_url( (string) $url ) : '';
        }
        if ( preg_match( '/^u:([a-f0-9]{32})$/', $image, $m ) && is_file( self::photo_path( $m[1] ) ) ) {
            return self::public_url( trailingslashit( wp_get_upload_dir()['baseurl'] ) . self::PHOTO_DIR . '/' . $m[1] . '.jpg' );
        }
        return '';
    }

    private static function design_of( array $card ): array {
        $s = self::settings( (int) $card['product_id'] ) ?? self::defaults();
        foreach ( (array) $s['designs'] as $d ) {
            if ( $d['id'] === $card['design'] ) return $d;
        }
        foreach ( self::default_designs() as $d ) {
            if ( $d['id'] === $card['design'] ) return $d;
        }
        return [ 'id' => 'simple', 'title' => self::l( 'A gift for you', 'מתנה בשבילך' ), 'mark' => '🎁' ];
    }

    private static function date_label( ?string $gmt ): string {
        return $gmt ? wp_date( get_option( 'date_format' ), strtotime( $gmt . ' UTC' ) ) : '';
    }

    /** What the email's gift blocks show (Qwoo_Email_Render's ctx.gift). */
    public static function gift_ctx( array $card ): array {
        $d       = self::design_of( $card );
        $expires = $card['expires_at'] ?? null;
        if ( ! $expires && (int) $card['expiry_years'] > 0 && $card['status'] === 'scheduled' ) {
            $expires = gmdate( 'Y-m-d H:i:s', strtotime( '+' . (int) $card['expiry_years'] . ' years' ) );
        }
        return [
            'title'   => (string) ( $d['title'] ?? '' ),
            'mark'    => (string) ( $d['mark'] ?? '' ),
            'color'   => sanitize_hex_color( (string) $card['color'] ) ?: self::COLORS[0],
            'image'   => self::image_url( (string) $card['image'] ),
            'amount'  => html_entity_decode( wp_strip_all_tags( wc_price( (float) $card['amount'], [ 'currency' => $card['currency'] ?: get_woocommerce_currency() ] ) ), ENT_QUOTES ),
            'code'    => (string) $card['code'],
            'expiry'  => $expires ? sprintf( self::l( 'Valid until %s', 'בתוקף עד %s' ), self::date_label( $expires ) ) : self::l( 'Never expires', 'ללא תאריך תפוגה' ),
            'to'      => (string) $card['to_name'],
            'from'    => (string) $card['from_name'],
            'message' => (string) $card['message'],
            'shop'    => self::front() !== '' ? self::front() . '/' : home_url( '/' ),
        ];
    }

    public static function email_args( array $card ): array {
        return [
            'recipient_name'  => (string) $card['to_name'],
            'recipient_email' => (string) $card['to_email'],
            'sender_name'     => (string) ( $card['from_name'] ?: $card['buyer_name'] ),
            'gift_amount'     => html_entity_decode( wp_strip_all_tags( wc_price( (float) $card['amount'], [ 'currency' => $card['currency'] ?: get_woocommerce_currency() ] ) ), ENT_QUOTES ),
            'first_name'      => trim( explode( ' ', (string) $card['buyer_name'] )[0] ?? '' ),
        ];
    }

    /** The email's HTML; with $track, the opens pixel. */
    public static function email_html( array $card, bool $track ): string {
        $html = Qwoo_Store_Emails::render_own( 'gift_card', self::email_args( $card ), [ 'gift' => self::gift_ctx( $card ) ] );
        if ( $track ) {
            $base  = self::front() !== '' ? self::front() . '/wp-json/' . self::NS . '/' : rest_url( self::NS . '/' );
            $pixel = '<img src="' . esc_url( $base . 'giftcard/o?' . http_build_query( [ 'i' => (int) $card['id'], 'k' => self::sign( 'o' . (int) $card['id'] ) ] ) ) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';
            $html  = str_replace( '</body>', $pixel . '</body>', $html );
        }
        return $html;
    }

    private static function send_email( array $card ): bool {
        try {
            $html    = self::email_html( $card, true );
            $subject = Qwoo_Store_Emails::own_subject( 'gift_card', self::email_args( $card ), self::l( '{sender_name} sent you a {store_name} gift card', '{sender_name} שלחו לך כרטיס מתנה מ{store_name}' ) );
            $headers = Qwoo_Store_Emails::headers( "Content-Type: text/html; charset=UTF-8\r\nX-Qwoo-Tag: gc-" . (int) $card['id'] . "\r\n", 'gift_card' );
            return (bool) wp_mail( (string) $card['to_email'], $subject, $html, $headers );
        } catch ( Throwable $e ) {
            error_log( '[qwoo giftcard] send ' . (int) $card['id'] . ': ' . $e->getMessage() ); // phpcs:ignore
            return false;
        }
    }

    /** From the platform (Resend through its webhook): delivered, bounced or complained. */
    public static function email_event( string $ref, string $type, string $email ): void {
        global $wpdb;
        if ( ! preg_match( '/^gc-(\d+)$/', $ref, $m ) ) return;
        $card = self::card( (int) $m[1] );
        if ( ! $card || strtolower( $card['to_email'] ) !== strtolower( $email ) ) return;
        if ( $type === 'delivered' ) {
            if ( in_array( $card['email_status'], [ 'sent', 'failed' ], true ) ) {
                $wpdb->update( self::cards_table(), [ 'email_status' => 'delivered', 'delivered_at' => self::now() ], [ 'id' => (int) $card['id'] ] );
                self::log( (int) $card['id'], 'delivered' );
            }
            return;
        }
        if ( $type === 'bounced' || $type === 'complained' ) {
            $wpdb->update( self::cards_table(), [ 'email_status' => 'bounced', 'updated_at' => self::now() ], [ 'id' => (int) $card['id'] ] );
            self::log( (int) $card['id'], $type === 'bounced' ? 'bounced' : 'complained' );
            if ( $type === 'bounced' && is_email( $card['buyer_email'] ) ) {
                Qwoo_Store_Emails::send_own( 'gift_card_bounced', (string) $card['buyer_email'], self::l( 'We couldn\'t deliver your gift card', 'לא הצלחנו למסור את כרטיס המתנה שלך' ), self::email_args( $card ) );
            }
        }
    }

    /* ---------------- public addresses ---------------- */

    public static function routes() {
        register_rest_route( self::NS, '/giftcard/o', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_open' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( self::NS, '/giftcard/preview', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_preview' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( self::NS, '/giftcard/photo', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_photo' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( self::NS, '/my-giftcards', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_mine' ], 'permission_callback' => 'qwoo_require_login' ] );
    }

    /**
     * My account → Gift cards: cards sent to the shopper's email (sent ones
     * only) and cards they bought. Codes show only their last 4 characters:
     * an account's email isn't proven to be theirs, the email with the code is.
     */
    public static function rest_mine() {
        global $wpdb;
        $user  = wp_get_current_user();
        $email = strtolower( (string) $user->user_email );
        $t     = self::cards_table();
        $date  = static fn( $gmt ) => $gmt ? self::date_label( $gmt ) : '';
        $money = static fn( $c, $v ) => html_entity_decode( wp_strip_all_tags( wc_price( (float) $v, [ 'currency' => $c['currency'] ?: get_woocommerce_currency() ] ) ), ENT_QUOTES );
        $got   = $email !== '' ? (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE LOWER(to_email) = %s AND status <> 'scheduled' ORDER BY id DESC LIMIT 50", $email ), ARRAY_A ) : [];
        $gave  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE customer_id = %d AND customer_id > 0 ORDER BY id DESC LIMIT 50", (int) $user->ID ), ARRAY_A );
        $res   = rest_ensure_response( [
            'received' => array_map( static fn( $c ) => [
                'last4'   => substr( $c['code'], -4 ),
                'state'   => self::state( $c ),
                'balance' => $money( $c, $c['balance'] ),
                'amount'  => $money( $c, $c['amount'] ),
                'from'    => (string) $c['from_name'],
                'expires' => $date( $c['expires_at'] ),
                'sent'    => $date( $c['sent_at'] ),
            ], $got ),
            'bought'   => array_map( static fn( $c ) => [
                'to_name' => (string) $c['to_name'],
                'amount'  => $money( $c, $c['amount'] ),
                'order'   => (int) $c['order_id'],
                'state'   => self::state( $c ),
                'email'   => in_array( $c['email_status'], [ 'bounced', 'failed' ], true ) ? 'problem' : ( $c['status'] === 'scheduled' ? 'scheduled' : 'sent' ),
                'when'    => $date( $c['sent_at'] ?: $c['send_at'] ),
            ], $gave ),
        ] );
        $res->header( 'Cache-Control', 'no-store' );
        return $res;
    }

    public static function rest_open( WP_REST_Request $r ) {
        global $wpdb;
        $id = (int) $r->get_param( 'i' );
        if ( $id && hash_equals( self::sign( 'o' . $id ), (string) $r->get_param( 'k' ) ) ) {
            $did = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::cards_table() . " SET opened_at = %s, email_status = CASE WHEN email_status = 'bounced' THEN email_status ELSE 'opened' END WHERE id = %d AND opened_at IS NULL", self::now(), $id ) );
            if ( $did ) self::log( $id, 'opened' );
        }
        header( 'Content-Type: image/gif' );
        header( 'Cache-Control: no-store' );
        echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore
        exit;
    }

    /**
     * The gift card email for the storefront's preview, with {gc_*} places the
     * page fills in as the shopper types (escaped there). Same for every
     * shopper, so it's cached briefly.
     */
    public static function rest_preview( WP_REST_Request $r ) {
        $product = wc_get_product( (int) $r->get_param( 'product' ) );
        if ( ! $product || ! self::is_gift( $product ) || $product->get_status() !== 'publish' ) {
            return new WP_Error( 'qwoo_giftcard_missing', 'Not a gift card.', [ 'status' => 404 ] );
        }
        $design = Qwoo_Store_Emails::design_for( 'gift_card' );
        $key    = 'qwoo_gc_pv_' . md5( wp_json_encode( $design ) . get_bloginfo( 'name' ) . Qwoo_Store_Emails::style()['logo'] );
        $html   = get_transient( $key );
        if ( ! is_string( $html ) ) {
            $html = Qwoo_Store_Emails::render_own( 'gift_card', [ 'recipient_name' => '{gc_to}', 'sender_name' => '{gc_from}', 'gift_amount' => '{gc_amount}', 'recipient_email' => '', 'first_name' => '' ], [ 'gift' => [ 'template' => true, 'shop' => self::front() !== '' ? self::front() . '/' : home_url( '/' ) ] ] );
            set_transient( $key, $html, 10 * MINUTE_IN_SECONDS );
        }
        $res = rest_ensure_response( [ 'html' => $html ] );
        $res->header( 'Cache-Control', 'public, max-age=120' );
        return $res;
    }

    private static function photo_dir(): string {
        return trailingslashit( wp_get_upload_dir()['basedir'] ) . self::PHOTO_DIR;
    }

    private static function photo_path( string $name ): string {
        return self::photo_dir() . '/' . $name . '.jpg';
    }

    /**
     * A shopper's own photo for the email: { data: "data:image/jpeg;base64,…" }.
     * Only real JPG, PNG or WebP photos; saved again as a new JPG (nothing of
     * the original file is kept), under a name nobody can guess.
     */
    public static function rest_photo( WP_REST_Request $r ) {
        $limit = qwoo_rate_limit_check( 'gc_photo', 12, HOUR_IN_SECONDS );
        if ( is_wp_error( $limit ) ) return $limit;
        $product = wc_get_product( (int) $r->get_param( 'product' ) );
        $s       = $product ? self::settings( $product ) : null;
        if ( ! $s || empty( $s['own_photo'] ) ) return new WP_Error( 'qwoo_giftcard_photo', self::l( 'Photos can\'t be added to this gift card.', 'אי אפשר להוסיף תמונה לכרטיס המתנה הזה.' ), [ 'status' => 403 ] );
        $data = (string) $r->get_param( 'data' );
        if ( ! preg_match( '#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $data, $m ) ) {
            return new WP_Error( 'qwoo_giftcard_photo', self::l( 'Choose a JPG or PNG photo.', 'צריך לבחור תמונת JPG או PNG.' ), [ 'status' => 400 ] );
        }
        $bytes = base64_decode( $m[2], true );
        if ( $bytes === false || strlen( $bytes ) > self::PHOTO_BYTES ) {
            return new WP_Error( 'qwoo_giftcard_photo', self::l( 'The photo is too big. Choose a smaller one.', 'התמונה גדולה מדי. כדאי לבחור תמונה קטנה יותר.' ), [ 'status' => 400 ] );
        }
        $info = @getimagesizefromstring( $bytes ); // phpcs:ignore
        if ( ! $info || ! in_array( $info[2] ?? 0, [ IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ], true ) || $info[0] < 50 || $info[1] < 50 || $info[0] * $info[1] > 40000000 ) {
            return new WP_Error( 'qwoo_giftcard_photo', self::l( 'This file isn\'t a photo we can use.', 'אי אפשר להשתמש בקובץ הזה.' ), [ 'status' => 400 ] );
        }
        wp_mkdir_p( self::photo_dir() );
        if ( ! is_file( self::photo_dir() . '/index.php' ) ) file_put_contents( self::photo_dir() . '/index.php', "<?php\n// Silence.\n" );
        $tmp = wp_tempnam( 'qwoo-gc' );
        file_put_contents( $tmp, $bytes );
        $editor = wp_get_image_editor( $tmp );
        if ( is_wp_error( $editor ) ) {
            @unlink( $tmp ); // phpcs:ignore
            return new WP_Error( 'qwoo_giftcard_photo', self::l( 'This file isn\'t a photo we can use.', 'אי אפשר להשתמש בקובץ הזה.' ), [ 'status' => 400 ] );
        }
        $editor->resize( self::PHOTO_SIDE, self::PHOTO_SIDE, false );
        $editor->set_quality( 82 );
        $name  = bin2hex( random_bytes( 16 ) );
        $saved = $editor->save( self::photo_path( $name ), 'image/jpeg' );
        @unlink( $tmp ); // phpcs:ignore
        if ( is_wp_error( $saved ) ) {
            return new WP_Error( 'qwoo_giftcard_photo', self::l( 'The photo couldn\'t be saved. Try again.', 'לא הצלחנו לשמור את התמונה. כדאי לנסות שוב.' ), [ 'status' => 500 ] );
        }
        return rest_ensure_response( [ 'image' => 'u:' . $name, 'url' => self::image_url( 'u:' . $name ) ] );
    }

    /** Shoppers' photos no card uses, a day after they were added (once a day). */
    private static function cleanup_photos(): void {
        global $wpdb;
        if ( get_transient( 'qwoo_gc_photo_clean' ) ) return;
        set_transient( 'qwoo_gc_photo_clean', 1, DAY_IN_SECONDS );
        $files = glob( self::photo_dir() . '/*.jpg' ) ?: [];
        foreach ( array_slice( $files, 0, 500 ) as $file ) {
            if ( filemtime( $file ) > time() - 2 * DAY_IN_SECONDS ) continue;
            $name = basename( $file, '.jpg' );
            $used = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::cards_table() . ' WHERE image = %s LIMIT 1', 'u:' . $name ) );
            if ( ! $used ) @unlink( $file ); // phpcs:ignore
        }
    }

    /* ---------------- the dashboard (growth action, gc_* operations) ---------------- */

    private static function row_out( array $c ): array {
        return [
            'id'           => (int) $c['id'],
            'code'         => (string) $c['code'],
            'state'        => self::state( $c ),
            'amount'       => (float) $c['amount'],
            'balance'      => (float) $c['balance'],
            'order_id'     => (int) $c['order_id'],
            'buyer_name'   => (string) $c['buyer_name'],
            'buyer_email'  => (string) $c['buyer_email'],
            'to_name'      => (string) $c['to_name'],
            'to_email'     => (string) $c['to_email'],
            'email'        => (string) $c['email_status'],
            'send_at'      => self::iso( $c['send_at'] ),
            'sent_at'      => self::iso( $c['sent_at'] ),
            'expires_at'   => self::iso( $c['expires_at'] ),
            'expiry_years' => (int) $c['expiry_years'],
            'last_used'    => self::iso( $c['last_used'] ?? null ),
        ];
    }

    private static function iso( $gmt ): string {
        return $gmt ? gmdate( 'c', strtotime( $gmt . ' UTC' ) ) : '';
    }

    public static function dashboard( string $op, array $p, string $who ) {
        switch ( $op ) {
            case 'gc_list':      return self::list( $p );
            case 'gc_get':       return self::get( (int) ( $p['id'] ?? 0 ) );
            case 'gc_issue':     return self::issue( $p, $who );
            case 'gc_update':    return self::update( $p, $who );
            case 'gc_preview':
                // The dashboard draws the email from its design (its page allows no inline styles).
                $c = self::card( (int) ( $p['id'] ?? 0 ) );
                if ( ! $c ) return new WP_Error( 'missing', 'That gift card doesn\'t exist.' );
                $args = self::email_args( $c );
                return [
                    'design'  => Qwoo_Store_Emails::design_for( 'gift_card' ),
                    'logo'    => Qwoo_Store_Emails::style()['logo'],
                    'gift'    => self::gift_ctx( $c ),
                    'tokens'  => [ 'recipient_name' => $args['recipient_name'], 'sender_name' => $args['sender_name'], 'gift_amount' => $args['gift_amount'] ],
                    'subject' => Qwoo_Store_Emails::own_subject( 'gift_card', $args, self::l( '{sender_name} sent you a {store_name} gift card', '{sender_name} שלחו לך כרטיס מתנה מ{store_name}' ) ),
                ];
        }
        return new WP_Error( 'unknown', 'Unknown action.' );
    }

    private static function list( array $p ): array {
        global $wpdb;
        $t      = self::cards_table();
        $page   = max( 1, min( 1000, (int) ( $p['page'] ?? 1 ) ) );
        $per    = 25;
        $where  = [ '1=1' ];
        $args   = [];
        $search = mb_substr( trim( sanitize_text_field( (string) ( $p['search'] ?? '' ) ) ), 0, 100 );
        if ( $search !== '' ) {
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(code LIKE %s OR to_name LIKE %s OR to_email LIKE %s OR buyer_name LIKE %s OR buyer_email LIKE %s OR order_id = %d)';
            array_push( $args, $like, $like, $like, $like, $like, (int) ltrim( $search, '#' ) );
        }
        $now = self::now();
        switch ( (string) ( $p['state'] ?? '' ) ) {
            case 'active':    $where[] = "status = 'active' AND balance > 0 AND (expires_at IS NULL OR expires_at > %s)"; $args[] = $now; break;
            case 'scheduled': $where[] = "status = 'scheduled'"; break;
            case 'used':      $where[] = "status = 'active' AND balance <= 0"; break;
            case 'expired':   $where[] = "status = 'active' AND balance > 0 AND expires_at IS NOT NULL AND expires_at <= %s"; $args[] = $now; break;
            case 'off':       $where[] = "status = 'disabled'"; break;
            case 'problem':   $where[] = "email_status IN ('bounced', 'failed')"; break;
        }
        $sql_where = implode( ' AND ', $where );
        $count     = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $sql_where", $args ) : "SELECT COUNT(*) FROM $t WHERE $sql_where" );
        $l         = self::log_table();
        $q         = "SELECT c.*, (SELECT MAX(created_at) FROM $l WHERE card_id = c.id AND type = 'used') AS last_used FROM $t c WHERE $sql_where ORDER BY c.id DESC LIMIT %d OFFSET %d";
        $rows      = (array) $wpdb->get_results( $wpdb->prepare( $q, array_merge( $args, [ $per, ( $page - 1 ) * $per ] ) ), ARRAY_A );
        $stats     = (array) $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS sold, COALESCE(SUM(CASE WHEN status = 'active' AND (expires_at IS NULL OR expires_at > %s) THEN balance ELSE 0 END), 0) AS open, SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS waiting FROM $t WHERE status <> 'disabled'", $now ), ARRAY_A );
        return [
            'items'    => array_map( [ __CLASS__, 'row_out' ], $rows ),
            'total'    => $count,
            'pages'    => (int) ceil( $count / $per ),
            'page'     => $page,
            'currency' => get_woocommerce_currency(),
            'stats'    => [ 'count' => (int) ( $stats['n'] ?? 0 ), 'sold' => (float) ( $stats['sold'] ?? 0 ), 'open' => (float) ( $stats['open'] ?? 0 ), 'waiting' => (int) ( $stats['waiting'] ?? 0 ) ],
            'products' => array_map( static fn( $id ) => [ 'id' => (int) $id, 'name' => get_the_title( $id ) ], self::gift_products() ),
        ];
    }

    public static function gift_products(): array {
        return wc_get_products( [ 'limit' => 20, 'status' => [ 'publish', 'draft' ], 'type' => 'simple', 'meta_key' => self::META, 'meta_compare' => 'EXISTS', 'return' => 'ids' ] );
    }

    private static function get( int $id ) {
        global $wpdb;
        $c = self::card( $id );
        if ( ! $c ) return new WP_Error( 'missing', 'That gift card doesn\'t exist.' );
        $log  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT type, amount, order_id, note, who, created_at FROM ' . self::log_table() . ' WHERE card_id = %d ORDER BY id', $id ), ARRAY_A );
        $uses = [];
        foreach ( $log as $e ) {
            if ( in_array( $e['type'], [ 'used', 'returned' ], true ) ) {
                $uses[] = [ 'type' => $e['type'], 'order_id' => (int) $e['order_id'], 'amount' => (float) $e['amount'], 'at' => self::iso( $e['created_at'] ) ];
            }
        }
        $d = self::design_of( $c );
        return self::row_out( $c ) + [
            'from_name'  => (string) $c['from_name'],
            'message'    => (string) $c['message'],
            'design'     => (string) ( $d['name'] ?? $c['design'] ),
            'note'       => (string) $c['note'],
            'opened_at'  => self::iso( $c['opened_at'] ),
            'delivered_at' => self::iso( $c['delivered_at'] ),
            'image'      => self::image_url( (string) $c['image'] ),
            'currency'   => (string) ( $c['currency'] ?: get_woocommerce_currency() ),
            'uses'       => $uses,
            'log'        => array_map( static fn( $e ) => [ 'type' => $e['type'], 'amount' => $e['amount'] === null ? null : (float) $e['amount'], 'order_id' => (int) $e['order_id'], 'note' => (string) $e['note'], 'who' => (string) $e['who'], 'at' => self::iso( $e['created_at'] ) ], $log ),
        ];
    }

    /** The owner gives a card: { amount, to_name, to_email, from, message, design, color, send_on, expiry, note, product_id }. */
    private static function issue( array $p, string $who ) {
        $amount = self::amount( $p['amount'] ?? '' );
        if ( $amount === null ) return new WP_Error( 'gc', 'Enter the amount, like 100.' );
        $to_email = sanitize_email( (string) ( $p['to_email'] ?? '' ) );
        if ( ! is_email( $to_email ) ) return new WP_Error( 'gc', 'Enter the recipient\'s email address.' );
        $to_name = mb_substr( sanitize_text_field( (string) ( $p['to_name'] ?? '' ) ), 0, 80 );
        if ( $to_name === '' ) return new WP_Error( 'gc', 'Enter the recipient\'s name.' );
        $expiry = (int) ( $p['expiry'] ?? 5 );
        if ( ! in_array( $expiry, self::EXPIRY, true ) ) $expiry = 5;
        $product = (int) ( $p['product_id'] ?? 0 );
        if ( $product && ! self::is_gift( $product ) ) $product = 0;
        $s       = $product ? self::settings( $product ) : self::defaults();
        $designs = array_column( (array) $s['designs'], null, 'id' );
        $design  = isset( $designs[ $p['design'] ?? '' ] ) ? (string) $p['design'] : 'simple';
        $color   = sanitize_hex_color( (string) ( $p['color'] ?? '' ) ) ?: ( $s['palette'][0] ?? self::COLORS[0] );
        $send_at = self::now();
        $send    = (string) ( $p['send_on'] ?? '' );
        $time    = preg_match( '/^([01]\d|2[0-3]):(00|30)$/', (string) ( $p['send_time'] ?? '' ) ) ? (string) $p['send_time'] : '09:00';
        if ( $send !== '' ) {
            $d = DateTime::createFromFormat( 'Y-m-d H:i', $send . ' ' . $time, wp_timezone() );
            if ( ! $d || $d->format( 'Y-m-d' ) !== $send ) return new WP_Error( 'gc', 'Pick the send date from the calendar.' );
            if ( $d->getTimestamp() > time() ) $send_at = gmdate( 'Y-m-d H:i:s', $d->getTimestamp() );
        }
        $id = self::insert( [
            'amount'       => $amount,
            'currency'     => get_woocommerce_currency(),
            'product_id'   => $product,
            'buyer_name'   => '',
            'buyer_email'  => '',
            'to_name'      => $to_name,
            'to_email'     => $to_email,
            'from_name'    => mb_substr( sanitize_text_field( (string) ( $p['from'] ?? '' ) ), 0, 80 ) ?: html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            'message'      => mb_substr( sanitize_textarea_field( (string) ( $p['message'] ?? '' ) ), 0, self::MAX_MESSAGE ),
            'design'       => $design,
            'color'        => strtolower( $color ),
            'image'        => '',
            'expiry_years' => $expiry,
            'send_at'      => $send_at,
            'note'         => mb_substr( sanitize_text_field( (string) ( $p['note'] ?? '' ) ), 0, 255 ),
        ], 'issued', $who );
        if ( ! $id ) return new WP_Error( 'gc', 'The gift card couldn\'t be made. Try again.' );
        if ( $send_at <= self::now() ) {
            $card = self::card( $id );
            if ( $card ) self::deliver( $card, $who );
        } else {
            self::wake();
        }
        return self::get( $id );
    }

    /**
     * One change: { id, do: resend | email | send_on | balance | expiry | off | on, … }.
     * Every change goes into the card's history with who made it.
     */
    private static function update( array $p, string $who ) {
        global $wpdb;
        $c = self::card( (int) ( $p['id'] ?? 0 ) );
        if ( ! $c ) return new WP_Error( 'missing', 'That gift card doesn\'t exist.' );
        $id  = (int) $c['id'];
        $t   = self::cards_table();
        $now = self::now();
        switch ( (string) ( $p['do'] ?? '' ) ) {
            case 'resend':
                if ( $c['status'] === 'disabled' ) return new WP_Error( 'gc', 'Turn the card on before sending it.' );
                if ( ! self::deliver( $c, $who ) ) return new WP_Error( 'gc', 'The email couldn\'t be sent right now. It will try again by itself.' );
                break;
            case 'email':
                $email = sanitize_email( (string) ( $p['to_email'] ?? '' ) );
                if ( ! is_email( $email ) ) return new WP_Error( 'gc', 'Enter a valid email address.' );
                $name = mb_substr( sanitize_text_field( (string) ( $p['to_name'] ?? $c['to_name'] ) ), 0, 80 ) ?: $c['to_name'];
                $wpdb->update( $t, [ 'to_email' => $email, 'to_name' => $name, 'email_status' => $c['status'] === 'scheduled' ? 'scheduled' : $c['email_status'], 'updated_at' => $now ], [ 'id' => $id ] );
                self::log( $id, 'email_changed', null, 0, $c['to_email'] . ' → ' . $email, $who );
                if ( ! empty( $p['send'] ) && $c['status'] !== 'disabled' ) {
                    $fresh = self::card( $id );
                    if ( $fresh && ! self::deliver( $fresh, $who ) ) return new WP_Error( 'gc', 'Saved. The email couldn\'t be sent right now; it will try again by itself.' );
                }
                break;
            case 'send_on':
                if ( $c['status'] !== 'scheduled' ) return new WP_Error( 'gc', 'This card was already sent.' );
                $time = preg_match( '/^([01]\d|2[0-3]):(00|30)$/', (string) ( $p['send_time'] ?? '' ) ) ? (string) $p['send_time'] : '09:00';
                $d = DateTime::createFromFormat( 'Y-m-d H:i', (string) ( $p['send_on'] ?? '' ) . ' ' . $time, wp_timezone() );
                if ( ! $d ) return new WP_Error( 'gc', 'Pick the send date from the calendar.' );
                $at = $d->getTimestamp() > time() ? gmdate( 'Y-m-d H:i:s', $d->getTimestamp() ) : $now;
                $wpdb->update( $t, [ 'send_at' => $at, 'attempts' => 0, 'updated_at' => $now ], [ 'id' => $id ] );
                self::log( $id, 'send_changed', null, 0, (string) $p['send_on'] . ' ' . $time, $who );
                self::wake();
                break;
            case 'balance':
                $new = trim( str_replace( ',', '.', (string) ( $p['balance'] ?? '' ) ) );
                if ( ! preg_match( '/^\d{1,6}(\.\d{1,2})?$/', $new ) || (float) $new > self::MAX_AMOUNT ) return new WP_Error( 'gc', 'Enter the new balance, like 150.' );
                $wpdb->update( $t, [ 'balance' => (float) $new, 'updated_at' => $now ], [ 'id' => $id ] );
                self::log( $id, 'balance', self::round( (float) $new - (float) $c['balance'] ), 0, mb_substr( sanitize_text_field( (string) ( $p['note'] ?? '' ) ), 0, 200 ), $who );
                break;
            case 'expiry':
                $date = (string) ( $p['expires'] ?? '' );
                $at   = null;
                if ( $date !== '' ) {
                    $d = DateTime::createFromFormat( 'Y-m-d H:i:s', $date . ' 23:59:59', wp_timezone() );
                    if ( ! $d || $d->format( 'Y-m-d' ) !== $date ) return new WP_Error( 'gc', 'Pick the expiry date from the calendar.' );
                    $at = gmdate( 'Y-m-d H:i:s', $d->getTimestamp() );
                }
                if ( $c['status'] === 'scheduled' ) {
                    $years = (int) ( $p['years'] ?? $c['expiry_years'] );
                    $wpdb->update( $t, [ 'expiry_years' => in_array( $years, self::EXPIRY, true ) ? $years : (int) $c['expiry_years'], 'updated_at' => $now ], [ 'id' => $id ] );
                } else {
                    $wpdb->update( $t, [ 'expires_at' => $at, 'updated_at' => $now ], [ 'id' => $id ] );
                }
                self::log( $id, 'expiry', null, 0, $date === '' ? 'none' : $date, $who );
                break;
            case 'off':
                $wpdb->update( $t, [ 'status' => 'disabled', 'updated_at' => $now ], [ 'id' => $id ] );
                self::log( $id, 'disabled', null, 0, mb_substr( sanitize_text_field( (string) ( $p['note'] ?? '' ) ), 0, 200 ), $who );
                break;
            case 'on':
                if ( $c['status'] !== 'disabled' ) break;
                $wpdb->update( $t, [ 'status' => $c['sent_at'] ? 'active' : 'scheduled', 'updated_at' => $now ], [ 'id' => $id ] );
                self::log( $id, 'enabled', null, 0, '', $who );
                if ( ! $c['sent_at'] ) self::wake();
                break;
            default:
                return new WP_Error( 'unknown', 'Unknown change.' );
        }
        return self::get( $id );
    }

    /* ---------------- privacy ---------------- */

    public static function register_eraser( $erasers ) {
        $erasers['qwoo-giftcards'] = [
            'eraser_friendly_name' => 'Gift cards',
            'callback'             => static function ( $email ) {
                global $wpdb;
                $email = strtolower( (string) $email );
                $n  = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::cards_table() . " SET to_name = '', to_email = '', message = '', from_name = '' WHERE LOWER(to_email) = %s", $email ) );
                $n += (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::cards_table() . " SET buyer_name = '', buyer_email = '' WHERE LOWER(buyer_email) = %s", $email ) );
                return [ 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => [], 'done' => true ];
            },
        ];
        return $erasers;
    }
}

Qwoo_Gift_Cards::init();

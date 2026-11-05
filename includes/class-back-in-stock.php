<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * "Notify me when it's back" by email: the sold-out product form's second way,
 * offered once push notifications can't work on the shopper's device (blocked,
 * dismissed, or no push at all). Push requests are Qwoo_Push_Engine's
 * ({prefix}qwoo_push_wants).
 *
 * Requests: {prefix}qwoo_back_emails { product_id (the parent of a variation), email, lang,
 * due_at (set when the product is back) }. One email each, then the row is removed.
 * Sent by Qwoo_Push_Engine::tick() (a run starts right after the product is back),
 * so a long list goes out in batches.
 * The form is on unless the owner turned it off (Qwoo_Push settings back_form).
 */
class Qwoo_Back_In_Stock {

    const BATCH = 40;

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
        add_action( 'woocommerce_product_set_stock_status', [ __CLASS__, 'on_stock_status' ], 21, 3 );
        add_action( 'woocommerce_variation_set_stock_status', [ __CLASS__, 'on_stock_status' ], 21, 3 );
        add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'qwoo_back_emails';
    }

    /** Called by Qwoo_Push_Engine::install_tables(). */
    public static function install_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta( "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        email VARCHAR(100) NOT NULL,
        lang VARCHAR(10) NULL,
        created_at DATETIME NOT NULL,
        due_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY product_email (product_id, email),
        KEY due (due_at)
        ) " . $wpdb->get_charset_collate() . ';' );
    }

    public static function routes() {
        register_rest_route( 'qwoo/v1', '/back-in-stock/email', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_email' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /** { product_id, email, website (a field people never see: bots fill it) } */
    public static function rest_email( WP_REST_Request $r ) {
        $p = (array) $r->get_json_params();
        if ( ! Qwoo_Push::settings()['back_form']['on'] ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'This store doesn\'t take these requests.' ], 403 );
        }
        // Limits in the callback: WordPress runs permission callbacks for the Allow header too.
        if ( ! qwoo_rate_limit_check( 'back_in_stock_email', 8, 15 * MINUTE_IN_SECONDS ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Too many requests. Try again in a few minutes.' ], 429 );
        }
        if ( trim( (string) ( $p['website'] ?? '' ) ) !== '' ) {
            return rest_ensure_response( [ 'success' => true ] );
        }
        $email = strtolower( sanitize_email( (string) ( $p['email'] ?? '' ) ) );
        if ( ! is_email( $email ) || strlen( $email ) > 100 ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Enter a valid email address.' ], 400 );
        }
        $item = wc_get_product( absint( $p['product_id'] ?? 0 ) );
        if ( ! $item || ( $item->get_parent_id() ? get_post_status( $item->get_parent_id() ) : $item->get_status() ) !== 'publish' ) {
            return new WP_REST_Response( [ 'success' => false ], 404 );
        }
        $product = $item->get_parent_id() ?: $item->get_id();
        global $wpdb;
        $table = self::table();
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE product_id = %d AND email = %s", $product, $email ) );
        $wpdb->insert( $table, [
            'product_id' => $product,
            'email'      => $email,
            'lang'       => Qwoo_Push_Engine::request_lang(),
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ] );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public static function on_stock_status( $product_id, $status, $product = null ) {
        if ( $status !== 'instock' ) return;
        try {
            $product = $product instanceof WC_Product ? $product : wc_get_product( $product_id );
            if ( ! $product ) return;
            $id = $product->get_parent_id() ?: $product->get_id();
            global $wpdb;
            $table = self::table();
            $n = $wpdb->query( $wpdb->prepare( "UPDATE $table SET due_at = %s WHERE product_id = %d AND due_at IS NULL", gmdate( 'Y-m-d H:i:s' ), $id ) );
            if ( $n ) Qwoo_Push_Engine::spawn_soon();
        } catch ( Throwable $e ) {
            error_log( '[qwoo] back in stock email: ' . $e->getMessage() );
        }
    }

    /** Requests still waiting (the dashboard shows the count). */
    public static function waiting_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE due_at IS NULL' );
    }

    public static function has_due(): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( 'SELECT id FROM ' . self::table() . ' WHERE due_at IS NOT NULL LIMIT 1' );
    }

    /** Sends due emails until $until (microtime). Each row is removed first, so nothing goes twice. */
    public static function send_due( float $until ) {
        global $wpdb;
        $table = self::table();
        while ( microtime( true ) < $until ) {
            $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE due_at IS NOT NULL ORDER BY id LIMIT %d", self::BATCH ), ARRAY_A );
            if ( ! $rows ) return;
            foreach ( $rows as $row ) {
                if ( microtime( true ) >= $until ) return;
                if ( ! $wpdb->delete( $table, [ 'id' => (int) $row['id'] ] ) ) continue;
                try {
                    self::send( $row );
                } catch ( Throwable $e ) {
                    error_log( '[qwoo] back in stock email: ' . $e->getMessage() );
                }
            }
        }
    }

    private static function send( array $row ) {
        $product = wc_get_product( (int) $row['product_id'] );
        $front   = Qwoo_Technical_Settings::get_primary_frontend_domain();
        // Sold out again, or gone, by the time it's sent: nothing to tell.
        if ( ! $product || $product->get_status() !== 'publish' || ! $product->is_in_stock() || $front === '' || ! is_email( $row['email'] ) ) return;
        $lang  = (string) ( $row['lang'] ?: Qwoo_Push::main_lang() );
        $name  = $product->get_name();
        if ( $lang !== Qwoo_Push::main_lang() && class_exists( 'Qwoo_Translations' ) ) {
            $name = (string) ( Qwoo_Translations::field( $product->get_id(), $lang, 'name' ) ?: $name );
        }
        $name  = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
        $store = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
        $url   = rtrim( $front, '/' ) . Qwoo_Push_Engine::link_url( [ 'type' => 'item' ], $lang, null, $product );

        Qwoo_I18n::in_language( $lang, static function () use ( $row, $name, $store, $url ) {
            // The owner's design; only if it can't be made, the plain email below.
            $done = Qwoo_Store_Emails::send_own( 'back_in_stock', (string) $row['email'], Qwoo_I18n::t( '{product} is back in stock', [ 'product' => $name ] ), [ 'product_name' => $name, 'product_url' => $url ] );
            if ( $done !== null ) return;
            $html  = '<p>' . esc_html( Qwoo_I18n::t( 'Hi,' ) ) . '</p>';
            $html .= '<p>' . esc_html( Qwoo_I18n::t( 'Good news: {product} is back in stock at {store}. Get it before it\'s gone again.', [ 'product' => $name, 'store' => $store ] ) ) . '</p>';
            $html .= '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:10px 18px;border-radius:8px;background:#1d1b2e;color:#ffffff;text-decoration:none;font-weight:bold;">'
                . esc_html( Qwoo_I18n::t( 'View {product}', [ 'product' => $name ] ) ) . '</a></p>';
            $html .= '<p style="color:#888888;font-size:12px;">' . esc_html( Qwoo_I18n::t( 'You asked us to tell you when it\'s back. This is the only email you\'ll get about it.' ) ) . '</p>';
            $mailer = WC()->mailer();
            $mailer->send( $row['email'], Qwoo_I18n::t( '{product} is back in stock', [ 'product' => $name ] ), $mailer->wrap_message( Qwoo_I18n::t( 'It\'s back' ), $html ) );
        } );
    }

    /* ---------------- privacy (Tools → Erase personal data, and customer deletion) ---------------- */

    public static function register_eraser( $erasers ) {
        $erasers['qwoo-back-in-stock'] = [ 'eraser_friendly_name' => 'Back in stock requests', 'callback' => [ __CLASS__, 'erase' ] ];
        return $erasers;
    }

    public static function erase( $email, $page = 1 ) {
        global $wpdb;
        $n = $wpdb->delete( self::table(), [ 'email' => strtolower( (string) $email ) ] );
        return [ 'items_removed' => (bool) $n, 'items_retained' => false, 'messages' => [], 'done' => true ];
    }
}

Qwoo_Back_In_Stock::init();

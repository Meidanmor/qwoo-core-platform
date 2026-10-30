<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Push notifications: triggers, the send queue, sending and counts.
 *
 * Everything that goes out is a row in {prefix}qwoo_push_log first (status wait), with the time it's due
 * (GMT). Rows are sent in batches by tick(), which runs:
 *   - from a non-blocking request the store makes to itself (qwoo-push/v1/run, signed) right after
 *     something is due now;
 *   - from the storefront's once-a-minute qwoo/v1/site call when something is due (maybe_spawn());
 *   - from the hosting cron job (cron/abandoned-cart.php, new stores: every 15 minutes).
 * No WordPress cron.
 *
 * kind: auto (an automation; ref = its id), send (a one-off send; ref = ps_…), test (the owner's devices).
 * The log doubles as the history (sent / opened counts), kept 90 days.
 *
 * Devices: {prefix}pwa_push_subscriptions, plus lang, customer_id, wishlist (",12,40,"), cart_items,
 * last_order_at (GMT) and owner (the owner's own test devices). "Notify me" requests:
 * {prefix}qwoo_push_wants.
 */
class Qwoo_Push_Engine {

    const LOCK      = 'qwoo_push_lock';
    const NEXT      = 'qwoo_push_next';     // transient: the earliest due time (Unix)
    const SPAWNED   = 'qwoo_push_spawned';  // transient: a run was started a moment ago
    const BATCH     = 100;
    const KEEP_DAYS = 90;

    private static $spawn_queued = false;

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'on_order_placed' ], 20 );
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_order_status' ], 20, 4 );
        add_action( 'woocommerce_product_set_stock_status', [ __CLASS__, 'on_stock_status' ], 20, 3 );
        add_action( 'woocommerce_variation_set_stock_status', [ __CLASS__, 'on_stock_status' ], 20, 3 );
        add_action( 'deleted_user', [ __CLASS__, 'on_user_deleted' ] );
    }

    /* ---------------- tables ---------------- */

    public static function log_table() {
        global $wpdb;
        return $wpdb->prefix . 'qwoo_push_log';
    }

    public static function subs_table() {
        global $wpdb;
        return $wpdb->prefix . 'pwa_push_subscriptions';
    }

    public static function wants_table() {
        global $wpdb;
        return $wpdb->prefix . 'qwoo_push_wants';
    }

    /** Called by qwoo_maybe_install_push_table() (rest-api.php) when the push tables' version changes. */
    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $log = self::log_table();
        dbDelta( "CREATE TABLE $log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sub_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        kind VARCHAR(10) NOT NULL,
        ref VARCHAR(24) NOT NULL,
        uniq VARCHAR(120) NULL,
        context TEXT NULL,
        marketing TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL,
        reason VARCHAR(60) NULL,
        send_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        opened_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq (uniq),
        KEY due (status, send_at),
        KEY ref (ref),
        KEY sub_sent (sub_id, sent_at)
        ) $charset;" );
        $wants = self::wants_table();
        dbDelta( "CREATE TABLE $wants (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sub_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY sub_product (sub_id, product_id),
        KEY product (product_id)
        ) $charset;" );
    }

    private static function now() {
        return gmdate( 'Y-m-d H:i:s' );
    }

    private static function at( int $unix ) {
        return gmdate( 'Y-m-d H:i:s', $unix );
    }

    /* ---------------- routes ---------------- */

    public static function routes() {
        // The store's own run request (no proxy secret: signed with a key only this store knows).
        register_rest_route( 'qwoo-push/v1', '/run', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_run' ],
            'permission_callback' => static fn( WP_REST_Request $r ) => hash_equals( self::run_key(), (string) $r->get_param( 'k' ) ),
        ] );
        register_rest_route( 'qwoo/v1', '/pwa/config', [
            'methods'             => 'GET',
            'callback'            => static fn() => rest_ensure_response( Qwoo_Push::storefront_config() ),
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'qwoo/v1', '/pwa/sync', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_sync' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'qwoo/v1', '/pwa/notify-me', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_notify_me' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'qwoo/v1', '/pwa/opened', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_opened' ],
            'permission_callback' => '__return_true',
        ] );
    }

    private static function run_key() {
        return hash_hmac( 'sha256', 'qwoo-push-run', wp_salt( 'auth' ) );
    }

    private static function open_key( $id ) {
        return substr( hash_hmac( 'sha256', 'qwoo-push-open|' . (int) $id, wp_salt( 'auth' ) ), 0, 20 );
    }

    public static function rest_run() {
        ignore_user_abort( true );
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 90 );
        self::tick( 25 );
        return rest_ensure_response( [ 'ok' => true ] );
    }

    /** { device_id, wishlist: [product ids] }: the device's language, account and saved products. */
    public static function rest_sync( WP_REST_Request $r ) {
        $p = (array) $r->get_json_params();
        $device = sanitize_text_field( (string) ( $p['device_id'] ?? '' ) );
        if ( $device === '' || ! qwoo_rate_limit_check( 'pwa_sync', 60, 5 * MINUTE_IN_SECONDS, $device ) ) {
            return new WP_REST_Response( [ 'success' => false ], $device === '' ? 400 : 429 );
        }
        global $wpdb;
        $fields = [ 'lang' => self::request_lang(), 'updated_at' => current_time( 'mysql' ) ];
        if ( isset( $p['wishlist'] ) && is_array( $p['wishlist'] ) ) {
            $fields['wishlist'] = self::wishlist_value( $p['wishlist'] );
        }
        $user = get_current_user_id();
        if ( $user ) {
            $fields['customer_id'] = $user;
        }
        $wpdb->update( self::subs_table(), $fields, [ 'device_id' => $device ] );
        return rest_ensure_response( [ 'success' => true ] );
    }

    /** { device_id, product_id, on }: "Notify me when it's back" on a sold-out product. */
    public static function rest_notify_me( WP_REST_Request $r ) {
        $p = (array) $r->get_json_params();
        $device = sanitize_text_field( (string) ( $p['device_id'] ?? '' ) );
        $product = absint( $p['product_id'] ?? 0 );
        if ( $device === '' || ! $product ) return new WP_REST_Response( [ 'success' => false ], 400 );
        if ( ! qwoo_rate_limit_check( 'pwa_notify_me', 30, 5 * MINUTE_IN_SECONDS, $device ) ) {
            return new WP_REST_Response( [ 'success' => false ], 429 );
        }
        $item = wc_get_product( $product );
        if ( ! $item || $item->get_status() !== 'publish' ) return new WP_REST_Response( [ 'success' => false ], 404 );
        $product = $item->get_parent_id() ?: $product;
        global $wpdb;
        $sub = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::subs_table() . ' WHERE device_id = %s LIMIT 1', $device ) );
        if ( ! $sub ) return new WP_REST_Response( [ 'success' => false, 'message' => 'not_subscribed' ], 409 );
        $wants = self::wants_table();
        $wpdb->delete( $wants, [ 'sub_id' => $sub, 'product_id' => $product ] );
        if ( ! isset( $p['on'] ) || ! empty( $p['on'] ) ) {
            $wpdb->insert( $wants, [ 'sub_id' => $sub, 'product_id' => $product, 'created_at' => self::now() ] );
        }
        return rest_ensure_response( [ 'success' => true ] );
    }

    /** { q, k }: a shopper opened a notification (counted once, no personal data). */
    public static function rest_opened( WP_REST_Request $r ) {
        $p = (array) $r->get_json_params();
        $id = absint( $p['q'] ?? 0 );
        if ( ! $id || ! hash_equals( self::open_key( $id ), (string) ( $p['k'] ?? '' ) ) ) {
            return new WP_REST_Response( [ 'success' => false ], 400 );
        }
        if ( ! qwoo_rate_limit_check( 'pwa_opened', 60, 5 * MINUTE_IN_SECONDS ) ) {
            return new WP_REST_Response( [ 'success' => false ], 429 );
        }
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::log_table() . ' SET opened_at = %s WHERE id = %d AND opened_at IS NULL', self::now(), $id ) );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public static function request_lang() {
        $main = Qwoo_Push::main_lang();
        $lang = class_exists( 'Qwoo_Store_Language' ) ? (string) Qwoo_Store_Language::request_lang() : '';
        return $lang !== '' ? $lang : $main;
    }

    public static function wishlist_value( array $ids ) {
        $ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, 200 );
        return $ids ? ',' . implode( ',', $ids ) . ',' : null;
    }

    /* ---------------- device events (called from rest-api.php) ---------------- */

    /** A device subscribed for the first time. */
    public static function on_subscribed( int $sub ) {
        self::event( 'subscribed', [], [ $sub ] );
    }

    /** The shopper left with items in the cart ($token) or came back. */
    public static function on_cart_state( string $device, string $status, ?string $token ) {
        global $wpdb;
        $sub = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::subs_table() . ' WHERE device_id = %s LIMIT 1', $device ) );
        if ( ! $sub ) return;
        if ( $status === 'active' ) {
            self::cancel_cart( $sub );
            return;
        }
        if ( ! $token ) return;
        $hash = substr( md5( $token ), 0, 12 );
        $due = [];
        foreach ( Qwoo_Push::settings()['automations'] as $a ) {
            if ( ! $a['on'] || $a['trigger']['type'] !== 'cart_left' ) continue;
            if ( ! self::sub_matches( $sub, $a['only'] ) ) continue;
            $ctx = [ 'cart' => $hash ];
            $due[] = self::queue_one( $sub, 'auto', $a['id'], $ctx, true, time() + $a['wait'] * 60, "cart:{$a['id']}:{$sub}:{$hash}", true );
            if ( ! empty( $a['second'] ) ) {
                $due[] = self::queue_one( $sub, 'auto', $a['id'], $ctx, true, time() + $a['second'] * 60, "cart2:{$a['id']}:{$sub}:{$hash}", true );
            }
        }
        self::after_queue( array_filter( $due ) );
    }

    private static function cancel_cart( int $sub ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::log_table() . " SET status = 'cancel' WHERE sub_id = %d AND status = 'wait' AND uniq LIKE %s",
            $sub, 'cart%'
        ) );
    }

    /* ---------------- shop events ---------------- */

    /** The device that placed an order (saved at checkout as pwa_device_id). */
    private static function sub_of_order( $order ) {
        $device = (string) $order->get_meta( 'pwa_device_id', true );
        if ( $device === '' ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::subs_table() . ' WHERE device_id = %s LIMIT 1', $device ) );
    }

    public static function on_order_placed( $order ) {
        try {
            $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
            if ( ! $order ) return;
            $sub = self::sub_of_order( $order );
            if ( ! $sub ) return;
            global $wpdb;
            $fields = [ 'last_order_at' => self::now(), 'cart_items' => 0 ];
            if ( $order->get_customer_id() ) $fields['customer_id'] = (int) $order->get_customer_id();
            $wpdb->update( self::subs_table(), $fields, [ 'id' => $sub ] );
            self::cancel_cart( $sub );
            self::event( 'order_placed', [ 'order' => $order->get_id() ], [ $sub ] );
        } catch ( Throwable $e ) {
            error_log( '[qwoo push] order placed: ' . $e->getMessage() );
        }
    }

    public static function on_order_status( $order_id, $from, $to, $order = null ) {
        try {
            $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
            if ( ! $order ) return;
            $sub = self::sub_of_order( $order );
            if ( ! $sub ) return;
            self::event( 'order_status', [ 'order' => $order->get_id(), 'status' => (string) $to ], [ $sub ] );
        } catch ( Throwable $e ) {
            error_log( '[qwoo push] order status: ' . $e->getMessage() );
        }
    }

    public static function on_stock_status( $product_id, $status, $product = null ) {
        if ( $status !== 'instock' ) return;
        try {
            $product = $product instanceof WC_Product ? $product : wc_get_product( $product_id );
            if ( ! $product ) return;
            $id = $product->get_parent_id() ?: $product->get_id();
            global $wpdb;
            $wants = self::wants_table();
            $subs = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT sub_id FROM $wants WHERE product_id = %d", $id ) ) );
            if ( ! $subs ) return;
            $wpdb->query( $wpdb->prepare( "DELETE FROM $wants WHERE product_id = %d", $id ) );
            self::event( 'back_in_stock', [ 'product' => $id ], $subs );
        } catch ( Throwable $e ) {
            error_log( '[qwoo push] back in stock: ' . $e->getMessage() );
        }
    }

    /** A product went on sale (admin-options.php; bulk changes and imports remove those hooks). */
    public static function on_sale( $product ) {
        try {
            if ( ! $product instanceof WC_Product || $product->get_status() !== 'publish' ) return;
            self::event( 'product_sale', [ 'product' => $product->get_id() ], null );
        } catch ( Throwable $e ) {
            error_log( '[qwoo push] sale: ' . $e->getMessage() );
        }
    }

    public static function on_user_deleted( $user_id ) {
        global $wpdb;
        $wpdb->update( self::subs_table(), [ 'customer_id' => 0 ], [ 'customer_id' => (int) $user_id ] );
    }

    /* ---------------- matching automations ---------------- */

    /**
     * Queues every automation of this trigger that matches. $subs: the devices it's about
     * (null: every device the automation's audience takes, for sales).
     */
    public static function event( string $type, array $ctx, ?array $subs ) {
        $due = [];
        foreach ( Qwoo_Push::settings()['automations'] as $a ) {
            if ( ! $a['on'] || $a['trigger']['type'] !== $type ) continue;
            if ( $type === 'order_status' && $a['trigger']['status'] !== preg_replace( '/^wc-/', '', (string) ( $ctx['status'] ?? '' ) ) ) continue;
            if ( ! self::item_matches( $a['only'], $ctx ) ) continue;
            $marketing = Qwoo_Push::is_marketing( $type );
            $send_at = time() + $a['wait'] * 60;

            if ( $type === 'product_sale' ) {
                $pid = (int) $ctx['product'];
                $gap_key = 'qwoo_push_sale_' . md5( $a['id'] . '|' . $pid );
                if ( $a['gap'] > 0 && get_transient( $gap_key ) ) continue;
                if ( $a['gap'] > 0 ) set_transient( $gap_key, 1, $a['gap'] * DAY_IN_SECONDS );
                $where = self::only_where( $a['only'] );
                if ( $a['audience'] === 'wishlist' ) {
                    global $wpdb;
                    $where[] = $wpdb->prepare( 'wishlist LIKE %s', '%,' . $pid . ',%' );
                }
                if ( self::fan_out( 'auto', $a['id'], $ctx, $marketing, $send_at, $where ) ) $due[] = $send_at;
                continue;
            }
            foreach ( (array) $subs as $sub ) {
                if ( ! self::sub_matches( (int) $sub, $a['only'] ) ) continue;
                $uniq = null;
                switch ( $type ) {
                    case 'subscribed':   $uniq = "sub:{$a['id']}:{$sub}"; break;
                    case 'order_placed': $uniq = "ord:{$a['id']}:{$ctx['order']}"; break;
                    case 'order_status': $uniq = "ost:{$a['id']}:{$ctx['order']}:{$ctx['status']}"; break;
                }
                $due[] = self::queue_one( (int) $sub, 'auto', $a['id'], $ctx, $marketing, $send_at, $uniq );
            }
        }
        self::after_queue( array_filter( $due ) );
    }

    /** Product / category conditions, for triggers about an order or a product. */
    private static function item_matches( array $only, array $ctx ): bool {
        if ( ! $only['products'] && ! $only['categories'] ) return true;
        $ids = [];
        if ( ! empty( $ctx['order'] ) && ( $order = wc_get_order( $ctx['order'] ) ) ) {
            foreach ( $order->get_items() as $item ) {
                $ids[] = (int) $item->get_product_id();
            }
        } elseif ( ! empty( $ctx['product'] ) ) {
            $ids[] = (int) $ctx['product'];
        } else {
            return true; // no item to check (subscribed, cart, no order)
        }
        if ( $only['products'] && array_intersect( $ids, $only['products'] ) ) return true;
        if ( $only['categories'] ) {
            foreach ( $ids as $id ) {
                if ( array_intersect( wc_get_product_cat_ids( $id ), $only['categories'] ) ) return true;
            }
        }
        return false;
    }

    /** SQL conditions on the devices table for an automation's `only`. */
    private static function only_where( array $only ): array {
        global $wpdb;
        $where = [];
        if ( $only['langs'] ) {
            $in = implode( ',', array_map( static fn( $l ) => $wpdb->prepare( '%s', $l ), $only['langs'] ) );
            $where[] = in_array( Qwoo_Push::main_lang(), $only['langs'], true ) ? "(lang IN ($in) OR lang IS NULL OR lang = '')" : "lang IN ($in)";
        }
        if ( $only['signed'] === 'in' ) $where[] = 'customer_id > 0';
        if ( $only['signed'] === 'out' ) $where[] = '(customer_id IS NULL OR customer_id = 0)';
        if ( $only['buyer'] === 'yes' ) $where[] = 'last_order_at IS NOT NULL';
        if ( $only['buyer'] === 'no' ) $where[] = 'last_order_at IS NULL';
        return $where;
    }

    private static function sub_matches( int $sub, array $only ): bool {
        $where = self::only_where( $only );
        if ( ! $where ) return true;
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::subs_table() . ' WHERE id = %d AND ' . implode( ' AND ', $where ), $sub ) );
    }

    /** Devices a one-off send reaches: { who: all | cart | buyers | new, langs }. */
    private static function audience_where( array $aud ): array {
        $where = self::only_where( [ 'langs' => (array) ( $aud['langs'] ?? [] ), 'signed' => 'any', 'buyer' => 'any' ] );
        switch ( $aud['who'] ?? 'all' ) {
            case 'cart':   $where[] = 'cart_items > 0'; break;
            case 'buyers': $where[] = 'last_order_at IS NOT NULL'; break;
            case 'new':    $where[] = 'last_order_at IS NULL'; break;
        }
        return $where;
    }

    public static function audience_count( array $aud ): int {
        global $wpdb;
        $where = self::audience_where( $aud );
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::subs_table() . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) );
    }

    /* ---------------- the queue ---------------- */

    /**
     * One row for one device. $uniq stops a second copy; with $reset a waiting (or cancelled)
     * row of the same uniq starts over at $send_at. Returns the due time, or 0 when nothing was queued.
     */
    private static function queue_one( int $sub, string $kind, string $ref, array $ctx, bool $marketing, int $send_at, ?string $uniq, bool $reset = false ) {
        global $wpdb;
        $table = self::log_table();
        if ( $uniq ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $table WHERE uniq = %s", $uniq ), ARRAY_A );
            if ( $row ) {
                if ( $reset && in_array( $row['status'], [ 'wait', 'cancel' ], true ) ) {
                    $wpdb->update( $table, [ 'status' => 'wait', 'send_at' => self::at( $send_at ), 'context' => wp_json_encode( $ctx ) ], [ 'id' => (int) $row['id'] ] );
                    return $send_at;
                }
                return 0;
            }
        }
        $ok = $wpdb->insert( $table, [
            'sub_id'     => $sub,
            'kind'       => $kind,
            'ref'        => $ref,
            'uniq'       => $uniq,
            'context'    => wp_json_encode( $ctx ),
            'marketing'  => $marketing ? 1 : 0,
            'status'     => 'wait',
            'send_at'    => self::at( $send_at ),
            'created_at' => self::now(),
        ] );
        return $ok ? $send_at : 0;
    }

    /** One row per device matching $where (a single INSERT … SELECT). Returns how many. */
    private static function fan_out( string $kind, string $ref, array $ctx, bool $marketing, int $send_at, array $where ) {
        global $wpdb;
        $log = self::log_table();
        $subs = self::subs_table();
        $sql = $wpdb->prepare(
            "INSERT INTO $log (sub_id, kind, ref, context, marketing, status, send_at, created_at) SELECT id, %s, %s, %s, %d, 'wait', %s, %s FROM $subs",
            $kind, $ref, wp_json_encode( $ctx ), $marketing ? 1 : 0, self::at( $send_at ), self::now()
        );
        if ( $where ) $sql .= ' WHERE ' . implode( ' AND ', $where );
        return (int) $wpdb->query( $sql );
    }

    public static function cancel_waiting( string $kind, string $ref ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::log_table() . " SET status = 'cancel' WHERE kind = %s AND ref = %s AND status = 'wait'", $kind, $ref ) );
    }

    private static function after_queue( array $due ) {
        if ( ! $due ) return;
        self::note_due( min( $due ) );
        if ( min( $due ) <= time() ) self::spawn_soon();
    }

    /** Remembers the earliest due time, so the storefront's minute call knows when to start a run. */
    public static function note_due( int $unix ) {
        $next = (int) get_transient( self::NEXT );
        if ( ! $next || $unix < $next ) set_transient( self::NEXT, $unix );
    }

    /** Starts a run when this request ends (once). */
    public static function spawn_soon() {
        if ( self::$spawn_queued ) return;
        self::$spawn_queued = true;
        add_action( 'shutdown', [ __CLASS__, 'spawn' ] );
    }

    public static function spawn() {
        if ( ! apply_filters( 'qwoo_push_spawn', true ) ) return;
        set_transient( self::SPAWNED, 1, 45 );
        wp_remote_post( rest_url( 'qwoo-push/v1/run' ), [
            'blocking'  => false,
            'timeout'   => 1,
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            'body'      => [ 'k' => self::run_key() ],
        ] );
    }

    /** From the storefront's minute call (qwoo/v1/site): start a run when something is due. */
    public static function maybe_spawn() {
        $next = (int) get_transient( self::NEXT );
        if ( $next && $next <= time() && ! get_transient( self::SPAWNED ) ) {
            self::spawn_soon();
        }
    }

    /* ---------------- the run ---------------- */

    private static function lock(): bool {
        if ( add_option( self::LOCK, time(), '', false ) ) return true;
        if ( (int) get_option( self::LOCK ) < time() - 150 ) {
            delete_option( self::LOCK );
            return add_option( self::LOCK, time(), '', false );
        }
        return false;
    }

    /** Sends what's due, for up to $budget seconds. */
    public static function tick( int $budget = 20 ) {
        if ( ! self::lock() ) return;
        $start = microtime( true );
        try {
            self::start_due_sends();
            self::scan_no_order();
            while ( microtime( true ) - $start < $budget ) {
                $rows = self::due_rows();
                if ( ! $rows ) break;
                self::send_rows( $rows );
            }
            self::finish_sends();
            self::prune();
        } catch ( Throwable $e ) {
            error_log( '[qwoo push] run: ' . $e->getMessage() );
        } finally {
            delete_option( self::LOCK );
        }
        global $wpdb;
        $next = $wpdb->get_var( 'SELECT MIN(send_at) FROM ' . self::log_table() . " WHERE status = 'wait'" );
        $next = $next ? strtotime( $next . ' UTC' ) : 0;
        foreach ( Qwoo_Push::sends() as $s ) {
            if ( ( $s['status'] ?? '' ) === 'scheduled' && ( ! $next || (int) $s['send_at'] < $next ) ) $next = (int) $s['send_at'];
        }
        if ( $next ) {
            set_transient( self::NEXT, $next );
            if ( $next <= time() ) self::spawn();
        } else {
            delete_transient( self::NEXT );
        }
    }

    private static function due_rows(): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::log_table() . " WHERE status = 'wait' AND send_at <= %s ORDER BY send_at, id LIMIT %d",
            self::now(), self::BATCH
        ), ARRAY_A );
    }

    /** One-off sends whose time came: one waiting row per device. */
    private static function start_due_sends() {
        $list = Qwoo_Push::sends();
        $changed = false;
        foreach ( $list as $i => $s ) {
            if ( ( $s['status'] ?? '' ) !== 'scheduled' || (int) $s['send_at'] > time() ) continue;
            $n = self::fan_out( 'send', $s['id'], [], true, time(), self::audience_where( (array) $s['audience'] ) );
            $list[ $i ]['status'] = $n ? 'sending' : 'done';
            $list[ $i ]['reach']  = $n;
            $changed = true;
        }
        if ( $changed ) Qwoo_Push::save_sends( $list );
    }

    private static function finish_sends() {
        global $wpdb;
        $list = Qwoo_Push::sends();
        $changed = false;
        foreach ( $list as $i => $s ) {
            if ( ( $s['status'] ?? '' ) !== 'sending' ) continue;
            $left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::log_table() . " WHERE kind = 'send' AND ref = %s AND status = 'wait'", $s['id'] ) );
            if ( ! $left ) {
                $list[ $i ]['status'] = 'done';
                $changed = true;
            }
        }
        if ( $changed ) Qwoo_Push::save_sends( $list );
    }

    /** "No order for X days": checked every 6 hours. */
    private static function scan_no_order() {
        if ( get_transient( 'qwoo_push_no_order_scan' ) ) return;
        set_transient( 'qwoo_push_no_order_scan', 1, 6 * HOUR_IN_SECONDS );
        global $wpdb;
        $due = [];
        foreach ( Qwoo_Push::settings()['automations'] as $a ) {
            if ( ! $a['on'] || $a['trigger']['type'] !== 'no_order' ) continue;
            $where = self::only_where( $a['only'] );
            $where[] = $wpdb->prepare( 'last_order_at IS NOT NULL AND last_order_at <= %s', self::at( time() - $a['trigger']['days'] * DAY_IN_SECONDS ) );
            $rows = (array) $wpdb->get_results( 'SELECT id, last_order_at FROM ' . self::subs_table() . ' WHERE ' . implode( ' AND ', $where ) . ' LIMIT 2000', ARRAY_A );
            foreach ( $rows as $r ) {
                $due[] = self::queue_one( (int) $r['id'], 'auto', $a['id'], [], true, time() + $a['wait'] * 60, "no:{$a['id']}:{$r['id']}:" . strtotime( $r['last_order_at'] . ' UTC' ) );
            }
        }
        $due = array_filter( $due );
        if ( $due ) self::note_due( min( $due ) );
    }

    private static function prune() {
        if ( get_transient( 'qwoo_push_pruned' ) ) return;
        set_transient( 'qwoo_push_pruned', 1, DAY_IN_SECONDS );
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::log_table() . " WHERE status <> 'wait' AND created_at < %s", self::at( time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::wants_table() . ' WHERE created_at < %s', self::at( time() - 180 * DAY_IN_SECONDS ) ) );
    }

    /** End of the store's quiet hours as a Unix time, or 0 when it isn't quiet now. */
    public static function quiet_until( array $quiet, ?int $now = null ): int {
        if ( empty( $quiet['on'] ) ) return 0;
        $tz  = wp_timezone();
        $now = $now ?? time();
        $day = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
        $mins = (int) $day->format( 'G' ) * 60 + (int) $day->format( 'i' );
        [ $fh, $fm ] = array_map( 'intval', explode( ':', $quiet['from'] ) );
        [ $th, $tm ] = array_map( 'intval', explode( ':', $quiet['to'] ) );
        $from = $fh * 60 + $fm;
        $to   = $th * 60 + $tm;
        $in = $from < $to ? ( $mins >= $from && $mins < $to ) : ( $mins >= $from || $mins < $to );
        if ( ! $in ) return 0;
        $end = $day->setTime( $th, $tm );
        if ( $end->getTimestamp() <= $now ) $end = $end->modify( '+1 day' );
        return $end->getTimestamp();
    }

    private static function send_rows( array $rows ) {
        global $wpdb;
        $log  = self::log_table();
        $settings = Qwoo_Push::settings();
        $quiet_end = self::quiet_until( $settings['quiet'] );
        $sends = [];
        foreach ( Qwoo_Push::sends() as $s ) $sends[ $s['id'] ] = $s;

        $sub_ids = array_values( array_unique( array_map( static fn( $r ) => (int) $r['sub_id'], $rows ) ) );
        $subs = [];
        if ( $sub_ids ) {
            $in = implode( ',', array_map( 'intval', $sub_ids ) );
            foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::subs_table() . " WHERE id IN ($in)", ARRAY_A ) as $s ) {
                $subs[ (int) $s['id'] ] = $s;
            }
        }
        $sent_today = [];
        $out = [];
        foreach ( $rows as $row ) {
            $id = (int) $row['id'];
            $sub = $subs[ (int) $row['sub_id'] ] ?? null;
            if ( ! $sub ) {
                self::mark( $id, 'skip', 'device gone' );
                continue;
            }
            if ( $row['kind'] === 'auto' ) {
                $source = Qwoo_Push::automation( $row['ref'] );
                if ( ! $source || ! $source['on'] ) {
                    self::mark( $id, 'cancel', 'automation off' );
                    continue;
                }
            } else {
                $source = $sends[ $row['ref'] ] ?? null;
                if ( ! $source || ( $source['status'] ?? '' ) === 'cancelled' ) {
                    self::mark( $id, 'cancel', 'cancelled' );
                    continue;
                }
            }
            if ( ! empty( $row['marketing'] ) ) {
                if ( $quiet_end ) {
                    $wpdb->update( $log, [ 'send_at' => self::at( $quiet_end ) ], [ 'id' => $id ] );
                    continue;
                }
                $sid = (int) $sub['id'];
                if ( ! isset( $sent_today[ $sid ] ) ) {
                    $sent_today[ $sid ] = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM $log WHERE sub_id = %d AND marketing = 1 AND status = 'sent' AND sent_at >= %s",
                        $sid, self::at( time() - DAY_IN_SECONDS )
                    ) );
                }
                if ( $sent_today[ $sid ] >= $settings['cap'] ) {
                    self::mark( $id, 'skip', 'daily limit' );
                    continue;
                }
            }
            $ctx = json_decode( (string) $row['context'], true ) ?: [];
            if ( $row['kind'] === 'auto' && $source['trigger']['type'] === 'cart_left' && ! self::cart_has_items( $sub ) ) {
                self::mark( $id, 'skip', 'cart empty' );
                continue;
            }
            $msg = self::render( $source, $sub, $ctx );
            if ( ! $msg ) {
                self::mark( $id, 'skip', 'item gone' );
                continue;
            }
            $msg['q'] = $id;
            $msg['k'] = self::open_key( $id );
            $out[] = [ 'row' => $id, 'sub' => $sub, 'msg' => $msg ];
            if ( ! empty( $row['marketing'] ) ) $sent_today[ (int) $sub['id'] ]++;
        }
        foreach ( self::deliver( $out ) as $row_id => $result ) {
            self::mark( $row_id, $result === true ? 'sent' : 'failed', $result === true ? null : (string) $result );
        }
    }

    private static function mark( int $id, string $status, ?string $reason = null ) {
        global $wpdb;
        $fields = [ 'status' => $status, 'reason' => $reason ? substr( $reason, 0, 60 ) : null ];
        if ( $status === 'sent' ) $fields['sent_at'] = self::now();
        $wpdb->update( self::log_table(), $fields, [ 'id' => $id ] );
    }

    private static function cart_has_items( array $sub ): bool {
        if ( $sub['cart_items'] !== null && $sub['cart_items'] !== '' ) return (int) $sub['cart_items'] > 0;
        if ( empty( $sub['cart_token'] ) ) return false;
        $res = wp_remote_get( get_rest_url( null, 'wc/store/v1/cart' ), [ 'headers' => [ 'Cart-Token' => $sub['cart_token'] ], 'timeout' => 10 ] );
        $body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        return ! empty( $body['items'] );
    }

    /* ---------------- the message ---------------- */

    /**
     * Title, body, address and image for one device. $source: an automation or a send.
     * Returns null when the item it's about is gone.
     */
    public static function render( array $source, array $sub, array $ctx ): ?array {
        $langs = Qwoo_Push::langs();
        $main  = $langs[0];
        $lang  = in_array( (string) ( $sub['lang'] ?? '' ), $langs, true ) ? $sub['lang'] : $main;
        $builtin = (string) ( $source['builtin'] ?? '' );

        $order = ! empty( $ctx['order'] ) ? wc_get_order( $ctx['order'] ) : null;
        $product = ! empty( $ctx['product'] ) ? wc_get_product( $ctx['product'] ) : null;
        if ( ( ! empty( $ctx['order'] ) && ! $order ) || ( ! empty( $ctx['product'] ) && ( ! $product || $product->get_status() !== 'publish' ) ) ) {
            return null;
        }

        $texts = (array) ( $source['texts'] ?? [] );
        $key = $builtin;
        if ( $builtin === 'order_status' && $order && self::is_pickup( $order ) ) {
            $texts = (array) ( $source['pickup'] ?? [] );
            $key = 'order_pickup';
        }
        $defaults = $key !== '' ? ( Qwoo_Push::default_texts()[ $key ] ?? [] ) : [];
        $pick = static function ( $l ) use ( $texts, $defaults ) {
            if ( ! empty( $texts[ $l ]['title'] ) ) return $texts[ $l ];
            if ( ! empty( $defaults[ $l ] ) ) return [ 'title' => $defaults[ $l ][0], 'body' => $defaults[ $l ][1] ];
            return null;
        };
        $t = $pick( $lang ) ?: $pick( $main ) ?: $pick( 'en' ) ?: [ 'title' => '', 'body' => '' ];

        $tokens = self::tokens( $order, $product, $sub );
        $fill = static fn( $s ) => trim( strtr( (string) $s, $tokens ) );
        $title = $fill( $t['title'] ?? '' );
        if ( $title === '' ) return null;

        $image = 0;
        if ( ! empty( $source['item_image'] ) && $product ) $image = (int) $product->get_image_id();
        if ( ! $image && ! empty( $source['image'] ) ) $image = (int) $source['image'];

        return [
            'title' => $title,
            'body'  => $fill( $t['body'] ?? '' ),
            'url'   => self::link_url( (array) ( $source['link'] ?? [] ), $lang, $order, $product ),
            'image' => $image ? self::image_url( $image ) : '',
            'tag'   => ( $source['id'] ?? 'qwoo' ) . ( $order ? '-' . $order->get_id() : '' ) . ( $product ? '-' . $product->get_id() : '' ),
            'lang'  => $lang,
        ];
    }

    private static function is_pickup( WC_Order $order ): bool {
        foreach ( $order->get_shipping_methods() as $m ) {
            if ( in_array( $m->get_method_id(), [ 'local_pickup', 'pickup_location' ], true ) ) return true;
        }
        return false;
    }

    private static function tokens( $order, $product, array $sub ): array {
        $first = '';
        if ( $order ) $first = (string) $order->get_billing_first_name();
        if ( $first === '' && ! empty( $sub['customer_id'] ) ) $first = (string) get_user_meta( (int) $sub['customer_id'], 'first_name', true );
        $price = '';
        if ( $product ) {
            $price = html_entity_decode( wp_strip_all_tags( wc_price( wc_get_price_to_display( $product ) ) ), ENT_QUOTES, 'UTF-8' );
            $price = str_replace( "\u{00A0}", ' ', $price );
        }
        $plain = static fn( $s ) => html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' );
        return [
            '{store_name}'   => $plain( get_bloginfo( 'name' ) ),
            '{first_name}'   => $plain( $first ),
            '{product}'      => $product ? $plain( $product->get_name() ) : '',
            '{price}'        => $price,
            '{order_number}' => $order ? (string) $order->get_order_number() : '',
        ];
    }

    /** The address a notification opens: a path in the store (with the language prefix) or an https address. */
    public static function link_url( array $link, string $lang, $order = null, $product = null ): string {
        $type = (string) ( $link['type'] ?? 'home' );
        $id = (string) ( $link['id'] ?? '' );
        $path = '/';
        switch ( $type ) {
            case 'item':
                if ( $product ) $path = '/product/' . $product->get_slug();
                elseif ( $order ) $path = '/my-account/orders';
                break;
            case 'product':
                $p = wc_get_product( (int) $id );
                if ( $p ) $path = '/product/' . $p->get_slug();
                break;
            case 'category':
                $term = get_term( (int) $id, 'product_cat' );
                if ( $term && ! is_wp_error( $term ) ) $path = '/product-category/' . $term->slug;
                break;
            case 'post':
                $post = get_post( (int) $id );
                if ( $post && $post->post_status === 'publish' ) $path = '/blog/' . $post->post_name;
                break;
            case 'page':
                foreach ( (array) get_option( 'qwoo_published_pages', [] ) as $page ) {
                    if ( is_array( $page ) && ( $page['id'] ?? '' ) === $id ) $path = '/' . ltrim( (string) $page['path'], '/' );
                }
                break;
            case 'cart':    $path = '/cart'; break;
            case 'account': $path = '/my-account'; break;
            case 'orders':  $path = '/my-account/orders'; break;
            case 'url':
                $url = (string) ( $link['url'] ?? '' );
                if ( strpos( $url, 'https://' ) === 0 ) return $url;
                if ( $url !== '' ) $path = $url;
                break;
        }
        if ( $lang !== Qwoo_Push::main_lang() && class_exists( 'Qwoo_Store_Language' ) && in_array( $lang, Qwoo_Store_Language::live_extra(), true ) ) {
            $path = '/' . Qwoo_Store_Language::prefix( $lang ) . ( $path === '/' ? '' : $path );
        }
        return $path;
    }

    /** Photos load from the storefront's address (it serves the store's uploads). */
    private static function image_url( int $id ): string {
        $src = wp_get_attachment_image_src( $id, 'large' );
        $url = $src ? (string) $src[0] : '';
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? Qwoo_Technical_Settings::get_primary_frontend_domain() : '';
        $uploads = wp_get_upload_dir();
        if ( $url !== '' && $front && strpos( $url, $uploads['baseurl'] ) === 0 ) {
            $url = $front . '/wp-content/uploads' . substr( $url, strlen( $uploads['baseurl'] ) );
        }
        return $url;
    }

    /* ---------------- delivery ---------------- */

    public static function keys_ready(): bool {
        return class_exists( 'Qwoo_Technical_Settings' )
            && Qwoo_Technical_Settings::get_key( 'VAPID_API_PUBLIC_KEY' ) !== ''
            && Qwoo_Technical_Settings::get_key( 'VAPID_API_PRIVATE_KEY' ) !== '';
    }

    /**
     * Sends [ { row, sub, msg } ]. Returns [ row => true | reason ].
     * Devices the push service says are gone are removed.
     */
    public static function deliver( array $items ): array {
        $results = [];
        if ( ! $items ) return $results;
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? Qwoo_Technical_Settings::get_primary_frontend_domain() : '';
        $web = [];
        foreach ( $items as $it ) {
            if ( pwa_is_native_subscription( $it['sub'] ) ) {
                $results[ $it['row'] ] = self::deliver_native( $it['sub'], $it['msg'] );
            } else {
                $web[] = $it;
            }
        }
        if ( ! $web ) return $results;
        if ( ! self::keys_ready() ) {
            foreach ( $web as $it ) $results[ $it['row'] ] = 'no push keys';
            return $results;
        }
        try {
            $push = new WebPush( [ 'VAPID' => [
                'subject'    => qwoo_get_push_sender_email(),
                'publicKey'  => Qwoo_Technical_Settings::get_key( 'VAPID_API_PUBLIC_KEY' ),
                'privateKey' => Qwoo_Technical_Settings::get_key( 'VAPID_API_PRIVATE_KEY' ),
            ] ], [ 'TTL' => 2 * DAY_IN_SECONDS, 'urgency' => 'normal' ], 8 );
            $by_endpoint = [];
            foreach ( $web as $it ) {
                $m = $it['msg'];
                $payload = wp_json_encode( [ 'notification' => array_filter( [
                    'title' => $m['title'],
                    'body'  => $m['body'],
                    'image' => $m['image'] ?: null,
                    'icon'  => $front ? $front . '/icons/icon-128x128.png' : null,
                    'badge' => $front ? $front . '/icons/favicon-96x96.png' : null,
                    'tag'   => $m['tag'],
                    'dir'   => $m['lang'] === 'he' ? 'rtl' : 'auto',
                    'lang'  => $m['lang'],
                    'requireInteraction' => false,
                    'data'  => [ 'url' => $m['url'], 'q' => $m['q'] ?? 0, 'k' => $m['k'] ?? '' ],
                ], static fn( $v ) => $v !== null ) ] );
                // aes128gcm: the standard one (Safari takes only this; the library's default is the older aesgcm).
                $sub = Subscription::create( [ 'endpoint' => $it['sub']['endpoint'], 'contentEncoding' => 'aes128gcm', 'keys' => [ 'p256dh' => $it['sub']['p256dh'], 'auth' => $it['sub']['auth_key'] ] ] );
                $push->queueNotification( $sub, $payload );
                $by_endpoint[ $it['sub']['endpoint'] ][] = $it;
            }
            foreach ( $push->flush() as $report ) {
                $endpoint = $report->getEndpoint();
                foreach ( (array) ( $by_endpoint[ $endpoint ] ?? [] ) as $it ) {
                    if ( $report->isSuccess() ) {
                        $results[ $it['row'] ] = true;
                    } else {
                        $results[ $it['row'] ] = $report->isSubscriptionExpired() ? 'device gone' : substr( (string) $report->getReason(), 0, 60 );
                        if ( $report->isSubscriptionExpired() ) self::forget_device( (int) $it['sub']['id'] );
                    }
                }
            }
        } catch ( Throwable $e ) {
            error_log( '[qwoo push] web push: ' . $e->getMessage() );
            foreach ( $web as $it ) {
                if ( ! isset( $results[ $it['row'] ] ) ) $results[ $it['row'] ] = 'send error';
            }
        }
        foreach ( $web as $it ) {
            if ( ! isset( $results[ $it['row'] ] ) ) $results[ $it['row'] ] = 'no answer';
        }
        return $results;
    }

    private static function deliver_native( array $sub, array $m ) {
        try {
            if ( empty( $sub['endpoint'] ) || strlen( $sub['endpoint'] ) < 50 ) return 'bad token';
            $fcm = get_fcm_access_token();
            $res = wp_remote_post( "https://fcm.googleapis.com/v1/projects/{$fcm['project_id']}/messages:send", [
                'headers' => [ 'Authorization' => 'Bearer ' . $fcm['access_token'], 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'message' => [
                    'token'        => $sub['endpoint'],
                    'notification' => array_filter( [ 'title' => $m['title'], 'body' => $m['body'], 'image' => $m['image'] ?: null ] ),
                    'android'      => [ 'notification' => [ 'icon' => 'ic_stat_notify', 'color' => '#FFFFFF', 'default_vibrate_timings' => true, 'tag' => $m['tag'] ] ],
                    'data'         => [ 'url' => $m['url'], 'q' => (string) ( $m['q'] ?? 0 ), 'k' => (string) ( $m['k'] ?? '' ) ],
                ] ] ),
                'timeout' => 10,
            ] );
            if ( is_wp_error( $res ) ) return substr( $res->get_error_message(), 0, 60 );
            $code = (int) wp_remote_retrieve_response_code( $res );
            if ( $code === 404 ) {
                self::forget_device( (int) $sub['id'] );
                return 'device gone';
            }
            return $code >= 200 && $code < 300 ? true : 'HTTP ' . $code;
        } catch ( Throwable $e ) {
            return substr( $e->getMessage(), 0, 60 );
        }
    }

    public static function forget_device( int $sub ) {
        global $wpdb;
        $wpdb->delete( self::subs_table(), [ 'id' => $sub ] );
        $wpdb->delete( self::wants_table(), [ 'sub_id' => $sub ] );
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::log_table() . " SET status = 'cancel' WHERE sub_id = %d AND status = 'wait'", $sub ) );
    }

    /* ---------------- the owner's test ---------------- */

    /**
     * Sends a message now to the owner's own devices, ignoring quiet hours and the daily limit.
     * $p: { automation } or { texts, link, image }. The example item is the newest product / order.
     */
    public static function send_test( array $p ) {
        global $wpdb;
        $owners = (array) $wpdb->get_results( 'SELECT * FROM ' . self::subs_table() . ' WHERE owner = 1 ORDER BY id DESC LIMIT 5', ARRAY_A );
        if ( ! $owners ) return [ 'error' => 'Connect your phone first: open your test link on it and turn on notifications.' ];

        if ( ! empty( $p['automation'] ) ) {
            $source = Qwoo_Push::automation( (string) $p['automation'] );
            if ( ! $source ) return [ 'error' => 'Automation not found.' ];
            if ( isset( $p['texts'] ) ) {
                $source['texts'] = Qwoo_Push::clean_texts( $p['texts'], Qwoo_Push::langs() );
            }
        } else {
            $link = Qwoo_Push::clean_link( (array) ( $p['link'] ?? [] ) );
            if ( isset( $link['error'] ) ) return $link;
            $source = [ 'id' => 'test', 'texts' => Qwoo_Push::clean_texts( $p['texts'] ?? [], Qwoo_Push::langs() ), 'link' => $link, 'image' => Qwoo_Push::clean_image( $p['image'] ?? 0 ) ];
        }
        $ctx = [];
        $type = $source['trigger']['type'] ?? '';
        if ( in_array( $type, [ 'order_placed', 'order_status' ], true ) ) {
            $orders = wc_get_orders( [ 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' ] );
            if ( $orders ) $ctx['order'] = (int) $orders[0];
        }
        if ( in_array( $type, [ 'product_sale', 'back_in_stock' ], true ) || ( $source['link']['type'] ?? '' ) === 'item' ) {
            $products = wc_get_products( [ 'limit' => 1, 'status' => 'publish', 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' ] );
            if ( $products ) $ctx['product'] = (int) $products[0];
        }
        $items = [];
        foreach ( $owners as $sub ) {
            $msg = self::render( $source, $sub, $ctx );
            if ( ! $msg ) return [ 'error' => 'Write a title first.' ];
            $wpdb->insert( self::log_table(), [
                'sub_id' => (int) $sub['id'], 'kind' => 'test', 'ref' => substr( (string) ( $source['id'] ?? 'test' ), 0, 24 ), 'context' => wp_json_encode( $ctx ),
                'marketing' => 0, 'status' => 'wait', 'send_at' => self::now(), 'created_at' => self::now(),
            ] );
            $row = (int) $wpdb->insert_id;
            $msg['q'] = $row;
            $msg['k'] = self::open_key( $row );
            $items[] = [ 'row' => $row, 'sub' => $sub, 'msg' => $msg ];
        }
        $sent = 0;
        $why = '';
        foreach ( self::deliver( $items ) as $row => $result ) {
            self::mark( $row, $result === true ? 'sent' : 'failed', $result === true ? null : (string) $result );
            if ( $result === true ) $sent++;
            else $why = (string) $result;
        }
        if ( ! $sent ) return [ 'error' => 'The test didn\'t go out' . ( $why ? ' (' . $why . ')' : '' ) . '. Open your test link on your phone again.' ];
        return [ 'sent' => $sent ];
    }

    public static function disconnect_owner_devices() {
        global $wpdb;
        $wpdb->query( 'UPDATE ' . self::subs_table() . ' SET owner = 0 WHERE owner = 1' );
    }

    /* ---------------- counts ---------------- */

    /**
     * [ ref => { sent, opened, failed, waiting } ], or one ref's counts (over the last $days days).
     */
    public static function counts_by_ref( ?string $ref = null, ?int $days = null ) {
        global $wpdb;
        $where = "kind <> 'test'";
        if ( $ref !== null ) $where .= $wpdb->prepare( ' AND ref = %s', $ref );
        if ( $days ) $where .= $wpdb->prepare( ' AND created_at >= %s', self::at( time() - $days * DAY_IN_SECONDS ) );
        $rows = (array) $wpdb->get_results(
            'SELECT ref, status, COUNT(*) AS n, SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened FROM ' . self::log_table() . " WHERE $where GROUP BY ref, status",
            ARRAY_A
        );
        $out = [];
        foreach ( $rows as $r ) {
            $c = &$out[ $r['ref'] ];
            $c = $c ?? [ 'sent' => 0, 'opened' => 0, 'failed' => 0, 'waiting' => 0 ];
            if ( $r['status'] === 'sent' ) $c['sent'] += (int) $r['n'];
            if ( $r['status'] === 'failed' ) $c['failed'] += (int) $r['n'];
            if ( $r['status'] === 'wait' ) $c['waiting'] += (int) $r['n'];
            $c['opened'] += (int) $r['opened'];
            unset( $c );
        }
        if ( $ref !== null ) return $out[ $ref ] ?? [ 'sent' => 0, 'opened' => 0, 'failed' => 0, 'waiting' => 0 ];
        return $out;
    }

    public static function overview(): array {
        global $wpdb;
        $subs = self::subs_table();
        $log = self::log_table();
        $main = Qwoo_Push::main_lang();
        $by_lang = [];
        foreach ( (array) $wpdb->get_results( "SELECT lang, COUNT(*) AS n FROM $subs GROUP BY lang", ARRAY_A ) as $r ) {
            $l = (string) ( $r['lang'] ?: $main );
            $by_lang[ $l ] = ( $by_lang[ $l ] ?? 0 ) + (int) $r['n'];
        }
        $since = self::at( time() - 30 * DAY_IN_SECONDS );
        $month = $wpdb->get_row( $wpdb->prepare(
            "SELECT SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent, SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed FROM $log WHERE kind <> 'test' AND created_at >= %s",
            $since
        ), ARRAY_A ) ?: [];
        return [
            'devices'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $subs" ),
            // Devices' created_at is in the site's time (current_time).
            'new_30'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $subs WHERE created_at >= %s", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) ) ),
            'by_lang'   => $by_lang,
            'owner'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $subs WHERE owner = 1" ),
            'waiting'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $log WHERE status = 'wait'" ),
            'wants'     => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::wants_table() ),
            'sent_30'   => (int) ( $month['sent'] ?? 0 ),
            'opened_30' => (int) ( $month['opened'] ?? 0 ),
            'failed_30' => (int) ( $month['failed'] ?? 0 ),
        ];
    }
}

Qwoo_Push_Engine::init();

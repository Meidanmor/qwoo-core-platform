<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Webhooks the owner sets (dashboard → Marketing → Connections): the store
 * tells another service (Make, n8n, Zapier, their own server) when something
 * happens.
 *
 * Each delivery is a POST of { id, event, created, data } with
 *   X-Openly-Event: order.created
 *   X-Openly-Delivery: the delivery's id (the same on retries)
 *   X-Openly-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<body>" with the hook's secret>
 *
 * Events wait in a table and go out in the push engine's run (no cron on
 * shared hosting), with retries, and only to https addresses that aren't
 * private ones (wp_safe_remote_post re-checks the address when it connects).
 */
class Qwoo_Webhooks {

    const OPTION    = 'qwoo_webhooks';
    const MAX_HOOKS = 10;
    const BATCH     = 25;
    const RETRIES   = [ 60, 300, 1800, 7200, 21600 ]; // seconds before each retry
    const KEEP_DAYS = 14;

    const EVENTS = [
        'order.created', 'order.paid', 'order.status_changed', 'order.refunded',
        'customer.created', 'product.created', 'product.updated', 'stock.low',
        'review.created', 'subscriber.created', 'subscriber.unsubscribed',
    ];

    private static $products_seen = [];

    public static function init() {
        add_action( 'woocommerce_new_order', [ __CLASS__, 'on_new_order' ], 60, 2 );
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_status' ], 60, 4 );
        add_action( 'woocommerce_order_refunded', [ __CLASS__, 'on_refund' ], 60, 2 );
        add_action( 'woocommerce_created_customer', [ __CLASS__, 'on_customer' ], 60, 1 );
        add_action( 'woocommerce_new_product', [ __CLASS__, 'on_product_new' ], 60, 1 );
        add_action( 'woocommerce_update_product', [ __CLASS__, 'on_product_update' ], 60, 1 );
        add_action( 'woocommerce_low_stock', [ __CLASS__, 'on_low_stock' ], 60, 1 );
        add_action( 'comment_post', [ __CLASS__, 'on_review' ], 60, 3 );
        add_action( 'qwoo_push_tick', [ __CLASS__, 'deliver_due' ] );
        add_filter( 'qwoo_push_next_due', [ __CLASS__, 'next_due' ] );
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'qwoo_webhook_queue'; }

    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        dbDelta( "CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        hook_id VARCHAR(20) NOT NULL DEFAULT '',
        event VARCHAR(40) NOT NULL DEFAULT '',
        body LONGTEXT NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'wait',
        attempts INT NOT NULL DEFAULT 0,
        code INT NOT NULL DEFAULT 0,
        next_at DATETIME NOT NULL,
        created DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY status_next (status, next_at),
        KEY hook_id (hook_id)
        ) " . $wpdb->get_charset_collate() . ';' );
    }

    /* ---------------- settings ---------------- */

    public static function hooks(): array {
        $list = get_option( self::OPTION, [] );
        return is_array( $list ) ? $list : [];
    }

    /** What the dashboard sees: never the secret, except right after it's made. */
    public static function public_list(): array {
        global $wpdb;
        $out = [];
        foreach ( self::hooks() as $hook ) {
            unset( $hook['secret'] );
            $out[] = $hook;
        }
        $log = [];
        foreach ( (array) $wpdb->get_results( 'SELECT id, hook_id, event, status, attempts, code, created FROM ' . self::table() . ' ORDER BY id DESC LIMIT 100', ARRAY_A ) as $row ) {
            $log[] = [ 'id' => (int) $row['id'], 'hook' => $row['hook_id'], 'event' => $row['event'], 'status' => $row['status'], 'attempts' => (int) $row['attempts'], 'code' => (int) $row['code'], 'created' => $row['created'] ];
        }
        return [ 'hooks' => $out, 'log' => $log, 'events' => self::EVENTS, 'allowed' => self::allowed() ];
    }

    public static function allowed(): bool {
        return ! Qwoo_Platform_Dashboard::is_free_plan();
    }

    /** https, a public host, no credentials in the address. Returns '' or what's wrong. */
    public static function check_url( string $url ): string {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) || strtolower( $parts['scheme'] ?? '' ) !== 'https' ) return 'The address must start with https://.';
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) return 'The address can\'t include a password.';
        if ( strlen( $url ) > 500 ) return 'That address is too long.';
        if ( ! wp_http_validate_url( $url ) || ! self::public_host( (string) $parts['host'] ) ) return 'That address can\'t be used. It must be a public website.';
        return '';
    }

    /**
     * Every address the host points to must be public. WordPress's own check lets
     * through the same host and link-local addresses (169.254.x: cloud metadata).
     */
    private static function public_host( string $host ): bool {
        $host = trim( $host, '[]' );
        if ( strtolower( $host ) === 'localhost' || substr( strtolower( $host ), -6 ) === '.local' ) return false;
        $ips = [];
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $ips[] = $host;
        } else {
            foreach ( (array) @dns_get_record( $host, DNS_A | DNS_AAAA ) as $record ) { // phpcs:ignore
                if ( ! empty( $record['ip'] ) ) $ips[] = $record['ip'];
                if ( ! empty( $record['ipv6'] ) ) $ips[] = $record['ipv6'];
            }
            if ( ! $ips ) {
                $one = gethostbyname( $host );
                // No DNS answer here: wp_safe_remote_post checks again when it connects.
                if ( $one === $host ) return true;
                $ips[] = $one;
            }
        }
        foreach ( $ips as $ip ) {
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) return false;
            // Carrier-grade NAT, not covered by the flags above.
            if ( strpos( $ip, '100.' ) === 0 && (int) explode( '.', $ip )[1] >= 64 && (int) explode( '.', $ip )[1] <= 127 ) return false;
        }
        return true;
    }

    public static function save( array $in ) {
        if ( ! self::allowed() ) return new WP_Error( 'plan', 'Webhooks are on the Basic plan and up.' );
        $url = esc_url_raw( trim( (string) ( $in['url'] ?? '' ) ) );
        $bad = self::check_url( $url );
        if ( $bad !== '' ) return new WP_Error( 'url', $bad );
        $events = array_values( array_intersect( self::EVENTS, array_map( 'strval', (array) ( $in['events'] ?? [] ) ) ) );
        if ( ! $events ) return new WP_Error( 'events', 'Choose at least one event.' );
        $hooks = self::hooks();
        $id    = sanitize_key( (string) ( $in['id'] ?? '' ) );
        $fresh = '';
        $found = false;
        foreach ( $hooks as &$hook ) {
            if ( $hook['id'] === $id ) {
                $hook  = array_merge( $hook, [ 'url' => $url, 'events' => $events, 'name' => sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 'on' => ! empty( $in['on'] ) ] );
                $found = true;
            }
        }
        unset( $hook );
        if ( ! $found ) {
            if ( count( $hooks ) >= self::MAX_HOOKS ) return new WP_Error( 'max', 'A store can have up to ' . self::MAX_HOOKS . ' webhooks.' );
            $fresh   = 'whsec_' . bin2hex( random_bytes( 24 ) );
            $id      = 'wh' . bin2hex( random_bytes( 5 ) );
            $hooks[] = [ 'id' => $id, 'name' => sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 'url' => $url, 'events' => $events, 'on' => true, 'secret' => $fresh, 'created' => time() ];
        }
        update_option( self::OPTION, $hooks, false );
        return [ 'id' => $id, 'secret' => $fresh ] + self::public_list();
    }

    public static function remove( string $id ): array {
        global $wpdb;
        update_option( self::OPTION, array_values( array_filter( self::hooks(), static fn( $h ) => $h['id'] !== $id ) ), false );
        $wpdb->delete( self::table(), [ 'hook_id' => $id, 'status' => 'wait' ] );
        return self::public_list();
    }

    /** A new signing key: the old one stops working. Shown once. */
    public static function new_secret( string $id ) {
        $hooks = self::hooks();
        $fresh = '';
        foreach ( $hooks as &$hook ) {
            if ( $hook['id'] !== $id ) continue;
            $fresh          = 'whsec_' . bin2hex( random_bytes( 24 ) );
            $hook['secret'] = $fresh;
        }
        unset( $hook );
        if ( $fresh === '' ) return new WP_Error( 'missing', 'That webhook doesn\'t exist any more.' );
        update_option( self::OPTION, $hooks, false );
        return [ 'secret' => $fresh ] + self::public_list();
    }

    /** A sample delivery, sent right now; the answer is shown in the dashboard. */
    public static function test( string $id ): array {
        foreach ( self::hooks() as $hook ) {
            if ( $hook['id'] !== $id ) continue;
            $body = wp_json_encode( [ 'id' => 'test_' . bin2hex( random_bytes( 6 ) ), 'event' => 'test', 'created' => time(), 'data' => [ 'message' => 'A test from your store.' ] ] );
            $code = self::post( $hook, 'test', 'test', $body );
            // In the log like any other message; tests never retry.
            global $wpdb;
            $now = gmdate( 'Y-m-d H:i:s' );
            $wpdb->insert( self::table(), [ 'hook_id' => $id, 'event' => 'test', 'body' => '', 'status' => $code >= 200 && $code < 300 ? 'sent' : 'failed', 'attempts' => 1, 'code' => $code, 'next_at' => $now, 'created' => $now ] );
            return [ 'code' => $code ] + self::public_list();
        }
        return [ 'code' => 0 ];
    }

    /* ---------------- events ---------------- */

    public static function emit( string $event, array $data ) {
        global $wpdb;
        if ( ! self::hooks() || ! self::allowed() ) return;
        $any = false;
        foreach ( self::hooks() as $hook ) {
            if ( empty( $hook['on'] ) || ! in_array( $event, (array) $hook['events'], true ) ) continue;
            $now = gmdate( 'Y-m-d H:i:s' );
            $wpdb->insert( self::table(), [
                'hook_id' => $hook['id'],
                'event'   => $event,
                'body'    => wp_json_encode( [ 'id' => 'evt_' . bin2hex( random_bytes( 8 ) ), 'event' => $event, 'created' => time(), 'data' => $data ] ),
                'status'  => 'wait',
                'next_at' => $now,
                'created' => $now,
            ] );
            $any = true;
        }
        if ( $any ) {
            Qwoo_Push_Engine::note_due( time() );
            Qwoo_Push_Engine::spawn_soon();
        }
    }

    public static function order_data( $order ): array {
        $items = [];
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $items[] = [ 'product_id' => (int) $item->get_product_id(), 'variation_id' => (int) $item->get_variation_id(), 'name' => $item->get_name(), 'quantity' => (int) $item->get_quantity(), 'total' => (float) $item->get_total() ];
        }
        return [
            'id'       => $order->get_id(),
            'number'   => $order->get_order_number(),
            'status'   => $order->get_status(),
            'total'    => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'email'    => $order->get_billing_email(),
            'phone'    => $order->get_billing_phone(),
            'name'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'city'     => $order->get_shipping_city() ?: $order->get_billing_city(),
            'items'    => $items,
            'date'     => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : '',
        ];
    }

    public static function on_new_order( $order_id, $order = null ) {
        $order = $order ?: wc_get_order( $order_id );
        if ( $order && $order->get_type() === 'shop_order' && $order->get_status() !== 'checkout-draft' ) self::emit( 'order.created', self::order_data( $order ) );
    }

    public static function on_status( $order_id, $from, $to, $order = null ) {
        $order = $order ?: wc_get_order( $order_id );
        if ( ! $order ) return;
        $data = self::order_data( $order ) + [ 'previous_status' => (string) $from ];
        if ( $from === 'checkout-draft' ) self::emit( 'order.created', $data );
        self::emit( 'order.status_changed', $data );
        $paid = wc_get_is_paid_statuses();
        if ( in_array( $to, $paid, true ) && ! in_array( $from, $paid, true ) ) self::emit( 'order.paid', $data );
    }

    public static function on_refund( $order_id, $refund_id ) {
        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );
        if ( $order ) self::emit( 'order.refunded', self::order_data( $order ) + [ 'refund_amount' => $refund ? (float) $refund->get_amount() : 0 ] );
    }

    public static function on_customer( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user ) self::emit( 'customer.created', [ 'id' => (int) $user_id, 'email' => $user->user_email, 'name' => $user->display_name ] );
    }

    private static function product_data( $product ): array {
        return [ 'id' => $product->get_id(), 'name' => $product->get_name(), 'sku' => $product->get_sku(), 'status' => $product->get_status(), 'price' => $product->get_price(), 'stock' => $product->get_stock_quantity(), 'in_stock' => $product->is_in_stock(), 'url' => get_permalink( $product->get_id() ) ];
    }

    public static function on_product_new( $id ) {
        self::$products_seen[ $id ] = true;
        $product = wc_get_product( $id );
        if ( $product ) self::emit( 'product.created', self::product_data( $product ) );
    }

    /** A save can fire several times in a request: one event. */
    public static function on_product_update( $id ) {
        if ( isset( self::$products_seen[ $id ] ) ) return;
        self::$products_seen[ $id ] = true;
        $product = wc_get_product( $id );
        if ( $product ) self::emit( 'product.updated', self::product_data( $product ) );
    }

    public static function on_low_stock( $product ) {
        if ( $product ) self::emit( 'stock.low', self::product_data( $product ) );
    }

    public static function on_review( $comment_id, $approved, $data ) {
        if ( ( $data['comment_type'] ?? '' ) !== 'review' ) return;
        self::emit( 'review.created', [ 'id' => (int) $comment_id, 'product_id' => (int) ( $data['comment_post_ID'] ?? 0 ), 'author' => (string) ( $data['comment_author'] ?? '' ), 'text' => (string) ( $data['comment_content'] ?? '' ), 'approved' => $approved === 1 ] );
    }

    /* ---------------- delivery ---------------- */

    private static function post( array $hook, string $event, string $delivery, string $body ): int {
        // Checked again here: the address may have stopped being public since it was saved.
        if ( self::check_url( (string) $hook['url'] ) !== '' ) return 0;
        $time = time();
        $res  = wp_safe_remote_post( $hook['url'], [
            'timeout'     => 8,
            'redirection' => 0,
            'blocking'    => true,
            'headers'     => [
                'Content-Type'          => 'application/json',
                'User-Agent'            => 'Openly-Webhooks/1',
                'X-Openly-Event'     => $event,
                'X-Openly-Delivery'  => $delivery,
                'X-Openly-Signature' => 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, (string) $hook['secret'] ),
            ],
            'body'        => $body,
        ] );
        return is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
    }

    public static function deliver_due( float $until ) {
        global $wpdb;
        $t     = self::table();
        $hooks = array_column( self::hooks(), null, 'id' );
        while ( microtime( true ) < $until ) {
            $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = 'wait' AND next_at <= %s ORDER BY id LIMIT %d", gmdate( 'Y-m-d H:i:s' ), self::BATCH ), ARRAY_A );
            if ( ! $rows ) break;
            foreach ( $rows as $row ) {
                if ( microtime( true ) >= $until ) break 2;
                $hook = $hooks[ $row['hook_id'] ] ?? null;
                if ( ! $hook || empty( $hook['on'] ) ) {
                    $wpdb->update( $t, [ 'status' => 'dropped' ], [ 'id' => $row['id'] ] );
                    continue;
                }
                $decoded = json_decode( $row['body'], true );
                $code    = self::post( $hook, $row['event'], (string) ( $decoded['id'] ?? $row['id'] ), $row['body'] );
                $tries   = (int) $row['attempts'];
                $done    = $code >= 200 && $code < 300;
                $wpdb->update( $t, [
                    'status'   => $done ? 'sent' : ( $tries >= count( self::RETRIES ) ? 'failed' : 'wait' ),
                    'attempts' => $tries + 1,
                    'code'     => $code,
                    'next_at'  => gmdate( 'Y-m-d H:i:s', time() + ( self::RETRIES[ $tries ] ?? 0 ) ),
                ], [ 'id' => $row['id'] ] );
            }
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE status <> 'wait' AND created < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
    }

    /** The earliest waiting delivery (Unix), for the engine's next run. */
    public static function next_due( $due ) {
        global $wpdb;
        $next = $wpdb->get_var( 'SELECT MIN(next_at) FROM ' . self::table() . " WHERE status = 'wait'" );
        if ( ! $next ) return $due;
        $unix = strtotime( $next . ' UTC' );
        return $due && $due < $unix ? $due : $unix;
    }
}

Qwoo_Webhooks::init();

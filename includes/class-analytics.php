<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Store analytics (dashboard → Analytics), from the store's own orders.
 *
 * Two small tables hold one row per order and one per order line, kept up to
 * date by WooCommerce's hooks, so a report is a few SQL sums rather than
 * thousands of loaded orders. Older orders are copied in by the reports
 * themselves: every call copies a batch (no cron on shared hosting) and says
 * how far it got (`filling`); the dashboard asks again until it's done.
 *
 * Visits, funnel and traffic reports aren't here: they come from the owner's
 * GA4 (the user's decision, 2026-11-05). `source / medium / campaign` on an
 * order is what the storefront saw when the shopper arrived (UTM tags or the
 * referring site), sent with the checkout.
 *
 * SQL stays portable: the local test setup runs on SQLite.
 */
class Qwoo_Analytics {

    const FILL_OPTION = 'qwoo_analytics_fill'; // missing = not started, 0 = done, anything else = copying
    const FILL_CURSOR = 'qwoo_analytics_fill_cursor'; // { at: copy orders made at or before this time, done, total }
    const FILL_BATCH  = 100;
    const MAX_DAYS    = 732;

    private static $dirty = [];

    public static function init() {
        foreach ( [ 'woocommerce_new_order', 'woocommerce_update_order', 'woocommerce_order_refunded', 'woocommerce_trash_order', 'woocommerce_untrash_order', 'woocommerce_delete_order' ] as $hook ) {
            add_action( $hook, [ __CLASS__, 'mark' ], 50, 1 );
        }
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'mark' ], 50, 1 );
        add_action( 'shutdown', [ __CLASS__, 'flush' ], 5 );
    }

    public static function orders_table() { global $wpdb; return $wpdb->prefix . 'qwoo_an_orders'; }
    public static function lines_table() { global $wpdb; return $wpdb->prefix . 'qwoo_an_lines'; }

    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $orders  = self::orders_table();
        $lines   = self::lines_table();
        dbDelta( "CREATE TABLE $orders (
        order_id BIGINT UNSIGNED NOT NULL,
        day CHAR(10) NOT NULL DEFAULT '',
        status VARCHAR(30) NOT NULL DEFAULT '',
        paid TINYINT(1) NOT NULL DEFAULT 0,
        total DECIMAL(16,4) NOT NULL DEFAULT 0,
        refunded DECIMAL(16,4) NOT NULL DEFAULT 0,
        tax DECIMAL(16,4) NOT NULL DEFAULT 0,
        shipping DECIMAL(16,4) NOT NULL DEFAULT 0,
        discount DECIMAL(16,4) NOT NULL DEFAULT 0,
        items INT NOT NULL DEFAULT 0,
        email VARCHAR(190) NOT NULL DEFAULT '',
        name VARCHAR(190) NOT NULL DEFAULT '',
        region VARCHAR(100) NOT NULL DEFAULT '',
        payment VARCHAR(100) NOT NULL DEFAULT '',
        source VARCHAR(60) NOT NULL DEFAULT '',
        medium VARCHAR(60) NOT NULL DEFAULT '',
        campaign VARCHAR(100) NOT NULL DEFAULT '',
        PRIMARY KEY  (order_id),
        KEY day (day),
        KEY email (email)
        ) $charset;" );
        dbDelta( "CREATE TABLE $lines (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        name VARCHAR(190) NOT NULL DEFAULT '',
        day CHAR(10) NOT NULL DEFAULT '',
        paid TINYINT(1) NOT NULL DEFAULT 0,
        units INT NOT NULL DEFAULT 0,
        subtotal DECIMAL(16,4) NOT NULL DEFAULT 0,
        total DECIMAL(16,4) NOT NULL DEFAULT 0,
        refunded DECIMAL(16,4) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY order_id (order_id),
        KEY day (day)
        ) $charset;" );
    }

    /* ---------------- keeping the tables up to date ---------------- */

    public static function mark( $order_id ) {
        $id = is_object( $order_id ) && method_exists( $order_id, 'get_id' ) ? (int) $order_id->get_id() : (int) $order_id;
        if ( $id > 0 ) self::$dirty[ $id ] = true;
    }

    public static function flush() {
        foreach ( array_keys( self::$dirty ) as $id ) {
            try {
                self::sync_order( (int) $id );
            } catch ( Throwable $e ) {
                error_log( '[qwoo analytics] order ' . $id . ': ' . $e->getMessage() );
            }
        }
        self::$dirty = [];
    }

    public static function forget_order( int $id ) {
        global $wpdb;
        $wpdb->delete( self::orders_table(), [ 'order_id' => $id ] );
        $wpdb->delete( self::lines_table(), [ 'order_id' => $id ] );
    }

    public static function sync_order( int $id ) {
        global $wpdb;
        $order = wc_get_order( $id );
        // A refund is its own kind of "order"; its parent gets counted through woocommerce_order_refunded.
        if ( ! $order || $order->get_type() !== 'shop_order' || in_array( $order->get_status(), [ 'trash', 'auto-draft', 'checkout-draft' ], true ) ) {
            self::forget_order( $id );
            return;
        }
        $created = $order->get_date_created();
        $day     = $created ? wp_date( 'Y-m-d', $created->getTimestamp(), wp_timezone() ) : '';
        $status  = $order->get_status();
        $paid    = in_array( $status, wc_get_is_paid_statuses(), true ) ? 1 : 0;
        $name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $region  = (string) ( $order->get_shipping_city() ?: $order->get_billing_city() );

        $wpdb->replace( self::orders_table(), [
            'order_id' => $id,
            'day'      => $day,
            'status'   => $status,
            'paid'     => $paid,
            'total'    => (float) $order->get_total(),
            'refunded' => (float) $order->get_total_refunded(),
            'tax'      => (float) $order->get_total_tax(),
            'shipping' => (float) $order->get_shipping_total(),
            'discount' => (float) $order->get_discount_total(),
            'items'    => (int) $order->get_item_count(),
            'email'    => mb_substr( strtolower( trim( (string) $order->get_billing_email() ) ), 0, 190 ),
            'name'     => mb_substr( $name, 0, 190 ),
            'region'   => mb_substr( $region, 0, 100 ),
            'payment'  => mb_substr( (string) $order->get_payment_method_title(), 0, 100 ),
            'source'   => mb_substr( (string) $order->get_meta( '_qwoo_source' ), 0, 60 ),
            'medium'   => mb_substr( (string) $order->get_meta( '_qwoo_medium' ), 0, 60 ),
            'campaign' => mb_substr( (string) $order->get_meta( '_qwoo_campaign' ), 0, 100 ),
        ] );

        $wpdb->delete( self::lines_table(), [ 'order_id' => $id ] );
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $wpdb->insert( self::lines_table(), [
                'order_id'   => $id,
                'product_id' => (int) $item->get_product_id(),
                'name'       => mb_substr( (string) $item->get_name(), 0, 190 ),
                'day'        => $day,
                'paid'       => $paid,
                'units'      => max( 0, (int) $item->get_quantity() ),
                'subtotal'   => (float) $item->get_subtotal(),
                'total'      => (float) $item->get_total(),
                'refunded'   => (float) $order->get_total_refunded_for_item( $item->get_id() ),
            ] );
        }
    }

    /**
     * Copies a batch of older orders, newest first, so the periods owners look at (this one and the one before)
     * are whole first. Copying oldest first left the current period short on big stores, so "vs previous" read low.
     * Returns how far it got, 0–100 (100 = done).
     */
    public static function fill(): int {
        $state = get_option( self::FILL_OPTION, null );
        if ( $state !== null && (int) $state <= 0 ) return 100;
        $cursor = get_option( self::FILL_CURSOR, null );
        if ( $state === null || ! is_array( $cursor ) ) {
            // A new copy, or one started by the older oldest-first code: start again from now (copying twice is harmless).
            $count  = wc_get_orders( [ 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ), 'limit' => 1, 'return' => 'ids', 'paginate' => true ] );
            $cursor = [ 'at' => time() + 60, 'done' => 0, 'total' => (int) $count->total ];
            update_option( self::FILL_OPTION, 1, false );
        }
        $ids = wc_get_orders( [
            'type'         => 'shop_order',
            'status'       => array_keys( wc_get_order_statuses() ),
            'limit'        => self::FILL_BATCH,
            'date_created' => '<=' . (int) $cursor['at'],
            'orderby'      => 'date',
            'order'        => 'DESC',
            'return'       => 'ids',
        ] );
        $oldest = null;
        foreach ( $ids as $id ) {
            self::sync_order( (int) $id );
            $order   = wc_get_order( $id );
            $created = $order ? $order->get_date_created() : null;
            if ( $created ) $oldest = $created->getTimestamp();
        }
        if ( count( $ids ) < self::FILL_BATCH || $oldest === null ) {
            update_option( self::FILL_OPTION, 0, false );
            delete_option( self::FILL_CURSOR );
            return 100;
        }
        // Next batch: orders made at or before the oldest one here (the same second may hold more of them).
        // A whole batch inside one second moves on a second, so the copy can't loop.
        $cursor['at']   = $oldest < (int) $cursor['at'] ? $oldest : (int) $cursor['at'] - 1;
        $cursor['done'] = (int) $cursor['done'] + count( $ids );
        update_option( self::FILL_CURSOR, $cursor, false );
        return (int) min( 99, floor( $cursor['done'] / max( 1, (int) $cursor['total'] ) * 99 ) );
    }

    /** True when every order made on or after this day (store time) is copied. */
    private static function copied_since( string $day ): bool {
        if ( (int) get_option( self::FILL_OPTION, 1 ) <= 0 ) return true;
        $cursor = get_option( self::FILL_CURSOR, null );
        if ( ! is_array( $cursor ) ) return false;
        $start = new DateTimeImmutable( $day, wp_timezone() );
        return (int) $cursor['at'] < $start->getTimestamp();
    }

    /* ---------------- the report ---------------- */

    /** The dates a request asks for: { from, to, prev_from, prev_to, days }, in the store's time zone. */
    public static function period( array $params ): array {
        $tz    = wp_timezone();
        $today = new DateTimeImmutable( 'today', $tz );
        $from  = self::date_param( $params['from'] ?? '', $tz );
        $to    = self::date_param( $params['to'] ?? '', $tz );
        if ( $from && $to && $from <= $to ) {
            if ( $to > $today ) $to = $today;
            if ( $from > $to ) $from = $to;
            $days = (int) $from->diff( $to )->days + 1;
            if ( $days > self::MAX_DAYS ) {
                $from = $to->modify( '-' . ( self::MAX_DAYS - 1 ) . ' days' );
                $days = self::MAX_DAYS;
            }
        } else {
            $days = (int) ( $params['range'] ?? 30 );
            if ( ! in_array( $days, [ 7, 30, 90, 365 ], true ) ) $days = 30;
            $to   = $today;
            $from = $today->modify( '-' . ( $days - 1 ) . ' days' );
        }
        $prev_to   = $from->modify( '-1 day' );
        $prev_from = $prev_to->modify( '-' . ( $days - 1 ) . ' days' );
        return [
            'from'      => $from->format( 'Y-m-d' ),
            'to'        => $to->format( 'Y-m-d' ),
            'prev_from' => $prev_from->format( 'Y-m-d' ),
            'prev_to'   => $prev_to->format( 'Y-m-d' ),
            'days'      => $days,
        ];
    }

    private static function date_param( $value, DateTimeZone $tz ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) return null;
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $tz );
        return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
    }

    public static function report( array $params ): array {
        global $wpdb;
        $filling = self::fill();
        $p       = self::period( $params );
        $o       = self::orders_table();
        $l       = self::lines_table();
        $range   = $wpdb->prepare( 'day >= %s AND day <= %s', $p['from'], $p['to'] );
        $before  = $wpdb->prepare( 'day >= %s AND day <= %s', $p['prev_from'], $p['prev_to'] );
        $sums    = 'COUNT(*) AS orders, COALESCE(SUM(total),0) AS gross, COALESCE(SUM(refunded),0) AS refunds, COALESCE(SUM(tax),0) AS tax, COALESCE(SUM(shipping),0) AS shipping, COALESCE(SUM(discount),0) AS discounts, COALESCE(SUM(items),0) AS items';

        $current = self::totals( (array) $wpdb->get_row( "SELECT $sums FROM $o WHERE paid = 1 AND $range", ARRAY_A ) );
        $prior   = self::totals( (array) $wpdb->get_row( "SELECT $sums FROM $o WHERE paid = 1 AND $before", ARRAY_A ) );

        // Every day of both periods, also the empty ones. `previous` lines up with `sales` day by day.
        $by_day = [];
        foreach ( (array) $wpdb->get_results( "SELECT day, paid, COUNT(*) AS n, COALESCE(SUM(total - refunded),0) AS net FROM $o WHERE $range GROUP BY day, paid", ARRAY_A ) as $row ) {
            $by_day[ $row['day'] ]['orders'] = ( $by_day[ $row['day'] ]['orders'] ?? 0 ) + (int) $row['n'];
            if ( (int) $row['paid'] ) $by_day[ $row['day'] ]['sales'] = (float) $row['net'];
        }
        $prev_day = [];
        foreach ( (array) $wpdb->get_results( "SELECT day, COALESCE(SUM(total - refunded),0) AS net FROM $o WHERE paid = 1 AND $before GROUP BY day", ARRAY_A ) as $row ) {
            $prev_day[ $row['day'] ] = (float) $row['net'];
        }
        $tz    = wp_timezone();
        $start = new DateTimeImmutable( $p['from'], $tz );
        $prev  = new DateTimeImmutable( $p['prev_from'], $tz );
        $daily = [];
        for ( $i = 0; $i < $p['days']; $i++ ) {
            $day = $start->modify( "+$i days" )->format( 'Y-m-d' );
            $old = $prev->modify( "+$i days" )->format( 'Y-m-d' );
            $daily[ $day ] = [
                'sales'    => round( (float) ( $by_day[ $day ]['sales'] ?? 0 ), 2 ),
                'orders'   => (int) ( $by_day[ $day ]['orders'] ?? 0 ),
                'previous' => round( (float) ( $prev_day[ $old ] ?? 0 ), 2 ),
            ];
        }

        $statuses = [];
        foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $o WHERE $range GROUP BY status ORDER BY n DESC", ARRAY_A ) as $row ) {
            $statuses[ $row['status'] ] = (int) $row['n'];
        }

        // Payment methods: "worked" = paid orders out of those not simply waiting to be paid.
        $payments = [];
        foreach ( (array) $wpdb->get_results( "SELECT payment, status, paid, COUNT(*) AS n, COALESCE(SUM(total - refunded),0) AS net FROM $o WHERE $range GROUP BY payment, status, paid", ARRAY_A ) as $row ) {
            $key = $row['payment'] !== '' ? $row['payment'] : 'Other';
            $payments[ $key ] = $payments[ $key ] ?? [ 'name' => $key, 'orders' => 0, 'sales' => 0.0, 'failed' => 0 ];
            if ( (int) $row['paid'] ) {
                $payments[ $key ]['orders'] += (int) $row['n'];
                $payments[ $key ]['sales']  += (float) $row['net'];
            } elseif ( in_array( $row['status'], [ 'failed', 'cancelled' ], true ) ) {
                $payments[ $key ]['failed'] += (int) $row['n'];
            }
        }
        foreach ( $payments as &$row ) {
            $row['sales']   = round( $row['sales'], 2 );
            $row['average'] = $row['orders'] ? round( $row['sales'] / $row['orders'], 2 ) : 0;
            $tries          = $row['orders'] + $row['failed'];
            $row['success'] = $tries ? round( $row['orders'] / $tries * 100, 1 ) : null;
        }
        unset( $row );
        usort( $payments, static fn( $a, $b ) => $b['sales'] <=> $a['sales'] );

        return [
            'filling'   => $filling,
            // While older orders are still being copied, a period that isn't whole yet would make "vs previous" wrong.
            'ready'     => [ 'current' => $filling >= 100 || self::copied_since( $p['from'] ), 'previous' => $filling >= 100 || self::copied_since( $p['prev_from'] ) ],
            'period'    => $p,
            'currency'  => get_woocommerce_currency(),
            'timezone'  => wp_timezone_string(),
            'current'   => $current,
            'previous'  => $prior,
            'daily'     => $daily,
            'statuses'  => $statuses,
            'payments'  => array_values( $payments ),
            'recent'    => self::recent(),
            'buyers'    => self::buyers( $p, $range ),
            'products'  => self::products( $range ),
            'sources'   => self::sources( $range ),
        ];
    }

    private static function totals( array $row ): array {
        $gross   = (float) ( $row['gross'] ?? 0 );
        $refunds = (float) ( $row['refunds'] ?? 0 );
        $orders  = (int) ( $row['orders'] ?? 0 );
        $net     = max( 0, $gross - $refunds );
        return [
            'orders'    => $orders,
            'gross'     => round( $gross, 2 ),
            'refunds'   => round( $refunds, 2 ),
            'sales'     => round( $net, 2 ),
            'average'   => $orders ? round( $net / $orders, 2 ) : 0,
            'tax'       => round( (float) ( $row['tax'] ?? 0 ), 2 ),
            'shipping'  => round( (float) ( $row['shipping'] ?? 0 ), 2 ),
            'discounts' => round( (float) ( $row['discounts'] ?? 0 ), 2 ),
            'items'     => (int) ( $row['items'] ?? 0 ),
        ];
    }

    private static function recent(): array {
        global $wpdb;
        $o = self::orders_table();
        $out = [];
        foreach ( (array) $wpdb->get_results( "SELECT order_id, day, status, total, name, email FROM $o ORDER BY order_id DESC LIMIT 5", ARRAY_A ) as $row ) {
            $out[] = [ 'id' => (int) $row['order_id'], 'day' => $row['day'], 'status' => $row['status'], 'total' => round( (float) $row['total'], 2 ), 'name' => $row['name'], 'email' => $row['email'] ];
        }
        return $out;
    }

    /** New and returning buyers: a buyer is new when their first paid order ever falls in the period. */
    private static function buyers( array $p, string $range ): array {
        global $wpdb;
        $o = self::orders_table();
        $people = (array) $wpdb->get_results( "SELECT email, MAX(name) AS name, COUNT(*) AS orders, COALESCE(SUM(total - refunded),0) AS net, MIN(day) AS first_day, MAX(day) AS last_day FROM $o WHERE paid = 1 AND email <> '' AND $range GROUP BY email", ARRAY_A );
        $first = [];
        foreach ( (array) $wpdb->get_results( "SELECT email, MIN(day) AS first_ever FROM $o WHERE paid = 1 AND email <> '' GROUP BY email", ARRAY_A ) as $row ) {
            $first[ $row['email'] ] = $row['first_ever'];
        }
        $new = $back = 0;
        $back_sales = 0.0;
        foreach ( $people as &$row ) {
            $row['first_ever'] = $first[ $row['email'] ] ?? $row['first_day'];
            if ( $row['first_ever'] >= $p['from'] ) {
                $new++;
            } else {
                $back++;
                $back_sales += (float) $row['net'];
            }
        }
        unset( $row );
        usort( $people, static fn( $a, $b ) => (float) $b['net'] <=> (float) $a['net'] );
        $top = [];
        foreach ( array_slice( $people, 0, 10 ) as $row ) {
            $top[] = [ 'name' => $row['name'] ?: $row['email'], 'email' => $row['email'], 'orders' => (int) $row['orders'], 'first' => $row['first_ever'], 'last' => $row['last_day'], 'sales' => round( (float) $row['net'], 2 ) ];
        }
        $regions = [];
        foreach ( (array) $wpdb->get_results( "SELECT region, COUNT(*) AS n FROM $o WHERE paid = 1 AND region <> '' AND $range GROUP BY region ORDER BY n DESC LIMIT 8", ARRAY_A ) as $row ) {
            $regions[] = [ 'name' => $row['region'], 'orders' => (int) $row['n'] ];
        }
        return [ 'unique' => count( $people ), 'new' => $new, 'returning' => $back, 'returning_sales' => round( $back_sales, 2 ), 'top' => $top, 'regions' => $regions ];
    }

    private static function products( string $range ): array {
        global $wpdb;
        $l    = self::lines_table();
        $rows = (array) $wpdb->get_results( "SELECT product_id, MAX(name) AS name, COALESCE(SUM(units),0) AS units, COALESCE(SUM(total - refunded),0) AS net, COALESCE(SUM(subtotal - total),0) AS discounts, COALESCE(SUM(refunded),0) AS refunds FROM $l WHERE paid = 1 AND $range GROUP BY product_id", ARRAY_A );
        usort( $rows, static fn( $a, $b ) => (float) $b['net'] <=> (float) $a['net'] );
        $rows = array_slice( $rows, 0, 100 );
        $list = [];
        $cats = [];
        foreach ( $rows as $row ) {
            $id      = (int) $row['product_id'];
            $product = $id ? wc_get_product( $id ) : null;
            $sales   = round( (float) $row['net'], 2 );
            $list[]  = [
                'id'        => $id,
                'name'      => $product ? html_entity_decode( $product->get_name(), ENT_QUOTES ) : $row['name'],
                'units'     => (int) $row['units'],
                'sales'     => $sales,
                'discounts' => round( (float) $row['discounts'], 2 ),
                'refunds'   => round( (float) $row['refunds'], 2 ),
                'stock'     => $product && $product->managing_stock() ? $product->get_stock_quantity() : null,
            ];
            foreach ( $product ? (array) wc_get_product_terms( $id, 'product_cat', [ 'fields' => 'names' ] ) : [] as $name ) {
                $cats[ $name ] = ( $cats[ $name ] ?? 0 ) + $sales;
            }
        }
        arsort( $cats );
        $categories = [];
        foreach ( array_slice( $cats, 0, 10, true ) as $name => $sales ) {
            $categories[] = [ 'name' => html_entity_decode( (string) $name, ENT_QUOTES ), 'sales' => round( $sales, 2 ) ];
        }

        $threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
        $low = wc_get_products( [ 'limit' => 20, 'status' => 'publish', 'manage_stock' => true, 'stock_status' => 'instock', 'orderby' => 'meta_value_num', 'meta_key' => '_stock', 'order' => 'ASC' ] );
        $low_list = [];
        foreach ( $low as $product ) {
            $stock = $product->get_stock_quantity();
            if ( $stock === null || $stock > max( 1, $product->get_low_stock_amount() ?: $threshold ) ) continue;
            $low_list[] = [ 'id' => $product->get_id(), 'name' => html_entity_decode( $product->get_name(), ENT_QUOTES ), 'stock' => (int) $stock ];
        }
        $active = wp_count_posts( 'product' );
        return [ 'list' => $list, 'categories' => $categories, 'low_stock' => $low_list, 'active' => (int) ( $active->publish ?? 0 ) ];
    }

    /** Sales by where the shopper came from, as seen by the storefront at checkout. */
    private static function sources( string $range ): array {
        global $wpdb;
        $o = self::orders_table();
        $out = [];
        foreach ( (array) $wpdb->get_results( "SELECT source, medium, campaign, COUNT(*) AS n, COALESCE(SUM(total - refunded),0) AS net FROM $o WHERE paid = 1 AND $range GROUP BY source, medium, campaign ORDER BY net DESC LIMIT 50", ARRAY_A ) as $row ) {
            $out[] = [ 'source' => $row['source'], 'medium' => $row['medium'], 'campaign' => $row['campaign'], 'orders' => (int) $row['n'], 'sales' => round( (float) $row['net'], 2 ) ];
        }
        return $out;
    }

    /** Orders and net sales that came from one campaign tag (Qwoo_Email_Marketing's "c12"). */
    public static function campaign_results( string $medium, string $campaign ): array {
        global $wpdb;
        $o   = self::orders_table();
        $row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(total - refunded),0) AS net FROM $o WHERE paid = 1 AND medium = %s AND campaign = %s", $medium, $campaign ), ARRAY_A );
        return [ 'orders' => (int) ( $row['n'] ?? 0 ), 'sales' => round( (float) ( $row['net'] ?? 0 ), 2 ) ];
    }

    /** Whether an email address has a paid order (segments, automation conditions). */
    public static function last_order_day( string $email ): string {
        global $wpdb;
        $o = self::orders_table();
        return (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(day) FROM $o WHERE paid = 1 AND email = %s", strtolower( $email ) ) );
    }
}

Qwoo_Analytics::init();

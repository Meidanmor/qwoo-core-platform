<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Data exports for the owner's dashboard: orders (one row per order line),
 * customers with their addresses, reviews, email subscribers, bookings and
 * gift cards. Each action answers one page:
 *
 *   { columns: [ keys ], rows: [ [ cells in column order ] ], page, pages, total }
 *
 * The dashboard asks for page 1, 2… until page >= pages, turns the keys
 * into translated headers and writes the CSV in the browser (formulas off).
 * Nothing is written to disk here. Only what the owner already sees in the
 * dashboard goes out (no IP hashes, sign-in data or internal logs).
 *
 * export_counts: how many of each, for Settings → Copy your store →
 * Download all your data.
 */
trait Qwoo_Platform_Exports {

    /** Orders per page (each can have several lines), and rows per page for the others. */
    private static $export_orders_per_page = 150;
    private static $export_rows_per_page   = 500;

    private static function export_page( array $params ) {
        return max( 1, min( 10000, (int) ( $params['page'] ?? 1 ) ) );
    }

    private static function export_answer( array $columns, array $rows, $page, $total, $per ) {
        return [
            'columns' => $columns,
            'rows'    => $rows,
            'page'    => $page,
            'pages'   => max( 1, (int) ceil( $total / $per ) ),
            'total'   => (int) $total,
        ];
    }

    /** A time from the database (UTC) in the store's own time; '' when empty. */
    private static function export_time( $utc ) {
        if ( empty( $utc ) || $utc === '0000-00-00 00:00:00' ) {
            return '';
        }
        $ts = is_numeric( $utc ) ? (int) $utc : strtotime( $utc . ' UTC' );
        return $ts ? wp_date( 'Y-m-d H:i', $ts ) : '';
    }

    private static function export_table_exists( $table ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
    }

    /* ---------------- orders ---------------- */

    private static $export_order_columns = [
        'order', 'date', 'status', 'customer', 'email', 'phone',
        'billing_company', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
        'shipping_name', 'shipping_company', 'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country', 'shipping_phone',
        'product', 'sku', 'quantity', 'line_total',
        'shipping_method', 'shipping_cost', 'coupons', 'discount', 'tax', 'order_total', 'currency', 'payment_method', 'customer_note',
        'courier', 'tracking_number', 'tracking_url',
    ];

    /**
     * { page, filter (a status, as the Orders list), search, from, to (Y-m-d,
     * the store's days) }: newest first, one row per order line.
     */
    private static function action_orders_export( array $params ) {
        $page   = self::export_page( $params );
        $per    = self::$export_orders_per_page;
        $filter = in_array( $params['filter'] ?? '', self::ORDER_FILTERS, true ) ? $params['filter'] : '';
        $search = mb_substr( sanitize_text_field( (string) ( $params['search'] ?? '' ) ), 0, 100 );
        $all    = array_map( static fn( $s ) => substr( $s, 3 ), array_keys( wc_get_order_statuses() ) );
        $status = $filter !== '' ? [ $filter ] : $all;
        $day    = static fn( $v ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? (string) $v : '';
        $from   = $day( $params['from'] ?? '' );
        $to     = $day( $params['to'] ?? '' );
        $tz     = wp_timezone();
        $from_ts = $from !== '' ? ( new DateTimeImmutable( $from . ' 00:00:00', $tz ) )->getTimestamp() : 0;
        $to_ts   = $to !== '' ? ( new DateTimeImmutable( $to . ' 23:59:59', $tz ) )->getTimestamp() : 0;

        if ( $search !== '' ) {
            $orders = [];
            foreach ( array_map( 'intval', wc_order_search( $search ) ) as $id ) {
                $order = wc_get_order( $id );
                if ( ! $order || $order->get_type() !== 'shop_order' || ! in_array( $order->get_status(), $status, true ) ) {
                    continue;
                }
                $made = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
                if ( ( $from_ts && $made < $from_ts ) || ( $to_ts && $made > $to_ts ) ) {
                    continue;
                }
                $orders[] = $order;
            }
            usort( $orders, static fn( $a, $b ) => $b->get_id() <=> $a->get_id() );
            $total  = count( $orders );
            $orders = array_slice( $orders, ( $page - 1 ) * $per, $per );
        } else {
            $args = [
                'type'     => 'shop_order',
                'status'   => $status,
                'limit'    => $per,
                'page'     => $page,
                'paginate' => true,
                'orderby'  => 'date',
                'order'    => 'DESC',
            ];
            if ( $from_ts || $to_ts ) {
                $args['date_created'] = ( $from_ts ?: 0 ) . '...' . ( $to_ts ?: time() + DAY_IN_SECONDS );
            }
            $result = wc_get_orders( $args );
            $orders = $result->orders;
            $total  = (int) $result->total;
        }

        $statuses = wc_get_order_statuses();
        $rows     = [];
        foreach ( $orders as $o ) {
            $made  = $o->get_date_created();
            $base  = [
                'order'             => $o->get_order_number(),
                'date'              => $made ? wp_date( 'Y-m-d H:i', $made->getTimestamp() ) : '',
                'status'            => $statuses[ 'wc-' . $o->get_status() ] ?? $o->get_status(),
                'customer'          => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
                'email'             => $o->get_billing_email(),
                'phone'             => $o->get_billing_phone(),
                'billing_company'   => $o->get_billing_company(),
                'billing_address_1' => $o->get_billing_address_1(),
                'billing_address_2' => $o->get_billing_address_2(),
                'billing_city'      => $o->get_billing_city(),
                'billing_state'     => $o->get_billing_state(),
                'billing_postcode'  => $o->get_billing_postcode(),
                'billing_country'   => $o->get_billing_country(),
                'shipping_name'     => trim( $o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name() ),
                'shipping_company'  => $o->get_shipping_company(),
                'shipping_address_1' => $o->get_shipping_address_1(),
                'shipping_address_2' => $o->get_shipping_address_2(),
                'shipping_city'     => $o->get_shipping_city(),
                'shipping_state'    => $o->get_shipping_state(),
                'shipping_postcode' => $o->get_shipping_postcode(),
                'shipping_country'  => $o->get_shipping_country(),
                'shipping_phone'    => method_exists( $o, 'get_shipping_phone' ) ? $o->get_shipping_phone() : '',
                'product'           => '',
                'sku'               => '',
                'quantity'          => '',
                'line_total'        => '',
                'shipping_method'   => html_entity_decode( (string) $o->get_shipping_method(), ENT_QUOTES ),
                'shipping_cost'     => wc_format_decimal( $o->get_shipping_total(), wc_get_price_decimals() ),
                'coupons'           => implode( ', ', $o->get_coupon_codes() ),
                'discount'          => wc_format_decimal( $o->get_discount_total(), wc_get_price_decimals() ),
                'tax'               => wc_format_decimal( $o->get_total_tax(), wc_get_price_decimals() ),
                'order_total'       => wc_format_decimal( $o->get_total(), wc_get_price_decimals() ),
                'currency'          => $o->get_currency(),
                'payment_method'    => html_entity_decode( (string) $o->get_payment_method_title(), ENT_QUOTES ),
                'customer_note'     => $o->get_customer_note(),
                'courier'           => (string) $o->get_meta( '_qwoo_courier' ),
                'tracking_number'   => (string) $o->get_meta( '_qwoo_tracking_number' ),
                'tracking_url'      => (string) $o->get_meta( '_qwoo_tracking_url' ),
            ];
            $items = $o->get_items( 'line_item' );
            if ( ! $items ) {
                $rows[] = array_values( $base );
                continue;
            }
            foreach ( $items as $item ) {
                $product = $item->get_product();
                $line    = $base;
                $line['product']    = html_entity_decode( (string) $item->get_name(), ENT_QUOTES );
                $line['sku']        = $product ? (string) $product->get_sku() : '';
                $line['quantity']   = (int) $item->get_quantity();
                $line['line_total'] = wc_format_decimal( (float) $item->get_total() + (float) $item->get_total_tax(), wc_get_price_decimals() );
                $rows[] = array_values( $line );
            }
        }
        return self::export_answer( self::$export_order_columns, $rows, $page, $total, $per );
    }

    /* ---------------- customers ---------------- */

    private static $export_address_parts = [ 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ];

    /** { page }: every customer (accounts and guests, as the Customers list), A–Z, with billing and shipping addresses. */
    private static function action_customers_export( array $params ) {
        $page = self::export_page( $params );
        $per  = self::$export_rows_per_page;
        $all  = self::customer_groups();
        usort( $all, static fn( $a, $b ) => strcasecmp( $a['name'] ?: $a['email'], $b['name'] ?: $b['email'] ) );
        $part = array_slice( $all, ( $page - 1 ) * $per, $per );

        $columns = [ 'name', 'email', 'phone', 'account', 'orders', 'spent', 'currency', 'first_order', 'last_order', 'joined' ];
        foreach ( [ 'billing', 'shipping' ] as $kind ) {
            $columns[] = $kind . '_name';
            foreach ( self::$export_address_parts as $p ) {
                $columns[] = $kind . '_' . $p;
            }
        }
        $currency = get_woocommerce_currency();
        $day      = static fn( $ts ) => $ts ? wp_date( 'Y-m-d', (int) $ts ) : '';
        $rows     = [];
        foreach ( $part as $c ) {
            $row = [ $c['name'], $c['email'], $c['phone'], $c['account'] ? 'yes' : 'no', (int) $c['orders'], $c['spent'], $currency, $day( $c['first'] ?? 0 ), $c['orders'] ? $day( $c['last'] ?? 0 ) : '', $day( $c['registered'] ?? 0 ) ];
            $addr = self::export_customer_addresses( (string) $c['key'] );
            foreach ( [ 'billing', 'shipping' ] as $kind ) {
                $row[] = trim( ( $addr[ $kind ]['first_name'] ?? '' ) . ' ' . ( $addr[ $kind ]['last_name'] ?? '' ) );
                foreach ( self::$export_address_parts as $p ) {
                    $row[] = (string) ( $addr[ $kind ][ $p ] ?? '' );
                }
            }
            $rows[] = $row;
        }
        return self::export_answer( $columns, $rows, $page, count( $all ), $per );
    }

    /** An account's saved addresses, or a guest's from their latest order ('g<order id>'). */
    private static function export_customer_addresses( $key ) {
        $out = [ 'billing' => [], 'shipping' => [] ];
        if ( preg_match( '/^u(\d+)$/', $key, $m ) ) {
            $id = (int) $m[1];
            foreach ( [ 'billing', 'shipping' ] as $kind ) {
                foreach ( array_merge( [ 'first_name', 'last_name' ], self::$export_address_parts ) as $p ) {
                    $out[ $kind ][ $p ] = (string) get_user_meta( $id, $kind . '_' . $p, true );
                }
            }
        } elseif ( preg_match( '/^g(\d+)$/', $key, $m ) ) {
            $order = wc_get_order( (int) $m[1] );
            if ( $order ) {
                $out['billing']  = $order->get_address( 'billing' );
                $out['shipping'] = $order->get_address( 'shipping' );
            }
        }
        return $out;
    }

    /* ---------------- reviews ---------------- */

    /** { page }: approved and waiting product reviews, newest first. */
    private static function action_reviews_export( array $params ) {
        $page  = self::export_page( $params );
        $per   = self::$export_rows_per_page;
        $args  = [ 'type' => 'review', 'post_type' => 'product', 'status' => 'all' ];
        $total = (int) get_comments( $args + [ 'count' => true ] );
        $rows  = [];
        foreach ( get_comments( $args + [ 'number' => $per, 'offset' => ( $page - 1 ) * $per, 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ] ) as $c ) {
            $r      = self::review_item( $c );
            $rows[] = [
                $r['product']['name'] ?? '',
                $r['rating'] ?: '',
                $r['title'],
                $r['text'],
                $r['author'],
                $r['email'],
                self::export_time( $r['date'] ),
                $r['status'],
                $r['verified'] ? 'yes' : 'no',
                $r['reply']['text'] ?? '',
                ! empty( $r['reply']['date'] ) ? self::export_time( $r['reply']['date'] ) : '',
            ];
        }
        return self::export_answer( [ 'product', 'rating', 'title', 'text', 'name', 'email', 'date', 'status', 'verified', 'reply', 'reply_date' ], $rows, $page, $total, $per );
    }

    /* ---------------- email subscribers ---------------- */

    /** { page }: everyone on the marketing list (not the suppressed addresses), oldest first. */
    private static function action_subscribers_export( array $params ) {
        global $wpdb;
        $page    = self::export_page( $params );
        $per     = self::$export_rows_per_page;
        $columns = [ 'email', 'name', 'status', 'source', 'consent', 'consent_date', 'unsubscribe_date', 'added' ];
        $t       = Qwoo_Email_Marketing::subs_table();
        if ( ! self::export_table_exists( $t ) ) {
            return self::export_answer( $columns, [], $page, 0, $per );
        }
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE source <> 'suppressed'" );
        $list  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT email, name, status, source, consent_text, consent_at, created, updated FROM $t WHERE source <> 'suppressed' ORDER BY id ASC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A );
        $rows  = [];
        foreach ( $list as $s ) {
            $rows[] = [
                $s['email'], $s['name'], $s['status'], $s['source'], $s['consent_text'],
                self::export_time( $s['consent_at'] ),
                // No own column: the last change of someone who left the list.
                $s['status'] === 'unsub' ? self::export_time( $s['updated'] ) : '',
                self::export_time( $s['created'] ),
            ];
        }
        return self::export_answer( $columns, $rows, $page, $total, $per );
    }

    /* ---------------- bookings ---------------- */

    /**
     * { page, _staff (from the platform: someone who sees only their own
     * bookings) }: every booking but holds in progress and blocked times,
     * newest start first.
     */
    private static function action_bookings_export( array $params ) {
        global $wpdb;
        $page    = self::export_page( $params );
        $per     = self::$export_rows_per_page;
        $columns = [ 'service', 'staff', 'start', 'end', 'customer', 'phone', 'email', 'status', 'seats', 'price', 'deposit', 'currency', 'order', 'place', 'notes', 'answers', 'created' ];
        $t       = Qwoo_Appointments::bookings_table();
        if ( ! self::export_table_exists( $t ) ) {
            return self::export_answer( $columns, [], $page, 0, $per );
        }
        $where = "status NOT IN ('held','blocked')";
        $only  = (int) ( $params['_staff'] ?? 0 );
        if ( $only ) {
            $where .= $wpdb->prepare( ' AND staff_id = %d', $only );
        }
        $total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $where" );
        $list     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $where ORDER BY start_utc DESC, id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A );
        $services = [];
        $staff    = [];
        $rows     = [];
        foreach ( $list as $b ) {
            $sid = (int) $b['service_id'];
            $pid = (int) $b['staff_id'];
            if ( ! array_key_exists( $sid, $services ) ) {
                $svc              = Qwoo_Appointments::service( $sid );
                $services[ $sid ] = $svc ? (string) $svc['name'] : '';
            }
            if ( $pid && ! array_key_exists( $pid, $staff ) ) {
                $person        = Qwoo_Appointments::staff_one( $pid );
                $staff[ $pid ] = $person ? (string) $person['name'] : '';
            }
            $answers = json_decode( (string) $b['answers'], true );
            $answers = is_array( $answers ) ? implode( '; ', array_map( static function ( $k, $v ) {
                $v = is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v;
                return is_int( $k ) ? $v : $k . ': ' . $v;
            }, array_keys( $answers ), $answers ) ) : '';
            $rows[] = [
                $services[ $sid ],
                $pid ? $staff[ $pid ] : '',
                self::export_time( $b['start_utc'] ),
                self::export_time( $b['end_utc'] ),
                $b['name'], $b['phone'], $b['email'], $b['status'], (int) $b['seats'],
                wc_format_decimal( $b['price'], wc_get_price_decimals() ),
                wc_format_decimal( $b['due_now'], wc_get_price_decimals() ),
                $b['currency'],
                (int) $b['order_id'] ?: '',
                $b['place'],
                (string) $b['note'],
                $answers,
                self::export_time( $b['created'] ),
            ];
        }
        return self::export_answer( $columns, $rows, $page, $total, $per );
    }

    /* ---------------- gift cards ---------------- */

    /** { page }: every gift card, newest first. */
    private static function action_gift_cards_export( array $params ) {
        global $wpdb;
        $page    = self::export_page( $params );
        $per     = self::$export_rows_per_page;
        $columns = [ 'code', 'amount', 'balance', 'currency', 'status', 'order', 'buyer_name', 'buyer_email', 'from_name', 'recipient_name', 'recipient_email', 'send_date', 'sent', 'expiry', 'email_status' ];
        $t       = Qwoo_Gift_Cards::cards_table();
        if ( ! self::export_table_exists( $t ) ) {
            return self::export_answer( $columns, [], $page, 0, $per );
        }
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
        $list  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A );
        $rows  = [];
        foreach ( $list as $c ) {
            $rows[] = [
                $c['code'],
                wc_format_decimal( $c['amount'], wc_get_price_decimals() ),
                wc_format_decimal( $c['balance'], wc_get_price_decimals() ),
                $c['currency'],
                Qwoo_Gift_Cards::state( $c ),
                (int) $c['order_id'] ?: '',
                $c['buyer_name'], $c['buyer_email'], $c['from_name'], $c['to_name'], $c['to_email'],
                self::export_time( $c['send_at'] ),
                self::export_time( $c['sent_at'] ),
                self::export_time( $c['expires_at'] ),
                $c['email_status'],
            ];
        }
        return self::export_answer( $columns, $rows, $page, $total, $per );
    }

    /* ---------------- counts ---------------- */

    /** How many of each kind there is (Download all your data). */
    private static function action_export_counts() {
        global $wpdb;
        $count = static function ( $table, $where = '1=1' ) use ( $wpdb ) {
            return self::export_table_exists( $table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" ) : 0;
        };
        $orders = wc_get_orders( [ 'type' => 'shop_order', 'status' => array_map( static fn( $s ) => substr( $s, 3 ), array_keys( wc_get_order_statuses() ) ), 'limit' => 1, 'paginate' => true, 'return' => 'ids' ] );
        $products = wp_count_posts( 'product' );
        return [
            'orders'      => (int) $orders->total,
            'products'    => (int) ( $products->publish ?? 0 ) + (int) ( $products->draft ?? 0 ) + (int) ( $products->private ?? 0 ) + (int) ( $products->pending ?? 0 ),
            'customers'   => count( self::customer_groups() ),
            'reviews'     => (int) get_comments( [ 'type' => 'review', 'post_type' => 'product', 'status' => 'all', 'count' => true ] ),
            'subscribers' => $count( Qwoo_Email_Marketing::subs_table(), "source <> 'suppressed'" ),
            'bookings'    => $count( Qwoo_Appointments::bookings_table(), "status NOT IN ('held','blocked')" ),
            'gift_cards'  => $count( Qwoo_Gift_Cards::cards_table() ),
        ];
    }
}

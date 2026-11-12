<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Audiences (dashboard → Marketing → Audiences; Pro and Plus).
 *
 * One row per browser that allowed Analytics cookies (cookie qwoo_persona,
 * httponly, only its hash is kept): what the visitor looked at, searched,
 * carted and where they came from, kept 90 days from the last visit. The row
 * is linked to a person (customer, email subscriber, order email) when the
 * store learns who it is. Daily totals for the Audiences page go to a second
 * table (no person in it).
 *
 * Audiences are the owner's rules over those facts and the store's orders. The
 * storefront asks which audiences this visitor is in (the answer to every
 * event batch) to show audience-only sections, blocks and popups; email
 * campaigns, push and automations ask the same rules about people.
 *
 * Without Analytics consent nothing is stored, and a "no" erases the row.
 */
class Qwoo_Personalization {

    const OPTION        = 'qwoo_personalization';
    const SIZES         = 'qwoo_persona_sizes';
    const COOKIE        = 'qwoo_persona';
    const RETENTION     = 90;   // days from the last visit
    const STATS_DAYS    = 400;
    const MAX_AUDIENCES = 50;
    const MAX_RULES     = 10;
    const MAX_VIEWS     = 60;
    const MAX_CATS      = 30;
    const MAX_PAGES     = 30;
    const MAX_SEARCHES  = 10;
    const MAX_EVENTS    = 25;   // per request
    const VISIT_GAP     = 1800; // a new visit after 30 minutes without any event
    const FEATURE       = 'audiences';

    const SOURCES = [ 'direct', 'instagram', 'facebook', 'tiktok', 'google', 'search', 'email', 'whatsapp', 'ads', 'other' ];
    const DEVICES = [ 'phone', 'tablet', 'computer' ];
    const KINDS   = [ 'home', 'product', 'category', 'shop', 'search', 'cart', 'checkout', 'account', 'blog', 'page', 'other' ];

    /** Rule types and the values each one keeps (see clean_rule()). */
    const RULES = [
        // Browsing
        'viewed_category'          => [ 'category_id', 'min', 'days' ],
        'viewed_product'           => [ 'product_id', 'seconds', 'days' ],
        'products_viewed_at_least' => [ 'value', 'days' ],
        'searched'                 => [ 'text', 'days' ],
        'visited_page'             => [ 'text', 'days' ],
        'did_event'                => [ 'event_id', 'min', 'days' ], // an event the owner defined (Analytics → Events)
        'visits_at_least'          => [ 'value' ],
        'first_visit_within'       => [ 'days' ],
        'last_seen_within'         => [ 'days' ],
        'not_seen_for'             => [ 'days' ],
        // Cart and wishlist
        'cart_has_items'           => [],
        'cart_left'                => [ 'hours' ],
        'cart_has_product'         => [ 'product_id' ],
        'cart_value_at_least'      => [ 'value' ],
        'wishlist_has_items'       => [],
        'wishlist_has_product'     => [ 'product_id' ],
        // Orders
        'has_orders'               => [],
        'no_orders'                => [],
        'orders_at_least'          => [ 'value' ],
        'spent_at_least'           => [ 'value' ],
        'bought_product'           => [ 'product_id' ],
        'bought_category'          => [ 'category_id' ],
        'last_order_within'        => [ 'days' ],
        'no_order_for'             => [ 'days' ],
        // Who
        'signed_in'                => [],
        'email_subscriber'         => [],
        'push_subscriber'          => [],
        'source'                   => [ 'text' ],
        'campaign'                 => [ 'text' ],
        'device'                   => [ 'text' ],
        'language'                 => [ 'text' ],
        'country'                  => [ 'text' ],
    ];

    /** Ready-made audiences (the dashboard has their names and texts). */
    const TEMPLATES = [
        'new_visitors'       => [ 'match' => 'all', 'rules' => [ [ 'type' => 'first_visit_within', 'days' => 7 ] ] ],
        'returning_no_order' => [ 'match' => 'all', 'rules' => [ [ 'type' => 'visits_at_least', 'value' => 3 ], [ 'type' => 'no_orders' ] ] ],
        'cart_left'          => [ 'match' => 'all', 'rules' => [ [ 'type' => 'cart_left', 'hours' => 24 ] ] ],
        'window_shoppers'    => [ 'match' => 'all', 'rules' => [ [ 'type' => 'products_viewed_at_least', 'value' => 5, 'days' => 30 ], [ 'type' => 'cart_has_items', 'not' => true ], [ 'type' => 'no_orders' ] ] ],
        'first_time_buyers'  => [ 'match' => 'all', 'rules' => [ [ 'type' => 'orders_at_least', 'value' => 1 ], [ 'type' => 'orders_at_least', 'value' => 2, 'not' => true ] ] ],
        'lapsed'             => [ 'match' => 'all', 'rules' => [ [ 'type' => 'no_order_for', 'days' => 90 ] ] ],
        'vip'                => [ 'match' => 'all', 'rules' => [ [ 'type' => 'orders_at_least', 'value' => 3 ] ] ],
        'from_instagram'     => [ 'match' => 'all', 'rules' => [ [ 'type' => 'source', 'text' => 'instagram' ] ] ],
    ];

    /** Facts about the current request's people, so one request reads each person once. */
    private static $people = [];

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
        add_action( 'qwoo_subscriber_added', [ __CLASS__, 'link_subscriber' ], 5, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'on_checkout' ], 20 );
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_order_status' ], 40, 4 );
        add_action( 'qwoo_push_tick', [ __CLASS__, 'prune' ] );
        add_action( 'deleted_user', [ __CLASS__, 'erase_user' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'privacy_eraser' ] );
    }

    /* ================================================================
       Tables and switches
       ================================================================ */

    public static function table() { global $wpdb; return $wpdb->prefix . 'qwoo_personalization'; }
    public static function stats_table() { global $wpdb; return $wpdb->prefix . 'qwoo_persona_stats'; }

    public static function install_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        $s = self::stats_table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $t (
        visitor_key CHAR(64) NOT NULL,
        customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        email VARCHAR(190) NOT NULL DEFAULT '',
        signals LONGTEXT NOT NULL,
        updated_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY  (visitor_key),
        KEY customer_id (customer_id),
        KEY subscriber_id (subscriber_id),
        KEY email (email),
        KEY updated_at (updated_at),
        KEY expires_at (expires_at)
        ) $charset;" );
        dbDelta( "CREATE TABLE $s (
        day CHAR(10) NOT NULL,
        metric VARCHAR(16) NOT NULL,
        item VARCHAR(120) NOT NULL DEFAULT '',
        n BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY  (day,metric,item)
        ) $charset;" );
    }

    private static function settings(): array {
        $v = get_option( self::OPTION, [] );
        return is_array( $v ) ? $v : [];
    }

    /** The owner turned Audiences on. */
    public static function enabled(): bool {
        return ! empty( self::settings()['enabled'] );
    }

    /** The store's plan includes Audiences (a store the platform never told about plans keeps it). */
    public static function plan_allows(): bool {
        $plan = get_option( 'qwoo_plan', null );
        if ( ! is_array( $plan ) ) return true;
        return class_exists( 'Qwoo_Platform_Dashboard' ) && Qwoo_Platform_Dashboard::has_feature( self::FEATURE );
    }

    /** Collecting and matching run: on, and the plan allows it. */
    public static function active(): bool {
        return self::enabled() && self::plan_allows();
    }

    public static function save_settings( array $in ) {
        if ( ! empty( $in['enabled'] ) && ! self::plan_allows() ) return new WP_Error( 'plan', 'Audiences are part of the Pro and Plus plans.' );
        $v = self::settings();
        $was = ! empty( $v['enabled'] );
        $v['enabled'] = ! empty( $in['enabled'] );
        update_option( self::OPTION, $v, false );
        if ( ! $v['enabled'] && ! empty( $in['clear'] ) ) self::clear_all();
        // The storefront's cookie banner asks for Analytics while Audiences is on.
        if ( $was !== $v['enabled'] && class_exists( 'Qwoo_Custom_Code' ) ) Qwoo_Custom_Code::touch();
        return self::dashboard_state();
    }

    public static function clear_all(): void {
        global $wpdb;
        $wpdb->query( 'DELETE FROM ' . self::table() );
        $wpdb->query( 'DELETE FROM ' . self::stats_table() );
        delete_option( self::SIZES );
    }

    /* ================================================================
       Audiences (saved rules)
       ================================================================ */

    public static function audiences(): array {
        return array_values( array_filter( (array) ( self::settings()['audiences'] ?? [] ), static fn( $a ) => is_array( $a ) && ! empty( $a['id'] ) ) );
    }

    public static function audience( string $id ): ?array {
        foreach ( self::audiences() as $a ) if ( $a['id'] === $id ) return $a;
        return null;
    }

    public static function exists( string $id ): bool {
        return $id !== '' && self::audience( $id ) !== null;
    }

    /** [ id => name ] for pickers. */
    public static function names(): array {
        return array_column( self::audiences(), 'name', 'id' );
    }

    private static function clean_rule( $rule ): ?array {
        if ( ! is_array( $rule ) ) return null;
        $type = sanitize_key( (string) ( $rule['type'] ?? '' ) );
        // Codex's first names.
        $type = [ 'view_category' => 'viewed_category', 'view_product' => 'viewed_product' ][ $type ] ?? $type;
        if ( ! isset( self::RULES[ $type ] ) ) return null;
        $out = [ 'type' => $type ];
        foreach ( self::RULES[ $type ] as $key ) {
            $raw = $rule[ $key ] ?? null;
            switch ( $key ) {
                case 'category_id':
                    $id = absint( $raw );
                    if ( ! $id || ! term_exists( $id, 'product_cat' ) ) return null;
                    $out[ $key ] = $id;
                    break;
                case 'product_id':
                    $id = absint( $raw );
                    if ( ! $id || ! function_exists( 'wc_get_product' ) || ! wc_get_product( $id ) ) return null;
                    $out[ $key ] = $id;
                    break;
                case 'event_id':
                    $id = is_scalar( $raw ) ? (string) $raw : '';
                    if ( ! class_exists( 'Qwoo_Events' ) || ! Qwoo_Events::exists( $id ) ) return null;
                    $out[ $key ] = $id;
                    break;
                case 'days':
                    $out[ $key ] = min( 730, max( 1, absint( $raw ?: 30 ) ) );
                    break;
                case 'hours':
                    $out[ $key ] = min( 24 * 30, max( 1, absint( $raw ?: 24 ) ) );
                    break;
                case 'seconds':
                    $out[ $key ] = min( 3600, max( 5, absint( $raw ?: 30 ) ) );
                    break;
                case 'min':
                    $out[ $key ] = min( 100, max( 1, absint( $raw ?: 1 ) ) );
                    break;
                case 'value':
                    $value = (float) $raw;
                    if ( $value <= 0 ) return null;
                    $out[ $key ] = min( 100000000, $value );
                    break;
                case 'text':
                    $text = mb_substr( trim( sanitize_text_field( (string) $raw ) ), 0, 80 );
                    if ( $type === 'source' && ! in_array( $text, self::SOURCES, true ) ) return null;
                    if ( $type === 'device' && ! in_array( $text, self::DEVICES, true ) ) return null;
                    if ( $type === 'country' ) $text = strtoupper( substr( $text, 0, 2 ) );
                    if ( $type === 'language' ) $text = strtolower( substr( $text, 0, 5 ) );
                    if ( $text === '' ) return null;
                    $out[ $key ] = $text;
                    break;
            }
        }
        if ( ! empty( $rule['not'] ) && $rule['not'] !== 'false' ) $out['not'] = true;
        return $out;
    }

    public static function save_audience( array $in ) {
        $rules = [];
        foreach ( array_slice( (array) ( $in['rules'] ?? [] ), 0, self::MAX_RULES ) as $raw ) {
            $rule = self::clean_rule( $raw );
            if ( $rule ) $rules[] = $rule;
        }
        if ( ! $rules ) return new WP_Error( 'rules', 'Add at least one condition.' );
        $name = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 80 );
        if ( $name === '' ) return new WP_Error( 'name', 'Give this audience a name.' );
        $list = self::audiences();
        $id   = sanitize_key( (string) ( $in['id'] ?? '' ) );
        $row  = [
            'id'         => $id && self::exists( $id ) ? $id : 'au' . bin2hex( random_bytes( 5 ) ),
            'name'       => $name,
            'match'      => ( $in['match'] ?? '' ) === 'any' ? 'any' : 'all',
            'rules'      => $rules,
            'template'   => sanitize_key( (string) ( $in['template'] ?? '' ) ),
            'updated_at' => time(),
        ];
        $found = false;
        foreach ( $list as &$a ) {
            if ( $a['id'] === $row['id'] ) { $row['created_at'] = (int) ( $a['created_at'] ?? time() ); $a = $row; $found = true; }
        }
        unset( $a );
        if ( ! $found ) {
            if ( count( $list ) >= self::MAX_AUDIENCES ) return new WP_Error( 'max', sprintf( 'A store can have up to %d audiences.', self::MAX_AUDIENCES ) );
            $row['created_at'] = time();
            $list[] = $row;
        }
        $v = self::settings();
        $v['audiences'] = $list;
        update_option( self::OPTION, $v, false );
        delete_option( self::SIZES );
        return [ 'audiences' => self::audiences(), 'audience' => $row ];
    }

    public static function add_template( string $key, string $name ) {
        if ( ! isset( self::TEMPLATES[ $key ] ) ) return new WP_Error( 'template', 'Unknown ready-made audience.' );
        return self::save_audience( self::TEMPLATES[ $key ] + [ 'name' => $name, 'template' => $key ] );
    }

    public static function delete_audience( string $id ): array {
        $v = self::settings();
        $v['audiences'] = array_values( array_filter( self::audiences(), static fn( $a ) => $a['id'] !== $id ) );
        update_option( self::OPTION, $v, false );
        delete_option( self::SIZES );
        return [ 'audiences' => self::audiences() ];
    }

    /* ================================================================
       Matching
       ================================================================ */

    /**
     * A person's facts for matching: [ signals, customer_id, subscriber_id, email,
     * orders => [ count, spent, last (unix), products, categories ], sub, push ].
     */
    private static function facts( array $row, ?array $orders = null, ?array $subs = null, ?array $push = null ): array {
        $email = strtolower( (string) ( $row['email'] ?? '' ) );
        $cid   = (int) ( $row['customer_id'] ?? 0 );
        if ( $email === '' && $cid ) {
            $user = get_user_by( 'id', $cid );
            if ( $user ) $email = strtolower( (string) $user->user_email );
        }
        $signals = $row['signals'] ?? [];
        if ( is_string( $signals ) ) $signals = json_decode( $signals, true );
        $signals = self::normalize( is_array( $signals ) ? $signals : [] );
        if ( $orders === null ) $orders = $email !== '' ? self::orders_for_email( $email ) : null;
        else $orders = $email !== '' ? ( $orders[ $email ] ?? null ) : null;
        $sub = $subs === null ? ( $email !== '' && self::is_subscriber( $email ) ) : ( $email !== '' && isset( $subs[ $email ] ) );
        if ( ! $sub && ! empty( $row['subscriber_id'] ) && $subs === null ) $sub = self::subscriber_active( (int) $row['subscriber_id'] );
        $has_push = $push === null ? ( $cid && self::has_push( $cid ) ) : ( $cid && isset( $push[ $cid ] ) );
        return [
            'signals'       => $signals,
            'customer_id'   => $cid,
            'subscriber_id' => (int) ( $row['subscriber_id'] ?? 0 ),
            'email'         => $email,
            'orders'        => $orders ?: [ 'count' => 0, 'spent' => 0.0, 'last' => 0, 'products' => [], 'categories' => [] ],
            'sub'           => $sub,
            'push'          => $has_push,
        ];
    }

    /** Whether these facts are in the audience. */
    public static function matches_facts( array $aud, array $f ): bool {
        $rules = (array) ( $aud['rules'] ?? [] );
        if ( ! $rules ) return false;
        $any = ( $aud['match'] ?? 'all' ) === 'any';
        foreach ( $rules as $rule ) {
            $hit = self::rule_matches( (array) $rule, $f );
            if ( ! empty( $rule['not'] ) ) $hit = ! $hit;
            if ( $any && $hit ) return true;
            if ( ! $any && ! $hit ) return false;
        }
        return ! $any;
    }

    private static function rule_matches( array $rule, array $f ): bool {
        $s   = $f['signals'];
        $o   = $f['orders'];
        $now = time();
        $since = static fn( $days ) => $now - (int) $days * DAY_IN_SECONDS;
        switch ( $rule['type'] ?? '' ) {
            case 'viewed_category':
                $c = $s['cats'][ (int) $rule['category_id'] ] ?? null;
                return $c && (int) $c['t'] >= $since( $rule['days'] ) && (int) $c['n'] >= (int) ( $rule['min'] ?? 1 );
            case 'viewed_product':
                $v = $s['views'][ (int) $rule['product_id'] ] ?? null;
                return $v && (int) $v['t'] >= $since( $rule['days'] ) && (int) $v['s'] >= (int) $rule['seconds'];
            case 'products_viewed_at_least':
                $n = 0;
                foreach ( $s['views'] as $v ) if ( (int) $v['t'] >= $since( $rule['days'] ?? 30 ) ) $n++;
                return $n >= (int) $rule['value'];
            case 'searched':
                $needle = mb_strtolower( (string) $rule['text'] );
                foreach ( $s['search'] as $q ) if ( (int) $q[1] >= $since( $rule['days'] ?? 30 ) && mb_strpos( mb_strtolower( (string) $q[0] ), $needle ) !== false ) return true;
                return false;
            case 'visited_page':
                $needle = '/' . trim( mb_strtolower( (string) $rule['text'] ), '/' );
                foreach ( $s['pages'] as $path => $p ) {
                    if ( (int) $p[1] < $since( $rule['days'] ?? 30 ) ) continue;
                    $path = '/' . trim( mb_strtolower( (string) $path ), '/' );
                    if ( $path === $needle || ( $needle !== '/' && strpos( $path, $needle . '/' ) === 0 ) ) return true;
                }
                return false;
            case 'did_event':
                // [ times, last time ]: at least N times, the last one within the days.
                $done = $s['ev'][ (string) $rule['event_id'] ] ?? null;
                return is_array( $done ) && (int) ( $done[1] ?? 0 ) >= $since( $rule['days'] ?? 30 ) && (int) ( $done[0] ?? 0 ) >= (int) ( $rule['min'] ?? 1 );
            case 'visits_at_least':      return (int) $s['visits'] >= (int) $rule['value'];
            case 'first_visit_within':   return (int) $s['first'] > 0 && (int) $s['first'] >= $since( $rule['days'] );
            case 'last_seen_within':     return (int) $s['last'] > 0 && (int) $s['last'] >= $since( $rule['days'] );
            case 'not_seen_for':         return (int) $s['last'] > 0 && (int) $s['last'] < $since( $rule['days'] );
            case 'cart_has_items':       return ! empty( $s['cart']['ids'] );
            case 'cart_left':
                return ! empty( $s['cart']['ids'] ) && (int) $s['cart']['t'] <= $now - (int) $rule['hours'] * HOUR_IN_SECONDS && (int) $s['ordered'] < (int) $s['cart']['t'];
            case 'cart_has_product':     return in_array( (int) $rule['product_id'], (array) ( $s['cart']['ids'] ?? [] ), true );
            case 'cart_value_at_least':  return (float) ( $s['cart']['value'] ?? 0 ) >= (float) $rule['value'];
            case 'wishlist_has_items':   return ! empty( $s['wish']['ids'] );
            case 'wishlist_has_product': return in_array( (int) $rule['product_id'], (array) ( $s['wish']['ids'] ?? [] ), true );
            case 'has_orders':           return (int) $o['count'] > 0;
            case 'no_orders':            return (int) $o['count'] === 0;
            case 'orders_at_least':      return (int) $o['count'] >= (int) $rule['value'];
            case 'spent_at_least':       return (float) $o['spent'] >= (float) $rule['value'];
            case 'bought_product':       return isset( $o['products'][ (int) $rule['product_id'] ] );
            case 'bought_category':      return isset( $o['categories'][ (int) $rule['category_id'] ] );
            case 'last_order_within':    return (int) $o['last'] >= $since( $rule['days'] );
            case 'no_order_for':         return (int) $o['count'] > 0 && (int) $o['last'] < $since( $rule['days'] );
            case 'signed_in':            return (int) $f['customer_id'] > 0;
            case 'email_subscriber':     return ! empty( $f['sub'] );
            case 'push_subscriber':      return ! empty( $f['push'] );
            case 'source':               return in_array( (string) $rule['text'], [ (string) ( $s['src']['first'] ?? '' ), (string) ( $s['src']['last'] ?? '' ) ], true );
            case 'campaign':
                $needle = mb_strtolower( (string) $rule['text'] );
                foreach ( [ 'fc', 'lc' ] as $k ) if ( ! empty( $s['src'][ $k ] ) && mb_strpos( mb_strtolower( (string) $s['src'][ $k ] ), $needle ) !== false ) return true;
                return false;
            case 'device':               return (string) ( $s['dev'] ?? '' ) === (string) $rule['text'];
            case 'language':             return (string) ( $s['lang'] ?? '' ) === (string) $rule['text'];
            case 'country':              return (string) ( $s['cc'] ?? '' ) === (string) $rule['text'];
        }
        return false;
    }

    /** Compatibility: Codex's matches( profile, audience_id, email ). */
    public static function matches( array $profile, string $audience_id, string $email = '', ?array $orders = null ): bool {
        $aud = self::audience( $audience_id );
        if ( ! $aud ) return false;
        $f = self::facts( [ 'signals' => $profile['signals'] ?? [], 'customer_id' => $profile['customer_id'] ?? 0, 'subscriber_id' => $profile['subscriber_id'] ?? 0, 'email' => $email ] );
        return self::matches_facts( $aud, $f );
    }

    /** The audience ids these facts are in. */
    private static function memberships( array $f ): array {
        $out = [];
        foreach ( self::audiences() as $aud ) if ( self::matches_facts( $aud, $f ) ) $out[] = $aud['id'];
        return $out;
    }

    /* ================================================================
       People: orders, subscribers, push
       ================================================================ */

    /** Whether any audience needs bought products / categories (line items are read only then). */
    private static function needs_lines(): bool {
        foreach ( self::audiences() as $a ) foreach ( (array) $a['rules'] as $r ) if ( in_array( $r['type'] ?? '', [ 'bought_product', 'bought_category' ], true ) ) return true;
        return false;
    }

    private static function product_cats( int $id ): array {
        static $cache = [];
        if ( ! isset( $cache[ $id ] ) ) {
            $ids = wp_get_post_terms( $id, 'product_cat', [ 'fields' => 'ids' ] );
            $parent = function_exists( 'wp_get_post_parent_id' ) ? (int) wp_get_post_parent_id( $id ) : 0;
            if ( ( is_wp_error( $ids ) || ! $ids ) && $parent ) $ids = wp_get_post_terms( $parent, 'product_cat', [ 'fields' => 'ids' ] );
            $cache[ $id ] = is_wp_error( $ids ) ? [] : array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
        }
        return $cache[ $id ];
    }

    /** Every paid buyer: [ email => [ count, spent, last, products, categories ] ]. */
    private static function order_index(): array {
        global $wpdb;
        if ( ! class_exists( 'Qwoo_Analytics' ) ) return [];
        $o = Qwoo_Analytics::orders_table();
        $out = [];
        foreach ( (array) $wpdb->get_results( "SELECT email, COUNT(*) AS n, COALESCE(SUM(total - refunded),0) AS spent, MAX(day) AS last FROM $o WHERE paid = 1 AND email <> '' GROUP BY email LIMIT 100000", ARRAY_A ) as $r ) {
            $out[ strtolower( (string) $r['email'] ) ] = [ 'count' => (int) $r['n'], 'spent' => (float) $r['spent'], 'last' => $r['last'] ? (int) strtotime( $r['last'] . ' 12:00:00' ) : 0, 'products' => [], 'categories' => [] ];
        }
        if ( $out && self::needs_lines() ) {
            $l = Qwoo_Analytics::lines_table();
            foreach ( (array) $wpdb->get_results( "SELECT DISTINCT o.email, l.product_id FROM $l l JOIN $o o ON o.order_id = l.order_id WHERE o.paid = 1 AND l.product_id > 0 LIMIT 300000", ARRAY_A ) as $r ) {
                $mail = strtolower( (string) $r['email'] );
                if ( ! isset( $out[ $mail ] ) ) continue;
                $pid = (int) $r['product_id'];
                $out[ $mail ]['products'][ $pid ] = true;
                foreach ( self::product_cats( $pid ) as $cat ) $out[ $mail ]['categories'][ $cat ] = true;
            }
        }
        return $out;
    }

    private static function orders_for_email( string $email ): ?array {
        global $wpdb;
        if ( isset( self::$people[ 'o:' . $email ] ) ) return self::$people[ 'o:' . $email ];
        if ( ! class_exists( 'Qwoo_Analytics' ) || ! is_email( $email ) ) return null;
        $o = Qwoo_Analytics::orders_table();
        $r = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(total - refunded),0) AS spent, MAX(day) AS last FROM $o WHERE paid = 1 AND email = %s", $email ), ARRAY_A );
        $out = [ 'count' => (int) ( $r['n'] ?? 0 ), 'spent' => (float) ( $r['spent'] ?? 0 ), 'last' => ! empty( $r['last'] ) ? (int) strtotime( $r['last'] . ' 12:00:00' ) : 0, 'products' => [], 'categories' => [] ];
        if ( $out['count'] && self::needs_lines() ) {
            $l = Qwoo_Analytics::lines_table();
            foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT l.product_id FROM $l l JOIN $o o ON o.order_id = l.order_id WHERE o.paid = 1 AND o.email = %s LIMIT 500", $email ) ) as $pid ) {
                $out['products'][ (int) $pid ] = true;
                foreach ( self::product_cats( (int) $pid ) as $cat ) $out['categories'][ $cat ] = true;
            }
        }
        return self::$people[ 'o:' . $email ] = $out;
    }

    private static function is_subscriber( string $email ): bool {
        global $wpdb;
        if ( ! class_exists( 'Qwoo_Email_Marketing' ) ) return false;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Qwoo_Email_Marketing::subs_table() . " WHERE email = %s AND status = 'sub'", $email ) );
    }

    private static function subscriber_active( int $id ): bool {
        global $wpdb;
        if ( ! class_exists( 'Qwoo_Email_Marketing' ) ) return false;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Qwoo_Email_Marketing::subs_table() . " WHERE id = %d AND status = 'sub'", $id ) );
    }

    private static function has_push( int $customer_id ): bool {
        global $wpdb;
        if ( ! class_exists( 'Qwoo_Push_Engine' ) ) return false;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Qwoo_Push_Engine::subs_table() . ' WHERE customer_id = %d LIMIT 1', $customer_id ) );
    }

    /** [ email => subscriber id ] of active email subscribers. */
    private static function subscriber_index(): array {
        global $wpdb;
        if ( ! class_exists( 'Qwoo_Email_Marketing' ) ) return [];
        $out = [];
        foreach ( (array) $wpdb->get_results( 'SELECT id, email FROM ' . Qwoo_Email_Marketing::subs_table() . " WHERE status = 'sub' LIMIT 100000", ARRAY_A ) as $r ) $out[ strtolower( (string) $r['email'] ) ] = (int) $r['id'];
        return $out;
    }

    /** [ customer id => true ] with a push device. */
    private static function push_index(): array {
        global $wpdb;
        if ( ! class_exists( 'Qwoo_Push_Engine' ) ) return [];
        $out = [];
        foreach ( (array) $wpdb->get_col( 'SELECT DISTINCT customer_id FROM ' . Qwoo_Push_Engine::subs_table() . ' WHERE customer_id > 0 LIMIT 100000' ) as $id ) $out[ (int) $id ] = true;
        return $out;
    }

    /* ================================================================
       The storefront: events in, memberships out
       ================================================================ */

    public static function routes() {
        register_rest_route( 'qwoo/v1', '/personalization/event', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_event' ],
            'permission_callback' => '__return_true',
        ] );
        // Older storefronts (one popup at a time).
        register_rest_route( 'qwoo/v1', '/personalization/evaluate', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_evaluate' ],
            'permission_callback' => '__return_true',
        ] );
    }

    private static function consent_allowed(): bool {
        $consent = (string) ( $_COOKIE['qwoo_consent'] ?? '' );
        if ( preg_match( '/^1\.a([01])\.m[01](?:\.|$)/', $consent, $m ) ) return $m[1] === '1';
        $code = get_option( 'qwoo_custom_code', [] );
        $mode = is_array( $code ) ? ( $code['consent']['mode'] ?? 'ask' ) : 'ask';
        return $mode === 'notice';
    }

    private static function origin_allowed( WP_REST_Request $request ): bool {
        $origin = (string) $request->get_header( 'origin' );
        if ( $origin === '' ) return false;
        $normalize = static function ( $url ) {
            if ( $url !== '' && ! preg_match( '#^https?://#i', (string) $url ) ) $url = 'https://' . $url;
            $parts = wp_parse_url( (string) $url );
            if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
            return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
        };
        $origin = $normalize( $origin );
        if ( $origin === '' ) return false;
        $allowed = [ $normalize( home_url() ) ];
        if ( class_exists( 'Qwoo_Technical_Settings' ) ) {
            $allowed[] = $normalize( (string) Qwoo_Technical_Settings::get_primary_frontend_domain() );
            $tech = get_option( 'qwoo_technical_settings', [] );
            if ( is_array( $tech ) && ! empty( $tech['frontend_domain'] ) ) $allowed[] = $normalize( (string) $tech['frontend_domain'] );
        }
        return in_array( $origin, array_filter( $allowed ), true );
    }

    private static function visitor_key( bool $create = true ): string {
        $raw = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $raw ) ) {
            if ( ! $create ) return '';
            try { $raw = bin2hex( random_bytes( 32 ) ); } catch ( Throwable $e ) { return ''; }
            setcookie( self::COOKIE, $raw, [ 'expires' => time() + self::RETENTION * DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ] );
            $_COOKIE[ self::COOKIE ] = $raw;
        }
        return hash( 'sha256', $raw . wp_salt( 'auth' ) );
    }

    private static function row( string $key ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE visitor_key = %s AND expires_at > %s', $key, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
        return $row ?: null;
    }

    /** Signals in today's shape (older rows came from the first version). */
    private static function normalize( array $s ): array {
        if ( (int) ( $s['v'] ?? 0 ) < 2 ) {
            $old = $s;
            $s = [ 'v' => 2 ];
            foreach ( (array) ( $old['views'] ?? [] ) as $pid => $v ) if ( is_array( $v ) ) $s['views'][ (int) $pid ] = [ 's' => (int) ( $v['seconds'] ?? 0 ), 'n' => 1, 't' => (int) ( $v['last_at'] ?? 0 ) ];
            foreach ( (array) ( $old['categories'] ?? [] ) as $cid => $c ) if ( is_array( $c ) ) $s['cats'][ (int) $cid ] = [ 'n' => (int) ( $c['views'] ?? 1 ), 's' => (int) ( $c['seconds'] ?? 0 ), 't' => (int) ( $c['last_at'] ?? 0 ) ];
            if ( ! empty( $old['cart']['product_ids'] ) ) $s['cart'] = [ 'ids' => array_map( 'absint', (array) $old['cart']['product_ids'] ), 'value' => 0, 't' => (int) ( $old['cart']['updated_at'] ?? 0 ) ];
            if ( ! empty( $old['wishlist']['product_ids'] ) ) $s['wish'] = [ 'ids' => array_map( 'absint', (array) $old['wishlist']['product_ids'] ), 't' => (int) ( $old['wishlist']['updated_at'] ?? 0 ) ];
            $last = 0;
            foreach ( $s['views'] ?? [] as $v ) $last = max( $last, (int) $v['t'] );
            $s['first'] = $last;
            $s['last']  = $last;
            $s['visits'] = $last ? 1 : 0;
        }
        return $s + [ 'v' => 2, 'first' => 0, 'last' => 0, 'visit' => 0, 'visits' => 0, 'day' => '', 'fd' => [], 'src' => [], 'dev' => '', 'lang' => '', 'cc' => '',
            'pages' => [], 'views' => [], 'cats' => [], 'search' => [], 'cart' => [], 'wish' => [], 'ordered' => 0, 'seen' => [], 'in' => [], 'q' => [], 'ev' => [] ];
    }

    private static function trim_map( array $map, int $max, string $time_key = 't' ): array {
        uasort( $map, static fn( $a, $b ) => (int) ( is_array( $b ) ? ( $b[ $time_key ] ?? $b[1] ?? 0 ) : 0 ) <=> (int) ( is_array( $a ) ? ( $a[ $time_key ] ?? $a[1] ?? 0 ) : 0 ) );
        return array_slice( $map, 0, $max, true );
    }

    private static function clean_ids( $raw, int $max = 50 ): array {
        if ( ! is_array( $raw ) ) return [];
        return array_slice( array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) ), 0, $max );
    }

    private static function clean_path( $raw ): string {
        $path = (string) wp_parse_url( '/' . ltrim( (string) $raw, '/' ), PHP_URL_PATH );
        $path = '/' . trim( preg_replace( '#/+#', '/', $path ), '/' );
        $path = mb_substr( sanitize_text_field( rawurldecode( $path ) ), 0, 110 );
        return $path === '' ? '/' : $path;
    }

    private static function today(): string {
        return wp_date( 'Y-m-d' );
    }

    /** Adds n to a daily total (portable: no ON DUPLICATE KEY). */
    private static function bump( string $metric, string $item = '', int $n = 1, ?string $day = null ): void {
        global $wpdb;
        if ( $n === 0 ) return;
        $t    = self::stats_table();
        $day  = $day ?: self::today();
        $item = mb_substr( $item, 0, 120 );
        $done = $wpdb->query( $wpdb->prepare( "UPDATE $t SET n = n + %d WHERE day = %s AND metric = %s AND item = %s", $n, $day, $metric, $item ) );
        if ( ! $done ) {
            $suppress = $wpdb->suppress_errors( true );
            $added = $wpdb->insert( $t, [ 'day' => $day, 'metric' => $metric, 'item' => $item, 'n' => $n ], [ '%s', '%s', '%s', '%d' ] );
            $wpdb->suppress_errors( $suppress );
            if ( ! $added ) $wpdb->query( $wpdb->prepare( "UPDATE $t SET n = n + %d WHERE day = %s AND metric = %s AND item = %s", $n, $day, $metric, $item ) );
        }
    }

    /** Sets a daily value (audience sizes). */
    private static function put_stat( string $metric, string $item, int $n, ?string $day = null ): void {
        global $wpdb;
        $t   = self::stats_table();
        $day = $day ?: self::today();
        $wpdb->delete( $t, [ 'day' => $day, 'metric' => $metric, 'item' => $item ] );
        $wpdb->insert( $t, [ 'day' => $day, 'metric' => $metric, 'item' => $item, 'n' => $n ], [ '%s', '%s', '%s', '%d' ] );
    }

    /** Once per visitor per day: the funnel step and the day's totals. */
    private static function funnel( array &$s, string $step ): void {
        $day = self::today();
        if ( ( $s['fd'][0] ?? '' ) !== $day ) $s['fd'] = [ $day, [] ];
        if ( in_array( $step, (array) $s['fd'][1], true ) ) return;
        $s['fd'][1][] = $step;
        self::bump( 'funnel', $step );
    }

    public static function rest_event( WP_REST_Request $request ) {
        if ( ! self::active() || ! self::origin_allowed( $request ) ) return rest_ensure_response( [ 'on' => false, 'audiences' => [] ] );
        $body = $request->get_body();
        if ( strlen( $body ) > 16384 ) return new WP_Error( 'payload', 'The request is too large.', [ 'status' => 413 ] );
        if ( ! self::consent_allowed() ) {
            self::erase_current();
            return rest_ensure_response( [ 'on' => false, 'audiences' => [] ] );
        }
        $json   = (array) $request->get_json_params();
        $events = isset( $json['events'] ) && is_array( $json['events'] ) ? $json['events'] : ( isset( $json['type'] ) ? [ $json ] : [] );
        if ( array_filter( $events, static fn( $e ) => is_array( $e ) && ( $e['type'] ?? '' ) === 'erase' ) ) {
            self::erase_current();
            return rest_ensure_response( [ 'on' => true, 'audiences' => [] ] );
        }
        $key = self::visitor_key();
        if ( $key === '' ) return new WP_Error( 'visitor', 'Unable to start.', [ 'status' => 400 ] );

        // 240 calls an hour per browser.
        $rate_key = 'qwoo_persona_rl_' . substr( hash_hmac( 'sha256', $key, wp_salt( 'auth' ) ), 0, 32 );
        $calls = (int) get_transient( $rate_key );
        if ( $calls >= 240 ) return new WP_Error( 'rate', 'Too many requests.', [ 'status' => 429 ] );
        set_transient( $rate_key, $calls + 1, HOUR_IN_SECONDS );

        $row = self::row( $key );
        $new = ! $row;
        $row = $row ?: [ 'visitor_key' => $key, 'customer_id' => 0, 'subscriber_id' => 0, 'email' => '', 'signals' => '{}' ];
        $s   = self::normalize( (array) json_decode( (string) $row['signals'], true ) );
        $now = time();

        // Events an hour per browser.
        $hour = gmdate( 'YmdH' );
        if ( ( $s['q'][0] ?? '' ) !== $hour ) $s['q'] = [ $hour, 0 ];
        $events = array_slice( array_filter( $events, 'is_array' ), 0, max( 0, min( self::MAX_EVENTS, 600 - (int) $s['q'][1] ) ) );
        $s['q'][1] += count( $events );

        // The day's visitor totals, the first time today.
        $day = self::today();
        $first_today = $s['day'] !== $day;
        if ( $new ) { $s['first'] = $now; self::bump( 'new' ); }
        if ( $first_today ) {
            $s['day'] = $day;
            self::bump( 'visitors' );
            self::funnel( $s, 'visited' );
        }
        if ( ! $s['visit'] || $now - (int) $s['last'] > self::VISIT_GAP ) {
            $s['visit'] = $now;
            $s['visits'] = (int) $s['visits'] + 1;
        }
        $s['last'] = $now;

        $country = strtoupper( (string) ( $_SERVER['HTTP_X_VERCEL_IP_COUNTRY'] ?? '' ) );
        if ( preg_match( '/^[A-Z]{2}$/', $country ) ) $s['cc'] = $country;

        $hooks = [];
        $own_left = 10; // the owner's own events counted per request
        foreach ( $events as $e ) {
            $type = sanitize_key( (string) ( $e['type'] ?? '' ) );
            switch ( $type ) {
                case 'page':
                    $path = self::clean_path( $e['path'] ?? '/' );
                    $kind = in_array( $e['kind'] ?? '', self::KINDS, true ) ? $e['kind'] : 'other';
                    $prev = $s['pages'][ $path ] ?? [ 0, 0 ];
                    $s['pages'][ $path ] = [ min( 9999, (int) $prev[0] + 1 ), $now ];
                    self::bump( 'page', $path );
                    $dev = in_array( $e['dev'] ?? '', self::DEVICES, true ) ? $e['dev'] : '';
                    if ( $dev ) {
                        if ( $first_today || $s['dev'] !== $dev ) self::bump( 'device', $dev );
                        $s['dev'] = $dev;
                    }
                    $lang = strtolower( substr( preg_replace( '/[^a-zA-Z-]/', '', (string) ( $e['lang'] ?? '' ) ), 0, 5 ) );
                    if ( $lang ) $s['lang'] = $lang;
                    // A new visit says where it came from.
                    if ( ! empty( $e['visit'] ) ) {
                        $src  = in_array( $e['src'] ?? '', self::SOURCES, true ) ? $e['src'] : 'direct';
                        $camp = mb_substr( sanitize_text_field( (string) ( $e['camp'] ?? '' ) ), 0, 60 );
                        if ( empty( $s['src']['first'] ) ) { $s['src']['first'] = $src; $s['src']['fc'] = $camp; }
                        $s['src']['last'] = $src;
                        $s['src']['lc'] = $camp;
                        self::bump( 'source', $src );
                        $ref = strtolower( preg_replace( '/[^a-z0-9.-]/i', '', (string) ( $e['ref'] ?? '' ) ) );
                        if ( $src === 'other' && $ref !== '' ) self::bump( 'refsite', mb_substr( $ref, 0, 60 ) );
                    }
                    if ( $kind === 'search' ) {
                        $q = mb_substr( trim( sanitize_text_field( (string) ( $e['q'] ?? '' ) ) ), 0, 60 );
                        if ( $q !== '' ) {
                            $s['search'] = array_slice( array_merge( [ [ $q, $now ] ], array_values( array_filter( $s['search'], static fn( $x ) => mb_strtolower( (string) $x[0] ) !== mb_strtolower( $q ) ) ) ), 0, self::MAX_SEARCHES );
                            self::bump( 'search', mb_strtolower( $q ) );
                        }
                    }
                    if ( $kind === 'checkout' ) {
                        $s['co'] = $now;
                        self::funnel( $s, 'checkout' );
                    }
                    break;

                case 'view':
                    $id = absint( $e['product_id'] ?? 0 );
                    $seconds = min( 3600, absint( $e['seconds'] ?? 0 ) );
                    if ( ! $id || $seconds < 3 ) break;
                    $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
                    if ( ! $product || $product->get_status() !== 'publish' ) break;
                    $prev = $s['views'][ $id ] ?? [ 's' => 0, 'n' => 0, 't' => 0 ];
                    $s['views'][ $id ] = [ 's' => min( 36000, (int) $prev['s'] + $seconds ), 'n' => min( 999, (int) $prev['n'] + 1 ), 't' => $now ];
                    foreach ( self::product_cats( $id ) as $cat ) {
                        $c = $s['cats'][ $cat ] ?? [ 'n' => 0, 's' => 0, 't' => 0 ];
                        $s['cats'][ $cat ] = [ 'n' => min( 999, (int) $c['n'] + 1 ), 's' => min( 36000, (int) $c['s'] + $seconds ), 't' => $now ];
                    }
                    self::bump( 'product', (string) $id );
                    self::bump( 'psec', (string) $id, $seconds );
                    self::funnel( $s, 'viewed' );
                    $hooks[] = [ 'view', [ 'product_id' => $id, 'category_ids' => self::product_cats( $id ), 'seconds' => $seconds ] ];
                    break;

                case 'cart':
                case 'wishlist':
                    $ids  = self::clean_ids( $e['product_ids'] ?? [] );
                    $part = $type === 'cart' ? 'cart' : 'wish';
                    $before = (array) ( $s[ $part ]['ids'] ?? [] );
                    $s[ $part ] = [ 'ids' => $ids, 't' => $now ] + ( $type === 'cart' ? [ 'value' => round( min( 100000000, max( 0, (float) ( $e['value'] ?? 0 ) ) ), 2 ) ] : [] );
                    if ( $type === 'cart' && array_diff( $ids, $before ) ) self::funnel( $s, 'cart' );
                    if ( $ids !== $before ) $hooks[] = [ $type, [ 'product_ids' => $ids ] ];
                    break;

                case 'seen':
                    // Audience-only content shown (attribution, popup numbers).
                    $aid = sanitize_key( (string) ( $e['audience_id'] ?? '' ) );
                    if ( $aid !== '' && self::exists( $aid ) ) {
                        if ( empty( $s['seen'][ $aid ] ) || $now - (int) $s['seen'][ $aid ] > self::VISIT_GAP ) self::bump( 'aseen', $aid );
                        $s['seen'][ $aid ] = $now;
                    }
                    $pid = (string) ( $e['popup_id'] ?? '' );
                    if ( preg_match( '/^pp_[A-Za-z0-9]{3,20}$/', $pid ) ) self::bump( 'pseen', $pid );
                    break;

                case 'ev':
                    // An event the owner defined (Analytics → Events): the day's totals and this visitor's own count.
                    $eid = is_scalar( $e['id'] ?? null ) ? (string) $e['id'] : '';
                    if ( $own_left < 1 || ! class_exists( 'Qwoo_Events' ) || ! Qwoo_Events::exists( $eid ) ) break;
                    $own_left--;
                    $prev = is_array( $s['ev'][ $eid ] ?? null ) ? $s['ev'][ $eid ] : [ 0, 0 ];
                    if ( wp_date( 'Y-m-d', (int) ( $prev[1] ?? 0 ) ) !== $day ) self::bump( 'evu', $eid );
                    $s['ev'][ $eid ] = [ min( 9999, (int) ( $prev[0] ?? 0 ) + 1 ), $now ];
                    self::bump( 'ev', $eid );
                    self::bump( 'evp', $eid . '|' . mb_substr( self::clean_path( $e['path'] ?? '/' ), 0, 100 ) );
                    $hooks[] = [ 'event', [ 'event_id' => $eid ] ];
                    break;

                case 'click':
                    $pid = (string) ( $e['popup_id'] ?? '' );
                    if ( preg_match( '/^pp_[A-Za-z0-9]{3,20}$/', $pid ) ) self::bump( 'pclick', $pid );
                    break;
            }
        }

        // Who this is, when the store knows.
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $row['customer_id'] = (int) $user->ID;
            if ( $row['email'] === '' ) $row['email'] = strtolower( (string) $user->user_email );
        }

        $s['views'] = self::trim_map( $s['views'], self::MAX_VIEWS );
        $s['cats']  = self::trim_map( $s['cats'], self::MAX_CATS );
        $s['pages'] = self::trim_map( $s['pages'], self::MAX_PAGES );
        $s['ev']    = self::trim_map( is_array( $s['ev'] ) ? $s['ev'] : [], 30 );
        $s['seen']  = array_filter( $s['seen'], static fn( $t ) => (int) $t > $now - 7 * DAY_IN_SECONDS );

        $f = self::facts( array_merge( $row, [ 'signals' => $s ] ) );
        $in = self::memberships( $f );
        $joined = array_values( array_diff( $in, (array) $s['in'] ) );
        $s['in'] = $in;
        $row['signals'] = $s;
        self::save_row( $row );

        foreach ( $hooks as [ $type, $event ] ) do_action( 'qwoo_personalization_event', $type, [ 'customer_id' => (int) $row['customer_id'], 'subscriber_id' => (int) $row['subscriber_id'], 'signals' => $s ], $event, $key );
        if ( $joined && $f['email'] !== '' ) foreach ( $joined as $aid ) do_action( 'qwoo_audience_joined', $aid, $f['email'], (int) $row['customer_id'] );

        return rest_ensure_response( [ 'on' => true, 'audiences' => $in, 'cats' => self::top_cats( $s, $f ) ] );
    }

    /** The visitor's favourite categories ("For you" product lists). */
    private static function top_cats( array $s, array $f ): array {
        $score = [];
        $now = time();
        foreach ( $s['cats'] as $id => $c ) {
            $age = max( 1, ( $now - (int) $c['t'] ) / DAY_IN_SECONDS );
            $score[ (int) $id ] = ( (int) $c['n'] * 10 + (int) $c['s'] / 10 ) / sqrt( $age );
        }
        $bought = (array) ( $f['orders']['categories'] ?? [] );
        foreach ( array_keys( $bought ) as $id ) $score[ (int) $id ] = ( $score[ (int) $id ] ?? 0 ) + 5;
        arsort( $score );
        return array_slice( array_keys( $score ), 0, 12 );
    }

    private static function save_row( array $row ): void {
        global $wpdb;
        $wpdb->replace( self::table(), [
            'visitor_key'   => $row['visitor_key'],
            'customer_id'   => max( 0, (int) $row['customer_id'] ),
            'subscriber_id' => max( 0, (int) $row['subscriber_id'] ),
            'email'         => mb_substr( strtolower( (string) $row['email'] ), 0, 190 ),
            'signals'       => is_string( $row['signals'] ) ? $row['signals'] : wp_json_encode( $row['signals'] ),
            'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
            'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + self::RETENTION * DAY_IN_SECONDS ),
        ], [ '%s', '%d', '%d', '%s', '%s', '%s', '%s' ] );
    }

    /** Older storefronts: { audience_id } → { match }. */
    public static function rest_evaluate( WP_REST_Request $request ) {
        if ( ! self::active() || ! self::consent_allowed() || ! self::origin_allowed( $request ) ) return rest_ensure_response( [ 'match' => false ] );
        $key = self::visitor_key( false );
        $row = $key ? self::row( $key ) : null;
        $aud = self::audience( sanitize_key( (string) $request->get_param( 'audience_id' ) ) );
        if ( ! $row || ! $aud ) return rest_ensure_response( [ 'match' => false ] );
        return rest_ensure_response( [ 'match' => self::matches_facts( $aud, self::facts( $row ) ) ] );
    }

    /* ================================================================
       Linking people and orders
       ================================================================ */

    public static function link_subscriber( $email ): void {
        $key = self::visitor_key( false );
        if ( $key === '' || ! self::active() || ! self::consent_allowed() ) return;
        global $wpdb;
        $email = strtolower( sanitize_email( (string) $email ) );
        $sub = class_exists( 'Qwoo_Email_Marketing' ) ? $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Qwoo_Email_Marketing::subs_table() . ' WHERE email = %s', $email ) ) : 0;
        $data = [ 'subscriber_id' => (int) $sub ];
        $row = self::row( $key );
        if ( $row && $row['email'] === '' ) $data['email'] = $email;
        $wpdb->update( self::table(), $data, [ 'visitor_key' => $key ] );
    }

    /** A placed order: the browser's row learns who it is; the order keeps which audience content was seen. */
    public static function on_checkout( $order ): void {
        if ( ! self::active() || ! is_object( $order ) || ! method_exists( $order, 'get_billing_email' ) ) return;
        $key = self::visitor_key( false );
        if ( $key === '' || ! self::consent_allowed() ) return;
        $row = self::row( $key );
        if ( ! $row ) return;
        $s = self::normalize( (array) json_decode( (string) $row['signals'], true ) );
        $s['ordered'] = time();
        self::funnel( $s, 'ordered' );
        $email = strtolower( (string) $order->get_billing_email() );
        if ( is_email( $email ) ) $row['email'] = $email;
        if ( (int) $order->get_customer_id() ) $row['customer_id'] = (int) $order->get_customer_id();
        $row['signals'] = $s;
        self::save_row( $row );
        $seen = array_keys( array_filter( $s['seen'], static fn( $t ) => (int) $t > time() - 7 * DAY_IN_SECONDS ) );
        $order->update_meta_data( '_qwoo_persona_seen', implode( ',', $seen ) );
        $order->save_meta_data();
    }

    /** Paid: sales from audience-only content (once per order). */
    public static function on_order_status( $order_id, $from, $to, $order = null ): void {
        if ( ! in_array( $to, [ 'processing', 'completed' ], true ) ) return;
        $order = $order ?: ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
        if ( ! $order || $order->get_meta( '_qwoo_persona_counted' ) ) return;
        $seen = array_filter( explode( ',', (string) $order->get_meta( '_qwoo_persona_seen' ) ) );
        if ( ! $seen ) return;
        $cents = (int) round( (float) $order->get_total() * 100 );
        self::bump( 'psales', '', $cents );
        self::bump( 'porders' );
        foreach ( $seen as $aid ) {
            self::bump( 'asales', sanitize_key( $aid ), $cents );
            self::bump( 'aorders', sanitize_key( $aid ) );
        }
        $order->update_meta_data( '_qwoo_persona_counted', 1 );
        $order->save_meta_data();
    }

    /* ================================================================
       The dashboard
       ================================================================ */

    public static function categories(): array {
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 300 ] );
        if ( is_wp_error( $terms ) ) return [];
        return array_map( static fn( $term ) => [ 'id' => (int) $term->term_id, 'name' => (string) $term->name ], $terms );
    }

    public static function dashboard_state(): array {
        return [
            'enabled'        => self::enabled(),
            'allowed'        => self::plan_allows(),
            'retention_days' => self::RETENTION,
            'audiences'      => self::audiences(),
            'categories'     => self::categories(),
            'templates'      => array_keys( self::TEMPLATES ),
            'rules'          => array_map( static fn( $keys ) => $keys, self::RULES ),
            'events'         => class_exists( 'Qwoo_Events' ) ? (object) Qwoo_Events::names() : (object) [],
            'sources'        => self::SOURCES,
            'devices'        => self::DEVICES,
            'consent_mode'   => is_array( $c = get_option( 'qwoo_custom_code', [] ) ) ? ( $c['consent']['mode'] ?? 'ask' ) : 'ask',
            'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
        ];
    }

    /** Sums of a metric per item between two days (inclusive). */
    private static function sums( string $metric, string $from, string $to, int $limit = 0 ): array {
        global $wpdb;
        $t = self::stats_table();
        $sql = $wpdb->prepare( "SELECT item, SUM(n) AS n FROM $t WHERE metric = %s AND day >= %s AND day <= %s GROUP BY item ORDER BY n DESC", $metric, $from, $to );
        if ( $limit ) $sql .= ' LIMIT ' . (int) $limit;
        $out = [];
        foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) $out[ (string) $r['item'] ] = (int) $r['n'];
        return $out;
    }

    private static function day_ago( int $days ): string {
        return wp_date( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
    }

    /** Owner-readable names for page addresses. */
    private static function page_labels( array $paths ): array {
        $out = [];
        foreach ( $paths as $path ) {
            $label = '';
            if ( $path === '/' ) $label = 'home';
            elseif ( preg_match( '#^/(?:[a-z]{2}/)?product/([^/]+)#', $path, $m ) ) {
                $post = get_page_by_path( $m[1], OBJECT, 'product' );
                if ( $post ) $label = get_the_title( $post );
            } elseif ( preg_match( '#^/(?:[a-z]{2}/)?product-category/(?:.*/)?([^/]+)$#', $path, $m ) ) {
                $term = get_term_by( 'slug', $m[1], 'product_cat' );
                if ( $term ) $label = $term->name;
            }
            $out[ $path ] = $label;
        }
        return $out;
    }

    private static function product_names( array $ids ): array {
        $out = [];
        foreach ( $ids as $id ) {
            $p = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : null;
            $out[ (string) $id ] = $p ? $p->get_name() : '#' . $id;
        }
        return $out;
    }

    /** The Audiences page: visitors, what they do, and each audience's size and results. */
    public static function overview( array $p = [] ): array {
        global $wpdb;
        $days  = in_array( (int) ( $p['days'] ?? 30 ), [ 7, 30, 90 ], true ) ? (int) $p['days'] : 30;
        $from  = self::day_ago( $days - 1 );
        $to    = self::today();
        $pfrom = self::day_ago( $days * 2 - 1 );
        $pto   = self::day_ago( $days );
        $cut   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $t     = self::table();

        $visitors = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE updated_at >= %s AND expires_at > %s", $cut, gmdate( 'Y-m-d H:i:s' ) ) );
        $known    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE updated_at >= %s AND expires_at > %s AND ( customer_id > 0 OR subscriber_id > 0 OR email <> '' )", $cut, gmdate( 'Y-m-d H:i:s' ) ) );
        $visits_now  = array_sum( self::sums( 'visitors', $from, $to ) );
        $visits_prev = array_sum( self::sums( 'visitors', $pfrom, $pto ) );

        // Visitors and new visitors per day.
        $series = [];
        $st = self::stats_table();
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, metric, SUM(n) AS n FROM $st WHERE metric IN ('visitors','new') AND day >= %s GROUP BY day, metric", $from ), ARRAY_A ) as $r ) {
            $series[ $r['day'] ][ $r['metric'] ] = (int) $r['n'];
        }
        $daily = [];
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $d = self::day_ago( $i );
            $daily[] = [ 'day' => $d, 'visitors' => (int) ( $series[ $d ]['visitors'] ?? 0 ), 'new' => (int) ( $series[ $d ]['new'] ?? 0 ) ];
        }

        $products = self::sums( 'product', $from, $to, 8 );
        $psec     = self::sums( 'psec', $from, $to );
        $names    = self::product_names( array_keys( $products ) );
        $top_products = [];
        foreach ( $products as $id => $n ) $top_products[] = [ 'id' => (int) $id, 'name' => $names[ $id ] ?? '#' . $id, 'views' => $n, 'avg_seconds' => $n ? (int) round( ( $psec[ $id ] ?? 0 ) / $n ) : 0 ];
        $all_views = array_sum( self::sums( 'product', $from, $to ) );

        $pages  = self::sums( 'page', $from, $to, 10 );
        $labels = self::page_labels( array_keys( $pages ) );
        $top_pages = [];
        foreach ( $pages as $path => $n ) $top_pages[] = [ 'path' => $path, 'label' => $labels[ $path ] ?? '', 'views' => $n ];

        $funnel = self::sums( 'funnel', $from, $to );
        $sizes  = self::sizes( ! empty( $p['refresh'] ) );
        $used   = self::where_used();
        $asales = self::sums( 'asales', $from, $to );
        $aorders = self::sums( 'aorders', $from, $to );
        $aseen  = self::sums( 'aseen', $from, $to );
        $week_ago = self::sums( 'asize', self::day_ago( 7 ), self::day_ago( 7 ) );

        $rows = [];
        foreach ( self::audiences() as $a ) {
            $id = $a['id'];
            $sz = $sizes['audiences'][ $id ] ?? [];
            $rows[] = [
                'id'       => $id,
                'name'     => $a['name'],
                'people'   => (int) ( $sz['n'] ?? 0 ),
                'known'    => (int) ( $sz['known'] ?? 0 ),
                'week_ago' => isset( $week_ago[ $id ] ) ? (int) $week_ago[ $id ] : null,
                'buyers'   => (int) ( $sz['buyers'] ?? 0 ),
                'sales'    => (float) ( $sz['sales'] ?? 0 ),
                'personal_sales'  => round( ( $asales[ $id ] ?? 0 ) / 100, 2 ),
                'personal_orders' => (int) ( $aorders[ $id ] ?? 0 ),
                'seen'     => (int) ( $aseen[ $id ] ?? 0 ),
                'used'     => $used[ $id ] ?? [],
            ];
        }

        return [
            'days'      => $days,
            'enabled'   => self::enabled(),
            'allowed'   => self::plan_allows(),
            'tiles'     => [
                'visitors'       => $visitors,
                'known'          => $known,
                'visits'         => $visits_now,
                'visits_prev'    => $visits_prev,
                'in_any'         => (int) ( $sizes['in_any'] ?? 0 ),
                'personal_sales' => round( array_sum( self::sums( 'psales', $from, $to ) ) / 100, 2 ),
                'personal_orders' => array_sum( self::sums( 'porders', $from, $to ) ),
            ],
            'daily'     => $daily,
            'funnel'    => [ 'visited' => (int) ( $funnel['visited'] ?? 0 ), 'viewed' => (int) ( $funnel['viewed'] ?? 0 ), 'cart' => (int) ( $funnel['cart'] ?? 0 ), 'checkout' => (int) ( $funnel['checkout'] ?? 0 ), 'ordered' => (int) ( $funnel['ordered'] ?? 0 ) ],
            'products'  => $top_products,
            'product_views' => $all_views,
            'avg_seconds' => $all_views ? (int) round( array_sum( $psec ) / $all_views ) : 0,
            'pages'     => $top_pages,
            'sources'   => self::sums( 'source', $from, $to ),
            'sites'     => self::sums( 'refsite', $from, $to, 8 ),
            'devices'   => self::sums( 'device', $from, $to ),
            'searches'  => self::sums( 'search', $from, $to, 10 ),
            'audiences' => $rows,
            'sizes_at'  => (int) ( $sizes['at'] ?? 0 ),
            'partial'   => ! empty( $sizes['partial'] ),
            'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
        ];
    }

    /**
     * Every audience's size: visitors seen in the last 90 days plus known buyers and
     * subscribers who never visited with consent. Kept an hour.
     */
    public static function sizes( bool $refresh = false ): array {
        $cached = get_option( self::SIZES, [] );
        if ( ! $refresh && is_array( $cached ) && (int) ( $cached['at'] ?? 0 ) > time() - HOUR_IN_SECONDS ) return $cached;
        $out = [ 'at' => time(), 'audiences' => [], 'in_any' => 0, 'partial' => false ];
        $auds = self::audiences();
        if ( ! $auds ) { update_option( self::SIZES, $out, false ); return $out; }
        $result = self::scan( $auds, null );
        $out['audiences'] = $result['sizes'];
        $out['in_any']    = $result['in_any'];
        $out['partial']   = $result['partial'];
        update_option( self::SIZES, $out, false );
        foreach ( $auds as $a ) self::put_stat( 'asize', $a['id'], (int) ( $out['audiences'][ $a['id'] ]['n'] ?? 0 ) );
        return $out;
    }

    /**
     * Walks every person once. $detail (an audience id) also collects that audience's
     * known people and what its members look at.
     */
    private static function scan( array $auds, ?string $detail ): array {
        global $wpdb;
        @set_time_limit( 60 );
        $started = microtime( true );
        $orders = self::order_index();
        $subs   = self::subscriber_index();
        $push   = self::push_index();
        $cut30  = time() - 30 * DAY_IN_SECONDS;
        $sizes  = [];
        foreach ( $auds as $a ) $sizes[ $a['id'] ] = [ 'n' => 0, 'known' => 0, 'buyers' => 0, 'sales' => 0.0 ];
        $in_any = 0;
        $members = [];
        $views = [];
        $seen_emails = [];
        $partial = false;
        $t = self::table();
        $last = '';
        $add = static function ( array $f, int $last_seen, string $who ) use ( $auds, &$sizes, &$in_any, $detail, &$members, &$views, $cut30 ) {
            $any = false;
            foreach ( $auds as $a ) {
                if ( ! self::matches_facts( $a, $f ) ) continue;
                $any = true;
                $id = $a['id'];
                $sizes[ $id ]['n']++;
                $known = $f['email'] !== '' || $f['customer_id'];
                if ( $known ) $sizes[ $id ]['known']++;
                if ( (int) $f['orders']['count'] > 0 ) {
                    $sizes[ $id ]['buyers']++;
                    $sizes[ $id ]['sales'] += (float) $f['orders']['spent'];
                }
                if ( $detail === $id ) {
                    foreach ( $f['signals']['views'] as $pid => $v ) if ( (int) $v['t'] >= $cut30 ) $views[ $pid ] = ( $views[ $pid ] ?? 0 ) + 1;
                    if ( $known && count( $members ) < 500 ) $members[] = [ 'email' => $f['email'], 'customer_id' => $f['customer_id'], 'last' => $last_seen, 'orders' => (int) $f['orders']['count'], 'spent' => (float) $f['orders']['spent'], 'sub' => (bool) $f['sub'], 'push' => (bool) $f['push'], 'who' => $who, 'cc' => (string) ( $f['signals']['cc'] ?? '' ) ];
                }
            }
            if ( $any ) $in_any++;
        };
        do {
            $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT visitor_key, customer_id, subscriber_id, email, signals, updated_at FROM $t WHERE visitor_key > %s AND expires_at > %s ORDER BY visitor_key LIMIT 1000", $last, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
            foreach ( $rows as $row ) {
                $last = $row['visitor_key'];
                $f = self::facts( $row, $orders, $subs, $push );
                if ( $f['email'] !== '' ) $seen_emails[ $f['email'] ] = true;
                $add( $f, (int) strtotime( $row['updated_at'] . ' UTC' ), 'visitor' );
                // Joining without a visit (time-based rules): only noted, the next visit fires automations.
            }
            if ( microtime( true ) - $started > 25 ) { $partial = true; break; }
        } while ( count( $rows ) === 1000 );

        // Buyers and subscribers who never visited with consent.
        if ( ! $partial ) {
            foreach ( array_keys( $orders + $subs ) as $email ) {
                if ( isset( $seen_emails[ $email ] ) ) continue;
                $f = self::facts( [ 'email' => $email, 'customer_id' => 0, 'subscriber_id' => (int) ( $subs[ $email ] ?? 0 ), 'signals' => [] ], $orders, $subs, $push );
                $add( $f, (int) ( $orders[ $email ]['last'] ?? 0 ), isset( $orders[ $email ] ) ? 'buyer' : 'subscriber' );
                if ( microtime( true ) - $started > 28 ) { $partial = true; break; }
            }
        }
        return [ 'sizes' => $sizes, 'in_any' => $in_any, 'partial' => $partial, 'members' => $members, 'views' => $views ];
    }

    /** A draft's size while the owner edits it: { people, known }. */
    public static function preview_size( array $in ): array {
        $rules = [];
        foreach ( array_slice( (array) ( $in['rules'] ?? [] ), 0, self::MAX_RULES ) as $raw ) {
            $rule = self::clean_rule( $raw );
            if ( $rule ) $rules[] = $rule;
        }
        if ( ! $rules ) return [ 'people' => 0, 'known' => 0 ];
        $draft = [ 'id' => '__draft', 'match' => ( $in['match'] ?? '' ) === 'any' ? 'any' : 'all', 'rules' => $rules ];
        $r = self::scan( [ $draft ], null );
        return [ 'people' => (int) $r['sizes']['__draft']['n'], 'known' => (int) $r['sizes']['__draft']['known'], 'partial' => $r['partial'] ];
    }

    /** One audience: size, known people, what they look at, results, where used. */
    public static function detail( string $id ) {
        $aud = self::audience( $id );
        if ( ! $aud ) return new WP_Error( 'missing', 'This audience was deleted.' );
        $r = self::scan( [ $aud ], $id );
        usort( $r['members'], static fn( $a, $b ) => $b['last'] <=> $a['last'] );
        $members = [];
        foreach ( array_slice( $r['members'], 0, 200 ) as $m ) {
            $name = '';
            $user = $m['customer_id'] ? get_user_by( 'id', $m['customer_id'] ) : ( $m['email'] ? get_user_by( 'email', $m['email'] ) : false );
            if ( $user ) $name = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
            $members[] = $m + [ 'name' => $name, 'user_id' => $user ? (int) $user->ID : 0 ];
        }
        arsort( $r['views'] );
        $top = array_slice( $r['views'], 0, 8, true );
        $names = self::product_names( array_keys( $top ) );
        $products = [];
        foreach ( $top as $pid => $n ) $products[] = [ 'id' => (int) $pid, 'name' => $names[ (string) $pid ] ?? '#' . $pid, 'people' => $n ];
        $size = $r['sizes'][ $id ];
        $from = self::day_ago( 29 );
        $history = self::sums_by_day( 'asize', $id, 30 );
        $rule_pids = [];
        foreach ( (array) $aud['rules'] as $rule ) if ( ! empty( $rule['product_id'] ) ) $rule_pids[] = (int) $rule['product_id'];
        return [
            'audience' => $aud,
            'people'   => $size['n'],
            'known'    => $size['known'],
            'buyers'   => $size['buyers'],
            'sales'    => round( $size['sales'], 2 ),
            'members'  => $members,
            'products' => $products,
            'history'  => $history,
            'rule_products' => self::product_names( array_unique( $rule_pids ) ),
            'personal_sales'  => round( (int) ( self::sums( 'asales', $from, self::today() )[ $id ] ?? 0 ) / 100, 2 ),
            'personal_orders' => (int) ( self::sums( 'aorders', $from, self::today() )[ $id ] ?? 0 ),
            'seen'     => (int) ( self::sums( 'aseen', $from, self::today() )[ $id ] ?? 0 ),
            'used'     => self::where_used()[ $id ] ?? [],
            'store_rate' => self::store_buy_rate(),
            'partial'  => $r['partial'],
            'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
        ];
    }

    private static function sums_by_day( string $metric, string $item, int $days ): array {
        global $wpdb;
        $t = self::stats_table();
        $out = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, n FROM $t WHERE metric = %s AND item = %s AND day >= %s ORDER BY day", $metric, $item, self::day_ago( $days - 1 ) ), ARRAY_A ) as $r ) $out[] = [ 'day' => $r['day'], 'n' => (int) $r['n'] ];
        return $out;
    }

    /** Visitors in 30 days who ordered, in % (for "store average"). */
    private static function store_buy_rate(): float {
        $f = self::sums( 'funnel', self::day_ago( 29 ), self::today() );
        return ! empty( $f['visited'] ) ? round( 100 * (int) ( $f['ordered'] ?? 0 ) / (int) $f['visited'], 1 ) : 0.0;
    }

    /**
     * Where each audience is used: [ id => [ [ kind, label, ref ] ] ] over Store builder
     * sections and blocks (every language), popups, emails, automations and push.
     */
    public static function where_used(): array {
        global $wpdb;
        $out = [];
        $add = static function ( $id, $kind, $label, $ref = '' ) use ( &$out ) {
            $id = sanitize_key( (string) $id );
            if ( $id === '' ) return;
            foreach ( $out[ $id ] ?? [] as $u ) if ( $u['kind'] === $kind && $u['ref'] === $ref ) return;
            $out[ $id ][] = [ 'kind' => $kind, 'label' => mb_substr( (string) $label, 0, 80 ), 'ref' => (string) $ref ];
        };
        $all = get_option( 'shop_builder_options', [] );
        if ( is_array( $all ) ) {
            foreach ( (array) ( $all['popups'] ?? [] ) as $pp ) {
                if ( in_array( $pp['settings']['audience'] ?? '', [ 'saved', 'not_saved' ], true ) ) $add( $pp['settings']['audience_id'] ?? '', 'popup', $pp['name'] ?? '', $pp['id'] ?? '' );
                self::walk_used( (array) ( $pp['sections'] ?? [] ), 'popup', (string) ( $pp['name'] ?? '' ), (string) ( $pp['id'] ?? '' ), $add );
            }
            $titles = [];
            foreach ( (array) ( $all['custom_pages'] ?? [] ) as $cp ) if ( is_array( $cp ) && ! empty( $cp['id'] ) ) $titles[ $cp['id'] ] = (string) ( $cp['title'] ?? '' );
            foreach ( $all as $page => $value ) {
                if ( in_array( $page, [ 'popups', 'languages', 'translations' ], true ) || ! is_array( $value ) ) continue;
                if ( $page === 'custom_pages' ) {
                    foreach ( $value as $cp ) if ( is_array( $cp ) ) self::walk_used( (array) ( $cp['sections'] ?? [] ), 'page', (string) ( $cp['title'] ?? '' ), (string) ( $cp['id'] ?? '' ), $add );
                    continue;
                }
                self::walk_used( $value, 'page', (string) $page, (string) $page, $add );
            }
        }
        if ( class_exists( 'Qwoo_Email_Marketing' ) ) {
            foreach ( (array) $wpdb->get_results( 'SELECT id, name, audience_id FROM ' . Qwoo_Email_Marketing::campaigns_table() . " WHERE segment = 'saved_audience' AND audience_id <> '' LIMIT 200", ARRAY_A ) as $c ) $add( $c['audience_id'], 'email', $c['name'], (string) $c['id'] );
        }
        foreach ( (array) get_option( 'qwoo_flows', [] ) as $flow ) {
            if ( ! is_array( $flow ) ) continue;
            if ( ! empty( $flow['audience_id'] ) ) $add( $flow['audience_id'], 'automation', $flow['name'] ?? '', $flow['id'] ?? '' );
            if ( ! empty( $flow['trigger']['audience_id'] ) ) $add( $flow['trigger']['audience_id'], 'automation', $flow['name'] ?? '', $flow['id'] ?? '' );
        }
        if ( class_exists( 'Qwoo_Push' ) ) {
            $push = Qwoo_Push::settings();
            foreach ( (array) ( $push['automations'] ?? [] ) as $a ) {
                if ( ! empty( $a['audience_id'] ) ) $add( $a['audience_id'], 'push', $a['name'] ?? '', $a['id'] ?? '' );
                if ( ! empty( $a['trigger']['audience_id'] ) ) $add( $a['trigger']['audience_id'], 'push', $a['name'] ?? '', $a['id'] ?? '' );
            }
            foreach ( (array) get_option( 'qwoo_push_sends', [] ) as $send ) if ( is_array( $send ) && ! empty( $send['audience']['audience_id'] ) ) $add( $send['audience']['audience_id'], 'push_send', $send['title'] ?? ( $send['texts'][ array_key_first( (array) ( $send['texts'] ?? [] ) ) ?? '' ]['title'] ?? '' ), $send['id'] ?? '' );
        }
        return $out;
    }

    /** Sections and blocks shown only to (or hidden from) an audience. */
    private static function walk_used( array $nodes, string $kind, string $label, string $ref, callable $add ): void {
        foreach ( $nodes as $key => $node ) {
            if ( ! is_array( $node ) ) continue;
            $style = is_array( $node['style'] ?? null ) ? $node['style'] : [];
            if ( in_array( $style['audience'] ?? '', [ 'in', 'out' ], true ) && ! empty( $style['audience_id'] ) ) $add( $style['audience_id'], $kind, $label, $ref );
            foreach ( $node as $child ) if ( is_array( $child ) ) self::walk_used( $child, $kind, $label, $ref, $add );
        }
    }

    /* ================================================================
       Email, push and automations
       ================================================================ */

    public static function audience_count( string $id ): array {
        $ids = self::matching_subscriber_ids( $id );
        return [ 'count' => count( $ids['ids'] ), 'sampled' => $ids['sampled'] ];
    }

    /** Active email subscribers in the audience. */
    public static function matching_subscriber_ids( string $audience_id ): array {
        global $wpdb;
        $aud = self::audience( $audience_id );
        if ( ! $aud || ! class_exists( 'Qwoo_Email_Marketing' ) ) return [ 'ids' => [], 'sampled' => false ];
        $sub_table = Qwoo_Email_Marketing::subs_table();
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sub_table WHERE status = 'sub'" );
        $subs  = self::subscriber_index();
        if ( ! $subs ) return [ 'ids' => [], 'sampled' => false ];
        $orders = self::order_index();
        $push   = self::push_index();
        // Each subscriber's newest visitor row (by email or subscriber id).
        $rows = [];
        $last = '';
        $t = self::table();
        $by_sub = array_flip( $subs );
        do {
            $batch = (array) $wpdb->get_results( $wpdb->prepare( "SELECT visitor_key, customer_id, subscriber_id, email, signals, updated_at FROM $t WHERE visitor_key > %s AND expires_at > %s AND ( email <> '' OR subscriber_id > 0 OR customer_id > 0 ) ORDER BY visitor_key LIMIT 1000", $last, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
            foreach ( $batch as $row ) {
                $last = $row['visitor_key'];
                $mail = strtolower( (string) $row['email'] );
                if ( $mail === '' && (int) $row['subscriber_id'] && isset( $by_sub[ (int) $row['subscriber_id'] ] ) ) $mail = $by_sub[ (int) $row['subscriber_id'] ];
                if ( $mail === '' && (int) $row['customer_id'] ) { $u = get_user_by( 'id', (int) $row['customer_id'] ); $mail = $u ? strtolower( $u->user_email ) : ''; }
                if ( $mail === '' || ! isset( $subs[ $mail ] ) ) continue;
                if ( ! isset( $rows[ $mail ] ) || $row['updated_at'] > $rows[ $mail ]['updated_at'] ) $rows[ $mail ] = $row + [ 'email' => $mail ];
            }
        } while ( count( $batch ) === 1000 );
        $ids = [];
        foreach ( $subs as $mail => $sid ) {
            $row = $rows[ $mail ] ?? [ 'email' => $mail, 'customer_id' => 0, 'subscriber_id' => $sid, 'signals' => [] ];
            $row['email'] = $mail;
            if ( self::matches_facts( $aud, self::facts( $row, $orders, $subs, $push ) ) ) $ids[] = $sid;
        }
        return [ 'ids' => $ids, 'sampled' => $total > count( $subs ) ];
    }

    /** One person by email (automations, sending). */
    public static function audience_for_email( string $audience_id, string $email ): bool {
        global $wpdb;
        $email = strtolower( $email );
        $aud = self::audience( $audience_id );
        if ( ! $aud || ! is_email( $email ) ) return false;
        $cache = 'm:' . $audience_id . ':' . $email;
        if ( array_key_exists( $cache, self::$people ) ) return self::$people[ $cache ];
        $user = get_user_by( 'email', $email );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ( email = %s OR customer_id = %d ) AND expires_at > %s ORDER BY updated_at DESC LIMIT 1', $email, $user ? (int) $user->ID : -1, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
        $row = $row ?: [ 'customer_id' => $user ? (int) $user->ID : 0, 'subscriber_id' => 0, 'signals' => [] ];
        $row['email'] = $email;
        return self::$people[ $cache ] = self::matches_facts( $aud, self::facts( $row ) );
    }

    /** Whether the person with this email did one of the owner's events in the last days (automation rules). */
    public static function email_did_event( string $email, string $event_id, int $days = 30 ): bool {
        global $wpdb;
        $email = strtolower( $email );
        if ( ! is_email( $email ) || ! class_exists( 'Qwoo_Events' ) || ! Qwoo_Events::exists( $event_id ) ) return false;
        $user = get_user_by( 'email', $email );
        $rows = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT signals FROM ' . self::table() . ' WHERE ( email = %s OR customer_id = %d ) AND expires_at > %s ORDER BY updated_at DESC LIMIT 5', $email, $user ? (int) $user->ID : -1, gmdate( 'Y-m-d H:i:s' ) ) );
        $since = time() - max( 1, $days ) * DAY_IN_SECONDS;
        foreach ( $rows as $json ) {
            $s = json_decode( (string) $json, true );
            if ( is_array( $s ) && (int) ( $s['ev'][ $event_id ][1] ?? 0 ) >= $since ) return true;
        }
        return false;
    }

    /** A deleted event: its daily totals go (visitors' own counts fade out with their rows). */
    public static function forget_event( string $event_id ): void {
        global $wpdb;
        $t = self::stats_table();
        $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE metric IN ('ev','evu') AND item = %s", $event_id ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE metric = 'evp' AND item LIKE %s", $wpdb->esc_like( $event_id . '|' ) . '%' ) );
    }

    public static function audience_for_customer( int $customer_id, string $audience_id ): bool {
        if ( $customer_id < 1 ) return false;
        $user = get_user_by( 'id', $customer_id );
        return $user ? self::audience_for_email( $audience_id, (string) $user->user_email ) : false;
    }

    /** Customer ids in the audience (push sends: one pass instead of one check per device). */
    public static function matching_customer_ids( string $audience_id ): array {
        global $wpdb;
        $aud = self::audience( $audience_id );
        if ( ! $aud || ! class_exists( 'Qwoo_Push_Engine' ) ) return [];
        $out = [];
        foreach ( array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT customer_id FROM ' . Qwoo_Push_Engine::subs_table() . ' WHERE customer_id > 0 LIMIT 50000' ) ) as $cid ) {
            if ( self::audience_for_customer( $cid, $audience_id ) ) $out[ $cid ] = true;
        }
        return $out;
    }

    /* ================================================================
       Erasing and cleaning up
       ================================================================ */

    public static function erase_current(): void {
        global $wpdb;
        $key = self::visitor_key( false );
        if ( $key !== '' ) $wpdb->delete( self::table(), [ 'visitor_key' => $key ] );
        if ( is_user_logged_in() ) $wpdb->delete( self::table(), [ 'customer_id' => get_current_user_id() ] );
        if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
            setcookie( self::COOKIE, '', [ 'expires' => time() - DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ] );
            unset( $_COOKIE[ self::COOKIE ] );
        }
    }

    public static function erase_user( int $user_id ): void {
        global $wpdb;
        $user = get_user_by( 'id', $user_id );
        $wpdb->delete( self::table(), [ 'customer_id' => $user_id ] );
        if ( $user ) $wpdb->delete( self::table(), [ 'email' => strtolower( (string) $user->user_email ) ] );
    }

    public static function erase_subscriber( int $subscriber_id ): void {
        global $wpdb;
        if ( $subscriber_id > 0 ) $wpdb->delete( self::table(), [ 'subscriber_id' => $subscriber_id ] );
    }

    public static function privacy_eraser( array $erasers ): array {
        $erasers['qwoo-personalization'] = [ 'eraser_friendly_name' => 'Store audiences (visitor activity)', 'callback' => static function ( $email ) {
            global $wpdb;
            $email = strtolower( (string) $email );
            $removed = (int) $wpdb->delete( self::table(), [ 'email' => $email ] );
            $user = get_user_by( 'email', $email );
            if ( $user ) $removed += (int) $wpdb->delete( self::table(), [ 'customer_id' => (int) $user->ID ] );
            if ( class_exists( 'Qwoo_Email_Marketing' ) ) {
                $sub = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Qwoo_Email_Marketing::subs_table() . ' WHERE email = %s', $email ) );
                if ( $sub ) $removed += (int) $wpdb->delete( self::table(), [ 'subscriber_id' => (int) $sub ] );
            }
            return [ 'items_removed' => $removed > 0, 'items_retained' => false, 'messages' => [], 'done' => true ];
        } ];
        return $erasers;
    }

    public static function prune(): void {
        if ( get_transient( 'qwoo_personalization_pruned' ) ) return;
        set_transient( 'qwoo_personalization_pruned', 1, DAY_IN_SECONDS );
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE expires_at <= %s', gmdate( 'Y-m-d H:i:s' ) ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::stats_table() . ' WHERE day < %s', self::day_ago( self::STATS_DAYS ) ) );
        // The day's audience sizes (the trend on the Audiences page) while it is on.
        if ( self::active() && self::audiences() ) self::sizes( true );
    }
}

Qwoo_Personalization::init();

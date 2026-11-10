<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * First-party shopper signals used only to evaluate store-owner audiences.
 * This is deliberately not an analytics/event-history service: each browser
 * has one bounded, expiring row of derived signals and no raw IP or email.
 */
class Qwoo_Personalization {

    const OPTION       = 'qwoo_personalization';
    const COOKIE       = 'qwoo_persona';
    const RETENTION    = 30;
    const MAX_AUDIENCES = 50;
    const MAX_RULES    = 8;
    const MAX_PRODUCTS = 40;

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
        add_action( 'qwoo_subscriber_added', [ __CLASS__, 'link_subscriber' ], 5, 1 );
        add_action( 'qwoo_push_tick', [ __CLASS__, 'prune' ] );
        add_action( 'deleted_user', [ __CLASS__, 'erase_user' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'privacy_eraser' ] );
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'qwoo_personalization'; }

    public static function install_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        dbDelta( "CREATE TABLE $t (
        visitor_key CHAR(64) NOT NULL,
        customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        signals LONGTEXT NOT NULL,
        updated_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY  (visitor_key),
        KEY customer_id (customer_id),
        KEY subscriber_id (subscriber_id),
        KEY expires_at (expires_at)
        ) " . $wpdb->get_charset_collate() . ';' );
    }

    public static function enabled(): bool {
        $v = get_option( self::OPTION, [] );
        return is_array( $v ) && ! empty( $v['enabled'] );
    }

    public static function dashboard_state(): array {
        $v = self::settings();
        return [ 'enabled' => ! empty( $v['enabled'] ), 'retention_days' => self::RETENTION, 'audiences' => self::audiences(), 'categories' => self::categories() ];
    }

    public static function save_settings( array $in ): array {
        $v = self::settings();
        $v['enabled'] = ! empty( $in['enabled'] );
        update_option( self::OPTION, $v, false );
        if ( empty( $v['enabled'] ) ) {
            global $wpdb;
            $wpdb->query( 'DELETE FROM ' . self::table() );
        }
        return self::dashboard_state();
    }

    public static function categories(): array {
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 300 ] );
        if ( is_wp_error( $terms ) ) return [];
        return array_map( static fn( $term ) => [ 'id' => (int) $term->term_id, 'name' => (string) $term->name ], $terms );
    }

    public static function audience_count( string $id ): array {
        $ids = self::matching_subscriber_ids( $id );
        return [ 'count' => count( $ids['ids'] ), 'sampled' => $ids['sampled'] ];
    }

    private static function settings(): array {
        $v = get_option( self::OPTION, [] );
        return is_array( $v ) ? $v : [];
    }

    public static function routes() {
        register_rest_route( 'qwoo/v1', '/personalization/event', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_event' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'qwoo/v1', '/personalization/evaluate', [
            'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_evaluate' ], 'permission_callback' => '__return_true',
        ] );
    }

    public static function rest_evaluate( WP_REST_Request $request ) {
        if ( ! self::enabled() || ! self::consent_allowed() || ! self::origin_allowed( $request ) ) return rest_ensure_response( [ 'match' => false ] );
        $id = sanitize_key( (string) $request->get_param( 'audience_id' ) );
        $key = self::visitor_key( false );
        if ( $id === '' || $key === '' ) return rest_ensure_response( [ 'match' => false ] );
        $rate_key = 'qwoo_persona_eval_' . substr( hash_hmac( 'sha256', $key, wp_salt( 'auth' ) ), 0, 32 );
        $rate_count = (int) get_transient( $rate_key );
        if ( $rate_count >= 60 ) return rest_ensure_response( [ 'match' => false ] );
        set_transient( $rate_key, $rate_count + 1, MINUTE_IN_SECONDS );
        $profile = self::get_profile( $key );
        $user_id = is_user_logged_in() ? get_current_user_id() : (int) ( $profile['customer_id'] ?? 0 );
        $user = $user_id ? get_user_by( 'id', $user_id ) : false;
        $email = $user ? (string) $user->user_email : '';
        if ( $email === '' && ! empty( $profile['subscriber_id'] ) ) {
            global $wpdb;
            $email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . Qwoo_Email_Marketing::subs_table() . ' WHERE id = %d', (int) $profile['subscriber_id'] ) );
        }
        return rest_ensure_response( [ 'match' => self::matches( $profile, $id, $email ) ] );
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
            $front = (string) Qwoo_Technical_Settings::get_primary_frontend_domain();
            $allowed[] = $normalize( $front );
        }
        return in_array( $origin, array_filter( $allowed ), true );
    }

    private static function visitor_key( bool $create = true ): string {
        $raw = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $raw ) ) {
            if ( ! $create ) return '';
            try { $raw = bin2hex( random_bytes( 32 ) ); } catch ( Throwable $e ) { return ''; }
            $secure = is_ssl() ? '; Secure' : '';
            setcookie( self::COOKIE, $raw, [ 'expires' => time() + self::RETENTION * DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ] );
            $_COOKIE[ self::COOKIE ] = $raw;
        }
        return hash( 'sha256', $raw . wp_salt( 'auth' ) );
    }

    private static function get_profile( string $key ): array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT customer_id, subscriber_id, signals FROM ' . self::table() . ' WHERE visitor_key = %s AND expires_at > %s', $key, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
        $signals = $row ? json_decode( (string) $row['signals'], true ) : [];
        return [
            'customer_id' => (int) ( $row['customer_id'] ?? get_current_user_id() ),
            'subscriber_id' => (int) ( $row['subscriber_id'] ?? 0 ),
            'signals' => is_array( $signals ) ? $signals : [],
        ];
    }

    private static function save_profile( string $key, array $profile ): void {
        global $wpdb;
        $signals = $profile['signals'];
        // Bound all profile dimensions to keep the row and evaluation cost small.
        foreach ( [ 'views', 'categories' ] as $part ) {
            if ( ! is_array( $signals[ $part ] ?? null ) ) $signals[ $part ] = [];
            $cutoff = time() - self::RETENTION * DAY_IN_SECONDS;
            foreach ( $signals[ $part ] as $id => $signal ) if ( (int) ( $signal['last_at'] ?? 0 ) < $cutoff ) unset( $signals[ $part ][ $id ] );
            uasort( $signals[ $part ], static fn( $a, $b ) => (int) ( $b['last_at'] ?? 0 ) <=> (int) ( $a['last_at'] ?? 0 ) );
            $signals[ $part ] = array_slice( $signals[ $part ], 0, self::MAX_PRODUCTS, true );
        }
        foreach ( [ 'cart', 'wishlist' ] as $part ) if ( (int) ( $signals[ $part ]['updated_at'] ?? 0 ) < time() - self::RETENTION * DAY_IN_SECONDS ) unset( $signals[ $part ] );
        $signals = array_intersect_key( $signals, array_flip( [ 'views', 'categories', 'cart', 'wishlist', 'quota' ] ) );
        $now = gmdate( 'Y-m-d H:i:s' );
        $data = [
            'visitor_key' => $key,
            'customer_id' => max( 0, (int) $profile['customer_id'] ),
            'subscriber_id' => max( 0, (int) $profile['subscriber_id'] ),
            'signals' => wp_json_encode( $signals ),
            'updated_at' => $now,
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::RETENTION * DAY_IN_SECONDS ),
        ];
        $wpdb->replace( self::table(), $data, [ '%s', '%d', '%d', '%s', '%s', '%s' ] );
    }

    private static function clean_product_ids( $raw ): array {
        if ( ! is_array( $raw ) ) return [];
        $ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) ), 0, self::MAX_PRODUCTS );
        return array_values( array_filter( $ids, static function( $id ) {
            $p = wc_get_product( $id );
            return $p && $p->is_visible() && $p->get_status() === 'publish';
        } ) );
    }

    private static function product_categories( int $product_id ): array {
        $ids = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
        return is_wp_error( $ids ) ? [] : array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
    }

    public static function rest_event( WP_REST_Request $request ) {
        if ( ! self::enabled() || ! self::origin_allowed( $request ) ) return new WP_Error( 'personalization_unavailable', 'Personalization is unavailable.', [ 'status' => 403 ] );
        $raw = $request->get_body();
        if ( strlen( $raw ) > 8192 ) return new WP_Error( 'payload', 'The request is too large.', [ 'status' => 413 ] );
        if ( ! self::consent_allowed() ) {
            self::erase_current();
            return new WP_Error( 'consent', 'Analytics consent is required.', [ 'status' => 403 ] );
        }
        $key = self::visitor_key();
        if ( $key === '' ) return new WP_Error( 'visitor', 'Unable to start a personalization session.', [ 'status' => 400 ] );
        $limit_key = 'qwoo_persona_rl_' . substr( hash_hmac( 'sha256', $key, wp_salt( 'auth' ) ), 0, 32 );
        $limited = (int) get_transient( $limit_key );
        if ( $limited >= 120 ) return new WP_Error( 'rate', 'Too many personalization events.', [ 'status' => 429 ] );
        set_transient( $limit_key, $limited + 1, HOUR_IN_SECONDS );
        $profile = self::get_profile( $key );
        $hour = gmdate( 'YmdH' );
        if ( ( $profile['signals']['quota']['hour'] ?? '' ) !== $hour ) $profile['signals']['quota'] = [ 'hour' => $hour, 'n' => 0 ];
        if ( (int) $profile['signals']['quota']['n'] >= 120 ) return new WP_Error( 'rate', 'Too many personalization events.', [ 'status' => 429 ] );
        $profile['signals']['quota']['n']++;
        $event = (array) $request->get_json_params();
        $type = sanitize_key( (string) ( $event['type'] ?? '' ) );
        $hook_type = '';
        $hook_event = [];
        $now = time();
        if ( $type === 'erase' ) { self::erase_current(); return rest_ensure_response( [ 'ok' => true ] ); }
        if ( $type === 'view' ) {
            $id = absint( $event['product_id'] ?? 0 );
            $product = $id ? wc_get_product( $id ) : null;
            if ( ! $product || ! $product->is_visible() || $product->get_status() !== 'publish' ) return new WP_Error( 'product', 'Choose a published product.', [ 'status' => 400 ] );
            $seconds = min( 3600, max( 0, absint( $event['seconds'] ?? 0 ) ) );
            if ( $seconds < 5 ) return rest_ensure_response( [ 'ok' => true ] );
            $cats = self::product_categories( $id );
            $prev = $profile['signals']['views'][ $id ] ?? [ 'seconds' => 0, 'last_at' => 0 ];
            $profile['signals']['views'][ $id ] = [ 'seconds' => min( 7200, (int) $prev['seconds'] + $seconds ), 'category_ids' => $cats, 'last_at' => $now ];
            foreach ( $cats as $cat ) {
                $row = $profile['signals']['categories'][ $cat ] ?? [ 'views' => 0, 'seconds' => 0, 'last_at' => 0 ];
                $profile['signals']['categories'][ $cat ] = [ 'views' => min( 100, (int) $row['views'] + 1 ), 'seconds' => min( 7200, (int) $row['seconds'] + $seconds ), 'last_at' => $now ];
            }
            $hook_type = 'view';
            $hook_event = [ 'product_id' => $id, 'category_ids' => $cats, 'seconds' => $seconds ];
        } elseif ( in_array( $type, [ 'cart', 'wishlist' ], true ) ) {
            $ids = self::clean_product_ids( $event['product_ids'] ?? [] );
            $category_ids = [];
            foreach ( $ids as $product_id ) $category_ids = array_merge( $category_ids, self::product_categories( $product_id ) );
            $profile['signals'][ $type ] = [ 'product_ids' => $ids, 'category_ids' => array_values( array_unique( array_map( 'absint', $category_ids ) ) ), 'updated_at' => $now ];
            $hook_type = $type;
            $hook_event = [ 'product_ids' => $ids ];
        } else {
            return new WP_Error( 'event', 'Unknown personalization event.', [ 'status' => 400 ] );
        }
        if ( is_user_logged_in() ) $profile['customer_id'] = get_current_user_id();
        self::save_profile( $key, $profile );
        if ( $hook_type !== '' ) do_action( 'qwoo_personalization_event', $hook_type, $profile, $hook_event, $key );
        return rest_ensure_response( [ 'ok' => true ] );
    }

    public static function link_subscriber( $email ): void {
        $key = self::visitor_key( false );
        if ( $key === '' || ! self::consent_allowed() ) return;
        global $wpdb;
        $sub = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Qwoo_Email_Marketing::subs_table() . ' WHERE email = %s', strtolower( sanitize_email( (string) $email ) ) ) );
        if ( $sub ) $wpdb->update( self::table(), [ 'subscriber_id' => (int) $sub ], [ 'visitor_key' => $key ] );
    }

    public static function audiences(): array {
        $v = self::settings();
        return array_values( array_filter( (array) ( $v['audiences'] ?? [] ), 'is_array' ) );
    }

    private static function clean_rules( array $raw ): array {
        $out = [];
        foreach ( array_slice( $raw, 0, self::MAX_RULES ) as $rule ) {
            if ( ! is_array( $rule ) ) continue;
            $type = sanitize_key( (string) ( $rule['type'] ?? '' ) );
            if ( $type === 'view_category' ) {
                $id = absint( $rule['category_id'] ?? 0 );
                if ( $id && term_exists( $id, 'product_cat' ) ) $out[] = [ 'type' => $type, 'category_id' => $id, 'days' => min( self::RETENTION, max( 1, absint( $rule['days'] ?? self::RETENTION ) ) ) ];
            } elseif ( $type === 'view_product' ) {
                $id = absint( $rule['product_id'] ?? 0 );
                if ( $id && wc_get_product( $id ) ) $out[] = [ 'type' => $type, 'product_id' => $id, 'seconds' => min( 3600, max( 5, absint( $rule['seconds'] ?? 30 ) ) ), 'days' => min( self::RETENTION, max( 1, absint( $rule['days'] ?? self::RETENTION ) ) ) ];
            } elseif ( in_array( $type, [ 'cart_has_items', 'wishlist_has_items', 'has_orders', 'no_orders' ], true ) ) {
                $out[] = [ 'type' => $type ];
            } elseif ( in_array( $type, [ 'orders_at_least', 'spent_at_least' ], true ) ) {
                $out[] = [ 'type' => $type, 'value' => min( 100000000, max( 1, (float) ( $rule['value'] ?? 1 ) ) ) ];
            }
        }
        return $out;
    }

    public static function save_audience( array $in ) {
        $rules = self::clean_rules( (array) ( $in['rules'] ?? [] ) );
        if ( ! $rules ) return new WP_Error( 'rules', 'Add at least one valid audience rule.' );
        $list = self::audiences();
        $id = sanitize_key( (string) ( $in['id'] ?? '' ) );
        $row = [ 'id' => $id ?: 'au' . bin2hex( random_bytes( 5 ) ), 'name' => mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 100 ), 'match' => ( $in['match'] ?? '' ) === 'any' ? 'any' : 'all', 'rules' => $rules, 'updated_at' => time() ];
        if ( $row['name'] === '' ) return new WP_Error( 'name', 'Give this audience a name.' );
        $found = false;
        foreach ( $list as &$a ) if ( ( $a['id'] ?? '' ) === $id && $id !== '' ) { $a = $row; $found = true; }
        unset( $a );
        if ( ! $found ) {
            if ( count( $list ) >= self::MAX_AUDIENCES ) return new WP_Error( 'max', 'A store can have up to ' . self::MAX_AUDIENCES . ' saved audiences.' );
            $list[] = $row;
        }
        $v = self::settings(); $v['audiences'] = $list; update_option( self::OPTION, $v, false );
        return [ 'audiences' => self::audiences(), 'audience' => $row ];
    }

    public static function delete_audience( string $id ): array {
        $v = self::settings();
        $v['audiences'] = array_values( array_filter( self::audiences(), static fn( $a ) => ( $a['id'] ?? '' ) !== $id ) );
        update_option( self::OPTION, $v, false );
        return [ 'audiences' => self::audiences() ];
    }

    public static function matches( array $profile, string $audience_id, string $email = '', ?array $orders = null ): bool {
        $aud = null;
        foreach ( self::audiences() as $a ) if ( ( $a['id'] ?? '' ) === $audience_id ) { $aud = $a; break; }
        if ( ! $aud ) return false;
        $results = array_map( static fn( $r ) => self::rule_matches( $r, $profile, $email, $orders ), (array) $aud['rules'] );
        return ( $aud['match'] ?? 'all' ) === 'any' ? in_array( true, $results, true ) : ! in_array( false, $results, true );
    }

    private static function rule_matches( array $rule, array $profile, string $email, ?array $orders = null ): bool {
        global $wpdb;
        $signals = (array) ( $profile['signals'] ?? [] );
        switch ( $rule['type'] ?? '' ) {
            case 'view_category':
                $row = $signals['categories'][ (int) $rule['category_id'] ] ?? null;
                return $row && (int) $row['last_at'] >= time() - (int) $rule['days'] * DAY_IN_SECONDS;
            case 'view_product':
                $row = $signals['views'][ (int) $rule['product_id'] ] ?? null;
                return $row && (int) $row['seconds'] >= (int) $rule['seconds'] && (int) $row['last_at'] >= time() - (int) $rule['days'] * DAY_IN_SECONDS;
            case 'cart_has_items': return ! empty( $signals['cart']['product_ids'] );
            case 'wishlist_has_items': return ! empty( $signals['wishlist']['product_ids'] );
            case 'has_orders':
            case 'no_orders':
            case 'orders_at_least':
            case 'spent_at_least':
                if ( ! is_email( $email ) ) return false;
                if ( $orders === null ) {
                    $t = Qwoo_Analytics::orders_table();
                    $orders = [
                        'count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE paid = 1 AND email = %s", strtolower( $email ) ) ),
                        'spent' => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total - refunded),0) FROM $t WHERE paid = 1 AND email = %s", strtolower( $email ) ) ),
                    ];
                }
                $count = (int) ( $orders['count'] ?? 0 );
                if ( $rule['type'] === 'has_orders' ) return $count > 0;
                if ( $rule['type'] === 'no_orders' ) return $count === 0;
                if ( $rule['type'] === 'orders_at_least' ) return $count >= (int) $rule['value'];
                return (float) ( $orders['spent'] ?? 0 ) >= (float) $rule['value'];
        }
        return false;
    }

    public static function audience_for_email( string $audience_id, string $email ): bool {
        static $cache = [];
        global $wpdb;
        if ( ! is_email( $email ) ) return false;
        $cache_key = $audience_id . ':' . hash( 'sha256', strtolower( $email ) );
        if ( array_key_exists( $cache_key, $cache ) ) return $cache[ $cache_key ];
        $user = get_user_by( 'email', $email );
        $sub = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Qwoo_Email_Marketing::subs_table() . " WHERE email = %s AND status = 'sub'", strtolower( $email ) ) );
        $rows = [];
        if ( $user ) $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT customer_id, subscriber_id, signals FROM ' . self::table() . ' WHERE customer_id = %d AND expires_at > %s', $user->ID, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
        if ( $sub ) $rows = array_merge( $rows, (array) $wpdb->get_results( $wpdb->prepare( 'SELECT customer_id, subscriber_id, signals FROM ' . self::table() . ' WHERE subscriber_id = %d AND expires_at > %s', $sub, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A ) );
        if ( ! $rows ) return $cache[ $cache_key ] = self::matches( [ 'customer_id' => (int) ( $user->ID ?? 0 ), 'signals' => [] ], $audience_id, $email );
        foreach ( $rows as $r ) if ( self::matches( [ 'customer_id' => (int) $r['customer_id'], 'signals' => json_decode( $r['signals'], true ) ?: [] ], $audience_id, $email ) ) return $cache[ $cache_key ] = true;
        return $cache[ $cache_key ] = false;
    }

    /** Matching eligible email-subscriber IDs, evaluated in one bounded batch. */
    public static function matching_subscriber_ids( string $audience_id ): array {
        global $wpdb;
        $sub_table = Qwoo_Email_Marketing::subs_table();
        $profile_table = self::table();
        $user_table = $wpdb->users;
        $order_table = Qwoo_Analytics::orders_table();
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sub_table WHERE status = 'sub'" );
        $subs = (array) $wpdb->get_results( "SELECT id,email FROM $sub_table WHERE status = 'sub' ORDER BY id LIMIT 50000", ARRAY_A );
        $sub_by_email = [];
        $emails = [];
        foreach ( $subs as $sub ) { $mail = strtolower( (string) $sub['email'] ); $sub_by_email[ $mail ] = (int) $sub['id']; $emails[] = $mail; }
        if ( ! $subs ) return [ 'ids' => [], 'sampled' => false ];
        $users = [];
        foreach ( (array) $wpdb->get_results( "SELECT s.id,u.ID FROM $sub_table s JOIN $user_table u ON u.user_email = s.email WHERE s.status = 'sub' LIMIT 50000", ARRAY_A ) as $user ) $users[ (int) $user['ID'] ] = (int) $user['id'];
        $customer_to_sub = [];
        foreach ( $users as $customer_id => $sid ) $customer_to_sub[ $customer_id ] = $sid;
        $profiles = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT customer_id,subscriber_id,signals FROM ' . $profile_table . ' WHERE expires_at > %s', gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A ) as $row ) {
            $sid = (int) $row['subscriber_id'];
            if ( ! $sid && ! empty( $customer_to_sub[ (int) $row['customer_id'] ] ) ) $sid = $customer_to_sub[ (int) $row['customer_id'] ];
            if ( $sid ) $profiles[ $sid ][] = json_decode( (string) $row['signals'], true ) ?: [];
        }
        $orders = [];
        foreach ( (array) $wpdb->get_results( "SELECT s.id,COUNT(o.order_id) AS n,COALESCE(SUM(o.total-o.refunded),0) AS spent FROM $sub_table s LEFT JOIN $order_table o ON o.email = s.email AND o.paid = 1 WHERE s.status = 'sub' GROUP BY s.id LIMIT 50000", ARRAY_A ) as $row ) {
            $orders[ (int) $row['id'] ] = [ 'count' => (int) $row['n'], 'spent' => (float) $row['spent'] ];
        }
        $ids = [];
        foreach ( $subs as $sub ) {
            $sid = (int) $sub['id'];
            $signals = [];
            foreach ( $profiles[ $sid ] ?? [] as $part ) foreach ( $part as $name => $value ) {
                if ( in_array( $name, [ 'views', 'categories' ], true ) && is_array( $value ) ) $signals[ $name ] = array_replace( $signals[ $name ] ?? [], $value );
                elseif ( in_array( $name, [ 'cart', 'wishlist' ], true ) && (int) ( $value['updated_at'] ?? 0 ) > (int) ( $signals[ $name ]['updated_at'] ?? 0 ) ) $signals[ $name ] = $value;
            }
            $profile = [ 'signals' => $signals ];
            if ( self::matches( $profile, $audience_id, strtolower( (string) $sub['email'] ), $orders[ $sid ] ?? [ 'count' => 0, 'spent' => 0 ] ) ) $ids[] = $sid;
        }
        return [ 'ids' => $ids, 'sampled' => $total > count( $subs ) ];
    }

    public static function audience_for_customer( int $customer_id, string $audience_id ): bool {
        if ( $customer_id < 1 ) return false;
        $user = get_user_by( 'id', $customer_id );
        return $user ? self::audience_for_email( $audience_id, (string) $user->user_email ) : false;
    }

    public static function erase_current(): void {
        global $wpdb;
        $key = self::visitor_key( false );
        if ( $key !== '' ) $wpdb->delete( self::table(), [ 'visitor_key' => $key ] );
        if ( is_user_logged_in() ) $wpdb->delete( self::table(), [ 'customer_id' => get_current_user_id() ] );
        setcookie( self::COOKIE, '', [ 'expires' => time() - DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ] );
        unset( $_COOKIE[ self::COOKIE ] );
    }

    public static function erase_user( int $user_id ): void {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'customer_id' => $user_id ] );
    }

    public static function erase_subscriber( int $subscriber_id ): void {
        global $wpdb;
        if ( $subscriber_id > 0 ) $wpdb->delete( self::table(), [ 'subscriber_id' => $subscriber_id ] );
    }

    public static function privacy_eraser( array $erasers ): array {
        $erasers['qwoo-personalization'] = [ 'eraser_friendly_name' => 'Store personalization signals', 'callback' => static function( $email ) {
            global $wpdb;
            $removed = 0;
            $user = get_user_by( 'email', (string) $email );
            if ( $user ) $removed += (int) $wpdb->delete( self::table(), [ 'customer_id' => (int) $user->ID ] );
            $sub = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Qwoo_Email_Marketing::subs_table() . ' WHERE email = %s', strtolower( (string) $email ) ) );
            if ( $sub ) $removed += (int) $wpdb->delete( self::table(), [ 'subscriber_id' => (int) $sub ] );
            return [ 'items_removed' => $removed > 0, 'items_retained' => false, 'messages' => [], 'done' => true ];
        } ];
        return $erasers;
    }

    public static function prune(): void {
        if ( get_transient( 'qwoo_personalization_pruned' ) ) return;
        set_transient( 'qwoo_personalization_pruned', 1, DAY_IN_SECONDS );
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE expires_at <= %s', gmdate( 'Y-m-d H:i:s' ) ) );
    }
}

Qwoo_Personalization::init();

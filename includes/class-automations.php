<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Automations (dashboard → Marketing → Automations): when something happens,
 * go through a short list of steps.
 *
 *   flow = { id, name, on, trigger: { type, status?, days?, minutes? }, rules: [ rule ], steps: [ step ] }
 *   rule = { check: first_order | order_over | not_ordered_since, amount? }
 *          checked before every send; when one fails the person leaves the flow
 *   step = { type: wait, minutes }
 *        | { type: email, campaign_id }       a design kept as a "flow" campaign
 *        | { type: push, title, body, link }  the shopper's own devices (signed-in shoppers)
 *        | { type: webhook, url }             Make, n8n and the like
 *
 * Flows saved before rules existed may still hold { type: condition } steps:
 * they keep working until the flow is next saved, which turns them into rules.
 *
 * Each person going through a flow is a row in qwoo_flow_runs, with `uniq` so
 * the same event can't start it twice. Rows run in the push engine's run (no
 * cron on shared hosting), so a wait is good to about 15 minutes.
 *
 * Marketing emails only reach subscribers: send_one() checks, whatever the flow
 * says. The push automations in Notifications stay as they are.
 */
class Qwoo_Automations {

    const OPTION    = 'qwoo_flows';
    const MAX_FLOWS = 20;
    const MAX_STEPS = 12;
    const TRIGGERS  = [ 'subscribed', 'order_placed', 'order_status', 'cart_left', 'no_order', 'product_view', 'cart_changed', 'wishlist_changed', 'audience_joined' ];
    const SCAN_KEY  = 'qwoo_flows_scanned';

    public static function init() {
        add_action( 'qwoo_subscriber_added', [ __CLASS__, 'on_subscribed' ], 10, 2 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'on_order_placed' ], 30 );
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_order_status' ], 30, 4 );
        add_action( 'qwoo_personalization_event', [ __CLASS__, 'on_personalization_event' ], 10, 4 );
        add_action( 'qwoo_audience_joined', [ __CLASS__, 'on_audience_joined' ], 10, 3 );
        add_action( 'qwoo_push_tick', [ __CLASS__, 'run_due' ] );
        add_filter( 'qwoo_push_next_due', [ __CLASS__, 'next_due' ] );
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'qwoo_flow_runs'; }

    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        dbDelta( "CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        flow_id VARCHAR(20) NOT NULL DEFAULT '',
        uniq VARCHAR(100) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        step INT NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT 'wait',
        next_at DATETIME NOT NULL,
        created DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq (uniq),
        KEY status_next (status, next_at),
        KEY flow_id (flow_id)
        ) " . $wpdb->get_charset_collate() . ';' );
    }

    public static function allowed(): bool {
        return ! Qwoo_Platform_Dashboard::is_free_plan();
    }

    /* ---------------- flows ---------------- */

    public static function flows(): array {
        $list = get_option( self::OPTION, [] );
        return is_array( $list ) ? $list : [];
    }

    /** The list for the dashboard: each flow with how many people went through it. */
    public static function overview(): array {
        global $wpdb;
        $counts = [];
        foreach ( (array) $wpdb->get_results( 'SELECT flow_id, status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY flow_id, status', ARRAY_A ) as $row ) {
            $counts[ $row['flow_id'] ][ $row['status'] ] = (int) $row['n'];
        }
        $out = [];
        foreach ( self::flows() as $flow ) {
            $c = $counts[ $flow['id'] ] ?? [];
            $flow['counts'] = [ 'waiting' => $c['wait'] ?? 0, 'done' => $c['done'] ?? 0, 'stopped' => $c['stopped'] ?? 0 ];
            $sent = [ 'sent' => 0, 'opened' => 0, 'clicked' => 0, 'orders' => 0, 'sales' => 0.0 ];
            foreach ( $flow['steps'] as $i => $step ) {
                if ( $step['type'] !== 'email' || empty( $step['campaign_id'] ) ) continue;
                $stats = Qwoo_Email_Marketing::campaign_stats( (int) $step['campaign_id'] );
                foreach ( $sent as $k => $v ) $sent[ $k ] = $v + $stats[ $k ];
                // For the editor's step title; save() doesn't keep it.
                $row = Qwoo_Email_Marketing::campaign_row( (int) $step['campaign_id'] );
                $flow['steps'][ $i ]['subject'] = $row ? (string) $row['subject'] : '';
            }
            $flow['results'] = $sent;
            $out[] = $flow;
        }
        return $out;
    }

    /** One step, cleaned. Null for what isn't allowed. */
    private static function clean_step( $step ) {
        $type = (string) ( $step['type'] ?? '' );
        switch ( $type ) {
            case 'wait':
                return [ 'type' => 'wait', 'minutes' => min( 90 * 24 * 60, max( 5, (int) ( $step['minutes'] ?? 60 ) ) ) ];
            case 'email':
                return [ 'type' => 'email', 'campaign_id' => (int) ( $step['campaign_id'] ?? 0 ) ];
            case 'push':
                return [ 'type' => 'push', 'title' => mb_substr( sanitize_text_field( (string) ( $step['title'] ?? '' ) ), 0, 80 ), 'body' => mb_substr( sanitize_text_field( (string) ( $step['body'] ?? '' ) ), 0, 180 ), 'link' => esc_url_raw( (string) ( $step['link'] ?? '' ) ) ];
            case 'webhook':
                $url = esc_url_raw( trim( (string) ( $step['url'] ?? '' ) ) );
                return Qwoo_Webhooks::check_url( $url ) === '' ? [ 'type' => 'webhook', 'url' => $url ] : null;
        }
        return null;
    }

    /** One rule per kind of check. */
    /**
     * What a rule can check ("Only continue if"). The order ones look at the
     * order that started the automation, or the cart of a checkout that was
     * left; with neither, they don't pass.
     */
    const CHECKS = [
        'first_order', 'order_over', 'order_under', 'not_ordered_since',
        'order_has_product', 'order_has_category', 'coupon_used', 'shipping_method', 'country',
        'orders_count', 'spent_total', 'subscribed', 'in_audience', 'has_account',
    ];

    private static function add_rule( array &$rules, array $raw ) {
        $check = (string) ( $raw['check'] ?? '' );
        if ( ! in_array( $check, self::CHECKS, true ) || isset( $rules[ $check ] ) ) return;
        $rule = [ 'check' => $check, 'amount' => max( 0, (float) ( $raw['amount'] ?? 0 ) ) ];
        $yes  = ! array_key_exists( 'is', $raw ) || ! empty( $raw['is'] );
        switch ( $check ) {
            case 'order_has_product':
            case 'order_has_category':
                $rule['ids'] = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $raw['ids'] ?? [] ) ) ) ) ), 0, 20 );
                // The names the owner picked, to show the rule again (the check uses the ids).
                $rule['names'] = array_slice( array_map( static fn( $n ) => mb_substr( sanitize_text_field( is_scalar( $n ) ? (string) $n : '' ), 0, 80 ), array_values( (array) ( $raw['names'] ?? [] ) ) ), 0, 20 );
                if ( ! $rule['ids'] ) return;
                break;
            case 'coupon_used':
                $rule['code'] = mb_substr( wc_format_coupon_code( (string) ( $raw['code'] ?? '' ) ), 0, 60 ); // '' = any code
                break;
            case 'shipping_method':
                $rule['method'] = sanitize_key( (string) ( $raw['method'] ?? '' ) );
                if ( $rule['method'] === '' ) return;
                break;
            case 'country':
                $codes = array_map( static fn( $c ) => strtoupper( preg_replace( '/[^a-zA-Z]/', '', is_scalar( $c ) ? (string) $c : '' ) ), (array) ( $raw['countries'] ?? [] ) );
                $rule['countries'] = array_slice( array_values( array_unique( array_filter( $codes, static fn( $c ) => strlen( $c ) === 2 ) ) ), 0, 60 );
                if ( ! $rule['countries'] ) return;
                break;
            case 'orders_count':
                $rule['op'] = ( $raw['op'] ?? '' ) === 'max' ? 'max' : 'min';
                $rule['n']  = min( 1000, max( 0, (int) ( $raw['n'] ?? 1 ) ) );
                break;
            case 'spent_total':
                $rule['op'] = ( $raw['op'] ?? '' ) === 'max' ? 'max' : 'min';
                break;
            case 'subscribed':
            case 'has_account':
                $rule['is'] = $yes;
                break;
            case 'in_audience':
                $rule['audience_id'] = sanitize_key( (string) ( $raw['audience_id'] ?? '' ) );
                $rule['is']          = $yes;
                if ( ! Qwoo_Personalization::exists( $rule['audience_id'] ) ) return;
                break;
        }
        $rules[ $check ] = $rule;
    }

    /** What the editor's rule fields choose from: countries and the store's shipping method kinds. */
    public static function rule_choices(): array {
        $methods = [];
        foreach ( WC()->shipping() ? WC()->shipping()->get_shipping_methods() : [] as $method ) {
            $methods[] = [ 'id' => (string) $method->id, 'name' => html_entity_decode( wp_strip_all_tags( (string) $method->get_method_title() ), ENT_QUOTES ) ];
        }
        return [ 'countries' => WC()->countries->get_countries(), 'shipping_methods' => $methods ];
    }

    public static function save( array $in ) {
        if ( ! self::allowed() ) return new WP_Error( 'plan', 'Automations are on the Basic plan and up.' );
        $type = (string) ( $in['trigger']['type'] ?? '' );
        if ( ! in_array( $type, self::TRIGGERS, true ) ) return new WP_Error( 'trigger', 'Choose what starts this automation.' );
        $trigger = [ 'type' => $type ];
        if ( $type === 'order_status' ) $trigger['status'] = sanitize_key( preg_replace( '/^wc-/', '', (string) ( $in['trigger']['status'] ?? 'completed' ) ) );
        if ( $type === 'no_order' ) $trigger['days'] = min( 730, max( 7, (int) ( $in['trigger']['days'] ?? 60 ) ) );
        if ( $type === 'cart_left' ) $trigger['minutes'] = min( 7 * 24 * 60, max( 30, (int) ( $in['trigger']['minutes'] ?? 60 ) ) );
        if ( $type === 'product_view' ) {
            $trigger['seconds'] = min( 3600, max( 5, absint( $in['trigger']['seconds'] ?? 30 ) ) );
            $category = absint( $in['trigger']['category_id'] ?? 0 );
            if ( $category && term_exists( $category, 'product_cat' ) ) $trigger['category_id'] = $category;
        }
        if ( $type === 'audience_joined' ) {
            $joined = sanitize_key( (string) ( $in['trigger']['audience_id'] ?? '' ) );
            if ( ! Qwoo_Personalization::exists( $joined ) ) return new WP_Error( 'audience', 'Choose the audience that starts this automation.' );
            $trigger['audience_id'] = $joined;
        }
        $audience_id = sanitize_key( (string) ( $in['audience_id'] ?? '' ) );
        if ( $audience_id && ! Qwoo_Personalization::exists( $audience_id ) ) return new WP_Error( 'audience', 'Choose an audience that still exists.' );
        $rules = [];
        foreach ( (array) ( $in['rules'] ?? [] ) as $raw ) self::add_rule( $rules, (array) $raw );
        $steps = [];
        foreach ( array_slice( (array) ( $in['steps'] ?? [] ), 0, self::MAX_STEPS ) as $raw ) {
            if ( ( $raw['type'] ?? '' ) === 'condition' ) {
                self::add_rule( $rules, (array) $raw );
                continue;
            }
            $step = self::clean_step( $raw );
            if ( $step === null ) return new WP_Error( 'step', 'A webhook step needs a public https address.' );
            $steps[] = $step;
        }
        if ( ! array_filter( $steps, static fn( $s ) => in_array( $s['type'], [ 'email', 'push', 'webhook' ], true ) ) ) {
            return new WP_Error( 'steps', 'Add at least one step that does something: an email, a notification or a webhook.' );
        }
        $flows = self::flows();
        $id    = sanitize_key( (string) ( $in['id'] ?? '' ) );
        $data  = [ 'name' => mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 120 ), 'on' => ! empty( $in['on'] ), 'trigger' => $trigger, 'audience_id' => $audience_id, 'rules' => array_values( $rules ), 'steps' => $steps ];
        $found = false;
        foreach ( $flows as &$flow ) {
            if ( $flow['id'] === $id ) {
                $flow  = array_merge( $flow, $data );
                $found = true;
            }
        }
        unset( $flow );
        if ( ! $found ) {
            if ( count( $flows ) >= self::MAX_FLOWS ) return new WP_Error( 'max', 'A store can have up to ' . self::MAX_FLOWS . ' automations.' );
            $id      = 'fl' . bin2hex( random_bytes( 5 ) );
            $flows[] = [ 'id' => $id ] + $data;
        }
        update_option( self::OPTION, $flows, false );
        return [ 'id' => $id, 'flows' => self::overview() ];
    }

    /** Sends the flow's emails to the owner right away, without the waits or the rules. Not counted. */
    public static function test( string $id, string $to ) {
        if ( ! is_email( $to ) ) return new WP_Error( 'email', 'Enter a valid email address.' );
        foreach ( self::flows() as $flow ) {
            if ( $flow['id'] !== $id ) continue;
            $sent = 0;
            foreach ( (array) $flow['steps'] as $step ) {
                if ( $step['type'] !== 'email' || empty( $step['campaign_id'] ) ) continue;
                $row = Qwoo_Email_Marketing::campaign_row( (int) $step['campaign_id'] );
                if ( $row && ! is_wp_error( Qwoo_Email_Marketing::send_test( $row, $to ) ) ) $sent++;
            }
            return [ 'sent' => $sent ];
        }
        return new WP_Error( 'missing', 'Save the automation first.' );
    }

    public static function remove( string $id ): array {
        global $wpdb;
        foreach ( self::flows() as $flow ) {
            if ( $flow['id'] !== $id ) continue;
            foreach ( $flow['steps'] as $step ) {
                if ( $step['type'] === 'email' && ! empty( $step['campaign_id'] ) ) Qwoo_Email_Marketing::delete_campaign( (int) $step['campaign_id'] );
            }
        }
        update_option( self::OPTION, array_values( array_filter( self::flows(), static fn( $f ) => $f['id'] !== $id ) ), false );
        $wpdb->delete( self::table(), [ 'flow_id' => $id ] );
        return self::overview();
    }

    /* ---------------- what starts a flow ---------------- */

    private static function start( string $type, string $key, string $email, int $order_id = 0, array $match = [] ) {
        global $wpdb;
        if ( ! self::allowed() ) return;
        foreach ( self::flows() as $flow ) {
            if ( empty( $flow['on'] ) || $flow['trigger']['type'] !== $type ) continue;
            if ( $type === 'order_status' && ( $flow['trigger']['status'] ?? '' ) !== ( $match['status'] ?? '' ) ) continue;
            if ( $type === 'no_order' && (int) ( $flow['trigger']['days'] ?? 0 ) !== (int) ( $match['days'] ?? 0 ) ) continue;
            if ( $type === 'audience_joined' && ( $flow['trigger']['audience_id'] ?? '' ) !== ( $match['audience_id'] ?? '' ) ) continue;
            if ( $type === 'product_view' ) {
                if ( (int) ( $match['seconds'] ?? 0 ) < (int) ( $flow['trigger']['seconds'] ?? 30 ) ) continue;
                if ( ! empty( $flow['trigger']['category_id'] ) && ! in_array( (int) $flow['trigger']['category_id'], array_map( 'absint', (array) ( $match['category_ids'] ?? [] ) ), true ) ) continue;
            }
            if ( ! empty( $flow['audience_id'] ) && ! Qwoo_Personalization::audience_for_email( (string) $flow['audience_id'], $email ) ) continue;
            $now = gmdate( 'Y-m-d H:i:s' );
            // A repeated event hits the unique key and adds nothing.
            $wpdb->query( $wpdb->prepare(
                'INSERT IGNORE INTO ' . self::table() . " (flow_id, uniq, email, order_id, step, status, next_at, created) VALUES (%s, %s, %s, %d, 0, 'wait', %s, %s)",
                $flow['id'], substr( $flow['id'] . ':' . $key, 0, 100 ), strtolower( $email ), $order_id, $now, $now
            ) );
            Qwoo_Push_Engine::note_due( time() );
            Qwoo_Push_Engine::spawn_soon();
        }
    }

    public static function on_subscribed( $email, $name = '' ) {
        self::start( 'subscribed', 'sub:' . md5( (string) $email ), (string) $email );
    }

    public static function on_order_placed( $order ) {
        if ( $order ) self::start( 'order_placed', 'ord:' . $order->get_id(), (string) $order->get_billing_email(), (int) $order->get_id() );
    }

    public static function on_order_status( $order_id, $from, $to, $order = null ) {
        $order = $order ?: wc_get_order( $order_id );
        if ( $order ) self::start( 'order_status', 'st:' . $order->get_id() . ':' . $to, (string) $order->get_billing_email(), (int) $order->get_id(), [ 'status' => (string) $to ] );
    }

    public static function on_personalization_event( string $type, array $profile, array $event, string $visitor_key = '' ): void {
        if ( ! self::allowed() ) return;
        $trigger = [ 'view' => 'product_view', 'cart' => 'cart_changed', 'wishlist' => 'wishlist_changed' ][ $type ] ?? '';
        if ( $trigger === '' ) return;
        $email = '';
        $customer_id = (int) ( $profile['customer_id'] ?? 0 );
        if ( $customer_id ) {
            $user = get_user_by( 'id', $customer_id );
            if ( $user ) $email = (string) $user->user_email;
        }
        if ( $email === '' && ! empty( $profile['subscriber_id'] ) ) {
            global $wpdb;
            $email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . Qwoo_Email_Marketing::subs_table() . " WHERE id = %d AND status = 'sub'", (int) $profile['subscriber_id'] ) );
        }
        if ( ! is_email( $email ) ) return;
        $key = 'persona:' . $type . ':' . substr( hash( 'sha256', $visitor_key ), 0, 18 ) . ':' . (int) ( $event['product_id'] ?? 0 ) . ':' . gmdate( 'Ymd' );
        self::start( $trigger, $key, $email, 0, $event );
    }

    /** Someone the store knows joined an audience (Marketing → Audiences); once a month per audience. */
    public static function on_audience_joined( string $audience_id, string $email, int $customer_id = 0 ): void {
        if ( ! self::allowed() || ! is_email( $email ) ) return;
        $key = 'aj:' . $audience_id . ':' . substr( hash( 'sha256', strtolower( $email ) ), 0, 18 ) . ':' . gmdate( 'Ym' );
        self::start( 'audience_joined', $key, $email, 0, [ 'audience_id' => $audience_id ] );
    }

    /** Twice an hour: checkouts left without paying, and buyers who haven't been back. */
    private static function scan() {
        if ( get_transient( self::SCAN_KEY ) ) return;
        set_transient( self::SCAN_KEY, 1, 30 * MINUTE_IN_SECONDS );
        global $wpdb;
        foreach ( self::flows() as $flow ) {
            if ( empty( $flow['on'] ) ) continue;
            if ( $flow['trigger']['type'] === 'cart_left' ) {
                $old  = time() - (int) $flow['trigger']['minutes'] * 60;
                $list = wc_get_orders( [ 'status' => 'checkout-draft', 'limit' => 100, 'date_modified' => ( $old - DAY_IN_SECONDS ) . '...' . $old ] );
                foreach ( $list as $order ) {
                    $email = (string) $order->get_billing_email();
                    if ( is_email( $email ) ) self::start( 'cart_left', 'cart:' . $order->get_id(), $email, (int) $order->get_id() );
                }
            } elseif ( $flow['trigger']['type'] === 'no_order' ) {
                $days = (int) $flow['trigger']['days'];
                $day  = wp_date( 'Y-m-d', time() - $days * DAY_IN_SECONDS, wp_timezone() );
                $o    = Qwoo_Analytics::orders_table();
                $rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT email FROM $o WHERE paid = 1 AND email <> '' GROUP BY email HAVING MAX(day) = %s LIMIT 500", $day ) );
                foreach ( $rows as $email ) {
                    self::start( 'no_order', 'no:' . md5( $email ) . ':' . $day, (string) $email, 0, [ 'days' => $days ] );
                }
            }
        }
    }

    /* ---------------- running ---------------- */

    public static function run_due( float $until ) {
        global $wpdb;
        if ( ! self::allowed() ) return;
        self::scan();
        $t     = self::table();
        $flows = array_column( self::flows(), null, 'id' );
        while ( microtime( true ) < $until ) {
            $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = 'wait' AND next_at <= %s ORDER BY next_at, id LIMIT 25", gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
            if ( ! $rows ) break;
            foreach ( $rows as $row ) {
                if ( microtime( true ) >= $until ) return;
                $flow = $flows[ $row['flow_id'] ] ?? null;
                if ( ! $flow || empty( $flow['on'] ) ) {
                    $wpdb->update( $t, [ 'status' => 'stopped' ], [ 'id' => $row['id'] ] );
                    continue;
                }
                self::advance( $flow, $row );
            }
        }
    }

    /** Does the steps that are due, up to the next wait. */
    private static function advance( array $flow, array $row ) {
        global $wpdb;
        $t     = self::table();
        $i     = (int) $row['step'];
        $steps = (array) $flow['steps'];
        // The cart was bought in the meantime: nothing more to remind about.
        if ( $flow['trigger']['type'] === 'cart_left' && (int) $row['order_id'] ) {
            $order = wc_get_order( (int) $row['order_id'] );
            if ( ! $order || $order->get_status() !== 'checkout-draft' ) {
                $wpdb->update( $t, [ 'status' => 'stopped' ], [ 'id' => $row['id'] ] );
                return;
            }
        }
        while ( isset( $steps[ $i ] ) ) {
            $step = $steps[ $i ];
            $i++;
            if ( $step['type'] === 'wait' ) {
                $wpdb->update( $t, [ 'step' => $i, 'next_at' => gmdate( 'Y-m-d H:i:s', time() + (int) $step['minutes'] * 60 ) ], [ 'id' => $row['id'] ] );
                return;
            }
            if ( $step['type'] === 'condition' && ! self::passes( $step, $row ) ) {
                $wpdb->update( $t, [ 'step' => $i, 'status' => 'stopped' ], [ 'id' => $row['id'] ] );
                return;
            }
            if ( in_array( $step['type'], [ 'email', 'push', 'webhook' ], true ) ) {
                if ( ! empty( $flow['audience_id'] ) && ! Qwoo_Personalization::audience_for_email( (string) $flow['audience_id'], (string) $row['email'] ) ) {
                    $wpdb->update( $t, [ 'step' => $i, 'status' => 'stopped' ], [ 'id' => $row['id'] ] );
                    return;
                }
                foreach ( (array) ( $flow['rules'] ?? [] ) as $rule ) {
                    if ( self::passes( $rule, $row ) ) continue;
                    $wpdb->update( $t, [ 'step' => $i, 'status' => 'stopped' ], [ 'id' => $row['id'] ] );
                    return;
                }
            }
            self::act( $step, $flow, $row );
        }
        $wpdb->update( $t, [ 'step' => $i, 'status' => 'done' ], [ 'id' => $row['id'] ] );
    }

    private static function passes( array $step, array $row ): bool {
        global $wpdb;
        $o     = Qwoo_Analytics::orders_table();
        $order = (int) $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : null;
        $email = strtolower( (string) $row['email'] );
        $is    = ! array_key_exists( 'is', $step ) || ! empty( $step['is'] );
        switch ( $step['check'] ) {
            case 'first_order':
                return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $o WHERE paid = 1 AND email = %s", $row['email'] ) ) <= 1;
            case 'order_over':
                return $order && (float) $order->get_total() >= (float) $step['amount'];
            case 'order_under':
                return $order && (float) $order->get_total() <= (float) $step['amount'];
            case 'order_has_product':
            case 'order_has_category':
                if ( ! $order ) return false;
                $ids = array_map( 'intval', (array) ( $step['ids'] ?? [] ) );
                foreach ( $order->get_items() as $item ) {
                    $product_id = (int) $item->get_product_id();
                    if ( $step['check'] === 'order_has_product' ) {
                        if ( in_array( $product_id, $ids, true ) || in_array( (int) $item->get_variation_id(), $ids, true ) ) return true;
                        continue;
                    }
                    // A product in a sub category counts for its parent categories too.
                    foreach ( wc_get_product_term_ids( $product_id, 'product_cat' ) as $cat ) {
                        if ( array_intersect( array_merge( [ (int) $cat ], array_map( 'intval', get_ancestors( (int) $cat, 'product_cat' ) ) ), $ids ) ) return true;
                    }
                }
                return false;
            case 'coupon_used':
                if ( ! $order ) return false;
                $codes = array_map( 'wc_format_coupon_code', $order->get_coupon_codes() );
                return ( $step['code'] ?? '' ) === '' ? (bool) $codes : in_array( (string) $step['code'], $codes, true );
            case 'shipping_method':
                foreach ( $order ? $order->get_shipping_methods() : [] as $method ) {
                    if ( $method->get_method_id() === ( $step['method'] ?? '' ) ) return true;
                }
                return false;
            case 'country':
                $country = $order ? ( $order->get_shipping_country() ?: $order->get_billing_country() ) : '';
                if ( $country === '' && ( $user = get_user_by( 'email', $email ) ) ) {
                    $country = (string) ( get_user_meta( $user->ID, 'shipping_country', true ) ?: get_user_meta( $user->ID, 'billing_country', true ) );
                }
                return $country !== '' && in_array( strtoupper( $country ), (array) ( $step['countries'] ?? [] ), true );
            case 'orders_count':
                $n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $o WHERE paid = 1 AND email = %s", $email ) );
                return ( $step['op'] ?? 'min' ) === 'max' ? $n <= (int) ( $step['n'] ?? 0 ) : $n >= (int) ( $step['n'] ?? 0 );
            case 'spent_total':
                $spent = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(total - refunded) FROM $o WHERE paid = 1 AND email = %s", $email ) );
                return ( $step['op'] ?? 'min' ) === 'max' ? $spent <= (float) $step['amount'] : $spent >= (float) $step['amount'];
            case 'subscribed':
                $status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Qwoo_Email_Marketing::subs_table() . ' WHERE email = %s', $email ) );
                return ( $status === 'sub' ) === $is;
            case 'has_account':
                return (bool) get_user_by( 'email', $email ) === $is;
            case 'in_audience':
                return (bool) Qwoo_Personalization::audience_for_email( (string) ( $step['audience_id'] ?? '' ), $email ) === $is;
            case 'not_ordered_since':
                $since = wp_date( 'Y-m-d', strtotime( $row['created'] . ' UTC' ), wp_timezone() );
                $found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $o WHERE paid = 1 AND email = %s AND day >= %s AND order_id <> %d", $row['email'], $since, (int) $row['order_id'] ) );
                return $found === 0;
        }
        return true;
    }

    private static function act( array $step, array $flow, array $row ) {
        switch ( $step['type'] ) {
            case 'email':
                if ( ! empty( $step['campaign_id'] ) ) Qwoo_Email_Marketing::send_one( (int) $step['campaign_id'], (string) $row['email'] );
                break;
            case 'push':
                self::push( $step, $row );
                break;
            case 'webhook':
                $order = (int) $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : null;
                wp_safe_remote_post( $step['url'], [
                    'timeout'     => 8,
                    'redirection' => 0,
                    'headers'     => [ 'Content-Type' => 'application/json', 'User-Agent' => 'YallaFlow-Automations/1' ],
                    'body'        => wp_json_encode( [ 'automation' => $flow['name'], 'email' => $row['email'], 'order' => $order ? Qwoo_Webhooks::order_data( $order ) : null ] ),
                ] );
                break;
        }
    }

    /** To the devices of the shopper's account. Guests have no device we can tie to their email. */
    private static function push( array $step, array $row ) {
        global $wpdb;
        if ( ! Qwoo_Push_Engine::allowed() || $step['title'] === '' ) return;
        $user = get_user_by( 'email', (string) $row['email'] );
        if ( ! $user ) return;
        $subs = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Qwoo_Push_Engine::subs_table() . ' WHERE customer_id = %d LIMIT 5', $user->ID ), ARRAY_A );
        $path = (string) wp_parse_url( (string) $step['link'], PHP_URL_PATH );
        $items = [];
        foreach ( $subs as $i => $sub ) {
            $items[] = [ 'row' => $i, 'sub' => $sub, 'msg' => [ 'title' => $step['title'], 'body' => $step['body'], 'url' => $path ?: '/', 'image' => '', 'tag' => 'flow-' . $row['flow_id'], 'lang' => (string) ( $sub['lang'] ?? '' ) ] ];
        }
        if ( $items ) Qwoo_Push_Engine::deliver( $items );
    }

    /** This order's runs that still wait to do something: for the order page. at: when the next step is due. */
    public static function waiting_for_order( int $order_id ): array {
        global $wpdb;
        if ( ! self::allowed() ) return [];
        $flows = array_column( self::flows(), null, 'id' );
        $out   = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, flow_id, step, next_at FROM ' . self::table() . " WHERE order_id = %d AND status = 'wait' ORDER BY next_at LIMIT 20", $order_id ), ARRAY_A ) as $row ) {
            $flow = $flows[ $row['flow_id'] ] ?? null;
            if ( ! $flow || empty( $flow['on'] ) ) continue;
            $next = '';
            foreach ( array_slice( (array) $flow['steps'], (int) $row['step'] ) as $step ) {
                if ( in_array( $step['type'], [ 'email', 'push', 'webhook' ], true ) ) {
                    $next = $step['type'];
                    break;
                }
            }
            if ( $next === '' ) continue;
            $out[] = [ 'key' => 'run' . (int) $row['id'], 'type' => 'flow', 'flow' => (string) $flow['id'], 'name' => (string) $flow['name'], 'next' => $next, 'at' => (int) strtotime( $row['next_at'] . ' UTC' ) ];
        }
        return $out;
    }

    /** The owner stops one waiting run of an order (the order page). */
    public static function stop_run( int $run_id, int $order_id ): void {
        global $wpdb;
        $wpdb->update( self::table(), [ 'status' => 'stopped' ], [ 'id' => $run_id, 'order_id' => $order_id, 'status' => 'wait' ] );
    }

    public static function next_due( $due ) {
        global $wpdb;
        $next = $wpdb->get_var( 'SELECT MIN(next_at) FROM ' . self::table() . " WHERE status = 'wait'" );
        if ( ! $next ) return $due;
        $unix = strtotime( $next . ' UTC' );
        return $due && $due < $unix ? $due : $unix;
    }

    /** A privacy request: nothing more goes to this address. */
    public static function erase_email( string $email ): void {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'email' => strtolower( trim( $email ) ) ] );
    }
}

Qwoo_Automations::init();

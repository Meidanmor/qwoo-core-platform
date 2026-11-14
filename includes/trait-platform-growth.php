<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Dashboard → Marketing and Analytics: one signed action, "growth", with the
 * operations below. Anything else is refused. Each class cleans what it gets.
 */
trait Qwoo_Platform_Growth {

    private static function action_growth( array $params ) {
        $result = self::growth_op( $params );
        // The owner sees the reason: the classes say it in a WP_Error.
        return is_wp_error( $result ) ? self::bad( $result->get_error_message() ) : $result;
    }

    private static function growth_op( array $params ) {
        $op = (string) ( $params['op'] ?? '' );
        $p  = is_array( $params['params'] ?? null ) ? $params['params'] : [];
        $id = (int) ( $p['id'] ?? 0 );
        switch ( $op ) {
            /* What the Marketing overview shows. */
            case 'overview':
                return self::growth_overview( $p );

            /* Shared shopper audiences and first-party signal controls. */
            case 'personalization_get':
                return Qwoo_Personalization::dashboard_state();
            case 'personalization_save':
                return Qwoo_Personalization::save_settings( $p );
            case 'audience_save':
                if ( ! Qwoo_Personalization::plan_allows() ) return new WP_Error( 'plan', 'Audiences are part of the Pro and Plus plans.' );
                return Qwoo_Personalization::save_audience( $p );
            case 'audience_template':
                if ( ! Qwoo_Personalization::plan_allows() ) return new WP_Error( 'plan', 'Audiences are part of the Pro and Plus plans.' );
                return Qwoo_Personalization::add_template( sanitize_key( (string) ( $p['template'] ?? '' ) ), (string) ( $p['name'] ?? '' ) );
            case 'audience_delete':
                return Qwoo_Personalization::delete_audience( sanitize_key( (string) ( $p['id'] ?? '' ) ) );
            case 'audience_count':
                return Qwoo_Personalization::audience_count( sanitize_key( (string) ( $p['id'] ?? '' ) ) );
            case 'audiences_overview':
                return Qwoo_Personalization::overview( $p );
            case 'audience_test':
                return Qwoo_Personalization::preview_size( $p );
            case 'audience_detail':
                return Qwoo_Personalization::detail( sanitize_key( (string) ( $p['id'] ?? '' ) ) );
            case 'personalization_clear':
                Qwoo_Personalization::clear_all();
                return [ 'ok' => true ];

            /* Analytics → Events: which tracking tools hear each shop event (Qwoo_Events). */
            case 'events_get':
                return Qwoo_Events::state();
            case 'events_save':
                return Qwoo_Events::save( $p );
            case 'event_save':
                return Qwoo_Events::save_own( $p );
            case 'event_delete':
                return Qwoo_Events::delete_own( (string) ( $p['id'] ?? '' ) );
            case 'event_detail':
                return Qwoo_Events::detail( (string) ( $p['id'] ?? '' ) );

            /* Customers → Inbox: shoppers' messages (Qwoo_Inbox). */
            case 'inbox_list':
                return Qwoo_Inbox::listing( $p );
            case 'inbox_count':
                return Qwoo_Inbox::counts();
            case 'inbox_get':
                return Qwoo_Inbox::get( $id );
            case 'inbox_reply':
                return Qwoo_Inbox::reply( $p );
            case 'inbox_note':
                return Qwoo_Inbox::note( $p );
            case 'inbox_status':
                return Qwoo_Inbox::set_status( $p );
            case 'inbox_settings':
                return Qwoo_Inbox::settings();
            case 'inbox_settings_save':
                return Qwoo_Inbox::save_settings( $p );

            /* Appointments: services, hours, bookings (Qwoo_Appointments). */
            case 'appt_state':
            case 'appt_settings_save':
            case 'appt_feed_full':
            case 'appt_service_save':
            case 'appt_service_delete':
            case 'appt_services_sort':
            case 'appt_list':
            case 'appt_get':
            case 'appt_times':
            case 'appt_create':
            case 'appt_update':
            // From the platform's calendar sync (QP_Calendars): connected or not, and Google's change notices.
            case 'appt_cal_link':
            case 'appt_cal_changed':
                return Qwoo_Appointments::dashboard( $op, $p );

            /* Subscribers */
            case 'subscribers':
                return Qwoo_Email_Marketing::subscribers( $p ) + [ 'counts' => Qwoo_Email_Marketing::counts() ];
            case 'subscriber_add':
                $added = Qwoo_Email_Marketing::subscribe( (string) ( $p['email'] ?? '' ), sanitize_text_field( (string) ( $p['name'] ?? '' ) ), 'owner', 'Added by the store owner, who confirmed consent', '', false );
                return [ 'result' => $added ];
            case 'subscribers_import':
                return Qwoo_Email_Marketing::import( $p );
            case 'subscriber_unsubscribe':
                Qwoo_Email_Marketing::suppress( (string) ( $p['email'] ?? '' ), 'unsub' );
                return [ 'ok' => true ];
            case 'subscriber_erase':
                Qwoo_Email_Marketing::erase_email( (string) ( $p['email'] ?? '' ) );
                Qwoo_Automations::erase_email( (string) ( $p['email'] ?? '' ) );
                return [ 'ok' => true ];
            case 'email_settings':
                return ! empty( $p['save'] ) ? Qwoo_Email_Marketing::save_settings( $p ) : Qwoo_Email_Marketing::settings();
            /* Gift cards (Qwoo_Gift_Cards). */
            case 'gc_list':
            case 'gc_get':
            case 'gc_issue':
            case 'gc_update':
            case 'gc_preview':
                return Qwoo_Gift_Cards::dashboard( $op, $p, 'owner' );
            /* From the platform: a bounce or a complaint (or a gift card email's delivery: ref gc-<id>). */
            case 'email_event':
                if ( preg_match( '/^gc-\d+$/', (string) ( $p['ref'] ?? '' ) ) ) {
                    Qwoo_Gift_Cards::email_event( (string) $p['ref'], (string) ( $p['type'] ?? '' ), (string) ( $p['email'] ?? '' ) );
                    return [ 'ok' => true ];
                }
                $status = [ 'bounced' => 'bounced', 'complained' => 'complained' ][ $p['type'] ?? '' ] ?? '';
                if ( $status !== '' ) Qwoo_Email_Marketing::suppress( (string) ( $p['email'] ?? '' ), $status );
                return [ 'ok' => true ];

            /* Emails */
            case 'campaigns':
                return [ 'campaigns' => Qwoo_Email_Marketing::campaigns(), 'last_error' => Qwoo_Email_Marketing::last_error() ];
            case 'campaign_get':
                $row = $id ? Qwoo_Email_Marketing::campaign_row( $id ) : null;
                if ( ! $row ) return $id ? new WP_Error( 'missing', 'That email doesn\'t exist any more.' ) : [ 'design' => Qwoo_Email_Marketing::starter_design(), 'segment' => 'all', 'segment_days' => 90, 'status' => 'draft', 'name' => '', 'subject' => '', 'preheader' => '', 'store_problems' => Qwoo_Email_Marketing::store_problems() ];
                // Why it can't go out (before sending), and why sending stopped (paused).
                return $row + [ 'stats' => Qwoo_Email_Marketing::campaign_stats( $id ), 'store_problems' => Qwoo_Email_Marketing::store_problems(), 'last_error' => $row['status'] === 'paused' ? Qwoo_Email_Marketing::last_error() : '' ];
            case 'campaign_save':
                return Qwoo_Email_Marketing::save_campaign( $p, ( $p['kind'] ?? '' ) === 'flow' ? 'flow' : 'campaign' );
            case 'campaign_delete':
                Qwoo_Email_Marketing::delete_campaign( $id );
                return [ 'ok' => true ];
            case 'campaign_duplicate':
                return Qwoo_Email_Marketing::duplicate_campaign( $id );
            case 'email_products':
                return Qwoo_Email_Render::preview_products( $p );
            case 'campaign_preview':
                return [ 'html' => Qwoo_Email_Marketing::preview_html( $p ) ];
            case 'campaign_audience':
                $segment = in_array( $p['segment'] ?? '', Qwoo_Email_Marketing::SEGMENTS, true ) ? $p['segment'] : 'all';
                if ( $segment === 'saved_audience' ) return Qwoo_Personalization::audience_count( sanitize_key( (string) ( $p['audience_id'] ?? '' ) ) );
                return [ 'count' => Qwoo_Email_Marketing::audience( $segment, max( 7, (int) ( $p['segment_days'] ?? 90 ) ) ), 'sampled' => false ];
            case 'campaign_test':
                $row = $id ? Qwoo_Email_Marketing::campaign_row( $id ) : null;
                return $row ? Qwoo_Email_Marketing::send_test( $row, (string) ( $p['to'] ?? '' ) ) : new WP_Error( 'missing', 'Save the email first.' );
            case 'campaign_send':
                return Qwoo_Email_Marketing::start( $id, (int) ( $p['send_at'] ?? 0 ) );
            case 'campaign_cancel':
                Qwoo_Email_Marketing::cancel( $id );
                return [ 'ok' => true ];

            /* The store's automatic emails */
            case 'store_emails':
                return Qwoo_Store_Emails::overview();
            case 'store_email_get':
                return Qwoo_Store_Emails::get( (string) ( $p['id'] ?? '' ) );
            case 'store_email_save':
                return Qwoo_Store_Emails::save_settings( $p );
            case 'store_email_design':
                return Qwoo_Store_Emails::save_design( $p );
            case 'store_email_reset':
                return Qwoo_Store_Emails::reset_design( (string) ( $p['id'] ?? '' ) );
            case 'store_email_test':
                return Qwoo_Store_Emails::test( $p );
            case 'store_email_style':
                return [ 'style' => Qwoo_Store_Emails::save_style( $p ) ];
            case 'email_saved':
                return [ 'blocks' => Qwoo_Store_Emails::saved_blocks() ];
            case 'email_saved_add':
                return Qwoo_Store_Emails::save_block( $p );
            case 'email_saved_del':
                return Qwoo_Store_Emails::delete_block( sanitize_key( (string) ( $p['id'] ?? '' ) ) );
            case 'store_email_sender':
                return Qwoo_Store_Emails::save_sender( $p );

            /* Automations */
            case 'flow_test':
                return Qwoo_Automations::test( sanitize_key( (string) ( $p['id'] ?? '' ) ), (string) ( $p['to'] ?? '' ) );
            case 'flows':
                return [ 'flows' => Qwoo_Automations::overview(), 'allowed' => Qwoo_Automations::allowed(), 'statuses' => array_keys( wc_get_order_statuses() ), 'audiences' => Qwoo_Personalization::audiences(), 'categories' => Qwoo_Personalization::categories() ] + Qwoo_Automations::rule_choices();
            case 'flow_save':
                return Qwoo_Automations::save( $p );
            case 'flow_delete':
                return [ 'flows' => Qwoo_Automations::remove( sanitize_key( (string) ( $p['id'] ?? '' ) ) ) ];

            /* Webhooks */
            case 'webhooks':
                return Qwoo_Webhooks::public_list();
            case 'webhook_save':
                return Qwoo_Webhooks::save( $p );
            case 'webhook_delete':
                return Qwoo_Webhooks::remove( sanitize_key( (string) ( $p['id'] ?? '' ) ) );
            case 'webhook_secret':
                return Qwoo_Webhooks::new_secret( sanitize_key( (string) ( $p['id'] ?? '' ) ) );
            case 'webhook_test':
                return Qwoo_Webhooks::test( sanitize_key( (string) ( $p['id'] ?? '' ) ) );

            /* Product feed */
            case 'feed':
                return [ 'url' => isset( $p['on'] ) ? Qwoo_Product_Feed::set( ! empty( $p['on'] ) ) : Qwoo_Product_Feed::url() ];

            /* Tracked links */
            case 'tracked_links':
                return [ 'links' => self::tracked_links(), 'results' => self::tracked_results(), 'currency' => get_woocommerce_currency() ];
            case 'tracked_links_add':
                return self::tracked_links_add( (array) ( $p['links'] ?? [] ) );
            case 'tracked_link_delete':
                $gone = sanitize_key( (string) ( $p['id'] ?? '' ) );
                $list = array_values( array_filter( self::tracked_links(), static fn( $l ) => $l['id'] !== $gone ) );
                update_option( 'qwoo_tracked_links', $list, false );
                return [ 'links' => $list ];

            /* Reads the API keeps to itself. */
            case 'subscriber_api_add':
                if ( empty( $p['consent'] ) ) return new WP_Error( 'consent', '"consent": true is required: the person must have agreed.' );
                return [ 'result' => Qwoo_Email_Marketing::subscribe( (string) ( $p['email'] ?? '' ), sanitize_text_field( (string) ( $p['name'] ?? '' ) ), 'api', mb_substr( sanitize_text_field( (string) ( $p['consent_text'] ?? 'Sent through the API' ) ), 0, 200 ), '', false ) ];
        }
        return new WP_Error( 'unknown', 'Unknown action.' );
    }

    private static function tracked_links(): array {
        $list = get_option( 'qwoo_tracked_links', [] );
        return is_array( $list ) ? array_values( $list ) : [];
    }

    /** Paid orders and net sales of the last 30 days per source / medium / campaign, for the tracked links list. */
    private static function tracked_results(): array {
        global $wpdb;
        if ( ! class_exists( 'Qwoo_Analytics' ) ) return [];
        $o    = Qwoo_Analytics::orders_table();
        $from = wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS, wp_timezone() );
        $old  = $wpdb->suppress_errors( true );
        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT source, medium, campaign, COUNT(*) AS n, COALESCE(SUM(total - refunded),0) AS net FROM $o WHERE paid = 1 AND day >= %s AND source <> '' GROUP BY source, medium, campaign ORDER BY net DESC LIMIT 300", $from ), ARRAY_A );
        $wpdb->suppress_errors( $old );
        return array_map( static fn( $r ) => [ 'source' => (string) $r['source'], 'medium' => (string) $r['medium'], 'campaign' => (string) $r['campaign'], 'orders' => (int) $r['n'], 'sales' => round( (float) $r['net'], 2 ) ], $rows );
    }

    /** One row per platform, newest first. The same address isn't kept twice. */
    private static function tracked_links_add( array $rows ) {
        $list = self::tracked_links();
        $seen = array_column( $list, 'url' );
        $new  = [];
        foreach ( array_slice( $rows, 0, 20 ) as $row ) {
            $url    = esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ), [ 'https', 'http' ] );
            $source = mb_substr( sanitize_text_field( (string) ( $row['source'] ?? '' ) ), 0, 60 );
            $medium = mb_substr( sanitize_text_field( (string) ( $row['medium'] ?? '' ) ), 0, 60 );
            if ( $url === '' || $source === '' || $medium === '' || in_array( $url, $seen, true ) ) continue;
            $seen[] = $url;
            $new[]  = [
                'id'       => 'tl' . bin2hex( random_bytes( 5 ) ),
                'url'      => $url,
                'source'   => $source,
                'medium'   => $medium,
                'campaign' => mb_substr( sanitize_text_field( (string) ( $row['campaign'] ?? '' ) ), 0, 120 ),
                'created'  => gmdate( 'Y-m-d H:i:s' ),
            ];
        }
        if ( count( $list ) + count( $new ) > 200 ) return new WP_Error( 'max', 'A store can keep up to 200 tracked links. Delete some first.' );
        $list = array_merge( $new, $list );
        update_option( 'qwoo_tracked_links', $list, false );
        return [ 'links' => $list ];
    }

    private static function growth_overview( array $p ): array {
        $campaigns = Qwoo_Email_Marketing::campaigns();
        $totals    = [ 'sent' => 0, 'opened' => 0, 'clicked' => 0, 'orders' => 0, 'sales' => 0.0, 'failed' => 0 ];
        foreach ( $campaigns as $c ) {
            foreach ( $totals as $k => $v ) $totals[ $k ] = $v + $c['stats'][ $k ];
        }
        $flows = Qwoo_Automations::overview();
        foreach ( $flows as $f ) {
            foreach ( [ 'sent', 'opened', 'clicked', 'orders', 'sales' ] as $k ) $totals[ $k ] += $f['results'][ $k ];
        }
        return [
            'currency'   => get_woocommerce_currency(),
            'counts'     => Qwoo_Email_Marketing::counts(),
            'email'      => $totals,
            'campaigns'  => array_slice( $campaigns, 0, 5 ),
            'flows'      => $flows,
            'push'       => Qwoo_Push_Engine::overview(),
            'free_plan'  => self::is_free_plan(),
            'address_ok' => trim( (string) get_option( 'woocommerce_store_address' ) ) !== '',
            'last_error' => Qwoo_Email_Marketing::last_error(),
        ];
    }
}

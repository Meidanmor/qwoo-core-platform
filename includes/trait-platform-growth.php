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
            /* From the platform: a bounce or a complaint. */
            case 'email_event':
                $status = [ 'bounced' => 'bounced', 'complained' => 'complained' ][ $p['type'] ?? '' ] ?? '';
                if ( $status !== '' ) Qwoo_Email_Marketing::suppress( (string) ( $p['email'] ?? '' ), $status );
                return [ 'ok' => true ];

            /* Emails */
            case 'campaigns':
                return [ 'campaigns' => Qwoo_Email_Marketing::campaigns(), 'last_error' => Qwoo_Email_Marketing::last_error() ];
            case 'campaign_get':
                $row = $id ? Qwoo_Email_Marketing::campaign_row( $id ) : null;
                if ( ! $row ) return $id ? new WP_Error( 'missing', 'That email doesn\'t exist any more.' ) : [ 'design' => Qwoo_Email_Marketing::starter_design(), 'segment' => 'all', 'segment_days' => 90, 'status' => 'draft', 'name' => '', 'subject' => '', 'preheader' => '' ];
                return $row + [ 'stats' => Qwoo_Email_Marketing::campaign_stats( $id ) ];
            case 'campaign_save':
                return Qwoo_Email_Marketing::save_campaign( $p, ( $p['kind'] ?? '' ) === 'flow' ? 'flow' : 'campaign' );
            case 'campaign_delete':
                Qwoo_Email_Marketing::delete_campaign( $id );
                return [ 'ok' => true ];
            case 'campaign_duplicate':
                return Qwoo_Email_Marketing::duplicate_campaign( $id );
            case 'campaign_preview':
                return [ 'html' => Qwoo_Email_Marketing::preview_html( $p ) ];
            case 'campaign_audience':
                return [ 'count' => Qwoo_Email_Marketing::audience( in_array( $p['segment'] ?? '', Qwoo_Email_Marketing::SEGMENTS, true ) ? $p['segment'] : 'all', max( 7, (int) ( $p['segment_days'] ?? 90 ) ) ) ];
            case 'campaign_test':
                $row = $id ? Qwoo_Email_Marketing::campaign_row( $id ) : null;
                return $row ? Qwoo_Email_Marketing::send_test( $row, (string) ( $p['to'] ?? '' ) ) : new WP_Error( 'missing', 'Save the email first.' );
            case 'campaign_send':
                return Qwoo_Email_Marketing::start( $id, (int) ( $p['send_at'] ?? 0 ) );
            case 'campaign_cancel':
                Qwoo_Email_Marketing::cancel( $id );
                return [ 'ok' => true ];

            /* Automations */
            case 'flows':
                return [ 'flows' => Qwoo_Automations::overview(), 'allowed' => Qwoo_Automations::allowed(), 'statuses' => array_keys( wc_get_order_statuses() ) ];
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
            case 'webhook_test':
                return Qwoo_Webhooks::test( sanitize_key( (string) ( $p['id'] ?? '' ) ) );

            /* Product feed */
            case 'feed':
                return [ 'url' => isset( $p['on'] ) ? Qwoo_Product_Feed::set( ! empty( $p['on'] ) ) : Qwoo_Product_Feed::url() ];

            /* Tracked links */
            case 'tracked_links':
                return [ 'links' => self::tracked_links() ];
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

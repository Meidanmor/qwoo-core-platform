<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Events (dashboard: Analytics → Events).
 *
 * Shop events: for each event the storefront sends by itself (page viewed,
 * product viewed, added to cart, started checkout, order placed), the owner
 * chooses which connected tracking tools hear it. Everything is on until the
 * owner turns a tool off for an event.
 *
 * Own events: the owner names an event and chooses what starts it (a click on
 * a block picked in the Store builder, a click on a kind of link, a page that
 * was opened, a form that was sent, scrolling down, staying on a page), how
 * it is counted, and the tools it is also sent to, each with its own name.
 * No code or selector is ever typed: a start is one of the choices below, and
 * "contains" texts are compared as plain text.
 *
 *   option qwoo_events  { off: { event: [ tool, … ] }, own: [ event, … ] }
 *
 * The storefront gets both with the tracking settings
 * (Qwoo_Custom_Code::storefront_payload → events_off, events; aura:
 * src/utils/custom-code.js and src/utils/events.js). Cookie choices still come
 * first: a tool never hears from a shopper who didn't accept its kind of
 * cookie. Our own counting goes through Audiences (Qwoo_Personalization:
 * daily totals "ev" / "evu" / "evp", and the visitor's `ev` signal), so it
 * needs visitor numbers on and analytics cookies; sending to tools doesn't.
 */
class Qwoo_Events {

    const OPTION  = 'qwoo_events';
    const MAX_OWN = 30;
    const ID_RE   = '/^ev_[a-z0-9]{8,12}$/';

    /** The shop events the storefront sends, in the order of a visit. */
    const SHOP = [
        'page_view'      => 'Page viewed',
        'view_item'      => 'Product viewed',
        'add_to_cart'    => 'Added to cart',
        'begin_checkout' => 'Started checkout',
        'purchase'       => 'Order placed',
    ];

    /** Tools an event can go to: the tracking shortcuts, and the owner's own code snippets (qwoo.on). */
    const TOOLS = [ 'ga4', 'gtm', 'meta', 'tiktok', 'clarity', 'code' ];

    /** What a tool can't take: Meta and TikTok have no event for these. */
    const NEVER = [
        'tiktok'  => [],
        'clarity' => [ 'page_view' ], // Clarity records pages by itself
    ];

    /** What can start an own event. */
    const STARTS     = [ 'block', 'link', 'page', 'form', 'scroll', 'time' ];
    const LINK_KINDS = [ 'phone', 'whatsapp', 'email', 'outside', 'download', 'contains' ];
    const PAGE_KINDS = [ 'home', 'product', 'category', 'shop', 'cart', 'checkout', 'thanks', 'search', 'blog', 'account', 'path', 'contains' ];
    const PERCENTS   = [ 25, 50, 75, 90 ];

    /** The tools' own standard events (anything else is sent as a custom event). */
    const META_STANDARD   = [ 'Lead', 'Contact', 'Subscribe', 'ViewContent', 'Search', 'CompleteRegistration', 'Schedule', 'FindLocation', 'CustomizeProduct', 'AddToWishlist', 'SubmitApplication' ];
    const TIKTOK_STANDARD = [ 'ClickButton', 'Contact', 'SubmitForm', 'Download', 'Subscribe', 'ViewContent', 'Search', 'CompleteRegistration', 'AddToWishlist' ];

    private static $own = null;

    /* ---------------- reading ---------------- */

    private static function saved(): array {
        $saved = get_option( self::OPTION, [] );
        return is_array( $saved ) ? $saved : [];
    }

    private static function off(): array {
        $saved = self::saved();
        $off   = is_array( $saved['off'] ?? null ) ? $saved['off'] : [];
        $out   = [];
        foreach ( self::SHOP as $event => $label ) {
            $tools = array_values( array_intersect( self::TOOLS, array_map( 'strval', (array) ( $off[ $event ] ?? [] ) ) ) );
            if ( $tools ) $out[ $event ] = $tools;
        }
        return $out;
    }

    /** The owner's own events, as saved (already cleaned). */
    public static function own(): array {
        if ( self::$own !== null ) return self::$own;
        $saved = self::saved();
        $list  = [];
        foreach ( (array) ( $saved['own'] ?? [] ) as $e ) {
            if ( is_array( $e ) && preg_match( self::ID_RE, (string) ( $e['id'] ?? '' ) ) ) $list[] = $e;
        }
        return self::$own = array_slice( $list, 0, self::MAX_OWN );
    }

    public static function exists( string $id ): bool {
        if ( ! preg_match( self::ID_RE, $id ) ) return false;
        foreach ( self::own() as $e ) if ( $e['id'] === $id ) return true;
        return false;
    }

    public static function find( string $id ): ?array {
        foreach ( self::own() as $e ) if ( $e['id'] === $id ) return $e;
        return null;
    }

    /** [ id => name ] for pickers (automations, audiences, the Store builder). */
    public static function names(): array {
        return array_column( self::own(), 'name', 'id' );
    }

    /** For the storefront: { event: [ tools it isn't sent to ] }. */
    public static function payload(): array {
        return self::off();
    }

    /**
     * For the storefront: the own events, short. Only what the browser needs:
     * the start, how to count, the value, and per tool that is on, the name to
     * send (true for tools without names).
     */
    public static function own_payload(): array {
        $out = [];
        foreach ( self::own() as $e ) {
            $tools = [];
            foreach ( self::TOOLS as $tool ) {
                $t = $e['tools'][ $tool ] ?? [];
                if ( empty( $t['on'] ) ) continue;
                $tools[ $tool ] = isset( $t['name'] ) && $t['name'] !== '' ? (string) $t['name'] : true;
            }
            $out[] = [ 'id' => $e['id'], 'key' => (string) ( $e['tools']['ga4']['name'] ?? 'event' ), 'start' => $e['start'], 'every' => ( $e['count'] ?? 'visit' ) === 'every', 'value' => (float) ( $e['value'] ?? 0 ), 'tools' => (object) $tools ];
        }
        return $out;
    }

    private static function connected(): array {
        $code      = class_exists( 'Qwoo_Custom_Code' ) ? Qwoo_Custom_Code::storefront_payload() : [];
        $connected = array_fill_keys( self::TOOLS, false );
        foreach ( array_keys( (array) ( $code['shortcuts'] ?? [] ) ) as $key ) {
            if ( isset( $connected[ $key ] ) ) $connected[ $key ] = true;
        }
        foreach ( (array) ( $code['snippets'] ?? [] ) as $snippet ) {
            if ( ( $snippet['kind'] ?? '' ) === 'snippet' ) $connected['code'] = true;
        }
        return $connected;
    }

    /** For the dashboard: the events with each tool on / off, the own events with their numbers, and which tools the store has connected. */
    public static function state(): array {
        $off    = self::off();
        $events = [];
        foreach ( self::SHOP as $event => $label ) {
            $tools = [];
            foreach ( self::TOOLS as $tool ) {
                if ( in_array( $event, self::NEVER[ $tool ] ?? [], true ) ) continue;
                $tools[ $tool ] = ! in_array( $tool, $off[ $event ] ?? [], true );
            }
            $events[] = [ 'id' => $event, 'name' => $label, 'tools' => $tools ];
        }
        $counting = class_exists( 'Qwoo_Personalization' ) && Qwoo_Personalization::active();
        return [
            'events'    => $events,
            'connected' => self::connected(),
            'own'       => self::own(),
            'numbers'   => $counting ? (object) self::numbers() : (object) [],
            'used'      => (object) self::where_used(),
            'counting'  => $counting,
            'can_add'   => ! class_exists( 'Qwoo_Personalization' ) || Qwoo_Personalization::plan_allows(),
            'max'       => self::MAX_OWN,
            'meta'      => self::META_STANDARD,
            'tiktok'    => self::TIKTOK_STANDARD,
        ];
    }

    /* ---------------- numbers ---------------- */

    /** { id: { 7: [times, visitors], 30: …, 90: …, trend: [14 days of times] } }. */
    private static function numbers(): array {
        global $wpdb;
        $ids = array_column( self::own(), 'id' );
        if ( ! $ids ) return [];
        $t   = Qwoo_Personalization::stats_table();
        $out = [];
        foreach ( $ids as $id ) $out[ $id ] = [ '7' => [ 0, 0 ], '30' => [ 0, 0 ], '90' => [ 0, 0 ], 'trend' => array_fill( 0, 14, 0 ) ];
        $today = wp_date( 'Y-m-d' );
        $from  = wp_date( 'Y-m-d', time() - 89 * DAY_IN_SECONDS );
        $rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, metric, item, n FROM $t WHERE metric IN ('ev','evu') AND day >= %s AND day <= %s", $from, $today ), ARRAY_A );
        $now   = strtotime( $today . ' 00:00:00 UTC' );
        foreach ( $rows as $r ) {
            $id = (string) $r['item'];
            if ( ! isset( $out[ $id ] ) ) continue;
            $age  = (int) round( ( $now - strtotime( $r['day'] . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
            $slot = $r['metric'] === 'ev' ? 0 : 1;
            foreach ( [ 7, 30, 90 ] as $span ) if ( $age < $span ) $out[ $id ][ (string) $span ][ $slot ] += (int) $r['n'];
            if ( $slot === 0 && $age >= 0 && $age < 14 ) $out[ $id ]['trend'][ 13 - $age ] += (int) $r['n'];
        }
        return $out;
    }

    /** One own event: a chart by day, the pages it happened on, and how many of those visitors ordered. */
    public static function detail( string $id ) {
        global $wpdb;
        $event = self::find( $id );
        if ( ! $event ) return new WP_Error( 'missing', 'This event was deleted.' );
        if ( ! class_exists( 'Qwoo_Personalization' ) || ! Qwoo_Personalization::active() ) return [ 'event' => $event, 'counting' => false, 'used' => self::where_used()[ $id ] ?? [] ];
        $t     = Qwoo_Personalization::stats_table();
        $from  = wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
        $days  = [];
        for ( $i = 29; $i >= 0; $i-- ) $days[ wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = [ 0, 0 ];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, metric, n FROM $t WHERE metric IN ('ev','evu') AND item = %s AND day >= %s", $id, $from ), ARRAY_A ) as $r ) {
            if ( isset( $days[ $r['day'] ] ) ) $days[ $r['day'] ][ $r['metric'] === 'ev' ? 0 : 1 ] += (int) $r['n'];
        }
        $chart = [];
        foreach ( $days as $day => $n ) $chart[] = [ 'day' => $day, 'times' => $n[0], 'visitors' => $n[1] ];
        $pages = [];
        $like  = $wpdb->esc_like( $id . '|' ) . '%';
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT item, SUM(n) AS n FROM $t WHERE metric = 'evp' AND item LIKE %s AND day >= %s GROUP BY item ORDER BY n DESC LIMIT 8", $like, $from ), ARRAY_A ) as $r ) {
            $pages[] = [ 'path' => substr( (string) $r['item'], strlen( $id ) + 1 ), 'times' => (int) $r['n'] ];
        }
        $did    = Qwoo_Personalization::preview_size( [ 'rules' => [ [ 'type' => 'did_event', 'event_id' => $id, 'min' => 1, 'days' => 90 ] ] ] );
        $bought = Qwoo_Personalization::preview_size( [ 'rules' => [ [ 'type' => 'did_event', 'event_id' => $id, 'min' => 1, 'days' => 90 ], [ 'type' => 'has_orders' ] ] ] );
        return [
            'event'    => $event,
            'counting' => true,
            'chart'    => $chart,
            'pages'    => $pages,
            'people'   => (int) $did['people'],
            'ordered'  => (int) $bought['people'],
            'used'     => self::where_used()[ $id ] ?? [],
        ];
    }

    /**
     * Where each own event is used: [ id => [ [ kind, label, ref ] ] ] over the
     * Store builder's sections and blocks ("Count clicks as an event"),
     * automations and audiences.
     */
    public static function where_used(): array {
        $out = [];
        $add = static function ( $id, $kind, $label, $ref = '' ) use ( &$out ) {
            $id = (string) $id;
            if ( ! preg_match( self::ID_RE, $id ) ) return;
            foreach ( $out[ $id ] ?? [] as $u ) if ( $u['kind'] === $kind && $u['ref'] === $ref ) return;
            if ( count( $out[ $id ] ?? [] ) >= 20 ) return;
            $out[ $id ][] = [ 'kind' => $kind, 'label' => mb_substr( (string) $label, 0, 80 ), 'ref' => (string) $ref ];
        };
        $all = get_option( 'shop_builder_options', [] );
        if ( is_array( $all ) ) {
            foreach ( $all as $page => $value ) {
                if ( in_array( $page, [ 'languages', 'translations' ], true ) || ! is_array( $value ) ) continue;
                if ( $page === 'popups' ) {
                    foreach ( $value as $pp ) if ( is_array( $pp ) ) self::walk_used( (array) ( $pp['sections'] ?? [] ), 'popup', (string) ( $pp['name'] ?? '' ), (string) ( $pp['id'] ?? '' ), $add );
                    continue;
                }
                if ( $page === 'custom_pages' ) {
                    foreach ( $value as $cp ) if ( is_array( $cp ) ) self::walk_used( (array) ( $cp['sections'] ?? [] ), 'page', (string) ( $cp['title'] ?? '' ), (string) ( $cp['id'] ?? '' ), $add );
                    continue;
                }
                self::walk_used( $value, 'page', (string) $page, (string) $page, $add );
            }
        }
        foreach ( (array) get_option( 'qwoo_flows', [] ) as $flow ) {
            if ( ! is_array( $flow ) ) continue;
            if ( ! empty( $flow['trigger']['event_id'] ) ) $add( $flow['trigger']['event_id'], 'automation', $flow['name'] ?? '', $flow['id'] ?? '' );
            foreach ( (array) ( $flow['rules'] ?? [] ) as $rule ) if ( is_array( $rule ) && ! empty( $rule['event_id'] ) ) $add( $rule['event_id'], 'automation', $flow['name'] ?? '', $flow['id'] ?? '' );
        }
        if ( class_exists( 'Qwoo_Personalization' ) ) {
            foreach ( Qwoo_Personalization::audiences() as $aud ) {
                foreach ( (array) ( $aud['rules'] ?? [] ) as $rule ) if ( ( $rule['type'] ?? '' ) === 'did_event' ) $add( $rule['event_id'] ?? '', 'audience', $aud['name'] ?? '', $aud['id'] ?? '' );
            }
        }
        return $out;
    }

    private static function walk_used( array $nodes, string $kind, string $label, string $ref, callable $add, int $depth = 0 ): void {
        if ( $depth > 14 ) return;
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) continue;
            $style = is_array( $node['style'] ?? null ) ? $node['style'] : [];
            if ( ! empty( $style['click_event'] ) && is_string( $style['click_event'] ) ) $add( $style['click_event'], $kind, $label, $ref );
            foreach ( $node as $child ) if ( is_array( $child ) ) self::walk_used( $child, $kind, $label, $ref, $add, $depth + 1 );
        }
    }

    /* ---------------- saving ---------------- */

    private static function write( array $off, array $own ): void {
        update_option( self::OPTION, [ 'off' => $off, 'own' => array_values( $own ) ], false );
        self::$own = null;
        // The storefront reads its tracking settings again.
        if ( class_exists( 'Qwoo_Custom_Code' ) ) Qwoo_Custom_Code::touch();
    }

    /** { tools: { event: { tool: bool } } }: only what is sent changes. */
    public static function save( array $in ): array {
        $off = self::off();
        foreach ( (array) ( $in['tools'] ?? [] ) as $event => $tools ) {
            if ( ! isset( self::SHOP[ $event ] ) || ! is_array( $tools ) ) continue;
            $list = $off[ $event ] ?? [];
            foreach ( $tools as $tool => $on ) {
                if ( ! in_array( $tool, self::TOOLS, true ) ) continue;
                $list = array_values( array_diff( $list, [ $tool ] ) );
                if ( empty( $on ) ) $list[] = $tool;
            }
            if ( $list ) $off[ $event ] = $list;
            else unset( $off[ $event ] );
        }
        self::write( $off, self::own() );
        return self::state();
    }

    /** Plain text an owner typed (an address part to look for): never markup, never a pattern. */
    private static function plain( $raw, int $max = 80 ): string {
        return mb_substr( trim( sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' ) ), 0, $max );
    }

    /** A name a tool accepts: letters, digits and _ (Google: lower case, starts with a letter). */
    private static function tool_name( $raw, string $fallback, bool $lower ): string {
        $name = preg_replace( '/[^A-Za-z0-9_]+/', '_', remove_accents( is_scalar( $raw ) ? (string) $raw : '' ) );
        $name = trim( (string) $name, '_' );
        if ( $lower ) $name = strtolower( $name );
        if ( $name === '' ) $name = $fallback;
        elseif ( ! preg_match( '/^[A-Za-z]/', $name ) ) $name = 'e_' . $name;
        return substr( $name, 0, 40 );
    }

    private static function clean_start( $raw ) {
        $raw  = is_array( $raw ) ? $raw : [];
        $type = (string) ( $raw['type'] ?? '' );
        if ( ! in_array( $type, self::STARTS, true ) ) return new WP_Error( 'start', 'Choose what starts this event.' );
        $start = [ 'type' => $type ];
        switch ( $type ) {
            case 'link':
                $kind = (string) ( $raw['kind'] ?? '' );
                if ( ! in_array( $kind, self::LINK_KINDS, true ) ) return new WP_Error( 'start', 'Choose the kind of link.' );
                $start['kind'] = $kind;
                if ( $kind === 'contains' ) {
                    $start['text'] = self::plain( $raw['text'] ?? '' );
                    if ( $start['text'] === '' ) return new WP_Error( 'start', 'Type the text the link\'s address contains.' );
                }
                break;
            case 'page':
                $kind = (string) ( $raw['kind'] ?? '' );
                if ( ! in_array( $kind, self::PAGE_KINDS, true ) ) return new WP_Error( 'start', 'Choose the page.' );
                $start['kind'] = $kind;
                if ( in_array( $kind, [ 'path', 'contains' ], true ) ) {
                    $text = self::plain( $raw['text'] ?? '', 110 );
                    if ( $text === '' ) return new WP_Error( 'start', 'Type the page\'s address.' );
                    if ( $kind === 'path' ) {
                        // An address someone pasted: only its path is kept.
                        $path = (string) wp_parse_url( preg_match( '#^https?://#i', $text ) ? $text : '/' . ltrim( $text, '/' ), PHP_URL_PATH );
                        $text = '/' . trim( $path, '/' );
                    }
                    $start['text'] = $text;
                }
                break;
            case 'scroll':
            case 'time':
                if ( $type === 'scroll' ) {
                    $percent = (int) ( $raw['percent'] ?? 50 );
                    $start['percent'] = in_array( $percent, self::PERCENTS, true ) ? $percent : 50;
                } else {
                    $start['seconds'] = min( 1800, max( 5, (int) ( $raw['seconds'] ?? 30 ) ) );
                }
                // On every page, or pages whose address contains a text.
                $text = self::plain( $raw['on_text'] ?? '' );
                $start['on'] = ( $raw['on'] ?? '' ) === 'contains' && $text !== '' ? 'contains' : 'all';
                if ( $start['on'] === 'contains' ) $start['on_text'] = $text;
                break;
        }
        return $start;
    }

    /**
     * Adds or changes an own event. A field that isn't sent keeps its saved
     * value (so a caller that only renames an event drops nothing).
     */
    public static function save_own( array $in ) {
        if ( class_exists( 'Qwoo_Personalization' ) && ! Qwoo_Personalization::plan_allows() ) return new WP_Error( 'plan', 'Your own events are part of the Pro and Plus plans.' );
        $own = self::own();
        $id  = (string) ( $in['id'] ?? '' );
        $at  = null;
        foreach ( $own as $i => $e ) if ( $e['id'] === $id ) $at = $i;
        $old = $at === null ? [] : $own[ $at ];
        if ( $id !== '' && $at === null ) return new WP_Error( 'missing', 'This event was deleted.' );
        if ( $at === null && count( $own ) >= self::MAX_OWN ) return new WP_Error( 'max', sprintf( 'A store can have up to %d events of its own.', self::MAX_OWN ) );

        $name = array_key_exists( 'name', $in ) ? self::plain( $in['name'], 60 ) : (string) ( $old['name'] ?? '' );
        if ( $name === '' ) return new WP_Error( 'name', 'Give this event a name.' );
        $start = array_key_exists( 'start', $in ) || ! $old ? self::clean_start( $in['start'] ?? null ) : $old['start'];
        if ( is_wp_error( $start ) ) return $start;
        $count = array_key_exists( 'count', $in ) ? ( $in['count'] === 'every' ? 'every' : 'visit' ) : (string) ( $old['count'] ?? 'visit' );
        $value = array_key_exists( 'value', $in ) ? round( min( 100000000, max( 0, (float) $in['value'] ) ), 2 ) : (float) ( $old['value'] ?? 0 );

        // The tools: new events start with every tool on; a tool that isn't sent keeps its saved choice.
        $base  = self::tool_name( $name, 'event', true );
        $tools = [];
        $sent  = is_array( $in['tools'] ?? null ) ? $in['tools'] : [];
        foreach ( self::TOOLS as $tool ) {
            $was = is_array( $old['tools'][ $tool ] ?? null ) ? $old['tools'][ $tool ] : [ 'on' => true ];
            $now = is_array( $sent[ $tool ] ?? null ) ? $sent[ $tool ] : [];
            $row = [ 'on' => array_key_exists( 'on', $now ) ? ! empty( $now['on'] ) : ! empty( $was['on'] ) ];
            if ( in_array( $tool, [ 'ga4', 'gtm', 'meta', 'tiktok' ], true ) ) {
                $raw = array_key_exists( 'name', $now ) ? $now['name'] : ( $was['name'] ?? '' );
                if ( $tool === 'meta' || $tool === 'tiktok' ) {
                    $standard    = $tool === 'meta' ? self::META_STANDARD : self::TIKTOK_STANDARD;
                    $row['name'] = in_array( (string) $raw, $standard, true ) ? (string) $raw : self::tool_name( $raw, $tool === 'meta' ? 'Lead' : 'ClickButton', false );
                } else {
                    $row['name'] = self::tool_name( $raw, $base, true );
                }
            }
            $tools[ $tool ] = $row;
        }

        $event = [
            'id'      => $old['id'] ?? 'ev_' . bin2hex( random_bytes( 5 ) ),
            'name'    => $name,
            'start'   => $start,
            'count'   => $count,
            'value'   => $value,
            'tools'   => $tools,
            'created' => (int) ( $old['created'] ?? time() ),
        ];
        if ( $at === null ) $own[] = $event;
        else $own[ $at ] = $event;
        self::write( self::off(), $own );
        return self::state() + [ 'event' => $event ];
    }

    public static function delete_own( string $id ): array {
        $own = array_values( array_filter( self::own(), static fn( $e ) => $e['id'] !== $id ) );
        self::write( self::off(), $own );
        if ( class_exists( 'Qwoo_Personalization' ) && preg_match( self::ID_RE, $id ) ) Qwoo_Personalization::forget_event( $id );
        return self::state();
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Events (dashboard: Analytics → Events), step 1: for each shop event the
 * storefront sends (page viewed, product viewed, added to cart, started
 * checkout, order placed), the owner chooses which connected tracking tools
 * hear it. Everything is on until the owner turns a tool off for an event.
 *
 *   option qwoo_events  { off: { event: [ tool, … ] } }
 *
 * The storefront gets the "off" lists with the tracking settings
 * (Qwoo_Custom_Code::storefront_payload → events_off) and skips those tools
 * (aura: src/utils/custom-code.js). Cookie choices still come first: a tool
 * never hears from a shopper who didn't accept its kind of cookie.
 */
class Qwoo_Events {

    const OPTION = 'qwoo_events';

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

    private static function off(): array {
        $saved = get_option( self::OPTION, [] );
        $off   = is_array( $saved ) && is_array( $saved['off'] ?? null ) ? $saved['off'] : [];
        $out   = [];
        foreach ( self::SHOP as $event => $label ) {
            $tools = array_values( array_intersect( self::TOOLS, array_map( 'strval', (array) ( $off[ $event ] ?? [] ) ) ) );
            if ( $tools ) $out[ $event ] = $tools;
        }
        return $out;
    }

    /** For the storefront: { event: [ tools it isn't sent to ] }. */
    public static function payload(): array {
        return self::off();
    }

    /** For the dashboard: the events with each tool on / off, and which tools the store has connected. */
    public static function state(): array {
        $code      = class_exists( 'Qwoo_Custom_Code' ) ? Qwoo_Custom_Code::storefront_payload() : [];
        $connected = array_fill_keys( self::TOOLS, false );
        foreach ( array_keys( (array) ( $code['shortcuts'] ?? [] ) ) as $key ) {
            if ( isset( $connected[ $key ] ) ) $connected[ $key ] = true;
        }
        foreach ( (array) ( $code['snippets'] ?? [] ) as $snippet ) {
            if ( ( $snippet['kind'] ?? '' ) === 'snippet' ) $connected['code'] = true;
        }
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
        return [ 'events' => $events, 'connected' => $connected ];
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
        update_option( self::OPTION, [ 'off' => $off ], false );
        // The storefront reads its tracking settings again.
        if ( class_exists( 'Qwoo_Custom_Code' ) ) Qwoo_Custom_Code::touch();
        return self::state();
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Store builder → Floating buttons: one layout for the storefront's floating buttons (Contact, the cookie
 * button, Accessibility, Help), saved in shop_builder_options['floating'] with the Store builder's draft
 * and sent to the storefront through the live pointer (no publish). The storefront stacks the buttons per
 * bottom corner in this order, all the same size (aura src/utils/floating-dock.js).
 *
 * { size: 44|52|60, edge: 8–40, gap: 6–24, reset: time (shoppers' own places are forgotten when it changes),
 *   buttons: { contact|cookie|a11y|help: { place: start|end (|own for Help), order: 1–9, move: bool, phone: bool } } }
 */
class Qwoo_Floating_Buttons {

    const IDS = [ 'contact', 'cookie', 'a11y', 'help' ];

    /** Before the owner saves this screen: today's places (the accessibility button keeps its chosen side). */
    public static function defaults(): array {
        $a11y = class_exists( 'Qwoo_Accessibility' ) ? ( Qwoo_Accessibility::settings()['place'] ?? 'auto' ) : 'auto';
        return [
            'size'    => 52,
            'edge'    => 16,
            'gap'     => 12,
            'reset'   => 0,
            'buttons' => [
                'contact' => [ 'place' => 'start', 'order' => 1, 'move' => false, 'phone' => true ],
                'cookie'  => [ 'place' => 'start', 'order' => 2, 'move' => false, 'phone' => true ],
                'a11y'    => [ 'place' => in_array( $a11y, [ 'start', 'end' ], true ) ? $a11y : 'end', 'order' => 1, 'move' => false, 'phone' => true ],
                'help'    => [ 'place' => 'own', 'order' => 2, 'move' => false, 'phone' => true ],
            ],
        ];
    }

    public static function clean( $v ): array {
        $v   = is_array( $v ) ? $v : [];
        $d   = self::defaults();
        $num = static fn( $x, int $min, int $max, int $def ) => is_numeric( $x ) ? max( $min, min( $max, (int) $x ) ) : $def;
        $out = [
            'size'    => in_array( (int) ( $v['size'] ?? 0 ), [ 44, 52, 60 ], true ) ? (int) $v['size'] : $d['size'],
            'edge'    => $num( $v['edge'] ?? null, 8, 40, $d['edge'] ),
            'gap'     => $num( $v['gap'] ?? null, 6, 24, $d['gap'] ),
            'reset'   => max( 0, (int) ( $v['reset'] ?? 0 ) ),
            'buttons' => [],
        ];
        foreach ( self::IDS as $id ) {
            $b      = is_array( $v['buttons'][ $id ] ?? null ) ? $v['buttons'][ $id ] : [];
            $places = $id === 'help' ? [ 'start', 'end', 'own' ] : [ 'start', 'end' ];
            $out['buttons'][ $id ] = [
                'place' => in_array( $b['place'] ?? '', $places, true ) ? $b['place'] : $d['buttons'][ $id ]['place'],
                'order' => $num( $b['order'] ?? null, 1, 9, $d['buttons'][ $id ]['order'] ),
                'move'  => array_key_exists( 'move', $b ) ? ! empty( $b['move'] ) : $d['buttons'][ $id ]['move'],
                'phone' => array_key_exists( 'phone', $b ) ? ! empty( $b['phone'] ) : $d['buttons'][ $id ]['phone'],
            ];
        }
        return $out;
    }

    /** The saved settings (or the defaults). */
    public static function settings( ?array $options = null ): array {
        $o = $options ?? get_option( 'shop_builder_options', [] );
        $o = is_array( $o ) ? $o : [];
        return isset( $o['floating'] ) ? self::clean( $o['floating'] ) : self::defaults();
    }

    /** For the live pointer (Qwoo_Site_Content::pointer_body). */
    public static function site_fields(): array {
        return [ 'floating' => self::settings() ];
    }
}

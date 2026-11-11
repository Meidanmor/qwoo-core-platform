<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Popups (Store builder → Popups): windows built from blocks like a page,
 * each with its own look and rules (POPUP_SETTINGS_FIELDS).
 *
 *   options['popups'] = [ { id: pp_…, name, enabled, settings, sections } ]
 *
 * A popup opens by itself (as the page opens, after some seconds, after
 * scrolling, when a visitor is about to leave, after some pages), or only
 * when a link opens it: any link field can hold "#popup:{id}"
 * (sanitize_url_value keeps it). Where (CHROME_TARGETS and own page ids,
 * plus pages it never shows on), who (visitors / customers, screen sizes),
 * how often and between which dates are checked by the storefront
 * (src/components/popups/PopupHost.vue), which opens one popup by itself per
 * page view at most.
 *
 * Published as config/popups.json { popups: [ { id, name, settings
 * (+ page_paths / exclude_paths), sections } ] } (enabled ones only).
 * Shared by every language for now, like Loop templates.
 */
trait SB_Popups {

    /** Popup settings for the dashboard (audiences are picked with the `audience` field type, from schema.audiences). */
    private static function popup_settings_schema(): array {
        return self::POPUP_SETTINGS_FIELDS;
    }

    /** Triggers that open a popup by itself (the others wait for a link). */
    private static $popup_auto_triggers = [ 'load', 'delay', 'scroll', 'exit', 'pages' ];

    /** The popups, cleaned. */
    private function sanitize_popups( $raw ) {
        $out = [];
        foreach ( array_slice( array_values( is_array( $raw ) ? $raw : [] ), 0, self::MAX_POPUPS ) as $popup ) {
            if ( ! is_array( $popup ) ) continue;
            $sections = is_array( $popup['sections'] ?? null ) ? $popup['sections'] : [];
            $legacy_code = sanitize_text_field( (string) ( $popup['settings']['coupon_code'] ?? '' ) );
            if ( $legacy_code && ! $this->has_personalized_offer( $sections ) ) {
                $offer = [ 'id' => self::new_id( 'blk' ), 'type' => 'personalized_offer', 'enabled' => true, 'style' => [],
                    'data' => [ 'title' => 'A special offer for you', 'coupon_code' => mb_substr( $legacy_code, 0, 50 ), 'button_label' => 'Copy code' ] ];
                if ( ! empty( $sections[0]['blocks'] ) && is_array( $sections[0]['blocks'] ) ) $sections[0]['blocks'][] = $offer;
                else $sections[] = [ 'id' => self::new_id( 'sec' ), 'enabled' => true, 'style' => [], 'blocks' => [ $offer ] ];
            }
            $out[] = [
                'id'       => preg_match( '/^pp_[A-Za-z0-9]{3,20}$/', (string) ( $popup['id'] ?? '' ) ) ? $popup['id'] : self::new_id( 'pp' ),
                'name'     => mb_substr( sanitize_text_field( (string) ( $popup['name'] ?? '' ) ), 0, 60 ) ?: 'Popup',
                'enabled'  => ! isset( $popup['enabled'] ) || ( ! empty( $popup['enabled'] ) && $popup['enabled'] !== 'false' ),
                'settings' => self::sanitize_popup_settings( (array) ( $popup['settings'] ?? [] ) ),
                'sections' => $this->sanitize_sections( $sections, 'popup' ),
            ];
        }
        return $out;
    }

    private function has_personalized_offer( array $sections ): bool {
        $walk = static function ( array $nodes ) use ( &$walk ): bool {
            foreach ( $nodes as $node ) {
                if ( ( $node['type'] ?? '' ) === 'personalized_offer' ) return true;
                if ( is_array( $node['blocks'] ?? null ) && $walk( $node['blocks'] ) ) return true;
            }
            return false;
        };
        return $walk( $sections );
    }

    /** One popup's settings: every POPUP_SETTINGS_FIELDS key, cleaned (missing ones get their defaults). */
    public static function sanitize_popup_settings( array $raw ) {
        $out = [];
        foreach ( self::POPUP_SETTINGS_FIELDS as $key => $spec ) {
            $type = $spec['type'] ?? 'text';
            if ( $type === 'targets' ) {
                $out[ $key ] = self::sanitize_chrome_targets( $raw[ $key ] ?? [] );
            } elseif ( $type === 'date' ) {
                $value       = is_scalar( $raw[ $key ] ?? null ) ? trim( (string) $raw[ $key ] ) : '';
                $out[ $key ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && strtotime( $value ) ? $value : '';
            } else {
                $out[ $key ] = self::sanitize_field_value( $spec, $raw[ $key ] ?? null );
            }
        }
        // Codex's "interested in categories" (browser only) becomes an audience with the same categories.
        if ( ( $raw['audience'] ?? '' ) === 'interest' && class_exists( 'Qwoo_Personalization' ) ) {
            $cats  = array_slice( array_filter( array_map( 'absint', (array) ( $raw['interest_category_ids'] ?? [] ) ) ), 0, 10 );
            $names = array_filter( array_map( static function ( $id ) { $t = get_term( $id, 'product_cat' ); return $t && ! is_wp_error( $t ) ? $t->name : ''; }, $cats ) );
            $made  = $cats ? Qwoo_Personalization::save_audience( [ 'name' => mb_substr( 'Interested in ' . implode( ', ', $names ), 0, 80 ), 'match' => 'any', 'rules' => array_map( static fn( $id ) => [ 'type' => 'viewed_category', 'category_id' => $id, 'min' => 1, 'days' => 30 ], $cats ) ] ) : null;
            $out['audience']    = is_array( $made ) ? 'saved' : 'all';
            $out['audience_id'] = is_array( $made ) ? $made['audience']['id'] : '';
        }
        if ( in_array( $out['audience'], [ 'saved', 'not_saved' ], true ) && $out['audience_id'] === '' ) $out['audience'] = 'all';
        // An end before the start would never show.
        if ( $out['start_date'] !== '' && $out['end_date'] !== '' && $out['end_date'] < $out['start_date'] ) {
            $out['end_date'] = '';
        }
        return $out;
    }

    /** The popups for the dashboard, without sections (they go into `pages` as "popup@{id}"). */
    private static function platform_popups_data( array $options, array &$pages, array &$refs ) {
        $out = [];
        foreach ( (array) ( $options['popups'] ?? [] ) as $popup ) {
            if ( ! is_array( $popup ) || empty( $popup['id'] ) ) continue;
            $key           = 'popup@' . $popup['id'];
            $pages[ $key ] = array_values( (array) ( $popup['sections'] ?? [] ) );
            self::collect_sections_refs( $pages[ $key ], $refs );
            $out[] = [
                'id'       => $popup['id'],
                'name'     => (string) ( $popup['name'] ?? '' ),
                'enabled'  => ! empty( $popup['enabled'] ),
                'settings' => self::sanitize_popup_settings( (array) ( $popup['settings'] ?? [] ) ),
            ];
        }
        return $out;
    }

    /** config/popups.json (main language folder only: every language reads it). */
    private function stage_popups( &$batch, array $options, $dir, array &$path_to_label, array &$kept_section_images ) {
        if ( $dir !== 'public/config/' ) return;
        $paths    = null;
        $resolver = $this->github_image_resolver( $batch, 'Popups', $path_to_label, $kept_section_images );
        $list     = [];
        foreach ( (array) ( $options['popups'] ?? [] ) as $popup ) {
            if ( ! is_array( $popup ) || empty( $popup['enabled'] ) || empty( $popup['id'] ) ) continue;
            $settings = self::sanitize_popup_settings( (array) ( $popup['settings'] ?? [] ) );
            // The chosen own pages' addresses (the storefront matches own pages by address).
            $paths = $paths ?? self::published_page_map( get_option( 'shop_builder_options', [] ) );
            foreach ( [ 'targets' => 'page_paths', 'exclude' => 'exclude_paths' ] as $from => $to ) {
                $settings[ $to ] = [];
                foreach ( $settings[ $from ] as $target ) {
                    if ( isset( $paths[ $target ] ) ) $settings[ $to ][] = (string) $paths[ $target ]['path'];
                }
            }
            $list[] = [
                'id'       => $popup['id'],
                'name'     => (string) ( $popup['name'] ?? '' ),
                'settings' => $settings,
                'sections' => self::output_sections( (array) ( $popup['sections'] ?? [] ), $resolver ),
            ];
        }
        $path = "{$dir}popups.json";
        aps_github_batch_put_file( $batch, $path, aps_normalize_json( json_encode( [ 'popups' => $list ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
        $path_to_label[ $path ] = 'Popups';
    }

    /**
     * New stores: a "Sign in" popup (opened only by links) with the Sign In
     * Form, a line to make an account, and the header's Account widget
     * opening it for visitors. Only when the store has no popups yet.
     */
    public static function ensure_default_popups( array &$options ) {
        if ( ! empty( $options['popups'] ) ) return;
        $id    = self::new_id( 'pp' );
        $rwd   = static fn( $d ) => [ 'desktop' => $d ];
        $login = [ 'id' => self::new_id( 'blk' ), 'type' => 'login_form', 'label' => '', 'enabled' => true, 'style' => [],
            'data' => [ 'title' => Qwoo_I18n::t( 'Sign in' ), 'card' => false, 'after' => 'stay', 'when_signed_in' => 'hide' ] ];
        $link  = [ 'id' => self::new_id( 'blk' ), 'type' => 'text_block', 'label' => '', 'enabled' => true, 'style' => [],
            'data' => [ 'text' => '<p>' . esc_html( Qwoo_I18n::t( 'New here?' ) ) . ' <a href="/my-account">' . esc_html( Qwoo_I18n::t( 'Create an account' ) ) . '</a></p>' ] ];
        $section = [ 'id' => self::new_id( 'sec' ), 'label' => '', 'enabled' => true,
            'style' => [ 'width_mode' => 'full', 'padding_preset' => 'custom', 'padding' => $rwd( [ 'top' => '28', 'right' => '24', 'bottom' => '24', 'left' => '24' ] ), 'gap' => $rwd( '12px' ) ],
            'blocks' => [ $login, $link ] ];
        $clean = self::instance()->sanitize_options( [ 'popups' => [ [
            'id' => $id, 'name' => Qwoo_I18n::t( 'Sign in' ), 'enabled' => true,
            'settings' => [ 'trigger' => 'manual', 'width' => $rwd( '420px' ) ],
            'sections' => [ $section ],
        ] ] ] );
        if ( empty( $clean['popups'] ) ) return;
        $options['popups'] = $clean['popups'];
        $popup_id = $clean['popups'][0]['id'];
        // The header's Account widget opens it for visitors (when it still goes to the default page).
        $walk = static function ( array $nodes ) use ( &$walk, $popup_id ) {
            foreach ( $nodes as $i => $node ) {
                if ( ! is_array( $node ) ) continue;
                if ( ( $node['type'] ?? '' ) === 'account_widget' && (string) ( $node['data']['guest_url'] ?? '' ) === '' ) {
                    $nodes[ $i ]['data']['guest_url'] = '#popup:' . $popup_id;
                }
                if ( is_array( $node['blocks'] ?? null ) ) $nodes[ $i ]['blocks'] = $walk( $node['blocks'] );
            }
            return $nodes;
        };
        if ( is_array( $options['header']['sections'] ?? null ) ) {
            $options['header']['sections'] = $walk( $options['header']['sections'] );
        }
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The Shop Builder for the platform's owner dashboard (the Design section
 * of /app/ on the platform). Qwoo_Platform_Dashboard calls these after it
 * has checked the platform's signed token; they reuse the builder's own
 * schema, sanitizing, versions, templates and publishing, so the dashboard
 * and wp-admin always store and publish exactly the same thing.
 */
trait SB_Platform {

    /** Options other than page sections that the dashboard edits. */
    private static $platform_option_groups = [ 'header', 'footer', 'home', 'checkout', 'branding', 'pwa', 'contact' ];

    /** Everything the Design screen needs: data, schema, display data and preview URLs. */
    public static function platform_design_data() {
        $options = get_option( 'shop_builder_options', [] );
        $options = is_array( $options ) ? $options : [];
        $refs    = [ 'media' => [], 'products' => [], 'categories' => [], 'tags' => [] ];

        $pages = [];
        foreach ( self::sectionable_pages() as $page_slug ) {
            $pages[ $page_slug ] = array_values( (array) ( $options[ $page_slug ]['sections'] ?? [] ) );
            self::collect_sections_refs( $pages[ $page_slug ], $refs );
        }
        foreach ( [ $options['home']['hero_image_id'] ?? 0, $options['branding']['logo_id'] ?? 0, $options['branding']['app_icon_id'] ?? 0 ] as $id ) {
            if ( (int) $id ) $refs['media'][ (int) $id ] = true;
        }

        $tech     = get_option( 'qwoo_technical_settings', [] );
        $frontend = ! empty( $tech['frontend_domain'] ) ? untrailingslashit( $tech['frontend_domain'] ) : '';
        $origin   = '';
        if ( $frontend ) {
            $parts  = wp_parse_url( $frontend );
            $origin = isset( $parts['scheme'], $parts['host'] ) ? $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) : '';
        }

        return [
            'options'       => self::platform_options( $options ),
            'pages'         => (object) $pages,
            'schema'        => [
                'blocks'           => self::BLOCK_SCHEMA,
                'section_style'    => self::SECTION_STYLE_FIELDS,
                'block_common'     => self::BLOCK_COMMON_FIELDS,
                'global_colors'    => self::GLOBAL_COLOR_KEYS,
                'icon_sets'        => self::ICON_SETS,
                'page_locations'   => self::PAGE_SECTION_LOCATIONS,
                'padding_presets'  => self::SECTION_PADDING_PRESETS,
                'contact_types'    => self::CONTACT_METHOD_TYPES,
                'max_depth'        => self::MAX_NESTING_DEPTH,
                'max_sections'     => self::MAX_SECTIONS,
                'max_blocks'       => self::MAX_BLOCKS,
            ],
            'global_colors' => self::get_global_colors(),
            'preview'       => [ 'urls' => self::build_preview_urls( $frontend ), 'origin' => $origin ],
            'timezone'      => wp_timezone_string(),
            'templates'     => self::template_summaries(),
        ] + self::refs_payload( $refs );
    }

    /** The non-section options, in the shape the forms edit (defaults filled in). */
    private static function platform_options( array $o ) {
        $ann = $o['header']['announcement'] ?? [];
        $out = [
            'header'   => [
                'announcement' => [
                    'enabled'    => ! empty( $ann['enabled'] ),
                    'text'       => (string) ( $ann['text'] ?? '' ),
                    'bg_color'   => (string) ( $ann['bg_color'] ?? '' ),
                    'text_color' => (string) ( $ann['text_color'] ?? '' ),
                ],
                'settings'     => [
                    'sticky'      => (bool) ( $o['header']['settings']['sticky'] ?? true ),
                    'show_search' => ! empty( $o['header']['settings']['show_search'] ),
                ],
            ],
            'footer'   => [ 'footer_text' => (string) ( $o['footer']['footer_text'] ?? '' ) ],
            'home'     => [
                'hero_title'       => (string) ( $o['home']['hero_title'] ?? '' ),
                'hero_description' => (string) ( $o['home']['hero_description'] ?? '' ),
                'hero_btn'         => [
                    'text' => (string) ( $o['home']['hero_btn']['text'] ?? '' ),
                    'url'  => (string) ( $o['home']['hero_btn']['url'] ?? '' ),
                ],
                'hero_image_id'    => (int) ( $o['home']['hero_image_id'] ?? 0 ),
            ],
            'checkout' => [ 'checkout_notice' => (string) ( $o['checkout']['checkout_notice'] ?? '' ) ],
            'branding' => [
                'global_colors' => self::get_global_colors(),
                'logo_id'       => (int) ( $o['branding']['logo_id'] ?? 0 ),
                'app_icon_id'   => (int) ( $o['branding']['app_icon_id'] ?? 0 ),
            ],
            'pwa'      => [
                'name'             => (string) ( $o['pwa']['name'] ?? '' ),
                'short_name'       => (string) ( $o['pwa']['short_name'] ?? '' ),
                'description'      => (string) ( $o['pwa']['description'] ?? '' ),
                'theme_color'      => (string) ( $o['pwa']['theme_color'] ?? '' ),
                'background_color' => (string) ( $o['pwa']['background_color'] ?? '' ),
            ],
            'contact'  => [
                'enabled' => ! empty( $o['contact']['enabled'] ),
                'methods' => array_values( array_map( static function ( $m ) {
                    return [
                        'type'    => (string) ( $m['type'] ?? 'whatsapp' ),
                        'value'   => (string) ( $m['value'] ?? '' ),
                        'label'   => (string) ( $m['label'] ?? '' ),
                        'icon'    => (string) ( $m['icon'] ?? '' ),
                        'enabled' => ! empty( $m['enabled'] ),
                    ];
                }, (array) ( $o['contact']['methods'] ?? [] ) ) ),
            ],
        ];
        if ( isset( $o['header']['navigation'] ) ) {
            $out['header']['navigation'] = $o['header']['navigation'];
        }
        return $out;
    }

    /**
     * Saves the dashboard's draft: the option groups and every page's
     * sections go through sanitize_options(), exactly like Save Draft.
     */
    public static function platform_save( array $options, array $pages ) {
        $input = array_intersect_key( $options, array_flip( self::$platform_option_groups ) );
        foreach ( self::sectionable_pages() as $page_slug ) {
            if ( isset( $pages[ $page_slug ] ) && is_array( $pages[ $page_slug ] ) ) {
                $input[ $page_slug ]             = is_array( $input[ $page_slug ] ?? null ) ? $input[ $page_slug ] : [];
                $input[ $page_slug ]['sections'] = $pages[ $page_slug ];
            }
        }
        self::instance()->save_input( $input );
        return self::platform_design_data();
    }

    /**
     * "Publish": pushes every page to the content repo (which redeploys the
     * storefront), and regenerates the app icon set when the icon changed.
     */
    public static function platform_publish() {
        $result = self::instance()->push_all_pages();
        if ( isset( $result['error'] ) ) {
            return $result;
        }

        $options = get_option( 'shop_builder_options', [] );
        $icon    = (int) ( $options['branding']['app_icon_id'] ?? 0 );
        if ( $icon && (int) get_option( 'qwoo_platform_icons_from' ) !== $icon ) {
            require_once __DIR__ . '/../class-icon-generator.php';
            $icons = Qwoo_Icon_Generator::generate_from_attachment( $icon );
            if ( is_wp_error( $icons ) ) {
                $result['warnings'][] = 'App icons: ' . $icons->get_error_message();
            } elseif ( Qwoo_Icon_Generator::sync_to_github( $icons['files'] ) === false ) {
                $result['warnings'][] = 'The app icons couldn\'t be published. Try again.';
            } else {
                update_option( 'qwoo_platform_icons_from', $icon, false );
            }
        }
        return $result;
    }

    /** Products, categories or tags for the pickers: [ { id, text, thumb } ]. */
    public static function platform_search( $kind, $term ) {
        $term = mb_substr( sanitize_text_field( (string) $term ), 0, 100 );
        $out  = [];
        if ( $kind === 'products' ) {
            foreach ( wc_get_products( [ 'limit' => 20, 'status' => 'publish', 's' => $term ] ) as $p ) {
                $out[] = [ 'id' => $p->get_id(), 'text' => html_entity_decode( $p->get_name(), ENT_QUOTES ), 'thumb' => (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) ];
            }
        } elseif ( $kind === 'categories' || $kind === 'tags' ) {
            $terms = get_terms( [ 'taxonomy' => $kind === 'tags' ? 'product_tag' : 'product_cat', 'hide_empty' => false, 'name__like' => $term, 'number' => 20 ] );
            foreach ( is_wp_error( $terms ) ? [] : $terms as $t ) {
                $thumb = $kind === 'categories' ? get_term_meta( $t->term_id, 'thumbnail_id', true ) : 0;
                $out[] = [ 'id' => (int) $t->term_id, 'text' => html_entity_decode( $t->name, ENT_QUOTES ), 'thumb' => $thumb ? (string) wp_get_attachment_image_url( $thumb, 'thumbnail' ) : '' ];
            }
        }
        return $out;
    }

    /** Media Library images or videos, newest first, 30 a page. */
    public static function platform_media( $kind, $page, $search ) {
        $query = new WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => $kind === 'video' ? 'video' : 'image',
            'posts_per_page' => 30,
            'paged'          => max( 1, (int) $page ),
            's'              => mb_substr( sanitize_text_field( (string) $search ), 0, 100 ),
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ] );
        $items = [];
        foreach ( $query->posts as $id ) {
            $payload = self::admin_media_payload( $id );
            if ( $payload ) {
                $items[] = [ 'id' => (int) $id, 'name' => get_the_title( $id ) ] + $payload;
            }
        }
        return [ 'items' => $items, 'pages' => (int) $query->max_num_pages ];
    }

    /** Display data for one uploaded attachment (url, thumb, width, height). */
    public static function platform_media_item( $id ) {
        return self::admin_media_payload( (int) $id );
    }

    /* ---------------- versions ---------------- */

    public static function platform_revisions() {
        $out = [];
        foreach ( self::get_revisions() as $rev ) {
            $counts = [];
            foreach ( (array) ( $rev['pages'] ?? [] ) as $page => $sections ) {
                $counts[ $page ] = count( (array) $sections );
            }
            $out[] = [ 'id' => $rev['id'], 'time' => (int) $rev['time'], 'user' => $rev['user'] ?? '', 'pushed' => ! empty( $rev['pushed'] ), 'counts' => $counts ];
        }
        return $out;
    }

    /** One version's pages (plus display data), or null. */
    public static function platform_revision( $id ) {
        foreach ( self::get_revisions() as $rev ) {
            if ( ( $rev['id'] ?? '' ) !== $id ) continue;
            $refs  = [];
            $pages = (array) ( $rev['pages'] ?? [] );
            foreach ( $pages as $sections ) self::collect_sections_refs( (array) $sections, $refs );
            return [ 'pages' => (object) $pages ] + self::refs_payload( $refs );
        }
        return null;
    }

    /* ---------------- section templates ---------------- */

    /** Saves a section as a named template; the template list, or an error string. */
    public static function platform_template_save( $name, $node ) {
        $name = mb_substr( sanitize_text_field( (string) $name ), 0, 80 );
        if ( $name === '' ) return 'Give the template a name.';
        if ( ! is_array( $node ) ) return 'The section couldn\'t be read.';
        unset( $node['type'], $node['location'] );
        $clean = self::instance()->sanitize_sections( [ $node ], 'home' );
        if ( ! $clean ) return 'The section couldn\'t be read.';
        $section = $clean[0];
        unset( $section['location'] );

        $list = self::get_templates();
        if ( count( $list ) >= self::MAX_TEMPLATES ) {
            return 'You already have ' . self::MAX_TEMPLATES . ' templates. Delete one first.';
        }
        array_unshift( $list, [ 'id' => self::new_id( 'tpl' ), 'name' => $name, 'time' => time(), 'user' => '', 'section' => $section ] );
        update_option( self::TEMPLATES_OPTION, $list, false );
        return self::template_summaries();
    }

    public static function platform_template( $id ) {
        foreach ( self::get_templates() as $t ) {
            if ( ( $t['id'] ?? '' ) !== $id ) continue;
            $refs = [];
            self::collect_sections_refs( [ $t['section'] ], $refs );
            return [ 'section' => $t['section'] ] + self::refs_payload( $refs );
        }
        return null;
    }

    public static function platform_template_delete( $id ) {
        $list = array_values( array_filter( self::get_templates(), static fn( $t ) => ( $t['id'] ?? '' ) !== $id ) );
        update_option( self::TEMPLATES_OPTION, $list, false );
        return self::template_summaries();
    }

    /** A version or template id as the builder creates them. */
    public static function platform_valid_id( $id ) {
        return is_string( $id ) && preg_match( '/^(rev|tpl)_[A-Za-z0-9]{6,20}$/', $id );
    }
}

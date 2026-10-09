<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The Store builder in an extra language (Qwoo_Store_Language, premium addon).
 *
 * Each extra language keeps its own copy of the content, in
 * shop_builder_options['i18n'][ lang ]:
 *   pages:        { header, footer, home, checkout, contact, shop, category,
 *                   product, cart, blog, blog_post }: the same shape as the
 *                   main options (texts, sections, layouts)
 *   menus:        the language's own menus
 *   custom_pages: { pg_id: { title, show_title, status, seo, sections } }
 * Pages themselves (which pages exist, their addresses and places in the
 * tree) are shared: a translation can't add, move or rename an address.
 * Until a language has its own copy of something, the main language's is
 * used as the starting point (custom pages: as drafts, so they only go live
 * in that language once the owner publishes them there).
 *
 * Publishing writes each live language's files to public/config/{lang}/;
 * the storefront falls back to the main files for anything missing there.
 */
trait SB_Languages {

    /** The option groups that can have their own copy per language. */
    private static $translatable_keys = [ 'header', 'footer', 'home', 'checkout', 'contact', 'shop', 'category', 'product', 'cart', 'blog', 'blog_post' ];

    /** The options as one language sees them ('' or the main language: unchanged). */
    public static function options_for_language( array $options, $lang ) {
        if ( $lang === '' || $lang === Qwoo_Store_Language::get() ) {
            unset( $options['i18n'] );
            return $options;
        }
        $tr = (array) ( $options['i18n'][ $lang ] ?? [] );
        foreach ( (array) ( $tr['pages'] ?? [] ) as $key => $value ) {
            if ( in_array( $key, self::$translatable_keys, true ) && is_array( $value ) ) {
                $options[ $key ] = $value;
            }
        }
        if ( isset( $tr['menus'] ) && is_array( $tr['menus'] ) ) {
            $options['menus'] = $tr['menus'];
        }
        $pages = [];
        foreach ( self::custom_pages_of( $options ) as $page ) {
            // The address stays the main language's.
            $page['slug'] = self::page_slug( (string) ( $page['slug'] ?? '' ), (string) ( $page['title'] ?? '' ) );
            $own          = $tr['custom_pages'][ $page['id'] ] ?? null;
            if ( is_array( $own ) ) {
                $page               = array_merge( $page, array_intersect_key( $own, array_flip( [ 'title', 'show_title', 'status', 'seo', 'sections' ] ) ) );
                $page['translated'] = true;
            } else {
                $page['status']     = 'draft';
                $page['translated'] = false;
            }
            // The homepage is always live where it exists.
            if ( ( $page['role'] ?? '' ) === 'home' && is_array( $own ) ) {
                $page['status'] = 'publish';
            }
            $pages[] = $page;
        }
        $options['custom_pages'] = $pages;
        unset( $options['i18n'] );
        return $options;
    }

    /** Which pages and option groups a language has its own copy of (for the dashboard). */
    public static function translation_status( array $options, $lang ) {
        $tr = (array) ( $options['i18n'][ $lang ] ?? [] );
        return [
            'lang'   => $lang,
            'pages'  => array_keys( (array) ( $tr['custom_pages'] ?? [] ) ),
            'groups' => array_keys( (array) ( $tr['pages'] ?? [] ) ),
            'menus'  => isset( $tr['menus'] ),
        ];
    }

    /**
     * Saves the dashboard's draft for an extra language: sanitized exactly
     * like the main language's, kept in i18n[ lang ].
     */
    public static function platform_save_language( $lang, array $options, array $pages, ?array $custom_pages, ?array $menus, ?array $layouts ) {
        $input = array_intersect_key( $options, array_flip( array_intersect( self::$platform_option_groups, self::$translatable_keys ) ) );
        foreach ( self::sectionable_pages() as $page_slug ) {
            if ( isset( $pages[ $page_slug ] ) && is_array( $pages[ $page_slug ] ) && in_array( $page_slug, self::$translatable_keys, true ) ) {
                $input[ $page_slug ]             = is_array( $input[ $page_slug ] ?? null ) ? $input[ $page_slug ] : [];
                $input[ $page_slug ]['sections'] = $pages[ $page_slug ];
            }
        }
        foreach ( array_keys( self::LAYOUT_PAGES ) as $page_slug ) {
            if ( $layouts !== null && isset( $layouts[ $page_slug ] ) && is_array( $layouts[ $page_slug ] ) ) {
                $input[ $page_slug ]            = is_array( $input[ $page_slug ] ?? null ) ? $input[ $page_slug ] : [];
                $input[ $page_slug ]['layouts'] = $layouts[ $page_slug ];
            }
        }
        if ( $menus !== null ) {
            $input['menus'] = $menus;
        }
        $all   = (array) get_option( 'shop_builder_options', [] );
        $main  = self::custom_pages_of( $all );
        $by_id = [];
        foreach ( $main as $page ) {
            $by_id[ $page['id'] ] = $page;
        }
        if ( $custom_pages !== null ) {
            // Only the language's own texts: the page list and addresses stay the main language's.
            $merged = [];
            foreach ( $custom_pages as $page ) {
                $id = (string) ( $page['id'] ?? '' );
                if ( ! isset( $by_id[ $id ] ) ) {
                    continue;
                }
                $merged[] = array_merge( $by_id[ $id ], array_intersect_key( (array) $page, array_flip( [ 'title', 'show_title', 'status', 'seo', 'sections' ] ) ) );
            }
            $input['custom_pages'] = $merged;
        }

        $clean = self::instance()->sanitize_options( $input );
        $tr    = (array) ( $all['i18n'][ $lang ] ?? [] );
        foreach ( self::$translatable_keys as $key ) {
            if ( ! isset( $clean[ $key ] ) ) {
                continue;
            }
            $prev                  = (array) ( $tr['pages'][ $key ] ?? ( $all[ $key ] ?? [] ) );
            $tr['pages'][ $key ]   = array_replace( $prev, $clean[ $key ] );
        }
        if ( isset( $clean['menus'] ) ) {
            $tr['menus'] = $clean['menus'];
        }
        if ( isset( $clean['custom_pages'] ) ) {
            $own = [];
            foreach ( (array) $clean['custom_pages'] as $page ) {
                $copy = array_intersect_key( $page, array_flip( [ 'title', 'show_title', 'status', 'seo', 'sections' ] ) );
                // A draft still identical to the main language isn't a translation yet.
                $was = $by_id[ $page['id'] ] ?? null;
                if ( ! isset( $tr['custom_pages'][ $page['id'] ] ) && $was && ( $copy['status'] ?? '' ) !== 'publish'
                    && wp_json_encode( [ $copy['title'] ?? '', $copy['sections'] ?? [], $copy['seo'] ?? [] ] ) === wp_json_encode( [ $was['title'] ?? '', $was['sections'] ?? [], $was['seo'] ?? [] ] ) ) {
                    continue;
                }
                $own[ $page['id'] ] = $copy;
            }
            $tr['custom_pages'] = $own;
        }
        $all['i18n'][ $lang ] = $tr;
        update_option( 'shop_builder_options', $all );
        return self::platform_design_data( $lang );
    }

    /**
     * Stages every live extra language's files into the push: the same files
     * as the main language (but not branding / app settings, which are shared)
     * under public/config/{lang}/. Folders of languages no longer live are removed.
     */
    private function stage_languages( &$batch, array $options, array &$path_to_label, array &$kept_section_images ) {
        $live = class_exists( 'Qwoo_Store_Language' ) ? Qwoo_Store_Language::live_extra() : [];
        foreach ( $live as $lang ) {
            $lang_options = self::options_for_language( $options, $lang );
            // Built-in texts (menu labels…) in that language.
            Qwoo_I18n::in_language( $lang, function () use ( &$batch, $lang_options, $lang, &$path_to_label, &$kept_section_images ) {
                $this->stage_configs( $batch, $lang_options, "public/config/{$lang}/", $path_to_label, $kept_section_images, self::$translatable_keys, strtoupper( $lang ) . ': ' );
            } );
        }
        // Folders of languages that aren't live any more.
        foreach ( array_keys( $batch['existing'] ) as $path ) {
            if ( ! preg_match( '#^public/config/([a-z]{2})/#', $path, $m ) || in_array( $m[1], $live, true ) ) {
                continue;
            }
            $batch['tree_updates'][] = [ 'path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null ];
            $batch['deleted'][]      = $path;
            $path_to_label[ $path ]  = strtoupper( $m[1] ) . ' (removed)';
        }
    }
}

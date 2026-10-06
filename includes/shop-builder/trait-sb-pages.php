<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The owner's own pages (About, Shipping, FAQ…): each one is built from
 * sections like the homepage and lives at /{slug} on the storefront.
 *
 * Stored in shop_builder_options['custom_pages'] (draft, versions and
 * undo work like every other page):
 *   [ { id: pg_…, title, slug, status: publish | draft, in_menu, in_footer,
 *       seo: { title, description, image_id, noindex, keyphrase },
 *       old_slugs: [ … ], sections: [ … ] } ]
 *
 * Publishing writes, for published pages only:
 *   public/config/pages.json        [ { id, slug, title } ]  (the router's list)
 *   public/config/page-{id}.json    { id, slug, title, sections }
 *   header.json / footer.json       "pages": [ { title, slug } ] for the menus
 * and remembers what went live (option qwoo_published_pages) for SEO and
 * the sitemap, so they never point at a page the storefront doesn't have.
 */
trait SB_Pages {

    /** Most pages a store can have. */
    private static $max_pages = 50;

    /** Addresses the storefront (or Vercel) already uses. */
    private static $reserved_slugs = [
        'cart', 'checkout', 'products', 'product', 'product-category', 'my-account', 'thank-you',
        'forgot-password', 'reset-password', 'auth', 'login', 'account', 'search', 'wp-json', 'wp-admin',
        'wp-content', 'wp-includes', 'api', 'config', 'data', 'sections', 'branding', 'homepage-hero',
        'js', 'css', 'fonts', 'icons', 'assets', 'img', 'images', '_quasar', 'sitemap', 'robots', 'llms',
        'manifest', 'offline', 'home', 'index', 'shop', 'page', 'pages',
    ];

    const PUBLISHED_PAGES_OPTION = 'qwoo_published_pages';

    /** The stored pages (always a list). */
    private static function custom_pages_of( $options ) {
        return array_values( array_filter( (array) ( $options['custom_pages'] ?? [] ), 'is_array' ) );
    }

    /** An address made from what the owner typed (or the title), like WordPress makes slugs. */
    private static function page_slug( $slug, $title ) {
        $slug = sanitize_title( mb_substr( (string) $slug, 0, 190 ) );
        return $slug !== '' ? $slug : sanitize_title( mb_substr( (string) $title, 0, 190 ) );
    }

    /**
     * Problems the owner has to fix before the pages can be saved: '' or a
     * message. (Saving itself never guesses: a clash would silently move a
     * page to another address.)
     */
    public static function platform_check_pages( array $pages ) {
        if ( count( $pages ) > self::$max_pages ) {
            return 'A store can have up to ' . self::$max_pages . ' pages.';
        }
        $seen = [];
        foreach ( $pages as $page ) {
            $page  = is_array( $page ) ? $page : [];
            $title = trim( sanitize_text_field( (string) ( $page['title'] ?? '' ) ) );
            if ( $title === '' || mb_strlen( $title ) > 120 ) {
                return 'Give every page a title (up to 120 characters).';
            }
            $slug = self::page_slug( $page['slug'] ?? '', $title );
            if ( $slug === '' ) {
                return "The page \"{$title}\" needs an address.";
            }
            if ( in_array( $slug, self::$reserved_slugs, true ) ) {
                return 'The address /' . urldecode( $slug ) . " is used by your store itself. Choose another one for \"{$title}\".";
            }
            if ( isset( $seen[ $slug ] ) ) {
                return 'Two pages use the address /' . urldecode( $slug ) . '. Give each page its own address.';
            }
            $seen[ $slug ] = true;
            if ( class_exists( 'Qwoo_Seo' ) ) {
                $seo = Qwoo_Seo::clean_input( $page['seo'] ?? [] );
                if ( is_wp_error( $seo ) ) {
                    return "\"{$title}\": " . $seo->get_error_message();
                }
            }
        }
        return '';
    }

    /** Sanitizes the posted pages (sanitize_options() calls this). */
    private function sanitize_custom_pages( $raw, array $existing ) {
        $before = [];
        foreach ( $existing as $page ) {
            if ( isset( $page['id'] ) ) $before[ $page['id'] ] = $page;
        }

        $clean = [];
        $used  = [];
        foreach ( array_slice( array_values( is_array( $raw ) ? $raw : [] ), 0, self::$max_pages ) as $page ) {
            if ( ! is_array( $page ) ) continue;
            $id    = is_string( $page['id'] ?? null ) && preg_match( '/^pg_[a-zA-Z0-9]{6,20}$/', $page['id'] ) ? $page['id'] : self::new_id( 'pg' );
            $title = mb_substr( trim( sanitize_text_field( (string) ( $page['title'] ?? '' ) ) ), 0, 120 ) ?: 'Untitled page';
            $slug  = self::page_slug( $page['slug'] ?? '', $title );
            // Never two pages on one address, never a store address (platform_check_pages() reports these first).
            if ( $slug === '' || in_array( $slug, self::$reserved_slugs, true ) || isset( $used[ $slug ] ) ) {
                $slug = sanitize_title( $title . '-' . substr( $id, 3, 6 ) );
            }
            $used[ $slug ] = true;

            $seo_in = is_array( $page['seo'] ?? null ) ? $page['seo'] : [];
            $seo    = class_exists( 'Qwoo_Seo' ) ? Qwoo_Seo::clean_input( [
                    'title'       => mb_substr( (string) ( $seo_in['title'] ?? '' ), 0, Qwoo_Seo::MAX_TITLE ),
                    'description' => mb_substr( (string) ( $seo_in['description'] ?? '' ), 0, Qwoo_Seo::MAX_DESCRIPTION ),
                    'image_id'    => $seo_in['image_id'] ?? 0,
                    'keyphrase'   => $seo_in['keyphrase'] ?? '',
                    'noindex'     => $seo_in['noindex'] ?? false,
            ] ) : [];
            if ( is_wp_error( $seo ) ) {
                $seo = [ 'title' => '', 'description' => '', 'image_id' => 0, 'keyphrase' => '', 'noindex' => ! empty( $seo_in['noindex'] ) ];
            }

            // A published page that moves keeps its old address (it redirects).
            $old_slugs = (array) ( $before[ $id ]['old_slugs'] ?? [] );
            $was       = $before[ $id ] ?? null;
            if ( $was && ( $was['slug'] ?? '' ) !== '' && $was['slug'] !== $slug && ( $was['status'] ?? '' ) === 'publish' ) {
                array_unshift( $old_slugs, $was['slug'] );
            }
            $old_slugs = array_slice( array_values( array_unique( array_diff( $old_slugs, [ $slug ] ) ) ), 0, 10 );

            $clean[] = [
                    'id'        => $id,
                    'title'     => $title,
                    'slug'      => $slug,
                    'status'    => ( $page['status'] ?? '' ) === 'publish' ? 'publish' : 'draft',
                    'in_menu'   => ! empty( $page['in_menu'] ),
                    'in_footer' => ! empty( $page['in_footer'] ),
                    'show_title' => ! array_key_exists( 'show_title', $page ) || ! empty( $page['show_title'] ),
                    'seo'       => $seo,
                    'old_slugs' => $old_slugs,
                    'sections'  => $this->sanitize_sections( $page['sections'] ?? [], 'home' ),
            ];
        }
        return $clean;
    }

    /** The pages as the dashboard edits them (sections included). */
    private static function platform_pages_data( array $options, array &$refs ) {
        $out = [];
        foreach ( self::custom_pages_of( $options ) as $page ) {
            $sections = array_values( (array) ( $page['sections'] ?? [] ) );
            self::collect_sections_refs( $sections, $refs );
            $seo = (array) ( $page['seo'] ?? [] );
            if ( ! empty( $seo['image_id'] ) ) $refs['media'][ (int) $seo['image_id'] ] = true;
            $out[] = [
                    'id'        => (string) $page['id'],
                    'title'     => (string) ( $page['title'] ?? '' ),
                    'slug'      => urldecode( (string) ( $page['slug'] ?? '' ) ),
                    'status'    => (string) ( $page['status'] ?? 'draft' ),
                    'in_menu'   => ! empty( $page['in_menu'] ),
                    'in_footer' => ! empty( $page['in_footer'] ),
                    'show_title' => ! array_key_exists( 'show_title', $page ) || ! empty( $page['show_title'] ),
                    'seo'       => [
                            'title'       => (string) ( $seo['title'] ?? '' ),
                            'description' => (string) ( $seo['description'] ?? '' ),
                            'image_id'    => (int) ( $seo['image_id'] ?? 0 ),
                            'keyphrase'   => (string) ( $seo['keyphrase'] ?? '' ),
                            'noindex'     => ! empty( $seo['noindex'] ),
                    ],
                    'sections'  => $sections,
            ];
        }
        return $out;
    }

    /** Menu links for header.json / footer.json: [ { title, slug } ] of published pages. */
    private static function custom_page_menus( array $options ) {
        $menus = [ 'header' => [], 'footer' => [] ];
        foreach ( self::custom_pages_of( $options ) as $page ) {
            if ( ( $page['status'] ?? '' ) !== 'publish' ) continue;
            $link = [ 'title' => (string) $page['title'], 'slug' => (string) $page['slug'] ];
            if ( ! empty( $page['in_menu'] ) ) $menus['header'][] = $link;
            if ( ! empty( $page['in_footer'] ) ) $menus['footer'][] = $link;
        }
        return $menus;
    }

    /**
     * Stages the published pages into the push batch: the list, one file per
     * page, and deletion of pages that aren't published any more.
     */
    private function stage_custom_pages( &$batch, array $options, array &$path_to_label, array &$kept_section_images ) {
        $list = [];
        $kept = [ 'public/config/pages.json' => true ];
        foreach ( self::custom_pages_of( $options ) as $page ) {
            if ( ( $page['status'] ?? '' ) !== 'publish' ) continue;
            $label    = 'Page: ' . $page['title'];
            $path     = "public/config/page-{$page['id']}.json";
            $resolver = $this->github_image_resolver( $batch, $label, $path_to_label, $kept_section_images );
            $data     = [
                    'id'       => $page['id'],
                    'slug'     => $page['slug'],
                    'title'    => $page['title'],
                    'show_title' => ! array_key_exists( 'show_title', $page ) || ! empty( $page['show_title'] ),
                    'sections' => self::output_sections( (array) ( $page['sections'] ?? [] ), $resolver ),
            ];
            aps_github_batch_put_file( $batch, $path, aps_normalize_json( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
            $path_to_label[ $path ] = $label;
            $kept[ $path ]          = true;
            $list[]                 = [ 'id' => $page['id'], 'slug' => $page['slug'], 'title' => $page['title'] ];
        }
        aps_github_batch_put_file( $batch, 'public/config/pages.json', aps_normalize_json( json_encode( [ 'pages' => $list ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
        $path_to_label['public/config/pages.json'] = 'Pages';

        foreach ( array_keys( $batch['existing'] ) as $path ) {
            if ( strpos( $path, 'public/config/page-' ) !== 0 || isset( $kept[ $path ] ) ) continue;
            $batch['tree_updates'][] = [ 'path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null ];
            $batch['deleted'][]      = $path;
            $path_to_label[ $path ]  = 'Pages (removed)';
        }
    }

    /** After a successful push: what's live now, for SEO and the sitemap. */
    private static function remember_published_pages( array $options ) {
        $live = [];
        foreach ( self::custom_pages_of( $options ) as $page ) {
            if ( ( $page['status'] ?? '' ) !== 'publish' ) continue;
            $live[] = [
                    'id'        => $page['id'],
                    'slug'      => $page['slug'],
                    'title'     => $page['title'],
                    'seo'       => (array) ( $page['seo'] ?? [] ),
                    'old_slugs' => (array) ( $page['old_slugs'] ?? [] ),
                    'hash'      => md5( wp_json_encode( [ $page['title'], $page['slug'], $page['sections'] ?? [] ] ) ),
                    'time'      => time(),
            ];
        }
        // A page that didn't change keeps its date (the sitemap's "last changed").
        foreach ( (array) get_option( self::PUBLISHED_PAGES_OPTION, [] ) as $before ) {
            foreach ( $live as $i => $page ) {
                if ( $page['id'] === ( $before['id'] ?? '' ) && $page['hash'] === ( $before['hash'] ?? '' ) ) {
                    $live[ $i ]['time'] = (int) ( $before['time'] ?? time() );
                }
            }
        }
        update_option( self::PUBLISHED_PAGES_OPTION, $live, false );
    }

    /** The pages that are live on the storefront (Qwoo_Seo reads these). */
    public static function published_pages() {
        return array_values( array_filter( (array) get_option( self::PUBLISHED_PAGES_OPTION, [] ), 'is_array' ) );
    }
}

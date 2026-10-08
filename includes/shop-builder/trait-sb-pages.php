<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The owner's own pages (About, Shipping, FAQ…): each one is built from
 * sections like the homepage. A page can sit inside another one (parent),
 * so it lives at /{parent path}/{slug} on the storefront, e.g. /about/team.
 *
 * Stored in shop_builder_options['custom_pages'] (draft, versions and
 * undo work like every other page):
 *   [ { id: pg_…, title, slug, parent: '' | pg_…, status: publish | draft,
 *       show_title, role: '' | home | privacy | terms | returns,
 *       seo: { title, description, image_id, noindex, keyphrase },
 *       sections: [ … ] } ]
 *
 * Publishing writes, for published pages only:
 *   public/config/pages.json        { pages: [ { id, path, title, parent, role? } ] }  (the router's list)
 *   public/config/page-{id}.json    { id, path, slug, title, show_title, crumbs, sections }
 * and remembers what went live (option qwoo_published_pages) for SEO and the
 * sitemap, so they never point at a page the storefront doesn't have. A
 * page whose address changed since the last publish keeps its old address
 * working (old_paths → 301).
 *
 * The page with role "home" is the storefront's homepage: its address is
 * "/" (path ''), and it never sits inside another page or holds pages.
 * New stores get it (and the legal pages) at setup; older stores get their
 * old Homepage tab turned into it (trait-sb-store-pages.php).
 *
 * Menus are their own thing (trait-sb-menus.php).
 */
trait SB_Pages {

    /** Most pages a store can have, and how deep pages can sit inside each other. */
    private static $max_pages = 50;
    private static $max_page_depth = 6;
    // What a page is for, when the storefront links to it on its own (one page each).
    private static $page_roles = [ 'home', 'privacy', 'terms', 'returns' ];

    /** Top-level addresses the storefront (or Vercel) already uses. */
    private static $reserved_slugs = [
        'cart', 'checkout', 'products', 'product', 'product-category', 'my-account', 'thank-you',
        'forgot-password', 'reset-password', 'auth', 'login', 'account', 'search', 'wp-json', 'wp-admin',
        'wp-content', 'wp-includes', 'api', 'config', 'data', 'sections', 'branding', 'homepage-hero',
        'js', 'css', 'fonts', 'icons', 'assets', 'img', 'images', '_quasar', 'sitemap', 'robots', 'llms',
        'manifest', 'offline', 'home', 'index', 'shop', 'page', 'pages', 'review',
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
     * id => full path ("about/team") for a list of pages with id, slug and
     * parent. A missing parent counts as top level; a loop or a chain deeper
     * than allowed is cut at the page where it goes wrong (null = loop).
     */
    private static function page_paths( array $pages ) {
        $by_id = [];
        foreach ( $pages as $page ) {
            $by_id[ $page['id'] ] = $page;
        }
        $paths = [];
        foreach ( $by_id as $id => $page ) {
            if ( ( $page['role'] ?? '' ) === 'home' ) {
                $paths[ $id ] = ''; // the homepage: "/"
                continue;
            }
            $parts = [];
            $seen  = [];
            $at    = $id;
            while ( $at !== '' && isset( $by_id[ $at ] ) ) {
                if ( isset( $seen[ $at ] ) ) {
                    $parts = null; // a loop
                    break;
                }
                $seen[ $at ] = true;
                array_unshift( $parts, $by_id[ $at ]['slug'] );
                $at = (string) ( $by_id[ $at ]['parent'] ?? '' );
            }
            $paths[ $id ] = $parts === null ? null : implode( '/', $parts );
        }
        return $paths;
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
        $ids   = [];
        $clean = [];
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
            if ( class_exists( 'Qwoo_Seo' ) ) {
                $seo = Qwoo_Seo::clean_input( $page['seo'] ?? [] );
                if ( is_wp_error( $seo ) ) {
                    return "\"{$title}\": " . $seo->get_error_message();
                }
            }
            $id         = (string) ( $page['id'] ?? '' );
            $ids[ $id ] = true;
            $clean[]    = [ 'id' => $id, 'title' => $title, 'slug' => $slug, 'parent' => (string) ( $page['parent'] ?? '' ), 'role' => (string) ( $page['role'] ?? '' ) ];
        }
        $homes = array_column( array_filter( $clean, static fn( $p ) => $p['role'] === 'home' ), 'id' );
        if ( count( $homes ) > 1 ) {
            return 'Only one page can be the homepage.';
        }
        foreach ( $clean as $i => $page ) {
            if ( $page['parent'] !== '' && ! isset( $ids[ $page['parent'] ] ) ) {
                $clean[ $i ]['parent'] = '';
            }
            if ( $page['parent'] !== '' && ( $page['role'] === 'home' || in_array( $page['parent'], $homes, true ) ) ) {
                return $page['role'] === 'home' ? 'The homepage can\'t sit inside another page.' : "\"{$page['title']}\" can't sit inside the homepage. Choose another page or the top level.";
            }
        }

        $paths = self::page_paths( $clean );
        $seen  = [];
        foreach ( $clean as $page ) {
            $path = $paths[ $page['id'] ] ?? null;
            if ( $path === null ) {
                return "\"{$page['title']}\" can't be inside itself or one of its own subpages.";
            }
            if ( substr_count( $path, '/' ) >= self::$max_page_depth ) {
                return "\"{$page['title']}\" is too deep: pages can sit up to " . self::$max_page_depth . ' levels inside each other.';
            }
            if ( $page['parent'] === '' && $page['role'] !== 'home' && in_array( $page['slug'], self::$reserved_slugs, true ) ) {
                return 'The address /' . urldecode( $page['slug'] ) . " is used by your store itself. Choose another one for \"{$page['title']}\".";
            }
            if ( isset( $seen[ $path ] ) ) {
                return 'Two pages use the address /' . urldecode( $path ) . '. Give each page its own address.';
            }
            $seen[ $path ] = true;
        }
        return '';
    }

    /** Sanitizes the posted pages (sanitize_options() calls this). */
    private function sanitize_custom_pages( $raw, array $existing ) {
        $clean = [];
        foreach ( array_slice( array_values( is_array( $raw ) ? $raw : [] ), 0, self::$max_pages ) as $page ) {
            if ( ! is_array( $page ) ) continue;
            $id    = is_string( $page['id'] ?? null ) && preg_match( '/^pg_[a-zA-Z0-9]{6,20}$/', $page['id'] ) ? $page['id'] : self::new_id( 'pg' );
            $title = mb_substr( trim( sanitize_text_field( (string) ( $page['title'] ?? '' ) ) ), 0, 120 ) ?: 'Untitled page';

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

            $clean[] = [
                    'id'         => $id,
                    'title'      => $title,
                    'slug'       => self::page_slug( $page['slug'] ?? '', $title ),
                    'parent'     => is_string( $page['parent'] ?? null ) && preg_match( '/^pg_[a-zA-Z0-9]{6,20}$/', $page['parent'] ) ? $page['parent'] : '',
                    'status'     => ( $page['status'] ?? '' ) === 'publish' ? 'publish' : 'draft',
                    'show_title' => ! array_key_exists( 'show_title', $page ) || ! empty( $page['show_title'] ),
                    'role'       => in_array( $page['role'] ?? '', self::$page_roles, true ) ? $page['role'] : '',
                    'seo'        => $seo,
                    'sections'   => $this->sanitize_sections( $page['sections'] ?? [], 'home' ),
            ];
        }

        // One page per role: the first one keeps it.
        $roles = [];
        foreach ( $clean as $i => $page ) {
            if ( $page['role'] === '' ) continue;
            if ( isset( $roles[ $page['role'] ] ) ) $clean[ $i ]['role'] = '';
            $roles[ $page['role'] ] = $page['id'];
        }
        // The homepage is "/": at the top level, with no pages inside it.
        foreach ( $clean as $i => $page ) {
            if ( $page['role'] === 'home' || ( isset( $roles['home'] ) && $page['parent'] === $roles['home'] ) ) {
                $clean[ $i ]['parent'] = '';
            }
        }

        // Parents must exist and not loop; addresses must be unique and never a store address
        // (platform_check_pages() reports these first; here nothing is left broken).
        $ids = array_flip( array_column( $clean, 'id' ) );
        foreach ( $clean as $i => $page ) {
            if ( $page['parent'] === $page['id'] || ! isset( $ids[ $page['parent'] ] ) ) {
                $clean[ $i ]['parent'] = '';
            }
        }
        foreach ( self::page_paths( $clean ) as $id => $path ) {
            if ( $path === null || substr_count( $path, '/' ) >= self::$max_page_depth ) {
                $clean[ $ids[ $id ] ]['parent'] = '';
            }
        }
        $used = [];
        foreach ( $clean as $i => $page ) {
            if ( $page['role'] === 'home' ) continue; // its address is "/", whatever its slug
            $key = $page['parent'] . '/' . $page['slug'];
            if ( isset( $used[ $key ] ) || ( $page['parent'] === '' && in_array( $page['slug'], self::$reserved_slugs, true ) ) ) {
                $clean[ $i ]['slug'] = sanitize_title( $page['title'] . '-' . substr( $page['id'], 3, 6 ) );
                $key                 = $page['parent'] . '/' . $clean[ $i ]['slug'];
            }
            $used[ $key ] = true;
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
                    'id'         => (string) $page['id'],
                    'title'      => (string) ( $page['title'] ?? '' ),
                    'slug'       => urldecode( (string) ( $page['slug'] ?? '' ) ),
                    'parent'     => (string) ( $page['parent'] ?? '' ),
                    'status'     => (string) ( $page['status'] ?? 'draft' ),
                    'show_title' => ! array_key_exists( 'show_title', $page ) || ! empty( $page['show_title'] ),
                    'role'       => (string) ( $page['role'] ?? '' ),
                    'seo'        => [
                            'title'       => (string) ( $seo['title'] ?? '' ),
                            'description' => (string) ( $seo['description'] ?? '' ),
                            'image_id'    => (int) ( $seo['image_id'] ?? 0 ),
                            'keyphrase'   => (string) ( $seo['keyphrase'] ?? '' ),
                            'noindex'     => ! empty( $seo['noindex'] ),
                    ],
                    'sections'   => $sections,
            ];
        }
        return $out;
    }

    /** The published pages with their full paths: id => [ page…, path ]. */
    private static function published_page_map( array $options ) {
        $pages = self::custom_pages_of( $options );
        $paths = self::page_paths( $pages );
        $out   = [];
        foreach ( $pages as $page ) {
            $path = $paths[ $page['id'] ] ?? null;
            // '' is only the homepage's address.
            if ( ( $page['status'] ?? '' ) === 'publish' && $path !== null && ( $path !== '' || ( $page['role'] ?? '' ) === 'home' ) ) {
                $out[ $page['id'] ] = $page + [ 'path' => $paths[ $page['id'] ] ];
            }
        }
        return $out;
    }

    /**
     * Stages the published pages into the push batch: the list, one file per
     * page, and deletion of pages that aren't published any more.
     */
    private function stage_custom_pages( &$batch, array $options, array &$path_to_label, array &$kept_section_images ) {
        $live = self::published_page_map( $options );
        $list = [];
        $kept = [ 'public/config/pages.json' => true ];
        foreach ( $live as $page ) {
            // The trail above the page ("About › Team"), for its breadcrumbs.
            $crumbs = [];
            for ( $at = (string) ( $page['parent'] ?? '' ); $at !== '' && isset( $live[ $at ] ); $at = (string) ( $live[ $at ]['parent'] ?? '' ) ) {
                array_unshift( $crumbs, [ 'title' => $live[ $at ]['title'], 'path' => $live[ $at ]['path'] ] );
                if ( count( $crumbs ) > self::$max_page_depth ) break;
            }
            $label    = 'Page: ' . $page['title'];
            $path     = "public/config/page-{$page['id']}.json";
            $resolver = $this->github_image_resolver( $batch, $label, $path_to_label, $kept_section_images );
            $data     = [
                    'id'         => $page['id'],
                    'path'       => $page['path'],
                    'slug'       => $page['slug'],
                    'title'      => $page['title'],
                    'show_title' => ! array_key_exists( 'show_title', $page ) || ! empty( $page['show_title'] ),
                    'crumbs'     => $crumbs,
                    'sections'   => self::output_sections( (array) ( $page['sections'] ?? [] ), $resolver ),
            ];
            aps_github_batch_put_file( $batch, $path, aps_normalize_json( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
            $path_to_label[ $path ] = $label;
            $kept[ $path ]          = true;
            $entry = [ 'id' => $page['id'], 'path' => $page['path'], 'title' => $page['title'], 'parent' => (string) ( $page['parent'] ?? '' ) ];
            // What the storefront links to on its own (the cookie notice → the privacy policy).
            if ( ! empty( $page['role'] ) ) $entry['role'] = (string) $page['role'];
            $list[] = $entry;
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

    /**
     * After a successful push: what's live now, for SEO and the sitemap. A
     * page whose address changed since it was last live remembers the old
     * one (up to 10), so it keeps working and redirects.
     */
    private static function remember_published_pages( array $options ) {
        $before = [];
        foreach ( self::published_pages() as $page ) {
            $before[ $page['id'] ?? '' ] = $page;
        }
        $live = [];
        foreach ( self::published_page_map( $options ) as $id => $page ) {
            $was  = $before[ $id ] ?? null;
            $old  = array_merge( (array) ( $was['old_paths'] ?? [] ), (array) ( $was['old_slugs'] ?? [] ) );
            $prev = (string) ( $was['path'] ?? $was['slug'] ?? '' );
            if ( $prev !== '' && $prev !== $page['path'] ) {
                array_unshift( $old, $prev );
            }
            $hash   = md5( wp_json_encode( [ $page['title'], $page['path'], $page['sections'] ?? [] ] ) );
            $live[] = [
                    'id'        => $id,
                    'path'      => $page['path'],
                    'slug'      => $page['slug'],
                    'title'     => $page['title'],
                    'role'      => (string) ( $page['role'] ?? '' ),
                    'seo'       => (array) ( $page['seo'] ?? [] ),
                    'excerpt'   => self::page_excerpt( (array) ( $page['sections'] ?? [] ) ),
                    'old_paths' => array_slice( array_values( array_unique( array_diff( $old, [ $page['path'] ] ) ) ), 0, 10 ),
                    'hash'      => $hash,
                    // A page that didn't change keeps its date (the sitemap's "last changed").
                    'time'      => $was && ( $was['hash'] ?? '' ) === $hash ? (int) ( $was['time'] ?? time() ) : time(),
            ];
        }
        update_option( self::PUBLISHED_PAGES_OPTION, $live, false );
    }

    /** The first text on a page (headings and text blocks), for its automatic search description. */
    private static function page_excerpt( array $sections ) {
        $text = [];
        $walk = static function ( array $blocks ) use ( &$walk, &$text ) {
            foreach ( $blocks as $block ) {
                if ( ! is_array( $block ) || empty( $block['enabled'] ) && isset( $block['enabled'] ) ) continue;
                if ( ( $block['type'] ?? '' ) === 'section' ) {
                    $walk( (array) ( $block['blocks'] ?? [] ) );
                    continue;
                }
                foreach ( [ 'text', 'description', 'subtitle', 'content' ] as $field ) {
                    if ( ! empty( $block['data'][ $field ] ) && is_string( $block['data'][ $field ] ) ) {
                        $text[] = $block['data'][ $field ];
                    }
                }
            }
        };
        foreach ( $sections as $section ) {
            if ( is_array( $section ) && ! empty( $section['enabled'] ) ) {
                $walk( (array) ( $section['blocks'] ?? [] ) );
            }
            if ( count( $text ) > 6 ) break;
        }
        $plain = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( implode( ' ', $text ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        return mb_substr( $plain, 0, 400 );
    }

    /** A page's first text (for its automatic search description). */
    public static function platform_page_excerpt( array $sections ) {
        return self::page_excerpt( $sections );
    }

    /** The pages that are live on the storefront (Qwoo_Seo reads these). */
    public static function published_pages() {
        return array_values( array_filter( (array) get_option( self::PUBLISHED_PAGES_OPTION, [] ), 'is_array' ) );
    }
}

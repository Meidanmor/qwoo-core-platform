<?php
/**
 * The store's SEO: what search engines and link previews see for each
 * storefront address, and the list of addresses for the sitemap.
 *
 * GET qwoo/v1/seo?path=…   { title, description, canonical, robots, og_image,
 *                            og_type, site_name, locale, … }
 * GET qwoo/v1/sitemap      { urls: [ { path, lastmod } ] }
 *
 * Both sit behind the proxy secret (the qwoo namespace): the storefront
 * asks for them, nobody else.
 *
 * Every value has an automatic default, so a store that never touches SEO
 * still gets a sensible title, description and image everywhere. What the
 * owner sets wins:
 *   post meta  _qwoo_seo          { title, description, image_id } (products)
 *   term meta  _qwoo_seo          the same (product categories)
 *   meta       _qwoo_seo_noindex  '1' keeps the item out of search engines
 *   option     qwoo_seo           store-wide: title_pattern, home_title,
 *                                 home_description, image_id, google_verification
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwoo_Seo {

    const META         = '_qwoo_seo';
    const NOINDEX_META = '_qwoo_seo_noindex';
    const OPTION       = 'qwoo_seo';

    /** Search results cut descriptions at about this many characters. */
    const DESCRIPTION_LENGTH = 155;

    /** The sitemap lists at most this many products (a sitemap file holds 50,000). */
    const SITEMAP_PRODUCTS = 20000;

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        register_rest_route( 'qwoo/v1', '/seo', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_seo' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'path' => [ 'required' => true, 'type' => 'string' ] ],
        ] );
        register_rest_route( 'qwoo/v1', '/sitemap', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_sitemap' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /* ---------------- settings ---------------- */

    /** The store-wide settings, with defaults filled in. */
    public static function settings(): array {
        $saved = get_option( self::OPTION, [] );
        return array_merge( [
            'title_pattern'       => '{title} | {store}',
            'home_title'          => '',
            'home_description'    => '',
            'image_id'            => 0,
            'google_verification' => '',
        ], is_array( $saved ) ? $saved : [] );
    }

    /** What the owner set for a product (post) or category (term): { title, description, image_id, keyphrase }. */
    public static function custom( int $id, string $kind = 'post' ): array {
        $saved = $kind === 'term' ? get_term_meta( $id, self::META, true ) : get_post_meta( $id, self::META, true );
        $saved = is_array( $saved ) ? $saved : [];
        return [
            'title'       => trim( (string) ( $saved['title'] ?? '' ) ),
            'description' => trim( (string) ( $saved['description'] ?? '' ) ),
            'image_id'    => absint( $saved['image_id'] ?? 0 ),
            'keyphrase'   => trim( (string) ( $saved['keyphrase'] ?? '' ) ),
        ];
    }

    public static function is_noindex( int $id, string $kind = 'post' ): bool {
        $value = $kind === 'term' ? get_term_meta( $id, self::NOINDEX_META, true ) : get_post_meta( $id, self::NOINDEX_META, true );
        return $value === '1';
    }

    /* ---------------- owner editing (dashboard) ---------------- */

    /** Longest values the owner can save (search results show ~60 and ~155 characters). */
    const MAX_TITLE       = 120;
    const MAX_DESCRIPTION = 320;

    /**
     * The owner's SEO fields { title, description, image_id, noindex, keyphrase },
     * checked. Returns them cleaned, or a WP_Error naming the problem.
     */
    public static function clean_input( $in ) {
        $in    = is_array( $in ) ? $in : [];
        $text  = static fn( $v ) => trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( is_scalar( $v ) ? (string) $v : '' ) ) );
        $title = $text( $in['title'] ?? '' );
        $desc  = $text( $in['description'] ?? '' );
        if ( mb_strlen( $title ) > self::MAX_TITLE ) {
            return new WP_Error( 'qwoo_seo', 'The search title can be up to ' . self::MAX_TITLE . ' characters.', [ 'status' => 400 ] );
        }
        if ( mb_strlen( $desc ) > self::MAX_DESCRIPTION ) {
            return new WP_Error( 'qwoo_seo', 'The search description can be up to ' . self::MAX_DESCRIPTION . ' characters.', [ 'status' => 400 ] );
        }
        $image = absint( $in['image_id'] ?? 0 );
        if ( $image && ! wp_attachment_is_image( $image ) ) {
            return new WP_Error( 'qwoo_seo', 'The share image is missing. Upload it again.', [ 'status' => 400 ] );
        }
        return [
            'title'       => $title,
            'description' => $desc,
            'image_id'    => $image,
            'keyphrase'   => mb_substr( $text( $in['keyphrase'] ?? '' ), 0, 100 ),
            'noindex'     => ! empty( $in['noindex'] ),
        ];
    }

    /** Saves cleaned fields (from clean_input) on a product or category. */
    public static function save( int $id, string $kind, array $c ): void {
        $meta = array_filter( [
            'title'       => $c['title'],
            'description' => $c['description'],
            'image_id'    => $c['image_id'],
            'keyphrase'   => $c['keyphrase'],
        ] );
        $update = $kind === 'term' ? 'update_term_meta' : 'update_post_meta';
        $delete = $kind === 'term' ? 'delete_term_meta' : 'delete_post_meta';
        $meta ? $update( $id, self::META, $meta ) : $delete( $id, self::META );
        $c['noindex'] ? $update( $id, self::NOINDEX_META, '1' ) : $delete( $id, self::NOINDEX_META );
    }

    /** The owner's fields as the dashboard edits them, plus the share image's address. */
    public static function for_editor( int $id, string $kind ): array {
        $c = self::custom( $id, $kind );
        return $c + [
            'image'   => $c['image_id'] ? (string) wp_get_attachment_image_url( $c['image_id'], 'medium' ) : '',
            'noindex' => self::is_noindex( $id, $kind ),
        ];
    }

    /** What search engines get when the owner leaves a field empty: { title, description }. */
    public static function product_defaults( WC_Product $product ): array {
        return [
            'title'       => self::with_pattern( self::plain( $product->get_name() ) ),
            'description' => self::shorten( self::plain( $product->get_short_description() ?: $product->get_description() ) ),
        ];
    }

    public static function term_defaults( WP_Term $term ): array {
        return [
            'title'       => self::with_pattern( self::plain( $term->name ) ),
            'description' => self::shorten( self::plain( $term->description ) ),
        ];
    }

    /** The homepage's automatic title and description. */
    public static function home_defaults(): array {
        $name    = self::store_name();
        $tagline = self::plain( get_bloginfo( 'description' ) );
        return [
            'title'       => $tagline !== '' ? "$name – $tagline" : $name,
            'description' => self::shorten( $tagline ),
        ];
    }

    /** Remembers a category's old slug, so its old address keeps working (redirects). */
    public static function remember_term_slug( int $term_id, string $old_slug ): void {
        if ( $old_slug !== '' && ! in_array( $old_slug, (array) get_term_meta( $term_id, '_qwoo_old_slug' ), true ) ) {
            add_term_meta( $term_id, '_qwoo_old_slug', $old_slug );
        }
    }

    /* ---------------- qwoo/v1/seo ---------------- */

    public static function rest_seo( WP_REST_Request $request ) {
        // The router hands over the path as the browser has it (Hebrew and
        // other non-Latin slugs percent-encoded): decode it before WordPress
        // looks it up — sanitize_text_field() would strip the encoded bytes.
        $path = rawurldecode( (string) $request['path'] );
        $path = trim( wp_check_invalid_utf8( wp_strip_all_tags( $path ) ), "/ \t\n\r" );
        $path = strtok( $path, '?#' ) ?: '';

        $answer = self::for_path( $path );
        if ( ! $answer ) {
            return new WP_Error( 'not_found', 'Nothing at this address.', [ 'status' => 404 ] );
        }
        return rest_ensure_response( array_merge( self::common(), $answer ) );
    }

    /** The answer for a storefront path ('' is the homepage), or null when nothing lives there. */
    public static function for_path( string $path ): ?array {
        if ( $path === '' || $path === 'homepage' ) {
            return self::homepage();
        }
        if ( $path === 'shop' || $path === 'products' ) {
            return self::shop();
        }
        if ( preg_match( '#^product/([^/]+)$#u', $path, $m ) ) {
            return self::product( $m[1] ) ?? self::moved( 'product', $m[1] );
        }
        if ( preg_match( '#^product-category/([^/]+)$#u', $path, $m ) ) {
            return self::category( $m[1] ) ?? self::moved( 'product_cat', $m[1] );
        }
        // The owner's own pages (/about, /shipping…).
        if ( strpos( $path, '/' ) === false ) {
            return self::page( $path );
        }
        return null;
    }

    /** A published page of the owner's (or { redirect } for one of its old addresses), or null. */
    private static function page( string $slug ): ?array {
        $slug = sanitize_title( $slug );
        if ( $slug === '' || ! class_exists( 'Shop_Settings_Builder' ) ) {
            return null;
        }
        $pages = Shop_Settings_Builder::published_pages();
        foreach ( $pages as $page ) {
            if ( ( $page['slug'] ?? '' ) !== $slug ) {
                continue;
            }
            $seo = (array) ( $page['seo'] ?? [] );
            return [
                'title'       => trim( (string) ( $seo['title'] ?? '' ) ) ?: self::with_pattern( self::plain( $page['title'] ?? '' ) ),
                'description' => trim( (string) ( $seo['description'] ?? '' ) ),
                'canonical'   => self::url( '/' . $slug ),
                'robots'      => self::robots( empty( $seo['noindex'] ) ),
                'og_image'    => self::image_url( absint( $seo['image_id'] ?? 0 ) ) ?: self::image_url( absint( self::settings()['image_id'] ) ),
                'og_type'     => 'website',
                'type'        => 'page',
                'page_id'     => (string) ( $page['id'] ?? '' ),
            ];
        }
        foreach ( $pages as $page ) {
            if ( in_array( $slug, (array) ( $page['old_slugs'] ?? [] ), true ) ) {
                return [ 'redirect' => '/' . $page['slug'] ];
            }
        }
        return null;
    }

    /**
     * An old address of a product or category whose slug the owner changed:
     * { redirect: new path } (the storefront answers 301), or null.
     */
    private static function moved( string $type, string $slug ): ?array {
        global $wpdb;
        $old = sanitize_title( $slug );
        if ( $old === '' ) {
            return null;
        }
        if ( $type === 'product' ) {
            // WordPress keeps a product's earlier slugs in _wp_old_slug.
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
                 WHERE m.meta_key = '_wp_old_slug' AND m.meta_value = %s AND p.post_type = 'product' AND p.post_status = 'publish'
                 ORDER BY p.post_modified_gmt DESC LIMIT 1",
                $old
            ) );
            $post = $id ? get_post( $id ) : null;
            return $post && $post->post_name !== $old && self::product( $post->post_name ) ? [ 'redirect' => '/product/' . $post->post_name ] : null;
        }
        $id   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_qwoo_old_slug' AND meta_value = %s ORDER BY meta_id DESC LIMIT 1",
            $old
        ) );
        $term = $id ? get_term( $id, 'product_cat' ) : null;
        return $term && ! is_wp_error( $term ) && $term->slug !== $old ? [ 'redirect' => '/product-category/' . $term->slug ] : null;
    }

    /** Fields every answer carries. */
    private static function common(): array {
        return [
            'site_name' => self::store_name(),
            'locale'    => str_replace( '-', '_', get_locale() ),
        ];
    }

    private static function homepage(): array {
        $s        = self::settings();
        $defaults = self::home_defaults();
        return [
            'title'               => $s['home_title'] !== '' ? $s['home_title'] : $defaults['title'],
            'description'         => $s['home_description'] !== '' ? $s['home_description'] : $defaults['description'],
            'canonical'           => self::url( '/' ),
            'robots'              => self::robots( true ),
            'og_image'            => self::image_url( absint( $s['image_id'] ) ),
            'og_type'             => 'website',
            'type'                => 'home',
            'google_verification' => (string) $s['google_verification'],
        ];
    }

    private static function shop(): array {
        $shop_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
        $name    = $shop_id > 0 ? self::plain( get_the_title( $shop_id ) ) : '';
        return [
            'title'       => self::with_pattern( $name !== '' ? $name : 'Shop' ),
            'description' => self::shorten( self::plain( get_bloginfo( 'description' ) ) ),
            'canonical'   => self::url( '/products' ),
            'robots'      => self::robots( true ),
            'og_image'    => self::image_url( absint( self::settings()['image_id'] ) ),
            'og_type'     => 'website',
            'type'        => 'product_archive',
        ];
    }

    private static function product( string $slug ): ?array {
        $post = get_page_by_path( $slug, OBJECT, 'product' );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return null;
        }
        $product = wc_get_product( $post );
        if ( ! $product || $product->get_catalog_visibility() === 'hidden' ) {
            return null;
        }
        $custom      = self::custom( $post->ID );
        $description = $custom['description'] !== ''
            ? $custom['description']
            : self::shorten( self::plain( $product->get_short_description() ?: $product->get_description() ) );
        $image       = $custom['image_id'] ?: (int) $product->get_image_id();
        return [
            'title'       => $custom['title'] !== '' ? $custom['title'] : self::with_pattern( self::plain( $product->get_name() ) ),
            'description' => $description,
            'canonical'   => self::url( '/product/' . $post->post_name ),
            'robots'      => self::robots( ! self::is_noindex( $post->ID ) ),
            'og_image'    => self::image_url( $image ) ?: self::image_url( absint( self::settings()['image_id'] ) ),
            'og_type'     => 'product',
            'type'        => 'product',
            'modified'    => mysql2date( 'c', $post->post_modified_gmt, false ),
        ];
    }

    private static function category( string $slug ): ?array {
        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) {
            return null;
        }
        $custom = self::custom( (int) $term->term_id, 'term' );
        $image  = $custom['image_id'] ?: absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) );
        return [
            'title'       => $custom['title'] !== '' ? $custom['title'] : self::with_pattern( self::plain( $term->name ) ),
            'description' => $custom['description'] !== '' ? $custom['description'] : self::shorten( self::plain( $term->description ) ),
            'canonical'   => self::url( '/product-category/' . $term->slug ),
            // An empty category is a thin page: keep it out of search until it has products.
            'robots'      => self::robots( ! self::is_noindex( (int) $term->term_id, 'term' ) && (int) $term->count > 0 ),
            'og_image'    => self::image_url( $image ) ?: self::image_url( absint( self::settings()['image_id'] ) ),
            'og_type'     => 'website',
            'type'        => 'product_cat',
        ];
    }

    /* ---------------- qwoo/v1/sitemap ---------------- */

    public static function rest_sitemap() {
        $urls = [
            [ 'path' => '/', 'lastmod' => '' ],
            [ 'path' => '/products', 'lastmod' => '' ],
        ];

        // Hidden products ("Catalog visibility: hidden") carry both exclude terms.
        $hidden = [];
        if ( function_exists( 'wc_get_product_visibility_term_ids' ) ) {
            $terms  = wc_get_product_visibility_term_ids();
            $hidden = array_intersect(
                array_map( 'intval', (array) get_objects_in_term( (int) ( $terms['exclude-from-catalog'] ?? 0 ), 'product_visibility' ) ),
                array_map( 'intval', (array) get_objects_in_term( (int) ( $terms['exclude-from-search'] ?? 0 ), 'product_visibility' ) )
            );
        }
        $posts = get_posts( [
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => self::SITEMAP_PRODUCTS,
            'orderby'                => 'modified',
            'order'                  => 'DESC',
            'post__not_in'           => $hidden,
            'meta_query'             => [ [ 'key' => self::NOINDEX_META, 'compare' => 'NOT EXISTS' ] ],
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ] );
        $newest = '';
        foreach ( $posts as $post ) {
            $lastmod = mysql2date( 'c', $post->post_modified_gmt, false );
            $newest  = $newest ?: $lastmod;
            $urls[]  = [ 'path' => '/product/' . $post->post_name, 'lastmod' => $lastmod ];
        }
        $urls[1]['lastmod'] = $newest;

        // The owner's published pages, unless hidden from search engines.
        if ( class_exists( 'Shop_Settings_Builder' ) ) {
            foreach ( Shop_Settings_Builder::published_pages() as $page ) {
                if ( empty( $page['seo']['noindex'] ) && ! empty( $page['slug'] ) ) {
                    $urls[] = [ 'path' => '/' . $page['slug'], 'lastmod' => ! empty( $page['time'] ) ? gmdate( 'c', (int) $page['time'] ) : '' ];
                }
            }
        }

        // (No meta_query here: WooCommerce's category ordering joins term meta
        // too, and the two together match nothing.)
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 2000 ] );
        foreach ( is_wp_error( $terms ) ? [] : $terms as $term ) {
            if ( ! self::is_noindex( (int) $term->term_id, 'term' ) ) {
                $urls[] = [ 'path' => '/product-category/' . $term->slug, 'lastmod' => '' ];
            }
        }

        // base: the store's main address, so every alias lists the same URLs.
        return rest_ensure_response( [ 'base' => Qwoo_Technical_Settings::get_primary_frontend_domain(), 'urls' => $urls ] );
    }

    /* ---------------- helpers ---------------- */

    /** A full storefront address for a path ("" when no frontend domain is set yet). */
    public static function url( string $path ): string {
        $front = Qwoo_Technical_Settings::get_primary_frontend_domain();
        if ( $front === '' ) {
            return '';
        }
        return $path === '/' ? $front . '/' : $front . $path;
    }

    public static function store_name(): string {
        return self::plain( get_bloginfo( 'name' ) );
    }

    /** "{title} | {store}" filled in. */
    private static function with_pattern( string $title ): string {
        $pattern = (string) self::settings()['title_pattern'];
        if ( strpos( $pattern, '{title}' ) === false ) {
            $pattern = '{title} | {store}';
        }
        return preg_replace( '/^[\s|–-]+|[\s|–-]+$/u', '', strtr( $pattern, [ '{title}' => $title, '{store}' => self::store_name() ] ) );
    }

    private static function robots( bool $index ): string {
        return $index
            ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
            : 'noindex, follow';
    }

    private static function image_url( int $id ): string {
        if ( ! $id ) {
            return '';
        }
        $url = wp_get_attachment_image_url( $id, 'large' );
        return $url ? (string) $url : '';
    }

    /** Text without tags, entities or extra spaces. */
    public static function plain( $text ): string {
        $text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( (string) $text ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }

    /** Cut at a word boundary to what search results show. */
    public static function shorten( string $text, int $max = self::DESCRIPTION_LENGTH ): string {
        if ( mb_strlen( $text ) <= $max ) {
            return $text;
        }
        $cut   = mb_substr( $text, 0, $max - 1 );
        $space = mb_strrpos( $cut, ' ' );
        if ( $space !== false && $space > $max * 0.6 ) {
            $cut = mb_substr( $cut, 0, $space );
        }
        return preg_replace( '/[\s,.;:–-]+$/u', '', $cut ) . '…';
    }
}

Qwoo_Seo::init();

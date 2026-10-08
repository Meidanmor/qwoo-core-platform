<?php
/**
 * The store's SEO: what search engines and link previews see for each
 * storefront address, and the list of addresses for the sitemap.
 *
 * GET qwoo/v1/seo?path=…   { title, description, canonical, robots, og_image,
 *                            og_type, site_name, locale, … }
 * GET qwoo/v1/sitemap      { urls: [ { path, lastmod } ] }
 *
 * Addresses: the homepage, /products, /product/…, /product-category/…,
 * /blog, /blog/<post>, /blog/category/<category>, and the owner's pages.
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
    /**
     * A product's automatic title and description. The description is the
     * product's own text; a product without any gets "Name · Category · Store",
     * so no page goes out without one.
     */
    public static function product_defaults( WC_Product $product ): array {
        $text = self::plain( $product->get_short_description() ?: $product->get_description() );
        if ( $text === '' ) {
            $category = '';
            foreach ( $product->get_category_ids() as $id ) {
                $term = get_term( $id, 'product_cat' );
                if ( $term && ! is_wp_error( $term ) && (int) $term->term_id !== (int) get_option( 'default_product_cat' ) ) {
                    $category = self::plain( $term->name );
                    break;
                }
            }
            $text = implode( ' · ', array_filter( [ self::plain( $product->get_name() ), $category, self::store_name() ] ) );
        }
        return [
            'title'       => self::with_pattern( self::plain( $product->get_name() ) ),
            'description' => self::shorten( $text ),
        ];
    }

    /**
     * A category's automatic title and description: its own description, or
     * its name and a few of its products ("Rings: Gold hoop, Silver band…").
     */
    public static function term_defaults( WP_Term $term ): array {
        $text = self::plain( $term->description );
        if ( $text === '' ) {
            $names = function_exists( 'wc_get_products' ) ? wc_get_products( [
                'status'   => 'publish',
                'limit'    => 4,
                'category' => [ $term->slug ],
                'orderby'  => 'popularity',
                'return'   => 'objects',
            ] ) : [];
            $names = array_map( static fn( $p ) => self::plain( $p->get_name() ), $names );
            $text  = self::plain( $term->name ) . ( $names ? ': ' . implode( ', ', $names ) . '…' : ' · ' . self::store_name() );
        }
        return [
            'title'       => self::with_pattern( self::plain( $term->name ) ),
            'description' => self::shorten( $text ),
        ];
    }

    /** A blog post's automatic title and description: its title, and its summary or first words. */
    public static function post_defaults( WP_Post $post ): array {
        $text = class_exists( 'Qwoo_Blog' ) ? Qwoo_Blog::excerpt( $post ) : self::plain( $post->post_content );
        return [
            'title'       => self::with_pattern( self::plain( get_the_title( $post ) ) ),
            'description' => self::shorten( self::plain( $text ) ),
        ];
    }

    /** The homepage's automatic title and description: the tagline, else the homepage's hero text. */
    public static function home_defaults(): array {
        $name    = self::store_name();
        $tagline = self::plain( get_bloginfo( 'description' ) );
        $options = get_option( 'shop_builder_options', [] );
        $hero    = self::plain( implode( ' ', array_filter( [ $options['home']['hero_title'] ?? '', $options['home']['hero_description'] ?? '' ], 'is_string' ) ) );
        // Stores whose homepage is one of their pages: its first words.
        foreach ( class_exists( 'Shop_Settings_Builder' ) ? Shop_Settings_Builder::published_pages() : [] as $page ) {
            if ( ( $page['role'] ?? '' ) === 'home' && trim( (string) ( $page['excerpt'] ?? '' ) ) !== '' ) {
                $hero = self::plain( (string) $page['excerpt'] );
            }
        }
        return [
            'title'       => $tagline !== '' ? "$name – $tagline" : $name,
            'description' => self::shorten( $tagline !== '' ? $tagline : ( $hero !== '' ? $hero : $name ) ),
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
        if ( $path === 'blog' ) {
            return self::blog();
        }
        if ( preg_match( '#^blog/category/([^/]+)$#u', $path, $m ) ) {
            return self::blog_category( $m[1] );
        }
        if ( preg_match( '#^blog/([^/]+)$#u', $path, $m ) ) {
            return self::post( $m[1] ) ?? self::moved( 'post', $m[1] );
        }
        // The owner's own pages (/about, /about/team…).
        return self::page( $path );
    }

    /** "About Us/Team" → "about-us/team", each part like WordPress makes slugs. */
    private static function page_path( string $path ): string {
        $parts = array_filter( array_map( 'sanitize_title', explode( '/', $path ) ), 'strlen' );
        return implode( '/', $parts );
    }

    /** A published page of the owner's (or { redirect } for one of its old addresses), or null. */
    private static function page( string $path ): ?array {
        $path = self::page_path( $path );
        if ( $path === '' || ! class_exists( 'Shop_Settings_Builder' ) ) {
            return null;
        }
        $pages = Shop_Settings_Builder::published_pages();
        foreach ( $pages as $page ) {
            if ( ( $page['path'] ?? $page['slug'] ?? '' ) !== $path ) {
                continue;
            }
            $seo = (array) ( $page['seo'] ?? [] );
            return [
                'title'       => trim( (string) ( $seo['title'] ?? '' ) ) ?: self::with_pattern( self::plain( $page['title'] ?? '' ) ),
                // The page's own words when the owner didn't write a description.
                'description' => trim( (string) ( $seo['description'] ?? '' ) ) ?: self::shorten( self::plain( $page['excerpt'] ?? '' ) ),
                'canonical'   => self::url( '/' . $path ),
                'robots'      => self::robots( empty( $seo['noindex'] ) ),
                'og_image'    => self::image_url( absint( $seo['image_id'] ?? 0 ) ) ?: self::image_url( absint( self::settings()['image_id'] ) ),
                'og_type'     => 'website',
                'type'        => 'page',
                'page_id'     => (string) ( $page['id'] ?? '' ),
            ];
        }
        foreach ( $pages as $page ) {
            $old = array_merge( (array) ( $page['old_paths'] ?? [] ), (array) ( $page['old_slugs'] ?? [] ) );
            if ( in_array( $path, $old, true ) ) {
                return [ 'redirect' => '/' . ( $page['path'] ?? $page['slug'] ) ];
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
        if ( $type === 'product' || $type === 'post' ) {
            // WordPress keeps a product's or post's earlier slugs in _wp_old_slug.
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
                 WHERE m.meta_key = '_wp_old_slug' AND m.meta_value = %s AND p.post_type = %s AND p.post_status = 'publish'
                 ORDER BY p.post_modified_gmt DESC LIMIT 1",
                $old,
                $type
            ) );
            $post = $id ? get_post( $id ) : null;
            if ( ! $post || $post->post_name === $old ) {
                return null;
            }
            if ( $type === 'post' ) {
                return self::post( $post->post_name ) ? [ 'redirect' => '/blog/' . $post->post_name ] : null;
            }
            return self::product( $post->post_name ) ? [ 'redirect' => '/product/' . $post->post_name ] : null;
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
            'description' => self::home_defaults()['description'],
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
        $custom   = self::custom( $post->ID );
        $defaults = self::product_defaults( $product );
        $image    = $custom['image_id'] ?: (int) $product->get_image_id();
        return [
            'title'       => $custom['title'] !== '' ? $custom['title'] : $defaults['title'],
            'description' => $custom['description'] !== '' ? $custom['description'] : $defaults['description'],
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
        $custom   = self::custom( (int) $term->term_id, 'term' );
        $defaults = self::term_defaults( $term );
        $image    = $custom['image_id'] ?: absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) );
        return [
            'title'       => $custom['title'] !== '' ? $custom['title'] : $defaults['title'],
            'description' => $custom['description'] !== '' ? $custom['description'] : $defaults['description'],
            'canonical'   => self::url( '/product-category/' . $term->slug ),
            // An empty category is a thin page: keep it out of search until it has products.
            'robots'      => self::robots( ! self::is_noindex( (int) $term->term_id, 'term' ) && (int) $term->count > 0 ),
            'og_image'    => self::image_url( $image ) ?: self::image_url( absint( self::settings()['image_id'] ) ),
            'og_type'     => 'website',
            'type'        => 'product_cat',
        ];
    }

    /** The blog's list: its own title, the store's description. Kept out of search until it has posts. */
    private static function blog(): array {
        $has_posts = (bool) get_posts( [ 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ] );
        return [
            'title'       => self::with_pattern( 'Blog' ),
            'description' => self::home_defaults()['description'],
            'canonical'   => self::url( '/blog' ),
            'robots'      => self::robots( $has_posts ),
            'og_image'    => self::image_url( absint( self::settings()['image_id'] ) ),
            'og_type'     => 'website',
            'type'        => 'blog',
        ];
    }

    private static function blog_category( string $slug ): ?array {
        $term = get_term_by( 'slug', sanitize_title( $slug ), 'category' );
        if ( ! $term || is_wp_error( $term ) || (int) $term->term_id === (int) get_option( 'default_category' ) ) {
            return null;
        }
        $text = self::plain( $term->description );
        return [
            'title'       => self::with_pattern( self::plain( $term->name ) ),
            'description' => self::shorten( $text !== '' ? $text : self::plain( $term->name ) . ' · ' . self::store_name() ),
            'canonical'   => self::url( '/blog/category/' . $term->slug ),
            'robots'      => self::robots( (int) $term->count > 0 ),
            'og_image'    => self::image_url( absint( self::settings()['image_id'] ) ),
            'og_type'     => 'website',
            'type'        => 'blog_category',
        ];
    }

    private static function post( string $slug ): ?array {
        $post = get_page_by_path( sanitize_title( $slug ), OBJECT, 'post' );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return null;
        }
        $custom   = self::custom( $post->ID );
        $defaults = self::post_defaults( $post );
        $image    = $custom['image_id'] ?: (int) get_post_thumbnail_id( $post );
        return [
            'title'       => $custom['title'] !== '' ? $custom['title'] : $defaults['title'],
            'description' => $custom['description'] !== '' ? $custom['description'] : $defaults['description'],
            'canonical'   => self::url( '/blog/' . $post->post_name ),
            'robots'      => self::robots( ! self::is_noindex( $post->ID ) ),
            'og_image'    => self::image_url( $image ) ?: self::image_url( absint( self::settings()['image_id'] ) ),
            'og_type'     => 'article',
            'type'        => 'post',
            'published'   => mysql2date( 'c', $post->post_date_gmt, false ),
            'modified'    => mysql2date( 'c', $post->post_modified_gmt, false ),
        ];
    }

    /* ---------------- SEO check (dashboard) ---------------- */

    /** Products the check looks at (the newest ones first). */
    const AUDIT_PRODUCTS = 300;

    /**
     * What's missing or weak, page by page, as search engines would see it
     * (the same titles and descriptions the storefront sends):
     *   { counts: { error, warn, tip }, checked: { products, categories, pages },
     *     items: [ { kind: home|product|category|page, id, name, issues: [ { level, text } ] } ] }
     * Only pages with something to say are listed.
     */
    public static function audit(): array {
        $entries = []; // [ kind, id, name, title, description, custom description?, extra issues ]

        // The homepage.
        $s    = self::settings();
        $home = self::homepage();
        $more = [];
        if ( self::plain( get_bloginfo( 'description' ) ) === '' && $s['home_description'] === '' ) {
            $more[] = [ 'warn', 'No description of your store: write one here (it also helps your other pages).' ];
        }
        if ( ! absint( $s['image_id'] ) ) {
            $more[] = [ 'tip', 'No image for shares: links to your store on WhatsApp or Facebook show without a picture.' ];
        }
        if ( $s['google_verification'] === '' ) {
            $more[] = [ 'tip', 'Connect Google Search Console (below) to see how your store does on Google.' ];
        }
        $entries[] = [ 'home', 0, 'Homepage', $home['title'], $home['description'], $s['home_description'] !== '', false, $more ];

        // Products.
        $products = function_exists( 'wc_get_products' ) ? wc_get_products( [ 'status' => 'publish', 'limit' => self::AUDIT_PRODUCTS, 'orderby' => 'date', 'order' => 'DESC' ] ) : [];
        foreach ( $products as $product ) {
            if ( $product->get_catalog_visibility() === 'hidden' ) continue;
            $custom   = self::custom( $product->get_id() );
            $defaults = self::product_defaults( $product );
            $more     = [];
            if ( $custom['description'] === '' && self::plain( $product->get_short_description() ?: $product->get_description() ) === '' ) {
                $more[] = [ 'warn', 'No description: add one to the product, or write one in its search listing.' ];
            }
            if ( ! $product->get_image_id() && ! $custom['image_id'] ) {
                $more[] = [ 'warn', 'No photo: products without a photo rarely get clicks.' ];
            }
            $entries[] = [ 'product', $product->get_id(), self::plain( $product->get_name() ), $custom['title'] ?: $defaults['title'], $custom['description'] ?: $defaults['description'], $custom['description'] !== '', self::is_noindex( $product->get_id() ), $more ];
        }

        // Categories.
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500 ] );
        foreach ( is_wp_error( $terms ) ? [] : $terms as $term ) {
            $custom   = self::custom( (int) $term->term_id, 'term' );
            $defaults = self::term_defaults( $term );
            $more     = [];
            if ( (int) $term->count === 0 ) {
                $more[] = [ 'tip', 'No products yet: it stays out of search engines until it has some.' ];
            }
            $entries[] = [ 'category', (int) $term->term_id, self::plain( $term->name ), $custom['title'] ?: $defaults['title'], $custom['description'] ?: $defaults['description'], $custom['description'] !== '', self::is_noindex( (int) $term->term_id, 'term' ), $more ];
        }

        // The owner's published pages (as saved: what the next Publish puts live).
        $pages = 0;
        if ( class_exists( 'Shop_Settings_Builder' ) ) {
            $options = get_option( 'shop_builder_options', [] );
            foreach ( (array) ( $options['custom_pages'] ?? [] ) as $page ) {
                if ( ! is_array( $page ) || ( $page['status'] ?? '' ) !== 'publish' ) continue;
                $pages++;
                $seo     = (array) ( $page['seo'] ?? [] );
                $excerpt = Shop_Settings_Builder::platform_page_excerpt( (array) ( $page['sections'] ?? [] ) );
                $more    = [];
                if ( ! array_filter( (array) ( $page['sections'] ?? [] ), static fn( $s ) => ! empty( $s['enabled'] ) ) ) {
                    $more[] = [ 'warn', 'The page is empty: add sections to it.' ];
                } elseif ( ( $seo['description'] ?? '' ) === '' && $excerpt === '' ) {
                    $more[] = [ 'warn', 'No description: the page has no text to describe it. Write one in its search listing.' ];
                }
                $title       = trim( (string) ( $seo['title'] ?? '' ) ) ?: self::with_pattern( self::plain( $page['title'] ?? '' ) );
                $description = trim( (string) ( $seo['description'] ?? '' ) ) ?: self::shorten( $excerpt );
                $entries[]   = [ 'page', (string) $page['id'], self::plain( $page['title'] ?? '' ), $title, $description, ( $seo['description'] ?? '' ) !== '', ! empty( $seo['noindex'] ), $more ];
            }
        }

        // Titles used more than once (Google may show only one of them).
        $titles = [];
        foreach ( $entries as $e ) {
            if ( ! $e[6] ) $titles[ mb_strtolower( $e[3] ) ][] = $e[2];
        }

        $items  = [];
        $counts = [ 'error' => 0, 'warn' => 0, 'tip' => 0 ];
        foreach ( $entries as [ $kind, $id, $name, $title, $description, $own_description, $noindex, $more ] ) {
            $issues = [];
            foreach ( $more as [ $level, $text ] ) {
                $issues[] = [ 'level' => $level, 'text' => $text ];
            }
            if ( $noindex ) {
                $issues[] = [ 'level' => 'tip', 'text' => 'Hidden from search engines (your choice in its search listing).' ];
            } else {
                $length = mb_strlen( $title );
                if ( $length > 60 ) {
                    $issues[] = [ 'level' => 'warn', 'text' => "The title is $length characters: Google shows about 60, the rest is cut off." ];
                } elseif ( $length < 15 ) {
                    $issues[] = [ 'level' => 'tip', 'text' => 'The title is very short: say what the page is about, in the words shoppers search for.' ];
                }
                $others = array_diff( $titles[ mb_strtolower( $title ) ] ?? [], [ $name ] );
                if ( count( $titles[ mb_strtolower( $title ) ] ?? [] ) > 1 ) {
                    $issues[] = [ 'level' => 'warn', 'text' => 'Same title as ' . ( $others ? '"' . implode( '", "', array_slice( $others, 0, 2 ) ) . '"' : 'another page' ) . ': give each page its own title.' ];
                }
                if ( $description === '' ) {
                    $issues[] = [ 'level' => 'error', 'text' => 'No description: search engines and PageSpeed will flag it.' ];
                } elseif ( $own_description && mb_strlen( $description ) > 160 ) {
                    $issues[] = [ 'level' => 'warn', 'text' => 'The description is ' . mb_strlen( $description ) . ' characters: Google shows about 155.' ];
                } elseif ( mb_strlen( $description ) < 50 ) {
                    $issues[] = [ 'level' => 'tip', 'text' => 'The description is short: a sentence or two about the page usually gets more clicks.' ];
                }
            }
            if ( ! $issues ) continue;
            foreach ( $issues as $issue ) {
                $counts[ $issue['level'] ]++;
            }
            // The most important first.
            usort( $issues, static fn( $a, $b ) => array_search( $a['level'], [ 'error', 'warn', 'tip' ], true ) <=> array_search( $b['level'], [ 'error', 'warn', 'tip' ], true ) );
            $items[] = [ 'kind' => $kind, 'id' => $id, 'name' => $name, 'issues' => $issues ];
        }
        $rank = static fn( $item ) => array_search( $item['issues'][0]['level'], [ 'error', 'warn', 'tip' ], true );
        usort( $items, static fn( $a, $b ) => $rank( $a ) <=> $rank( $b ) );

        return [
            'counts'  => $counts,
            'checked' => [ 'products' => count( $products ), 'categories' => is_wp_error( $terms ) ? 0 : count( $terms ), 'pages' => $pages ],
            'items'   => $items,
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
                $path = (string) ( $page['path'] ?? $page['slug'] ?? '' );
                if ( empty( $page['seo']['noindex'] ) && $path !== '' ) {
                    $urls[] = [ 'path' => '/' . $path, 'lastmod' => ! empty( $page['time'] ) ? gmdate( 'c', (int) $page['time'] ) : '' ];
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

        // The blog: its list, its posts, and categories with posts.
        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 5000,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'meta_query'     => [ [ 'key' => self::NOINDEX_META, 'compare' => 'NOT EXISTS' ] ],
            'no_found_rows'  => true,
        ] );
        if ( $posts ) {
            $urls[] = [ 'path' => '/blog', 'lastmod' => mysql2date( 'c', $posts[0]->post_modified_gmt, false ) ];
            foreach ( $posts as $post ) {
                $urls[] = [ 'path' => '/blog/' . $post->post_name, 'lastmod' => mysql2date( 'c', $post->post_modified_gmt, false ) ];
            }
            $cats = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => true, 'exclude' => [ (int) get_option( 'default_category' ) ], 'number' => 500 ] );
            foreach ( is_wp_error( $cats ) ? [] : $cats as $term ) {
                $urls[] = [ 'path' => '/blog/category/' . $term->slug, 'lastmod' => '' ];
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

    /** Text without tags, entities or extra spaces (paragraphs, list items and line breaks become spaces). */
    public static function plain( $text ): string {
        $text = preg_replace( '#<(br|/p|/li|/h[1-6]|/div|/blockquote)\b[^>]*>#i', '$0 ', strip_shortcodes( (string) $text ) );
        $text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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

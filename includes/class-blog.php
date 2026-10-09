<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The store's blog for the storefront: WordPress posts and their
 * categories, read live (no Store builder publish step).
 *
 *   GET qwoo/v1/blog?page=&per_page=&category=   published posts, newest first
 *   GET qwoo/v1/blog/post?slug=                  one post, with a few more to read
 *
 * Addresses on the storefront: /blog, /blog/<post>, /blog/category/<category>.
 * Scheduled posts (status future) go live on the storefront's next API
 * request after their time (checked every minute at most; no cron).
 *
 * Post text keeps BLOG_TAGS only, and images only from this store's uploads.
 * WordPress's default category ("Uncategorized") is never shown: a post in
 * it has no category as far as the storefront is concerned.
 */
class Qwoo_Blog {

    const PER_PAGE  = 9;
    const DUE_KEY   = 'qwoo_blog_due_checked';
    const CLEAN_KEY = 'qwoo_blog_sample_removed';

    /** What a post's text can have (the dashboard's editor sends the same). */
    const TAGS = [
        'p'          => [],
        'br'         => [],
        'strong'     => [],
        'b'          => [],
        'em'         => [],
        'i'          => [],
        'u'          => [],
        'h2'         => [],
        'h3'         => [],
        'h4'         => [],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'blockquote' => [],
        'hr'         => [],
        'a'          => [ 'href' => [], 'title' => [] ],
        'img'        => [ 'src' => [], 'alt' => [], 'width' => [], 'height' => [] ],
    ];

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'publish_due' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'remove_sample_post' ] );
    }

    public static function routes() {
        register_rest_route( 'qwoo/v1', '/blog', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_list' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'qwoo/v1', '/blog/post', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_post' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /* ---------------- storefront ---------------- */

    /**
     * { posts: [ card ], page, pages, total, categories: [ { name, slug, count } ],
     *   category: { name, slug, description } | null }.
     */
    public static function rest_list( WP_REST_Request $r ) {
        $page     = max( 1, min( 1000, (int) $r->get_param( 'page' ) ) );
        $per_page = max( 1, min( 24, (int) ( $r->get_param( 'per_page' ) ?: self::PER_PAGE ) ) );
        $args     = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        $category = null;
        $slug     = (string) $r->get_param( 'category' );
        if ( $slug !== '' ) {
            $term = get_term_by( 'slug', sanitize_title( rawurldecode( $slug ) ), 'category' );
            if ( ! $term || is_wp_error( $term ) || (int) $term->term_id === (int) get_option( 'default_category' ) ) {
                return new WP_Error( 'qwoo_blog_not_found', 'This blog category doesn\'t exist.', [ 'status' => 404 ] );
            }
            $args['cat'] = (int) $term->term_id;
            $category    = [ 'name' => html_entity_decode( $term->name, ENT_QUOTES ), 'slug' => urldecode( $term->slug ), 'description' => wp_strip_all_tags( $term->description ) ];
        }
        $query = new WP_Query( self::only_translated( $args ) );
        return rest_ensure_response( [
            'posts'      => array_map( [ __CLASS__, 'card' ], array_map( [ __CLASS__, 'in_language' ], $query->posts ) ),
            'page'       => $page,
            'pages'      => (int) $query->max_num_pages,
            'total'      => (int) $query->found_posts,
            'categories' => self::categories(),
            'category'   => $category,
        ] );
    }

    /** One published post: its card, text and three more to read (same category first). */
    public static function rest_post( WP_REST_Request $r ) {
        $slug = sanitize_title( rawurldecode( (string) $r->get_param( 'slug' ) ) );
        $post = $slug !== '' ? get_page_by_path( $slug, OBJECT, 'post' ) : null;
        // In an extra language, a post without a translation doesn't exist there.
        $post = $post && $post->post_status === 'publish' ? self::in_language( $post, true ) : null;
        if ( ! $post ) {
            return new WP_Error( 'qwoo_blog_not_found', 'This post doesn\'t exist.', [ 'status' => 404 ] );
        }
        $cats = self::post_categories( $post->ID );
        $more = $cats ? get_posts( self::only_translated( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 3,
            'post__not_in'   => [ $post->ID ],
            'category__in'   => array_column( $cats, 'id' ),
        ] ) ) : [];
        if ( count( $more ) < 3 ) {
            $more = array_merge( $more, get_posts( self::only_translated( [ 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 3 - count( $more ), 'post__not_in' => array_merge( [ $post->ID ], wp_list_pluck( $more, 'ID' ) ) ] ) ) );
        }
        $more = array_map( [ __CLASS__, 'in_language' ], $more );
        return rest_ensure_response( self::card( $post ) + [
            'content'  => self::clean_content( $post->post_content ),
            'modified' => (int) strtotime( $post->post_modified_gmt . ' UTC' ),
            'more'     => array_map( [ __CLASS__, 'card' ], $more ),
        ] );
    }

    /** Query args limited to posts translated into the request's language (unchanged in the main language). */
    private static function only_translated( array $args ) {
        $lang = class_exists( 'Qwoo_Store_Language' ) ? Qwoo_Store_Language::request_lang() : '';
        if ( $lang !== '' ) {
            $args['meta_query'] = [ [ 'key' => Qwoo_Translations::META, 'value' => '"' . $lang . '"', 'compare' => 'LIKE' ] ];
        }
        return $args;
    }

    /**
     * The post with its texts in the request's language. $strict: null when
     * it has no translation (else the post as it is).
     */
    public static function in_language( $post, $strict = false ) {
        $lang = class_exists( 'Qwoo_Store_Language' ) ? Qwoo_Store_Language::request_lang() : '';
        if ( $lang === '' || ! ( $post instanceof WP_Post ) ) {
            return $post;
        }
        $tr = Qwoo_Translations::get( $post->ID )[ $lang ] ?? [];
        if ( empty( $tr['title'] ) ) {
            return $strict ? null : $post;
        }
        $copy               = clone $post;
        $copy->post_title   = (string) $tr['title'];
        $copy->post_content = (string) ( $tr['content'] ?? '' );
        $copy->post_excerpt = (string) ( $tr['excerpt'] ?? '' );
        return $copy;
    }

    /** A post as lists show it. */
    public static function card( WP_Post $post ) {
        $image_id = (int) get_post_thumbnail_id( $post );
        $image    = $image_id ? wp_get_attachment_image_src( $image_id, 'large' ) : false;
        return [
            'id'         => (int) $post->ID,
            'slug'       => urldecode( $post->post_name ),
            'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
            'excerpt'    => self::excerpt( $post ),
            'date'       => (int) strtotime( $post->post_date_gmt . ' UTC' ),
            // The day in the store's time zone, as the storefront shows it.
            'day'        => mysql2date( 'Y-m-d', $post->post_date, false ),
            'image'      => $image ? [
                'url'    => $image[0],
                'width'  => (int) $image[1],
                'height' => (int) $image[2],
                'alt'    => (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
            ] : null,
            'categories' => array_map( static fn( $c ) => [ 'name' => $c['name'], 'slug' => $c['slug'] ], self::post_categories( $post->ID ) ),
        ];
    }

    /** The owner's excerpt, or the first words of the post. */
    public static function excerpt( WP_Post $post ) {
        $own = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
        if ( $own !== '' ) {
            return html_entity_decode( $own, ENT_QUOTES );
        }
        $text = class_exists( 'Qwoo_Seo' ) ? Qwoo_Seo::plain( $post->post_content ) : wp_strip_all_tags( $post->post_content );
        return wp_trim_words( $text, 30, '…' );
    }

    /** [ { id, name, slug } ] without the default category. */
    public static function post_categories( $post_id ) {
        $default = (int) get_option( 'default_category' );
        $out     = [];
        foreach ( get_the_category( $post_id ) as $term ) {
            if ( (int) $term->term_id !== $default ) {
                $out[] = [ 'id' => (int) $term->term_id, 'name' => html_entity_decode( $term->name, ENT_QUOTES ), 'slug' => urldecode( $term->slug ) ];
            }
        }
        return $out;
    }

    /** Categories with published posts (the default one never). */
    public static function categories() {
        $terms = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => true, 'exclude' => [ (int) get_option( 'default_category' ) ], 'number' => 200 ] );
        return array_map( static fn( $t ) => [ 'name' => html_entity_decode( $t->name, ENT_QUOTES ), 'slug' => urldecode( $t->slug ), 'count' => (int) $t->count ], is_wp_error( $terms ) ? [] : $terms );
    }

    /* ---------------- text ---------------- */

    /**
     * A post's text: TAGS only, http(s)/mailto/tel links, images only from
     * this store's uploads (no tracking pixels or hotlinked files), no empty
     * paragraphs.
     */
    public static function clean_content( $html ) {
        $html    = preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', (string) $html );
        $html    = wp_kses( $html, self::TAGS, [ 'http', 'https', 'mailto', 'tel' ] );
        $uploads = preg_replace( '#^https?:#', '', (string) wp_get_upload_dir()['baseurl'] );
        $html    = preg_replace_callback( '#<img\b[^>]*>#i', static function ( $m ) use ( $uploads ) {
            if ( ! preg_match( '#\ssrc="([^"]+)"#i', $m[0], $src ) ) {
                return '';
            }
            $url = preg_replace( '#^https?:#', '', html_entity_decode( $src[1] ) );
            return $uploads !== '' && strpos( $url, $uploads . '/' ) === 0 ? $m[0] : '';
        }, $html );
        $html = preg_replace( '#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html );
        return trim( $html );
    }

    /* ---------------- scheduled posts and the sample post ---------------- */

    /** Scheduled posts whose time has come go live (at most every minute). */
    public static function publish_due() {
        if ( get_transient( self::DUE_KEY ) ) {
            return;
        }
        set_transient( self::DUE_KEY, 1, MINUTE_IN_SECONDS );
        $due = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'future',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'date_query'     => [ [ 'column' => 'post_date_gmt', 'before' => gmdate( 'Y-m-d H:i:s' ), 'inclusive' => true ] ],
        ] );
        foreach ( $due as $id ) {
            wp_publish_post( (int) $id );
        }
    }

    /**
     * WordPress starts every site with a "Hello world!" post. Once, it goes
     * to the trash (only if nobody changed it), so a new blog starts empty.
     */
    public static function remove_sample_post() {
        if ( get_option( self::CLEAN_KEY ) ) {
            return;
        }
        update_option( self::CLEAN_KEY, 1, true );
        $post = get_page_by_path( 'hello-world', OBJECT, 'post' );
        if ( $post && $post->post_status === 'publish' && $post->post_modified_gmt === $post->post_date_gmt && strpos( $post->post_content, 'Welcome to WordPress' ) !== false ) {
            wp_trash_post( $post->ID );
        }
    }
}

Qwoo_Blog::init();

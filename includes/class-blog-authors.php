<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Blog authors: who wrote each post, kept as terms of the private taxonomy
 * qwoo_author (name, address, and term meta: bio, photo, SEO). A post has one
 * author; a post without one shows the default author, made once with the
 * store's name. Posts written from the dashboard have no WordPress user, so
 * WordPress's own post author isn't used.
 *
 * Author profiles on the storefront are the owner's choice (option
 * qwoo_blog_authors.public): when on, /blog/author/<author> lists an author's
 * posts with their profile and /blog/authors lists the authors, each with its
 * own Store builder template (blog_author, blog_authors) and SEO.
 *
 *   GET qwoo/v1/blog/authors       the authors with posts (404 while profiles are off)
 *   GET qwoo/v1/blog?author=<slug> an author's posts (Qwoo_Blog::rest_list)
 */
class Qwoo_Blog_Authors {

    const TAX        = 'qwoo_author';
    const OPTION     = 'qwoo_blog_authors';
    const BIO_META   = '_qwoo_author_bio';
    const IMAGE_META = '_qwoo_author_image';
    const MAX        = 200;

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function register() {
        register_taxonomy( self::TAX, 'post', [
            'public'            => false,
            'show_ui'           => false,
            'show_in_rest'      => false,
            'hierarchical'      => false,
            'rewrite'           => false,
            'query_var'         => false,
            'show_admin_column' => false,
        ] );
    }

    public static function routes() {
        register_rest_route( 'qwoo/v1', '/blog/authors', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_authors' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /* ---------------- settings ---------------- */

    /** { public: bool, default: term id }. */
    public static function settings(): array {
        $s = get_option( self::OPTION, [] );
        return [ 'public' => ! empty( $s['public'] ), 'default' => (int) ( $s['default'] ?? 0 ) ];
    }

    public static function is_public(): bool {
        return self::settings()['public'];
    }

    public static function save_settings( array $changes ): void {
        update_option( self::OPTION, array_merge( self::settings(), $changes ), false );
    }

    /** The default author's term (made with the store's name the first time it's needed). */
    public static function default_author(): ?WP_Term {
        if ( ! taxonomy_exists( self::TAX ) ) {
            self::register();
        }
        $id   = self::settings()['default'];
        $term = $id ? get_term( $id, self::TAX ) : null;
        if ( $term instanceof WP_Term ) {
            return $term;
        }
        // The default was deleted (or there's none yet): the first author, else a new one.
        $first = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false, 'number' => 1, 'orderby' => 'term_id' ] );
        if ( ! is_wp_error( $first ) && $first ) {
            $term = $first[0];
        } else {
            $name   = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ?: 'Store';
            $result = wp_insert_term( mb_substr( $name, 0, 100 ), self::TAX );
            if ( is_wp_error( $result ) ) {
                $existing = (int) ( $result->get_error_data( 'term_exists' ) ?: 0 );
                $term     = $existing ? get_term( $existing, self::TAX ) : null;
            } else {
                $term = get_term( (int) $result['term_id'], self::TAX );
            }
        }
        if ( $term instanceof WP_Term ) {
            self::save_settings( [ 'default' => (int) $term->term_id ] );
            return $term;
        }
        return null;
    }

    /** The post's author, else the default one. */
    public static function of_post( int $post_id ): ?WP_Term {
        $terms = wp_get_object_terms( $post_id, self::TAX );
        return ! is_wp_error( $terms ) && $terms ? $terms[0] : self::default_author();
    }

    public static function set_for_post( int $post_id, int $author_id ): void {
        wp_set_object_terms( $post_id, [ $author_id ], self::TAX, false );
    }

    public static function find( int $id ): ?WP_Term {
        $term = $id ? get_term( $id, self::TAX ) : null;
        return $term instanceof WP_Term ? $term : null;
    }

    public static function find_by_slug( string $slug ): ?WP_Term {
        $term = get_term_by( 'slug', sanitize_title( rawurldecode( $slug ) ), self::TAX );
        return $term instanceof WP_Term ? $term : null;
    }

    /** Published posts per author (the default author also counts posts without one). */
    public static function post_counts(): array {
        global $wpdb;
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT tt.term_id, COUNT(p.ID) AS n FROM {$wpdb->term_taxonomy} tt
             JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
             JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'post' AND p.post_status = 'publish'
             WHERE tt.taxonomy = %s GROUP BY tt.term_id",
            self::TAX
        ) );
        $counts = [];
        foreach ( (array) $rows as $row ) {
            $counts[ (int) $row->term_id ] = (int) $row->n;
        }
        $default = self::default_author();
        if ( $default ) {
            $counts[ (int) $default->term_id ] = ( $counts[ (int) $default->term_id ] ?? 0 ) + count( self::posts_without_author( 'publish' ) );
        }
        return $counts;
    }

    /** IDs of posts with no author term (they show the default author). */
    public static function posts_without_author( $status = [ 'publish', 'draft', 'future' ] ): array {
        return get_posts( [
            'post_type'      => 'post',
            'post_status'    => $status,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [ [ 'taxonomy' => self::TAX, 'operator' => 'NOT EXISTS' ] ],
        ] );
    }

    /**
     * Query args for an author's posts. The default author's posts include
     * the ones without an author.
     */
    public static function tax_query( WP_Term $term ): array {
        $default = self::default_author();
        $mine    = [ 'taxonomy' => self::TAX, 'field' => 'term_id', 'terms' => [ (int) $term->term_id ] ];
        if ( $default && (int) $default->term_id === (int) $term->term_id ) {
            return [ 'relation' => 'OR', $mine, [ 'taxonomy' => self::TAX, 'operator' => 'NOT EXISTS' ] ];
        }
        return [ $mine ];
    }

    /* ---------------- shapes ---------------- */

    public static function image( WP_Term $term, string $size = 'thumbnail' ): ?array {
        $id  = (int) get_term_meta( $term->term_id, self::IMAGE_META, true );
        $src = $id ? wp_get_attachment_image_src( $id, $size ) : false;
        return $src ? [ 'url' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2], 'alt' => html_entity_decode( $term->name, ENT_QUOTES ) ] : null;
    }

    /** What the storefront shows: name, photo, bio, and the profile's address while profiles are on. */
    public static function card( WP_Term $term, bool $full = false ): array {
        $out = [
            'id'    => (int) $term->term_id,
            'name'  => html_entity_decode( $term->name, ENT_QUOTES ),
            'slug'  => urldecode( $term->slug ),
            'url'   => self::is_public() ? '/blog/author/' . urldecode( $term->slug ) : '',
            'image' => self::image( $term, $full ? 'medium' : 'thumbnail' ),
        ];
        if ( $full ) {
            $out['bio'] = (string) get_term_meta( $term->term_id, self::BIO_META, true );
        }
        return $out;
    }

    /* ---------------- storefront ---------------- */

    /** { authors: [ card + count ] }: authors with published posts, most posts first. */
    public static function rest_authors() {
        if ( ! self::is_public() ) {
            return new WP_Error( 'qwoo_blog_not_found', 'Not found.', [ 'status' => 404 ] );
        }
        $counts = self::post_counts();
        $terms  = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false, 'number' => self::MAX ] );
        $out    = [];
        foreach ( is_wp_error( $terms ) ? [] : $terms as $term ) {
            $n = $counts[ (int) $term->term_id ] ?? 0;
            if ( $n > 0 ) {
                $out[] = self::card( $term, true ) + [ 'count' => $n ];
            }
        }
        usort( $out, static fn( $a, $b ) => $b['count'] <=> $a['count'] ?: strcasecmp( $a['name'], $b['name'] ) );
        return rest_ensure_response( [ 'authors' => $out ] );
    }
}

Qwoo_Blog_Authors::init();

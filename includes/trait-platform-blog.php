<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The blog for the platform dashboard (used by Qwoo_Platform_Dashboard):
 * posts (draft, published, scheduled) and blog categories. The storefront
 * side is Qwoo_Blog.
 */
trait Qwoo_Platform_Blog {

    private static $post_statuses = [ 'draft', 'publish', 'future' ];

    /** { page, search, filter: '' | publish | future | draft, category (id) }: newest first, 20 a page. */
    private static function action_posts_list( array $params ) {
        $page   = max( 1, min( 1000, (int) ( $params['page'] ?? 1 ) ) );
        $filter = in_array( $params['filter'] ?? '', self::$post_statuses, true ) ? $params['filter'] : self::$post_statuses;
        $args   = [
            'post_type'      => 'post',
            'post_status'    => $filter,
            'posts_per_page' => 20,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        $search = mb_substr( sanitize_text_field( (string) ( $params['search'] ?? '' ) ), 0, 100 );
        if ( $search !== '' ) {
            $args['s'] = $search;
        }
        if ( absint( $params['category'] ?? 0 ) ) {
            $args['cat'] = absint( $params['category'] );
        }
        $query  = new WP_Query( $args );
        $counts = wp_count_posts( 'post' );
        return [
            'items'      => array_map( [ __CLASS__, 'post_summary' ], $query->posts ),
            'total'      => (int) $query->found_posts,
            'pages'      => (int) $query->max_num_pages,
            'page'       => $page,
            'counts'     => [ 'publish' => (int) $counts->publish, 'future' => (int) $counts->future, 'draft' => (int) $counts->draft ],
            'categories' => self::blog_category_list(),
        ];
    }

    private static function action_post_get( array $params ) {
        $post = self::find_post( $params['id'] ?? 0 );
        return is_wp_error( $post ) ? $post : self::post_full( $post );
    }

    /**
     * Creates (no id) or updates a post from params.fields: title,
     * content_html (Qwoo_Blog::TAGS), excerpt, status (draft | publish |
     * future), date (YYYY-MM-DDTHH:MM in the store's time zone, for future),
     * slug, image_id (the cover), category_ids, seo.
     */
    private static function action_post_save( array $params ) {
        $f    = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];
        $id   = absint( $params['id'] ?? 0 );
        $post = null;
        if ( $id ) {
            $post = self::find_post( $id );
            if ( is_wp_error( $post ) ) {
                return $post;
            }
        }

        $title = trim( sanitize_text_field( (string) ( $f['title'] ?? '' ) ) );
        if ( $title === '' || mb_strlen( $title ) > 200 ) {
            return self::bad( 'Give the post a title (up to 200 characters).' );
        }
        $content = (string) ( $f['content_html'] ?? '' );
        if ( mb_strlen( $content ) > 200000 ) {
            return self::bad( 'The post is too long. Split it into two posts.' );
        }
        $content = Qwoo_Blog::clean_content( $content );
        $excerpt = trim( sanitize_textarea_field( (string) ( $f['excerpt'] ?? '' ) ) );
        if ( mb_strlen( $excerpt ) > 300 ) {
            return self::bad( 'The summary can be up to 300 characters.' );
        }
        $status = in_array( $f['status'] ?? '', self::$post_statuses, true ) ? $f['status'] : 'draft';
        if ( $status === 'publish' && trim( wp_strip_all_tags( $content ) ) === '' && strpos( $content, '<img' ) === false ) {
            return self::bad( 'Write the post before publishing it.' );
        }

        // When it shows: now for a new post going live, the chosen time for a scheduled one,
        // and a published post keeps its date.
        $dates = [];
        if ( $status === 'future' ) {
            $local = str_replace( 'T', ' ', (string) ( $f['date'] ?? '' ) );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $local ) || ! strtotime( $local ) ) {
                return self::bad( 'Choose the day and time to publish the post.' );
            }
            $gmt = get_gmt_from_date( $local . ':00' );
            if ( strtotime( $gmt . ' UTC' ) <= time() + 60 ) {
                return self::bad( 'Choose a time in the future, or publish it now.' );
            }
            $dates = [ 'post_date' => $local . ':00', 'post_date_gmt' => $gmt, 'edit_date' => true ];
        } elseif ( $status === 'publish' && ( ! $post || $post->post_status !== 'publish' ) ) {
            $dates = [ 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ), 'edit_date' => true ];
        } elseif ( $status === 'draft' && $post && $post->post_status === 'future' ) {
            // No longer scheduled: no date until it's published.
            $dates = [ 'post_date_gmt' => '0000-00-00 00:00:00', 'edit_date' => false ];
        }

        $image = absint( $f['image_id'] ?? 0 );
        if ( $image && ! wp_attachment_is_image( $image ) ) {
            return self::bad( 'The cover image is missing. Upload it again.' );
        }
        $categories = array_values( array_filter( array_unique( array_map( 'absint', (array) ( $f['category_ids'] ?? [] ) ) ) ) );
        foreach ( $categories as $category ) {
            $term = get_term( $category, 'category' );
            if ( ! $term || is_wp_error( $term ) ) {
                return self::bad( 'One of the categories doesn\'t exist any more.' );
            }
        }
        $seo = null;
        if ( array_key_exists( 'seo', $f ) ) {
            $seo = Qwoo_Seo::clean_input( $f['seo'] );
            if ( is_wp_error( $seo ) ) {
                return $seo;
            }
        }

        $data = [
            'post_type'    => 'post',
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => $status,
        ] + $dates;
        if ( array_key_exists( 'slug', $f ) && trim( (string) $f['slug'] ) !== '' ) {
            $data['post_name'] = sanitize_title( mb_substr( (string) $f['slug'], 0, 190 ) );
        }
        if ( $post ) {
            $data['ID'] = $post->ID;
            $id         = wp_update_post( wp_slash( $data ), true );
        } else {
            $data['comment_status'] = 'closed';
            $data['ping_status']    = 'closed';
            $id                     = wp_insert_post( wp_slash( $data ), true );
        }
        if ( is_wp_error( $id ) ) {
            return self::bad( $id->get_error_message() );
        }
        wp_set_post_categories( $id, $categories ); // none: WordPress's default category
        $image ? set_post_thumbnail( $id, $image ) : delete_post_thumbnail( $id );
        if ( $seo !== null ) {
            Qwoo_Seo::save( $id, 'post', $seo );
        }
        return self::post_full( get_post( $id ) );
    }

    /** A draft copy of a post ("… (Copy)"): text, summary, cover and categories; not its address or search listing. */
    private static function action_post_duplicate( array $params ) {
        $post = self::find_post( $params['id'] ?? 0 );
        if ( is_wp_error( $post ) ) {
            return $post;
        }
        $id = wp_insert_post( wp_slash( [
            'post_type'      => 'post',
            'post_status'    => 'draft',
            'post_title'     => mb_substr( html_entity_decode( $post->post_title, ENT_QUOTES ) . ' (Copy)', 0, 200 ),
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ] ), true );
        if ( is_wp_error( $id ) ) {
            return self::bad( $id->get_error_message() );
        }
        wp_set_post_categories( $id, wp_get_post_categories( $post->ID ) );
        $image = (int) get_post_thumbnail_id( $post );
        if ( $image ) {
            set_post_thumbnail( $id, $image );
        }
        return self::post_full( get_post( $id ) );
    }

    /** Moves a post to the trash (restorable from wp-admin for 30 days). */
    private static function action_post_delete( array $params ) {
        $post = self::find_post( $params['id'] ?? 0 );
        if ( is_wp_error( $post ) ) {
            return $post;
        }
        wp_trash_post( $post->ID );
        return [ 'deleted' => $post->ID ];
    }

    private static function action_blog_categories() {
        return [ 'items' => self::blog_category_list() ];
    }

    /** { id (none: new), name, slug, description }. */
    private static function action_blog_category_save( array $params ) {
        $f    = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];
        $id   = absint( $params['id'] ?? 0 );
        $name = trim( sanitize_text_field( (string) ( $f['name'] ?? '' ) ) );
        if ( $name === '' || mb_strlen( $name ) > 100 ) {
            return self::bad( 'Give the category a name (up to 100 characters).' );
        }
        $args = [ 'description' => mb_substr( sanitize_textarea_field( (string) ( $f['description'] ?? '' ) ), 0, 1000 ) ];
        if ( trim( (string) ( $f['slug'] ?? '' ) ) !== '' ) {
            $args['slug'] = sanitize_title( mb_substr( (string) $f['slug'], 0, 190 ) );
        }
        if ( $id ) {
            $term = get_term( $id, 'category' );
            if ( ! $term || is_wp_error( $term ) || $id === (int) get_option( 'default_category' ) ) {
                return self::error( 'qwoo_dashboard_not_found', 'This category doesn\'t exist any more.', 404 );
            }
            $result = wp_update_term( $id, 'category', [ 'name' => $name ] + $args );
        } else {
            $result = wp_insert_term( $name, 'category', $args );
        }
        if ( is_wp_error( $result ) ) {
            return self::bad( $result->get_error_code() === 'term_exists' ? 'There\'s already a category with this name.' : $result->get_error_message() );
        }
        return [ 'category' => self::blog_category( get_term( (int) $result['term_id'], 'category' ) ), 'items' => self::blog_category_list() ];
    }

    /** Deletes a blog category; its posts stay (without it). */
    private static function action_blog_category_delete( array $params ) {
        $id   = absint( $params['id'] ?? 0 );
        $term = $id ? get_term( $id, 'category' ) : null;
        if ( ! $term || is_wp_error( $term ) || $id === (int) get_option( 'default_category' ) ) {
            return self::error( 'qwoo_dashboard_not_found', 'This category doesn\'t exist any more.', 404 );
        }
        wp_delete_term( $id, 'category' );
        return [ 'items' => self::blog_category_list() ];
    }

    /* ---------------- helpers ---------------- */

    private static function find_post( $id ) {
        $post = get_post( absint( $id ) );
        if ( ! $post || $post->post_type !== 'post' || ! in_array( $post->post_status, self::$post_statuses, true ) ) {
            return self::error( 'qwoo_dashboard_not_found', 'This post doesn\'t exist any more.', 404 );
        }
        return $post;
    }

    /** Blog categories (not WordPress's default one), by name. */
    private static function blog_category_list() {
        $terms = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false, 'exclude' => [ (int) get_option( 'default_category' ) ], 'orderby' => 'name', 'number' => 500 ] );
        return array_map( [ __CLASS__, 'blog_category' ], is_wp_error( $terms ) ? [] : $terms );
    }

    private static function blog_category( WP_Term $t ) {
        return [
            'id'          => (int) $t->term_id,
            'name'        => html_entity_decode( $t->name, ENT_QUOTES ),
            'slug'        => urldecode( $t->slug ),
            'description' => (string) $t->description,
            'count'       => (int) $t->count,
        ];
    }

    private static function post_summary( WP_Post $p ) {
        $date = $p->post_status === 'draft' ? $p->post_modified_gmt : $p->post_date_gmt;
        return [
            'id'         => (int) $p->ID,
            'title'      => html_entity_decode( get_the_title( $p ), ENT_QUOTES ),
            'status'     => $p->post_status,
            'date'       => $date && $date !== '0000-00-00 00:00:00' ? (int) strtotime( $date . ' UTC' ) : 0,
            'image'      => self::image_url( get_post_thumbnail_id( $p ), 'medium' ),
            'categories' => array_column( Qwoo_Blog::post_categories( $p->ID ), 'name' ),
        ];
    }

    private static function post_full( WP_Post $p ) {
        $image_id = (int) get_post_thumbnail_id( $p );
        $local    = $p->post_status === 'future' ? mysql2date( 'Y-m-d\TH:i', $p->post_date, false ) : '';
        return self::post_summary( $p ) + [
            'content_html' => Qwoo_Blog::clean_content( $p->post_content ),
            'excerpt'      => (string) $p->post_excerpt,
            'auto_excerpt' => Qwoo_Blog::excerpt( $p ),
            'schedule'     => $local, // YYYY-MM-DDTHH:MM, the store's time zone
            'timezone'     => wp_timezone_string(),
            'slug'         => urldecode( (string) $p->post_name ),
            'image_id'     => $image_id,
            'image_url'    => self::image_url( $image_id, 'medium_large' ),
            'category_ids' => array_column( Qwoo_Blog::post_categories( $p->ID ), 'id' ),
            'url'          => $p->post_name !== '' ? Qwoo_Seo::url( '/blog/' . $p->post_name ) : '',
            'seo'          => Qwoo_Seo::for_editor( $p->ID, 'post' ),
            'seo_defaults' => Qwoo_Seo::post_defaults( $p ),
        ];
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The blog for the platform dashboard (used by Qwoo_Platform_Dashboard):
 * posts (draft, published, scheduled), blog categories and authors. The
 * storefront side is Qwoo_Blog and Qwoo_Blog_Authors.
 */
trait Qwoo_Platform_Blog {

    private static $post_statuses = [ 'draft', 'publish', 'future' ];

    /** { page, search, filter: '' | publish | future | draft, category (id), author (id) }: newest first, 20 a page. */
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
        $author = Qwoo_Blog_Authors::find( absint( $params['author'] ?? 0 ) );
        if ( $author ) {
            $args['tax_query'] = Qwoo_Blog_Authors::tax_query( $author );
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
            'authors'    => self::author_list(),
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
        if ( $image && ! Qwoo_Media_Support::is_image( $image ) ) {
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
        // The author: one from the list, a new one by name (kept for later posts), or for a
        // new post the default one. A save that sends neither keeps the post's author.
        $author = null;
        if ( trim( (string) ( $f['new_author'] ?? '' ) ) !== '' ) {
            $author = self::author_create( (string) $f['new_author'] );
            if ( is_wp_error( $author ) ) {
                return $author;
            }
        } elseif ( absint( $f['author_id'] ?? 0 ) ) {
            $author = Qwoo_Blog_Authors::find( absint( $f['author_id'] ) );
            if ( ! $author ) {
                return self::bad( 'This author doesn\'t exist any more. Choose another one.' );
            }
        } elseif ( ! $post ) {
            $author = Qwoo_Blog_Authors::default_author();
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
        if ( $author ) {
            Qwoo_Blog_Authors::set_for_post( (int) $id, (int) $author->term_id );
        }
        $image ? set_post_thumbnail( $id, $image ) : delete_post_thumbnail( $id );
        if ( $seo !== null ) {
            Qwoo_Seo::save( $id, 'post', $seo );
        }
        Qwoo_Translations::save( 'post', $id, ( is_array( $params['fields'] ?? null ) ? ( $params['fields']['translations'] ?? null ) : null ) );
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
        $author = Qwoo_Blog_Authors::of_post( $post->ID );
        if ( $author ) {
            Qwoo_Blog_Authors::set_for_post( (int) $id, (int) $author->term_id );
        }
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

    /* ---------------- authors ---------------- */

    /** { items, public, default }. */
    private static function action_authors_list() {
        return self::authors_state();
    }

    /** { id }: the author, their SEO fields and their posts (newest first, up to 200). */
    private static function action_author_get( array $params ) {
        $term = Qwoo_Blog_Authors::find( absint( $params['id'] ?? 0 ) );
        if ( ! $term ) {
            return self::error( 'qwoo_dashboard_not_found', 'This author doesn\'t exist any more.', 404 );
        }
        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => self::$post_statuses,
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => Qwoo_Blog_Authors::tax_query( $term ),
        ] );
        return [
            'author' => self::author_item( $term ) + [
                'seo'          => Qwoo_Seo::for_editor( (int) $term->term_id, 'term' ),
                'url'          => Qwoo_Seo::url( '/blog/author/' . $term->slug ),
            ],
            'posts'  => array_map( [ __CLASS__, 'post_summary' ], $posts ),
        ] + self::authors_state();
    }

    /** { id (none: new), fields: { name, slug, bio, image_id, seo } }. */
    private static function action_author_save( array $params ) {
        $f    = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];
        $id   = absint( $params['id'] ?? 0 );
        $term = $id ? Qwoo_Blog_Authors::find( $id ) : null;
        if ( $id && ! $term ) {
            return self::error( 'qwoo_dashboard_not_found', 'This author doesn\'t exist any more.', 404 );
        }
        $name = trim( sanitize_text_field( (string) ( $f['name'] ?? ( $term ? $term->name : '' ) ) ) );
        if ( $name === '' || mb_strlen( $name ) > 100 ) {
            return self::bad( 'Give the author a name (up to 100 characters).' );
        }
        $bio = trim( sanitize_textarea_field( (string) ( $f['bio'] ?? '' ) ) );
        if ( mb_strlen( $bio ) > 1000 ) {
            return self::bad( 'The bio can be up to 1,000 characters.' );
        }
        $image = absint( $f['image_id'] ?? 0 );
        if ( $image && ! Qwoo_Media_Support::is_image( $image ) ) {
            return self::bad( 'The photo is missing. Upload it again.' );
        }
        $seo = null;
        if ( array_key_exists( 'seo', $f ) ) {
            $seo = Qwoo_Seo::clean_input( $f['seo'] );
            if ( is_wp_error( $seo ) ) {
                return $seo;
            }
        }
        $args = [];
        if ( trim( (string) ( $f['slug'] ?? '' ) ) !== '' ) {
            $args['slug'] = sanitize_title( mb_substr( (string) $f['slug'], 0, 190 ) );
        }
        if ( ! $term && self::author_named( $name ) ) {
            return self::bad( 'There\'s already an author with this name.' );
        }
        $result = $term ? wp_update_term( $term->term_id, Qwoo_Blog_Authors::TAX, [ 'name' => $name ] + $args ) : wp_insert_term( $name, Qwoo_Blog_Authors::TAX, $args );
        if ( is_wp_error( $result ) ) {
            return self::bad( $result->get_error_code() === 'duplicate_term_slug' ? 'Another author already uses this address.' : $result->get_error_message() );
        }
        $tid = (int) $result['term_id'];
        $bio !== '' ? update_term_meta( $tid, Qwoo_Blog_Authors::BIO_META, $bio ) : delete_term_meta( $tid, Qwoo_Blog_Authors::BIO_META );
        $image ? update_term_meta( $tid, Qwoo_Blog_Authors::IMAGE_META, $image ) : delete_term_meta( $tid, Qwoo_Blog_Authors::IMAGE_META );
        if ( $seo !== null ) {
            Qwoo_Seo::save( $tid, 'term', $seo );
        }
        return [ 'author' => self::author_item( get_term( $tid, Qwoo_Blog_Authors::TAX ) ) ] + self::authors_state();
    }

    /** { id, move_to }: deletes an author; their posts go to move_to (else the default author). */
    private static function action_author_delete( array $params ) {
        $term    = Qwoo_Blog_Authors::find( absint( $params['id'] ?? 0 ) );
        $default = Qwoo_Blog_Authors::default_author();
        if ( ! $term ) {
            return self::error( 'qwoo_dashboard_not_found', 'This author doesn\'t exist any more.', 404 );
        }
        if ( $default && (int) $default->term_id === (int) $term->term_id ) {
            return self::bad( 'This is the default author. Make another author the default first.' );
        }
        $to = Qwoo_Blog_Authors::find( absint( $params['move_to'] ?? 0 ) ) ?: $default;
        if ( ! $to || (int) $to->term_id === (int) $term->term_id ) {
            return self::bad( 'Choose who the posts move to.' );
        }
        self::move_posts( self::author_post_ids( $term ), $to );
        wp_delete_term( $term->term_id, Qwoo_Blog_Authors::TAX );
        return self::authors_state();
    }

    /** { public?, default? }: author profiles on the storefront, and the default author. */
    private static function action_authors_settings( array $params ) {
        $changes = [];
        if ( array_key_exists( 'public', $params ) ) {
            $changes['public'] = ! empty( $params['public'] );
        }
        if ( array_key_exists( 'default', $params ) ) {
            $term = Qwoo_Blog_Authors::find( absint( $params['default'] ) );
            if ( ! $term ) {
                return self::bad( 'This author doesn\'t exist any more.' );
            }
            // Posts without an author showed the old default: they keep it.
            $old = Qwoo_Blog_Authors::default_author();
            if ( $old && (int) $old->term_id !== (int) $term->term_id ) {
                self::move_posts( Qwoo_Blog_Authors::posts_without_author(), $old );
            }
            $changes['default'] = (int) $term->term_id;
        }
        Qwoo_Blog_Authors::save_settings( $changes );
        return self::authors_state();
    }

    /** { to, post_ids: [] } or { to, from }: posts move to another author (some, or all of one author's). */
    private static function action_authors_move( array $params ) {
        $to = Qwoo_Blog_Authors::find( absint( $params['to'] ?? 0 ) );
        if ( ! $to ) {
            return self::bad( 'Choose who the posts move to.' );
        }
        if ( absint( $params['from'] ?? 0 ) ) {
            $from = Qwoo_Blog_Authors::find( absint( $params['from'] ) );
            if ( ! $from ) {
                return self::error( 'qwoo_dashboard_not_found', 'This author doesn\'t exist any more.', 404 );
            }
            $ids = self::author_post_ids( $from );
        } else {
            $ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $params['post_ids'] ?? [] ) ) ) ) ), 0, 500 );
            $ids = array_values( array_filter( $ids, static fn( $id ) => ! is_wp_error( self::find_post( $id ) ) ) );
        }
        self::move_posts( $ids, $to );
        return [ 'moved' => count( $ids ) ] + self::authors_state();
    }

    private static function authors_state(): array {
        $s = Qwoo_Blog_Authors::settings();
        Qwoo_Blog_Authors::default_author(); // made once, with the store's name
        return [ 'items' => self::author_list(), 'public' => $s['public'], 'default' => Qwoo_Blog_Authors::settings()['default'] ];
    }

    /** Every author, by name, with their published posts. */
    private static function author_list(): array {
        Qwoo_Blog_Authors::default_author();
        $counts = Qwoo_Blog_Authors::post_counts();
        $terms  = get_terms( [ 'taxonomy' => Qwoo_Blog_Authors::TAX, 'hide_empty' => false, 'orderby' => 'name', 'number' => Qwoo_Blog_Authors::MAX ] );
        return array_map( static fn( $t ) => self::author_item( $t, $counts ), is_wp_error( $terms ) ? [] : $terms );
    }

    private static function author_item( WP_Term $t, ?array $counts = null ): array {
        $counts = $counts ?? Qwoo_Blog_Authors::post_counts();
        $image  = (int) get_term_meta( $t->term_id, Qwoo_Blog_Authors::IMAGE_META, true );
        return [
            'id'        => (int) $t->term_id,
            'name'      => html_entity_decode( $t->name, ENT_QUOTES ),
            'slug'      => urldecode( $t->slug ),
            'bio'       => (string) get_term_meta( $t->term_id, Qwoo_Blog_Authors::BIO_META, true ),
            'image_id'  => $image,
            'image_url' => $image ? self::image_url( $image, 'thumbnail' ) : '',
            'count'     => (int) ( $counts[ (int) $t->term_id ] ?? 0 ),
        ];
    }

    private static function author_named( string $name ): ?WP_Term {
        $term = get_term_by( 'name', $name, Qwoo_Blog_Authors::TAX );
        return $term instanceof WP_Term ? $term : null;
    }

    /** A new author by name from the post editor (an existing one with that name is reused). */
    private static function author_create( string $name ) {
        $name = trim( sanitize_text_field( $name ) );
        if ( $name === '' || mb_strlen( $name ) > 100 ) {
            return self::bad( 'Give the author a name (up to 100 characters).' );
        }
        $same = self::author_named( $name );
        if ( $same ) {
            return $same;
        }
        $result = wp_insert_term( $name, Qwoo_Blog_Authors::TAX );
        return is_wp_error( $result ) ? self::bad( $result->get_error_message() ) : get_term( (int) $result['term_id'], Qwoo_Blog_Authors::TAX );
    }

    /** Every post (any status) the author wrote, the default author's ones without an author included. */
    private static function author_post_ids( WP_Term $term ): array {
        return get_posts( [ 'post_type' => 'post', 'post_status' => self::$post_statuses, 'posts_per_page' => -1, 'fields' => 'ids', 'tax_query' => Qwoo_Blog_Authors::tax_query( $term ) ] );
    }

    private static function move_posts( array $ids, WP_Term $to ): void {
        foreach ( $ids as $id ) {
            Qwoo_Blog_Authors::set_for_post( (int) $id, (int) $to->term_id );
        }
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
            'author'     => ( $a = Qwoo_Blog_Authors::of_post( $p->ID ) ) ? html_entity_decode( $a->name, ENT_QUOTES ) : '',
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
            'author_id'    => ( $a = Qwoo_Blog_Authors::of_post( $p->ID ) ) ? (int) $a->term_id : 0,
            'authors'      => self::author_list(),
            'url'          => $p->post_name !== '' ? Qwoo_Seo::url( '/blog/' . $p->post_name ) : '',
            'seo'          => Qwoo_Seo::for_editor( $p->ID, 'post' ),
            'seo_defaults' => Qwoo_Seo::post_defaults( $p ),
            'translations' => Qwoo_Translations::for_dashboard( 'post', $p->ID ),
        ];
    }
}

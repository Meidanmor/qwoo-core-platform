<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The Media screen and image picker of the platform dashboard (used by
 * Qwoo_Platform_Dashboard): one image or video with where it's used, its
 * alt text and name, and deleting it. The list itself is design_media
 * (Shop_Settings_Builder::platform_media), which the Store builder uses too.
 */
trait Qwoo_Platform_Media {

    private static $media_max_uses = 50; // per kind of place

    /* ---------------- dashboard actions ---------------- */

    /** { id }: details and where it's used. */
    private static function action_media_get( array $params ) {
        $id = self::media_id( $params );
        return is_wp_error( $id ) ? $id : self::media_details( $id, true );
    }

    /** { id, alt?, title? }. */
    private static function action_media_save( array $params ) {
        $id = self::media_id( $params );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        if ( array_key_exists( 'title', $params ) ) {
            $title = mb_substr( sanitize_text_field( (string) $params['title'] ), 0, 200 );
            if ( $title === '' ) {
                return self::bad( 'Give the file a name.' );
            }
            wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
        }
        if ( array_key_exists( 'alt', $params ) && self::media_is_image( $id ) ) {
            $alt = mb_substr( sanitize_text_field( (string) $params['alt'] ), 0, 250 );
            $alt === '' ? delete_post_meta( $id, '_wp_attachment_image_alt' ) : update_post_meta( $id, '_wp_attachment_image_alt', $alt );
        }
        return self::media_details( $id, false );
    }

    /**
     * { id }: deleted for good, with all its sizes. Product galleries,
     * category photos and share images let go of it; WordPress itself
     * clears main photos and blog covers. Text that shows it keeps a
     * broken image, which the dashboard warns about before.
     */
    private static function action_media_delete( array $params ) {
        global $wpdb;
        $id = self::media_id( $params );
        if ( is_wp_error( $id ) ) {
            return $id;
        }

        $touched = [];
        $rows    = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND " . self::media_in_list_sql( 'meta_value' ),
            ...self::media_in_list_args( $id )
        ) );
        foreach ( $rows as $row ) {
            $ids = array_values( array_diff( array_map( 'intval', explode( ',', (string) $row->meta_value ) ), [ $id ] ) );
            update_post_meta( (int) $row->post_id, '_product_image_gallery', implode( ',', $ids ) );
            $touched[] = (int) $row->post_id;
        }
        $terms = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = 'thumbnail_id' AND meta_value = %s", (string) $id ) );
        foreach ( $terms as $term_id ) {
            delete_term_meta( (int) $term_id, 'thumbnail_id' );
        }

        foreach ( [ 'post', 'term' ] as $kind ) {
            foreach ( self::media_seo_owners( $kind, $id ) as $owner ) {
                $get  = $kind === 'term' ? 'get_term_meta' : 'get_post_meta';
                $set  = $kind === 'term' ? 'update_term_meta' : 'update_post_meta';
                $seo  = (array) $get( $owner, Qwoo_Seo::META, true );
                $seo['image_id'] = 0;
                $set( $owner, Qwoo_Seo::META, $seo );
            }
        }
        $settings = get_option( Qwoo_Seo::OPTION, [] );
        if ( is_array( $settings ) && absint( $settings['image_id'] ?? 0 ) === $id ) {
            $settings['image_id'] = 0;
            update_option( Qwoo_Seo::OPTION, $settings );
        }

        if ( ! wp_delete_attachment( $id, true ) ) {
            return self::bad( 'This file couldn\'t be deleted. Try again.' );
        }
        foreach ( array_unique( $touched ) as $product_id ) {
            clean_post_cache( $product_id );
            if ( function_exists( 'wc_delete_product_transients' ) ) {
                wc_delete_product_transients( $product_id );
            }
        }
        return [ 'deleted' => true ];
    }

    /* ---------------- helpers ---------------- */

    /** The attachment ID from { id }: an image or video, else a 404 error. */
    private static function media_id( array $params ) {
        $id   = absint( $params['id'] ?? 0 );
        $post = $id ? get_post( $id ) : null;
        $mime = $post ? (string) get_post_mime_type( $post ) : '';
        if ( ! $post || $post->post_type !== 'attachment' || ! preg_match( '#^(image|video)/#', $mime ) ) {
            return self::error( 'qwoo_dashboard_not_found', 'This file doesn\'t exist any more.', 404 );
        }
        return $id;
    }

    private static function media_is_image( $id ) {
        return strpos( (string) get_post_mime_type( $id ), 'image/' ) === 0;
    }

    private static function media_details( $id, $with_uses ) {
        $post  = get_post( $id );
        $file  = get_attached_file( $id );
        $meta  = wp_get_attachment_metadata( $id );
        $image = self::media_is_image( $id );
        $size  = (int) ( $meta['filesize'] ?? 0 );
        if ( ! $size && $file && file_exists( $file ) ) {
            $size = (int) filesize( $file );
        }
        $url = (string) wp_get_attachment_url( $id );
        $out = [
            'id'      => (int) $id,
            'name'    => get_the_title( $id ),
            'file'    => $file ? wp_basename( $file ) : '',
            'mime'    => (string) get_post_mime_type( $id ),
            'size'    => $size,
            'width'   => isset( $meta['width'] ) ? (int) $meta['width'] : null,
            'height'  => isset( $meta['height'] ) ? (int) $meta['height'] : null,
            'date'    => mysql2date( 'Y-m-d', $post->post_date ),
            'alt'     => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'url'     => $url,
            'thumb'   => $image ? ( self::image_url( $id, 'medium' ) ?: $url ) : '',
            'large'   => $image ? ( self::image_url( $id, 'large' ) ?: $url ) : $url,
            'product' => $image ? ( self::image_url( $id, 'woocommerce_thumbnail' ) ?: $url ) : $url,
        ];
        if ( $with_uses ) {
            $out['uses'] = self::media_uses( $id );
        }
        return $out;
    }

    /**
     * Where an image is used: [{ type: product|category|post|builder|seo,
     * id, name, as: [photo|variant|cover|share|text|…] }].
     */
    private static function media_uses( $id ) {
        global $wpdb;
        $uses = [];
        $add  = static function ( $type, $obj_id, $name, $as ) use ( &$uses ) {
            $key = $type . ':' . $obj_id;
            if ( ! isset( $uses[ $key ] ) ) {
                $uses[ $key ] = [ 'type' => $type, 'id' => (int) $obj_id, 'name' => (string) $name, 'as' => [] ];
            }
            if ( ! in_array( $as, $uses[ $key ]['as'], true ) ) {
                $uses[ $key ]['as'][] = $as;
            }
        };
        $max  = self::$media_max_uses;
        $live = "p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )";

        // Main photos, variant photos and blog covers.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_type, p.post_parent, p.post_title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = %s AND p.post_type IN ( 'product', 'product_variation', 'post' ) AND {$live} LIMIT %d",
            (string) $id,
            $max
        ) );
        foreach ( $rows as $r ) {
            if ( $r->post_type === 'product_variation' ) {
                $add( 'product', $r->post_parent, get_the_title( $r->post_parent ), 'variant' );
            } elseif ( $r->post_type === 'post' ) {
                $add( 'post', $r->ID, $r->post_title, 'cover' );
            } else {
                $add( 'product', $r->ID, $r->post_title, 'photo' );
            }
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key = '_product_image_gallery' AND " . self::media_in_list_sql( 'm.meta_value' ) . " AND p.post_type = 'product' AND {$live} LIMIT %d",
            ...array_merge( self::media_in_list_args( $id ), [ $max ] )
        ) );
        foreach ( $rows as $r ) {
            $add( 'product', $r->ID, $r->post_title, 'photo' );
        }

        $terms = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.term_id, t.name, tt.taxonomy FROM {$wpdb->termmeta} m JOIN {$wpdb->terms} t ON t.term_id = m.term_id JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE m.meta_key = 'thumbnail_id' AND m.meta_value = %s AND tt.taxonomy = 'product_cat' LIMIT %d",
            (string) $id,
            $max
        ) );
        foreach ( $terms as $r ) {
            $add( 'category', $r->term_id, $r->name, 'photo' );
        }

        // Share images.
        foreach ( self::media_seo_owners( 'post', $id ) as $owner ) {
            $p = get_post( $owner );
            if ( $p && in_array( $p->post_type, [ 'product', 'post' ], true ) && ! in_array( $p->post_status, [ 'trash', 'auto-draft' ], true ) ) {
                $add( $p->post_type, $p->ID, $p->post_title, 'share' );
            }
        }
        foreach ( self::media_seo_owners( 'term', $id ) as $owner ) {
            $term = get_term( $owner );
            if ( $term && ! is_wp_error( $term ) && $term->taxonomy === 'product_cat' ) {
                $add( 'category', $term->term_id, $term->name, 'share' );
            }
        }
        $settings = get_option( Qwoo_Seo::OPTION, [] );
        if ( is_array( $settings ) && absint( $settings['image_id'] ?? 0 ) === $id ) {
            $add( 'seo', 0, '', 'share' );
        }

        // Shown inside a description or a blog post (by its address).
        $base = self::media_file_base( $id );
        if ( $base !== '' ) {
            $like  = '%uploads/' . $wpdb->esc_like( $base );
            $rows  = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.ID, p.post_type, p.post_title FROM {$wpdb->posts} p
                 WHERE p.post_type IN ( 'product', 'post' ) AND {$live}
                 AND ( p.post_content LIKE %s OR p.post_content LIKE %s OR p.post_content LIKE %s ) LIMIT %d",
                $like . '.%',
                $like . '-scaled.%',
                $like . '-%x%',
                $max
            ) );
            foreach ( $rows as $r ) {
                $add( $r->post_type, $r->ID, $r->post_title, 'text' );
            }
        }

        if ( isset( Shop_Settings_Builder::platform_media_used_ids()[ $id ] ) ) {
            $add( 'builder', 0, '', 'design' );
        }

        return array_values( $uses );
    }

    /**
     * "This ID is in a comma list" ("12,34,56"), without FIND_IN_SET (so it
     * also runs on SQLite): four placeholders, see media_in_list_args().
     */
    private static function media_in_list_sql( $column ) {
        return "( {$column} = %s OR {$column} LIKE %s OR {$column} LIKE %s OR {$column} LIKE %s )";
    }

    private static function media_in_list_args( $id ) {
        $id = (string) (int) $id;
        return [ $id, $id . ',%', '%,' . $id, '%,' . $id . ',%' ];
    }

    /** Posts or terms whose own share image (_qwoo_seo image_id) is this one. */
    private static function media_seo_owners( $kind, $id ) {
        global $wpdb;
        $table  = $kind === 'term' ? $wpdb->termmeta : $wpdb->postmeta;
        $column = $kind === 'term' ? 'term_id' : 'post_id';
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT {$column} FROM {$table} WHERE meta_key = %s AND meta_value LIKE %s LIMIT %d",
            Qwoo_Seo::META,
            '%' . $wpdb->esc_like( 's:8:"image_id";i:' . (int) $id . ';' ) . '%',
            self::$media_max_uses
        ) ) );
    }

    /** "2026/10/photo" for uploads/2026/10/photo-scaled.jpg: what every size's address starts with. */
    private static function media_file_base( $id ) {
        $file = (string) get_post_meta( $id, '_wp_attached_file', true );
        if ( $file === '' ) {
            return '';
        }
        $dir  = dirname( $file );
        $name = preg_replace( '/-scaled$/', '', pathinfo( $file, PATHINFO_FILENAME ) );
        return ( $dir === '.' ? '' : $dir . '/' ) . $name;
    }
}

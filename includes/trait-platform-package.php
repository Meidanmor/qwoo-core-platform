<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * "Copy your store" for the platform dashboard (used by
 * Qwoo_Platform_Dashboard): export files (Qwoo_Store_Package) and bringing
 * one in. An import runs through the move's importer
 * (Qwoo_Platform_Migration) with the file as its source, so the platform's
 * migrate_store job drives it with migrate_run, and the same rules apply:
 * items keep "<file's store>:<old id>", so importing a file from the same
 * store again updates instead of adding copies; nothing is emailed.
 *
 *   package_status                  { export, import, run }
 *   package_export_start { parts }  package_export_step  package_chunk { index }  package_export_discard
 *   package_upload { index, total, size, data }   package_inspect
 *   package_import_start { parts, mode: add | replace, template }   package_import_cancel   package_import_discard
 *   package_import_revert: stops the import and puts the store back as it was (Qwoo_Import_Snapshot)
 */
trait Qwoo_Platform_Package {

    /** Which part each move phase belongs to, when the source is a file. */
    private static $mig_package_parts = [
        'settings'        => 'settings',
        'remove'          => 'remove_existing',
        'attributes'      => 'products',
        'product_cat'     => 'products',
        'product_tag'     => 'products',
        'products'        => 'products',
        'links'           => 'products',
        'coupons'         => 'coupons',
        'post_categories' => 'blog',
        'posts'           => 'blog',
        'builder'         => 'builder',
    ];

    private static function mig_is_package() {
        return ( self::$mig['source']['kind'] ?? '' ) === 'package';
    }

    private static function package_public() {
        Qwoo_Store_Package::cleanup();
        $s   = self::mig_state();
        $run = ( $s['source']['kind'] ?? '' ) === 'package' ? self::mig_public( $s ) + [ 'mode' => (string) ( $s['package_mode'] ?? 'add' ) ] : null;
        // A stopped or failed import: the file can be brought in again.
        $import = Qwoo_Store_Package::import_state();
        if ( ( $import['status'] ?? '' ) === 'importing' && ( ! $run || $run['status'] !== 'running' ) ) {
            Qwoo_Store_Package::import_mark( 'checked' );
        }
        return [
            'export' => Qwoo_Store_Package::export_public(),
            'import' => Qwoo_Store_Package::import_public(),
            'run'    => $run,
        ];
    }

    private static function package_answer( $result ) {
        return is_wp_error( $result ) ? $result : self::package_public();
    }

    private static function action_package_status( array $params ) {
        return self::package_public();
    }

    private static function action_package_export_start( array $params ) {
        return self::package_answer( Qwoo_Store_Package::export_start( (array) ( $params['parts'] ?? [] ) ) );
    }

    private static function action_package_export_step( array $params ) {
        return self::package_answer( Qwoo_Store_Package::export_step() );
    }

    private static function action_package_chunk( array $params ) {
        return Qwoo_Store_Package::export_chunk( (int) ( $params['index'] ?? 0 ), (int) ( $params['bytes'] ?? Qwoo_Store_Package::CHUNK ) );
    }

    private static function action_package_export_discard( array $params ) {
        Qwoo_Store_Package::export_discard();
        return self::package_public();
    }

    private static function action_package_upload( array $params ) {
        return self::package_answer( Qwoo_Store_Package::upload( $params ) );
    }

    private static function action_package_inspect( array $params ) {
        return self::package_answer( Qwoo_Store_Package::inspect() );
    }

    private static function action_package_import_discard( array $params ) {
        return self::package_answer( Qwoo_Store_Package::import_discard() );
    }

    /** { parts, mode }: brings the checked file in. */
    private static function action_package_import_start( array $params ) {
        $s = self::mig_state();
        if ( ( $s['status'] ?? '' ) === 'running' ) {
            return self::error( 'qwoo_dashboard_busy', ( $s['source']['kind'] ?? '' ) === 'package' ? 'An import is running. Wait for it to finish, or stop it first.' : 'A move is running. Wait for it to finish first.', 409 );
        }
        if ( ( $s['source']['kind'] ?? '' ) !== 'package' && ! empty( $s['secret'] ) ) {
            return self::bad( 'Your old store is still connected in Settings → Move your store in. Finish or disconnect that move first.' );
        }
        $file = Qwoo_Store_Package::import_state();
        if ( ( $file['status'] ?? '' ) !== 'checked' || empty( $file['file'] ) ) {
            return self::bad( 'Upload the file first.' );
        }
        $parts = array_values( array_intersect( (array) $file['file']['parts'], array_map( 'strval', (array) ( $params['parts'] ?? [] ) ) ) );
        if ( ! $parts ) {
            return self::bad( 'Choose what to bring in.' );
        }
        $mode = ( $params['mode'] ?? '' ) === 'replace' ? 'replace' : 'add';
        $key  = (string) $file['key'];
        $what = array_merge( [ 'package' ], $parts );

        // Cancel puts the store back: a copy of its content first (not for a new store's template).
        if ( empty( $params['template'] ) ) {
            $saved = Qwoo_Import_Snapshot::take();
            if ( is_wp_error( $saved ) ) return $saved;
        }

        // Replace: what's here and didn't come from this file's store goes to the trash first.
        $remove = [];
        $types  = array_values( array_filter( [ in_array( 'products', $parts, true ) ? 'product' : '', in_array( 'blog', $parts, true ) ? 'post' : '', in_array( 'coupons', $parts, true ) ? 'shop_coupon' : '' ] ) );
        if ( $mode === 'replace' && $types ) {
            global $wpdb;
            $in     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
            $remove = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type IN ($in) AND p.post_status IN ('publish','draft','pending','private','future')
                 AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value LIKE %s ) ORDER BY p.ID LIMIT 10000",
                array_merge( $types, [ Qwoo_Migration::META, $wpdb->esc_like( $key . ':' ) . '%' ] )
            ) ) );
            if ( $remove ) $what[] = 'remove_existing';
        }
        $same = ( $s['source']['key'] ?? '' ) === $key;
        $new  = [
            'source'       => [ 'kind' => 'package', 'name' => (string) $file['file']['name'], 'url' => '', 'host' => '', 'endpoint' => '', 'key' => $key ],
            'summary'      => [ 'site' => [ 'name' => (string) $file['file']['name'], 'url' => '' ], 'counts' => (array) $file['file']['counts'] ],
            'status'       => 'running',
            'what'         => $what,
            'package_mode' => $mode,
            // A store copy template the platform brings in while setting up a new store.
            'package_template' => ! empty( $params['template'] ),
            'phase'        => 0,
            'cursor'       => 0,
            'counts'       => [],
            'problems'     => [],
            'notes'        => [],
            'started'      => time(),
            'finished'     => 0,
            'remove_ids'   => $remove,
            'maps'         => $same ? (array) ( $s['maps'] ?? [] ) : [],
            'runs'         => ( $same ? (int) ( $s['runs'] ?? 0 ) : 0 ) + 1,
            'error'        => '',
        ];
        update_option( Qwoo_Migration::OPTION, $new, false );
        Qwoo_Store_Package::import_mark( 'importing' );
        return self::package_public();
    }

    private static function action_package_import_cancel( array $params ) {
        $s = self::mig_state();
        if ( ( $s['source']['kind'] ?? '' ) === 'package' && ( $s['status'] ?? '' ) === 'running' ) {
            $s['status'] = 'cancelled';
            update_option( Qwoo_Migration::OPTION, $s, false );
        }
        return self::package_public();
    }

    /**
     * Stops the import and puts the store back as it was before it started. While a step is still
     * working it answers 409 with retry: true (the dashboard asks again in a few seconds).
     */
    private static function action_package_import_revert( array $params ) {
        $s = self::mig_state();
        if ( ( $s['source']['kind'] ?? '' ) !== 'package' || ! Qwoo_Import_Snapshot::exists() ) {
            return self::bad( 'There\'s no import to undo.' );
        }
        if ( ( $s['status'] ?? '' ) === 'running' ) {
            $s['status'] = 'cancelled';
            update_option( Qwoo_Migration::OPTION, $s, false );
        }
        if ( get_transient( 'qwoo_migration_lock' ) ) {
            return new WP_Error( 'qwoo_import_busy', 'Stopping the import…', [ 'status' => 409, 'retry' => true ] );
        }
        $done = Qwoo_Import_Snapshot::restore();
        if ( is_wp_error( $done ) ) return $done;
        $s = self::mig_state();
        $s['status']   = 'reverted';
        $s['finished'] = time();
        update_option( Qwoo_Migration::OPTION, $s, false );
        Qwoo_Store_Package::import_mark( 'checked' );
        return self::package_public();
    }

    /* ---------------- importing (move phases with a file as the source) ---------------- */

    /** A photo or video from the file, in this store's media (once per file's store and ID). Returns its ID, or 0. */
    private static function mig_package_media( array $img ) {
        global $wpdb;
        $old = (int) ( $img['id'] ?? 0 );
        if ( $old <= 0 ) return 0;
        $hash = md5( 'pkg:' . self::$mig['source']['key'] . ':' . $old );
        $key  = 'img:' . $hash;
        if ( isset( self::$mig_cache[ $key ] ) ) {
            return self::$mig_cache[ $key ];
        }
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type = 'attachment' LIMIT 1",
            Qwoo_Migration::SRC_META, $hash
        ) );
        $meta = [];
        if ( ! $id ) {
            $file = Qwoo_Store_Package::media( $old );
            if ( ! $file ) {
                self::mig_problem( 'A photo or video wasn\'t in the file (it may have been too big): number ' . $old . '.' );
                return self::$mig_cache[ $key ] = 0;
            }
            [ $bytes, $meta ] = $file;
            $params = [ 'data' => base64_encode( $bytes ), 'name' => sanitize_file_name( (string) ( $meta['name'] ?? '' ) ) ];
            unset( $bytes, $file );
            $upload = strpos( (string) ( $meta['mime'] ?? '' ), 'video/' ) === 0 ? self::action_video_upload( $params ) : self::action_image_upload( $params );
            if ( is_wp_error( $upload ) ) {
                self::mig_problem( 'A photo or video wasn\'t copied (' . $upload->get_error_message() . '): ' . sanitize_file_name( (string) ( $meta['name'] ?? '' ) ) );
                return self::$mig_cache[ $key ] = 0;
            }
            $id = (int) $upload['id'];
            update_post_meta( $id, Qwoo_Migration::SRC_META, $hash );
        }
        $alt   = sanitize_text_field( (string) ( $img['alt'] ?? ( $meta['alt'] ?? '' ) ) );
        $title = sanitize_text_field( (string) ( $img['title'] ?? ( $meta['title'] ?? '' ) ) );
        if ( $alt !== '' ) update_post_meta( $id, '_wp_attachment_image_alt', mb_substr( $alt, 0, 300 ) );
        if ( $title !== '' && get_the_title( $id ) !== $title ) wp_update_post( [ 'ID' => $id, 'post_title' => mb_substr( $title, 0, 200 ) ] );
        return self::$mig_cache[ $key ] = $id;
    }

    /** Translations from the file: { lang: { field: text } } cleaned like the dashboard's (any known language). */
    private static function mig_i18n( $kind, $input ) {
        $out    = [];
        $fields = Qwoo_Translations::FIELDS[ $kind ] ?? [];
        foreach ( (array) $input as $lang => $values ) {
            if ( ! is_string( $lang ) || ! isset( Qwoo_Store_Language::LANGS[ $lang ] ) || ! is_array( $values ) ) continue;
            foreach ( $fields as $field => $type ) {
                $value = $values[ $field ] ?? '';
                if ( ! is_scalar( $value ) ) continue;
                $value = $type === 'html'
                    ? trim( wp_kses_post( (string) $value ) )
                    : mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, $field === 'seo_description' ? 320 : 200 );
                if ( $value !== '' ) $out[ $lang ][ $field ] = $value;
            }
        }
        return $out;
    }

    private static function mig_save_i18n( $kind, $id, $input, $is_term = false ) {
        if ( ! self::mig_is_package() ) return;
        $clean = self::mig_i18n( $kind, $input );
        if ( $is_term ) {
            $clean ? update_term_meta( (int) $id, Qwoo_Translations::META, $clean ) : delete_term_meta( (int) $id, Qwoo_Translations::META );
        } else {
            $clean ? update_post_meta( (int) $id, Qwoo_Translations::META, $clean ) : delete_post_meta( (int) $id, Qwoo_Translations::META );
        }
    }

    /** The store's own settings in the file: reviews, SEO, languages. */
    private static function mig_package_settings( array $q ) {
        if ( is_array( $q['reviews'] ?? null ) ) {
            Qwoo_Reviews::save_settings( $q['reviews'] );
        }
        if ( is_array( $q['seo'] ?? null ) ) {
            $seo = Qwoo_Seo::settings();
            $seo['title_pattern']    = mb_substr( sanitize_text_field( (string) ( $q['seo']['title_pattern'] ?? $seo['title_pattern'] ) ), 0, 200 ) ?: $seo['title_pattern'];
            $seo['home_title']       = mb_substr( sanitize_text_field( (string) ( $q['seo']['home_title'] ?? '' ) ), 0, Qwoo_Seo::MAX_TITLE );
            $seo['home_description'] = mb_substr( sanitize_text_field( (string) ( $q['seo']['home_description'] ?? '' ) ), 0, Qwoo_Seo::MAX_DESCRIPTION );
            $seo['image_id']         = ! empty( $q['seo']['image'] ) ? self::mig_image( $q['seo']['image'] ) : 0;
            update_option( Qwoo_Seo::OPTION, $seo );
        }
        // A template at setup: the store keeps the language its owner chose.
        if ( ! empty( self::$mig['package_template'] ) ) {
            return;
        }
        $main = (string) ( $q['language'] ?? '' );
        if ( isset( Qwoo_Store_Language::LANGS[ $main ] ) && $main !== Qwoo_Store_Language::get() ) {
            $done = Qwoo_Store_Language::set( $main, false );
            if ( is_wp_error( $done ) && empty( $done->get_error_data()['saved'] ) ) {
                self::mig_problem( 'The store\'s language couldn\'t be changed: ' . $done->get_error_message() );
            } else {
                self::mig_note( 'Your store\'s main language is now the file\'s: ' . $main . '.' );
            }
        }
        $langs = (array) ( $q['languages'] ?? [] );
        $rows  = [];
        foreach ( (array) ( $langs['extra'] ?? [] ) as $code ) {
            if ( ! is_string( $code ) ) continue;
            $rows[] = [ 'code' => $code, 'prefix' => (string) ( $langs['prefixes'][ $code ] ?? '' ), 'live' => ! empty( $langs['live'][ $code ] ) ];
        }
        if ( $rows ) {
            if ( ! Qwoo_Store_Language::allowed() ) {
                self::mig_note( 'The file\'s store sells in more languages. Extra languages are part of the Pro and Plus plans, so they weren\'t added here.' );
            } else {
                $saved = Qwoo_Store_Language::save_extra( $rows );
                if ( is_wp_error( $saved ) && empty( $saved->get_error_data()['saved'] ) ) {
                    self::mig_problem( 'The extra languages couldn\'t be added: ' . $saved->get_error_message() );
                }
            }
        }
    }

    /** The Store builder from the file: its photos first, then the settings with this store's IDs. */
    private static function mig_phase_builder() {
        $data = self::mig_fetch( 'builder' );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $refs  = (array) ( $data['refs'] ?? [] );
        $media = array_values( array_filter( array_map( 'intval', (array) ( $refs['media'] ?? [] ) ) ) );
        $pos   = (int) ( self::$mig['cursor'] ?? 0 );
        while ( $pos < count( $media ) && self::mig_time_left() > 4 ) {
            self::mig_package_media( [ 'id' => $media[ $pos ] ] );
            $pos++;
            self::$mig['cursor'] = $pos;
        }
        if ( $pos < count( $media ) ) {
            self::mig_save();
            return false;
        }
        $by_slug = static function ( $kind, $old ) use ( $refs ) {
            $slug = (string) ( $refs[ $kind ][ $old ] ?? '' );
            if ( $slug === '' ) return 0;
            if ( $kind === 'product' ) {
                $post = get_page_by_path( $slug, OBJECT, 'product' );
                return $post ? (int) $post->ID : 0;
            }
            $term = get_term_by( 'slug', $slug, [ 'category' => 'product_cat', 'tag' => 'product_tag', 'blog_category' => 'category' ][ $kind ] );
            return $term ? (int) $term->term_id : 0;
        };
        $map = static function ( $kind, $old ) use ( $by_slug ) {
            switch ( $kind ) {
                case 'media':
                    return self::mig_package_media( [ 'id' => $old ] );
                case 'product':
                    return self::mig_post( [ 'product' ], $old ) ?: $by_slug( $kind, $old );
                case 'category':
                    return self::mig_term( 'product_cat', $old ) ?: $by_slug( $kind, $old );
                case 'tag':
                    return self::mig_term( 'product_tag', $old ) ?: $by_slug( $kind, $old );
                case 'blog_category':
                    return self::mig_term( 'category', $old ) ?: $by_slug( $kind, $old );
            }
            return 0;
        };
        $mode   = (string) ( self::$mig['package_mode'] ?? 'add' );
        $result = Shop_Settings_Builder::package_builder_import( $data, $map, $mode );
        for ( $i = 0; $i < (int) $result['pages']; $i++ ) {
            self::mig_count( 'builder', $mode === 'replace' ? 'updated' : 'created' );
        }
        if ( $result['skipped'] ) {
            self::mig_note( 'These pages weren\'t added because your store already has a page at the same address (or one with the same role): ' . implode( ', ', array_slice( $result['skipped'], 0, 20 ) ) . '.' );
        }
        if ( $mode === 'replace' || $result['pages'] > 0 ) self::mig_note( $mode === 'replace'
            ? 'Your Store builder now has the file\'s design and pages. Open the Store builder and publish to put them live.'
            : 'The file\'s pages were added to your Store builder as drafts. Publish them when they\'re ready.' );
        return true;
    }
}

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
 *   package_import_start { parts, mode: add | replace, template, identity, publish }   package_import_cancel   package_import_discard
 *     identity: the store takes the file's name and description. publish: the Store builder is
 *     published when the import ends, so the storefront changes with it (2026-11-20).
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
        'appointments'    => 'appointments',
        'marketing'       => 'marketing',
        'builder'         => 'builder',
    ];

    /**
     * The import ended and the owner asked for the changes to go live: the Store builder is published
     * (the same as its Publish button), so the storefront shows the file's design and the store's new name.
     */
    private static function package_go_live() {
        try {
            $result = Shop_Settings_Builder::platform_publish();
        } catch ( Throwable $e ) {
            $result = [ 'error' => $e->getMessage() ];
        }
        self::mig_note( isset( $result['error'] )
            ? 'The import is done, but the changes couldn\'t go live by themselves. Open the Store builder and press Publish.'
            : 'The changes are live in your store.' );
    }

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

        // "Store name and description": after the snapshot, so Cancel puts the old ones back.
        $identity = ! empty( $params['identity'] ) && empty( $params['template'] );
        if ( $identity ) {
            $fname = trim( (string) ( $file['file']['name'] ?? '' ) );
            if ( $fname !== '' ) update_option( 'blogname', $fname );
            if ( isset( $file['file']['description'] ) && is_string( $file['file']['description'] ) ) {
                update_option( 'blogdescription', $file['file']['description'] );
            }
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
            // A template: the file's main language and the new store's (mig_lang_item / package_swap_language).
            'package_lang'     => (string) ( $file['file']['lang'] ?? '' ),
            'package_lang_to'  => ! empty( $params['template'] ) && class_exists( 'Qwoo_Store_Language' ) && isset( Qwoo_Store_Language::LANGS[ (string) ( $params['lang'] ?? '' ) ] ) ? (string) $params['lang'] : '',
            // "Put the changes live when the import ends" (mig_finish → package_go_live).
            'package_publish'  => ! empty( $params['publish'] ) && empty( $params['template'] ),
            'package_identity' => $identity,
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

    /**
     * A template brought into a store of another language: that language's
     * code when the swap applies (the file's copy in it becomes the main
     * texts, owner 2026-11-26), else ''.
     */
    private static function mig_swap_lang() {
        if ( empty( self::$mig['package_template'] ) ) return '';
        $from = (string) ( self::$mig['package_lang'] ?? '' );
        $to   = (string) ( self::$mig['package_lang_to'] ?? '' );
        return ( $from !== '' && $to !== '' && $to !== $from ) ? $to : '';
    }

    /**
     * An item (product, term, post) in the store's language: its texts from
     * the file's copy in that language, the file's own texts kept as the
     * main language's copy (ready if the owner turns that language on).
     */
    private static function mig_lang_item( $kind, array $item ) {
        $to = self::mig_swap_lang();
        if ( $to === '' || ! is_array( $item['i18n'][ $to ] ?? null ) ) return $item;
        $from = (string) self::$mig['package_lang'];
        $own  = $item['i18n'][ $to ];
        $keep = [];
        foreach ( array_keys( Qwoo_Translations::FIELDS[ $kind ] ?? [] ) as $field ) {
            $is_seo = strpos( $field, 'seo_' ) === 0;
            $sub    = $is_seo ? substr( $field, 4 ) : $field;
            $now    = $is_seo ? ( $item['seo'][ $sub ] ?? '' ) : ( $item[ $field ] ?? '' );
            if ( is_scalar( $now ) && trim( (string) $now ) !== '' ) $keep[ $field ] = (string) $now;
            $new = $own[ $field ] ?? '';
            if ( ! is_scalar( $new ) || trim( (string) $new ) === '' ) continue;
            if ( $is_seo ) {
                $item['seo']         = is_array( $item['seo'] ?? null ) ? $item['seo'] : [];
                $item['seo'][ $sub ] = (string) $new;
            } else {
                $item[ $field ] = (string) $new;
            }
        }
        unset( $item['i18n'][ $to ] );
        if ( $keep ) $item['i18n'][ $from ] = $keep;
        return $item;
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
            // A template in another language: the file's home title is in the wrong language (the home page's own SEO is swapped).
            if ( self::mig_swap_lang() !== '' ) $seo['home_title'] = $seo['home_description'] = '';
            update_option( Qwoo_Seo::OPTION, $seo );
        }
        // Settings → Cookie banner: look, texts and rules (templates too).
        if ( is_array( $q['cookie_banner'] ?? null ) && class_exists( 'Qwoo_Custom_Code' ) ) {
            Qwoo_Custom_Code::package_banner_in( $q['cookie_banner'] );
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
        // Sell online and Shopper accounts (Settings → General / Sign-in).
        if ( is_array( $q['shop_mode'] ?? null ) && class_exists( 'Qwoo_Shop_Mode' ) ) {
            $mode = $q['shop_mode'];
            $done = Qwoo_Shop_Mode::save( $mode, 'shop' );
            if ( ! is_wp_error( $done ) ) Qwoo_Shop_Mode::save( $mode, 'accounts' );
            if ( ! is_wp_error( $done ) && array_key_exists( 'sell', $mode ) && empty( $mode['sell'] ) ) {
                self::mig_note( 'Selling is off in the file\'s store, so it\'s off here too now (Settings → General → Sell online).' );
            }
        }
        // Products with no price (Products → Product settings).
        if ( is_array( $q['zero_price'] ?? null ) && class_exists( 'Qwoo_Zero_Price' ) ) {
            $done = Qwoo_Zero_Price::save( $q['zero_price'] );
            if ( is_wp_error( $done ) ) self::mig_problem( 'The settings for products with no price weren\'t copied: ' . $done->get_error_message() );
        }
        if ( is_array( $q['accessibility'] ?? null ) && class_exists( 'Qwoo_Accessibility' ) ) {
            $done = Qwoo_Accessibility::save( json_decode( wp_json_encode( $q['accessibility'] ), true ) ?: [] );
            if ( is_wp_error( $done ) ) self::mig_problem( 'The accessibility settings were not copied: ' . $done->get_error_message() );
        }
        if ( is_array( $q['giftcard_rules'] ?? null ) && class_exists( 'Qwoo_Gift_Cards' ) ) {
            update_option( Qwoo_Gift_Cards::RULES, Qwoo_Gift_Cards::clean_rules( $q['giftcard_rules'] ), false );
        }
        // Inbox: saved answers, automatic reply, the Help button (alerts keep going to this store's address).
        if ( is_array( $q['inbox'] ?? null ) && class_exists( 'Qwoo_Inbox' ) ) {
            $inbox = $q['inbox'];
            $inbox['notify_to'] = (string) ( Qwoo_Inbox::settings()['notify_to'] ?? '' );
            Qwoo_Inbox::save_settings( $inbox );
        }
    }

    /* ---------------- Appointments ---------------- */

    /**
     * Appointments from the file: settings, the team, then the services (with this store's team,
     * photos and categories). A service or person brought in before is updated; with "Replace",
     * services that aren't in the file are turned off (their bookings stay).
     */
    private static function mig_phase_appointments() {
        $data = self::mig_fetch( 'appointments' );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( ! class_exists( 'Qwoo_Appointments' ) ) {
            return true;
        }
        Qwoo_Appointments::maybe_install();
        $maps  = (array) ( self::$mig['maps'] ?? [] );
        $team  = (array) ( $maps['staff'] ?? [] );
        $svcs  = (array) ( $maps['service'] ?? [] );
        $mode  = (string) ( self::$mig['package_mode'] ?? 'add' );
        $same  = static fn( $a, $b ) => mb_strtolower( trim( (string) $a ) ) === mb_strtolower( trim( (string) $b ) );

        if ( is_array( $data['settings'] ?? null ) ) {
            $in   = array_intersect_key( $data['settings'], array_flip( [ 'enabled', 'hours', 'dates', 'rules', 'reminders', 'confirm_text', 'terms', 'quick', 'channels', 'sms_texts' ] ) );
            $done = Qwoo_Appointments::save_settings( $in );
            if ( is_wp_error( $done ) ) {
                unset( $in['enabled'] );
                Qwoo_Appointments::save_settings( $in );
                self::mig_note( 'Appointments aren\'t part of this store\'s plan, so they stay off. The services, team and hours are in place for when they\'re on.' );
            }
        }

        $have = Qwoo_Appointments::staff_list();
        foreach ( (array) ( $data['staff'] ?? [] ) as $p ) {
            if ( ! is_array( $p ) ) continue;
            $old = (int) ( $p['id'] ?? 0 );
            $in  = array_intersect_key( $p, array_flip( [ 'name', 'email', 'bio', 'color', 'hours', 'dates', 'calendar', 'notify', 'status' ] ) );
            $in['photo'] = ! empty( $p['photo'] ) ? self::mig_image( $p['photo'] ) : 0;
            $id = (int) ( $team[ $old ] ?? 0 );
            if ( ! $id || ! isset( $have[ $id ] ) ) {
                $id = 0;
                foreach ( $have as $h ) {
                    if ( $same( $h['name'], $in['name'] ?? '' ) ) { $id = (int) $h['id']; break; }
                }
            }
            if ( $id ) $in['id'] = $id;
            $saved = Qwoo_Appointments::save_staff( $in );
            if ( is_wp_error( $saved ) ) {
                self::mig_problem( sprintf( 'Team member “%s” wasn\'t added: %s', (string) ( $p['name'] ?? '' ), $saved->get_error_message() ) );
                continue;
            }
            $team[ $old ] = (int) $saved['id'];
        }

        $current = [];
        foreach ( Qwoo_Appointments::services() as $h ) $current[ (int) $h['id'] ] = $h;
        $kept = [];
        foreach ( (array) ( $data['services'] ?? [] ) as $svc ) {
            if ( ! is_array( $svc ) ) continue;
            $old = (int) ( $svc['id'] ?? 0 );
            $in  = $svc;
            unset( $in['id'], $in['product_id'] );
            $in['image']           = ! empty( $svc['image'] ) ? self::mig_image( $svc['image'] ) : 0;
            $in['staff']           = array_values( array_filter( array_map( static fn( $o ) => (int) ( $team[ (int) $o ] ?? 0 ), (array) ( $svc['staff'] ?? [] ) ) ) );
            $in['shop_categories'] = array_values( array_filter( array_map( static fn( $o ) => self::mig_term( 'product_cat', (int) $o ), (array) ( $svc['shop_categories'] ?? [] ) ) ) );
            $id = (int) ( $svcs[ $old ] ?? 0 );
            if ( ! $id || ! isset( $current[ $id ] ) ) {
                $id = 0;
                foreach ( $current as $h ) {
                    if ( ! in_array( (int) $h['id'], $kept, true ) && $same( $h['name'], $in['name'] ?? '' ) ) { $id = (int) $h['id']; break; }
                }
            }
            if ( $id ) $in['id'] = $id;
            $saved = Qwoo_Appointments::save_service( $in );
            if ( is_wp_error( $saved ) ) {
                self::mig_count( 'appointments', 'skipped' );
                self::mig_problem( sprintf( 'Service “%s” wasn\'t added: %s', (string) ( $svc['name'] ?? '' ), $saved->get_error_message() ) );
                continue;
            }
            $svcs[ $old ] = (int) $saved['id'];
            $kept[]       = (int) $saved['id'];
            self::mig_count( 'appointments', $id ? 'updated' : 'created' );
        }
        if ( $mode === 'replace' ) {
            $off = 0;
            foreach ( $current as $h ) {
                if ( in_array( (int) $h['id'], $kept, true ) || $h['status'] === 'off' ) continue;
                if ( ! is_wp_error( Qwoo_Appointments::save_service( [ 'id' => (int) $h['id'], 'status' => 'off' ] ) ) ) $off++;
            }
            if ( $off ) self::mig_note( sprintf( '%d services that weren\'t in the file were turned off (their bookings stay). Appointments → Services.', $off ) );
        }
        $maps['staff']     = $team;
        $maps['service']   = $svcs;
        self::$mig['maps'] = $maps;
        return true;
    }

    /* ---------------- Emails and marketing ---------------- */

    /** An email design from the file: its photos as this store's, product IDs as this store's. */
    private static function mig_design_in( $value ) {
        if ( ! is_array( $value ) ) return $value;
        if ( isset( $value['type'], $value['data'] ) && is_string( $value['type'] ) && is_array( $value['data'] ) ) {
            $d = $value['data'];
            foreach ( [ '_media' => 'url', '_cover' => 'cover' ] as $ref => $field ) {
                if ( ! isset( $d[ $ref ] ) ) continue;
                $id = self::mig_image( $d[ $ref ] );
                if ( $id ) $d[ $field ] = (string) wp_get_attachment_url( $id );
                unset( $d[ $ref ] );
            }
            if ( $value['type'] === 'products' && isset( $d['ids'] ) ) {
                $d['ids'] = array_values( array_filter( array_map( static fn( $o ) => self::mig_post( [ 'product' ], (int) $o ), (array) $d['ids'] ) ) );
            }
            $value['data'] = $d;
            return $value;
        }
        foreach ( $value as $k => $v ) {
            if ( is_array( $v ) ) $value[ $k ] = self::mig_design_in( $v );
        }
        return $value;
    }

    /**
     * Emails and marketing from the file, each through the dashboard's own checks: own events,
     * audiences, store emails and their look, saved blocks, email settings, the automations'
     * emails and draft emails, then the automations (they come in turned off).
     */
    private static function mig_phase_marketing() {
        $data = self::mig_fetch( 'marketing' );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $maps  = (array) ( self::$mig['maps'] ?? [] );
        $camps = (array) ( $maps['campaign'] ?? [] );
        $svcs  = (array) ( $maps['service'] ?? [] );
        $prod  = static fn( $o ) => self::mig_post( [ 'product' ], (int) $o );
        $cat   = static fn( $o ) => self::mig_term( 'product_cat', (int) $o );

        // Own events first: audiences and automations can start from them.
        if ( is_array( $data['events'] ?? null ) && class_exists( 'Qwoo_Events' ) ) {
            $wanted = count( (array) ( $data['events']['own'] ?? [] ) );
            $got    = Qwoo_Events::import( $data['events'] );
            if ( $wanted > $got ) self::mig_note( sprintf( '%d of the file\'s own events weren\'t added (they\'re part of the Pro and Plus plans, or the store has 30 already).', $wanted - $got ) );
        }

        if ( is_array( $data['audiences'] ?? null ) && class_exists( 'Qwoo_Personalization' ) ) {
            $list = [];
            foreach ( $data['audiences'] as $a ) {
                if ( ! is_array( $a ) ) continue;
                foreach ( (array) ( $a['rules'] ?? [] ) as $i => $rule ) {
                    if ( isset( $rule['category_id'] ) ) $a['rules'][ $i ]['category_id'] = $cat( $rule['category_id'] );
                    if ( isset( $rule['product_id'] ) ) $a['rules'][ $i ]['product_id'] = $prod( $rule['product_id'] );
                }
                $list[] = $a;
            }
            $got = Qwoo_Personalization::import_audiences( $list );
            if ( count( $list ) > $got ) self::mig_note( sprintf( '%d audiences weren\'t added: their conditions point at products or categories that didn\'t come in.', count( $list ) - $got ) );
            for ( $i = 0; $i < $got; $i++ ) self::mig_count( 'marketing', 'created' );
        }

        if ( class_exists( 'Qwoo_Store_Emails' ) ) {
            $catalog = Qwoo_Store_Emails::catalog();
            foreach ( (array) ( $data['store_emails'] ?? [] ) as $id => $row ) {
                if ( ! is_array( $row ) || ! isset( $catalog[ $id ] ) ) continue;
                $cur = Qwoo_Store_Emails::saved( (string) $id );
                $set = [
                    'id'        => (string) $id,
                    'subject'   => (string) ( $row['subject'] ?? ( $cur['subject'] ?? '' ) ),
                    'preheader' => (string) ( $row['preheader'] ?? ( $cur['preheader'] ?? '' ) ),
                    // Who gets it stays this store's.
                    'to'        => (array) ( $cur['to'] ?? [] ),
                    'copy'      => (array) ( $cur['copy'] ?? [] ),
                ];
                if ( array_key_exists( 'on', $row ) ) $set['on'] = ! empty( $row['on'] );
                if ( $catalog[ $id ]['group'] !== 'team' || $set['to'] ) Qwoo_Store_Emails::save_settings( $set );
                if ( ! empty( $row['design'] ) ) Qwoo_Store_Emails::save_design( [ 'id' => (string) $id, 'design' => self::mig_design_in( $row['design'] ) ] );
            }
            if ( is_array( $data['email_style'] ?? null ) ) {
                $style = $data['email_style'];
                if ( ! empty( $style['_logo'] ) ) {
                    $logo          = self::mig_image( $style['_logo'] );
                    $style['logo'] = $logo ? (string) wp_get_attachment_url( $logo ) : '';
                } elseif ( strpos( (string) ( $style['logo'] ?? '' ), '/wp-content/uploads/' ) !== false ) {
                    // The other store's photo that wasn't in the file: the Store builder logo is used.
                    $style['logo'] = '';
                }
                unset( $style['_logo'] );
                Qwoo_Store_Emails::save_style( $style );
            }
            $names = array_map( static fn( $b ) => (string) ( $b['name'] ?? '' ), Qwoo_Store_Emails::saved_blocks() );
            foreach ( array_reverse( (array) ( $data['saved_blocks'] ?? [] ) ) as $b ) {
                if ( ! is_array( $b ) || in_array( (string) ( $b['name'] ?? '' ), $names, true ) ) continue;
                if ( is_wp_error( Qwoo_Store_Emails::save_block( [ 'name' => (string) ( $b['name'] ?? '' ), 'block' => self::mig_design_in( $b['block'] ?? [] ) ] ) ) ) break;
            }
        }

        if ( class_exists( 'Qwoo_Email_Marketing' ) ) {
            if ( is_array( $data['email_settings'] ?? null ) ) {
                Qwoo_Email_Marketing::save_settings( array_merge( Qwoo_Email_Marketing::settings(), array_intersect_key( $data['email_settings'], array_flip( [ 'ad_label', 'ad_word', 'optin_on', 'optin_text' ] ) ) ) );
            }
            foreach ( (array) ( $data['campaigns'] ?? [] ) as $c ) {
                if ( ! is_array( $c ) ) continue;
                $old = (int) ( $c['id'] ?? 0 );
                $in  = array_intersect_key( $c, array_flip( [ 'name', 'subject', 'preheader', 'segment', 'audience_id', 'segment_days' ] ) );
                if ( ( $in['segment'] ?? '' ) === 'saved_audience' && ! ( class_exists( 'Qwoo_Personalization' ) && Qwoo_Personalization::exists( (string) ( $in['audience_id'] ?? '' ) ) ) ) {
                    $in['segment'] = 'all';
                }
                $in['design'] = self::mig_design_in( $c['design'] ?? [] );
                $id = (int) ( $camps[ $old ] ?? 0 );
                if ( $id && Qwoo_Email_Marketing::campaign_row( $id ) ) $in['id'] = $id;
                $saved = Qwoo_Email_Marketing::save_campaign( $in, ( $c['kind'] ?? '' ) === 'flow' ? 'flow' : 'campaign' );
                if ( is_wp_error( $saved ) ) {
                    self::mig_problem( sprintf( 'The email “%s” wasn\'t added: %s', (string) ( $c['name'] ?? $c['subject'] ?? '' ), $saved->get_error_message() ) );
                    continue;
                }
                $camps[ $old ] = (int) $saved['id'];
                self::mig_count( 'marketing', $id ? 'updated' : 'created' );
            }
            $maps['campaign']  = $camps;
            self::$mig['maps'] = $maps;
        }

        if ( is_array( $data['flows'] ?? null ) && $data['flows'] && class_exists( 'Qwoo_Automations' ) ) {
            $flows = [];
            foreach ( $data['flows'] as $f ) {
                if ( ! is_array( $f ) ) continue;
                $t = (array) ( $f['trigger'] ?? [] );
                if ( isset( $t['category_id'] ) ) $t['category_id'] = $cat( $t['category_id'] );
                if ( isset( $t['service_id'] ) ) $t['service_id'] = (int) ( $svcs[ (int) $t['service_id'] ] ?? 0 );
                $f['trigger'] = $t;
                foreach ( (array) ( $f['rules'] ?? [] ) as $i => $rule ) {
                    if ( ( $rule['check'] ?? '' ) === 'order_has_product' ) $f['rules'][ $i ]['ids'] = array_values( array_filter( array_map( $prod, (array) ( $rule['ids'] ?? [] ) ) ) );
                    if ( ( $rule['check'] ?? '' ) === 'order_has_category' ) $f['rules'][ $i ]['ids'] = array_values( array_filter( array_map( $cat, (array) ( $rule['ids'] ?? [] ) ) ) );
                }
                $steps = [];
                foreach ( (array) ( $f['steps'] ?? [] ) as $step ) {
                    if ( ( $step['type'] ?? '' ) === 'email' ) {
                        $step['campaign_id'] = (int) ( $camps[ (int) ( $step['campaign_id'] ?? 0 ) ] ?? 0 );
                        if ( ! $step['campaign_id'] ) continue;
                    }
                    $steps[] = $step;
                }
                $f['steps'] = $steps;
                // Nothing starts sending by itself in a store that was just filled in.
                $f['on']    = false;
                $flows[]    = $f;
            }
            $got = Qwoo_Automations::import( $flows );
            if ( $got < 0 ) {
                self::mig_note( 'Automations aren\'t part of this store\'s plan, so the file\'s automations weren\'t added.' );
            } else {
                if ( $got ) self::mig_note( 'The automations came in turned off. Check each one in Marketing → Automations, then turn it on.' );
                if ( count( $flows ) > $got ) self::mig_problem( sprintf( '%d automations weren\'t added: what starts them (an audience, an event or a service) didn\'t come in.', count( $flows ) - $got ) );
                for ( $i = 0; $i < $got; $i++ ) self::mig_count( 'marketing', 'created' );
            }
        }
        if ( class_exists( 'Qwoo_Custom_Code' ) ) Qwoo_Custom_Code::touch();
        return true;
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
                case 'service':
                    return (int) ( self::$mig['maps']['service'][ (int) $old ] ?? 0 );
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
        $to     = self::mig_swap_lang();
        if ( $to !== '' && is_array( $data['options'] ?? null ) ) {
            $data['options'] = Shop_Settings_Builder::package_swap_language( $data['options'], (string) self::$mig['package_lang'], $to );
        }
        $result = Shop_Settings_Builder::package_builder_import( $data, $map, $mode );
        for ( $i = 0; $i < (int) $result['pages']; $i++ ) {
            self::mig_count( 'builder', $mode === 'replace' ? 'updated' : 'created' );
        }
        if ( $result['skipped'] ) {
            self::mig_note( 'These pages weren\'t added because your store already has a page at the same address (or one with the same role): ' . implode( ', ', array_slice( $result['skipped'], 0, 20 ) ) . '.' );
        }
        if ( empty( self::$mig['package_publish'] ) && ( $mode === 'replace' || $result['pages'] > 0 ) ) self::mig_note( $mode === 'replace'
            ? 'Your Store builder now has the file\'s design and pages. Open the Store builder and publish to put them live.'
            : 'The file\'s pages were added to your Store builder as drafts. Publish them when they\'re ready.' );
        return true;
    }
}

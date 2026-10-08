<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Starter-template import ("reverse setup"): turns a template's published
 * files back into Shop Builder data, so a new store's builder starts with
 * exactly what the template shows. The files are read from this store's
 * content repo (which the platform created from the template).
 *
 * It reverses the push flow (handle_github_push() + output_sections()):
 *   - image/video payloads {url, width, height} become Media Library
 *     attachments (uploaded from the repo, or downloaded when the template
 *     links a file on another site, like videos) and the field gets the
 *     attachment id back;
 *   - ISO datetimes go back to "Y-m-dTH:i" in the store's timezone;
 *   - home hero_image and branding logo / app_icon become their *_id fields;
 *   - the result goes through sanitize_options(), like a normal save.
 *
 * Uploading images takes time, so the work is split into calls of a few
 * seconds: the platform calls import_template_step() until it answers
 * done. Progress is kept in the qwoo_template_import option.
 */
trait SB_Template_Import {

    private static $import_option = 'qwoo_template_import';

    /**
     * Runs the import for up to $budget seconds.
     *
     * @return array [ 'done' => bool, ... ] or [ 'error' => message ].
     */
    public static function import_template_step( $budget = 20.0 ) {
        $gh = Qwoo_Platform_Connection::github_settings();
        if ( ! $gh ) {
            return [ 'error' => 'No access to the content repository.' ];
        }

        $state = get_option( self::$import_option, [] );
        $state = is_array( $state ) ? $state : [];
        if ( ! empty( $state['done'] ) ) {
            return [ 'done' => true ] + (array) ( $state['summary'] ?? [] );
        }

        // 1. Read the template's page settings.
        if ( empty( $state['pages'] ) ) {
            $pages = [];
            foreach ( self::publishable_pages() as $slug ) {
                $raw = self::template_repo_file( $gh, "public/config/{$slug}.json" );
                if ( $raw === false ) {
                    return [ 'error' => "Could not read public/config/{$slug}.json from the content repository." ];
                }
                if ( $raw === null ) continue; // this template doesn't set that page
                $json = json_decode( $raw, true );
                if ( is_array( $json ) ) $pages[ $slug ] = $json;
            }
            if ( ! $pages ) {
                return [ 'error' => 'The content repository has no page settings (public/config/*.json).' ];
            }
            $state = [
                'pages'  => $pages,
                'images' => self::template_images( $pages ),
                'map'    => [],
                'failed' => [],
            ];
            update_option( self::$import_option, $state, false );
        }

        // 2. Media: one file at a time, saving progress after each.
        $deadline = microtime( true ) + $budget;
        foreach ( $state['images'] as $key => $source ) {
            if ( isset( $state['map'][ $key ] ) || isset( $state['failed'][ $key ] ) ) continue;
            if ( microtime( true ) > $deadline ) {
                return [ 'done' => false, 'imported' => count( $state['map'] ), 'total' => count( $state['images'] ) ];
            }
            $result = self::template_import_media( $gh, $source );
            if ( is_int( $result ) ) {
                $state['map'][ $key ] = $result;
            } else {
                $state['failed'][ $key ] = $result;
            }
            update_option( self::$import_option, $state, false );
        }

        // 3. Builder data, saved like a normal save.
        $input = [];
        foreach ( $state['pages'] as $slug => $page ) {
            $data = $page;
            if ( isset( $data['sections'] ) && is_array( $data['sections'] ) ) {
                $data['sections'] = self::reverse_sections( $data['sections'], $state['map'] );
            }
            if ( $slug === 'home' ) {
                $data['hero_image_id'] = self::mapped_id( $page['hero_image'] ?? '', 'public/homepage-hero/', $state['map'] );
                unset( $data['hero_image'] );
            }
            if ( $slug === 'branding' ) {
                $data['logo_id']     = self::mapped_id( $page['logo'] ?? '', 'public/branding/', $state['map'] );
                $data['app_icon_id'] = self::mapped_id( $page['app_icon'] ?? '', 'public/branding/', $state['map'] );
                unset( $data['logo'], $data['app_icon'] );
            }
            $input[ $slug ] = $data;
        }

        $clean = self::instance()->sanitize_options( $input );
        // Keep anything the template doesn't cover (e.g. the contact button).
        $final = array_merge( (array) get_option( 'shop_builder_options', [] ), $clean );
        update_option( 'shop_builder_options', $final );
        self::record_revision( $final );

        $summary = [
            'pages'  => array_keys( $state['pages'] ),
            'images' => count( $state['map'] ),
            'failed' => array_values( $state['failed'] ),
        ];
        update_option( self::$import_option, [ 'done' => true, 'summary' => $summary, 'map' => $state['map'] ], false );

        return [ 'done' => true ] + $summary;
    }

    /* ---------------- the owner's branding (platform signup) ---------------- */

    private static $branding_option = 'qwoo_platform_branding';

    /**
     * Applies what the owner chose when signing up, after the template
     * import: colors, logo and app icon (Branding tab), and the store name
     * and colors (PWA tab). Then pushes every page to the content repo, which
     * redeploys the storefront, and regenerates the app icons from the new
     * icon. Runs once; later calls answer done.
     *
     * @param array $input [
     *   'name'   => store name,
     *   'colors' => [ 'primary' => '#hex', 'secondary' => ..., 'accent' => ..., 'text' => ... ] (any subset),
     *   'logo'   => [ 'name' => file name, 'data' => base64 ] (optional),
     *   'icon'   => [ 'name' => file name, 'data' => base64 ] (optional, square PNG/JPG/WebP),
     * ]
     * @return array [ 'done' => true, 'warnings' => [...] ] or [ 'error' => message ].
     */
    public static function apply_platform_branding( array $input ) {
        $state = get_option( self::$branding_option, [] );
        $state = is_array( $state ) ? $state : [];
        if ( ! empty( $state['done'] ) ) {
            return [ 'done' => true, 'warnings' => (array) ( $state['warnings'] ?? [] ) ];
        }
        $gh = Qwoo_Platform_Connection::github_settings();
        if ( ! $gh ) {
            return [ 'error' => 'No access to the content repository.' ];
        }

        // What the store sells (optional at signup): its tagline and the homepage's opening text.
        $description = mb_substr( trim( sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ) ), 0, 300 );
        if ( $description !== '' ) {
            update_option( 'blogdescription', $description );
        }
        // Home (from the template's homepage), privacy policy, terms, shipping & returns.
        self::ensure_store_pages( true, trim( (string) ( $input['name'] ?? '' ) ) );

        $options  = (array) get_option( 'shop_builder_options', [] );
        $branding = (array) ( $options['branding'] ?? [] );
        $pwa      = (array) ( $options['pwa'] ?? [] );
        $warnings = [];

        // 1. Files (kept in the state, so a retry doesn't upload them twice).
        foreach ( [ 'logo' => 'logo_id', 'icon' => 'app_icon_id' ] as $key => $field ) {
            if ( empty( $input[ $key ]['data'] ) ) continue;
            if ( empty( $state[ $field ] ) ) {
                $bytes = base64_decode( (string) $input[ $key ]['data'], true );
                $name  = sanitize_file_name( (string) ( $input[ $key ]['name'] ?? $key ) );
                $id    = $bytes ? self::template_import_media( $gh, [ 'bytes' => $bytes, 'name' => $name ] ) : 'empty file';
                if ( ! is_int( $id ) ) {
                    $warnings[] = "The {$key} could not be added: {$id}";
                    continue;
                }
                $state[ $field ] = $id;
                update_option( self::$branding_option, $state, false );
            }
            $branding[ $field ] = $state[ $field ];
        }

        // 2. Colors and the app's name.
        $colors = (array) ( $branding['global_colors'] ?? [] );
        foreach ( (array) ( $input['colors'] ?? [] ) as $key => $value ) {
            if ( isset( self::GLOBAL_COLOR_KEYS[ $key ] ) && sanitize_hex_color( $value ) ) {
                $colors[ $key ] = $value;
            }
        }
        $branding['global_colors'] = $colors;

        $name = trim( (string) ( $input['name'] ?? '' ) );
        if ( $name !== '' ) {
            $pwa['name']       = $name;
            $pwa['short_name'] = mb_substr( $name, 0, 12 );
        }
        if ( ! empty( $input['colors']['primary'] ) && sanitize_hex_color( $input['colors']['primary'] ) ) {
            $pwa['theme_color'] = $input['colors']['primary'];
        }
        $pwa['background_color'] = $pwa['background_color'] ?? '#ffffff';

        $clean = self::instance()->sanitize_options( [ 'branding' => $branding, 'pwa' => $pwa ] );
        $final = array_merge( $options, $clean );
        update_option( 'shop_builder_options', $final );
        self::record_revision( $final );

        // 3. Live: push every page, then the app icons.
        $push = self::instance()->push_all_pages();
        if ( isset( $push['error'] ) ) {
            return [ 'error' => $push['error'] ];
        }
        // The owner's icon (or logo); without either, the template's icons are removed.
        $icon_warning = self::platform_sync_icons( $final );
        if ( $icon_warning !== '' ) {
            $warnings[] = $icon_warning;
        }

        update_option( self::$branding_option, [ 'done' => true, 'warnings' => $warnings ], false );
        return [ 'done' => true, 'warnings' => $warnings ];
    }

    /* ---------------- images in the template ---------------- */

    /**
     * Every file the template's pages use: [ key => source ]. A source is
     * [ 'repo' => 'public/...', 'name' => file ] or [ 'url' => https URL, 'name' => file ].
     * Keyed by the repo path / URL, so a file used twice is imported once.
     */
    private static function template_images( array $pages ) {
        $images = [];
        $add    = static function ( $source ) use ( &$images ) {
            if ( $source ) $images[ $source['repo'] ?? $source['url'] ] = $source;
        };

        $add( self::image_source( $pages['home']['hero_image'] ?? '', 'public/homepage-hero/' ) );
        $add( self::image_source( $pages['branding']['logo'] ?? '', 'public/branding/' ) );
        $add( self::image_source( $pages['branding']['app_icon'] ?? '', 'public/branding/' ) );

        // Section/block payloads: { url, width, height }.
        $walk = static function ( $value ) use ( &$walk, $add ) {
            if ( ! is_array( $value ) ) return;
            if ( self::is_media_payload( $value ) ) {
                $add( self::image_source( $value['url'], '' ) );
                return;
            }
            foreach ( $value as $child ) $walk( $child );
        };
        foreach ( $pages as $page ) {
            $walk( $page['sections'] ?? [] );
        }

        return $images;
    }

    private static function is_media_payload( $value ) {
        return is_array( $value ) && isset( $value['url'] ) && is_string( $value['url'] )
            && array_key_exists( 'width', $value ) && array_key_exists( 'height', $value );
    }

    /**
     * Where a published URL's file is.
     *  - "/sections/12-a.png"            -> repo public/sections/12-a.png
     *  - absolute URL with $folder given -> repo $folder + file name (hero, branding:
     *                                       the push stores those files there)
     *  - other https URL                 -> downloaded from that URL (e.g. videos)
     */
    private static function image_source( $url, $folder ) {
        if ( ! is_string( $url ) || $url === '' ) return null;

        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $file = sanitize_file_name( wp_basename( $path ) );
        if ( $file === '' ) return null;

        if ( $folder !== '' ) {
            return [ 'repo' => $folder . $file, 'name' => $file ];
        }
        if ( $url[0] === '/' && strpos( $url, '//' ) !== 0 ) {
            $repo = 'public' . $path;
            if ( strpos( $repo, '..' ) !== false ) return null;
            // Section images are stored as "{attachment id}-{file name}".
            return [ 'repo' => $repo, 'name' => preg_replace( '/^\d+-/', '', $file ) ];
        }
        if ( stripos( $url, 'https://' ) === 0 ) {
            return [ 'url' => $url, 'name' => $file ];
        }
        return null;
    }

    private static function mapped_id( $url, $folder, array $map ) {
        $source = self::image_source( $url, $folder );
        return $source ? (int) ( $map[ $source['repo'] ?? $source['url'] ] ?? 0 ) : 0;
    }

    /**
     * Adds one file to the Media Library. $source is a repo file, a URL, or
     * [ 'bytes' => file contents, 'name' => file name ].
     *
     * @return int|string Attachment id, or a reason it failed.
     */
    private static function template_import_media( array $gh, array $source ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $name = $source['name'];
        if ( isset( $source['bytes'] ) ) {
            $tmp = wp_tempnam( $name );
            file_put_contents( $tmp, $source['bytes'] );
        } elseif ( isset( $source['repo'] ) ) {
            $bytes = self::template_repo_file( $gh, $source['repo'] );
            if ( ! is_string( $bytes ) || $bytes === '' ) {
                return "{$source['repo']}: not found in the content repository";
            }
            $tmp = wp_tempnam( $name );
            file_put_contents( $tmp, $bytes );
        } else {
            $tmp = download_url( $source['url'], 120 );
            if ( is_wp_error( $tmp ) ) {
                return "{$source['url']}: " . $tmp->get_error_message();
            }
        }

        $is_svg = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) === 'svg';
        if ( $is_svg ) {
            // Rebuilt from a whitelist: only the safe parts of the file are kept.
            $clean = Qwoo_Svg_Sanitizer::sanitize( (string) file_get_contents( $tmp ) );
            if ( $clean === null ) {
                @unlink( $tmp );
                return "{$name}: not a usable SVG file";
            }
            file_put_contents( $tmp, $clean );
        }

        // WordPress blocks SVG uploads by default; allow them for this file only.
        $mimes = static function ( $m ) { return $m + [ 'svg' => 'image/svg+xml' ]; };
        $check = static function ( $data, $file, $filename ) use ( $is_svg ) {
            return $is_svg ? [ 'ext' => 'svg', 'type' => 'image/svg+xml', 'proper_filename' => false ] : $data;
        };
        if ( $is_svg ) {
            add_filter( 'upload_mimes', $mimes );
            add_filter( 'wp_check_filetype_and_ext', $check, 10, 3 );
        }

        $id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], 0 );

        if ( $is_svg ) {
            remove_filter( 'upload_mimes', $mimes );
            remove_filter( 'wp_check_filetype_and_ext', $check, 10 );
        }
        if ( is_wp_error( $id ) ) {
            @unlink( $tmp );
            return "{$name}: " . $id->get_error_message();
        }
        return (int) $id;
    }

    /**
     * A file from the content repo: its contents, null when it doesn't exist,
     * or false when GitHub couldn't be reached.
     */
    private static function template_repo_file( array $gh, $path ) {
        $url = 'https://api.github.com/repos/' . rawurlencode( $gh['owner'] ) . '/' . rawurlencode( $gh['repo'] )
            . '/contents/' . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) )
            . '?ref=' . rawurlencode( $gh['branch'] );

        $response = wp_remote_get( $url, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'token ' . $gh['token'],
                'Accept'        => 'application/vnd.github.raw',
                'User-Agent'    => 'qwoo-core',
            ],
        ] );
        if ( is_wp_error( $response ) ) {
            error_log( "Qwoo template import: {$path}: " . $response->get_error_message() );
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code === 404 ) return null;
        if ( $code !== 200 ) {
            error_log( "Qwoo template import: {$path} returned {$code}." );
            return false;
        }
        return (string) wp_remote_retrieve_body( $response );
    }

    /* ---------------- published shape -> stored shape ---------------- */

    private static function reverse_sections( array $sections, array $map ) {
        $out = [];
        foreach ( $sections as $section ) {
            if ( ! is_array( $section ) || empty( $section['id'] ) ) continue;
            $row = [
                'id'      => $section['id'],
                'enabled' => true,
                'style'   => self::reverse_fields( self::SECTION_STYLE_FIELDS, $section['style'] ?? [], $map ),
                'blocks'  => self::reverse_blocks( $section['blocks'] ?? [], $map ),
            ];
            if ( isset( $section['location'] ) ) $row['location'] = $section['location'];
            $out[] = $row;
        }
        return $out;
    }

    private static function reverse_blocks( $blocks, array $map ) {
        $out = [];
        foreach ( is_array( $blocks ) ? $blocks : [] as $block ) {
            if ( ! is_array( $block ) || empty( $block['id'] ) ) continue;
            $type = $block['type'] ?? '';
            if ( $type === 'section' ) {
                $out[] = [
                    'id'      => $block['id'],
                    'type'    => 'section',
                    'enabled' => true,
                    'style'   => self::reverse_fields( self::SECTION_STYLE_FIELDS, $block['style'] ?? [], $map ),
                    'blocks'  => self::reverse_blocks( $block['blocks'] ?? [], $map ),
                ];
                continue;
            }
            $schema = self::get_type_schema( $type );
            if ( ! $schema ) continue;
            $out[] = [
                'id'      => $block['id'],
                'type'    => $type,
                'enabled' => true,
                'style'   => self::reverse_fields( self::BLOCK_COMMON_FIELDS, $block['style'] ?? [], $map ),
                'data'    => self::reverse_fields( $schema['fields'], $block['data'] ?? [], $map ),
            ];
        }
        return $out;
    }

    private static function reverse_fields( array $specs, $data, array $map ) {
        $out = [];
        foreach ( is_array( $data ) ? $data : [] as $key => $value ) {
            $spec = $specs[ $key ] ?? null;
            $type = $spec['type'] ?? '';

            if ( $type === 'repeater' ) {
                $items = [];
                foreach ( is_array( $value ) ? $value : [] as $item ) {
                    $items[] = self::reverse_fields( $spec['fields'] ?? [], $item, $map );
                }
                $out[ $key ] = $items;
            } elseif ( $type === 'image' || $type === 'video' ) {
                $out[ $key ] = self::is_device_map( $value ) && ! self::is_media_payload( $value )
                    ? array_map( static function ( $v ) use ( $map ) { return self::payload_to_id( $v, $map ); }, $value )
                    : self::payload_to_id( $value, $map );
            } elseif ( $type === 'datetime' ) {
                $out[ $key ] = self::iso_to_local( $value );
            } else {
                $out[ $key ] = $value;
            }
        }
        return $out;
    }

    private static function payload_to_id( $value, array $map ) {
        if ( ! self::is_media_payload( $value ) ) return 0;
        $source = self::image_source( $value['url'], '' );
        return $source ? (int) ( $map[ $source['repo'] ?? $source['url'] ] ?? 0 ) : 0;
    }

    /** "2026-12-31T23:59:00+02:00" -> "2026-12-31T23:59" in the store's timezone. */
    private static function iso_to_local( $value ) {
        if ( ! is_string( $value ) || $value === '' ) return '';
        try {
            return ( new DateTimeImmutable( $value ) )->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
        } catch ( Exception $e ) {
            return '';
        }
    }
}

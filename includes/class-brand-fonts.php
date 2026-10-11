<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Brand fonts (Store builder → Branding): the store's fonts, set once and
 * used everywhere, like the brand colors.
 *
 *   text      paragraphs, menus, prices… ('' = the storefront's own Roboto)
 *   headings  every title                 ('' = same as text)
 *   buttons   every button                ('' = same as text)
 *   custom    the owner's own named fonts ({ id cf_…, name, family }), for
 *             items that should look different (a block's "Font" picks one)
 *
 * Stored in shop_builder_options['branding']['fonts']. Fonts come from a
 * hand-picked list of Google Fonts (includes/fonts/catalog.json, built by
 * the dashboard's scripts/font-catalog.mjs). Publishing downloads the files
 * once (uploads/qwoo-fonts/) and serves them from the storefront itself
 * (/brand-fonts/…): visitors never contact Google.
 *
 * branding.json gets `fonts`:
 *   vars      { key: family or '' }   what each brand font is
 *   families  { family: { generic, fallback } }
 *   faces     [ { family, weight, src, range } ]   the @font-face rules
 * The storefront turns it into CSS variables (--qf-{key}).
 */
class Qwoo_Brand_Fonts {

    /** The built-in brand fonts, in the order the dashboard shows them. */
    const BUILTIN = [
        'text'     => 'Text',
        'headings' => 'Headings',
        'buttons'  => 'Buttons',
    ];

    const MAX_CUSTOM  = 10;
    const ID_RE       = '/^cf_[a-zA-Z0-9]{6,12}$/';
    const FOLDER      = 'public/brand-fonts/';
    const CACHE_DIR   = 'qwoo-fonts';
    const MAX_BYTES   = 2097152; // 2 MB a file

    /** Hebrew letters for fonts without them: serif fonts get a serif one. */
    const HEBREW_FALLBACK = [ 'serif' => 'Frank Ruhl Libre', 'default' => 'Heebo' ];

    private static $catalog = null;

    /** { family: { kind, generic, hebrew, weights, faces: [ { s, w, u, r } ] } } */
    public static function catalog() {
        if ( self::$catalog === null ) {
            $raw  = @file_get_contents( __DIR__ . '/fonts/catalog.json' );
            $json = $raw ? json_decode( $raw, true ) : null;
            self::$catalog = is_array( $json['fonts'] ?? null ) ? $json['fonts'] : [];
        }
        return self::$catalog;
    }

    /** The font names the dashboard may offer (it has its own copy of the list). */
    public static function families() {
        return array_keys( self::catalog() );
    }

    private static function known( $family ) {
        return is_string( $family ) && $family !== '' && isset( self::catalog()[ $family ] );
    }

    /** The saved settings, complete. */
    public static function get( $options = null ) {
        $options = is_array( $options ) ? $options : get_option( 'shop_builder_options', [] );
        return self::sanitize( $options['branding']['fonts'] ?? [] );
    }

    public static function sanitize( $input ) {
        $input = is_array( $input ) ? $input : [];
        $out   = [];
        foreach ( array_keys( self::BUILTIN ) as $key ) {
            $out[ $key ] = self::known( $input[ $key ] ?? '' ) ? $input[ $key ] : '';
        }
        $out['custom'] = [];
        $seen          = [];
        foreach ( array_slice( array_values( (array) ( $input['custom'] ?? [] ) ), 0, self::MAX_CUSTOM ) as $font ) {
            if ( ! is_array( $font ) ) continue;
            $id = (string) ( $font['id'] ?? '' );
            if ( ! preg_match( self::ID_RE, $id ) || isset( $seen[ $id ] ) ) continue;
            $seen[ $id ]     = true;
            $out['custom'][] = [
                'id'     => $id,
                'name'   => mb_substr( sanitize_text_field( (string) ( $font['name'] ?? '' ) ), 0, 40 ),
                'family' => self::known( $font['family'] ?? '' ) ? $font['family'] : '',
            ];
        }
        return $out;
    }

    /** A block's "Font" value: a built-in key, a custom id, or '' (the default). */
    public static function sanitize_choice( $value ) {
        $value = is_scalar( $value ) ? (string) $value : '';
        return isset( self::BUILTIN[ $value ] ) || preg_match( self::ID_RE, $value ) ? $value : '';
    }

    /** Whether the store shows Hebrew anywhere (its main language or an extra one). */
    private static function needs_hebrew() {
        if ( ! class_exists( 'Qwoo_Store_Language' ) ) return false;
        return Qwoo_Store_Language::get() === 'he' || in_array( 'he', Qwoo_Store_Language::extra(), true );
    }

    /**
     * What branding.json gets, with the font files staged in the publish
     * batch (files no font uses any more are removed). Returns null when the
     * store uses no brand font (nothing to add).
     */
    public static function stage( &$batch, array $fonts, array &$path_to_label, $label ) {
        $fonts   = self::sanitize( $fonts );
        $catalog = self::catalog();
        $hebrew  = self::needs_hebrew();

        $vars = [];
        foreach ( array_keys( self::BUILTIN ) as $key ) {
            $vars[ $key ] = $fonts[ $key ];
        }
        foreach ( $fonts['custom'] as $font ) {
            $vars[ $font['id'] ] = $font['family'];
        }

        // Each font used, and the Hebrew letters for those without them.
        $families = [];
        foreach ( array_unique( array_filter( $vars ) ) as $family ) {
            $meta     = $catalog[ $family ];
            $fallback = null;
            if ( $hebrew && empty( $meta['hebrew'] ) ) {
                $fallback = $meta['generic'] === 'serif' ? self::HEBREW_FALLBACK['serif'] : self::HEBREW_FALLBACK['default'];
            }
            $families[ $family ] = [ 'generic' => $meta['generic'], 'fallback' => $fallback ];
        }

        $wanted = []; // family => subsets
        foreach ( $families as $family => $info ) {
            $wanted[ $family ] = array_merge( $wanted[ $family ] ?? [], $hebrew ? [ 'latin', 'latin-ext', 'hebrew' ] : [ 'latin', 'latin-ext' ] );
            if ( $info['fallback'] ) {
                $wanted[ $info['fallback'] ] = array_merge( $wanted[ $info['fallback'] ] ?? [], [ 'hebrew' ] );
            }
        }

        $faces = [];
        $kept  = [];
        foreach ( $wanted as $family => $subsets ) {
            foreach ( (array) ( $catalog[ $family ]['faces'] ?? [] ) as $face ) {
                if ( ! in_array( $face['s'], $subsets, true ) ) continue;
                $bytes = self::file( (string) $face['u'] );
                if ( $bytes === null ) {
                    error_log( "Qwoo: brand font file for {$family} ({$face['s']} {$face['w']}) could not be downloaded, skipping it." );
                    continue;
                }
                // Named after its content: a changed file is a new address (cached for a year).
                $name = sanitize_title( $family ) . '-' . $face['s'] . '-' . str_replace( ' ', '-', $face['w'] ) . '-' . substr( sha1( $bytes ), 0, 10 ) . '.woff2';
                $path = self::FOLDER . $name;
                if ( ! isset( $kept[ $path ] ) ) {
                    aps_github_batch_put_file( $batch, $path, $bytes );
                    $kept[ $path ]          = true;
                    $path_to_label[ $path ] = "{$label} (fonts)";
                }
                $faces[] = [ 'family' => $family, 'weight' => $face['w'], 'src' => '/brand-fonts/' . $name, 'range' => $face['r'] ];
            }
        }

        foreach ( array_keys( $batch['existing'] ) as $old ) {
            if ( strpos( $old, self::FOLDER ) === 0 && ! isset( $kept[ $old ] ) ) {
                $batch['tree_updates'][] = [ 'path' => $old, 'mode' => '100644', 'type' => 'blob', 'sha' => null ];
                $batch['deleted'][]      = $old;
                $path_to_label[ $old ]   = "{$label} (fonts)";
            }
        }

        if ( ! array_filter( $vars ) && ! $fonts['custom'] ) {
            return null;
        }
        return [ 'vars' => (object) $vars, 'families' => (object) $families, 'faces' => $faces ];
    }

    /**
     * A font file from Google's font server, downloaded once and kept on the
     * store (the same address never changes). null when it can't be had.
     */
    private static function file( $url ) {
        if ( ! preg_match( '#^https://fonts\.gstatic\.com/[A-Za-z0-9/._-]+\.woff2$#', $url ) ) return null;

        $uploads = wp_upload_dir( null, false );
        $dir     = trailingslashit( $uploads['basedir'] ) . self::CACHE_DIR . '/';
        $file    = $dir . md5( $url ) . '.woff2';
        if ( is_readable( $file ) ) {
            $bytes = file_get_contents( $file );
            if ( is_string( $bytes ) && self::is_woff2( $bytes ) ) return $bytes;
        }

        $response = wp_safe_remote_get( $url, [ 'timeout' => 20, 'redirection' => 0, 'limit_response_size' => self::MAX_BYTES ] );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return null;
        $bytes = wp_remote_retrieve_body( $response );
        if ( ! self::is_woff2( $bytes ) ) return null;

        if ( ( is_dir( $dir ) || wp_mkdir_p( $dir ) ) ) {
            if ( ! file_exists( $dir . 'index.html' ) ) @file_put_contents( $dir . 'index.html', '' );
            @file_put_contents( $file, $bytes );
        }
        return $bytes;
    }

    private static function is_woff2( $bytes ) {
        return is_string( $bytes ) && strlen( $bytes ) > 64 && strlen( $bytes ) <= self::MAX_BYTES && strncmp( $bytes, 'wOF2', 4 ) === 0;
    }
}

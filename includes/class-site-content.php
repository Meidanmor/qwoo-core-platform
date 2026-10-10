<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The storefront's published files (configs, pages, section and branding
 * images, icons, the products backup), kept on this store instead of in a
 * GitHub content repo.
 *
 * Publishing used to commit to GitHub, and every commit rebuilt the
 * storefront on Vercel (a minute or more, and GitHub's limits are shared by
 * every store). Now a publish writes the files here and the storefront reads
 * them while it runs, so changes are live within seconds and no build runs.
 *
 *   uploads/qwoo-site/files/{sha}.{ext}  each file once, named by its content
 *                                        (git blob sha1), so it never changes
 *   uploads/qwoo-site/v/{version}.json   a version: { version, time, files:
 *                                        { "config/home.json": { sha, ext,
 *                                        size } }, json: { the config files'
 *                                        content } }
 *   option qwoo_site_current             the live version
 *
 * The storefront asks GET qwoo/v1/site (behind the proxy secret) which
 * version is live, through its CDN every few seconds, and keeps that
 * version's files in memory.
 *
 * Switching over: the store keeps publishing to GitHub until its storefront
 * runs code that reads from here (it says so in the X-Qwoo-Storefront
 * header). The first version copies what the content repo holds, so nothing
 * published earlier (icons, the products backup) is lost.
 *
 * The publish code talks to this through the same batch helpers it used for
 * GitHub (aps_github_start_batch() & co. in admin-options.php): paths stay
 * "public/…", and a batch can be applied on top of a newer version (a publish
 * and the daily products backup at the same time).
 */
class Qwoo_Site_Content {

    const DIR      = 'qwoo-site';
    const CURRENT  = 'qwoo_site_current';  // [ version, time ]
    const VERSIONS = 'qwoo_site_versions'; // newest first: [ [ version, time ], … ]
    const MODE     = 'qwoo_site_mode';     // 'local' once the storefront reads from here
    const LOCK     = 'qwoo_site_lock';
    const KEEP     = 10;                   // versions kept (with their files)

    /** The storefront code that reads published files from the store. */
    const STOREFRONT_VERSION = 2;

    /** Folders (and root files) of public/ that are the store's content. */
    const CONTENT = [ 'config/', 'data/', 'sections/', 'homepage-hero/', 'branding/', 'icons/', 'favicon.ico' ];

    /** File types kept; anything else is stored as .bin (never runnable on the server). */
    const EXTENSIONS = [ 'json', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'svg', 'ico', 'mp4', 'webm', 'txt', 'xml' ];

    /** Why the last batch couldn't start (shown to the owner). */
    public static $last_error = '';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes() {
        register_rest_route( 'qwoo/v1', '/site', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_site' ],
            // Behind the proxy secret like every qwoo/v1 route (only the storefront's server has it).
            'permission_callback' => '__return_true',
        ] );
    }

    /** Whether publishing writes here (the storefront reads from here). */
    public static function enabled() {
        return get_option( self::MODE ) === 'local';
    }

    /**
     * { version, manifest, files } of the live version, or { version: null }
     * before the first one. Also where a new storefront says it reads from
     * here, which switches publishing over for good.
     */
    public static function rest_site( WP_REST_Request $request ) {
        if ( (int) $request->get_header( 'x-qwoo-storefront' ) >= self::STOREFRONT_VERSION && ! self::enabled() ) {
            update_option( self::MODE, 'local', true );
        }

        $current = get_option( self::CURRENT );
        $body    = is_array( $current ) && ! empty( $current['version'] )
            ? [
                'version'  => (string) $current['version'],
                'time'     => (int) ( $current['time'] ?? 0 ),
                'manifest' => self::url( 'v/' . $current['version'] . '.json' ),
                'files'    => self::url( 'files/' ),
            ]
            : [ 'version' => null ];
        $body['mode'] = self::enabled() ? 'local' : 'github';

        $response = new WP_REST_Response( $body );
        $response->header( 'Cache-Control', 'no-store, max-age=0' );
        return $response;
    }

    /* ---------------- storage ---------------- */

    private static function dir( $sub = '' ) {
        $uploads = wp_upload_dir( null, false );
        return trailingslashit( $uploads['basedir'] ) . self::DIR . '/' . $sub;
    }

    private static function url( $sub = '' ) {
        $uploads = wp_upload_dir( null, false );
        return set_url_scheme( trailingslashit( $uploads['baseurl'] ) . self::DIR . '/' . $sub );
    }

    private static function ensure_dirs() {
        foreach ( [ '', 'files/', 'v/' ] as $sub ) {
            $dir = self::dir( $sub );
            if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
                return false;
            }
            if ( ! file_exists( $dir . 'index.html' ) ) {
                @file_put_contents( $dir . 'index.html', '' ); // no folder listings
            }
        }
        return true;
    }

    /** "public/branding/12-logo.svg" → "svg". */
    private static function ext_of( $path ) {
        $ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
        return in_array( $ext, self::EXTENSIONS, true ) ? $ext : 'bin';
    }

    private static function blob_sha( $content ) {
        return sha1( 'blob ' . strlen( $content ) . "\0" . $content );
    }

    private static function is_content_path( $path ) {
        foreach ( self::CONTENT as $prefix ) {
            if ( $path === $prefix || ( substr( $prefix, -1 ) === '/' && strpos( $path, $prefix ) === 0 ) ) {
                return true;
            }
        }
        return false;
    }

    /** Writes a file once (its name is its content's hash). */
    private static function store_file( $content, $sha, $ext ) {
        $file = self::dir( 'files/' ) . $sha . '.' . $ext;
        if ( file_exists( $file ) && filesize( $file ) === strlen( $content ) ) {
            return true;
        }
        $tmp = $file . '.' . wp_generate_password( 8, false ) . '.tmp';
        if ( file_put_contents( $tmp, $content ) === false ) {
            return false;
        }
        return @rename( $tmp, $file ) || ( @unlink( $tmp ) && file_exists( $file ) );
    }

    /** A stored version: [ version, time, files, json ], or null. */
    public static function manifest( $version ) {
        if ( ! is_string( $version ) || ! preg_match( '/^[a-f0-9]{20,40}$/', $version ) ) {
            return null;
        }
        $raw  = @file_get_contents( self::dir( 'v/' ) . $version . '.json' );
        $data = $raw ? json_decode( $raw, true ) : null;
        return is_array( $data ) && isset( $data['files'] ) ? $data : null;
    }

    private static function current_manifest() {
        $current = get_option( self::CURRENT );
        return is_array( $current ) ? self::manifest( (string) ( $current['version'] ?? '' ) ) : null;
    }

    /* ---------------- batches (see aps_github_start_batch()) ---------------- */

    /**
     * A batch on top of the live version (the first one copies the content
     * repo). false when it can't start ($last_error says why).
     */
    public static function start_batch() {
        self::$last_error = '';
        if ( ! self::ensure_dirs() ) {
            self::$last_error = 'The store could not write its published files (uploads folder).';
            return false;
        }

        $manifest = self::current_manifest();
        if ( ! $manifest && ! self::seed() ) {
            return false;
        }
        $manifest = $manifest ?: self::current_manifest();

        $existing = [];
        foreach ( (array) ( $manifest['files'] ?? [] ) as $path => $file ) {
            $existing[ 'public/' . $path ] = (string) $file['sha'];
        }

        return [
            'store'        => 'local',
            'existing'     => $existing,
            'tree_updates' => [],
            'updated'      => [],
            'skipped'      => [],
            'deleted'      => [],
            'failed'       => [],
        ];
    }

    /** Stages one file (skipped when it's what's already published). */
    public static function batch_put( &$batch, $path, $content ) {
        $content = (string) $content;
        $sha     = self::blob_sha( $content );

        if ( isset( $batch['existing'][ $path ] ) && $batch['existing'][ $path ] === $sha ) {
            $batch['skipped'][] = $path;
            return true;
        }
        if ( ! self::store_file( $content, $sha, self::ext_of( $path ) ) ) {
            error_log( "Qwoo: could not store published file {$path}." );
            $batch['failed'][] = $path;
            return false;
        }
        $batch['tree_updates'][] = [ 'path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $sha, 'size' => strlen( $content ) ];
        $batch['updated'][]      = $path;
        return true;
    }

    /**
     * Makes the staged changes the live version (on top of whatever is live
     * now). true, 'no_changes', or false.
     */
    public static function finish_batch( $batch, $message = '' ) {
        if ( empty( $batch['tree_updates'] ) ) {
            return 'no_changes';
        }
        if ( ! self::lock() ) {
            error_log( 'Qwoo: another publish kept the published files busy; this one was not saved.' );
            return false;
        }
        try {
            $base  = self::current_manifest() ?: [ 'files' => [], 'json' => [] ];
            $files = (array) $base['files'];
            $json  = (array) ( $base['json'] ?? [] );

            foreach ( $batch['tree_updates'] as $entry ) {
                $path = (string) $entry['path'];
                if ( strpos( $path, 'public/' ) !== 0 ) continue;
                $path = substr( $path, 7 );
                if ( $entry['sha'] === null ) {
                    unset( $files[ $path ], $json[ $path ] );
                    continue;
                }
                $ext            = self::ext_of( $path );
                $files[ $path ] = [ 'sha' => (string) $entry['sha'], 'ext' => $ext, 'size' => (int) ( $entry['size'] ?? 0 ) ];
                unset( $json[ $path ] );
                // The config files travel inside the version itself: one download for the storefront.
                if ( $ext === 'json' && strpos( $path, 'config/' ) === 0 ) {
                    $decoded = json_decode( (string) @file_get_contents( self::dir( 'files/' ) . $entry['sha'] . '.json' ), true );
                    if ( $decoded !== null ) $json[ $path ] = $decoded;
                }
            }

            return self::save_version( $files, $json, $message );
        } finally {
            self::unlock();
        }
    }

    /** Writes a version and makes it live. true, 'no_changes' or false. */
    private static function save_version( array $files, array $json, $message ) {
        ksort( $files );
        ksort( $json );
        $version = substr( sha1( wp_json_encode( $files ) ), 0, 24 );

        $current = get_option( self::CURRENT );
        if ( is_array( $current ) && ( $current['version'] ?? '' ) === $version ) {
            return 'no_changes';
        }

        $time     = time();
        $manifest = [ 'version' => $version, 'time' => $time, 'message' => (string) $message, 'files' => (object) $files, 'json' => (object) $json ];
        $encoded  = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $target   = self::dir( 'v/' ) . $version . '.json';
        $tmp      = $target . '.' . wp_generate_password( 8, false ) . '.tmp';
        if ( ! $encoded || file_put_contents( $tmp, $encoded ) === false || ! @rename( $tmp, $target ) ) {
            @unlink( $tmp );
            error_log( 'Qwoo: could not write the published version.' );
            return false;
        }

        update_option( self::CURRENT, [ 'version' => $version, 'time' => $time ], true );

        $versions = get_option( self::VERSIONS, [] );
        $versions = is_array( $versions ) ? $versions : [];
        $versions = array_values( array_filter( $versions, static fn( $v ) => ( $v['version'] ?? '' ) !== $version ) );
        array_unshift( $versions, [ 'version' => $version, 'time' => $time ] );
        update_option( self::VERSIONS, array_slice( $versions, 0, self::KEEP ), false );

        self::cleanup();
        return true;
    }

    /** Removes versions past the last KEEP, and files no kept version uses. */
    private static function cleanup() {
        $versions = (array) get_option( self::VERSIONS, [] );
        $keep     = [];
        $used     = [];
        foreach ( $versions as $v ) {
            $m = self::manifest( (string) ( $v['version'] ?? '' ) );
            if ( ! $m ) continue;
            $keep[ $m['version'] ] = true;
            foreach ( (array) $m['files'] as $file ) {
                $used[ $file['sha'] . '.' . $file['ext'] ] = true;
            }
        }
        if ( ! $keep ) return; // never clean up without knowing what's live

        foreach ( (array) glob( self::dir( 'v/' ) . '*.json' ) as $file ) {
            if ( ! isset( $keep[ basename( $file, '.json' ) ] ) ) @unlink( $file );
        }
        foreach ( (array) glob( self::dir( 'files/' ) . '*' ) as $file ) {
            $name = basename( $file );
            if ( $name === 'index.html' ) continue;
            // Leftovers of an interrupted write older than an hour go too.
            if ( substr( $name, -4 ) === '.tmp' ) {
                if ( filemtime( $file ) < time() - HOUR_IN_SECONDS ) @unlink( $file );
                continue;
            }
            if ( ! isset( $used[ $name ] ) ) @unlink( $file );
        }
    }

    /** One batch at a time finishes (a lock older than a minute is a crashed one). */
    private static function lock() {
        for ( $i = 0; $i < 20; $i++ ) {
            if ( add_option( self::LOCK, time(), '', false ) ) {
                return true;
            }
            $since = (int) get_option( self::LOCK );
            if ( $since && $since < time() - 60 ) {
                delete_option( self::LOCK );
                continue;
            }
            usleep( 250000 );
        }
        return false;
    }

    private static function unlock() {
        delete_option( self::LOCK );
    }

    /* ---------------- switching over from GitHub ---------------- */

    /**
     * The first version: what the content repo holds now (only the content
     * folders). A store without a repo starts empty. false when the repo
     * couldn't be read (nothing is published from here until it can, so the
     * storefront never loses files).
     */
    private static function seed() {
        $gh    = class_exists( 'Qwoo_Platform_Connection' ) ? Qwoo_Platform_Connection::github_settings() : null;
        $files = [];
        $json  = [];

        if ( $gh ) {
            $read = self::read_repo( $gh );
            if ( $read === false ) {
                self::$last_error = 'Publishing is moving to a faster system and needs to copy your published files once. That copy didn\'t work just now: try again in a few minutes.';
                return false;
            }
            foreach ( $read as $path => $content ) {
                $sha = self::blob_sha( $content );
                $ext = self::ext_of( $path );
                if ( ! self::store_file( $content, $sha, $ext ) ) {
                    self::$last_error = 'The store could not write its published files (uploads folder).';
                    return false;
                }
                $files[ $path ] = [ 'sha' => $sha, 'ext' => $ext, 'size' => strlen( $content ) ];
                if ( $ext === 'json' && strpos( $path, 'config/' ) === 0 ) {
                    $decoded = json_decode( $content, true );
                    if ( $decoded !== null ) $json[ $path ] = $decoded;
                }
            }
        }

        if ( ! self::lock() ) {
            self::$last_error = 'Another publish is running. Try again in a moment.';
            return false;
        }
        try {
            if ( self::current_manifest() ) {
                return true; // another request did it meanwhile
            }
            if ( self::save_version( $files, $json, 'Copied from the content repository' ) === false ) {
                self::$last_error = 'The store could not write its published files (uploads folder).';
                return false;
            }
            return true;
        } finally {
            self::unlock();
        }
    }

    /**
     * The repo's content files: [ "config/home.json" => bytes ], or false.
     * One download (the repo as a tarball), else file by file.
     */
    private static function read_repo( array $gh ) {
        $base    = 'https://api.github.com/repos/' . rawurlencode( $gh['owner'] ) . '/' . rawurlencode( $gh['repo'] );
        $headers = [
            'Authorization' => 'token ' . $gh['token'],
            'User-Agent'    => 'qwoo-store',
            'Accept'        => 'application/vnd.github+json',
        ];

        if ( class_exists( 'PharData' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; // wp_tempnam()
            $temp = wp_tempnam( 'qwoo-site' );
            @unlink( $temp );
            $tmp = $temp . '.tar.gz'; // PharData goes by the extension
            $res = wp_remote_get( $base . '/tarball/' . rawurlencode( $gh['branch'] ), [
                'headers'  => $headers,
                'timeout'  => 60,
                'stream'   => true,
                'filename' => $tmp,
            ] );
            if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
                $out = self::read_tarball( $tmp );
                @unlink( $tmp );
                if ( $out !== false ) return $out;
            } else {
                @unlink( $tmp );
                if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 404 ) {
                    return []; // no repo (or an empty one): nothing to copy
                }
            }
        }

        // File by file.
        $res = wp_remote_get( $base . '/git/trees/' . rawurlencode( $gh['branch'] ) . '?recursive=1', [ 'headers' => $headers, 'timeout' => 30 ] );
        if ( is_wp_error( $res ) ) return false;
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code === 404 || $code === 409 ) return []; // empty repo
        $tree = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( $code !== 200 || ! is_array( $tree['tree'] ?? null ) ) return false;

        $out = [];
        foreach ( $tree['tree'] as $entry ) {
            if ( ( $entry['type'] ?? '' ) !== 'blob' || strpos( (string) $entry['path'], 'public/' ) !== 0 ) continue;
            $path = substr( $entry['path'], 7 );
            if ( ! self::is_content_path( $path ) ) continue;
            if ( count( $out ) >= 500 ) break;
            $blob = wp_remote_get( $base . '/git/blobs/' . $entry['sha'], [ 'headers' => [ 'Accept' => 'application/vnd.github.raw' ] + $headers, 'timeout' => 30 ] );
            if ( is_wp_error( $blob ) || wp_remote_retrieve_response_code( $blob ) !== 200 ) return false;
            $out[ $path ] = wp_remote_retrieve_body( $blob );
        }
        return $out;
    }

    /** The content files inside a GitHub tarball ("owner-repo-sha/public/…"), or false. */
    private static function read_tarball( $file ) {
        try {
            $archive = new PharData( $file );
            $out     = [];
            foreach ( new RecursiveIteratorIterator( $archive ) as $item ) {
                if ( ! $item->isFile() ) continue;
                $full   = str_replace( '\\', '/', $item->getPathname() );
                $at     = strpos( $full, '.tar.gz/' );
                if ( $at === false ) continue;
                $parts  = explode( '/', substr( $full, $at + 8 ), 2 ); // drop "owner-repo-sha/"
                $path   = $parts[1] ?? '';
                if ( strpos( $path, 'public/' ) !== 0 ) continue;
                $path = substr( $path, 7 );
                if ( ! self::is_content_path( $path ) ) continue;
                $out[ $path ] = file_get_contents( $item->getPathname() );
                if ( count( $out ) >= 500 ) break;
            }
            return $out;
        } catch ( Throwable $e ) {
            error_log( 'Qwoo: reading the content repo download failed: ' . $e->getMessage() );
            return false;
        }
    }
}

Qwoo_Site_Content::init();

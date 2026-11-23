<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Faster pictures: a WebP copy of every JPEG / PNG upload and of each of its
 * smaller sizes, saved next to the original under its own name
 * ("photo-600x754.png" → "photo-600x754.png.webp"). The originals and their
 * addresses never change, so caches can never mix the two up.
 *
 *   - New uploads: made when WordPress saves the picture's sizes.
 *   - Older pictures: converted in small batches by the store's own runner
 *     (Qwoo_Push_Engine's qwoo_push_tick, no cron); when the last batch is
 *     done, the live design's pictures get their WebP files too
 *     (refresh_live(): same design, no drafts published).
 *   - The storefront gets the addresses next to the originals: product
 *     pictures as webp_src / webp_srcset (Store API and the store's own
 *     answers), Store builder pictures as `webp` in the published design.
 *     It offers them only while the switch is on (pointer field `webp`), so
 *     turning it off shows the originals at once.
 *   - Copies larger than their original are thrown away. GIF, SVG and
 *     videos are never converted. A server that can't make WebP converts nothing.
 *
 * Settings → General → Faster pictures (on unless the owner turns it off).
 */
class Qwoo_Webp {

    const OPTION  = 'qwoo_webp';     // [ 'off' => bool ]
    const STATE   = 'qwoo_webp_job'; // [ last, done, total, finished, refreshed ]
    const META    = '_qwoo_webp';    // [ original file name => webp file name, or '' (no smaller copy) ]
    const SUPPORT = 'qwoo_webp_support';
    const QUALITY = 80;
    const BATCH   = 15;
    const MIMES   = [ 'image/jpeg', 'image/png' ];

    public static function init() {
        add_filter( 'wp_update_attachment_metadata', [ __CLASS__, 'on_metadata' ], 20, 2 );
        add_action( 'delete_attachment', [ __CLASS__, 'on_delete' ] );
        add_action( 'qwoo_push_tick', [ __CLASS__, 'run_batch' ] );
        add_filter( 'rest_post_dispatch', [ __CLASS__, 'on_rest' ], 20, 3 );
        add_action( 'init', [ __CLASS__, 'keep_going' ], 30 );
    }

    /* ---------------- the switch ---------------- */

    /** Can this server make WebP files (asked once a day). */
    public static function supported(): bool {
        $cached = get_transient( self::SUPPORT );
        if ( $cached === 'yes' || $cached === 'no' ) return $cached === 'yes';
        if ( ! function_exists( 'wp_image_editor_supports' ) ) require_once ABSPATH . WPINC . '/media.php';
        $yes = (bool) wp_image_editor_supports( [ 'mime_type' => 'image/webp', 'methods' => [ 'save' ] ] );
        set_transient( self::SUPPORT, $yes ? 'yes' : 'no', DAY_IN_SECONDS );
        return $yes;
    }

    /** Turned on (the default) and the server can make them. */
    public static function on(): bool {
        $s = get_option( self::OPTION, [] );
        return empty( $s['off'] ) && self::supported();
    }

    private static function state(): array {
        $s = get_option( self::STATE, [] );
        return is_array( $s ) ? $s + [ 'last' => 0, 'done' => 0, 'total' => 0, 'finished' => false, 'refreshed' => false ] : [ 'last' => 0, 'done' => 0, 'total' => 0, 'finished' => false, 'refreshed' => false ];
    }

    /** For the dashboard (Settings → General). */
    public static function for_dashboard(): array {
        $s = get_option( self::OPTION, [] );
        $j = self::state();
        return [
            'on'        => empty( $s['off'] ),
            'supported' => self::supported(),
            'done'      => (int) $j['done'],
            'total'     => (int) $j['total'],
            'finished'  => ! empty( $j['finished'] ),
        ];
    }

    /** Settings → General → Faster pictures. */
    public static function save( array $v ) {
        if ( ! array_key_exists( 'on', $v ) ) return true;
        update_option( self::OPTION, [ 'off' => empty( $v['on'] ) ], true );
        if ( ! empty( $v['on'] ) ) self::keep_going();
        return true;
    }

    /** What the storefront reads live (no publish needed). */
    public static function site_fields(): array {
        return self::on() ? [ 'webp' => true ] : [];
    }

    /* ---------------- making the copies ---------------- */

    /** The WebP name for a file name: "a.png" → "a.png.webp" (a.jpg and a.png never share one). */
    private static function webp_name( string $file ): string {
        return $file . '.webp';
    }

    /** The picture's copies: [ original file name => webp file name or '' ]. */
    public static function copies( int $id ): array {
        $m = get_post_meta( $id, self::META, true );
        return is_array( $m ) ? $m : [];
    }

    /** WordPress saved a picture's sizes (upload, edit, regenerate): copies for what's missing. */
    public static function on_metadata( $data, $id ) {
        if ( is_array( $data ) && self::on() && in_array( (string) get_post_mime_type( $id ), self::MIMES, true ) ) {
            self::convert( (int) $id, $data );
        }
        return $data;
    }

    /**
     * Makes the WebP copies a picture doesn't have yet. $meta: the metadata being saved
     * (WordPress hasn't written it yet when called from on_metadata).
     */
    public static function convert( int $id, ?array $meta = null ): bool {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) return false;
        $meta = $meta ?? wp_get_attachment_metadata( $id );
        $mime = (string) get_post_mime_type( $id );
        if ( ! in_array( $mime, self::MIMES, true ) ) return false;

        $dir     = trailingslashit( dirname( $file ) );
        $sources = [ basename( $file ) ];
        foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
            if ( empty( $size['file'] ) ) continue;
            if ( ! empty( $size['mime-type'] ) && ! in_array( $size['mime-type'], self::MIMES, true ) ) continue;
            $sources[] = basename( (string) $size['file'] );
        }

        $copies  = self::copies( $id );
        $changed = false;
        foreach ( array_unique( $sources ) as $name ) {
            if ( array_key_exists( $name, $copies ) && ( $copies[ $name ] === '' || file_exists( $dir . $copies[ $name ] ) ) ) continue;
            $src = $dir . $name;
            if ( ! file_exists( $src ) ) continue;
            $copies[ $name ] = self::make( $src, $dir . self::webp_name( $name ) ) ? self::webp_name( $name ) : '';
            $changed = true;
        }
        if ( $changed ) {
            // Quiet: Qwoo_Content_Stamp ignores this key (the job touches the stamp once when it ends).
            update_post_meta( $id, self::META, $copies );
        }
        return true;
    }

    /** One WebP file; false (and nothing left behind) when it fails or isn't smaller. */
    private static function make( string $src, string $target ): bool {
        $editor = wp_get_image_editor( $src );
        if ( is_wp_error( $editor ) ) return false;
        $editor->set_quality( self::QUALITY );
        $saved = $editor->save( $target, 'image/webp' );
        if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) return false;
        if ( $saved['path'] !== $target ) {
            @rename( $saved['path'], $target );
        }
        clearstatcache();
        if ( ! file_exists( $target ) || filesize( $target ) >= filesize( $src ) ) {
            @unlink( $target );
            return false;
        }
        return true;
    }

    public static function on_delete( $id ) {
        $file = get_attached_file( (int) $id );
        if ( ! $file ) return;
        $dir = trailingslashit( dirname( $file ) );
        foreach ( self::copies( (int) $id ) as $webp ) {
            if ( $webp !== '' && strpos( $webp, '/' ) === false ) @unlink( $dir . $webp );
        }
    }

    /* ---------------- older pictures: the job ---------------- */

    private static function pending_query( int $after, int $limit ): array {
        global $wpdb;
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png') AND ID > %d ORDER BY ID ASC LIMIT %d",
            $after, $limit
        ) ) );
    }

    /** While the job isn't done: makes sure the store's runner comes back to it. */
    public static function keep_going() {
        if ( ! self::on() || ! class_exists( 'Qwoo_Push_Engine' ) ) return;
        $j = self::state();
        if ( ! empty( $j['finished'] ) && ! empty( $j['refreshed'] ) ) return;
        Qwoo_Push_Engine::note_due( time() );
    }

    /** One run's worth of pictures (from qwoo_push_tick, until $deadline). */
    public static function run_batch( $deadline ) {
        if ( ! self::on() ) return;
        $j = self::state();
        if ( ! empty( $j['finished'] ) && ! empty( $j['refreshed'] ) ) return;
        $deadline = (float) $deadline;

        if ( empty( $j['finished'] ) ) {
            if ( ! $j['total'] ) {
                global $wpdb;
                $j['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png')" );
            }
            while ( microtime( true ) < $deadline - 4 ) {
                $ids = self::pending_query( (int) $j['last'], self::BATCH );
                if ( ! $ids ) {
                    $j['finished'] = true;
                    break;
                }
                foreach ( $ids as $id ) {
                    if ( microtime( true ) >= $deadline - 4 ) break 2;
                    self::convert( $id );
                    $j['last'] = $id;
                    $j['done'] = min( (int) $j['total'], (int) $j['done'] + 1 );
                }
            }
            update_option( self::STATE, $j, true );
        }

        if ( ! empty( $j['finished'] ) && empty( $j['refreshed'] ) ) {
            // The live design: its pictures' WebP files and addresses (drafts untouched).
            $j['refreshed'] = self::refresh_live() !== false;
            update_option( self::STATE, $j, true );
            // Product answers kept by the storefront: fetched again, now with their WebP addresses.
            if ( class_exists( 'Qwoo_Content_Stamp' ) ) Qwoo_Content_Stamp::touch( 'webp' );
        }

        if ( empty( $j['finished'] ) || empty( $j['refreshed'] ) ) {
            Qwoo_Push_Engine::note_due( time() );
            Qwoo_Push_Engine::spawn_soon();
        }
    }

    /* ---------------- addresses for the storefront ---------------- */

    /** "…/photo-600x754.png" → "…/photo-600x754.png.webp" when that copy exists, else ''. */
    public static function url_for( int $id, string $url ): string {
        if ( $url === '' ) return '';
        $copies = self::copies( $id );
        $path   = (string) wp_parse_url( $url, PHP_URL_PATH );
        $name   = rawurldecode( basename( $path ) );
        if ( empty( $copies[ $name ] ) ) return '';
        $q = strpos( $url, '?' );
        $base = $q === false ? $url : substr( $url, 0, $q );
        return substr( $base, 0, strlen( $base ) - strlen( basename( $path ) ) ) . rawurlencode( $copies[ $name ] );
    }

    /** A srcset string with every entry that has a copy ("a.png.webp 300w, …"); '' when none. */
    public static function srcset_for( int $id, string $srcset ): string {
        $out = [];
        foreach ( explode( ',', $srcset ) as $part ) {
            $bits = preg_split( '/\s+/', trim( $part ) );
            if ( count( $bits ) < 2 ) continue;
            $webp = self::url_for( $id, $bits[0] );
            if ( $webp !== '' ) $out[] = $webp . ' ' . $bits[1];
        }
        return implode( ', ', $out );
    }

    /** Store API and the store's own answers: webp_src / webp_srcset on every product picture. */
    public static function on_rest( $result, $server, $request ) {
        if ( ! ( $result instanceof WP_REST_Response ) || $result->is_error() ) return $result;
        $route = (string) $request->get_route();
        if ( strpos( $route, '/wc/store' ) !== 0 && strpos( $route, '/qwoo/v1/' ) !== 0 ) return $result;
        if ( ! self::on() ) return $result;
        $data = $result->get_data();
        if ( ! is_array( $data ) && ! is_object( $data ) ) return $result;

        $ids = [];
        self::collect( $data, $ids );
        if ( ! $ids ) return $result;
        update_meta_cache( 'post', array_keys( $ids ) );
        $result->set_data( self::add( $data ) );
        return $result;
    }

    private static function is_picture( $node ): bool {
        return is_array( $node ) && isset( $node['id'], $node['src'] ) && is_numeric( $node['id'] ) && is_string( $node['src'] ) && array_key_exists( 'srcset', $node );
    }

    private static function collect( $node, array &$ids, int $depth = 0 ) {
        if ( $depth > 12 ) return;
        if ( is_object( $node ) ) $node = get_object_vars( $node );
        if ( ! is_array( $node ) ) return;
        if ( self::is_picture( $node ) ) $ids[ (int) $node['id'] ] = true;
        foreach ( $node as $v ) {
            if ( is_array( $v ) || is_object( $v ) ) self::collect( $v, $ids, $depth + 1 );
        }
    }

    /** [ webp_src, webp_srcset ] for a picture, or null when it has no copies. */
    private static function fields( array $pic ): ?array {
        $id  = (int) $pic['id'];
        $src = self::url_for( $id, (string) $pic['src'] );
        $set = self::srcset_for( $id, (string) ( $pic['srcset'] ?? '' ) );
        return $src !== '' || $set !== '' ? [ $src, $set ] : null;
    }

    private static function add( $node, int $depth = 0 ) {
        if ( $depth > 12 ) return $node;
        // WooCommerce's Store API answers with objects (its image schema) inside arrays.
        if ( is_object( $node ) ) {
            if ( ! ( $node instanceof stdClass ) ) return $node;
            $vars = get_object_vars( $node );
            if ( self::is_picture( $vars ) && ( $f = self::fields( $vars ) ) ) {
                $node->webp_src    = $f[0];
                $node->webp_srcset = $f[1];
            }
            foreach ( $vars as $k => $v ) {
                if ( is_array( $v ) || is_object( $v ) ) $node->$k = self::add( $v, $depth + 1 );
            }
            return $node;
        }
        if ( ! is_array( $node ) ) return $node;
        if ( self::is_picture( $node ) && ( $f = self::fields( $node ) ) ) {
            $node['webp_src']    = $f[0];
            $node['webp_srcset'] = $f[1];
        }
        foreach ( $node as $k => $v ) {
            if ( is_array( $v ) || is_object( $v ) ) $node[ $k ] = self::add( $v, $depth + 1 );
        }
        return $node;
    }

    /* ---------------- Store builder pictures ---------------- */

    /**
     * Stages the WebP files of a published picture and adds their addresses to its payload:
     * `webp` for the full picture, `webp` on each srcset entry. $dir: "public/sections/".
     * Only for stores whose published files live on the store (the storefront reads `webp` there).
     */
    public static function stage_payload( &$batch, int $id, array $payload, string $dir, array &$kept, array &$path_to_label, string $label ): array {
        if ( ( $batch['store'] ?? '' ) !== 'local' || ! self::on() ) return $payload;
        $file = get_attached_file( $id );
        if ( ! $file ) return $payload;
        $copies = self::copies( $id );
        if ( ! $copies ) {
            self::convert( $id );
            $copies = self::copies( $id );
        }
        $src_dir = trailingslashit( dirname( $file ) );
        $public  = '/' . substr( $dir, strlen( 'public/' ) );

        $stage = function ( string $original_name ) use ( &$batch, $id, $copies, $src_dir, $dir, $public, &$kept, &$path_to_label, $label ) {
            if ( empty( $copies[ $original_name ] ) ) return '';
            $repo = $dir . "{$id}-" . sanitize_file_name( $copies[ $original_name ] );
            if ( ! isset( $kept[ $repo ] ) ) {
                $bytes = @file_get_contents( $src_dir . $copies[ $original_name ] );
                if ( $bytes === false ) return '';
                aps_github_batch_put_file( $batch, $repo, $bytes );
                $kept[ $repo ]          = true;
                $path_to_label[ $repo ] = "{$label} (section image)";
            }
            return $public . substr( $repo, strlen( $dir ) );
        };

        $full = $stage( basename( $file ) );
        if ( $full !== '' ) $payload['webp'] = $full;

        if ( ! empty( $payload['srcset'] ) && is_array( $payload['srcset'] ) ) {
            // Each entry's address is "/sections/{id}-{sanitized size file}".
            $by_clean = [];
            foreach ( array_keys( $copies ) as $name ) $by_clean[ sanitize_file_name( $name ) ] = $name;
            foreach ( $payload['srcset'] as $i => $entry ) {
                $clean = preg_replace( '/^' . $id . '-/', '', basename( (string) ( $entry['url'] ?? '' ) ) );
                if ( ! isset( $by_clean[ $clean ] ) ) continue;
                $w = $stage( $by_clean[ $clean ] );
                if ( $w !== '' ) $payload['srcset'][ $i ]['webp'] = $w;
            }
        }
        return $payload;
    }

    /**
     * The live design's pictures get their WebP files and addresses: a new published version
     * with only that added (the saved drafts are never published here). false on failure,
     * 'github' for stores still publishing to a content repo (they get it on their next publish).
     */
    public static function refresh_live() {
        if ( ! class_exists( 'Qwoo_Site_Content' ) || ! Qwoo_Site_Content::enabled() ) return 'github';
        $json = Qwoo_Site_Content::live_json();
        if ( ! $json ) return 'nothing';
        $batch = aps_github_start_batch();
        if ( ! is_array( $batch ) || ( $batch['store'] ?? '' ) !== 'local' ) return false;

        $kept   = [];
        $labels = [];
        foreach ( $json as $path => $content ) {
            if ( strpos( $path, 'config/' ) !== 0 || ! is_array( $content ) ) continue;
            $changed = false;
            $content = self::walk_design( $batch, $content, $kept, $labels, $changed );
            if ( strpos( basename( $path ), 'branding' ) === 0 ) {
                $content = self::branding_logo( $batch, $content, $kept, $labels, $changed );
            }
            if ( $changed ) {
                aps_github_batch_put_file( $batch, 'public/' . $path, aps_normalize_json( json_encode( $content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
            }
        }
        if ( ! empty( $batch['failed'] ) ) return false;
        return Qwoo_Site_Content::finish_batch( $batch, 'Faster pictures (WebP)' );
    }

    private static function walk_design( &$batch, $node, array &$kept, array &$labels, bool &$changed, int $depth = 0 ) {
        if ( ! is_array( $node ) || $depth > 40 ) return $node;
        // A picture's payload (url + width); its srcset entries (url + w) are handled with it.
        if ( isset( $node['url'] ) && is_string( $node['url'] ) && array_key_exists( 'width', $node ) && ! isset( $node['webp'] )
            && preg_match( '#^/sections/(\d+)-[^/]+\.(png|jpe?g)$#i', $node['url'], $m ) ) {
            $id = (int) $m[1];
            if ( in_array( (string) get_post_mime_type( $id ), self::MIMES, true ) ) {
                $before = $node;
                $node   = self::stage_payload( $batch, $id, $node, 'public/sections/', $kept, $labels, 'Faster pictures' );
                if ( $node !== $before ) $changed = true;
            }
        }
        foreach ( $node as $k => $v ) {
            if ( is_array( $v ) ) $node[ $k ] = self::walk_design( $batch, $v, $kept, $labels, $changed, $depth + 1 );
        }
        return $node;
    }

    /** branding.json: the header logo's WebP file (logo_webp_path). */
    public static function branding_logo( &$batch, array $data, array &$kept, array &$labels, bool &$changed = false ): array {
        if ( ( $batch['store'] ?? '' ) !== 'local' || ! self::on() ) return $data;
        $path = (string) ( $data['logo_path'] ?? '' );
        if ( isset( $data['logo_webp_path'] ) || ! preg_match( '#^/branding/(\d+)-[^/]+\.(png|jpe?g)$#i', $path, $m ) ) return $data;
        $id   = (int) $m[1];
        $file = get_attached_file( $id );
        if ( ! $file ) return $data;
        $copies = self::copies( $id );
        if ( ! $copies ) {
            self::convert( $id );
            $copies = self::copies( $id );
        }
        $webp = $copies[ basename( $file ) ] ?? '';
        if ( $webp === '' ) return $data;
        $bytes = @file_get_contents( trailingslashit( dirname( $file ) ) . $webp );
        if ( $bytes === false ) return $data;
        $repo = 'public/branding/' . $id . '-' . sanitize_file_name( $webp );
        aps_github_batch_put_file( $batch, $repo, $bytes );
        $kept[ $repo ]         = true;
        $labels[ $repo ]       = 'Branding (logo)';
        $data['logo_webp_path'] = '/branding/' . $id . '-' . sanitize_file_name( $webp );
        $changed = true;
        return $data;
    }
}

Qwoo_Webp::init();

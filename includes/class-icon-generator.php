<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Qwoo_Icon_Generator {

    const STANDARD_SIZES = [ 128, 192, 256, 384, 512 ];
    const FAVICON_SIZE      = 32;
    const FAVICON_ICO_SIZES = [ 16, 32, 48 ]; // sizes bundled into favicon.ico
    const APPLE_TOUCH_SIZE  = 180;
    const MASKABLE_SIZE     = 512;
    const MASKABLE_SAFE_ZONE_RATIO = 0.8;

    public static function generate_from_attachment( $attachment_id ) {
        if ( ! extension_loaded( 'gd' ) ) {
            return new WP_Error( 'no_gd', 'The GD PHP extension is required to generate icons and is not available on this server.' );
        }

        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) {
            return new WP_Error( 'file_missing', 'Source image file not found.' );
        }

        $info = @getimagesize( $path );
        if ( ! $info ) {
            return new WP_Error( 'invalid_image', 'Could not read image dimensions.' );
        }

        list( $src_w, $src_h ) = $info;

        $warnings = [];
        if ( $src_w < self::MASKABLE_SIZE || $src_h < self::MASKABLE_SIZE ) {
            $warnings[] = sprintf(
                'Source image is %dx%d — for best results the App Icon should be at least %dx%d.',
                $src_w, $src_h, self::MASKABLE_SIZE, self::MASKABLE_SIZE
            );
        }
        $ratio = $src_w / max( 1, $src_h );
        if ( $ratio < 0.9 || $ratio > 1.1 ) {
            $warnings[] = 'Source image is not square — it will be center-cropped to a square, which may cut off parts of the logo.';
        }

        $src_im = self::load_image( $path, $info[2] );
        if ( ! $src_im ) {
            return new WP_Error( 'load_failed', 'Could not load image into memory (unsupported format).' );
        }

        $files = [];

        foreach ( self::STANDARD_SIZES as $size ) {
            $im = self::square_crop_resize( $src_im, $src_w, $src_h, $size );
            $files[] = [
                'filename' => "icon-{$size}x{$size}.png",
                'data'     => self::im_to_png_string( $im ),
                'size'     => $size,
                'purpose'  => 'any',
                'location' => 'icons', // goes inside the icon folder
            ];
            imagedestroy( $im );
        }

        $maskable = self::build_maskable( $src_im, $src_w, $src_h, self::MASKABLE_SIZE );
        $files[] = [
            'filename' => 'icon-maskable-' . self::MASKABLE_SIZE . 'x' . self::MASKABLE_SIZE . '.png',
            'data'     => self::im_to_png_string( $maskable ),
            'size'     => self::MASKABLE_SIZE,
            'purpose'  => 'maskable',
            'location' => 'icons',
        ];
        imagedestroy( $maskable );

        $apple = self::square_crop_resize( $src_im, $src_w, $src_h, self::APPLE_TOUCH_SIZE, true );
        $files[] = [
            'filename' => 'apple-touch-icon.png',
            'data'     => self::im_to_png_string( $apple ),
            'size'     => self::APPLE_TOUCH_SIZE,
            'purpose'  => 'apple-touch-icon',
            'location' => 'icons',
        ];
        imagedestroy( $apple );

        $favicon = self::square_crop_resize( $src_im, $src_w, $src_h, self::FAVICON_SIZE );
        $files[] = [
            'filename' => 'favicon-32x32.png',
            'data'     => self::im_to_png_string( $favicon ),
            'size'     => self::FAVICON_SIZE,
            'purpose'  => 'favicon',
            'location' => 'icons',
        ];
        imagedestroy( $favicon );

        // Multi-resolution favicon.ico, placed at the public/ root (not public/icons/),
        // since browsers and Windows request /favicon.ico from the site root by default.
        $favicon_ico = self::generate_favicon_ico( $src_im, $src_w, $src_h );
        $files[] = [
            'filename' => 'favicon.ico',
            'data'     => $favicon_ico,
            'size'     => max( self::FAVICON_ICO_SIZES ),
            'purpose'  => 'favicon-ico',
            'location' => 'root', // goes directly in the public root, not the icon folder
        ];

        imagedestroy( $src_im );

        return [ 'files' => $files, 'warnings' => $warnings ];
    }

    private static function load_image( $path, $type ) {
        switch ( $type ) {
            case IMAGETYPE_JPEG: return @imagecreatefromjpeg( $path );
            case IMAGETYPE_PNG:  return @imagecreatefrompng( $path );
            case IMAGETYPE_WEBP: return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
            case IMAGETYPE_GIF:  return @imagecreatefromgif( $path );
            default: return false;
        }
    }

    private static function square_crop_resize( $src_im, $src_w, $src_h, $size, $flatten_white = false ) {
        $crop_dim = min( $src_w, $src_h );
        $crop_x   = intval( ( $src_w - $crop_dim ) / 2 );
        $crop_y   = intval( ( $src_h - $crop_dim ) / 2 );

        $out = imagecreatetruecolor( $size, $size );

        if ( $flatten_white ) {
            $white = imagecolorallocate( $out, 255, 255, 255 );
            imagefill( $out, 0, 0, $white );
        } else {
            imagesavealpha( $out, true );
            $transparent = imagecolorallocatealpha( $out, 0, 0, 0, 127 );
            imagefill( $out, 0, 0, $transparent );
        }

        imagecopyresampled( $out, $src_im, 0, 0, $crop_x, $crop_y, $size, $size, $crop_dim, $crop_dim );
        return $out;
    }

    private static function build_maskable( $src_im, $src_w, $src_h, $canvas_size ) {
        $out = imagecreatetruecolor( $canvas_size, $canvas_size );

        $bg = imagecolorallocate( $out, 255, 255, 255 );
        imagefill( $out, 0, 0, $bg );

        $inner = intval( $canvas_size * self::MASKABLE_SAFE_ZONE_RATIO );
        $offset = intval( ( $canvas_size - $inner ) / 2 );

        $crop_dim = min( $src_w, $src_h );
        $crop_x   = intval( ( $src_w - $crop_dim ) / 2 );
        $crop_y   = intval( ( $src_h - $crop_dim ) / 2 );

        imagecopyresampled( $out, $src_im, $offset, $offset, $crop_x, $crop_y, $inner, $inner, $crop_dim, $crop_dim );
        return $out;
    }

    private static function im_to_png_string( $im ) {
        ob_start();
        imagepng( $im );
        return ob_get_clean();
    }

    /**
     * Build a multi-resolution .ico from square source image.
     * Uses the modern ICO format (Vista+) that embeds raw PNG bytes per
     * entry, so no manual BMP/DIB encoding is required.
     *
     * @return string Raw .ico binary data.
     */
    private static function generate_favicon_ico( $src_im, $src_w, $src_h ) {
        $pngs = [];

        foreach ( self::FAVICON_ICO_SIZES as $size ) {
            $im = self::square_crop_resize( $src_im, $src_w, $src_h, $size );
            $pngs[ $size ] = self::im_to_png_string( $im );
            imagedestroy( $im );
        }

        return self::build_ico( $pngs );
    }

    /**
     * @param array $png_images Map of size => raw PNG binary string,
     *                          e.g. [16 => $png16, 32 => $png32, 48 => $png48]
     * @return string Raw .ico binary.
     */
    private static function build_ico( array $png_images ) {
        ksort( $png_images );
        $count = count( $png_images );

        // ICONDIR header: reserved(2) + type(2, 1=icon) + count(2)
        $header = pack( 'vvv', 0, 1, $count );

        $dir_entries = '';
        $image_data  = '';
        $offset      = 6 + ( 16 * $count ); // header + one 16-byte dir entry per image

        foreach ( $png_images as $size => $png_data ) {
            $w   = $size >= 256 ? 0 : $size; // 0 means 256 per the ICO spec
            $h   = $w;
            $len = strlen( $png_data );

            // ICONDIRENTRY: width(1) height(1) colors(1) reserved(1) planes(2) bitcount(2) bytesInRes(4) imageOffset(4)
            $dir_entries .= pack( 'CCCCvvVV', $w, $h, 0, 0, 1, 32, $len, $offset );
            $image_data  .= $png_data;
            $offset      += $len;
        }

        return $header . $dir_entries . $image_data;
    }

    /**
     * @param array  $files         Files array from generate_from_attachment(); none
     *                              removes the icons and favicon.ico.
     * @param string $icon_folder   Repo path for files with 'location' => 'icons'.
     * @param string $public_root   Repo path for files with 'location' => 'root'
     *                              (e.g. favicon.ico, which must sit outside icon_folder).
     * @param string $manifest_path Repo path for the generated manifest.json.
     */
    public static function sync_to_github( $files, $icon_folder = 'public/icons', $manifest_path = 'public/config/icons.json', $public_root = 'public' ) {
        $gh = Qwoo_Platform_Connection::github_settings();
        if ( ! $gh ) {
            return false;
        }
        $owner  = $gh['owner'];
        $repo   = $gh['repo'];
        $token  = $gh['token'];
        $branch = $gh['branch'];

        $icon_folder = trim( $icon_folder, '/' );
        $public_root = trim( $public_root, '/' );

        $github_api = function ( $method, $endpoint, $body = null ) use ( $owner, $repo, $token ) {
            $args = [
                'method'  => $method,
                'headers' => [
                    'Authorization' => "token {$token}",
                    'User-Agent'    => 'WordPress-GitHub-Client',
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/vnd.github+json',
                ],
                'timeout' => 30,
            ];
            if ( $body !== null ) {
                $args['body'] = json_encode( $body );
            }

            $url      = "https://api.github.com/repos/{$owner}/{$repo}{$endpoint}";
            $response = wp_remote_request( $url, $args );

            if ( is_wp_error( $response ) ) {
                error_log( 'Qwoo Icon Sync error: ' . $response->get_error_message() );
                return false;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $raw  = wp_remote_retrieve_body( $response );
            $decoded = json_decode( $raw, true );

            if ( $code < 200 || $code >= 300 ) {
                error_log( "Qwoo Icon Sync failed [{$method} {$endpoint}]: {$code} — {$raw}" );
                return false;
            }

            return $decoded;
        };

        $ref = $github_api( 'GET', "/git/ref/heads/{$branch}" );
        if ( ! $ref ) return false;
        $base_commit_sha = $ref['object']['sha'];

        $commit = $github_api( 'GET', "/git/commits/{$base_commit_sha}" );
        if ( ! $commit ) return false;
        $base_tree_sha = $commit['tree']['sha'];

        $tree = $github_api( 'GET', "/git/trees/{$base_tree_sha}?recursive=1" );
        if ( ! $tree || empty( $tree['tree'] ) ) return false;

        // Build the exact set of repo paths our current file list will occupy,
        // so we only treat old icon-folder files as "stale" and never touch
        // unrelated root-level files that happen to live in public/.
        $expected_paths = [];
        foreach ( $files as $file ) {
            $expected_paths[] = ( $file['location'] ?? 'icons' ) === 'root'
                ? "{$public_root}/{$file['filename']}"
                : "{$icon_folder}/{$file['filename']}";
        }

        $stale_files           = [];
        $existing_by_path      = [];
        $existing_manifest_sha = null;

        foreach ( $tree['tree'] as $entry ) {
            if ( $entry['type'] !== 'blob' ) continue;

            if ( $entry['path'] === $manifest_path ) {
                $existing_manifest_sha = $entry['sha'];
                continue;
            }

            // Only consider a path "managed" (and therefore prunable) if it's
            // either inside the icon folder, or it's one of our known root-level
            // filenames directly under $public_root (e.g. favicon.ico).
            $in_icon_folder = strpos( $entry['path'], $icon_folder . '/' ) === 0;
            $is_managed_root_file = ( in_array( $entry['path'], $expected_paths, true ) || $entry['path'] === "{$public_root}/favicon.ico" )
                && strpos( $entry['path'], $public_root . '/' ) === 0
                && strpos( $entry['path'], $icon_folder . '/' ) !== 0;

            if ( ! $in_icon_folder && ! $is_managed_root_file ) continue;

            $existing_by_path[ $entry['path'] ] = $entry['sha'];
            $stale_files[] = $entry['path'];
        }

        $tree_updates   = [];
        $manifest_icons = [];

        foreach ( $files as $file ) {
            $location = $file['location'] ?? 'icons';
            $path     = $location === 'root'
                ? "{$public_root}/{$file['filename']}"
                : "{$icon_folder}/{$file['filename']}";

            $local_sha = sha1( "blob " . strlen( $file['data'] ) . "\0" . $file['data'] );

            if ( isset( $existing_by_path[ $path ] ) && $existing_by_path[ $path ] === $local_sha ) {
                $stale_files = array_diff( $stale_files, [ $path ] );
            } else {
                $blob = $github_api( 'POST', '/git/blobs', [
                    'content'  => base64_encode( $file['data'] ),
                    'encoding' => 'base64',
                ] );
                if ( ! $blob ) return false;

                $tree_updates[] = [ 'path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha'] ];
                $stale_files = array_diff( $stale_files, [ $path ] );
            }

            // favicon.ico isn't part of the web manifest icon list (it's a
            // legacy/root asset), so skip it there.
            if ( $location === 'root' ) continue;

            $manifest_icons[] = [
                'src'     => "/icons/{$file['filename']}",
                'sizes'   => "{$file['size']}x{$file['size']}",
                'type'    => 'image/png',
                'purpose' => $file['purpose'],
            ];
        }

        foreach ( array_values( $stale_files ) as $old_path ) {
            $tree_updates[] = [ 'path' => $old_path, 'mode' => '100644', 'type' => 'blob', 'sha' => null ];
        }

        $manifest_json = json_encode( [ 'icons' => $manifest_icons ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        $local_manifest_sha = sha1( "blob " . strlen( $manifest_json ) . "\0" . $manifest_json );

        if ( $existing_manifest_sha !== $local_manifest_sha ) {
            $blob = $github_api( 'POST', '/git/blobs', [
                'content'  => base64_encode( $manifest_json ),
                'encoding' => 'base64',
            ] );
            if ( ! $blob ) return false;

            $tree_updates[] = [ 'path' => $manifest_path, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha'] ];
        }

        if ( empty( $tree_updates ) ) {
            return 'no_changes';
        }

        $new_tree = $github_api( 'POST', '/git/trees', [
            'base_tree' => $base_tree_sha,
            'tree'      => $tree_updates,
        ] );
        if ( ! $new_tree ) return false;

        $new_commit = $github_api( 'POST', '/git/commits', [
            'message' => 'Update PWA icon set',
            'tree'    => $new_tree['sha'],
            'parents' => [ $base_commit_sha ],
        ] );
        if ( ! $new_commit ) return false;

        $updated_ref = $github_api( 'PATCH', "/git/refs/heads/{$branch}", [
            'sha'   => $new_commit['sha'],
            'force' => false,
        ] );
        if ( ! $updated_ref ) return false;
        return true;
    }
}
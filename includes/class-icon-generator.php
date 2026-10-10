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
        // One batch with the rest of the publishing code: GitHub, or the
        // store's own published files (Qwoo_Site_Content).
        $batch = aps_github_start_batch();
        if ( ! $batch ) {
            return false;
        }

        $icon_folder = trim( $icon_folder, '/' );
        $public_root = trim( $public_root, '/' );

        // The paths our current file list will occupy: only old icon-folder
        // files (and our own root files, like favicon.ico) are "stale";
        // unrelated root-level files in public/ are never touched.
        $expected_paths = [];
        foreach ( $files as $file ) {
            $expected_paths[] = ( $file['location'] ?? 'icons' ) === 'root'
                ? "{$public_root}/{$file['filename']}"
                : "{$icon_folder}/{$file['filename']}";
        }

        $stale_files = [];
        foreach ( array_keys( $batch['existing'] ) as $path ) {
            if ( $path === $manifest_path ) continue;
            $in_icon_folder       = strpos( $path, $icon_folder . '/' ) === 0;
            $is_managed_root_file = ( in_array( $path, $expected_paths, true ) || $path === "{$public_root}/favicon.ico" )
                && strpos( $path, $public_root . '/' ) === 0
                && ! $in_icon_folder;
            if ( $in_icon_folder || $is_managed_root_file ) {
                $stale_files[ $path ] = true;
            }
        }

        $manifest_icons = [];
        foreach ( $files as $file ) {
            $location = $file['location'] ?? 'icons';
            $path     = $location === 'root'
                ? "{$public_root}/{$file['filename']}"
                : "{$icon_folder}/{$file['filename']}";

            if ( ! aps_github_batch_put_file( $batch, $path, $file['data'] ) ) {
                return false;
            }
            unset( $stale_files[ $path ] );

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

        foreach ( array_keys( $stale_files ) as $old_path ) {
            $batch['tree_updates'][] = [ 'path' => $old_path, 'mode' => '100644', 'type' => 'blob', 'sha' => null ];
            $batch['deleted'][]      = $old_path;
        }

        $manifest_json = json_encode( [ 'icons' => $manifest_icons ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! aps_github_batch_put_file( $batch, $manifest_path, $manifest_json ) ) {
            return false;
        }

        return aps_github_finish_batch( $batch, 'Update PWA icon set' );
    }
}
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SVG images and product videos.
 *
 * SVGs are uploaded only through the dashboard (cleaned by
 * Qwoo_Svg_Sanitizer); WordPress has no sizes for them, so every size is
 * the file itself, at the size its width/height or viewBox gives.
 *
 * Product videos: WooCommerce galleries hold images only, so a product with
 * videos also keeps its whole gallery in order in meta _qwoo_gallery
 * (attachment IDs, photos and videos). WooCommerce keeps the photos; the
 * Store API gets the videos and where they go (extensions.qwoo.videos).
 */
class Qwoo_Media_Support {

    const GALLERY_META = '_qwoo_gallery';

    public static function init() {
        add_filter( 'wp_get_attachment_image_src', [ __CLASS__, 'svg_image_src' ], 10, 4 );
    }

    public static function is_svg( $id ) {
        return get_post_mime_type( $id ) === 'image/svg+xml';
    }

    /** An image the store can show: a photo WordPress knows, or an SVG. */
    public static function is_image( $id ) {
        return $id && ( wp_attachment_is_image( $id ) || self::is_svg( $id ) );
    }

    public static function is_video( $id ) {
        return $id && in_array( get_post_mime_type( $id ), [ 'video/mp4', 'video/webm' ], true );
    }

    /**
     * "image", "svg", "video", "audio" or "file". MOV videos count as video
     * here (the Media screen) but not in is_video(): browsers other than
     * Safari often can't play them, so pickers and galleries leave them out.
     */
    public static function kind( $id ) {
        if ( self::is_svg( $id ) ) return 'svg';
        $mime = (string) get_post_mime_type( $id );
        if ( self::is_video( $id ) || $mime === 'video/quicktime' ) return 'video';
        if ( strpos( $mime, 'audio/' ) === 0 ) return 'audio';
        if ( strpos( $mime, 'image/' ) === 0 ) return 'image';
        return 'file';
    }

    /**
     * Files the dashboard's file upload (file_upload) takes, by ending:
     * [ saved mime, kind, what finfo may call it, container ]. Container
     * "zip" / "ole": the file must also start like one (office files are
     * zips or OLE files inside, and finfo often says just that). Never:
     * HTML, SVG (it has its own cleaned image upload), scripts, programs,
     * JSON. The platform checks the same list first (QP_Portal_API).
     */
    const FILE_TYPES = [
        'pdf'  => [ 'application/pdf', 'file', [ 'application/pdf' ], '' ],
        'zip'  => [ 'application/zip', 'file', [ 'application/zip', 'application/x-zip-compressed' ], 'zip' ],
        'doc'  => [ 'application/msword', 'file', [ 'application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage' ], 'ole' ],
        'xls'  => [ 'application/vnd.ms-excel', 'file', [ 'application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage' ], 'ole' ],
        'ppt'  => [ 'application/vnd.ms-powerpoint', 'file', [ 'application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage' ], 'ole' ],
        'docx' => [ 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'file', [ 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream' ], 'zip' ],
        'xlsx' => [ 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'file', [ 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream' ], 'zip' ],
        'pptx' => [ 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'file', [ 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream' ], 'zip' ],
        'odt'  => [ 'application/vnd.oasis.opendocument.text', 'file', [ 'application/vnd.oasis.opendocument.text', 'application/zip', 'application/octet-stream' ], 'zip' ],
        'ods'  => [ 'application/vnd.oasis.opendocument.spreadsheet', 'file', [ 'application/vnd.oasis.opendocument.spreadsheet', 'application/zip', 'application/octet-stream' ], 'zip' ],
        'odp'  => [ 'application/vnd.oasis.opendocument.presentation', 'file', [ 'application/vnd.oasis.opendocument.presentation', 'application/zip', 'application/octet-stream' ], 'zip' ],
        'csv'  => [ 'text/csv', 'file', [ 'text/csv', 'text/plain', 'application/csv' ], '' ],
        'txt'  => [ 'text/plain', 'file', [ 'text/plain' ], '' ],
        'rtf'  => [ 'application/rtf', 'file', [ 'text/rtf', 'application/rtf' ], '' ],
        'mp3'  => [ 'audio/mpeg', 'audio', [ 'audio/mpeg', 'audio/mp3', 'audio/x-mpeg' ], '' ],
        'm4a'  => [ 'audio/mp4', 'audio', [ 'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'video/mp4' ], '' ],
        'wav'  => [ 'audio/wav', 'audio', [ 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave' ], '' ],
        'ogg'  => [ 'audio/ogg', 'audio', [ 'audio/ogg', 'application/ogg', 'audio/x-ogg' ], '' ],
        'gif'  => [ 'image/gif', 'image', [ 'image/gif' ], '' ],
    ];

    /** Documents, archives and text files (what the Media screen's Files filter lists). */
    public static function file_mimes() {
        $out = [];
        foreach ( self::FILE_TYPES as $t ) {
            if ( $t[1] === 'file' ) $out[ $t[0] ] = true;
        }
        return array_keys( $out );
    }

    /**
     * [ ext, saved mime, kind ] for a file_upload, from its name and its
     * real content, or an owner-facing error string.
     */
    public static function check_file( $name, $bytes ) {
        $ext = strtolower( (string) pathinfo( (string) $name, PATHINFO_EXTENSION ) );
        if ( ! isset( self::FILE_TYPES[ $ext ] ) ) {
            return 'This kind of file can\'t be uploaded. Use a PDF, ZIP, Word, Excel, PowerPoint, OpenDocument, CSV, TXT, RTF, MP3, M4A, WAV, OGG or GIF file.';
        }
        if ( ! class_exists( 'finfo' ) ) {
            return 'File uploads are not available on this server.';
        }
        [ $mime, $kind, $real, $box ] = self::FILE_TYPES[ $ext ];
        $found = (string) ( new finfo( FILEINFO_MIME_TYPE ) )->buffer( $bytes );
        $head  = substr( (string) $bytes, 0, 8 );
        $ok    = in_array( $found, $real, true )
            && ( $box !== 'zip' || strpos( $head, "PK\x03\x04" ) === 0 || strpos( $head, "PK\x05\x06" ) === 0 )
            && ( $box !== 'ole' || strpos( $head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" ) === 0 );
        if ( ! $ok ) {
            return 'This file\'s content doesn\'t match its type. Save it again from the app that made it and try again.';
        }
        return [ $ext, $mime, $kind ];
    }

    /** WordPress has no sizes for an SVG: the file itself, every time. */
    public static function svg_image_src( $image, $id, $size, $icon ) {
        if ( $image || ! self::is_svg( $id ) ) {
            return $image;
        }
        $url = wp_get_attachment_url( $id );
        if ( ! $url ) {
            return $image;
        }
        $meta = wp_get_attachment_metadata( $id );
        return [ $url, (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ), false ];
    }

    /** [ width, height ] from an SVG's width/height (in px or plain numbers), else its viewBox. */
    public static function svg_size( $svg ) {
        $attr = static function ( $name ) use ( $svg ) {
            return preg_match( '/<svg\b[^>]*\s' . $name . '\s*=\s*["\']\s*([0-9.]+)\s*(px)?\s*["\']/i', $svg, $m ) ? (float) $m[1] : 0;
        };
        $w = $attr( 'width' );
        $h = $attr( 'height' );
        if ( ( ! $w || ! $h ) && preg_match( '/<svg\b[^>]*\sviewbox\s*=\s*["\']\s*[-0-9.]+[\s,]+[-0-9.]+[\s,]+([0-9.]+)[\s,]+([0-9.]+)\s*["\']/i', $svg, $m ) ) {
            $w = (float) $m[1];
            $h = (float) $m[2];
        }
        return [ (int) round( $w ), (int) round( $h ) ];
    }

    /**
     * A product's videos for the storefront: [{ id, position, src, type,
     * width, height }], position = its place in the gallery (0 = first).
     */
    public static function product_videos( WC_Product $product ) {
        $order = array_filter( array_map( 'absint', explode( ',', (string) $product->get_meta( self::GALLERY_META ) ) ) );
        if ( ! $order ) {
            return [];
        }
        $photos = array_filter( array_merge( [ (int) $product->get_image_id() ], array_map( 'intval', $product->get_gallery_image_ids() ) ) );
        $out    = [];
        $place  = 0;
        foreach ( $order as $id ) {
            if ( self::is_video( $id ) ) {
                $url = wp_get_attachment_url( $id );
                if ( ! $url ) continue;
                $meta  = wp_get_attachment_metadata( $id );
                $out[] = [
                    'id'       => (int) $id,
                    'position' => $place,
                    'src'      => $url,
                    'type'     => (string) get_post_mime_type( $id ),
                    'width'    => (int) ( $meta['width'] ?? 0 ),
                    'height'   => (int) ( $meta['height'] ?? 0 ),
                ];
                $place++;
            } elseif ( in_array( $id, $photos, true ) ) {
                $place++;
            }
        }
        return $out;
    }
}

Qwoo_Media_Support::init();

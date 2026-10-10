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

    /** "image", "svg" or "video". */
    public static function kind( $id ) {
        return self::is_svg( $id ) ? 'svg' : ( self::is_video( $id ) ? 'video' : 'image' );
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

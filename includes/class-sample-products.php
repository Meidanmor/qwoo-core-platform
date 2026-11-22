<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sample products: the examples a store designed by the AI from one prompt starts with
 * (product meta `_qp_sample` = 'yes'). They show on the shop pages like any product, with a
 * "Sample" badge in the storefront (Store API `extensions.qwoo.sample`), but:
 * - they can't be bought (not purchasable; adding one to a cart is refused with a clear message);
 * - they stay out of the product feed, the sitemap (and search engines), order emails' product
 *   lists and WooCommerce's related products;
 * - the dashboard can delete them all at once (Qwoo_Platform_Dashboard samples_delete), with
 *   their pictures when nothing else uses them.
 */
class Qwoo_Sample_Products {

    const META = '_qp_sample';

    public static function init() {
        add_filter( 'woocommerce_is_purchasable', [ __CLASS__, 'purchasable' ], 30, 2 );
        add_filter( 'woocommerce_variation_is_purchasable', [ __CLASS__, 'purchasable' ], 30, 2 );
        // Runs before the Store API (and the classic cart) checks "purchasable", so the shopper
        // reads why instead of "not available for purchase".
        add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'refuse_add' ], 1, 3 );
        add_filter( 'woocommerce_related_products', [ __CLASS__, 'drop_ids' ], 20 );
    }

    /** A product (or a variation of one) marked as a sample. */
    public static function is_sample( $product ): bool {
        if ( is_numeric( $product ) ) {
            $id = (int) $product;
        } elseif ( $product instanceof WC_Product ) {
            $id = $product->get_parent_id() ?: $product->get_id();
        } else {
            return false;
        }
        if ( $id && get_post_type( $id ) === 'product_variation' ) {
            $id = (int) wp_get_post_parent_id( $id );
        }
        return $id > 0 && get_post_meta( $id, self::META, true ) === 'yes';
    }

    /** Marks a product as a sample, or takes the mark off. */
    public static function set( int $product_id, bool $on ): void {
        if ( $on ) {
            update_post_meta( $product_id, self::META, 'yes' );
        } else {
            delete_post_meta( $product_id, self::META );
        }
    }

    public static function purchasable( $purchasable, $product ) {
        return self::is_sample( $product ) ? false : $purchasable;
    }

    public static function message(): string {
        $he = class_exists( 'Qwoo_I18n' ) && Qwoo_I18n::default_code() === 'he';
        return $he ? 'זהו מוצר לדוגמה.' : 'This is a sample product.';
    }

    public static function refuse_add( $data, $product_id, $variation_id = 0 ) {
        if ( self::is_sample( (int) $product_id ) || ( $variation_id && self::is_sample( (int) $variation_id ) ) ) {
            if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'qwoo_sample_product', self::message(), 400 );
            }
            throw new Exception( self::message() );
        }
        return $data;
    }

    /** Product ids without the samples. */
    public static function drop_ids( $ids ) {
        $ids = (array) $ids;
        $samples = self::ids();
        return $samples ? array_values( array_diff( array_map( 'intval', $ids ), $samples ) ) : $ids;
    }

    /** Products (WC_Product list) without the samples. */
    public static function drop( array $products ): array {
        return array_values( array_filter( $products, static fn( $p ) => $p && ! self::is_sample( $p ) ) );
    }

    /** Ids of every sample product, whatever its status (trash too). */
    public static function ids(): array {
        global $wpdb;
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type = 'product' AND m.meta_key = %s AND m.meta_value = 'yes'",
            self::META
        ) ) );
    }

    /**
     * Deletes every sample product for good (variations included), then each of their
     * pictures that nothing else uses any more. $media_uses: callable( id ) → list of uses.
     * Returns { deleted, pictures }.
     */
    public static function delete_all( callable $media_uses ): array {
        $deleted  = 0;
        $pictures = [];
        foreach ( self::ids() as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) continue;
            $pictures = array_merge( $pictures, [ (int) $product->get_image_id() ], array_map( 'intval', $product->get_gallery_image_ids() ) );
            if ( $product->is_type( 'variable' ) ) {
                foreach ( $product->get_children() as $child_id ) {
                    $child = wc_get_product( $child_id );
                    if ( ! $child ) continue;
                    $pictures[] = (int) $child->get_image_id();
                    $child->delete( true );
                }
            }
            if ( $product->delete( true ) ) {
                $deleted++;
                wc_delete_product_transients( $id );
            }
        }
        $removed = 0;
        foreach ( array_unique( array_filter( $pictures ) ) as $pic ) {
            if ( get_post_type( $pic ) !== 'attachment' ) continue;
            if ( $media_uses( $pic ) ) continue;
            if ( wp_delete_attachment( $pic, true ) ) $removed++;
        }
        return [ 'deleted' => $deleted, 'pictures' => $removed ];
    }
}

Qwoo_Sample_Products::init();

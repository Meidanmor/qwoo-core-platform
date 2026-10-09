<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The shared frontend-URL builder used by the admin screen (enqueue +
 * settings_page_html) so the two can't drift apart.
 *
 * (The public /shop-builder/v1/preview/{page} REST route that used to live
 * here was removed: it exposed saved-but-unpublished content to anyone.
 * The Live Preview panel gets unsaved drafts over postMessage instead.)
 */
trait SB_Rest {

    /**
     * Builds the per-tab frontend preview URLs from a normalized frontend
     * domain (no trailing slash). Shared by enqueue_assets() (localized for
     * JS, used when switching tabs) and settings_page_html() (used for the
     * initial server-rendered state) so the two can't drift out of sync.
     * Most tabs preview against the storefront root since they affect
     * global chrome (header/footer) or the homepage itself; Checkout and
     * Shop Archive have their own frontend routes.
     */
    private static function build_preview_urls( $frontend_domain ) {
        $root     = $frontend_domain ? $frontend_domain . '/' : '';
        $checkout = $frontend_domain ? $frontend_domain . '/checkout' : '';
        $shop     = $frontend_domain ? $frontend_domain . '/products' : '';

        $category_slug = self::first_product_category_slug();
        $category      = ( $frontend_domain && $category_slug )
                ? $frontend_domain . '/product-category/' . $category_slug . ''
                : $root;

        $product_slug = self::first_product_slug();
        $product      = ( $frontend_domain && $product_slug )
                ? $frontend_domain . '/product/' . $product_slug . ''
                : $root;

        return [
                'tab-header'   => $root,
                'tab-footer'   => $root,
                'tab-home'     => $root,
                'tab-shop'     => $shop,
                'tab-category' => $category,
                'tab-product'  => $product,
                'tab-checkout' => $checkout,
                'tab-cart'     => $frontend_domain ? $frontend_domain . '/cart' : '',
                'tab-blog'     => $frontend_domain ? $frontend_domain . '/blog' : '',
                'tab-blog_post' => $frontend_domain ? $frontend_domain . '/blog' . self::first_post_path() : '',
                'tab-branding' => $root,
                'tab-pwa'      => $root,
                'tab-contact'  => $root,
        ];
    }

    // Picks any one real category to preview the Category Archive tab
    // against — it doesn't matter WHICH one (per product requirements),
    // just that it's a real, existing slug so the frontend route resolves
    // instead of 404ing. Prefers a non-empty category so there's actually
    // product content to look at; falls back to any category (even empty)
    // if that's all that exists yet.
    private static function first_product_category_slug() {
        $terms = get_terms( [
                'taxonomy'   => 'product_cat',
                'number'     => 1,
                'hide_empty' => true,
                'fields'     => 'slugs',
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            $terms = get_terms( [
                    'taxonomy'   => 'product_cat',
                    'number'     => 1,
                    'hide_empty' => false,
                    'fields'     => 'slugs',
            ] );
        }

        return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : '';
    }

    // Same idea for the Product Page tab: any one published product.
    private static function first_product_slug() {
        if ( ! function_exists( 'wc_get_products' ) ) return '';

        $products = wc_get_products( [
                'limit'   => 1,
                'status'  => 'publish',
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'objects',
        ] );

        return ! empty( $products[0] ) ? $products[0]->get_slug() : '';
    }

    /** "/<slug>" of the newest published blog post, or '' (the blog itself then). */
    private static function first_post_path() {
        $posts = get_posts( [ 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ] );
        return $posts ? '/' . get_post_field( 'post_name', $posts[0] ) : '';
    }

    /** Page slugs that are published as public/config/{slug}.json. */
    private static function publishable_pages() {
        return array_merge(
                [ 'header', 'footer', 'home', 'checkout', 'branding', 'pwa' ],
                array_keys( self::PAGE_SECTION_LOCATIONS ),
                self::TEMPLATE_PAGES
        );
    }
}

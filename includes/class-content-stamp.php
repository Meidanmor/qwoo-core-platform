<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A stamp that changes whenever something shoppers can see changes: products (prices, stock,
 * photos…), categories and other terms, posts and pages, reviews, settings. The storefront's
 * server keeps the store's public answers (products, categories, SEO, blog, reviews) and uses
 * them again until the stamp changes, so most page views don't ask WordPress anything.
 *
 * The stamp goes out with the live version (Qwoo_Site_Content::pointer_body()), also in a small
 * public file the storefront reads every few seconds without starting WordPress
 * (uploads/qwoo-site/current.json, written at the end of a request that changed something).
 *
 * When in doubt it changes: a missed change would show shoppers old content, an extra change
 * only costs one round of fresh answers.
 */
class Qwoo_Content_Stamp {

    const OPTION = 'qwoo_content_stamp'; // { stamp, time, why }

    /** Options that change on their own all the time, or say nothing shoppers see. */
    const QUIET_OPTIONS = [ 'cron', 'doing_cron', 'rewrite_rules', self::OPTION, 'qwoo_site_lock', 'recently_activated', 'active_plugins_count' ];
    const QUIET_PREFIXES = [ '_transient_', '_site_transient_', 'action_scheduler', 'as_', 'woocommerce_admin_', 'wc_admin_', 'wcpay_', 'jetpack_', 'litespeed', 'hostinger', 'auto_updater', 'wp_' ];

    /** Post meta that changes while someone edits in wp-admin, not the post itself. */
    const QUIET_META = [ '_edit_lock', '_edit_last', '_wp_old_date', '_encloseme', '_pingme' ];

    /** Why this request changed it (null: nothing changed). */
    private static $why = null;

    public static function init() {
        // Posts of every kind (products, variations, blog posts, pages, orders…).
        foreach ( [ 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post' ] as $hook ) {
            add_action( $hook, [ __CLASS__, 'on_post' ], 10, 1 );
        }
        add_action( 'transition_post_status', static function ( $new, $old ) {
            if ( $new !== $old ) self::touch( 'status' );
        }, 10, 2 );
        foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
            add_action( $hook, [ __CLASS__, 'on_post_meta' ], 10, 3 );
        }
        // Categories, tags, product attributes, blog categories and authors.
        foreach ( [ 'created_term', 'edited_term', 'delete_term', 'set_object_terms', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ] as $hook ) {
            add_action( $hook, static function () use ( $hook ) { self::touch( $hook ); } );
        }
        // Reviews.
        foreach ( [ 'wp_insert_comment', 'edit_comment', 'wp_set_comment_status', 'delete_comment' ] as $hook ) {
            add_action( $hook, static function () use ( $hook ) { self::touch( $hook ); } );
        }
        // Stock and sales counts (WooCommerce writes them with its own SQL), and its own catch-all.
        foreach ( [ 'woocommerce_updated_product_stock', 'woocommerce_updated_product_sales', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_delete_product_transients' ] as $hook ) {
            add_action( $hook, static function () use ( $hook ) { self::touch( $hook ); } );
        }
        // Settings (store name, currency, SEO, publishing a new version, hiding the store…).
        add_action( 'updated_option', [ __CLASS__, 'on_option' ], 10, 1 );
        add_action( 'added_option', [ __CLASS__, 'on_option' ], 10, 1 );
        add_action( 'deleted_option', [ __CLASS__, 'on_option' ], 10, 1 );

        add_action( 'shutdown', [ __CLASS__, 'save' ], 5 );
    }

    public static function on_post( $post_id ) {
        if ( wp_is_post_revision( $post_id ) || get_post_type( $post_id ) === 'customize_changeset' ) return;
        self::touch( 'post' );
    }

    public static function on_post_meta( $meta_id, $object_id, $key ) {
        if ( in_array( (string) $key, self::QUIET_META, true ) ) return;
        self::touch( 'post meta' );
    }

    public static function on_option( $name ) {
        $name = (string) $name;
        if ( in_array( $name, self::QUIET_OPTIONS, true ) ) return;
        foreach ( self::QUIET_PREFIXES as $prefix ) {
            if ( strpos( $name, $prefix ) === 0 ) return;
        }
        self::touch( 'option ' . $name );
    }

    public static function touch( $why = '' ) {
        if ( self::$why === null ) self::$why = substr( (string) $why, 0, 80 );
    }

    /** The current stamp (made the first time it's asked for). */
    public static function get(): string {
        $v = get_option( self::OPTION );
        if ( is_array( $v ) && ! empty( $v['stamp'] ) ) return (string) $v['stamp'];
        $v = [ 'stamp' => bin2hex( random_bytes( 6 ) ), 'time' => time(), 'why' => 'first' ];
        update_option( self::OPTION, $v, true );
        return $v['stamp'];
    }

    /**
     * Whether the store's product answers are the same for every shopper. Not when taxes
     * follow the shopper's address: prices then depend on who's asking.
     */
    public static function shared(): bool {
        return ! ( function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() && get_option( 'woocommerce_tax_based_on' ) !== 'base' );
    }

    /** End of a request that changed something: a new stamp, and the public file again. */
    public static function save() {
        if ( self::$why === null ) return;
        $why       = self::$why;
        self::$why = null; // our own writes below don't count
        update_option( self::OPTION, [ 'stamp' => bin2hex( random_bytes( 6 ) ), 'time' => time(), 'why' => $why ], true );
        if ( class_exists( 'Qwoo_Site_Content' ) ) Qwoo_Site_Content::write_pointer();
    }
}

Qwoo_Content_Stamp::init();

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Translations of store data into the extra languages (premium addon):
 * one product, category or post, with its texts per language in meta
 * "_qwoo_i18n" ({ lang: { field: text } }). Price, stock and SKU stay one.
 *
 * When the storefront asks in an extra language (header X-Qwoo-Lang,
 * Qwoo_Store_Language::request_lang()), WooCommerce's getters and terms give
 * the translated texts, so the Store API, the cart, SEO and emails sent in
 * that request follow without changes. A missing translation shows the main
 * language's text.
 */
class Qwoo_Translations {

    const META = '_qwoo_i18n';

    /** Fields per kind, with their sanitizer (text / html). */
    const FIELDS = [
        'product'  => [ 'name' => 'text', 'short_description' => 'html', 'description' => 'html', 'seo_title' => 'text', 'seo_description' => 'text' ],
        'term'     => [ 'name' => 'text', 'description' => 'html', 'seo_title' => 'text', 'seo_description' => 'text' ],
        'post'     => [ 'title' => 'text', 'content' => 'html', 'excerpt' => 'text', 'seo_title' => 'text', 'seo_description' => 'text' ],
    ];

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'maybe_hook' ], 5 );
    }

    /** Hooks the getters only for a storefront request in an extra language. */
    public static function maybe_hook() {
        $uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        if ( strpos( $uri, '/qwoo/v1/platform' ) !== false || Qwoo_Store_Language::request_lang() === '' ) {
            return;
        }
        // WordPress' and WooCommerce's own messages (cart, checkout) in that language too.
        switch_to_locale( Qwoo_Store_Language::LANGS[ Qwoo_Store_Language::request_lang() ] );
        foreach ( [ 'name', 'description', 'short_description' ] as $field ) {
            add_filter( "woocommerce_product_get_{$field}", static fn( $value, $product ) => self::product_text( $product, $field, $value ), 20, 2 );
        }
        add_filter( 'woocommerce_product_variation_get_name', [ __CLASS__, 'variation_name' ], 20, 2 );
        add_filter( 'get_term', [ __CLASS__, 'term' ], 20, 2 );
        add_filter( 'get_terms', static fn( $terms ) => is_array( $terms ) ? array_map( static fn( $t ) => $t instanceof WP_Term ? self::term( $t, $t->taxonomy ) : $t, $terms ) : $terms, 20 );
    }

    /* ---------------- reading ---------------- */

    /** { lang: { field: text } } of a product / post (or term with $is_term). */
    public static function get( $id, $is_term = false ) {
        $raw = $is_term ? get_term_meta( (int) $id, self::META, true ) : get_post_meta( (int) $id, self::META, true );
        return is_array( $raw ) ? $raw : [];
    }

    /** One field in a language ('' when not translated). */
    public static function field( $id, $lang, $field, $is_term = false ) {
        return (string) ( self::get( $id, $is_term )[ $lang ][ $field ] ?? '' );
    }

    private static function product_text( $product, $field, $value ) {
        $lang = Qwoo_Store_Language::request_lang();
        $text = $lang !== '' && $product instanceof WC_Product ? self::field( $product->get_id(), $lang, $field ) : '';
        return $text !== '' ? $text : $value;
    }

    /** "Shirt - S" with the parent's translated name. */
    public static function variation_name( $value, $variation ) {
        $lang   = Qwoo_Store_Language::request_lang();
        $parent = $variation instanceof WC_Product_Variation ? (int) $variation->get_parent_id() : 0;
        $name   = $parent ? self::field( $parent, $lang, 'name' ) : '';
        if ( $name === '' ) {
            return $value;
        }
        $main = (string) get_post_field( 'post_title', $parent );
        return $main !== '' && strpos( (string) $value, $main ) === 0 ? $name . substr( (string) $value, strlen( $main ) ) : $value;
    }

    public static function term( $term, $taxonomy ) {
        if ( ! ( $term instanceof WP_Term ) || ! in_array( $taxonomy, [ 'product_cat', 'product_tag', 'category' ], true ) ) {
            return $term;
        }
        $tr = self::get( $term->term_id, true )[ Qwoo_Store_Language::request_lang() ] ?? [];
        if ( ! empty( $tr['name'] ) ) {
            $term->name = (string) $tr['name'];
        }
        if ( ! empty( $tr['description'] ) ) {
            $term->description = (string) $tr['description'];
        }
        return $term;
    }

    /* ---------------- saving (dashboard) ---------------- */

    /**
     * Cleans { lang: { field: value } } for a kind: only the store's extra
     * languages and the kind's fields. Empty fields are dropped.
     */
    public static function clean( $kind, $input ) {
        $out    = [];
        $fields = self::FIELDS[ $kind ] ?? [];
        foreach ( (array) $input as $lang => $values ) {
            if ( ! in_array( $lang, Qwoo_Store_Language::extra(), true ) || ! is_array( $values ) ) {
                continue;
            }
            foreach ( $fields as $field => $type ) {
                $value = $values[ $field ] ?? '';
                if ( ! is_scalar( $value ) ) {
                    continue;
                }
                $value = $type === 'html'
                    ? trim( wp_kses_post( (string) $value ) )
                    : mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, $field === 'seo_description' ? 320 : 200 );
                if ( $value !== '' ) {
                    $out[ $lang ][ $field ] = $value;
                }
            }
        }
        return $out;
    }

    /** Saves a translation set (null = leave as is). Languages not sent are kept. */
    public static function save( $kind, $id, $input, $is_term = false ) {
        if ( $input === null || ! Qwoo_Store_Language::allowed() ) {
            return;
        }
        $clean = self::clean( $kind, $input );
        $all   = self::get( $id, $is_term );
        foreach ( Qwoo_Store_Language::extra() as $lang ) {
            if ( array_key_exists( $lang, (array) $input ) ) {
                if ( isset( $clean[ $lang ] ) ) {
                    $all[ $lang ] = $clean[ $lang ];
                } else {
                    unset( $all[ $lang ] );
                }
            }
        }
        if ( $is_term ) {
            $all ? update_term_meta( (int) $id, self::META, $all ) : delete_term_meta( (int) $id, self::META );
        } else {
            $all ? update_post_meta( (int) $id, self::META, $all ) : delete_post_meta( (int) $id, self::META );
        }
    }

    /** What the dashboard edits: { lang: { field: value } } for the store's extra languages. */
    public static function for_dashboard( $kind, $id, $is_term = false ) {
        $all = self::get( $id, $is_term );
        $out = [];
        foreach ( Qwoo_Store_Language::extra() as $lang ) {
            foreach ( array_keys( self::FIELDS[ $kind ] ) as $field ) {
                $out[ $lang ][ $field ] = (string) ( $all[ $lang ][ $field ] ?? '' );
            }
        }
        return (object) $out;
    }
}

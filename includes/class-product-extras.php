<?php
/**
 * Extra product details the owner edits in the dashboard:
 *   - questions and answers per product (meta _qwoo_faq: [{ q, a }], plain text),
 *     shown by the Store builder's "Product Questions" block;
 *   - "bought together" products (WooCommerce's cross-sells), shown by the
 *     "Bought Together" block.
 * Both reach the storefront only in single product answers (extensions.qwoo).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Qwoo_Product_Extras {

    const FAQ_META     = '_qwoo_faq';
    const MAX_FAQ      = 20;
    const MAX_TOGETHER = 6;

    /** The stored questions, cleaned again on the way out. */
    public static function faq( $product_id ) {
        return self::clean_faq( get_post_meta( (int) $product_id, self::FAQ_META, true ) );
    }

    /** [{ q, a }]: question ≤ 200 characters, answer ≤ 3000, plain text (line breaks kept); empty pairs dropped. */
    public static function clean_faq( $value ) {
        $out = [];
        foreach ( is_array( $value ) ? $value : [] as $item ) {
            if ( ! is_array( $item ) ) continue;
            $q = trim( sanitize_text_field( (string) ( $item['q'] ?? '' ) ) );
            $a = trim( sanitize_textarea_field( (string) ( $item['a'] ?? '' ) ) );
            if ( $q === '' || $a === '' ) continue;
            $out[] = [ 'q' => mb_substr( $q, 0, 200 ), 'a' => mb_substr( $a, 0, 3000 ) ];
            if ( count( $out ) >= self::MAX_FAQ ) break;
        }
        return $out;
    }

    /** "Bought together": published products only, in the owner's order. */
    public static function together( WC_Product $p ) {
        $ids = [];
        foreach ( array_map( 'intval', $p->get_cross_sell_ids( 'edit' ) ) as $id ) {
            if ( $id > 0 && $id !== $p->get_id() && get_post_status( $id ) === 'publish' ) $ids[] = $id;
        }
        return array_slice( $ids, 0, self::MAX_TOGETHER );
    }

    /** For the dashboard's product editor. */
    public static function for_editor( WC_Product $p ) {
        return [
            'faq'             => self::faq( $p->get_id() ),
            'bought_together' => array_values( array_slice( array_map( 'intval', $p->get_cross_sell_ids( 'edit' ) ), 0, self::MAX_TOGETHER ) ),
        ];
    }

    /** A save from the dashboard: only the keys it sent change. */
    public static function save( WC_Product $p, array $f ) {
        if ( array_key_exists( 'faq', $f ) ) {
            $faq = self::clean_faq( $f['faq'] );
            $faq ? update_post_meta( $p->get_id(), self::FAQ_META, $faq ) : delete_post_meta( $p->get_id(), self::FAQ_META );
        }
        if ( array_key_exists( 'bought_together', $f ) ) {
            $ids = [];
            foreach ( (array) $f['bought_together'] as $id ) {
                $id = absint( $id );
                if ( $id && $id !== $p->get_id() && ! in_array( $id, $ids, true ) && wc_get_product( $id ) ) $ids[] = $id;
            }
            $p->set_cross_sell_ids( array_slice( $ids, 0, self::MAX_TOGETHER ) );
            $p->save();
        }
    }

    /** Store API (single product answers only): questions in the shopper's language and the bought-together ids. */
    public static function for_storefront( $product ) {
        if ( empty( $GLOBALS['qwoo_single_product_request'] ) ) {
            return [ 'faq' => null, 'bought_together' => null ];
        }
        $faq = self::faq( $product->get_id() );
        if ( $faq && class_exists( 'Qwoo_Translations' ) && method_exists( 'Qwoo_Translations', 'get' ) && class_exists( 'Qwoo_Store_Language' ) ) {
            $lang = Qwoo_Store_Language::request_lang();
            $tr   = $lang ? ( Qwoo_Translations::get( $product->get_id() )[ $lang ]['faq'] ?? null ) : null;
            $tr   = self::clean_faq( $tr );
            if ( $tr ) $faq = $tr;
        }
        return [ 'faq' => $faq, 'bought_together' => self::together( $product ) ];
    }
}

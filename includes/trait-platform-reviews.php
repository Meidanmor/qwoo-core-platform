<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Product reviews for the platform dashboard (used by Qwoo_Platform_Dashboard):
 * the list to approve, hide or reply to, and the settings. The storefront
 * side is Qwoo_Reviews.
 */
trait Qwoo_Platform_Reviews {

    private static $review_filters = [ 'pending' => 'hold', 'approved' => 'approve' ];
    private static $review_moves   = [ 'approve' => 'approve', 'unapprove' => 'hold', 'spam' => 'spam', 'trash' => 'trash' ];

    /**
     * { page, filter: '' | pending | approved, search }: newest first, 20 a
     * page, with counts and the settings.
     */
    private static function action_reviews_list( array $params ) {
        $page   = max( 1, min( 1000, (int) ( $params['page'] ?? 1 ) ) );
        $filter = self::$review_filters[ $params['filter'] ?? '' ] ?? 'all';
        $search = mb_substr( sanitize_text_field( (string) ( $params['search'] ?? '' ) ), 0, 100 );
        $args   = [ 'type' => 'review', 'post_type' => 'product', 'status' => $filter ];
        if ( $search !== '' ) {
            $args['search'] = $search;
        }
        $total = (int) get_comments( $args + [ 'count' => true ] );
        $items = get_comments( $args + [ 'number' => 20, 'offset' => ( $page - 1 ) * 20, 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ] );
        return [
            'items'    => array_map( [ __CLASS__, 'review_item' ], $items ),
            'total'    => $total,
            'pages'    => (int) ceil( $total / 20 ),
            'page'     => $page,
            'counts'   => self::review_counts(),
            'settings' => Qwoo_Reviews::settings(),
        ];
    }

    /** { pending, enabled }: for the dashboard's badge. */
    private static function action_reviews_pending() {
        return [ 'pending' => self::review_counts()['pending'], 'enabled' => Qwoo_Reviews::settings()['enabled'] ];
    }

    /** { id, op: approve | unapprove | spam | trash }. */
    private static function action_review_moderate( array $params ) {
        $review = self::find_review( $params['id'] ?? 0 );
        if ( is_wp_error( $review ) ) {
            return $review;
        }
        $op = (string) ( $params['op'] ?? '' );
        if ( ! isset( self::$review_moves[ $op ] ) ) {
            return self::bad( 'Unknown action.' );
        }
        wp_set_comment_status( (int) $review->comment_ID, self::$review_moves[ $op ] );
        $after = get_comment( (int) $review->comment_ID );
        return [
            'review' => $after && in_array( $after->comment_approved, [ '0', '1' ], true ) ? self::review_item( $after ) : null,
            'counts' => self::review_counts(),
        ];
    }

    /** { id, text }: the store's public reply ('' removes it). */
    private static function action_review_reply( array $params ) {
        $review = self::find_review( $params['id'] ?? 0 );
        if ( is_wp_error( $review ) ) {
            return $review;
        }
        $text = trim( sanitize_textarea_field( (string) ( $params['text'] ?? '' ) ) );
        if ( mb_strlen( $text ) > 1000 ) {
            return self::bad( 'A reply can be up to 1,000 characters.' );
        }
        if ( $text === '' ) {
            delete_comment_meta( (int) $review->comment_ID, Qwoo_Reviews::REPLY_META );
        } else {
            update_comment_meta( (int) $review->comment_ID, Qwoo_Reviews::REPLY_META, [ 'text' => $text, 'date' => time() ] );
        }
        return [ 'review' => self::review_item( get_comment( (int) $review->comment_ID ) ) ];
    }

    /** { enabled, notify, request, request_days }. */
    private static function action_reviews_settings( array $params ) {
        $f = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];
        return [ 'settings' => Qwoo_Reviews::save_settings( $f ), 'counts' => self::review_counts() ];
    }

    /* ---------------- helpers ---------------- */

    private static function review_counts() {
        $base = [ 'type' => 'review', 'post_type' => 'product', 'count' => true ];
        return [
            'pending'  => (int) get_comments( $base + [ 'status' => 'hold' ] ),
            'approved' => (int) get_comments( $base + [ 'status' => 'approve' ] ),
        ];
    }

    private static function find_review( $id ) {
        $review = get_comment( absint( $id ) );
        if ( ! $review || $review->comment_type !== 'review' || get_post_type( (int) $review->comment_post_ID ) !== 'product' || ! in_array( $review->comment_approved, [ '0', '1' ], true ) ) {
            return self::error( 'qwoo_dashboard_not_found', 'This review doesn\'t exist any more.', 404 );
        }
        return $review;
    }

    /** A review as the owner sees it: full name and email, product, status. */
    private static function review_item( WP_Comment $c ) {
        $product = wc_get_product( (int) $c->comment_post_ID );
        return [
            'id'       => (int) $c->comment_ID,
            'status'   => $c->comment_approved === '1' ? 'approved' : 'pending',
            'rating'   => (int) get_comment_meta( $c->comment_ID, 'rating', true ),
            'title'    => (string) get_comment_meta( $c->comment_ID, Qwoo_Reviews::TITLE_META, true ),
            'text'     => (string) $c->comment_content,
            'author'   => (string) $c->comment_author,
            'email'    => (string) $c->comment_author_email,
            'date'     => (int) strtotime( $c->comment_date_gmt . ' UTC' ),
            'verified' => (bool) get_comment_meta( $c->comment_ID, 'verified', true ),
            'reply'    => Qwoo_Reviews::reply_of( (int) $c->comment_ID ),
            'product'  => $product ? [
                'id'    => $product->get_id(),
                'name'  => html_entity_decode( $product->get_name(), ENT_QUOTES ),
                'image' => self::image_url( $product->get_image_id(), 'woocommerce_thumbnail' ),
            ] : null,
        ];
    }
}

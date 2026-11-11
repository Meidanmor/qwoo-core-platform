<?php
/**
 * The Products Grid's Query (Store builder → Products Grid → Content → Query) on the Store API's
 * product lists. The storefront sends the grid's rules with each list request:
 *   qq_ids / qq_cats / qq_tags             only these products / categories / tags
 *   qq_not_ids / qq_not_cats / qq_not_tags  never these
 * (on sale, featured and in stock use the Store API's own on_sale / featured / stock_status).
 * They only narrow a list of products that are public anyway, so nothing here needs a sign-in.
 * Categories count their sub categories too.
 */
defined( 'ABSPATH' ) || exit;

class Qwoo_Product_List_Rules {

    const MAX = 200;

    /** The rules of the current Store API list request, or null. */
    private static $rules = null;

    public static function init() {
        add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'read' ], 10, 3 );
        add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'done' ], 10, 1 );
        add_action( 'pre_get_posts', [ __CLASS__, 'apply' ], 20 );
    }

    private static function ids( $value ): array {
        if ( is_array( $value ) ) $value = implode( ',', $value );
        if ( ! is_scalar( $value ) ) return [];
        $ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) ) );
        return array_slice( $ids, 0, self::MAX );
    }

    public static function read( $response, $handler, $request ) {
        self::$rules = null;
        // The products list, and its counts per filter value (the Filters block's "how many products each choice has").
        if ( ! $request instanceof WP_REST_Request || ! in_array( $request->get_route(), [ '/wc/store/v1/products', '/wc/store/v1/products/collection-data' ], true ) ) return $response;
        $rules = [];
        foreach ( [ 'qq_ids', 'qq_cats', 'qq_tags', 'qq_not_ids', 'qq_not_cats', 'qq_not_tags' ] as $key ) {
            $ids = self::ids( $request->get_param( $key ) );
            if ( $ids ) $rules[ $key ] = $ids;
        }
        if ( $rules ) self::$rules = $rules;
        return $response;
    }

    public static function done( $response ) {
        self::$rules = null;
        return $response;
    }

    public static function apply( $query ) {
        if ( ! self::$rules || ! $query instanceof WP_Query ) return;
        $type = $query->get( 'post_type' );
        if ( $type !== 'product' && ! in_array( 'product', (array) $type, true ) ) return;
        $r = self::$rules;

        if ( ! empty( $r['qq_ids'] ) ) {
            $in = array_filter( array_map( 'intval', (array) $query->get( 'post__in' ) ) );
            $in = $in ? array_values( array_intersect( $in, $r['qq_ids'] ) ) : $r['qq_ids'];
            // An empty post__in means "every product" to WP_Query.
            $query->set( 'post__in', $in ?: [ 0 ] );
        }
        if ( ! empty( $r['qq_not_ids'] ) ) {
            $query->set( 'post__not_in', array_values( array_unique( array_merge( array_map( 'intval', (array) $query->get( 'post__not_in' ) ), $r['qq_not_ids'] ) ) ) );
        }

        $tax = [];
        $map = [
            'qq_cats'     => [ 'product_cat', 'IN' ],
            'qq_tags'     => [ 'product_tag', 'IN' ],
            'qq_not_cats' => [ 'product_cat', 'NOT IN' ],
            'qq_not_tags' => [ 'product_tag', 'NOT IN' ],
        ];
        foreach ( $map as $key => [ $taxonomy, $operator ] ) {
            if ( empty( $r[ $key ] ) ) continue;
            $tax[] = [ 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $r[ $key ], 'operator' => $operator, 'include_children' => true ];
        }
        if ( ! $tax ) return;
        $existing = $query->get( 'tax_query' );
        if ( is_array( $existing ) && $existing ) array_unshift( $tax, $existing );
        $query->set( 'tax_query', array_merge( [ 'relation' => 'AND' ], $tax ) );
    }
}

Qwoo_Product_List_Rules::init();

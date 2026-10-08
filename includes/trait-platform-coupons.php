<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Discount codes for the platform dashboard (used by Qwoo_Platform_Dashboard):
 * WooCommerce coupons, with the same options as in wp-admin.
 *
 * The storefront already applies codes through WooCommerce's Store API,
 * which checks every rule here (dates, limits, products, emails…), so
 * nothing else is needed for the customer side.
 */
trait Qwoo_Platform_Coupons {

    private static $coupon_types    = [ 'percent', 'fixed_cart', 'fixed_product' ];
    private static $coupon_filters  = [ 'active', 'paused', 'expired' ];
    private static $coupon_max_list = 200; // products, categories or emails in one list

    /* ---------------- dashboard actions ---------------- */

    /** { page, search, filter: '' | active | paused | expired }, 20 a page, newest first. */
    private static function action_coupons_list( array $params ) {
        $page   = max( 1, min( 1000, (int) ( $params['page'] ?? 1 ) ) );
        $search = mb_substr( sanitize_text_field( (string) ( $params['search'] ?? '' ) ), 0, 100 );
        $filter = in_array( $params['filter'] ?? '', self::$coupon_filters, true ) ? $params['filter'] : '';
        $now    = time();

        $args = [
            'post_type'      => 'shop_coupon',
            'post_status'    => $filter === 'paused' ? 'draft' : ( $filter ? 'publish' : [ 'publish', 'draft', 'pending', 'future', 'private' ] ),
            'posts_per_page' => 20,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ];
        if ( $search !== '' ) {
            $args['s'] = $search; // the code is the title; the description is the excerpt
        }
        if ( $filter === 'expired' ) {
            $args['meta_query'] = [
                [ 'key' => 'date_expires', 'value' => '', 'compare' => '!=' ],
                [ 'key' => 'date_expires', 'value' => $now, 'compare' => '<=', 'type' => 'NUMERIC' ],
            ];
        } elseif ( $filter === 'active' ) {
            $args['meta_query'] = [
                'relation' => 'OR',
                [ 'key' => 'date_expires', 'compare' => 'NOT EXISTS' ],
                [ 'key' => 'date_expires', 'value' => '' ],
                [ 'key' => 'date_expires', 'value' => $now, 'compare' => '>', 'type' => 'NUMERIC' ],
            ];
        }
        $query = new WP_Query( $args );
        $items = [];
        foreach ( $query->posts as $id ) {
            $items[] = self::coupon_summary( new WC_Coupon( $id ) );
        }
        return [
            'items'    => $items,
            'total'    => (int) $query->found_posts,
            'pages'    => (int) $query->max_num_pages,
            'page'     => $page,
            'currency' => get_woocommerce_currency(),
            'enabled'  => wc_coupons_enabled(),
        ];
    }

    private static function action_coupon_get( array $params ) {
        $coupon = self::find_coupon( $params['id'] ?? 0 );
        return is_wp_error( $coupon ) ? $coupon : self::coupon_full( $coupon );
    }

    /**
     * Creates (no id) or updates a coupon from params.fields, named like
     * WooCommerce's own: code, description, status (publish | draft),
     * discount_type, amount, free_shipping, date_expires (Y-m-d: the code
     * works until the end of that day), minimum_amount, maximum_amount,
     * individual_use, exclude_sale_items, product_ids, excluded_product_ids,
     * product_categories, excluded_product_categories, email_restrictions,
     * usage_limit, usage_limit_per_user, limit_usage_to_x_items ('' or 0 =
     * no limit). Everything is checked before anything changes.
     */
    private static function action_coupon_save( array $params ) {
        $f      = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];
        $id     = absint( $params['id'] ?? 0 );
        $coupon = null;
        if ( $id ) {
            $coupon = self::find_coupon( $id );
            if ( is_wp_error( $coupon ) ) {
                return $coupon;
            }
        }

        $code = wc_format_coupon_code( trim( sanitize_text_field( (string) ( $f['code'] ?? '' ) ) ) );
        // Codes made in wp-admin may have other characters: they can stay as they are.
        $kept = $coupon && wc_strtolower( $code ) === wc_strtolower( $coupon->get_code() );
        if ( ! $kept && ! preg_match( '/^[\p{L}\p{N}_-]{2,50}$/u', $code ) ) {
            return self::bad( 'The code needs 2 to 50 letters or numbers (dashes and underscores are fine, spaces aren\'t).' );
        }
        if ( wc_get_coupon_id_by_code( $code, $id ) ) {
            return self::bad( 'Another discount already uses this code. Choose a different one.' );
        }

        $description = trim( sanitize_textarea_field( (string) ( $f['description'] ?? '' ) ) );
        if ( mb_strlen( $description ) > 500 ) {
            return self::bad( 'The description is too long (500 characters at most).' );
        }
        $type = (string) ( $f['discount_type'] ?? '' );
        if ( ! in_array( $type, self::$coupon_types, true ) ) {
            return self::bad( 'Choose the kind of discount.' );
        }
        $free   = ! empty( $f['free_shipping'] );
        $amount = self::price( $f['amount'] ?? '' );
        if ( $amount === null ) {
            return self::bad( 'The discount must be a number, like 10 or 9.90.' );
        }
        if ( (float) $amount <= 0 && ! $free ) {
            return self::bad( 'Enter the discount, or give free shipping.' );
        }
        if ( $type === 'percent' && (float) $amount > 100 ) {
            return self::bad( 'A percentage discount can be 100% at most.' );
        }

        $expires = trim( (string) ( $f['date_expires'] ?? '' ) );
        if ( $expires !== '' ) {
            $date = DateTime::createFromFormat( '!Y-m-d', $expires, wp_timezone() );
            if ( ! $date || $date->format( 'Y-m-d' ) !== $expires ) {
                return self::bad( 'The expiry date isn\'t a valid date.' );
            }
            $expires = $date->setTime( 23, 59, 59 )->getTimestamp();
        }

        $min = self::price( $f['minimum_amount'] ?? '' );
        $max = self::price( $f['maximum_amount'] ?? '' );
        if ( $min === null || $max === null ) {
            return self::bad( 'The minimum and maximum spend must be numbers.' );
        }
        if ( $min !== '' && $max !== '' && (float) $max < (float) $min ) {
            return self::bad( 'The maximum spend is lower than the minimum spend.' );
        }

        $lists = [];
        foreach ( [ 'product_ids', 'excluded_product_ids' ] as $key ) {
            $lists[ $key ] = self::coupon_ids( $f[ $key ] ?? [], static fn( $id ) => in_array( get_post_type( $id ), [ 'product', 'product_variation' ], true ) );
        }
        foreach ( [ 'product_categories', 'excluded_product_categories' ] as $key ) {
            $lists[ $key ] = self::coupon_ids( $f[ $key ] ?? [], static fn( $id ) => (bool) term_exists( $id, 'product_cat' ) );
        }
        foreach ( $lists as $list ) {
            if ( $list === null ) {
                return self::bad( 'A product or category in the lists doesn\'t exist any more. Remove it and save again.' );
            }
        }
        if ( array_intersect( $lists['product_ids'], $lists['excluded_product_ids'] ) ) {
            return self::bad( 'A product can\'t be both included and excluded.' );
        }
        if ( array_intersect( $lists['product_categories'], $lists['excluded_product_categories'] ) ) {
            return self::bad( 'A category can\'t be both included and excluded.' );
        }

        $emails = [];
        foreach ( array_slice( (array) ( $f['email_restrictions'] ?? [] ), 0, self::$coupon_max_list + 1 ) as $email ) {
            $email = strtolower( trim( sanitize_text_field( (string) $email ) ) );
            if ( $email === '' ) {
                continue;
            }
            // Like wp-admin, * matches anything: *@example.com.
            if ( strlen( $email ) > 200 || ! is_email( str_replace( '*', 'a', $email ) ) ) {
                return self::bad( '"' . $email . '" isn\'t an email address.' );
            }
            $emails[] = $email;
        }
        $emails = array_values( array_unique( $emails ) );
        if ( count( $emails ) > self::$coupon_max_list ) {
            return self::bad( 'Up to ' . self::$coupon_max_list . ' email addresses.' );
        }

        $limits = [];
        foreach ( [ 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items' ] as $key ) {
            $value = trim( (string) ( $f[ $key ] ?? '' ) );
            if ( $value !== '' && ! preg_match( '/^\d{1,7}$/', $value ) ) {
                return self::bad( 'Usage limits must be whole numbers.' );
            }
            $limits[ $key ] = (int) $value;
        }

        $status = ( $f['status'] ?? 'publish' ) === 'draft' ? 'draft' : 'publish';
        $coupon = $coupon ?: new WC_Coupon();
        $coupon->set_props( [
            'code'                        => $code,
            'description'                 => $description,
            'discount_type'               => $type,
            'amount'                      => $amount === '' ? 0 : $amount,
            'free_shipping'               => $free,
            'date_expires'                => $expires === '' ? null : $expires,
            'minimum_amount'              => $min,
            'maximum_amount'              => $max,
            'individual_use'              => ! empty( $f['individual_use'] ),
            'exclude_sale_items'          => ! empty( $f['exclude_sale_items'] ),
            'product_ids'                 => $lists['product_ids'],
            'excluded_product_ids'        => $lists['excluded_product_ids'],
            'product_categories'          => $lists['product_categories'],
            'excluded_product_categories' => $lists['excluded_product_categories'],
            'email_restrictions'          => $emails,
            'usage_limit'                 => $limits['usage_limit'],
            'usage_limit_per_user'        => $limits['usage_limit_per_user'],
            // Only per-product discounts count items (as in wp-admin).
            'limit_usage_to_x_items'      => $type === 'fixed_cart' ? 0 : $limits['limit_usage_to_x_items'],
        ] );
        if ( method_exists( $coupon, 'set_status' ) ) {
            $coupon->set_status( $status );
        }
        $coupon->save();
        if ( ! $coupon->get_id() ) {
            return self::bad( 'The discount couldn\'t be saved. Please try again.' );
        }
        if ( get_post_status( $coupon->get_id() ) !== $status ) {
            wp_update_post( [ 'ID' => $coupon->get_id(), 'post_status' => $status ] );
        }
        return self::coupon_full( new WC_Coupon( $coupon->get_id() ) );
    }

    /** Moves a coupon to the trash (restorable from wp-admin for 30 days). */
    private static function action_coupon_delete( array $params ) {
        $coupon = self::find_coupon( $params['id'] ?? 0 );
        if ( is_wp_error( $coupon ) ) {
            return $coupon;
        }
        $id = $coupon->get_id(); // the trash clears it
        $coupon->delete( false );
        return [ 'deleted' => $id ];
    }

    /** Turns on discount codes at checkout (WooCommerce → Settings → General). */
    private static function action_coupons_enable() {
        update_option( 'woocommerce_enable_coupons', 'yes' );
        return [ 'enabled' => wc_coupons_enabled() ];
    }

    /* ---------------- helpers ---------------- */

    private static function find_coupon( $id ) {
        $id = absint( $id );
        if ( ! $id || get_post_type( $id ) !== 'shop_coupon' || ! in_array( get_post_status( $id ), [ 'publish', 'draft', 'pending', 'future', 'private' ], true ) ) {
            return self::error( 'qwoo_dashboard_not_found', 'This discount doesn\'t exist any more.', 404 );
        }
        return new WC_Coupon( $id );
    }

    private static function coupon_summary( WC_Coupon $c ) {
        $expires = $c->get_date_expires();
        return [
            'id'            => $c->get_id(),
            'code'          => html_entity_decode( $c->get_code(), ENT_QUOTES ),
            'description'   => $c->get_description(),
            'status'        => get_post_status( $c->get_id() ) === 'publish' ? 'publish' : 'draft',
            'discount_type' => $c->get_discount_type(),
            'amount'        => wc_format_decimal( $c->get_amount() ),
            'free_shipping' => $c->get_free_shipping(),
            'date_expires'  => $expires ? wp_date( 'Y-m-d', $expires->getTimestamp() ) : '',
            'expired'       => $expires && $expires->getTimestamp() <= time(),
            'usage_count'   => $c->get_usage_count(),
            'usage_limit'   => $c->get_usage_limit(),
        ];
    }

    private static function coupon_full( WC_Coupon $c ) {
        $products   = array_merge( $c->get_product_ids(), $c->get_excluded_product_ids() );
        $categories = array_merge( $c->get_product_categories(), $c->get_excluded_product_categories() );
        $labels     = [ 'products' => [], 'categories' => [] ];
        foreach ( array_unique( $products ) as $id ) {
            $product = wc_get_product( $id );
            $labels['products'][ $id ] = $product ? html_entity_decode( wp_strip_all_tags( $product->get_formatted_name() ), ENT_QUOTES ) : '#' . $id;
        }
        foreach ( array_unique( $categories ) as $id ) {
            $term = get_term( $id, 'product_cat' );
            $labels['categories'][ $id ] = $term && ! is_wp_error( $term ) ? html_entity_decode( $term->name, ENT_QUOTES ) : '#' . $id;
        }
        return self::coupon_summary( $c ) + [
            'minimum_amount'              => $c->get_minimum_amount() === '' ? '' : wc_format_decimal( $c->get_minimum_amount() ),
            'maximum_amount'              => $c->get_maximum_amount() === '' ? '' : wc_format_decimal( $c->get_maximum_amount() ),
            'individual_use'              => $c->get_individual_use(),
            'exclude_sale_items'          => $c->get_exclude_sale_items(),
            'product_ids'                 => array_map( 'intval', $c->get_product_ids() ),
            'excluded_product_ids'        => array_map( 'intval', $c->get_excluded_product_ids() ),
            'product_categories'          => array_map( 'intval', $c->get_product_categories() ),
            'excluded_product_categories' => array_map( 'intval', $c->get_excluded_product_categories() ),
            'email_restrictions'          => array_values( $c->get_email_restrictions() ),
            'usage_limit_per_user'        => $c->get_usage_limit_per_user(),
            'limit_usage_to_x_items'      => (int) $c->get_limit_usage_to_x_items(),
            'labels'                      => $labels,
            'currency'                    => get_woocommerce_currency(),
            'enabled'                     => wc_coupons_enabled(),
            'free_shipping_ready'         => self::free_shipping_takes_codes(),
        ];
    }

    /** Unique ids that pass $exists; null when one doesn't. */
    private static function coupon_ids( $value, callable $exists ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : [] ) ) ) );
        if ( count( $ids ) > self::$coupon_max_list ) {
            return null;
        }
        foreach ( $ids as $id ) {
            if ( ! $exists( $id ) ) {
                return null;
            }
        }
        return $ids;
    }

    /** Whether a turned-on free shipping method accepts free-shipping codes. */
    private static function free_shipping_takes_codes() {
        $zones = array_map( static fn( $z ) => new WC_Shipping_Zone( $z['id'] ), WC_Shipping_Zones::get_zones() );
        $zones[] = new WC_Shipping_Zone( 0 );
        foreach ( $zones as $zone ) {
            foreach ( $zone->get_shipping_methods( true ) as $method ) {
                if ( $method->id === 'free_shipping' && in_array( $method->get_instance_option( 'requires', '' ), [ 'coupon', 'either', 'both' ], true ) ) {
                    return true;
                }
            }
        }
        return false;
    }
}

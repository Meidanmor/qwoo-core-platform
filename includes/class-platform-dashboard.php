<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The platform's store dashboard: owners manage this store from the
 * platform's app (/app/ on the platform site), never from wp-admin here.
 *
 * The owner's browser only talks to the platform. The platform calls
 * POST /qwoo/v1/platform/dashboard with { action, params } and a token
 * signed with its Ed25519 private key (header X-Qwoo-Platform-Token):
 *
 *   base64url(payload) "." base64url(signature)
 *
 * The signature covers "qwoo-dashboard-v1." + base64url(payload), and the
 * payload is JSON:
 *
 *   v    1
 *   kid  which platform key signed it
 *   aud  "store:<store id>@<this site's host>": a token is only valid here
 *   act  the action; must match the body's action
 *   sub  the platform user (the store owner)
 *   iat / exp  issued / expires (at most 2 minutes apart)
 *   jti  random id: each token works once
 *   bh   base64url(sha256(request body)): the token covers the exact body
 *
 * This store only keeps the platform's public key, so nothing here can
 * sign requests (for this store or any other). Only the actions in
 * ACTIONS can be run.
 *
 * The public key is set once through POST /qwoo/v1/platform/trust-key with
 * the publish key (Qwoo_Platform_Connection). Replacing it afterwards also
 * needs a token signed by the current key.
 */
class Qwoo_Platform_Dashboard {

    use Qwoo_Platform_Payments;

    const KEY_OPTION   = 'qwoo_platform_dashboard_key';
    const TOKEN_HEADER = 'X-Qwoo-Platform-Token';
    const CONTEXT      = 'qwoo-dashboard-v1.';
    const MAX_LIFETIME = 120;
    const CLOCK_SKEW   = 30;
    const JTI_PREFIX   = '_qwoo_pd_jti_';

    /** action => method. Anything else is refused. */
    const ACTIONS = [
        'overview'        => 'action_overview',
        'products_list'   => 'action_products_list',
        'product_get'     => 'action_product_get',
        'product_save'    => 'action_product_save',
        'product_delete'  => 'action_product_delete',
        'categories_list' => 'action_categories_list',
        'category_create' => 'action_category_create',
        'category_get'    => 'action_category_get',
        'category_save'   => 'action_category_save',
        'category_delete' => 'action_category_delete',
        'seo_get'         => 'action_seo_get',
        'seo_save'        => 'action_seo_save',
        'image_upload'    => 'action_image_upload',
        'orders_list'     => 'action_orders_list',
        'order_get'       => 'action_order_get',
        'order_status'    => 'action_order_status',
        'order_note'      => 'action_order_note',
        'order_refund'    => 'action_order_refund',
        'settings_get'    => 'action_settings_get',
        'settings_save'   => 'action_settings_save',
        'states'          => 'action_states',
        'tax_rate_save'   => 'action_tax_rate_save',
        'tax_rate_delete' => 'action_tax_rate_delete',
        'shipping_get'    => 'action_shipping_get',
        'zone_save'       => 'action_zone_save',
        'zone_delete'     => 'action_zone_delete',
        'method_save'     => 'action_method_save',
        'method_delete'   => 'action_method_delete',
        'design_get'      => 'action_design_get',
        'design_save'     => 'action_design_save',
        'design_publish'  => 'action_design_publish',
        'design_search'   => 'action_design_search',
        'design_media'    => 'action_design_media',
        'design_versions' => 'action_design_versions',
        'design_version'  => 'action_design_version',
        'template_save'   => 'action_template_save',
        'template_get'    => 'action_template_get',
        'template_delete' => 'action_template_delete',
        'video_upload'    => 'action_video_upload',
        'payments_get'          => 'action_payments_get',
        'payment_save'          => 'action_payment_save',
        'stripe_install'        => 'action_stripe_install',
        'stripe_connect_start'  => 'action_stripe_connect_start',
        'stripe_connect_finish' => 'action_stripe_connect_finish',
        'stripe_keys'           => 'action_stripe_keys',
        'stripe_settings'       => 'action_stripe_settings',
        'stripe_disconnect'     => 'action_stripe_disconnect',
        'frontend_domain_set'   => 'action_frontend_domain_set',
    ];

    const MAX_VIDEO_BYTES = 20971520; // 20 MB

    /** Shipping methods the dashboard can add and edit. */
    const SHIPPING_METHODS = [ 'flat_rate', 'free_shipping', 'local_pickup' ];

    /** Order statuses the owner can move an order to, from each status. */
    const ORDER_MOVES = [
        'pending'    => [ 'processing', 'cancelled' ],
        'on-hold'    => [ 'processing', 'completed', 'cancelled' ],
        'processing' => [ 'completed', 'on-hold', 'cancelled' ],
        'completed'  => [ 'processing' ],
        'failed'     => [ 'cancelled' ],
    ];
    const ORDER_FILTERS = [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ];

    /** Product statuses the dashboard shows and sets. */
    const PRODUCT_STATUSES = [ 'publish', 'draft' ];
    const MAX_GALLERY      = 12;
    const EDITABLE_TYPES   = [ 'simple', 'variable' ];
    const MAX_ATTRIBUTES   = 5;   // options per product (Size, Color…)
    const MAX_OPTIONS      = 40;  // choices per option
    const MAX_VARIATIONS   = 150; // combinations per product
    const MAX_IMAGE_BYTES  = 8388608; // 8 MB
    const MAX_IMAGE_SIDE   = 2400;

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        // The 'qwoo' namespace also requires the proxy secret.
        register_rest_route( 'qwoo/v1', '/platform/dashboard', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_dashboard' ],
            'permission_callback' => '__return_true', // checked in the callback: the token covers the body
        ] );
        // The storefront's checkout (public values only; the proxy secret still applies).
        register_rest_route( 'qwoo/v1', '/payment-config', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_payment_config' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'qwoo/v1', '/platform/trust-key', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_trust_key' ],
            'permission_callback' => [ 'Qwoo_Platform_Connection', 'rest_authorized' ],
        ] );
    }

    /* ---------------- routes ---------------- */

    public static function rest_dashboard( WP_REST_Request $request ) {
        $body   = (string) $request->get_body();
        $data   = json_decode( $body, true );
        $action = is_array( $data ) ? (string) ( $data['action'] ?? '' ) : '';

        $claims = self::verify( (string) $request->get_header( 'x_qwoo_platform_token' ), $body, $action );
        if ( is_wp_error( $claims ) ) {
            return $claims;
        }
        if ( ! isset( self::ACTIONS[ $action ] ) ) {
            return self::error( 'qwoo_dashboard_action', 'Unknown action.', 400 );
        }
        if ( ! class_exists( 'WooCommerce' ) ) {
            return self::error( 'qwoo_dashboard_no_wc', 'WooCommerce is not active on this store.', 503 );
        }

        $params = is_array( $data['params'] ?? null ) ? $data['params'] : [];
        $result = call_user_func( [ __CLASS__, self::ACTIONS[ $action ] ], $params, $claims );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $response = rest_ensure_response( $result );
        $response->header( 'Cache-Control', 'no-store' );
        return $response;
    }

    /** { kid, public_key } (base64). */
    public static function rest_trust_key( WP_REST_Request $request ) {
        $params = (array) $request->get_json_params();
        $kid    = (string) ( $params['kid'] ?? '' );
        $public = base64_decode( (string) ( $params['public_key'] ?? '' ), true );
        if ( ! preg_match( '/^[a-f0-9]{16}$/', $kid ) || $public === false || strlen( $public ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
            return self::error( 'qwoo_dashboard_key', 'Invalid key.', 400 );
        }
        if ( $kid !== substr( hash( 'sha256', $public ), 0, 16 ) ) {
            return self::error( 'qwoo_dashboard_key', 'The key id does not match the key.', 400 );
        }

        $current = self::trusted_key();
        if ( $current && $current['kid'] !== $kid ) {
            // A new key must be vouched for by the one we have.
            $claims = self::verify( (string) $request->get_header( 'x_qwoo_platform_token' ), (string) $request->get_body(), 'trust_key' );
            if ( is_wp_error( $claims ) ) {
                return self::error( 'qwoo_dashboard_key_change', 'Replacing the platform key needs a token signed by the current key.', 403 );
            }
        }
        if ( ! $current || $current['kid'] !== $kid ) {
            update_option( self::KEY_OPTION, [ 'kid' => $kid, 'public' => base64_encode( $public ), 'since' => time() ], false );
        }
        return rest_ensure_response( [ 'kid' => $kid ] );
    }

    /* ---------------- token checks ---------------- */

    private static function trusted_key() {
        $k = get_option( self::KEY_OPTION );
        if ( ! is_array( $k ) || empty( $k['kid'] ) || empty( $k['public'] ) ) {
            return null;
        }
        $public = base64_decode( (string) $k['public'], true );
        if ( $public === false || strlen( $public ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
            return null;
        }
        return [ 'kid' => (string) $k['kid'], 'public' => $public ];
    }

    /** @return array|WP_Error The token's claims. */
    private static function verify( $token, $body, $action ) {
        $connection = Qwoo_Platform_Connection::get();
        $key        = self::trusted_key();
        if ( ! $connection || ! $key ) {
            return self::error( 'qwoo_dashboard_no_key', 'This store has no platform key yet.', 412 );
        }

        $parts = explode( '.', $token );
        if ( count( $parts ) !== 2 || strlen( $token ) > 2048 ) {
            return self::denied();
        }
        $payload   = self::b64url_decode( $parts[0] );
        $signature = self::b64url_decode( $parts[1] );
        if ( $payload === null || $signature === null || strlen( $signature ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
            return self::denied();
        }
        if ( ! sodium_crypto_sign_verify_detached( $signature, self::CONTEXT . $parts[0], $key['public'] ) ) {
            return self::denied();
        }

        $c   = json_decode( $payload, true );
        $now = time();
        $aud = 'store:' . (int) $connection['store_id'] . '@' . strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        if (
            ! is_array( $c )
            || ( $c['v'] ?? null ) !== 1
            || ! hash_equals( $key['kid'], (string) ( $c['kid'] ?? '' ) )
            || ! hash_equals( $aud, (string) ( $c['aud'] ?? '' ) )
            || ! hash_equals( (string) $action, (string) ( $c['act'] ?? '' ) )
            || ! is_int( $c['iat'] ?? null ) || ! is_int( $c['exp'] ?? null )
            || $c['iat'] > $now + self::CLOCK_SKEW
            || $c['exp'] < $now - self::CLOCK_SKEW
            || $c['exp'] - $c['iat'] > self::MAX_LIFETIME
            || ! hash_equals( self::b64url( hash( 'sha256', $body, true ) ), (string) ( $c['bh'] ?? '' ) )
            || ! preg_match( '/^[a-f0-9]{32}$/', (string) ( $c['jti'] ?? '' ) )
        ) {
            return self::denied();
        }
        if ( ! self::use_jti( $c['jti'], $c['exp'] + self::CLOCK_SKEW ) ) {
            return self::denied();
        }
        return $c;
    }

    /**
     * Records a token id; false when it was used before. The options
     * table's unique option_name makes this atomic.
     */
    private static function use_jti( $jti, $until ) {
        global $wpdb;
        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::JTI_PREFIX . $jti,
            (string) $until
        ) );
        if ( wp_rand( 1, 50 ) === 1 ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
                $wpdb->esc_like( self::JTI_PREFIX ) . '%',
                time()
            ) );
        }
        return $inserted === 1;
    }

    private static function denied() {
        return self::error( 'qwoo_dashboard_denied', 'Not allowed.', 401 );
    }

    private static function error( $code, $message, $status ) {
        return new WP_Error( $code, $message, [ 'status' => $status ] );
    }

    private static function b64url( $raw ) {
        return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
    }

    private static function b64url_decode( $text ) {
        if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $text ) ) {
            return null;
        }
        $raw = base64_decode( strtr( $text, '-_', '+/' ), true );
        return $raw === false ? null : $raw;
    }

    /* ---------------- actions ---------------- */

    /** Sales, orders and products at a glance. */
    private static function action_overview( array $params, array $claims ) {
        $tz      = wp_timezone();
        $start   = new DateTimeImmutable( 'today', $tz );
        $today   = $start->getTimestamp();
        $since   = $start->modify( '-29 days' )->getTimestamp();
        $counted = array_unique( array_merge( wc_get_is_paid_statuses(), [ 'on-hold' ] ) );

        // Calendar days in the store's time zone (not 86400-second steps, which drift over DST).
        $daily = [];
        for ( $i = 29; $i >= 0; $i-- ) {
            $daily[ $start->modify( "-{$i} days" )->format( 'Y-m-d' ) ] = 0.0;
        }
        $sales = [ 'total' => 0.0, 'orders' => 0, 'today' => 0.0, 'today_orders' => 0 ];

        $orders = wc_get_orders( [
            'type'         => 'shop_order',
            'status'       => $counted,
            'date_created' => '>=' . $since,
            'limit'        => 2000,
        ] );
        foreach ( $orders as $order ) {
            $created = $order->get_date_created();
            if ( ! $created ) continue;
            $total = (float) $order->get_total() - (float) $order->get_total_refunded();
            $day   = wp_date( 'Y-m-d', $created->getTimestamp(), $tz );
            if ( isset( $daily[ $day ] ) ) {
                $daily[ $day ] += $total;
            }
            $sales['total'] += $total;
            $sales['orders']++;
            if ( $created->getTimestamp() >= $today ) {
                $sales['today'] += $total;
                $sales['today_orders']++;
            }
        }

        $recent = [];
        foreach ( wc_get_orders( [ 'type' => 'shop_order', 'limit' => 6, 'orderby' => 'date', 'order' => 'DESC' ] ) as $order ) {
            $created  = $order->get_date_created();
            $recent[] = [
                'id'       => $order->get_id(),
                'number'   => (string) $order->get_order_number(),
                'date'     => $created ? $created->getTimestamp() : null,
                'status'   => $order->get_status(),
                'label'    => wc_get_order_status_name( $order->get_status() ),
                'total'    => (float) $order->get_total(),
                'currency' => $order->get_currency(),
                'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'items'    => $order->get_item_count(),
            ];
        }

        $count = static function ( array $args ) {
            $r = wc_get_products( $args + [ 'limit' => 1, 'paginate' => true, 'return' => 'ids' ] );
            return (int) $r->total;
        };

        return [
            'store'    => [
                'title'    => get_bloginfo( 'name' ),
                'currency' => get_woocommerce_currency(),
            ],
            'sales'    => array_map( static fn( $v ) => is_float( $v ) ? round( $v, 2 ) : $v, $sales ),
            'daily'    => array_map( static fn( $v ) => round( $v, 2 ), $daily ),
            'to_fulfil' => (int) wc_orders_count( 'processing' ),
            'products' => [
                'published'    => $count( [ 'status' => 'publish' ] ),
                'out_of_stock' => $count( [ 'status' => 'publish', 'stock_status' => 'outofstock' ] ),
            ],
            'recent'   => $recent,
        ];
    }

    /* ---------------- products ---------------- */

    /** A page of products (20), newest first: { items, total, pages, page }. */
    private static function action_products_list( array $params ) {
        $page   = max( 1, min( 1000, (int) ( $params['page'] ?? 1 ) ) );
        $search = mb_substr( sanitize_text_field( (string) ( $params['search'] ?? '' ) ), 0, 100 );
        $filter = (string) ( $params['filter'] ?? '' );

        $args = [
            'post_type'      => 'product',
            'post_status'    => in_array( $filter, self::PRODUCT_STATUSES, true ) ? $filter : [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => 20,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ];
        if ( $search !== '' ) {
            $args['s'] = $search;
        }
        if ( $filter === 'outofstock' ) {
            $args['meta_query'] = [ [ 'key' => '_stock_status', 'value' => 'outofstock' ] ];
        }
        $query = new WP_Query( $args );
        $items = [];
        foreach ( $query->posts as $id ) {
            $product = wc_get_product( $id );
            if ( $product ) {
                $items[] = self::product_summary( $product );
            }
        }
        return [
            'items'    => $items,
            'total'    => (int) $query->found_posts,
            'pages'    => (int) $query->max_num_pages,
            'page'     => $page,
            'currency' => get_woocommerce_currency(),
        ];
    }

    private static function action_product_get( array $params ) {
        $product = self::find_product( $params['id'] ?? 0 );
        return is_wp_error( $product ) ? $product : self::product_full( $product );
    }

    /**
     * Creates (no id) or updates a product from params.fields:
     * type (simple | variable), name, status, description (plain text; left
     * alone when missing), sku, category_ids, image_ids (the first is the
     * main photo), and for simple products regular_price, sale_price,
     * manage_stock, stock_quantity, stock_status.
     *
     * Products with options (type variable) take attributes and variations
     * instead of prices and stock (see variable_plan). Everything is checked
     * before anything changes, so a refused save leaves the product as it was.
     */
    private static function action_product_save( array $params ) {
        $f       = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];
        $type    = ( $f['type'] ?? 'simple' ) === 'variable' ? 'variable' : 'simple';
        $id      = absint( $params['id'] ?? 0 );
        $product = null;
        if ( $id ) {
            $product = self::find_product( $id );
            if ( is_wp_error( $product ) ) {
                return $product;
            }
            if ( ! $product->is_type( self::EDITABLE_TYPES ) ) {
                return self::bad( 'This kind of product can\'t be edited here yet.' );
            }
        }

        $common = self::common_fields( $f );
        if ( is_wp_error( $common ) ) {
            return $common;
        }
        if ( $type === 'variable' ) {
            $plan = self::variable_plan( $product, $f, $common['status'], $common['sku'] );
            if ( is_wp_error( $plan ) ) {
                return $plan;
            }
        } else {
            $stock = self::stock_fields( $f );
            if ( is_wp_error( $stock ) ) {
                return $stock;
            }
            if ( $common['status'] === 'publish' && $stock['regular'] === '' ) {
                return self::bad( 'Add a price before publishing the product.' );
            }
        }

        try {
            if ( $type === 'variable' ) {
                if ( ! $product || ! $product->is_type( 'variable' ) ) {
                    $product = self::change_type( $id, 'variable' );
                }
                self::apply_common( $product, $common );
                $product->set_attributes( self::build_attributes( $plan ) );
                // Prices and stock are on the combinations.
                $product->set_regular_price( '' );
                $product->set_sale_price( '' );
                $product->set_manage_stock( false );
                $product->save();
                self::save_variations( $product, $plan );
                WC_Product_Variable::sync( $product->get_id() );
            } else {
                if ( $product && $product->is_type( 'variable' ) ) {
                    $product = self::change_type( $id, 'simple' );
                    $product->set_attributes( [] );
                } elseif ( ! $product ) {
                    $product = new WC_Product_Simple();
                }
                self::apply_common( $product, $common );
                self::apply_stock( $product, $stock );
                $product->save();
            }
        } catch ( WC_Data_Exception $e ) {
            return self::bad( $e->getMessage() );
        }
        if ( $common['seo'] !== null ) {
            Qwoo_Seo::save( $product->get_id(), 'post', $common['seo'] );
        }
        wc_delete_product_transients( $product->get_id() );
        return self::product_full( wc_get_product( $product->get_id() ) );
    }

    /** Name, status, SKU, description, photos and categories: checked, not yet applied. */
    private static function common_fields( array $f ) {
        $name = trim( sanitize_text_field( (string) ( $f['name'] ?? '' ) ) );
        if ( $name === '' || mb_strlen( $name ) > 200 ) {
            return self::bad( 'Give the product a name (up to 200 characters).' );
        }
        $sku = wc_clean( (string) ( $f['sku'] ?? '' ) );
        if ( mb_strlen( $sku ) > 100 ) {
            return self::bad( 'The SKU can be up to 100 characters.' );
        }
        // The address (/product/<slug>): left alone when missing.
        $slug = null;
        if ( array_key_exists( 'slug', $f ) ) {
            $slug = sanitize_title( mb_substr( (string) $f['slug'], 0, 190 ) );
        }
        // Search engine listing: left alone when missing.
        $seo = null;
        if ( array_key_exists( 'seo', $f ) ) {
            $seo = Qwoo_Seo::clean_input( $f['seo'] );
            if ( is_wp_error( $seo ) ) {
                return $seo;
            }
        }
        $description = null;
        if ( array_key_exists( 'description', $f ) ) {
            $description = (string) $f['description'];
            if ( mb_strlen( $description ) > 20000 ) {
                return self::bad( 'The description is too long (20,000 characters at most).' );
            }
        }
        $images = array_values( array_unique( array_map( 'absint', (array) ( $f['image_ids'] ?? [] ) ) ) );
        if ( count( $images ) > self::MAX_GALLERY + 1 ) {
            return self::bad( 'A product can have up to ' . ( self::MAX_GALLERY + 1 ) . ' photos.' );
        }
        foreach ( $images as $image ) {
            if ( ! $image || ! wp_attachment_is_image( $image ) ) {
                return self::bad( 'One of the photos is missing. Upload it again.' );
            }
        }
        $categories = array_values( array_filter( array_unique( array_map( 'absint', (array) ( $f['category_ids'] ?? [] ) ) ) ) );
        // Every product is in a category: without one, the store's default ("Uncategorized").
        if ( ! $categories && (int) get_option( 'default_product_cat' ) ) {
            $categories = [ (int) get_option( 'default_product_cat' ) ];
        }
        foreach ( $categories as $category ) {
            $term = get_term( $category, 'product_cat' );
            if ( ! $term || is_wp_error( $term ) ) {
                return self::bad( 'One of the categories doesn\'t exist any more.' );
            }
        }
        return [
            'name'        => $name,
            'status'      => in_array( $f['status'] ?? '', self::PRODUCT_STATUSES, true ) ? $f['status'] : 'draft',
            'sku'         => $sku,
            'description' => $description,
            'images'      => $images,
            'categories'  => $categories,
            'slug'        => $slug,
            'seo'         => $seo,
        ];
    }

    private static function apply_common( WC_Product $product, array $c ) {
        $product->set_name( $c['name'] );
        $product->set_status( $c['status'] );
        $product->set_sku( $c['sku'] ); // throws for an SKU another product has
        if ( $c['description'] !== null ) {
            $product->set_description( self::from_text( $c['description'] ) );
        }
        $product->set_category_ids( $c['categories'] );
        if ( $c['slug'] ) {
            // WordPress keeps the old one (_wp_old_slug): the old address redirects.
            $product->set_slug( $c['slug'] );
        }
        $product->set_image_id( $c['images'][0] ?? 0 );
        $product->set_gallery_image_ids( array_slice( $c['images'], 1 ) );
    }

    /**
     * Prices and stock of a simple product or of one combination:
     * regular_price, sale_price, manage_stock, stock_quantity, stock_status.
     * $label names the combination in messages.
     */
    private static function stock_fields( array $f, $label = '' ) {
        $of      = $label === '' ? '' : " ($label)";
        $regular = self::price( $f['regular_price'] ?? '' );
        $sale    = self::price( $f['sale_price'] ?? '' );
        if ( $regular === null || $sale === null ) {
            return self::bad( "Prices must be numbers, like 24.90$of." );
        }
        if ( $sale !== '' && ( $regular === '' || (float) $sale >= (float) $regular ) ) {
            return self::bad( "The sale price must be lower than the regular price$of." );
        }
        $manage   = ! empty( $f['manage_stock'] );
        $quantity = $manage ? filter_var( $f['stock_quantity'] ?? null, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => -999999, 'max_range' => 9999999 ] ] ) : null;
        if ( $manage && $quantity === false ) {
            return self::bad( "Stock must be a whole number$of." );
        }
        return [
            'regular'  => $regular,
            'sale'     => $sale,
            'manage'   => $manage,
            'quantity' => $quantity,
            'status'   => ( $f['stock_status'] ?? '' ) === 'outofstock' ? 'outofstock' : 'instock',
        ];
    }

    private static function apply_stock( WC_Product $product, array $s ) {
        $product->set_regular_price( $s['regular'] );
        $product->set_sale_price( $s['sale'] );
        $product->set_manage_stock( $s['manage'] );
        if ( $s['manage'] ) {
            $product->set_stock_quantity( $s['quantity'] );
        } else {
            $product->set_stock_status( $s['status'] );
        }
    }

    /**
     * Switches an existing product between simple and variable (a new
     * product just starts as the right class). Going back to simple deletes
     * the combinations.
     */
    private static function change_type( $id, $type ) {
        if ( ! $id ) {
            return $type === 'variable' ? new WC_Product_Variable() : new WC_Product_Simple();
        }
        if ( $type === 'simple' ) {
            $old = wc_get_product( $id );
            foreach ( $old ? $old->get_children() : [] as $child ) {
                $variation = wc_get_product( $child );
                if ( $variation ) {
                    $variation->delete( true );
                }
            }
        }
        wp_set_object_terms( $id, $type, 'product_type' );
        WC_Cache_Helper::invalidate_cache_group( 'product_' . $id ); // the cached product type
        return $type === 'variable' ? new WC_Product_Variable( $id ) : new WC_Product_Simple( $id );
    }

    /**
     * Checks the options and combinations of a product with options:
     *
     *   attributes  [ { name: "Size", options: [ "S", "M" ] } ] (in order)
     *   variations  [ { id (existing combinations only), options: { "Size": "S" },
     *                   regular_price, sale_price, manage_stock, stock_quantity,
     *                   stock_status, sku, image_id, enabled } ]
     *
     * New options are kept on the product itself. Options that come from the
     * store's shared attributes (pa_…) stay shared. Attributes the product
     * has that aren't options (only shown as details) are kept as they are.
     */
    private static function variable_plan( $product, array $f, $status, $parent_sku = '' ) {
        $existing = $product ? $product->get_attributes() : [];
        $input    = array_values( array_filter( (array) ( $f['attributes'] ?? [] ), 'is_array' ) );
        if ( ! $input ) {
            return self::bad( 'Add at least one option, like Size or Color.' );
        }
        if ( count( $input ) > self::MAX_ATTRIBUTES ) {
            return self::bad( 'A product can have up to ' . self::MAX_ATTRIBUTES . ' options.' );
        }

        // lower-case name => { name, key, taxonomy, values: [ lower-case choice => [ name, stored value ] ] }
        $attributes = [];
        foreach ( $input as $a ) {
            $name = trim( sanitize_text_field( (string) ( $a['name'] ?? '' ) ) );
            if ( $name === '' || mb_strlen( $name ) > 50 ) {
                return self::bad( 'Give each option a name, like Size (up to 50 characters).' );
            }
            $lower = mb_strtolower( $name );
            if ( isset( $attributes[ $lower ] ) ) {
                return self::bad( "\"$name\" is there twice." );
            }
            $taxonomy = '';
            foreach ( $existing as $attr ) {
                if ( $attr->is_taxonomy() && mb_strtolower( wc_attribute_label( $attr->get_name() ) ) === $lower ) {
                    $taxonomy = $attr->get_name();
                }
            }
            $values = [];
            foreach ( (array) ( $a['options'] ?? [] ) as $option ) {
                $option = trim( sanitize_text_field( is_scalar( $option ) ? (string) $option : '' ) );
                if ( $option === '' ) {
                    continue;
                }
                if ( mb_strlen( $option ) > 100 ) {
                    return self::bad( "Choices can be up to 100 characters ($name)." );
                }
                if ( strpos( $option, WC_DELIMITER ) !== false ) {
                    return self::bad( 'Choices can\'t contain "' . WC_DELIMITER . "\" ($name)." );
                }
                $key = mb_strtolower( $option );
                if ( isset( $values[ $key ] ) ) {
                    continue;
                }
                $stored = $option; // kept on the product: the combination stores the text itself
                if ( $taxonomy !== '' ) {
                    $term   = get_term_by( 'name', $option, $taxonomy );
                    $stored = $term ? $term->slug : null; // a new shared choice: created when saving
                }
                $values[ $key ] = [ $option, $stored ];
            }
            if ( ! $values ) {
                return self::bad( "Add at least one choice to \"$name\"." );
            }
            if ( count( $values ) > self::MAX_OPTIONS ) {
                return self::bad( "\"$name\" can have up to " . self::MAX_OPTIONS . ' choices.' );
            }
            $attributes[ $lower ] = [
                'name'     => $name,
                'key'      => $taxonomy !== '' ? $taxonomy : sanitize_title( $name ),
                'taxonomy' => $taxonomy,
                'values'   => $values,
            ];
        }

        $details = [];
        foreach ( $existing as $key => $attr ) {
            if ( ! $attr->get_variation() ) {
                $label = mb_strtolower( $attr->is_taxonomy() ? wc_attribute_label( $attr->get_name() ) : $attr->get_name() );
                if ( isset( $attributes[ $label ] ) ) {
                    return self::bad( "\"{$attributes[ $label ]['name']}\" is already a product detail. Use another name." );
                }
                $details[ $key ] = $attr;
            }
        }

        $children = $product && $product->is_type( 'variable' ) ? array_map( 'intval', $product->get_children() ) : [];
        $input    = array_values( array_filter( (array) ( $f['variations'] ?? [] ), 'is_array' ) );
        if ( ! $input ) {
            return self::bad( 'There are no variants to sell. Add choices to the options.' );
        }
        if ( count( $input ) > self::MAX_VARIATIONS ) {
            return self::bad( 'A product can have up to ' . self::MAX_VARIATIONS . ' variants. Use fewer choices.' );
        }

        $variations = [];
        $seen       = [];
        $skus       = $parent_sku === '' ? [] : [ $parent_sku => true ];
        $sold       = 0;
        foreach ( $input as $v ) {
            $vid = absint( $v['id'] ?? 0 );
            if ( $vid && ! in_array( $vid, $children, true ) ) {
                return self::bad( 'One of the variants doesn\'t exist any more. Reload the product and try again.' );
            }
            $chosen = is_array( $v['options'] ?? null ) ? $v['options'] : [];
            $picked = []; // lower-case option name => lower-case choice ('' = any)
            foreach ( $chosen as $attr_name => $option ) {
                $attr_lower = mb_strtolower( trim( (string) $attr_name ) );
                if ( ! isset( $attributes[ $attr_lower ] ) ) {
                    return self::bad( 'A variant uses an option that isn\'t on the product. Reload the product and try again.' );
                }
                $option_lower = mb_strtolower( trim( is_scalar( $option ) ? (string) $option : '' ) );
                if ( $option_lower !== '' && ! isset( $attributes[ $attr_lower ]['values'][ $option_lower ] ) ) {
                    return self::bad( '"' . sanitize_text_field( (string) $option ) . "\" isn't one of the choices of \"{$attributes[ $attr_lower ]['name']}\"." );
                }
                $picked[ $attr_lower ] = $option_lower;
            }
            $labels = [];
            foreach ( $attributes as $attr_lower => $a ) {
                $picked += [ $attr_lower => '' ];
                $labels[] = $picked[ $attr_lower ] === '' ? 'Any ' . $a['name'] : $a['values'][ $picked[ $attr_lower ] ][0];
            }
            ksort( $picked );
            $label = implode( ' / ', $labels );
            $sig   = wp_json_encode( $picked );
            if ( isset( $seen[ $sig ] ) ) {
                return self::bad( "\"$label\" is there twice." );
            }
            $seen[ $sig ] = true;

            $stock = self::stock_fields( $v, $label );
            if ( is_wp_error( $stock ) ) {
                return $stock;
            }
            $enabled = ! array_key_exists( 'enabled', $v ) || ! empty( $v['enabled'] );
            if ( $enabled && $status === 'publish' && $stock['regular'] === '' ) {
                return self::bad( "Add a price to \"$label\", or turn it off." );
            }
            $sold += $enabled ? 1 : 0;
            $sku   = wc_clean( (string) ( $v['sku'] ?? '' ) );
            if ( mb_strlen( $sku ) > 100 ) {
                return self::bad( "The SKU can be up to 100 characters ($label)." );
            }
            if ( $sku !== '' ) {
                if ( isset( $skus[ $sku ] ) || ! wc_product_has_unique_sku( $vid, $sku ) ) {
                    return self::bad( "The SKU \"$sku\" is already used ($label)." );
                }
                $skus[ $sku ] = true;
            }
            $image = absint( $v['image_id'] ?? 0 );
            if ( $image && ! wp_attachment_is_image( $image ) ) {
                return self::bad( "The photo of \"$label\" is missing. Choose it again." );
            }
            $variations[] = [ 'id' => $vid, 'picked' => $picked, 'label' => $label, 'stock' => $stock, 'enabled' => $enabled, 'sku' => $sku, 'image' => $image ];
        }
        if ( $status === 'publish' && ! $sold ) {
            return self::bad( 'Turn on at least one variant before publishing the product.' );
        }
        return [ 'attributes' => $attributes, 'details' => $details, 'variations' => $variations, 'children' => $children ];
    }

    /** The product's attributes from a plan: its options (in order), then its other details. */
    private static function build_attributes( array &$plan ) {
        $list     = [];
        $position = 0;
        foreach ( $plan['attributes'] as &$a ) {
            $attr = new WC_Product_Attribute();
            $attr->set_position( $position++ );
            $attr->set_visible( true );
            $attr->set_variation( true );
            if ( $a['taxonomy'] !== '' ) {
                $ids = [];
                foreach ( $a['values'] as &$value ) {
                    if ( $value[1] === null ) {
                        $term = wp_insert_term( $value[0], $a['taxonomy'] );
                        if ( is_wp_error( $term ) ) {
                            throw new WC_Data_Exception( 'qwoo_term', "\"{$value[0]}\" couldn't be added to \"{$a['name']}\"." );
                        }
                        $value[1] = get_term( $term['term_id'], $a['taxonomy'] )->slug;
                    }
                    $ids[] = (int) get_term_by( 'slug', $value[1], $a['taxonomy'] )->term_id;
                }
                unset( $value );
                $attr->set_id( wc_attribute_taxonomy_id_by_name( $a['taxonomy'] ) );
                $attr->set_name( $a['taxonomy'] );
                $attr->set_options( $ids );
            } else {
                $attr->set_id( 0 );
                $attr->set_name( $a['name'] );
                $attr->set_options( array_values( array_column( $a['values'], 0 ) ) );
            }
            $list[] = $attr;
        }
        unset( $a );
        foreach ( $plan['details'] as $attr ) {
            $attr->set_position( $position++ );
            $list[] = $attr;
        }
        return $list;
    }

    /** Saves the combinations of a plan and deletes the ones that are gone. */
    private static function save_variations( WC_Product_Variable $product, array $plan ) {
        $keep = array_filter( array_column( $plan['variations'], 'id' ) );
        foreach ( array_diff( $plan['children'], $keep ) as $gone ) {
            $variation = wc_get_product( $gone );
            if ( $variation ) {
                $variation->delete( true );
            }
        }
        foreach ( $plan['variations'] as $order => $v ) {
            $variation = new WC_Product_Variation( $v['id'] );
            $variation->set_parent_id( $product->get_id() );
            $values = [];
            foreach ( $v['picked'] as $attr_lower => $option_lower ) {
                $a                   = $plan['attributes'][ $attr_lower ];
                $values[ $a['key'] ] = $option_lower === '' ? '' : $a['values'][ $option_lower ][1];
            }
            $variation->set_attributes( $values );
            $variation->set_status( $v['enabled'] ? 'publish' : 'private' );
            $variation->set_menu_order( $order );
            $variation->set_image_id( $v['image'] );
            self::apply_stock( $variation, $v['stock'] );
            try {
                $variation->set_sku( $v['sku'] );
                $variation->save();
            } catch ( WC_Data_Exception $e ) {
                throw new WC_Data_Exception( $e->getErrorCode(), $e->getMessage() . " ({$v['label']})" );
            }
        }
    }

    /** Moves a product to the trash (restorable from wp-admin for 30 days). */
    private static function action_product_delete( array $params ) {
        $product = self::find_product( $params['id'] ?? 0 );
        if ( is_wp_error( $product ) ) {
            return $product;
        }
        $product->delete( false );
        return [ 'deleted' => $product->get_id() ];
    }

    private static function action_categories_list() {
        $default = (int) get_option( 'default_product_cat' );
        $terms   = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'number' => 500 ] );
        $out     = [];
        foreach ( is_wp_error( $terms ) ? [] : $terms as $term ) {
            $out[] = [ 'id' => (int) $term->term_id, 'name' => html_entity_decode( $term->name, ENT_QUOTES ), 'slug' => urldecode( $term->slug ), 'parent' => (int) $term->parent, 'count' => (int) $term->count, 'default' => (int) $term->term_id === $default ];
        }
        return [ 'items' => $out ];
    }

    private static function find_category( $id ) {
        $term = get_term( absint( $id ), 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) {
            return self::error( 'qwoo_dashboard_not_found', 'This category doesn\'t exist any more.', 404 );
        }
        return $term;
    }

    private static function category_full( WP_Term $term ) {
        $image = absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) );
        return [
            'id'           => (int) $term->term_id,
            'name'         => html_entity_decode( $term->name, ENT_QUOTES ),
            'slug'         => urldecode( $term->slug ),
            'description'  => self::to_text( $term->description ),
            'count'        => (int) $term->count,
            'default'      => (int) $term->term_id === (int) get_option( 'default_product_cat' ),
            'image_id'     => $image,
            'image'        => self::image_url( $image, 'medium' ),
            'url'          => Qwoo_Seo::url( '/product-category/' . $term->slug ),
            'seo'          => Qwoo_Seo::for_editor( (int) $term->term_id, 'term' ),
            'seo_defaults' => Qwoo_Seo::term_defaults( $term ),
        ];
    }

    private static function action_category_get( array $params ) {
        $term = self::find_category( $params['id'] ?? 0 );
        return is_wp_error( $term ) ? $term : self::category_full( $term );
    }

    /**
     * Updates a category from params.fields: name, slug, description (plain
     * text), image_id (its photo) and seo (search engine listing). A changed
     * slug keeps the old address working (it redirects).
     */
    private static function action_category_save( array $params ) {
        $term = self::find_category( $params['id'] ?? 0 );
        if ( is_wp_error( $term ) ) {
            return $term;
        }
        $f    = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];
        $name = trim( sanitize_text_field( (string) ( $f['name'] ?? '' ) ) );
        if ( $name === '' || mb_strlen( $name ) > 100 ) {
            return self::bad( 'Give the category a name (up to 100 characters).' );
        }
        $description = (string) ( $f['description'] ?? '' );
        if ( mb_strlen( $description ) > 5000 ) {
            return self::bad( 'The description is too long (5,000 characters at most).' );
        }
        $slug  = sanitize_title( mb_substr( (string) ( $f['slug'] ?? '' ), 0, 190 ) ) ?: $term->slug;
        $other = get_term_by( 'slug', $slug, 'product_cat' );
        if ( $other && (int) $other->term_id !== (int) $term->term_id ) {
            return self::bad( 'Another category already uses this address. Choose a different one.' );
        }
        $image = absint( $f['image_id'] ?? 0 );
        if ( $image && ! wp_attachment_is_image( $image ) ) {
            return self::bad( 'The photo is missing. Upload it again.' );
        }
        $seo = Qwoo_Seo::clean_input( $f['seo'] ?? [] );
        if ( is_wp_error( $seo ) ) {
            return $seo;
        }

        $old    = $term->slug;
        $result = wp_update_term( $term->term_id, 'product_cat', [
            'name'        => $name,
            'slug'        => $slug,
            'description' => self::from_text( $description ),
        ] );
        if ( is_wp_error( $result ) ) {
            return self::bad( $result->get_error_message() );
        }
        $term = get_term( $term->term_id, 'product_cat' );
        if ( $term->slug !== $old ) {
            Qwoo_Seo::remember_term_slug( (int) $term->term_id, $old );
        }
        $image ? update_term_meta( $term->term_id, 'thumbnail_id', $image ) : delete_term_meta( $term->term_id, 'thumbnail_id' );
        Qwoo_Seo::save( (int) $term->term_id, 'term', $seo );
        return self::category_full( $term );
    }

    /** Deletes a category; its products without another category move to the default one. */
    private static function action_category_delete( array $params ) {
        $term = self::find_category( $params['id'] ?? 0 );
        if ( is_wp_error( $term ) ) {
            return $term;
        }
        $default = (int) get_option( 'default_product_cat' );
        if ( (int) $term->term_id === $default ) {
            return self::bad( 'This is the default category: products without another category are in it. It can\'t be deleted.' );
        }
        $products = get_objects_in_term( $term->term_id, 'product_cat' );
        $result   = wp_delete_term( $term->term_id, 'product_cat' );
        if ( ! $result || is_wp_error( $result ) ) {
            return self::bad( 'The category couldn\'t be deleted. Please try again.' );
        }
        foreach ( is_wp_error( $products ) ? [] : $products as $id ) {
            if ( $default && ! wp_get_object_terms( (int) $id, 'product_cat', [ 'fields' => 'ids' ] ) ) {
                wp_set_object_terms( (int) $id, [ $default ], 'product_cat' );
            }
        }
        return [ 'deleted' => (int) $term->term_id ];
    }

    /* ---------------- SEO: store-wide ---------------- */

    private static function seo_answer() {
        $s     = Qwoo_Seo::settings();
        $image = absint( $s['image_id'] );
        return [
            'home_title'          => (string) $s['home_title'],
            'home_description'    => (string) $s['home_description'],
            'title_pattern'       => (string) $s['title_pattern'],
            'image_id'            => $image,
            'image'               => self::image_url( $image, 'medium' ),
            'google_verification' => (string) $s['google_verification'],
            'store_name'          => Qwoo_Seo::store_name(),
            'url'                 => Qwoo_Seo::url( '/' ),
            'defaults'            => Qwoo_Seo::home_defaults(),
        ];
    }

    private static function action_seo_get() {
        return self::seo_answer();
    }

    /**
     * Store-wide SEO: home_title, home_description, title_pattern (with
     * {title}, may have {store}), image_id (the default share image) and
     * google_verification (Search Console's code or its whole meta tag).
     */
    private static function action_seo_save( array $params ) {
        $f    = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];
        $home = Qwoo_Seo::clean_input( [
            'title'       => $f['home_title'] ?? '',
            'description' => $f['home_description'] ?? '',
            'image_id'    => $f['image_id'] ?? 0,
        ] );
        if ( is_wp_error( $home ) ) {
            return $home;
        }
        $pattern = trim( sanitize_text_field( (string) ( $f['title_pattern'] ?? '' ) ) ) ?: '{title} | {store}';
        if ( strpos( $pattern, '{title}' ) === false || mb_strlen( $pattern ) > 100 ) {
            return self::bad( 'The title pattern needs {title} (where the product or category name goes).' );
        }
        // Search Console gives a whole tag: <meta name="google-site-verification" content="…" />.
        $code = (string) ( $f['google_verification'] ?? '' );
        if ( preg_match( '/content=["\']([^"\']+)["\']/', $code, $m ) ) {
            $code = $m[1];
        }
        $code = trim( $code );
        if ( $code !== '' && ! preg_match( '/^[A-Za-z0-9_-]{10,100}$/', $code ) ) {
            return self::bad( 'That doesn\'t look like a Google verification code. Paste the code (or the whole tag) from Search Console.' );
        }
        update_option( Qwoo_Seo::OPTION, [
            'home_title'          => $home['title'],
            'home_description'    => $home['description'],
            'title_pattern'       => $pattern,
            'image_id'            => $home['image_id'],
            'google_verification' => $code,
        ] );
        return self::seo_answer();
    }

    /** { name } → the new (or existing, same name) category. */
    private static function action_category_create( array $params ) {
        $name = trim( sanitize_text_field( (string) ( $params['name'] ?? '' ) ) );
        if ( $name === '' || mb_strlen( $name ) > 100 ) {
            return self::bad( 'Give the category a name (up to 100 characters).' );
        }
        $existing = term_exists( $name, 'product_cat' );
        $result   = $existing ?: wp_insert_term( $name, 'product_cat' );
        if ( is_wp_error( $result ) ) {
            return self::bad( $result->get_error_message() );
        }
        return [ 'id' => (int) $result['term_id'], 'name' => $name, 'parent' => 0, 'count' => 0, 'default' => false ];
    }

    /**
     * { name, data (base64) } → a Media Library image { id, url }. The photo
     * is decoded and saved again (preferably with GD), which turns it upright
     * and drops everything else in the file: camera data, GPS location,
     * anything hidden. Large photos are scaled to MAX_IMAGE_SIDE.
     */
    private static function action_image_upload( array $params ) {
        $bytes = base64_decode( (string) ( $params['data'] ?? '' ), true );
        if ( $bytes === false || $bytes === '' || strlen( $bytes ) > self::MAX_IMAGE_BYTES ) {
            return self::bad( 'Photos can be up to 8 MB.' );
        }
        $info  = @getimagesizefromstring( $bytes );
        $types = [ IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp' ];
        if ( ! $info || ! isset( $types[ $info[2] ] ) ) {
            return self::bad( 'Use a JPG, PNG or WebP photo.' );
        }
        if ( $info[0] * $info[1] > 40000000 ) {
            return self::bad( 'This photo is too large. Use one under 40 megapixels.' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $ext  = $types[ $info[2] ];
        $base = substr( sanitize_file_name( pathinfo( (string) ( $params['name'] ?? '' ), PATHINFO_FILENAME ) ), 0, 60 ) ?: 'product';
        $tmp  = wp_tempnam( $base . '.' . $ext );
        file_put_contents( $tmp, $bytes );

        $gd_only = static fn() => [ 'WP_Image_Editor_GD' ];
        add_filter( 'wp_image_editors', $gd_only );
        $editor = wp_get_image_editor( $tmp, [ 'mime_type' => $info['mime'] ] );
        remove_filter( 'wp_image_editors', $gd_only );
        if ( is_wp_error( $editor ) ) {
            $editor = wp_get_image_editor( $tmp ); // no GD (or no WebP in GD): the site's default editor
        }
        if ( is_wp_error( $editor ) ) {
            @unlink( $tmp );
            return self::bad( 'This photo can\'t be read. Try another one.' );
        }
        $editor->maybe_exif_rotate();
        $editor->resize( self::MAX_IMAGE_SIDE, self::MAX_IMAGE_SIDE, false ); // a WP_Error when it's already smaller: fine
        $saved = $editor->save( $tmp . '-clean.' . $ext );
        @unlink( $tmp );
        if ( is_wp_error( $saved ) ) {
            return self::bad( 'This photo couldn\'t be saved. Try another one.' );
        }

        $id = media_handle_sideload( [ 'name' => $base . '.' . $ext, 'tmp_name' => $saved['path'] ], 0 );
        if ( is_wp_error( $id ) ) {
            @unlink( $saved['path'] );
            return self::bad( $id->get_error_message() );
        }
        // url: the product-size thumbnail; media: what the Design screen shows and previews.
        return [ 'id' => (int) $id, 'url' => self::image_url( $id, 'woocommerce_thumbnail' ), 'media' => Shop_Settings_Builder::platform_media_item( $id ) ];
    }

    /* ---------------- orders ---------------- */

    /** A page of orders (20), newest first, plus how many wait in each status. */
    private static function action_orders_list( array $params ) {
        $page   = max( 1, min( 1000, (int) ( $params['page'] ?? 1 ) ) );
        $filter = in_array( $params['filter'] ?? '', self::ORDER_FILTERS, true ) ? $params['filter'] : '';
        $search = mb_substr( sanitize_text_field( (string) ( $params['search'] ?? '' ) ), 0, 100 );
        $all    = array_map( static fn( $s ) => substr( $s, 3 ), array_keys( wc_get_order_statuses() ) );
        $status = $filter !== '' ? [ $filter ] : $all;

        if ( $search !== '' ) {
            // Order number, customer name, email, address… (WooCommerce's own order search).
            $ids    = array_map( 'intval', wc_order_search( $search ) );
            $orders = [];
            foreach ( $ids as $id ) {
                $order = wc_get_order( $id );
                if ( $order && $order->get_type() === 'shop_order' && in_array( $order->get_status(), $status, true ) ) {
                    $orders[] = $order;
                }
            }
            usort( $orders, static fn( $a, $b ) => $b->get_id() <=> $a->get_id() );
            $total  = count( $orders );
            $orders = array_slice( $orders, ( $page - 1 ) * 20, 20 );
            $pages  = (int) ceil( $total / 20 );
        } else {
            $result = wc_get_orders( [
                'type'     => 'shop_order',
                'status'   => $status,
                'limit'    => 20,
                'page'     => $page,
                'paginate' => true,
                'orderby'  => 'date',
                'order'    => 'DESC',
            ] );
            $orders = $result->orders;
            $total  = (int) $result->total;
            $pages  = (int) $result->max_num_pages;
        }

        $counts = [];
        foreach ( [ 'processing', 'on-hold', 'pending' ] as $s ) {
            $counts[ $s ] = (int) wc_orders_count( $s );
        }
        return [
            'items'  => array_map( [ __CLASS__, 'order_summary' ], $orders ),
            'total'  => $total,
            'pages'  => $pages,
            'page'   => $page,
            'counts' => $counts,
        ];
    }

    private static function action_order_get( array $params ) {
        $order = self::find_order( $params['id'] ?? 0 );
        return is_wp_error( $order ) ? $order : self::order_full( $order );
    }

    /** { id, status }: only the moves in ORDER_MOVES. WooCommerce emails the customer as usual. */
    private static function action_order_status( array $params ) {
        $order = self::find_order( $params['id'] ?? 0 );
        if ( is_wp_error( $order ) ) {
            return $order;
        }
        $to = (string) ( $params['status'] ?? '' );
        if ( ! in_array( $to, self::ORDER_MOVES[ $order->get_status() ] ?? [], true ) ) {
            return self::bad( 'This order can\'t be changed to that status.' );
        }
        $order->update_status( $to, 'Changed from the store dashboard.' );
        return self::order_full( wc_get_order( $order->get_id() ) );
    }

    /** { id, note, customer }: a private note, or a note emailed to the customer. */
    private static function action_order_note( array $params ) {
        $order = self::find_order( $params['id'] ?? 0 );
        if ( is_wp_error( $order ) ) {
            return $order;
        }
        $note = trim( sanitize_textarea_field( (string) ( $params['note'] ?? '' ) ) );
        if ( $note === '' || mb_strlen( $note ) > 2000 ) {
            return self::bad( 'Write a note (up to 2,000 characters).' );
        }
        $order->add_order_note( esc_html( $note ), ! empty( $params['customer'] ) ? 1 : 0 );
        return self::order_full( wc_get_order( $order->get_id() ) );
    }

    /**
     * { id, amount, reason, gateway, restock }. With gateway, the payment
     * provider refunds the customer (when it supports refunds); otherwise
     * the refund is only recorded and the owner returns the money. Items go
     * back into stock only with a full refund.
     */
    private static function action_order_refund( array $params ) {
        $order = self::find_order( $params['id'] ?? 0 );
        if ( is_wp_error( $order ) ) {
            return $order;
        }
        $left   = (float) $order->get_total() - (float) $order->get_total_refunded();
        $amount = self::price( $params['amount'] ?? '' );
        if ( $amount === null || $amount === '' || (float) $amount <= 0 ) {
            return self::bad( 'Enter the amount to refund.' );
        }
        if ( (float) $amount > round( $left, wc_get_price_decimals() ) + 0.00001 ) {
            return self::bad( 'That\'s more than what\'s left to refund (' . wc_format_decimal( $left, wc_get_price_decimals() ) . ').' );
        }
        $gateway = ! empty( $params['gateway'] );
        if ( $gateway && ! self::can_refund_online( $order ) ) {
            return self::bad( 'This payment method can\'t refund automatically. Record the refund and return the money yourself.' );
        }
        $full  = abs( (float) $amount - $left ) < 0.00001;
        $items = [];
        if ( $full && ! empty( $params['restock'] ) ) {
            foreach ( $order->get_items() as $item_id => $item ) {
                $items[ $item_id ] = [ 'qty' => $item->get_quantity(), 'refund_total' => 0 ];
            }
        }
        $refund = wc_create_refund( [
            'order_id'       => $order->get_id(),
            'amount'         => $amount,
            'reason'         => mb_substr( sanitize_text_field( (string) ( $params['reason'] ?? '' ) ), 0, 200 ),
            'refund_payment' => $gateway,
            'restock_items'  => (bool) $items,
            'line_items'     => $items,
        ] );
        if ( is_wp_error( $refund ) ) {
            return self::bad( 'The refund didn\'t go through: ' . $refund->get_error_message() );
        }
        return self::order_full( wc_get_order( $order->get_id() ) );
    }

    /* ---------------- design (the Shop Builder) ---------------- */

    private static function action_design_get() {
        return Shop_Settings_Builder::platform_design_data();
    }

    /** { options, pages }: sanitized by the Shop Builder itself, like Save Draft. */
    private static function action_design_save( array $params ) {
        $options = is_array( $params['options'] ?? null ) ? $params['options'] : [];
        $pages   = is_array( $params['pages'] ?? null ) ? $params['pages'] : [];
        return Shop_Settings_Builder::platform_save( self::to_arrays( $options ), self::to_arrays( $pages ) );
    }

    private static function action_design_publish() {
        $result = Shop_Settings_Builder::platform_publish();
        if ( isset( $result['error'] ) ) {
            $message = wp_strip_all_tags( (string) $result['error'] );
            // Messages written for wp-admin point at Technical Settings, which owners don't have.
            if ( stripos( $message, 'Technical Settings' ) !== false ) {
                $message = "Publishing couldn't reach your store's content. Try again in a minute; if it keeps failing, contact support.";
            }
            return self::bad( $message );
        }
        return [
            'summary'  => wp_strip_all_tags( (string) ( $result['summary'] ?? '' ) ),
            'warnings' => array_map( 'wp_strip_all_tags', array_map( 'strval', (array) ( $result['warnings'] ?? [] ) ) ),
            'failed'   => array_map( 'strval', (array) ( $result['failed_labels'] ?? [] ) ),
            'versions' => Shop_Settings_Builder::platform_revisions(),
        ];
    }

    /** { kind: products | categories | tags, term }. */
    private static function action_design_search( array $params ) {
        $kind = (string) ( $params['kind'] ?? '' );
        if ( ! in_array( $kind, [ 'products', 'categories', 'tags' ], true ) ) {
            return self::bad( 'Unknown search.' );
        }
        return [ 'items' => Shop_Settings_Builder::platform_search( $kind, $params['term'] ?? '' ) ];
    }

    /** { kind: image | video, page, search }: the Media Library. */
    private static function action_design_media( array $params ) {
        return Shop_Settings_Builder::platform_media( ( $params['kind'] ?? '' ) === 'video' ? 'video' : 'image', (int) ( $params['page'] ?? 1 ), $params['search'] ?? '' );
    }

    private static function action_design_versions() {
        return [ 'items' => Shop_Settings_Builder::platform_revisions() ];
    }

    private static function action_design_version( array $params ) {
        $id      = (string) ( $params['id'] ?? '' );
        $version = Shop_Settings_Builder::platform_valid_id( $id ) ? Shop_Settings_Builder::platform_revision( $id ) : null;
        return $version ?? self::error( 'qwoo_dashboard_not_found', 'That version doesn\'t exist any more.', 404 );
    }

    /** { name, section }. */
    private static function action_template_save( array $params ) {
        $result = Shop_Settings_Builder::platform_template_save( $params['name'] ?? '', is_array( $params['section'] ?? null ) ? self::to_arrays( $params['section'] ) : null );
        return is_string( $result ) ? self::bad( $result ) : [ 'items' => $result ];
    }

    private static function action_template_get( array $params ) {
        $id       = (string) ( $params['id'] ?? '' );
        $template = Shop_Settings_Builder::platform_valid_id( $id ) ? Shop_Settings_Builder::platform_template( $id ) : null;
        return $template ?? self::error( 'qwoo_dashboard_not_found', 'That template doesn\'t exist any more.', 404 );
    }

    private static function action_template_delete( array $params ) {
        $id = (string) ( $params['id'] ?? '' );
        if ( ! Shop_Settings_Builder::platform_valid_id( $id ) ) {
            return self::bad( 'Unknown template.' );
        }
        return [ 'items' => Shop_Settings_Builder::platform_template_delete( $id ) ];
    }

    /** { name, data (base64) }: an MP4 or WebM for video blocks and backgrounds. */
    private static function action_video_upload( array $params ) {
        $bytes = base64_decode( (string) ( $params['data'] ?? '' ), true );
        if ( $bytes === false || $bytes === '' || strlen( $bytes ) > self::MAX_VIDEO_BYTES ) {
            return self::bad( 'Videos can be up to 20 MB. Compress it and try again.' );
        }
        if ( ! class_exists( 'finfo' ) ) {
            return self::bad( 'Video uploads are not available on this server.' );
        }
        $mime  = ( new finfo( FILEINFO_MIME_TYPE ) )->buffer( $bytes );
        $types = [ 'video/mp4' => 'mp4', 'video/webm' => 'webm' ];
        if ( ! isset( $types[ $mime ] ) ) {
            return self::bad( 'Use an MP4 or WebM video.' );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $base = substr( sanitize_file_name( pathinfo( (string) ( $params['name'] ?? '' ), PATHINFO_FILENAME ) ), 0, 60 ) ?: 'video';
        $tmp  = wp_tempnam( $base . '.' . $types[ $mime ] );
        file_put_contents( $tmp, $bytes );
        $id = media_handle_sideload( [ 'name' => $base . '.' . $types[ $mime ], 'tmp_name' => $tmp ], 0 );
        if ( is_wp_error( $id ) ) {
            @unlink( $tmp );
            return self::bad( $id->get_error_message() );
        }
        return [ 'id' => (int) $id ] + (array) Shop_Settings_Builder::platform_media_item( $id );
    }

    /** JSON objects arrive as arrays already; this also turns stdClass leftovers into arrays. */
    private static function to_arrays( $value ) {
        return json_decode( wp_json_encode( $value ), true ) ?: [];
    }

    /* ---------------- settings ---------------- */

    /** Store details, checkout & emails and tax options, plus the lists the forms need. */
    private static function action_settings_get() {
        [ $country, $state ] = array_pad( explode( ':', (string) get_option( 'woocommerce_default_country', '' ) ), 2, '' );
        $new_order = (array) get_option( 'woocommerce_new_order_settings', [] );
        $currencies = [];
        foreach ( get_woocommerce_currencies() as $code => $name ) {
            $currencies[ $code ] = html_entity_decode( $name . ' (' . get_woocommerce_currency_symbol( $code ) . ')', ENT_QUOTES );
        }
        return [
            'store'    => [
                'name'           => html_entity_decode( get_option( 'blogname' ), ENT_QUOTES ),
                'email'          => (string) ( $new_order['recipient'] ?? '' ) ?: (string) get_option( 'admin_email' ),
                'address1'       => (string) get_option( 'woocommerce_store_address' ),
                'address2'       => (string) get_option( 'woocommerce_store_address_2' ),
                'city'           => (string) get_option( 'woocommerce_store_city' ),
                'postcode'       => (string) get_option( 'woocommerce_store_postcode' ),
                'country'        => $country,
                'state'          => $state,
                'currency'       => get_woocommerce_currency(),
                'weight_unit'    => (string) get_option( 'woocommerce_weight_unit', 'kg' ),
                'dimension_unit' => (string) get_option( 'woocommerce_dimension_unit', 'cm' ),
            ],
            'checkout' => [
                'guest_checkout'     => get_option( 'woocommerce_enable_guest_checkout' ) === 'yes',
                'signup_at_checkout' => get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) === 'yes',
                'signup_on_account'  => get_option( 'woocommerce_enable_myaccount_registration' ) === 'yes',
                'from_name'          => html_entity_decode( (string) get_option( 'woocommerce_email_from_name' ), ENT_QUOTES ),
                'from_email'         => (string) get_option( 'woocommerce_email_from_address' ),
            ],
            'taxes'    => [
                'enabled'            => wc_tax_enabled(),
                'prices_include_tax' => wc_prices_include_tax(),
                'based_on'           => (string) get_option( 'woocommerce_tax_based_on', 'shipping' ),
                'rates'              => self::tax_rates(),
            ],
            'lists'    => [
                'countries'       => array_map( static fn( $n ) => html_entity_decode( $n, ENT_QUOTES ), WC()->countries->get_countries() ),
                'states'          => self::states_of( $country ),
                'currencies'      => $currencies,
                'weight_units'    => [ 'kg', 'g', 'lbs', 'oz' ],
                'dimension_units' => [ 'm', 'cm', 'mm', 'in', 'yd' ],
            ],
        ];
    }

    /** { section: store | checkout | taxes, values }. */
    private static function action_settings_save( array $params ) {
        $v = is_array( $params['values'] ?? null ) ? $params['values'] : [];
        $text = static fn( $key, $max = 200 ) => mb_substr( trim( sanitize_text_field( (string) ( $v[ $key ] ?? '' ) ) ), 0, $max );
        $yes  = static fn( $key ) => ! empty( $v[ $key ] ) ? 'yes' : 'no';

        switch ( $params['section'] ?? '' ) {
            case 'store':
                $name    = $text( 'name', 100 );
                $email   = sanitize_email( (string) ( $v['email'] ?? '' ) );
                $country = strtoupper( $text( 'country', 2 ) );
                $state   = strtoupper( $text( 'state', 10 ) );
                if ( $name === '' ) {
                    return self::bad( 'Enter your business name.' );
                }
                if ( ! is_email( $email ) ) {
                    return self::bad( 'Enter a valid email for new-order notifications.' );
                }
                if ( ! isset( WC()->countries->get_countries()[ $country ] ) ) {
                    return self::bad( 'Choose your country.' );
                }
                if ( ! isset( get_woocommerce_currencies()[ strtoupper( $text( 'currency', 3 ) ) ] ) ) {
                    return self::bad( 'Choose a currency from the list.' );
                }
                $states = self::states_of( $country );
                if ( $states && ! isset( $states[ $state ] ) ) {
                    return self::bad( 'Choose your state or region.' );
                }
                $saved = self::wc_settings( [
                    'general'         => [
                        'woocommerce_store_address'   => $text( 'address1' ),
                        'woocommerce_store_address_2' => $text( 'address2' ),
                        'woocommerce_store_city'      => $text( 'city', 100 ),
                        'woocommerce_store_postcode'  => $text( 'postcode', 20 ),
                        'woocommerce_default_country' => $states ? "$country:$state" : $country,
                        'woocommerce_currency'        => strtoupper( $text( 'currency', 3 ) ),
                    ],
                    'products'        => [
                        'woocommerce_weight_unit'    => $text( 'weight_unit', 5 ),
                        'woocommerce_dimension_unit' => $text( 'dimension_unit', 5 ),
                    ],
                    'email_new_order' => [ 'recipient' => $email ],
                ] );
                if ( is_wp_error( $saved ) ) {
                    return $saved;
                }
                update_option( 'blogname', $name );
                break;

            case 'checkout':
                $from = sanitize_email( (string) ( $v['from_email'] ?? '' ) );
                if ( ! is_email( $from ) ) {
                    return self::bad( 'Enter a valid sender email.' );
                }
                if ( $text( 'from_name', 100 ) === '' ) {
                    return self::bad( 'Enter the sender name for customer emails.' );
                }
                $saved = self::wc_settings( [
                    'account' => [
                        'woocommerce_enable_guest_checkout'                 => $yes( 'guest_checkout' ),
                        'woocommerce_enable_signup_and_login_from_checkout' => $yes( 'signup_at_checkout' ),
                        'woocommerce_enable_myaccount_registration'         => $yes( 'signup_on_account' ),
                    ],
                    'email'   => [
                        'woocommerce_email_from_name'    => $text( 'from_name', 100 ),
                        'woocommerce_email_from_address' => $from,
                    ],
                ] );
                if ( is_wp_error( $saved ) ) {
                    return $saved;
                }
                break;

            case 'taxes':
                $saved = self::wc_settings( [ 'general' => [ 'woocommerce_calc_taxes' => $yes( 'enabled' ) ] ] );
                if ( is_wp_error( $saved ) ) {
                    return $saved;
                }
                // WooCommerce only offers its "tax" settings group while taxes are on, so these
                // two (checked here: yes/no and a fixed list) are saved directly.
                update_option( 'woocommerce_prices_include_tax', $yes( 'prices_include_tax' ) );
                update_option( 'woocommerce_tax_based_on', in_array( $v['based_on'] ?? '', [ 'shipping', 'billing', 'base' ], true ) ? $v['based_on'] : 'shipping' );
                break;

            default:
                return self::bad( 'Unknown settings section.' );
        }
        return self::action_settings_get();
    }

    private static function action_states( array $params ) {
        return [ 'states' => self::states_of( strtoupper( substr( (string) ( $params['country'] ?? '' ), 0, 2 ) ) ) ];
    }

    /** { id?, country, state, rate, name, shipping } in the standard tax class. */
    private static function action_tax_rate_save( array $params ) {
        $country = strtoupper( substr( sanitize_text_field( (string) ( $params['country'] ?? '' ) ), 0, 2 ) );
        $state   = strtoupper( substr( sanitize_text_field( (string) ( $params['state'] ?? '' ) ), 0, 10 ) );
        $rate    = trim( (string) ( $params['rate'] ?? '' ) );
        if ( $country !== '' && ! isset( WC()->countries->get_countries()[ $country ] ) ) {
            return self::bad( 'Choose a country (or "Everywhere").' );
        }
        if ( ! preg_match( '/^\d{1,3}(\.\d{1,4})?$/', $rate ) || (float) $rate > 100 ) {
            return self::bad( 'The rate must be a percentage between 0 and 100, like 17 or 7.5.' );
        }
        $body = [
            'country'  => $country,
            'state'    => $state,
            'rate'     => $rate,
            'name'     => mb_substr( trim( sanitize_text_field( (string) ( $params['name'] ?? '' ) ) ), 0, 50 ) ?: 'Tax',
            'shipping' => ! empty( $params['shipping'] ),
            'class'    => 'standard',
        ];
        $id     = absint( $params['id'] ?? 0 );
        $result = $id ? self::wc_rest( 'PUT', "/wc/v3/taxes/$id", $body ) : self::wc_rest( 'POST', '/wc/v3/taxes', $body );
        return is_wp_error( $result ) ? $result : [ 'rates' => self::tax_rates() ];
    }

    private static function action_tax_rate_delete( array $params ) {
        $id     = absint( $params['id'] ?? 0 );
        $result = $id ? self::wc_rest( 'DELETE', "/wc/v3/taxes/$id", [ 'force' => true ] ) : self::bad( 'Unknown tax rate.' );
        return is_wp_error( $result ) ? $result : [ 'rates' => self::tax_rates() ];
    }

    /* ---------------- shipping ---------------- */

    /** Zones (in order, "everywhere else" last) with their countries and methods. */
    private static function action_shipping_get() {
        $zones = [];
        foreach ( array_merge( array_column( WC_Shipping_Zones::get_zones(), 'id' ), [ 0 ] ) as $zone_id ) {
            $zone      = new WC_Shipping_Zone( (int) $zone_id );
            $countries = [];
            $other     = 0;
            foreach ( $zone->get_zone_locations() as $location ) {
                if ( $location->type === 'country' ) {
                    $countries[] = $location->code;
                } else {
                    $other++; // states, postcodes, continents: kept as they are
                }
            }
            $methods = [];
            foreach ( $zone->get_shipping_methods( false ) as $method ) {
                $methods[] = [
                    'instance_id' => (int) $method->instance_id,
                    'method_id'   => $method->id,
                    'title'       => html_entity_decode( (string) $method->get_title(), ENT_QUOTES ),
                    'enabled'     => $method->is_enabled(),
                    'cost'        => (string) $method->get_instance_option( 'cost', '' ),
                    'min_amount'  => (string) $method->get_instance_option( 'min_amount', '' ),
                    'requires'    => (string) $method->get_instance_option( 'requires', '' ),
                    'editable'    => in_array( $method->id, self::SHIPPING_METHODS, true ),
                ];
            }
            $zones[] = [
                'id'        => $zone->get_id(),
                'name'      => $zone->get_id() ? html_entity_decode( $zone->get_zone_name(), ENT_QUOTES ) : 'Everywhere else',
                'countries' => $countries,
                'other'     => $other,
                'methods'   => $methods,
            ];
        }
        return [
            'zones'     => $zones,
            'countries' => array_map( static fn( $n ) => html_entity_decode( $n, ENT_QUOTES ), WC()->countries->get_countries() ),
            'currency'  => get_woocommerce_currency(),
        ];
    }

    /** { id?, name, countries[] }. Regions other than whole countries are kept. */
    private static function action_zone_save( array $params ) {
        $name = mb_substr( trim( sanitize_text_field( (string) ( $params['name'] ?? '' ) ) ), 0, 100 );
        if ( $name === '' ) {
            return self::bad( 'Give the shipping zone a name, like "Domestic".' );
        }
        $all       = WC()->countries->get_countries();
        $countries = array_values( array_unique( array_filter(
            array_map( static fn( $c ) => strtoupper( substr( (string) $c, 0, 2 ) ), (array) ( $params['countries'] ?? [] ) ),
            static fn( $c ) => isset( $all[ $c ] )
        ) ) );
        if ( ! $countries ) {
            return self::bad( 'Choose at least one country for this zone.' );
        }
        $id = absint( $params['id'] ?? 0 );
        if ( $id && ! self::zone_exists( $id ) ) {
            return self::error( 'qwoo_dashboard_not_found', 'This shipping zone doesn\'t exist any more.', 404 );
        }
        $zone = new WC_Shipping_Zone( $id ?: null );
        $zone->set_zone_name( $name );
        $zone->clear_locations( [ 'country' ] ); // states, postcodes… stay
        foreach ( $countries as $code ) {
            $zone->add_location( $code, 'country' );
        }
        $zone->save();
        return self::action_shipping_get();
    }

    private static function action_zone_delete( array $params ) {
        $id = absint( $params['id'] ?? 0 );
        if ( ! $id || ! self::zone_exists( $id ) ) {
            return self::bad( 'This zone can\'t be deleted.' );
        }
        ( new WC_Shipping_Zone( $id ) )->delete();
        return self::action_shipping_get();
    }

    /** { zone_id, instance_id?, method_id, title, enabled, cost, min_amount }. */
    private static function action_method_save( array $params ) {
        $zone_id = absint( $params['zone_id'] ?? 0 );
        if ( $zone_id && ! self::zone_exists( $zone_id ) ) {
            return self::error( 'qwoo_dashboard_not_found', 'This shipping zone doesn\'t exist any more.', 404 );
        }
        $zone        = new WC_Shipping_Zone( $zone_id );
        $instance_id = absint( $params['instance_id'] ?? 0 );
        if ( $instance_id ) {
            $method = self::zone_method( $zone, $instance_id );
            if ( ! $method ) {
                return self::error( 'qwoo_dashboard_not_found', 'This shipping method doesn\'t exist any more.', 404 );
            }
            $type = $method->id;
        } else {
            $type = (string) ( $params['method_id'] ?? '' );
        }
        if ( ! in_array( $type, self::SHIPPING_METHODS, true ) ) {
            return self::bad( 'This shipping method can\'t be edited here.' );
        }

        $settings = [ 'title' => mb_substr( trim( sanitize_text_field( (string) ( $params['title'] ?? '' ) ) ), 0, 100 ) ];
        if ( $settings['title'] === '' ) {
            return self::bad( 'Give the method a name customers will see, like "Standard delivery".' );
        }
        if ( $type === 'flat_rate' || $type === 'local_pickup' ) {
            $cost = self::price( $params['cost'] ?? '' );
            if ( $cost === null ) {
                return self::bad( 'The cost must be a number, like 25 or 9.90.' );
            }
            $settings['cost'] = $cost;
        }
        if ( $type === 'free_shipping' ) {
            $min = self::price( $params['min_amount'] ?? '' );
            if ( $min === null ) {
                return self::bad( 'The minimum order must be a number.' );
            }
            $settings['requires']   = $min === '' || (float) $min <= 0 ? '' : 'min_amount';
            $settings['min_amount'] = $min;
        }

        if ( ! $instance_id ) {
            $instance_id = $zone->add_shipping_method( $type );
            if ( ! $instance_id ) {
                return self::bad( 'The shipping method couldn\'t be added.' );
            }
        }
        $method = self::zone_method( new WC_Shipping_Zone( $zone_id ), $instance_id );
        $method->init_instance_settings();
        update_option( $method->get_instance_option_key(), array_merge( (array) $method->instance_settings, $settings ), 'yes' );

        global $wpdb;
        $enabled = ! array_key_exists( 'enabled', $params ) || ! empty( $params['enabled'] );
        if ( $wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", [ 'is_enabled' => (int) $enabled ], [ 'instance_id' => $instance_id ] ) ) {
            do_action( 'woocommerce_shipping_zone_method_status_toggled', $instance_id, $type, $zone_id, (int) $enabled );
        }
        WC_Cache_Helper::get_transient_version( 'shipping', true );
        return self::action_shipping_get();
    }

    private static function action_method_delete( array $params ) {
        $zone_id     = absint( $params['zone_id'] ?? 0 );
        $instance_id = absint( $params['instance_id'] ?? 0 );
        if ( $zone_id && ! self::zone_exists( $zone_id ) ) {
            return self::bad( 'This shipping zone doesn\'t exist any more.' );
        }
        $zone = new WC_Shipping_Zone( $zone_id );
        if ( ! self::zone_method( $zone, $instance_id ) ) {
            return self::bad( 'This shipping method doesn\'t exist any more.' );
        }
        $zone->delete_shipping_method( $instance_id );
        return self::action_shipping_get();
    }

    /* ---------------- settings helpers ---------------- */

    private static function zone_exists( $id ) {
        foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
            if ( (int) $zone['id'] === (int) $id ) {
                return true;
            }
        }
        return false;
    }

    private static function zone_method( WC_Shipping_Zone $zone, $instance_id ) {
        foreach ( $zone->get_shipping_methods( false ) as $method ) {
            if ( (int) $method->instance_id === (int) $instance_id ) {
                return $method;
            }
        }
        return null;
    }

    private static function states_of( $country ) {
        $states = $country !== '' ? WC()->countries->get_states( $country ) : [];
        return is_array( $states ) ? array_map( static fn( $n ) => html_entity_decode( $n, ENT_QUOTES ), $states ) : [];
    }

    private static function tax_rates() {
        $rates = [];
        foreach ( WC_Tax::get_rates_for_tax_class( '' ) as $r ) {
            $rates[] = [
                'id'       => (int) $r->tax_rate_id,
                'country'  => (string) $r->tax_rate_country,
                'state'    => (string) $r->tax_rate_state,
                'rate'     => rtrim( rtrim( (string) $r->tax_rate, '0' ), '.' ) ?: '0',
                'name'     => (string) $r->tax_rate_name,
                'shipping' => (bool) $r->tax_rate_shipping,
            ];
        }
        return $rates;
    }

    /**
     * Saves WooCommerce settings through its own settings API (which checks
     * each value: known currencies, countries, units…). $groups:
     * [ group => [ setting id => value ] ].
     */
    private static function wc_settings( array $groups ) {
        foreach ( $groups as $group => $values ) {
            $update = [];
            foreach ( $values as $id => $value ) {
                $update[] = [ 'id' => $id, 'value' => $value ];
            }
            $result = self::wc_rest( 'POST', "/wc/v3/settings/$group/batch", [ 'update' => $update ] );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            foreach ( (array) ( $result['update'] ?? [] ) as $item ) {
                if ( ! empty( $item['error'] ) ) {
                    return self::bad( 'This setting couldn\'t be saved: ' . ( $item['error']['message'] ?? $item['id'] ?? '' ) );
                }
            }
        }
        return true;
    }

    /**
     * Calls a WooCommerce REST route inside this request, as the site's
     * administrator. Only used with the fixed routes in this class: the
     * dashboard's own checks happen before, WooCommerce's validation here.
     */
    private static function wc_rest( $method, $route, array $body = [] ) {
        $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ] );
        if ( ! $admins ) {
            return self::error( 'qwoo_dashboard_no_admin', 'This store has no administrator account.', 500 );
        }
        $previous = get_current_user_id();
        wp_set_current_user( (int) $admins[0] );
        try {
            $request = new WP_REST_Request( $method, $route );
            if ( $method === 'GET' || $method === 'DELETE' ) {
                $request->set_query_params( $body );
            } else {
                $request->set_header( 'Content-Type', 'application/json' );
                $request->set_body( wp_json_encode( $body ) );
            }
            $response = rest_do_request( $request );
        } finally {
            wp_set_current_user( $previous );
        }
        $data = rest_get_server()->response_to_data( $response, false );
        if ( $response->is_error() ) {
            $message = is_array( $data ) && ! empty( $data['message'] ) ? wp_strip_all_tags( $data['message'] ) : 'WooCommerce refused this change.';
            return self::bad( $message );
        }
        return $data;
    }

    /* ---------------- order helpers ---------------- */

    private static function find_order( $id ) {
        $order = absint( $id ) ? wc_get_order( absint( $id ) ) : false;
        if ( ! $order || $order->get_type() !== 'shop_order' || $order->get_status() === 'checkout-draft' ) {
            return self::error( 'qwoo_dashboard_not_found', 'This order doesn\'t exist.', 404 );
        }
        return $order;
    }

    private static function can_refund_online( WC_Order $order ) {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        $gateway  = $gateways[ $order->get_payment_method() ] ?? null;
        return $gateway && $gateway->supports( 'refunds' ) && $gateway->can_refund_order( $order );
    }

    private static function order_summary( WC_Order $o ) {
        $created = $o->get_date_created();
        return [
            'id'       => $o->get_id(),
            'number'   => (string) $o->get_order_number(),
            'date'     => $created ? $created->getTimestamp() : null,
            'status'   => $o->get_status(),
            'label'    => wc_get_order_status_name( $o->get_status() ),
            'total'    => (float) $o->get_total(),
            'refunded' => (float) $o->get_total_refunded(),
            'currency' => $o->get_currency(),
            'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
            'items'    => $o->get_item_count(),
        ];
    }

    private static function order_full( WC_Order $o ) {
        $items = [];
        foreach ( $o->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            $meta    = [];
            foreach ( $item->get_all_formatted_meta_data() as $m ) {
                $meta[] = wp_strip_all_tags( $m->display_key ) . ': ' . wp_strip_all_tags( $m->display_value );
            }
            $items[] = [
                'name'     => html_entity_decode( $item->get_name(), ENT_QUOTES ),
                'qty'      => (int) $item->get_quantity(),
                'total'    => (float) $item->get_total() + (float) $item->get_total_tax(),
                'sku'      => $product ? $product->get_sku() : '',
                'image'    => $product ? self::image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : '',
                'product'  => $product ? ( $product->get_parent_id() ?: $product->get_id() ) : 0,
                'meta'     => $meta,
            ];
        }
        $notes = [];
        foreach ( wc_get_order_notes( [ 'order_id' => $o->get_id() ] ) as $note ) {
            $notes[] = [
                'id'       => (int) $note->id,
                'text'     => html_entity_decode( wp_strip_all_tags( $note->content ), ENT_QUOTES ),
                'customer' => (bool) $note->customer_note,
                'date'     => $note->date_created ? $note->date_created->getTimestamp() : null,
            ];
        }
        $refunds = [];
        foreach ( $o->get_refunds() as $refund ) {
            $date      = $refund->get_date_created();
            $refunds[] = [ 'amount' => (float) $refund->get_amount(), 'reason' => $refund->get_reason(), 'date' => $date ? $date->getTimestamp() : null ];
        }
        $address = static function ( array $fields ) {
            $text = WC()->countries->get_formatted_address( $fields, "\n" );
            return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES ) );
        };
        $paid = $o->get_date_paid();
        return self::order_summary( $o ) + [
            'moves'        => array_map( static fn( $s ) => [ 'status' => $s, 'label' => wc_get_order_status_name( $s ) ], self::ORDER_MOVES[ $o->get_status() ] ?? [] ),
            'subtotal'     => (float) $o->get_subtotal(),
            'shipping'     => (float) $o->get_shipping_total() + (float) $o->get_shipping_tax(),
            'discount'     => (float) $o->get_discount_total(),
            'tax'          => (float) $o->get_total_tax(),
            'payment'      => $o->get_payment_method_title(),
            'paid'         => $paid ? $paid->getTimestamp() : null,
            'online_refund' => self::can_refund_online( $o ),
            'shipping_via' => $o->get_shipping_method(),
            'email'        => $o->get_billing_email(),
            'phone'        => $o->get_billing_phone(),
            'billing'      => $address( $o->get_address( 'billing' ) ),
            'ship_to'      => $o->has_shipping_address() ? $address( $o->get_address( 'shipping' ) ) : '',
            'customer_note' => $o->get_customer_note(),
            'line_items'   => $items,
            'notes'        => $notes,
            'refunds'      => $refunds,
        ];
    }

    /* ---------------- storefront address ---------------- */

    /**
     * { url }: the storefront's address (the owner's own domain once it's
     * connected, or back to *.vercel.app). Used for email links, SEO
     * addresses and the Design preview.
     */
    private static function action_frontend_domain_set( array $params ) {
        $url   = esc_url_raw( (string) ( $params['url'] ?? '' ), [ 'https' ] );
        $parts = wp_parse_url( $url );
        if ( ! $url || empty( $parts['host'] ) || ! empty( $parts['path'] ) && $parts['path'] !== '/' || isset( $parts['query'] ) || isset( $parts['user'] ) || isset( $parts['port'] ) ) {
            return self::bad( 'Invalid storefront address.' );
        }
        $settings                    = get_option( 'qwoo_technical_settings', [] );
        $settings                    = is_array( $settings ) ? $settings : [];
        $settings['frontend_domain'] = 'https://' . strtolower( $parts['host'] );
        update_option( 'qwoo_technical_settings', $settings );
        return [ 'frontend' => $settings['frontend_domain'] ];
    }

    /* ---------------- product helpers ---------------- */

    private static function find_product( $id ) {
        $id      = absint( $id );
        $product = $id && get_post_type( $id ) === 'product' ? wc_get_product( $id ) : null;
        if ( ! $product || ! in_array( $product->get_status(), [ 'publish', 'draft', 'pending', 'private' ], true ) ) {
            return self::error( 'qwoo_dashboard_not_found', 'This product doesn\'t exist any more.', 404 );
        }
        return $product;
    }

    private static function product_summary( WC_Product $p ) {
        $variable = $p->is_type( 'variable' );
        return [
            'id'           => $p->get_id(),
            'name'         => html_entity_decode( $p->get_name(), ENT_QUOTES ),
            'status'       => $p->get_status(),
            'type'         => $p->get_type(),
            'price'        => (string) $p->get_price(),
            'price_max'    => $variable ? (string) $p->get_variation_price( 'max' ) : (string) $p->get_price(),
            // Before the sale (a range for products with options), when on sale.
            'on_sale'      => $p->is_on_sale(),
            'regular_min'  => $variable ? (string) $p->get_variation_regular_price( 'min' ) : (string) $p->get_regular_price(),
            'regular_max'  => $variable ? (string) $p->get_variation_regular_price( 'max' ) : (string) $p->get_regular_price(),
            'regular'      => (string) $p->get_regular_price(),
            'sale'         => (string) $p->get_sale_price(),
            'stock'        => $p->get_stock_status(),
            'quantity'     => $p->managing_stock() ? $p->get_stock_quantity() : null,
            'combinations' => $variable ? count( $p->get_children() ) : 0,
            'image'        => self::image_url( $p->get_image_id(), 'woocommerce_thumbnail' ),
        ];
    }

    /**
     * The options of a product with options, as the editor uses them:
     * attributes [ { name, options: [ choice names ], shared } ] and
     * variations [ { id, options: { name: choice name ('' = any) }, regular,
     * sale, sku, manage_stock, quantity, stock, image_id, enabled } ].
     */
    private static function product_options( WC_Product $p ) {
        if ( ! $p->is_type( 'variable' ) ) {
            return [ 'attributes' => [], 'variations' => [] ];
        }
        $attributes = [];
        $lookup     = []; // attribute key => [ name, [ stored value (sanitized) => choice name ] ]
        foreach ( $p->get_attributes() as $key => $attr ) {
            if ( ! $attr->get_variation() ) {
                continue;
            }
            $choices = [];
            if ( $attr->is_taxonomy() ) {
                $name = wc_attribute_label( $attr->get_name() );
                foreach ( $attr->get_terms() ?: [] as $term ) {
                    $choices[ $term->slug ] = html_entity_decode( $term->name, ENT_QUOTES );
                }
            } else {
                $name = $attr->get_name();
                foreach ( $attr->get_options() as $option ) {
                    $choices[ sanitize_title( $option ) ] = $option;
                }
            }
            $attributes[]   = [ 'name' => $name, 'options' => array_values( $choices ), 'shared' => $attr->is_taxonomy() ];
            $lookup[ $key ] = [ $name, $choices ];
        }
        $variations = [];
        foreach ( $p->get_children() as $child ) {
            $v = wc_get_product( $child );
            if ( ! $v ) {
                continue;
            }
            $options = [];
            foreach ( $lookup as $key => [ $name, $choices ] ) {
                $stored           = (string) ( $v->get_attributes( 'edit' )[ $key ] ?? '' );
                $options[ $name ] = $stored === '' ? '' : ( $choices[ $stored ] ?? $choices[ sanitize_title( $stored ) ] ?? $stored );
            }
            $variations[] = [
                'id'           => $v->get_id(),
                'options'      => $options,
                'regular'      => (string) $v->get_regular_price( 'edit' ),
                'sale'         => (string) $v->get_sale_price( 'edit' ),
                'sku'          => (string) $v->get_sku( 'edit' ),
                'manage_stock' => $v->get_manage_stock( 'edit' ) === true,
                'quantity'     => $v->get_manage_stock( 'edit' ) === true ? $v->get_stock_quantity() : null,
                'stock'        => $v->get_stock_status( 'edit' ),
                'image_id'     => (int) $v->get_image_id( 'edit' ),
                'image'        => self::image_url( $v->get_image_id( 'edit' ), 'woocommerce_thumbnail' ),
                'enabled'      => $v->get_status( 'edit' ) === 'publish',
            ];
        }
        return [ 'attributes' => $attributes, 'variations' => $variations ];
    }

    private static function product_full( WC_Product $p ) {
        $images = [];
        foreach ( array_merge( [ $p->get_image_id() ], $p->get_gallery_image_ids() ) as $id ) {
            $url = self::image_url( $id, 'woocommerce_thumbnail' );
            if ( $url !== '' ) {
                $images[] = [ 'id' => (int) $id, 'url' => $url ];
            }
        }
        $description = (string) $p->get_description();
        return self::product_summary( $p ) + self::product_options( $p ) + [
            'editable'     => $p->is_type( self::EDITABLE_TYPES ),
            'sku'          => $p->get_sku(),
            'manage_stock' => $p->managing_stock(),
            'categories'   => array_map( 'intval', $p->get_category_ids() ),
            'images'       => $images,
            'description'  => self::to_text( $description ),
            // Formatting beyond paragraphs (lists, bold…) would be lost if
            // the owner edits the text here; the app says so.
            'formatted'    => (bool) preg_match( '/<(?!\/?(p|br)\b)[a-z]/i', $description ),
            'currency'     => get_woocommerce_currency(),
            'decimals'     => wc_get_price_decimals(),
            // The address on the storefront, shown decoded ("טבעת-זהב").
            'slug'         => urldecode( (string) get_post_field( 'post_name', $p->get_id() ) ),
            // Drafts get their address when first published.
            'url'          => get_post_field( 'post_name', $p->get_id() ) !== '' ? Qwoo_Seo::url( '/product/' . get_post_field( 'post_name', $p->get_id() ) ) : '',
            'seo'          => Qwoo_Seo::for_editor( $p->get_id(), 'post' ),
            'seo_defaults' => Qwoo_Seo::product_defaults( $p ),
        ];
    }

    private static function image_url( $id, $size ) {
        $url = $id ? wp_get_attachment_image_url( (int) $id, $size ) : false;
        return $url ? (string) $url : '';
    }

    /** '' stays '', "24.9" → "24.9"; null when it isn't a price. */
    private static function price( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) {
            return '';
        }
        return preg_match( '/^\d{1,9}(\.\d{1,4})?$/', $value ) ? wc_format_decimal( $value ) : null;
    }

    /** The owner edits descriptions as plain text: paragraphs and line breaks. */
    private static function to_text( $html ) {
        $text = preg_replace( [ '/<br\s*\/?>/i', '/<\/p>\s*/i' ], [ "\n", "\n\n" ], (string) $html );
        return trim( html_entity_decode( wp_strip_all_tags( $text, false ), ENT_QUOTES ) );
    }

    private static function from_text( $text ) {
        $text = trim( str_replace( "\r\n", "\n", (string) $text ) );
        return $text === '' ? '' : wpautop( esc_html( $text ) );
    }

    private static function bad( $message ) {
        return self::error( 'qwoo_dashboard_invalid', $message, 400 );
    }
}

Qwoo_Platform_Dashboard::init();

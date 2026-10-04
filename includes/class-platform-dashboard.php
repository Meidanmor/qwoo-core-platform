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
        'image_upload'    => 'action_image_upload',
        'orders_list'     => 'action_orders_list',
        'order_get'       => 'action_order_get',
        'order_status'    => 'action_order_status',
        'order_note'      => 'action_order_note',
        'order_refund'    => 'action_order_refund',
    ];

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
     * Creates (no id) or updates a simple product from params.fields:
     * name, status, regular_price, sale_price, sku, manage_stock,
     * stock_quantity, stock_status, description (plain text; left alone
     * when missing), category_ids, image_ids (the first is the main photo).
     */
    private static function action_product_save( array $params ) {
        $id = absint( $params['id'] ?? 0 );
        if ( $id ) {
            $product = self::find_product( $id );
            if ( is_wp_error( $product ) ) {
                return $product;
            }
            if ( ! $product->is_type( 'simple' ) ) {
                return self::bad( 'This kind of product can\'t be edited here yet.' );
            }
        } else {
            $product = new WC_Product_Simple();
        }
        $f = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];

        $name = trim( sanitize_text_field( (string) ( $f['name'] ?? '' ) ) );
        if ( $name === '' || mb_strlen( $name ) > 200 ) {
            return self::bad( 'Give the product a name (up to 200 characters).' );
        }
        $status  = in_array( $f['status'] ?? '', self::PRODUCT_STATUSES, true ) ? $f['status'] : 'draft';
        $regular = self::price( $f['regular_price'] ?? '' );
        $sale    = self::price( $f['sale_price'] ?? '' );
        if ( $regular === null || $sale === null ) {
            return self::bad( 'Prices must be numbers, like 24.90.' );
        }
        if ( $status === 'publish' && $regular === '' ) {
            return self::bad( 'Add a price before publishing the product.' );
        }
        if ( $sale !== '' && ( $regular === '' || (float) $sale >= (float) $regular ) ) {
            return self::bad( 'The sale price must be lower than the regular price.' );
        }

        $manage   = ! empty( $f['manage_stock'] );
        $quantity = $manage ? filter_var( $f['stock_quantity'] ?? null, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => -999999, 'max_range' => 9999999 ] ] ) : null;
        if ( $manage && $quantity === false ) {
            return self::bad( 'Stock must be a whole number.' );
        }

        $sku = wc_clean( (string) ( $f['sku'] ?? '' ) );
        if ( mb_strlen( $sku ) > 100 ) {
            return self::bad( 'The SKU can be up to 100 characters.' );
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
        $categories = array_values( array_unique( array_map( 'absint', (array) ( $f['category_ids'] ?? [] ) ) ) );
        foreach ( $categories as $category ) {
            $term = get_term( $category, 'product_cat' );
            if ( ! $term || is_wp_error( $term ) ) {
                return self::bad( 'One of the categories doesn\'t exist any more.' );
            }
        }

        try {
            $product->set_name( $name );
            $product->set_status( $status );
            $product->set_regular_price( $regular );
            $product->set_sale_price( $sale );
            $product->set_sku( $sku ); // throws for an SKU another product has
            $product->set_manage_stock( $manage );
            if ( $manage ) {
                $product->set_stock_quantity( $quantity );
            } else {
                $product->set_stock_status( ( $f['stock_status'] ?? '' ) === 'outofstock' ? 'outofstock' : 'instock' );
            }
            if ( $description !== null ) {
                $product->set_description( self::from_text( $description ) );
            }
            $product->set_category_ids( $categories );
            $product->set_image_id( $images[0] ?? 0 );
            $product->set_gallery_image_ids( array_slice( $images, 1 ) );
            $product->save();
        } catch ( WC_Data_Exception $e ) {
            return self::bad( $e->getMessage() );
        }
        return self::product_full( wc_get_product( $product->get_id() ) );
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
            $out[] = [ 'id' => (int) $term->term_id, 'name' => html_entity_decode( $term->name, ENT_QUOTES ), 'parent' => (int) $term->parent, 'count' => (int) $term->count, 'default' => (int) $term->term_id === $default ];
        }
        return [ 'items' => $out ];
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
        return [ 'id' => (int) $id, 'url' => self::image_url( $id, 'woocommerce_thumbnail' ) ];
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
        return [
            'id'       => $p->get_id(),
            'name'     => html_entity_decode( $p->get_name(), ENT_QUOTES ),
            'status'   => $p->get_status(),
            'type'     => $p->get_type(),
            'price'    => (string) $p->get_price(),
            'regular'  => (string) $p->get_regular_price(),
            'sale'     => (string) $p->get_sale_price(),
            'stock'    => $p->get_stock_status(),
            'quantity' => $p->managing_stock() ? $p->get_stock_quantity() : null,
            'image'    => self::image_url( $p->get_image_id(), 'woocommerce_thumbnail' ),
        ];
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
        return self::product_summary( $p ) + [
            'editable'     => $p->is_type( 'simple' ),
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

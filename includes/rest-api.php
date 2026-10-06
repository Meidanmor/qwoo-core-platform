<?php
// The SEO endpoints (qwoo/v1/seo, qwoo/v1/sitemap) live in class-seo.php.

add_action('rest_api_init', function () {

    register_rest_route('qwoo/v1', '/contact-options', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $methods = class_exists('Shop_Settings_Builder')
                ? Shop_Settings_Builder::get_enabled_contact_methods()
                : [];

            return rest_ensure_response([
                'enabled' => ! empty( $methods ),
                'methods' => $methods,
            ]);
        },
    ]);

    register_rest_route('qwoo/v1', '/products-meta', [
        'methods' => 'GET',

        'callback' => function ($request) {

            global $wpdb;

            $table = $wpdb->prefix . 'wc_product_meta_lookup';
            $posts_table = $wpdb->posts;

            $category_param = $request->get_param('category');

            // ─────────────────────────────────────────
            // GLOBAL SHOP META
            // ─────────────────────────────────────────

            if (!$category_param) {

                $global = $wpdb->get_row("
                    SELECT
                        MIN(pml.min_price) AS min_price,
                        MAX(pml.max_price) AS max_price

                    FROM {$table} pml

                    INNER JOIN {$posts_table} p
                        ON pml.product_id = p.ID

                    WHERE pml.stock_status = 'instock'
                    AND p.post_status = 'publish'
                    AND p.post_type = 'product'
                ");

                return [
                    'global' => [
                        'min_price' => floatval($global->min_price),
                        'max_price' => floatval($global->max_price),
                    ]
                ];
            }

            // ─────────────────────────────────────────
            // ALL CATEGORIES
            // ─────────────────────────────────────────

            if ($category_param === 'all') {

                $results = $wpdb->get_results("
                    SELECT
                        tt.term_id as category_id,
                        MIN(pml.min_price) AS min_price,
                        MAX(pml.max_price) AS max_price

                    FROM {$wpdb->term_relationships} tr

                    INNER JOIN {$wpdb->term_taxonomy} tt
                        ON tr.term_taxonomy_id = tt.term_taxonomy_id

                    INNER JOIN {$table} pml
                        ON tr.object_id = pml.product_id

                    INNER JOIN {$posts_table} p
                        ON pml.product_id = p.ID

                    WHERE tt.taxonomy = 'product_cat'
                    AND pml.stock_status = 'instock'
                    AND p.post_status = 'publish'
                    AND p.post_type = 'product'

                    GROUP BY tt.term_id
                ");

                $formatted = [];

                foreach ($results as $row) {

                    $formatted[$row->category_id] = [
                        'min_price' => floatval($row->min_price),
                        'max_price' => floatval($row->max_price),
                    ];
                }

                // Global archive min/max

                $global = $wpdb->get_row("
                    SELECT
                        MIN(pml.min_price) AS min_price,
                        MAX(pml.max_price) AS max_price

                    FROM {$table} pml

                    INNER JOIN {$posts_table} p
                        ON pml.product_id = p.ID

                    WHERE pml.stock_status = 'instock'
                    AND p.post_status = 'publish'
                    AND p.post_type = 'product'
                ");

                return [
                    'global' => [
                        'min_price' => floatval($global->min_price),
                        'max_price' => floatval($global->max_price),
                    ],

                    'categories' => $formatted
                ];
            }

            // ─────────────────────────────────────────
            // SPECIFIC CATEGORY
            // ─────────────────────────────────────────

            $category_ids = is_array($category_param)
                ? $category_param
                : explode(',', $category_param);

            $category_ids = array_map('intval', $category_ids);
            $category_ids = array_filter($category_ids);

            if (empty($category_ids)) {

                return new WP_Error(
                    'invalid_category',
                    'Invalid category parameter',
                    ['status' => 400]
                );
            }

            $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));

            $result = $wpdb->get_row($wpdb->prepare("
                SELECT
                    MIN(pml.min_price) AS min_price,
                    MAX(pml.max_price) AS max_price

                FROM {$table} pml

                INNER JOIN {$posts_table} p
                    ON pml.product_id = p.ID

                WHERE pml.stock_status = 'instock'
                AND p.post_status = 'publish'
                AND p.post_type = 'product'

                AND pml.product_id IN (

                    SELECT tr.object_id

                    FROM {$wpdb->term_relationships} tr

                    INNER JOIN {$wpdb->term_taxonomy} tt
                        ON tr.term_taxonomy_id = tt.term_taxonomy_id

                    WHERE tt.taxonomy = 'product_cat'
                    AND tt.term_id IN ($placeholders)
                )

            ", ...$category_ids));

            return [
                'min_price' => floatval($result->min_price),
                'max_price' => floatval($result->max_price),
            ];
        },

        'permission_callback' => '__return_true'
    ]);
});

add_action('rest_api_init', function () {
  register_rest_route('qwoo/v1', '/my-orders', [
    'methods' => 'GET',
    'callback' => 'store_api_get_my_orders',
    'permission_callback' => 'qwoo_require_login'
  ]);
});

function store_api_get_my_orders() {
  $user_id = get_current_user_id();

  $args = [
    'customer_id' => $user_id,
    'status' => ['wc-completed', 'wc-processing', 'wc-on-hold'], // Customize as needed
    'limit' => -1,
    'orderby' => 'date',
    'order' => 'DESC'
  ];

  $orders = wc_get_orders($args);

  $data = [];

  foreach ($orders as $order) {
    $data[] = [
      'id' => $order->get_id(),
      'number' => $order->get_order_number(),
      'status' => $order->get_status(),
      'total' => $order->get_total(),
      'currency' => $order->get_currency(),
      'date_created' => $order->get_date_created()->date('Y-m-d H:i:s'),
      'items' => array_map(function ($item) {
          $thumbnailID = get_post_thumbnail_id( $item->get_product_id() );

          return [
              'name' => $item->get_name(),
              'thumbnail' => $thumbnailID ? wp_get_attachment_image_src( $thumbnailID, 'thumbnail' )[0] ?? '' : '',
              'quantity' => $item->get_quantity(),
              'total' => $item->get_total(),
          ];
      }, $order->get_items())
    ];
  }

  return rest_ensure_response($data);
}

add_action('rest_api_init', function () {
    register_rest_route('qwoo/v1', '/wishlist/', [
        'methods' => 'GET',
        'callback' => 'get_wishlist',
        'permission_callback' => 'qwoo_require_login',
    ]);

    register_rest_route('qwoo/v1', '/wishlist/', [
        'methods' => 'POST',
        'callback' => 'toggle_wishlist',
        'permission_callback' => 'qwoo_require_login',
        'args' => [
            'product_id' => [
                'required' => true,
                'type' => 'integer',
            ]
        ]
    ]);
});

// ✅ Ensure WC session is always ready

// ✅ Unified getter
function get_wishlist_products()
{
    $wishlist = get_user_meta(get_current_user_id(), 'wishlist_product_ids', true);
    return $wishlist ? array_map('intval', array_unique((array)$wishlist)) : [];
}

// ✅ Unified setter
function set_wishlist_products( $wishlist )
{
    $wishlist = array_unique(array_map('intval', (array)$wishlist));
    update_user_meta(get_current_user_id(), 'wishlist_product_ids', $wishlist);
}

// ✅ Toggle add/remove
function toggle_wishlist( $request ) {

    $product_id = (int) $request->get_param( 'product_id' );
    $product    = wc_get_product( $product_id );

    if ( ! $product ) {
        return new WP_Error( 'invalid_product', 'Invalid product ID.', [ 'status' => 404 ] );
    }

    $wishlist = get_wishlist_products();

    if ( ( $key = array_search( $product_id, $wishlist ) ) !== false ) {
        unset( $wishlist[$key] );
        $added = false;
    } else {
        $wishlist[] = $product_id;
        $added = true;
    }

    set_wishlist_products( $wishlist );

    return get_wishlist();
}

// ✅ Get wishlist with product data
function get_wishlist() {

    $product_ids = get_wishlist_products();
    $products    = [];

    foreach ( $product_ids as $id ) {
        $product = wc_get_product( $id );
        if ( ! $product ) {
            continue;
        }

        // Handle variations
        $attributes = [];
        $variations = [];
        if ( $product->is_type( 'variation' ) ) {
            $attributes = $product->get_variation_attributes();

            foreach ($attributes as $attr => $val) {
                $variations[] = ['attribute' => str_replace('attribute_', '', $attr), 'value' => $val];
            }

            $parent_id = $product->get_parent_id();
            $parent = wc_get_product($parent_id);
            $slug = $parent ? $parent->get_slug(): '';

        } else{
            $slug = $product->get_slug();
        }

        $products[] = [
            'id'        => $product->get_id(),
            'parent' => $product->get_parent_id(),
            'name'      => $product->get_name(),
            'price'     => $product->get_price(),
            'in_stock'  => $product->is_in_stock(),
            'type'      => $product->get_type(),
            'permalink' => $product->get_permalink(),
            'slug' => $slug,
            'image'     => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
            'variation'=> $variations,
        ];
    }

    return rest_ensure_response( [
        'wishlist' => array_values( $products ),
    ] );
}

/*--------------------------------------------------------------
# 1. Create / upgrade the PWA push subscriptions table
--------------------------------------------------------------*/

/** Bump when the CREATE TABLE below changes; dbDelta() applies the diff. */
const QWOO_PUSH_DB_VERSION = '1';

/**
 * Creates the table on the first request after install/update.
 *
 * This used to be a register_activation_hook( __FILE__, ... ) in this file,
 * but __FILE__ here is includes/rest-api.php, not the plugin's main file,
 * so WordPress never fired it and the table was never created. A version
 * check covers fresh installs, existing installs that never got the table,
 * and future schema changes, without requiring a plugin re-activation.
 * The check is one autoloaded option read per request.
 */
add_action( 'plugins_loaded', 'qwoo_maybe_install_push_table' );
function qwoo_maybe_install_push_table() {
    if ( get_option( 'qwoo_push_db_version' ) === QWOO_PUSH_DB_VERSION ) {
        return;
    }
    pwa_create_push_subscriptions_table();

    global $wpdb;
    $table = $wpdb->prefix . 'pwa_push_subscriptions';
    // Only record the version once the table really exists, so a failed
    // CREATE (e.g. missing DB privileges) is retried on the next request.
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
        update_option( 'qwoo_push_db_version', QWOO_PUSH_DB_VERSION, true );
    } else {
        error_log( "Qwoo: could not create {$table}: " . $wpdb->last_error );
    }
}

function pwa_create_push_subscriptions_table() {
    global $wpdb;

    $table = $wpdb->prefix . 'pwa_push_subscriptions';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        device_id VARCHAR(64) NULL,
        endpoint TEXT NOT NULL,
        endpoint_hash CHAR(64) NOT NULL,
        p256dh TEXT NULL,
        auth_key TEXT NULL,
        cart_token VARCHAR(255) NULL,
        platform VARCHAR(20) NULL,
        last_seen_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY endpoint_hash (endpoint_hash),
        KEY device_id (device_id),
        KEY cart_token (cart_token)
    ) $charset_collate;";

    dbDelta($sql);
}

/*--------------------------------------------------------------
# 2. Register REST Routes
--------------------------------------------------------------*/
add_action('rest_api_init', function () {
    register_rest_route('qwoo/v1', '/pwa/save-subscription', [
        'methods'  => 'POST',
        'callback' => 'pwa_save_push_subscription',
        'permission_callback' => function () {
            return qwoo_rate_limit_check('pwa_save_subscription', 20, 5 * MINUTE_IN_SECONDS);
        },
    ]);

    register_rest_route('qwoo/v1', '/pwa/remove-subscription', [
        'methods'  => 'POST',
        'callback' => 'pwa_remove_push_subscription',
        'permission_callback' => function () {
            return qwoo_rate_limit_check('pwa_remove_subscription', 20, 5 * MINUTE_IN_SECONDS);
        },
    ]);
});

/*--------------------------------------------------------------
# 3. Save / Update Subscription
--------------------------------------------------------------*/
function pwa_save_push_subscription($request)
{
    global $wpdb;

    $table = $wpdb->prefix . 'pwa_push_subscriptions';
    $data = $request->get_json_params();

    $device_id = !empty($data['device_id']) ? sanitize_text_field($data['device_id']) : null;
    $cart_token = !empty($data['cart_token']) ? sanitize_text_field($data['cart_token']) : null;
    $subscription = $data['subscription'] ?? null;

    if (empty($subscription['endpoint'])) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing endpoint'
        ], 400);
    }

    $endpoint = trim($subscription['endpoint']);
    $endpoint_hash = hash('sha256', $endpoint);

    $keys = $subscription['keys'] ?? [];

    $p256dh = !empty($keys['p256dh']) ? sanitize_text_field($keys['p256dh']) : '';
    $auth_key = !empty($keys['auth']) ? sanitize_text_field($keys['auth']) : '';

    $platform = !empty($subscription['native']) ? 'native' : 'web';

    $now = current_time('mysql');

    $existing_id = null;

// First priority = same device
    if ($device_id) {
        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE device_id = %s LIMIT 1",
                $device_id
            )
        );
    }

// Second priority = same endpoint
    if (!$existing_id) {
        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE endpoint_hash = %s LIMIT 1",
                $endpoint_hash
            )
        );
    }

    if ($existing_id !== null) {

        $wpdb->update(
            $table,
            [
                'device_id' => $device_id,
                'endpoint' => $endpoint,
                'endpoint_hash' => $endpoint_hash,
                'cart_token' => $cart_token,
                'p256dh' => $p256dh,
                'auth_key' => $auth_key,
                'platform' => $platform,
                'updated_at' => $now,
            ],
            ['id' => $existing_id]
        );

        return rest_ensure_response([
            'success' => true,
            'action' => 'updated'
        ]);
    }

    $wpdb->insert(
        $table,
        [
            'device_id' => $device_id,
            'endpoint' => $endpoint,
            'endpoint_hash' => $endpoint_hash,
            'p256dh' => $p256dh,
            'auth_key' => $auth_key,
            'cart_token' => $cart_token,
            'platform' => $platform,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    return rest_ensure_response([
        'success' => true,
        'action' => 'inserted'
    ]);
}

/*--------------------------------------------------------------
# 4. Remove Subscription
--------------------------------------------------------------*/
function pwa_remove_push_subscription($request) {
    global $wpdb;

    $table = $wpdb->prefix . 'pwa_push_subscriptions';
    $data = $request->get_json_params();

    $endpoint = !empty($data['endpoint']) ? trim($data['endpoint']) : null;
    $device_id = !empty($data['device_id']) ? sanitize_text_field($data['device_id']) : null;

    if ($endpoint) {
        $deleted = $wpdb->delete(
            $table,
            ['endpoint_hash' => hash('sha256', $endpoint)]
        );

        return rest_ensure_response([
            'success' => true,
            'removed' => (bool) $deleted
        ]);
    }

    if ($device_id) {
        $deleted = $wpdb->delete(
            $table,
            ['device_id' => $device_id]
        );

        return rest_ensure_response([
            'success' => true,
            'removed' => (bool) $deleted
        ]);
    }

    return new WP_REST_Response([
        'success' => false,
        'message' => 'Nothing to remove'
    ], 400);
}

/**
 * Update cart_token on an existing push subscription record using the stable device_id.
 * POST JSON: { device_id: "...", cart_token: "..." }
 */
function update_subscription_cart_token($request)
{
    global $wpdb;

    $table = $wpdb->prefix . 'pwa_push_subscriptions';
    $data = $request->get_json_params();

    $device_id = isset($data['device_id']) ? sanitize_text_field($data['device_id']) : null;
    $new_cart_token = isset($data['cart_token']) ? sanitize_text_field($data['cart_token']) : null;

    // 'hidden' -> app went to background, start the abandoned-cart clock
    // 'active' -> app is in foreground, cancel any pending abandoned-cart send
    $status = isset($data['status']) && $data['status'] === 'active' ? 'active' : 'hidden';

    if (empty($device_id)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing device_id'
        ], 400);
    }

    // Allow "active" pings without a cart token (just clearing last_seen_at)
    if ($status === 'hidden' && empty($new_cart_token)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Missing cart_token'
        ], 400);
    }

    $fields = [
        'updated_at'   => current_time('mysql'),
        'last_seen_at' => $status === 'active' ? null : current_time('mysql'),
    ];

    if ($new_cart_token) {
        $fields['cart_token'] = $new_cart_token;
    }

    $where = ['device_id' => $device_id];

    $updated = $wpdb->update($table, $fields, $where);

    if ($updated === false) {
        error_log('Cart token update DB error: ' . $wpdb->last_error);
        error_log('Cart token update query: ' . $wpdb->last_query);

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Database update failed'
        ], 500);
    }

    return rest_ensure_response([
        'success' => true,
        'action' => $status === 'active' ? 'cleared_last_seen' : 'cart_token_updated',
        'device_id' => $device_id
    ]);
}

// Register the new REST route (Add this to your rest_api_init action hook)
add_action('rest_api_init', function() {
    register_rest_route('qwoo/v1', '/pwa/update-cart-token', [
        'methods' => 'POST',
        'callback' => 'update_subscription_cart_token',
        // Legit clients send at most two calls per real background/foreground
        // switch (see queueCartTokenSync() in the frontend), but many
        // visitors can share one IP (offices, mobile carrier NAT), so the
        // per-IP cap is generous; each call is a single indexed UPDATE. The
        // same cap also applies per device_id, so one device can't exhaust a
        // shared IP's allowance for everyone else.
        'permission_callback' => function ( $request ) {
            $params    = $request->get_json_params();
            $device_id = isset( $params['device_id'] ) ? sanitize_text_field( (string) $params['device_id'] ) : null;
            return qwoo_rate_limit_check( 'pwa_update_cart_token', 120, 5 * MINUTE_IN_SECONDS, $device_id );
        },
    ]);
});
<?php
use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema;

add_action( 'init', function() {
    // Make sure the function exists (added by WooCommerce Blocks/Store API)
    if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
        return;
    }

    woocommerce_store_api_register_endpoint_data(
        [
            'endpoint' => ProductSchema::IDENTIFIER,
            'namespace' => 'qwoo',
            'data_callback' => function ($product) {
                // Get the first assigned category as the "default" one
                $terms = get_the_terms($product->get_id(), 'product_cat');

                if (empty($terms) || is_wp_error($terms)) {
                    return ['default_category' => null];
                }

                // Get the first term (you can customize sorting if needed)
                $default = array_shift($terms);

                return [
                    'default_category' => [
                        'id' => $default->term_id,
                        'name' => $default->name,
                        'slug' => $default->slug,
                    ],
                ];
            },
            'schema_callback' => function () {
                return [
                    'properties' => [
                        'default_category' => [
                            'description' => __('Default category assigned to the product.', 'woocommerce'),
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                            ],
                            'context' => ['view', 'edit'],
                            'readonly' => true,
                        ],
                    ],
                ];
            },
        ]
    );
    woocommerce_store_api_register_endpoint_data(array(
        'endpoint' => ProductSchema::IDENTIFIER,
        'namespace' => 'offline_order',

        'data_callback' => function ($product) {
            // Only inject when the special param is present
            $include = isset($_GET['include_sort_meta']) && $_GET['include_sort_meta'] === 'true';

            if (!$include) {
                return [];
            }

            return [
                'menu_order' => (int)$product->get_menu_order(),
                'total_sales' => (int)$product->get_total_sales(),
                'average_rating' => (float)$product->get_average_rating(),
            ];
        },

        'schema_callback' => function () {
            return [
                'properties' => [
                    'menu_order' => ['type' => 'integer'],
                    'total_sales' => ['type' => 'integer'],
                    'average_rating' => ['type' => 'number'],
                ],
            ];
        },

        'schema_type' => ARRAY_A,
    ));
});

add_action('woocommerce_store_api_checkout_order_processed', 'send_admin_new_order_email_from_store_api', 20, 1);
function send_admin_new_order_email_from_store_api($order) {
    if (!is_a($order, 'WC_Order')) {
        return;
    }

    $order_id = $order->get_id();

    // Optional: Only send for specific statuses (adjust as needed)
    $allowed_statuses = ['pending', 'processing', 'on-hold'];
    if (!in_array($order->get_status(), $allowed_statuses)) {
        return;
    }

    // Prevent duplicate sending
    if (get_post_meta($order_id, '_new_order_email_sent', true) === 'yes') {
        return;
    }

    // Send email
    $mailer = WC()->mailer();
    $emails = $mailer->get_emails();

    if (!isset($emails['WC_Email_New_Order'])) {
        return;
    }

    $emails['WC_Email_New_Order']->trigger($order_id);
    update_post_meta($order_id, '_new_order_email_sent', 'yes');
}

add_action('woocommerce_store_api_checkout_update_order_meta', function( $order ) {
    // Get the raw body from REST API request
    $request_body = file_get_contents('php://input');
    if ($request_body) {
        $data = json_decode($request_body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (!empty($data['pwa_device_id'])) {
                $device_id = sanitize_text_field($data['pwa_device_id']);
                $order->update_meta_data('pwa_device_id', $device_id);
            }

            if (!empty($data['wc_cart_token'])) {
                $cart_token = sanitize_text_field($data['wc_cart_token']);
                $order->update_meta_data('wc_cart_token', $cart_token);
            }
        }
    }

    $origin = get_http_origin();

    if ( $origin ) {
        $frontend_url = Qwoo_Technical_Settings::get_frontend_url_from_origin( $origin );

        if ( $frontend_url ) {
            $order->update_meta_data( '_qwoo_frontend_url', $frontend_url );
        }
    }

    $order->save();
}, 10, 1);

/**
 * Modify WooCommerce emails url to frontend url instead of the backend url
 */

add_action( 'woocommerce_email_header', function ( $email_heading, $email ) {
    global $qwoo_email_order;

    if ( $email instanceof WC_Email && $email->object instanceof WC_Order ) {
        $qwoo_email_order = $email->object;
    }
}, 5, 2 );

add_action( 'woocommerce_email_footer', function ( $email ) {
    global $qwoo_email_order;

    if ( $email instanceof WC_Email && $email->object instanceof WC_Order ) {
        $qwoo_email_order = $email->object;
    }
}, 5, 1 );

add_filter( 'woocommerce_email_header_image_url', function ( $url ) {
    global $qwoo_email_order;


    if ( $qwoo_email_order instanceof WC_Order ) {
        $frontend_url = $qwoo_email_order->get_meta( '_qwoo_frontend_url' );

        if ( $frontend_url ) {
            return $frontend_url;
        }
    }

    return $url;
} );

add_filter( 'bloginfo', function ( $output, $show ) {
    if ( 'name' !== $show ) {
        return $output;
    }

    global $qwoo_email_order;

    if ( ! $qwoo_email_order instanceof WC_Order ) {
        return $output;
    }

    $frontend_url = $qwoo_email_order->get_meta( '_qwoo_frontend_url' );

    if ( ! $frontend_url ) {
        return $output;
    }

    $backend_domain = wp_parse_url( home_url(), PHP_URL_HOST );
    $frontend_domain = wp_parse_url( $frontend_url, PHP_URL_HOST );

    if (
        $backend_domain &&
        $frontend_domain &&
        strtolower( $output ) === strtolower( $backend_domain )
    ) {
        return $frontend_domain;
    }

    return $output;
}, 10, 2 );

add_filter( 'woocommerce_email_subject_new_order','update_woocommerce_emails_subject_url',10, 2);
add_filter( 'woocommerce_email_subject_customer_processing_order','update_woocommerce_emails_subject_url',10, 2);
add_filter( 'woocommerce_email_subject_customer_completed_order','update_woocommerce_emails_subject_url',10, 2);
add_filter( 'woocommerce_email_subject_customer_invoice','update_woocommerce_emails_subject_url',10, 2);
add_filter( 'woocommerce_email_subject_customer_note','update_woocommerce_emails_subject_url',10, 2);
add_filter( 'woocommerce_email_subject_low_stock','update_woocommerce_emails_subject_url',10, 2);
add_filter( 'woocommerce_email_subject_no_stock','update_woocommerce_emails_subject_url',10, 2);
add_filter( 'woocommerce_email_subject_backorder','update_woocommerce_emails_subject_url',10, 2);
add_filter( 'woocommerce_email_subject_customer_new_account','update_woocommerce_emails_subject_url',10, 2);
add_filter( 'woocommerce_email_subject_customer_invoice_paid','update_woocommerce_emails_subject_url',10, 2);
function update_woocommerce_emails_subject_url( $subject, $order ) {
    if ( ! $order instanceof WC_Order ) {
        return $subject;
    }

    $frontend_url = $order->get_meta( '_qwoo_frontend_url' );

    if ( ! $frontend_url ) {
        return $subject;
    }

    $frontend_domain = wp_parse_url( $frontend_url, PHP_URL_HOST );

    if ( ! $frontend_domain ) {
        return $subject;
    }

    $backend_domain = wp_parse_url( home_url(), PHP_URL_HOST );

    if (
        $backend_domain &&
        strpos( $subject, $backend_domain ) !== false
    ) {
        $subject = str_replace(
             $backend_domain,
             $frontend_domain,
            $subject
        );
    }

    return $subject;
}

add_filter( 'woocommerce_email_footer_text', function ( $text ) {
    global $qwoo_email_order;

    if ( ! $qwoo_email_order instanceof WC_Order ) {
        return $text;
    }

    $frontend_url = $qwoo_email_order->get_meta( '_qwoo_frontend_url' );

    if ( ! $frontend_url ) {
        return $text;
    }

    $frontend_domain = wp_parse_url( $frontend_url, PHP_URL_HOST );
    $backend_domain  = wp_parse_url( home_url(), PHP_URL_HOST );

    if ( ! $frontend_domain || ! $backend_domain ) {
        return $text;
    }

    return str_replace(
        $backend_domain,
        $frontend_domain,
        $text
    );
}, 20 );

/*****************/

add_filter( 'wp_mail_from_name', function ( $name ) {
    return get_bloginfo( 'name' );
} );

add_filter( 'woocommerce_rest_prepare_product_object', function( $response, $product, $request ) {
    if ( strpos( $request->get_route(), '/wc/store/' ) === false ) {
        return $response;
    }
    $data = $response->get_data();
    $terms = get_the_terms( $product->get_id(), 'product_cat' );
    $data['categories'] = [];
    if ( $terms && ! is_wp_error( $terms ) ) {
        foreach ( $terms as $term ) {
            $data['categories'][] = [
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }
    }
    $response->set_data( $data );
    return $response;
}, 10, 3 );


add_filter( 'wpseo_canonical', function ( $canonical ) {
    $frontend_url = Qwoo_Technical_Settings::get_primary_frontend_domain();
    if ( empty( $canonical ) || empty( $frontend_url ) ) {
        return $canonical;
    }

    $backend_url = home_url();

    return str_replace(
        untrailingslashit( $backend_url ),
        untrailingslashit( $frontend_url ),
        $canonical
    );
} );
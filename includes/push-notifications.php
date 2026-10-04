<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // security
}

function pwa_get_subscriptions($where_sql = '', $params = [])
{
    global $wpdb;

    $table = $wpdb->prefix . 'pwa_push_subscriptions';

    $sql = "SELECT * FROM $table";

    if ($where_sql) {
        $sql .= " WHERE $where_sql";
    }

    $sql .= " ORDER BY id DESC";

    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, ...$params);
    }

    return $wpdb->get_results($sql, ARRAY_A);
}

function pwa_is_web_subscription($sub)
{
    return !empty($sub['p256dh']);
}

function pwa_is_native_subscription($sub)
{
    return empty($sub['p256dh']);
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * VAPID subject, built from the "Notification Sender Email" saved in
 * Technical Settings (falls back to the site's admin email).
 */
function qwoo_get_push_sender_email() {
    $tech_settings = get_option( 'qwoo_technical_settings', [] );
    $email = ! empty( $tech_settings['push_email'] ) ? $tech_settings['push_email'] : get_option( 'admin_email' );
    return 'mailto:' . sanitize_email( $email );
}

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

function get_fcm_access_token()
{
    $serviceAccountPath = QWOO_FIREBASE_SA_PATH;

    if (!file_exists($serviceAccountPath)) {
        throw new Exception('Firebase service account file not found');
    }

    $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);

    $now = time();

    $jwtHeader = base64url_encode(json_encode([
        'alg' => 'RS256',
        'typ' => 'JWT',
    ]));

    $jwtClaimSet = base64url_encode(json_encode([
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => $serviceAccount['token_uri'],
        'iat' => $now,
        'exp' => $now + 3600,
    ]));

    $unsignedJwt = $jwtHeader . '.' . $jwtClaimSet;

    openssl_sign($unsignedJwt, $signature, $serviceAccount['private_key'], 'sha256');

    $jwt = $unsignedJwt . '.' . base64url_encode($signature);

    $response = wp_remote_post($serviceAccount['token_uri'], [
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'body' => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        throw new Exception($response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['access_token'])) {
        throw new Exception('Failed to obtain FCM access token');
    }

    if (empty($serviceAccount['project_id'])) {
        throw new Exception('Firebase service account file is missing "project_id"');
    }

    return [
        'access_token' => $body['access_token'],
        'project_id'   => $serviceAccount['project_id'],
    ];
}

function send_order_push_notification_safe($order) {
    $order_id = $order->get_id();
    $device_id = $order->get_meta('pwa_device_id', true);

    if (!$device_id) return;

    $subscriptions = pwa_get_subscriptions();
    if (!$subscriptions) return;

    $targets = pwa_get_subscriptions(
        "device_id = %s",
        [$device_id]
    );

    if (!$targets) return;

    foreach ($targets as $sub) {
        try {
            if (pwa_is_native_subscription($sub)) {
                send_native_order_fcm_push($sub, $order);
            } else {
                send_web_order_push($sub, $order);
            }
        } catch (Throwable $e) {
            error_log('[Push] Failed for device ' . $device_id . ': ' . $e->getMessage());
        }
    }
}

function send_native_order_fcm_push($sub, $order)
{
    $fcm         = get_fcm_access_token();
    $accessToken = $fcm['access_token'];
    $project_id  = $fcm['project_id'];

    if (empty($sub['endpoint']) || strlen($sub['endpoint']) < 50) {
        throw new Exception('Invalid FCM token');
    }

    $message =
    [
        'message' => [
            'token' => $sub['endpoint'], // FCM token
            'notification' => [
                'title' => 'Order Confirmation',
                'body' => 'Order #' . $order->get_order_number() . ' created!',
            ],
            'android' => [
                'notification' => [
                    'icon' => 'ic_stat_notify', // must exist in Android resources
                    'color' => '#FFFFFF',
                    'default_vibrate_timings' => true,
                    'tag' => 'order_' . $order->get_order_number() . '_created',
                    'channel_id' => 'orders',
                ]

            ],
            'data' => [
                'url' => '/my-account/',
                'order_id' => (string)$order->get_id(),
            ],
        ]
    ];

    $response = wp_remote_post(
        "https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send",
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($message),
            'timeout' => 10,
        ]
    );

    if (is_wp_error($response)) {
        error_log('[Native Push] WP_Error: ' . $response->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

    }
}

function send_web_order_push($sub, $order) {
    // Prefer the domain this specific order was actually placed through
    // (saved at checkout in woocommerce_store_api_checkout_update_order_meta)
    // over always defaulting to the first configured domain — matters once
    // more than one frontend domain is configured.
    $order_domain   = $order->get_meta( '_qwoo_frontend_url' );
    $frontend_url   = $order_domain ?: Qwoo_Technical_Settings::get_primary_frontend_domain();
    $frontend_url   = $frontend_url ?: 'about:blank';

    $auth = [
        'VAPID' => [
            'subject' => qwoo_get_push_sender_email(),
            'publicKey' => Qwoo_Technical_Settings::get_key( 'VAPID_API_PUBLIC_KEY' ),
            'privateKey' => Qwoo_Technical_Settings::get_key( 'VAPID_API_PRIVATE_KEY' ),
        ],
    ];

    $payload = json_encode([
    'notification' => [
        'title' => 'Order Confirmation',
        'body'  => 'Order #' . $order->get_order_number() . ' created!',
        'data'  => [
            'url' => '/my-account/',
        ],
        'icon' => $frontend_url.'/icons/icon-128x128.png',
        'badge' => $frontend_url.'/icons/favicon-32x32.png',
        'vibrate' => [200, 100, 200],
        'requireInteraction' => true,
    ]
    ]);

    $webPush = new WebPush($auth, ['timeout' => 5]);

    $subscription = Subscription::create([
        'endpoint' => $sub['endpoint'],
        'keys' => [
            'p256dh' => $sub['p256dh'],
            'auth' => $sub['auth_key'],
        ],
    ]);

    $webPush->queueNotification($subscription, $payload);
        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                error_log("❌ Order push failed: " . $report->getReason());
            }
        }
}

function send_order_push_notification($order_id) {
    try {
        $order = wc_get_order($order_id);
        if (!$order) return;

        send_order_push_notification_safe($order);

    } catch (Throwable $e) {
        error_log('[Push] Fatal error: ' . $e->getMessage());
    }
}

add_action('woocommerce_store_api_checkout_order_processed', 'send_order_push_notification', 10, 1);

function send_sale_push_notification_web($sub, $product)
{
    $primary_domain = Qwoo_Technical_Settings::get_primary_frontend_domain();
    $frontend_url   = $primary_domain ?: 'about:blank';

    $auth = [
        'VAPID' => [
            'subject' => qwoo_get_push_sender_email(),
            'publicKey' => Qwoo_Technical_Settings::get_key( 'VAPID_API_PUBLIC_KEY' ),
            'privateKey' => Qwoo_Technical_Settings::get_key( 'VAPID_API_PRIVATE_KEY' ),
        ],
    ];

    $product_id = $product->get_id();
    $product_name = $product->get_name();
    $product_url = '/product/' . $product->get_slug();

    $payload = json_encode([
        'product_id' => $product_id,
        'title' => '🔥 Sale Alert!',
        'body' => "$product_name is now on sale for ₪" . $product->get_sale_price(),
        'data' => [
            'url' => $product_url,
        ],
        'icon' => $frontend_url.'/icons/icon-128x128.png',
        'badge' => $frontend_url.'/icons/favicon-32x32.png',
    ]);


    // Split into batches of 10 to control memory usage
    $webPush = new WebPush($auth, ['timeout' => 2]);

    try {
        $subscription = Subscription::create([
            'endpoint' => $sub['endpoint'],
            'keys' => [
                'p256dh' => $sub['p256dh'],
                'auth' => $sub['auth_key'],
            ]
        ]);
        $webPush->queueNotification($subscription, $payload);
    } catch (Exception $e) {
        error_log("❌ Subscription create failed: " . $e->getMessage());
    }


    foreach ($webPush->flush() as $report) {
        $endpoint = $report->getRequest()->getUri()->__toString();

        if (!$report->isSuccess()) {
            $reason = $report->getReason();
            error_log("❌ Push failed to $endpoint: $reason");

            // Remove if 410 Gone or clearly expired
            /* if (str_contains($reason, '410') || str_contains($reason, 'unsubscribed') || str_contains($reason, 'expired')) {
                 $invalid_endpoints[] = $endpoint;
             }*/
        }
    }

}

function send_sale_push_notification_native($sub, $product)
{
    $fcm         = get_fcm_access_token();
    $accessToken = $fcm['access_token'];
    $project_id  = $fcm['project_id'];

    if (empty($sub['endpoint']) || strlen($sub['endpoint']) < 50) {
        throw new Exception('Invalid FCM token');
    }

    $product_id = $product->get_id();
    $product_name = $product->get_name();
    $product_url = '/product/' . $product->get_slug();

    $payload = json_encode([
            'product_id' => $product_id,
        'title' => '🔥 Sale Alert!',
        'body'  => "$product_name is now on sale for ₪" . $product->get_sale_price(),
        'data' => [
            'url' => $product_url,
        ]
    ]);
    $message =
    [
        'message' => [
            'token' => $sub['endpoint'], // FCM token
            'notification' => [
                'title' => '🔥 Sale Alert!',
                'body' => $product_name .'is now on sale for ₪' . $product->get_sale_price(),
            ],
            'android' => [
                'notification' => [
                    'icon' => 'ic_stat_notify', // must exist in Android resources
                    'color' => '#FFFFFF',
                    'default_vibrate_timings' => true,
                    'tag' => 'sale_alert_for_' . $product_id,
                    'channel_id' => 'promotions',
                ]

            ],
            'data' => [
                'url' => '/my-account/',
                'order_id' => '',
            ],
        ]
    ];

    $response = wp_remote_post(
        "https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send",
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($message),
            'timeout' => 10,
        ]
    );

    if (is_wp_error($response)) {
        error_log('[Native Push] WP_Error: ' . $response->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

    }
}
function send_sale_push_notification($product) {

    $subscriptions = pwa_get_subscriptions();
    if (!$subscriptions) return;


    foreach ($subscriptions as $sub) {
        try {
            if (pwa_is_native_subscription($sub)) {
                send_sale_push_notification_native($sub,$product);
            } else {
                send_sale_push_notification_web($sub,$product);
            }
        } catch (Throwable $e) {
            error_log('[Push] Failed for device ' . $sub['device_id'] . ': ' . $e->getMessage());
        }
    }
}
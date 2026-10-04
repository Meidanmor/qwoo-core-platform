<?php
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
// Load WordPress
require dirname(__FILE__, 5) . '/wp-load.php'; // navigate up to WP root
require_once dirname(__FILE__, 2) . '/includes/vendor/autoload.php';
// Only WordPress's own auto-generated secret may trigger this file.
// See qwoo_get_cron_url() in qwoo-core.php for the ready-to-use URL —
// it's shown in the admin settings screen, no manual setup required.
if ( ! hash_equals( qwoo_get_cron_secret(), $_GET['secret'] ?? '' ) ) {
    http_response_code(403);
    exit('Forbidden');
}

function get_cart_item_count_by_token($cart_token)
{
    $url = get_rest_url(null, 'wc/store/v1/cart');

    $response = wp_remote_get($url, [
        'headers' => [
            'Cart-Token' => $cart_token,
            'Content-Type' => 'application/json',
        ],
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        return 0;
    }

    $body_raw = wp_remote_retrieve_body($response);
    $body = json_decode($body_raw, true);

    if (empty($body['items']) || !is_array($body['items'])) {
        return 0;
    }

    return count($body['items']);
}
function crone_check_and_send_abandoned_cart_push() {
    global $wpdb;

    $table = $wpdb->prefix . 'pwa_push_subscriptions';
    $threshold_minutes = Qwoo_Technical_Settings::get_abandoned_cart_threshold();

    $subs = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT *
            FROM $table
            WHERE cart_token IS NOT NULL
            AND last_seen_at IS NOT NULL
            AND last_seen_at <= DATE_SUB(NOW(), INTERVAL %d MINUTE)
            ",
            $threshold_minutes
        ),
        ARRAY_A
    );

    if (empty($subs)) {
        return;
    }

    foreach ($subs as $sub) {

        // Atomically claim this row: only proceed if last_seen_at
        // is STILL exactly what we read it as. If the user reopened
        // the app in the meantime, their 'active' ping already set
        // last_seen_at to NULL, this UPDATE affects 0 rows, and we skip.
        $claimed = $wpdb->query(
            $wpdb->prepare(
                "
                UPDATE $table
                SET last_seen_at = NULL
                WHERE id = %d
                AND last_seen_at = %s
                ",
                $sub['id'],
                $sub['last_seen_at']
            )
        );

        if (!$claimed) {
            continue;
        }

        $item_count = get_cart_item_count_by_token($sub['cart_token']);

        if ($item_count <= 0) {
            // last_seen_at already cleared by the claim above
            continue;
        }

        crone_send_abandoned_cart_push($sub, $item_count);
        // last_seen_at already cleared by the claim above — no second update needed
    }
}

/**
 * Updated to take the specific subscription array directly
 */
function crone_send_abandoned_cart_push($subscription_data)
{
    $platform = !empty($subscription_data['p256dh']) ? 'web' : 'native';
    $result = [
        'success'  => false,
        'reason'   => null,
        'platform' => $platform,
    ];

    try {
        if ($platform === 'web') {
            $primary_domain = Qwoo_Technical_Settings::get_primary_frontend_domain();
            $frontend_url   = $primary_domain ?: 'about:blank';

            $auth = [
                'VAPID' => [
                    'subject' => qwoo_get_push_sender_email(),
                    'publicKey' => Qwoo_Technical_Settings::get_key( 'VAPID_API_PUBLIC_KEY' ),
                    'privateKey' => Qwoo_Technical_Settings::get_key( 'VAPID_API_PRIVATE_KEY' ),
                ]
            ];

            $payload = json_encode([
                'notification' => [
                    'title' => '🛒 You left something behind!',
                    'body' => 'Your cart is waiting — complete your purchase before items run out!',
                    'data' => ['url' => '/cart/'],
                    'icon' => $frontend_url.'/icons/icon-128x128.png',
                    'badge' => $frontend_url.'/icons/favicon-32x32.png',
                    'vibrate' => [200, 100, 200],
                    'requireInteraction' => true,
                ]
            ]);

            $webPush = new WebPush($auth, ['timeout' => 5]);

            $subscription = Subscription::create([
                'endpoint' => $subscription_data['endpoint'],
                'keys' => [
                    'p256dh' => $subscription_data['p256dh'],
                    'auth' => $subscription_data['auth_key'],
                ]
            ]);

            $webPush->queueNotification($subscription, $payload);

            // Assume success unless a report says otherwise — queueNotification()
            // above only queues one subscription, so flush() yields at most one report.
            $result['success'] = true;
            foreach ($webPush->flush() as $report) {
                if (!$report->isSuccess()) {
                    $result['success'] = false;
                    $result['reason']  = $report->getReason();
                    error_log("❌ Push failed: " . $report->getReason());
                    // Optional: If reason is 410 (Gone), you could remove it from the main list here
                }
            }
        } else {
            // Native / FCM. This used to throw an uncaught Exception on an
            // invalid token, which — since the caller's foreach loop has no
            // try/catch around this call — would kill the ENTIRE cron run
            // and silently skip every subscription still left in the batch,
            // including any that were perfectly valid. Now caught below so
            // one bad token only fails its own push instead of the whole run.
            if (empty($subscription_data['endpoint']) || strlen($subscription_data['endpoint']) < 50) {
                throw new Exception('Invalid FCM token');
            }

            $fcm         = get_fcm_access_token();
            $accessToken = $fcm['access_token'];
            $project_id  = $fcm['project_id'];

            $message = [
                'message' => [
                    'token' => $subscription_data['endpoint'],
                    'notification' => [
                        'title' => '🛒 You left something behind!',
                        'body' => 'Your cart is waiting — complete your purchase before items run out!',
                    ],
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'icon' => 'ic_stat_notify', // must exist in Android resources
                            'color' => '#FFFFFF',
                            'default_vibrate_timings' => true,
                            'channel_id' => 'abandoned_cart',
                            'tag' => 'abandoned_cart',
                        ],
                    ],
                    'data' => [
                        'url' => '/cart/',
                        'type' => 'abandoned_cart',
                    ],
                ],
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
                $result['reason'] = $response->get_error_message();
                //error_log('[Native Push] WP_Error: ' . $response->get_error_message());
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);

                if ($code >= 200 && $code < 300) {
                    $result['success'] = true;
                } else {
                    $result['reason'] = "HTTP {$code}: {$body}";
                }
            }
        }
    } catch (Exception $e) {
        $result['reason'] = $e->getMessage();
        error_log("❌ Push Error: " . $e->getMessage());
    }

    return $result;
}

// Execute
crone_check_and_send_abandoned_cart_push();
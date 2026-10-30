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

/**
 * A product went on sale (admin-options.php's hooks call this). Who gets it, how often and the text
 * are the owner's: dashboard → Notifications → Sale alert (Qwoo_Push_Engine).
 */
function send_sale_push_notification($product) {
    Qwoo_Push_Engine::on_sale($product);
}

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
        'overview' => 'action_overview',
    ];

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
}

Qwoo_Platform_Dashboard::init();

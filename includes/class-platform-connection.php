<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Connection to the store platform (stores created by the qwoo-platform
 * plugin). The platform's setup script saves `qwoo_platform_connection`:
 *
 *   platform_url  https://platform.example
 *   store_id      this store's id on the platform
 *   publish_key   'gcm:...' (AES-256-GCM with sha256(AUTH_KEY), the same
 *                 format as the keys in qwoo_api_keys)
 *   repo          "Org/store-acme", this store's content repo
 *
 * When it's present:
 *   - publishing gets a short-lived GitHub token from the platform that
 *     only works on this store's content repo (github_settings()), instead
 *     of a token saved in Technical Settings;
 *   - pushes to the content repo redeploy the storefront through a
 *     webhook the platform added to that repo (nothing to do here);
 *   - the platform can run the starter-template import (REST route below).
 *
 * Stores without it (like the original Aura site) keep using the GitHub
 * settings from Technical Settings, unchanged.
 *
 * Requests between the platform and the store use the X-Qwoo-Platform-Key
 * header rather than Authorization, which some shared hosts strip before
 * it reaches PHP.
 */
class Qwoo_Platform_Connection {

    const OPTION          = 'qwoo_platform_connection';
    const TOKEN_TRANSIENT = 'qwoo_platform_gh_token';
    const KEY_HEADER      = 'X-Qwoo-Platform-Key';

    /** @var array|null Token for this request. */
    private static $token = null;

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /** The connection with the publish key decrypted, or null when not connected. */
    public static function get() {
        $c = get_option( self::OPTION );
        if ( ! is_array( $c ) || empty( $c['platform_url'] ) || empty( $c['store_id'] ) ) {
            return null;
        }
        $key = self::decrypt( (string) ( $c['publish_key'] ?? '' ) );
        if ( $key === '' ) {
            return null;
        }
        $c['publish_key'] = $key;
        return $c;
    }

    public static function is_connected() {
        return self::get() !== null;
    }

    /**
     * Where and how to push published files:
     * [ 'owner' => ..., 'repo' => ..., 'token' => ..., 'branch' => ... ], or null.
     */
    public static function github_settings() {
        $c = self::get();

        if ( ! $c ) {
            $owner = Qwoo_Technical_Settings::get_key( 'GITHUB_REPO_OWNER' );
            $repo  = Qwoo_Technical_Settings::get_key( 'GITHUB_REPO_NAME' );
            $token = Qwoo_Technical_Settings::get_key( 'GITHUB_TOKEN' );
            if ( empty( $owner ) || empty( $repo ) || empty( $token ) ) {
                return null;
            }
            return [
                'owner'  => $owner,
                'repo'   => $repo,
                'token'  => $token,
                'branch' => Qwoo_Technical_Settings::get_key( 'GITHUB_BRANCH' ) ?: 'main',
            ];
        }

        $t = self::token( $c );
        if ( ! $t ) {
            return null;
        }
        return [ 'owner' => $t['owner'], 'repo' => $t['repo'], 'token' => $t['token'], 'branch' => $t['branch'] ];
    }

    /** A GitHub token from the platform, cached until shortly before it expires. */
    private static function token( array $c ) {
        if ( self::$token && self::$token['expires'] > time() + 120 ) {
            return self::$token;
        }

        $cached = get_transient( self::TOKEN_TRANSIENT );
        if ( is_array( $cached ) && ( $cached['expires'] ?? 0 ) > time() + 120 ) {
            $plain = self::decrypt( (string) ( $cached['token'] ?? '' ) );
            if ( $plain !== '' ) {
                $cached['token'] = $plain;
                return self::$token = $cached;
            }
        }

        $res = self::call( $c, 'github-token' );
        if ( ! $res || empty( $res['token'] ) || empty( $res['owner'] ) || empty( $res['repo'] ) ) {
            return null;
        }

        $expires = strtotime( (string) ( $res['expires_at'] ?? '' ) ) ?: time() + 1800;
        $token   = [
            'token'   => (string) $res['token'],
            'owner'   => (string) $res['owner'],
            'repo'    => (string) $res['repo'],
            'branch'  => (string) ( $res['branch'] ?? 'main' ),
            'expires' => $expires,
        ];
        set_transient( self::TOKEN_TRANSIENT, [ 'token' => self::encrypt( $token['token'] ) ] + $token, max( 60, $expires - time() - 120 ) );
        return self::$token = $token;
    }

    /** POSTs to this store's platform API; the decoded JSON answer, or null. */
    private static function call( array $c, $action, array $body = [], $timeout = 20 ) {
        $url = rtrim( $c['platform_url'], '/' ) . '/wp-json/qwoo-platform/v1/stores/' . (int) $c['store_id'] . '/' . $action;

        $response = wp_remote_post( $url, [
            'timeout' => $timeout,
            'headers' => [
                self::KEY_HEADER => $c['publish_key'],
                'Content-Type'   => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( "Qwoo platform {$action} failed: " . $response->get_error_message() );
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            error_log( "Qwoo platform {$action} returned {$code}." );
            return null;
        }
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        return is_array( $data ) ? $data : [];
    }

    /* ---------------- REST: called by the platform ---------------- */

    public static function register_routes() {
        // The 'qwoo' namespace also requires the proxy secret (see
        // Qwoo_Technical_Settings::enforce_proxy_secret), which the platform has.
        register_rest_route( 'qwoo/v1', '/platform/import-template', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_import_template' ],
            'permission_callback' => [ __CLASS__, 'rest_authorized' ],
        ] );
    }

    public static function rest_authorized( WP_REST_Request $request ) {
        $c     = self::get();
        $given = (string) $request->get_header( 'x_qwoo_platform_key' );
        return $c && $given !== '' && hash_equals( $c['publish_key'], $given );
    }

    public static function rest_import_template() {
        $result = Shop_Settings_Builder::import_template_step();
        if ( ! empty( $result['error'] ) ) {
            return new WP_REST_Response( $result, 500 );
        }
        return rest_ensure_response( $result );
    }

    /* ---------------- encryption (same format as qwoo_api_keys) ---------------- */

    private static function aes_key() {
        if ( ! defined( 'AUTH_KEY' ) || AUTH_KEY === '' || strpos( AUTH_KEY, 'put your unique phrase here' ) !== false ) {
            return false;
        }
        return hash( 'sha256', AUTH_KEY, true );
    }

    private static function encrypt( $value ) {
        $key = self::aes_key();
        if ( $value === '' || $key === false ) return '';
        $iv     = random_bytes( 12 );
        $tag    = '';
        $cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
        return $cipher === false ? '' : 'gcm:' . base64_encode( $iv . $tag . $cipher );
    }

    private static function decrypt( $value ) {
        $key = self::aes_key();
        if ( $key === false || strpos( $value, 'gcm:' ) !== 0 ) return '';
        $raw = base64_decode( substr( $value, 4 ), true );
        if ( $raw === false || strlen( $raw ) < 29 ) return '';
        $plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
        return $plain === false ? '' : $plain;
    }
}

Qwoo_Platform_Connection::init();

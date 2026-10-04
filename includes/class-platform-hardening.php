<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Security hardening for stores created by the platform (sites with a
 * platform connection). Store owners never use this WordPress directly,
 * so anything they'd never need is switched off:
 *
 *   - XML-RPC (an old remote API, a common brute-force target);
 *   - application passwords (Basic-auth API access);
 *   - the theme/plugin file editor in wp-admin;
 *   - listing users without being signed in (REST /wp/v2/users, ?author=N);
 *   - the WordPress version in the page source;
 *   - repeated failed logins: 5 per address or account in 15 minutes,
 *     then a 15 minute pause;
 *   - basic security headers on every response.
 */
class Qwoo_Platform_Hardening {

    const MAX_FAILURES = 5;
    const LOCK_SECONDS = 900;

    public static function init() {
        if ( ! get_option( Qwoo_Platform_Connection::OPTION ) ) {
            return; // not a platform store
        }
        if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
            define( 'DISALLOW_FILE_EDIT', true );
        }

        add_filter( 'xmlrpc_enabled', '__return_false' );
        add_filter( 'xmlrpc_methods', '__return_empty_array' );
        add_filter( 'wp_headers', [ __CLASS__, 'headers' ] );
        add_filter( 'wp_is_application_passwords_available', '__return_false' );
        remove_action( 'wp_head', 'wp_generator' );
        add_filter( 'the_generator', '__return_empty_string' );

        add_filter( 'rest_endpoints', [ __CLASS__, 'hide_users_endpoints' ] );
        add_action( 'template_redirect', [ __CLASS__, 'no_author_archives' ] );

        add_filter( 'authenticate', [ __CLASS__, 'refuse_when_locked' ], 30, 2 );
        add_action( 'wp_login_failed', [ __CLASS__, 'count_failure' ] );
    }

    public static function headers( $headers ) {
        unset( $headers['X-Pingback'] );
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['X-Frame-Options']        = 'SAMEORIGIN';
        $headers['Referrer-Policy']        = 'strict-origin-when-cross-origin';
        $headers['Permissions-Policy']     = 'camera=(), microphone=(), geolocation=()';
        return $headers;
    }

    public static function hide_users_endpoints( $endpoints ) {
        if ( is_user_logged_in() ) {
            return $endpoints;
        }
        foreach ( array_keys( $endpoints ) as $route ) {
            if ( strpos( $route, '/wp/v2/users' ) === 0 ) {
                unset( $endpoints[ $route ] );
            }
        }
        return $endpoints;
    }

    public static function no_author_archives() {
        if ( ( is_author() || isset( $_GET['author'] ) ) && ! is_user_logged_in() ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();
        }
    }

    /* ---------------- login throttling ---------------- */

    private static function keys( $username ) {
        $ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        return [ 'qwoo_lf_ip_' . md5( $ip ), 'qwoo_lf_user_' . md5( strtolower( (string) $username ) ) ];
    }

    public static function refuse_when_locked( $user, $username ) {
        foreach ( self::keys( $username ) as $key ) {
            if ( (int) get_transient( $key ) >= self::MAX_FAILURES ) {
                return new WP_Error( 'qwoo_locked', __( 'Too many failed login attempts. Try again in 15 minutes.' ) );
            }
        }
        return $user;
    }

    public static function count_failure( $username ) {
        foreach ( self::keys( $username ) as $key ) {
            set_transient( $key, (int) get_transient( $key ) + 1, self::LOCK_SECONDS );
        }
    }
}

Qwoo_Platform_Hardening::init();

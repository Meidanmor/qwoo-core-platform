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
 *   - repeated failed logins: 5 per address in 15 minutes (an account
 *     only when attacked from several addresses; see below);
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

    /* ---------------- login throttling ----------------
     *
     * Two counters, each with a FIXED 15 minute window that starts at the
     * first failure (a new failure never moves the window's end):
     *
     *  - per client IP (qwoo_get_client_ip: the shopper's address sent by
     *    the storefront with the proxy secret, otherwise REMOTE_ADDR):
     *    5 failures lock that address until the window ends;
     *  - per username: only locked when it fails 10 times from 3 or more
     *    different addresses (a spread-out attack). One address can't lock
     *    someone else's account (its own IP lock stops it first), the lock
     *    ends with the window, and resetting the password still works.
     */

    const USER_MAX_FAILURES = 10;
    const USER_MIN_IPS      = 3;

    private static function ip_key() {
        $ip = function_exists( 'qwoo_get_client_ip' ) ? (string) qwoo_get_client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        return 'qwoo_lf_ip_' . md5( $ip );
    }

    private static function user_key( $username ) {
        return 'qwoo_lf_user_' . md5( strtolower( (string) $username ) );
    }

    /** A counter's current window: [ n, until, ips ]. */
    private static function window( $key ) {
        $w = get_transient( $key );
        if ( ! is_array( $w ) || (int) ( $w['until'] ?? 0 ) <= time() ) {
            return [ 'n' => 0, 'until' => time() + self::LOCK_SECONDS, 'ips' => [] ];
        }
        $w['ips'] = isset( $w['ips'] ) && is_array( $w['ips'] ) ? $w['ips'] : [];
        return $w;
    }

    public static function refuse_when_locked( $user, $username ) {
        $locked = self::window( self::ip_key() )['n'] >= self::MAX_FAILURES;
        if ( ! $locked && (string) $username !== '' ) {
            $u      = self::window( self::user_key( $username ) );
            $locked = $u['n'] >= self::USER_MAX_FAILURES && count( $u['ips'] ) >= self::USER_MIN_IPS;
        }
        if ( $locked ) {
            return new WP_Error( 'qwoo_locked', __( 'Too many failed login attempts. Try again in 15 minutes.' ) );
        }
        return $user;
    }

    public static function count_failure( $username ) {
        $ip_key = self::ip_key();
        $w      = self::window( $ip_key );
        set_transient( $ip_key, [ 'n' => $w['n'] + 1, 'until' => $w['until'] ], max( 1, $w['until'] - time() ) );

        if ( (string) $username === '' ) {
            return;
        }
        $key = self::user_key( $username );
        $u   = self::window( $key );
        $ips = $u['ips'];
        $h   = substr( $ip_key, -12 );
        if ( ! in_array( $h, $ips, true ) && count( $ips ) < 20 ) {
            $ips[] = $h;
        }
        set_transient( $key, [ 'n' => $u['n'] + 1, 'until' => $u['until'], 'ips' => $ips ], max( 1, $u['until'] - time() ) );
    }
}

Qwoo_Platform_Hardening::init();

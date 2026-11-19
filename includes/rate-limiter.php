<?php
/**
 * Qwoo Rate Limiter
 * Simple transient-based rate limiting for public write endpoints.
 * No external dependencies (Redis/Memcached) — works on any host.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Check + increment a rate limit bucket for the current request.
 *
 * @param string      $action        Unique key for this endpoint, e.g. 'login'.
 * @param int         $max_attempts  Max allowed attempts within the window.
 * @param int         $window_secs   Window length in seconds.
 * @param string|null $identifier    Optional stable identifier (e.g. the
 *                                   username being attempted). When given,
 *                                   attempts are ALSO capped per-identifier,
 *                                   independent of the caller's claimed IP —
 *                                   this is what actually stops brute-forcing
 *                                   one account, since IP/X-Forwarded-For is
 *                                   trivially spoofable and can't be trusted
 *                                   on its own.
 * @return true|WP_Error             True if allowed, WP_Error (429) if blocked.
 */
function qwoo_rate_limit_check( string $action, int $max_attempts, int $window_secs, ?string $identifier = null ) {
    $buckets = [];

    $ip = qwoo_get_client_ip();
    if ( $ip ) {
        $buckets[] = 'qwoo_rl_' . $action . '_' . md5( $ip );
    } else {
        // Fail open on unknown IP rather than blocking everyone behind a
        // misconfigured proxy — logged so it can be investigated.
        error_log( "[qwoo][rate-limit] Could not determine client IP for action '{$action}'" );
    }

    if ( $identifier !== null && $identifier !== '' ) {
        // Not IP-based, so it can't be bypassed by rotating a spoofed
        // X-Forwarded-For header — caps attempts against a single account
        // regardless of how many "IPs" the caller claims to be.
        $buckets[] = 'qwoo_rl_' . $action . '_id_' . md5( strtolower( $identifier ) );
    }

    if ( empty( $buckets ) ) {
        return true;
    }

    $now  = time();
    $hits = [];
    foreach ( $buckets as $key ) {
        $hits[ $key ] = qwoo_rate_limit_window( $key, $window_secs, $now );
        if ( $hits[ $key ]['n'] >= $max_attempts ) {
            return new WP_Error(
                'qwoo_rate_limited',
                __( 'Too many requests. Please wait a moment and try again.' ),
                [ 'status' => 429 ]
            );
        }
    }

    // A fixed window from the first hit: the count is saved with the time
    // left, so saving it again never moves the window's end (set_transient
    // alone restarts the expiry, which kept an active visitor blocked).
    foreach ( $hits as $key => $hit ) {
        set_transient( $key, [ 'n' => $hit['n'] + 1, 'until' => $hit['until'] ], max( 1, $hit['until'] - $now ) );
    }

    return true;
}

/** A bucket's current window: [ 'n' => hits so far, 'until' => its end ]. */
function qwoo_rate_limit_window( string $key, int $window_secs, int $now ): array {
    $hit = get_transient( $key );
    if ( ! is_array( $hit ) || (int) ( $hit['until'] ?? 0 ) <= $now ) {
        return [ 'n' => 0, 'until' => $now + $window_secs ];
    }
    return [ 'n' => (int) ( $hit['n'] ?? 0 ), 'until' => (int) $hit['until'] ];
}

/**
 * The shopper's IP. Shoppers reach this store only through the
 * storefront's server, which sends the shopper's address in
 * X-Qwoo-Client-IP together with the proxy secret. That header is trusted
 * only when the request carries a valid secret; any other caller gets its
 * own connection address (REMOTE_ADDR). CF-Connecting-IP and
 * X-Forwarded-For are never trusted: anyone can send them.
 */
function qwoo_get_client_ip(): ?string {
    static $ip = false;
    if ( $ip !== false ) {
        return $ip;
    }
    $ip = null;
    $client = trim( (string) ( $_SERVER['HTTP_X_QWOO_CLIENT_IP'] ?? '' ) );
    if ( $client !== '' && filter_var( $client, FILTER_VALIDATE_IP ) && qwoo_request_has_proxy_secret() ) {
        $ip = $client;
        return $ip;
    }
    $remote = trim( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    if ( $remote !== '' && filter_var( $remote, FILTER_VALIDATE_IP ) ) {
        $ip = $remote;
    }
    return $ip;
}

/** True when this request carries the storefront's proxy secret (constant-time compare). */
function qwoo_request_has_proxy_secret(): bool {
    $provided = (string) ( $_SERVER['HTTP_X_PROXY_SECRET'] ?? '' );
    if ( $provided === '' || ! class_exists( 'Qwoo_Technical_Settings' ) ) {
        return false;
    }
    $expected = (string) Qwoo_Technical_Settings::get_proxy_secret();
    return $expected !== '' && hash_equals( $expected, $provided );
}

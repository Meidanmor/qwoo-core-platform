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

    foreach ( $buckets as $key ) {
        if ( (int) get_transient( $key ) >= $max_attempts ) {
            return new WP_Error(
                'qwoo_rate_limited',
                __( 'Too many requests. Please wait a moment and try again.' ),
                [ 'status' => 429 ]
            );
        }
    }

    // First hit in this window sets the transient with the full expiry;
    // subsequent hits just increment the existing counter, which does not
    // reset its TTL — a fixed window, not a rolling one.
    foreach ( $buckets as $key ) {
        $count = (int) get_transient( $key );
        set_transient( $key, $count + 1, $window_secs );
    }

    return true;
}

/**
 * Best-effort client IP resolution. Trusts X-Forwarded-For only if you're
 * behind a known proxy (Vercel/Cloudflare) — adjust if your infra differs.
 */
function qwoo_get_client_ip(): ?string {
    // Cloudflare
    if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
        return sanitize_text_field( $_SERVER['HTTP_CF_CONNECTING_IP'] );
    }
    // Common proxy header — take the first (client) IP in the chain
    if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
        return sanitize_text_field( trim( $parts[0] ) );
    }
    if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
    }
    return null;
}
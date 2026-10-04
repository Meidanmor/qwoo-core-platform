<?php
/**
 * Security & Cookie Handling
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ── 1. WooCommerce Store API nonce check — intentionally disabled ──
//
// Store API's built-in nonce (X-WC-Store-API-Nonce) requires the client to
// fetch and re-attach a per-session nonce on every mutating cart/checkout
// request. In headless SSR mode, with requests proxied through the frontend
// origin, this added a fetch-nonce-then-fetch round trip to every cart
// interaction. Disabled here to simplify that flow for launch.
//
// Residual risk: without this check, cart-mutation endpoints (add/update/
// remove item) rely only on the session cookie for authentication, which
// is a CSRF exposure — a third-party site could trigger a logged-in
// user's browser to fire these requests. This is not currently mitigated
// beyond CORS + PROXY_SHARED_SECRET, which restrict cross-origin *reads*
// and unauthenticated *server-to-server* calls, but do not stop a
// same-origin form/fetch submission triggered from another tab/site.
//
// TODO: reintroduce nonce handling for state-changing Store API routes —
// fetch once on session init, cache client-side, attach to mutating
// requests only (reads can stay nonce-free).
//add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

// ── 2. Headless mode: block direct WordPress frontend access ──
add_action( 'template_redirect', function () {
    // Only ever touches classic theme-rendered frontend pages — never
    // admin, REST (Store API, qwoo/v1, Stripe webhooks all live here),
    // AJAX, cron, or WooCommerce's legacy ?wc-api= webhook endpoint.
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || isset( $_GET['wc-api'] ) ) {
        return;
    }
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return;
    }

    if ( ! class_exists( 'Qwoo_Technical_Settings' ) || ! Qwoo_Technical_Settings::is_frontend_blocked() ) {
        return;
    }

    // Always show a clear message rather than redirecting — with
    // multiple frontend domains configured, there's no single correct
    // destination to send visitors to.
    wp_die(
        'This site runs headless. Please visit the storefront directly.',
        'Headless Mode',
        [ 'response' => 503 ]
    );
} );
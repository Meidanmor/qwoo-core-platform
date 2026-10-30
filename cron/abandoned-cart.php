<?php
// Sends the push notifications that are due (Qwoo_Push_Engine: cart reminders, automations, scheduled
// sends). Run by the hosting account's cron job (php on the command line; the
// platform sets it up for new stores: every 15 minutes), or over the web with
// the store's own secret (qwoo_get_cron_url() in qwoo-core.php).
$qwoo_cli = PHP_SAPI === 'cli';
if ( $qwoo_cli ) {
    // WordPress expects a web request's server values.
    $_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
}
// Load WordPress
require dirname(__FILE__, 5) . '/wp-load.php'; // navigate up to WP root
require_once dirname(__FILE__, 2) . '/includes/vendor/autoload.php';
if ( ! $qwoo_cli && ! hash_equals( qwoo_get_cron_secret(), $_GET['secret'] ?? '' ) ) {
    http_response_code(403);
    exit('Forbidden');
}

// Sends what is due (the store also starts runs by itself; this covers quiet times).
if ( class_exists( 'Qwoo_Push_Engine' ) ) {
    Qwoo_Push_Engine::tick( 40 );
}

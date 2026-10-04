<?php
/**
 * Plugin Name: QWoo Core
 * Description: Headless WooCommerce backend for QWoo
 * Version:     1.0.2
 * Author:      Meidan Mor
 * Plugin URI:  https://github.com/Meidanmor/qwoo-core
 * Requires Plugins: woocommerce
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Tested up to: 7.1
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: qwoo-core
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Hard guards (block activation) ──
register_activation_hook( __FILE__, 'qwoo_core_check_requirements' );

function qwoo_core_check_requirements() {
    if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            'Qwoo Core requires <strong>PHP 8.1 or higher</strong>. ' .
            'Your server is running PHP ' . PHP_VERSION . '. Please upgrade PHP and try again.',
            'Plugin Activation Error',
            [ 'back_link' => true ]
        );
    }

    if ( ! extension_loaded( 'openssl' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            'Qwoo Core requires the <strong>OpenSSL PHP extension</strong>, which is not enabled on your server. ' .
            'Please contact your host to enable it.',
            'Plugin Activation Error',
            [ 'back_link' => true ]
        );
    }
}

define( 'QWOO_PATH',            plugin_dir_path( __FILE__ ) );
define( 'QWOO_URL',             plugin_dir_url( __FILE__ ) );
//define( 'QWOO_VERSION',         '1.0.20' );
define( 'QWOO_VERSION',         time() );
define( 'QWOO_FIREBASE_SA_PATH', WP_CONTENT_DIR . '/private/firebase-service-account.json' );

/**
 * Get (or lazily generate) the secret used to authorize the external
 * cron hit on cron/abandoned-cart.php. Get-or-create rather than
 * activation-only, so existing installs pick one up automatically on
 * next load too — no re-activation required after an update.
 */
function qwoo_get_cron_secret() {
    $secret = get_option( 'qwoo_cron_secret' );
    if ( empty( $secret ) ) {
        $secret = wp_generate_password( 32, false );
        update_option( 'qwoo_cron_secret', $secret, false );
    }
    return $secret;
}

/**
 * Full, ready-to-paste URL for the external cron job — shown in the
 * admin settings screen so the user never has to construct or type
 * the secret themselves.
 */
function qwoo_get_cron_url() {
    return add_query_arg( 'secret', qwoo_get_cron_secret(), QWOO_URL . 'cron/abandoned-cart.php' );
}

// ── Core includes ──
require_once QWOO_PATH . 'includes/security.php';
require_once QWOO_PATH . 'includes/rate-limiter.php';
require_once QWOO_PATH . 'includes/auth.php';
require_once QWOO_PATH . 'includes/rest-api.php';
require_once QWOO_PATH . 'includes/woocommerce-headless.php';
require_once QWOO_PATH . 'includes/push-notifications.php';
require_once QWOO_PATH . 'includes/admin-options.php';

// ── Admin settings ──
require_once QWOO_PATH . 'includes/class-technical-settings.php';
require_once QWOO_PATH . 'includes/class-platform-connection.php';
require_once QWOO_PATH . 'includes/class-shop-settings.php';

// Instantiate technical settings (registers AJAX hooks etc.)
new Qwoo_Technical_Settings();
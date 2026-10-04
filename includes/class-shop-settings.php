<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shop Builder now lives in includes/shop-builder/ (split into small,
 * single-concern files instead of one large class). This file is kept only
 * so that whatever `require_once`s `includes/class-shop-settings.php`
 * elsewhere in the plugin (e.g. the main plugin bootstrap file) doesn't
 * need to change. If you control that bootstrap file, feel free to point it
 * directly at includes/shop-builder/class-shop-settings-builder.php instead
 * and delete this shim.
 */
require_once __DIR__ . '/shop-builder/class-shop-settings-builder.php';

<?php
/**
 * Stands in for a WooCommerce email template while the store's own design is
 * used. The HTML was made already (Qwoo_Store_Emails::swap_template).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

echo Qwoo_Store_Emails::$rendered; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by Qwoo_Email_Render.

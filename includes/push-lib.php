<?php
/**
 * The push notification libraries (minishlink/web-push and what it needs) live in ONE file,
 * push-lib.phar, instead of ~1,150 files: the hosting counts files per account. Built from
 * .build/push-lib/vendor by .build/push-lib/build.mjs (the same Composer code, packed).
 *
 * Loaded only when it's needed (sending a push, making keys), not on every request.
 * Returns false when it can't be loaded (no phar support on the server).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function qwoo_push_lib() {
	static $ready = null;
	if ( $ready !== null ) {
		return $ready;
	}
	if ( class_exists( '\Minishlink\WebPush\WebPush' ) ) {
		return $ready = true;
	}
	$file = __DIR__ . '/push-lib.phar';
	if ( ! extension_loaded( 'phar' ) || ! is_file( $file ) ) {
		error_log( 'Qwoo push: the push library can\'t be loaded (' . ( extension_loaded( 'phar' ) ? 'file missing' : 'no phar support' ) . ').' );
		return $ready = false;
	}
	try {
		require_once 'phar://' . $file . '/vendor/autoload.php';
	} catch ( \Throwable $e ) {
		error_log( 'Qwoo push: loading the push library failed: ' . $e->getMessage() );
		return $ready = false;
	}
	return $ready = class_exists( '\Minishlink\WebPush\WebPush' );
}

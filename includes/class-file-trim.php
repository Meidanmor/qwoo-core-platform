<?php
/**
 * Fewer files per store: the hosting counts every file on the account (about 400,000 for all
 * stores), so a store keeps only what it uses.
 *
 * - New photos skip two sizes nothing reads: 2048x2048 (the 2560px "scaled" copy covers big
 *   screens in srcsets) and WooCommerce's 100px gallery square (classic theme galleries only).
 *   With the WebP copies that's 4 files fewer per photo. Older photos keep their files.
 * - Once per store (after an update): themes that aren't in use and the Akismet and Hello Dolly
 *   plugins (WordPress installs them; a headless store uses neither) are deleted, only when
 *   they're inactive. New stores lose them at setup too (platform settings).
 *
 * The push libraries are one packed file for the same reason (push-lib.php).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Qwoo_File_Trim {
	/** Raise to run the clean-up again on every store after an update. */
	const CLEANUP_VERSION = 1;
	const SKIP_SIZES      = [ '2048x2048', 'woocommerce_gallery_thumbnail' ];

	public static function init() {
		add_filter( 'intermediate_image_sizes_advanced', [ __CLASS__, 'skip_sizes' ] );
		add_action( 'wp_loaded', [ __CLASS__, 'maybe_cleanup' ], 99 );
	}

	public static function skip_sizes( $sizes ) {
		foreach ( self::SKIP_SIZES as $name ) {
			unset( $sizes[ $name ] );
		}
		return $sizes;
	}

	public static function maybe_cleanup() {
		if ( (int) get_option( 'qwoo_file_trim', 0 ) >= self::CLEANUP_VERSION ) {
			return;
		}
		// Marked first: a second request at the same moment doesn't start it again.
		update_option( 'qwoo_file_trim', self::CLEANUP_VERSION, true );
		try {
			self::cleanup();
		} catch ( \Throwable $e ) {
			error_log( 'Qwoo file trim: ' . $e->getMessage() );
		}
	}

	private static function cleanup() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		if ( get_filesystem_method() !== 'direct' || ! WP_Filesystem() ) {
			return; // Would need FTP details: leave it.
		}
		$removed = [];

		// Themes: never the active one or its parent.
		$keep = array_unique( [ get_stylesheet(), get_template() ] );
		foreach ( array_keys( wp_get_themes() ) as $slug ) {
			if ( in_array( $slug, $keep, true ) ) {
				continue;
			}
			if ( delete_theme( $slug ) === true ) {
				$removed[] = 'theme ' . $slug;
			}
		}

		// Plugins WordPress ships with, only when nobody turned them on.
		$plugins = array_keys( get_plugins() );
		$drop    = array_values( array_filter( $plugins, static function ( $file ) {
			return ( strpos( $file, 'akismet/' ) === 0 || $file === 'hello.php' || strpos( $file, 'hello-dolly/' ) === 0 )
				&& ! is_plugin_active( $file ) && ! is_plugin_active_for_network( $file );
		} ) );
		if ( $drop && delete_plugins( $drop ) === true ) {
			$removed = array_merge( $removed, $drop );
		}

		if ( $removed ) {
			error_log( 'Qwoo file trim removed: ' . implode( ', ', $removed ) );
		}
	}
}

Qwoo_File_Trim::init();

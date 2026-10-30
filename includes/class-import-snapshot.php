<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cancel puts the store back (dashboard → an import's Cancel): before a store copy file is brought in,
 * the content tables are copied inside the database (posts, terms, comments, WooCommerce's product
 * tables, options). Cancel stops the import and copies them back; photos the import added are
 * deleted from the disk. Orders and customers are never in a store copy file, so their tables
 * aren't touched; while the import runs the storefront takes no orders (see Qwoo_Import_Lock).
 *
 * Option `qwoo_import_snapshot` { taken, tables: [ name ] }. The copies are `{prefix}qwoosnap_{table}`,
 * dropped when the import finishes, is undone, or a new snapshot is taken.
 */
class Qwoo_Import_Snapshot {

    const OPTION = 'qwoo_import_snapshot';
    const PREFIX = 'qwoosnap_';

    /** Tables copied (without the WordPress prefix); the ones a store doesn't have are skipped. */
    const TABLES = [
        'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'comments', 'commentmeta',
        'options', 'woocommerce_attribute_taxonomies', 'wc_product_meta_lookup', 'wc_product_attributes_lookup',
    ];

    /** Options a restore leaves as they are now: the import's own state, schedules, caches. */
    const KEEP_OPTIONS = [ self::OPTION, 'cron', 'doing_cron', 'qwoo_content_stamp', 'qwoo_site_current', 'qwoo_site_lock', 'rewrite_rules' ];
    const KEEP_PREFIXES = [ '_transient_', '_site_transient_', 'qwoo_migration', 'qwoo_package', 'action_scheduler' ];

    public static function exists(): bool {
        return ! empty( get_option( self::OPTION ) );
    }

    private static function table_exists( $name ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
    }

    /** The SQLite database of a local test site (WordPress Playground), else null: MySQL. */
    private static function sqlite() {
        global $wpdb;
        return is_object( $wpdb->dbh ?? null ) && method_exists( $wpdb->dbh, 'get_sqlite_pdo' ) ? $wpdb->dbh->get_sqlite_pdo() : null;
    }

    /** Runs a statement on the copies (straight on SQLite, whose MySQL layer can't copy tables). */
    private static function run( $sql ): bool {
        global $wpdb;
        $pdo = self::sqlite();
        if ( $pdo ) {
            try {
                return $pdo->exec( $sql ) !== false;
            } catch ( Throwable $e ) {
                return false;
            }
        }
        return $wpdb->query( $sql ) !== false;
    }

    /** Rows of a query that reads a copy (assoc arrays). */
    private static function rows( $sql ): array {
        global $wpdb;
        $pdo = self::sqlite();
        if ( $pdo ) {
            try {
                return (array) $pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC );
            } catch ( Throwable $e ) {
                return [];
            }
        }
        return (array) $wpdb->get_results( $sql, ARRAY_A );
    }

    private static function copy_table( $from, $copy ): bool {
        // MySQL: the same columns and keys (some servers refuse a table without a primary key).
        return self::sqlite()
            ? self::run( "CREATE TABLE `$copy` AS SELECT * FROM `$from`" )
            : self::run( "CREATE TABLE `$copy` LIKE `$from`" ) && self::run( "INSERT INTO `$copy` SELECT * FROM `$from`" );
    }

    /** Copies the tables. Returns true or a WP_Error. */
    public static function take() {
        global $wpdb;
        self::drop();
        $done = [];
        foreach ( self::TABLES as $table ) {
            $from = $wpdb->prefix . $table;
            if ( ! self::table_exists( $from ) ) continue;
            $copy = $wpdb->prefix . self::PREFIX . $table;
            if ( ! self::copy_table( $from, $copy ) ) {
                $done[] = $table; // whatever part of it was made goes too
                self::drop_tables( $done );
                return new WP_Error( 'qwoo_snapshot', 'Your store couldn\'t be saved before the import, so it didn\'t start. Try again in a minute.' );
            }
            $done[] = $table;
        }
        update_option( self::OPTION, [ 'taken' => time(), 'tables' => $done ], false );
        return true;
    }

    /** Puts every copied table back. Returns true or a WP_Error. */
    public static function restore() {
        global $wpdb;
        $snap = get_option( self::OPTION );
        if ( ! is_array( $snap ) || empty( $snap['tables'] ) ) {
            return new WP_Error( 'qwoo_snapshot', 'There\'s no saved copy of your store to go back to.' );
        }
        $tables = (array) $snap['tables'];

        // Photos and videos the import added: their files go (the rows go back below).
        if ( in_array( 'posts', $tables, true ) ) {
            $copy  = $wpdb->prefix . self::PREFIX . 'posts';
            $added = array_column( self::rows( "SELECT p.ID FROM `{$wpdb->posts}` p WHERE p.post_type = 'attachment' AND p.ID NOT IN ( SELECT s.ID FROM `$copy` s )" ), 'ID' );
            foreach ( array_map( 'intval', $added ) as $id ) {
                $file   = get_attached_file( $id );
                $meta   = wp_get_attachment_metadata( $id );
                $backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
                if ( $file && function_exists( 'wp_delete_attachment_files' ) ) {
                    try {
                        wp_delete_attachment_files( $id, is_array( $meta ) ? $meta : [], is_array( $backup ) ? $backup : [], $file );
                    } catch ( Throwable $e ) {
                        // A file left on the disk is harmless: the store goes back anyway.
                    }
                }
            }
        }

        foreach ( $tables as $table ) {
            if ( $table === 'options' ) continue;
            $live = $wpdb->prefix . $table;
            $copy = $wpdb->prefix . self::PREFIX . $table;
            if ( ! self::run( "DELETE FROM `$live`" ) || ! self::run( "INSERT INTO `$live` SELECT * FROM `$copy`" ) ) {
                return new WP_Error( 'qwoo_snapshot', 'Going back stopped halfway (' . $table . '). Try Cancel again; if it keeps failing, contact support.' );
            }
        }
        if ( in_array( 'options', $tables, true ) ) {
            self::restore_options();
        }

        wp_cache_flush();
        if ( function_exists( 'wc_delete_product_transients' ) ) wc_delete_product_transients();
        delete_option( 'product_cat_children' );
        if ( class_exists( 'Qwoo_Content_Stamp' ) ) Qwoo_Content_Stamp::touch( 'import undone' );
        self::drop();
        return true;
    }

    private static function keep_option( $name ): bool {
        if ( in_array( $name, self::KEEP_OPTIONS, true ) ) return true;
        foreach ( self::KEEP_PREFIXES as $prefix ) {
            if ( strpos( $name, $prefix ) === 0 ) return true;
        }
        return false;
    }

    /** Options: what changed goes back, what the import added goes, the import's own state stays. */
    private static function restore_options() {
        global $wpdb;
        $copy  = $wpdb->prefix . self::PREFIX . 'options';
        $saved = [];
        foreach ( self::rows( "SELECT option_name, option_value, autoload FROM `$copy`" ) as $row ) {
            $saved[ $row['option_name'] ] = $row;
        }
        foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options}", ARRAY_A ) as $row ) {
            $name = $row['option_name'];
            if ( self::keep_option( $name ) ) continue;
            if ( ! isset( $saved[ $name ] ) ) {
                $wpdb->delete( $wpdb->options, [ 'option_name' => $name ] );
            } elseif ( $saved[ $name ]['option_value'] !== $row['option_value'] ) {
                $wpdb->update( $wpdb->options, [ 'option_value' => $saved[ $name ]['option_value'] ], [ 'option_name' => $name ] );
            }
            unset( $saved[ $name ] );
        }
        // Options the import deleted.
        foreach ( $saved as $name => $row ) {
            if ( self::keep_option( $name ) ) continue;
            $wpdb->insert( $wpdb->options, $row );
        }
    }

    /** Forgets the copies (the import finished, or was undone). */
    public static function drop() {
        $snap = get_option( self::OPTION );
        self::drop_tables( is_array( $snap ) ? (array) ( $snap['tables'] ?? [] ) : self::TABLES );
        delete_option( self::OPTION );
    }

    private static function drop_tables( array $tables ) {
        global $wpdb;
        foreach ( $tables as $table ) {
            $copy = $wpdb->prefix . self::PREFIX . sanitize_key( $table );
            self::run( "DROP TABLE IF EXISTS `$copy`" );
        }
    }
}

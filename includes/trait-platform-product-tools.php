<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Product tools for the platform dashboard (used by Qwoo_Platform_Dashboard):
 * bulk changes, duplicating, CSV export and import, and starting and
 * ending scheduled sales on time.
 *
 * Export and import use WooCommerce's own product CSV format (the same
 * file as wp-admin's Products → Export), so a file from any WooCommerce
 * store can come in.
 */
trait Qwoo_Platform_Product_Tools {

    private static $bulk_max         = 100;
    private static $export_page_size = 100;
    private static $import_max_bytes = 4194304; // 4 MB
    private static $import_max_rows  = 5000;
    private static $import_seconds   = 15;      // per call; the dashboard keeps calling until it's done

    /** WooCommerce fields an import may set (anything else in the file is ignored). */
    private static $import_fields = [
        'id', 'type', 'sku', 'global_unique_id', 'name', 'published', 'featured', 'catalog_visibility',
        'short_description', 'description', 'date_on_sale_from', 'date_on_sale_to', 'tax_status', 'tax_class',
        'stock_status', 'stock_quantity', 'backorders', 'low_stock_amount', 'sold_individually', 'weight',
        'length', 'width', 'height', 'reviews_allowed', 'purchase_note', 'sale_price', 'regular_price',
        'category_ids', 'tag_ids', 'shipping_class_id', 'images', 'parent_id', 'upsell_ids', 'cross_sell_ids',
        'grouped_products', 'product_url', 'button_text', 'menu_order',
    ];

    /* ---------------- scheduled sales ---------------- */

    /**
     * Starts and ends scheduled sales. WooCommerce does it once a day from
     * WP-Cron, which may not run on time here, so the storefront's API
     * requests check every 15 minutes too.
     */
    public static function run_scheduled_sales() {
        if ( ! function_exists( 'wc_scheduled_sales' ) || get_transient( 'qwoo_sales_checked' ) ) {
            return;
        }
        set_transient( 'qwoo_sales_checked', 1, 15 * MINUTE_IN_SECONDS );
        wc_scheduled_sales();
    }

    /** 'YYYY-MM-DD' dates of a scheduled sale: null when not sent, false when invalid. */
    private static function sale_dates( array $f ) {
        if ( ! array_key_exists( 'sale_from', $f ) && ! array_key_exists( 'sale_to', $f ) ) {
            return null;
        }
        $dates = [];
        foreach ( [ 'from', 'to' ] as $key ) {
            $value = trim( (string) ( $f[ 'sale_' . $key ] ?? '' ) );
            if ( $value !== '' ) {
                if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
                    return false;
                }
            }
            $dates[ $key ] = $value;
        }
        return $dates;
    }

    /** Applies sale dates to a product or combination (none without a sale price). */
    private static function apply_sale_dates( WC_Product $product, $dates ) {
        if ( $dates === null ) {
            return;
        }
        $on_sale = (string) $product->get_sale_price( 'edit' ) !== '';
        $product->set_date_on_sale_from( $on_sale && $dates['from'] !== '' ? $dates['from'] . ' 00:00:00' : '' );
        $product->set_date_on_sale_to( $on_sale && $dates['to'] !== '' ? $dates['to'] . ' 23:59:59' : '' );
    }

    /** The sale dates the editor shows: the product's, or its first combination's on sale. */
    private static function sale_dates_of( WC_Product $p ) {
        $source = $p;
        if ( $p->is_type( 'variable' ) ) {
            $source = null;
            foreach ( $p->get_children() as $child ) {
                $v = wc_get_product( $child );
                if ( $v && ( $v->get_date_on_sale_from( 'edit' ) || $v->get_date_on_sale_to( 'edit' ) ) ) {
                    $source = $v;
                    break;
                }
            }
        }
        $date = static function ( $value ) {
            return $value instanceof WC_DateTime ? $value->date( 'Y-m-d' ) : '';
        };
        return [
            'sale_from' => $source ? $date( $source->get_date_on_sale_from( 'edit' ) ) : '',
            'sale_to'   => $source ? $date( $source->get_date_on_sale_to( 'edit' ) ) : '',
        ];
    }

    /**
     * The store announces new sales to app subscribers. Bulk changes and
     * imports would send one alert per product, so they're off for this
     * request.
     */
    private static function quiet_sale_alerts() {
        remove_action( 'woocommerce_product_object_updated_props', 'handle_product_sale_change', 10 );
        remove_action( 'woocommerce_product_object_updated_props', 'handle_scheduled_sale_start', 10 );
    }

    /* ---------------- bulk changes ---------------- */

    /**
     * { ids: [ product ids ], op, value }. ops: publish, draft, delete,
     * category_add / category_remove (value: category id), price (value:
     * percent, -90…500), sale (value: percent off, 1…95), sale_end,
     * instock, outofstock.
     *
     * Products a change doesn't fit are skipped and listed with the reason.
     */
    private static function action_products_bulk( array $params ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $params['ids'] ?? [] ) ) ) ) );
        if ( ! $ids ) {
            return self::bad( 'Choose at least one product.' );
        }
        if ( count( $ids ) > self::$bulk_max ) {
            return self::bad( 'Change up to ' . self::$bulk_max . ' products at a time.' );
        }
        $op       = (string) ( $params['op'] ?? '' );
        $value    = $params['value'] ?? '';
        $category = 0;
        $percent  = 0.0;
        switch ( $op ) {
            case 'publish':
            case 'draft':
            case 'delete':
            case 'instock':
            case 'outofstock':
            case 'sale_end':
                break;
            case 'category_add':
            case 'category_remove':
                $term = is_scalar( $value ) && absint( $value ) ? get_term( absint( $value ), 'product_cat' ) : null;
                if ( ! $term || is_wp_error( $term ) ) {
                    return self::bad( 'Choose a category.' );
                }
                $category = (int) $term->term_id;
                break;
            case 'price':
            case 'sale':
                $percent = is_numeric( $value ) ? round( (float) $value, 2 ) : 0.0;
                if ( $op === 'price' && ( ! $percent || $percent < -90 || $percent > 500 ) ) {
                    return self::bad( 'Change prices by between -90% and +500%.' );
                }
                if ( $op === 'sale' && ( $percent < 1 || $percent > 95 ) ) {
                    return self::bad( 'The discount must be between 1% and 95%.' );
                }
                break;
            default:
                return self::bad( 'Unknown change.' );
        }

        self::quiet_sale_alerts();
        $done    = 0;
        $skipped = [];
        foreach ( $ids as $id ) {
            $product = self::find_product( $id );
            if ( is_wp_error( $product ) ) {
                $skipped[] = [ 'id' => $id, 'name' => '', 'reason' => 'It doesn\'t exist any more.' ];
                continue;
            }
            try {
                $reason = self::bulk_one( $product, $op, $category, $percent );
            } catch ( WC_Data_Exception $e ) {
                $reason = $e->getMessage();
            }
            if ( $reason === '' ) {
                $done++;
                wc_delete_product_transients( $id );
            } else {
                $skipped[] = [ 'id' => $id, 'name' => html_entity_decode( $product->get_name(), ENT_QUOTES ), 'reason' => $reason ];
            }
        }
        return [ 'done' => $done, 'skipped' => $skipped ];
    }

    /** One product of a bulk change: '' when done, otherwise why it was skipped. */
    private static function bulk_one( WC_Product $p, $op, $category, $percent ) {
        switch ( $op ) {
            case 'delete':
                $p->delete( false ); // the trash, like the editor's Delete
                return '';
            case 'draft':
                $p->set_status( 'draft' );
                $p->save();
                return '';
            case 'publish':
                if ( (string) $p->get_price( 'edit' ) === '' ) {
                    return 'It has no price yet.';
                }
                $p->set_status( 'publish' );
                $p->save();
                return '';
            case 'category_add':
            case 'category_remove':
                $ids     = array_map( 'intval', $p->get_category_ids() );
                $default = (int) get_option( 'default_product_cat' );
                if ( $op === 'category_add' ) {
                    $ids[] = $category;
                    // Out of the default category ("Uncategorized") once it has a real one, as in the editor.
                    if ( $category !== $default ) {
                        $ids = array_diff( $ids, [ $default ] );
                    }
                } else {
                    $ids = array_diff( $ids, [ $category ] );
                    if ( ! $ids && $default ) {
                        $ids = [ $default ];
                    }
                }
                $p->set_category_ids( array_values( array_unique( $ids ) ) );
                $p->save();
                return '';
        }

        // Prices and stock: on the product, or on each combination of a product with options.
        if ( ! $p->is_type( self::EDITABLE_TYPES ) ) {
            return 'This kind of product can\'t be changed here.';
        }
        $variable = $p->is_type( 'variable' );
        $targets  = $variable ? array_filter( array_map( 'wc_get_product', $p->get_children() ) ) : [ $p ];
        $changed  = 0;
        $why      = '';
        foreach ( $targets as $target ) {
            $reason = self::bulk_price_stock( $target, $op, $percent );
            if ( $reason === '' ) {
                $target->save();
                $changed++;
            } else {
                $why = $reason;
            }
        }
        if ( $variable && $changed ) {
            WC_Product_Variable::sync( $p->get_id() );
        }
        return $changed ? '' : ( $why ?: 'There was nothing to change.' );
    }

    private static function bulk_price_stock( WC_Product $t, $op, $percent ) {
        $regular = (string) $t->get_regular_price( 'edit' );
        $sale    = (string) $t->get_sale_price( 'edit' );
        switch ( $op ) {
            case 'price':
                if ( $regular === '' ) {
                    return 'It has no price yet.';
                }
                $t->set_regular_price( self::scaled( $regular, 1 + $percent / 100 ) );
                if ( $sale !== '' ) {
                    $t->set_sale_price( self::scaled( $sale, 1 + $percent / 100 ) );
                }
                return '';
            case 'sale':
                if ( $regular === '' ) {
                    return 'It has no price yet.';
                }
                $new = self::scaled( $regular, 1 - $percent / 100 );
                if ( (float) $new <= 0 || (float) $new >= (float) $regular ) {
                    return 'Its price is too low for this discount.';
                }
                // Starts now, with no end date.
                $t->set_sale_price( $new );
                $t->set_date_on_sale_from( '' );
                $t->set_date_on_sale_to( '' );
                return '';
            case 'sale_end':
                if ( $sale === '' ) {
                    return 'It isn\'t on sale.';
                }
                $t->set_sale_price( '' );
                $t->set_date_on_sale_from( '' );
                $t->set_date_on_sale_to( '' );
                return '';
            case 'instock':
            case 'outofstock':
                if ( $t->get_manage_stock( 'edit' ) === true ) {
                    return 'It tracks quantity. Change the quantity instead.';
                }
                $t->set_stock_status( $op );
                return '';
        }
        return 'Unknown change.';
    }

    /** A price times a factor, rounded to the store's decimals. */
    private static function scaled( $price, $factor ) {
        $decimals = wc_get_price_decimals();
        return wc_format_decimal( round( (float) $price * $factor, $decimals ), $decimals, true );
    }

    /* ---------------- duplicate ---------------- */

    /**
     * Copies a product (with its photos, options and combinations) as a
     * draft named "… (Copy)". Its search engine listing and old addresses
     * aren't copied.
     */
    private static function action_product_duplicate( array $params ) {
        $product = self::find_product( $params['id'] ?? 0 );
        if ( is_wp_error( $product ) ) {
            return $product;
        }
        if ( ! $product->is_type( self::EDITABLE_TYPES ) ) {
            return self::bad( 'This kind of product can\'t be duplicated here.' );
        }
        if ( ! class_exists( 'WC_Admin_Duplicate_Product', false ) ) {
            include_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
        }
        $skip = static function ( $keys ) {
            return array_merge( (array) $keys, [ '_wp_old_slug', Qwoo_Seo::META, Qwoo_Seo::NOINDEX_META ] );
        };
        add_filter( 'woocommerce_duplicate_product_exclude_meta', $skip );
        self::quiet_sale_alerts();
        try {
            $copy = ( new WC_Admin_Duplicate_Product() )->product_duplicate( $product );
        } catch ( Exception $e ) {
            return self::bad( 'The product couldn\'t be duplicated: ' . $e->getMessage() );
        } finally {
            remove_filter( 'woocommerce_duplicate_product_exclude_meta', $skip );
        }
        return self::product_full( wc_get_product( $copy->get_id() ) );
    }

    /* ---------------- CSV export ---------------- */

    private static function load_csv_tools() {
        if ( ! class_exists( 'WC_Product_CSV_Exporter', false ) ) {
            include_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
        }
        if ( ! class_exists( 'WP_Importer', false ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
        }
        if ( ! class_exists( 'WC_Product_CSV_Importer', false ) ) {
            include_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
        }
        require_once __DIR__ . '/class-product-csv.php';
    }

    /**
     * { page }: 100 rows a page (products and their combinations), in
     * WooCommerce's CSV format: columns { id: heading } and rows
     * [ { column id: value } ]. The dashboard joins the pages into one file.
     */
    private static function action_products_export( array $params ) {
        self::load_csv_tools();
        $page     = max( 1, min( 10000, (int) ( $params['page'] ?? 1 ) ) );
        $exporter = new Qwoo_Product_CSV_Exporter();
        $exporter->set_page( $page );
        $exporter->set_limit( self::$export_page_size );
        $exporter->prepare_data_to_export();
        $total = $exporter->total();
        return [
            'columns' => $exporter->get_column_names(),
            'rows'    => $exporter->rows(),
            'total'   => $total,
            'done'    => $page * self::$export_page_size >= $total,
        ];
    }

    /* ---------------- CSV import ---------------- */

    /**
     * { data: base64 CSV, update_existing }: checks the file and keeps it
     * for the import (2 hours). Answers { job, rows, columns: [ { name, used } ] }.
     * Nothing changes until products_import_run.
     */
    private static function action_products_import_check( array $params ) {
        $csv = base64_decode( (string) ( $params['data'] ?? '' ), true );
        if ( ! is_string( $csv ) || trim( $csv ) === '' ) {
            return self::bad( 'The file is empty.' );
        }
        if ( strlen( $csv ) > self::$import_max_bytes ) {
            return self::bad( 'The file can be up to 4 MB. Split it into smaller files.' );
        }
        if ( strpos( $csv, "\0" ) !== false ) {
            return self::bad( 'This isn\'t a CSV file.' );
        }
        if ( ! mb_check_encoding( $csv, 'UTF-8' ) ) {
            return self::bad( 'Save the file as "CSV UTF-8" and try again (in Excel: File → Save As → CSV UTF-8).' );
        }

        $handle = fopen( 'php://temp', 'r+' );
        fwrite( $handle, $csv );
        rewind( $handle );
        $headers = fgetcsv( $handle, 0, ',', '"', "\0" );
        $lines   = [];
        while ( ( $row = fgetcsv( $handle, 0, ',', '"', "\0" ) ) !== false ) {
            if ( $row !== [ null ] ) {
                $lines[] = $row;
            }
            if ( count( $lines ) > self::$import_max_rows ) {
                fclose( $handle );
                return self::bad( 'A file can have up to ' . self::$import_max_rows . ' rows. Split it into smaller files.' );
            }
        }
        fclose( $handle );
        $rows = count( $lines );
        if ( ! is_array( $headers ) || count( $headers ) < 2 ) {
            return self::bad( 'The first row must have the column names, separated by commas.' );
        }
        if ( ! $rows ) {
            return self::bad( 'The file has no products.' );
        }
        $headers    = array_map( 'trim', $headers );
        $headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $headers[0] );

        self::load_csv_tools();
        $mapped = ( new Qwoo_Product_CSV_Mapper() )->map( $headers );
        // The export's own headings, for the few the importer doesn't guess (like GTIN).
        $labels  = array_change_key_case( array_flip( ( new Qwoo_Product_CSV_Exporter() )->get_default_column_names() ) );
        $known   = static fn( $field ) => in_array( $field, self::$import_fields, true ) || preg_match( '/^attributes:(name|value|visible|taxonomy|default)\d+$/', $field );
        $mapping = [];
        $columns = [];
        foreach ( $headers as $header ) {
            $field = (string) ( $mapped[ $header ] ?? '' );
            if ( ! $known( $field ) && isset( $labels[ mb_strtolower( $header ) ] ) ) {
                $field = (string) $labels[ mb_strtolower( $header ) ];
            }
            $used = $known( $field );
            // Columns that aren't used are left out of the import (meta, downloads, unknown).
            $mapping[ $header ] = $used ? $field : '';
            $columns[]          = [ 'name' => mb_substr( sanitize_text_field( $header ), 0, 80 ), 'used' => (bool) $used ];
        }
        if ( ! array_intersect( [ 'name', 'id', 'sku' ], $mapping ) ) {
            return self::bad( 'There\'s no Name, ID or SKU column. Use a file from Export, or a WooCommerce product CSV.' );
        }

        // Rows are sorted into products to add and products to update. A
        // file's IDs only mean something in the store it came from, so they
        // are renumbered: a matched row gets its product's ID here, a new one
        // a one-off number (WooCommerce links a new product's variations and
        // upsells through it), and links between rows ("id:12") follow.
        $update  = ! empty( $params['update_existing'] );
        $cols    = array_flip( array_filter( array_values( $mapping ) ) );
        $counts  = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0 ];
        $skipped = [];
        $base    = random_int( 1000000, 9000000 ) * 1000;
        $targets = [];
        $ids     = [];  // file ID => ID here (or one-off number)
        $sources = [];  // one-off number => file ID
        foreach ( $lines as $i => $line ) {
            $targets[ $i ] = self::import_match( $line, $cols );
            $file_id       = isset( $cols['id'] ) ? absint( $line[ $cols['id'] ] ?? 0 ) : 0;
            if ( $file_id && ! isset( $ids[ $file_id ] ) ) {
                $ids[ $file_id ] = $targets[ $i ] ?: $base + $i + 1;
                if ( ! $targets[ $i ] ) {
                    $sources[ $base + $i + 1 ] = $file_id;
                }
            }
        }
        $relink = static function ( $value ) use ( $ids ) {
            $links = array_map( 'trim', explode( ',', (string) $value ) );
            foreach ( $links as $n => $link ) {
                if ( preg_match( '/^id:(\d+)$/', $link, $m ) && isset( $ids[ (int) $m[1] ] ) ) {
                    $links[ $n ] = 'id:' . $ids[ (int) $m[1] ];
                }
            }
            return implode( ',', $links );
        };
        $add    = [];
        $change = [];
        foreach ( $lines as $i => $line ) {
            if ( isset( $cols['id'] ) && absint( $line[ $cols['id'] ] ?? 0 ) ) {
                $line[ $cols['id'] ] = (string) $ids[ absint( $line[ $cols['id'] ] ) ];
            }
            foreach ( [ 'parent_id', 'upsell_ids', 'cross_sell_ids', 'grouped_products' ] as $field ) {
                if ( isset( $cols[ $field ] ) && ( $line[ $cols[ $field ] ] ?? '' ) !== '' ) {
                    $line[ $cols[ $field ] ] = $relink( $line[ $cols[ $field ] ] );
                }
            }
            if ( ! $targets[ $i ] ) {
                $add[] = $line;
            } elseif ( $update ) {
                $change[] = self::import_aim( $line, $cols, $targets[ $i ] );
            } else {
                $counts['skipped']++;
                if ( count( $skipped ) < 100 ) {
                    $skipped[] = [ 'row' => self::import_row_label( $line, $cols ), 'message' => 'Already in the store (not updated).' ];
                }
            }
        }
        $parts = [];
        if ( $add ) {
            $parts[] = [ 'csv' => self::import_csv( $headers, $add ), 'update' => false ];
        }
        if ( $change ) {
            $parts[] = [ 'csv' => self::import_csv( $headers, $change ), 'update' => true ];
        }

        $job = bin2hex( random_bytes( 12 ) );
        set_transient( 'qwoo_import_' . $job, [
            'parts'    => $parts,
            'part'     => 0,
            'mapping'  => $mapping,
            'direct'   => array_values( array_unique( array_filter( $targets ) ) ),
            'sources'  => $sources,
            'pos'      => 0,
            'rows'     => $rows,
            'counts'   => $counts,
            'problems' => $skipped,
            'finished' => ! $parts,
        ], 2 * HOUR_IN_SECONDS );
        return [ 'job' => $job, 'rows' => $rows, 'columns' => $columns, 'update_existing' => $update, 'new' => count( $add ), 'matched' => $rows - count( $add ) ];
    }

    /**
     * The product in this store a row stands for, or 0 for a new one: the
     * product with its SKU, else the one with its ID (or imported earlier
     * from that ID), when the name matches too and it has no other SKU.
     * IDs alone aren't trusted, since a file from another store has IDs
     * that belong to other products here.
     */
    private static function import_match( array $line, array $cols ) {
        $cell = static fn( $field ) => isset( $cols[ $field ] ) ? trim( (string) ( $line[ $cols[ $field ] ] ?? '' ) ) : '';
        $live = static fn( $id ) => $id && in_array( get_post_type( $id ), [ 'product', 'product_variation' ], true ) && ! in_array( get_post_status( $id ), [ 'importing', 'trash', 'auto-draft' ], true );

        $sku = $cell( 'sku' );
        if ( $sku !== '' ) {
            $found = wc_get_product_id_by_sku( $sku );
            if ( $live( $found ) ) {
                return (int) $found;
            }
        }
        $id = absint( $cell( 'id' ) );
        if ( ! $id ) {
            return 0;
        }
        global $wpdb;
        $earlier = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( '_qwoo_source_id', '_original_id' ) AND meta_value = %s ORDER BY post_id DESC LIMIT 20",
            (string) $id
        ) ) );
        foreach ( array_unique( array_merge( [ $id ], $earlier ) ) as $candidate ) {
            $product = $live( $candidate ) ? wc_get_product( $candidate ) : null;
            if ( ! $product ) {
                continue;
            }
            $same_name = ! isset( $cols['name'] ) || mb_strtolower( trim( $product->get_name( 'edit' ) ) ) === mb_strtolower( $cell( 'name' ) );
            $own_sku   = (string) $product->get_sku( 'edit' );
            if ( $same_name && ( $sku === '' || $own_sku === '' || $own_sku === $sku ) ) {
                return (int) $candidate;
            }
        }
        return 0;
    }

    /** A matched row, pointed at its product (and a combination at its own parent). */
    private static function import_aim( array $line, array $cols, $target ) {
        if ( isset( $cols['id'] ) ) {
            $line[ $cols['id'] ] = (string) $target;
        }
        $parent = wp_get_post_parent_id( $target );
        if ( isset( $cols['parent_id'] ) && $parent && get_post_type( $target ) === 'product_variation' ) {
            $line[ $cols['parent_id'] ] = 'id:' . $parent;
        }
        return $line;
    }

    /**
     * After an import: new products remember the file ID they came from
     * (so the same file can update them next time), and WooCommerce's
     * temporary links and leftover placeholders are removed.
     */
    private static function import_tidy( array $sources ) {
        global $wpdb;
        foreach ( array_chunk( $sources, 200, true ) as $chunk ) {
            $in   = implode( ',', array_map( 'intval', array_keys( $chunk ) ) );
            $rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_original_id' AND meta_value IN ( {$in} )" );
            foreach ( $rows as $row ) {
                $post_id = (int) $row->post_id;
                delete_post_meta( $post_id, '_original_id' );
                if ( get_post_status( $post_id ) === 'importing' ) {
                    wp_delete_post( $post_id, true );
                } else {
                    update_post_meta( $post_id, '_qwoo_source_id', (string) $chunk[ (int) $row->meta_value ] );
                }
            }
        }
    }

    private static function import_row_label( array $line, array $cols ) {
        foreach ( [ 'name', 'sku', 'id' ] as $field ) {
            $value = isset( $cols[ $field ] ) ? trim( (string) ( $line[ $cols[ $field ] ] ?? '' ) ) : '';
            if ( $value !== '' ) {
                return mb_substr( wp_strip_all_tags( $value ), 0, 120 );
            }
        }
        return '';
    }

    private static function import_csv( array $headers, array $lines ) {
        $handle = fopen( 'php://temp', 'r+' );
        fputcsv( $handle, $headers, ',', '"', '' );
        foreach ( $lines as $line ) {
            fputcsv( $handle, $line, ',', '"', '' );
        }
        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );
        return $csv;
    }

    /**
     * { job }: imports for about 15 seconds and answers how far it got
     * ({ percent, finished, counts, problems }). The dashboard calls again
     * until finished.
     */
    private static function action_products_import_run( array $params ) {
        $job   = preg_replace( '/[^a-f0-9]/', '', (string) ( $params['job'] ?? '' ) );
        $key   = 'qwoo_import_' . $job;
        $state = strlen( $job ) === 24 ? get_transient( $key ) : false;
        if ( ! is_array( $state ) || ! isset( $state['parts'] ) ) {
            return self::error( 'qwoo_dashboard_not_found', 'This import has expired. Upload the file again.', 404 );
        }
        if ( $state['finished'] ) {
            return self::import_answer( $state, 100 );
        }
        // One call at a time per import.
        $lock = 'qwoo_import_lock_' . $job;
        if ( get_transient( $lock ) ) {
            return self::error( 'qwoo_dashboard_busy', 'The import is still running. Wait a moment.', 409 );
        }
        set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

        self::load_csv_tools();
        self::quiet_sale_alerts();
        // Empty choice cells (published, in stock, visibility…) keep the
        // product's own value, or the default for a new one, instead of
        // meaning "no" or failing the row.
        $blank_keeps = static function ( $callbacks, $importer ) {
            foreach ( $importer->get_mapped_keys() as $index => $key ) {
                if ( isset( $callbacks[ $index ] ) && in_array( $key, [ 'type', 'published', 'catalog_visibility', 'tax_status', 'stock_status', 'backorders' ], true ) ) {
                    $parse               = $callbacks[ $index ];
                    $callbacks[ $index ] = static fn( $value ) => trim( (string) $value ) === '' ? null : call_user_func( $parse, $value );
                }
            }
            return $callbacks;
        };
        // Rows with an empty ID and SKU are new products, also when updating
        // (WooCommerce would skip them), so one file can update and add.
        $new_rows = static function ( $data ) {
            foreach ( $data as $key => $value ) {
                if ( $value === null || ( in_array( $key, [ 'id', 'sku' ], true ) && empty( $value ) ) ) {
                    unset( $data[ $key ] );
                }
            }
            return $data;
        };
        // The importer only adds new categories and tags for people who may manage them.
        $terms = static function ( $all, $caps ) {
            return in_array( 'manage_product_terms', (array) $caps, true ) ? array_merge( $all, [ 'manage_product_terms' => true ] ) : $all;
        };
        add_filter( 'woocommerce_product_importer_formatting_callbacks', $blank_keeps, 10, 2 );
        add_filter( 'woocommerce_product_importer_parsed_data', $new_rows );
        add_filter( 'user_has_cap', $terms, 10, 2 );
        // A private copy for WooCommerce's importer (it reads from a .csv file), removed right after.
        $file    = trailingslashit( get_temp_dir() ) . 'qwoo-import-' . $job . '.csv';
        $percent = 0;
        $start   = microtime( true );
        $sizes   = array_map( static fn( $part ) => strlen( $part['csv'] ), $state['parts'] );
        $written = -1;
        try {
            do {
                $part = $state['parts'][ $state['part'] ];
                if ( $written !== $state['part'] ) {
                    if ( file_put_contents( $file, $part['csv'] ) === false ) {
                        throw new RuntimeException( 'The file couldn\'t be read.' );
                    }
                    $written = $state['part'];
                }
                $importer = new Qwoo_Product_CSV_Importer( $file, [
                    'start_pos'        => (int) $state['pos'],
                    'lines'            => 10,
                    'mapping'          => $state['mapping'],
                    'update_existing'  => (bool) $part['update'],
                    'parse'            => true,
                    'prevent_timeouts' => true,
                ], $state['direct'] );
                $result = $importer->import();
                $state['counts']['imported'] += count( $result['imported'] ) + count( $result['imported_variations'] );
                $state['counts']['updated']  += count( $result['updated'] );
                foreach ( [ 'skipped', 'failed' ] as $kind ) {
                    $state['counts'][ $kind ] += count( $result[ $kind ] );
                    foreach ( $result[ $kind ] as $problem ) {
                        if ( is_wp_error( $problem ) && count( $state['problems'] ) < 100 ) {
                            $data                = (array) $problem->get_error_data();
                            // Rows are named with the file's own IDs, not the one-off numbers.
                            $row                 = preg_replace_callback( '/\bID (\d+)/', static fn( $m ) => 'ID ' . ( $state['sources'][ (int) $m[1] ] ?? $m[1] ), (string) ( $data['row'] ?? '' ) );
                            $state['problems'][] = [
                                'row'     => mb_substr( wp_strip_all_tags( $row ), 0, 120 ),
                                'message' => mb_substr( wp_strip_all_tags( html_entity_decode( $problem->get_error_message(), ENT_QUOTES ) ), 0, 300 ),
                            ];
                        }
                    }
                }
                $moved        = $importer->get_file_position() > $state['pos'];
                $state['pos'] = $importer->get_file_position();
                if ( ! $moved || $importer->get_percent_complete() >= 100 ) {
                    // This part is done: on to the next one, if any.
                    $state['part']++;
                    $state['pos'] = 0;
                    $moved        = true;
                }
                $done    = array_sum( array_slice( $sizes, 0, $state['part'] ) ) + $state['pos'];
                $percent = $state['part'] >= count( $sizes ) ? 100 : min( 99, (int) floor( $done / max( 1, array_sum( $sizes ) ) * 100 ) );
            } while ( $percent < 100 && microtime( true ) - $start < self::$import_seconds );
        } catch ( Throwable $e ) {
            $state['problems'][] = [ 'row' => '', 'message' => 'The import stopped: ' . mb_substr( wp_strip_all_tags( $e->getMessage() ), 0, 300 ) ];
            $percent             = 100;
        } finally {
            if ( is_file( $file ) ) {
                unlink( $file );
            }
            delete_transient( $lock );
            remove_filter( 'woocommerce_product_importer_formatting_callbacks', $blank_keeps, 10 );
            remove_filter( 'woocommerce_product_importer_parsed_data', $new_rows );
            remove_filter( 'user_has_cap', $terms, 10 );
        }

        if ( $percent >= 100 ) {
            // Done: the file itself isn't kept.
            $state['finished'] = true;
            $state['parts']    = [];
            self::import_tidy( $state['sources'] );
            wc_delete_product_transients();
        }
        set_transient( $key, $state, 2 * HOUR_IN_SECONDS );
        return self::import_answer( $state, $percent );
    }

    private static function import_answer( array $state, $percent ) {
        return [
            'percent'  => (int) $percent,
            'finished' => (bool) $state['finished'],
            'rows'     => (int) $state['rows'],
            'counts'   => $state['counts'],
            'problems' => $state['problems'],
        ];
    }
}

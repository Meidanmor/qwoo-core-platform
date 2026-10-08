<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce's product CSV exporter and import column mapping, opened up
 * for the platform dashboard (Qwoo_Platform_Product_Tools). Loaded only
 * when exporting or importing, after WooCommerce's own classes.
 */
class Qwoo_Product_CSV_Exporter extends WC_Product_CSV_Exporter {

    /** The page's rows as { column id: value }, formatted and escaped like WooCommerce's file. */
    public function rows() {
        $rows = [];
        foreach ( $this->row_data as $row ) {
            $out = [];
            foreach ( $row as $column => $value ) {
                if ( isset( $this->column_names[ $column ] ) ) {
                    $out[ $column ] = $this->format_data( $value );
                }
            }
            $rows[] = $out;
        }
        return $rows;
    }

    /** All rows to export (products and combinations). */
    public function total() {
        return (int) $this->total_rows;
    }
}

class Qwoo_Product_CSV_Mapper extends WC_Product_CSV_Importer_Controller {

    /** Column heading => WooCommerce field, as wp-admin's importer guesses it. */
    public function map( array $headers ) {
        return $this->auto_map_columns( $headers, false );
    }
}

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

/**
 * WooCommerce's importer, taking the IDs of products already matched in
 * this store as they are. (WooCommerce would first look them up among IDs
 * remembered from earlier imports, which may be other products.)
 */
class Qwoo_Product_CSV_Importer extends WC_Product_CSV_Importer {

    /** @var array Product IDs here that rows were matched to (as keys). */
    private $direct;

    public function __construct( $file, $params = [], array $direct = [] ) {
        // Set before the parent, which reads and parses the file right away.
        $this->direct = array_flip( array_map( 'intval', $direct ) );
        parent::__construct( $file, $params );
    }

    public function parse_id_field( $value ) {
        $id = absint( $value );
        return isset( $this->direct[ $id ] ) ? $id : parent::parse_id_field( $value );
    }

    public function parse_relative_field( $value ) {
        if ( preg_match( '/^id:(\d+)$/', (string) $value, $m ) && isset( $this->direct[ (int) $m[1] ] ) ) {
            return (int) $m[1];
        }
        return parent::parse_relative_field( $value );
    }
}

class Qwoo_Product_CSV_Mapper extends WC_Product_CSV_Importer_Controller {

    /** Column heading => WooCommerce field, as wp-admin's importer guesses it. */
    public function map( array $headers ) {
        return $this->auto_map_columns( $headers, false );
    }
}

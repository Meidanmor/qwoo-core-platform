<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Lionwheel (members.lionwheel.com/api/v1, github.com/lionwheel/api): many
 * Israeli courier companies run on it; the owner gets a key from theirs.
 * Create a task, its label, its status, cancel. Status changes also come to
 * Qwoo_Couriers::rest_lionwheel (a webhook the owner sets in Lionwheel).
 *
 * The key goes in the address (?key=): addresses and answers are never logged,
 * and the owner only sees fixed messages or Lionwheel's own short reason.
 * Not tried against Lionwheel yet (no test account).
 *
 * $s (from Qwoo_Couriers::settings()): { key, name }.
 */
class Qwoo_Courier_Lionwheel {

    const API = 'https://members.lionwheel.com/api/v1';

    private static function call( $method, $path, $key, $body = null ) {
        $args = [
            'method'      => $method,
            'timeout'     => 15,
            'redirection' => 0,
            'sslverify'   => true,
            'user-agent'  => 'Openly-Store',
            'headers'     => [ 'Accept' => 'application/json' ],
        ];
        if ( $body !== null ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = wp_json_encode( $body );
        }
        $res = wp_remote_request( self::API . $path . ( strpos( $path, '?' ) === false ? '?' : '&' ) . 'key=' . rawurlencode( $key ), $args );
        if ( is_wp_error( $res ) ) {
            return new WP_Error( 'lw_unreachable', 'Lionwheel didn\'t answer. Try again in a few minutes.' );
        }
        $d = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        return [ 'code' => (int) wp_remote_retrieve_response_code( $res ), 'data' => is_array( $d ) ? $d : [] ];
    }

    private static function auth_error( $res ) {
        if ( in_array( $res['code'], [ 401, 403 ], true ) ) {
            return new WP_Error( 'lw_auth', 'Lionwheel didn\'t accept the key. Ask your courier company for it again.' );
        }
        return null;
    }

    private static function reason( array $d ) {
        foreach ( [ 'error', 'errors', 'message', 'messages' ] as $k ) {
            if ( ! empty( $d[ $k ] ) ) return Qwoo_Couriers::their_message( $d[ $k ] );
        }
        return '';
    }

    /** Checks a key: asking for a task that doesn't exist answers 401 only when the key is wrong. */
    public static function check( $key ) {
        $res = self::call( 'GET', '/tasks/show/0', $key );
        if ( is_wp_error( $res ) ) return $res;
        if ( $err = self::auth_error( $res ) ) return $err;
        if ( $res['code'] >= 500 ) return new WP_Error( 'lw_down', 'Lionwheel didn\'t answer. Try again in a few minutes.' );
        return true;
    }

    /** Opens a task. Returns { id, number, label, url } or a WP_Error. */
    public static function create( array $s, WC_Order $o, array $a ) {
        $to   = $a['to'];
        $body = [
            'pickup_at'                  => wp_date( 'd/m/Y' ),
            // Unique per task: a shipment sent again after a cancel gets "-2".
            'original_order_id'          => (string) $o->get_order_number() . ( $a['attempt'] > 1 ? '-' . (int) $a['attempt'] : '' ),
            'destination_city'           => $to['city'],
            'destination_street'         => $to['street'],
            'destination_number'         => $to['number'],
            'destination_recipient_name' => $to['name'],
            'destination_phone'          => $to['phone'],
            'destination_floor'          => $to['floor'],
            'destination_apartment'      => $to['apartment'],
            'destination_entrance'       => $to['entrance'],
            'destination_email'          => $to['email'],
            'packages_quantity'          => (int) $a['packages'],
            'notes'                      => trim( $a['note'] . ( $to['remarks'] !== '' ? ' · ' . $to['remarks'] : '' ), ' ·' ),
            'is_roundtrip'               => false,
        ];
        if ( $a['cod'] > 0 ) {
            $body['money_collect'] = (int) round( $a['cod'] * 100 ); // agorot
            $body['cod_type']      = 0; // cash
        }
        $lines = [];
        foreach ( $o->get_items() as $item ) {
            $product = $item->get_product();
            $lines[] = [ 'name' => Qwoo_Couriers::clean( $item->get_name(), 80 ), 'quantity' => (int) $item->get_quantity(), 'sku' => $product ? Qwoo_Couriers::clean( $product->get_sku(), 40 ) : '' ];
            if ( count( $lines ) >= 30 ) break;
        }
        if ( $lines ) $body['line_items'] = $lines;

        $res = self::call( 'POST', '/tasks/create', $s['key'], $body );
        if ( is_wp_error( $res ) ) return $res;
        if ( $err = self::auth_error( $res ) ) return $err;
        $d    = $res['data'];
        $task = $d['task_id'] ?? ( $d['id'] ?? ( $d['task']['id'] ?? '' ) );
        if ( ! is_scalar( $task ) || ! preg_match( '/^\d{1,15}$/', (string) $task ) ) {
            $why = self::reason( $d );
            return new WP_Error( 'lw_create', $why !== '' ? 'Lionwheel: ' . $why : 'Lionwheel didn\'t open the shipment. Check the order\'s address and try again.' );
        }
        $number = '';
        foreach ( [ 'barcode', 'public_id' ] as $k ) {
            if ( isset( $d[ $k ] ) && is_scalar( $d[ $k ] ) && trim( (string) $d[ $k ] ) !== '' ) { $number = Qwoo_Couriers::clean( $d[ $k ], 40 ); break; }
        }
        return [
            'id'     => (string) $task,
            'number' => $number !== '' ? $number : (string) $task,
            'label'  => self::safe_url( $d['label'] ?? '' ),
            'url'    => self::safe_url( $d['tracking_link'] ?? '', false ),
        ];
    }

    /** An https address (labels: only on lionwheel.com, so the store never fetches anything else). */
    private static function safe_url( $url, $lionwheel_only = true ) {
        if ( ! is_string( $url ) || $url === '' ) return '';
        $clean = esc_url_raw( $url, [ 'https' ] );
        $host  = strtolower( (string) wp_parse_url( $clean, PHP_URL_HOST ) );
        if ( ! $clean || $host === '' || strlen( $clean ) > 600 ) return '';
        if ( $lionwheel_only && ! preg_match( '/(^|\.)lionwheel\.com$/', $host ) ) return '';
        return $clean;
    }

    /** The label PDF (from the address Lionwheel gave when the task was made). */
    public static function label( array $s, array $ship ) {
        $url = self::safe_url( $ship['label'] ?? '' );
        if ( $url === '' ) return new WP_Error( 'lw_label', 'Lionwheel didn\'t give a label for this shipment. Print it from Lionwheel.' );
        $res = wp_remote_get( $url, [ 'timeout' => 15, 'redirection' => 2, 'sslverify' => true, 'user-agent' => 'Openly-Store', 'limit_response_size' => 5 * MB_IN_BYTES ] );
        $pdf = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
        if ( strncmp( $pdf, '%PDF', 4 ) === 0 ) return [ 'pdf' => $pdf ];
        // Not a PDF file (a page that shows it): the owner opens Lionwheel's own address.
        return [ 'url' => $url ];
    }

    /** The task's status number, or a WP_Error. */
    public static function status( array $s, $task ) {
        if ( ! preg_match( '/^\d{1,15}$/', (string) $task ) ) return new WP_Error( 'lw_task', 'No task.' );
        $res = self::call( 'GET', '/tasks/show/' . $task, $s['key'] );
        if ( is_wp_error( $res ) ) return $res;
        if ( $res['code'] < 200 || $res['code'] >= 300 ) return new WP_Error( 'lw_status', 'No status.' );
        $d      = $res['data'];
        $status = $d['status'] ?? ( $d['task']['status'] ?? ( $d['data']['status'] ?? null ) );
        return is_numeric( $status ) ? (int) $status : new WP_Error( 'lw_status', 'No status.' );
    }

    public static function cancel( array $s, array $ship ) {
        if ( ! preg_match( '/^\d{1,15}$/', (string) $ship['id'] ) ) return new WP_Error( 'lw_cancel', 'This shipment has no task number.' );
        $res = self::call( 'PUT', '/tasks/' . $ship['id'] . '/update', $s['key'], [ 'status' => 4 ] );
        if ( is_wp_error( $res ) ) return $res;
        if ( $err = self::auth_error( $res ) ) return $err;
        if ( $res['code'] < 200 || $res['code'] >= 300 ) {
            $why = self::reason( $res['data'] );
            return new WP_Error( 'lw_cancel', 'Lionwheel didn\'t cancel the shipment' . ( $why !== '' ? ': ' . $why : '. Cancel it with your courier company.' ) );
        }
        return true;
    }
}

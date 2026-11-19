<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HFD (api.hfd.co.il/rest/v2), from HFD's own WordPress plugin: create a
 * shipment, its PDF label, ePost pickup points, cancel. No status lookup:
 * shoppers follow it on HFD's page (run.hfd.co.il/info/<randNumber>).
 * Not tried against HFD yet (no test account). SSL checks stay on.
 *
 * $s (from Qwoo_Couriers::settings()): { token, client }.
 */
class Qwoo_Courier_HFD {

    const API      = 'https://api.hfd.co.il/rest/v2';
    const TRACKING = 'https://run.hfd.co.il/info/';

    /** [ shipmentTypeCode, cargoTypeHaloch, cargoTypeHazor ] per way to send. */
    const CODES = [
        'door'    => [ 35, 10, 0 ],
        'pickup'  => [ 50, 11, 0 ],
        'cod'     => [ 37, 10, 100 ],
        'collect' => [ 35, 10, 0 ],
    ];

    /** One call. Returns { code, body, type } or a WP_Error. Nothing here is logged. */
    private static function call( $method, $path, $token, $body = null, $accept = 'application/json' ) {
        $args = [
            'method'      => $method,
            'timeout'     => 15,
            'redirection' => 0,
            'sslverify'   => true,
            'user-agent'  => 'Openly-Store',
            'headers'     => [ 'Authorization' => 'Bearer ' . $token, 'Accept' => $accept ],
        ];
        if ( $body !== null ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = wp_json_encode( $body );
        }
        $res = wp_remote_request( self::API . $path, $args );
        if ( is_wp_error( $res ) ) {
            return new WP_Error( 'hfd_unreachable', 'HFD didn\'t answer. Try again in a few minutes.' );
        }
        return [
            'code' => (int) wp_remote_retrieve_response_code( $res ),
            'body' => (string) wp_remote_retrieve_body( $res ),
            'type' => strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) ),
        ];
    }

    private static function json( $res ) {
        $d = json_decode( $res['body'], true );
        return is_array( $d ) ? $d : [];
    }

    /** HFD's reason, for the owner. */
    private static function reason( array $d ) {
        foreach ( [ 'errorMessage', 'message', 'details', 'error' ] as $k ) {
            if ( ! empty( $d[ $k ] ) ) return Qwoo_Couriers::their_message( $d[ $k ] );
        }
        return '';
    }

    private static function auth_error( $res ) {
        if ( in_array( $res['code'], [ 401, 403 ], true ) ) {
            return new WP_Error( 'hfd_auth', 'HFD didn\'t accept the token or the customer number. Check them with HFD.' );
        }
        return null;
    }

    /** Checks a token: a pickup point question only HFD's customers can ask. */
    public static function check( $token, $client ) {
        $res = self::call( 'POST', '/epost-points/get-list-by-address', $token, self::points_body( $client, 'תל אביב' ) );
        if ( is_wp_error( $res ) ) return $res;
        if ( $err = self::auth_error( $res ) ) return $err;
        if ( $res['code'] >= 500 ) return new WP_Error( 'hfd_down', 'HFD didn\'t answer. Try again in a few minutes.' );
        return true;
    }

    private static function points_body( $client, $city, $street = '' ) {
        return [
            'clientId'           => (int) $client,
            'city'               => $city,
            'street'             => $street,
            'shipmentDirection'  => 'מסירה',
            'language'           => 'HE',
            'openingHoursFormat' => false,
        ];
    }

    /** A city's ePost points, tidied: [ { id, name, type, address, hours[], lat, lng } ]. */
    public static function points( array $s, $city ) {
        $res = self::call( 'POST', '/epost-points/get-list-by-address', $s['token'], self::points_body( $s['client'], $city ) );
        if ( is_wp_error( $res ) ) return $res;
        if ( $err = self::auth_error( $res ) ) return $err;
        if ( $res['code'] < 200 || $res['code'] >= 300 ) return new WP_Error( 'hfd_points', 'HFD couldn\'t list the pickup points.' );
        $d    = self::json( $res );
        $list = isset( $d[0] ) ? $d : ( $d['items'] ?? ( $d['data'] ?? ( $d['points'] ?? ( $d['result'] ?? [] ) ) ) );
        $out  = [];
        foreach ( (array) $list as $p ) {
            if ( ! is_array( $p ) || ! isset( $p['spotId'] ) || ! is_scalar( $p['spotId'] ) ) continue;
            $id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $p['spotId'] );
            if ( $id === '' ) continue;
            $street  = Qwoo_Couriers::clean( trim( ( $p['street'] ?? '' ) . ' ' . ( $p['houseNo'] ?? '' ) ), 100 );
            $address = Qwoo_Couriers::clean( $p['spotAddress'] ?? '', 160 );
            if ( $address === '' ) $address = trim( $street . ( ! empty( $p['city'] ) ? ', ' . Qwoo_Couriers::clean( $p['city'], 60 ) : '' ), ', ' );
            $hours = [];
            foreach ( array_slice( (array) ( $p['openingHours'] ?? [] ), 0, 10 ) as $h ) {
                $line = is_array( $h ) ? implode( ' ', array_filter( array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $h ) ) ) : (string) $h;
                $line = Qwoo_Couriers::clean( $line, 80 );
                if ( $line !== '' ) $hours[] = $line;
            }
            $out[] = [
                'id'      => $id,
                'name'    => Qwoo_Couriers::clean( $p['spotName'] ?? '', 100 ) ?: $street,
                'type'    => Qwoo_Couriers::clean( $p['spotType'] ?? '', 40 ),
                'street'  => $street,
                'address' => $address,
                'hours'   => $hours,
                'lat'     => is_numeric( $p['latitude'] ?? null ) ? (float) $p['latitude'] : null,
                'lng'     => is_numeric( $p['longitude'] ?? null ) ? (float) $p['longitude'] : null,
            ];
            if ( count( $out ) >= 400 ) break;
        }
        return $out;
    }

    /** Opens the shipment. Returns { id, number, rand, url } or a WP_Error. */
    public static function create( array $s, WC_Order $o, array $a ) {
        $to    = $a['to'];
        $kind  = $a['cod'] > 0 ? 'cod' : $a['service'];
        $codes = self::CODES[ $kind ];
        $body  = [
            'clientNumber'        => (int) $s['client'],
            'mesiraIsuf'          => $a['service'] === 'collect' ? 'איסוף' : 'מסירה',
            'shipmentTypeCode'    => $codes[0],
            'cargoTypeHaloch'     => $codes[1],
            'cargoTypeHazor'      => $codes[2],
            'packsHaloch'         => (string) $a['packages'],
            'nameTo'              => $to['name'],
            'cityName'            => $to['city'],
            'streetName'          => $to['street'],
            'houseNum'            => $to['number'],
            'apartment'           => $to['apartment'],
            'floor'               => $to['floor'],
            'entrance'            => $to['entrance'],
            'streetCode'          => '',
            'telFirst'            => $to['phone'],
            'email'               => $to['email'],
            'referenceNum1'       => (string) $o->get_order_number(),
            'shipmentRemarks'     => $a['note'],
            'addressRemarks'      => Qwoo_Couriers::clean( $to['remarks'], 100 ),
            'productsPrice'       => $a['cod'] > 0 ? round( $a['cod'], 2 ) : 0,
        ];
        if ( $a['service'] === 'pickup' && ! empty( $a['point']['id'] ) ) {
            $body['pudoCodeDestination'] = ctype_digit( (string) $a['point']['id'] ) ? (int) $a['point']['id'] : (string) $a['point']['id'];
        }
        if ( $a['service'] === 'collect' ) $body['stageCode'] = 10;
        $items = [];
        foreach ( $o->get_items() as $item ) {
            $items[] = [ 'productName' => Qwoo_Couriers::clean( $item->get_name(), 80 ), 'quantity' => (int) $item->get_quantity() ];
            if ( count( $items ) >= 30 ) break;
        }
        if ( $items ) $body['orderItems'] = $items;

        $res = self::call( 'POST', '/shipments/create', $s['token'], $body );
        if ( is_wp_error( $res ) ) return $res;
        if ( $err = self::auth_error( $res ) ) return $err;
        $d      = self::json( $res );
        $number = $d['shipmentNumber'] ?? ( $d['data']['shipmentNumber'] ?? '' );
        if ( ! is_scalar( $number ) || ! preg_match( '/^\d{1,20}$/', (string) $number ) || (int) $number <= 0 ) {
            $why = self::reason( $d );
            return new WP_Error( 'hfd_create', $why !== '' ? 'HFD: ' . $why : 'HFD didn\'t open the shipment. Check the order\'s address and try again.' );
        }
        $rand = $d['randNumber'] ?? ( $d['data']['randNumber'] ?? '' );
        $rand = is_scalar( $rand ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $rand ) : '';
        return [
            'id'     => (string) $number,
            'number' => (string) $number,
            'rand'   => $rand,
            'url'    => $rand !== '' ? self::TRACKING . rawurlencode( $rand ) : '',
        ];
    }

    /** The PDF label: [ 'pdf' => bytes ] or a WP_Error. */
    public static function label( array $s, array $ship ) {
        $n = preg_replace( '/\D/', '', (string) $ship['id'] );
        if ( $n === '' ) return new WP_Error( 'hfd_label', 'This shipment has no number.' );
        $res = self::call( 'GET', '/shipments/' . $n . '/label', $s['token'], null, 'application/pdf' );
        if ( is_wp_error( $res ) ) return $res;
        if ( $err = self::auth_error( $res ) ) return $err;
        $pdf = $res['body'];
        if ( strncmp( $pdf, '%PDF', 4 ) !== 0 ) {
            // Some accounts answer JSON with the file in Base64String.
            $d   = self::json( $res );
            $b64 = $d['Base64String'] ?? ( $d['base64String'] ?? ( $d['data']['Base64String'] ?? '' ) );
            $pdf = is_string( $b64 ) ? (string) base64_decode( $b64, true ) : '';
        }
        if ( strncmp( $pdf, '%PDF', 4 ) !== 0 || strlen( $pdf ) > 5 * MB_IN_BYTES ) {
            return new WP_Error( 'hfd_label', 'HFD didn\'t send the label. Try again in a minute.' );
        }
        return [ 'pdf' => $pdf ];
    }

    /** Cancels the shipment (HFD turns this on per customer). */
    public static function cancel( array $s, array $ship ) {
        $n = preg_replace( '/\D/', '', (string) $ship['id'] );
        if ( $n === '' ) return new WP_Error( 'hfd_cancel', 'This shipment has no number.' );
        $res = self::call( 'DELETE', '/shipments/' . $n, $s['token'] );
        if ( is_wp_error( $res ) ) return $res;
        if ( $err = self::auth_error( $res ) ) return $err;
        $d  = self::json( $res );
        $ok = strtoupper( (string) ( $d['status'] ?? '' ) ) === 'OK' || ( $res['code'] >= 200 && $res['code'] < 300 && ( $d['status'] ?? 'OK' ) !== 'ERROR' );
        if ( ! $ok ) {
            $why = self::reason( $d );
            return new WP_Error( 'hfd_cancel', 'HFD didn\'t cancel the shipment' . ( $why !== '' ? ': ' . $why : '. Ask HFD to turn on cancelling for your account, or cancel it with them.' ) );
        }
        return true;
    }
}

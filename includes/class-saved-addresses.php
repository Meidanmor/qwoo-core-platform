<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Customers' saved addresses (My account → Account details, and "Use saved
 * address" at checkout).
 *
 *   GET    qwoo/v1/addresses        signed in: { addresses: [...] }
 *   POST   qwoo/v1/addresses        one address (with `id` = change it, without = add it);
 *                                   the same address again is not added twice
 *   DELETE qwoo/v1/addresses/{id}
 *
 * An address is a whole recipient: { id, label, first_name, last_name, phone,
 * address_1, city, postcode, country }, kept in user meta (up to MAX). Deleting
 * the account removes them with it.
 */
class Qwoo_Saved_Addresses {

    const META = '_qwoo_addresses';
    const MAX  = 10;

    // Field => longest length kept.
    const FIELDS = [
        'label'      => 40,
        'first_name' => 60,
        'last_name'  => 60,
        'phone'      => 30,
        'address_1'  => 120,
        'city'       => 60,
        'postcode'   => 20,
        'country'    => 2,
    ];

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes() {
        register_rest_route( 'qwoo/v1', '/addresses', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'rest_list' ],
                'permission_callback' => 'qwoo_require_login',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'rest_save' ],
                'permission_callback' => 'qwoo_require_login',
            ],
        ] );
        register_rest_route( 'qwoo/v1', '/addresses/(?P<id>ad_[a-z0-9]{8,16})', [
            'methods'             => 'DELETE',
            'callback'            => [ __CLASS__, 'rest_delete' ],
            'permission_callback' => 'qwoo_require_login',
        ] );
    }

    public static function of_user( int $user_id ): array {
        $list = get_user_meta( $user_id, self::META, true );
        if ( ! is_array( $list ) ) return [];
        $out = [];
        foreach ( $list as $a ) {
            if ( is_array( $a ) && ! empty( $a['id'] ) ) $out[] = self::clean( $a, (string) $a['id'] );
        }
        return array_slice( $out, 0, self::MAX );
    }

    private static function clean( array $in, string $id ): array {
        $a = [ 'id' => $id ];
        foreach ( self::FIELDS as $key => $max ) {
            $v = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? sanitize_text_field( (string) $in[ $key ] ) : '';
            $a[ $key ] = trim( mb_substr( $v, 0, $max ) );
        }
        $a['country'] = preg_match( '/^[A-Z]{2}$/', strtoupper( $a['country'] ) ) ? strtoupper( $a['country'] ) : '';
        return $a;
    }

    // Same place and recipient (letter case and spaces don't count): not saved twice.
    private static function same( array $a, array $b ): bool {
        foreach ( [ 'first_name', 'last_name', 'phone', 'address_1', 'city', 'postcode', 'country' ] as $k ) {
            $x = mb_strtolower( preg_replace( '/\s+/u', ' ', $a[ $k ] ?? '' ) );
            $y = mb_strtolower( preg_replace( '/\s+/u', ' ', $b[ $k ] ?? '' ) );
            if ( $x !== $y ) return false;
        }
        return true;
    }

    public static function rest_list() {
        return new WP_REST_Response( [ 'addresses' => self::of_user( get_current_user_id() ) ], 200 );
    }

    public static function rest_save( WP_REST_Request $r ) {
        $user_id = get_current_user_id();
        $limited = qwoo_rate_limit_check( 'addresses', 60, 10 * MINUTE_IN_SECONDS, 'u' . $user_id );
        if ( is_wp_error( $limited ) ) return $limited;

        $params = $r->get_json_params();
        if ( ! is_array( $params ) ) $params = $r->get_body_params();
        $id   = isset( $params['id'] ) && preg_match( '/^ad_[a-z0-9]{8,16}$/', (string) $params['id'] ) ? (string) $params['id'] : '';
        $list = self::of_user( $user_id );
        $new  = self::clean( $params, $id ?: 'ad_' . strtolower( wp_generate_password( 10, false ) ) );

        if ( $new['address_1'] === '' || $new['city'] === '' ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Please fill in the street and city.' ], 400 );
        }

        $at = -1;
        foreach ( $list as $i => $a ) {
            if ( $id && $a['id'] === $id ) { $at = $i; break; }
        }
        if ( $at >= 0 ) {
            $list[ $at ] = $new;
        } else {
            foreach ( $list as $a ) {
                if ( self::same( $a, $new ) ) {
                    // Already saved (checkout's "Save this address" with a saved one): nothing new.
                    return new WP_REST_Response( [ 'success' => true, 'address' => $a, 'addresses' => $list ], 200 );
                }
            }
            if ( count( $list ) >= self::MAX ) {
                return new WP_REST_Response( [ 'success' => false, 'code' => 'qwoo_addresses_full', 'message' => 'You can save up to 10 addresses. Delete one to add another.' ], 400 );
            }
            $list[] = $new;
        }
        update_user_meta( $user_id, self::META, $list );
        return new WP_REST_Response( [ 'success' => true, 'address' => $new, 'addresses' => $list ], 200 );
    }

    public static function rest_delete( WP_REST_Request $r ) {
        $user_id = get_current_user_id();
        $id      = (string) $r['id'];
        $list    = array_values( array_filter( self::of_user( $user_id ), function ( $a ) use ( $id ) {
            return $a['id'] !== $id;
        } ) );
        update_user_meta( $user_id, self::META, $list );
        return new WP_REST_Response( [ 'success' => true, 'addresses' => $list ], 200 );
    }
}

Qwoo_Saved_Addresses::init();

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema;

/**
 * Appointments on the storefront (the Booking block, the booking page, My account):
 * the public routes, and a paid booking's way through the cart and the checkout.
 *
 *   GET  appointments/services       what can be booked (the block's list)
 *   GET  appointments/times          free times of a service between two dates
 *   POST appointments/hold           keeps a time for 10 minutes → { hold }
 *   POST appointments/release        lets it go
 *   POST appointments/details        the form (a paid booking, before the cart)
 *   POST appointments/book           the form + confirm (free, or pay there)
 *   GET  appointments/manage         a booking by its link (id + key)
 *   POST appointments/manage         cancel or move it by its link
 *   GET  appointments/ics            the booking as a calendar file (id + key)
 *   GET  appointments/feed           the owner's subscribe link (key)
 *   GET  appointments/mine           the signed-in shopper's bookings
 *
 * A paid booking: the block holds the time, saves the form, then adds the service's
 * hidden product with qwoo_booking { hold } (Store API add-item). The cart line keeps
 * the hold for 30 minutes, the placed order for 60; paying confirms it.
 * Limits are counted in the callbacks, never in permission_callback.
 */
class Qwoo_Appointments_Shop {

    const NS   = 'qwoo/v1';
    const ITEM = 'qwoo_booking';
    private static $listing = false;

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
        add_action( 'init', [ __CLASS__, 'register_store_api' ] );
        add_filter( 'woocommerce_store_api_add_to_cart_data', [ __CLASS__, 'add_to_cart_data' ], 10, 2 );
        add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_add' ], 10, 6 );
        add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'set_prices' ], 20 );
        add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'item_data' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'order_line' ], 10, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'on_placed' ], 6 );
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_status' ], 20, 4 );
        add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ __CLASS__, 'hide_item_meta' ], 20, 2 );
        add_action( 'woocommerce_remove_cart_item', [ __CLASS__, 'on_remove' ], 10, 2 );
        // The hidden products behind paid services stay out of product lists and search.
        add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'mark_product_list' ], 10, 3 );
        add_action( 'pre_get_posts', [ __CLASS__, 'hide_from_lists' ] );
    }

    private static function l( string $en, string $he ): string { return Qwoo_Appointments::l( $en, $he ); }

    private static function limited( string $action, int $max, int $seconds ): bool {
        return function_exists( 'qwoo_rate_limit_check' ) && is_wp_error( qwoo_rate_limit_check( 'appt_' . $action, $max, $seconds ) );
    }

    private static function slow_down(): WP_REST_Response {
        return new WP_REST_Response( [ 'message' => self::l( 'Too many tries. Please wait a minute.', 'יותר מדי ניסיונות. אפשר לחכות דקה.' ) ], 429 );
    }

    private static function fail( WP_Error $e ): WP_REST_Response {
        $data = $e->get_error_data();
        return new WP_REST_Response( [ 'code' => $e->get_error_code(), 'message' => $e->get_error_message() ], (int) ( is_array( $data ) ? ( $data['status'] ?? 400 ) : 400 ) );
    }

    private static function no_cache( $data, int $status = 200 ): WP_REST_Response {
        $r = new WP_REST_Response( $data, $status );
        $r->header( 'Cache-Control', 'no-store, max-age=0' );
        return $r;
    }

    public static function routes() {
        $open = [ 'permission_callback' => '__return_true' ];
        register_rest_route( self::NS, '/appointments/services', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_services' ] ] + $open );
        register_rest_route( self::NS, '/appointments/times', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_times' ] ] + $open );
        register_rest_route( self::NS, '/appointments/hold', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_hold' ] ] + $open );
        register_rest_route( self::NS, '/appointments/release', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_release' ] ] + $open );
        register_rest_route( self::NS, '/appointments/details', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_details' ] ] + $open );
        register_rest_route( self::NS, '/appointments/book', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_book' ] ] + $open );
        register_rest_route( self::NS, '/appointments/manage', [ 'methods' => [ 'GET', 'POST' ], 'callback' => [ __CLASS__, 'rest_manage' ] ] + $open );
        register_rest_route( self::NS, '/appointments/ics', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_ics' ] ] + $open );
        register_rest_route( self::NS, '/appointments/feed', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_feed' ] ] + $open );
        register_rest_route( self::NS, '/appointments/mine', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_mine' ], 'permission_callback' => 'qwoo_require_login' ] );
    }

    /* ---------------- what shoppers see ---------------- */

    public static function public_service( array $s ): array {
        return [
            'id' => (int) $s['id'], 'name' => $s['name'], 'description' => $s['description'], 'category' => $s['category'],
            'image' => $s['image'] ? (string) wp_get_attachment_image_url( $s['image'], 'medium_large' ) : '',
            'duration' => (int) $s['duration'], 'price' => (float) $s['price'], 'pay' => $s['pay'], 'due_now' => Qwoo_Appointments::due_now( $s ),
            'capacity' => (int) $s['capacity'], 'max_seats' => (int) $s['max_seats'],
            'place' => [ 'type' => $s['place']['type'], 'text' => Qwoo_Appointments::place_text( $s ) ],
            'questions' => $s['questions'], 'product_id' => in_array( $s['pay'], [ 'full', 'deposit' ], true ) ? (int) $s['product_id'] : 0,
            'hidden' => $s['status'] === 'hidden',
        ];
    }

    public static function rest_services( WP_REST_Request $r ) {
        if ( ! Qwoo_Appointments::active() ) return self::no_cache( [ 'enabled' => false, 'services' => [] ] );
        $want = absint( $r->get_param( 'id' ) );
        $list = [];
        foreach ( Qwoo_Appointments::services( true ) as $s ) {
            if ( $s['status'] === 'hidden' && $s['id'] !== $want ) continue;
            $list[] = self::public_service( $s );
        }
        $settings = Qwoo_Appointments::settings();
        return self::no_cache( [
            'enabled' => true, 'services' => $list, 'tz' => wp_timezone_string(), 'currency' => get_woocommerce_currency(),
            'week_start' => (int) get_option( 'start_of_week', 0 ), 'terms' => $settings['terms'], 'confirm_text' => $settings['confirm_text'],
            'cancel_hours' => (int) $settings['rules']['cancel_hours'], 'today' => wp_date( 'Y-m-d' ),
        ] );
    }

    private static function service_from( $id ): ?array {
        $s = Qwoo_Appointments::service( absint( $id ) );
        return $s && $s['status'] !== 'off' && Qwoo_Appointments::active() ? $s : null;
    }

    public static function rest_times( WP_REST_Request $r ) {
        if ( self::limited( 'times', 120, 60 ) ) return self::slow_down();
        $s = self::service_from( $r->get_param( 'service' ) );
        if ( ! $s ) return self::no_cache( [ 'message' => self::l( 'This service can\'t be booked right now.', 'אי אפשר לקבוע את השירות הזה כרגע.' ) ], 404 );
        Qwoo_Appointments::expire_holds();
        $days = Qwoo_Appointments::free_times( $s, (string) $r->get_param( 'from' ), (string) $r->get_param( 'to' ), [ 'seats' => max( 1, (int) $r->get_param( 'seats' ) ) ] );
        return self::no_cache( [ 'days' => (object) $days ] );
    }

    public static function rest_hold( WP_REST_Request $r ) {
        if ( self::limited( 'hold', 15, 600 ) ) return self::slow_down();
        $in = (array) $r->get_json_params();
        $s  = self::service_from( $in['service'] ?? 0 );
        if ( ! $s ) return self::no_cache( [ 'message' => self::l( 'This service can\'t be booked right now.', 'אי אפשר לקבוע את השירות הזה כרגע.' ) ], 404 );
        $start = Qwoo_Appointments::parse_start( $in['start'] ?? '' );
        if ( ! $start ) return self::no_cache( [ 'message' => self::l( 'Pick a time.', 'צריך לבחור זמן.' ) ], 400 );
        $held = Qwoo_Appointments::hold( $s, $start, (int) ( $in['seats'] ?? 1 ) );
        return is_wp_error( $held ) ? self::fail( $held ) : self::no_cache( $held );
    }

    public static function rest_release( WP_REST_Request $r ) {
        $in = (array) $r->get_json_params();
        Qwoo_Appointments::release_hold( (string) ( $in['hold'] ?? '' ) );
        return self::no_cache( [ 'ok' => true ] );
    }

    /** The held booking behind a token, still held, with its service. */
    private static function held( string $token ) {
        $b = Qwoo_Appointments::booking_by_hold( $token );
        if ( ! $b || $b['status'] !== 'held' || strtotime( $b['hold_until'] . ' UTC' ) < time() ) {
            return new WP_Error( 'expired', self::l( 'We couldn\'t keep that time any longer. Please pick it again.', 'לא יכולנו לשמור את הזמן הזה יותר. אפשר לבחור אותו שוב.' ), [ 'status' => 410 ] );
        }
        $s = Qwoo_Appointments::service( (int) $b['service_id'] );
        if ( ! $s ) return new WP_Error( 'service', self::l( 'This service can\'t be booked right now.', 'אי אפשר לקבוע את השירות הזה כרגע.' ), [ 'status' => 404 ] );
        return [ $b, $s ];
    }

    public static function rest_details( WP_REST_Request $r ) {
        if ( self::limited( 'details', 30, 600 ) ) return self::slow_down();
        $in   = (array) $r->get_json_params();
        $held = self::held( (string) ( $in['hold'] ?? '' ) );
        if ( is_wp_error( $held ) ) return self::fail( $held );
        [ $b, $s ] = $held;
        $d = Qwoo_Appointments::clean_details( $s, $in );
        if ( is_wp_error( $d ) ) return self::fail( $d );
        Qwoo_Appointments::save_details( (int) $b['id'], $d );
        return self::no_cache( [ 'ok' => true, 'product_id' => (int) $s['product_id'] ] );
    }

    public static function rest_book( WP_REST_Request $r ) {
        if ( self::limited( 'book', 10, 600 ) ) return self::slow_down();
        $in   = (array) $r->get_json_params();
        $held = self::held( (string) ( $in['hold'] ?? '' ) );
        if ( is_wp_error( $held ) ) return self::fail( $held );
        [ $b, $s ] = $held;
        if ( in_array( $s['pay'], [ 'full', 'deposit' ], true ) ) return self::no_cache( [ 'message' => self::l( 'This service is paid at checkout.', 'השירות הזה משולם בקופה.' ) ], 400 );
        $d = Qwoo_Appointments::clean_details( $s, $in );
        if ( is_wp_error( $d ) ) return self::fail( $d );
        // Honeypot: a field people never see.
        if ( trim( (string) ( $in['website'] ?? '' ) ) !== '' ) return self::no_cache( [ 'message' => self::l( 'Please try again.', 'אפשר לנסות שוב.' ) ], 400 );
        Qwoo_Appointments::save_details( (int) $b['id'], $d );
        if ( ! Qwoo_Appointments::confirm( (int) $b['id'] ) ) return self::no_cache( [ 'message' => self::l( 'That time was just taken. Please pick another one.', 'הזמן הזה נתפס הרגע. אפשר לבחור זמן אחר.' ) ], 409 );
        $b = Qwoo_Appointments::booking( (int) $b['id'] );
        return self::no_cache( self::shopper_view( $b ) + [ 'key' => Qwoo_Appointments::key( (int) $b['id'], (string) $b['email'] ) ] );
    }

    /** A booking as its shopper sees it. */
    public static function shopper_view( array $b ): array {
        $s     = Qwoo_Appointments::service( (int) $b['service_id'] );
        $start = (int) strtotime( $b['start_utc'] . ' UTC' );
        $on    = in_array( $b['status'], [ 'confirmed', 'pending' ], true ) && $start > time();
        $hours = $s && array_key_exists( 'cancel_hours', $s['rules'] ) ? (int) $s['rules']['cancel_hours'] : (int) Qwoo_Appointments::settings()['rules']['cancel_hours'];
        $moves = (int) Qwoo_Appointments::settings()['rules']['moves'];
        $in_time = $start - $hours * HOUR_IN_SECONDS > time();
        $card  = Qwoo_Appointments::email_card( $b );
        return [
            'id' => (int) $b['id'], 'service_id' => (int) $b['service_id'], 'service' => $s ? $s['name'] : '', 'status' => (string) $b['status'],
            'start' => gmdate( 'Y-m-d\TH:i:s\Z', $start ), 'end' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $b['end_utc'] . ' UTC' ) ),
            'local' => wp_date( 'Y-m-d H:i', $start ), 'tz' => wp_timezone_string(), 'seats' => (int) $b['seats'], 'place' => (string) $b['place'],
            'video' => $s && $s['place']['type'] === 'video' && $b['status'] === 'confirmed' ? ( (string) $b['video'] ?: (string) $s['place']['link'] ) : '',
            'price' => (float) $b['price'], 'due_now' => (float) $b['due_now'], 'currency' => (string) $b['currency'], 'paid' => (bool) $b['order_id'],
            'name' => (string) $b['name'], 'rows' => $card['rows'],
            'can_cancel' => $on && $in_time, 'can_move' => $on && $in_time && (int) $b['moves'] < $moves && $s && $s['status'] !== 'off',
            'cancel_hours' => $hours, 'google' => $card['google'], 'outlook' => $card['outlook'],
            'ics' => $b['email'] !== '' ? rtrim( (string) ( class_exists( 'Qwoo_Technical_Settings' ) ? Qwoo_Technical_Settings::get_primary_frontend_domain() : home_url() ), '/' ) . '/wp-json/qwoo/v1/appointments/ics?id=' . (int) $b['id'] . '&k=' . Qwoo_Appointments::key( (int) $b['id'], (string) $b['email'] ) : '',
            'refund' => $s ? ( array_key_exists( 'refund', $s['rules'] ) ? $s['rules']['refund'] : Qwoo_Appointments::settings()['rules']['refund'] ) : 'keep',
        ];
    }

    public static function rest_manage( WP_REST_Request $r ) {
        if ( self::limited( 'manage', 40, 600 ) ) return self::slow_down();
        $in = $r->get_method() === 'POST' ? (array) $r->get_json_params() : $r->get_query_params();
        $b  = Qwoo_Appointments::booking( absint( $in['id'] ?? 0 ) );
        // The same answer for a wrong id or a wrong key.
        if ( ! $b || ! Qwoo_Appointments::check_key( $b, (string) ( $in['k'] ?? '' ) ) ) {
            return self::no_cache( [ 'message' => self::l( 'This link doesn\'t open a booking. Check the link in your email.', 'הקישור הזה לא פותח תור. כדאי לבדוק את הקישור במייל.' ) ], 404 );
        }
        if ( $r->get_method() === 'POST' ) {
            $view = self::shopper_view( $b );
            $act  = (string) ( $in['action'] ?? '' );
            if ( $act === 'cancel' ) {
                if ( ! $view['can_cancel'] ) return self::no_cache( [ 'message' => self::l( 'It\'s too late to cancel online. Please contact us.', 'כבר מאוחר לבטל באתר. אפשר ליצור איתנו קשר.' ) ], 403 );
                $done = Qwoo_Appointments::cancel( (int) $b['id'], 'shopper' );
                if ( is_wp_error( $done ) ) return self::fail( $done );
            } elseif ( $act === 'move' ) {
                if ( ! $view['can_move'] ) return self::no_cache( [ 'message' => self::l( 'This booking can\'t be moved online. Please contact us.', 'אי אפשר להזיז את התור הזה באתר. אפשר ליצור איתנו קשר.' ) ], 403 );
                $done = Qwoo_Appointments::move( (int) $b['id'], Qwoo_Appointments::parse_start( $in['start'] ?? '' ), 'shopper' );
                if ( is_wp_error( $done ) ) return self::fail( $done );
            } else {
                return self::no_cache( [ 'message' => 'Unknown action.' ], 400 );
            }
            $b = Qwoo_Appointments::booking( (int) $b['id'] );
        }
        return self::no_cache( self::shopper_view( $b ) );
    }

    public static function rest_ics( WP_REST_Request $r ) {
        if ( self::limited( 'ics', 30, 600 ) ) return self::slow_down();
        $b = Qwoo_Appointments::booking( absint( $r->get_param( 'id' ) ) );
        if ( ! $b || ! Qwoo_Appointments::check_key( $b, (string) $r->get_param( 'k' ) ) ) return new WP_REST_Response( [ 'message' => 'Not found.' ], 404 );
        self::calendar_out( Qwoo_Appointments::ics_for( $b ), 'booking-' . (int) $b['id'] . '.ics' );
    }

    public static function rest_feed( WP_REST_Request $r ) {
        if ( self::limited( 'feed', 60, 3600 ) ) return self::slow_down();
        $text = Qwoo_Appointments::feed( (string) $r->get_param( 'k' ) );
        if ( $text === null ) return new WP_REST_Response( [ 'message' => 'Not found.' ], 404 );
        self::calendar_out( $text, 'bookings.ics', false );
    }

    private static function calendar_out( string $text, string $name, bool $download = true ): void {
        nocache_headers();
        header( 'Content-Type: text/calendar; charset=utf-8' );
        header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . $name . '"' );
        header( 'X-Robots-Tag: noindex' );
        header( 'X-Content-Type-Options: nosniff' );
        echo $text; // phpcs:ignore -- a calendar file, built from escaped values
        exit;
    }

    public static function rest_mine( WP_REST_Request $r ) {
        global $wpdb;
        $user = wp_get_current_user();
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . Qwoo_Appointments::bookings_table() . " WHERE (customer_id = %d OR (email <> '' AND email = %s)) AND status IN ('confirmed','pending','done','noshow','cancelled') ORDER BY start_utc DESC LIMIT 100",
            (int) $user->ID, (string) $user->user_email
        ), ARRAY_A );
        $out = [];
        foreach ( $rows as $b ) {
            $v = self::shopper_view( $b );
            if ( $b['email'] !== '' ) $v['key'] = Qwoo_Appointments::key( (int) $b['id'], (string) $b['email'] );
            $out[] = $v;
        }
        return self::no_cache( [ 'bookings' => $out ] );
    }

    /* ---------------- the cart and the checkout ---------------- */

    /** Cart lines say which booking they hold (the storefront keeps each one as its own line). */
    public static function register_store_api() {
        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) return;
        woocommerce_store_api_register_endpoint_data( [
            'endpoint'        => CartItemSchema::IDENTIFIER,
            'namespace'       => 'qwoo_booking',
            'data_callback'   => [ __CLASS__, 'cart_item_ext' ],
            'schema_callback' => static fn() => [],
        ] );
    }

    public static function cart_item_ext( $item ): array {
        $hold = is_array( $item ) ? (string) ( $item[ self::ITEM ]['hold'] ?? '' ) : '';
        $id   = is_array( $item ) ? (int) ( $item[ self::ITEM ]['id'] ?? 0 ) : 0;
        $b    = $id ? Qwoo_Appointments::booking( $id ) : null;
        if ( $hold === '' || ! $b ) return [];
        return [
            'key'   => $hold,
            'hold'  => $hold,
            'when'  => Qwoo_Appointments::when_text( $b ),
            'place' => (string) $b['place'],
            'until' => $b['hold_until'] ? gmdate( 'c', strtotime( $b['hold_until'] . ' UTC' ) ) : '',
            'kept'  => in_array( $b['status'], [ 'held', 'pending', 'confirmed' ], true ),
        ];
    }

    private static function is_booking_product( $product ): bool {
        $id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
        return $id && in_array( $id, Qwoo_Appointments::product_ids(), true );
    }

    public static function add_to_cart_data( $data, $request ) {
        $product = wc_get_product( (int) ( $data['id'] ?? 0 ) );
        if ( ! $product || ! self::is_booking_product( $product ) ) return $data;
        $given = $request['qwoo_booking'] ?? null;
        $held  = self::held( is_array( $given ) ? (string) ( $given['hold'] ?? '' ) : '' );
        if ( is_wp_error( $held ) ) throw new RouteException( 'qwoo_booking_expired', esc_html( $held->get_error_message() ), 410 );
        [ $b, $s ] = $held;
        if ( (int) $s['product_id'] !== $product->get_id() || $b['email'] === '' ) {
            throw new RouteException( 'qwoo_booking_details', esc_html( self::l( 'Choose a time and fill in your details first.', 'צריך לבחור זמן ולמלא פרטים קודם.' ) ), 400 );
        }
        Qwoo_Appointments::extend_hold( (int) $b['id'], Qwoo_Appointments::CART_MIN );
        $data['quantity']                     = 1;
        $data['cart_item_data'][ self::ITEM ] = [ 'id' => (int) $b['id'], 'hold' => (string) $b['hold_token'] ];
        return $data;
    }

    public static function validate_add( $ok, $product_id, $quantity, $variation_id = 0, $variations = [], $cart_item_data = [] ) {
        if ( $ok && self::is_booking_product( (int) $product_id ) && empty( $cart_item_data[ self::ITEM ] ) ) {
            wc_add_notice( self::l( 'Book this on the booking page.', 'את זה קובעים בעמוד קביעת התור.' ), 'error' );
            return false;
        }
        return $ok;
    }

    public static function set_prices( $cart ) {
        if ( ! $cart instanceof WC_Cart ) return;
        foreach ( $cart->get_cart() as $item ) {
            $id = (int) ( $item[ self::ITEM ]['id'] ?? 0 );
            if ( ! $id || ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) continue;
            $b = Qwoo_Appointments::booking( $id );
            if ( $b ) $item['data']->set_price( (float) $b['due_now'] );
        }
    }

    public static function item_data( $data, $item ) {
        $id = (int) ( $item[ self::ITEM ]['id'] ?? 0 );
        $b  = $id ? Qwoo_Appointments::booking( $id ) : null;
        if ( ! $b ) return $data;
        $data[] = [ 'key' => self::l( 'When', 'מתי' ), 'value' => Qwoo_Appointments::when_text( $b ) ];
        if ( (int) $b['seats'] > 1 ) $data[] = [ 'key' => self::l( 'Places', 'מקומות' ), 'value' => (string) (int) $b['seats'] ];
        if ( $b['place'] !== '' ) $data[] = [ 'key' => self::l( 'Where', 'איפה' ), 'value' => (string) $b['place'] ];
        $rest = (float) $b['price'] - (float) $b['due_now'];
        if ( $rest > 0 ) $data[] = [ 'key' => self::l( 'To pay there', 'לתשלום במקום' ), 'value' => html_entity_decode( wp_strip_all_tags( wc_price( $rest ) ), ENT_QUOTES ) ];
        return $data;
    }

    public static function on_remove( $key, $cart ) {
        $item = $cart instanceof WC_Cart ? ( $cart->removed_cart_contents[ $key ] ?? $cart->get_cart_item( $key ) ) : null;
        $hold = is_array( $item ) ? (string) ( $item[ self::ITEM ]['hold'] ?? '' ) : '';
        if ( $hold !== '' ) Qwoo_Appointments::release_hold( $hold );
    }

    public static function order_line( $item, $cart_item_key, $values ) {
        $id = (int) ( $values[ self::ITEM ]['id'] ?? 0 );
        $b  = $id ? Qwoo_Appointments::booking( $id ) : null;
        if ( ! $b ) return;
        $item->add_meta_data( '_qwoo_booking', $id, true );
        $item->add_meta_data( self::l( 'When', 'מתי' ), Qwoo_Appointments::when_text( $b ), true );
        if ( $b['place'] !== '' ) $item->add_meta_data( self::l( 'Where', 'איפה' ), (string) $b['place'], true );
    }

    public static function hide_item_meta( $meta, $item ) {
        return array_filter( (array) $meta, static fn( $m ) => ( $m->key ?? '' ) !== '_qwoo_booking' );
    }

    private static function bookings_of( WC_Order $order ): array {
        $ids = [];
        foreach ( $order->get_items() as $item ) {
            $id = (int) $item->get_meta( '_qwoo_booking' );
            if ( $id ) $ids[] = $id;
        }
        return $ids;
    }

    /** Placing the order: the times are still held (or free again), and stay held while it's paid. */
    public static function on_placed( $order ) {
        if ( ! $order instanceof WC_Order ) return;
        global $wpdb;
        foreach ( self::bookings_of( $order ) as $id ) {
            $b = Qwoo_Appointments::booking( $id );
            if ( ! $b ) continue;
            $mine = (int) $b['order_id'] === $order->get_id() || ! $b['order_id'];
            $ok   = $mine && in_array( $b['status'], [ 'held', 'pending', 'confirmed' ], true ) && ( $b['status'] === 'confirmed' || ! $b['hold_until'] || strtotime( $b['hold_until'] . ' UTC' ) > time() );
            if ( ! $ok ) {
                throw new RouteException( 'qwoo_booking_expired', esc_html( self::l( 'We couldn\'t keep the time you chose any longer. Remove it from your cart and choose it again.', 'לא יכולנו לשמור יותר את הזמן שבחרת. אפשר להסיר אותו מהעגלה ולבחור אותו שוב.' ) ), 409 );
            }
            if ( $b['status'] !== 'confirmed' ) {
                $wpdb->update( Qwoo_Appointments::bookings_table(), [
                    'status' => 'pending', 'order_id' => $order->get_id(), 'hold_until' => gmdate( 'Y-m-d H:i:s', time() + Qwoo_Appointments::ORDER_MIN * 60 ),
                    'customer_id' => (int) $order->get_customer_id() ?: (int) $b['customer_id'], 'updated' => gmdate( 'Y-m-d H:i:s' ),
                ], [ 'id' => $id ] );
            }
        }
    }

    public static function on_status( $order_id, $from, $to, $order ) {
        if ( ! $order instanceof WC_Order ) $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        global $wpdb;
        foreach ( self::bookings_of( $order ) as $id ) {
            $b = Qwoo_Appointments::booking( $id );
            if ( ! $b ) continue;
            if ( in_array( $to, wc_get_is_paid_statuses(), true ) ) {
                if ( ! Qwoo_Appointments::confirm( $id, $order->get_id() ) ) {
                    $order->add_order_note( self::l( 'This order paid for a booking whose time was taken in the meantime. Contact the customer to choose another time (Appointments → Calendar).', 'ההזמנה הזו שילמה על תור שהזמן שלו נתפס בינתיים. כדאי ליצור קשר עם הלקוח ולבחור זמן אחר (תורים ← יומן).' ) );
                }
            } elseif ( $to === 'on-hold' && $b['status'] === 'pending' ) {
                // Waiting for a bank transfer: the time stays kept until the owner decides.
                $wpdb->update( Qwoo_Appointments::bookings_table(), [ 'hold_until' => null ], [ 'id' => $id ] );
            } elseif ( in_array( $to, [ 'cancelled', 'failed', 'refunded' ], true ) ) {
                Qwoo_Appointments::cancel( $id, 'order', $b['status'] === 'confirmed' );
            }
        }
    }

    /* ---------------- out of product lists ---------------- */

    public static function mark_product_list( $response, $handler, $request ) {
        if ( $request instanceof WP_REST_Request && $request->get_route() === '/wc/store/v1/products' && ! $request->get_param( 'include' ) ) {
            self::$listing = true;
        }
        return $response;
    }

    public static function hide_from_lists( $query ) {
        if ( ! $query instanceof WP_Query ) return;
        $type = $query->get( 'post_type' );
        if ( $type !== 'product' && ! in_array( 'product', (array) $type, true ) ) return;
        // Product lists and searches on the storefront (the Store API), never a product asked for by id.
        if ( ! self::$listing && ! ( $query->is_search() && ! is_admin() ) ) return;
        $hidden = Qwoo_Appointments::product_ids();
        if ( $hidden ) $query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $hidden ) );
    }
}

Qwoo_Appointments_Shop::init();

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Grow (Meshulam) payments for Israeli stores, through Grow's Light API
 * (https://developers.grow.business/reference/introduction).
 *
 * Each method is its own WooCommerce payment method (Qwoo_Grow_Gateway):
 * credit card, Bit, Apple Pay and Google Pay, each with the page code Grow
 * gives the business for it. The owner enters their own Grow identifiers
 * (userId + page codes) in the dashboard; there's no platform apiKey.
 *
 * The flow (Grow's "payment process"):
 *   1. Checkout: createPaymentProcess answers with a payment page address,
 *      which the Store API returns as payment_result.redirect_url. The
 *      order waits "pending payment".
 *   2. Grow calls notifyUrl (qwoo-grow/v1/notify, outside the "qwoo"
 *      namespace, which needs the storefront's proxy secret). The address
 *      carries the order id and a signature; the payload itself isn't
 *      trusted (Grow doesn't sign it): the store asks Grow for the process
 *      (getPaymentProcessInfo, with the process token only it and Grow
 *      know) and checks it was paid in full before completing the order.
 *   3. Then approveTransaction, which Grow requires after every payment.
 * The storefront can also ask qwoo-grow/v1/check (order id + key) when the
 * shopper comes back, in case Grow's call is late.
 *
 * Settings: option qwoo_grow. Order meta: _qwoo_grow_* (process and
 * transaction identifiers; never shown to the shopper).
 */
class Qwoo_Grow {

    const OPTION = 'qwoo_grow';

    /** key => [ Grow transactionTypeId, default name ]. */
    const METHODS = [
        'card'      => [ 1, 'Credit card' ],
        'bit'       => [ 6, 'Bit' ],
        'applepay'  => [ 13, 'Apple Pay' ],
        'googlepay' => [ 14, 'Google Pay' ],
    ];

    /** Grow refuses Bit payments above this (error 271). */
    const BIT_MAX = 3600;

    const LIVE_URL    = 'https://secure.meshulam.co.il/api/light/server/1.0/';
    const SANDBOX_URL = 'https://sandbox.meshulam.co.il/api/light/server/1.0/';

    public static function init() {
        add_filter( 'woocommerce_payment_gateways', [ __CLASS__, 'register_gateways' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_gateways( $gateways ) {
        require_once __DIR__ . '/class-grow-gateway.php';
        foreach ( array_keys( self::METHODS ) as $key ) {
            $gateways[] = new Qwoo_Grow_Gateway( $key );
        }
        return $gateways;
    }

    /* ---------------- settings ---------------- */

    /** The saved settings, with every field present. */
    public static function settings() {
        $saved = get_option( self::OPTION, [] );
        $saved = is_array( $saved ) ? $saved : [];
        $out   = [
            'test'              => ! empty( $saved['test'] ),
            'user_id'           => (string) ( $saved['user_id'] ?? '' ),
            'max_payments'      => max( 1, min( 12, (int) ( $saved['max_payments'] ?? 1 ) ) ),
            'installments_from' => (string) ( $saved['installments_from'] ?? '' ),
            'invoices'          => ! empty( $saved['invoices'] ),
            'methods'           => [],
        ];
        foreach ( self::METHODS as $key => [ , $name ] ) {
            $m                      = (array) ( $saved['methods'][ $key ] ?? [] );
            $out['methods'][ $key ] = [
                'enabled'   => ! empty( $m['enabled'] ),
                'page_code' => (string) ( $m['page_code'] ?? '' ),
                'title'     => (string) ( $m['title'] ?? '' ) !== '' ? (string) $m['title'] : Qwoo_I18n::t( $name ),
            ];
        }
        return $out;
    }

    /** One method's settings: { enabled, page_code, title }. */
    public static function method( $key ) {
        return self::settings()['methods'][ $key ] ?? [ 'enabled' => false, 'page_code' => '', 'title' => '' ];
    }

    /** Whether a method can take payments: turned on, identifiers in, prices in shekels. */
    public static function ready( $key ) {
        $s = self::settings();
        $m = $s['methods'][ $key ] ?? null;
        return $m && $m['enabled'] && $s['user_id'] !== '' && $m['page_code'] !== '' && get_woocommerce_currency() === 'ILS';
    }

    /** For the dashboard (Settings → Payments). Grow's identifiers aren't secrets: they only open payment pages that pay this business. */
    public static function dashboard_state() {
        $s       = self::settings();
        $methods = [];
        foreach ( self::METHODS as $key => [ , $name ] ) {
            $methods[ $key ] = $s['methods'][ $key ] + [ 'name' => $name ];
        }
        return [
            'currency'          => get_woocommerce_currency(),
            'storefront'        => self::storefront() !== '',
            'test'              => $s['test'],
            'user_id'           => $s['user_id'],
            'max_payments'      => $s['max_payments'],
            'installments_from' => $s['installments_from'],
            'invoices'          => $s['invoices'],
            'methods'           => $methods,
        ];
    }

    /**
     * Saves what the dashboard sent (fields not sent keep their value):
     * { test, user_id, max_payments, installments_from, invoices,
     *   methods: { card: { enabled, page_code, title }, … } }.
     * Returns null, or an error message for the owner.
     */
    public static function save( array $in ) {
        $s  = self::settings();
        $id = static fn( $v ) => trim( (string) $v );

        if ( array_key_exists( 'test', $in ) ) {
            $s['test'] = ! empty( $in['test'] );
        }
        if ( array_key_exists( 'user_id', $in ) ) {
            $s['user_id'] = $id( $in['user_id'] );
            if ( $s['user_id'] !== '' && ! preg_match( '/^[A-Za-z0-9]{6,40}$/', $s['user_id'] ) ) {
                return 'The business ID (userId) is the code Grow gave you: letters and numbers only. Copy it again from Grow.';
            }
        }
        if ( array_key_exists( 'max_payments', $in ) ) {
            $n = (int) $in['max_payments'];
            if ( $n < 1 || $n > 12 ) {
                return 'Installments can go up to 12 payments.';
            }
            $s['max_payments'] = $n;
        }
        if ( array_key_exists( 'installments_from', $in ) ) {
            $from = trim( (string) $in['installments_from'] );
            if ( $from !== '' && ( ! is_numeric( $from ) || (float) $from < 0 || (float) $from > 1000000 ) ) {
                return 'Enter the order total from which installments are offered, or leave it empty.';
            }
            $s['installments_from'] = $from === '' ? '' : wc_format_decimal( $from, 2 );
        }
        if ( array_key_exists( 'invoices', $in ) ) {
            $s['invoices'] = ! empty( $in['invoices'] );
        }
        foreach ( (array) ( $in['methods'] ?? [] ) as $key => $m ) {
            if ( ! isset( self::METHODS[ $key ] ) || ! is_array( $m ) ) {
                continue;
            }
            $name = self::METHODS[ $key ][1];
            if ( array_key_exists( 'page_code', $m ) ) {
                $code = $id( $m['page_code'] );
                if ( $code !== '' && ! preg_match( '/^[A-Za-z0-9]{6,40}$/', $code ) ) {
                    return Qwoo_I18n::t( 'The page code for {name} is letters and numbers only. Copy it again from Grow.', [ 'name' => $name ] );
                }
                $s['methods'][ $key ]['page_code'] = $code;
            }
            if ( array_key_exists( 'title', $m ) ) {
                $title = trim( sanitize_text_field( (string) $m['title'] ) );
                if ( mb_strlen( $title ) > 100 ) {
                    return 'Names at checkout can be up to 100 characters.';
                }
                $s['methods'][ $key ]['title'] = $title;
            }
            if ( array_key_exists( 'enabled', $m ) ) {
                $s['methods'][ $key ]['enabled'] = ! empty( $m['enabled'] );
            }
        }
        foreach ( $s['methods'] as $key => $m ) {
            if ( ! $m['enabled'] ) {
                continue;
            }
            $name = self::METHODS[ $key ][1];
            if ( $s['user_id'] === '' ) {
                return 'Add your Grow business ID (userId) first.';
            }
            if ( $m['page_code'] === '' ) {
                return Qwoo_I18n::t( 'Add the page code Grow gave you for {name}, or turn {name} off.', [ 'name' => $name ] );
            }
            if ( get_woocommerce_currency() !== 'ILS' ) {
                return 'Grow takes payments in shekels only. Change your store\'s currency to ILS in Store details first.';
            }
        }
        // A default name is saved as empty, so it follows the store's language.
        foreach ( self::METHODS as $key => [ , $name ] ) {
            if ( $s['methods'][ $key ]['title'] === Qwoo_I18n::t( $name ) ) {
                $s['methods'][ $key ]['title'] = '';
            }
        }
        update_option( self::OPTION, $s );
        return null;
    }

    /* ---------------- Grow's API ---------------- */

    /**
     * POSTs to a Light API method (as form data, which Grow requires) and
     * answers with its `data`, or a WP_Error with Grow's message.
     */
    public static function api( $method, array $fields, $test = null ) {
        $test     = $test === null ? self::settings()['test'] : (bool) $test;
        $boundary = 'qwoo' . wp_generate_password( 24, false );
        $body     = '';
        foreach ( $fields as $name => $value ) {
            if ( $value === null || is_array( $value ) ) {
                continue;
            }
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . str_replace( [ '"', "\r", "\n" ], '', (string) $name ) . "\"\r\n\r\n" . $value . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        $response = wp_safe_remote_post( ( $test ? self::SANDBOX_URL : self::LIVE_URL ) . $method, [
            'timeout' => 25,
            'headers' => [ 'Content-Type' => 'multipart/form-data; boundary=' . $boundary, 'Accept' => 'application/json' ],
            'body'    => $body,
        ] );
        if ( is_wp_error( $response ) ) {
            error_log( 'qwoo: Grow ' . $method . ' failed: ' . $response->get_error_message() );
            return new WP_Error( 'qwoo_grow_unreachable', 'Grow can\'t be reached right now. Try again in a minute.', [ 'status' => 502 ] );
        }
        $json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $json ) ) {
            error_log( 'qwoo: Grow ' . $method . ' answered HTTP ' . wp_remote_retrieve_response_code( $response ) . ' without JSON.' );
            return new WP_Error( 'qwoo_grow_bad_answer', 'Grow didn\'t answer as expected. Try again in a minute.', [ 'status' => 502 ] );
        }
        if ( (string) ( $json['status'] ?? '' ) !== '1' ) {
            $err  = is_array( $json['err'] ?? null ) ? $json['err'] : [];
            $code = (int) ( $err['id'] ?? 0 );
            $text = trim( wp_strip_all_tags( (string) ( $err['message'] ?? '' ) ) );
            error_log( sprintf( 'qwoo: Grow %s error %d: %s', $method, $code, $text ) );
            return new WP_Error( 'qwoo_grow_' . $code, $text !== '' ? $text : 'Grow refused the request.', [ 'status' => 400, 'grow_code' => $code ] );
        }
        return is_array( $json['data'] ?? null ) ? $json['data'] : [];
    }

    /* ---------------- checkout ---------------- */

    /** The storefront's address ('' when none is set yet). */
    public static function storefront() {
        return class_exists( 'Qwoo_Technical_Settings' ) ? Qwoo_Technical_Settings::get_primary_frontend_domain() : '';
    }

    /**
     * Opens Grow's payment page for an order: the fields for
     * createPaymentProcess, the call, and the order meta.
     * Returns the page's address, or a WP_Error with a message for the shopper.
     */
    public static function start_payment( WC_Order $order, $key ) {
        $s    = self::settings();
        $m    = $s['methods'][ $key ];
        $site = self::storefront();
        if ( ! self::ready( $key ) || $site === '' ) {
            return new WP_Error( 'qwoo_grow_off', Qwoo_I18n::t( 'This payment method isn\'t available right now. Choose another one.' ) );
        }

        $name = self::clean( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), 60 );
        if ( count( preg_split( '/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY ) ) < 2 ) {
            return new WP_Error( 'qwoo_grow_name', Qwoo_I18n::t( 'Enter your first and last name to pay with {method}.', [ 'method' => $m['title'] ] ) );
        }
        $phone = self::israeli_mobile( $order->get_billing_phone() );
        if ( $phone === '' ) {
            return new WP_Error( 'qwoo_grow_phone', Qwoo_I18n::t( 'Enter an Israeli mobile number (05…) to pay with {method}.', [ 'method' => $m['title'] ] ) );
        }
        $total = (float) $order->get_total();
        if ( $total <= 0 ) {
            return new WP_Error( 'qwoo_grow_sum', Qwoo_I18n::t( 'This order has nothing to pay.' ) );
        }
        if ( $key === 'bit' && $total > self::BIT_MAX ) {
            return new WP_Error( 'qwoo_grow_bit_max', Qwoo_I18n::t( 'Bit payments are up to {max}. Choose another payment method.', [ 'max' => '₪' . number_format( self::BIT_MAX ) ] ) );
        }

        $back   = [ 'orderId' => $order->get_id(), 'order_key' => $order->get_order_key(), 'billing_email' => $order->get_billing_email(), 'pm' => 'grow_' . $key ];
        $fields = [
            'pageCode'            => $m['page_code'],
            'userId'              => $s['user_id'],
            'chargeType'          => '1',
            'sum'                 => wc_format_decimal( $total, 2 ),
            'description'         => self::clean( Qwoo_I18n::t( 'Order #{n}', [ 'n' => $order->get_order_number() ] ) . ' ' . html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 100 ),
            // Grow adds "&response=success" and the cFields to this address.
            'successUrl'          => add_query_arg( array_map( 'rawurlencode', $back ), $site . '/thank-you' ),
            'cancelUrl'           => $site . '/checkout',
            'notifyUrl'           => self::notify_url( $order ),
            'pageField[fullName]' => $name,
            'pageField[phone]'    => $phone,
            'cField1'             => (string) $order->get_id(),
        ];
        if ( is_email( $order->get_billing_email() ) ) {
            $fields['pageField[email]'] = $order->get_billing_email();
        }
        // Installments: cards only; the shopper picks 1…max on Grow's page.
        if ( $key === 'card' && $s['max_payments'] > 1 && ( $s['installments_from'] === '' || $total >= (float) $s['installments_from'] ) ) {
            $fields['maxPaymentNum'] = (string) $s['max_payments'];
        }
        // Grow's invoice: the order's lines, when they add up to the total exactly (Grow refuses otherwise).
        if ( $s['invoices'] ) {
            $lines = self::invoice_lines( $order );
            if ( $lines ) {
                foreach ( $lines as $i => $line ) {
                    foreach ( $line as $field => $value ) {
                        $fields[ "productData[{$i}][{$field}]" ] = $value;
                    }
                }
                $company                        = self::clean( $order->get_billing_company(), 60 );
                $fields['pageField[invoiceName]'] = $company !== '' ? $company : $name;
            }
        }

        $data = self::api( 'createPaymentProcess', $fields, $s['test'] );
        if ( is_wp_error( $data ) ) {
            $order->add_order_note( 'Grow didn\'t open a payment page: ' . $data->get_error_message() );
            return new WP_Error( 'qwoo_grow_start', Qwoo_I18n::t( 'The payment page couldn\'t be opened. Check your details and try again, or choose another payment method.' ) );
        }
        $url  = esc_url_raw( (string) ( $data['url'] ?? '' ), [ 'https' ] );
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( $url === '' || ! preg_match( '/(^|\.)(meshulam\.co\.il|grow\.link|grow\.business)$/', $host ) || empty( $data['processId'] ) || empty( $data['processToken'] ) ) {
            error_log( 'qwoo: Grow createPaymentProcess answered without a usable page address.' );
            return new WP_Error( 'qwoo_grow_start', Qwoo_I18n::t( 'The payment page couldn\'t be opened. Check your details and try again, or choose another payment method.' ) );
        }

        $order->update_meta_data( '_qwoo_grow_process_id', (string) $data['processId'] );
        $order->update_meta_data( '_qwoo_grow_process_token', (string) $data['processToken'] );
        $order->update_meta_data( '_qwoo_grow_page_code', $m['page_code'] );
        $order->update_meta_data( '_qwoo_grow_test', $s['test'] ? 'yes' : 'no' );
        $order->add_order_note( $s['test'] ? 'Grow test payment page opened (sandbox: no real money).' : 'Grow payment page opened. Waiting for the payment.' );
        $order->save();
        return $url;
    }

    /** Where Grow reports the payment: this order, signed so nobody else can point Grow (or anyone) at another order. */
    private static function notify_url( WC_Order $order ) {
        return add_query_arg( [ 'o' => $order->get_id(), 'k' => self::sign( $order->get_id() ) ], rest_url( 'qwoo-grow/v1/notify' ) );
    }

    private static function sign( $order_id ) {
        return substr( hash_hmac( 'sha256', 'grow-notify|' . (int) $order_id, wp_salt( 'auth' ) ), 0, 32 );
    }

    /**
     * The order's lines for Grow's invoice: [ { catalogNumber, quantity, price, itemDescription } ],
     * prices with tax. [] when they can't add up to the order total exactly
     * (a negative fee, rounding): Grow then invoices the total as one sum.
     */
    private static function invoice_lines( WC_Order $order ) {
        $lines = [];
        $sum   = 0.0;
        $add   = static function ( $name, $qty, $total, $sku = '' ) use ( &$lines, &$sum ) {
            $total = round( (float) $total, 2 );
            $qty   = max( 1, (int) $qty );
            $unit  = round( $total / $qty, 2 );
            if ( abs( $unit * $qty - $total ) > 0.001 ) {
                // Doesn't split evenly: one line for all of them.
                $name = $name . ' x' . $qty;
                $qty  = 1;
                $unit = $total;
            }
            $lines[] = [ 'catalogNumber' => self::clean( $sku, 40 ), 'quantity' => (string) $qty, 'price' => wc_format_decimal( $unit, 2 ), 'itemDescription' => self::clean( $name, 100 ) ];
            $sum    += $unit * $qty;
        };
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $add( $item->get_name(), $item->get_quantity(), (float) $item->get_total() + (float) $item->get_total_tax(), $product ? (string) $product->get_sku() : '' );
        }
        $shipping = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
        if ( $shipping > 0 ) {
            $add( Qwoo_I18n::t( 'Shipping' ) . ( $order->get_shipping_method() ? ': ' . $order->get_shipping_method() : '' ), 1, $shipping );
        }
        foreach ( $order->get_fees() as $fee ) {
            $amount = (float) $fee->get_total() + (float) $fee->get_total_tax();
            if ( $amount < 0 ) {
                return [];
            }
            if ( $amount > 0 ) {
                $add( $fee->get_name(), 1, $amount );
            }
        }
        if ( ! $lines || abs( $sum - (float) $order->get_total() ) > 0.005 ) {
            return [];
        }
        // Grow's item names can't be empty.
        foreach ( $lines as $i => $line ) {
            if ( $line['itemDescription'] === '' ) {
                $lines[ $i ]['itemDescription'] = Qwoo_I18n::t( 'Item' );
            }
        }
        return $lines;
    }

    /** Grow asks for no special characters: letters (any language), digits, spaces and . , - ( ) / # only. */
    private static function clean( $text, $max ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES );
        $text = preg_replace( '/[^\p{L}\p{N}\s.,\-()\/#]/u', ' ', $text );
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) );
        return mb_substr( $text, 0, $max );
    }

    /** "054-123 4567", "+972 54…" → "0541234567"; '' when it isn't an Israeli mobile number. */
    public static function israeli_mobile( $phone ) {
        $digits = preg_replace( '/\D+/', '', (string) $phone );
        if ( strpos( $digits, '972' ) === 0 ) {
            $digits = '0' . substr( $digits, 3 );
        }
        return preg_match( '/^05\d{8}$/', $digits ) ? $digits : '';
    }

    /* ---------------- after the payment ---------------- */

    public static function register_routes() {
        // Called by Grow's servers, which don't have the storefront's proxy
        // secret: outside the protected "qwoo" namespace on purpose. The
        // signed address picks the order; the payment is checked with Grow.
        register_rest_route( 'qwoo-grow/v1', '/notify', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_notify' ],
            'permission_callback' => '__return_true',
        ] );
        // The storefront, when the shopper comes back: the order key proves it's theirs.
        register_rest_route( 'qwoo-grow/v1', '/check', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_check' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function rest_notify( WP_REST_Request $r ) {
        $id    = absint( $r->get_param( 'o' ) );
        $sig   = (string) $r->get_param( 'k' );
        $order = $id ? wc_get_order( $id ) : null;
        if ( ! $order || ! hash_equals( self::sign( $id ), $sig ) || strpos( (string) $order->get_payment_method(), 'grow_' ) !== 0 ) {
            return new WP_REST_Response( [ 'ok' => false ], 404 );
        }
        $result = self::confirm( $order );
        // Grow tries again later when it doesn't get a 200: only ask for that when Grow couldn't be reached.
        if ( is_wp_error( $result ) && $result->get_error_code() === 'qwoo_grow_retry' ) {
            return new WP_REST_Response( [ 'ok' => false ], 503 );
        }
        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    public static function rest_check( WP_REST_Request $r ) {
        $id    = absint( $r->get_param( 'order' ) );
        $key   = (string) $r->get_param( 'key' );
        $order = $id ? wc_get_order( $id ) : null;
        if ( ! $order || $key === '' || ! hash_equals( $order->get_order_key(), $key ) || strpos( (string) $order->get_payment_method(), 'grow_' ) !== 0 ) {
            return new WP_Error( 'qwoo_grow_no_order', 'Order not found.', [ 'status' => 404 ] );
        }
        // At most one question to Grow per order every 5 seconds.
        $limit = 'qwoo_grow_chk_' . $id;
        if ( ! $order->is_paid() && ! get_transient( $limit ) ) {
            set_transient( $limit, 1, 5 );
            self::confirm( $order );
            $order = wc_get_order( $id );
        }
        return [ 'paid' => $order->is_paid(), 'status' => $order->get_status() ];
    }

    /**
     * Asks Grow whether the order's payment process was paid, in full, and
     * if so completes the order and approves the transaction (once).
     * Returns true when paid, false when not (yet), or a WP_Error.
     */
    public static function confirm( WC_Order $order ) {
        $process = (string) $order->get_meta( '_qwoo_grow_process_id' );
        $token   = (string) $order->get_meta( '_qwoo_grow_process_token' );
        $page    = (string) $order->get_meta( '_qwoo_grow_page_code' );
        $test    = $order->get_meta( '_qwoo_grow_test' ) === 'yes';
        if ( $process === '' || $token === '' || $page === '' ) {
            return false;
        }
        if ( $order->is_paid() && $order->get_meta( '_qwoo_grow_approved' ) === 'yes' ) {
            return true;
        }

        // One at a time per order (Grow's call and the storefront's check can meet).
        $lock = 'qwoo_grow_lock_' . $order->get_id();
        if ( ! add_option( $lock, time(), '', 'no' ) ) {
            if ( time() - (int) get_option( $lock ) < 60 ) {
                return $order->is_paid();
            }
            update_option( $lock, time(), false );
        }
        try {
            $data = self::api( 'getPaymentProcessInfo', [ 'pageCode' => $page, 'processId' => $process, 'processToken' => $token ], $test );
            if ( is_wp_error( $data ) ) {
                return new WP_Error( 'qwoo_grow_retry', $data->get_error_message() );
            }
            $paid = null;
            foreach ( (array) ( $data['transactions'] ?? [] ) as $tx ) {
                // statusCode 2 = paid ("שולם").
                if ( is_array( $tx ) && (string) ( $tx['statusCode'] ?? '' ) === '2' ) {
                    $paid = $tx;
                    break;
                }
            }
            if ( ! $paid ) {
                return false;
            }
            $sum = (float) ( $paid['sum'] ?? 0 );
            if ( abs( $sum - (float) $order->get_total() ) > 0.01 ) {
                if ( $order->get_meta( '_qwoo_grow_sum_warned' ) !== 'yes' ) {
                    $order->update_meta_data( '_qwoo_grow_sum_warned', 'yes' );
                    $order->add_order_note( sprintf( 'Grow reports a payment of %s, but the order total is %s. The order was not marked as paid: check it in Grow.', wc_format_decimal( $sum, 2 ), wc_format_decimal( $order->get_total(), 2 ) ) );
                    $order->update_status( 'on-hold' );
                    $order->save();
                }
                return false;
            }

            if ( ! $order->is_paid() ) {
                foreach ( [ 'transactionId' => '_qwoo_grow_tx_id', 'transactionToken' => '_qwoo_grow_tx_token', 'asmachta' => '_qwoo_grow_asmachta', 'cardSuffix' => '_qwoo_grow_card_suffix', 'cardBrand' => '_qwoo_grow_card_brand', 'allPaymentsNum' => '_qwoo_grow_payments' ] as $field => $meta ) {
                    $order->update_meta_data( $meta, sanitize_text_field( (string) ( $paid[ $field ] ?? '' ) ) );
                }
                $parts = array_filter( [
                    trim( ( $paid['cardBrand'] ?? '' ) . ( ! empty( $paid['cardSuffix'] ) ? ' ••' . $paid['cardSuffix'] : '' ) ),
                    ! empty( $paid['asmachta'] ) ? 'approval ' . $paid['asmachta'] : '',
                    (int) ( $paid['allPaymentsNum'] ?? 1 ) > 1 ? (int) $paid['allPaymentsNum'] . ' payments' : '',
                ] );
                $order->add_order_note( ( $test ? 'Paid with Grow (test, no real money)' : 'Paid with Grow' ) . ( $parts ? ': ' . implode( ', ', $parts ) : '' ) . '.' );
                $order->payment_complete( sanitize_text_field( (string) ( $paid['transactionId'] ?? '' ) ) );
            }

            if ( $order->get_meta( '_qwoo_grow_approved' ) !== 'yes' ) {
                $fields = [ 'pageCode' => $page, 'processId' => $process, 'processToken' => $token ];
                foreach ( [ 'transactionId', 'transactionToken', 'transactionTypeId', 'paymentType', 'sum', 'firstPaymentSum', 'periodicalPaymentSum', 'paymentsNum', 'allPaymentsNum', 'paymentDate', 'asmachta', 'description', 'fullName', 'payerPhone', 'payerEmail', 'cardSuffix', 'cardType', 'cardTypeCode', 'cardBrand', 'cardBrandCode', 'cardExp' ] as $field ) {
                    $fields[ $field ] = is_scalar( $paid[ $field ] ?? null ) ? (string) $paid[ $field ] : '';
                }
                $approved = self::api( 'approveTransaction', $fields, $test );
                if ( is_wp_error( $approved ) ) {
                    // The payment stands either way (Grow's docs); it's retried on Grow's next call or check.
                    error_log( 'qwoo: Grow approveTransaction for order ' . $order->get_id() . ': ' . $approved->get_error_message() );
                } else {
                    $order->update_meta_data( '_qwoo_grow_approved', 'yes' );
                }
                $order->save();
            }
            return true;
        } finally {
            delete_option( $lock );
        }
    }

    /** Refunds through Grow (the dashboard's "Refund the payment"). True, or a WP_Error with Grow's message. */
    public static function refund( WC_Order $order, $amount ) {
        $tx    = (string) $order->get_meta( '_qwoo_grow_tx_id' );
        $token = (string) $order->get_meta( '_qwoo_grow_tx_token' );
        if ( $tx === '' || $token === '' ) {
            return new WP_Error( 'qwoo_grow_refund', 'This order has no Grow payment to refund.' );
        }
        $data = self::api( 'refundTransaction', [
            'userId'           => self::settings()['user_id'],
            'pageCode'         => (string) $order->get_meta( '_qwoo_grow_page_code' ),
            'transactionId'    => $tx,
            'transactionToken' => $token,
            'refundSum'        => wc_format_decimal( $amount, 2 ),
        ], $order->get_meta( '_qwoo_grow_test' ) === 'yes' );
        if ( is_wp_error( $data ) ) {
            return new WP_Error( 'qwoo_grow_refund', 'Grow: ' . $data->get_error_message() );
        }
        $order->add_order_note( sprintf( 'Refunded %s through Grow%s.', wc_format_decimal( $amount, 2 ), ! empty( $data['transactionId'] ) ? ' (transaction ' . sanitize_text_field( (string) $data['transactionId'] ) . ')' : '' ) );
        return true;
    }
}

Qwoo_Grow::init();

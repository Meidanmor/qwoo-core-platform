<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cardcom payments (Israel), through Cardcom's API v11 "Low Profile" payment
 * page (https://secure.cardcom.solutions/Api/v11/Docs).
 *
 * Each way to pay is its own WooCommerce payment method (Qwoo_Cardcom_Gateway):
 * credit card, Bit, Apple Pay and Google Pay. All of them open one Cardcom
 * page (LowProfile/Create): Bit goes to the page's Bit address (UrlToBit);
 * Apple Pay and Google Pay are buttons Cardcom shows on its card page when
 * they're turned on for the owner's terminal. The owner enters their own
 * terminal number and API name (and the API password, only for refunds).
 *
 * The flow:
 *   1. Checkout: LowProfile/Create answers with the page's address, which the
 *      Store API returns as payment_result.redirect_url. The order waits
 *      "pending payment". The storefront either sends the shopper there or,
 *      when the owner chose "inside the checkout", opens it in a frame (card
 *      payments only).
 *   2. Cardcom calls WebHookUrl (qwoo-cardcom/v1/notify, outside the "qwoo"
 *      namespace, which needs the storefront's proxy secret). The address
 *      carries the order id and a signature; the payload isn't trusted: the
 *      store asks Cardcom (LowProfile/GetLpResult with the page id it saved)
 *      and checks the order, the result and the full sum before completing
 *      the order.
 * The storefront can also ask qwoo-cardcom/v1/check (order id + key) when the
 * shopper comes back, in case Cardcom's call is late.
 *
 * Settings: option qwoo_cardcom (the API password is never sent back to the
 * dashboard). Order meta: _qwoo_cardcom_* (never shown to the shopper).
 */
class Qwoo_Cardcom {

    const OPTION = 'qwoo_cardcom';

    /** key => default name. */
    const METHODS = [
        'card'      => 'Credit card',
        'bit'       => 'Bit',
        'applepay'  => 'Apple Pay',
        'googlepay' => 'Google Pay',
    ];

    const API = 'https://secure.cardcom.solutions/api/v11/';

    /** Cardcom's pages and their Bit page. */
    const HOSTS = '/(^|\.)(cardcom\.solutions|cardcom\.co\.il)$/';

    public static function init() {
        add_filter( 'woocommerce_payment_gateways', [ __CLASS__, 'register_gateways' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_gateways( $gateways ) {
        require_once __DIR__ . '/class-cardcom-gateway.php';
        foreach ( array_keys( self::METHODS ) as $key ) {
            $gateways[] = new Qwoo_Cardcom_Gateway( $key );
        }
        return $gateways;
    }

    /* ---------------- settings ---------------- */

    /** The saved settings, with every field present (the password too: only for this class). */
    public static function settings() {
        $saved = get_option( self::OPTION, [] );
        $saved = is_array( $saved ) ? $saved : [];
        $out   = [
            'test'              => ! empty( $saved['test'] ),
            'terminal'          => (string) ( $saved['terminal'] ?? '' ),
            'api_name'          => (string) ( $saved['api_name'] ?? '' ),
            'api_password'      => (string) ( $saved['api_password'] ?? '' ),
            'display'           => ( $saved['display'] ?? '' ) === 'frame' ? 'frame' : 'redirect',
            'max_payments'      => max( 1, min( 36, (int) ( $saved['max_payments'] ?? 1 ) ) ),
            'installments_from' => (string) ( $saved['installments_from'] ?? '' ),
            'invoices'          => ! empty( $saved['invoices'] ),
            'methods'           => [],
        ];
        foreach ( self::METHODS as $key => $name ) {
            $m                      = (array) ( $saved['methods'][ $key ] ?? [] );
            $out['methods'][ $key ] = [
                'enabled' => ! empty( $m['enabled'] ),
                'title'   => (string) ( $m['title'] ?? '' ) !== '' ? (string) $m['title'] : Qwoo_I18n::t( $name ),
            ];
        }
        return $out;
    }

    /** One method's settings: { enabled, title }. */
    public static function method( $key ) {
        return self::settings()['methods'][ $key ] ?? [ 'enabled' => false, 'title' => '' ];
    }

    /** Whether a method can take payments: turned on, details in (Bit: shekels only). */
    public static function ready( $key ) {
        $s = self::settings();
        $m = $s['methods'][ $key ] ?? null;
        if ( ! $m || ! $m['enabled'] || $s['terminal'] === '' || $s['api_name'] === '' ) {
            return false;
        }
        return $key !== 'bit' || get_woocommerce_currency() === 'ILS';
    }

    /** How the storefront shows the page for a method: 'frame' (card only, when the owner chose it) or 'redirect'. */
    public static function display( $key ) {
        return $key === 'card' && self::settings()['display'] === 'frame' ? 'frame' : 'redirect';
    }

    /** For the dashboard (Settings → Payments). The API password is never sent, only whether one is saved. */
    public static function dashboard_state() {
        $s       = self::settings();
        $methods = [];
        foreach ( self::METHODS as $key => $name ) {
            $methods[ $key ] = $s['methods'][ $key ] + [ 'name' => $name ];
        }
        return [
            'currency'          => get_woocommerce_currency(),
            'storefront'        => self::storefront() !== '',
            'test'              => $s['test'],
            'terminal'          => $s['terminal'],
            'api_name'          => $s['api_name'],
            'has_password'      => $s['api_password'] !== '',
            'display'           => $s['display'],
            'max_payments'      => $s['max_payments'],
            'installments_from' => $s['installments_from'],
            'invoices'          => $s['invoices'],
            'methods'           => $methods,
        ];
    }

    /**
     * Saves what the dashboard sent (fields not sent keep their value):
     * { test, terminal, api_name, api_password, display, max_payments,
     *   installments_from, invoices, methods: { card: { enabled, title }, … } }.
     * An empty api_password keeps the saved one; clear_password removes it.
     * New terminal details are checked with Cardcom first.
     * Returns null, or an error message for the owner.
     */
    public static function save( array $in ) {
        $s   = self::settings();
        $old = [ $s['terminal'], $s['api_name'] ];

        if ( array_key_exists( 'test', $in ) ) {
            $s['test'] = ! empty( $in['test'] );
        }
        if ( array_key_exists( 'terminal', $in ) ) {
            $s['terminal'] = trim( (string) $in['terminal'] );
            if ( $s['terminal'] !== '' && ! preg_match( '/^\d{1,10}$/', $s['terminal'] ) ) {
                return 'The terminal number is digits only. Copy it again from Cardcom.';
            }
        }
        if ( array_key_exists( 'api_name', $in ) ) {
            $s['api_name'] = trim( (string) $in['api_name'] );
            if ( $s['api_name'] !== '' && ! preg_match( '/^[A-Za-z0-9_.\-]{2,60}$/', $s['api_name'] ) ) {
                return 'The API name is the user name Cardcom gave you for the API: letters, numbers, - and _ only. Copy it again from Cardcom.';
            }
        }
        if ( ! empty( $in['clear_password'] ) ) {
            $s['api_password'] = '';
        } elseif ( isset( $in['api_password'] ) && trim( (string) $in['api_password'] ) !== '' ) {
            $password = trim( (string) $in['api_password'] );
            if ( strlen( $password ) > 100 || preg_match( '/[\x00-\x1f]/', $password ) ) {
                return 'That API password doesn\'t look right. Copy it again from Cardcom.';
            }
            $s['api_password'] = $password;
        }
        if ( array_key_exists( 'display', $in ) ) {
            $s['display'] = $in['display'] === 'frame' ? 'frame' : 'redirect';
        }
        if ( array_key_exists( 'max_payments', $in ) ) {
            $n = (int) $in['max_payments'];
            if ( $n < 1 || $n > 36 ) {
                return 'Installments can go up to 36 payments.';
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
            if ( $s['terminal'] === '' || $s['api_name'] === '' ) {
                return 'Add your Cardcom terminal number and API name first.';
            }
            if ( $key === 'bit' && get_woocommerce_currency() !== 'ILS' ) {
                return 'Bit takes payments in shekels only. Change your store\'s currency to ILS in Store details, or turn Bit off.';
            }
        }
        // New terminal details: one test call, so a typo shows now and not at a shopper's checkout.
        if ( $s['terminal'] !== '' && $s['api_name'] !== '' && [ $s['terminal'], $s['api_name'] ] !== $old ) {
            $error = self::check_details( $s['terminal'], $s['api_name'] );
            if ( $error !== null ) {
                return $error;
            }
        }
        // A default name is saved as empty, so it follows the store's language.
        foreach ( self::METHODS as $key => $name ) {
            if ( $s['methods'][ $key ]['title'] === Qwoo_I18n::t( $name ) ) {
                $s['methods'][ $key ]['title'] = '';
            }
        }
        update_option( self::OPTION, $s, false );
        return null;
    }

    /** Opens (and leaves unused) a 1-shekel page with these details. Null when Cardcom accepts them. */
    private static function check_details( $terminal, $api_name ) {
        $site = self::storefront() !== '' ? self::storefront() : home_url();
        $data = self::api( 'LowProfile/Create', [
            'TerminalNumber'     => (int) $terminal,
            'ApiName'            => $api_name,
            'Amount'             => 1,
            'ReturnValue'        => 'qwoo-check',
            'ProductName'        => 'Check',
            'SuccessRedirectUrl' => $site,
            'FailedRedirectUrl'  => $site,
            'WebHookUrl'         => rest_url( 'qwoo-cardcom/v1/notify' ),
        ] );
        if ( ! is_wp_error( $data ) ) {
            return null;
        }
        if ( $data->get_error_code() === 'qwoo_cardcom_603' ) {
            return 'Cardcom didn\'t accept this terminal number and API name. Check both in your Cardcom account (Settings → API) and try again.';
        }
        return 'Cardcom: ' . $data->get_error_message();
    }

    /* ---------------- Cardcom's API ---------------- */

    /**
     * POSTs JSON to an API v11 method and answers with Cardcom's answer, or a
     * WP_Error with Cardcom's message (ResponseCode other than 0).
     */
    public static function api( $method, array $body ) {
        $response = wp_safe_remote_post( self::API . $method, [
            'timeout' => 25,
            'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );
        if ( is_wp_error( $response ) ) {
            error_log( 'qwoo: Cardcom ' . $method . ' failed: ' . $response->get_error_message() );
            return new WP_Error( 'qwoo_cardcom_unreachable', 'Cardcom can\'t be reached right now. Try again in a minute.', [ 'status' => 502 ] );
        }
        $json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $json ) ) {
            error_log( 'qwoo: Cardcom ' . $method . ' answered HTTP ' . wp_remote_retrieve_response_code( $response ) . ' without JSON.' );
            return new WP_Error( 'qwoo_cardcom_bad_answer', 'Cardcom didn\'t answer as expected. Try again in a minute.', [ 'status' => 502 ] );
        }
        $code = (int) ( $json['ResponseCode'] ?? -1 );
        if ( $code !== 0 ) {
            $text = trim( wp_strip_all_tags( (string) ( $json['Description'] ?? '' ) ) );
            error_log( sprintf( 'qwoo: Cardcom %s error %d: %s', $method, $code, $text ) );
            return new WP_Error( 'qwoo_cardcom_' . $code, $text !== '' ? $text : 'Cardcom refused the request.', [ 'status' => 400, 'cardcom_code' => $code, 'answer' => $json ] );
        }
        return $json;
    }

    /* ---------------- checkout ---------------- */

    /** The storefront's address ('' when none is set yet). */
    public static function storefront() {
        return class_exists( 'Qwoo_Technical_Settings' ) ? Qwoo_Technical_Settings::get_primary_frontend_domain() : '';
    }

    /**
     * Opens Cardcom's payment page for an order. Returns the page's address
     * (Bit: the Bit page), or a WP_Error with a message for the shopper.
     */
    public static function start_payment( WC_Order $order, $key ) {
        $s    = self::settings();
        $m    = $s['methods'][ $key ];
        $site = self::storefront();
        if ( ! self::ready( $key ) || $site === '' ) {
            return new WP_Error( 'qwoo_cardcom_off', Qwoo_I18n::t( 'This payment method isn\'t available right now. Choose another one.' ) );
        }
        $total = round( (float) $order->get_total(), 2 );
        if ( $total <= 0 ) {
            return new WP_Error( 'qwoo_cardcom_sum', Qwoo_I18n::t( 'This order has nothing to pay.' ) );
        }

        $pm   = 'cardcom_' . $key;
        $back = [ 'orderId' => $order->get_id(), 'order_key' => $order->get_order_key(), 'billing_email' => $order->get_billing_email(), 'pm' => $pm ];
        // In a frame, Cardcom's page sends the frame back: a small storefront page (/__pay-return)
        // moves the whole window on, since the store's own pages refuse to show inside Cardcom's page.
        $frame   = self::display( $key ) === 'frame';
        $success = $frame ? add_query_arg( array_map( 'rawurlencode', $back + [ 'to' => 'thank-you' ] ), $site . '/__pay-return' ) : add_query_arg( array_map( 'rawurlencode', $back ), $site . '/thank-you' );
        $failed  = $frame ? $site . '/__pay-return?to=checkout' : $site . '/checkout';
        $name    = self::clean( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), 50 );
        $lang    = strpos( (string) get_locale(), 'he' ) === 0 ? 'he' : 'en';

        $body = [
            'TerminalNumber'     => (int) $s['terminal'],
            'ApiName'            => $s['api_name'],
            'Operation'          => 'ChargeOnly',
            'ReturnValue'        => (string) $order->get_id(),
            'Amount'             => $total,
            'ISOCoinName'        => get_woocommerce_currency(),
            'Language'           => $lang,
            'ProductName'        => self::clean( Qwoo_I18n::t( 'Order #{n}', [ 'n' => $order->get_order_number() ] ) . ' ' . html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 100 ),
            'SuccessRedirectUrl' => $success,
            'FailedRedirectUrl'  => $failed,
            'CancelRedirectUrl'  => $failed,
            'WebHookUrl'         => self::notify_url( $order ),
            'UIDefinition'       => array_filter( [
                'CardOwnerNameValue'  => $name,
                'CardOwnerEmailValue' => is_email( $order->get_billing_email() ) ? $order->get_billing_email() : '',
                'CardOwnerPhoneValue' => self::clean( $order->get_billing_phone(), 20 ),
            ] ),
        ];
        // Installments: cards only; the shopper picks 1…max on Cardcom's page.
        if ( $key === 'card' && $s['max_payments'] > 1 && ( $s['installments_from'] === '' || $total >= (float) $s['installments_from'] ) ) {
            $body['AdvancedDefinition'] = [ 'MaxNumOfPayments' => $s['max_payments'] ];
        }
        // Cardcom's invoice / receipt, made by Cardcom once the payment goes through.
        if ( $s['invoices'] ) {
            $company          = self::clean( $order->get_billing_company(), 50 );
            $body['Document'] = array_filter( [
                'Name'          => $company !== '' ? $company : ( $name !== '' ? $name : Qwoo_I18n::t( 'Customer' ) ),
                'Email'         => is_email( $order->get_billing_email() ) ? $order->get_billing_email() : '',
                'IsSendByEmail' => is_email( $order->get_billing_email() ) ? true : null,
                'Mobile'        => self::clean( $order->get_billing_phone(), 20 ),
                'AddressLine1'  => self::clean( $order->get_billing_address_1(), 100 ),
                'City'          => self::clean( $order->get_billing_city(), 50 ),
                'ExternalId'    => (string) $order->get_order_number(),
                'Products'      => self::invoice_lines( $order ),
            ], static fn( $v ) => $v !== '' && $v !== null );
        }

        $data = self::api( 'LowProfile/Create', $body );
        if ( is_wp_error( $data ) ) {
            $order->add_order_note( 'Cardcom didn\'t open a payment page: ' . $data->get_error_message() );
            return new WP_Error( 'qwoo_cardcom_start', Qwoo_I18n::t( 'The payment page couldn\'t be opened. Check your details and try again, or choose another payment method.' ) );
        }
        $url = (string) ( $key === 'bit' ? ( $data['UrlToBit'] ?? '' ) : ( $data['Url'] ?? '' ) );
        if ( $key === 'bit' && $url === '' ) {
            $order->add_order_note( 'Cardcom gave no Bit page: Bit may not be turned on for this Cardcom terminal.' );
            return new WP_Error( 'qwoo_cardcom_bit', Qwoo_I18n::t( 'This payment method isn\'t available right now. Choose another one.' ) );
        }
        $url  = esc_url_raw( $url, [ 'https' ] );
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( $url === '' || ! preg_match( self::HOSTS, $host ) || empty( $data['LowProfileId'] ) ) {
            error_log( 'qwoo: Cardcom LowProfile/Create answered without a usable page address (' . $host . ').' );
            return new WP_Error( 'qwoo_cardcom_start', Qwoo_I18n::t( 'The payment page couldn\'t be opened. Check your details and try again, or choose another payment method.' ) );
        }

        $order->update_meta_data( '_qwoo_cardcom_lp_id', sanitize_text_field( (string) $data['LowProfileId'] ) );
        $order->update_meta_data( '_qwoo_cardcom_terminal', $s['terminal'] );
        $order->update_meta_data( '_qwoo_cardcom_test', $s['test'] ? 'yes' : 'no' );
        $order->add_order_note( $s['test'] ? 'Cardcom test payment page opened (test terminal).' : 'Cardcom payment page opened. Waiting for the payment.' );
        $order->save();
        return $url;
    }

    /** Where Cardcom reports the payment: this order, signed so nobody can point it at another order. */
    private static function notify_url( WC_Order $order ) {
        return add_query_arg( [ 'o' => $order->get_id(), 'k' => self::sign( $order->get_id() ) ], rest_url( 'qwoo-cardcom/v1/notify' ) );
    }

    private static function sign( $order_id ) {
        return substr( hash_hmac( 'sha256', 'cardcom-notify|' . (int) $order_id, wp_salt( 'auth' ) ), 0, 32 );
    }

    /**
     * The order's lines for Cardcom's document: [ { ProductID, Description, Quantity, UnitCost, TotalLineCost } ],
     * prices with tax. When they don't add up to the total exactly (a discount
     * fee, rounding), one line with the whole total, so Cardcom accepts it.
     */
    private static function invoice_lines( WC_Order $order ) {
        $lines = [];
        $sum   = 0.0;
        $add   = static function ( $name, $qty, $total, $sku = '' ) use ( &$lines, &$sum ) {
            $total = round( (float) $total, 2 );
            $qty   = max( 1, (int) $qty );
            $line  = [ 'Description' => self::clean( $name, 100 ) ?: Qwoo_I18n::t( 'Item' ), 'Quantity' => $qty, 'UnitCost' => round( $total / $qty, 2 ), 'TotalLineCost' => $total ];
            $sku   = self::clean( $sku, 40 );
            if ( $sku !== '' ) {
                $line['ProductID'] = $sku;
            }
            $lines[] = $line;
            $sum    += $total;
        };
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $add( $item->get_name(), $item->get_quantity(), (float) $item->get_total() + (float) $item->get_total_tax(), $product ? (string) $product->get_sku() : '' );
        }
        $shipping = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
        if ( $shipping > 0 ) {
            $add( Qwoo_I18n::t( 'Shipping' ) . ( $order->get_shipping_method() ? ': ' . $order->get_shipping_method() : '' ), 1, $shipping );
        }
        $ok = true;
        foreach ( $order->get_fees() as $fee ) {
            $amount = (float) $fee->get_total() + (float) $fee->get_total_tax();
            if ( $amount < 0 ) {
                $ok = false;
            } elseif ( $amount > 0 ) {
                $add( $fee->get_name(), 1, $amount );
            }
        }
        $total = round( (float) $order->get_total(), 2 );
        if ( ! $ok || ! $lines || abs( $sum - $total ) > 0.005 ) {
            return [ [ 'Description' => self::clean( Qwoo_I18n::t( 'Order #{n}', [ 'n' => $order->get_order_number() ] ), 100 ), 'Quantity' => 1, 'UnitCost' => $total, 'TotalLineCost' => $total ] ];
        }
        return $lines;
    }

    /** Plain text for Cardcom's fields: no tags, no control characters, no quotes or angle brackets. */
    private static function clean( $text, $max ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES );
        $text = preg_replace( '/[\x00-\x1f<>"\'\\\\]/u', ' ', $text );
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) );
        return mb_substr( $text, 0, $max );
    }

    /* ---------------- after the payment ---------------- */

    public static function register_routes() {
        // Called by Cardcom's servers, which don't have the storefront's proxy
        // secret: outside the protected "qwoo" namespace on purpose. The
        // signed address picks the order; the payment is checked with Cardcom.
        register_rest_route( 'qwoo-cardcom/v1', '/notify', [
            'methods'             => [ 'POST', 'GET' ],
            'callback'            => [ __CLASS__, 'rest_notify' ],
            'permission_callback' => '__return_true',
        ] );
        // The storefront, when the shopper comes back: the order key proves it's theirs.
        register_rest_route( 'qwoo-cardcom/v1', '/check', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_check' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function rest_notify( WP_REST_Request $r ) {
        $id    = absint( $r->get_param( 'o' ) );
        $sig   = (string) $r->get_param( 'k' );
        $order = $id ? wc_get_order( $id ) : null;
        if ( ! $order || ! hash_equals( self::sign( $id ), $sig ) || strpos( (string) $order->get_payment_method(), 'cardcom_' ) !== 0 ) {
            return new WP_REST_Response( [ 'ok' => false ], 404 );
        }
        $result = self::confirm( $order );
        if ( is_wp_error( $result ) && $result->get_error_code() === 'qwoo_cardcom_retry' ) {
            return new WP_REST_Response( [ 'ok' => false ], 503 );
        }
        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    public static function rest_check( WP_REST_Request $r ) {
        $id    = absint( $r->get_param( 'order' ) );
        $key   = (string) $r->get_param( 'key' );
        $order = $id ? wc_get_order( $id ) : null;
        if ( ! $order || $key === '' || ! hash_equals( $order->get_order_key(), $key ) || strpos( (string) $order->get_payment_method(), 'cardcom_' ) !== 0 ) {
            return new WP_Error( 'qwoo_cardcom_no_order', 'Order not found.', [ 'status' => 404 ] );
        }
        // At most one question to Cardcom per order every 5 seconds.
        $limit = 'qwoo_cardcom_chk_' . $id;
        if ( ! $order->is_paid() && ! get_transient( $limit ) ) {
            set_transient( $limit, 1, 5 );
            self::confirm( $order );
            $order = wc_get_order( $id );
        }
        $response = rest_ensure_response( [ 'paid' => $order->is_paid(), 'status' => $order->get_status() ] );
        $response->header( 'Cache-Control', 'no-store' );
        return $response;
    }

    /**
     * Asks Cardcom whether the order's payment page was paid, in full, and if
     * so completes the order (once). True when paid, false when not (yet),
     * or a WP_Error when Cardcom couldn't be asked.
     */
    public static function confirm( WC_Order $order ) {
        $lp       = (string) $order->get_meta( '_qwoo_cardcom_lp_id' );
        $terminal = (string) $order->get_meta( '_qwoo_cardcom_terminal' );
        $test     = $order->get_meta( '_qwoo_cardcom_test' ) === 'yes';
        if ( $lp === '' || $terminal === '' ) {
            return false;
        }
        if ( $order->is_paid() ) {
            return true;
        }
        $s = self::settings();
        if ( $s['terminal'] !== $terminal || $s['api_name'] === '' ) {
            // The terminal changed since the page opened: Cardcom must be asked with the old one.
            $order->add_order_note( 'The Cardcom terminal changed after this payment page opened, so the payment can\'t be checked automatically. Check it in Cardcom.' );
            return false;
        }

        // One at a time per order (Cardcom's call and the storefront's check can meet).
        $lock = 'qwoo_cardcom_lock_' . $order->get_id();
        if ( ! add_option( $lock, time(), '', 'no' ) ) {
            if ( time() - (int) get_option( $lock ) < 60 ) {
                return $order->is_paid();
            }
            update_option( $lock, time(), false );
        }
        try {
            $data = self::api( 'LowProfile/GetLpResult', [ 'TerminalNumber' => (int) $terminal, 'ApiName' => $s['api_name'], 'LowProfileId' => $lp ] );
            if ( is_wp_error( $data ) ) {
                $code = (int) ( $data->get_error_data()['cardcom_code'] ?? 0 );
                // Not paid (yet): the page is open, or the shopper's attempt failed.
                if ( $code > 0 ) {
                    return false;
                }
                return new WP_Error( 'qwoo_cardcom_retry', $data->get_error_message() );
            }
            $tx = is_array( $data['TranzactionInfo'] ?? null ) ? $data['TranzactionInfo'] : null;
            if ( (string) ( $data['ReturnValue'] ?? '' ) !== (string) $order->get_id() || (string) ( $data['LowProfileId'] ?? $lp ) !== $lp ) {
                error_log( 'qwoo: Cardcom result for order ' . $order->get_id() . ' names another order.' );
                return false;
            }
            if ( ! $tx || (int) ( $tx['ResponseCode'] ?? -1 ) !== 0 || ! empty( $tx['IsRefund'] ) ) {
                return false;
            }
            $sum = (float) ( $tx['Amount'] ?? 0 );
            if ( abs( $sum - (float) $order->get_total() ) > 0.01 ) {
                if ( $order->get_meta( '_qwoo_cardcom_sum_warned' ) !== 'yes' ) {
                    $order->update_meta_data( '_qwoo_cardcom_sum_warned', 'yes' );
                    $order->add_order_note( sprintf( 'Cardcom reports a payment of %s, but the order total is %s. The order was not marked as paid: check it in Cardcom.', wc_format_decimal( $sum, 2 ), wc_format_decimal( $order->get_total(), 2 ) ) );
                    $order->update_status( 'on-hold' );
                    $order->save();
                }
                return false;
            }

            $tx_id = (string) (int) ( $tx['TranzactionId'] ?? $data['TranzactionId'] ?? 0 );
            $order->update_meta_data( '_qwoo_cardcom_tx_id', $tx_id );
            $order->update_meta_data( '_qwoo_cardcom_approval', sanitize_text_field( (string) ( $tx['ApprovalNumber'] ?? '' ) ) );
            $order->update_meta_data( '_qwoo_cardcom_last4', sanitize_text_field( (string) ( $tx['Last4CardDigitsString'] ?? '' ) ) );
            $order->update_meta_data( '_qwoo_cardcom_payments', (string) (int) ( $tx['NumberOfPayments'] ?? 1 ) );
            $doc = is_array( $data['DocumentInfo'] ?? null ) ? $data['DocumentInfo'] : null;
            if ( $doc && (int) ( $doc['ResponseCode'] ?? -1 ) === 0 ) {
                $order->update_meta_data( '_qwoo_cardcom_doc_number', sanitize_text_field( (string) ( $doc['DocumentNumber'] ?? '' ) ) );
                $order->update_meta_data( '_qwoo_cardcom_doc_url', esc_url_raw( (string) ( $doc['DocumentUrl'] ?? '' ), [ 'https' ] ) );
            }
            $parts = array_filter( [
                ! empty( $tx['Last4CardDigitsString'] ) ? '••' . sanitize_text_field( (string) $tx['Last4CardDigitsString'] ) : '',
                ! empty( $tx['ApprovalNumber'] ) ? 'approval ' . sanitize_text_field( (string) $tx['ApprovalNumber'] ) : '',
                (int) ( $tx['NumberOfPayments'] ?? 1 ) > 1 ? (int) $tx['NumberOfPayments'] . ' payments' : '',
                $doc && ! empty( $doc['DocumentNumber'] ) ? 'document ' . sanitize_text_field( (string) $doc['DocumentNumber'] ) : '',
            ] );
            $order->add_order_note( ( $test ? 'Paid with Cardcom (test terminal)' : 'Paid with Cardcom' ) . ( $parts ? ': ' . implode( ', ', $parts ) : '' ) . '.' );
            if ( $doc && (int) ( $doc['ResponseCode'] ?? -1 ) !== 0 ) {
                $order->add_order_note( 'Cardcom didn\'t make the invoice: ' . sanitize_text_field( (string) ( $doc['Description'] ?? '' ) ) );
            }
            $order->payment_complete( $tx_id );
            return true;
        } finally {
            delete_option( $lock );
        }
    }

    /** Refunds through Cardcom (needs the API password). True, or a WP_Error with Cardcom's message. */
    public static function refund( WC_Order $order, $amount ) {
        $tx = (int) $order->get_meta( '_qwoo_cardcom_tx_id' );
        $s  = self::settings();
        if ( $tx <= 0 ) {
            return new WP_Error( 'qwoo_cardcom_refund', 'This order has no Cardcom payment to refund.' );
        }
        if ( $s['api_password'] === '' ) {
            return new WP_Error( 'qwoo_cardcom_refund', 'To refund through Cardcom, add your Cardcom API password in Settings → Payments.' );
        }
        $body = [ 'ApiName' => $s['api_name'], 'ApiPassword' => $s['api_password'], 'TransactionId' => $tx, 'AllowMultipleRefunds' => true ];
        if ( abs( (float) $amount - (float) $order->get_total() ) > 0.005 ) {
            $body['PartialSum'] = round( (float) $amount, 2 );
        }
        $data = self::api( 'Transactions/RefundByTransactionId', $body );
        if ( is_wp_error( $data ) ) {
            return new WP_Error( 'qwoo_cardcom_refund', 'Cardcom: ' . $data->get_error_message() );
        }
        $order->add_order_note( sprintf( 'Refunded %s through Cardcom%s.', wc_format_decimal( $amount, 2 ), ! empty( $data['NewTranzactionId'] ) ? ' (transaction ' . (int) $data['NewTranzactionId'] . ')' : '' ) . ( $s['invoices'] ? ' If Cardcom made an invoice for this order, make the matching credit document in Cardcom.' : '' ) );
        return true;
    }
}

Qwoo_Cardcom::init();

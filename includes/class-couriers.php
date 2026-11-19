<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

/**
 * Couriers: the owner connects their own HFD or Lionwheel account (Settings →
 * Couriers in the dashboard) and sends orders to it from the order page or
 * the orders list. Any other company: a tracking number + link typed by hand.
 *
 * Keys: option qwoo_couriers, the HFD token and the Lionwheel key encrypted
 * (AES-256-GCM, a key derived from AUTH_KEY). They never leave this class:
 * the dashboard only sees "connected" and the last 4 characters. Lionwheel's
 * key travels in the address (?key=): addresses and answers are never logged
 * or shown, and the owner only sees short, fixed messages.
 *
 * Order meta (read by the orders export too; keep the names):
 *   _qwoo_courier          the company's name as the shopper sees it ("HFD", the
 *                          Lionwheel company name, or what the owner typed)
 *   _qwoo_tracking_number  the shipment / tracking number
 *   _qwoo_tracking_url     the tracking page (https)
 *   _qwoo_shipment         { courier: hfd|lionwheel|manual, id, number, rand,
 *                          service, packages, cod, created, status, label, checked }
 *   _qwoo_pickup_point     { id, name, address } chosen at checkout (HFD ePost)
 *   _qwoo_lw_task          Lionwheel's task id (the webhook finds the order by it)
 *
 * After a shipment is opened: action qwoo_order_shipped( WC_Order, array $shipment )
 * (webhook event order.shipped, automation trigger order_shipped).
 *
 * Not tried against HFD or Lionwheel (no test account yet): built from HFD's
 * WordPress plugin and Lionwheel's public docs (github.com/lionwheel/api).
 */
class Qwoo_Couriers {

    const OPTION = 'qwoo_couriers';

    const META_COURIER  = '_qwoo_courier';
    const META_NUMBER   = '_qwoo_tracking_number';
    const META_URL      = '_qwoo_tracking_url';
    const META_SHIPMENT = '_qwoo_shipment';
    const META_POINT    = '_qwoo_pickup_point';
    const META_LW_TASK  = '_qwoo_lw_task';

    const METHOD = 'qwoo_hfd_pickup';

    /** How many orders one call sends or prints (the dashboard sends batches). */
    const BATCH = 10;

    /** Ways to send, per courier. */
    const SERVICES = [
        'hfd'       => [ 'door', 'pickup', 'collect' ],
        'lionwheel' => [ 'door' ],
    ];

    /** Lionwheel task statuses → ours. */
    const LW_STATUS = [ 0 => 'created', 1 => 'assigned', 2 => 'on_the_way', 3 => 'delivered', 4 => 'cancelled', 8 => 'failed' ];

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        add_action( 'woocommerce_shipping_init', [ __CLASS__, 'load_method' ] );
        add_filter( 'woocommerce_shipping_methods', [ __CLASS__, 'register_method' ] );
        add_action( 'init', [ __CLASS__, 'register_schema' ] );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'checkout_point' ], 20, 2 );
        add_filter( 'woocommerce_order_item_display_meta_key', [ __CLASS__, 'meta_label' ] );
    }

    /* ---------------- small helpers ---------------- */

    /** Text in the store's language (order notes, emails): Hebrew stores get Hebrew. */
    public static function l( $en, $he ) {
        return class_exists( 'Qwoo_I18n' ) && Qwoo_I18n::default_code() === 'he' ? $he : $en;
    }

    private static function crypt_key() {
        if ( ! defined( 'AUTH_KEY' ) || AUTH_KEY === '' || strpos( AUTH_KEY, 'put your unique phrase here' ) !== false ) {
            return false;
        }
        return hash_hmac( 'sha256', 'qwoo-couriers-v1', AUTH_KEY, true );
    }

    private static function encrypt( $value ) {
        $key = self::crypt_key();
        if ( $value === '' || $key === false ) return '';
        $iv     = random_bytes( 12 );
        $tag    = '';
        $cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
        return $cipher === false ? '' : 'gcm:' . base64_encode( $iv . $tag . $cipher );
    }

    private static function decrypt( $value ) {
        $key = self::crypt_key();
        if ( $key === false || ! is_string( $value ) || strpos( $value, 'gcm:' ) !== 0 ) return '';
        $raw = base64_decode( substr( $value, 4 ), true );
        if ( $raw === false || strlen( $raw ) < 29 ) return '';
        $plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
        return $plain === false ? '' : $plain;
    }

    /** One line of plain text: no tags, control characters or quotes. */
    public static function clean( $text, $max ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES );
        $text = preg_replace( '/[\x00-\x1f<>"\\\\]/u', ' ', $text );
        $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
        return mb_substr( $text, 0, $max );
    }

    /** A courier's own error text, safe to show the owner (never an address or a key). */
    public static function their_message( $text ) {
        $text = self::clean( is_scalar( $text ) ? $text : wp_json_encode( $text ), 200 );
        $text = preg_replace( '#https?://\S+#i', '', $text );
        $text = preg_replace( '/key=\S+/i', '', $text );
        return trim( $text );
    }

    /* ---------------- settings ---------------- */

    /** Everything saved, keys decrypted (only for this class and its adapters). */
    public static function settings() {
        $saved = get_option( self::OPTION, [] );
        $saved = is_array( $saved ) ? $saved : [];
        $hfd   = is_array( $saved['hfd'] ?? null ) ? $saved['hfd'] : [];
        $lw    = is_array( $saved['lionwheel'] ?? null ) ? $saved['lionwheel'] : [];
        $out   = [
            'hfd'       => [
                'token'   => self::decrypt( (string) ( $hfd['token'] ?? '' ) ),
                'client'  => preg_replace( '/\D/', '', (string) ( $hfd['client'] ?? '' ) ),
                'checked' => (int) ( $hfd['checked'] ?? 0 ),
            ],
            'lionwheel' => [
                'key'     => self::decrypt( (string) ( $lw['key'] ?? '' ) ),
                'name'    => self::clean( $lw['name'] ?? '', 60 ),
                'hook'    => preg_match( '/^[A-Za-z0-9]{40}$/', (string) ( $lw['hook'] ?? '' ) ) ? (string) $lw['hook'] : '',
                'checked' => (int) ( $lw['checked'] ?? 0 ),
            ],
            'default'   => in_array( $saved['default'] ?? '', [ 'hfd', 'lionwheel' ], true ) ? $saved['default'] : '',
            'service'   => in_array( $saved['service'] ?? '', [ 'door', 'pickup', 'collect' ], true ) ? $saved['service'] : 'door',
            'packages'  => max( 1, min( 20, (int) ( $saved['packages'] ?? 1 ) ) ),
            'complete'  => ! array_key_exists( 'complete', $saved ) || ! empty( $saved['complete'] ),
            'note'      => self::clean( $saved['note'] ?? '', 200 ),
        ];
        // A default that isn't connected: the first one that is.
        if ( $out['default'] === '' || ! self::connected( $out['default'], $out ) ) {
            $out['default'] = self::connected( 'hfd', $out ) ? 'hfd' : ( self::connected( 'lionwheel', $out ) ? 'lionwheel' : '' );
        }
        return $out;
    }

    public static function connected( $courier, $s = null ) {
        $s = $s ?: self::settings();
        if ( $courier === 'hfd' ) return $s['hfd']['token'] !== '' && $s['hfd']['client'] !== '';
        if ( $courier === 'lionwheel' ) return $s['lionwheel']['key'] !== '';
        return false;
    }

    /** The name shoppers see for a courier. */
    public static function name( $courier, $s = null ) {
        if ( $courier === 'hfd' ) return 'HFD';
        if ( $courier === 'lionwheel' ) {
            $s = $s ?: self::settings();
            return $s['lionwheel']['name'] !== '' ? $s['lionwheel']['name'] : 'Lionwheel';
        }
        return '';
    }

    private static function write( array $raw ) {
        update_option( self::OPTION, $raw, false );
    }

    private static function raw() {
        $raw = get_option( self::OPTION, [] );
        return is_array( $raw ) ? $raw : [];
    }

    private static function hint( $secret ) {
        return $secret === '' ? '' : '••••' . mb_substr( $secret, -4 );
    }

    /** Lionwheel calls this address when a task changes (the owner pastes it in Lionwheel). */
    public static function webhook_url( $hook ) {
        return $hook === '' ? '' : rest_url( 'qwoo-couriers/v1/lionwheel/' . $hook );
    }

    /** For the dashboard (Settings → Couriers). Keys are never sent: only whether one is saved and its end. */
    public static function dashboard_state() {
        $s = self::settings();
        return [
            'can_encrypt' => self::crypt_key() !== false,
            'couriers'    => [
                'hfd'       => [
                    'connected' => self::connected( 'hfd', $s ),
                    'client'    => $s['hfd']['client'],
                    'token'     => self::hint( $s['hfd']['token'] ),
                    'checked'   => $s['hfd']['checked'] ?: null,
                    'services'  => self::SERVICES['hfd'],
                ],
                'lionwheel' => [
                    'connected' => self::connected( 'lionwheel', $s ),
                    'name'      => $s['lionwheel']['name'],
                    'key'       => self::hint( $s['lionwheel']['key'] ),
                    'checked'   => $s['lionwheel']['checked'] ?: null,
                    'webhook'   => self::connected( 'lionwheel', $s ) ? self::webhook_url( $s['lionwheel']['hook'] ) : '',
                    'services'  => self::SERVICES['lionwheel'],
                ],
            ],
            'default'     => $s['default'],
            'defaults'    => [ 'service' => $s['service'], 'packages' => $s['packages'], 'complete' => $s['complete'], 'note' => $s['note'] ],
            // A "Pickup point (HFD)" shipping method in some zone (Settings → Shipping).
            'pickup_method' => self::has_pickup_method(),
        ];
    }

    /** The couriers an order can go to now: [ { key, name, services } ]. */
    public static function available( $s = null ) {
        $s   = $s ?: self::settings();
        $out = [];
        foreach ( [ 'hfd', 'lionwheel' ] as $c ) {
            if ( self::connected( $c, $s ) ) $out[] = [ 'key' => $c, 'name' => self::name( $c, $s ), 'services' => self::SERVICES[ $c ] ];
        }
        return $out;
    }

    /**
     * Saves what the dashboard sent:
     *   { courier: hfd, token?, client }           connect / change HFD (checked with HFD first)
     *   { courier: lionwheel, key?, name }         connect / change Lionwheel (checked first)
     *   { defaults: { default, service, packages, complete, note } }
     * An empty token / key keeps the saved one. Returns the state or a WP_Error.
     */
    public static function save( array $in ) {
        if ( isset( $in['defaults'] ) && is_array( $in['defaults'] ) ) {
            $d   = $in['defaults'];
            $raw = self::raw();
            if ( isset( $d['default'] ) ) {
                $raw['default'] = in_array( $d['default'], [ 'hfd', 'lionwheel' ], true ) ? $d['default'] : '';
            }
            if ( isset( $d['service'] ) ) {
                if ( ! in_array( $d['service'], [ 'door', 'pickup', 'collect' ], true ) ) return new WP_Error( 'service', 'Choose how orders are sent.' );
                $raw['service'] = $d['service'];
            }
            if ( isset( $d['packages'] ) ) {
                $p = (int) $d['packages'];
                if ( $p < 1 || $p > 20 ) return new WP_Error( 'packages', 'Packages must be between 1 and 20.' );
                $raw['packages'] = $p;
            }
            if ( array_key_exists( 'complete', $d ) ) $raw['complete'] = ! empty( $d['complete'] );
            if ( array_key_exists( 'note', $d ) ) $raw['note'] = self::clean( $d['note'], 200 );
            self::write( $raw );
            return self::dashboard_state();
        }

        if ( self::crypt_key() === false ) {
            return new WP_Error( 'crypt', 'Your store can\'t keep keys safely yet. Contact support.' );
        }
        $courier = (string) ( $in['courier'] ?? '' );
        $raw     = self::raw();
        $s       = self::settings();

        if ( $courier === 'hfd' ) {
            $client = preg_replace( '/\s/', '', (string) ( $in['client'] ?? $s['hfd']['client'] ) );
            if ( ! preg_match( '/^\d{1,12}$/', $client ) ) return new WP_Error( 'client', 'Enter your HFD customer number (digits only).' );
            $token = trim( (string) ( $in['token'] ?? '' ) );
            if ( $token === '' ) $token = $s['hfd']['token'];
            if ( $token === '' ) return new WP_Error( 'token', 'Paste the API token you got from HFD.' );
            if ( strlen( $token ) > 2000 || ! preg_match( '/^[\x21-\x7e]+$/', $token ) ) return new WP_Error( 'token', 'That token doesn\'t look right. Copy it again from HFD.' );
            $check = Qwoo_Courier_HFD::check( $token, $client );
            if ( is_wp_error( $check ) ) return $check;
            $raw['hfd'] = [ 'token' => self::encrypt( $token ), 'client' => $client, 'checked' => time() ];
            if ( empty( $raw['default'] ) ) $raw['default'] = 'hfd';
            self::write( $raw );
            return self::dashboard_state();
        }

        if ( $courier === 'lionwheel' ) {
            $name = self::clean( $in['name'] ?? $s['lionwheel']['name'], 60 );
            if ( $name === '' ) return new WP_Error( 'name', 'Write the name of your courier company (customers see it).' );
            $key = trim( (string) ( $in['key'] ?? '' ) );
            if ( $key === '' ) $key = $s['lionwheel']['key'];
            if ( $key === '' ) return new WP_Error( 'key', 'Paste the API key you got from your courier company.' );
            if ( strlen( $key ) > 500 || ! preg_match( '/^[A-Za-z0-9_\-\.]+$/', $key ) ) return new WP_Error( 'key', 'That key doesn\'t look right. Copy it again from your courier company.' );
            $check = Qwoo_Courier_Lionwheel::check( $key );
            if ( is_wp_error( $check ) ) return $check;
            $hook = $s['lionwheel']['hook'] !== '' ? $s['lionwheel']['hook'] : self::new_hook();
            $raw['lionwheel'] = [ 'key' => self::encrypt( $key ), 'name' => $name, 'hook' => $hook, 'checked' => time() ];
            if ( empty( $raw['default'] ) ) $raw['default'] = 'lionwheel';
            self::write( $raw );
            return self::dashboard_state();
        }
        return new WP_Error( 'courier', 'Choose a courier.' );
    }

    private static function new_hook() {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $out   = '';
        for ( $i = 0; $i < 40; $i++ ) $out .= $chars[ random_int( 0, 61 ) ];
        return $out;
    }

    /** Checks the saved keys again. */
    public static function test( array $in ) {
        $courier = (string) ( $in['courier'] ?? '' );
        $s       = self::settings();
        if ( ! self::connected( $courier, $s ) ) return new WP_Error( 'courier', 'This courier isn\'t connected.' );
        $check = $courier === 'hfd' ? Qwoo_Courier_HFD::check( $s['hfd']['token'], $s['hfd']['client'] ) : Qwoo_Courier_Lionwheel::check( $s['lionwheel']['key'] );
        if ( is_wp_error( $check ) ) return $check;
        $raw = self::raw();
        if ( isset( $raw[ $courier ] ) && is_array( $raw[ $courier ] ) ) {
            $raw[ $courier ]['checked'] = time();
            self::write( $raw );
        }
        return self::dashboard_state() + [ 'ok' => true ];
    }

    /** Forgets a courier's keys (shipments already opened stay on their orders). A new webhook address next time. */
    public static function disconnect( array $in ) {
        $courier = (string) ( $in['courier'] ?? '' );
        if ( ! in_array( $courier, [ 'hfd', 'lionwheel' ], true ) ) return new WP_Error( 'courier', 'Choose a courier.' );
        $raw = self::raw();
        unset( $raw[ $courier ] );
        if ( ( $raw['default'] ?? '' ) === $courier ) unset( $raw['default'] );
        self::write( $raw );
        if ( $courier === 'hfd' ) self::forget_points();
        return self::dashboard_state();
    }

    /* ---------------- the address ---------------- */

    /**
     * Splits a street line into street, house number, apartment, floor and
     * entrance. Best effort: "הנביאים 14 דירה 3", "הנביאים 14/3", "14 Herzl St".
     */
    public static function split_street( $line ) {
        $line = trim( preg_replace( '/\s+/u', ' ', (string) $line ) );
        $out  = [ 'street' => $line, 'number' => '', 'apartment' => '', 'floor' => '', 'entrance' => '' ];
        if ( $line === '' ) return $out;
        $take = static function ( $re ) use ( &$line ) {
            if ( preg_match( $re, $line, $m ) ) {
                $line = trim( preg_replace( '/\s+/u', ' ', str_replace( $m[0], ' ', $line ) ) );
                return $m[1];
            }
            return '';
        };
        $out['apartment'] = $take( '/(?:דירה|דיר\'|ד[\'׳]|apt\.?|apartment|flat)\s*[:.]?\s*(\d{1,4}[א-תa-z]?)/iu' );
        $out['floor']     = $take( '/(?:קומה|קו[\'׳]|floor|fl\.?)\s*[:.]?\s*(-?\d{1,3})/iu' );
        $out['entrance']  = $take( '/(?:כניסה|כנ[\'׳]|entrance|ent\.?)\s*[:.]?\s*([א-תa-z0-9]{1,3})\b/iu' );
        $line = trim( $line, " ,.-\t" );
        if ( preg_match( '/^(.*?[^\d\s,])\s*,?\s*(\d{1,4}[א-תa-zA-Z]?)\s*(?:[\/\\\\]\s*(\d{1,4}))?\s*$/u', $line, $m ) ) {
            $out['street'] = trim( $m[1], " ,.-" );
            $out['number'] = $m[2];
            if ( $out['apartment'] === '' && ! empty( $m[3] ) ) $out['apartment'] = $m[3];
        } elseif ( preg_match( '/^(\d{1,4}[a-zA-Z]?)\s*,?\s+(.+)$/u', $line, $m ) ) {
            $out['number'] = $m[1];
            $out['street'] = trim( $m[2], " ,.-" );
        } else {
            $out['street'] = $line;
        }
        return $out;
    }

    /**
     * Where an order goes: the shipping address, or the billing one when the
     * order has none. { name, phone, email, city, street, number, apartment,
     * floor, entrance, remarks, text, missing[] }.
     */
    public static function address( WC_Order $o ) {
        $kind   = $o->has_shipping_address() ? 'shipping' : 'billing';
        $a      = $o->get_address( $kind );
        $parts  = self::split_street( (string) ( $a['address_1'] ?? '' ) );
        $second = self::clean( $a['address_2'] ?? '', 120 );
        if ( $second !== '' ) {
            // "דירה 3, קומה 2" in the second line.
            $more = self::split_street( 'x 1 ' . $second );
            foreach ( [ 'apartment', 'floor', 'entrance' ] as $k ) {
                if ( $parts[ $k ] === '' && $more[ $k ] !== '' ) $parts[ $k ] = $more[ $k ];
            }
        }
        $name  = trim( ( $a['first_name'] ?? '' ) . ' ' . ( $a['last_name'] ?? '' ) ) ?: trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
        $phone = (string) ( $o->get_shipping_phone() ?: $o->get_billing_phone() );
        $out   = [
            'name'      => self::clean( $name, 80 ),
            'phone'     => self::clean( $phone, 30 ),
            'email'     => sanitize_email( $o->get_billing_email() ),
            'city'      => self::clean( $a['city'] ?? '', 60 ),
            'street'    => self::clean( $parts['street'], 80 ),
            'number'    => self::clean( $parts['number'], 10 ),
            'apartment' => self::clean( $parts['apartment'], 10 ),
            'floor'     => self::clean( $parts['floor'], 10 ),
            'entrance'  => self::clean( $parts['entrance'], 10 ),
            'remarks'   => $second,
            'company'   => self::clean( $a['company'] ?? '', 60 ),
        ];
        $text = trim( $out['street'] . ' ' . $out['number'] );
        if ( $out['apartment'] !== '' ) $text .= ', ' . self::l( 'apt.', 'דירה' ) . ' ' . $out['apartment'];
        if ( $out['floor'] !== '' ) $text .= ', ' . self::l( 'floor', 'קומה' ) . ' ' . $out['floor'];
        if ( $out['entrance'] !== '' ) $text .= ', ' . self::l( 'entrance', 'כניסה' ) . ' ' . $out['entrance'];
        $out['text']    = trim( $text . ( $out['city'] !== '' ? ', ' . $out['city'] : '' ), ', ' );
        $out['missing'] = [];
        foreach ( [ 'name', 'phone', 'city' ] as $k ) if ( $out[ $k ] === '' ) $out['missing'][] = $k;
        return $out;
    }

    /* ---------------- shipments ---------------- */

    public static function shipment( WC_Order $o ) {
        $s = $o->get_meta( self::META_SHIPMENT );
        return is_array( $s ) && ! empty( $s['courier'] ) ? $s : null;
    }

    /** A shipment that is open at a courier (not cancelled, not manual). */
    private static function open_shipment( WC_Order $o ) {
        $s = self::shipment( $o );
        return $s && $s['courier'] !== 'manual' && ( $s['status'] ?? '' ) !== 'cancelled' ? $s : null;
    }

    /** The order's pickup point (chosen at checkout), or null. */
    public static function pickup_point( WC_Order $o ) {
        $p = $o->get_meta( self::META_POINT );
        return is_array( $p ) && ! empty( $p['id'] ) ? [ 'id' => (string) $p['id'], 'name' => (string) ( $p['name'] ?? '' ), 'address' => (string) ( $p['address'] ?? '' ) ] : null;
    }

    /** What the shopper sees: { number, url, courier } or null. */
    public static function tracking( WC_Order $o ) {
        $number = (string) $o->get_meta( self::META_NUMBER );
        $url    = (string) $o->get_meta( self::META_URL );
        if ( $number === '' && $url === '' ) return null;
        return [ 'number' => $number, 'url' => $url, 'courier' => (string) $o->get_meta( self::META_COURIER ) ];
    }

    /** The orders list: a short shipment line. */
    public static function summary( WC_Order $o ) {
        $s = self::shipment( $o );
        $t = self::tracking( $o );
        if ( ! $s && ! $t ) {
            $p = self::pickup_point( $o );
            return $p ? [ 'status' => 'none', 'point' => $p['name'] ] : null;
        }
        return [
            'courier' => $t['courier'] ?? '',
            'number'  => $t['number'] ?? '',
            'url'     => $t['url'] ?? '',
            'status'  => $s['status'] ?? 'created',
            'kind'    => $s['courier'] ?? 'manual',
            'label'   => $s && in_array( $s['courier'], [ 'hfd', 'lionwheel' ], true ) && ( $s['status'] ?? '' ) !== 'cancelled',
        ];
    }

    /** The order page's shipping card. */
    public static function order_view( WC_Order $o ) {
        $s = self::settings();
        $ship = self::shipment( $o );
        if ( $ship && $ship['courier'] === 'lionwheel' && ! in_array( $ship['status'] ?? '', [ 'delivered', 'cancelled', 'failed' ], true ) && time() - (int) ( $ship['checked'] ?? 0 ) > 600 ) {
            self::refresh_lionwheel( $o );
            $ship = self::shipment( $o );
        }
        $point = self::pickup_point( $o );
        $to    = self::address( $o );
        $cod   = $o->get_payment_method() === 'cod' ? (float) $o->get_total() - (float) $o->get_total_refunded() : 0;
        return [
            'shipment' => $ship ? [
                'courier'  => $ship['courier'],
                'name'     => (string) $o->get_meta( self::META_COURIER ),
                'number'   => (string) ( $ship['number'] ?? '' ),
                'url'      => (string) $o->get_meta( self::META_URL ),
                'service'  => (string) ( $ship['service'] ?? '' ),
                'packages' => (int) ( $ship['packages'] ?? 1 ),
                'cod'      => (float) ( $ship['cod'] ?? 0 ),
                'created'  => (int) ( $ship['created'] ?? 0 ) ?: null,
                'status'   => (string) ( $ship['status'] ?? 'created' ),
                'label'    => $ship['courier'] !== 'manual' && ( $ship['status'] ?? '' ) !== 'cancelled',
                'cancel'   => $ship['courier'] !== 'manual' && ! in_array( $ship['status'] ?? '', [ 'cancelled', 'delivered' ], true ),
            ] : null,
            'tracking' => self::tracking( $o ),
            'point'    => $point,
            'ship'     => [
                'couriers' => self::available( $s ),
                'default'  => $s['default'],
                'service'  => $point ? 'pickup' : ( $s['service'] === 'pickup' ? 'door' : $s['service'] ),
                'packages' => $s['packages'],
                'cod'      => round( max( 0, $cod ), 2 ),
                'complete' => $s['complete'],
                'note'     => $s['note'],
                'to'       => $to,
            ],
        ];
    }

    /**
     * Sends one order to a courier. $opt: { courier, service, packages, cod,
     * note, complete }. Returns the shipment, or a WP_Error for the owner.
     */
    public static function ship( WC_Order $o, array $opt ) {
        $s       = self::settings();
        $courier = (string) ( $opt['courier'] ?? '' ) ?: $s['default'];
        if ( ! self::connected( $courier, $s ) ) {
            return new WP_Error( 'courier', 'Connect a courier first (Settings → Couriers).' );
        }
        if ( self::open_shipment( $o ) ) {
            return new WP_Error( 'shipped', 'This order already has a shipment. Cancel it first to send it again.' );
        }
        if ( in_array( $o->get_status(), [ 'cancelled', 'refunded', 'failed', 'pending', 'checkout-draft' ], true ) ) {
            return new WP_Error( 'status', 'Only paid orders (processing, on hold or completed) can be sent.' );
        }
        $point   = self::pickup_point( $o );
        $service = (string) ( $opt['service'] ?? '' );
        if ( $service === '' ) $service = $point ? 'pickup' : ( $s['service'] === 'pickup' ? 'door' : $s['service'] );
        if ( ! in_array( $service, self::SERVICES[ $courier ], true ) ) {
            return new WP_Error( 'service', 'This courier can\'t send it that way.' );
        }
        if ( $service === 'pickup' && ! $point ) {
            return new WP_Error( 'point', 'This order has no pickup point. Send it to the door instead.' );
        }
        $packages = (int) ( $opt['packages'] ?? $s['packages'] );
        if ( $packages < 1 || $packages > 20 ) return new WP_Error( 'packages', 'Packages must be between 1 and 20.' );
        $cod = trim( (string) ( $opt['cod'] ?? '' ) );
        if ( $cod === '' || $cod === null ) {
            $cod = $o->get_payment_method() === 'cod' ? (float) $o->get_total() - (float) $o->get_total_refunded() : 0;
        } elseif ( ! preg_match( '/^\d{1,7}(\.\d{1,2})?$/', (string) $cod ) ) {
            return new WP_Error( 'cod', 'The amount to collect must be a number, like 120.' );
        }
        $cod = round( (float) $cod, 2 );
        if ( $cod > 0 && $service !== 'door' ) return new WP_Error( 'cod', 'Collecting money on delivery works only for delivery to the door.' );
        $note = self::clean( $opt['note'] ?? $s['note'], 200 );

        $to = self::address( $o );
        if ( $to['missing'] ) {
            return new WP_Error( 'address', 'The order is missing the customer\'s name, phone or city.' );
        }
        if ( $service !== 'pickup' && ( $to['street'] === '' ) ) {
            return new WP_Error( 'address', 'The order has no street address. Send it to a pickup point, or add the address in a note and send it by hand.' );
        }

        $attempt = (int) $o->get_meta( '_qwoo_ship_attempt' ) + 1;
        $args    = [ 'service' => $service, 'packages' => $packages, 'cod' => $cod, 'note' => $note, 'to' => $to, 'point' => $point, 'attempt' => $attempt ];
        $made    = $courier === 'hfd' ? Qwoo_Courier_HFD::create( $s['hfd'], $o, $args ) : Qwoo_Courier_Lionwheel::create( $s['lionwheel'], $o, $args );
        if ( is_wp_error( $made ) ) return $made;

        $shipment = [
            'courier'  => $courier,
            'id'       => (string) $made['id'],
            'number'   => (string) $made['number'],
            'rand'     => (string) ( $made['rand'] ?? '' ),
            'label'    => (string) ( $made['label'] ?? '' ),
            'service'  => $service,
            'packages' => $packages,
            'cod'      => $cod,
            'created'  => time(),
            'status'   => 'created',
            'checked'  => time(),
        ];
        $o->update_meta_data( '_qwoo_ship_attempt', $attempt );
        $o->update_meta_data( self::META_SHIPMENT, $shipment );
        $o->update_meta_data( self::META_COURIER, self::name( $courier, $s ) );
        $o->update_meta_data( self::META_NUMBER, $shipment['number'] );
        $o->update_meta_data( self::META_URL, (string) ( $made['url'] ?? '' ) );
        if ( $courier === 'lionwheel' ) $o->update_meta_data( self::META_LW_TASK, $shipment['id'] );
        $o->save();
        $o->add_order_note( sprintf( self::l( 'Sent to %1$s, shipment %2$s.', 'נשלחה ל-%1$s, משלוח %2$s.' ), self::name( $courier, $s ), $shipment['number'] ) );

        do_action( 'qwoo_order_shipped', $o, $shipment );

        // "Mark as shipped": WooCommerce's completed email goes out, with the tracking number.
        $complete = array_key_exists( 'complete', $opt ) ? ! empty( $opt['complete'] ) : $s['complete'];
        if ( $complete && in_array( $o->get_status(), [ 'processing', 'on-hold' ], true ) ) {
            $o->update_status( 'completed', self::l( 'Shipped.', 'נשלחה.' ) );
        }
        return $shipment;
    }

    /** Cancels the order's shipment at the courier, then forgets its tracking number. */
    public static function cancel( WC_Order $o ) {
        $ship = self::open_shipment( $o );
        if ( ! $ship ) return new WP_Error( 'none', 'This order has no shipment to cancel.' );
        if ( ( $ship['status'] ?? '' ) === 'delivered' ) return new WP_Error( 'delivered', 'This shipment was already delivered.' );
        $s = self::settings();
        if ( ! self::connected( $ship['courier'], $s ) ) {
            return new WP_Error( 'courier', 'The courier isn\'t connected any more. Cancel the shipment in their system.' );
        }
        $done = $ship['courier'] === 'hfd' ? Qwoo_Courier_HFD::cancel( $s['hfd'], $ship ) : Qwoo_Courier_Lionwheel::cancel( $s['lionwheel'], $ship );
        if ( is_wp_error( $done ) ) return $done;
        $ship['status'] = 'cancelled';
        $o->update_meta_data( self::META_SHIPMENT, $ship );
        $o->delete_meta_data( self::META_NUMBER );
        $o->delete_meta_data( self::META_URL );
        $o->delete_meta_data( self::META_COURIER );
        $o->delete_meta_data( self::META_LW_TASK );
        $o->save();
        $o->add_order_note( sprintf( self::l( 'Shipment %s cancelled.', 'משלוח %s בוטל.' ), $ship['number'] ) );
        return $ship;
    }

    /**
     * A tracking number + link typed by the owner (any company). An empty
     * number removes it. { number, url, courier, complete }.
     */
    public static function save_tracking( WC_Order $o, array $in ) {
        if ( self::open_shipment( $o ) ) {
            return new WP_Error( 'shipped', 'This order has a shipment from a connected courier. Cancel it first to type your own.' );
        }
        $number  = self::clean( $in['number'] ?? '', 60 );
        $courier = self::clean( $in['courier'] ?? '', 60 );
        $url     = trim( (string) ( $in['url'] ?? '' ) );
        if ( $number === '' && $url === '' ) {
            $o->delete_meta_data( self::META_NUMBER );
            $o->delete_meta_data( self::META_URL );
            $o->delete_meta_data( self::META_COURIER );
            $o->delete_meta_data( self::META_SHIPMENT );
            $o->save();
            return null;
        }
        if ( $number !== '' && ! preg_match( '/^[\p{L}\p{N} \-_.\/#]{1,60}$/u', $number ) ) {
            return new WP_Error( 'number', 'The tracking number can have letters, digits, spaces and dashes.' );
        }
        if ( $url !== '' ) {
            $clean = esc_url_raw( $url, [ 'https' ] );
            $parts = wp_parse_url( $clean );
            if ( ! $clean || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || strlen( $clean ) > 500 ) {
                return new WP_Error( 'url', 'The tracking link must be a full https:// address.' );
            }
            $url = $clean;
        }
        $was = self::tracking( $o );
        $o->update_meta_data( self::META_NUMBER, $number );
        $o->update_meta_data( self::META_URL, $url );
        $o->update_meta_data( self::META_COURIER, $courier );
        $o->update_meta_data( self::META_SHIPMENT, [ 'courier' => 'manual', 'id' => '', 'number' => $number, 'service' => '', 'packages' => 1, 'cod' => 0, 'created' => time(), 'status' => 'created' ] );
        $o->save();
        if ( ! $was || $was['number'] !== $number ) {
            $o->add_order_note( trim( sprintf( self::l( 'Tracking number %1$s %2$s', 'מספר מעקב %1$s %2$s' ), $number, $courier !== '' ? '(' . $courier . ')' : '' ) ) );
            do_action( 'qwoo_order_shipped', $o, self::shipment( $o ) );
        }
        if ( ! empty( $in['complete'] ) && in_array( $o->get_status(), [ 'processing', 'on-hold' ], true ) ) {
            $o->update_status( 'completed', self::l( 'Shipped.', 'נשלחה.' ) );
        }
        return self::tracking( $o );
    }

    /** The label: [ 'pdf' => bytes ] or [ 'url' => address to open ], or a WP_Error. */
    public static function label( WC_Order $o ) {
        $ship = self::open_shipment( $o );
        if ( ! $ship ) return new WP_Error( 'none', 'This order has no shipment yet.' );
        $s = self::settings();
        if ( ! self::connected( $ship['courier'], $s ) ) return new WP_Error( 'courier', 'The courier isn\'t connected any more.' );
        return $ship['courier'] === 'hfd' ? Qwoo_Courier_HFD::label( $s['hfd'], $ship ) : Qwoo_Courier_Lionwheel::label( $s['lionwheel'], $ship );
    }

    /* ---------------- Lionwheel status ---------------- */

    /** Asks Lionwheel for the task's status and saves it (notes when it was delivered, cancelled or failed). */
    public static function refresh_lionwheel( WC_Order $o ) {
        $ship = self::shipment( $o );
        if ( ! $ship || $ship['courier'] !== 'lionwheel' ) return;
        $s = self::settings();
        if ( ! self::connected( 'lionwheel', $s ) ) return;
        $ship['checked'] = time();
        $status = Qwoo_Courier_Lionwheel::status( $s['lionwheel'], $ship['id'] );
        if ( ! is_wp_error( $status ) && isset( self::LW_STATUS[ $status ] ) ) {
            $now = self::LW_STATUS[ $status ];
            if ( $now !== ( $ship['status'] ?? '' ) ) {
                $ship['status'] = $now;
                $notes = [
                    'delivered' => self::l( 'The courier delivered shipment %s.', 'השליח מסר את משלוח %s.' ),
                    'cancelled' => self::l( 'Shipment %s was cancelled at the courier.', 'משלוח %s בוטל אצל חברת השליחויות.' ),
                    'failed'    => self::l( 'The courier couldn\'t deliver shipment %s.', 'השליח לא הצליח למסור את משלוח %s.' ),
                ];
                if ( isset( $notes[ $now ] ) ) $o->add_order_note( sprintf( $notes[ $now ], $ship['number'] ) );
            }
        }
        $o->update_meta_data( self::META_SHIPMENT, $ship );
        $o->save();
    }

    /* ---------------- REST ---------------- */

    public static function register_routes() {
        // Lionwheel's servers have no proxy secret: outside the protected "qwoo" namespace on purpose.
        // The secret in the address picks the store's hook; the task is then read from Lionwheel.
        register_rest_route( 'qwoo-couriers/v1', '/lionwheel/(?P<hook>[A-Za-z0-9]{40})', [
            'methods'             => [ 'POST' ],
            'callback'            => [ __CLASS__, 'rest_lionwheel' ],
            'permission_callback' => '__return_true',
        ] );
        // The storefront's checkout (behind the proxy secret, like every qwoo/v1 route).
        register_rest_route( 'qwoo/v1', '/pickup-points', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_points' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function rest_lionwheel( WP_REST_Request $r ) {
        $limited = qwoo_rate_limit_check( 'lw_hook', 120, 60 );
        if ( is_wp_error( $limited ) ) return new WP_REST_Response( [ 'ok' => false ], 429 );
        $s = self::settings();
        if ( $s['lionwheel']['hook'] === '' || ! hash_equals( $s['lionwheel']['hook'], (string) $r['hook'] ) || ! self::connected( 'lionwheel', $s ) ) {
            return new WP_REST_Response( [ 'ok' => false ], 404 );
        }
        // The body only says which task: never trusted for the status.
        $body = $r->get_json_params();
        if ( ! is_array( $body ) ) $body = $r->get_body_params();
        $task = '';
        foreach ( [ 'task_id', 'id' ] as $k ) {
            $v = $body[ $k ] ?? ( $body['task'][ $k ] ?? ( $body['data'][ $k ] ?? '' ) );
            if ( is_scalar( $v ) && preg_match( '/^\d{1,15}$/', (string) $v ) ) {
                $task = (string) $v;
                break;
            }
        }
        if ( $task === '' ) return new WP_REST_Response( [ 'ok' => true ], 200 );
        $orders = wc_get_orders( [ 'limit' => 1, 'type' => 'shop_order', 'meta_query' => [ [ 'key' => self::META_LW_TASK, 'value' => $task ] ] ] );
        if ( $orders ) self::refresh_lionwheel( $orders[0] );
        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    /** ?city=: the HFD ePost points in that city (cached 6 hours). */
    public static function rest_points( WP_REST_Request $r ) {
        $limited = qwoo_rate_limit_check( 'pickup_points', 40, 60 );
        if ( is_wp_error( $limited ) ) return $limited;
        $city = self::clean( $r->get_param( 'city' ), 60 );
        if ( mb_strlen( $city ) < 2 ) {
            return new WP_Error( 'qwoo_points_city', 'Enter your city first.', [ 'status' => 400 ] );
        }
        if ( ! self::connected( 'hfd' ) ) {
            return new WP_Error( 'qwoo_points_off', 'Pickup points aren\'t available right now.', [ 'status' => 404 ] );
        }
        $points = self::points( $city );
        if ( is_wp_error( $points ) ) {
            return new WP_Error( 'qwoo_points_failed', 'We couldn\'t load the pickup points. Please try again.', [ 'status' => 502 ] );
        }
        $response = rest_ensure_response( [ 'city' => $city, 'points' => $points, 'total' => count( $points ) ] );
        $response->header( 'Cache-Control', 'no-store' );
        return $response;
    }

    private static function points_key( $city ) {
        return 'qwoo_hfd_pts_' . md5( mb_strtolower( $city ) );
    }

    private static function forget_points() {
        update_option( 'qwoo_hfd_pts_gen', (int) get_option( 'qwoo_hfd_pts_gen', 0 ) + 1, false );
    }

    /** A city's points: [ { id, name, type, address, hours[], lat, lng } ], or a WP_Error. */
    public static function points( $city ) {
        $key    = self::points_key( $city ) . '_' . (int) get_option( 'qwoo_hfd_pts_gen', 0 );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;
        $s      = self::settings();
        $points = Qwoo_Courier_HFD::points( $s['hfd'], $city );
        if ( is_wp_error( $points ) ) return $points;
        set_transient( $key, $points, 6 * HOUR_IN_SECONDS );
        return $points;
    }

    /* ---------------- shipping method + checkout ---------------- */

    public static function load_method() {
        require_once __DIR__ . '/class-courier-pickup-method.php';
    }

    public static function register_method( $methods ) {
        if ( class_exists( 'Qwoo_HFD_Pickup_Method' ) || class_exists( 'WC_Shipping_Method' ) ) {
            self::load_method();
            $methods[ self::METHOD ] = 'Qwoo_HFD_Pickup_Method';
        }
        return $methods;
    }

    public static function has_pickup_method() {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) return false;
        foreach ( array_merge( array_column( WC_Shipping_Zones::get_zones(), 'id' ), [ 0 ] ) as $zone_id ) {
            foreach ( ( new WC_Shipping_Zone( (int) $zone_id ) )->get_shipping_methods( true ) as $m ) {
                if ( $m->id === self::METHOD ) return true;
            }
        }
        return false;
    }

    /** The order's shipping line says "Pickup point", not the meta key. */
    public static function meta_label( $key ) {
        return $key === 'qwoo_pickup_point' ? self::l( 'Pickup point', 'נקודת איסוף' ) : $key;
    }

    /** The checkout accepts { extensions: { qwoo_couriers: { pickup_point } } }; the order shows its point and tracking. */
    public static function register_schema() {
        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) return;
        $checkout = 'Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema';
        if ( class_exists( $checkout ) ) {
            woocommerce_store_api_register_endpoint_data( [
                'endpoint'        => $checkout::IDENTIFIER,
                'namespace'       => 'qwoo_couriers',
                'schema_callback' => static fn() => [
                    'pickup_point' => [ 'description' => 'The HFD pickup point the customer chose.', 'type' => [ 'string', 'null' ], 'context' => [ 'view', 'edit' ], 'optional' => true ],
                ],
            ] );
        }
        $order = 'Automattic\WooCommerce\StoreApi\Schemas\V1\OrderSchema';
        if ( class_exists( $order ) ) {
            woocommerce_store_api_register_endpoint_data( [
                'endpoint'        => $order::IDENTIFIER,
                'namespace'       => 'qwoo_shipment',
                'data_callback'   => static function ( $o ) {
                    if ( ! $o instanceof WC_Order ) return [];
                    return array_filter( [ 'tracking' => self::tracking( $o ), 'point' => self::pickup_point( $o ) ] );
                },
                'schema_callback' => static fn() => [],
            ] );
        }
    }

    /** Whether this order (or the cart behind it) ships with the pickup point method. */
    private static function uses_pickup( WC_Order $order ) {
        foreach ( $order->get_items( 'shipping' ) as $line ) {
            if ( $line->get_method_id() === self::METHOD ) return $line;
        }
        if ( function_exists( 'WC' ) && WC()->session ) {
            foreach ( (array) WC()->session->get( 'chosen_shipping_methods', [] ) as $rate ) {
                if ( strtok( (string) $rate, ':' ) === self::METHOD ) return true;
            }
        }
        return false;
    }

    /**
     * The checkout: with "Pickup point (HFD)", the chosen point must be one of
     * HFD's points in the order's city. Its name and address come from HFD's
     * list, never from the request.
     */
    public static function checkout_point( WC_Order $order, WP_REST_Request $request ) {
        $line = self::uses_pickup( $order );
        if ( ! $line ) {
            $order->delete_meta_data( self::META_POINT );
            return;
        }
        $ext = $request['extensions']['qwoo_couriers'] ?? [];
        $id  = is_array( $ext ) && is_scalar( $ext['pickup_point'] ?? null ) ? (string) $ext['pickup_point'] : '';
        if ( ! preg_match( '/^[A-Za-z0-9_-]{1,20}$/', $id ) ) {
            throw new RouteException( 'qwoo_pickup_point', esc_html( self::l( 'Please choose a pickup point.', 'נא לבחור נקודת איסוף.' ) ), 400 );
        }
        $city   = $order->get_shipping_city() ?: $order->get_billing_city();
        $points = self::clean( $city, 60 ) !== '' ? self::points( self::clean( $city, 60 ) ) : new WP_Error( 'city', '' );
        $found  = null;
        if ( ! is_wp_error( $points ) ) {
            foreach ( $points as $p ) {
                if ( (string) $p['id'] === $id ) { $found = $p; break; }
            }
            if ( ! $found ) {
                throw new RouteException( 'qwoo_pickup_point', esc_html( self::l( 'That pickup point isn\'t in your city. Please choose another one.', 'נקודת האיסוף הזו לא בעיר שלך. נא לבחור נקודה אחרת.' ) ), 400 );
            }
        }
        // HFD didn't answer: the order goes through with the point's number; the owner checks it.
        $point = $found ? [ 'id' => $id, 'name' => $found['name'], 'address' => $found['address'] ] : [ 'id' => $id, 'name' => sprintf( self::l( 'Point %s', 'נקודה %s' ), $id ), 'address' => '' ];
        $order->update_meta_data( self::META_POINT, $point );
        if ( $line instanceof WC_Order_Item_Shipping ) {
            $line->update_meta_data( 'qwoo_pickup_point', trim( $point['name'] . ( $point['address'] !== '' ? ', ' . $point['address'] : '' ) ) );
            $line->save();
        }
        if ( ! $found ) {
            $order->add_order_note( self::l( 'HFD didn\'t answer during checkout, so the pickup point wasn\'t checked. Check it before sending.', 'HFD לא ענתה בזמן התשלום, ולכן נקודת האיסוף לא נבדקה. כדאי לבדוק אותה לפני השליחה.' ) );
        }
    }
}

Qwoo_Couriers::init();

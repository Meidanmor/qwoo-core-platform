<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Payments for the platform dashboard (used by Qwoo_Platform_Dashboard):
 * cash on delivery, bank transfer, and Stripe through WooCommerce's
 * official Stripe gateway plugin.
 *
 * Stripe connects two ways, both the plugin's own:
 *   - "Connect with Stripe": the plugin's OAuth through WooCommerce's
 *     connect server. The owner returns to the platform's app (not to
 *     wp-admin here), which passes the code back through a signed call.
 *   - API keys pasted by the owner: checked against Stripe first.
 * Either way the plugin stores the keys and sets up the webhook. Secret
 * keys never leave this site.
 *
 * The storefront reads the public part (publishable key, method titles,
 * bank details) from GET /qwoo/v1/payment-config.
 */
trait Qwoo_Platform_Payments {

    private static $stripe_plugin = 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php';

    /** Payment methods the dashboard manages, besides Stripe. */
    private static $offline_gateways = [ 'cod', 'bacs' ];

    /* ---------------- dashboard actions ---------------- */

    private static function action_payments_get() {
        $out = [ 'currency' => get_woocommerce_currency(), 'https' => wp_is_using_https(), 'stripe' => self::stripe_state() ];
        foreach ( self::$offline_gateways as $id ) {
            $out[ $id ] = self::offline_gateway( $id );
        }
        return $out;
    }

    /** Settings of cash on delivery or bank transfer (with its accounts). */
    private static function offline_gateway( $id ) {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        $gateway  = $gateways[ $id ] ?? null;
        $out      = [
            'enabled'      => $gateway ? $gateway->enabled === 'yes' : false,
            'title'        => $gateway ? (string) $gateway->get_option( 'title' ) : '',
            'description'  => $gateway ? (string) $gateway->get_option( 'description' ) : '',
            'instructions' => $gateway ? (string) $gateway->get_option( 'instructions' ) : '',
        ];
        if ( $id === 'bacs' ) {
            $out['accounts'] = self::bank_accounts();
        }
        return $out;
    }

    /** { id: cod | bacs, enabled, title, description, instructions, accounts (bacs) }. */
    private static function action_payment_save( array $params ) {
        $id = (string) ( $params['id'] ?? '' );
        if ( ! in_array( $id, self::$offline_gateways, true ) ) {
            return self::bad( 'Unknown payment method.' );
        }
        // Fields that aren't sent keep their value.
        $current = self::offline_gateway( $id );
        $title   = trim( sanitize_text_field( (string) ( $params['title'] ?? $current['title'] ) ) );
        if ( $title === '' || mb_strlen( $title ) > 100 ) {
            return self::bad( 'Give the payment method a name customers see (up to 100 characters).' );
        }
        $texts = [];
        foreach ( [ 'description', 'instructions' ] as $key ) {
            $texts[ $key ] = sanitize_textarea_field( (string) ( $params[ $key ] ?? $current[ $key ] ) );
            if ( mb_strlen( $texts[ $key ] ) > 1000 ) {
                return self::bad( 'Texts can be up to 1,000 characters.' );
            }
        }
        $enabled = array_key_exists( 'enabled', $params ) ? ! empty( $params['enabled'] ) : $current['enabled'];

        $accounts = null;
        if ( $id === 'bacs' && array_key_exists( 'accounts', $params ) ) {
            $accounts = self::clean_bank_accounts( $params['accounts'] );
            if ( is_wp_error( $accounts ) ) {
                return $accounts;
            }
        }
        if ( $id === 'bacs' && $enabled && ! ( $accounts ?? $current['accounts'] ) ) {
            return self::bad( 'Add the bank account customers transfer to.' );
        }

        $saved = self::wc_rest( 'PUT', "/wc/v3/payment_gateways/$id", [
            'enabled'     => $enabled,
            'title'       => $title,
            'description' => $texts['description'],
            'settings'    => [ 'instructions' => $texts['instructions'] ],
        ] );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        if ( $accounts !== null ) {
            update_option( 'woocommerce_bacs_accounts', $accounts );
        }
        return self::action_payments_get();
    }

    /** Installs and activates the Stripe gateway plugin. */
    private static function action_stripe_install() {
        $result = self::as_admin( [ 'Qwoo_Technical_Settings', 'install_stripe_gateway' ] );
        if ( is_wp_error( $result ) ) {
            return self::bad( $result->get_error_message() );
        }
        return [ 'message' => $result ];
    }

    /**
     * Starts "Connect with Stripe": { mode: live | test, return_url } → { url }.
     * return_url must be on the platform this store is connected to.
     */
    private static function action_stripe_connect_start( array $params ) {
        $ready = self::stripe_ready();
        if ( is_wp_error( $ready ) ) {
            return $ready;
        }
        $mode   = ( $params['mode'] ?? '' ) === 'test' ? 'test' : 'live';
        $return = esc_url_raw( (string) ( $params['return_url'] ?? '' ), [ 'https', 'http' ] );
        if ( ! self::is_platform_url( $return ) ) {
            return self::bad( 'Invalid return address.' );
        }
        $url = self::as_admin( static fn() => WC_Stripe::get_instance()->connect->get_oauth_url( $return, $mode ) );
        if ( is_wp_error( $url ) ) {
            return self::bad( 'Stripe can\'t be connected right now: ' . wp_strip_all_tags( $url->get_error_message() ) );
        }
        if ( ! is_string( $url ) || strpos( $url, 'https://' ) !== 0 ) {
            return self::bad( 'Stripe can\'t be connected right now. Try again in a moment.' );
        }
        return [ 'url' => $url ];
    }

    /** Finishes "Connect with Stripe": { state, code, type, mode } from the return address. */
    private static function action_stripe_connect_finish( array $params ) {
        $ready = self::stripe_ready();
        if ( is_wp_error( $ready ) ) {
            return $ready;
        }
        $mode  = ( $params['mode'] ?? '' ) === 'test' ? 'test' : 'live';
        $type  = ( $params['type'] ?? '' ) === 'app' ? 'app' : 'connect';
        $state = (string) ( $params['state'] ?? '' );
        $code  = (string) ( $params['code'] ?? '' );
        // Printable, no spaces: they're only compared and passed on to WooCommerce's connect server.
        if ( ! preg_match( '/^[\x21-\x7e]{4,2048}$/', $state ) || ! preg_match( '/^[\x21-\x7e]{4,2048}$/', $code ) ) {
            error_log( sprintf( 'qwoo: Stripe connect answer refused (state: %d chars, code: %d chars).', strlen( $state ), strlen( $code ) ) );
            return self::bad( 'The answer from Stripe is incomplete. Try connecting again.' );
        }
        $result = self::as_admin( static fn() => WC_Stripe::get_instance()->connect->connect_oauth( $state, $code, $type, $mode ) );
        if ( is_wp_error( $result ) ) {
            $message = $result->get_error_code() === 'wc_stripe_webhook_error'
                ? 'Stripe is connected, but its notifications couldn\'t be set up: ' . wp_strip_all_tags( $result->get_error_message() )
                : 'Stripe couldn\'t be connected. Try again.';
            if ( $result->get_error_code() !== 'wc_stripe_webhook_error' ) {
                return self::bad( $message );
            }
            return [ 'warning' => $message ] + self::action_payments_get();
        }
        return self::action_payments_get();
    }

    /**
     * API keys pasted by the owner: { mode, publishable_key, secret_key }.
     * They're checked with Stripe, saved by the plugin, and the webhook is set up.
     */
    private static function action_stripe_keys( array $params ) {
        $ready = self::stripe_ready();
        if ( is_wp_error( $ready ) ) {
            return $ready;
        }
        $mode        = ( $params['mode'] ?? '' ) === 'test' ? 'test' : 'live';
        $publishable = trim( (string) ( $params['publishable_key'] ?? '' ) );
        $secret      = trim( (string) ( $params['secret_key'] ?? '' ) );
        if ( ! preg_match( "/^pk_{$mode}_[A-Za-z0-9]{10,250}$/", $publishable ) ) {
            return self::bad( "The publishable key starts with pk_{$mode}_. Copy it from Stripe → Developers → API keys." );
        }
        if ( ! preg_match( "/^[rs]k_{$mode}_[A-Za-z0-9]{10,250}$/", $secret ) ) {
            return self::bad( "The secret key starts with sk_{$mode}_ (or rk_{$mode}_ for a restricted key)." );
        }

        $test = self::wc_rest( 'POST', '/wc/v3/wc_stripe/account_keys/test', [ 'live_mode' => $mode === 'live', 'publishable' => $publishable, 'secret' => $secret ] );
        if ( is_wp_error( $test ) ) {
            return self::bad( 'Stripe didn\'t accept these keys. Check that both are from the same Stripe account and copied completely.' );
        }
        // The plugin's route only updates key fields that exist (new installs have none yet).
        $settings = self::stripe_settings();
        foreach ( [ 'publishable_key', 'secret_key', 'webhook_secret', 'test_publishable_key', 'test_secret_key', 'test_webhook_secret' ] as $field ) {
            $settings += [ $field => '' ];
        }
        self::stripe_save_settings( $settings );

        $prefix = $mode === 'test' ? 'test_' : '';
        $saved  = self::wc_rest( 'POST', '/wc/v3/wc_stripe/account_keys', [ $prefix . 'publishable_key' => $publishable, $prefix . 'secret_key' => $secret ] );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        $settings                              = self::stripe_settings();
        $settings['enabled']                   = 'yes';
        $settings['testmode']                  = $mode === 'test' ? 'yes' : 'no';
        $settings[ $prefix . 'connection_type' ] = '';
        self::stripe_save_settings( $settings );

        $hooks = self::wc_rest( 'POST', '/wc/v3/wc_stripe/account_keys/configure_webhooks', [ 'live_mode' => $mode === 'live' ] );
        $out   = self::action_payments_get();
        if ( is_wp_error( $hooks ) ) {
            $out['warning'] = 'The keys are saved, but Stripe\'s notifications couldn\'t be set up: ' . $hooks->get_error_message();
        }
        return $out;
    }

    /** { enabled, mode }: turns card payments on or off and picks live or test. */
    private static function action_stripe_settings( array $params ) {
        $ready = self::stripe_ready();
        if ( is_wp_error( $ready ) ) {
            return $ready;
        }
        $mode    = ( $params['mode'] ?? '' ) === 'test' ? 'test' : 'live';
        $enabled = ! empty( $params['enabled'] );
        if ( $enabled && ! WC_Stripe_Helper::is_connected( $mode ) ) {
            return self::bad( $mode === 'test' ? 'Connect a Stripe test account first.' : 'Connect your Stripe account first.' );
        }
        $settings             = self::stripe_settings();
        $settings['enabled']  = $enabled ? 'yes' : 'no';
        $settings['testmode'] = $mode === 'test' ? 'yes' : 'no';
        self::stripe_save_settings( $settings );
        return self::action_payments_get();
    }

    /** { mode }: removes that mode's keys (and its webhook, by the plugin). */
    private static function action_stripe_disconnect( array $params ) {
        $ready = self::stripe_ready();
        if ( is_wp_error( $ready ) ) {
            return $ready;
        }
        $mode   = ( $params['mode'] ?? '' ) === 'test' ? 'test' : 'live';
        $prefix = $mode === 'test' ? 'test_' : '';
        $result = self::wc_rest( 'POST', '/wc/v3/wc_stripe/account_keys', [ $prefix . 'publishable_key' => '', $prefix . 'secret_key' => '', $prefix . 'webhook_secret' => '' ] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $settings = self::stripe_settings();
        if ( ( $settings['testmode'] ?? 'no' ) === ( $mode === 'test' ? 'yes' : 'no' ) ) {
            $settings['enabled'] = 'no'; // the mode in use has no account now
        }
        $settings[ $prefix . 'connection_type' ] = '';
        $settings[ $prefix . 'refresh_token' ]   = '';
        self::stripe_save_settings( $settings );
        WC_Stripe::get_instance()->account->clear_cache();
        return self::action_payments_get();
    }

    /* ---------------- storefront ---------------- */

    /**
     * GET /qwoo/v1/payment-config (behind the proxy secret, like the rest of
     * the storefront API): what checkout needs to show and use each method.
     * Only public values: Stripe's publishable key, titles, bank details
     * (shown to customers who pay by transfer anyway).
     */
    public static function rest_payment_config() {
        $config   = [ 'stripe' => null, 'methods' => [] ];
        $gateways = class_exists( 'WooCommerce' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        foreach ( $gateways as $id => $gateway ) {
            if ( $gateway->enabled !== 'yes' || ! in_array( $id, [ 'stripe', 'cod', 'bacs' ], true ) ) {
                continue;
            }
            $config['methods'][ $id ] = [
                'title'       => html_entity_decode( wp_strip_all_tags( (string) $gateway->get_title() ), ENT_QUOTES ),
                'description' => wp_strip_all_tags( (string) $gateway->get_option( 'description' ) ),
            ];
        }
        if ( isset( $config['methods']['bacs'] ) ) {
            $config['methods']['bacs']['instructions'] = wp_strip_all_tags( (string) ( $gateways['bacs']->get_option( 'instructions' ) ) );
            $config['methods']['bacs']['accounts']     = self::bank_accounts();
        }
        if ( isset( $config['methods']['stripe'] ) && class_exists( 'WC_Stripe_Helper' ) ) {
            $settings = self::stripe_settings();
            $test     = ( $settings['testmode'] ?? 'no' ) === 'yes';
            $key      = (string) ( $settings[ $test ? 'test_publishable_key' : 'publishable_key' ] ?? '' );
            if ( preg_match( '/^pk_(live|test)_[A-Za-z0-9]+$/', $key ) ) {
                $config['stripe'] = [ 'publishable_key' => $key, 'test' => $test ];
            } else {
                unset( $config['methods']['stripe'] ); // on, but not connected: can't take payments
            }
        }
        $response = rest_ensure_response( $config );
        $response->header( 'Cache-Control', 'no-store' );
        return $response;
    }

    /* ---------------- helpers ---------------- */

    /** The Stripe section of payments_get (no secrets). */
    private static function stripe_state() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $state = [
            'installed' => array_key_exists( self::$stripe_plugin, get_plugins() ),
            'active'    => class_exists( 'WC_Stripe' ) && class_exists( 'WC_Stripe_Helper' ),
            'enabled'   => false,
            'mode'      => 'live',
            'live'      => [ 'connected' => false ],
            'test'      => [ 'connected' => false ],
        ];
        if ( ! $state['active'] ) {
            return $state;
        }
        $settings         = self::stripe_settings();
        $state['enabled'] = ( $settings['enabled'] ?? 'no' ) === 'yes';
        $state['mode']    = ( $settings['testmode'] ?? 'no' ) === 'yes' ? 'test' : 'live';
        foreach ( [ 'live', 'test' ] as $mode ) {
            $prefix = $mode === 'test' ? 'test_' : '';
            if ( ! WC_Stripe_Helper::is_connected( $mode ) ) {
                continue;
            }
            $account        = self::as_admin( static fn() => WC_Stripe::get_instance()->account->get_cached_account_data( $mode ) );
            $account        = is_array( $account ) ? $account : [];
            $state[ $mode ] = [
                'connected'   => true,
                'how'         => ( $settings[ $prefix . 'connection_type' ] ?? '' ) !== '' ? 'connect' : 'keys',
                'key_end'     => substr( (string) ( $settings[ $prefix . 'publishable_key' ] ?? '' ), -4 ),
                'name'        => (string) ( $account['settings']['dashboard']['display_name'] ?? $account['business_profile']['name'] ?? '' ),
                'email'       => (string) ( $account['email'] ?? '' ),
                'country'     => (string) ( $account['country'] ?? '' ),
                'can_charge'  => isset( $account['charges_enabled'] ) ? (bool) $account['charges_enabled'] : null,
                'can_payout'  => isset( $account['payouts_enabled'] ) ? (bool) $account['payouts_enabled'] : null,
                'webhook'     => ! empty( $settings[ $prefix . 'webhook_secret' ] ),
            ];
        }
        return $state;
    }

    /** The plugin's settings (its accessors changed over versions; the option didn't). */
    private static function stripe_settings() {
        $settings = get_option( 'woocommerce_stripe_settings', [] );
        return is_array( $settings ) ? $settings : [];
    }

    private static function stripe_save_settings( array $settings ) {
        update_option( 'woocommerce_stripe_settings', $settings );
    }

    private static function stripe_ready() {
        if ( ! class_exists( 'WC_Stripe' ) || ! class_exists( 'WC_Stripe_Helper' ) ) {
            return self::error( 'qwoo_dashboard_no_stripe', 'Set up card payments first.', 409 );
        }
        return true;
    }

    /** Runs $fn as the site's administrator (the Stripe plugin checks capabilities). */
    private static function as_admin( callable $fn ) {
        $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ] );
        if ( ! $admins ) {
            return new WP_Error( 'qwoo_dashboard_no_admin', 'This store has no administrator account.' );
        }
        $previous = get_current_user_id();
        wp_set_current_user( (int) $admins[0] );
        try {
            return $fn();
        } finally {
            wp_set_current_user( $previous );
        }
    }

    /** True for an address on the platform this store is connected to. */
    private static function is_platform_url( $url ) {
        $connection = Qwoo_Platform_Connection::get();
        if ( ! $connection || $url === '' ) {
            return false;
        }
        $want = wp_parse_url( (string) $connection['platform_url'] );
        $got  = wp_parse_url( $url );
        return isset( $want['host'], $got['host'], $got['scheme'] )
            && strtolower( $got['host'] ) === strtolower( $want['host'] )
            && ( $got['port'] ?? null ) === ( $want['port'] ?? null )
            && $got['scheme'] === ( $want['scheme'] ?? 'https' );
    }

    private static function bank_accounts() {
        $out = [];
        foreach ( (array) get_option( 'woocommerce_bacs_accounts', [] ) as $a ) {
            if ( is_array( $a ) ) {
                $out[] = array_map( 'strval', array_intersect_key( $a + array_fill_keys( self::bank_fields(), '' ), array_flip( self::bank_fields() ) ) );
            }
        }
        return $out;
    }

    private static function bank_fields() {
        return [ 'account_name', 'account_number', 'bank_name', 'sort_code', 'iban', 'bic' ];
    }

    private static function clean_bank_accounts( $input ) {
        $out = [];
        foreach ( array_slice( is_array( $input ) ? array_values( $input ) : [], 0, 5 ) as $a ) {
            $a   = is_array( $a ) ? $a : [];
            $row = [];
            foreach ( self::bank_fields() as $field ) {
                $row[ $field ] = trim( sanitize_text_field( is_scalar( $a[ $field ] ?? null ) ? (string) $a[ $field ] : '' ) );
                if ( mb_strlen( $row[ $field ] ) > 100 ) {
                    return self::bad( 'Bank details can be up to 100 characters each.' );
                }
            }
            if ( implode( '', $row ) === '' ) {
                continue;
            }
            if ( $row['account_number'] === '' && $row['iban'] === '' ) {
                return self::bad( 'Add the account number or IBAN of each bank account.' );
            }
            $out[] = $row;
        }
        return $out;
    }
}

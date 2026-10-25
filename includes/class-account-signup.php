<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Customers making an account themselves (Store builder → My account:
 * "Visitors can create an account", on by default).
 *
 * - POST qwoo/v1/register { first_name, last_name, email, password, website }:
 *   a WooCommerce customer, signed in at once (`website` is a honeypot).
 *   Limited to 5 accounts per 15 minutes per address (in the callback: a
 *   limit in permission_callback also counts the Allow header's run).
 * - Google sign-in only makes new accounts while sign-up is on.
 * - published(): what the storefront's forms need, in branding.json
 *   `account` { signup, google } (Google's Client ID is public; the secret
 *   never leaves the store).
 */
class Qwoo_Account_Signup {

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    /** Whether visitors can make an account (the owner's switch). */
    public static function allowed() {
        $options = get_option( 'shop_builder_options', [] );
        return ! isset( $options['account']['allow_signup'] ) || ! empty( $options['account']['allow_signup'] );
    }

    /** WooCommerce's own switch (its "My account" registration) follows the owner's. */
    public static function sync( $on ) {
        update_option( 'woocommerce_enable_myaccount_registration', $on ? 'yes' : 'no' );
        update_option( 'woocommerce_registration_generate_username', 'yes' );
    }

    /** branding.json `account`. */
    public static function published() {
        $google = class_exists( 'Qwoo_Technical_Settings' ) ? (string) Qwoo_Technical_Settings::get_key( 'GOOGLE_CLIENT_ID' ) : '';
        return [
            'signup' => self::allowed(),
            'google' => preg_match( '/^[\w.-]{10,200}$/', $google ) ? $google : '',
        ];
    }

    public static function routes() {
        register_rest_route( 'qwoo/v1', '/register', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'register' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'first_name' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'last_name'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'email'      => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ],
                // Not sanitized: that would strip valid special characters.
                'password'   => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );
    }

    private static function fail( $message, $status = 400, $code = 'qwoo_register' ) {
        return new WP_REST_Response( [ 'success' => false, 'code' => $code, 'message' => $message ], $status );
    }

    public static function register( WP_REST_Request $request ) {
        if ( ! self::allowed() ) {
            return self::fail( 'This store doesn\'t take new accounts here.', 403, 'qwoo_signup_off' );
        }
        $limited = qwoo_rate_limit_check( 'register', 5, 15 * MINUTE_IN_SECONDS );
        if ( is_wp_error( $limited ) ) {
            return self::fail( $limited->get_error_message(), 429, 'qwoo_rate_limited' );
        }
        // Bots fill every field; people never see this one. Same answer as a real failure.
        if ( trim( (string) $request->get_param( 'website' ) ) !== '' ) {
            return self::fail( 'Something went wrong. Please try again.' );
        }

        $email    = (string) $request->get_param( 'email' );
        $password = (string) $request->get_param( 'password' );
        $first    = mb_substr( trim( (string) $request->get_param( 'first_name' ) ), 0, 60 );
        $last     = mb_substr( trim( (string) $request->get_param( 'last_name' ) ), 0, 60 );

        if ( ! is_email( $email ) ) {
            return self::fail( 'Please enter a valid email address.' );
        }
        if ( strlen( $password ) < 8 ) {
            return self::fail( 'Password must be at least 8 characters.' );
        }
        if ( strlen( $password ) > 200 ) {
            return self::fail( 'That password is too long.' );
        }
        if ( email_exists( $email ) ) {
            return self::fail( 'An account with this email already exists. Please sign in.', 409, 'qwoo_email_exists' );
        }
        if ( ! function_exists( 'wc_create_new_customer' ) ) {
            return self::fail( 'Something went wrong. Please try again.', 500 );
        }

        // WooCommerce makes the customer and sends its "Your account" email.
        $names    = [ 'first_name' => $first, 'last_name' => $last ];
        $username = function_exists( 'wc_create_new_customer_username' ) ? wc_create_new_customer_username( $email, $names ) : '';
        $user_id  = wc_create_new_customer( $email, $username, $password, $names );
        if ( is_wp_error( $user_id ) ) {
            $code = $user_id->get_error_code();
            return self::fail( $code === 'registration-error-email-exists'
                ? 'An account with this email already exists. Please sign in.'
                : 'Something went wrong. Please try again.' );
        }
        if ( $first !== '' ) {
            wp_update_user( [ 'ID' => $user_id, 'display_name' => trim( $first . ' ' . $last ) ] );
        }

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true, true );

        return new WP_REST_Response( [ 'success' => true, 'user' => qwoo_get_user_data( $user_id ) ], 200 );
    }
}

Qwoo_Account_Signup::init();

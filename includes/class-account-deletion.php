<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Customers deleting their own account from the storefront (My account).
 *
 *   POST qwoo/v1/account/delete-request   signed in (REST nonce): emails a link
 *                                         to confirm, valid for an hour
 *   POST qwoo/v1/account/delete-confirm   { u, token } from that link: deletes
 *
 * Confirming by email works for every account, also ones made with Google
 * sign-in (no password), and proves it's the account's owner. Deleting does
 * what the dashboard's "Delete customer data" does (orders keep their
 * amounts without personal details, the account and saved cards go). It's
 * refused while an order is still being processed. The owner is told.
 * Only customer accounts can be deleted here (never staff).
 */
class Qwoo_Account_Deletion {

    const TOKEN_META   = '_qwoo_delete_token';   // sha256 of the token
    const EXPIRES_META = '_qwoo_delete_expires';
    const VALID_FOR    = HOUR_IN_SECONDS;

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes() {
        register_rest_route( 'qwoo/v1', '/account/delete-request', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_request' ],
            'permission_callback' => 'qwoo_require_login',
        ] );
        register_rest_route( 'qwoo/v1', '/account/delete-confirm', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_confirm' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function rest_request( WP_REST_Request $r ) {
        $user     = wp_get_current_user();
        $limited  = qwoo_rate_limit_check( 'account_delete', 5, HOUR_IN_SECONDS, 'u' . $user->ID );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }
        $customer = Qwoo_Platform_Dashboard::customer_for_user( $user->ID );
        if ( ! $customer ) {
            return new WP_Error( 'qwoo_account_staff', 'This account can\'t be deleted here. Contact the store.', [ 'status' => 403 ] );
        }
        if ( self::has_open_orders( $user->ID ) ) {
            return self::open_orders_error();
        }
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? Qwoo_Technical_Settings::get_primary_frontend_domain() : '';
        if ( $front === '' ) {
            return new WP_Error( 'qwoo_account_unavailable', 'This isn\'t available right now. Contact the store.', [ 'status' => 503 ] );
        }

        $token = bin2hex( random_bytes( 16 ) );
        update_user_meta( $user->ID, self::TOKEN_META, hash( 'sha256', $token ) );
        update_user_meta( $user->ID, self::EXPIRES_META, time() + self::VALID_FOR );

        $store = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $link  = rtrim( $front, '/' ) . '/my-account?' . http_build_query( [ 'delete_account' => $token, 'u' => $user->ID ] );
        $html  = '<p>' . esc_html( Qwoo_I18n::t( 'Someone (hopefully you) asked to delete your account at {store}.', [ 'store' => $store ] ) ) . '</p>'
            . '<p>' . esc_html( Qwoo_I18n::t( 'Deleting removes your account, your saved details and your name, email, phone and addresses from your past orders. It can\'t be undone.' ) ) . '</p>'
            . '<p><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:10px 18px;border-radius:8px;background:#b42318;color:#ffffff;text-decoration:none;font-weight:bold;">' . esc_html( Qwoo_I18n::t( 'Delete my account' ) ) . '</a></p>'
            . '<p style="color:#888888;font-size:12px;">' . esc_html( Qwoo_I18n::t( 'The link works for one hour. If you didn\'t ask for this, ignore this email: your account stays as it is.' ) ) . '</p>';
        $mailer = WC()->mailer();
        $mailer->send( $user->user_email, Qwoo_I18n::t( 'Confirm deleting your account at {store}', [ 'store' => $store ] ), $mailer->wrap_message( Qwoo_I18n::t( 'Delete your account?' ), $html ) );

        return rest_ensure_response( [ 'sent' => true, 'email' => self::masked( $user->user_email ) ] );
    }

    public static function rest_confirm( WP_REST_Request $r ) {
        $limited = qwoo_rate_limit_check( 'account_delete_confirm', 10, HOUR_IN_SECONDS );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }
        $bad     = new WP_Error( 'qwoo_account_link', 'This link isn\'t valid any more. Ask for a new one from My account.', [ 'status' => 403 ] );
        $user_id = absint( $r->get_param( 'u' ) );
        $token   = (string) $r->get_param( 'token' );
        $hash    = $user_id ? (string) get_user_meta( $user_id, self::TOKEN_META, true ) : '';
        if ( $hash === '' || ! preg_match( '/^[a-f0-9]{32}$/', $token ) || ! hash_equals( $hash, hash( 'sha256', $token ) ) || (int) get_user_meta( $user_id, self::EXPIRES_META, true ) < time() ) {
            return $bad;
        }
        $customer = Qwoo_Platform_Dashboard::customer_for_user( $user_id );
        if ( ! $customer ) {
            return $bad;
        }
        $result = Qwoo_Platform_Dashboard::erase_customer( $customer );
        if ( $result === 'open_orders' ) {
            return self::open_orders_error();
        }
        if ( get_current_user_id() === $user_id ) {
            wp_clear_auth_cookie();
        }

        // The owner hears about it (no personal details: they're gone).
        $owner = class_exists( 'Qwoo_Reviews' ) ? Qwoo_Reviews::owner_email() : (string) get_option( 'admin_email' );
        wp_mail( $owner, Qwoo_I18n::t( 'A customer deleted their account' ), Qwoo_I18n::t(
            "A customer deleted their account from your store, as privacy laws allow.\n\nTheir name, email, phone and addresses were removed from their orders ({n}). The amounts stay in your sales reports.",
            [ 'n' => (int) $result ]
        ) );
        return rest_ensure_response( [ 'deleted' => true ] );
    }

    private static function has_open_orders( $user_id ) {
        return (bool) wc_get_orders( [ 'customer_id' => $user_id, 'status' => [ 'processing', 'on-hold' ], 'limit' => 1, 'return' => 'ids' ] );
    }

    private static function open_orders_error() {
        return new WP_Error( 'qwoo_account_open_orders', 'You have an order that is still being processed. Once it\'s done, you can delete your account (or contact the store).', [ 'status' => 409 ] );
    }

    /** "d***@example.com". */
    private static function masked( $email ) {
        [ $name, $domain ] = array_pad( explode( '@', (string) $email, 2 ), 2, '' );
        return mb_substr( $name, 0, 1 ) . '***@' . $domain;
    }
}

Qwoo_Account_Deletion::init();

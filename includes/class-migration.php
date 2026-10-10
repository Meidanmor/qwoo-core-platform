<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Moving an existing WordPress / WooCommerce store in ("Move your store
 * in" in the dashboard). The old site runs the YallaFlow Mover plugin,
 * whose connection code holds its address and a secret; this store asks it
 * for the data page by page (signed requests) and brings it in
 * (Qwoo_Platform_Migration, run by the platform in steps).
 *
 * This class keeps the connection (option OPTION; the secret encrypted
 * with this site's keys and forgotten when the move is done) and what
 * stays useful afterwards:
 *
 *   - old order numbers (order meta ORDER_NUMBER) show instead of the new IDs;
 *   - old addresses that differ from the storefront's (meta OLD_PATH on
 *     products, categories and posts) answer with a redirect (Qwoo_SEO);
 *   - customers whose password couldn't come over (user meta RESET) get a
 *     "set a new password" email the first time they try to sign in.
 *
 * Items brought in carry META = "<source>:<old id>", so running the move
 * again updates them instead of adding copies.
 */
class Qwoo_Migration {

    const OPTION       = 'qwoo_migration';
    const META         = '_qwoo_mig_id';
    const SRC_META     = '_qwoo_mig_src';   // on attachments: md5 of the file's old address
    const OLD_PATH     = '_qwoo_old_path';
    const ORDER_NUMBER = '_qwoo_order_number';
    const RESET        = '_qwoo_mig_reset';  // the password couldn't come over
    const TOUCHED      = '_qwoo_mig_touched'; // signed in here since: a new move doesn't change them
    const PASS         = '_qwoo_mig_pass';   // md5 of the hash brought in

    public static function init() {
        add_filter( 'woocommerce_order_number', [ __CLASS__, 'order_number' ], 10, 2 );
        add_action( 'wp_login', [ __CLASS__, 'touched' ], 10, 2 );
    }

    /* ---------------- after the move ---------------- */

    public static function order_number( $number, $order ) {
        $old = $order instanceof WC_Order ? (string) $order->get_meta( self::ORDER_NUMBER ) : '';
        return $old !== '' ? $old : $number;
    }

    public static function touched( $login, $user ) {
        if ( $user instanceof WP_User && get_user_meta( $user->ID, self::META, true ) ) {
            update_user_meta( $user->ID, self::TOUCHED, time() );
            delete_user_meta( $user->ID, self::RESET );
        }
    }

    /**
     * For the storefront's sign-in: a customer brought in whose password
     * couldn't come over gets a reset link (at most every 15 minutes).
     * Returns the message to show, or '' when it doesn't apply.
     */
    public static function password_needed( $username ) {
        $user = is_email( $username ) ? get_user_by( 'email', $username ) : get_user_by( 'login', $username );
        if ( ! $user || ! get_user_meta( $user->ID, self::RESET, true ) ) {
            return '';
        }
        $key = 'qwoo_mig_reset_' . $user->ID;
        if ( ! get_transient( $key ) ) {
            set_transient( $key, 1, 15 * MINUTE_IN_SECONDS );
            retrieve_password( $user->user_login );
        }
        return 'Our store has moved. To sign in, set a new password: we\'ve emailed you a link.';
    }

    /** The storefront path an old address now lives at, or null. */
    public static function moved_path( $path ) {
        global $wpdb;
        $path = trim( (string) $path, '/' );
        if ( $path === '' ) {
            return null;
        }
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_status = 'publish' AND p.post_type IN ('product','post') LIMIT 1",
            self::OLD_PATH,
            $path
        ) );
        if ( $id ) {
            $post = get_post( $id );
            return $post->post_type === 'product' ? '/product/' . $post->post_name : '/blog/' . $post->post_name;
        }
        $term = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            self::OLD_PATH,
            $path
        ) );
        $t = $term ? get_term( $term ) : null;
        if ( $t instanceof WP_Term ) {
            if ( $t->taxonomy === 'product_cat' ) {
                return '/product-category/' . $t->slug;
            }
            if ( $t->taxonomy === 'category' ) {
                return '/blog/category/' . $t->slug;
            }
        }
        return null;
    }

    /* ---------------- the connection ---------------- */

    /** Tests set QWOO_MIGRATION_ALLOW_LOCAL to reach an old site on this machine. */
    public static function allow_local() {
        return defined( 'QWOO_MIGRATION_ALLOW_LOCAL' ) && QWOO_MIGRATION_ALLOW_LOCAL;
    }

    private static function key() {
        return sodium_crypto_generichash( wp_salt( 'auth' ) . '|qwoo-migration', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
    }

    public static function seal( $secret ) {
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        return base64_encode( $nonce . sodium_crypto_secretbox( $secret, $nonce, self::key() ) );
    }

    public static function unseal( $sealed ) {
        $raw = base64_decode( (string) $sealed, true );
        if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
            return '';
        }
        $open = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
        return $open === false ? '' : $open;
    }

    private static function b64url_decode( $text ) {
        return base64_decode( strtr( (string) $text, '-_', '+/' ), true );
    }

    /**
     * A pasted code → { endpoint, secret (raw bytes), name }, or a WP_Error
     * saying what's wrong with it.
     */
    public static function parse_code( $code ) {
        $code = preg_replace( '/\s+/', '', (string) $code );
        if ( strpos( $code, 'YFM1-' ) !== 0 || strlen( $code ) > 3000 ) {
            return new WP_Error( 'qwoo_migration_code', 'That isn\'t a YallaFlow Mover code. Copy it again from Tools → YallaFlow Mover on your old site.', [ 'status' => 400 ] );
        }
        $json = self::b64url_decode( substr( $code, 5 ) );
        $data = $json !== false ? json_decode( $json, true ) : null;
        if ( ! is_array( $data ) || empty( $data['e'] ) || empty( $data['s'] ) ) {
            return new WP_Error( 'qwoo_migration_code', 'The code is incomplete. Copy all of it again.', [ 'status' => 400 ] );
        }
        $endpoint = esc_url_raw( (string) $data['e'], [ 'https', 'http' ] );
        $parts    = wp_parse_url( $endpoint );
        $secret   = self::b64url_decode( (string) $data['s'] );
        if ( ! $parts || empty( $parts['host'] ) || $secret === false || strlen( $secret ) !== 32 ) {
            return new WP_Error( 'qwoo_migration_code', 'The code is incomplete. Copy all of it again.', [ 'status' => 400 ] );
        }
        if ( ( $parts['scheme'] ?? '' ) !== 'https' && ! self::allow_local() ) {
            return new WP_Error( 'qwoo_migration_code', 'Your old site needs https (SSL) to be connected safely.', [ 'status' => 400 ] );
        }
        return [
            'endpoint' => $endpoint,
            'secret'   => $secret,
            'name'     => mb_substr( sanitize_text_field( (string) ( $data['n'] ?? '' ) ), 0, 100 ),
            'host'     => strtolower( $parts['host'] ) . ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ),
        ];
    }

    /** A request to an address someone gave us: never to this server's private network. */
    public static function http( $method, $url, array $args ) {
        $args += [ 'redirection' => 0, 'user-agent' => 'YallaFlow store (' . home_url() . ')' ];
        if ( self::allow_local() ) {
            return $method === 'POST' ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
        }
        return $method === 'POST' ? wp_safe_remote_post( $url, $args ) : wp_safe_remote_get( $url, $args );
    }

    /**
     * Asks the old site for one page: { items, next } (or the summary).
     * Returns the answer or a WP_Error with a message for the owner.
     */
    public static function fetch( $endpoint, $secret, $type, $after = 0, $limit = 0 ) {
        $body  = wp_json_encode( array_filter( [ 'type' => $type, 'after' => (int) $after, 'limit' => (int) $limit ] ) );
        $time  = (string) time();
        $nonce = bin2hex( random_bytes( 16 ) );
        $resp  = self::http( 'POST', $endpoint, [
            'timeout'             => 90,
            'body'                => $body,
            'limit_response_size' => 40 * MB_IN_BYTES,
            'headers'             => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'X-YFM-Time'   => $time,
                'X-YFM-Nonce'  => $nonce,
                'X-YFM-Sig'    => hash_hmac( 'sha256', $time . "\n" . $nonce . "\n" . $body, $secret ),
            ],
        ] );
        if ( is_wp_error( $resp ) ) {
            return new WP_Error( 'qwoo_migration_unreachable', 'Your old site can\'t be reached: ' . $resp->get_error_message(), [ 'status' => 502, 'retry' => true ] );
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
        if ( $code === 200 && is_array( $data ) ) {
            return $data;
        }
        $said = is_array( $data ) && ! empty( $data['message'] ) ? mb_substr( wp_strip_all_tags( (string) $data['message'] ), 0, 300 ) : '';
        if ( $code === 401 ) {
            return new WP_Error( 'qwoo_migration_code', 'The connection code doesn\'t work anymore. Create a new one in YallaFlow Mover on your old site.', [ 'status' => 400 ] );
        }
        if ( $code === 404 ) {
            return new WP_Error( 'qwoo_migration_missing', 'YallaFlow Mover isn\'t active on your old site. Install and activate it, then try again.', [ 'status' => 400 ] );
        }
        if ( $code >= 300 && $code < 400 ) {
            return new WP_Error( 'qwoo_migration_moved', 'Your old site sent us to another address. Create the code again on the site itself (with https).', [ 'status' => 400 ] );
        }
        if ( $code === 403 || $code === 409 ) {
            return new WP_Error( 'qwoo_migration_refused', $said ?: 'Your old site refused the request.', [ 'status' => 400 ] );
        }
        return new WP_Error( 'qwoo_migration_old_site', 'Your old site had a problem answering (HTTP ' . $code . ').' . ( $said ? ' ' . $said : '' ), [ 'status' => 502, 'retry' => true ] );
    }
}

Qwoo_Migration::init();

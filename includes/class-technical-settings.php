<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles cross-origin access to this site's REST API from the headless
 * QWoo frontend.
 *
 * Access control here is two independent layers, not one:
 *
 *   Layer 1 — Proxy secret (enforce_proxy_secret, rest_pre_dispatch)
 *     Origin-agnostic and non-negotiable. Requests to protected namespaces
 *     (wc/store, qwoo) are rejected with a 403 unless they carry a valid
 *     X-Proxy-Secret header — regardless of Origin, referrer, or whether
 *     the caller is a browser at all. This is what actually stops direct
 *     curl/Postman/bot access to the store API.
 *
 *   Layer 2 — CORS headers (send_cors_headers, rest_pre_serve_request)
 *     Browser-only, and NOT a security boundary by itself. It tells the
 *     browser which Origins are allowed to read the response. A
 *     non-browser client can still send the request and get a response —
 *     CORS just stops JS running on disallowed origins from reading it.
 *     Real enforcement for protected routes still comes from Layer 1.
 *
 * In short: Layer 1 decides who is allowed to succeed; Layer 2 only
 * decides which websites' JavaScript is allowed to see the result.
 */
class Qwoo_Technical_Settings {

    private static $option_key      = 'qwoo_technical_settings';
    private static $api_keys_option = 'qwoo_api_keys';
    private static $secret_option   = 'qwoo_proxy_secret';

    // Namespaces that require the proxy secret — anything your headless
    // frontend actually calls. wp/v2 and other core routes are left alone
    // since wp-admin/Gutenberg call those directly from the browser and
    // have no way to attach a custom header.
    private static $protected_namespaces = [ 'wc/store', 'qwoo' ];

    // All API key field definitions
    private static $api_key_fields = [
            'vapid' => [
                    'label'  => 'Push Notifications (VAPID)',
                    'fields' => [
                            'VAPID_API_PUBLIC_KEY'  => 'VAPID Public Key',
                            'VAPID_API_PRIVATE_KEY' => 'VAPID Private Key',
                    ]
            ],
            'github' => [
                    'label'  => 'GitHub Integration',
                    'fields' => [
                            'GITHUB_REPO_OWNER' => 'Repository Owner',
                            'GITHUB_REPO_NAME'  => 'Repository Name',
                            'GITHUB_TOKEN'      => 'GitHub Token',
                    ]
            ],
            'google' => [
                    'label'  => 'Google OAuth',
                    'fields' => [
                            'GOOGLE_CLIENT_ID'     => 'Client ID',
                            'GOOGLE_CLIENT_SECRET' => 'Client Secret',
                            'GOOGLE_REDIRECT_URI'  => 'Redirect URI',
                    ]
            ],
    ];

    public function __construct() {
        add_action( 'admin_notices', [ __CLASS__, 'maybe_warn_missing_auth_key' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_qwoo_save_technical_settings',   [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_qwoo_reset_api_key',             [ $this, 'ajax_reset_api_key' ] );
        add_action( 'wp_ajax_qwoo_regenerate_proxy_secret',   [ $this, 'ajax_regenerate_proxy_secret' ] );
        add_action( 'wp_ajax_qwoo_sync_data',                 [ $this, 'ajax_sync_data' ] );
        add_action( 'wp_ajax_qwoo_install_stripe_gateway',    [ $this, 'ajax_install_stripe_gateway' ] );
        add_action( 'wp_ajax_qwoo_generate_vapid_keys', [ $this, 'ajax_generate_vapid_keys' ] );
        add_action( 'rest_api_init', [ $this, 'setup_cors_headers' ], 15 );

        // Layer 1 (see class docblock): the actual access-control gate.
        // Runs in PHP on every request, so it works identically on
        // Apache, Nginx, LiteSpeed, or any other host — there's no
        // server-config equivalent to keep in sync anymore.
        add_filter( 'rest_pre_dispatch', [ $this, 'enforce_proxy_secret' ], 10, 3 );

        // Auto-attach the secret to any request WordPress itself makes
        // to its own protected REST routes (cron jobs, internal
        // wp_remote_* calls, etc.) so nothing internal breaks silently.
        add_filter( 'http_request_args', [ $this, 'inject_internal_proxy_secret' ], 10, 2 );
    }

    /* ─────────────────────────────────────────
       Proxy secret: auto-generated, never
       manually typed by the site owner
    ───────────────────────────────────────── */
    public static function get_proxy_secret() {
        $secret = get_option( self::$secret_option );
        if ( empty( $secret ) ) {
            $secret = bin2hex( random_bytes( 32 ) ); // 64-char hex
            update_option( self::$secret_option, $secret );
        }
        return $secret;
    }

    public static function regenerate_proxy_secret() {
        $secret = bin2hex( random_bytes( 32 ) );
        update_option( self::$secret_option, $secret );
        return $secret;
    }

    /**
     * Layer 1 enforcement (see class docblock). Runs on every REST
     * request, before WordPress dispatches it to a route handler.
     * Rejects anything to a protected namespace that doesn't carry a
     * valid X-Proxy-Secret header — this is what actually blocks
     * Postman/curl/bots; the CORS headers in send_cors_headers() below
     * are a separate, browser-only mechanism and provide no protection
     * against non-browser clients on their own.
     */
    public function enforce_proxy_secret( $result, $server, $request ) {
        // Preflight never carries the real header — only declares intent
        // to send it via Access-Control-Request-Headers. Let it through;
        // the actual request that follows is still checked.
        if ( $request->get_method() === 'OPTIONS' ) {
            return $result;
        }

        $route = $request->get_route(); // e.g. "/wc/store/v1/cart"
        $is_protected = false;
        foreach ( self::$protected_namespaces as $ns ) {
            if ( strpos( ltrim( $route, '/' ), $ns . '/' ) === 0 ) {
                $is_protected = true;
                break;
            }
        }
        if ( ! $is_protected ) {
            return $result;
        }

        $provided = $request->get_header( 'x-proxy-secret' );
        $expected = self::get_proxy_secret();

        if ( empty( $provided ) || ! hash_equals( $expected, $provided ) ) {
            return new WP_Error(
                    'qwoo_proxy_forbidden',
                    'Direct access to this endpoint is not allowed. Requests must go through the configured frontend.',
                    [ 'status' => 403 ]
            );
        }

        return $result;
    }

    /**
     * If WordPress itself (cron, admin code, anything using wp_remote_*)
     * makes a request to one of its own protected routes, attach the
     * secret automatically. Nobody has to remember to add it by hand.
     */
    public function inject_internal_proxy_secret( $args, $url ) {
        $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $url_host  = wp_parse_url( $url, PHP_URL_HOST );

        if ( ! $home_host || ! $url_host || $url_host !== $home_host ) {
            return $args; // not calling ourselves — leave untouched
        }

        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! $path ) {
            return $args;
        }

        foreach ( self::$protected_namespaces as $ns ) {
            if ( strpos( $path, '/wp-json/' . $ns . '/' ) !== false ) {
                $args['headers']['X-Proxy-Secret'] = self::get_proxy_secret();
                break;
            }
        }

        return $args;
    }

    /**
     * Whether the WP frontend should be blocked for direct visitors.
     * Defaults to true (blocking ON) until the admin explicitly saves
     * this setting unchecked — so fresh installs are headless by default.
     */
    public static function is_frontend_blocked() {
        $settings = get_option( self::$option_key, [] );
        return array_key_exists( 'block_frontend', $settings )
                ? ! empty( $settings['block_frontend'] )
                : true;
    }

    /* ─────────────────────────────────────────
       Layer 2 (see class docblock): browser CORS
       headers. Pure PHP — no .htaccess involved,
       so this is identical on every host. This
       layer only controls which Origins a browser
       will let JS read the response from; it does
       NOT block the request itself from reaching
       WordPress (Layer 1 above handles that part).
    ───────────────────────────────────────── */
    public function setup_cors_headers() {
        remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
        add_filter( 'rest_pre_serve_request', [ $this, 'send_cors_headers' ] );
    }

    public function send_cors_headers( $value ) {
        $origin  = get_http_origin();
        $allowed = self::get_allowed_origins();

        // No Origin header (server-to-server calls, curl, cron) or an
        // Origin not in the allow-list: skip CORS headers entirely.
        // This does NOT block the request — Layer 1 is what actually
        // rejects unauthorized access to protected namespaces. Skipping
        // headers here only means a disallowed browser origin won't be
        // able to read the response even if it got one.
        if ( $origin && in_array( rtrim( $origin, '/' ), $allowed, true ) ) {
            header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE' );
            header( 'Access-Control-Allow-Headers: Cart-Token, Content-Type, Authorization, X-Requested-With, X-WP-Nonce, X-Proxy-Secret' );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Vary: Origin' );
        }

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
            status_header( 204 );
            exit;
        }

        return $value;
    }

    /* ─────────────────────────────────────────
       Encryption helpers
    ───────────────────────────────────────── */
    private static function encrypt( $value ) {
        if ( empty( $value ) ) return '';
        $key = self::get_encryption_key();

        // No real secret available — refuse to "encrypt" with a key anyone
        // could reconstruct from this public repo. Better to visibly fail
        // to save than to silently store something that looks protected
        // but isn't.
        if ( false === $key ) return '';

        $iv     = openssl_random_pseudo_bytes( 12 ); // 96-bit IV, recommended size for GCM
        $tag    = '';
        $cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );

        if ( false === $cipher ) return '';

        // 'gcm:' prefix lets decrypt() tell new-format values apart from
        // legacy AES-256-CBC values still sitting in the DB.
        return 'gcm:' . base64_encode( $iv . $tag . $cipher );
    }

    private static function decrypt( $value ) {
        if ( empty( $value ) ) return '';
        $key = self::get_encryption_key();
        if ( false === $key ) return '';

        if ( strpos( $value, 'gcm:' ) === 0 ) {
            $raw = base64_decode( substr( $value, 4 ) );
            if ( false === $raw || strlen( $raw ) < 12 + 16 ) return '';

            $iv     = substr( $raw, 0, 12 );
            $tag    = substr( $raw, 12, 16 );
            $cipher = substr( $raw, 28 );

            $plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
            return false === $plain ? '' : $plain;
        }

        // Legacy AES-256-CBC format — kept only so keys saved before this
        // upgrade still decrypt correctly. Anything re-saved through the
        // settings UI from now on is written back out as GCM.
        $parts = explode( '::', base64_decode( $value ), 2 );
        if ( count( $parts ) !== 2 ) return '';
        [ $iv, $cipher ] = $parts;

        $plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
        return false === $plain ? '' : $plain;
    }

    /**
     * Returns the raw encryption key derived from AUTH_KEY, or false if
     * AUTH_KEY isn't a real, unique secret. We deliberately do NOT fall
     * back to a hardcoded string here: this file is public on GitHub, so
     * any fallback baked into the code would itself be public — anyone
     * with a DB dump could trivially decrypt every stored API key/token
     * on an install that hit the fallback. Refusing to encrypt/decrypt is
     * safer than silently "protecting" secrets with a known key.
     */
    private static function get_encryption_key() {
        if ( ! defined( 'AUTH_KEY' ) || AUTH_KEY === '' || strpos( AUTH_KEY, 'put your unique phrase here' ) !== false ) {
            return false;
        }
        return hash( 'sha256', AUTH_KEY, true );
    }

    /**
     * Warn in wp-admin if AUTH_KEY is missing/default, since that silently
     * breaks API key storage above rather than throwing a visible error.
     */
    public static function maybe_warn_missing_auth_key() {
        if ( false !== self::get_encryption_key() ) return;

        if ( ! current_user_can( 'manage_options' ) ) return;

        echo '<div class="notice notice-error"><p><strong>Q-Woo:</strong> '
                . 'WordPress\'s <code>AUTH_KEY</code> is missing or still set to its default placeholder in <code>wp-config.php</code>. '
                . 'Until this is fixed with a real, unique value, Q-Woo cannot securely store or read your API keys (GitHub token, Google OAuth, VAPID keys) — they will appear unset. '
                . 'Generate a fresh set of unique keys at <a href="https://api.wordpress.org/secret-key/1.1/salt/" target="_blank" rel="noopener">api.wordpress.org/secret-key/1.1/salt/</a> and replace the corresponding block in <code>wp-config.php</code>.'
                . '</p></div>';
    }

    /* ─────────────────────────────────────────
       Public: get a decrypted key by constant name
       Called from security.php / other includes
    ───────────────────────────────────────── */
    public static function get_key( $constant_name ) {
        // Prefer wp-config.php constant if defined
        if ( defined( $constant_name ) ) {
            return constant( $constant_name );
        }
        $stored = get_option( self::$api_keys_option, [] );
        if ( ! empty( $stored[ $constant_name ] ) ) {
            return self::decrypt( $stored[ $constant_name ] );
        }
        return '';
    }

    /**
     * Builds the list of Origins allowed by Layer 2 (browser CORS —
     * see class docblock). Consumed only by send_cors_headers() above;
     * Layer 1's proxy-secret check does not use this list at all, since
     * it's origin-agnostic by design.
     */
    public static function get_allowed_origins() {
        $settings = get_option( self::$option_key, [] );
        $origins  = [];

        // The WP backend's own origin — derived from home_url(), so this
        // is correct for every install automatically, never hardcoded to
        // any specific domain. Needed so wp-admin/Gutenberg's own
        // same-origin REST calls keep getting standard headers.
        $site_origin = self::get_site_origin();
        if ( $site_origin ) {
            $origins[] = $site_origin;
        }

        $frontend_domain = self::get_primary_frontend_domain();
        if ( $frontend_domain ) {
            $origins[] = $frontend_domain;
        }

        if ( ! empty( $settings['localhost_enabled'] ) && ! empty( $settings['localhost_port'] ) ) {
            $origins[] = 'https://localhost:' . intval( $settings['localhost_port'] );
        }

        return array_values( array_unique( $origins ) );
    }

    /**
     * Returns the configured frontend domain, normalized (trimmed, no
     * trailing slash). This is the single source of truth for "what is
     * the frontend URL" — every read path (CORS, canonical URLs, push
     * notification icons, the Shop Builder preview iframe, etc.) should
     * go through this instead of reading $settings['frontend_domain']
     * directly, so normalization can't drift out of sync between callers.
     *
     * NOTE: only one frontend domain is supported right now. Multiple
     * domains previously existed here (`frontend_domains` repeater) but
     * were reverted — the frontend config (branding, hero images, app
     * icons, etc.) is shared across every domain anyway, so supporting
     * more than one didn't do anything meaningful yet. If per-domain
     * config is ever built, this is the place to reintroduce a list.
     */
    public static function get_primary_frontend_domain() {
        $settings = get_option( self::$option_key, [] );
        $domain   = $settings['frontend_domain'] ?? '';
        return $domain ? rtrim( trim( $domain ), '/' ) : '';
    }

    /**
     * Returns the configured frontend domain if it matches the given
     * Origin, or '' otherwise. Used to validate an incoming Origin
     * header before trusting it (e.g. storing it on an order) rather
     * than persisting whatever a client claims.
     */
    public static function get_frontend_url_from_origin( $origin ) {
        $origin = untrailingslashit( trim( $origin ) );

        if ( empty( $origin ) ) {
            return '';
        }

        $frontend_domain = self::get_primary_frontend_domain();

        return ( $frontend_domain && $frontend_domain === $origin ) ? $frontend_domain : '';
    }

    /* ─────────────────────────────────────────
       Public: get push notification sender email
    ───────────────────────────────────────── */
    public static function get_push_email() {
        $settings = get_option( self::$option_key, [] );
        // Fall back to WordPress admin email if not set
        return ! empty( $settings['push_email'] ) ? $settings['push_email'] : get_option( 'admin_email' );
    }

    /* ─────────────────────────────────────────
    Public: get abandoned cart threshold (minutes)
    ───────────────────────────────────────── */
    public static function get_abandoned_cart_threshold() {
        $settings = get_option( self::$option_key, [] );
        $minutes  = isset( $settings['abandoned_cart_threshold'] )
                ? absint( $settings['abandoned_cart_threshold'] )
                : 0;

        return $minutes > 0 ? $minutes : 30; // sane default if unset/invalid
    }

    /* ─────────────────────────────────────────
       Register Settings
    ───────────────────────────────────────── */
    public function register_settings() {
        register_setting( 'qwoo_technical_group', self::$option_key );
    }

    /* ─────────────────────────────────────────
       Enqueue Assets
    ───────────────────────────────────────── */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'qwoo-technical-settings' ) === false ) return;

        wp_enqueue_style(
                'qwoo-technical-settings',
                QWOO_URL . 'assets/admin/css/technical-settings.css',
                [],
                QWOO_VERSION
        );

        wp_enqueue_script(
                'qwoo-technical-settings',
                QWOO_URL . 'assets/admin/js/technical-settings.js',
                [ 'jquery' ],
                QWOO_VERSION,
                true
        );

        wp_localize_script( 'qwoo-technical-settings', 'qwooTech', [
                'nonce'    => wp_create_nonce( 'qwoo_technical_nonce' ),
                'ajax_url' => admin_url( 'admin-ajax.php' ),
        ] );
    }

    /* ─────────────────────────────────────────
       AJAX: Save Settings
    ───────────────────────────────────────── */
    public function ajax_save_settings() {
        check_ajax_referer( 'qwoo_technical_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // ── Save CORS / general settings ──
        $posted = $_POST['qwoo_technical'] ?? [];

        $frontend_domain = esc_url_raw( trim( $posted['frontend_domain'] ?? '' ) );
        $frontend_domain = rtrim( $frontend_domain, '/' );

        $settings = [
                'frontend_domain'   => $frontend_domain,
                'localhost_enabled' => ! empty( $posted['localhost_enabled'] ),
                'localhost_port'    => absint( $posted['localhost_port'] ?? 9000 ),
                'push_email'        => sanitize_email( $posted['push_email'] ?? '' ),
                'block_frontend'    => ! empty( $posted['block_frontend'] ),
                'abandoned_cart_threshold'  => max( 1, absint( $posted['abandoned_cart_threshold'] ?? 30 ) ),
        ];
        update_option( self::$option_key, $settings );

        // ── Save API keys (encrypt, skip placeholder values) ──
        $existing_keys = get_option( self::$api_keys_option, [] );
        $posted_keys   = $_POST['qwoo_api_keys'] ?? [];

        foreach ( $posted_keys as $key_name => $value ) {
            $value = sanitize_text_field( $value );
            // Skip if empty or still masked (user didn't change it)
            if ( empty( $value ) || $value === '••••••••' ) continue;
            $existing_keys[ $key_name ] = self::encrypt( $value );
        }
        update_option( self::$api_keys_option, $existing_keys );

        wp_send_json_success( 'Settings saved successfully.' );
    }

    /* ─────────────────────────────────────────
       AJAX: Reset a single API key
    ───────────────────────────────────────── */
    public function ajax_reset_api_key() {
        check_ajax_referer( 'qwoo_technical_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $key_name = sanitize_key( $_POST['key_name'] ?? '' );
        if ( empty( $key_name ) ) {
            wp_send_json_error( 'Invalid key name' );
        }

        $existing = get_option( self::$api_keys_option, [] );
        unset( $existing[ $key_name ] );
        update_option( self::$api_keys_option, $existing );

        wp_send_json_success( 'Key reset.' );
    }

    /* ─────────────────────────────────────────
       AJAX: Generate a new VAPID key pair
       Uses the bundled minishlink/web-push library
       (already a dependency for push notifications)
       instead of requiring the admin to run any
       external tool or CLI.
    ───────────────────────────────────────── */
    public function ajax_generate_vapid_keys() {
        check_ajax_referer( 'qwoo_technical_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // If keys are hard-set in wp-config.php, generating here would be
        // misleading — those values always win over anything we save.
        if ( defined( 'VAPID_API_PUBLIC_KEY' ) || defined( 'VAPID_API_PRIVATE_KEY' ) ) {
            wp_send_json_error( 'VAPID keys are defined in wp-config.php and cannot be generated from here. Remove those constants first if you want to manage keys from this screen.' );
        }

        if ( ! class_exists( '\Minishlink\WebPush\VAPID' ) ) {
            $autoload = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
            if ( file_exists( $autoload ) ) {
                require_once $autoload;
            }
        }

        if ( ! class_exists( '\Minishlink\WebPush\VAPID' ) ) {
            wp_send_json_error( 'Push notification library not found. Please reinstall the plugin (composer dependencies missing).' );
        }

        try {
            $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
        } catch ( \Throwable $e ) {
            wp_send_json_error( 'Key generation failed: ' . $e->getMessage() );
        }

        if ( empty( $keys['publicKey'] ) || empty( $keys['privateKey'] ) ) {
            wp_send_json_error( 'Key generation returned an unexpected result. Please try again.' );
        }

        $existing = get_option( self::$api_keys_option, [] );
        $existing['VAPID_API_PUBLIC_KEY']  = self::encrypt( $keys['publicKey'] );
        $existing['VAPID_API_PRIVATE_KEY'] = self::encrypt( $keys['privateKey'] );
        update_option( self::$api_keys_option, $existing );

        // Only the public key is ever sent back to the browser — the
        // private key is encrypted at rest and never leaves the server.
        wp_send_json_success( [
                'publicKey' => $keys['publicKey'],
                'message'   => 'New VAPID keys generated and saved.',
        ] );
    }

    /* ─────────────────────────────────────────
       AJAX: Regenerate the proxy secret
    ───────────────────────────────────────── */
    public function ajax_regenerate_proxy_secret() {
        check_ajax_referer( 'qwoo_technical_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $new_secret = self::regenerate_proxy_secret();

        wp_send_json_success( [
                'secret'  => $new_secret,
                'message' => 'Secret regenerated. Update PROXY_SHARED_SECRET on your frontend now — old requests will start failing immediately.',
        ] );
    }

    /* ─────────────────────────────────────────
       Manual "Sync Data Now" — pushes fresh
       products.json, categories.json, and
       price-meta.json to GitHub on demand.
    ───────────────────────────────────────── */
    public function ajax_sync_data() {
        check_ajax_referer( 'qwoo_technical_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! function_exists( 'aps_sync_all_data_to_github' ) ) {
            wp_send_json_error( [ 'results' => [
                    'products'   => 'unavailable',
                    'categories' => 'unavailable',
                    'price-meta' => 'unavailable',
            ] ] );
        }

        // Regenerates products/categories/price-meta and pushes any that
        // changed to GitHub in a SINGLE commit, instead of one commit per file.
        $sync = aps_sync_all_data_to_github();

        $results = [];
        foreach ( array_keys( [ 'products' => 1, 'categories' => 1, 'price-meta' => 1 ] ) as $label ) {
            if ( in_array( $label, $sync['failed'], true ) ) {
                $results[ $label ] = 'failed';
            } elseif ( in_array( $label, $sync['updated'], true ) ) {
                $results[ $label ] = 'synced';
            } elseif ( in_array( $label, $sync['skipped'], true ) ) {
                $results[ $label ] = 'up to date';
            } else {
                // Shouldn't normally happen, but keep the response shape stable.
                $results[ $label ] = $sync['ok'] ? 'up to date' : 'failed';
            }
        }

        $response = [ 'results' => $results ];

        if ( ! $sync['ok'] || ! empty( $sync['failed'] ) ) {
            wp_send_json_error( $response );
        }

        wp_send_json_success( $response );
    }

    /* ─────────────────────────────────────────
    AJAX: Install & Activate WooCommerce Stripe Gateway
    ───────────────────────────────────────── */
    public function ajax_install_stripe_gateway() {
        check_ajax_referer( 'qwoo_technical_nonce', 'nonce' );

        if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $plugin_file = 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php';

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        // Already active — nothing to do.
        if ( is_plugin_active( $plugin_file ) ) {
            wp_send_json_success( [ 'status' => 'active', 'message' => 'Stripe gateway is already active.' ] );
        }

        // Downloaded but not active — just activate it.
        if ( array_key_exists( $plugin_file, get_plugins() ) ) {
            $result = activate_plugin( $plugin_file );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( 'Could not activate: ' . $result->get_error_message() );
            }
            wp_send_json_success( [ 'status' => 'active', 'message' => 'Stripe gateway activated.' ] );
        }

        // Not installed — download and install. This needs direct filesystem
        // access; hosts requiring FTP credentials can't complete this over
        // AJAX (there's no UI here to collect them), so fall back to
        // pointing the admin at the manual install screen instead of hanging.
        if ( 'direct' !== get_filesystem_method() ) {
            wp_send_json_error(
                    'Your server requires FTP credentials to install plugins. ' .
                    'Please install "WooCommerce Stripe Gateway" manually from Plugins → Add New.'
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        if ( ! WP_Filesystem() ) {
            wp_send_json_error( 'Could not access the filesystem to install the plugin.' );
        }

        $api = plugins_api( 'plugin_information', [
                'slug'   => 'woocommerce-gateway-stripe',
                'fields' => [ 'sections' => false ],
        ] );

        if ( is_wp_error( $api ) ) {
            wp_send_json_error( 'Could not reach WordPress.org: ' . $api->get_error_message() );
        }

        $upgrader  = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
        $installed = $upgrader->install( $api->download_link );

        if ( is_wp_error( $installed ) || ! $installed ) {
            wp_send_json_error( 'Installation failed. Please install "WooCommerce Stripe Gateway" manually from Plugins → Add New.' );
        }

        $result = activate_plugin( $plugin_file );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( 'Installed but could not activate: ' . $result->get_error_message() );
        }

        wp_send_json_success( [ 'status' => 'active', 'message' => 'Stripe gateway installed and activated.' ] );
    }

    /**
     * Current install state of the Stripe gateway, for the settings page.
     */
    private static function stripe_gateway_status() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_file = 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php';
        if ( is_plugin_active( $plugin_file ) )        return 'active';
        if ( array_key_exists( $plugin_file, get_plugins() ) ) return 'inactive';
        return 'not_installed';
    }

    /**
     * Get the scheme+host(+port) of this WordPress install, as a plain
     * string suitable for exact-match comparison against Access-Control
     * request Origins in get_allowed_origins(). Derived from home_url()
     * so it's always correct without hardcoding any specific domain.
     */
    public static function get_site_origin() {
        $home = home_url();
        $parts = wp_parse_url( $home );

        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return '';
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if ( ! empty( $parts['port'] ) ) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    /* ─────────────────────────────────────────
       Settings Page HTML
    ───────────────────────────────────────── */
    public function render_page() {
        $settings    = get_option( self::$option_key, [] );
        $stored_keys = get_option( self::$api_keys_option, [] );
        $proxy_secret = self::get_proxy_secret();
        ?>
        <div class="wrap qwoo-tech-wrap">
            <div class="qwoo-tech-header">
                <div class="qwoo-tech-header__logo">⚙</div>
                <div>
                    <h1>Technical Settings</h1>
                    <p class="qwoo-tech-header__sub">CORS, security, and API configuration for your headless setup</p>
                </div>
            </div>

            <div id="qwoo-save-notice" class="qwoo-notice" style="display:none;"></div>

            <div class="qwoo-tech-grid">

                <!-- ── CORS & Domains ── -->
                <div class="qwoo-card">
                    <div class="qwoo-card__head">
                        <span class="qwoo-card__icon">🌐</span>
                        <div>
                            <h2>CORS & Allowed Origins</h2>
                            <p>Controls which domains browsers will let read responses from your REST API. Handled entirely in PHP — no server config file is touched.</p>
                        </div>
                    </div>

                    <div class="qwoo-field">
                        <label for="frontend_domain">Frontend Domain</label>
                        <input
                                type="url"
                                id="frontend_domain"
                                name="qwoo_technical[frontend_domain]"
                                value="<?php echo esc_attr( $settings['frontend_domain'] ?? '' ); ?>"
                                placeholder="https://your-app.com"
                                class="qwoo-input"
                        />
                        <span class="qwoo-hint">The URL of your headless frontend app (no trailing slash). Added to the allowed CORS origins.</span>
                    </div>

                    <div class="qwoo-field">
                        <label class="qwoo-toggle-label">
                            <input
                                    type="checkbox"
                                    id="localhost_enabled"
                                    name="qwoo_technical[localhost_enabled]"
                                    value="1"
                                    <?php checked( ! empty( $settings['localhost_enabled'] ) ); ?>
                            />
                            <span class="qwoo-toggle"></span>
                            Allow localhost (development environment)
                        </label>
                    </div>

                    <div class="qwoo-field qwoo-localhost-port <?php echo empty( $settings['localhost_enabled'] ) ? 'qwoo-hidden' : ''; ?>">
                        <label for="localhost_port">Localhost Port</label>
                        <input
                                type="number"
                                id="localhost_port"
                                name="qwoo_technical[localhost_port]"
                                value="<?php echo esc_attr( $settings['localhost_port'] ?? 9000 ); ?>"
                                min="1"
                                max="65535"
                                class="qwoo-input qwoo-input--short"
                                placeholder="9000"
                        />
                        <span class="qwoo-hint">e.g. 9000 → allows <code>https://localhost:9000</code></span>
                    </div>
                </div>

                <!-- ── Frontend Access ── -->
                <div class="qwoo-card">
                    <div class="qwoo-card__head">
                        <span class="qwoo-card__icon">🚧</span>
                        <div>
                            <h2>Frontend Access</h2>
                            <p>Controls whether visitors can browse this WordPress site directly.</p>
                        </div>
                    </div>

                    <div class="qwoo-field">
                        <label class="qwoo-toggle-label">
                            <input
                                    type="checkbox"
                                    id="block_frontend"
                                    name="qwoo_technical[block_frontend]"
                                    value="1"
                                    <?php checked( self::is_frontend_blocked() ); ?>
                            />
                            <span class="qwoo-toggle"></span>
                            Block the WordPress frontend (recommended for headless setups)
                        </label>
                        <span class="qwoo-hint">Visitors who land on this WordPress site directly are redirected to your QWoo frontend. Admin, login, and REST API requests are never affected.</span>
                    </div>
                </div>

                <!-- ── Payments ── -->
                <div class="qwoo-card">
                    <div class="qwoo-card__head">
                        <span class="qwoo-card__icon">💳</span>
                        <div>
                            <h2>Payments</h2>
                            <p>QWoo's checkout uses Stripe. This installs WooCommerce's official Stripe gateway plugin.</p>
                        </div>
                    </div>

                    <?php $stripe_status = self::stripe_gateway_status(); ?>
                    <div class="qwoo-field">
                        <div class="qwoo-stripe-status">
            <span class="qwoo-badge <?php echo $stripe_status === 'active' ? 'qwoo-badge--set' : 'qwoo-badge--unset'; ?>">
                <?php
                echo $stripe_status === 'active'
                        ? 'Installed &amp; Active'
                        : ( $stripe_status === 'inactive' ? 'Installed, Not Active' : 'Not Installed' );
                ?>
            </span>
                            <button
                                    type="button"
                                    id="qwoo-install-stripe"
                                    class="qwoo-btn qwoo-btn--secondary"
                                    <?php echo $stripe_status === 'active' ? 'style="display:none;"' : ''; ?>
                            >
                                <span class="qwoo-btn__text">Install &amp; Activate Stripe Gateway</span>
                                <span class="qwoo-btn__loader" style="display:none;">Installing…</span>
                            </button>
                        </div>
                        <span class="qwoo-hint">After installing, connect your Stripe account under WooCommerce → Settings → Payments → Stripe.</span>
                    </div>
                </div>

                <!-- ── Proxy Secret ── -->
                <div class="qwoo-card">
                    <div class="qwoo-card__head">
                        <span class="qwoo-card__icon">🔒</span>
                        <div>
                            <h2>Frontend Proxy Secret</h2>
                            <p>Blocks direct access to your store API (even from tools like Postman or bots) so requests only work when routed through your frontend's proxy. Generated automatically — you never need to invent one yourself.</p>
                        </div>
                    </div>

                    <div class="qwoo-field">
                        <label>Secret Key</label>
                        <div class="qwoo-masked-row">
                            <input
                                    type="text"
                                    id="qwoo-proxy-secret-value"
                                    class="qwoo-input"
                                    value="<?php echo esc_attr( $proxy_secret ); ?>"
                                    readonly
                                    onclick="this.select();"
                            />
                            <button
                                    type="button"
                                    class="qwoo-btn qwoo-btn--ghost"
                                    onclick="navigator.clipboard.writeText(document.getElementById('qwoo-proxy-secret-value').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 1500);"
                            >Copy</button>
                        </div>
                        <span class="qwoo-hint">
                            Paste this into your frontend's <code>PROXY_SHARED_SECRET</code> environment variable (server-side only — never a <code>VITE_</code>-prefixed variable).
                        </span>
                    </div>

                    <div class="qwoo-field">
                        <button type="button" id="qwoo-regenerate-secret" class="qwoo-btn qwoo-btn--ghost">Regenerate Secret</button>
                        <span class="qwoo-hint">Regenerating immediately invalidates the old value — update it on your frontend right after, or requests will start failing.</span>
                    </div>
                </div>

                <!-- ── Abandoned Cart Cron ── -->
                <div class="qwoo-card">
                    <div class="qwoo-card__head">
                        <span class="qwoo-card__icon">⏱</span>
                        <div>
                            <h2>Abandoned Cart Cron</h2>
                            <p>Paste this exact URL into your host's cron job panel (or crontab) to run abandoned-cart checks on a schedule. The security key below is generated automatically — you never need to set one yourself.</p>
                        </div>
                    </div>

                    <div class="qwoo-field">
                        <label>Cron URL</label>
                        <div class="qwoo-masked-row">
                            <input
                                    type="text"
                                    class="qwoo-input"
                                    value="<?php echo esc_url( qwoo_get_cron_url() ); ?>"
                                    readonly
                                    onclick="this.select();"
                            />
                        </div>
                        <span class="qwoo-hint">Typical host setup: run this URL via <code>curl</code> or <code>wget</code> every 15–60 minutes. Requests without the correct key are rejected automatically.</span>
                    </div>
                    <div class="qwoo-field">
                        <label for="abandoned_cart_threshold">Inactivity Threshold (minutes)</label>
                        <input
                                type="number"
                                id="abandoned_cart_threshold"
                                name="qwoo_technical[abandoned_cart_threshold]"
                                value="<?php echo esc_attr( self::get_abandoned_cart_threshold() ); ?>"
                                min="1"
                                step="1"
                                class="qwoo-input qwoo-input--short"
                                placeholder="5"
                        />
                        <span class="qwoo-hint">How many minutes of inactivity (no app activity ping) before a cart is considered abandoned and a push notification is sent.</span>
                    </div>
                </div>

                <!-- ── API Keys ── -->
                <?php foreach ( self::$api_key_fields as $group_key => $group ) : ?>
                    <div class="qwoo-card">
                        <div class="qwoo-card__head">
                        <span class="qwoo-card__icon">
                            <?php echo $group_key === 'vapid' ? '🔔' : ( $group_key === 'github' ? '🐙' : '🔑' ); ?>
                        </span>
                            <div>
                                <h2><?php echo esc_html( $group['label'] ); ?></h2>
                                <p>Keys are encrypted before storage using your WordPress secret key.</p>
                            </div>
                        </div>

                        <?php if ( $group_key === 'vapid' ) :
                            $vapid_locked  = defined( 'VAPID_API_PUBLIC_KEY' ) || defined( 'VAPID_API_PRIVATE_KEY' );
                            $vapid_is_set  = ! empty( $stored_keys['VAPID_API_PUBLIC_KEY'] ) || defined( 'VAPID_API_PUBLIC_KEY' );
                            ?>
                            <div class="qwoo-field">
                                <label for="push_email">Notification Sender Email</label>
                                <input
                                        type="email"
                                        id="push_email"
                                        name="qwoo_technical[push_email]"
                                        value="<?php echo esc_attr( $settings['push_email'] ?? get_option( 'admin_email' ) ); ?>"
                                        placeholder="your@email.com"
                                        class="qwoo-input"
                                />
                                <span class="qwoo-hint">Used as the VAPID <code>subject</code> — identifies who is sending push notifications. Defaults to your WordPress admin email.</span>
                            </div>

                            <?php if ( ! $vapid_locked ) : ?>
                            <div class="qwoo-field">
                                <button type="button" id="qwoo-generate-vapid" class="qwoo-btn qwoo-btn--secondary">
                                    <span class="qwoo-btn__text">⚡ Generate VAPID Keys</span>
                                    <span class="qwoo-btn__loader" style="display:none;">Generating…</span>
                                </button>
                                <span class="qwoo-hint">
                            Creates a valid public/private key pair automatically — no external tools needed.
                            <?php echo $vapid_is_set ? ' Generating new keys will replace the existing pair, and any devices already subscribed for web push will need to re-subscribe.' : ''; ?>
                        </span>
                            </div>
                        <?php endif; ?>
                        <?php endif; ?>

                        <?php foreach ( $group['fields'] as $const_name => $field_label ) :
                            $is_set = ! empty( $stored_keys[ $const_name ] ) || defined( $const_name );
                            $from_config = defined( $const_name );
                            ?>
                            <div class="qwoo-field qwoo-key-field" data-key="<?php echo esc_attr( $const_name ); ?>">
                                <label><?php echo esc_html( $field_label ); ?>
                                    <?php if ( $from_config ) : ?>
                                        <span class="qwoo-badge qwoo-badge--config">wp-config.php</span>
                                    <?php elseif ( $is_set ) : ?>
                                        <span class="qwoo-badge qwoo-badge--set">Saved</span>
                                    <?php else : ?>
                                        <span class="qwoo-badge qwoo-badge--unset">Not set</span>
                                    <?php endif; ?>
                                </label>

                                <?php if ( $from_config ) : ?>
                                    <div class="qwoo-config-note">
                                        Defined in <code>wp-config.php</code> — plugin will use that value automatically.
                                    </div>
                                <?php elseif ( $const_name === 'VAPID_API_PUBLIC_KEY' && $is_set ) : ?>
                                    <div class="qwoo-masked-row">
                                        <input
                                                type="text"
                                                id="qwoo-vapid-public-key"
                                                class="qwoo-input qwoo-input--pubkey"
                                                value="<?php echo esc_attr( self::get_key( $const_name ) ); ?>"
                                                readonly
                                                onclick="this.select();"
                                        />
                                        <button
                                                type="button"
                                                class="qwoo-btn qwoo-btn--ghost qwoo-copy-key"
                                                data-copy-target="qwoo-vapid-public-key"
                                        >Copy</button>
                                    </div>
                                    <span class="qwoo-hint">Public — safe to paste into your frontend app's push-subscription code.</span>
                                <?php elseif ( $is_set ) : ?>
                                    <div class="qwoo-masked-row">
                                        <input type="text" class="qwoo-input qwoo-input--masked" value="••••••••••••••••" readonly />
                                        <button
                                                type="button"
                                                class="qwoo-btn qwoo-btn--ghost qwoo-reset-key"
                                                data-key="<?php echo esc_attr( $const_name ); ?>"
                                        >Reset</button>
                                    </div>
                                <?php else : ?>
                                    <input
                                            type="text"
                                            name="qwoo_api_keys[<?php echo esc_attr( $const_name ); ?>]"
                                            class="qwoo-input qwoo-input--key"
                                            placeholder="Enter <?php echo esc_attr( $field_label ); ?>"
                                            autocomplete="off"
                                    />
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php if ( $group_key === 'github' ) :
                            $github_configured = ( ! empty( $stored_keys['GITHUB_REPO_OWNER'] ) || defined( 'GITHUB_REPO_OWNER' ) )
                                    && ( ! empty( $stored_keys['GITHUB_REPO_NAME'] ) || defined( 'GITHUB_REPO_NAME' ) )
                                    && ( ! empty( $stored_keys['GITHUB_TOKEN'] ) || defined( 'GITHUB_TOKEN' ) );
                            ?>
                            <div class="qwoo-field">
                                <button
                                        type="button"
                                        id="qwoo-sync-data"
                                        class="qwoo-btn qwoo-btn--secondary"
                                        <?php disabled( ! $github_configured ); ?>
                                >
                                    <span class="qwoo-btn__text">🔄 Sync Data Now</span>
                                    <span class="qwoo-btn__loader" style="display:none;">Syncing…</span>
                                </button>
                                <span class="qwoo-hint" id="qwoo-sync-data-hint">
                            <?php if ( $github_configured ) : ?>
                                Pushes fresh <code>products.json</code>, <code>categories.json</code>, and <code>price-meta.json</code> to your frontend repo right now — useful after bulk category or price changes, which don't sync automatically.
                            <?php else : ?>
                                Set your Repository Owner, Repository Name, and GitHub Token above first.
                            <?php endif; ?>
                        </span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>


            </div><!-- /.qwoo-tech-grid -->

            <!-- ── Save Button ── -->
            <div class="qwoo-tech-footer">
                <button type="button" id="qwoo-save-technical" class="qwoo-btn qwoo-btn--primary">
                    <span class="qwoo-btn__text">Save Settings</span>
                    <span class="qwoo-btn__loader" style="display:none;">Saving…</span>
                </button>
            </div>
        </div>

        <?php
    }
}
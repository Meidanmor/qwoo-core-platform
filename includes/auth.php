<?php
/**
 * Qwoo Auth
 * Handles login, logout, user endpoints, and cookie-based REST authentication.
 */

function qwoo_get_authenticated_user(): WP_User|false {
    $user_id = wp_validate_auth_cookie($_COOKIE[LOGGED_IN_COOKIE] ?? '', 'logged_in');
    if (!$user_id) return false;

    // Also verify the session token is still alive server-side
    $manager = WP_Session_Tokens::get_instance($user_id);
    $token   = wp_get_session_token();  // extracts token from the current cookie

    if (!$manager->verify($token)) return false;
    wp_set_current_user($user_id);

    return get_userdata($user_id);
}

function qwoo_require_login()
{
    if (!is_user_logged_in()) {
        return new WP_Error(
            'rest_forbidden',
            __('Authentication required.'),
            ['status' => 401]
        );
    }

    return true;
}

// ─── Register Routes ──────────────────────────────────────────────────────────
add_action('rest_api_init', function () {
    register_rest_route('qwoo/v1', '/nonce', [
        'methods' => 'GET',
        'callback' => function () {
            qwoo_get_authenticated_user();
            $nonce = wp_create_nonce('wp_rest');
            return new WP_REST_Response([
                'nonce' => $nonce
            ], 200);
        },
        'permission_callback' => '__return_true',
    ]);
});
add_action('rest_api_init', function () {

    // Login
    register_rest_route('qwoo/v1', '/login', [
        'methods'             => 'POST',
        'callback'            => 'qwoo_handle_login',
        'permission_callback' => function ( $request ) {
            return qwoo_rate_limit_check( 'login', 20, 5 * MINUTE_IN_SECONDS, $request->get_param( 'username' ) );
        },
        'args'                => [
            'username' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'password' => [
                'required' => true,
                'type'     => 'string',
                // Do NOT sanitize passwords — strips valid special characters
            ],
        ],
    ]);

    // Logout
    register_rest_route('qwoo/v1', '/logout', [
        'methods'             => 'POST',
        'callback'            => 'qwoo_handle_logout',
        'permission_callback' => 'qwoo_require_login'
    ]);

    // Me — GET and POST
    register_rest_route('qwoo/v1', '/me', [
        [
            'methods'             => 'GET',
            'callback'            => 'qwoo_get_me',
            'permission_callback' => 'qwoo_require_login'
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'qwoo_update_me',
            'permission_callback' => 'qwoo_require_login',
            'args'                => [
                'first_name' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'last_name' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ],
    ]);

    // Google Login
    register_rest_route('qwoo/v1', '/google-login', [
        'methods'             => 'POST',
        'callback'            => 'qwoo_handle_google_login',
        'permission_callback' => function () {
            return qwoo_rate_limit_check('google_login', 10, 5 * MINUTE_IN_SECONDS);
        },
    ]);

    // Google Login Redirect (OAuth code exchange)
    register_rest_route('qwoo/v1', '/google-login-redirect', [
        'methods'             => 'POST',
        'callback'            => 'qwoo_handle_google_login_redirect',
        'permission_callback' => function () {
            return qwoo_rate_limit_check('google_login', 10, 5 * MINUTE_IN_SECONDS);
        },
    ]);

    // Forgot Password — sends a reset-password email if the account exists
    register_rest_route('qwoo/v1', '/forgot-password', [
        'methods'             => 'POST',
        'callback'            => 'qwoo_handle_forgot_password',
        'permission_callback' => function ( $request ) {
            // Deliberately stricter than /login — this endpoint sends email
            // to whatever address is supplied, so it's also a spam vector,
            // not just a brute-force target.
            return qwoo_rate_limit_check( 'forgot_password', 5, 15 * MINUTE_IN_SECONDS, $request->get_param( 'username' ) );
        },
        'args'                => [
            'username' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);

    // Reset Password — consumes the key from the emailed link
    register_rest_route('qwoo/v1', '/reset-password', [
        'methods'             => 'POST',
        'callback'            => 'qwoo_handle_reset_password',
        'permission_callback' => function ( $request ) {
            return qwoo_rate_limit_check( 'reset_password', 10, 15 * MINUTE_IN_SECONDS, $request->get_param( 'login' ) );
        },
        'args'                => [
            'login' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'key' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'password' => [
                'required' => true,
                'type'     => 'string',
                // Do NOT sanitize passwords — strips valid special characters
            ],
        ],
    ]);

});

// ─── Login ────────────────────────────────────────────────────────────────────

function qwoo_handle_login(WP_REST_Request $request): WP_REST_Response {
    $username = $request->get_param('username');
    $password = $request->get_param('password');
    $remember = $request->get_param('remember');

    $user = wp_signon(
        [
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => $remember ? true : false,
        ],
        true // always secure — both localhost (via proxy) and production are HTTPS
    );

    if (is_wp_error($user)) {
        $code = $user->get_error_code();

        $messages = [
            'invalid_username'   => 'No account found with that username or email.',
            'invalid_email'      => 'No account found with that email address.',
            'incorrect_password' => 'Incorrect password. Please try again.',
            'empty_username'     => 'Please enter your username or email.',
            'empty_password'     => 'Please enter your password.',
            'invalidcombo'       => 'Incorrect username or password.',
        ];

        return new WP_REST_Response(
            [
                'success' => false,
                'code'    => $code,
                'message' => $messages[$code] ?? 'Login failed. Please check your credentials and try again.',
            ],
            401
        );
    }
    return new WP_REST_Response(
        [
            'success' => true,
            'user'    => qwoo_get_user_data($user->ID),
        ],
        200
    );
}

// ─── Logout ───────────────────────────────────────────────────────────────────

function qwoo_handle_logout(): WP_REST_Response {
    $user_id = wp_validate_auth_cookie($_COOKIE[LOGGED_IN_COOKIE] ?? '', 'logged_in');

    if (!$user_id) {
        return new WP_REST_Response(
            ['success' => false, 'message' => 'No active session.'],
            400
        );
    }

    wp_logout();

    return new WP_REST_Response(['success' => true], 200);
}

// ─── Me — GET ─────────────────────────────────────────────────────────────────

function qwoo_get_me(): WP_REST_Response
{
    $user_id = get_current_user_id();

    return new WP_REST_Response(
        ['success' => true, 'user' => qwoo_get_user_data($user_id)],
        200
    );
}

// ─── Me — POST ────────────────────────────────────────────────────────────────

function qwoo_update_me(WP_REST_Request $request): WP_REST_Response
{
    $user_id = get_current_user_id();

    $updates = ['ID' => $user_id];

    $first_name = $request->get_param('first_name');
    $last_name = $request->get_param('last_name');

    if ($first_name !== null) $updates['first_name'] = $first_name;
    if ($last_name !== null) $updates['last_name'] = $last_name;

    if (count($updates) === 1) {
        return new WP_REST_Response(
            ['success' => false, 'message' => 'No fields provided to update.'],
            400
        );
    }

    $result = wp_update_user($updates);

    if (is_wp_error($result)) {
        return new WP_REST_Response(
            ['success' => false, 'message' => 'Failed to update profile. Please try again.'],
            500
        );
    }

    return new WP_REST_Response(
        [
            'success' => true,
            'user' => qwoo_get_user_data($user_id),
        ],
        200
    );
}

// ─── Google Login ─────────────────────────────────────────────────────────────

function qwoo_handle_google_login(WP_REST_Request $request): WP_REST_Response {
    $token = sanitize_text_field($request->get_param('token') ?? '');

    if (!$token) {
        return new WP_REST_Response(['success' => false, 'message' => 'Missing token'], 400);
    }

    // Verify token with Google
    $response = wp_remote_get("https://oauth2.googleapis.com/tokeninfo?id_token={$token}");
    if (is_wp_error($response)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Google token validation failed'], 401);
    }

    $data = json_decode(wp_remote_retrieve_body($response));
    if (!isset($data->email) || $data->email_verified !== "true") {
        return new WP_REST_Response(['success' => false, 'message' => 'Invalid Google token'], 401);
    }

    if (!hash_equals(Qwoo_Technical_Settings::get_key( 'GOOGLE_CLIENT_ID' ), $data->aud ?? '')) {
        return new WP_REST_Response(['success' => false, 'message' => 'Token not issued for this app'], 401);
    }

    // Get or create user
    $user = get_user_by('email', $data->email);

    if (!$user) {
        $email      = sanitize_email($data->email);
        $base_name  = sanitize_user(current(explode('@', $email)), true);
        $username   = username_exists($base_name)
            ? $base_name . wp_generate_password(4, false)
            : $base_name;
        $first_name = ucfirst($base_name);

        $user_id = wp_create_user($username, wp_generate_password(), $email);

        if (is_wp_error($user_id)) {
            return new WP_REST_Response(['success' => false, 'message' => 'User creation failed'], 500);
        }

        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $first_name,
            'display_name' => $first_name,
        ]);

        wp_new_user_notification($user_id, null, 'user');

        $user = get_userdata($user_id);
    }

    // Log the user in via WP session cookie — same as regular login
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);

    return new WP_REST_Response(
        [
            'success' => true,
            'user'    => qwoo_get_user_data($user->ID),
        ],
        200
    );
}

// ─── Google Login Redirect (OAuth code exchange) ──────────────────────────────

function qwoo_handle_google_login_redirect(WP_REST_Request $request): WP_REST_Response {
    $code = sanitize_text_field($request->get_param('code') ?? '');

    if (!$code) {
        return new WP_REST_Response(['success' => false, 'message' => 'Missing code'], 400);
    }

    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'body' => [
            'code'          => $code,
            'client_id'     => Qwoo_Technical_Settings::get_key( 'GOOGLE_CLIENT_ID' ),
            'client_secret' => Qwoo_Technical_Settings::get_key( 'GOOGLE_CLIENT_SECRET' ),
            'redirect_uri'  => Qwoo_Technical_Settings::get_key( 'GOOGLE_REDIRECT_URI' ),
            'grant_type'    => 'authorization_code',
        ],
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
    ]);

    if (is_wp_error($response)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Failed to exchange code'], 500);
    }

    $tokens = json_decode(wp_remote_retrieve_body($response));

    if (!isset($tokens->id_token)) {
        return new WP_REST_Response(['success' => false, 'message' => 'No ID token returned'], 401);
    }

    $request->set_param('token', $tokens->id_token);

    return qwoo_handle_google_login($request);
}

// ─── Forgot Password ──────────────────────────────────────────────────────────

// Points WordPress's password-reset email at the frontend instead of
// wp-login.php?action=rp — the reset flow is handled entirely by the
// headless frontend (a page reading ?key=&login= from the URL, posting to
// /qwoo/v1/reset-password). Falls back to WP's default message/link if no
// frontend domain is configured yet.
add_filter( 'retrieve_password_message', function ( $message, $key, $user_login, $user_data ) {
    $frontend_domain = class_exists( 'Qwoo_Technical_Settings' )
        ? Qwoo_Technical_Settings::get_primary_frontend_domain()
        : '';

    if ( ! $frontend_domain ) {
        return $message;
    }

    $reset_url = trailingslashit( $frontend_domain ) . 'reset-password?key='
        . rawurlencode( $key ) . '&login=' . rawurlencode( $user_login );

    return __( 'Someone has requested a password reset for the following account:' ) . "\r\n\r\n"
        . sprintf( __( 'Username: %s' ), $user_login ) . "\r\n\r\n"
        . __( 'If this was a mistake, ignore this email and nothing will happen.' ) . "\r\n\r\n"
        . __( 'To reset your password, visit the following address:' ) . "\r\n\r\n"
        . $reset_url . "\r\n\r\n"
        . __( 'This link will expire in 24 hours.' );
}, 10, 4 );

function qwoo_handle_forgot_password( WP_REST_Request $request ): WP_REST_Response {
    $username = $request->get_param( 'username' );

    // Always return the same response whether or not an account matches —
    // this endpoint must not be usable to check which emails/usernames are
    // registered. The actual outcome (sent / not sent) never reaches the
    // client either way.
    $generic_response = new WP_REST_Response(
        [
            'success' => true,
            'message' => "If an account matches that username or email, we've sent a password reset link to it.",
        ],
        200
    );

    $user = is_email( $username ) ? get_user_by( 'email', $username ) : get_user_by( 'login', $username );

    if ( ! $user ) {
        return $generic_response;
    }

    retrieve_password( $user->user_login );

    return $generic_response;
}

// ─── Reset Password ───────────────────────────────────────────────────────────

function qwoo_handle_reset_password( WP_REST_Request $request ): WP_REST_Response {
    $login    = $request->get_param( 'login' );
    $key      = $request->get_param( 'key' );
    $password = $request->get_param( 'password' );

    if ( strlen( $password ) < 8 ) {
        return new WP_REST_Response(
            [ 'success' => false, 'message' => 'Password must be at least 8 characters.' ],
            400
        );
    }

    $user = check_password_reset_key( $key, $login );

    if ( is_wp_error( $user ) ) {
        return new WP_REST_Response(
            [ 'success' => false, 'message' => 'This reset link is invalid or has expired. Please request a new one.' ],
            400
        );
    }

    reset_password( $user, $password );

    return new WP_REST_Response( [ 'success' => true ], 200 );
}

// ─── Helper: Clean user payload ───────────────────────────────────────────────

function qwoo_get_user_data(int $user_id): array {
    $user = get_userdata($user_id);
    if (!$user) {
        return [];
    }

    return [
        'id'         => $user->ID,
        'email'      => $user->user_email,
        'first_name' => $user->first_name,
        'last_name'  => $user->last_name,
        'is_admin'   => user_can($user, 'manage_woocommerce'),
    ];
}
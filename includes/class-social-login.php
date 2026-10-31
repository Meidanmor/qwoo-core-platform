<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings → Sign-in (dashboard): customers sign in with Google, through the
 * owner's own Google app (Client ID + secret from Google Cloud).
 *
 * - Option `qwoo_social_login` { google: { enabled, check: { ok, message, time } } };
 *   the ID and secret are Qwoo_Technical_Settings keys (encrypted), the same
 *   ones wp-admin's Technical settings show. Stores that had keys before this
 *   option existed count as on.
 * - save() asks Google whether it knows the ID and secret (a token request
 *   with a made-up code: a wrong pair answers invalid_client, a right one
 *   invalid_grant). A pair Google refuses can't be turned on.
 * - The Client ID reaches the storefront in branding.json `account.google`
 *   (Qwoo_Account_Signup::published()); a save patches the live version, so
 *   no publish is needed.
 * - The return address is the storefront's own `/auth/callback` on whatever
 *   address the shopper used (redirect_uri()): Google only hands out a code
 *   for addresses the owner listed in Google Cloud, and only swaps it back
 *   for that same address.
 */
class Qwoo_Social_Login {

    const OPTION = 'qwoo_social_login';
    const ID_RE  = '/^\d{6,30}-[a-z0-9]{8,64}\.apps\.googleusercontent\.com$/';
    const KEY_RE = '/^[A-Za-z0-9_-]{10,100}$/';

    private static function settings() {
        $s = get_option( self::OPTION, [] );
        return is_array( $s ) ? $s : [];
    }

    public static function client_id() {
        return trim( (string) Qwoo_Technical_Settings::get_key( 'GOOGLE_CLIENT_ID' ) );
    }

    public static function client_secret() {
        return trim( (string) Qwoo_Technical_Settings::get_key( 'GOOGLE_CLIENT_SECRET' ) );
    }

    /** Whether shoppers see "Sign in with Google": switched on, with an ID and a secret. */
    public static function google_on() {
        $g  = self::settings()['google'] ?? null;
        $on = is_array( $g ) && array_key_exists( 'enabled', $g ) ? ! empty( $g['enabled'] ) : true;
        return $on && self::client_id() !== '' && self::client_secret() !== '';
    }

    /**
     * Where Google sends the shopper back: `{origin}/auth/callback` from the
     * storefront (any https address, or http on this computer), else the old
     * wp-admin setting, else the main storefront address.
     */
    public static function redirect_uri( $asked = '' ) {
        $p = wp_parse_url( (string) $asked );
        if ( is_array( $p ) && ( $p['path'] ?? '' ) === '/auth/callback' && ! empty( $p['host'] )
            && ! isset( $p['query'] ) && ! isset( $p['fragment'] ) && ! isset( $p['user'] )
            && preg_match( '/^[a-z0-9.-]+$/i', $p['host'] )
            && ( ( $p['scheme'] ?? '' ) === 'https' || ( ( $p['scheme'] ?? '' ) === 'http' && in_array( $p['host'], [ 'localhost', '127.0.0.1' ], true ) ) ) ) {
            return $p['scheme'] . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' ) . '/auth/callback';
        }
        $old = (string) Qwoo_Technical_Settings::get_key( 'GOOGLE_REDIRECT_URI' );
        if ( $old !== '' ) {
            return $old;
        }
        $front = Qwoo_Technical_Settings::get_primary_frontend_domain();
        return $front !== '' ? $front . '/auth/callback' : '';
    }

    /** What Settings → Sign-in shows (never the secret itself). */
    public static function dashboard_state() {
        $g      = self::settings()['google'] ?? [];
        $secret = self::client_secret();
        return [
            'google' => [
                'on'          => self::google_on(),
                'client_id'   => self::client_id(),
                'secret_set'  => $secret !== '',
                'secret_end'  => $secret !== '' ? substr( $secret, -4 ) : '',
                // Set in wp-config.php: the dashboard can't change them.
                'fixed'       => defined( 'GOOGLE_CLIENT_ID' ) || defined( 'GOOGLE_CLIENT_SECRET' ),
                'check'       => is_array( $g['check'] ?? null ) ? $g['check'] : null,
            ],
            'signup'     => Qwoo_Account_Signup::allowed(),
            'storefront' => Qwoo_Technical_Settings::get_primary_frontend_domain(),
            'local'      => class_exists( 'Qwoo_Site_Content' ) && Qwoo_Site_Content::enabled(),
        ];
    }

    /**
     * { google: { enabled?, client_id?, client_secret? ('' = keep), remove? } }.
     * Returns dashboard_state(), or { error }.
     */
    public static function save( array $params ) {
        $in = is_array( $params['google'] ?? null ) ? $params['google'] : [];
        $s  = self::settings();
        $g  = is_array( $s['google'] ?? null ) ? $s['google'] : [];

        if ( ! empty( $in['remove'] ) ) {
            Qwoo_Technical_Settings::set_key( 'GOOGLE_CLIENT_ID', '' );
            Qwoo_Technical_Settings::set_key( 'GOOGLE_CLIENT_SECRET', '' );
            $s['google'] = [ 'enabled' => false ];
            update_option( self::OPTION, $s, false );
            self::refresh_published();
            return self::dashboard_state();
        }

        $id     = array_key_exists( 'client_id', $in ) ? trim( (string) $in['client_id'] ) : null;
        $secret = trim( (string) ( $in['client_secret'] ?? '' ) );
        if ( $id !== null && $id !== '' && ! preg_match( self::ID_RE, $id ) ) {
            return [ 'error' => 'That doesn\'t look like a Google Client ID. It ends with .apps.googleusercontent.com.' ];
        }
        if ( $secret !== '' && ! preg_match( self::KEY_RE, $secret ) ) {
            return [ 'error' => 'That doesn\'t look like a Google client secret. It usually starts with GOCSPX-.' ];
        }

        $new_id     = $id !== null ? $id : self::client_id();
        $new_secret = $secret !== '' ? $secret : self::client_secret();
        $enabled    = array_key_exists( 'enabled', $in ) ? ! empty( $in['enabled'] ) : self::google_on();
        $changed    = ( $id !== null && $id !== self::client_id() ) || ( $secret !== '' && $secret !== self::client_secret() );

        if ( $enabled && ( $new_id === '' || $new_secret === '' ) ) {
            return [ 'error' => 'Add your Client ID and client secret first.' ];
        }

        $check = $g['check'] ?? null;
        if ( $new_id !== '' && $new_secret !== '' && ( $changed || ( $enabled && ! self::google_on() ) ) ) {
            $check = self::check( $new_id, $new_secret );
            if ( $check['ok'] === false && $enabled ) {
                return [ 'error' => $check['message'] ];
            }
        }

        if ( $id !== null && $id !== self::client_id() && ! Qwoo_Technical_Settings::set_key( 'GOOGLE_CLIENT_ID', $id ) ) {
            return [ 'error' => 'Your store can\'t save keys safely (its AUTH_KEY isn\'t set). Contact support.' ];
        }
        if ( $secret !== '' && $secret !== self::client_secret() && ! Qwoo_Technical_Settings::set_key( 'GOOGLE_CLIENT_SECRET', $secret ) ) {
            return [ 'error' => 'Your store can\'t save keys safely (its AUTH_KEY isn\'t set). Contact support.' ];
        }

        $s['google'] = [ 'enabled' => $enabled, 'check' => $check ];
        update_option( self::OPTION, $s, false );
        self::refresh_published();
        return self::dashboard_state();
    }

    /**
     * Asks Google whether the pair is real: { ok: true | false | null, message, time }
     * (null: Google couldn't be reached, so nothing is known).
     */
    public static function check( $id, $secret ) {
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'timeout' => 10,
            'body'    => [
                'code'          => 'qwoo-check-' . wp_generate_password( 12, false ),
                'client_id'     => $id,
                'client_secret' => $secret,
                'redirect_uri'  => self::redirect_uri() ?: 'https://localhost/auth/callback',
                'grant_type'    => 'authorization_code',
            ],
        ] );
        $now = time();
        if ( is_wp_error( $response ) ) {
            return [ 'ok' => null, 'message' => 'Google couldn\'t be reached to check the keys. Try signing in once to be sure.', 'time' => $now ];
        }
        $error = (string) ( json_decode( (string) wp_remote_retrieve_body( $response ), true )['error'] ?? '' );
        if ( $error === 'invalid_client' || $error === 'unauthorized_client' ) {
            return [ 'ok' => false, 'message' => 'Google didn\'t accept this Client ID and secret. Copy both again from the same OAuth client in Google Cloud.', 'time' => $now ];
        }
        if ( $error === 'invalid_grant' || $error === 'redirect_uri_mismatch' ) {
            return [ 'ok' => true, 'message' => 'Google knows this Client ID and secret.', 'time' => $now ];
        }
        return [ 'ok' => null, 'message' => 'Google gave an answer we didn\'t expect. Try signing in once to be sure.', 'time' => $now ];
    }

    /**
     * The live version's branding.json gets the new `account` (Client ID on or off), so the
     * storefront's buttons follow at once. Stores that still publish to GitHub get it on their
     * next publish.
     */
    public static function refresh_published() {
        if ( ! class_exists( 'Qwoo_Site_Content' ) || ! Qwoo_Site_Content::enabled() ) {
            return;
        }
        $current  = get_option( Qwoo_Site_Content::CURRENT );
        $manifest = is_array( $current ) ? Qwoo_Site_Content::manifest( (string) ( $current['version'] ?? '' ) ) : null;
        if ( ! $manifest ) {
            return;
        }
        $batch = null;
        foreach ( (array) ( $manifest['json'] ?? [] ) as $path => $config ) {
            if ( ! preg_match( '#^config/(?:[a-z]{2}/)?branding\.json$#', (string) $path ) || ! is_array( $config ) ) {
                continue;
            }
            $config['account'] = Qwoo_Account_Signup::published();
            $batch             = $batch ?? Qwoo_Site_Content::start_batch();
            if ( ! $batch ) {
                return;
            }
            Qwoo_Site_Content::batch_put( $batch, 'public/' . $path, wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        }
        if ( $batch ) {
            Qwoo_Site_Content::finish_batch( $batch, 'Sign-in settings' );
        }
    }
}

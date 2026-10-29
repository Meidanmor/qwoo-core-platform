<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tracking and custom code (dashboard → Settings → Tracking & code).
 *
 *   option qwoo_custom_code = {
 *     consent:   { mode: ask | notice, texts: { lang: { title, text, accept, reject, choose, save } } },
 *     shortcuts: { gtm | ga4 | meta: { id, state, group } },
 *     snippets:  [ { id sn_…, name, code, place, where, targets, group, state, by, updated } ],
 *     domains:   [ { host, kinds, from: auto | learned | owner, added } ],
 *     blocked:   [ { host, kind, count, last, via } ],
 *     allow_eval, ai_live, test_key, stamp
 *   }
 *
 * Every snippet and shortcut is Off, Testing or Live. Testing ones run only
 * in a browser that opened the private test link (/?qwoo_code_test={key}:
 * the storefront keeps the key in the qwoo_code_test cookie). Snippets run
 * only for the consent group the shopper allowed (the storefront's consent
 * banner; "notice" mode allows everything until the shopper turns it off).
 *
 * The storefront's page security rules (CSP) allow outside addresses only
 * from the domains list: those written in the snippets (on save), those a
 * test browser reached (it runs with a relaxed policy plus a report-only copy
 * of the strict one, and reports what the strict one would block), and the
 * owner's own. A live page reports what it blocked: those wait in `blocked`
 * for the owner to allow or dismiss.
 *
 * The storefront learns a new stamp from qwoo/v1/site (public through its
 * CDN: only a hash) and then reads qwoo/v1/site/code from its server, signed
 * with the proxy secret (the browser can reach qwoo routes through the
 * proxy, never with that signature).
 *
 * Saves from AI apps (the platform says `by: ai`): a new or changed snippet
 * or shortcut starts in Testing and nothing goes live, unless the owner
 * turned on "ai_live". AI apps can't change ai_live.
 */
class Qwoo_Custom_Code {

    const OPTION       = 'qwoo_custom_code';
    const COOKIE       = 'qwoo_code_test';
    const MAX_SNIPPETS = 30;
    const MAX_CODE     = 30000;
    const MAX_DOMAINS  = 200;
    const MAX_BLOCKED  = 50;

    const PLACES = [ 'head', 'body_start', 'body_end' ];
    const GROUPS = [ 'necessary', 'analytics', 'marketing' ];
    const STATES = [ 'off', 'testing', 'live' ];
    const WHERE  = [ 'all', 'only', 'except' ];
    const KINDS  = [ 'script', 'connect', 'frame', 'style', 'font', 'media' ];

    /** The built-in tracker shortcuts: the ID's shape, the default consent group, the addresses they use. */
    const SHORTCUTS = [
        'gtm'  => [
            'name'    => 'Google Tag Manager',
            'pattern' => '/^GTM-[A-Z0-9]{4,12}$/',
            'group'   => 'analytics',
            'domains' => [
                'script'  => [ 'www.googletagmanager.com' ],
                'connect' => [ 'www.googletagmanager.com', '*.google-analytics.com', '*.analytics.google.com' ],
                'frame'   => [ 'www.googletagmanager.com' ],
            ],
        ],
        'ga4'  => [
            'name'    => 'Google Analytics 4',
            'pattern' => '/^G-[A-Z0-9]{4,15}$/',
            'group'   => 'analytics',
            'domains' => [
                'script'  => [ 'www.googletagmanager.com' ],
                'connect' => [ '*.google-analytics.com', '*.analytics.google.com', 'www.google.com', '*.g.doubleclick.net' ],
            ],
        ],
        'meta' => [
            'name'    => 'Meta Pixel',
            'pattern' => '/^\d{8,20}$/',
            'group'   => 'marketing',
            'domains' => [
                'script'  => [ 'connect.facebook.net' ],
                'connect' => [ 'connect.facebook.net', 'www.facebook.com' ],
                'frame'   => [ 'www.facebook.com' ],
            ],
        ],
    ];

    /** Banner texts the owner can change (empty = the storefront's own, in the page's language). */
    const TEXT_KEYS = [ 'title' => 80, 'text' => 600, 'accept' => 40, 'reject' => 40, 'choose' => 40, 'save' => 40 ];

    /**
     * Title and message start with real texts (not placeholders): until the
     * owner saves them, each language gets these; an owner who empties one
     * keeps it empty (no title / no message).
     */
    const DEFAULT_TEXTS = [
        'en' => [ 'title' => 'We use cookies', 'text' => 'We use cookies to run the store and to improve your experience.' ],
        'he' => [ 'title' => 'אנחנו משתמשים בעוגיות', 'text' => 'אנחנו משתמשים בעוגיות כדי להפעיל את החנות ולשפר את החוויה שלך.' ],
    ];

    /** Addresses that are never worth listing (XML namespaces inside SVGs and the like). */
    const IGNORED_HOSTS = [ 'www.w3.org', 'w3.org', 'schema.org', 'www.schema.org' ];

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
        add_filter( 'rest_post_dispatch', [ __CLASS__, 'order_item_ids' ], 10, 3 );
    }

    /**
     * The Store API's order answer (the thank-you page) gives each item its
     * order line id only: the purchase event needs the product's.
     */
    public static function order_item_ids( $response, $server, $request ) {
        if ( ! $response instanceof WP_REST_Response || $response->is_error() || ! preg_match( '#^/wc/store/v1/order/(\d+)$#', (string) $request->get_route(), $m ) ) {
            return $response;
        }
        $data  = $response->get_data();
        $order = wc_get_order( (int) $m[1] );
        if ( ! $order || ! is_array( $data['items'] ?? null ) ) return $response;
        foreach ( $data['items'] as $i => $item ) {
            $line = $order->get_item( (int) ( $item['id'] ?? 0 ) );
            if ( $line instanceof WC_Order_Item_Product ) {
                $data['items'][ $i ]['product_id']   = (int) $line->get_product_id();
                $data['items'][ $i ]['variation_id'] = (int) $line->get_variation_id();
            }
        }
        $response->set_data( $data );
        return $response;
    }

    public static function routes() {
        // Both are in the proxy-secret 'qwoo' namespace.
        register_rest_route( 'qwoo/v1', '/site/code', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_code' ],
            'permission_callback' => '__return_true', // signed: checked in the callback
        ] );
        register_rest_route( 'qwoo/v1', '/code-report', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_report' ],
            'permission_callback' => '__return_true', // rate limited in the callback
        ] );
    }

    /* ---------------- storage ---------------- */

    private static function get(): array {
        $v = get_option( self::OPTION, [] );
        return is_array( $v ) ? $v : [];
    }

    private static function put( array $v ): void {
        $v['stamp'] = max( time(), (int) ( $v['stamp'] ?? 0 ) + 1 );
        update_option( self::OPTION, $v, false );
    }

    /** The test link's key, made the first time it's needed. */
    private static function key( array &$v ): string {
        if ( empty( $v['test_key'] ) || ! preg_match( '/^[a-f0-9]{32}$/', (string) $v['test_key'] ) ) {
            $v['test_key'] = bin2hex( random_bytes( 16 ) );
        }
        return (string) $v['test_key'];
    }

    /** This request comes from a browser that opened the test link. */
    private static function testing_browser( array $v ): bool {
        $cookie = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
        return $cookie !== '' && ! empty( $v['test_key'] ) && hash_equals( (string) $v['test_key'], $cookie );
    }

    /* ---------------- the storefront ---------------- */

    /** For qwoo/v1/site (public through the storefront's CDN): a hash of the stamp, never the settings. */
    public static function site_fields(): array {
        $v = self::get();
        return empty( $v['stamp'] ) ? [] : [ 'code' => substr( md5( 'qwoo-code-' . (int) $v['stamp'] ), 0, 16 ) ];
    }

    /** The signature only the storefront's server can make (it has the proxy secret). */
    public static function signature(): string {
        return hash_hmac( 'sha256', 'qwoo-site-code', Qwoo_Technical_Settings::get_proxy_secret() );
    }

    public static function rest_code( WP_REST_Request $request ) {
        $sig = (string) $request->get_header( 'x-qwoo-code-sig' );
        if ( $sig === '' || ! hash_equals( self::signature(), $sig ) ) {
            return new WP_Error( 'qwoo_forbidden', 'Not allowed.', [ 'status' => 403 ] );
        }
        $response = new WP_REST_Response( self::storefront_payload() );
        $response->header( 'Cache-Control', 'no-store, max-age=0' );
        return $response;
    }

    /**
     * What the storefront needs: the banner, every snippet that isn't off
     * (shortcuts turned into snippets), the allowed addresses per kind, and a
     * hash of the test key.
     */
    public static function storefront_payload(): array {
        $v     = self::get();
        $paths = self::page_paths();
        $list  = [];
        $csp   = array_fill_keys( self::KINDS, [] );
        $active = [];

        foreach ( self::shortcuts_of( $v ) as $key => $s ) {
            if ( $s['state'] === 'off' || $s['id'] === '' ) continue;
            $active[ $key ] = [ 'id' => $s['id'], 'state' => $s['state'] ];
            $list[] = [
                'id'    => $key,
                'kind'  => $key,
                'name'  => self::SHORTCUTS[ $key ]['name'],
                'code'  => self::shortcut_code( $key, $s['id'] ),
                'place' => 'head',
                'where' => 'all',
                'targets' => [],
                'page_paths' => [],
                'group' => $s['group'],
                'state' => $s['state'],
            ];
            foreach ( self::SHORTCUTS[ $key ]['domains'] as $kind => $hosts ) {
                foreach ( $hosts as $host ) $csp[ $kind ][ $host ] = true;
            }
        }
        foreach ( self::snippets_of( $v ) as $s ) {
            if ( $s['state'] === 'off' || trim( $s['code'] ) === '' ) continue;
            $page_paths = [];
            foreach ( $s['targets'] as $target ) {
                if ( isset( $paths[ $target ] ) ) $page_paths[] = $paths[ $target ];
            }
            $list[] = [
                'id'         => $s['id'],
                'kind'       => 'snippet',
                'name'       => $s['name'],
                'code'       => $s['code'],
                'place'      => $s['place'],
                'where'      => $s['where'],
                'targets'    => $s['targets'],
                'page_paths' => $page_paths,
                'group'      => $s['group'],
                'state'      => $s['state'],
            ];
        }
        foreach ( self::domains_of( $v ) as $d ) {
            foreach ( $d['kinds'] as $kind ) $csp[ $kind ][ $d['host'] ] = true;
        }

        return [
            'stamp'     => (int) ( $v['stamp'] ?? 0 ),
            'consent'   => self::consent_of( $v ),
            'snippets'  => $list,
            'shortcuts' => $active,
            'csp'       => array_map( 'array_keys', $csp ),
            'eval'      => ! empty( $v['allow_eval'] ),
            'test'      => ! empty( $v['test_key'] ) ? hash( 'sha256', (string) $v['test_key'] ) : '',
        ];
    }

    /** Own page id → its address, for "only on" / "everywhere except" (the storefront matches own pages by address). */
    private static function page_paths(): array {
        $out = [];
        if ( class_exists( 'Shop_Settings_Builder' ) ) {
            foreach ( Shop_Settings_Builder::published_pages( '' ) as $page ) {
                if ( ! empty( $page['id'] ) ) $out[ (string) $page['id'] ] = (string) ( $page['path'] ?? '' );
            }
        }
        return $out;
    }

    /** The tracker's own code (its usual install snippet; events are sent by the storefront). */
    private static function shortcut_code( string $key, string $id ): string {
        $id = esc_js( $id );
        switch ( $key ) {
            case 'gtm':
                // The standard snippet; it passes the page's nonce on, so Tag Manager's own Custom HTML tags can run.
                return "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;var n=d.querySelector('script[nonce]');if(n)j.setAttribute('nonce',n.nonce||n.getAttribute('nonce'));f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$id}');</script>";
            case 'ga4':
                // Page views come from the storefront (it changes pages without reloading).
                return "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$id}\"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','{$id}',{send_page_view:false});</script>";
            case 'meta':
                return "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$id}');</script>";
        }
        return '';
    }

    /**
     * POST { reports: [ { host, kind, via } ] } from a storefront page: what
     * its security rules blocked (or, in a test browser, would block).
     */
    public static function rest_report( WP_REST_Request $request ) {
        $limited = qwoo_rate_limit_check( 'code_report', 30, 600 );
        if ( is_wp_error( $limited ) ) return $limited;

        $v    = self::get();
        $rows = array_slice( (array) ( $request->get_json_params()['reports'] ?? [] ), 0, 20 );
        if ( ! $rows || ( empty( $v['snippets'] ) && empty( $v['shortcuts'] ) ) ) {
            return [ 'ok' => true ];
        }
        $test    = self::testing_browser( $v );
        $own     = self::own_hosts();
        $changed = false;
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $kind = (string) ( $row['kind'] ?? '' );
            $host = self::clean_host( (string) ( $row['host'] ?? '' ), false );
            if ( $kind === 'eval' ) {
                $host = 'eval';
            } elseif ( ! in_array( $kind, self::KINDS, true ) || $host === '' || isset( $own[ $host ] ) || self::allowed( $v, $host, $kind ) ) {
                continue;
            }
            $via = self::clean_host( (string) ( $row['via'] ?? '' ), false );
            if ( $test ) {
                // The tester's browser: what the snippets need goes straight into the list.
                if ( $kind === 'eval' ) {
                    $changed = self::add_blocked( $v, 'eval', 'eval', $via ) || $changed;
                } else {
                    $changed = self::add_domain( $v, $host, [ $kind ], 'learned' ) || $changed;
                }
            } else {
                $changed = self::add_blocked( $v, $host, $kind, $via ) || $changed;
            }
        }
        if ( $changed ) {
            // A learned address changes the live rules (a new stamp); a blocked one only waits for the owner.
            if ( $test ) self::put( $v ); else update_option( self::OPTION, $v, false );
        }
        return [ 'ok' => true ];
    }

    /** This store's own addresses (never listed). */
    private static function own_hosts(): array {
        $out = [];
        foreach ( [ home_url(), site_url(), Qwoo_Technical_Settings::get_primary_frontend_domain() ] as $url ) {
            $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
            if ( $host !== '' ) $out[ $host ] = true;
        }
        return $out;
    }

    /** Whether the list already allows this address for this kind (wildcards too). */
    private static function allowed( array $v, string $host, string $kind ): bool {
        $lists = self::domains_of( $v );
        foreach ( self::shortcuts_of( $v ) as $key => $s ) {
            if ( $s['state'] === 'off' || $s['id'] === '' ) continue;
            foreach ( self::SHORTCUTS[ $key ]['domains'] as $k => $hosts ) {
                foreach ( $hosts as $h ) $lists[] = [ 'host' => $h, 'kinds' => [ $k ] ];
            }
        }
        foreach ( $lists as $d ) {
            if ( ! in_array( $kind, $d['kinds'], true ) ) continue;
            $h = $d['host'];
            if ( $h === $host || ( strpos( $h, '*.' ) === 0 && substr( $host, -strlen( $h ) + 1 ) === substr( $h, 1 ) ) ) {
                return true;
            }
        }
        return false;
    }

    private static function add_domain( array &$v, string $host, array $kinds, string $from ): bool {
        $list = self::domains_of( $v );
        foreach ( $list as $i => $d ) {
            if ( $d['host'] !== $host ) continue;
            $merged = array_values( array_unique( array_merge( $d['kinds'], $kinds ) ) );
            if ( count( $merged ) === count( $d['kinds'] ) ) return false;
            $list[ $i ]['kinds'] = $merged;
            $v['domains']        = $list;
            return true;
        }
        if ( count( $list ) >= self::MAX_DOMAINS ) return false;
        $list[]       = [ 'host' => $host, 'kinds' => array_values( array_unique( $kinds ) ), 'from' => $from, 'added' => time() ];
        $v['domains'] = $list;
        // Allowed now: no longer waiting.
        $v['blocked'] = array_values( array_filter( self::blocked_of( $v ), static fn( $b ) => $b['host'] !== $host ) );
        return true;
    }

    private static function add_blocked( array &$v, string $host, string $kind, string $via ): bool {
        $list = self::blocked_of( $v );
        foreach ( $list as $i => $b ) {
            if ( $b['host'] === $host && $b['kind'] === $kind ) {
                $list[ $i ]['count'] = min( 1000000, $b['count'] + 1 );
                $list[ $i ]['last']  = time();
                if ( $via !== '' ) $list[ $i ]['via'] = $via;
                $v['blocked'] = $list;
                return true;
            }
        }
        if ( count( $list ) >= self::MAX_BLOCKED ) return false;
        $list[]       = [ 'host' => $host, 'kind' => $kind, 'count' => 1, 'last' => time(), 'via' => $via ];
        $v['blocked'] = $list;
        return true;
    }

    /* ---------------- reading the settings (always clean) ---------------- */

    /**
     * { mode, banner, texts }. banner: "Show a cookie banner" (on unless turned
     * off; with analytics / marketing code in "ask" mode the storefront shows it
     * anyway). Title and message are kept even when empty (the owner emptied
     * them); a language that never had them gets DEFAULT_TEXTS.
     */
    private static function consent_of( array $v ): array {
        $c     = is_array( $v['consent'] ?? null ) ? $v['consent'] : [];
        $texts = [];
        foreach ( (array) ( $c['texts'] ?? [] ) as $lang => $row ) {
            if ( ! is_array( $row ) || ! preg_match( '/^[a-z]{2}$/', (string) $lang ) ) continue;
            foreach ( self::TEXT_KEYS as $key => $max ) {
                $text = trim( (string) ( $row[ $key ] ?? '' ) );
                if ( $text !== '' || ( isset( self::DEFAULT_TEXTS['en'][ $key ] ) && array_key_exists( $key, $row ) ) ) {
                    $texts[ $lang ][ $key ] = mb_substr( $text, 0, $max );
                }
            }
        }
        $langs = class_exists( 'Qwoo_Store_Language' ) ? array_unique( array_merge( [ Qwoo_Store_Language::get() ], Qwoo_Store_Language::extra() ) ) : [ 'en' ];
        foreach ( $langs as $lang ) {
            foreach ( self::DEFAULT_TEXTS[ $lang ] ?? self::DEFAULT_TEXTS['en'] as $key => $text ) {
                if ( ! array_key_exists( $key, $texts[ $lang ] ?? [] ) ) $texts[ $lang ][ $key ] = $text;
            }
        }
        return [
            'mode'   => ( $c['mode'] ?? 'ask' ) === 'notice' ? 'notice' : 'ask',
            'banner' => ! array_key_exists( 'banner', $c ) || ! empty( $c['banner'] ),
            'texts'  => (object) $texts,
        ];
    }

    private static function shortcuts_of( array $v ): array {
        $out = [];
        foreach ( self::SHORTCUTS as $key => $spec ) {
            $s           = is_array( $v['shortcuts'][ $key ] ?? null ) ? $v['shortcuts'][ $key ] : [];
            $id          = (string) ( $s['id'] ?? '' );
            $out[ $key ] = [
                'id'    => preg_match( $spec['pattern'], $id ) ? $id : '',
                'state' => in_array( $s['state'] ?? '', self::STATES, true ) ? $s['state'] : 'off',
                'group' => in_array( $s['group'] ?? '', self::GROUPS, true ) ? $s['group'] : $spec['group'],
            ];
        }
        return $out;
    }

    private static function snippets_of( array $v ): array {
        return array_values( array_filter( (array) ( $v['snippets'] ?? [] ), static fn( $s ) => is_array( $s ) && ! empty( $s['id'] ) ) );
    }

    private static function domains_of( array $v ): array {
        return array_values( array_filter( (array) ( $v['domains'] ?? [] ), static fn( $d ) => is_array( $d ) && ! empty( $d['host'] ) && ! empty( $d['kinds'] ) ) );
    }

    private static function blocked_of( array $v ): array {
        return array_values( array_filter( (array) ( $v['blocked'] ?? [] ), static fn( $b ) => is_array( $b ) && ! empty( $b['host'] ) ) );
    }

    /**
     * "https://js.flashyapp.com/x.js" → "js.flashyapp.com"; "*.example.com"
     * stays (when $wildcards). '' for anything that isn't a plain address.
     */
    private static function clean_host( string $raw, bool $wildcards = true ): string {
        $raw = strtolower( trim( $raw ) );
        $raw = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $raw );
        $raw = preg_replace( '#^//#', '', $raw );
        $raw = preg_replace( '#[/?\#].*$#', '', $raw );
        $raw = preg_replace( '#:\d+$#', '', $raw );
        $wild = '';
        if ( $wildcards && strpos( $raw, '*.' ) === 0 ) {
            $wild = '*.';
            $raw  = substr( $raw, 2 );
        }
        if ( strlen( $raw ) > 200 || ! preg_match( '/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,24}$/', $raw ) ) {
            return '';
        }
        return $wild . $raw;
    }

    /** The addresses written in a snippet, with what they're probably used for. */
    private static function hosts_in_code( string $code ): array {
        $out = [];
        $tag = [
            'script' => '#<script\b[^>]*\bsrc\s*=\s*["\']?((?:https?:)?//[^"\'\s>]+)#i',
            'frame'  => '#<iframe\b[^>]*\bsrc\s*=\s*["\']?((?:https?:)?//[^"\'\s>]+)#i',
            'style'  => '#<link\b[^>]*\bhref\s*=\s*["\']?((?:https?:)?//[^"\'\s>]+)#i',
        ];
        foreach ( $tag as $kind => $re ) {
            if ( preg_match_all( $re, $code, $m ) ) {
                foreach ( $m[1] as $url ) {
                    $host = self::clean_host( $url, false );
                    if ( $host !== '' ) $out[ $host ][ $kind ] = true;
                }
            }
        }
        // Any other address (a loader that adds its own script, an API): script and data calls.
        if ( preg_match_all( '#(?:https?:)?//([a-z0-9.-]+\.[a-z]{2,24})(?=[:/"\'\s?\\\\]|$)#i', $code, $m ) ) {
            foreach ( $m[1] as $host ) {
                $host = self::clean_host( $host, false );
                if ( $host === '' || isset( $out[ $host ] ) ) continue;
                $out[ $host ] = [ 'script' => true, 'connect' => true ];
            }
        }
        foreach ( self::IGNORED_HOSTS as $host ) unset( $out[ $host ] );
        return array_map( 'array_keys', $out );
    }

    /* ---------------- the dashboard ---------------- */

    /** Everything for the Tracking & code screen. */
    public static function dashboard_state(): array {
        $v   = self::get();
        $had = $v['test_key'] ?? '';
        $key = self::key( $v );
        if ( $had !== $key ) update_option( self::OPTION, $v, false );

        $pages = [];
        if ( class_exists( 'Shop_Settings_Builder' ) ) {
            foreach ( Shop_Settings_Builder::published_pages( '' ) as $page ) {
                if ( ! empty( $page['id'] ) ) $pages[] = [ 'id' => (string) $page['id'], 'title' => (string) ( $page['title'] ?? '' ), 'path' => (string) ( $page['path'] ?? '' ) ];
            }
        }
        $shortcuts = self::shortcuts_of( $v );
        foreach ( $shortcuts as $k => $s ) {
            $shortcuts[ $k ]['name'] = self::SHORTCUTS[ $k ]['name'];
        }
        return [
            'consent'    => self::consent_of( $v ),
            'shortcuts'  => $shortcuts,
            'snippets'   => array_map( static fn( $s ) => self::clean_snippet( $s, $s ), self::snippets_of( $v ) ),
            'domains'    => self::domains_of( $v ),
            'blocked'    => self::blocked_of( $v ),
            'allow_eval' => ! empty( $v['allow_eval'] ),
            'ai_live'    => ! empty( $v['ai_live'] ),
            'key'        => $key,
            'lists'      => [
                'pages'     => $pages,
                'targets'   => Shop_Builder_Constants::CHROME_TARGETS,
                'kinds'     => self::KINDS,
                'max'       => self::MAX_SNIPPETS,
                'max_code'  => self::MAX_CODE,
                'languages' => class_exists( 'Qwoo_Store_Language' ) ? array_values( array_unique( array_merge( [ Qwoo_Store_Language::get() ], Qwoo_Store_Language::extra() ) ) ) : [ 'en' ],
            ],
        ];
    }

    /** A snippet as stored: every field cleaned, unknown ones dropped. */
    private static function clean_snippet( array $in, ?array $before ): array {
        $code = (string) ( $in['code'] ?? '' );
        // The page's own structure stays the storefront's.
        $code = preg_replace( '#</?(html|head|body)\b[^>]*>#i', '', $code );
        $code = mb_substr( str_replace( "\0", '', $code ), 0, self::MAX_CODE );
        $targets = [];
        foreach ( (array) ( $in['targets'] ?? [] ) as $target ) {
            $target = is_scalar( $target ) ? (string) $target : '';
            if ( in_array( $target, Shop_Builder_Constants::CHROME_TARGETS, true ) || preg_match( '/^pg_[A-Za-z0-9]{6,20}$/', $target ) ) {
                $targets[ $target ] = true;
            }
        }
        return [
            'id'      => preg_match( '/^sn_[a-z0-9]{6,16}$/', (string) ( $in['id'] ?? '' ) ) ? $in['id'] : 'sn_' . strtolower( wp_generate_password( 10, false ) ),
            'name'    => mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 60 ) ?: 'Custom code',
            'code'    => $code,
            'place'   => in_array( $in['place'] ?? '', self::PLACES, true ) ? $in['place'] : 'head',
            'where'   => in_array( $in['where'] ?? '', self::WHERE, true ) ? $in['where'] : 'all',
            'targets' => array_slice( array_keys( $targets ), 0, 100 ),
            'group'   => in_array( $in['group'] ?? '', self::GROUPS, true ) ? $in['group'] : 'marketing',
            'state'   => in_array( $in['state'] ?? '', self::STATES, true ) ? $in['state'] : 'testing',
            'by'      => (string) ( $before['by'] ?? 'owner' ) === 'ai' ? 'ai' : 'owner',
            'updated' => (int) ( $before['updated'] ?? time() ),
        ];
    }

    /** An AI app's change may not make anything live (unless the owner allowed it). */
    private static function ai_state( string $wanted, string $before, bool $changed, bool $ai_live ): string {
        if ( $ai_live || $wanted !== 'live' ) return $wanted;
        // Already live and not changed: stays live.
        return ( $before === 'live' && ! $changed ) ? 'live' : 'testing';
    }

    /**
     * { consent?, shortcuts?, snippets?, domains?, allow_eval?, ai_live?,
     *   allow?: [host], dismiss?: [host], new_link?: bool, by: owner | ai }.
     * Only the parts sent change (the others are kept).
     */
    public static function save( array $p ): array {
        $v      = self::get();
        $ai     = ( $p['by'] ?? '' ) === 'ai';
        $ai_live = ! empty( $v['ai_live'] );

        if ( is_array( $p['consent'] ?? null ) ) {
            $v['consent'] = self::consent_of( [ 'consent' => $p['consent'] ] );
            $v['consent']['texts'] = (array) $v['consent']['texts'];
        }

        if ( is_array( $p['shortcuts'] ?? null ) ) {
            $old = self::shortcuts_of( $v );
            $new = self::shortcuts_of( [ 'shortcuts' => $p['shortcuts'] ] );
            foreach ( $new as $key => $s ) {
                // A wrong ID never wipes the saved one by accident: say so.
                $sent = trim( (string) ( $p['shortcuts'][ $key ]['id'] ?? '' ) );
                if ( $sent !== '' && $s['id'] === '' ) {
                    return [ 'error' => sprintf( 'That doesn\'t look like a %s ID.', self::SHORTCUTS[ $key ]['name'] ) ];
                }
                if ( $ai ) {
                    $new[ $key ]['state'] = self::ai_state( $s['state'], $old[ $key ]['state'], $s['id'] !== $old[ $key ]['id'], $ai_live );
                }
                // An ID taken out: the tracker is off.
                if ( $s['id'] === '' ) $new[ $key ]['state'] = 'off';
            }
            $v['shortcuts'] = $new;
        }

        if ( is_array( $p['snippets'] ?? null ) ) {
            $before = [];
            foreach ( self::snippets_of( $v ) as $s ) $before[ $s['id'] ] = $s;
            $list = [];
            foreach ( array_slice( array_values( $p['snippets'] ), 0, self::MAX_SNIPPETS ) as $in ) {
                if ( ! is_array( $in ) ) continue;
                $was   = $before[ (string) ( $in['id'] ?? '' ) ] ?? null;
                $s     = self::clean_snippet( $in, $was );
                $changed = ! $was || $was['code'] !== $s['code'] || $was['place'] !== $s['place'];
                if ( $ai ) {
                    $s['state'] = self::ai_state( $s['state'], (string) ( $was['state'] ?? 'off' ), $changed, $ai_live );
                    if ( $changed ) $s['by'] = 'ai';
                } elseif ( $changed ) {
                    $s['by'] = 'owner';
                }
                if ( $changed ) $s['updated'] = time();
                $list[] = $s;
                // The addresses the code names are allowed at once.
                if ( $changed ) {
                    foreach ( self::hosts_in_code( $s['code'] ) as $host => $kinds ) {
                        self::add_domain( $v, $host, $kinds, 'auto' );
                    }
                }
            }
            $v['snippets'] = $list;
        }

        if ( is_array( $p['domains'] ?? null ) ) {
            $old = [];
            foreach ( self::domains_of( $v ) as $d ) $old[ $d['host'] ] = $d;
            $list = [];
            foreach ( array_slice( array_values( $p['domains'] ), 0, self::MAX_DOMAINS ) as $in ) {
                if ( ! is_array( $in ) ) continue;
                $host  = self::clean_host( (string) ( $in['host'] ?? '' ) );
                $kinds = array_values( array_intersect( self::KINDS, (array) ( $in['kinds'] ?? [] ) ) );
                if ( $host === '' || ! $kinds || isset( $list[ $host ] ) ) continue;
                $list[ $host ] = [
                    'host'  => $host,
                    'kinds' => $kinds,
                    'from'  => $old[ $host ]['from'] ?? 'owner',
                    'added' => (int) ( $old[ $host ]['added'] ?? time() ),
                ];
            }
            $v['domains'] = array_values( $list );
        }

        foreach ( (array) ( $p['allow'] ?? [] ) as $host ) {
            $host = (string) $host;
            if ( $host === 'eval' ) {
                $v['allow_eval'] = true;
                $v['blocked']    = array_values( array_filter( self::blocked_of( $v ), static fn( $b ) => $b['host'] !== 'eval' ) );
                continue;
            }
            $kinds = [];
            foreach ( self::blocked_of( $v ) as $b ) {
                if ( $b['host'] === $host ) $kinds[] = $b['kind'];
            }
            if ( $kinds ) self::add_domain( $v, $host, array_values( array_intersect( self::KINDS, $kinds ) ), 'owner' );
        }
        $dismiss = array_map( 'strval', (array) ( $p['dismiss'] ?? [] ) );
        if ( $dismiss ) {
            $v['blocked'] = array_values( array_filter( self::blocked_of( $v ), static fn( $b ) => ! in_array( $b['host'], $dismiss, true ) ) );
        }

        if ( array_key_exists( 'allow_eval', $p ) ) $v['allow_eval'] = ! empty( $p['allow_eval'] );
        // Only the owner decides whether AI apps' code goes live by itself.
        if ( array_key_exists( 'ai_live', $p ) && ! $ai ) $v['ai_live'] = ! empty( $p['ai_live'] );
        if ( ! empty( $p['new_link'] ) && ! $ai ) $v['test_key'] = bin2hex( random_bytes( 16 ) );

        self::key( $v );
        self::put( $v );
        return self::dashboard_state();
    }
}

Qwoo_Custom_Code::init();

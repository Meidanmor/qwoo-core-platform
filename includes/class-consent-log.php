<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The cookie banner's record of choices (dashboard → Settings → Cookie banner → Consents).
 *
 * The storefront (aura ConsentBanner.vue) posts to qwoo/v1/consent:
 *   { event: shown }                                   once per visit the banner showed;
 *   { event: choice, cid, analytics, marketing, version, device, lang }   each saved choice.
 * A choice row is proof of what the shopper allowed: a random choice ID the shopper sees in
 * the choices window, the time, the banner version, the screen and language. No name, no IP
 * address, no cookie beyond the choice itself. Rows are kept KEEP_DAYS, then deleted
 * (no cron: a few saves in a hundred prune). Daily counts live in their own small table, so
 * the numbers stay quick however many rows there are.
 */
class Qwoo_Consent_Log {

    const KEEP_DAYS = 365;
    const METRICS   = [ 'shown', 'all', 'some', 'none', 'a_yes', 'm_yes', 'phone', 'desk' ];

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'qwoo_consent_log'; }
    public static function days_table() { global $wpdb; return $wpdb->prefix . 'qwoo_consent_days'; }

    /** Called by Qwoo_Push_Engine::install_tables(). */
    public static function install_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        $d = self::days_table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        cid CHAR(9) NOT NULL,
        created_at DATETIME NOT NULL,
        choice VARCHAR(4) NOT NULL,
        analytics TINYINT(1) NOT NULL DEFAULT 0,
        marketing TINYINT(1) NOT NULL DEFAULT 0,
        version INT UNSIGNED NOT NULL DEFAULT 1,
        device VARCHAR(5) NOT NULL DEFAULT '',
        lang VARCHAR(5) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY cid (cid),
        KEY created_at (created_at)
        ) $charset;" );
        dbDelta( "CREATE TABLE $d (
        day CHAR(10) NOT NULL,
        metric VARCHAR(8) NOT NULL,
        n BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY  (day,metric)
        ) $charset;" );
    }

    public static function routes() {
        register_rest_route( 'qwoo/v1', '/consent', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_consent' ],
            'permission_callback' => '__return_true', // public: rate limited in the callback
        ] );
    }

    private static function bump( string $day, string $metric ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::days_table() . ' (day, metric, n) VALUES (%s, %s, 1) ON DUPLICATE KEY UPDATE n = n + 1', $day, $metric ) );
    }

    public static function rest_consent( WP_REST_Request $r ) {
        $limited = qwoo_rate_limit_check( 'consent_log', 20, 600 );
        if ( is_wp_error( $limited ) ) return $limited;
        $p   = (array) $r->get_json_params();
        $day = wp_date( 'Y-m-d' );
        if ( ( $p['event'] ?? '' ) === 'shown' ) {
            self::bump( $day, 'shown' );
            return [ 'ok' => true ];
        }
        $cid = strtoupper( (string) ( $p['cid'] ?? '' ) );
        if ( ( $p['event'] ?? '' ) !== 'choice' || ! preg_match( '/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $cid ) ) {
            return new WP_Error( 'qwoo_consent', 'Bad choice.', [ 'status' => 400 ] );
        }
        $a      = ! empty( $p['analytics'] );
        $m      = ! empty( $p['marketing'] );
        $choice = $a && $m ? 'all' : ( $a || $m ? 'some' : 'none' );
        $device = ( $p['device'] ?? '' ) === 'phone' ? 'phone' : 'desk';
        $lang   = preg_match( '/^[a-z]{2}$/', (string) ( $p['lang'] ?? '' ) ) ? $p['lang'] : '';
        global $wpdb;
        $wpdb->insert( self::table(), [
            'cid'        => $cid,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
            'choice'     => $choice,
            'analytics'  => $a ? 1 : 0,
            'marketing'  => $m ? 1 : 0,
            'version'    => max( 1, min( 100000, (int) ( $p['version'] ?? 1 ) ) ),
            'device'     => $device,
            'lang'       => $lang,
        ] );
        self::bump( $day, $choice );
        if ( $a ) self::bump( $day, 'a_yes' );
        if ( $m ) self::bump( $day, 'm_yes' );
        self::bump( $day, $device );
        if ( wp_rand( 1, 40 ) === 1 ) self::prune();
        return [ 'ok' => true ];
    }

    public static function prune(): void {
        global $wpdb;
        $cut = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s LIMIT 5000', gmdate( 'Y-m-d H:i:s', $cut ) ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::days_table() . ' WHERE day < %s', wp_date( 'Y-m-d', $cut ) ) );
    }

    private static function row_out( array $r ): array {
        return [
            'cid'       => $r['cid'],
            'at'        => wp_date( 'c', strtotime( $r['created_at'] . ' UTC' ) ),
            'choice'    => $r['choice'],
            'analytics' => (bool) $r['analytics'],
            'marketing' => (bool) $r['marketing'],
            'version'   => (int) $r['version'],
            'device'    => $r['device'],
            'lang'      => $r['lang'],
        ];
    }

    /**
     * Dashboard: { days: 7 | 30 | 90, page, find (a choice ID), export (true: up to 5000 rows, no numbers) }.
     * → { series: [ { day, shown, all, some, none } ], totals: { metric: n }, rows, pages, total }.
     */
    public static function stats( array $p ): array {
        global $wpdb;
        $t = self::table();
        $find = strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', (string) ( $p['find'] ?? '' ) ) );
        $where = $find !== '' ? $wpdb->prepare( ' WHERE cid LIKE %s', '%' . $wpdb->esc_like( $find ) . '%' ) : '';
        if ( ! empty( $p['export'] ) ) {
            $rows = (array) $wpdb->get_results( "SELECT * FROM $t$where ORDER BY id DESC LIMIT 5000", ARRAY_A );
            return [ 'rows' => array_map( [ __CLASS__, 'row_out' ], $rows ) ];
        }
        $days  = in_array( (int) ( $p['days'] ?? 30 ), [ 7, 30, 90, 365 ], true ) ? (int) $p['days'] : 30;
        $from  = wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
        $by    = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT day, metric, n FROM ' . self::days_table() . ' WHERE day >= %s', $from ), ARRAY_A ) as $r ) {
            $by[ $r['day'] ][ $r['metric'] ] = (int) $r['n'];
        }
        $series = [];
        $totals = array_fill_keys( self::METRICS, 0 );
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $day = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
            $row = [ 'day' => $day ];
            foreach ( self::METRICS as $m ) {
                $n = (int) ( $by[ $day ][ $m ] ?? 0 );
                $totals[ $m ] += $n;
                if ( in_array( $m, [ 'shown', 'all', 'some', 'none' ], true ) ) $row[ $m ] = $n;
            }
            $series[] = $row;
        }
        $per   = 25;
        $page  = max( 1, (int) ( $p['page'] ?? 1 ) );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t$where" );
        $rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t$where ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A );
        return [
            'days'   => $days,
            'series' => $series,
            'totals' => $totals,
            'rows'   => array_map( [ __CLASS__, 'row_out' ], $rows ),
            'total'  => $total,
            'pages'  => max( 1, (int) ceil( $total / $per ) ),
            'keep'   => self::KEEP_DAYS,
        ];
    }
}

Qwoo_Consent_Log::init();

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Appointments (dashboard: Appointments → Calendar, Services, Hours, Settings).
 * Plan: plans/appointments.md (phase 1: one calendar for the store, no staff yet,
 * no outside calendars yet; an iCal link out).
 *
 *   services  qwoo_appt_services: what shoppers book (length, price, payment,
 *             free time around it, places for groups, where, fixed times for classes)
 *   bookings  qwoo_appt_bookings: one row per booking (or per "busy" time the owner blocks)
 *   slots     qwoo_appt_slots: the 5-minute units a booking takes. Its primary key
 *             (resource, unit, seat) is what makes double booking impossible: two
 *             shoppers can't insert the same unit, whatever the timing.
 *             - one-to-one: resource 'r0', seat 0, the booking's id
 *             - groups: the session's time on 'r0' (seat 0, booking 0, sess = g<svc>-<unit>)
 *               and one row per place on resource = sess, seats 1..capacity
 *   settings  option qwoo_appt_settings: weekly hours, special dates, time rules, reminders
 *
 * All times are stored in UTC; hours are wall-clock times in the store's time zone
 * (wp_timezone), so 9:00 stays 9:00 across the clock change.
 *
 * Holding: picking a time takes its units with status 'held' for 10 minutes (30 once
 * it's in the cart, 60 once the order is placed). Expired holds are let go whenever
 * times are read or written, and on the tick chain (qwoo_push_tick; no cron).
 *
 * Paying: a paid service has a hidden product (one per service); the cart line carries
 * the hold (Qwoo_Appointments_Shop). A paid order confirms the booking.
 *
 * The shopper's manage link (/booking/<id>?k=<key>) is the key: an HMAC of the id and
 * the email, never stored. Bookings are personal data: removed with the shopper's account.
 */
class Qwoo_Appointments {

    const OPTION      = 'qwoo_appt_settings';
    const DB_OPTION   = 'qwoo_appt_db';
    const DB_VERSION  = '3';
    const CALENDAR    = 'qwoo_appt_calendar'; // { connected, provider, email, invite } (the platform sets it)
    const BUSY_GEN    = 'qwoo_appt_busy_gen'; // bumped when Google says something changed
    const BUSY_TTL    = 120;
    const PRODUCTS    = 'qwoo_appt_products';
    const LISTED      = 'qwoo_appt_listed'; // service products shown in the shop
    const LOCK        = 'qwoo_appt_lock';
    const FEATURE     = 'appointments';
    const UNIT        = 300; // 5 minutes
    const PAY         = [ 'free', 'full', 'deposit', 'there' ];
    const PLACES      = [ 'business', 'phone', 'video', 'customer' ];
    const SERVICE_ON  = [ 'active', 'hidden', 'off' ];
    // Statuses whose units are taken.
    const BUSY        = [ 'held', 'pending', 'requested', 'confirmed', 'done', 'noshow', 'blocked' ];
    const STEPS       = [ 0, 5, 10, 15, 20, 30, 45, 60, 90, 120 ];
    const HOLD_MIN    = 10;
    const CART_MIN    = 30;
    const ORDER_MIN   = 60;
    const MAX_RANGE   = 62; // days per slots request
    const MAX_SERVICES = 100;

    public static function init() {
        add_action( 'init', [ __CLASS__, 'maybe_install' ] );
        add_action( 'qwoo_push_tick', [ __CLASS__, 'tick' ] );
        add_filter( 'qwoo_push_next_due', [ __CLASS__, 'next_due' ] );
        add_action( 'deleted_user', [ __CLASS__, 'forget_user' ], 10, 3 );
        add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
    }

    /* ---------------- basics ---------------- */

    public static function services_table() { global $wpdb; return $wpdb->prefix . 'qwoo_appt_services'; }
    public static function bookings_table() { global $wpdb; return $wpdb->prefix . 'qwoo_appt_bookings'; }
    public static function slots_table() { global $wpdb; return $wpdb->prefix . 'qwoo_appt_slots'; }

    public static function l( string $en, string $he ): string {
        return class_exists( 'Qwoo_I18n' ) && Qwoo_I18n::default_code() === 'he' ? $he : $en;
    }

    private static function now(): string { return gmdate( 'Y-m-d H:i:s' ); }
    private static function sql_time( int $ts ): string { return gmdate( 'Y-m-d H:i:s', $ts ); }
    private static function ts( ?string $sql ): int { return $sql ? (int) strtotime( $sql . ' UTC' ) : 0; }

    /** The plan includes it (or the store was never told a plan: everything on). */
    public static function plan_allows(): bool {
        $plan = get_option( 'qwoo_plan', null );
        if ( ! is_array( $plan ) ) return true;
        return class_exists( 'Qwoo_Platform_Dashboard' ) && Qwoo_Platform_Dashboard::has_feature( self::FEATURE );
    }

    public static function active(): bool {
        return self::plan_allows() && ! empty( self::settings()['enabled'] );
    }

    public static function maybe_install() {
        if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( 'CREATE TABLE ' . self::services_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        status VARCHAR(10) NOT NULL DEFAULT 'active',
        sort INT NOT NULL DEFAULT 0,
        product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        data LONGTEXT NULL,
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY status (status)
        ) $charset;" );
        dbDelta( 'CREATE TABLE ' . self::bookings_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        service_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        staff_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sess VARCHAR(40) NOT NULL DEFAULT '',
        start_utc DATETIME NOT NULL,
        end_utc DATETIME NOT NULL,
        seats SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        status VARCHAR(10) NOT NULL DEFAULT 'held',
        hold_until DATETIME NULL,
        hold_token VARCHAR(64) NOT NULL DEFAULT '',
        ip_hash VARCHAR(40) NOT NULL DEFAULT '',
        customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        name VARCHAR(190) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        phone VARCHAR(40) NOT NULL DEFAULT '',
        answers LONGTEXT NULL,
        place VARCHAR(255) NOT NULL DEFAULT '',
        price DECIMAL(14,4) NOT NULL DEFAULT 0,
        due_now DECIMAL(14,4) NOT NULL DEFAULT 0,
        currency VARCHAR(8) NOT NULL DEFAULT '',
        order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        lang VARCHAR(8) NOT NULL DEFAULT '',
        tz VARCHAR(64) NOT NULL DEFAULT '',
        moves TINYINT UNSIGNED NOT NULL DEFAULT 0,
        reminded TINYINT UNSIGNED NOT NULL DEFAULT 0,
        source VARCHAR(10) NOT NULL DEFAULT 'store',
        video VARCHAR(500) NOT NULL DEFAULT '',
        cal_event VARCHAR(255) NOT NULL DEFAULT '',
        cal_state VARCHAR(10) NOT NULL DEFAULT '',
        cal_tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
        cal_flag VARCHAR(10) NOT NULL DEFAULT '',
        cal_note VARCHAR(120) NOT NULL DEFAULT '',
        note TEXT NULL,
        log LONGTEXT NULL,
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY start_utc (start_utc),
        KEY status_hold (status,hold_until),
        KEY email (email),
        KEY order_id (order_id),
        KEY customer_id (customer_id)
        ) $charset;" );
        dbDelta( 'CREATE TABLE ' . self::staff_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        status VARCHAR(10) NOT NULL DEFAULT 'active',
        sort INT NOT NULL DEFAULT 0,
        data LONGTEXT NULL,
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL,
        PRIMARY KEY  (id)
        ) $charset;" );
        dbDelta( 'CREATE TABLE ' . self::slots_table() . " (
        resource VARCHAR(40) NOT NULL,
        unit INT UNSIGNED NOT NULL,
        seat SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        booking_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sess VARCHAR(40) NOT NULL DEFAULT '',
        PRIMARY KEY  (resource,unit,seat),
        KEY booking_id (booking_id),
        KEY sess (sess)
        ) $charset;" );
        update_option( self::DB_OPTION, self::DB_VERSION, false );
    }

    /* ---------------- settings ---------------- */

    private static function default_hours(): array {
        // Sunday..Saturday; minutes after midnight. Israel: Sunday to Thursday, Friday morning.
        $he  = class_exists( 'Qwoo_I18n' ) && Qwoo_I18n::default_code() === 'he';
        $day = [ [ 540, 1080 ] ];
        return $he
            ? [ $day, $day, $day, $day, $day, [ [ 540, 840 ] ], [] ]
            : [ [], $day, $day, $day, $day, $day, [] ];
    }

    public static function settings(): array {
        $s = get_option( self::OPTION, [] );
        $s = is_array( $s ) ? $s : [];
        $r = is_array( $s['rules'] ?? null ) ? $s['rules'] : [];
        $m = is_array( $s['reminders'] ?? null ) ? $s['reminders'] : [];
        return [
            'enabled'   => ! empty( $s['enabled'] ),
            'hours'     => isset( $s['hours'] ) ? self::clean_week( $s['hours'] ) : self::default_hours(),
            'dates'     => self::clean_dates( $s['dates'] ?? [] ),
            'rules'     => [
                'notice'      => min( 43200, max( 0, (int) ( $r['notice'] ?? 120 ) ) ),
                'ahead'       => min( 730, max( 1, (int) ( $r['ahead'] ?? 60 ) ) ),
                'step'        => in_array( (int) ( $r['step'] ?? 15 ), self::STEPS, true ) ? (int) ( $r['step'] ?? 15 ) : 15,
                'max_per_day' => min( 500, max( 0, (int) ( $r['max_per_day'] ?? 0 ) ) ),
                'cancel_hours' => min( 720, max( 0, (int) ( $r['cancel_hours'] ?? 24 ) ) ),
                'moves'       => min( 10, max( 0, (int) ( $r['moves'] ?? 2 ) ) ),
                'refund'      => in_array( $r['refund'] ?? '', [ 'full', 'keep', 'none' ], true ) ? $r['refund'] : 'keep',
            ],
            'reminders' => [
                'day'    => ! array_key_exists( 'day', $m ) || ! empty( $m['day'] ),
                'hours2' => ! empty( $m['hours2'] ),
            ],
            'notify_to'    => is_email( $s['notify_to'] ?? '' ) ? (string) $s['notify_to'] : '',
            'confirm_text' => mb_substr( (string) ( $s['confirm_text'] ?? '' ), 0, 1000 ),
            'terms'        => mb_substr( (string) ( $s['terms'] ?? '' ), 0, 500 ),
            'feed'         => self::feed_key( $s ),
        ];
    }

    private static function feed_key( array $s ): string {
        $k = (string) ( $s['ics_key'] ?? '' );
        if ( preg_match( '/^[a-f0-9]{48}$/', $k ) ) return $k;
        $k = bin2hex( random_bytes( 24 ) );
        $s['ics_key'] = $k;
        update_option( self::OPTION, $s, false );
        return $k;
    }

    /** A week: 7 lists of [from, to] minutes; ranges sorted, inside the day, not overlapping. */
    public static function clean_week( $week ): array {
        $out = [];
        for ( $d = 0; $d < 7; $d++ ) {
            $out[] = self::clean_ranges( is_array( $week ) ? ( $week[ $d ] ?? [] ) : [] );
        }
        return $out;
    }

    private static function clean_ranges( $ranges ): array {
        $list = [];
        foreach ( (array) $ranges as $r ) {
            if ( ! is_array( $r ) || count( $r ) < 2 ) continue;
            $a = self::minutes( $r[0] );
            $b = self::minutes( $r[1] );
            if ( $a === null || $b === null || $b <= $a ) continue;
            $list[] = [ $a, $b ];
        }
        usort( $list, static fn( $x, $y ) => $x[0] <=> $y[0] );
        $out = [];
        foreach ( $list as $r ) {
            if ( $out && $r[0] < $out[ count( $out ) - 1 ][1] ) continue;
            $out[] = $r;
            if ( count( $out ) >= 6 ) break;
        }
        return $out;
    }

    /** 540, "09:00" → minutes after midnight (0..1440, 5-minute steps). */
    private static function minutes( $v ): ?int {
        if ( is_string( $v ) && preg_match( '/^(\d{1,2}):(\d{2})$/', $v, $m ) ) $v = (int) $m[1] * 60 + (int) $m[2];
        if ( ! is_numeric( $v ) ) return null;
        $v = (int) $v;
        if ( $v < 0 || $v > 1440 ) return null;
        return intdiv( $v, 5 ) * 5;
    }

    private static function clean_date( $v ): string {
        $v = is_scalar( $v ) ? (string) $v : '';
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && checkdate( (int) substr( $v, 5, 2 ), (int) substr( $v, 8, 2 ), (int) substr( $v, 0, 4 ) ) ? $v : '';
    }

    private static function clean_dates( $dates ): array {
        $out = [];
        foreach ( (array) $dates as $d ) {
            if ( ! is_array( $d ) ) continue;
            $from = self::clean_date( $d['from'] ?? '' );
            $to   = self::clean_date( $d['to'] ?? '' ) ?: $from;
            if ( $from === '' || $to < $from ) continue;
            $out[] = [
                'id'     => preg_match( '/^[a-z0-9]{4,16}$/', (string) ( $d['id'] ?? '' ) ) ? (string) $d['id'] : substr( md5( $from . $to . count( $out ) ), 0, 10 ),
                'from'   => $from,
                'to'     => $to,
                'closed' => ! empty( $d['closed'] ),
                'ranges' => empty( $d['closed'] ) ? self::clean_ranges( $d['ranges'] ?? [] ) : [],
                'note'   => mb_substr( sanitize_text_field( (string) ( $d['note'] ?? '' ) ), 0, 80 ),
                'holiday' => ! empty( $d['holiday'] ),
            ];
            if ( count( $out ) >= 400 ) break;
        }
        usort( $out, static fn( $a, $b ) => strcmp( $a['from'], $b['from'] ) );
        return $out;
    }

    public static function save_settings( array $in ) {
        if ( ! empty( $in['enabled'] ) && ! self::plan_allows() ) return new WP_Error( 'plan', 'Appointments aren\'t part of this plan.' );
        $old = get_option( self::OPTION, [] );
        $old = is_array( $old ) ? $old : [];
        $new = $old;
        if ( array_key_exists( 'enabled', $in ) ) $new['enabled'] = ! empty( $in['enabled'] );
        if ( array_key_exists( 'hours', $in ) ) $new['hours'] = self::clean_week( $in['hours'] );
        if ( array_key_exists( 'dates', $in ) ) $new['dates'] = self::clean_dates( $in['dates'] );
        if ( is_array( $in['rules'] ?? null ) ) $new['rules'] = array_intersect_key( $in['rules'], array_flip( [ 'notice', 'ahead', 'step', 'max_per_day', 'cancel_hours', 'moves', 'refund' ] ) );
        if ( is_array( $in['reminders'] ?? null ) ) $new['reminders'] = [ 'day' => ! empty( $in['reminders']['day'] ), 'hours2' => ! empty( $in['reminders']['hours2'] ) ];
        if ( array_key_exists( 'notify_to', $in ) ) $new['notify_to'] = sanitize_email( (string) $in['notify_to'] );
        if ( array_key_exists( 'confirm_text', $in ) ) $new['confirm_text'] = mb_substr( sanitize_textarea_field( (string) $in['confirm_text'] ), 0, 1000 );
        if ( array_key_exists( 'terms', $in ) ) $new['terms'] = mb_substr( sanitize_text_field( (string) $in['terms'] ), 0, 500 );
        if ( ! empty( $in['new_feed'] ) ) $new['ics_key'] = bin2hex( random_bytes( 24 ) );
        update_option( self::OPTION, $new, false );
        if ( class_exists( 'Qwoo_Custom_Code' ) && method_exists( 'Qwoo_Custom_Code', 'touch' ) ) Qwoo_Custom_Code::touch();
        return self::dashboard_state();
    }

    /* ---------------- services ---------------- */

    private static function clean_service( array $in, array $old = [] ): array {
        $g   = static fn( $k, $d = null ) => array_key_exists( $k, $in ) ? $in[ $k ] : ( $old[ $k ] ?? $d );
        $dur = max( 5, min( 720, (int) $g( 'duration', 60 ) ) );
        $dur = intdiv( $dur, 5 ) * 5;
        $cap = max( 1, min( 500, (int) $g( 'capacity', 1 ) ) );
        $price = max( 0, round( (float) $g( 'price', 0 ), 4 ) );
        $pay = in_array( $g( 'pay', 'free' ), self::PAY, true ) ? (string) $g( 'pay', 'free' ) : 'free';
        if ( $price <= 0 && in_array( $pay, [ 'full', 'deposit' ], true ) ) $pay = 'free';
        $place = is_array( $g( 'place', [] ) ) ? $g( 'place', [] ) : [];
        $times = is_array( $g( 'times', [] ) ) ? $g( 'times', [] ) : [];
        $fixed = [];
        for ( $d = 0; $d < 7; $d++ ) {
            $list = [];
            foreach ( (array) ( $times['fixed'][ $d ] ?? [] ) as $t ) {
                $m = self::minutes( $t );
                if ( $m !== null && $m + $dur <= 1440 ) $list[ $m ] = $m;
            }
            ksort( $list );
            $fixed[] = array_values( array_slice( $list, 0, 24 ) );
        }
        $rules = is_array( $g( 'rules', [] ) ) ? $g( 'rules', [] ) : [];
        $own   = [];
        foreach ( [ 'notice' => [ 0, 43200 ], 'ahead' => [ 1, 730 ], 'cancel_hours' => [ 0, 720 ] ] as $k => [ $lo, $hi ] ) {
            if ( isset( $rules[ $k ] ) && $rules[ $k ] !== '' && $rules[ $k ] !== null ) $own[ $k ] = min( $hi, max( $lo, (int) $rules[ $k ] ) );
        }
        if ( isset( $rules['step'] ) && $rules['step'] !== '' && in_array( (int) $rules['step'], self::STEPS, true ) ) $own['step'] = (int) $rules['step'];
        if ( in_array( $rules['refund'] ?? '', [ 'full', 'keep', 'none' ], true ) ) $own['refund'] = $rules['refund'];
        $questions = [];
        foreach ( (array) $g( 'questions', [] ) as $q ) {
            if ( ! is_array( $q ) ) continue;
            $label = mb_substr( sanitize_text_field( (string) ( $q['label'] ?? '' ) ), 0, 150 );
            if ( $label === '' ) continue;
            $type  = in_array( $q['type'] ?? '', [ 'text', 'textarea', 'choice', 'checkbox' ], true ) ? $q['type'] : 'text';
            $opts  = $type === 'choice' ? array_values( array_filter( array_map( static fn( $o ) => mb_substr( sanitize_text_field( (string) $o ), 0, 80 ), (array) ( $q['options'] ?? [] ) ) ) ) : [];
            $questions[] = [
                'id'       => preg_match( '/^q[a-z0-9]{3,12}$/', (string) ( $q['id'] ?? '' ) ) ? $q['id'] : 'q' . substr( md5( $label . count( $questions ) ), 0, 8 ),
                'label'    => $label,
                'type'     => $type,
                'options'  => array_slice( $opts, 0, 20 ),
                'required' => ! empty( $q['required'] ),
            ];
            if ( count( $questions ) >= 10 ) break;
        }
        $deposit_type = $g( 'deposit_type', 'amount' ) === 'percent' ? 'percent' : 'amount';
        $deposit      = max( 0, (float) $g( 'deposit', 0 ) );
        if ( $deposit_type === 'percent' ) $deposit = min( 100, $deposit );
        $image = absint( $g( 'image', 0 ) );
        $staff = array_values( array_unique( array_filter( array_map( 'absint', (array) $g( 'staff', [] ) ) ) ) );
        return [
            // Who gives it (Appointments → Staff); none = the store's own calendar.
            'staff'         => array_slice( $staff, 0, 100 ),
            'anyone'        => ! array_key_exists( 'anyone', $in ) ? (bool) ( $old['anyone'] ?? true ) : ! empty( $in['anyone'] ),
            // The owner approves each booking: the time is held for approval_hours, then declined by itself.
            'approval'      => $g( 'approval', 'auto' ) === 'owner' ? 'owner' : 'auto',
            'approval_hours' => min( 168, max( 1, (int) $g( 'approval_hours', 24 ) ) ),
            'name'          => mb_substr( sanitize_text_field( (string) $g( 'name', '' ) ), 0, 120 ),
            'description'   => mb_substr( sanitize_textarea_field( (string) $g( 'description', '' ) ), 0, 2000 ),
            'category'      => mb_substr( sanitize_text_field( (string) $g( 'category', '' ) ), 0, 60 ),
            'image'         => $image && wp_attachment_is_image( $image ) ? $image : 0,
            'duration'      => $dur,
            'price'         => $price,
            'pay'           => $pay,
            'deposit_type'  => $deposit_type,
            'deposit'       => round( $deposit, 4 ),
            'buffer_before' => min( 240, max( 0, intdiv( (int) $g( 'buffer_before', 0 ), 5 ) * 5 ) ),
            'buffer_after'  => min( 240, max( 0, intdiv( (int) $g( 'buffer_after', 0 ), 5 ) * 5 ) ),
            'capacity'      => $cap,
            'max_seats'     => max( 1, min( $cap, (int) $g( 'max_seats', 1 ) ) ),
            'place'         => [
                'type'    => in_array( $place['type'] ?? '', self::PLACES, true ) ? $place['type'] : 'business',
                'address' => mb_substr( sanitize_text_field( (string) ( $place['address'] ?? '' ) ), 0, 255 ),
                'link'    => mb_substr( esc_url_raw( (string) ( $place['link'] ?? '' ), [ 'https' ] ), 0, 500 ),
                // Video calls: a Google Meet link made for each booking (needs Google Calendar connected).
                'meet'    => ! empty( $place['meet'] ),
                'note'    => mb_substr( sanitize_text_field( (string) ( $place['note'] ?? '' ) ), 0, 255 ),
            ],
            'times'         => [ 'mode' => ( $times['mode'] ?? '' ) === 'fixed' ? 'fixed' : 'step', 'fixed' => $fixed ],
            'rules'         => $own,
            'questions'     => $questions,
            // Also a product in the shop (grids, search, its own page), in these categories.
            'in_shop'       => ! array_key_exists( 'in_shop', $in ) ? (bool) ( $old['in_shop'] ?? false ) : ! empty( $in['in_shop'] ),
            'shop_categories' => array_slice( array_values( array_filter( array_map( 'absint', (array) $g( 'shop_categories', [] ) ) ) ), 0, 20 ),
        ];
    }

    private static function row_service( $row ): ?array {
        if ( ! $row ) return null;
        $row  = (array) $row;
        $data = json_decode( (string) $row['data'], true );
        $data = self::clean_service( is_array( $data ) ? $data : [] );
        return [ 'id' => (int) $row['id'], 'status' => (string) $row['status'], 'sort' => (int) $row['sort'], 'product_id' => (int) $row['product_id'] ] + $data;
    }

    public static function service( int $id ): ?array {
        global $wpdb;
        return $id ? self::row_service( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::services_table() . ' WHERE id = %d', $id ), ARRAY_A ) ) : null;
    }

    public static function services( bool $public = false ): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::services_table() . ( $public ? " WHERE status <> 'off'" : '' ) . ' ORDER BY sort ASC, id ASC LIMIT ' . self::MAX_SERVICES, ARRAY_A );
        return array_values( array_filter( array_map( [ __CLASS__, 'row_service' ], $rows ) ) );
    }

    /** What a shopper pays at checkout for one place. */
    public static function due_now( array $svc ): float {
        $price = (float) $svc['price'];
        if ( $svc['pay'] === 'full' ) return $price;
        if ( $svc['pay'] === 'deposit' ) {
            $d = $svc['deposit_type'] === 'percent' ? $price * (float) $svc['deposit'] / 100 : (float) $svc['deposit'];
            return round( min( $price, max( 0, $d ) ), wc_get_price_decimals() );
        }
        return 0.0;
    }

    public static function save_service( array $in ) {
        global $wpdb;
        $id  = absint( $in['id'] ?? 0 );
        $old = $id ? self::service( $id ) : null;
        if ( $id && ! $old ) return new WP_Error( 'missing', 'That service doesn\'t exist any more.' );
        if ( ! $id && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::services_table() ) >= self::MAX_SERVICES ) return new WP_Error( 'limit', 'You can have up to 100 services.' );
        $data = self::clean_service( $in, $old ?: [] );
        if ( $data['name'] === '' ) return new WP_Error( 'name', 'Give the service a name.' );
        if ( $data['times']['mode'] === 'fixed' && ! array_filter( $data['times']['fixed'] ) ) return new WP_Error( 'times', 'Add at least one start time, or choose "Any free time".' );
        $status = in_array( $in['status'] ?? ( $old['status'] ?? 'active' ), self::SERVICE_ON, true ) ? ( $in['status'] ?? ( $old['status'] ?? 'active' ) ) : 'active';
        $now    = self::now();
        $row    = [ 'status' => $status, 'data' => wp_json_encode( $data ), 'updated' => $now ];
        if ( isset( $in['sort'] ) ) $row['sort'] = (int) $in['sort'];
        if ( $id ) {
            $wpdb->update( self::services_table(), $row, [ 'id' => $id ] );
        } else {
            $row['created'] = $now;
            $row['sort']    = $row['sort'] ?? (int) $wpdb->get_var( 'SELECT COALESCE(MAX(sort), 0) + 1 FROM ' . self::services_table() );
            $wpdb->insert( self::services_table(), $row );
            $id = (int) $wpdb->insert_id;
        }
        $svc = self::service( $id );
        self::sync_product( $svc );
        return self::service( $id );
    }

    public static function delete_service( int $id ) {
        global $wpdb;
        $svc = self::service( $id );
        if ( ! $svc ) return [ 'ok' => true ];
        $future = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::bookings_table() . " WHERE service_id = %d AND status IN ('held','pending','confirmed') AND start_utc > %s", $id, self::now() ) );
        if ( $future ) return new WP_Error( 'busy', sprintf( 'This service has %d upcoming bookings. Cancel or move them first, or turn the service off.', $future ) );
        if ( $svc['product_id'] ) wp_trash_post( $svc['product_id'] );
        self::forget_product( (int) $svc['product_id'] );
        update_option( self::LISTED, array_values( array_diff( array_map( 'intval', (array) get_option( self::LISTED, [] ) ), [ (int) $svc['product_id'] ] ) ), false );
        $wpdb->delete( self::services_table(), [ 'id' => $id ] );
        return [ 'ok' => true ];
    }

    public static function sort_services( array $ids ) {
        global $wpdb;
        foreach ( array_values( array_map( 'absint', $ids ) ) as $n => $id ) {
            $wpdb->update( self::services_table(), [ 'sort' => $n + 1 ], [ 'id' => $id ] );
        }
        return [ 'ok' => true ];
    }

    /* ---------------- the hidden product behind a paid service ---------------- */

    public static function product_ids(): array {
        return array_values( array_filter( array_map( 'intval', (array) get_option( self::PRODUCTS, [] ) ) ) );
    }

    private static function forget_product( int $id ): void {
        if ( ! $id ) return;
        update_option( self::PRODUCTS, array_values( array_diff( self::product_ids(), [ $id ] ) ), false );
    }

    /**
     * A paid service gets a hidden virtual product named after it (the cart line); one that
     * stops being paid keeps it, unused. "Show in the shop" makes that product a visible one
     * (product grids, search, its own page with the Booking calendar in Add to Cart's place),
     * with the service's description, photo, price and chosen categories.
     */
    private static function sync_product( ?array $svc ): void {
        if ( ! $svc || ! function_exists( 'wc_get_product' ) ) return;
        $paid    = in_array( $svc['pay'], [ 'full', 'deposit' ], true );
        $shown   = ! empty( $svc['in_shop'] ) && $svc['status'] !== 'off';
        $product = $svc['product_id'] ? wc_get_product( $svc['product_id'] ) : null;
        if ( ! $paid && ! $shown && ! $product ) return;
        if ( ! $product || $product->get_status() === 'trash' ) {
            $product = new WC_Product_Simple();
        }
        $product->set_name( $svc['name'] );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( $shown && $svc['status'] === 'active' ? 'visible' : 'hidden' );
        if ( $shown ) {
            $product->set_description( wpautop( esc_html( $svc['description'] ) ) );
            $product->set_short_description( '' );
        }
        $product->set_virtual( true );
        $product->set_sold_individually( true );
        $product->set_manage_stock( false );
        $product->set_stock_status( 'instock' );
        $product->set_tax_status( 'taxable' );
        // A free service shows no price (it's never bought through the cart).
        $price = (float) $svc['price'] > 0 ? (string) $svc['price'] : '';
        $product->set_regular_price( $price );
        $product->set_price( $price );
        $product->set_reviews_allowed( $shown );
        $product->update_meta_data( '_qwoo_appt_service', (int) $svc['id'] );
        if ( $svc['image'] ) $product->set_image_id( (int) $svc['image'] );
        $pid = (int) $product->save();
        if ( $pid && $pid !== (int) $svc['product_id'] ) {
            global $wpdb;
            $wpdb->update( self::services_table(), [ 'product_id' => $pid ], [ 'id' => (int) $svc['id'] ] );
        }
        // Categories: the chosen ones when shown in the shop, else none (WooCommerce puts "Uncategorized" on every save).
        if ( $pid ) wp_set_object_terms( $pid, $shown ? array_values( array_filter( array_map( 'absint', (array) ( $svc['shop_categories'] ?? [] ) ), static fn( $t ) => term_exists( $t, 'product_cat' ) ) ) : [], 'product_cat' );
        if ( $pid && ! in_array( $pid, self::product_ids(), true ) ) update_option( self::PRODUCTS, array_merge( self::product_ids(), [ $pid ] ), false );
        $listed = array_values( array_diff( (array) get_option( self::LISTED, [] ), [ $pid ] ) );
        if ( $pid && $shown && $svc['status'] === 'active' ) $listed[] = $pid;
        update_option( self::LISTED, array_map( 'intval', $listed ), false );
    }

    /** The service products kept out of product lists and searches (all but the ones shown in the shop). */
    public static function hidden_product_ids(): array {
        return array_values( array_diff( self::product_ids(), array_map( 'intval', (array) get_option( self::LISTED, [] ) ) ) );
    }

    public static function service_of_product( int $product_id ): ?array {
        $product = $product_id ? wc_get_product( $product_id ) : null;
        $sid     = $product ? (int) $product->get_meta( '_qwoo_appt_service' ) : 0;
        return $sid ? self::service( $sid ) : null;
    }

    /* ---------------- the lock ---------------- */

    /** One writer at a time while checking and taking units (the primary key is the last word). */
    private static function lock(): bool {
        for ( $i = 0; $i < 30; $i++ ) {
            if ( add_option( self::LOCK, time(), '', false ) ) return true;
            if ( (int) get_option( self::LOCK ) < time() - 20 ) {
                delete_option( self::LOCK );
                continue;
            }
            usleep( 100000 );
        }
        return false;
    }

    private static function unlock(): void {
        delete_option( self::LOCK );
    }

    /* ---------------- free times ---------------- */

    private static function rule( array $svc, string $key ) {
        return array_key_exists( $key, $svc['rules'] ) ? $svc['rules'][ $key ] : self::settings()['rules'][ $key ];
    }

    /**
     * The day's opening ranges (minutes) for a staff member (0 = the store): their own
     * special dates first, then the store's (a store holiday closes everyone), then their
     * week (or the store's when they work the store's hours).
     */
    private static function day_ranges( string $date, array $s, int $staff = 0 ): array {
        $person = $staff ? self::staff_one( $staff ) : null;
        $lists  = $person && $person['dates'] ? [ $person['dates'], $s['dates'] ] : [ $s['dates'] ];
        foreach ( $lists as $dates ) {
            foreach ( $dates as $d ) {
                if ( $d['from'] <= $date && $date <= $d['to'] ) {
                    return [ 'ranges' => $d['closed'] ? [] : $d['ranges'], 'special' => true ];
                }
            }
        }
        $week = $person && is_array( $person['hours'] ) ? $person['hours'] : $s['hours'];
        $dt   = new DateTimeImmutable( $date . ' 12:00', wp_timezone() );
        return [ 'ranges' => $week[ (int) $dt->format( 'w' ) ] ?? [], 'special' => false ];
    }

    /** The slots resource of a staff member (r0 = the store, for services without staff). */
    private static function resource( int $staff ): string {
        return 'r' . max( 0, $staff );
    }

    /** Taken units of a resource in [from, to): unit => sess ('' = one-to-one or busy), leaving out $exclude's own rows. */
    private static function taken( int $u0, int $u1, int $exclude = 0, string $exclude_sess = '', int $staff = 0 ): array {
        global $wpdb;
        $rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT unit, booking_id, sess FROM ' . self::slots_table() . ' WHERE resource = %s AND unit >= %d AND unit < %d', self::resource( $staff ), $u0, $u1 ), ARRAY_A );
        $taken = [];
        foreach ( $rows as $r ) {
            if ( $exclude && (int) $r['booking_id'] === $exclude ) continue;
            if ( $exclude_sess !== '' && $r['sess'] === $exclude_sess ) continue;
            $taken[ (int) $r['unit'] ] = (string) $r['sess'];
        }
        return $taken;
    }

    /** Places taken per group session (sess => count), leaving out $exclude's own. */
    private static function seats_taken( int $u0, int $u1, int $exclude = 0 ): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT sess, booking_id FROM ' . self::slots_table() . " WHERE resource LIKE 'g%%' AND unit >= %d AND unit < %d AND seat > 0", $u0, $u1 ), ARRAY_A );
        $out  = [];
        foreach ( $rows as $r ) {
            if ( $exclude && (int) $r['booking_id'] === $exclude ) continue;
            $out[ $r['sess'] ] = ( $out[ $r['sess'] ] ?? 0 ) + 1;
        }
        return $out;
    }

    private static function sess_for( array $svc, int $start, int $staff = 0 ): string {
        if ( $svc['capacity'] <= 1 ) return '';
        return 'g' . $svc['id'] . '-' . ( $staff ? $staff . '-' : '' ) . intdiv( $start, self::UNIT );
    }

    /** The staff ids that give a service and are on (none: the service is the store's own, staff 0). */
    public static function service_staff( array $svc ): array {
        $out = [];
        foreach ( (array) ( $svc['staff'] ?? [] ) as $id ) {
            $p = self::staff_one( (int) $id );
            if ( $p && $p['status'] === 'active' ) $out[] = (int) $id;
        }
        return $out;
    }

    /** [ first unit, end unit (exclusive) ] a booking at $start takes, with its free time around it. */
    private static function units( array $svc, int $start ): array {
        $a = $start - $svc['buffer_before'] * 60;
        $b = $start + ( $svc['duration'] + $svc['buffer_after'] ) * 60;
        return [ intdiv( $a, self::UNIT ), (int) ceil( $b / self::UNIT ) ];
    }

    /**
     * Free times for a service between two store dates (Y-m-d, inclusive):
     * [ date => [ { start (ISO UTC), time (store H:i), left (places, groups), staff: [ids free] } ] ].
     * $opt: owner (the owner adding a booking: no notice or "how far ahead"), staff (one
     * person; 0 = anyone who gives it), seats, exclude (a booking being moved), fresh.
     */
    public static function free_times( array $svc, string $from, string $to, array $opt = [] ): array {
        $from = self::clean_date( $from );
        $to   = self::clean_date( $to );
        if ( $from === '' || $to === '' || $to < $from ) return [];
        $tz    = wp_timezone();
        $start = new DateTimeImmutable( $from . ' 00:00', $tz );
        $end   = new DateTimeImmutable( $to . ' 00:00', $tz );
        if ( $end->diff( $start )->days > self::MAX_RANGE ) $end = $start->modify( '+' . self::MAX_RANGE . ' days' );
        $who  = self::service_staff( $svc );
        $want = (int) ( $opt['staff'] ?? 0 );
        if ( ! $who ) {
            if ( ! empty( $svc['staff'] ) ) return []; // everyone who gives it is off
            $who = [ 0 ];
        } elseif ( $want ) {
            $who = in_array( $want, $who, true ) ? [ $want ] : [];
        }
        $out = [];
        foreach ( $who as $staff ) {
            foreach ( self::free_times_of( $svc, $start, $end, $opt, $staff ) as $date => $list ) {
                $out[ $date ] = $out[ $date ] ?? [];
                foreach ( $list as $slot ) {
                    $k = $slot['start'];
                    if ( ! isset( $out[ $date ][ $k ] ) ) {
                        $out[ $date ][ $k ] = $slot + [ 'staff' => [] ];
                    } elseif ( isset( $slot['left'] ) ) {
                        $out[ $date ][ $k ]['left'] = max( (int) $out[ $date ][ $k ]['left'], (int) $slot['left'] );
                    }
                    $out[ $date ][ $k ]['staff'][] = $staff;
                }
            }
        }
        foreach ( $out as $date => $list ) {
            ksort( $list );
            $out[ $date ] = array_values( $list );
        }
        ksort( $out );
        return $out;
    }

    /** One person's (or the store's) free times. */
    private static function free_times_of( array $svc, DateTimeImmutable $start, DateTimeImmutable $end, array $opt, int $staff ): array {
        $s       = self::settings();
        $owner   = ! empty( $opt['owner'] );
        $exclude = (int) ( $opt['exclude'] ?? 0 );
        $ex_sess = (string) ( $opt['exclude_sess'] ?? '' );
        $seats   = max( 1, (int) ( $opt['seats'] ?? 1 ) );
        $now     = time();
        $min_ts  = $owner ? 0 : $now + (int) self::rule( $svc, 'notice' ) * 60;
        $max_ts  = $owner ? PHP_INT_MAX : $now + (int) self::rule( $svc, 'ahead' ) * DAY_IN_SECONDS;
        $step    = (int) self::rule( $svc, 'step' ) ?: (int) $svc['duration'];
        $dur     = (int) $svc['duration'];
        $u0      = intdiv( $start->getTimestamp() - DAY_IN_SECONDS, self::UNIT );
        $u1      = intdiv( $end->modify( '+2 days' )->getTimestamp(), self::UNIT );
        $taken   = self::taken( $u0, $u1, $exclude, $ex_sess, $staff );
        // Their own calendar (Google): its busy times take the same units.
        foreach ( self::busy( $u0 * self::UNIT, $u1 * self::UNIT, ! empty( $opt['fresh'] ), $staff ) as [ $a, $b ] ) {
            for ( $u = max( $u0, intdiv( $a, self::UNIT ) ); $u < min( $u1, (int) ceil( $b / self::UNIT ) ); $u++ ) $taken[ $u ] = '';
        }
        $places  = $svc['capacity'] > 1 ? self::seats_taken( $u0, $u1, $exclude ) : [];
        $per_day = self::day_counts( $start->getTimestamp(), $end->modify( '+1 day' )->getTimestamp(), $exclude, $staff );
        $max_day = (int) $s['rules']['max_per_day'];
        $out     = [];
        for ( $day = $start; $day <= $end; $day = $day->modify( '+1 day' ) ) {
            $date = $day->format( 'Y-m-d' );
            $list = [];
            if ( ! $max_day || $owner || ( $per_day[ $date ] ?? 0 ) < $max_day ) {
                $info   = self::day_ranges( $date, $s, $staff );
                $ranges = $info['ranges'];
                $cands  = [];
                if ( $svc['times']['mode'] === 'fixed' ) {
                    $fixed = $svc['times']['fixed'][ (int) $day->format( 'w' ) ] ?? [];
                    foreach ( $fixed as $m ) {
                        if ( ! $info['special'] ) { $cands[] = $m; continue; }
                        foreach ( $ranges as $r ) {
                            if ( $m >= $r[0] && $m + $dur <= $r[1] ) { $cands[] = $m; break; }
                        }
                    }
                } else {
                    foreach ( $ranges as $r ) {
                        for ( $m = $r[0]; $m + $dur <= $r[1]; $m += $step ) $cands[] = $m;
                    }
                }
                foreach ( $cands as $m ) {
                    $ts = $day->setTime( intdiv( $m, 60 ), $m % 60 )->getTimestamp();
                    if ( $ts < $min_ts || $ts > $max_ts ) continue;
                    [ $a, $b ] = self::units( $svc, $ts );
                    $sess = self::sess_for( $svc, $ts, $staff );
                    $ok   = true;
                    for ( $u = $a; $u < $b; $u++ ) {
                        if ( isset( $taken[ $u ] ) && ( $sess === '' || $taken[ $u ] !== $sess ) ) { $ok = false; break; }
                    }
                    if ( ! $ok ) continue;
                    $slot = [ 'start' => gmdate( 'Y-m-d\TH:i:s\Z', $ts ), 'time' => sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 ) ];
                    if ( $sess !== '' ) {
                        $left = (int) $svc['capacity'] - (int) ( $places[ $sess ] ?? 0 );
                        if ( $left < $seats ) continue;
                        $slot['left'] = $left;
                    }
                    $list[] = $slot;
                }
            }
            $out[ $date ] = $list;
        }
        return $out;
    }

    /** Bookings that take time, per store date, between two moments (one person's, or the store's). */
    private static function day_counts( int $from, int $to, int $exclude = 0, int $staff = 0 ): array {
        global $wpdb;
        $rows = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT start_utc FROM ' . self::bookings_table() . " WHERE status IN ('held','pending','requested','confirmed','done','noshow') AND start_utc >= %s AND start_utc < %s AND id <> %d AND staff_id = %d", self::sql_time( $from ), self::sql_time( $to ), $exclude, $staff ) );
        $out  = [];
        foreach ( $rows as $r ) {
            $d = wp_date( 'Y-m-d', self::ts( $r ) );
            $out[ $d ] = ( $out[ $d ] ?? 0 ) + 1;
        }
        return $out;
    }

    /** Whether $start is one of the service's free times right now ($opt staff: for that person). */
    private static function is_free( array $svc, int $start, array $opt = [] ): bool {
        return (bool) self::free_staff( $svc, $start, $opt );
    }

    /** Who is free at $start (staff ids; [0] for a service without staff). */
    private static function free_staff( array $svc, int $start, array $opt = [] ): array {
        $date = wp_date( 'Y-m-d', $start );
        $want = gmdate( 'Y-m-d\TH:i:s\Z', $start );
        foreach ( self::free_times( $svc, $date, $date, $opt + [ 'fresh' => true ] )[ $date ] ?? [] as $slot ) {
            if ( $slot['start'] === $want ) return $slot['staff'];
        }
        return [];
    }

    /* ---------------- staff ---------------- */

    public static function staff_table() { global $wpdb; return $wpdb->prefix . 'qwoo_appt_staff'; }

    private static $staff_cache = null;

    /** How many staff the plan allows (0 = no limit). Basic 1, Pro (and the trial) 5, Plus no limit. */
    public static function staff_limit(): int {
        $plan = get_option( 'qwoo_plan', null );
        if ( ! is_array( $plan ) ) return 0;
        return [ 'basic' => 1, 'free' => 1, 'pro' => 5, 'trial' => 5, 'plus' => 0 ][ (string) ( $plan['plan'] ?? '' ) ] ?? 5;
    }

    private static function clean_staff( array $in, array $old = [] ): array {
        $g     = static fn( $k, $d = null ) => array_key_exists( $k, $in ) ? $in[ $k ] : ( $old[ $k ] ?? $d );
        $photo = absint( $g( 'photo', 0 ) );
        $hours = $g( 'hours', null );
        $cal   = $old['cal'] ?? [];
        return [
            'name'     => mb_substr( sanitize_text_field( (string) $g( 'name', '' ) ), 0, 80 ),
            'email'    => is_email( (string) $g( 'email', '' ) ) ? sanitize_email( (string) $g( 'email', '' ) ) : '',
            'bio'      => mb_substr( sanitize_textarea_field( (string) $g( 'bio', '' ) ), 0, 500 ),
            'photo'    => $photo && wp_attachment_is_image( $photo ) ? $photo : 0,
            'color'    => sanitize_hex_color( (string) $g( 'color', '' ) ) ?: '',
            // null = the store's hours
            'hours'    => is_array( $hours ) ? self::clean_week( $hours ) : null,
            'dates'    => self::clean_dates( $g( 'dates', [] ) ),
            // Whose calendar: the store's connected calendar, their own, or none.
            'calendar' => in_array( $g( 'calendar', 'store' ), [ 'store', 'own', 'none' ], true ) ? (string) $g( 'calendar', 'store' ) : 'store',
            'notify'   => ! array_key_exists( 'notify', $in ) ? ( $old['notify'] ?? true ) : ! empty( $in['notify'] ),
            'cal'      => is_array( $cal ) ? $cal : [],
        ];
    }

    private static function row_staff( $row ): ?array {
        if ( ! $row ) return null;
        $row  = (array) $row;
        $data = json_decode( (string) $row['data'], true );
        $data = is_array( $data ) ? $data : [];
        return [ 'id' => (int) $row['id'], 'status' => (string) $row['status'], 'sort' => (int) $row['sort'] ] + self::clean_staff( [], $data + [ 'cal' => $data['cal'] ?? [] ] );
    }

    public static function staff_list(): array {
        if ( self::$staff_cache === null ) {
            global $wpdb;
            $rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::staff_table() . ' ORDER BY sort ASC, id ASC LIMIT 200', ARRAY_A );
            self::$staff_cache = [];
            foreach ( $rows as $r ) {
                $p = self::row_staff( $r );
                if ( $p ) self::$staff_cache[ $p['id'] ] = $p;
            }
        }
        return self::$staff_cache;
    }

    public static function staff_one( int $id ): ?array {
        return $id ? ( self::staff_list()[ $id ] ?? null ) : null;
    }

    public static function save_staff( array $in, int $only = 0 ) {
        global $wpdb;
        $id  = absint( $in['id'] ?? 0 );
        if ( $only && $id !== $only ) return new WP_Error( 'access', 'You can change your own details only.' );
        $old = $id ? self::staff_one( $id ) : null;
        if ( $id && ! $old ) return new WP_Error( 'missing', 'That person isn\'t on your team any more.' );
        if ( $only ) {
            // Someone with "only their own bookings": their hours, days off, photo and words.
            $in = array_intersect_key( $in, array_flip( [ 'hours', 'dates', 'bio', 'photo' ] ) );
        }
        $limit = self::staff_limit();
        if ( ! $id && $limit && count( array_filter( self::staff_list(), static fn( $p ) => $p['status'] === 'active' ) ) >= $limit ) {
            return new WP_Error( 'limit', sprintf( 'Your plan has room for %d people. See Plan & billing for more.', $limit ) );
        }
        $data = self::clean_staff( $in, $old ?: [] );
        if ( $data['name'] === '' ) return new WP_Error( 'name', 'Write their name.' );
        $status = $only ? ( $old['status'] ?? 'active' ) : ( ( $in['status'] ?? ( $old['status'] ?? 'active' ) ) === 'off' ? 'off' : 'active' );
        $row    = [ 'status' => $status, 'data' => wp_json_encode( $data ), 'updated' => self::now() ];
        if ( $id ) {
            $wpdb->update( self::staff_table(), $row, [ 'id' => $id ] );
        } else {
            $wpdb->insert( self::staff_table(), $row + [ 'sort' => count( self::staff_list() ) + 1, 'created' => self::now() ] );
            $id = (int) $wpdb->insert_id;
        }
        self::$staff_cache = null;
        // "Gives these services" from the staff card.
        if ( ! $only && is_array( $in['services'] ?? null ) ) {
            $want = array_map( 'absint', $in['services'] );
            foreach ( self::services() as $svc ) {
                $has  = in_array( $id, $svc['staff'], true );
                $gets = in_array( $svc['id'], $want, true );
                if ( $has === $gets ) continue;
                $list = $gets ? array_merge( $svc['staff'], [ $id ] ) : array_values( array_diff( $svc['staff'], [ $id ] ) );
                $wpdb->update( self::services_table(), [ 'data' => wp_json_encode( self::clean_service( [ 'staff' => $list ], $svc ) ), 'updated' => self::now() ], [ 'id' => $svc['id'] ] );
            }
        }
        return self::staff_view( self::staff_one( $id ) );
    }

    public static function delete_staff( int $id ) {
        global $wpdb;
        if ( ! self::staff_one( $id ) ) return [ 'ok' => true ];
        $future = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::bookings_table() . " WHERE staff_id = %d AND status IN ('held','pending','requested','confirmed') AND start_utc > %s", $id, self::now() ) );
        if ( $future ) return new WP_Error( 'busy', sprintf( 'They have %d upcoming bookings. Move them to someone else first, or turn them off.', $future ) );
        foreach ( self::services() as $svc ) {
            if ( in_array( $id, $svc['staff'], true ) ) {
                $wpdb->update( self::services_table(), [ 'data' => wp_json_encode( self::clean_service( [ 'staff' => array_values( array_diff( $svc['staff'], [ $id ] ) ) ], $svc ) ) ], [ 'id' => $svc['id'] ] );
            }
        }
        $wpdb->delete( self::staff_table(), [ 'id' => $id ] );
        self::$staff_cache = null;
        return [ 'ok' => true ];
    }

    public static function staff_view( ?array $p ): ?array {
        if ( ! $p ) return null;
        return $p + [
            'photo_url' => $p['photo'] ? (string) wp_get_attachment_image_url( $p['photo'], 'thumbnail' ) : '',
            'services'  => array_values( array_map( static fn( $s ) => $s['id'], array_filter( self::services(), static fn( $s ) => in_array( $p['id'], $s['staff'], true ) ) ) ),
        ];
    }

    /** The least busy of these people that day (anyone available). */
    private static function pick_staff( array $ids, int $start ): int {
        if ( count( $ids ) < 2 ) return (int) ( $ids[0] ?? 0 );
        $day  = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( wp_timezone() )->setTime( 0, 0 )->getTimestamp();
        $best = 0;
        $low  = PHP_INT_MAX;
        foreach ( $ids as $id ) {
            $n = array_sum( self::day_counts( $day, $day + DAY_IN_SECONDS, 0, (int) $id ) );
            if ( $n < $low ) { $low = $n; $best = (int) $id; }
        }
        return $best;
    }

    /* ---------------- the owner's calendar (Google, through the platform) ---------------- */

    public static function calendar(): array {
        $c = get_option( self::CALENDAR, [] );
        $c = is_array( $c ) ? $c : [];
        return [ 'connected' => ! empty( $c['connected'] ), 'provider' => (string) ( $c['provider'] ?? '' ), 'email' => (string) ( $c['email'] ?? '' ), 'invite' => ! empty( $c['invite'] ), 'error' => (string) ( $c['error'] ?? '' ) ];
    }

    /**
     * Whose connected calendar a person's bookings use: 0 = the store's, a staff id = their
     * own, null = none. Staff 0 is the store itself (services without staff).
     */
    public static function cal_target( int $staff ): ?int {
        if ( $staff ) {
            $p = self::staff_one( $staff );
            if ( ! $p || $p['calendar'] === 'none' ) return null;
            if ( $p['calendar'] === 'own' ) return ! empty( $p['cal']['connected'] ) ? $staff : null;
        }
        return self::calendar()['connected'] ? 0 : null;
    }

    private static function cal_info( int $link ): array {
        if ( ! $link ) return self::calendar();
        $p = self::staff_one( $link );
        return [ 'connected' => ! empty( $p['cal']['connected'] ), 'email' => (string) ( $p['cal']['email'] ?? '' ), 'invite' => ! empty( $p['cal']['invite'] ), 'error' => '' ];
    }

    private static function calendar_error( string $error ): void {
        $c = get_option( self::CALENDAR, [] );
        if ( ! is_array( $c ) || ( $c['error'] ?? '' ) === $error ) return;
        $c['error'] = $error;
        update_option( self::CALENDAR, $c, false );
    }

    /**
     * Busy times of the owner's calendar between two moments: [ [ start, end ] ] (unix).
     * Kept for 2 minutes (and dropped when Google says something changed); $fresh asks
     * again (before a time is held). When the calendar can't be reached, nothing is
     * busy: bookings still work.
     */
    public static function busy( int $from, int $to, bool $fresh = false, int $staff = 0 ): array {
        $link = self::cal_target( $staff );
        if ( $link === null || ! class_exists( 'Qwoo_Platform_Connection' ) ) return [];
        $a   = intdiv( $from, DAY_IN_SECONDS ) * DAY_IN_SECONDS;
        $b   = (int) ceil( $to / DAY_IN_SECONDS ) * DAY_IN_SECONDS;
        $key = 'qwoo_appt_busy_' . md5( (int) get_option( self::BUSY_GEN, 0 ) . '|' . $link . '|' . $a . '|' . $b );
        $hit = $fresh ? false : get_transient( $key );
        if ( is_array( $hit ) ) return $hit;
        $res = Qwoo_Platform_Connection::post( 'calendar', [ 'do' => 'busy', 'staff' => $link, 'from' => gmdate( 'c', $a ), 'to' => gmdate( 'c', $b ) ], 8 );
        if ( ! is_array( $res ) || empty( $res['connected'] ) || ! is_array( $res['busy'] ?? null ) ) {
            if ( ! $link ) self::calendar_error( is_array( $res ) && array_key_exists( 'connected', $res ) && ! $res['connected'] ? 'disconnected' : 'unreachable' );
            set_transient( $key, [], 30 );
            return [];
        }
        if ( ! $link ) self::calendar_error( '' );
        $tz  = wp_timezone();
        $out = [];
        foreach ( $res['busy'] as $iv ) {
            if ( ! is_array( $iv ) || count( $iv ) < 2 ) continue;
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $iv[0] ) ) {
                // An all-day event: whole days in the store's time zone.
                $s = date_create_immutable( $iv[0] . ' 00:00', $tz );
                $e = date_create_immutable( $iv[1] . ' 00:00', $tz );
                if ( $s && $e ) $out[] = [ $s->getTimestamp(), $e->getTimestamp() ];
                continue;
            }
            $s = strtotime( (string) $iv[0] );
            $e = strtotime( (string) $iv[1] );
            if ( $s && $e && $e > $s ) $out[] = [ $s, $e ];
        }
        set_transient( $key, $out, self::BUSY_TTL );
        return $out;
    }

    /**
     * Puts the booking in the owner's calendar, moves it there, or takes it out.
     * Failed tries are tried again on the tick chain (5 times).
     */
    public static function cal_sync( int $id ): void {
        global $wpdb;
        $b = self::booking( $id );
        if ( ! $b || ! class_exists( 'Qwoo_Platform_Connection' ) ) return;
        $link = self::cal_target( (int) $b['staff_id'] );
        if ( $link === null ) return;
        $live = in_array( $b['status'], [ 'confirmed', 'done', 'noshow', 'blocked' ], true );
        if ( ! $live ) {
            if ( $b['cal_event'] === '' ) return;
            $res = Qwoo_Platform_Connection::post( 'calendar', [ 'do' => 'delete', 'staff' => $link, 'event_id' => $b['cal_event'] ], 8 );
            $ok  = is_array( $res ) && ( ! empty( $res['ok'] ) || ( array_key_exists( 'connected', $res ) && ! $res['connected'] ) );
            $wpdb->update( self::bookings_table(), $ok ? [ 'cal_event' => '', 'cal_state' => 'ok', 'cal_tries' => 0 ] : [ 'cal_state' => 'retry', 'cal_tries' => (int) $b['cal_tries'] + 1 ], [ 'id' => $id ] );
            return;
        }
        if ( in_array( $b['status'], [ 'done', 'noshow' ], true ) && $b['cal_event'] !== '' ) return; // nothing to change for a past one
        $res = Qwoo_Platform_Connection::post( 'calendar', [ 'do' => 'write', 'staff' => $link, 'event' => self::cal_event( $b, $link ) ], 10 );
        if ( is_array( $res ) && ! empty( $res['event_id'] ) ) {
            $set = [ 'cal_event' => mb_substr( (string) $res['event_id'], 0, 255 ), 'cal_state' => 'ok', 'cal_tries' => 0 ];
            if ( ! empty( $res['meet'] ) && preg_match( '#^https://(meet\.google\.com|teams\.microsoft\.com|teams\.live\.com)/#', (string) $res['meet'] ) ) $set['video'] = mb_substr( (string) $res['meet'], 0, 500 );
            $wpdb->update( self::bookings_table(), $set, [ 'id' => $id ] );
            return;
        }
        if ( is_array( $res ) && array_key_exists( 'connected', $res ) && ! $res['connected'] ) return;
        $wpdb->update( self::bookings_table(), [ 'cal_state' => 'retry', 'cal_tries' => (int) $b['cal_tries'] + 1 ], [ 'id' => $id ] );
    }

    /** The event as the owner sees it in their calendar (the customer sees only the title, place and link when invited). */
    private static function cal_event( array $b, int $link = 0 ): array {
        $svc     = self::service( (int) $b['service_id'] );
        $invite  = self::cal_info( $link )['invite'] && $b['email'] !== '' && $b['status'] !== 'blocked';
        if ( $b['status'] === 'blocked' ) {
            $summary = self::l( 'Busy', 'תפוס' ) . ( $b['note'] ? ' · ' . $b['note'] : '' );
            $desc    = '';
        } else {
            $summary = ( $svc ? $svc['name'] : self::l( 'Booking', 'תור' ) ) . ' · ' . $b['name'] . ( (int) $b['seats'] > 1 ? ' (' . (int) $b['seats'] . ')' : '' );
            $lines   = [];
            if ( ! $invite ) {
                // Only in the owner's own copy: the guest would see the description too.
                $lines = array_filter( [ $b['phone'], $b['email'] ] );
                foreach ( (array) json_decode( (string) $b['answers'], true ) as $a ) $lines[] = ( $a['label'] ?? '' ) . ': ' . ( $a['value'] ?? '' );
                if ( $b['note'] ) $lines[] = $b['note'];
            }
            $owner = self::owner_link( (int) $b['id'] );
            if ( $owner !== '' && ! $invite ) $lines[] = $owner;
            if ( $invite ) $lines[] = self::l( 'Move or cancel:', 'הזזה או ביטול:' ) . ' ' . self::manage_url( $b );
            $desc = implode( "\n", $lines );
        }
        $meet = $svc && $svc['place']['type'] === 'video' && ! empty( $svc['place']['meet'] ) && $b['status'] !== 'blocked';
        return [
            'booking' => (int) $b['id'], 'event_id' => (string) $b['cal_event'],
            'start' => gmdate( 'c', self::ts( $b['start_utc'] ) ), 'end' => gmdate( 'c', self::ts( $b['end_utc'] ) ),
            'summary' => $summary, 'description' => $desc, 'location' => $meet ? '' : (string) $b['place'],
            'attendee' => $invite ? (string) $b['email'] : '', 'attendee_name' => (string) $b['name'], 'meet' => $meet && $b['video'] === '',
        ];
    }

    /** From the platform: the owner connected or disconnected a calendar. */
    public static function cal_link( array $p ): array {
        global $wpdb;
        $info  = [
            'connected' => ! empty( $p['connected'] ), 'provider' => sanitize_key( (string) ( $p['provider'] ?? '' ) ),
            'email' => sanitize_email( (string) ( $p['email'] ?? '' ) ), 'invite' => ! empty( $p['invite'] ), 'error' => '',
        ];
        $staff = absint( $p['staff'] ?? 0 );
        if ( $staff ) {
            // A staff member's own calendar: kept on their card.
            $person = self::staff_one( $staff );
            if ( ! $person ) return [ 'ok' => false ];
            $data          = array_diff_key( $person, array_flip( [ 'id', 'status', 'sort' ] ) );
            $data['cal']   = $info;
            if ( $info['connected'] ) $data['calendar'] = 'own';
            elseif ( $data['calendar'] === 'own' ) $data['calendar'] = 'store';
            $wpdb->update( self::staff_table(), [ 'data' => wp_json_encode( $data ), 'updated' => self::now() ], [ 'id' => $staff ] );
            self::$staff_cache = null;
        } else {
            update_option( self::CALENDAR, $info, false );
        }
        update_option( self::BUSY_GEN, (int) get_option( self::BUSY_GEN, 0 ) + 1, false );
        // Upcoming bookings go into a newly connected calendar (the tick chain does the rest).
        if ( ! empty( $p['connected'] ) ) {
            $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::bookings_table() . " SET cal_state = 'retry', cal_tries = 0 WHERE status IN ('confirmed','blocked') AND cal_event = '' AND start_utc > %s", self::now() ) );
        }
        return [ 'ok' => true ];
    }

    /**
     * From the platform: Google said the calendar changed. Busy times are read again;
     * our events that were moved or deleted there are flagged on their booking (the
     * booking itself never changes by itself: the customer wasn't told).
     */
    public static function cal_changed( array $p ): array {
        global $wpdb;
        update_option( self::BUSY_GEN, (int) get_option( self::BUSY_GEN, 0 ) + 1, false );
        $flagged = 0;
        foreach ( array_slice( (array) ( $p['events'] ?? [] ), 0, 250 ) as $e ) {
            $b = is_array( $e ) ? self::booking( absint( $e['booking'] ?? 0 ) ) : null;
            // Outlook can only say which event was deleted (not whose): found by its event id.
            if ( ! $b && is_array( $e ) && (string) ( $e['event'] ?? '' ) !== '' ) {
                $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::bookings_table() . ' WHERE cal_event = %s LIMIT 1', (string) $e['event'] ), ARRAY_A );
                $b   = $row ?: null;
            }
            if ( ! $b || ! in_array( $b['status'], [ 'confirmed', 'pending' ], true ) || $b['cal_event'] !== (string) ( $e['event'] ?? '' ) ) continue;
            $flag = '';
            $note = '';
            if ( ( $e['status'] ?? '' ) === 'cancelled' ) {
                $flag = 'deleted';
            } else {
                $s = strtotime( (string) ( $e['start'] ?? '' ) );
                $f = strtotime( (string) ( $e['end'] ?? '' ) );
                if ( $s && $f && ( $s !== self::ts( $b['start_utc'] ) || $f !== self::ts( $b['end_utc'] ) ) ) {
                    $flag = 'moved';
                    $note = wp_date( 'Y-m-d H:i', $s ) . '–' . wp_date( 'H:i', $f );
                }
            }
            if ( $flag !== '' && ( $flag !== $b['cal_flag'] || $note !== $b['cal_note'] ) ) {
                $wpdb->update( self::bookings_table(), [ 'cal_flag' => $flag, 'cal_note' => $note ], [ 'id' => (int) $b['id'] ] );
                self::log( (int) $b['id'], 'cal_' . $flag, $note );
                $flagged++;
            }
        }
        return [ 'ok' => true, 'flagged' => $flagged ];
    }

    /** Bookings still to put in the calendar (the tick chain). */
    private static function cal_retry( float $until ): void {
        global $wpdb;
        $rows = (array) $wpdb->get_col( $wpdb->prepare(
            'SELECT id FROM ' . self::bookings_table() . " WHERE cal_state = 'retry' AND cal_tries < 5 AND updated < %s ORDER BY id LIMIT 10",
            self::sql_time( time() - 5 * MINUTE_IN_SECONDS )
        ) );
        foreach ( $rows as $id ) {
            if ( microtime( true ) > $until ) return;
            $wpdb->update( self::bookings_table(), [ 'updated' => self::now() ], [ 'id' => (int) $id ] );
            self::cal_sync( (int) $id );
        }
    }

    /* ---------------- taking and letting go ---------------- */

    /** Takes the booking's units on its person's resource. False: someone else has them. Call inside the lock. */
    private static function take_units( int $booking_id, array $svc, int $start, int $seats, int $staff = 0 ): bool {
        global $wpdb;
        [ $a, $b ] = self::units( $svc, $start );
        $sess  = self::sess_for( $svc, $start, $staff );
        $res   = self::resource( $staff );
        $table = self::slots_table();
        if ( $sess === '' ) {
            $values = [];
            for ( $u = $a; $u < $b; $u++ ) $values[] = $wpdb->prepare( "(%s, %d, 0, %d, '')", $res, $u, $booking_id );
            $ok = $wpdb->query( "INSERT INTO $table (resource, unit, seat, booking_id, sess) VALUES " . implode( ',', $values ) );
            if ( $ok === false ) {
                $wpdb->delete( $table, [ 'booking_id' => $booking_id ] );
                return false;
            }
            return true;
        }
        // A group: its session's time once on the person's resource, then a row per place.
        $existing = (array) $wpdb->get_results( $wpdb->prepare( "SELECT unit, sess FROM $table WHERE resource = %s AND unit >= %d AND unit < %d", $res, $a, $b ), ARRAY_A );
        $have     = [];
        foreach ( $existing as $r ) {
            if ( $r['sess'] !== $sess ) return false;
            $have[ (int) $r['unit'] ] = true;
        }
        $used = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT seat FROM $table WHERE resource = %s AND seat > 0", $sess ) ) );
        $free = array_values( array_diff( range( 1, (int) $svc['capacity'] ), $used ) );
        if ( count( $free ) < $seats ) return false;
        $values = [];
        for ( $i = 0; $i < $seats; $i++ ) $values[] = $wpdb->prepare( '(%s, %d, %d, %d, %s)', $sess, intdiv( $start, self::UNIT ), $free[ $i ], $booking_id, $sess );
        if ( $wpdb->query( "INSERT INTO $table (resource, unit, seat, booking_id, sess) VALUES " . implode( ',', $values ) ) === false ) {
            $wpdb->delete( $table, [ 'booking_id' => $booking_id ] );
            return false;
        }
        $missing = [];
        for ( $u = $a; $u < $b; $u++ ) {
            if ( empty( $have[ $u ] ) ) $missing[] = $wpdb->prepare( '(%s, %d, 0, 0, %s)', $res, $u, $sess );
        }
        if ( $missing && $wpdb->query( "INSERT INTO $table (resource, unit, seat, booking_id, sess) VALUES " . implode( ',', $missing ) ) === false ) {
            $wpdb->delete( $table, [ 'booking_id' => $booking_id ] );
            self::tidy_session( $sess );
            return false;
        }
        return true;
    }

    /** A group session nobody is in any more lets go of its time. */
    private static function tidy_session( string $sess ): void {
        global $wpdb;
        if ( $sess === '' ) return;
        $left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::slots_table() . ' WHERE resource = %s AND seat > 0', $sess ) );
        if ( ! $left ) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::slots_table() . " WHERE resource LIKE 'r%%' AND sess = %s", $sess ) );
    }

    private static function release_units( array $b ): void {
        global $wpdb;
        $wpdb->delete( self::slots_table(), [ 'booking_id' => (int) $b['id'] ] );
        self::tidy_session( (string) $b['sess'] );
    }

    /**
     * Lets go of holds that ran out (a shopper who left, an order never paid), and
     * declines requests the owner didn't answer in time.
     */
    public static function expire_holds(): void {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::bookings_table() . " WHERE status IN ('held','pending','requested') AND hold_until IS NOT NULL AND hold_until < %s LIMIT 200", self::now() ), ARRAY_A );
        foreach ( $rows as $b ) {
            if ( $b['status'] === 'requested' ) {
                self::decline( (int) $b['id'], self::l( 'Not answered in time', 'לא נענה בזמן' ), 'expired' );
                continue;
            }
            self::release_units( $b );
            $wpdb->update( self::bookings_table(), [ 'status' => 'expired', 'hold_until' => null, 'updated' => self::now() ], [ 'id' => (int) $b['id'] ] );
        }
    }

    /* ---------------- bookings ---------------- */

    public static function booking( int $id ): ?array {
        global $wpdb;
        $row = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::bookings_table() . ' WHERE id = %d', $id ), ARRAY_A ) : null;
        return $row ?: null;
    }

    public static function booking_by_hold( string $token ): ?array {
        global $wpdb;
        if ( ! preg_match( '/^[a-f0-9]{40}$/', $token ) ) return null;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::bookings_table() . ' WHERE hold_token = %s', $token ), ARRAY_A );
        return $row ?: null;
    }

    private static function log( int $id, string $type, string $note = '' ): void {
        global $wpdb;
        $b   = self::booking( $id );
        if ( ! $b ) return;
        $log = json_decode( (string) $b['log'], true );
        $log = is_array( $log ) ? $log : [];
        $log[] = [ 't' => time(), 'type' => $type, 'note' => mb_substr( $note, 0, 200 ) ];
        $wpdb->update( self::bookings_table(), [ 'log' => wp_json_encode( array_slice( $log, -40 ) ) ], [ 'id' => $id ] );
    }

    private static function ip_hash(): string {
        $ip = function_exists( 'qwoo_get_client_ip' ) ? (string) qwoo_get_client_ip() : '';
        return $ip !== '' ? substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 ) : '';
    }

    /**
     * Holds a time for a shopper (10 minutes). { hold, id, until, staff } or a WP_Error.
     * The time must be one of the service's free times right now. $staff: the person
     * chosen (0 = anyone: the least busy of those free then).
     */
    public static function hold( array $svc, int $start, int $seats = 1, int $staff = 0 ) {
        global $wpdb;
        $seats = max( 1, min( (int) $svc['max_seats'], $seats ) );
        if ( $svc['capacity'] <= 1 ) $seats = 1;
        $team = self::service_staff( $svc );
        if ( $team && ! $staff && empty( $svc['anyone'] ) ) return new WP_Error( 'staff', self::l( 'Choose who you\'d like to book with.', 'צריך לבחור עם מי לקבוע.' ), [ 'status' => 400 ] );
        $ip = self::ip_hash();
        if ( $ip !== '' ) {
            $open = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::bookings_table() . " WHERE ip_hash = %s AND status = 'held' AND hold_until > %s", $ip, self::now() ) );
            if ( $open >= 3 ) return new WP_Error( 'holds', self::l( 'You already have times waiting. Finish one of them first.', 'יש לך כבר זמנים שמחכים. כדאי לסיים אחד מהם קודם.' ), [ 'status' => 429 ] );
        }
        if ( ! self::lock() ) return new WP_Error( 'busy', self::l( 'Many people are booking right now. Please try again.', 'הרבה אנשים קובעים עכשיו. אפשר לנסות שוב.' ), [ 'status' => 503 ] );
        try {
            self::expire_holds();
            $free = self::free_staff( $svc, $start, [ 'seats' => $seats, 'staff' => $staff ] );
            if ( ! $free ) {
                return new WP_Error( 'taken', self::l( 'That time was just taken. Please pick another one.', 'הזמן הזה נתפס הרגע. אפשר לבחור זמן אחר.' ), [ 'status' => 409 ] );
            }
            $who   = $team ? ( $staff ?: self::pick_staff( $free, $start ) ) : 0;
            $token = bin2hex( random_bytes( 20 ) );
            $now   = self::now();
            $wpdb->insert( self::bookings_table(), [
                'service_id' => (int) $svc['id'], 'staff_id' => $who, 'sess' => self::sess_for( $svc, $start, $who ),
                'start_utc' => self::sql_time( $start ), 'end_utc' => self::sql_time( $start + $svc['duration'] * 60 ),
                'seats' => $seats, 'status' => 'held', 'hold_until' => self::sql_time( time() + self::HOLD_MIN * 60 ), 'hold_token' => $token,
                'ip_hash' => $ip, 'customer_id' => get_current_user_id(), 'price' => (float) $svc['price'] * $seats, 'due_now' => self::due_now( $svc ) * $seats,
                'currency' => get_woocommerce_currency(), 'place' => self::place_text( $svc ), 'created' => $now, 'updated' => $now,
            ] );
            $id = (int) $wpdb->insert_id;
            if ( ! $id || ! self::take_units( $id, $svc, $start, $seats, $who ) ) {
                if ( $id ) $wpdb->delete( self::bookings_table(), [ 'id' => $id ] );
                return new WP_Error( 'taken', self::l( 'That time was just taken. Please pick another one.', 'הזמן הזה נתפס הרגע. אפשר לבחור זמן אחר.' ), [ 'status' => 409 ] );
            }
            $person = $who ? self::staff_one( $who ) : null;
            return [ 'hold' => $token, 'id' => $id, 'until' => gmdate( 'c', time() + self::HOLD_MIN * 60 ), 'staff' => $person ? $person['name'] : '' ];
        } finally {
            self::unlock();
        }
    }

    /** The shopper changed their mind: the time goes back. */
    public static function release_hold( string $token ): void {
        global $wpdb;
        $b = self::booking_by_hold( $token );
        if ( ! $b || $b['status'] !== 'held' ) return;
        self::release_units( $b );
        $wpdb->update( self::bookings_table(), [ 'status' => 'expired', 'hold_until' => null, 'updated' => self::now() ], [ 'id' => (int) $b['id'] ] );
    }

    public static function extend_hold( int $id, int $minutes ): void {
        global $wpdb;
        $wpdb->update( self::bookings_table(), [ 'hold_until' => self::sql_time( time() + $minutes * 60 ), 'updated' => self::now() ], [ 'id' => $id ] );
    }

    /** The booking form's answers, cleaned against the service's questions. */
    public static function clean_details( array $svc, array $in ) {
        $name  = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 190 );
        $email = sanitize_email( (string) ( $in['email'] ?? '' ) );
        $phone = mb_substr( preg_replace( '/[^0-9+\-\s()]/', '', (string) ( $in['phone'] ?? '' ) ), 0, 40 );
        if ( $name === '' ) return new WP_Error( 'name', self::l( 'Please write your name.', 'צריך לכתוב שם.' ) );
        if ( ! is_email( $email ) ) return new WP_Error( 'email', self::l( 'Please write a valid email address.', 'צריך לכתוב כתובת מייל תקינה.' ) );
        if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 6 ) return new WP_Error( 'phone', self::l( 'Please write your phone number.', 'צריך לכתוב מספר טלפון.' ) );
        $given   = is_array( $in['answers'] ?? null ) ? $in['answers'] : [];
        $answers = [];
        foreach ( $svc['questions'] as $q ) {
            $v = $given[ $q['id'] ] ?? '';
            if ( $q['type'] === 'checkbox' ) $v = ! empty( $v ) ? self::l( 'Yes', 'כן' ) : '';
            else $v = $q['type'] === 'textarea' ? mb_substr( sanitize_textarea_field( (string) $v ), 0, 2000 ) : mb_substr( sanitize_text_field( (string) $v ), 0, 300 );
            if ( $q['type'] === 'choice' && $v !== '' && ! in_array( $v, $q['options'], true ) ) $v = '';
            if ( $q['required'] && $v === '' ) return new WP_Error( 'answer', sprintf( self::l( 'Please answer "%s".', 'צריך לענות על "%s".' ), $q['label'] ) );
            if ( $v !== '' ) $answers[] = [ 'label' => $q['label'], 'value' => $v ];
        }
        if ( $svc['place']['type'] === 'customer' ) {
            $address = mb_substr( sanitize_text_field( (string) ( $in['address'] ?? '' ) ), 0, 255 );
            if ( $address === '' ) return new WP_Error( 'address', self::l( 'Please write the address.', 'צריך לכתוב את הכתובת.' ) );
            $answers[] = [ 'label' => self::l( 'Address', 'כתובת' ), 'value' => $address, 'address' => true ];
        }
        $tz = (string) ( $in['tz'] ?? '' );
        return [
            'name' => $name, 'email' => $email, 'phone' => $phone, 'answers' => $answers,
            'lang' => preg_match( '/^[a-z]{2}(-[A-Za-z]{2})?$/', (string) ( $in['lang'] ?? '' ) ) ? (string) $in['lang'] : '',
            'tz'   => in_array( $tz, timezone_identifiers_list(), true ) ? $tz : '',
        ];
    }

    public static function save_details( int $id, array $d ): void {
        global $wpdb;
        $wpdb->update( self::bookings_table(), [
            'name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'], 'answers' => wp_json_encode( $d['answers'] ),
            'lang' => $d['lang'], 'tz' => $d['tz'], 'updated' => self::now(),
        ], [ 'id' => $id ] );
        foreach ( $d['answers'] as $a ) {
            if ( ! empty( $a['address'] ) ) $wpdb->update( self::bookings_table(), [ 'place' => mb_substr( $a['value'], 0, 255 ) ], [ 'id' => $id ] );
        }
    }

    /**
     * A held (or paid) booking becomes a booking: emails go out. A service the owner
     * approves becomes a request instead (the time stays held until they answer);
     * $approved: the owner said yes.
     */
    public static function confirm( int $id, int $order_id = 0, bool $approved = false ): bool {
        global $wpdb;
        $b = self::booking( $id );
        if ( ! $b ) return false;
        if ( $b['status'] === 'confirmed' ) return true;
        $svc = self::service( (int) $b['service_id'] );
        if ( $b['status'] === 'expired' || $b['status'] === 'cancelled' ) {
            // Paid after the hold ran out: take the time again if it's still free.
            if ( ! $svc || ! self::lock() ) return false;
            try {
                $ok = self::take_units( $id, $svc, self::ts( $b['start_utc'] ), (int) $b['seats'], (int) $b['staff_id'] );
            } finally {
                self::unlock();
            }
            if ( ! $ok ) return false;
        }
        $order = $order_id ?: (int) $b['order_id'];
        if ( ! $approved && $svc && $svc['approval'] === 'owner' && $b['source'] !== 'owner' ) {
            if ( $b['status'] === 'requested' ) return true;
            $until = min( self::ts( $b['start_utc'] ) - 15 * MINUTE_IN_SECONDS, time() + (int) $svc['approval_hours'] * HOUR_IN_SECONDS );
            $wpdb->update( self::bookings_table(), [ 'status' => 'requested', 'hold_until' => self::sql_time( max( time() + 15 * MINUTE_IN_SECONDS, $until ) ), 'hold_token' => '', 'order_id' => $order, 'updated' => self::now() ], [ 'id' => $id ] );
            self::log( $id, 'requested' );
            $b = self::booking( $id );
            self::send( 'booking_requested', $b );
            self::send( 'booking_request', $b );
            if ( class_exists( 'Qwoo_Owner_Alerts' ) && method_exists( 'Qwoo_Owner_Alerts', 'booking' ) ) {
                Qwoo_Owner_Alerts::booking( $id, (string) $b['name'], self::l( 'Waiting for you: ', 'מחכה לאישורך: ' ) . self::title_for( $b ) . ' · ' . wp_date( 'D j M, H:i', self::ts( $b['start_utc'] ) ) );
            }
            do_action( 'qwoo_booking_status', $b, 'requested' );
            return true;
        }
        $wpdb->update( self::bookings_table(), [ 'status' => 'confirmed', 'hold_until' => null, 'hold_token' => '', 'order_id' => $order, 'updated' => self::now() ], [ 'id' => $id ] );
        self::log( $id, $approved ? 'approved' : 'confirmed' );
        // Into the owner's calendar first: a Meet link made there goes into the confirmation.
        self::cal_sync( $id );
        $b = self::booking( $id );
        self::send( 'booking_confirmed', $b );
        if ( ! $approved ) {
            self::send( 'booking_new', $b );
            if ( class_exists( 'Qwoo_Owner_Alerts' ) && method_exists( 'Qwoo_Owner_Alerts', 'booking' ) ) {
                Qwoo_Owner_Alerts::booking( $id, (string) $b['name'], self::title_for( $b ) . ' · ' . wp_date( 'D j M, H:i', self::ts( $b['start_utc'] ) ) );
            }
        }
        do_action( 'qwoo_booking_status', $b, 'confirmed' );
        return true;
    }

    /** The owner says no to a request (or nobody answered in time): the time goes back, a payment is refunded. */
    public static function decline( int $id, string $why = '', string $by = 'owner' ) {
        global $wpdb;
        $b = self::booking( $id );
        if ( ! $b || $b['status'] !== 'requested' ) return new WP_Error( 'status', 'Only requests can be declined.' );
        self::release_units( $b );
        $wpdb->update( self::bookings_table(), [ 'status' => 'cancelled', 'hold_until' => null, 'updated' => self::now() ], [ 'id' => $id ] );
        self::log( $id, 'declined', $by . ( $why !== '' ? ': ' . $why : '' ) );
        self::refund_all( $b, self::l( 'Booking request declined', 'בקשת התור נדחתה' ) );
        $b = self::booking( $id );
        if ( $b['email'] !== '' ) self::send( 'booking_declined', $b, [ 'why' => $why ] );
        do_action( 'qwoo_booking_status', $b, 'declined' );
        return true;
    }

    /** The booking's whole payment back (a declined request). */
    private static function refund_all( array $b, string $reason ): void {
        $order = $b['order_id'] ? wc_get_order( (int) $b['order_id'] ) : null;
        if ( ! $order || ! $order->is_paid() ) return;
        $amount = 0.0;
        foreach ( $order->get_items() as $item ) {
            if ( (int) $item->get_meta( '_qwoo_booking' ) === (int) $b['id'] ) $amount += (float) $item->get_total() + (float) $item->get_total_tax();
        }
        $amount = min( $amount, (float) $order->get_remaining_refund_amount() );
        if ( $amount <= 0 ) return;
        $refund = wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => $amount, 'reason' => $reason, 'refund_payment' => true ] );
        if ( is_wp_error( $refund ) ) {
            $order->add_order_note( sprintf( self::l( 'The booking request was declined, but the refund didn\'t go through (%s). Refund it from the order.', 'בקשת התור נדחתה, אבל ההחזר לא עבר (%s). אפשר להחזיר מההזמנה.' ), $refund->get_error_message() ) );
        }
    }

    /** Cancels a booking (the shopper's link or the owner). */
    public static function cancel( int $id, string $by, bool $tell = true, string $why = '' ) {
        global $wpdb;
        $b = self::booking( $id );
        if ( ! $b ) return new WP_Error( 'missing', 'That booking doesn\'t exist any more.' );
        if ( in_array( $b['status'], [ 'cancelled', 'expired' ], true ) ) return true;
        self::release_units( $b );
        $wpdb->update( self::bookings_table(), [ 'status' => 'cancelled', 'hold_until' => null, 'updated' => self::now() ], [ 'id' => $id ] );
        self::log( $id, 'cancelled', $by . ( $why !== '' ? ': ' . $why : '' ) );
        self::cal_sync( $id );
        $was_on = in_array( $b['status'], [ 'confirmed', 'pending', 'requested' ], true );
        // A request the shopper takes back is refunded in full (the owner never said yes).
        if ( $b['status'] === 'requested' ) self::refund_all( $b, self::l( 'Booking request withdrawn', 'בקשת התור בוטלה' ) );
        elseif ( $was_on && $by === 'shopper' ) self::refund_on_cancel( $b );
        $b = self::booking( $id );
        if ( $was_on && $tell && $b['email'] !== '' ) self::send( 'booking_cancelled', $b, [ 'by' => $by ] );
        if ( $was_on && $by === 'shopper' ) self::send( 'booking_cancelled_team', $b );
        do_action( 'qwoo_booking_status', $b, 'cancelled' );
        return true;
    }

    /** The store's refund rule when a shopper cancels in time. */
    private static function refund_on_cancel( array $b ): void {
        $order = $b['order_id'] ? wc_get_order( (int) $b['order_id'] ) : null;
        if ( ! $order || ! $order->is_paid() ) return;
        $svc  = self::service( (int) $b['service_id'] );
        $rule = $svc ? (string) self::rule( $svc, 'refund' ) : self::settings()['rules']['refund'];
        if ( $rule !== 'full' ) {
            $order->add_order_note( self::l( 'The customer cancelled the booking. Your rule keeps the payment.', 'הלקוח ביטל את התור. לפי הכלל שלך התשלום נשאר.' ) );
            return;
        }
        self::refund_all( $b, self::l( 'Booking cancelled by the customer', 'התור בוטל על ידי הלקוח' ) );
    }

    /** Moves a booking to another free time (the owner may also give it to someone else on the team). */
    public static function move( int $id, int $start, string $by, bool $tell = true, int $to_staff = -1 ) {
        global $wpdb;
        $b   = self::booking( $id );
        $svc = $b ? self::service( (int) $b['service_id'] ) : null;
        if ( ! $b || ! $svc ) return new WP_Error( 'missing', 'That booking doesn\'t exist any more.' );
        if ( ! in_array( $b['status'], [ 'confirmed', 'pending', 'requested' ], true ) ) return new WP_Error( 'status', 'Only upcoming bookings can be moved.' );
        $staff = $by === 'owner' && $to_staff >= 0 && ( ! $to_staff || in_array( $to_staff, self::service_staff( $svc ), true ) ) ? $to_staff : (int) $b['staff_id'];
        if ( self::service_staff( $svc ) && ! $staff ) $staff = (int) $b['staff_id'];
        if ( ! self::lock() ) return new WP_Error( 'busy', self::l( 'Please try again in a moment.', 'אפשר לנסות שוב בעוד רגע.' ), [ 'status' => 503 ] );
        try {
            self::expire_holds();
            $opt = [ 'exclude' => $id, 'owner' => $by === 'owner', 'seats' => (int) $b['seats'], 'staff' => $staff ];
            if ( $b['sess'] !== '' && ! self::others_in( $b ) ) $opt['exclude_sess'] = $b['sess'];
            if ( ! self::is_free( $svc, $start, $opt ) ) return new WP_Error( 'taken', self::l( 'That time isn\'t free. Please pick another one.', 'הזמן הזה לא פנוי. אפשר לבחור זמן אחר.' ), [ 'status' => 409 ] );
            $old_start = self::ts( $b['start_utc'] );
            $old_staff = (int) $b['staff_id'];
            self::release_units( $b );
            if ( ! self::take_units( $id, $svc, $start, (int) $b['seats'], $staff ) ) {
                self::take_units( $id, $svc, $old_start, (int) $b['seats'], $old_staff );
                return new WP_Error( 'taken', self::l( 'That time was just taken. Please pick another one.', 'הזמן הזה נתפס הרגע. אפשר לבחור זמן אחר.' ), [ 'status' => 409 ] );
            }
            $wpdb->update( self::bookings_table(), [
                'start_utc' => self::sql_time( $start ), 'end_utc' => self::sql_time( $start + $svc['duration'] * 60 ), 'staff_id' => $staff,
                'sess' => self::sess_for( $svc, $start, $staff ), 'reminded' => 0, 'cal_flag' => '', 'cal_note' => '',
                'moves' => (int) $b['moves'] + ( $by === 'shopper' ? 1 : 0 ), 'updated' => self::now(),
            ], [ 'id' => $id ] );
        } finally {
            self::unlock();
        }
        self::log( $id, 'moved', $by . ': ' . wp_date( 'Y-m-d H:i', $old_start ) . ' → ' . wp_date( 'Y-m-d H:i', $start ) );
        // Another person's calendar: out of the old one, into the new one.
        if ( $old_staff !== $staff && $b['cal_event'] !== '' ) {
            $wpdb->update( self::bookings_table(), [ 'status' => 'cancelled' ], [ 'id' => $id ] );
            $wpdb->update( self::bookings_table(), [ 'staff_id' => $old_staff ], [ 'id' => $id ] );
            self::cal_sync( $id );
            $wpdb->update( self::bookings_table(), [ 'status' => $b['status'], 'staff_id' => $staff, 'cal_event' => '' ], [ 'id' => $id ] );
        }
        self::cal_sync( $id );
        $b = self::booking( $id );
        if ( $tell && $b['email'] !== '' ) self::send( 'booking_moved', $b );
        if ( $by === 'shopper' ) self::send( 'booking_new', $b, [ 'moved' => true ] );
        return true;
    }

    private static function others_in( array $b ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::slots_table() . ' WHERE resource = %s AND seat > 0 AND booking_id <> %d', $b['sess'], (int) $b['id'] ) ) > 0;
    }

    /** The owner adds a booking by hand (a phone call) or blocks time (for one person, or the store). */
    public static function owner_create( array $in ) {
        global $wpdb;
        $start = self::parse_start( $in['start'] ?? '' );
        if ( ! $start ) return new WP_Error( 'start', 'Choose a day and time.' );
        $now   = self::now();
        $staff = absint( $in['staff_id'] ?? 0 );
        if ( $staff && ! self::staff_one( $staff ) ) return new WP_Error( 'staff', 'That person isn\'t on your team any more.' );
        if ( ! empty( $in['block'] ) ) {
            $minutes = max( 5, min( 24 * 60, intdiv( (int) ( $in['minutes'] ?? 60 ), 5 ) * 5 ) );
            $busy    = [ 'id' => 0, 'capacity' => 1, 'duration' => $minutes, 'buffer_before' => 0, 'buffer_after' => 0 ];
            if ( ! self::lock() ) return new WP_Error( 'busy', 'Please try again in a moment.' );
            try {
                $wpdb->insert( self::bookings_table(), [ 'service_id' => 0, 'staff_id' => $staff, 'start_utc' => self::sql_time( $start ), 'end_utc' => self::sql_time( $start + $minutes * 60 ), 'status' => 'blocked', 'source' => 'owner', 'note' => mb_substr( sanitize_text_field( (string) ( $in['note'] ?? '' ) ), 0, 200 ), 'created' => $now, 'updated' => $now ] );
                $id = (int) $wpdb->insert_id;
                if ( ! self::take_units( $id, $busy, $start, 1, $staff ) ) {
                    $wpdb->delete( self::bookings_table(), [ 'id' => $id ] );
                    return new WP_Error( 'taken', 'Part of that time is already booked. Move or cancel those bookings first.' );
                }
            } finally {
                self::unlock();
            }
            self::cal_sync( $id );
            return self::owner_view( self::booking( $id ) );
        }
        $svc = self::service( absint( $in['service_id'] ?? 0 ) );
        if ( ! $svc ) return new WP_Error( 'service', 'Choose a service.' );
        $team = self::service_staff( $svc );
        if ( $team && $staff && ! in_array( $staff, $team, true ) ) return new WP_Error( 'staff', 'That person doesn\'t give this service.' );
        if ( ! $team ) $staff = 0;
        $details = self::clean_details( $svc, $in + [ 'email' => '' ] );
        // The owner may add someone without an email (a walk-in): only a name is needed then.
        if ( is_wp_error( $details ) && in_array( $details->get_error_code(), [ 'email', 'phone' ], true ) ) {
            $details = [ 'name' => mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 190 ), 'email' => is_email( $in['email'] ?? '' ) ? sanitize_email( $in['email'] ) : '', 'phone' => mb_substr( preg_replace( '/[^0-9+\-\s()]/', '', (string) ( $in['phone'] ?? '' ) ), 0, 40 ), 'answers' => [], 'lang' => '', 'tz' => '' ];
            if ( $details['name'] === '' ) return new WP_Error( 'name', 'Write the customer\'s name.' );
        } elseif ( is_wp_error( $details ) ) {
            return $details;
        }
        if ( ! self::lock() ) return new WP_Error( 'busy', 'Please try again in a moment.' );
        try {
            self::expire_holds();
            $seats = max( 1, min( (int) $svc['capacity'], (int) ( $in['seats'] ?? 1 ) ) );
            $free  = self::free_staff( $svc, $start, [ 'owner' => true, 'seats' => $seats, 'staff' => $staff ] );
            if ( ! $free ) return new WP_Error( 'taken', 'That time isn\'t free. Choose another time, or move what\'s there first.' );
            if ( $team && ! $staff ) $staff = self::pick_staff( $free, $start );
            $wpdb->insert( self::bookings_table(), [
                'service_id' => (int) $svc['id'], 'staff_id' => $staff, 'sess' => self::sess_for( $svc, $start, $staff ), 'start_utc' => self::sql_time( $start ), 'end_utc' => self::sql_time( $start + $svc['duration'] * 60 ),
                'seats' => $seats, 'status' => 'held', 'hold_until' => self::sql_time( time() + 300 ), 'price' => (float) $svc['price'] * $seats, 'currency' => get_woocommerce_currency(),
                'place' => self::place_text( $svc ), 'source' => 'owner', 'created' => $now, 'updated' => $now,
            ] );
            $id = (int) $wpdb->insert_id;
            if ( ! self::take_units( $id, $svc, $start, $seats, $staff ) ) {
                $wpdb->delete( self::bookings_table(), [ 'id' => $id ] );
                return new WP_Error( 'taken', 'That time was just taken.' );
            }
        } finally {
            self::unlock();
        }
        self::save_details( $id, $details );
        $user = $details['email'] !== '' ? get_user_by( 'email', $details['email'] ) : null;
        if ( $user ) $wpdb->update( self::bookings_table(), [ 'customer_id' => (int) $user->ID ], [ 'id' => $id ] );
        $wpdb->update( self::bookings_table(), [ 'status' => 'confirmed', 'hold_until' => null, 'note' => mb_substr( sanitize_textarea_field( (string) ( $in['note'] ?? '' ) ), 0, 2000 ), 'updated' => self::now() ], [ 'id' => $id ] );
        self::log( $id, 'created', 'owner' );
        self::cal_sync( $id );
        $b = self::booking( $id );
        if ( ! empty( $in['tell'] ) && $b['email'] !== '' ) self::send( 'booking_confirmed', $b );
        do_action( 'qwoo_booking_status', $b, 'confirmed' );
        return self::owner_view( $b );
    }

    public static function parse_start( $v ): int {
        $v = is_scalar( $v ) ? trim( (string) $v ) : '';
        if ( $v === '' ) return 0;
        // "2026-11-18T10:00" without a zone = the store's time; with Z / an offset = that moment.
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $v ) ) {
            $d = date_create_immutable( str_replace( 'T', ' ', $v ), wp_timezone() );
        } else {
            $d = date_create_immutable( $v );
        }
        if ( ! $d ) return 0;
        $ts = $d->getTimestamp();
        return $ts % 60 === 0 ? $ts : 0;
    }

    /* ---------------- words ---------------- */

    public static function place_text( array $svc ): string {
        $p = $svc['place'];
        switch ( $p['type'] ) {
            case 'phone':    return $p['note'] !== '' ? $p['note'] : self::l( 'Phone call', 'שיחת טלפון' );
            case 'video':    return self::l( 'Video call', 'שיחת וידאו' );
            case 'customer': return self::l( 'At your address', 'בכתובת שלך' );
        }
        if ( $p['address'] !== '' ) return $p['address'];
        $parts = array_filter( [ get_option( 'woocommerce_store_address' ), get_option( 'woocommerce_store_city' ) ] );
        return implode( ', ', $parts );
    }

    public static function when_text( array $b, string $tz = '' ): string {
        $ts   = self::ts( $b['start_utc'] );
        $zone = $tz !== '' && in_array( $tz, timezone_identifiers_list(), true ) ? new DateTimeZone( $tz ) : wp_timezone();
        return wp_date( 'l, j F Y', $ts, $zone ) . ' · ' . wp_date( 'H:i', $ts, $zone );
    }

    /* ---------------- links ---------------- */

    public static function key( int $id, string $email ): string {
        return substr( hash_hmac( 'sha256', $id . '|' . strtolower( $email ), wp_salt( 'auth' ) . 'qwoo-appt' ), 0, 40 );
    }

    public static function check_key( array $b, string $k ): bool {
        return $b['email'] !== '' && preg_match( '/^[a-f0-9]{40}$/', $k ) && hash_equals( self::key( (int) $b['id'], (string) $b['email'] ), $k );
    }

    private static function front(): string {
        return class_exists( 'Qwoo_Technical_Settings' ) ? rtrim( (string) Qwoo_Technical_Settings::get_primary_frontend_domain(), '/' ) : rtrim( home_url(), '/' );
    }

    public static function manage_url( array $b ): string {
        return self::front() . '/booking/' . (int) $b['id'] . '?k=' . self::key( (int) $b['id'], (string) $b['email'] );
    }

    public static function feed_url(): string {
        return self::front() . '/wp-json/qwoo/v1/appointments/feed?k=' . self::settings()['feed'];
    }

    private static function title_for( array $b ): string {
        $svc = self::service( (int) $b['service_id'] );
        return $svc ? $svc['name'] : self::l( 'Booking', 'תור' );
    }

    /** Google Calendar's "add" link for the shopper. */
    public static function google_link( array $b ): string {
        $fmt = static fn( $sql ) => gmdate( 'Ymd\THis\Z', self::ts( $sql ) );
        return 'https://calendar.google.com/calendar/render?' . http_build_query( [
            'action'   => 'TEMPLATE',
            'text'     => self::title_for( $b ) . ' · ' . html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            'dates'    => $fmt( $b['start_utc'] ) . '/' . $fmt( $b['end_utc'] ),
            'details'  => self::manage_url( $b ),
            'location' => (string) $b['place'],
        ], '', '&', PHP_QUERY_RFC3986 );
    }

    public static function outlook_link( array $b ): string {
        $fmt = static fn( $sql ) => gmdate( 'Y-m-d\TH:i:s\Z', self::ts( $sql ) );
        return 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query( [
            'subject' => self::title_for( $b ) . ' · ' . html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            'startdt' => $fmt( $b['start_utc'] ), 'enddt' => $fmt( $b['end_utc'] ),
            'body' => self::manage_url( $b ), 'location' => (string) $b['place'], 'path' => '/calendar/action/compose', 'rru' => 'addevent',
        ], '', '&', PHP_QUERY_RFC3986 );
    }

    /* ---------------- iCal ---------------- */

    private static function ics_text( string $v ): string {
        $v = str_replace( [ '\\', ';', ',', "\r\n", "\n" ], [ '\\\\', '\;', '\,', '\n', '\n' ], $v );
        return $v;
    }

    private static function ics_fold( string $line ): string {
        $out = '';
        while ( strlen( $line ) > 74 ) {
            $cut = 74;
            while ( $cut > 1 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) $cut--; // never split a UTF-8 letter
            $out .= substr( $line, 0, $cut ) . "\r\n ";
            $line = substr( $line, $cut );
        }
        return $out . $line;
    }

    private static function ics_event( array $b, bool $full, string $method = '' ): array {
        $host = wp_parse_url( self::front(), PHP_URL_HOST ) ?: 'store';
        $name = self::title_for( $b );
        $lines = [
            'BEGIN:VEVENT',
            'UID:booking-' . (int) $b['id'] . '@' . $host,
            'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
            'DTSTART:' . gmdate( 'Ymd\THis\Z', self::ts( $b['start_utc'] ) ),
            'DTEND:' . gmdate( 'Ymd\THis\Z', self::ts( $b['end_utc'] ) ),
            'SEQUENCE:' . max( 0, intdiv( self::ts( $b['updated'] ) - self::ts( $b['created'] ), 60 ) ),
        ];
        if ( $b['status'] === 'blocked' ) {
            $lines[] = 'SUMMARY:' . self::ics_text( self::l( 'Busy', 'תפוס' ) . ( $b['note'] ? ' · ' . $b['note'] : '' ) );
        } elseif ( $method !== '' ) {
            // The shopper's own copy.
            $lines[] = 'SUMMARY:' . self::ics_text( $name . ' · ' . html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
            $lines[] = 'DESCRIPTION:' . self::ics_text( self::manage_url( $b ) );
            if ( $b['place'] !== '' ) $lines[] = 'LOCATION:' . self::ics_text( (string) $b['place'] );
            $lines[] = 'STATUS:' . ( $b['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED' );
        } else {
            $first = trim( explode( ' ', (string) $b['name'] )[0] ?? '' );
            $lines[] = 'SUMMARY:' . self::ics_text( $name . ( $full ? ' · ' . $b['name'] : ( $first !== '' ? ' · ' . $first : '' ) ) );
            if ( $full ) {
                $desc = array_filter( [ $b['phone'], $b['email'] ] );
                foreach ( (array) json_decode( (string) $b['answers'], true ) as $a ) $desc[] = ( $a['label'] ?? '' ) . ': ' . ( $a['value'] ?? '' );
                if ( $b['note'] ) $desc[] = $b['note'];
                $lines[] = 'DESCRIPTION:' . self::ics_text( implode( "\n", $desc ) );
            }
            if ( $b['place'] !== '' && $full ) $lines[] = 'LOCATION:' . self::ics_text( (string) $b['place'] );
        }
        $lines[] = 'END:VEVENT';
        return $lines;
    }

    private static function ics_wrap( array $events, string $method = '' ): string {
        $lines = array_merge( [ 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Openly//Appointments//EN', 'CALSCALE:GREGORIAN' ], $method !== '' ? [ 'METHOD:' . $method ] : [ 'X-WR-CALNAME:' . self::ics_text( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) ], $events, [ 'END:VCALENDAR' ] );
        return implode( "\r\n", array_map( [ __CLASS__, 'ics_fold' ], $lines ) ) . "\r\n";
    }

    /** One booking as a calendar file (the email's attachment, the "Apple / other" link). */
    public static function ics_for( array $b ): string {
        return self::ics_wrap( self::ics_event( $b, false, 'PUBLISH' ), 'PUBLISH' );
    }

    /** The owner's subscribe link: bookings from 30 days back to a year ahead. */
    public static function feed( string $k ): ?string {
        $s = self::settings();
        if ( ! preg_match( '/^[a-f0-9]{48}$/', $k ) || ! hash_equals( $s['feed'], $k ) ) return null;
        global $wpdb;
        $full = ! empty( get_option( self::OPTION, [] )['feed_full'] );
        $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::bookings_table() . " WHERE status IN ('confirmed','pending','done','blocked') AND start_utc >= %s AND start_utc < %s ORDER BY start_utc LIMIT 3000", self::sql_time( time() - 30 * DAY_IN_SECONDS ), self::sql_time( time() + 366 * DAY_IN_SECONDS ) ), ARRAY_A );
        $events = [];
        foreach ( $rows as $b ) $events = array_merge( $events, self::ics_event( $b, $full ) );
        return self::ics_wrap( $events );
    }

    /* ---------------- emails ---------------- */

    /** Store emails (Qwoo_Store_Emails): their tokens and the booking's card. */
    public static function email_args( array $b ): array {
        $svc = self::service( (int) $b['service_id'] );
        $ts  = self::ts( $b['start_utc'] );
        $first = trim( explode( ' ', (string) $b['name'] )[0] ?? '' );
        return [
            'first_name'     => $first,
            'service_name'   => $svc ? $svc['name'] : '',
            'booking_date'   => wp_date( 'l, j F Y', $ts ),
            'booking_time'   => wp_date( 'H:i', $ts ),
            'booking_place'  => (string) $b['place'],
            'customer_name'  => (string) $b['name'],
            'booking_url'    => self::manage_url( $b ),
            'calendar_url'   => self::google_link( $b ),
            'owner_url'      => self::owner_link( (int) $b['id'] ),
        ];
    }

    private static function owner_link( int $id ): string {
        $platform = get_option( 'qwoo_platform_connection', [] );
        $base     = is_array( $platform ) ? rtrim( (string) ( $platform['platform_url'] ?? '' ), '/' ) : '';
        return $base !== '' ? $base . '/app/appointments/' . $id : '';
    }

    /** What the booking_details email block shows. */
    public static function email_card( array $b ): array {
        $svc     = self::service( (int) $b['service_id'] );
        $ts      = self::ts( $b['start_utc'] );
        $minutes = (int) round( ( self::ts( $b['end_utc'] ) - $ts ) / 60 );
        $rows    = [];
        $rows[]  = [ 'k' => self::l( 'Service', 'שירות' ), 'v' => $svc ? $svc['name'] : '' ];
        $rows[]  = [ 'k' => self::l( 'When', 'מתי' ), 'v' => wp_date( 'l, j F Y', $ts ) . ' · ' . wp_date( 'H:i', $ts ) . ' (' . sprintf( self::l( '%d minutes', '%d דקות' ), $minutes ) . ')' ];
        if ( $b['tz'] !== '' && $b['tz'] !== wp_timezone_string() && in_array( $b['tz'], timezone_identifiers_list(), true ) ) {
            $rows[] = [ 'k' => self::l( 'Your time', 'אצלך' ), 'v' => wp_date( 'l, j F · H:i', $ts, new DateTimeZone( $b['tz'] ) ) ];
        }
        $person = self::staff_one( (int) $b['staff_id'] );
        if ( $person ) $rows[] = [ 'k' => self::l( 'With', 'עם' ), 'v' => $person['name'] ];
        if ( (int) $b['seats'] > 1 ) $rows[] = [ 'k' => self::l( 'Places', 'מקומות' ), 'v' => (string) (int) $b['seats'] ];
        if ( $b['place'] !== '' ) $rows[] = [ 'k' => self::l( 'Where', 'איפה' ), 'v' => (string) $b['place'] ];
        $video = $b['video'] !== '' ? (string) $b['video'] : ( $svc ? (string) $svc['place']['link'] : '' );
        if ( $svc && $svc['place']['type'] === 'video' && $video !== '' && $b['status'] === 'confirmed' ) $rows[] = [ 'k' => self::l( 'Video link', 'קישור לשיחה' ), 'v' => $video, 'link' => true ];
        if ( (float) $b['due_now'] > 0 && $b['order_id'] ) $rows[] = [ 'k' => self::l( 'Paid', 'שולם' ), 'v' => html_entity_decode( wp_strip_all_tags( wc_price( (float) $b['due_now'], [ 'currency' => $b['currency'] ] ) ), ENT_QUOTES ) ];
        $rest = (float) $b['price'] - ( $b['order_id'] ? (float) $b['due_now'] : 0 );
        if ( $rest > 0 && $svc && $svc['pay'] !== 'free' ) $rows[] = [ 'k' => self::l( 'To pay there', 'לתשלום במקום' ), 'v' => html_entity_decode( wp_strip_all_tags( wc_price( $rest, [ 'currency' => $b['currency'] ] ) ), ENT_QUOTES ) ];
        return [ 'rows' => $rows, 'google' => self::google_link( $b ), 'outlook' => self::outlook_link( $b ), 'manage' => self::manage_url( $b ), 'labels' => [ 'google' => 'Google', 'outlook' => 'Outlook', 'manage' => self::l( 'Move or cancel', 'הזזה או ביטול' ) ] ];
    }

    /** The subject a booking email has when the owner didn't write one. */
    public static function default_subject( string $id, bool $moved = false ): string {
        switch ( $id ) {
            case 'booking_confirmed':      return self::l( 'Your booking at {store_name}: {booking_date}', 'התור שלך ב{store_name}: {booking_date}' );
            case 'booking_reminder':       return self::l( 'Reminder: {service_name} on {booking_date} at {booking_time}', 'תזכורת: {service_name} ב{booking_date} בשעה {booking_time}' );
            case 'booking_moved':          return self::l( 'Your booking moved to {booking_date} at {booking_time}', 'התור שלך הוזז ל{booking_date} בשעה {booking_time}' );
            case 'booking_cancelled':      return self::l( 'Your booking was cancelled', 'התור שלך בוטל' );
            case 'booking_requested':      return self::l( 'We got your request: {service_name}, {booking_date}', 'קיבלנו את הבקשה שלך: {service_name}, {booking_date}' );
            case 'booking_declined':       return self::l( 'About your request for {booking_date}', 'לגבי הבקשה שלך ל{booking_date}' );
            case 'booking_new':            return $moved ? self::l( 'Booking moved: {customer_name}, {booking_date} {booking_time}', 'תור הוזז: {customer_name}, {booking_date} {booking_time}' ) : self::l( 'New booking: {customer_name}, {booking_date} {booking_time}', 'תור חדש: {customer_name}, {booking_date} {booking_time}' );
            case 'booking_request':        return self::l( 'Waiting for you: {customer_name}, {booking_date} {booking_time}', 'מחכה לאישורך: {customer_name}, {booking_date} {booking_time}' );
            case 'booking_cancelled_team': return self::l( 'Booking cancelled: {customer_name}, {booking_date} {booking_time}', 'תור בוטל: {customer_name}, {booking_date} {booking_time}' );
        }
        return '{store_name}';
    }

    const TEAM_EMAILS = [ 'booking_new', 'booking_request', 'booking_cancelled_team' ];

    /** Sends one of the booking emails (store emails catalog). Team emails also go to the staff member it's with. */
    public static function send( string $id, array $b, array $extra = [] ): void {
        if ( ! class_exists( 'Qwoo_Store_Emails' ) ) return;
        $team = in_array( $id, self::TEAM_EMAILS, true );
        if ( $team ) {
            $list = array_filter( (array) ( Qwoo_Store_Emails::saved( $id )['to'] ?? [] ), 'is_email' );
            $to   = $list ?: [ self::settings()['notify_to'] ?: (string) get_option( 'admin_email' ) ];
            $p    = self::staff_one( (int) $b['staff_id'] );
            if ( $p && $p['notify'] && is_email( $p['email'] ) ) $to[] = $p['email'];
            $to = implode( ',', array_unique( array_map( 'strtolower', $to ) ) );
        } else {
            $to = (string) $b['email'];
            if ( ! is_email( $to ) ) return;
        }
        $args     = self::email_args( $b ) + [ 'booking' => self::email_card( $b ), 'team' => $team ];
        $defaults = [ $id => self::default_subject( $id, ! empty( $extra['moved'] ) ) ];
        $attach = [];
        $file   = '';
        if ( ! $team && in_array( $id, [ 'booking_confirmed', 'booking_moved', 'booking_reminder' ], true ) ) {
            $file = trailingslashit( get_temp_dir() ) . 'booking-' . wp_generate_password( 12, false ) . '.ics';
            if ( @file_put_contents( $file, self::ics_for( $b ) ) !== false ) $attach[] = $file; // phpcs:ignore
        }
        try {
            Qwoo_Store_Emails::send_own( $id, $to, $defaults[ $id ] ?? '{store_name}', $args, null, false, $attach );
        } catch ( \Throwable $e ) {
            error_log( '[qwoo appointments] email ' . $id . ': ' . $e->getMessage() ); // phpcs:ignore
        }
        if ( $file !== '' && file_exists( $file ) ) @unlink( $file ); // phpcs:ignore
        self::log( (int) $b['id'], 'email', $id );
    }

    /* ---------------- reminders (the tick chain) ---------------- */

    public static function tick( float $until ) {
        self::expire_holds();
        if ( ! self::plan_allows() ) return;
        self::cal_retry( $until );
        global $wpdb;
        $s    = self::settings()['reminders'];
        $jobs = [];
        if ( $s['day'] ) $jobs[] = [ 1, 24 * 60 ];
        if ( $s['hours2'] ) $jobs[] = [ 2, 120 ];
        foreach ( $jobs as [ $bit, $minutes ] ) {
            $rows = (array) $wpdb->get_results( $wpdb->prepare(
                'SELECT * FROM ' . self::bookings_table() . " WHERE status = 'confirmed' AND email <> '' AND (reminded & %d) = 0 AND start_utc > %s AND start_utc <= %s ORDER BY start_utc LIMIT 30",
                $bit, self::now(), self::sql_time( time() + $minutes * 60 )
            ), ARRAY_A );
            foreach ( $rows as $b ) {
                if ( microtime( true ) > $until ) return;
                // Booked a moment ago for the same day: the confirmation is enough for the day-before one.
                $fresh = self::ts( $b['created'] ) > self::ts( $b['start_utc'] ) - $minutes * 60;
                $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::bookings_table() . ' SET reminded = reminded | %d WHERE id = %d', $bit, (int) $b['id'] ) );
                if ( ! ( $fresh && $bit === 1 ) ) self::send( 'booking_reminder', $b );
            }
        }
        // Yesterday's bookings are done.
        $done = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::bookings_table() . " WHERE status = 'confirmed' AND end_utc < %s LIMIT 100", self::sql_time( time() - 6 * HOUR_IN_SECONDS ) ), ARRAY_A );
        foreach ( $done as $b ) {
            $wpdb->update( self::bookings_table(), [ 'status' => 'done' ], [ 'id' => (int) $b['id'] ] );
            do_action( 'qwoo_booking_status', array_merge( $b, [ 'status' => 'done' ] ), 'done' );
        }
    }

    public static function next_due( $due ) {
        global $wpdb;
        $s     = self::settings()['reminders'];
        $cands = [];
        foreach ( [ [ 'day', 1, DAY_IN_SECONDS ], [ 'hours2', 2, 2 * HOUR_IN_SECONDS ] ] as [ $key, $bit, $before ] ) {
            if ( ! $s[ $key ] ) continue;
            $first = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(start_utc) FROM ' . self::bookings_table() . " WHERE status = 'confirmed' AND email <> '' AND (reminded & %d) = 0 AND start_utc > %s", $bit, self::now() ) );
            if ( $first ) $cands[] = max( time(), self::ts( $first ) - $before );
        }
        $hold = $wpdb->get_var( 'SELECT MIN(hold_until) FROM ' . self::bookings_table() . " WHERE status IN ('held','pending','requested') AND hold_until IS NOT NULL" );
        // Bookings that didn't reach a calendar yet: another try in 5 minutes.
        if ( $wpdb->get_var( 'SELECT id FROM ' . self::bookings_table() . " WHERE cal_state = 'retry' AND cal_tries < 5 LIMIT 1" ) ) $cands[] = time() + 5 * MINUTE_IN_SECONDS;
        if ( $hold ) $cands[] = self::ts( $hold );
        if ( ! $cands ) return $due;
        $unix = min( $cands );
        return $due && $due < $unix ? $due : $unix;
    }

    /* ---------------- the dashboard ---------------- */

    public static function owner_view( ?array $b ): ?array {
        if ( ! $b ) return null;
        $svc    = self::service( (int) $b['service_id'] );
        $person = self::staff_one( (int) $b['staff_id'] );
        $log    = json_decode( (string) $b['log'], true );
        return [
            'id' => (int) $b['id'], 'service_id' => (int) $b['service_id'], 'service' => $svc ? $svc['name'] : '',
            'staff_id' => (int) $b['staff_id'], 'staff' => $person ? $person['name'] : '',
            'start' => gmdate( 'Y-m-d\TH:i:s\Z', self::ts( $b['start_utc'] ) ), 'end' => gmdate( 'Y-m-d\TH:i:s\Z', self::ts( $b['end_utc'] ) ),
            'local' => wp_date( 'Y-m-d H:i', self::ts( $b['start_utc'] ) ), 'local_end' => wp_date( 'Y-m-d H:i', self::ts( $b['end_utc'] ) ),
            'status' => (string) $b['status'], 'seats' => (int) $b['seats'], 'name' => (string) $b['name'], 'email' => (string) $b['email'], 'phone' => (string) $b['phone'],
            'answers' => (array) ( json_decode( (string) $b['answers'], true ) ?: [] ), 'place' => (string) $b['place'],
            'price' => (float) $b['price'], 'due_now' => (float) $b['due_now'], 'currency' => (string) $b['currency'], 'order_id' => (int) $b['order_id'],
            'customer_id' => (int) $b['customer_id'], 'source' => (string) $b['source'], 'note' => (string) $b['note'], 'moves' => (int) $b['moves'],
            'video' => (string) $b['video'], 'cal_state' => (string) $b['cal_state'], 'cal_flag' => (string) $b['cal_flag'], 'cal_note' => (string) $b['cal_note'], 'in_calendar' => $b['cal_event'] !== '',
            'hold_until' => $b['hold_until'] ? gmdate( 'c', self::ts( $b['hold_until'] ) ) : '', 'created' => gmdate( 'c', self::ts( $b['created'] ) ),
            'log' => is_array( $log ) ? array_map( static fn( $e ) => [ 'at' => gmdate( 'c', (int) $e['t'] ), 'type' => (string) $e['type'], 'note' => (string) $e['note'] ], $log ) : [],
        ];
    }

    private static function service_view( array $svc ): array {
        return $svc + [ 'image_url' => $svc['image'] ? (string) wp_get_attachment_image_url( $svc['image'], 'medium' ) : '', 'due_now' => self::due_now( $svc ) ];
    }

    /** $only: someone who sees only their own bookings (a staff id, from the platform's People access). */
    public static function dashboard_state( int $only = 0 ): array {
        global $wpdb;
        self::expire_holds();
        $s     = self::settings();
        $now   = self::now();
        $week  = self::sql_time( time() + 7 * DAY_IN_SECONDS );
        $mine  = $only ? $wpdb->prepare( ' AND staff_id = %d', $only ) : '';
        $count = static fn( string $where, ...$args ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::bookings_table() . ' WHERE ' . $where . $mine, ...$args ) );
        $staff = array_values( array_map( [ __CLASS__, 'staff_view' ], self::staff_list() ) );
        return [
            'allowed'   => self::plan_allows(),
            'only'      => $only,
            'settings'  => array_diff_key( $s, [ 'feed' => 1 ] ) + [ 'feed_full' => ! empty( get_option( self::OPTION, [] )['feed_full'] ) ],
            'services'  => array_map( [ __CLASS__, 'service_view' ], self::services() ),
            'staff'     => $only ? array_values( array_filter( $staff, static fn( $p ) => $p['id'] === $only ) ) : $staff,
            'staff_limit' => self::staff_limit(),
            // For "Show in the shop as a product": the store's product categories.
            'categories' => $only ? [] : array_values( array_map( static fn( $t ) => [ 'id' => (int) $t->term_id, 'name' => html_entity_decode( $t->name, ENT_QUOTES ) ], (array) get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 200 ] ) ) ),
            'feed_url'  => $only ? '' : self::feed_url(),
            'calendar'  => self::calendar(),
            'flagged'   => $count( "cal_flag <> '' AND status IN ('confirmed','pending') AND start_utc > %s", $now ),
            'requests'  => $count( "status = 'requested' AND start_utc > %s", $now ),
            'tz'        => wp_timezone_string(),
            'tz_unset'  => in_array( (string) get_option( 'timezone_string' ), [ '', 'UTC' ], true ) && ! (float) get_option( 'gmt_offset' ),
            'currency'  => get_woocommerce_currency(),
            'country'   => WC()->countries ? WC()->countries->get_base_country() : '',
            'address'   => implode( ', ', array_filter( [ get_option( 'woocommerce_store_address' ), get_option( 'woocommerce_store_city' ) ] ) ),
            'counts'    => [
                'today'    => $count( "status IN ('confirmed','pending') AND start_utc >= %s AND start_utc < %s", self::sql_time( strtotime( 'today', time() ) ), self::sql_time( strtotime( 'tomorrow', time() ) ) ),
                'week'     => $count( "status IN ('confirmed','pending') AND start_utc >= %s AND start_utc < %s", $now, $week ),
                'upcoming' => $count( "status IN ('confirmed','pending') AND start_utc >= %s", $now ),
            ],
        ];
    }

    /** Bookings between two moments (the calendar), or a list; one person's with staff. */
    public static function listing( array $p, int $only = 0 ): array {
        global $wpdb;
        self::expire_holds();
        $from = self::parse_start( $p['from'] ?? '' ) ?: strtotime( 'today' );
        $to   = self::parse_start( $p['to'] ?? '' ) ?: $from + 7 * DAY_IN_SECONDS;
        $to   = min( $to, $from + 100 * DAY_IN_SECONDS );
        $where = 'start_utc >= %s AND start_utc < %s';
        $args  = [ self::sql_time( $from ), self::sql_time( $to ) ];
        $status = (string) ( $p['status'] ?? '' );
        if ( $status === 'all' ) {
            $where .= " AND status NOT IN ('held','expired')";
        } elseif ( in_array( $status, [ 'confirmed', 'cancelled', 'done', 'noshow', 'pending', 'requested' ], true ) ) {
            $where .= ' AND status = %s';
            $args[] = $status;
        } else {
            $where .= " AND status IN ('confirmed','pending','requested','done','noshow','blocked','held')";
        }
        $staff = $only ?: absint( $p['staff'] ?? 0 );
        if ( $staff ) {
            $where .= ' AND staff_id = %d';
            $args[] = $staff;
        }
        $search = mb_substr( sanitize_text_field( (string) ( $p['search'] ?? '' ) ), 0, 100 );
        if ( $search !== '' ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= ' AND (name LIKE %s OR email LIKE %s OR phone LIKE %s)';
            array_push( $args, $like, $like, $like );
        }
        $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::bookings_table() . " WHERE $where ORDER BY start_utc ASC LIMIT 1000", $args ), ARRAY_A );
        return [ 'items' => array_values( array_map( [ __CLASS__, 'owner_view' ], $rows ) ), 'from' => gmdate( 'c', $from ), 'to' => gmdate( 'c', $to ) ];
    }

    public static function owner_update( array $p, int $only = 0 ) {
        global $wpdb;
        $id = absint( $p['id'] ?? 0 );
        $b  = self::booking( $id );
        if ( ! $b || ( $only && (int) $b['staff_id'] !== $only ) ) return new WP_Error( 'missing', 'That booking doesn\'t exist any more.' );
        $tell = ! empty( $p['tell'] );
        switch ( (string) ( $p['action'] ?? '' ) ) {
            case 'approve':
                if ( $b['status'] !== 'requested' ) return new WP_Error( 'status', 'This booking isn\'t waiting for you.' );
                $r = self::confirm( $id, 0, true ) ? true : new WP_Error( 'taken', 'That time isn\'t free any more.' );
                break;
            case 'decline':
                $r = self::decline( $id, mb_substr( sanitize_text_field( (string) ( $p['why'] ?? '' ) ), 0, 200 ) );
                break;
            case 'cancel':
                if ( $b['status'] === 'blocked' ) {
                    self::release_units( $b );
                    // Out of the calendar too, then gone.
                    $wpdb->update( self::bookings_table(), [ 'status' => 'cancelled' ], [ 'id' => $id ] );
                    self::cal_sync( $id );
                    $wpdb->delete( self::bookings_table(), [ 'id' => $id ] );
                    return [ 'deleted' => true ];
                }
                $r = self::cancel( $id, 'owner', $tell, mb_substr( sanitize_text_field( (string) ( $p['why'] ?? '' ) ), 0, 200 ) );
                break;
            case 'move':
                $to = $only ? -1 : ( array_key_exists( 'staff_id', $p ) ? absint( $p['staff_id'] ) : -1 );
                $r  = self::move( $id, self::parse_start( $p['start'] ?? '' ), 'owner', $tell, $to );
                break;
            case 'noshow':
            case 'done':
            case 'confirmed':
                if ( ! in_array( $b['status'], [ 'confirmed', 'done', 'noshow' ], true ) ) return new WP_Error( 'status', 'This booking can\'t be marked like that.' );
                $wpdb->update( self::bookings_table(), [ 'status' => $p['action'], 'updated' => self::now() ], [ 'id' => $id ] );
                self::log( $id, $p['action'] );
                do_action( 'qwoo_booking_status', self::booking( $id ), $p['action'] );
                $r = true;
                break;
            case 'note':
                $wpdb->update( self::bookings_table(), [ 'note' => mb_substr( sanitize_textarea_field( (string) ( $p['note'] ?? '' ) ), 0, 2000 ), 'updated' => self::now() ], [ 'id' => $id ] );
                $r = true;
                break;
            case 'unflag':
                // The owner looked at what changed in their calendar.
                $wpdb->update( self::bookings_table(), [ 'cal_flag' => '', 'cal_note' => '' ], [ 'id' => $id ] );
                $r = true;
                break;
            case 'cal_again':
                $wpdb->update( self::bookings_table(), [ 'cal_tries' => 0 ], [ 'id' => $id ] );
                self::cal_sync( $id );
                $r = true;
                break;
            case 'resend':
                if ( $b['email'] === '' ) return new WP_Error( 'email', 'This booking has no email address.' );
                self::send( 'booking_confirmed', $b );
                $r = true;
                break;
            default:
                return new WP_Error( 'action', 'Unknown action.' );
        }
        return is_wp_error( $r ) ? $r : self::owner_view( self::booking( $id ) );
    }

    public static function feed_full( bool $on ): array {
        $s = get_option( self::OPTION, [] );
        $s = is_array( $s ) ? $s : [];
        $s['feed_full'] = $on;
        update_option( self::OPTION, $s, false );
        return self::dashboard_state();
    }

    /**
     * Growth ops from the dashboard (Qwoo_Platform_Growth). $p['_staff'] is set by the
     * platform only (never the browser's) for someone the owner invited with "only their
     * own bookings": they see and change their own bookings and hours, nothing else.
     */
    public static function dashboard( string $op, array $p ) {
        $only = absint( $p['_staff'] ?? 0 );
        if ( $only ) {
            if ( ! self::staff_one( $only ) ) return new WP_Error( 'access', 'Your place on the team was removed. Ask the store owner.' );
            if ( ! in_array( $op, [ 'appt_state', 'appt_list', 'appt_get', 'appt_times', 'appt_create', 'appt_update', 'appt_staff_save', 'appt_insights' ], true ) ) {
                return new WP_Error( 'access', 'Only the store owner can change this.' );
            }
            $p['staff']    = $only;
            $p['staff_id'] = $only;
        }
        switch ( $op ) {
            case 'appt_state':
                return self::dashboard_state( $only );
            case 'appt_settings_save':
                return self::save_settings( $p );
            case 'appt_feed_full':
                return self::feed_full( ! empty( $p['on'] ) );
            case 'appt_service_save':
                return self::save_service( $p );
            case 'appt_service_delete':
                return self::delete_service( absint( $p['id'] ?? 0 ) );
            case 'appt_services_sort':
                return self::sort_services( (array) ( $p['ids'] ?? [] ) );
            case 'appt_staff_save':
                $saved = self::save_staff( $p, $only );
                return is_wp_error( $saved ) ? $saved : [ 'person' => $saved, 'state' => self::dashboard_state( $only ) ];
            case 'appt_staff_delete':
                $done = self::delete_staff( absint( $p['id'] ?? 0 ) );
                return is_wp_error( $done ) ? $done : self::dashboard_state();
            case 'appt_list':
                return self::listing( $p, $only );
            case 'appt_get':
                $row = self::booking( absint( $p['id'] ?? 0 ) );
                if ( $row && $only && (int) $row['staff_id'] !== $only ) $row = null;
                $b = self::owner_view( $row );
                return $b ?: new WP_Error( 'missing', 'That booking doesn\'t exist any more.' );
            case 'appt_times':
                $svc = self::service( absint( $p['service_id'] ?? 0 ) );
                if ( ! $svc ) return new WP_Error( 'service', 'Choose a service.' );
                $exclude = absint( $p['exclude'] ?? 0 );
                return [ 'days' => self::free_times( $svc, (string) ( $p['from'] ?? '' ), (string) ( $p['to'] ?? '' ), [ 'owner' => true, 'exclude' => $exclude, 'staff' => absint( $p['staff'] ?? 0 ) ] ) ];
            case 'appt_create':
                if ( ! self::plan_allows() ) return new WP_Error( 'plan', 'Appointments aren\'t part of this plan.' );
                return self::owner_create( $p );
            case 'appt_update':
                return self::owner_update( $p, $only );
            case 'appt_insights':
                return self::insights( (int) ( $p['days'] ?? 30 ), $only );
            case 'appt_cal_link':
                return self::cal_link( $p );
            case 'appt_cal_changed':
                return self::cal_changed( $p );
        }
        return new WP_Error( 'op', 'Unknown action.' );
    }

    /**
     * Appointments → Insights: the last $days days (bookings that started in them): how many,
     * cancelled, no-shows, takings (price of what took place or is confirmed), per service, per
     * person, per weekday and hour, and how many customers came back.
     */
    public static function insights( int $days, int $only = 0 ): array {
        global $wpdb;
        $days = in_array( $days, [ 7, 30, 90, 365 ], true ) ? $days : 30;
        $from = self::sql_time( time() - $days * DAY_IN_SECONDS );
        $to   = self::now();
        $mine = $only ? $wpdb->prepare( ' AND staff_id = %d', $only ) : '';
        $rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, service_id, staff_id, status, start_utc, price, email, seats FROM ' . self::bookings_table() . " WHERE start_utc >= %s AND start_utc < %s AND status IN ('confirmed','done','noshow','cancelled')" . $mine . ' LIMIT 20000', $from, $to ), ARRAY_A );
        $out  = [ 'days' => $days, 'total' => 0, 'done' => 0, 'cancelled' => 0, 'noshow' => 0, 'takings' => 0.0, 'currency' => get_woocommerce_currency(), 'services' => [], 'staff' => [], 'weekdays' => array_fill( 0, 7, 0 ), 'hours' => array_fill( 0, 24, 0 ), 'customers' => 0, 'returning' => 0 ];
        $people = [];
        foreach ( $rows as $r ) {
            $out['total']++;
            $st = (string) $r['status'];
            if ( $st === 'cancelled' ) { $out['cancelled']++; continue; }
            if ( $st === 'noshow' ) { $out['noshow']++; continue; }
            $out['done']++;
            $out['takings'] += (float) $r['price'];
            $sid = (int) $r['service_id'];
            $out['services'][ $sid ] = ( $out['services'][ $sid ] ?? 0 ) + 1;
            if ( (int) $r['staff_id'] ) $out['staff'][ (int) $r['staff_id'] ] = ( $out['staff'][ (int) $r['staff_id'] ] ?? 0 ) + 1;
            $ts = self::ts( $r['start_utc'] );
            $out['weekdays'][ (int) wp_date( 'w', $ts ) ]++;
            $out['hours'][ (int) wp_date( 'G', $ts ) ]++;
            if ( $r['email'] !== '' ) $people[ strtolower( $r['email'] ) ] = ( $people[ strtolower( $r['email'] ) ] ?? 0 ) + 1;
        }
        $out['customers'] = count( $people );
        $out['returning'] = count( array_filter( $people, static fn( $n ) => $n > 1 ) );
        $names = [];
        foreach ( self::services() as $s ) $names[ $s['id'] ] = $s['name'];
        $out['services'] = array_values( array_map( static fn( $id, $n ) => [ 'id' => $id, 'name' => $names[ $id ] ?? '—', 'count' => $n ], array_keys( $out['services'] ), $out['services'] ) );
        usort( $out['services'], static fn( $a, $b ) => $b['count'] <=> $a['count'] );
        $out['staff'] = array_values( array_map( static fn( $id, $n ) => [ 'id' => $id, 'name' => self::staff_one( $id )['name'] ?? '—', 'count' => $n ], array_keys( $out['staff'] ), $out['staff'] ) );
        usort( $out['staff'], static fn( $a, $b ) => $b['count'] <=> $a['count'] );
        $out['takings'] = round( $out['takings'], 2 );
        return $out;
    }

    /** A booking as webhooks and automation webhooks send it. */
    public static function webhook_data( array $b, string $status ): array {
        $svc    = self::service( (int) $b['service_id'] );
        $person = self::staff_one( (int) $b['staff_id'] );
        return [
            'id' => (int) $b['id'], 'status' => $status, 'service' => $svc ? $svc['name'] : '', 'service_id' => (int) $b['service_id'],
            'staff' => $person ? $person['name'] : '', 'staff_id' => (int) $b['staff_id'],
            'start' => gmdate( 'c', self::ts( $b['start_utc'] ) ), 'end' => gmdate( 'c', self::ts( $b['end_utc'] ) ), 'local_start' => wp_date( 'Y-m-d H:i', self::ts( $b['start_utc'] ) ),
            'seats' => (int) $b['seats'], 'name' => (string) $b['name'], 'email' => (string) $b['email'], 'phone' => (string) $b['phone'],
            'answers' => (array) ( json_decode( (string) $b['answers'], true ) ?: [] ), 'place' => (string) $b['place'],
            'price' => (float) $b['price'], 'paid_now' => (float) $b['due_now'], 'currency' => (string) $b['currency'], 'order_id' => (int) $b['order_id'],
        ];
    }

    /* ---------------- privacy ---------------- */

    public static function forget_user( $id, $reassign = null, $user = null ) {
        global $wpdb;
        $email = $user instanceof WP_User ? $user->user_email : '';
        $where = $email !== '' ? $wpdb->prepare( 'customer_id = %d OR email = %s', (int) $id, $email ) : $wpdb->prepare( 'customer_id = %d', (int) $id );
        $wpdb->query( 'UPDATE ' . self::bookings_table() . " SET name = '', email = '', phone = '', answers = NULL, customer_id = 0 WHERE $where" );
    }

    public static function register_eraser( $erasers ) {
        $erasers['qwoo-appointments'] = [ 'eraser_friendly_name' => 'Appointments', 'callback' => [ __CLASS__, 'erase_email' ] ];
        return $erasers;
    }

    public static function erase_email( $email, $page = 1 ) {
        global $wpdb;
        $n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::bookings_table() . " SET name = '', email = '', phone = '', answers = NULL, customer_id = 0 WHERE email = %s", (string) $email ) );
        return [ 'items_removed' => (bool) $n, 'items_retained' => false, 'messages' => [], 'done' => true ];
    }
}

Qwoo_Appointments::init();

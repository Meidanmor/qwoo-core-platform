<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Accessibility (dashboard Settings → Accessibility, 2026-11-23).
 *
 * - The storefront's accessibility tool: a button that opens display choices for the visitor
 *   (text size, spacing, contrast, stop motion…). On by default for every store (Israeli law asks
 *   for an accessible site and a statement; the tool sits on top of the storefront's real fixes).
 * - The accessibility statement: written live from the store's details and the owner's answers,
 *   served at qwoo/v1/site/a11y-statement and shown by the storefront at /accessibility-statement.
 *   It never goes stale (the coordinator, the name, the date follow the settings) and needs no publish.
 * - Report a problem: the tool's form opens an Inbox conversation (every plan: it's a legal
 *   channel) and emails the accessibility coordinator when they have their own address.
 *
 * Option `qwoo_a11y`: { on, place, color, size, phone, features {…}, coordinator { name, phone, email },
 * texts { lang: { gaps, physical, other_ways, extra } }, answer_days, updated }.
 * The storefront reads the tool's part from the live pointer (`a11y`): a change shows within seconds.
 */
class Qwoo_Accessibility {

    const OPTION   = 'qwoo_a11y';
    const NS       = 'qwoo/v1';
    const PLACES   = [ 'auto', 'start', 'end' ];
    const FEATURES = [ 'size', 'spacing', 'font', 'align', 'contrast', 'gray', 'links', 'headings', 'motion', 'cursor', 'reading', 'map', 'profiles' ];
    const TEXTS    = [ 'gaps', 'physical', 'other_ways', 'extra' ];
    const SLUG     = 'accessibility-statement';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    /** The saved settings, always clean (never saved: on, every feature, no coordinator). */
    public static function settings(): array {
        $s = get_option( self::OPTION, [] );
        $s = is_array( $s ) ? $s : [];
        $features = [];
        foreach ( self::FEATURES as $f ) $features[ $f ] = ! isset( $s['features'][ $f ] ) || ! empty( $s['features'][ $f ] );
        $coord = is_array( $s['coordinator'] ?? null ) ? $s['coordinator'] : [];
        $texts = [];
        foreach ( (array) ( $s['texts'] ?? [] ) as $lang => $t ) {
            if ( ! isset( Qwoo_Store_Language::LANGS[ $lang ] ) || ! is_array( $t ) ) continue;
            foreach ( self::TEXTS as $k ) $texts[ $lang ][ $k ] = (string) ( $t[ $k ] ?? '' );
        }
        return [
            'on'          => ! array_key_exists( 'on', $s ) || ! empty( $s['on'] ),
            'place'       => in_array( $s['place'] ?? '', self::PLACES, true ) ? $s['place'] : 'auto',
            'color'       => preg_match( '/^#[0-9a-f]{6}$/i', (string) ( $s['color'] ?? '' ) ) ? strtolower( $s['color'] ) : '',
            'size'        => ( $s['size'] ?? '' ) === 's' ? 's' : 'm',
            'phone'       => ( $s['phone'] ?? '' ) === 'hide' ? 'hide' : 'show',
            'features'    => $features,
            'coordinator' => [
                'name'  => (string) ( $coord['name'] ?? '' ),
                'phone' => (string) ( $coord['phone'] ?? '' ),
                'email' => (string) ( $coord['email'] ?? '' ),
            ],
            'texts'       => (object) $texts,
            'answer_days' => max( 0, min( 60, (int) ( $s['answer_days'] ?? 0 ) ) ),
            'updated'     => (int) ( $s['updated'] ?? 0 ),
        ];
    }

    /** For the dashboard: the settings, the store's own contact (the fallback) and the statement's address. */
    public static function for_dashboard(): array {
        $c = self::store_contact();
        return self::settings() + [
            'store_contact' => $c,
            'statement'     => [ 'slug' => self::SLUG, 'title' => self::title( Qwoo_Store_Language::get() ) ],
            'images_no_alt' => self::images_without_alt(),
        ];
    }

    public static function save( array $v ) {
        $old  = self::settings();
        $text = static fn( $x, $max ) => mb_substr( trim( sanitize_text_field( (string) $x ) ), 0, $max );
        $long = static fn( $x ) => mb_substr( trim( sanitize_textarea_field( (string) $x ) ), 0, 2000 );
        $color = strtolower( trim( (string) ( $v['color'] ?? '' ) ) );
        if ( $color !== '' && ! preg_match( '/^#[0-9a-f]{6}$/', $color ) ) {
            return new WP_Error( 'qwoo_a11y_color', 'Choose a colour from the list or type one like #1f5a43.' );
        }
        $coord = is_array( $v['coordinator'] ?? null ) ? $v['coordinator'] : [];
        $email = sanitize_email( (string) ( $coord['email'] ?? '' ) );
        if ( trim( (string) ( $coord['email'] ?? '' ) ) !== '' && ! is_email( $email ) ) {
            return new WP_Error( 'qwoo_a11y_email', 'Enter a valid email for the accessibility coordinator.' );
        }
        $features = [];
        foreach ( self::FEATURES as $f ) $features[ $f ] = ! is_array( $v['features'] ?? null ) || ! array_key_exists( $f, $v['features'] ) ? $old['features'][ $f ] : ! empty( $v['features'][ $f ] );
        $texts = [];
        foreach ( (array) ( $v['texts'] ?? [] ) as $lang => $t ) {
            if ( ! isset( Qwoo_Store_Language::LANGS[ $lang ] ) || ! is_array( $t ) ) continue;
            foreach ( self::TEXTS as $k ) $texts[ $lang ][ $k ] = $long( $t[ $k ] ?? '' );
        }
        $new = [
            'on'          => array_key_exists( 'on', $v ) ? ! empty( $v['on'] ) : $old['on'],
            'place'       => in_array( $v['place'] ?? '', self::PLACES, true ) ? $v['place'] : $old['place'],
            'color'       => $color,
            'size'        => ( $v['size'] ?? $old['size'] ) === 's' ? 's' : 'm',
            'phone'       => ( $v['phone'] ?? $old['phone'] ) === 'hide' ? 'hide' : 'show',
            'features'    => $features,
            'coordinator' => [
                'name'  => $text( $coord['name'] ?? '', 100 ),
                'phone' => $text( $coord['phone'] ?? '', 40 ),
                'email' => $email,
            ],
            'texts'       => $texts ?: (array) $old['texts'],
            'answer_days' => max( 0, min( 60, (int) ( $v['answer_days'] ?? $old['answer_days'] ) ) ),
            'updated'     => time(),
        ];
        update_option( self::OPTION, $new, true );
        // The storefront reads the tool from the live pointer: no publish needed.
        if ( class_exists( 'Qwoo_Site_Content' ) ) Qwoo_Site_Content::write_pointer();
        return self::for_dashboard();
    }

    /** The live pointer's part (public: only what the storefront shows anyway). */
    public static function site_fields(): array {
        $s = self::settings();
        $c = self::contact();
        return [ 'a11y' => [
            'on'       => $s['on'],
            'place'    => $s['place'],
            'color'    => $s['color'],
            'size'     => $s['size'],
            'phone'    => $s['phone'],
            // Only the features that are off (smaller): the storefront shows everything else.
            'off'      => array_values( array_keys( array_filter( $s['features'], static fn( $on ) => ! $on ) ) ),
            'contact'  => array_filter( [ 'name' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email'] ] ),
        ] ];
    }

    /** Home (the store's overview): what the owner should still do. */
    public static function overview(): array {
        $s = self::settings();
        return [
            'coordinator_missing' => trim( $s['coordinator']['name'] ) === '' || ( $s['coordinator']['email'] === '' && $s['coordinator']['phone'] === '' ),
            'images_no_alt'       => self::images_without_alt(),
        ];
    }

    /** Images in the media library with no description for screen readers (alt text). */
    public static function images_without_alt(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND p.post_mime_type <> 'image/svg+xml'
             AND ( m.meta_value IS NULL OR m.meta_value = '' )"
        );
    }

    /* ---------------- contact ---------------- */

    /** The store's own email and phone (the statement's fallback when there's no coordinator). */
    private static function store_contact(): array {
        $own       = (array) get_option( 'qwoo_store_contact', [] );
        $new_order = (array) get_option( 'woocommerce_new_order_settings', [] );
        $email     = (string) ( $own['email'] ?? '' ) ?: ( (string) ( $new_order['recipient'] ?? '' ) ?: (string) get_option( 'admin_email' ) );
        return [ 'email' => is_email( $email ) ? $email : '', 'phone' => (string) ( $own['phone'] ?? '' ) ];
    }

    /** Who answers accessibility questions: the coordinator, else the store's own email and phone. */
    public static function contact(): array {
        $s     = self::settings()['coordinator'];
        $store = self::store_contact();
        return [
            'name'  => $s['name'],
            'phone' => $s['phone'] !== '' ? $s['phone'] : ( $s['email'] === '' ? $store['phone'] : '' ),
            'email' => $s['email'] !== '' ? $s['email'] : $store['email'],
        ];
    }

    /* ---------------- the statement ---------------- */

    private static function title( string $lang ): string {
        return $lang === 'he' ? 'הצהרת נגישות' : 'Accessibility statement';
    }

    private static function long_date( int $time, string $lang ): string {
        if ( $lang !== 'he' ) return wp_date( 'F j, Y', $time );
        $months = [ 'ינואר', 'פברואר', 'מרץ', 'אפריל', 'מאי', 'יוני', 'יולי', 'אוגוסט', 'ספטמבר', 'אוקטובר', 'נובמבר', 'דצמבר' ];
        return wp_date( 'j', $time ) . ' ב' . $months[ (int) wp_date( 'n', $time ) - 1 ] . ' ' . wp_date( 'Y', $time );
    }

    /** Paragraphs from the owner's plain text (blank lines split them). */
    private static function paras( string $text ): string {
        $out = '';
        foreach ( preg_split( '/\n\s*\n/', trim( $text ) ) as $p ) {
            if ( trim( $p ) !== '' ) $out .= '<p>' . nl2br( esc_html( trim( $p ) ) ) . '</p>';
        }
        return $out;
    }

    /**
     * { title, html, updated } in the language asked for (the store's main one by default). Only what
     * the storefront really does is claimed; nothing says "compliant".
     */
    public static function statement( string $lang = '' ): array {
        $lang  = isset( Qwoo_Store_Language::LANGS[ $lang ] ) ? $lang : Qwoo_Store_Language::get();
        $he    = $lang === 'he';
        $s     = self::settings();
        $t     = (array) ( ( (array) $s['texts'] )[ $lang ] ?? [] );
        $c     = self::contact();
        $name  = esc_html( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
        // The date the statement last changed: the owner's last save, else the day the tool came in.
        $when  = $s['updated'] ?: (int) get_option( 'qwoo_a11y_since', 0 );
        if ( ! $when ) {
            $when = time();
            update_option( 'qwoo_a11y_since', $when, false );
        }
        $li = static fn( array $items ) => '<ul>' . implode( '', array_map( static fn( $i ) => '<li>' . $i . '</li>', $items ) ) . '</ul>';
        $h  = '';
        $h .= '<p>' . ( $he ? 'עודכנה: ' : 'Last updated: ' ) . esc_html( self::long_date( $when, $lang ) ) . '</p>';
        if ( $he ) {
            $h .= '<h2>המחויבות שלנו</h2><p>' . $name . ' רוצה שכל אחד ואחת יוכלו לגלוש ולקנות באתר בקלות, גם אנשים עם מוגבלות. אנחנו פועלים להנגיש את האתר לפי תקן ישראלי 5568, המבוסס על הנחיות WCAG ברמה AA.</p>';
            $h .= '<h2>מה עשינו באתר</h2>' . $li( [
                'אפשר לגלוש בכל האתר במקלדת, עם סימון ברור של המקום הנוכחי וקישור "דילוג לתוכן" בתחילת כל עמוד.',
                'התפריטים, העגלה, החיפוש והחלונות הקופצים עובדים עם מקלדת וקוראי מסך, ו־Escape סוגר אותם.',
                'לשדות הטפסים יש תוויות, והודעות שגיאה מוקראות.',
                'השפה וכיוון הכתיבה של כל עמוד מוגדרים, כדי שקוראי מסך יקריאו נכון.',
                'האתר מכבד את הגדרת "הפחתת תנועה" של המכשיר, ואפשר להגדיל אותו עד 200% בלי לאבד תוכן.',
                'לתמונות יש שדה תיאור לקוראי מסך.',
            ] );
            if ( $s['on'] ) $h .= '<h2>כלי ההתאמות</h2><p>בפינת המסך יש כפתור נגישות. אפשר להגדיל טקסט, לשנות ריווח וגופן, לבחור ניגודיות, להדגיש קישורים וכותרות, לעצור אנימציות ועוד. הבחירות נשמרות במכשיר שלכם בלבד. הכלי נוסף על נגישות האתר עצמו, ולא במקומה.</p>';
            $h .= '<h2>מה עוד לא נגיש</h2>' . ( trim( $t['gaps'] ?? '' ) !== '' ? self::paras( $t['gaps'] ) : '<p>אנחנו ממשיכים לבדוק ולשפר. אם מצאתם חלק באתר שלא נוח לשימוש, ספרו לנו ונתקן.</p>' );
            if ( trim( $t['physical'] ?? '' ) !== '' ) $h .= '<h2>נגישות פיזית</h2>' . self::paras( $t['physical'] );
            if ( trim( $t['other_ways'] ?? '' ) !== '' ) $h .= '<h2>דרכים נוספות לקבל שירות</h2>' . self::paras( $t['other_ways'] );
            if ( trim( $t['extra'] ?? '' ) !== '' ) $h .= self::paras( $t['extra'] );
            $h .= '<h2>פניות בנושא נגישות</h2><p>נתקלתם בבעיה, או צריכים התאמה? נשמח לשמוע ולעזור.</p>';
            $rows = [];
            if ( $c['name'] !== '' ) $rows[] = 'רכז/ת הנגישות: ' . esc_html( $c['name'] );
            if ( $c['phone'] !== '' ) $rows[] = 'טלפון: <a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $c['phone'] ) ) . '">' . esc_html( $c['phone'] ) . '</a>';
            if ( $c['email'] !== '' ) $rows[] = 'אימייל: <a href="mailto:' . esc_attr( $c['email'] ) . '">' . esc_html( $c['email'] ) . '</a>';
            if ( $s['on'] ) $rows[] = 'או בכפתור הנגישות: "דיווח על בעיה".';
            $h .= $li( $rows );
            $h .= '<p>' . ( $s['answer_days'] ? 'נחזור אליכם תוך ' . (int) $s['answer_days'] . ' ימי עסקים.' : 'נחזור אליכם בהקדם.' ) . '</p>';
        } else {
            $h .= '<h2>Our commitment</h2><p>' . $name . ' wants everyone to be able to browse and shop here easily, including people with disabilities. We work to make this site accessible following WCAG at level AA (in Israel, Israeli Standard 5568).</p>';
            $h .= '<h2>What we did</h2>' . $li( [
                'The whole site works with a keyboard, with a clear mark on where you are and a "Skip to content" link at the top of every page.',
                'Menus, the cart, search and pop-ups work with a keyboard and screen readers, and Escape closes them.',
                'Form fields have labels, and error messages are read out.',
                'Every page states its language and direction, so screen readers read it correctly.',
                'The site follows your device\'s "reduce motion" setting, and you can zoom to 200% without losing content.',
                'Pictures have a description field for screen readers.',
            ] );
            if ( $s['on'] ) $h .= '<h2>The accessibility tool</h2><p>The accessibility button in the corner of the screen lets you make text bigger, change spacing and font, pick a contrast, highlight links and headings, stop animations and more. Your choices stay on your device only. The tool is on top of the site\'s own accessibility, never instead of it.</p>';
            $h .= '<h2>What isn\'t accessible yet</h2>' . ( trim( $t['gaps'] ?? '' ) !== '' ? self::paras( $t['gaps'] ) : '<p>We keep checking and improving. If part of the site is hard to use, tell us and we\'ll fix it.</p>' );
            if ( trim( $t['physical'] ?? '' ) !== '' ) $h .= '<h2>Physical access</h2>' . self::paras( $t['physical'] );
            if ( trim( $t['other_ways'] ?? '' ) !== '' ) $h .= '<h2>Other ways to get our service</h2>' . self::paras( $t['other_ways'] );
            if ( trim( $t['extra'] ?? '' ) !== '' ) $h .= self::paras( $t['extra'] );
            $h .= '<h2>Contact us about accessibility</h2><p>Ran into a problem, or need an adjustment? We\'re happy to help.</p>';
            $rows = [];
            if ( $c['name'] !== '' ) $rows[] = 'Accessibility coordinator: ' . esc_html( $c['name'] );
            if ( $c['phone'] !== '' ) $rows[] = 'Phone: <a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $c['phone'] ) ) . '">' . esc_html( $c['phone'] ) . '</a>';
            if ( $c['email'] !== '' ) $rows[] = 'Email: <a href="mailto:' . esc_attr( $c['email'] ) . '">' . esc_html( $c['email'] ) . '</a>';
            if ( $s['on'] ) $rows[] = 'Or use the accessibility button: "Report a problem".';
            $h .= $li( $rows );
            $h .= '<p>' . ( $s['answer_days'] ? 'We\'ll answer within ' . (int) $s['answer_days'] . ' business days.' : 'We\'ll get back to you soon.' ) . '</p>';
        }
        return [ 'title' => self::title( $lang ), 'html' => $h, 'updated' => $when, 'lang' => $lang ];
    }

    /* ---------------- REST (public) ---------------- */

    public static function routes() {
        register_rest_route( self::NS, '/site/a11y-statement', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_statement' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( self::NS, '/a11y/report', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_report' ], 'permission_callback' => '__return_true' ] );
    }

    public static function rest_statement( WP_REST_Request $r ) {
        $res = new WP_REST_Response( self::statement( sanitize_key( (string) $r->get_param( 'lang' ) ) ) );
        $res->header( 'Cache-Control', 'no-store, max-age=0' );
        return $res;
    }

    /** { message, email, name, page, website } from the tool's "Report a problem". */
    public static function rest_report( WP_REST_Request $r ) {
        $he = Qwoo_Store_Language::get() === 'he';
        if ( (string) $r->get_param( 'website' ) !== '' ) return rest_ensure_response( [ 'ok' => true ] ); // a bot
        $email = strtolower( sanitize_email( (string) $r->get_param( 'email' ) ) );
        $phone = mb_substr( trim( sanitize_text_field( (string) $r->get_param( 'phone' ) ) ), 0, 40 );
        $body  = mb_substr( trim( sanitize_textarea_field( (string) $r->get_param( 'message' ) ) ), 0, 3000 );
        if ( mb_strlen( $body ) < 3 ) return new WP_Error( 'message', $he ? 'כתבו מה לא עבד.' : 'Write what didn\'t work.', [ 'status' => 400 ] );
        if ( ! is_email( $email ) ) return new WP_Error( 'email', $he ? 'צריך כתובת אימייל תקינה כדי שנוכל לענות.' : 'We need a valid email address to answer you.', [ 'status' => 400 ] );
        if ( function_exists( 'qwoo_rate_limit_check' ) && is_wp_error( qwoo_rate_limit_check( 'a11y_report', 5, HOUR_IN_SECONDS ) ) ) {
            return new WP_Error( 'rate', $he ? 'יותר מדי דיווחים בזמן קצר. נסו שוב מאוחר יותר.' : 'Too many reports in a short time. Try again later.', [ 'status' => 429 ] );
        }
        $day_key = 'qwoo_a11y_day_' . gmdate( 'Ymd' );
        $today   = (int) get_transient( $day_key );
        if ( $today >= 100 ) return new WP_Error( 'busy', $he ? 'לא הצלחנו לקבל את הדיווח כרגע. נסו שוב מאוחר יותר.' : 'We couldn\'t take your report right now. Please try again later.', [ 'status' => 429 ] );
        set_transient( $day_key, $today + 1, DAY_IN_SECONDS );

        $page = (string) wp_parse_url( '/' . ltrim( (string) $r->get_param( 'page' ), '/' ), PHP_URL_PATH );
        $page = mb_substr( sanitize_text_field( rawurldecode( $page ) ), 0, 190 );
        $text = $body . ( $phone !== '' ? "\n\n" . ( $he ? 'טלפון: ' : 'Phone: ' ) . $phone : '' );
        $name = mb_substr( sanitize_text_field( (string) $r->get_param( 'name' ) ), 0, 120 );
        $kept = class_exists( 'Qwoo_Inbox' ) ? Qwoo_Inbox::from_accessibility( $email, $name, $text, $page ) : 0;

        // The coordinator hears it too when they have their own address (the owner already got the Inbox alert).
        $coord = self::settings()['coordinator']['email'];
        $owner = self::store_contact()['email'];
        if ( $coord !== '' && ( ! $kept || strtolower( $coord ) !== strtolower( $owner ) ) ) {
            $subject = ( $he ? 'דיווח נגישות מהאתר: ' : 'Accessibility report from the site: ' ) . html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
            $mail    = $text . "\n\n" . ( $he ? 'עמוד: ' : 'Page: ' ) . $page . "\n" . ( $he ? 'אימייל לתשובה: ' : 'Reply to: ' ) . $email;
            wp_mail( $coord, $subject, $mail, [ 'Reply-To: ' . $email ] );
        } elseif ( ! $kept && $owner !== '' ) {
            wp_mail( $owner, ( $he ? 'דיווח נגישות מהאתר' : 'Accessibility report from the site' ), $text . "\n\n" . $page . "\n" . $email, [ 'Reply-To: ' . $email ] );
        }
        return rest_ensure_response( [ 'ok' => true ] );
    }
}
Qwoo_Accessibility::init();

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email marketing (dashboard → Marketing): subscribers with a record of their
 * consent, emails designed in the dashboard, and sending to a group.
 *
 * What the law asks for is built in, not left to the owner (Israel's
 * Communications Law section 30A; a lawyer still has to confirm the details):
 * only people who agreed get marketing, every email carries the store's details
 * and an unsubscribe link, one click unsubscribes, and the subject starts with
 * the word for "advertisement" (the store's setting, on by default in Israel).
 *
 * Emails go out through the platform in small batches (QP_Marketing_Mail on
 * the platform checks the plan's monthly allowance), in the push engine's run
 * (no cron on shared hosting).
 *
 * A design is { settings: { bg, content_bg, color, link, button_bg, button_color }, blocks: [ { id, type, data } ] }.
 * render() turns it into table-based HTML that email apps can show.
 */
class Qwoo_Email_Marketing {

    const SETTINGS  = 'qwoo_em_settings';
    const BATCH     = 40;
    const MAX_SUBS_IMPORT = 2000;
    const NS        = 'qwoo/v1';
    const SEGMENTS  = [ 'all', 'buyers', 'no_orders', 'inactive' ];
    const BLOCKS    = [ 'heading', 'text', 'image', 'button', 'divider', 'spacer', 'products', 'coupon', 'social' ];
    const KEEP_SENDS_DAYS = 365;

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
        add_action( 'qwoo_push_tick', [ __CLASS__, 'send_due' ] );
        add_filter( 'qwoo_push_next_due', [ __CLASS__, 'next_due' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
    }

    public static function subs_table() { global $wpdb; return $wpdb->prefix . 'qwoo_em_subs'; }
    public static function campaigns_table() { global $wpdb; return $wpdb->prefix . 'qwoo_em_campaigns'; }
    public static function sends_table() { global $wpdb; return $wpdb->prefix . 'qwoo_em_sends'; }

    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $subs = self::subs_table();
        $camp = self::campaigns_table();
        $send = self::sends_table();
        dbDelta( "CREATE TABLE $subs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL,
        name VARCHAR(190) NOT NULL DEFAULT '',
        lang VARCHAR(5) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'sub',
        source VARCHAR(30) NOT NULL DEFAULT '',
        consent_text VARCHAR(255) NOT NULL DEFAULT '',
        consent_at DATETIME NULL,
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY email (email),
        KEY status (status)
        ) $charset;" );
        dbDelta( "CREATE TABLE $camp (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        kind VARCHAR(10) NOT NULL DEFAULT 'campaign',
        name VARCHAR(190) NOT NULL DEFAULT '',
        subject VARCHAR(190) NOT NULL DEFAULT '',
        preheader VARCHAR(190) NOT NULL DEFAULT '',
        design LONGTEXT NOT NULL,
        segment VARCHAR(20) NOT NULL DEFAULT 'all',
        segment_days INT NOT NULL DEFAULT 90,
        status VARCHAR(12) NOT NULL DEFAULT 'draft',
        send_at DATETIME NULL,
        recipients INT NOT NULL DEFAULT 0,
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY kind_status (kind, status)
        ) $charset;" );
        dbDelta( "CREATE TABLE $send (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        campaign_id BIGINT UNSIGNED NOT NULL,
        sub_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        email VARCHAR(190) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT 'wait',
        sent_at DATETIME NULL,
        opened_at DATETIME NULL,
        clicked_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY campaign_status (campaign_id, status),
        KEY sub_id (sub_id)
        ) $charset;" );
    }

    /* ---------------- settings ---------------- */

    public static function settings(): array {
        $saved = get_option( self::SETTINGS, [] );
        $main  = class_exists( 'Qwoo_Push' ) ? Qwoo_Push::main_lang() : 'en';
        $il    = strpos( (string) get_option( 'woocommerce_default_country' ), 'IL' ) === 0;
        return array_merge( [
            'from_name'  => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            'reply_to'   => (string) get_option( 'woocommerce_email_from_address', '' ),
            'ad_label'   => $il || $main === 'he',
            'ad_word'    => $main === 'he' ? 'פרסומת' : 'Advertisement',
            'optin_on'   => false,
            'optin_text' => $main === 'he' ? 'אני רוצה לקבל מהחנות מבצעים ועדכונים במייל' : 'Send me offers and news from this store by email',
        ], is_array( $saved ) ? $saved : [] );
    }

    public static function save_settings( array $in ): array {
        $reply = sanitize_email( (string) ( $in['reply_to'] ?? '' ) );
        update_option( self::SETTINGS, [
            'from_name'  => mb_substr( sanitize_text_field( (string) ( $in['from_name'] ?? '' ) ), 0, 100 ),
            'reply_to'   => is_email( $reply ) ? $reply : '',
            'ad_label'   => ! empty( $in['ad_label'] ),
            'ad_word'    => mb_substr( sanitize_text_field( (string) ( $in['ad_word'] ?? '' ) ), 0, 30 ),
            'optin_on'   => ! empty( $in['optin_on'] ),
            'optin_text' => mb_substr( sanitize_text_field( (string) ( $in['optin_text'] ?? '' ) ), 0, 200 ),
        ], false );
        return self::settings();
    }

    /** What the checkout's published page needs for its "send me offers" box. */
    public static function checkout_optin(): array {
        $s = self::settings();
        return [ 'on' => (bool) $s['optin_on'], 'text' => (string) $s['optin_text'] ];
    }

    /* ---------------- subscribers ---------------- */

    /**
     * Adds someone who agreed, or brings back someone who left only when they
     * signed up again by themselves ($own). Bounced and complained addresses stay out.
     */
    public static function subscribe( string $email, string $name, string $source, string $consent, string $lang = '', bool $own = true ): string {
        global $wpdb;
        $email = strtolower( trim( $email ) );
        if ( ! is_email( $email ) ) return 'invalid';
        $t    = self::subs_table();
        $now  = gmdate( 'Y-m-d H:i:s' );
        $row  = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $t WHERE email = %s", $email ), ARRAY_A );
        $base = [ 'status' => 'sub', 'source' => mb_substr( $source, 0, 30 ), 'consent_text' => mb_substr( $consent, 0, 255 ), 'consent_at' => $now, 'updated' => $now ];
        if ( $row ) {
            if ( $row['status'] === 'sub' ) return 'exists';
            if ( ! $own || in_array( $row['status'], [ 'bounced', 'complained' ], true ) ) return 'blocked';
            $wpdb->update( $t, $base, [ 'id' => $row['id'] ] );
        } else {
            $wpdb->insert( $t, $base + [ 'email' => $email, 'name' => mb_substr( $name, 0, 190 ), 'lang' => mb_substr( $lang, 0, 5 ), 'created' => $now ] );
        }
        if ( class_exists( 'Qwoo_Webhooks' ) ) Qwoo_Webhooks::emit( 'subscriber.created', [ 'email' => $email, 'name' => $name, 'source' => $source ] );
        do_action( 'qwoo_subscriber_added', $email, $name );
        return 'added';
    }

    /** Stops marketing to an address: 'unsub', 'bounced' or 'complained'. */
    public static function suppress( string $email, string $status ): void {
        global $wpdb;
        $email = strtolower( trim( $email ) );
        $t     = self::subs_table();
        $id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE email = %s", $email ) );
        $now   = gmdate( 'Y-m-d H:i:s' );
        if ( $id ) {
            $wpdb->update( $t, [ 'status' => $status, 'updated' => $now ], [ 'id' => $id ] );
        } elseif ( is_email( $email ) ) {
            // Remember it, so a later import can't add it back.
            $wpdb->insert( $t, [ 'email' => $email, 'status' => $status, 'source' => 'suppressed', 'created' => $now, 'updated' => $now ] );
        }
        $wpdb->update( self::sends_table(), [ 'status' => 'skipped' ], [ 'email' => $email, 'status' => 'wait' ] );
        if ( $status === 'unsub' && class_exists( 'Qwoo_Webhooks' ) ) Qwoo_Webhooks::emit( 'subscriber.unsubscribed', [ 'email' => $email ] );
    }

    /** Is this address allowed to get marketing? */
    public static function can_mail( string $email ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . self::subs_table() . ' WHERE email = %s', strtolower( trim( $email ) ) ) ) === 'sub';
    }

    public static function subscribers( array $p ): array {
        global $wpdb;
        $t      = self::subs_table();
        $page   = max( 1, (int) ( $p['page'] ?? 1 ) );
        $search = trim( (string) ( $p['search'] ?? '' ) );
        $where  = "source <> 'suppressed'";
        if ( $search !== '' ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( ' AND (email LIKE %s OR name LIKE %s)', $like, $like );
        }
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $where" );
        $rows  = (array) $wpdb->get_results( "SELECT id, email, name, status, source, consent_text, consent_at, created FROM $t WHERE $where ORDER BY id DESC LIMIT 50 OFFSET " . ( ( $page - 1 ) * 50 ), ARRAY_A );
        return [ 'rows' => $rows, 'total' => $total, 'pages' => (int) ceil( $total / 50 ), 'page' => $page ];
    }

    /** { rows: [ { email, name } ], consent: true }: the owner states that these people agreed. */
    public static function import( array $p ) {
        if ( empty( $p['consent'] ) ) return new WP_Error( 'consent', 'Confirm that everyone on the list agreed to get marketing emails from you.' );
        $done = [ 'added' => 0, 'exists' => 0, 'blocked' => 0, 'invalid' => 0 ];
        foreach ( array_slice( (array) ( $p['rows'] ?? [] ), 0, self::MAX_SUBS_IMPORT ) as $row ) {
            $result = self::subscribe( (string) ( $row['email'] ?? '' ), sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 'import', 'Imported by the store owner, who confirmed consent', '', false );
            $done[ $result ]++;
        }
        return $done;
    }

    /** Removes someone and what's known about them (a privacy request). Their address stays out of later sends. */
    public static function erase_email( string $email ): void {
        global $wpdb;
        $email = strtolower( trim( $email ) );
        $wpdb->delete( self::sends_table(), [ 'email' => $email ] );
        $wpdb->delete( self::subs_table(), [ 'email' => $email ] );
    }

    public static function register_eraser( $erasers ) {
        $erasers['qwoo-email-marketing'] = [
            'eraser_friendly_name' => 'Email marketing',
            'callback'             => static function ( $email ) {
                self::erase_email( (string) $email );
                return [ 'items_removed' => true, 'items_retained' => false, 'messages' => [], 'done' => true ];
            },
        ];
        return $erasers;
    }

    public static function counts(): array {
        global $wpdb;
        $out = [ 'sub' => 0, 'unsub' => 0, 'bounced' => 0, 'complained' => 0 ];
        foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::subs_table() . ' GROUP BY status', ARRAY_A ) as $row ) {
            $out[ $row['status'] ] = (int) $row['n'];
        }
        return $out;
    }

    /* ---------------- designs ---------------- */

    public static function clean_design( $raw ): array {
        $raw      = is_array( $raw ) ? $raw : [];
        $settings = is_array( $raw['settings'] ?? null ) ? $raw['settings'] : [];
        $color    = static fn( $v, $fallback ) => sanitize_hex_color( (string) $v ) ?: $fallback;
        $out = [
            'settings' => [
                'bg'           => $color( $settings['bg'] ?? '', '#f4f4f7' ),
                'content_bg'   => $color( $settings['content_bg'] ?? '', '#ffffff' ),
                'color'        => $color( $settings['color'] ?? '', '#1d1b2e' ),
                'link'         => $color( $settings['link'] ?? '', '#5b4cf5' ),
                'button_bg'    => $color( $settings['button_bg'] ?? '', '#1d1b2e' ),
                'button_color' => $color( $settings['button_color'] ?? '', '#ffffff' ),
            ],
            'blocks'   => [],
        ];
        foreach ( array_slice( (array) ( $raw['blocks'] ?? [] ), 0, 60 ) as $block ) {
            $type = (string) ( $block['type'] ?? '' );
            if ( ! in_array( $type, self::BLOCKS, true ) ) continue;
            $d = is_array( $block['data'] ?? null ) ? $block['data'] : [];
            $align = in_array( $d['align'] ?? '', [ 'start', 'center', 'end' ], true ) ? $d['align'] : 'start';
            switch ( $type ) {
                case 'heading':
                    $data = [ 'text' => mb_substr( sanitize_text_field( (string) ( $d['text'] ?? '' ) ), 0, 200 ), 'size' => min( 40, max( 16, (int) ( $d['size'] ?? 26 ) ) ), 'align' => $align ];
                    break;
                case 'text':
                    $html = wp_kses( (string) ( $d['html'] ?? '' ), [ 'a' => [ 'href' => true ], 'b' => [], 'strong' => [], 'em' => [], 'i' => [], 'u' => [], 'br' => [], 'p' => [], 'ul' => [], 'ol' => [], 'li' => [] ], [ 'http', 'https', 'mailto' ] );
                    // wp_kses leaves "alert(1)" of a javascript: link: what isn't a full address stops being a link.
                    $html = preg_replace( '/<a\s+href="(?!https?:|mailto:)[^"]*"\s*>/i', '<a>', (string) $html );
                    $data = [ 'html' => (string) $html, 'align' => $align ];
                    break;
                case 'image':
                    $data = [ 'url' => esc_url_raw( (string) ( $d['url'] ?? '' ), [ 'http', 'https' ] ), 'alt' => mb_substr( sanitize_text_field( (string) ( $d['alt'] ?? '' ) ), 0, 200 ), 'link' => esc_url_raw( (string) ( $d['link'] ?? '' ) ), 'width' => min( 100, max( 10, (int) ( $d['width'] ?? 100 ) ) ), 'align' => $align ];
                    break;
                case 'button':
                    $data = [ 'label' => mb_substr( sanitize_text_field( (string) ( $d['label'] ?? '' ) ), 0, 80 ), 'link' => esc_url_raw( (string) ( $d['link'] ?? '' ) ), 'align' => $align ];
                    break;
                case 'spacer':
                    $data = [ 'height' => min( 80, max( 4, (int) ( $d['height'] ?? 24 ) ) ) ];
                    break;
                case 'products':
                    $data = [ 'ids' => array_slice( array_values( array_filter( array_map( 'intval', (array) ( $d['ids'] ?? [] ) ) ) ), 0, 6 ), 'button' => mb_substr( sanitize_text_field( (string) ( $d['button'] ?? '' ) ), 0, 40 ) ];
                    break;
                case 'coupon':
                    $data = [ 'code' => mb_substr( sanitize_text_field( (string) ( $d['code'] ?? '' ) ), 0, 40 ), 'text' => mb_substr( sanitize_text_field( (string) ( $d['text'] ?? '' ) ), 0, 200 ) ];
                    break;
                case 'social':
                    $links = [];
                    foreach ( array_slice( (array) ( $d['links'] ?? [] ), 0, 8 ) as $link ) {
                        $url = esc_url_raw( (string) ( $link['url'] ?? '' ), [ 'http', 'https' ] );
                        if ( $url !== '' ) $links[] = [ 'label' => mb_substr( sanitize_text_field( (string) ( $link['label'] ?? '' ) ), 0, 30 ), 'url' => $url ];
                    }
                    $data = [ 'links' => $links ];
                    break;
                default:
                    $data = [];
            }
            $out['blocks'][] = [ 'id' => sanitize_key( (string) ( $block['id'] ?? '' ) ) ?: 'b' . bin2hex( random_bytes( 4 ) ), 'type' => $type, 'data' => $data ];
        }
        return $out;
    }

    /** A new email: the store's logo, a title, a text and a button. */
    public static function starter_design(): array {
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? rtrim( Qwoo_Technical_Settings::get_primary_frontend_domain(), '/' ) : '';
        $shop  = class_exists( 'Qwoo_Push' ) && Qwoo_Push::main_lang() === 'he' ? 'לחנות' : 'Shop now';
        return self::clean_design( [ 'blocks' => [
            [ 'id' => 'b1', 'type' => 'heading', 'data' => [ 'text' => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 'size' => 28, 'align' => 'center' ] ],
            [ 'id' => 'b2', 'type' => 'text', 'data' => [ 'html' => '<p>{first_name},</p><p></p>', 'align' => 'start' ] ],
            [ 'id' => 'b3', 'type' => 'button', 'data' => [ 'label' => $shop, 'link' => $front . '/', 'align' => 'center' ] ],
        ] ] );
    }

    /**
     * The email's HTML. $link wraps every link (click tracking); $footer is
     * the required part. Tables and inline styles: email apps ignore the rest.
     */
    public static function render( array $design, string $preheader, callable $link, string $footer, string $first_name = '' ): string {
        $s    = $design['settings'];
        $main = class_exists( 'Qwoo_Push' ) ? Qwoo_Push::main_lang() : 'en';
        $rtl  = in_array( $main, [ 'he', 'ar' ], true );
        $dir  = $rtl ? 'rtl' : 'ltr';
        $start = $rtl ? 'right' : 'left';
        $end   = $rtl ? 'left' : 'right';
        $font  = "-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif";
        $al    = static fn( $a ) => $a === 'center' ? 'center' : ( $a === 'end' ? $end : $start );
        $body  = '';
        foreach ( (array) $design['blocks'] as $block ) {
            $d = $block['data'];
            $cell = '';
            switch ( $block['type'] ) {
                case 'heading':
                    $cell = '<h1 style="margin:0;font-family:' . $font . ';font-size:' . (int) $d['size'] . 'px;line-height:1.25;color:' . esc_attr( $s['color'] ) . ';text-align:' . $al( $d['align'] ) . ';">' . esc_html( $d['text'] ) . '</h1>';
                    break;
                case 'text':
                    $html = preg_replace_callback( '/href="([^"]*)"/', static fn( $m ) => 'href="' . esc_attr( $link( html_entity_decode( $m[1] ) ) ) . '" style="color:' . esc_attr( $s['link'] ) . ';"', (string) $d['html'] );
                    $html = str_replace( '<p>', '<p style="margin:0 0 12px;">', (string) $html );
                    $cell = '<div style="font-family:' . $font . ';font-size:16px;line-height:1.6;color:' . esc_attr( $s['color'] ) . ';text-align:' . $al( $d['align'] ) . ';">' . $html . '</div>';
                    break;
                case 'image':
                    if ( $d['url'] === '' ) break;
                    $w   = (int) round( 552 * $d['width'] / 100 );
                    $img = '<img src="' . esc_url( $d['url'] ) . '" alt="' . esc_attr( $d['alt'] ) . '" width="' . $w . '" style="display:inline-block;width:' . (int) $d['width'] . '%;max-width:' . $w . 'px;height:auto;border:0;border-radius:8px;">';
                    $cell = '<div style="text-align:' . $al( $d['align'] ) . ';">' . ( $d['link'] !== '' ? '<a href="' . esc_url( $link( $d['link'] ) ) . '">' . $img . '</a>' : $img ) . '</div>';
                    break;
                case 'button':
                    if ( $d['link'] === '' ) break;
                    $cell = '<div style="text-align:' . $al( $d['align'] ) . ';"><a href="' . esc_url( $link( $d['link'] ) ) . '" style="display:inline-block;padding:13px 26px;border-radius:8px;background:' . esc_attr( $s['button_bg'] ) . ';color:' . esc_attr( $s['button_color'] ) . ';font-family:' . $font . ';font-size:16px;font-weight:bold;text-decoration:none;">' . esc_html( $d['label'] ) . '</a></div>';
                    break;
                case 'divider':
                    $cell = '<div style="border-top:1px solid #e5e5ea;font-size:0;line-height:0;">&nbsp;</div>';
                    break;
                case 'spacer':
                    $cell = '<div style="height:' . (int) $d['height'] . 'px;font-size:0;line-height:0;">&nbsp;</div>';
                    break;
                case 'products':
                    $cell = self::products_html( $d, $s, $link, $font );
                    break;
                case 'coupon':
                    if ( $d['code'] === '' ) break;
                    $cell = '<div style="padding:18px;border:2px dashed ' . esc_attr( $s['button_bg'] ) . ';border-radius:10px;text-align:center;font-family:' . $font . ';color:' . esc_attr( $s['color'] ) . ';">'
                        . ( $d['text'] !== '' ? '<div style="font-size:15px;margin-bottom:6px;">' . esc_html( $d['text'] ) . '</div>' : '' )
                        . '<div style="font-size:24px;font-weight:bold;letter-spacing:2px;" dir="ltr">' . esc_html( $d['code'] ) . '</div></div>';
                    break;
                case 'social':
                    $parts = [];
                    foreach ( (array) $d['links'] as $l ) {
                        $parts[] = '<a href="' . esc_url( $link( $l['url'] ) ) . '" style="color:' . esc_attr( $s['link'] ) . ';text-decoration:none;font-family:' . $font . ';font-size:14px;margin:0 8px;">' . esc_html( $l['label'] ) . '</a>';
                    }
                    $cell = '<div style="text-align:center;">' . implode( '', $parts ) . '</div>';
                    break;
            }
            if ( $cell !== '' ) $body .= '<tr><td style="padding:10px 24px;">' . $cell . '</td></tr>';
        }
        $body = str_replace( '{first_name}', esc_html( $first_name ), $body );
        $body = str_replace( '{store_name}', esc_html( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), $body );

        return '<!doctype html><html dir="' . $dir . '" lang="' . esc_attr( $main ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:' . esc_attr( $s['bg'] ) . ';">'
            . '<div style="display:none;max-height:0;overflow:hidden;">' . esc_html( $preheader ) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . esc_attr( $s['bg'] ) . ';"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:' . esc_attr( $s['content_bg'] ) . ';border-radius:12px;">'
            . '<tr><td style="height:14px;font-size:0;">&nbsp;</td></tr>' . $body . '<tr><td style="height:14px;font-size:0;">&nbsp;</td></tr></table>'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;"><tr><td style="padding:16px 24px;font-family:' . $font . ';font-size:12px;line-height:1.6;color:#77748b;text-align:center;">' . $footer . '</td></tr></table>'
            . '</td></tr></table></body></html>';
    }

    private static function products_html( array $d, array $s, callable $link, string $font ): string {
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? rtrim( Qwoo_Technical_Settings::get_primary_frontend_domain(), '/' ) : '';
        $cells = '';
        $i     = 0;
        foreach ( (array) $d['ids'] as $id ) {
            $product = wc_get_product( (int) $id );
            if ( ! $product || $product->get_status() !== 'publish' ) continue;
            $url   = $link( $front . '/product/' . $product->get_slug() );
            $img   = wp_get_attachment_image_url( $product->get_image_id(), 'medium' );
            $price = wp_strip_all_tags( wc_price( (float) $product->get_price() ) );
            if ( $i && $i % 2 === 0 ) $cells .= '</tr><tr>';
            $cells .= '<td width="50%" valign="top" style="padding:6px;font-family:' . $font . ';text-align:center;color:' . esc_attr( $s['color'] ) . ';">'
                . ( $img ? '<a href="' . esc_url( $url ) . '"><img src="' . esc_url( $img ) . '" alt="" width="250" style="width:100%;max-width:250px;height:auto;border:0;border-radius:8px;"></a>' : '' )
                . '<div style="font-size:15px;font-weight:bold;margin:8px 0 2px;">' . esc_html( html_entity_decode( $product->get_name(), ENT_QUOTES ) ) . '</div>'
                . ( (float) $product->get_price() > 0 ? '<div style="font-size:14px;margin-bottom:8px;">' . esc_html( html_entity_decode( $price, ENT_QUOTES ) ) . '</div>' : '' )
                . ( $d['button'] !== '' ? '<a href="' . esc_url( $url ) . '" style="color:' . esc_attr( $s['link'] ) . ';font-size:14px;">' . esc_html( $d['button'] ) . '</a>' : '' )
                . '</td>';
            $i++;
        }
        if ( $i % 2 === 1 ) $cells .= '<td width="50%"></td>';
        return $cells === '' ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table>';
    }

    /** Store details and the unsubscribe link: on every email, not editable. */
    private static function footer( string $unsubscribe ): string {
        $parts = array_filter( [
            html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            trim( get_option( 'woocommerce_store_address' ) . ' ' . get_option( 'woocommerce_store_city' ) ),
            (string) get_option( 'woocommerce_email_from_address', '' ),
        ] );
        $word = class_exists( 'Qwoo_Push' ) && Qwoo_Push::main_lang() === 'he' ? 'להסרה מרשימת התפוצה' : 'Unsubscribe';
        return esc_html( implode( ' · ', $parts ) ) . '<br><a href="' . esc_url( $unsubscribe ) . '" style="color:#77748b;text-decoration:underline;">' . esc_html( $word ) . '</a>';
    }

    /* ---------------- campaigns ---------------- */

    public static function campaign_row( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::campaigns_table() . ' WHERE id = %d', $id ), ARRAY_A );
        if ( ! $row ) return null;
        $row['design'] = json_decode( (string) $row['design'], true ) ?: self::starter_design();
        return $row;
    }

    public static function campaign_stats( int $id ): array {
        global $wpdb;
        $t    = self::sends_table();
        $row  = (array) $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent, SUM(CASE WHEN status = 'wait' THEN 1 ELSE 0 END) AS waiting, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed, SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked FROM $t WHERE campaign_id = %d", $id ), ARRAY_A );
        $res  = class_exists( 'Qwoo_Analytics' ) ? Qwoo_Analytics::campaign_results( 'email', 'c' . $id ) : [ 'orders' => 0, 'sales' => 0 ];
        return [ 'total' => (int) ( $row['total'] ?? 0 ), 'sent' => (int) ( $row['sent'] ?? 0 ), 'waiting' => (int) ( $row['waiting'] ?? 0 ), 'failed' => (int) ( $row['failed'] ?? 0 ), 'opened' => (int) ( $row['opened'] ?? 0 ), 'clicked' => (int) ( $row['clicked'] ?? 0 ) ] + $res;
    }

    public static function campaigns(): array {
        global $wpdb;
        $out = [];
        foreach ( (array) $wpdb->get_results( 'SELECT id, name, subject, status, segment, send_at, updated FROM ' . self::campaigns_table() . " WHERE kind = 'campaign' ORDER BY id DESC LIMIT 100", ARRAY_A ) as $row ) {
            $out[] = [ 'id' => (int) $row['id'] ] + $row + [ 'stats' => self::campaign_stats( (int) $row['id'] ) ];
        }
        return $out;
    }

    public static function save_campaign( array $in, string $kind = 'campaign' ) {
        global $wpdb;
        $id = (int) ( $in['id'] ?? 0 );
        $t  = self::campaigns_table();
        if ( $id ) {
            $status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $t WHERE id = %d", $id ) );
            if ( in_array( $status, [ 'sending', 'sent' ], true ) ) return new WP_Error( 'locked', 'An email that was sent can\'t be changed. Make a copy instead.' );
        }
        $segment = in_array( $in['segment'] ?? '', self::SEGMENTS, true ) ? $in['segment'] : 'all';
        $data = [
            'kind'         => $kind,
            'name'         => mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 190 ),
            'subject'      => mb_substr( sanitize_text_field( (string) ( $in['subject'] ?? '' ) ), 0, 190 ),
            'preheader'    => mb_substr( sanitize_text_field( (string) ( $in['preheader'] ?? '' ) ), 0, 190 ),
            'design'       => wp_json_encode( self::clean_design( $in['design'] ?? [] ) ),
            'segment'      => $segment,
            'segment_days' => min( 730, max( 7, (int) ( $in['segment_days'] ?? 90 ) ) ),
            'updated'      => gmdate( 'Y-m-d H:i:s' ),
        ];
        if ( $id ) {
            $wpdb->update( $t, $data, [ 'id' => $id ] );
        } else {
            $wpdb->insert( $t, $data + [ 'status' => 'draft', 'created' => $data['updated'] ] );
            $id = (int) $wpdb->insert_id;
        }
        return self::campaign_row( $id );
    }

    public static function delete_campaign( int $id ): void {
        global $wpdb;
        $wpdb->delete( self::campaigns_table(), [ 'id' => $id ] );
        $wpdb->delete( self::sends_table(), [ 'campaign_id' => $id ] );
    }

    public static function duplicate_campaign( int $id ) {
        $row = self::campaign_row( $id );
        if ( ! $row ) return new WP_Error( 'missing', 'That email doesn\'t exist any more.' );
        unset( $row['id'] );
        $row['name'] .= ' (copy)';
        return self::save_campaign( $row );
    }

    /** SQL condition for a group of subscribers. Portable: no FIND_IN_SET and such. */
    private static function segment_where( string $segment, int $days ): string {
        global $wpdb;
        $o = Qwoo_Analytics::orders_table();
        $buyers = "email IN (SELECT email FROM $o WHERE paid = 1)";
        switch ( $segment ) {
            case 'buyers':    return $buyers;
            case 'no_orders': return "email NOT IN (SELECT email FROM $o WHERE paid = 1)";
            case 'inactive':
                $cutoff = wp_date( 'Y-m-d', time() - $days * DAY_IN_SECONDS, wp_timezone() );
                return $buyers . $wpdb->prepare( " AND email NOT IN (SELECT email FROM $o WHERE paid = 1 AND day >= %s)", $cutoff );
        }
        return '1 = 1';
    }

    public static function audience( string $segment, int $days ): int {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::subs_table() . " WHERE status = 'sub' AND " . self::segment_where( $segment, $days ) );
    }

    /** What stops an email from going out: empty when it can. */
    public static function send_problems( array $row ): array {
        $problems = [];
        if ( trim( (string) $row['subject'] ) === '' ) $problems[] = 'Add a subject.';
        if ( ! array_filter( (array) ( $row['design']['blocks'] ?? [] ), static fn( $b ) => ! in_array( $b['type'], [ 'divider', 'spacer' ], true ) ) ) $problems[] = 'The email is empty.';
        if ( trim( (string) get_option( 'woocommerce_store_address' ) ) === '' ) $problems[] = 'Add your business address in Settings → General: it has to appear on marketing emails.';
        if ( Qwoo_Platform_Dashboard::is_free_plan() ) $problems[] = 'Email marketing is on the Basic plan and up.';
        return $problems;
    }

    /** Sends now, or at `send_at` (Unix). */
    public static function start( int $id, int $send_at = 0 ) {
        global $wpdb;
        $row = self::campaign_row( $id );
        if ( ! $row || $row['kind'] !== 'campaign' ) return new WP_Error( 'missing', 'That email doesn\'t exist any more.' );
        if ( ! in_array( $row['status'], [ 'draft', 'scheduled' ], true ) ) return new WP_Error( 'sent', 'This email was already sent.' );
        $problems = self::send_problems( $row );
        if ( $problems ) return new WP_Error( 'problem', implode( ' ', $problems ) );
        if ( self::audience( (string) $row['segment'], (int) $row['segment_days'] ) < 1 ) return new WP_Error( 'empty', 'Nobody is in this group yet.' );
        $when = $send_at > time() ? $send_at : time();
        $wpdb->update( self::campaigns_table(), [ 'status' => 'scheduled', 'send_at' => gmdate( 'Y-m-d H:i:s', $when ) ], [ 'id' => $id ] );
        Qwoo_Push_Engine::note_due( $when );
        if ( $when <= time() ) Qwoo_Push_Engine::spawn_soon();
        return self::campaign_row( $id );
    }

    public static function cancel( int $id ): void {
        global $wpdb;
        $status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . self::campaigns_table() . ' WHERE id = %d', $id ) );
        if ( ! in_array( $status, [ 'scheduled', 'sending', 'limit', 'paused' ], true ) ) return;
        $wpdb->update( self::sends_table(), [ 'status' => 'skipped' ], [ 'campaign_id' => $id, 'status' => 'wait' ] );
        $sent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::sends_table() . " WHERE campaign_id = %d AND status = 'sent'", $id ) );
        $wpdb->update( self::campaigns_table(), [ 'status' => $sent ? 'sent' : 'draft', 'send_at' => null ], [ 'id' => $id ] );
        if ( ! $sent ) $wpdb->delete( self::sends_table(), [ 'campaign_id' => $id ] );
    }

    /* ---------------- sending ---------------- */

    private static function sign( string $what ): string {
        return substr( hash_hmac( 'sha256', $what, wp_salt( 'auth' ) . 'qwoo-email' ), 0, 24 );
    }

    /** Tracking and unsubscribe addresses go through the storefront's address: the backend's never shows in an email. */
    private static function base_url(): string {
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? rtrim( Qwoo_Technical_Settings::get_primary_frontend_domain(), '/' ) : '';
        return $front !== '' ? $front . '/wp-json/' . self::NS . '/m/' : rest_url( self::NS . '/m/' );
    }

    public static function unsubscribe_url( int $sub_id, string $email ): string {
        return self::base_url() . 'u?' . http_build_query( [ 's' => $sub_id, 'k' => self::sign( 'u' . $sub_id . strtolower( $email ) ) ] );
    }

    private static function click_url( int $send_id, string $url ): string {
        return self::base_url() . 'c?' . http_build_query( [ 'i' => $send_id, 'u' => $url, 'k' => self::sign( 'c' . $send_id . $url ) ] );
    }

    /** Adds the campaign's tags to a link to the store, so an order can be counted to it. */
    private static function tagged( string $url, int $campaign_id ): string {
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? (string) wp_parse_url( Qwoo_Technical_Settings::get_primary_frontend_domain(), PHP_URL_HOST ) : '';
        if ( $front === '' || wp_parse_url( $url, PHP_URL_HOST ) !== $front ) return $url;
        return add_query_arg( [ 'utm_source' => 'store-email', 'utm_medium' => 'email', 'utm_campaign' => 'c' . $campaign_id ], $url );
    }

    /** The email for one person. */
    public static function build_message( array $campaign, int $send_id, int $sub_id, string $email, string $name ): array {
        $settings = self::settings();
        $first    = trim( explode( ' ', trim( $name ) )[0] ?? '' );
        $link     = static fn( string $url ) => self::click_url( $send_id, self::tagged( $url, (int) $campaign['id'] ) );
        $unsub    = self::unsubscribe_url( $sub_id, $email );
        $html     = self::render( (array) $campaign['design'], (string) $campaign['preheader'], $link, self::footer( $unsub ), $first );
        $pixel    = '<img src="' . esc_url( self::base_url() . 'o?' . http_build_query( [ 'i' => $send_id, 'k' => self::sign( 'o' . $send_id ) ] ) ) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';
        $html     = str_replace( '</body>', $pixel . '</body>', $html );
        $subject  = str_replace( [ '{first_name}', '{store_name}' ], [ $first, (string) $settings['from_name'] ], (string) $campaign['subject'] );
        if ( ! empty( $settings['ad_label'] ) && (string) $settings['ad_word'] !== '' ) $subject = $settings['ad_word'] . ': ' . $subject;
        return [ 'to' => $email, 'subject' => $subject, 'html' => $html, 'unsubscribe' => $unsub ];
    }

    /** A test to the owner's own address: not counted, no tracking. */
    public static function send_test( array $campaign, string $to ) {
        if ( ! is_email( $to ) ) return new WP_Error( 'email', 'Enter a valid email address.' );
        $html = self::render( (array) $campaign['design'], (string) $campaign['preheader'], static fn( $u ) => $u, self::footer( home_url( '/' ) ), 'Dana' );
        $ok   = wp_mail( $to, '[Test] ' . $campaign['subject'], $html, [ 'Content-Type: text/html; charset=UTF-8' ] );
        return $ok ? [ 'ok' => true ] : new WP_Error( 'mail', 'The test couldn\'t be sent.' );
    }

    public static function preview_html( array $in ): string {
        $design = self::clean_design( $in['design'] ?? [] );
        return self::render( $design, (string) ( $in['preheader'] ?? '' ), static fn( $u ) => $u, self::footer( '#' ), 'Dana' );
    }

    /** Campaigns whose time came get their list of people, once. */
    private static function start_due(): void {
        global $wpdb;
        $c = self::campaigns_table();
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, segment, segment_days FROM $c WHERE kind = 'campaign' AND status = 'scheduled' AND send_at <= %s", gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A ) as $row ) {
            $id     = (int) $row['id'];
            $where  = self::segment_where( (string) $row['segment'], (int) $row['segment_days'] );
            $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::sends_table() . " (campaign_id, sub_id, email, status) SELECT %d, id, email, 'wait' FROM " . self::subs_table() . " WHERE status = 'sub' AND $where", $id ) );
            $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::sends_table() . ' WHERE campaign_id = %d', $id ) );
            $wpdb->update( $c, [ 'status' => 'sending', 'recipients' => $count ], [ 'id' => $id ] );
        }
    }

    public static function send_due( float $until ) {
        global $wpdb;
        if ( Qwoo_Platform_Dashboard::is_free_plan() ) return;
        self::start_due();
        $s = self::sends_table();
        $c = self::campaigns_table();
        // After a month's allowance ran out: try once an hour, it starts again in the new month or after an upgrade.
        foreach ( (array) $wpdb->get_results( "SELECT id FROM $c WHERE status IN ('limit', 'paused')", ARRAY_A ) as $row ) {
            if ( ! get_transient( 'qwoo_em_limit_wait' ) ) $wpdb->update( $c, [ 'status' => 'sending' ], [ 'id' => $row['id'] ] );
        }
        $settings = self::settings();
        while ( microtime( true ) < $until ) {
            $rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT s.id, s.campaign_id, s.sub_id, s.email, u.name FROM $s s JOIN " . self::subs_table() . " u ON u.id = s.sub_id JOIN $c c ON c.id = s.campaign_id WHERE s.status = 'wait' AND c.status = 'sending' AND u.status = 'sub' ORDER BY s.id LIMIT %d", self::BATCH
            ), ARRAY_A );
            if ( ! $rows ) break;
            $campaigns = [];
            $messages  = [];
            $ids       = [];
            foreach ( $rows as $row ) {
                $cid = (int) $row['campaign_id'];
                $campaigns[ $cid ] = $campaigns[ $cid ] ?? self::campaign_row( $cid );
                if ( ! $campaigns[ $cid ] ) continue;
                $messages[] = self::build_message( $campaigns[ $cid ], (int) $row['id'], (int) $row['sub_id'], (string) $row['email'], (string) $row['name'] );
                $ids[]      = (int) $row['id'];
            }
            $answer = Qwoo_Platform_Connection::marketing_send( [ 'from_name' => $settings['from_name'], 'reply_to' => $settings['reply_to'], 'messages' => $messages ] );
            if ( $answer === null ) {
                self::pause( array_keys( $campaigns ), 'The platform can\'t be reached right now.' );
                break;
            }
            if ( ! empty( $answer['limit'] ) ) {
                set_transient( 'qwoo_em_limit_wait', 1, HOUR_IN_SECONDS );
                foreach ( array_keys( $campaigns ) as $cid ) $wpdb->update( $c, [ 'status' => 'limit' ], [ 'id' => $cid ] );
                break;
            }
            if ( ! empty( $answer['error'] ) ) {
                error_log( '[qwoo email] ' . $answer['error'] );
                self::pause( array_keys( $campaigns ), (string) $answer['error'] );
                break;
            }
            $now = gmdate( 'Y-m-d H:i:s' );
            foreach ( $ids as $id ) {
                $wpdb->update( $s, [ 'status' => 'sent', 'sent_at' => $now ], [ 'id' => $id ] );
            }
        }
        // Those whose status changed while waiting.
        $wpdb->query( "UPDATE $s SET status = 'skipped' WHERE status = 'wait' AND sub_id IN (SELECT id FROM " . self::subs_table() . " WHERE status <> 'sub')" );
        foreach ( (array) $wpdb->get_results( "SELECT id FROM $c WHERE status = 'sending'", ARRAY_A ) as $row ) {
            $left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $s WHERE campaign_id = %d AND status = 'wait'", $row['id'] ) );
            if ( ! $left ) $wpdb->update( $c, [ 'status' => 'sent' ], [ 'id' => $row['id'] ] );
        }
    }

    /** Sending failed: wait a while, and tell the owner why (shown on the email and on Marketing). */
    private static function pause( array $ids, string $why ): void {
        global $wpdb;
        set_transient( 'qwoo_em_limit_wait', 1, 15 * MINUTE_IN_SECONDS );
        update_option( 'qwoo_em_last_error', mb_substr( $why, 0, 300 ), false );
        foreach ( $ids as $id ) $wpdb->update( self::campaigns_table(), [ 'status' => 'paused' ], [ 'id' => (int) $id ] );
    }

    public static function last_error(): string {
        global $wpdb;
        $waiting = $wpdb->get_var( 'SELECT id FROM ' . self::campaigns_table() . " WHERE status = 'paused' LIMIT 1" );
        return $waiting ? (string) get_option( 'qwoo_em_last_error', '' ) : '';
    }

    /** One email from an automation, right now. False when it can't go (not subscribed, the platform refused). */
    public static function send_one( int $campaign_id, string $email ): bool {
        global $wpdb;
        $email = strtolower( trim( $email ) );
        $sub   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, status FROM ' . self::subs_table() . ' WHERE email = %s', $email ), ARRAY_A );
        $row   = self::campaign_row( $campaign_id );
        if ( ! $sub || $sub['status'] !== 'sub' || ! $row || trim( (string) $row['subject'] ) === '' || Qwoo_Platform_Dashboard::is_free_plan() ) return false;
        $wpdb->insert( self::sends_table(), [ 'campaign_id' => $campaign_id, 'sub_id' => (int) $sub['id'], 'email' => $email, 'status' => 'wait' ] );
        $send = (int) $wpdb->insert_id;
        $settings = self::settings();
        $answer = Qwoo_Platform_Connection::marketing_send( [ 'from_name' => $settings['from_name'], 'reply_to' => $settings['reply_to'], 'messages' => [ self::build_message( $row, $send, (int) $sub['id'], $email, (string) $sub['name'] ) ] ] );
        $ok = $answer !== null && empty( $answer['limit'] ) && empty( $answer['error'] );
        $wpdb->update( self::sends_table(), [ 'status' => $ok ? 'sent' : 'failed', 'sent_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $send ] );
        return $ok;
    }

    public static function next_due( $due ) {
        global $wpdb;
        $c = self::campaigns_table();
        if ( $wpdb->get_var( "SELECT id FROM $c WHERE status = 'sending' LIMIT 1" ) ) {
            return time();
        }
        $next = $wpdb->get_var( "SELECT MIN(send_at) FROM $c WHERE status = 'scheduled'" );
        $wait = $wpdb->get_var( "SELECT id FROM $c WHERE status IN ('limit', 'paused') LIMIT 1" );
        $unix = $next ? strtotime( $next . ' UTC' ) : ( $wait ? time() + 15 * MINUTE_IN_SECONDS : 0 );
        if ( ! $unix ) return $due;
        return $due && $due < $unix ? $due : $unix;
    }

    /* ---------------- public addresses (opens, clicks, unsubscribe) ---------------- */

    public static function routes() {
        register_rest_route( self::NS, '/m/o', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_open' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( self::NS, '/m/c', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_click' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( self::NS, '/m/u', [ 'methods' => [ 'GET', 'POST' ], 'callback' => [ __CLASS__, 'rest_unsubscribe' ], 'permission_callback' => '__return_true' ] );
    }

    public static function rest_open( WP_REST_Request $r ) {
        global $wpdb;
        $id = (int) $r->get_param( 'i' );
        if ( $id && hash_equals( self::sign( 'o' . $id ), (string) $r->get_param( 'k' ) ) ) {
            $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::sends_table() . ' SET opened_at = %s WHERE id = %d AND opened_at IS NULL', gmdate( 'Y-m-d H:i:s' ), $id ) );
        }
        header( 'Content-Type: image/gif' );
        header( 'Cache-Control: no-store' );
        echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore
        exit;
    }

    /** Only addresses this store signed: never an open redirect. */
    public static function rest_click( WP_REST_Request $r ) {
        global $wpdb;
        $id  = (int) $r->get_param( 'i' );
        $url = (string) $r->get_param( 'u' );
        if ( ! $id || ! hash_equals( self::sign( 'c' . $id . $url ), (string) $r->get_param( 'k' ) ) ) {
            return new WP_Error( 'bad_link', 'This link isn\'t valid.', [ 'status' => 404 ] );
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::sends_table() . ' SET clicked_at = %s WHERE id = %d AND clicked_at IS NULL', $now, $id ) );
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::sends_table() . ' SET opened_at = %s WHERE id = %d AND opened_at IS NULL', $now, $id ) );
        header( 'Location: ' . esc_url_raw( $url ), true, 302 );
        header( 'Cache-Control: no-store' );
        exit;
    }

    /**
     * GET shows a confirmation with one button (mail scanners follow links, and
     * must not unsubscribe anyone); POST unsubscribes: that button, or the mail
     * app's own one-click button (List-Unsubscribe-Post).
     */
    public static function rest_unsubscribe( WP_REST_Request $r ) {
        global $wpdb;
        $sub = (int) $r->get_param( 's' );
        $key = (string) $r->get_param( 'k' );
        $email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . self::subs_table() . ' WHERE id = %d', $sub ) );
        $good  = $email !== '' && hash_equals( self::sign( 'u' . $sub . strtolower( $email ) ), $key );
        $he    = class_exists( 'Qwoo_Push' ) && Qwoo_Push::main_lang() === 'he';
        $text  = static fn( $en, $hebrew ) => $he ? $hebrew : $en;
        $store = esc_html( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
        if ( $good && $r->get_method() === 'POST' ) {
            self::suppress( $email, 'unsub' );
            $body = '<h1>' . esc_html( $text( 'You\'re unsubscribed', 'הוסרת מהרשימה' ) ) . '</h1><p>' . esc_html( $text( 'You won\'t get marketing emails from us any more.', 'לא נשלח לך יותר דיוור שיווקי.' ) ) . '</p>';
        } elseif ( $good ) {
            $action = esc_url( self::base_url() . 'u?' . http_build_query( [ 's' => $sub, 'k' => $key ] ) );
            $body = '<h1>' . esc_html( $text( 'Unsubscribe?', 'להסיר אותך מהרשימה?' ) ) . '</h1><p>' . esc_html( $text( 'You\'ll stop getting marketing emails. Emails about your orders still arrive.', 'לא יישלח אליך יותר דיוור שיווקי. מיילים על ההזמנות שלך ימשיכו להגיע.' ) ) . '</p><form method="post" action="' . $action . '"><button type="submit">' . esc_html( $text( 'Unsubscribe', 'הסרה מהרשימה' ) ) . '</button></form>';
        } else {
            $body = '<h1>' . esc_html( $text( 'This link isn\'t valid', 'הקישור לא תקין' ) ) . '</h1>';
        }
        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'Cache-Control: no-store' );
        header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'" );
        echo '<!doctype html><html dir="' . ( $he ? 'rtl' : 'ltr' ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $store . '</title><style>body{font-family:system-ui,Arial,sans-serif;background:#f4f4f7;color:#1d1b2e;display:grid;place-items:center;min-height:90vh;margin:0}main{background:#fff;padding:32px;border-radius:12px;max-width:420px;margin:16px;text-align:center}h1{font-size:22px;margin:0 0 10px}button{margin-top:14px;padding:12px 22px;border:0;border-radius:8px;background:#1d1b2e;color:#fff;font-size:15px;cursor:pointer}</style></head><body><main><p>' . $store . '</p>' . $body . '</main></body></html>'; // phpcs:ignore
        exit;
    }
}

Qwoo_Email_Marketing::init();

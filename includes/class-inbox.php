<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The store's Inbox: messages from shoppers, answered by the owner from the
 * dashboard (Customers → Inbox).
 *
 *   in    contact forms set to "Collect in your Inbox" (trait-sb-forms.php), the
 *         storefront's Help button ("Write to us"), and the shopper's own
 *         replies: on their conversation page, in the Help panel, or in
 *         My account → Messages
 *   out   the owner's answer goes to the shopper by email, with a link to the
 *         conversation page, where they read the thread and answer
 *   alert a new message or reply tells the owner: on their phone (the owner
 *         app, Qwoo_Owner_Alerts) and by email (a setting)
 *
 * The conversation page is served here and reached through the storefront's
 * address (/wp-json/qwoo/v1/inbox/t?k=…), like the unsubscribe page. Its link
 * is the key: "<id>.<hmac of id + email>", valid while the conversation was
 * active in the last 180 days. It never carries the email.
 *
 * Everything a shopper writes is plain text and is escaped wherever it shows.
 * Conversations are personal data: removed after "keep_months", and with the
 * shopper's account.
 *
 * The Help button (settings → help; Pro and Plus): the storefront gets its look
 * and texts with the tracking settings (Qwoo_Custom_Code::storefront_payload →
 * help), and talks to the routes at the end of this file: write a message,
 * read and answer a conversation (by its key), the signed-in shopper's own
 * conversations, and "Where is my order?" (order number + the order's email,
 * the same answer for any wrong pair, status only).
 *
 *   tables  qwoo_conversations, qwoo_inbox_messages
 *   option  qwoo_inbox_settings
 */
class Qwoo_Inbox {

    const NS         = 'qwoo/v1';
    const OPTION     = 'qwoo_inbox_settings';
    const DB_OPTION  = 'qwoo_inbox_db';
    const DB_VERSION = '1';
    const MAX_TEXT   = 4000;
    const LINK_DAYS  = 180;
    const STATUSES   = [ 'open', 'waiting', 'solved' ];
    const HELP_STYLES = [ 'pill', 'round', 'tab' ];
    const HELP_EDGES  = [ 'start', 'end', 'bottom' ];
    const DAY_CAP     = 300; // messages a day from the Help button, per store

    public static function init() {
        add_action( 'init', [ __CLASS__, 'maybe_install' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
        add_action( 'deleted_user', [ __CLASS__, 'forget_user' ], 10, 3 );
    }

    public static function conversations() { global $wpdb; return $wpdb->prefix . 'qwoo_conversations'; }
    public static function messages() { global $wpdb; return $wpdb->prefix . 'qwoo_inbox_messages'; }

    public static function maybe_install() {
        if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( 'CREATE TABLE ' . self::conversations() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL DEFAULT '',
        name VARCHAR(120) NOT NULL DEFAULT '',
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        topic VARCHAR(120) NOT NULL DEFAULT '',
        source VARCHAR(20) NOT NULL DEFAULT 'form',
        page VARCHAR(190) NOT NULL DEFAULT '',
        order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        unread TINYINT UNSIGNED NOT NULL DEFAULT 1,
        preview VARCHAR(200) NOT NULL DEFAULT '',
        last_author VARCHAR(10) NOT NULL DEFAULT 'shopper',
        lang VARCHAR(8) NOT NULL DEFAULT '',
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY status_updated (status,updated),
        KEY email (email)
        ) $charset;" );
        dbDelta( 'CREATE TABLE ' . self::messages() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        conv_id BIGINT UNSIGNED NOT NULL,
        author VARCHAR(10) NOT NULL DEFAULT 'shopper',
        body TEXT NOT NULL,
        created DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY conv_id (conv_id,id)
        ) $charset;" );
        update_option( self::DB_OPTION, self::DB_VERSION, false );
    }

    /* ---------------- settings ---------------- */

    public static function settings() {
        $s = get_option( self::OPTION, [] );
        $s = is_array( $s ) ? $s : [];
        return [
            'notify_email' => ! array_key_exists( 'notify_email', $s ) || ! empty( $s['notify_email'] ),
            'notify_to'    => is_email( $s['notify_to'] ?? '' ) ? $s['notify_to'] : '',
            'auto_reply'   => ! array_key_exists( 'auto_reply', $s ) || ! empty( $s['auto_reply'] ),
            'auto_text'    => (string) ( $s['auto_text'] ?? '' ),
            'answer_time'  => (string) ( $s['answer_time'] ?? '' ),
            'saved'        => array_values( array_filter( (array) ( $s['saved'] ?? [] ), 'is_array' ) ),
            'keep_months'  => min( 60, max( 3, (int) ( $s['keep_months'] ?? 24 ) ) ),
            'help'         => self::clean_help( $s['help'] ?? [] ),
            'help_allowed' => self::help_allowed(),
        ];
    }

    /** The Help button is part of the Pro and Plus plans (like Audiences). */
    private static function help_allowed(): bool {
        return ! class_exists( 'Qwoo_Personalization' ) || Qwoo_Personalization::plan_allows();
    }

    /** The Help button's settings, cleaned (saving and reading use the same rules). */
    private static function clean_help( $h ): array {
        $h    = is_array( $h ) ? $h : [];
        $text = static fn( $v, $max ) => mb_substr( sanitize_text_field( is_scalar( $v ) ? (string) $v : '' ), 0, $max );
        $on   = static fn( $key ) => ! array_key_exists( $key, $h ) || ! empty( $h[ $key ] );
        $topics = [];
        foreach ( array_slice( (array) ( $h['topics'] ?? [] ), 0, 8 ) as $topic ) {
            $topic = $text( $topic, 40 );
            if ( $topic !== '' ) $topics[] = $topic;
        }
        $faq = [];
        foreach ( array_slice( (array) ( $h['faq'] ?? [] ), 0, 20 ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $q = $text( $row['q'] ?? '', 120 );
            $a = mb_substr( trim( sanitize_textarea_field( is_scalar( $row['a'] ?? '' ) ? (string) $row['a'] : '' ) ), 0, 800 );
            if ( $q !== '' && $a !== '' ) $faq[] = [ 'q' => $q, 'a' => $a ];
        }
        return [
            'on'       => ! empty( $h['on'] ),
            'style'    => in_array( $h['style'] ?? '', self::HELP_STYLES, true ) ? $h['style'] : 'tab',
            'edge'     => in_array( $h['edge'] ?? '', self::HELP_EDGES, true ) ? $h['edge'] : 'end',
            'position' => min( 88, max( 12, (int) ( $h['position'] ?? 50 ) ) ),
            'label'    => $text( $h['label'] ?? '', 30 ),
            'title'    => $text( $h['title'] ?? '', 60 ),
            'bg'       => (string) ( sanitize_hex_color( is_scalar( $h['bg'] ?? '' ) ? (string) $h['bg'] : '' ) ?: '' ),
            'color'    => (string) ( sanitize_hex_color( is_scalar( $h['color'] ?? '' ) ? (string) $h['color'] : '' ) ?: '' ),
            'answers'  => $on( 'answers' ),
            'order'    => $on( 'order' ),
            'write'    => $on( 'write' ),
            'topics'   => $topics,
            'faq'      => $faq,
        ];
    }

    /** For the storefront (with the tracking settings): the Help button, or null when it's off. */
    public static function help_payload(): ?array {
        $s = get_option( self::OPTION, [] );
        $h = self::clean_help( is_array( $s ) ? ( $s['help'] ?? [] ) : [] );
        if ( ! $h['on'] || ! self::help_allowed() ) return null;
        unset( $h['on'] );
        return $h + [ 'answer_time' => mb_substr( sanitize_text_field( (string) ( is_array( $s ) ? ( $s['answer_time'] ?? '' ) : '' ) ), 0, 80 ) ];
    }

    public static function save_settings( array $in ) {
        $before = get_option( self::OPTION, [] );
        $saved  = [];
        foreach ( array_slice( (array) ( $in['saved'] ?? [] ), 0, 20 ) as $row ) {
            $title = mb_substr( sanitize_text_field( (string) ( $row['title'] ?? '' ) ), 0, 60 );
            $text  = self::clean( (string) ( $row['text'] ?? '' ) );
            if ( $title !== '' && $text !== '' ) $saved[] = [ 'title' => $title, 'text' => $text ];
        }
        update_option( self::OPTION, [
            'notify_email' => ! empty( $in['notify_email'] ),
            'notify_to'    => sanitize_email( (string) ( $in['notify_to'] ?? '' ) ),
            'auto_reply'   => ! empty( $in['auto_reply'] ),
            'auto_text'    => mb_substr( self::clean( (string) ( $in['auto_text'] ?? '' ) ), 0, 600 ),
            'answer_time'  => mb_substr( sanitize_text_field( (string) ( $in['answer_time'] ?? '' ) ), 0, 80 ),
            'saved'        => $saved,
            'keep_months'  => min( 60, max( 3, (int) ( $in['keep_months'] ?? 24 ) ) ),
            // Not sent (an older dashboard): the Help button stays as it is.
            'help'         => self::clean_help( array_key_exists( 'help', $in ) ? $in['help'] : ( is_array( $before ) ? ( $before['help'] ?? [] ) : [] ) ),
        ], false );
        // The storefront reads its settings again (the Help button travels with them).
        if ( class_exists( 'Qwoo_Custom_Code' ) ) Qwoo_Custom_Code::touch();
        return self::settings();
    }

    /* ---------------- helpers ---------------- */

    /** Plain text, cut to size. */
    private static function clean( string $text ): string {
        return mb_substr( trim( sanitize_textarea_field( $text ) ), 0, self::MAX_TEXT );
    }

    private static function now(): string { return gmdate( 'Y-m-d H:i:s' ); }

    private static function he(): bool {
        return class_exists( 'Qwoo_Push' ) && Qwoo_Push::main_lang() === 'he';
    }

    private static function store_name(): string {
        return html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
    }

    private static function key( int $id, string $email ): string {
        return $id . '.' . substr( hash_hmac( 'sha256', 'inbox|' . $id . '|' . strtolower( $email ), wp_salt( 'auth' ) ), 0, 40 );
    }

    /** The shopper's page for a conversation, through the storefront's address. */
    public static function thread_url( array $conv ): string {
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? rtrim( (string) Qwoo_Technical_Settings::get_primary_frontend_domain(), '/' ) : '';
        $base  = $front !== '' ? $front . '/wp-json/' . self::NS . '/inbox/t' : rest_url( self::NS . '/inbox/t' );
        return $base . '?k=' . rawurlencode( self::key( (int) $conv['id'], (string) $conv['email'] ) );
    }

    private static function conv( int $id ): ?array {
        global $wpdb;
        $row = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::conversations() . ' WHERE id = %d', $id ), ARRAY_A ) : null;
        return $row ?: null;
    }

    /** The conversation a page link opens, or null (wrong key, or too old). */
    private static function conv_by_key( string $k ): ?array {
        if ( ! preg_match( '/^(\d{1,12})\.[a-f0-9]{40}$/', $k, $m ) ) return null;
        $conv = self::conv( (int) $m[1] );
        if ( ! $conv || ! hash_equals( self::key( (int) $conv['id'], (string) $conv['email'] ), $k ) ) return null;
        return strtotime( $conv['updated'] . ' UTC' ) > time() - self::LINK_DAYS * DAY_IN_SECONDS ? $conv : null;
    }

    private static function add_message( int $conv_id, string $author, string $body ) {
        global $wpdb;
        $wpdb->insert( self::messages(), [ 'conv_id' => $conv_id, 'author' => $author, 'body' => $body, 'created' => self::now() ] );
        if ( $author === 'note' ) return;
        $wpdb->update( self::conversations(), [
            'preview'     => mb_substr( preg_replace( '/\s+/', ' ', $body ), 0, 200 ),
            'last_author' => $author,
            'updated'     => self::now(),
        ], [ 'id' => $conv_id ] );
    }

    /* ---------------- a new message ---------------- */

    /**
     * A contact form set to "Collect in your Inbox". $fields: the form's cleaned
     * values [ key => { label, value } ]. Returns the conversation's id, or 0
     * when it couldn't be kept (the form then emails the owner as before).
     */
    public static function from_form( array $fields, string $email, string $page, array $form ): int {
        $email = strtolower( sanitize_email( $email ) );
        if ( ! is_email( $email ) ) return 0;
        $name  = '';
        $lines = [];
        foreach ( $fields as $item ) {
            $label = (string) ( $item['label'] ?? '' );
            $value = is_scalar( $item['value'] ?? '' ) ? trim( (string) $item['value'] ) : '';
            if ( $value === '' || strtolower( $value ) === $email ) continue;
            if ( $name === '' && preg_match( '/name|שם/iu', $label ) && mb_strlen( $value ) <= 120 ) {
                $name = $value;
                continue;
            }
            $lines[] = $label !== '' ? $label . ': ' . $value : $value;
        }
        // One field left (the message itself): no label in front of it.
        $body = count( $lines ) === 1 ? preg_replace( '/^[^:\n]{1,60}: /u', '', $lines[0] ) : implode( "\n", $lines );
        $body = self::clean( (string) $body );
        if ( $body === '' ) return 0;
        $topic = mb_substr( sanitize_text_field( (string) ( $form['email_subject'] ?? '' ) ), 0, 120 );
        return self::open( [
            'email'  => $email,
            'name'   => mb_substr( sanitize_text_field( $name ), 0, 120 ),
            'topic'  => $topic === 'New form submission' ? '' : $topic,
            'source' => 'form',
            'page'   => mb_substr( sanitize_text_field( $page ), 0, 190 ),
        ], $body );
    }

    /** Opens a conversation with its first message, tells the owner, and confirms to the shopper. */
    private static function open( array $conv, string $body ): int {
        global $wpdb;
        $user = get_user_by( 'email', $conv['email'] );
        $ok   = $wpdb->insert( self::conversations(), $conv + [
            'user_id' => $user ? (int) $user->ID : 0,
            'status'  => 'open',
            'unread'  => 1,
            'lang'    => class_exists( 'Qwoo_Push_Engine' ) ? mb_substr( (string) Qwoo_Push_Engine::request_lang(), 0, 8 ) : '',
            'created' => self::now(),
            'updated' => self::now(),
        ] );
        if ( ! $ok ) {
            error_log( '[qwoo inbox] could not save a conversation: ' . $wpdb->last_error ); // phpcs:ignore
            return 0;
        }
        $id = (int) $wpdb->insert_id;
        self::add_message( $id, 'shopper', $body );
        $conv = self::conv( $id );
        self::tell_owner( $conv, $body, true );
        self::confirm_to_shopper( $conv );
        return $id;
    }

    /** The owner's phone (the owner app) and, when on, an email. */
    private static function tell_owner( array $conv, string $body, bool $first ) {
        $who = $conv['name'] !== '' ? $conv['name'] : $conv['email'];
        if ( class_exists( 'Qwoo_Owner_Alerts' ) && method_exists( 'Qwoo_Owner_Alerts', 'message' ) ) {
            Qwoo_Owner_Alerts::message( (int) $conv['id'], $who, $body );
        }
        $s = self::settings();
        if ( ! $s['notify_email'] ) return;
        $to = $s['notify_to'] !== '' ? $s['notify_to'] : (string) get_option( 'admin_email' );
        $he = self::he();
        $subject = ( $he ? ( $first ? 'הודעה חדשה מ־' : 'תשובה חדשה מ־' ) : ( $first ? 'New message from ' : 'New reply from ' ) ) . $who;
        $text    = $body . "\n\n— " . $who . ' <' . $conv['email'] . ">\n\n"
            . ( $he ? 'עונים מלוח הניהול: לקוחות ← תיבת פניות. תשובה למייל הזה לא תישמר שם.' : 'Answer from your dashboard: Customers → Inbox. A reply to this email isn\'t kept there.' );
        wp_mail( $to, $subject, $text );
    }

    /** "We got it" with the link to the conversation: at most one per address every 10 minutes. */
    private static function confirm_to_shopper( array $conv ) {
        $s = self::settings();
        if ( ! $s['auto_reply'] ) return;
        $gate = 'qwoo_inbox_ar_' . md5( $conv['email'] );
        if ( get_transient( $gate ) ) return;
        set_transient( $gate, 1, 10 * MINUTE_IN_SECONDS );
        $he   = self::he();
        $text = $s['auto_text'] !== '' ? $s['auto_text'] : ( $he ? 'קיבלנו את ההודעה שלך ונענה בהקדם.' : 'We got your message and will answer soon.' );
        if ( $s['answer_time'] !== '' ) $text .= "\n" . $s['answer_time'];
        self::mail_shopper( $conv, $he ? 'קיבלנו את ההודעה שלך' : 'We got your message', $text );
    }

    /** An email to the shopper in the store's email look, with the button to their conversation page. */
    private static function mail_shopper( array $conv, string $heading, string $text ): bool {
        $he     = self::he();
        $button = $he ? 'לצפייה ולתשובה' : 'Read and answer';
        $html   = '<p>' . nl2br( esc_html( $text ) ) . '</p>'
            . '<p style="margin:24px 0"><a href="' . esc_url( self::thread_url( $conv ) ) . '" style="display:inline-block;padding:12px 22px;border-radius:8px;background:#1d1b2e;color:#ffffff;text-decoration:none;font-weight:600">' . esc_html( $button ) . '</a></p>'
            . '<p style="color:#6b6880;font-size:13px">' . esc_html( $he ? 'כדי לענות, לחצו על הכפתור. תשובה למייל הזה עלולה לא להגיע אלינו.' : 'To answer, use the button. A reply to this email may not reach us.' ) . '</p>';
        $subject = ( $conv['topic'] !== '' ? $conv['topic'] : ( $he ? 'ההודעה שלך אל ' : 'Your message to ' ) . self::store_name() );
        try {
            $mailer = WC()->mailer();
            return (bool) $mailer->send( $conv['email'], $subject, $mailer->wrap_message( $heading, $html ), "Content-Type: text/html\r\n" );
        } catch ( \Throwable $e ) {
            error_log( '[qwoo inbox] email: ' . $e->getMessage() ); // phpcs:ignore
            return false;
        }
    }

    /* ---------------- the dashboard ---------------- */

    private static function row( array $c ): array {
        return [
            'id'          => (int) $c['id'],
            'name'        => $c['name'],
            'email'       => $c['email'],
            'topic'       => $c['topic'],
            'source'      => $c['source'],
            'page'        => $c['page'],
            'order_id'    => (int) $c['order_id'],
            'product_id'  => (int) $c['product_id'],
            'product'     => (int) $c['product_id'] ? html_entity_decode( (string) get_the_title( (int) $c['product_id'] ), ENT_QUOTES ) : '',
            'status'      => $c['status'],
            'unread'      => (bool) $c['unread'],
            'preview'     => $c['preview'],
            'last_author' => $c['last_author'],
            'created'     => strtotime( $c['created'] . ' UTC' ),
            'updated'     => strtotime( $c['updated'] . ' UTC' ),
        ];
    }

    public static function counts(): array {
        global $wpdb;
        $t    = self::conversations();
        $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n, SUM(unread) AS u FROM $t GROUP BY status", ARRAY_A );
        $out  = [ 'open' => 0, 'waiting' => 0, 'solved' => 0, 'unread' => 0 ];
        foreach ( $rows ?: [] as $r ) {
            if ( isset( $out[ $r['status'] ] ) ) $out[ $r['status'] ] = (int) $r['n'];
            $out['unread'] += (int) $r['u'];
        }
        return $out;
    }

    /** { filter: active | solved | all, search, page } */
    public static function listing( array $p ): array {
        global $wpdb;
        self::cleanup();
        $t      = self::conversations();
        $filter = (string) ( $p['filter'] ?? 'active' );
        $where  = [ '1=1' ];
        $args   = [];
        if ( $filter === 'active' ) $where[] = "status <> 'solved'";
        elseif ( $filter === 'solved' ) $where[] = "status = 'solved'";
        $search = trim( sanitize_text_field( (string) ( $p['search'] ?? '' ) ) );
        if ( $search !== '' ) {
            $like    = '%' . $wpdb->esc_like( mb_substr( $search, 0, 100 ) ) . '%';
            // Who wrote, the topic, or anything said in the conversation (notes too).
            $where[] = '( email LIKE %s OR name LIKE %s OR topic LIKE %s OR id IN ( SELECT conv_id FROM ' . self::messages() . ' WHERE body LIKE %s ) )';
            array_push( $args, $like, $like, $like, $like );
        }
        $per    = 30;
        $page   = max( 1, (int) ( $p['page'] ?? 1 ) );
        $sql    = "SELECT * FROM $t WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated DESC LIMIT %d OFFSET %d';
        $args[] = $per + 1;
        $args[] = ( $page - 1 ) * $per;
        $rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: [];
        return [
            'items'  => array_map( [ __CLASS__, 'row' ], array_slice( $rows, 0, $per ) ),
            'more'   => count( $rows ) > $per,
            'page'   => $page,
            'counts' => self::counts(),
        ];
    }

    /** One conversation with its messages and what the store knows about the shopper. Opening it marks it read. */
    public static function get( int $id ) {
        global $wpdb;
        $conv = self::conv( $id );
        if ( ! $conv ) return new WP_Error( 'missing', 'This conversation doesn\'t exist any more.' );
        if ( $conv['unread'] ) {
            $wpdb->update( self::conversations(), [ 'unread' => 0 ], [ 'id' => $id ] );
            $conv['unread'] = 0;
        }
        $messages = $wpdb->get_results( $wpdb->prepare( 'SELECT id, author, body, created FROM ' . self::messages() . ' WHERE conv_id = %d ORDER BY id ASC LIMIT 500', $id ), ARRAY_A ) ?: [];
        $orders   = wc_get_orders( [ 'billing_email' => $conv['email'], 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC', 'status' => array_keys( wc_get_order_statuses() ) ] );
        $spent    = 0.0;
        $list     = [];
        foreach ( $orders as $order ) {
            if ( in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) $spent += (float) $order->get_total();
            $list[] = [ 'id' => $order->get_id(), 'number' => (string) $order->get_order_number(), 'status' => wc_get_order_status_name( $order->get_status() ), 'total' => trim( html_entity_decode( wp_strip_all_tags( wc_price( (float) $order->get_total(), [ 'currency' => $order->get_currency() ] ) ), ENT_QUOTES, 'UTF-8' ) ), 'date' => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0 ];
        }
        return [
            'conversation' => self::row( $conv ),
            'messages'     => array_map( static fn( $m ) => [ 'id' => (int) $m['id'], 'author' => $m['author'], 'body' => $m['body'], 'time' => strtotime( $m['created'] . ' UTC' ) ], $messages ),
            'customer'     => [ 'account' => (int) $conv['user_id'] > 0, 'orders' => $list, 'spent_recent' => trim( html_entity_decode( wp_strip_all_tags( wc_price( $spent ) ), ENT_QUOTES, 'UTF-8' ) ) ],
            'saved'        => self::settings()['saved'],
            'counts'       => self::counts(),
        ];
    }

    /** { id, text, solve }: the owner's answer, emailed to the shopper. */
    public static function reply( array $p ) {
        global $wpdb;
        $conv = self::conv( (int) ( $p['id'] ?? 0 ) );
        if ( ! $conv ) return new WP_Error( 'missing', 'This conversation doesn\'t exist any more.' );
        $text = self::clean( (string) ( $p['text'] ?? '' ) );
        if ( $text === '' ) return new WP_Error( 'empty', 'Write your answer first.' );
        $limited = function_exists( 'qwoo_rate_limit_check' ) ? qwoo_rate_limit_check( 'inbox_reply', 60, 10 * MINUTE_IN_SECONDS, 'owner' ) : true;
        if ( is_wp_error( $limited ) ) return new WP_Error( 'limit', 'That\'s a lot of answers in a short time. Wait a few minutes.' );
        self::add_message( (int) $conv['id'], 'owner', $text );
        $wpdb->update( self::conversations(), [ 'status' => empty( $p['solve'] ) ? 'waiting' : 'solved', 'unread' => 0 ], [ 'id' => $conv['id'] ] );
        $sent = self::mail_shopper( $conv, ( self::he() ? 'תשובה מ־' : 'An answer from ' ) . self::store_name(), $text );
        return self::get( (int) $conv['id'] ) + [ 'emailed' => $sent ];
    }

    /** { id, text }: a note only the owner sees. */
    public static function note( array $p ) {
        $conv = self::conv( (int) ( $p['id'] ?? 0 ) );
        if ( ! $conv ) return new WP_Error( 'missing', 'This conversation doesn\'t exist any more.' );
        $text = self::clean( (string) ( $p['text'] ?? '' ) );
        if ( $text === '' ) return new WP_Error( 'empty', 'Write the note first.' );
        self::add_message( (int) $conv['id'], 'note', $text );
        return self::get( (int) $conv['id'] );
    }

    /** { id, status: open | waiting | solved }, or { id, delete: true }. */
    public static function set_status( array $p ) {
        global $wpdb;
        $conv = self::conv( (int) ( $p['id'] ?? 0 ) );
        if ( ! $conv ) return new WP_Error( 'missing', 'This conversation doesn\'t exist any more.' );
        if ( ! empty( $p['delete'] ) ) {
            $wpdb->delete( self::messages(), [ 'conv_id' => $conv['id'] ] );
            $wpdb->delete( self::conversations(), [ 'id' => $conv['id'] ] );
            return [ 'deleted' => (int) $conv['id'], 'counts' => self::counts() ];
        }
        $status = (string) ( $p['status'] ?? '' );
        if ( ! in_array( $status, self::STATUSES, true ) ) return new WP_Error( 'status', 'Choose a status.' );
        $wpdb->update( self::conversations(), [ 'status' => $status, 'unread' => 0 ], [ 'id' => $conv['id'] ] );
        return self::get( (int) $conv['id'] );
    }

    /** Old conversations go, once a day at most (the owner's "keep for" setting). */
    private static function cleanup() {
        if ( get_transient( 'qwoo_inbox_clean' ) ) return;
        set_transient( 'qwoo_inbox_clean', 1, DAY_IN_SECONDS );
        global $wpdb;
        $before = gmdate( 'Y-m-d H:i:s', time() - self::settings()['keep_months'] * 30 * DAY_IN_SECONDS );
        $ids    = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::conversations() . ' WHERE updated < %s LIMIT 500', $before ) );
        self::delete_ids( $ids );
    }

    private static function delete_ids( array $ids ) {
        global $wpdb;
        foreach ( array_map( 'intval', $ids ) as $id ) {
            $wpdb->delete( self::messages(), [ 'conv_id' => $id ] );
            $wpdb->delete( self::conversations(), [ 'id' => $id ] );
        }
    }

    /** A deleted account takes its conversations with it. */
    public static function forget_user( $user_id, $reassign = null, $user = null ) {
        global $wpdb;
        $t   = self::conversations();
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE user_id = %d", (int) $user_id ) );
        if ( $user instanceof WP_User && is_email( $user->user_email ) ) {
            $ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE email = %s", strtolower( $user->user_email ) ) ) );
        }
        self::delete_ids( array_unique( $ids ) );
    }

    /* ---------------- the shopper's conversation page ---------------- */

    public static function routes() {
        register_rest_route( self::NS, '/inbox/t', [ 'methods' => [ 'GET', 'POST' ], 'callback' => [ __CLASS__, 'rest_thread' ], 'permission_callback' => '__return_true' ] );
        // The storefront's Help panel and My account → Messages. Limits are checked inside each callback.
        register_rest_route( self::NS, '/inbox/write', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_write' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( self::NS, '/inbox/order', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'rest_order' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( self::NS, '/inbox/thread', [ 'methods' => [ 'GET', 'POST' ], 'callback' => [ __CLASS__, 'rest_thread_json' ], 'permission_callback' => '__return_true' ] );
        register_rest_route( self::NS, '/inbox/mine', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'rest_mine' ], 'permission_callback' => 'qwoo_require_login' ] );
    }

    /* ---------------- the storefront: Help panel, My account → Messages ---------------- */

    private static function limited( string $action, int $max, int $seconds, ?string $who = null ): bool {
        return function_exists( 'qwoo_rate_limit_check' ) && is_wp_error( qwoo_rate_limit_check( $action, $max, $seconds, $who ) );
    }

    private static function too_many() {
        return new WP_Error( 'rate', self::he() ? 'יותר מדי ניסיונות בזמן קצר. נסו שוב בעוד כמה דקות.' : 'Too many tries in a short time. Try again in a few minutes.', [ 'status' => 429 ] );
    }

    /** What a shopper may know about an order they proved is theirs: its status. Never the address, the items or the amounts. */
    private static function order_view( $order ): array {
        $status = (string) $order->get_status();
        $made   = $order->get_date_created();
        $method = '';
        foreach ( $order->get_shipping_methods() as $m ) { $method = html_entity_decode( wp_strip_all_tags( (string) $m->get_name() ), ENT_QUOTES ); break; }
        return [
            'number'   => (string) $order->get_order_number(),
            'date'     => $made ? wp_date( get_option( 'date_format' ), $made->getTimestamp() ) : '',
            'status'   => $status,
            'label'    => html_entity_decode( (string) wc_get_order_status_name( $status ), ENT_QUOTES ),
            'paid'     => (bool) $order->is_paid(),
            'done'     => $status === 'completed',
            'stopped'  => in_array( $status, [ 'cancelled', 'refunded', 'failed' ], true ),
            'delivery' => $method,
        ];
    }

    /** The order of a number + email pair, or null. The same null for "no such order" and "not that email". */
    private static function order_for( string $number, string $email ) {
        $id = (int) preg_replace( '/\D/', '', $number );
        if ( ! $id || ! is_email( $email ) || ! function_exists( 'wc_get_order' ) ) return null;
        $order = wc_get_order( $id );
        if ( ! $order || ! ( $order instanceof WC_Order ) || $order->get_type() !== 'shop_order' ) return null;
        $owner = strtolower( (string) $order->get_billing_email() );
        return $owner !== '' && hash_equals( $owner, strtolower( $email ) ) ? $order : null;
    }

    /**
     * "Where is my order?": { number, email } → { found, order }. Limited per
     * visitor, per email and per order number, and a wrong pair always gets the
     * same answer, so nobody can find out which orders or emails exist.
     */
    public static function rest_order( WP_REST_Request $r ) {
        $help   = self::help_payload();
        if ( ! $help || empty( $help['order'] ) ) return new WP_Error( 'off', 'Not available.', [ 'status' => 404 ] );
        $number = mb_substr( preg_replace( '/[^0-9]/', '', (string) $r->get_param( 'number' ) ), 0, 12 );
        $email  = strtolower( sanitize_email( (string) $r->get_param( 'email' ) ) );
        if ( self::limited( 'inbox_order', 8, 10 * MINUTE_IN_SECONDS, $email ?: null ) || self::limited( 'inbox_order_n', 12, HOUR_IN_SECONDS, 'n' . $number ) ) return self::too_many();
        $order  = $number !== '' ? self::order_for( $number, $email ) : null;
        return rest_ensure_response( $order ? [ 'found' => true, 'order' => self::order_view( $order ) ] : [ 'found' => false ] );
    }

    /**
     * "Write to us": { message, topic, email, name, page, product_id, order,
     * order_email, website (the bot field) } → { ok, k }. k is the key of the new
     * conversation, so the panel can show it and wait for the answer.
     */
    public static function rest_write( WP_REST_Request $r ) {
        $he   = self::he();
        $help = self::help_payload();
        if ( ! $help || empty( $help['write'] ) ) return new WP_Error( 'off', 'Not available.', [ 'status' => 404 ] );
        // A bot filled the hidden field: it hears "sent" and nothing is kept.
        if ( (string) $r->get_param( 'website' ) !== '' ) return rest_ensure_response( [ 'ok' => true ] );
        $user  = is_user_logged_in() ? wp_get_current_user() : null;
        $email = $user ? strtolower( (string) $user->user_email ) : strtolower( sanitize_email( (string) $r->get_param( 'email' ) ) );
        if ( ! is_email( $email ) ) return new WP_Error( 'email', $he ? 'צריך כתובת אימייל תקינה כדי שנוכל לענות.' : 'We need a valid email address to answer you.', [ 'status' => 400 ] );
        $body = self::clean( (string) $r->get_param( 'message' ) );
        if ( mb_strlen( $body ) < 2 ) return new WP_Error( 'message', $he ? 'כתבו את ההודעה שלכם.' : 'Write your message.', [ 'status' => 400 ] );
        if ( self::limited( 'inbox_write', 5, 10 * MINUTE_IN_SECONDS, $email ) ) return self::too_many();
        // The whole store, per day: a flood can't fill the Inbox or the owner's mailbox.
        $day_key = 'qwoo_inbox_day_' . gmdate( 'Ymd' );
        $today   = (int) get_transient( $day_key );
        if ( $today >= self::DAY_CAP ) return new WP_Error( 'busy', $he ? 'לא הצלחנו לקבל את ההודעה כרגע. נסו שוב מאוחר יותר.' : 'We couldn\'t take your message right now. Please try again later.', [ 'status' => 429 ] );
        set_transient( $day_key, $today + 1, DAY_IN_SECONDS );

        // Only a topic from the owner's list (or the ready-made ones the panel shows).
        $topic  = mb_substr( sanitize_text_field( (string) $r->get_param( 'topic' ) ), 0, 40 );
        $topics = $help['topics'] ?: [ 'Before buying', 'An order', 'Returns', 'לפני קנייה', 'הזמנה', 'החזרות' ];
        if ( ! in_array( $topic, $topics, true ) ) $topic = '';
        $path = (string) wp_parse_url( '/' . ltrim( (string) $r->get_param( 'page' ), '/' ), PHP_URL_PATH );
        $product_id = absint( $r->get_param( 'product_id' ) );
        if ( $product_id && ( get_post_type( $product_id ) !== 'product' || get_post_status( $product_id ) !== 'publish' ) ) $product_id = 0;
        if ( ! $product_id && preg_match( '#/product/([^/]+)/?$#', $path, $m ) ) {
            $found = get_page_by_path( sanitize_title( rawurldecode( $m[1] ) ), OBJECT, 'product' );
            if ( $found && $found->post_status === 'publish' ) $product_id = (int) $found->ID;
        }
        // An order is attached only when it is theirs: their account's, or the number with its email.
        $order_id = 0;
        $number   = preg_replace( '/[^0-9]/', '', (string) $r->get_param( 'order' ) );
        if ( $number !== '' && function_exists( 'wc_get_order' ) ) {
            $order = $user ? wc_get_order( (int) $number ) : self::order_for( $number, strtolower( sanitize_email( (string) $r->get_param( 'order_email' ) ) ) ?: $email );
            if ( $order instanceof WC_Order && ( ! $user || (int) $order->get_customer_id() === (int) $user->ID || strtolower( (string) $order->get_billing_email() ) === $email ) ) $order_id = (int) $order->get_id();
        }
        $name = $user ? trim( $user->first_name . ' ' . $user->last_name ) ?: (string) $user->display_name : (string) $r->get_param( 'name' );
        $id = self::open( [
            'email'      => $email,
            'name'       => mb_substr( sanitize_text_field( $name ), 0, 120 ),
            'topic'      => $topic,
            'source'     => 'help',
            'page'       => mb_substr( sanitize_text_field( rawurldecode( $path ) ), 0, 190 ),
            'order_id'   => $order_id,
            'product_id' => $product_id,
        ], $body );
        if ( ! $id ) return new WP_Error( 'failed', $he ? 'לא הצלחנו לשמור את ההודעה. נסו שוב.' : 'We couldn\'t save your message. Please try again.', [ 'status' => 500 ] );
        return rest_ensure_response( [ 'ok' => true, 'k' => self::key( $id, $email ) ] );
    }

    /** A conversation as the shopper sees it: no notes, no email, nothing about other shoppers. */
    private static function thread_view( array $conv ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, author, body, created FROM ' . self::messages() . " WHERE conv_id = %d AND author <> 'note' ORDER BY id ASC LIMIT 500", (int) $conv['id'] ), ARRAY_A ) ?: [];
        return [
            'k'        => self::key( (int) $conv['id'], (string) $conv['email'] ),
            'topic'    => (string) $conv['topic'],
            'status'   => (string) $conv['status'],
            'order'    => (int) $conv['order_id'] ? (string) $conv['order_id'] : '',
            'updated'  => strtotime( $conv['updated'] . ' UTC' ),
            'messages' => array_map( static fn( $m ) => [ 'id' => (int) $m['id'], 'mine' => $m['author'] === 'shopper', 'body' => (string) $m['body'], 'at' => strtotime( $m['created'] . ' UTC' ) ], $rows ),
        ];
    }

    /** GET ?k= → the conversation; POST { k, message, website } → the shopper's answer, then the conversation. */
    public static function rest_thread_json( WP_REST_Request $r ) {
        global $wpdb;
        // The open panel asks every few seconds; guessing keys still gets nowhere.
        if ( self::limited( 'inbox_thread_json', 200, 10 * MINUTE_IN_SECONDS ) ) return self::too_many();
        $conv = self::conv_by_key( (string) $r->get_param( 'k' ) );
        if ( ! $conv ) return new WP_Error( 'missing', self::he() ? 'השיחה הזאת כבר לא זמינה.' : 'This conversation isn\'t available any more.', [ 'status' => 404 ] );
        if ( $r->get_method() === 'POST' && (string) $r->get_param( 'website' ) === '' ) {
            $body = self::clean( (string) $r->get_param( 'message' ) );
            if ( $body === '' ) return new WP_Error( 'message', self::he() ? 'כתבו את ההודעה שלכם.' : 'Write your message.', [ 'status' => 400 ] );
            if ( self::limited( 'inbox_shopper_reply', 8, 10 * MINUTE_IN_SECONDS ) ) return self::too_many();
            self::add_message( (int) $conv['id'], 'shopper', $body );
            $wpdb->update( self::conversations(), [ 'status' => 'open', 'unread' => 1 ], [ 'id' => $conv['id'] ] );
            $conv = self::conv( (int) $conv['id'] );
            self::tell_owner( $conv, $body, false );
        }
        return rest_ensure_response( self::thread_view( $conv ) );
    }

    /** The signed-in shopper's conversations (newest first) and their last orders, for the Help panel and My account → Messages. */
    public static function rest_mine() {
        global $wpdb;
        $user  = wp_get_current_user();
        $email = strtolower( (string) $user->user_email );
        $t     = self::conversations();
        $since = gmdate( 'Y-m-d H:i:s', time() - self::LINK_DAYS * DAY_IN_SECONDS );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE ( user_id = %d OR email = %s ) AND updated > %s ORDER BY updated DESC LIMIT 30", (int) $user->ID, $email, $since ), ARRAY_A ) ?: [];
        $list  = [];
        foreach ( $rows as $c ) {
            $list[] = [
                'k'       => self::key( (int) $c['id'], (string) $c['email'] ),
                'topic'   => (string) $c['topic'],
                'preview' => (string) $c['preview'],
                'status'  => (string) $c['status'],
                'answered' => $c['last_author'] === 'owner',
                'order'   => (int) $c['order_id'] ? (string) $c['order_id'] : '',
                'updated' => strtotime( $c['updated'] . ' UTC' ),
            ];
        }
        $orders = [];
        if ( function_exists( 'wc_get_orders' ) ) {
            foreach ( wc_get_orders( [ 'customer_id' => (int) $user->ID, 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ] ) as $order ) $orders[] = self::order_view( $order );
        }
        return rest_ensure_response( [ 'conversations' => $list, 'orders' => $orders ] );
    }

    public static function rest_thread( WP_REST_Request $r ) {
        global $wpdb;
        $he   = self::he();
        $text = static fn( $en, $hebrew ) => $he ? $hebrew : $en;
        $k    = (string) $r->get_param( 'k' );
        // Guessing keys gets nowhere fast.
        $limited = function_exists( 'qwoo_rate_limit_check' ) ? qwoo_rate_limit_check( 'inbox_thread', 60, 10 * MINUTE_IN_SECONDS ) : true;
        $conv    = is_wp_error( $limited ) ? null : self::conv_by_key( $k );
        $notice  = '';
        if ( $conv && $r->get_method() === 'POST' ) {
            $body = self::clean( (string) $r->get_param( 'message' ) );
            $post = function_exists( 'qwoo_rate_limit_check' ) ? qwoo_rate_limit_check( 'inbox_shopper_reply', 8, 10 * MINUTE_IN_SECONDS ) : true;
            if ( (string) $r->get_param( 'website' ) !== '' ) {
                $notice = ''; // a bot filled the hidden field: nothing is kept
            } elseif ( is_wp_error( $post ) ) {
                $notice = $text( 'Too many messages in a short time. Try again in a few minutes.', 'יותר מדי הודעות בזמן קצר. נסו שוב בעוד כמה דקות.' );
            } elseif ( $body !== '' ) {
                self::add_message( (int) $conv['id'], 'shopper', $body );
                $wpdb->update( self::conversations(), [ 'status' => 'open', 'unread' => 1 ], [ 'id' => $conv['id'] ] );
                $conv = self::conv( (int) $conv['id'] );
                self::tell_owner( $conv, $body, false );
                $notice = $text( 'Sent. We\'ll answer here and by email.', 'נשלח. נענה כאן וגם במייל.' );
            }
        }
        $store = esc_html( self::store_name() );
        if ( ! $conv ) {
            $main = '<h1>' . esc_html( $text( 'This link isn\'t valid any more', 'הקישור כבר לא בתוקף' ) ) . '</h1><p>' . esc_html( $text( 'Write to us again from the store and we\'ll answer.', 'כתבו לנו שוב מהחנות ונענה.' ) ) . '</p>';
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT author, body, created FROM " . self::messages() . " WHERE conv_id = %d AND author <> 'note' ORDER BY id ASC LIMIT 500", (int) $conv['id'] ), ARRAY_A ) ?: [];
            $main = '<h1>' . esc_html( $conv['topic'] !== '' ? $conv['topic'] : $text( 'Your conversation with us', 'השיחה שלך איתנו' ) ) . '</h1>';
            foreach ( $rows as $m ) {
                $mine  = $m['author'] === 'shopper';
                $main .= '<div class="m ' . ( $mine ? 'me' : 'us' ) . '"><small>' . esc_html( $mine ? $text( 'You', 'את/ה' ) : self::store_name() ) . ' · ' . esc_html( wp_date( 'j M, H:i', strtotime( $m['created'] . ' UTC' ) ) ) . '</small>' . nl2br( esc_html( $m['body'] ) ) . '</div>';
            }
            if ( $notice !== '' ) $main .= '<p class="n" role="status">' . esc_html( $notice ) . '</p>';
            $action = esc_url( self::thread_url( $conv ) );
            $main  .= '<form method="post" action="' . $action . '"><label for="message">' . esc_html( $text( 'Your answer', 'התשובה שלך' ) ) . '</label><textarea id="message" name="message" rows="4" maxlength="' . self::MAX_TEXT . '" required></textarea><input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true"><button type="submit">' . esc_html( $text( 'Send', 'שליחה' ) ) . '</button></form>';
            $front  = class_exists( 'Qwoo_Email_Marketing' ) ? Qwoo_Email_Marketing::front_home() : home_url( '/' );
            $main  .= '<p class="b"><a href="' . esc_url( $front ) . '">' . esc_html( $text( 'Back to the store', 'חזרה לחנות' ) ) . '</a></p>';
        }
        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'Cache-Control: no-store' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        header( 'Referrer-Policy: no-referrer' );
        header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'" );
        echo '<!doctype html><html dir="' . ( $he ? 'rtl' : 'ltr' ) . '" lang="' . ( $he ? 'he' : 'en' ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $store . '</title><style>'
            . 'body{font-family:system-ui,Arial,sans-serif;background:#f4f4f7;color:#1d1b2e;margin:0;padding:16px;line-height:1.5}main{background:#fff;padding:24px;border-radius:14px;max-width:560px;margin:24px auto}'
            . 'h1{font-size:20px;margin:0 0 16px}.s{color:#6b6880;margin:0 0 4px;font-size:14px}.m{padding:10px 14px;border-radius:14px;margin:0 0 10px;max-width:88%;overflow-wrap:anywhere}.m small{display:block;color:#6b6880;font-size:12px;margin-bottom:2px}'
            . '.me{background:#eeebff;margin-inline-start:auto}.us{background:#f1f1f5}.n{background:#e2f4ea;color:#17613f;padding:10px 14px;border-radius:10px}'
            . 'form{display:grid;gap:8px;margin-top:18px}label{font-size:14px;font-weight:600}textarea{font:inherit;padding:10px 12px;border:1px solid #d9d6e5;border-radius:10px;resize:vertical}'
            . 'button{justify-self:start;padding:11px 22px;border:0;border-radius:8px;background:#1d1b2e;color:#fff;font-size:15px;cursor:pointer}.hp{position:absolute;inset-inline-start:-9999px;width:1px;height:1px;opacity:0}.b{margin:18px 0 0;font-size:14px}'
            . '</style></head><body><main><p class="s">' . $store . '</p>' . $main . '</main></body></html>'; // phpcs:ignore
        exit;
    }
}

Qwoo_Inbox::init();

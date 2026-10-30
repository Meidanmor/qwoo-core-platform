<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Push notifications: the owner's settings (dashboard → Notifications).
 *
 * Option `qwoo_push`:
 *   ask      { when: views | cart | seconds | never, views, seconds, texts: { lang: { title, text, yes, no } } }
 *   quiet    { on, from 'HH:MM', to 'HH:MM' } (store time; marketing waits until `to`)
 *   cap      marketing notifications a device gets a day at most (order updates and back in stock don't count)
 *   automations [ automation ]
 *
 * An automation: { id, builtin, name, on, trigger { type, status, days }, wait (minutes),
 *   only { langs, signed any|in|out, buyer any|yes|no, products, categories },
 *   texts { lang: { title, body } }, link { type, id, url }, image (media id), item_image,
 *   audience wishlist|everyone (sales), gap (days, sales), second (minutes, cart), pickup { lang: { title, body } } }
 *
 * Built-in automations (BUILTINS) always exist: the owner can turn them off and change them, not delete them.
 * Sending, the queue and the triggers are in Qwoo_Push_Engine.
 */
class Qwoo_Push {

    const OPTION     = 'qwoo_push';
    const SENDS      = 'qwoo_push_sends';
    const OWNER_KEY  = 'qwoo_push_owner_key';

    const MAX_AUTOMATIONS = 30;
    const MAX_SENDS_KEPT  = 100;
    const SENDS_PER_DAY   = 3;
    const MAX_WAIT        = 43200; // 30 days, in minutes
    const TITLE_MAX       = 65;
    const BODY_MAX        = 180;

    const TRIGGERS = [ 'subscribed', 'order_placed', 'order_status', 'cart_left', 'product_sale', 'back_in_stock', 'no_order' ];

    /** Triggers whose notifications don't count as marketing (the shopper asked for them or ordered). */
    const SERVICE_TRIGGERS = [ 'order_placed', 'order_status', 'back_in_stock' ];

    const LINK_TYPES = [ 'item', 'home', 'product', 'category', 'page', 'post', 'cart', 'account', 'orders', 'url' ];

    const BUILTINS = [ 'order_received', 'order_status', 'cart_left', 'back_in_stock', 'sale' ];

    const TOKENS = [ 'store_name', 'first_name', 'product', 'price', 'order_number' ];

    /* ---------------- defaults ---------------- */

    /** The ready-made automations as a new store has them. */
    public static function builtin_defaults(): array {
        $cart_wait = class_exists( 'Qwoo_Technical_Settings' ) ? (int) Qwoo_Technical_Settings::get_abandoned_cart_threshold() : 60;
        return [
            'order_received' => [
                'name' => 'Order received', 'on' => true,
                'trigger' => [ 'type' => 'order_placed' ], 'wait' => 0,
                'link' => [ 'type' => 'orders' ],
            ],
            'order_status' => [
                'name' => 'Order on its way / ready for pickup', 'on' => true,
                'trigger' => [ 'type' => 'order_status', 'status' => 'completed' ], 'wait' => 0,
                'link' => [ 'type' => 'orders' ],
            ],
            'cart_left' => [
                'name' => 'Cart left behind', 'on' => true,
                'trigger' => [ 'type' => 'cart_left' ], 'wait' => max( 15, $cart_wait ), 'second' => 0,
                'link' => [ 'type' => 'cart' ],
            ],
            'back_in_stock' => [
                'name' => 'Back in stock', 'on' => true,
                'trigger' => [ 'type' => 'back_in_stock' ], 'wait' => 0,
                'link' => [ 'type' => 'item' ], 'item_image' => true,
            ],
            'sale' => [
                'name' => 'Sale alert', 'on' => true,
                'trigger' => [ 'type' => 'product_sale' ], 'wait' => 0,
                'audience' => 'wishlist', 'gap' => 7,
                'link' => [ 'type' => 'item' ], 'item_image' => true,
            ],
        ];
    }

    /** Built-in texts per language: [ builtin => [ lang => [ title, body ] ] ] (+ order_status's pickup). */
    public static function default_texts(): array {
        return [
            'order_received' => [
                'en' => [ 'Thanks for your order!', 'Order #{order_number} is in. We\'ll let you know when it\'s on its way.' ],
                'he' => [ 'תודה על ההזמנה!', 'הזמנה #{order_number} התקבלה. נעדכן כשהיא יוצאת אליכם.' ],
            ],
            'order_status' => [
                'en' => [ 'Your order is on its way', 'Order #{order_number} has left us and is on its way to you.' ],
                'he' => [ 'ההזמנה בדרך אליכם', 'הזמנה #{order_number} יצאה ובדרך אליכם.' ],
            ],
            'order_pickup' => [
                'en' => [ 'Your order is ready for pickup', 'Order #{order_number} is ready. Come pick it up whenever it suits you.' ],
                'he' => [ 'ההזמנה מוכנה לאיסוף', 'הזמנה #{order_number} מחכה לכם. בואו לאסוף מתי שנוח לכם.' ],
            ],
            'cart_left' => [
                'en' => [ 'You left something in your cart', 'Your cart is saved. Come back and finish your order.' ],
                'he' => [ 'שכחתם משהו בסל', 'הסל שלכם שמור. חזרו להשלים את ההזמנה.' ],
            ],
            'back_in_stock' => [
                'en' => [ '{product} is back', 'It\'s back in stock. Get it before it\'s gone again.' ],
                'he' => [ '{product} חזר למלאי', 'אפשר להזמין שוב, כדאי לא לחכות.' ],
            ],
            'sale' => [
                'en' => [ '{product} is on sale', 'Now only {price}.' ],
                'he' => [ '{product} במבצע', 'עכשיו רק {price}.' ],
            ],
        ];
    }

    public static function default_ask_texts(): array {
        return [
            'en' => [ 'title' => 'Never miss an update', 'text' => 'Get a notification when your order is on its way, when something you saved goes on sale, and more. You can turn it off anytime.', 'yes' => 'Turn on notifications', 'no' => 'Not now' ],
            'he' => [ 'title' => 'לא מפספסים כלום', 'text' => 'נשלח לכם התראה כשההזמנה בדרך, כשמשהו ששמרתם יורד במחיר, ועוד. אפשר לכבות בכל רגע.', 'yes' => 'הפעלת התראות', 'no' => 'לא עכשיו' ],
        ];
    }

    /* ---------------- reading ---------------- */

    /** Languages messages can be written in: the main one first, then extra ones. */
    public static function langs(): array {
        $main = class_exists( 'Qwoo_Store_Language' ) ? Qwoo_Store_Language::get() : 'en';
        $extra = class_exists( 'Qwoo_Store_Language' ) ? Qwoo_Store_Language::extra() : [];
        return array_values( array_unique( array_merge( [ $main ], $extra ) ) );
    }

    public static function main_lang(): string {
        return self::langs()[0];
    }

    /** The saved settings with every built-in present and defaults filled in. */
    public static function settings(): array {
        $raw = get_option( self::OPTION, [] );
        $raw = is_array( $raw ) ? $raw : [];
        $ask = (array) ( $raw['ask'] ?? [] );
        $quiet = (array) ( $raw['quiet'] ?? [] );
        $out = [
            'ask' => [
                'when'    => in_array( $ask['when'] ?? '', [ 'views', 'cart', 'seconds', 'never' ], true ) ? $ask['when'] : 'views',
                'views'   => max( 1, min( 20, (int) ( $ask['views'] ?? 3 ) ) ),
                'seconds' => max( 3, min( 600, (int) ( $ask['seconds'] ?? 20 ) ) ),
                'texts'   => (array) ( $ask['texts'] ?? [] ),
            ],
            'quiet' => [
                'on'   => array_key_exists( 'on', $quiet ) ? ! empty( $quiet['on'] ) : true,
                'from' => self::clock( $quiet['from'] ?? '' ) ?: '22:00',
                'to'   => self::clock( $quiet['to'] ?? '' ) ?: '08:00',
            ],
            'cap' => isset( $raw['cap'] ) ? max( 1, min( 10, (int) $raw['cap'] ) ) : 2,
        ];

        $saved = [];
        foreach ( (array) ( $raw['automations'] ?? [] ) as $a ) {
            if ( is_array( $a ) && ! empty( $a['id'] ) ) {
                $saved[] = $a;
            }
        }
        $list = [];
        $defaults = self::builtin_defaults();
        foreach ( self::BUILTINS as $key ) {
            $found = null;
            foreach ( $saved as $a ) {
                if ( ( $a['builtin'] ?? '' ) === $key ) {
                    $found = $a;
                }
            }
            $list[] = self::fill( array_merge( $defaults[ $key ], [ 'id' => $key, 'builtin' => $key ], $found ? self::builtin_editable( $found ) : [] ) );
        }
        foreach ( $saved as $a ) {
            if ( empty( $a['builtin'] ) ) {
                $list[] = self::fill( $a );
            }
        }
        $out['automations'] = $list;
        return $out;
    }

    /** What an owner may change on a built-in (its trigger type and id stay). */
    private static function builtin_editable( array $a ): array {
        $keep = array_intersect_key( $a, array_flip( [ 'name', 'on', 'wait', 'only', 'texts', 'pickup', 'link', 'image', 'item_image', 'audience', 'gap', 'second' ] ) );
        if ( ( $a['builtin'] ?? '' ) === 'order_status' && ! empty( $a['trigger']['status'] ) ) {
            $keep['trigger'] = [ 'type' => 'order_status', 'status' => $a['trigger']['status'] ];
        }
        return $keep;
    }

    /** Every key of an automation, with safe values. */
    private static function fill( array $a ): array {
        $t = (array) ( $a['trigger'] ?? [] );
        $only = (array) ( $a['only'] ?? [] );
        $link = (array) ( $a['link'] ?? [] );
        return [
            'id'         => (string) $a['id'],
            'builtin'    => (string) ( $a['builtin'] ?? '' ),
            'name'       => (string) ( $a['name'] ?? '' ),
            'on'         => ! empty( $a['on'] ),
            'trigger'    => [
                'type'   => in_array( $t['type'] ?? '', self::TRIGGERS, true ) ? $t['type'] : 'subscribed',
                'status' => (string) ( $t['status'] ?? '' ),
                'days'   => max( 1, min( 365, (int) ( $t['days'] ?? 30 ) ) ),
            ],
            'wait'       => max( 0, min( self::MAX_WAIT, (int) ( $a['wait'] ?? 0 ) ) ),
            'only'       => [
                'langs'      => array_values( array_map( 'strval', (array) ( $only['langs'] ?? [] ) ) ),
                'signed'     => in_array( $only['signed'] ?? '', [ 'in', 'out' ], true ) ? $only['signed'] : 'any',
                'buyer'      => in_array( $only['buyer'] ?? '', [ 'yes', 'no' ], true ) ? $only['buyer'] : 'any',
                'products'   => array_values( array_filter( array_map( 'absint', (array) ( $only['products'] ?? [] ) ) ) ),
                'categories' => array_values( array_filter( array_map( 'absint', (array) ( $only['categories'] ?? [] ) ) ) ),
            ],
            'texts'      => (array) ( $a['texts'] ?? [] ),
            'pickup'     => (array) ( $a['pickup'] ?? [] ),
            'link'       => [
                'type' => in_array( $link['type'] ?? '', self::LINK_TYPES, true ) ? $link['type'] : 'home',
                'id'   => (string) ( $link['id'] ?? '' ),
                'url'  => (string) ( $link['url'] ?? '' ),
            ],
            'image'      => absint( $a['image'] ?? 0 ),
            'item_image' => ! empty( $a['item_image'] ),
            'audience'   => ( $a['audience'] ?? '' ) === 'everyone' ? 'everyone' : 'wishlist',
            'gap'        => max( 0, min( 90, (int) ( $a['gap'] ?? 7 ) ) ),
            'second'     => max( 0, min( self::MAX_WAIT, (int) ( $a['second'] ?? 0 ) ) ),
        ];
    }

    public static function automation( string $id ): ?array {
        foreach ( self::settings()['automations'] as $a ) {
            if ( $a['id'] === $id ) return $a;
        }
        return null;
    }

    /** Whether a trigger's notifications count as marketing (daily cap, quiet hours). */
    public static function is_marketing( string $trigger ): bool {
        return ! in_array( $trigger, self::SERVICE_TRIGGERS, true );
    }

    /* ---------------- saving ---------------- */

    /**
     * { ask?, quiet?, cap?, sender?, automations? (the whole list) }. Parts left out are kept.
     * Returns [ 'error' => message ] or the dashboard state.
     */
    public static function save( array $p ) {
        $now = get_option( self::OPTION, [] );
        $now = is_array( $now ) ? $now : [];
        $langs = self::langs();

        if ( isset( $p['ask'] ) && is_array( $p['ask'] ) ) {
            $ask = $p['ask'];
            $texts = [];
            foreach ( $langs as $lang ) {
                $row = (array) ( $ask['texts'][ $lang ] ?? [] );
                $clean = [];
                foreach ( [ 'title' => 60, 'text' => 240, 'yes' => 40, 'no' => 40 ] as $k => $max ) {
                    $v = self::plain( $row[ $k ] ?? '', $max );
                    if ( $v !== '' ) $clean[ $k ] = $v;
                }
                if ( $clean ) $texts[ $lang ] = $clean;
            }
            $now['ask'] = [
                'when'    => in_array( $ask['when'] ?? '', [ 'views', 'cart', 'seconds', 'never' ], true ) ? $ask['when'] : 'views',
                'views'   => max( 1, min( 20, (int) ( $ask['views'] ?? 3 ) ) ),
                'seconds' => max( 3, min( 600, (int) ( $ask['seconds'] ?? 20 ) ) ),
                'texts'   => $texts,
            ];
        }
        if ( isset( $p['quiet'] ) && is_array( $p['quiet'] ) ) {
            $from = self::clock( $p['quiet']['from'] ?? '' );
            $to   = self::clock( $p['quiet']['to'] ?? '' );
            if ( ! $from || ! $to ) return [ 'error' => 'Quiet hours need a start and an end time.' ];
            if ( $from === $to ) return [ 'error' => 'Quiet hours can\'t start and end at the same time.' ];
            $now['quiet'] = [ 'on' => ! empty( $p['quiet']['on'] ), 'from' => $from, 'to' => $to ];
        }
        if ( isset( $p['cap'] ) ) {
            $now['cap'] = max( 1, min( 10, (int) $p['cap'] ) );
        }
        if ( isset( $p['sender'] ) ) {
            $email = sanitize_email( (string) $p['sender'] );
            if ( $p['sender'] !== '' && ! is_email( $email ) ) return [ 'error' => 'Enter a valid email address.' ];
            $tech = get_option( 'qwoo_technical_settings', [] );
            $tech = is_array( $tech ) ? $tech : [];
            $tech['push_email'] = $email;
            update_option( 'qwoo_technical_settings', $tech );
        }
        if ( isset( $p['automations'] ) && is_array( $p['automations'] ) ) {
            $list = [];
            $own = 0;
            foreach ( $p['automations'] as $a ) {
                if ( ! is_array( $a ) ) continue;
                $clean = self::clean_automation( $a, $langs );
                if ( isset( $clean['error'] ) ) return $clean;
                if ( $clean['builtin'] === '' && ++$own > self::MAX_AUTOMATIONS ) {
                    return [ 'error' => sprintf( 'You can have up to %d own automations.', self::MAX_AUTOMATIONS ) ];
                }
                $list[] = $clean;
            }
            $ids = array_column( $list, 'id' );
            if ( count( $ids ) !== count( array_unique( $ids ) ) ) return [ 'error' => 'Two automations have the same id.' ];
            // Turned off or removed: what was waiting to go out for them is cancelled.
            $before = self::settings()['automations'];
            $now['automations'] = $list;
            update_option( self::OPTION, $now, false );
            foreach ( $before as $old ) {
                $still = null;
                foreach ( self::settings()['automations'] as $a ) {
                    if ( $a['id'] === $old['id'] ) $still = $a;
                }
                if ( ! $still || ! $still['on'] ) {
                    Qwoo_Push_Engine::cancel_waiting( 'auto', $old['id'] );
                }
            }
            return true;
        }
        update_option( self::OPTION, $now, false );
        return true;
    }

    /** One automation from the dashboard / an AI app. */
    private static function clean_automation( array $a, array $langs ) {
        $builtin = in_array( $a['builtin'] ?? '', self::BUILTINS, true ) ? $a['builtin'] : '';
        $id = $builtin !== '' ? $builtin : (string) ( $a['id'] ?? '' );
        if ( $builtin === '' && ! preg_match( '/^au_[a-z0-9]{6,16}$/', $id ) ) {
            $id = 'au_' . strtolower( wp_generate_password( 10, false ) );
        }
        $name = self::plain( $a['name'] ?? '', 80 );
        if ( $name === '' ) return [ 'error' => 'Give every automation a name.' ];

        $t = (array) ( $a['trigger'] ?? [] );
        $type = $builtin !== '' ? self::builtin_defaults()[ $builtin ]['trigger']['type'] : (string) ( $t['type'] ?? '' );
        if ( ! in_array( $type, self::TRIGGERS, true ) ) return [ 'error' => 'Choose when the notification goes out.' ];
        $status = '';
        if ( $type === 'order_status' ) {
            $status = preg_replace( '/^wc-/', '', (string) ( $t['status'] ?? '' ) );
            $known = function_exists( 'wc_get_order_statuses' ) ? array_map( static fn( $k ) => preg_replace( '/^wc-/', '', $k ), array_keys( wc_get_order_statuses() ) ) : [ 'completed' ];
            if ( ! in_array( $status, $known, true ) ) return [ 'error' => 'Choose the order status.' ];
        }

        $texts = self::clean_texts( $a['texts'] ?? [], $langs );
        if ( $builtin === '' && empty( $texts[ $langs[0] ]['title'] ) ) {
            return [ 'error' => sprintf( '"%s" needs a title in the store\'s main language.', $name ) ];
        }
        $link = self::clean_link( (array) ( $a['link'] ?? [] ) );
        if ( isset( $link['error'] ) ) return $link;

        $only = (array) ( $a['only'] ?? [] );
        $out = [
            'id'         => $id,
            'builtin'    => $builtin,
            'name'       => $name,
            'on'         => ! empty( $a['on'] ),
            'trigger'    => [ 'type' => $type, 'status' => $status, 'days' => max( 1, min( 365, (int) ( $t['days'] ?? 30 ) ) ) ],
            'wait'       => max( 0, min( self::MAX_WAIT, (int) ( $a['wait'] ?? 0 ) ) ),
            'only'       => [
                'langs'      => array_values( array_intersect( array_map( 'strval', (array) ( $only['langs'] ?? [] ) ), $langs ) ),
                'signed'     => in_array( $only['signed'] ?? '', [ 'in', 'out' ], true ) ? $only['signed'] : 'any',
                'buyer'      => in_array( $only['buyer'] ?? '', [ 'yes', 'no' ], true ) ? $only['buyer'] : 'any',
                'products'   => array_slice( array_values( array_filter( array_map( 'absint', (array) ( $only['products'] ?? [] ) ) ) ), 0, 50 ),
                'categories' => array_slice( array_values( array_filter( array_map( 'absint', (array) ( $only['categories'] ?? [] ) ) ) ), 0, 50 ),
            ],
            'texts'      => $texts,
            'link'       => $link,
            'image'      => self::clean_image( $a['image'] ?? 0 ),
            'item_image' => ! empty( $a['item_image'] ),
        ];
        if ( $builtin === 'order_status' ) {
            $out['pickup'] = self::clean_texts( $a['pickup'] ?? [], $langs );
        }
        if ( $type === 'product_sale' ) {
            $out['audience'] = ( $a['audience'] ?? '' ) === 'everyone' ? 'everyone' : 'wishlist';
            $out['gap']      = max( 0, min( 90, (int) ( $a['gap'] ?? 7 ) ) );
        }
        if ( $builtin === 'cart_left' ) {
            $out['second'] = max( 0, min( self::MAX_WAIT, (int) ( $a['second'] ?? 0 ) ) );
            if ( $out['second'] && $out['second'] <= $out['wait'] ) {
                return [ 'error' => 'The second cart reminder must come after the first one.' ];
            }
        }
        return $out;
    }

    /** { lang: { title, body } } for the store's languages; empty ones left out. */
    public static function clean_texts( $in, array $langs ): array {
        $out = [];
        foreach ( $langs as $lang ) {
            $row = (array) ( ( (array) $in )[ $lang ] ?? [] );
            $title = self::plain( $row['title'] ?? '', self::TITLE_MAX );
            $body  = self::plain( $row['body'] ?? '', self::BODY_MAX );
            if ( $title !== '' || $body !== '' ) {
                $out[ $lang ] = [ 'title' => $title, 'body' => $body ];
            }
        }
        return $out;
    }

    /** A link: the store's own pages, or an https address. */
    public static function clean_link( array $l ) {
        $type = in_array( $l['type'] ?? '', self::LINK_TYPES, true ) ? $l['type'] : 'home';
        $out = [ 'type' => $type, 'id' => '', 'url' => '' ];
        switch ( $type ) {
            case 'product':
            case 'category':
            case 'post':
                $out['id'] = (string) absint( $l['id'] ?? 0 );
                if ( $out['id'] === '0' ) return [ 'error' => 'Choose where the notification opens.' ];
                break;
            case 'page':
                $out['id'] = preg_match( '/^pg_[A-Za-z0-9_]{1,40}$/', (string) ( $l['id'] ?? '' ) ) ? (string) $l['id'] : '';
                if ( $out['id'] === '' ) return [ 'error' => 'Choose where the notification opens.' ];
                break;
            case 'url':
                $url = trim( (string) ( $l['url'] ?? '' ) );
                if ( preg_match( '#^/[^/\\\\]#', $url ) || $url === '/' ) {
                    $out['url'] = substr( preg_replace( '/[\s<>"]/', '', $url ), 0, 500 );
                } elseif ( preg_match( '#^https://[^\s<>"]+$#i', $url ) && wp_http_validate_url( $url ) ) {
                    $out['url'] = substr( $url, 0, 500 );
                } else {
                    return [ 'error' => 'Links must start with https:// or be an address in your store (starting with /).' ];
                }
                break;
        }
        return $out;
    }

    public static function clean_image( $id ): int {
        $id = absint( $id );
        if ( ! $id ) return 0;
        $mime = (string) get_post_mime_type( $id );
        return in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ], true ) ? $id : 0;
    }

    /** Plain text: no tags, no line breaks, trimmed to $max characters. */
    public static function plain( $value, int $max ): string {
        $value = wp_strip_all_tags( (string) $value );
        $value = trim( preg_replace( '/\s+/u', ' ', $value ) );
        return mb_substr( $value, 0, $max );
    }

    private static function clock( $value ) {
        return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value ) ? (string) $value : '';
    }

    /* ---------------- one-off sends ---------------- */

    public static function sends(): array {
        $list = get_option( self::SENDS, [] );
        return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : [];
    }

    public static function save_sends( array $list ) {
        update_option( self::SENDS, array_slice( array_values( $list ), 0, self::MAX_SENDS_KEPT ), false );
    }

    /**
     * { texts, link, image, audience { who, langs }, send_at (Unix, 0 = now), dry, by }.
     * dry: only how many devices it would reach. Returns [ 'error' ] or the send.
     */
    public static function create_send( array $p ) {
        $langs = self::langs();
        $texts = self::clean_texts( $p['texts'] ?? [], $langs );
        if ( empty( $texts[ $langs[0] ]['title'] ) ) return [ 'error' => 'Write a title in the store\'s main language.' ];
        $link = self::clean_link( (array) ( $p['link'] ?? [] ) );
        if ( isset( $link['error'] ) ) return $link;
        if ( $link['type'] === 'item' ) $link = [ 'type' => 'home', 'id' => '', 'url' => '' ];
        $aud = (array) ( $p['audience'] ?? [] );
        $audience = [
            'who'   => in_array( $aud['who'] ?? '', [ 'all', 'cart', 'buyers', 'new' ], true ) ? $aud['who'] : 'all',
            'langs' => array_values( array_intersect( array_map( 'strval', (array) ( $aud['langs'] ?? [] ) ), $langs ) ),
        ];
        $reach = Qwoo_Push_Engine::audience_count( $audience );
        if ( ! empty( $p['dry'] ) ) {
            return [ 'reach' => $reach ];
        }
        $send_at = (int) ( $p['send_at'] ?? 0 );
        if ( $send_at && $send_at < time() - 60 ) return [ 'error' => 'That time has passed. Choose a time from now on.' ];
        if ( $send_at > time() + 90 * DAY_IN_SECONDS ) return [ 'error' => 'Schedule at most 90 days ahead.' ];

        $list = self::sends();
        $today = 0;
        foreach ( $list as $s ) {
            if ( ( $s['status'] ?? '' ) !== 'cancelled' && (int) ( $s['created'] ?? 0 ) > time() - DAY_IN_SECONDS ) $today++;
        }
        if ( $today >= self::SENDS_PER_DAY ) {
            return [ 'error' => sprintf( 'You can send up to %d notifications a day. Try again tomorrow.', self::SENDS_PER_DAY ) ];
        }
        $send = [
            'id'       => 'ps_' . strtolower( wp_generate_password( 10, false ) ),
            'texts'    => $texts,
            'link'     => $link,
            'image'    => self::clean_image( $p['image'] ?? 0 ),
            'audience' => $audience,
            'send_at'  => $send_at ?: time(),
            'status'   => 'scheduled',
            'created'  => time(),
            'by'       => ( $p['by'] ?? '' ) === 'ai' ? 'ai' : 'owner',
            'reach'    => $reach,
        ];
        array_unshift( $list, $send );
        self::save_sends( $list );
        Qwoo_Push_Engine::note_due( $send['send_at'] );
        if ( $send['send_at'] <= time() ) {
            Qwoo_Push_Engine::spawn_soon();
        }
        return $send;
    }

    public static function cancel_send( string $id ) {
        $list = self::sends();
        foreach ( $list as $i => $s ) {
            if ( ( $s['id'] ?? '' ) !== $id ) continue;
            if ( ( $s['status'] ?? '' ) === 'scheduled' ) {
                $list[ $i ]['status'] = 'cancelled';
                self::save_sends( $list );
                return true;
            }
            if ( ( $s['status'] ?? '' ) === 'sending' ) {
                Qwoo_Push_Engine::cancel_waiting( 'send', $id );
                $list[ $i ]['status'] = 'cancelled';
                self::save_sends( $list );
                return true;
            }
            return [ 'error' => 'This notification has already gone out.' ];
        }
        return [ 'error' => 'Notification not found.' ];
    }

    /* ---------------- the owner's own devices (test sends) ---------------- */

    public static function owner_key(): string {
        $key = (string) get_option( self::OWNER_KEY, '' );
        if ( ! preg_match( '/^[a-z0-9]{24}$/', $key ) ) {
            $key = strtolower( wp_generate_password( 24, false ) );
            update_option( self::OWNER_KEY, $key, false );
        }
        return $key;
    }

    public static function new_owner_key() {
        delete_option( self::OWNER_KEY );
        return self::owner_key();
    }

    /* ---------------- dashboard ---------------- */

    public static function dashboard_state(): array {
        $s = self::settings();
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? Qwoo_Technical_Settings::get_primary_frontend_domain() : '';
        $sends = self::sends();
        $counts = Qwoo_Push_Engine::counts_by_ref();
        foreach ( $sends as $i => $send ) {
            $sends[ $i ]['stats'] = $counts[ $send['id'] ] ?? [ 'sent' => 0, 'opened' => 0, 'failed' => 0, 'waiting' => 0 ];
            $sends[ $i ]['link']['label'] = self::link_label( (array) ( $send['link'] ?? [] ) );
            $sends[ $i ]['image_url'] = ! empty( $send['image'] ) ? (string) wp_get_attachment_image_url( (int) $send['image'], 'medium' ) : '';
        }
        foreach ( $s['automations'] as $i => $a ) {
            $s['automations'][ $i ]['stats'] = Qwoo_Push_Engine::counts_by_ref( $a['id'], 30 );
            $s['automations'][ $i ]['link']['label'] = self::link_label( $a['link'] );
            $s['automations'][ $i ]['image_url'] = $a['image'] ? (string) wp_get_attachment_image_url( $a['image'], 'medium' ) : '';
            $names = [];
            foreach ( $a['only']['products'] as $pid ) {
                $names[ $pid ] = html_entity_decode( (string) get_the_title( $pid ), ENT_QUOTES, 'UTF-8' );
            }
            $s['automations'][ $i ]['product_names'] = (object) $names;
        }
        $statuses = [];
        if ( function_exists( 'wc_get_order_statuses' ) ) {
            foreach ( wc_get_order_statuses() as $k => $label ) {
                $statuses[ preg_replace( '/^wc-/', '', $k ) ] = $label;
            }
        }
        $tech = get_option( 'qwoo_technical_settings', [] );
        // Link pickers: the store's live own pages and its product categories (products and posts are searched).
        $pages = [];
        foreach ( (array) get_option( 'qwoo_published_pages', [] ) as $page ) {
            if ( is_array( $page ) && ! empty( $page['id'] ) ) {
                $pages[] = [ 'id' => (string) $page['id'], 'title' => (string) ( $page['title'] ?? '' ), 'path' => '/' . ltrim( (string) ( $page['path'] ?? '' ), '/' ) ];
            }
        }
        $cats = [];
        foreach ( (array) get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 300 ] ) as $term ) {
            if ( $term instanceof WP_Term ) {
                $cats[] = [ 'id' => (int) $term->term_id, 'name' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) ];
            }
        }
        return [
            'settings' => [ 'ask' => $s['ask'], 'quiet' => $s['quiet'], 'cap' => $s['cap'], 'sender' => (string) ( is_array( $tech ) ? ( $tech['push_email'] ?? '' ) : '' ) ],
            'automations' => $s['automations'],
            'sends' => $sends,
            'stats' => Qwoo_Push_Engine::overview(),
            'ready' => Qwoo_Push_Engine::keys_ready(),
            'owner_link' => $front ? $front . '/?qwoo_push_owner=' . self::owner_key() : '',
            'lists' => [
                'langs'       => self::langs(),
                'statuses'    => $statuses,
                'triggers'    => self::TRIGGERS,
                'service'     => self::SERVICE_TRIGGERS,
                'link_types'  => self::LINK_TYPES,
                'tokens'      => self::TOKENS,
                'defaults'    => self::default_texts(),
                'ask_texts'   => self::default_ask_texts(),
                'max_wait'    => self::MAX_WAIT,
                'title_max'   => self::TITLE_MAX,
                'body_max'    => self::BODY_MAX,
                'sends_per_day' => self::SENDS_PER_DAY,
                'max_automations' => self::MAX_AUTOMATIONS,
                'pages'       => $pages,
                'categories'  => $cats,
                'store_name'  => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
                'icon'        => $front ? $front . '/icons/icon-128x128.png' : '',
            ],
        ];
    }

    /** The name of a picked product / category / post (shown next to the link in the dashboard). */
    public static function link_label( array $link ): string {
        $id = (int) ( $link['id'] ?? 0 );
        switch ( $link['type'] ?? '' ) {
            case 'product':
                $p = $id ? wc_get_product( $id ) : null;
                return $p ? html_entity_decode( $p->get_name(), ENT_QUOTES, 'UTF-8' ) : '';
            case 'category':
                $t = $id ? get_term( $id, 'product_cat' ) : null;
                return $t && ! is_wp_error( $t ) ? html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) : '';
            case 'post':
                return $id ? html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) : '';
        }
        return '';
    }

    /** What the storefront reads (public; no secrets): the push key, the ask, and whether "Notify me" is on. */
    public static function storefront_config(): array {
        $s = self::settings();
        $lang = class_exists( 'Qwoo_Store_Language' ) ? ( Qwoo_Store_Language::request_lang() ?: self::main_lang() ) : 'en';
        $defaults = self::default_ask_texts();
        $texts = array_merge( $defaults[ $lang ] ?? $defaults['en'], (array) ( $s['ask']['texts'][ $lang ] ?? [] ) );
        $notify = false;
        foreach ( $s['automations'] as $a ) {
            if ( $a['builtin'] === 'back_in_stock' ) $notify = $a['on'];
        }
        return [
            'key'       => Qwoo_Push_Engine::keys_ready() ? (string) Qwoo_Technical_Settings::get_key( 'VAPID_API_PUBLIC_KEY' ) : '',
            'ask'       => [ 'when' => $s['ask']['when'], 'views' => $s['ask']['views'], 'seconds' => $s['ask']['seconds'], 'texts' => $texts ],
            'notify_me' => $notify,
        ];
    }
}

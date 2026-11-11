<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The emails a store sends by itself (dashboard → Emails): on or off, who gets
 * them, the subject, and the design. WooCommerce still decides when each one
 * goes out; this only changes what it sends.
 *
 *   qwoo_store_emails = { id: { on?, to?: [], copy?: [], subject?, preheader?, design? } }
 *   qwoo_email_style  = { logo, title, button, bg, font, footer }
 *
 * An email without its own design uses a ready-made one in the store style. If
 * making a design ever fails, WooCommerce's own template goes out instead: an
 * order email always reaches the customer.
 */
class Qwoo_Store_Emails {

    const OPTION = 'qwoo_store_emails';
    const STYLE  = 'qwoo_email_style';
    const REPLY  = 'qwoo_email_reply_to';
    const MAX_TO = 10;

    /** The HTML waiting for store-email-template.php and for the send. */
    public static $rendered = '';
    private static $final   = null;

    /** kind: what the email is about, which decides its blocks, details and button targets. */
    public static function catalog(): array {
        return [
            'customer_processing_order' => [ 'name' => 'Order confirmation', 'when' => 'When a payment goes through', 'group' => 'customer', 'kind' => 'order', 'wc' => true ],
            'customer_on_hold_order'    => [ 'name' => 'Order on hold', 'when' => 'When an order waits for payment, like a bank transfer', 'group' => 'customer', 'kind' => 'order', 'wc' => true ],
            'customer_completed_order'  => [ 'name' => 'Order completed', 'when' => 'When you mark an order as completed', 'group' => 'customer', 'kind' => 'order', 'wc' => true ],
            'customer_refunded_order'   => [ 'name' => 'Order refunded', 'when' => 'When you refund an order, fully or partly', 'group' => 'customer', 'kind' => 'order', 'wc' => true ],
            'customer_cancelled_order'  => [ 'name' => 'Order cancelled', 'when' => 'When an order is cancelled', 'group' => 'customer', 'kind' => 'order', 'wc' => true ],
            'customer_failed_order'     => [ 'name' => 'Payment failed', 'when' => 'When a customer\'s payment fails', 'group' => 'customer', 'kind' => 'order', 'wc' => true ],
            'customer_note'             => [ 'name' => 'Note from you', 'when' => 'When you add a note for the customer on an order', 'group' => 'customer', 'kind' => 'order', 'wc' => true ],
            'customer_invoice'          => [ 'name' => 'Order details', 'when' => 'When you send it yourself from an order', 'group' => 'customer', 'kind' => 'order', 'wc' => true ],
            'customer_new_account'      => [ 'name' => 'New account', 'when' => 'When someone creates an account', 'group' => 'customer', 'kind' => 'account', 'wc' => true ],
            'customer_reset_password'   => [ 'name' => 'Password reset', 'when' => 'When someone asks for a new password', 'group' => 'customer', 'kind' => 'account', 'wc' => true, 'lock' => true ],
            'back_in_stock'             => [ 'name' => 'Back in stock', 'when' => 'When a product someone asked about returns', 'group' => 'customer', 'kind' => 'stock' ],
            'review_request'            => [ 'name' => 'Review request', 'when' => 'After an order is completed', 'group' => 'customer', 'kind' => 'review' ],
            'gift_card'                 => [ 'name' => 'Gift card', 'when' => 'On the day a gift card is sent to its recipient', 'group' => 'customer', 'kind' => 'gift', 'lock' => true ],
            'gift_card_bounced'         => [ 'name' => 'Gift card not delivered', 'when' => 'When a gift card\'s email can\'t be delivered (to the buyer)', 'group' => 'customer', 'kind' => 'gift_notice' ],
            'new_order'                 => [ 'name' => 'New order', 'when' => 'When someone places an order', 'group' => 'team', 'kind' => 'order', 'wc' => true ],
            'cancelled_order'           => [ 'name' => 'Cancelled order', 'when' => 'When an order is cancelled', 'group' => 'team', 'kind' => 'order', 'wc' => true ],
            'failed_order'              => [ 'name' => 'Failed payment', 'when' => 'When a payment fails', 'group' => 'team', 'kind' => 'order', 'wc' => true ],
            'low_stock'                 => [ 'name' => 'Low stock', 'when' => 'When a product is running low', 'group' => 'team', 'kind' => 'stock' ],
            'no_stock'                  => [ 'name' => 'Out of stock', 'when' => 'When a product sells out', 'group' => 'team', 'kind' => 'stock' ],
        ];
    }

    public static function init() {
        add_filter( 'wc_get_template', [ __CLASS__, 'swap_template' ], 20, 5 );
        add_filter( 'woocommerce_mail_content', [ __CLASS__, 'final_content' ], 99 );
        add_filter( 'woocommerce_email_headers', [ __CLASS__, 'headers' ], 20, 3 );
        foreach ( self::catalog() as $id => $def ) {
            if ( empty( $def['wc'] ) ) continue;
            add_filter( 'woocommerce_email_enabled_' . $id, static fn( $on ) => self::enabled( $id, (bool) $on ), 20 );
            add_filter( 'woocommerce_email_recipient_' . $id, static fn( $to ) => self::recipients( $id, $to ), 20 );
            add_filter( 'woocommerce_email_subject_' . $id, static fn( $subject, $object = null, $email = null ) => self::subject( $id, (string) $subject, $object, $email ), 5, 3 );
        }
        foreach ( [ 'low_stock', 'no_stock' ] as $id ) {
            add_filter( 'pre_option_woocommerce_notify_' . $id, static function ( $v ) use ( $id ) {
                $on = self::saved( $id )['on'] ?? null;
                return $on === null ? $v : ( $on ? 'yes' : 'no' );
            } );
            add_filter( 'woocommerce_email_recipient_' . $id, static fn( $to ) => self::recipients( $id, $to ), 20 );
            add_filter( 'woocommerce_email_subject_' . $id, static fn( $subject, $product = null ) => self::stock_subject( $id, (string) $subject, $product ), 5, 2 );
            add_filter( 'woocommerce_email_content_' . $id, static fn( $text, $product = null ) => self::stock_content( $id, (string) $text, $product ), 20, 2 );
        }
    }

    private static function l( string $en, string $he ): string {
        return class_exists( 'Qwoo_I18n' ) && Qwoo_I18n::default_code() === 'he' ? $he : $en;
    }

    /* ---------------- what's saved ---------------- */

    private static function all(): array {
        $saved = get_option( self::OPTION, [] );
        return is_array( $saved ) ? $saved : [];
    }

    public static function saved( string $id ): array {
        return (array) ( self::all()[ $id ] ?? [] );
    }

    private static function put( string $id, array $data ): void {
        $all = self::all();
        $all[ $id ] = array_merge( (array) ( $all[ $id ] ?? [] ), $data );
        update_option( self::OPTION, $all, false );
    }

    private static function emails_list( $raw ): array {
        $out = [];
        foreach ( array_slice( (array) $raw, 0, self::MAX_TO ) as $address ) {
            $address = sanitize_email( (string) $address );
            if ( is_email( $address ) && ! in_array( $address, $out, true ) ) $out[] = $address;
        }
        return $out;
    }

    /** "Email for new orders" in Settings changes the first recipient here too. */
    public static function primary_changed( string $email ): void {
        $to = (array) ( self::saved( 'new_order' )['to'] ?? [] );
        if ( ! $to ) return;
        $to[0] = $email;
        self::put( 'new_order', [ 'to' => self::emails_list( $to ) ] );
    }

    /* ---------------- the store style ---------------- */

    public static function style(): array {
        $saved    = get_option( self::STYLE, [] );
        $options  = (array) get_option( 'shop_builder_options', [] );
        $branding = (array) ( $options['branding'] ?? [] );
        $colors   = (array) ( $branding['global_colors'] ?? [] );
        $logo_id  = (int) ( $branding['logo_id'] ?? 0 );
        $address  = trim( get_option( 'woocommerce_store_address' ) . ' ' . get_option( 'woocommerce_store_city' ) );
        return array_merge( [
            'logo'   => $logo_id ? (string) wp_get_attachment_url( $logo_id ) : '',
            'title'  => sanitize_hex_color( (string) ( $colors['text'] ?? '' ) ) ?: '#1d1b2e',
            'button' => sanitize_hex_color( (string) ( $colors['secondary'] ?? '' ) ) ?: '#1d1b2e',
            'bg'     => '#f4f4f7',
            'font'   => '',
            'footer' => trim( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ( $address !== '' ? ', ' . $address : '' ) ),
        ], is_array( $saved ) ? array_filter( $saved, static fn( $v ) => $v !== '' ) : [] );
    }

    public static function save_style( array $in ): array {
        update_option( self::STYLE, [
            'logo'   => esc_url_raw( (string) ( $in['logo'] ?? '' ), [ 'http', 'https' ] ),
            'title'  => sanitize_hex_color( (string) ( $in['title'] ?? '' ) ) ?: '',
            'button' => sanitize_hex_color( (string) ( $in['button'] ?? '' ) ) ?: '',
            'bg'     => sanitize_hex_color( (string) ( $in['bg'] ?? '' ) ) ?: '',
            'font'   => isset( Qwoo_Email_Render::FONTS[ $in['font'] ?? '' ] ) ? (string) ( $in['font'] ?? '' ) : '',
            'footer' => mb_substr( sanitize_textarea_field( (string) ( $in['footer'] ?? '' ) ), 0, 300 ),
        ], false );
        return self::style();
    }

    /* ---------------- ready-made designs ---------------- */

    private static function row( array $block, array $r = [] ): array {
        return [ 'layout' => '1', 'r' => $r, 'cols' => [ [ $block ] ] ];
    }

    private static function b( string $type, array $data = [] ): array {
        return [ 'id' => 'b' . bin2hex( random_bytes( 4 ) ), 'type' => $type, 'data' => $data ];
    }

    /** The email's ready-made design, in the store style. */
    public static function default_design( string $id ): array {
        $st      = self::style();
        $def     = self::catalog()[ $id ] ?? [ 'kind' => 'order', 'group' => 'customer' ];
        $title   = static fn( $text ) => self::row( self::b( 'heading', [ 'text' => $text, 'size' => 26, 'color' => $st['title'], 'padV' => 16 ] ) );
        $text    = static fn( $text ) => self::row( self::b( 'text', [ 'html' => '<p>' . esc_html( $text ) . '</p>', 'size' => 15, 'padV' => 4 ] ) );
        $button  = static fn( $label, $to ) => self::row( self::b( 'button', [ 'label' => $label, 'to' => $to, 'padV' => 14, 'align' => 'center' ] ) );
        $line    = self::row( self::b( 'divider', [ 'padV' => 12 ] ) );
        $copy    = self::copy_for( $id );
        $rows    = [ self::row( self::b( 'logo', [ 'width' => 150, 'align' => 'center', 'padV' => 22 ] ) ), $title( $copy['title'] ) ];
        if ( ( $copy['text'] ?? '' ) !== '' ) $rows[] = $text( $copy['text'] );
        if ( $def['kind'] === 'order' ) {
            if ( $id === 'customer_note' ) {
                $rows[] = self::row( self::b( 'text', [ 'html' => '<p><b>{customer_note}</b></p>', 'size' => 15, 'padV' => 8 ] ) );
            }
            if ( $id === 'customer_invoice' ) $rows[] = $button( self::l( 'Pay for this order', 'לתשלום על ההזמנה' ), 'pay' );
            $rows[] = self::row( self::b( 'order_button', [ 'label' => $def['group'] === 'team' ? self::l( 'Open the order', 'פתיחת ההזמנה' ) : self::l( 'View your order', 'צפייה בהזמנה' ), 'padV' => 14, 'align' => 'center' ] ) );
            $rows[] = $line;
            $rows[] = self::row( self::b( 'order_info', [ 'padV' => 4 ] ) );
            $rows[] = self::row( self::b( 'order_items', [ 'images' => true, 'padV' => 8 ] ) );
            $rows[] = self::row( self::b( 'order_totals', [ 'padV' => 4 ] ) );
            $rows[] = self::row( self::b( 'downloads', [ 'padV' => 6 ] ) );
            $rows[] = self::row( self::b( 'addresses', [ 'which' => 'both', 'padV' => 14 ] ) );
        } elseif ( $id === 'customer_reset_password' ) {
            $rows[] = $button( self::l( 'Choose a new password', 'בחירת סיסמה חדשה' ), 'reset' );
            $rows[] = $text( self::l( 'If you didn\'t ask for this, you can ignore this email. Your password stays as it is.', 'אם לא ביקשת זאת, אפשר להתעלם מהמייל. הסיסמה שלך נשארת כמו שהיא.' ) );
        } elseif ( $id === 'customer_new_account' ) {
            $rows[] = $button( self::l( 'Go to your account', 'לחשבון שלך' ), 'account' );
        } elseif ( $id === 'review_request' ) {
            $rows[] = self::row( self::b( 'review_links', [ 'padV' => 8 ] ) );
        } elseif ( $id === 'gift_card' ) {
            // The logo and the card's own header, no title of its own.
            $rows = [
                self::row( self::b( 'logo', [ 'width' => 150, 'align' => 'center', 'padV' => 22 ] ) ),
                self::row( self::b( 'gift_header', [ 'padV' => 4 ] ) ),
                self::row( self::b( 'gift_card', [ 'padV' => 14 ] ) ),
                self::row( self::b( 'gift_message', [ 'padV' => 10 ] ) ),
                self::row( self::b( 'gift_button', [ 'label' => self::l( 'Shop with your gift card', 'לקנייה עם כרטיס המתנה' ), 'padV' => 14 ] ) ),
                $text( self::l( 'Use the code at checkout. You can spend it over several orders, and the balance stays on the card.', 'משתמשים בקוד בתשלום. אפשר לנצל אותו בכמה הזמנות, והיתרה נשארת בכרטיס.' ) ),
            ];
        } elseif ( $id === 'gift_card_bounced' ) {
            $rows[] = $button( self::l( 'Go to the store', 'לחנות' ), 'shop' );
        } elseif ( $id === 'back_in_stock' ) {
            $rows[] = $button( self::l( 'View {product_name}', 'לצפייה ב{product_name}' ), 'product' );
        } else {
            $rows[] = $button( self::l( 'Open your products', 'פתיחת המוצרים' ), 'product' );
        }
        $rows[] = self::row( self::b( 'footer', [ 'text' => $st['footer'], 'padV' => 16 ] ), [ 'bg' => $st['bg'] ] );
        return Qwoo_Email_Render::clean( [
            'settings' => [ 'bg' => $st['bg'], 'color' => '#1d1b2e', 'link' => $st['button'], 'button_bg' => $st['button'], 'button_color' => '#ffffff', 'font' => $st['font'] ],
            'rows'     => $rows,
        ] );
    }

    private static function copy_for( string $id ): array {
        switch ( $id ) {
            case 'customer_processing_order': return [ 'title' => self::l( 'Thanks for your order, {first_name}', 'תודה על ההזמנה, {first_name}' ), 'text' => self::l( 'We\'ve got order #{order_number} and we\'re getting it ready.', 'קיבלנו את הזמנה {order_number} ואנחנו מכינים אותה.' ) ];
            case 'customer_on_hold_order':    return [ 'title' => self::l( 'We got your order, {first_name}', 'קיבלנו את ההזמנה, {first_name}' ), 'text' => self::l( 'Order #{order_number} is waiting for payment. We\'ll start on it as soon as the payment arrives.', 'הזמנה {order_number} ממתינה לתשלום. נתחיל לטפל בה ברגע שהתשלום יגיע.' ) ];
            case 'customer_completed_order':  return [ 'title' => self::l( 'Your order is complete, {first_name}', 'ההזמנה שלך הושלמה, {first_name}' ), 'text' => self::l( 'Order #{order_number} is done. Thanks for shopping with us.', 'הזמנה {order_number} הושלמה. תודה שקניתם אצלנו.' ) ];
            case 'customer_refunded_order':   return [ 'title' => self::l( 'Your refund, {first_name}', 'הזיכוי שלך, {first_name}' ), 'text' => self::l( 'We refunded order #{order_number}. Here are the details.', 'החזרנו את התשלום על הזמנה {order_number}. הנה הפרטים.' ) ];
            case 'customer_cancelled_order':  return [ 'title' => self::l( 'Your order was cancelled', 'ההזמנה שלך בוטלה' ), 'text' => self::l( 'Order #{order_number} was cancelled.', 'הזמנה {order_number} בוטלה.' ) ];
            case 'customer_failed_order':     return [ 'title' => self::l( 'Your payment didn\'t go through', 'התשלום לא עבר' ), 'text' => self::l( 'We couldn\'t complete the payment for order #{order_number}.', 'לא הצלחנו להשלים את התשלום על הזמנה {order_number}.' ) ];
            case 'customer_note':             return [ 'title' => self::l( 'A note about your order', 'הערה על ההזמנה שלך' ), 'text' => self::l( 'We added a note to order #{order_number}:', 'הוספנו הערה להזמנה {order_number}:' ) ];
            case 'customer_invoice':          return [ 'title' => self::l( 'Your order details', 'פרטי ההזמנה שלך' ), 'text' => self::l( 'Here are the details of order #{order_number}.', 'הנה הפרטים של הזמנה {order_number}.' ) ];
            case 'customer_new_account':      return [ 'title' => self::l( 'Welcome to {store_name}', 'ברוכים הבאים ל{store_name}' ), 'text' => self::l( 'Your account is ready. You can see your orders and details there.', 'החשבון שלך מוכן. אפשר לראות בו את ההזמנות והפרטים שלך.' ) ];
            case 'customer_reset_password':   return [ 'title' => self::l( 'Reset your password', 'איפוס סיסמה' ), 'text' => self::l( 'Someone asked for a new password for your account at {store_name}.', 'התקבלה בקשה לסיסמה חדשה לחשבון שלך ב{store_name}.' ) ];
            case 'back_in_stock':             return [ 'title' => self::l( 'It\'s back', 'זה חזר למלאי' ), 'text' => self::l( 'Good news: {product_name} is back in stock at {store_name}. Get it before it\'s gone again.', 'חדשות טובות: {product_name} חזר למלאי ב{store_name}. כדאי למהר לפני שייגמר שוב.' ) ];
            case 'review_request':            return [ 'title' => self::l( 'How was your order?', 'איך הייתה ההזמנה?' ), 'text' => self::l( 'Thank you for your order from {store_name}. We\'d love to hear what you think. It only takes a minute:', 'תודה על ההזמנה מ{store_name}. נשמח לשמוע מה דעתך. זה לוקח רק דקה:' ) ];
            case 'gift_card':                 return [ 'title' => self::l( '{sender_name} sent you a {store_name} gift card', '{sender_name} שלחו לך כרטיס מתנה מ{store_name}' ), 'text' => '' ];
            case 'gift_card_bounced':         return [ 'title' => self::l( 'We couldn\'t deliver your gift card', 'לא הצלחנו למסור את כרטיס המתנה שלך' ), 'text' => self::l( 'Hi {first_name}, the gift card for {recipient_name} couldn\'t be delivered to {recipient_email}. Reply to this email with the right address and we\'ll send it again.', 'היי {first_name}, לא הצלחנו למסור את כרטיס המתנה של {recipient_name} לכתובת {recipient_email}. אפשר להשיב למייל הזה עם הכתובת הנכונה ונשלח אותו שוב.' ) ];
            case 'new_order':                 return [ 'title' => self::l( 'New order #{order_number}', 'הזמנה חדשה {order_number}' ), 'text' => self::l( 'You have a new order from {first_name} {last_name}.', 'יש לך הזמנה חדשה מ{first_name} {last_name}.' ) ];
            case 'cancelled_order':           return [ 'title' => self::l( 'Order #{order_number} was cancelled', 'הזמנה {order_number} בוטלה' ), 'text' => self::l( 'The order from {first_name} {last_name} was cancelled.', 'ההזמנה של {first_name} {last_name} בוטלה.' ) ];
            case 'failed_order':              return [ 'title' => self::l( 'Payment failed on order #{order_number}', 'התשלום נכשל בהזמנה {order_number}' ), 'text' => self::l( 'The payment from {first_name} {last_name} failed.', 'התשלום של {first_name} {last_name} נכשל.' ) ];
            case 'low_stock':                 return [ 'title' => self::l( '{product_name} is running low', '{product_name} עומד להיגמר' ), 'text' => self::l( '{stock} left in stock.', 'נשארו במלאי: {stock}.' ) ];
            case 'no_stock':                  return [ 'title' => self::l( '{product_name} sold out', '{product_name} אזל מהמלאי' ), 'text' => self::l( 'It\'s out of stock now.', 'הוא אזל מהמלאי עכשיו.' ) ];
        }
        return [ 'title' => '{store_name}', 'text' => '' ];
    }

    public static function design_for( string $id ): array {
        $saved = self::saved( $id )['design'] ?? null;
        return is_array( $saved ) ? $saved : self::default_design( $id );
    }

    /* ---------------- WooCommerce's emails ---------------- */

    private static function enabled( string $id, bool $on ): bool {
        if ( ! empty( self::catalog()[ $id ]['lock'] ) ) return $on;
        $saved = self::saved( $id );
        return array_key_exists( 'on', $saved ) ? (bool) $saved['on'] : $on;
    }

    private static function recipients( string $id, $to ) {
        $list = (array) ( self::saved( $id )['to'] ?? [] );
        return ( self::catalog()[ $id ]['group'] ?? '' ) === 'team' && $list ? implode( ',', $list ) : $to;
    }

    private static function subject( string $id, string $subject, $object, $email ): string {
        $mine = trim( (string) ( self::saved( $id )['subject'] ?? '' ) );
        return $mine === '' ? $subject : strtr( $mine, self::tokens( $object instanceof WC_Order ? $object : null, is_object( $email ) ? $email : null, [] ) );
    }

    public static function headers( $headers, $id = '', $object = null ) {
        $headers = (string) $headers;
        $saved   = self::saved( (string) $id );
        $def     = self::catalog()[ (string) $id ] ?? null;
        if ( ! $def ) return $headers;
        if ( $def['group'] === 'customer' && ! empty( $saved['copy'] ) ) {
            $headers .= 'Bcc: ' . implode( ',', (array) $saved['copy'] ) . "\r\n";
        }
        $reply = (string) get_option( self::REPLY, '' );
        if ( $def['group'] === 'customer' && is_email( $reply ) && stripos( $headers, 'reply-to' ) === false ) {
            $headers .= 'Reply-To: ' . $reply . "\r\n";
        }
        if ( in_array( $id, [ 'low_stock', 'no_stock' ], true ) && stripos( $headers, 'content-type' ) === false ) {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        }
        return $headers;
    }

    /** Where an email's buttons go. */
    private static function urls( ?WC_Order $order, $email, array $args, bool $admin ): array {
        $front = '';
        if ( $order && function_exists( 'qwoo_email_frontend_url' ) ) $front = (string) qwoo_email_frontend_url( $order );
        if ( $front === '' && class_exists( 'Qwoo_Technical_Settings' ) ) $front = (string) Qwoo_Technical_Settings::get_primary_frontend_domain();
        $front = rtrim( $front, '/' );
        $urls  = [ 'shop' => $front . '/', 'account' => $front . '/my-account' ];
        if ( $order ) {
            $urls['order'] = $urls['account'];
            if ( $order->needs_payment() ) $urls['pay'] = $order->get_checkout_payment_url();
            if ( $admin ) {
                $platform = get_option( 'qwoo_platform_connection', [] );
                $base     = is_array( $platform ) ? rtrim( (string) ( $platform['platform_url'] ?? '' ), '/' ) : '';
                $urls['order'] = $base !== '' ? $base . '/app/orders/' . $order->get_id() : $order->get_edit_order_url();
            }
        }
        if ( isset( $args['reset_key'], $args['user_login'] ) ) {
            $urls['reset'] = add_query_arg( [ 'key' => $args['reset_key'], 'id' => (int) ( $args['user_id'] ?? 0 ), 'login' => rawurlencode( (string) $args['user_login'] ) ], wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) ) );
        }
        if ( is_object( $email ) && ! empty( $email->set_password_url ) ) $urls['account'] = (string) $email->set_password_url;
        if ( ! empty( $args['product_url'] ) ) $urls['product'] = (string) $args['product_url'];
        return $urls;
    }

    private static function tokens( ?WC_Order $order, $email, array $args ): array {
        $name  = '';
        $last  = '';
        if ( $order ) {
            $name = $order->get_billing_first_name();
            $last = $order->get_billing_last_name();
        } elseif ( is_object( $email ) && isset( $email->object ) && $email->object instanceof WP_User ) {
            $name = (string) ( $email->object->first_name ?: $email->object->display_name );
            $last = (string) $email->object->last_name;
        }
        $out = [
            '{first_name}'    => $name,
            '{last_name}'     => $last,
            '{store_name}'    => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            '{site_title}'    => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            '{customer_note}' => (string) ( $args['customer_note'] ?? '' ),
            '{user_login}'    => (string) ( $args['user_login'] ?? '' ),
            '{product_name}'  => (string) ( $args['product_name'] ?? '' ),
            '{stock}'         => (string) ( $args['stock'] ?? '' ),
            '{recipient_name}'  => (string) ( $args['recipient_name'] ?? '' ),
            '{recipient_email}' => (string) ( $args['recipient_email'] ?? '' ),
            '{sender_name}'     => (string) ( $args['sender_name'] ?? '' ),
            '{gift_amount}'     => (string) ( $args['gift_amount'] ?? '' ),
        ];
        if ( ! $order && isset( $args['first_name'] ) ) $out['{first_name}'] = (string) $args['first_name'];
        if ( $order ) {
            $out['{order_number}'] = (string) $order->get_order_number();
            $out['{order_total}']  = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES );
            $out['{order_date}']   = $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '';
        }
        return $out;
    }

    private static function context( string $id, ?WC_Order $order, $email, array $args ): array {
        $def   = self::catalog()[ $id ] ?? [ 'group' => 'customer' ];
        $admin = $def['group'] === 'team';
        return [
            'preheader'    => (string) ( self::saved( $id )['preheader'] ?? '' ),
            'logo'         => self::style()['logo'],
            'tokens'       => self::tokens( $order, $email, $args ),
            'urls'         => self::urls( $order, $email, $args, $admin ),
            'order'        => $order,
            'email'        => $email,
            'admin'        => $admin,
            'note'         => (string) ( $args['customer_note'] ?? '' ),
            'review_links' => (array) ( $args['review_links'] ?? [] ),
        ];
    }

    /** Used by wc_get_template: our design in place of WooCommerce's template file. */
    public static function swap_template( $template, $name, $args, $path = '', $default = '' ) {
        $email = is_array( $args ) ? ( $args['email'] ?? null ) : null;
        if ( ! $email instanceof WC_Email || $name !== $email->template_html || ! isset( self::catalog()[ $email->id ] ) ) return $template;
        try {
            $order = ( $args['order'] ?? null ) instanceof WC_Order ? $args['order'] : ( $email->object instanceof WC_Order ? $email->object : null );
            self::$rendered = Qwoo_Email_Render::html( self::design_for( $email->id ), self::context( $email->id, $order, $email, (array) $args ) );
            self::$final    = self::$rendered;
            return QWOO_PATH . 'includes/store-email-template.php';
        } catch ( \Throwable $e ) {
            error_log( '[qwoo email] ' . $email->id . ': ' . $e->getMessage() ); // phpcs:ignore
            self::$final = null;
            return $template;
        }
    }

    /** WooCommerce's styling step changes tables: what goes out is exactly what we made. */
    public static function final_content( $content ) {
        if ( self::$final === null ) return $content;
        $out = self::$final;
        self::$final = null;
        return $out;
    }

    /* ---------------- low and out of stock (WooCommerce sends these as plain text) ---------------- */

    private static function stock_args( $product ): array {
        if ( ! $product instanceof WC_Product ) return [];
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? rtrim( (string) Qwoo_Technical_Settings::get_primary_frontend_domain(), '/' ) : '';
        $platform = get_option( 'qwoo_platform_connection', [] );
        $base  = is_array( $platform ) ? rtrim( (string) ( $platform['platform_url'] ?? '' ), '/' ) : '';
        return [ 'product_name' => html_entity_decode( $product->get_name(), ENT_QUOTES ), 'stock' => (string) $product->get_stock_quantity(), 'product_url' => $base !== '' ? $base . '/app/products/' . $product->get_id() : $front . '/' ];
    }

    private static function stock_subject( string $id, string $subject, $product ): string {
        $mine = trim( (string) ( self::saved( $id )['subject'] ?? '' ) );
        return $mine === '' ? $subject : strtr( $mine, self::tokens( null, null, self::stock_args( $product ) ) );
    }

    private static function stock_content( string $id, string $text, $product ): string {
        try {
            return Qwoo_Email_Render::html( self::design_for( $id ), self::context( $id, null, null, self::stock_args( $product ) ) );
        } catch ( \Throwable $e ) {
            error_log( '[qwoo email] ' . $id . ': ' . $e->getMessage() ); // phpcs:ignore
            return nl2br( esc_html( $text ) );
        }
    }

    /* ---------------- emails the store sends itself ---------------- */

    /** An email of the store's own (gift cards): its design with these details; $extra goes into the design's ctx (gift). */
    public static function render_own( string $id, array $args, array $extra = [] ): string {
        return Qwoo_Email_Render::html( self::design_for( $id ), array_merge( self::context( $id, null, null, $args ), $extra ) );
    }

    /** The owner's subject for the email, or $default, with the details filled in. */
    public static function own_subject( string $id, array $args, string $default ): string {
        $mine = trim( (string) ( self::saved( $id )['subject'] ?? '' ) );
        return strtr( $mine !== '' ? $mine : $default, self::tokens( null, null, $args ) );
    }

    public static function is_on( string $id ): bool {
        if ( $id === 'review_request' && class_exists( 'Qwoo_Reviews' ) ) return ! empty( Qwoo_Reviews::settings()['request'] );
        $saved = self::saved( $id );
        return array_key_exists( 'on', $saved ) ? (bool) $saved['on'] : true;
    }

    /**
     * Back in stock and review requests. True or false: sent or not. Null: the
     * design failed, the caller sends its plain version.
     */
    public static function send_own( string $id, string $to, string $default_subject, array $args, ?WC_Order $order = null ) {
        if ( ! self::is_on( $id ) ) return false;
        try {
            $html    = Qwoo_Email_Render::html( self::design_for( $id ), self::context( $id, $order, null, $args ) );
            $mine    = trim( (string) ( self::saved( $id )['subject'] ?? '' ) );
            $subject = $mine !== '' ? strtr( $mine, self::tokens( $order, null, $args ) ) : $default_subject;
            self::$final = $html;
            $sent = (bool) WC()->mailer()->send( $to, $subject, $html, self::headers( "Content-Type: text/html\r\n", $id ) );
            self::$final = null;
            return $sent;
        } catch ( \Throwable $e ) {
            error_log( '[qwoo email] ' . $id . ': ' . $e->getMessage() ); // phpcs:ignore
            return null;
        }
    }

    /* ---------------- the dashboard ---------------- */

    private static function wc_emails(): array {
        $out = [];
        foreach ( WC()->mailer()->get_emails() as $email ) {
            $out[ $email->id ] = $email;
        }
        return $out;
    }

    public static function overview(): array {
        $wc   = self::wc_emails();
        $list = [];
        foreach ( self::catalog() as $id => $def ) {
            if ( ! empty( $def['wc'] ) && ! isset( $wc[ $id ] ) ) continue; // an older WooCommerce doesn't have it
            $mail  = $wc[ $id ] ?? null;
            $saved = self::saved( $id );
            if ( $mail ) {
                $on      = $mail->is_enabled();
                $subject = (string) $mail->get_default_subject();
                $to      = $def['group'] === 'team' ? array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $mail->recipient ?: get_option( 'admin_email' ) ) ) ) ) ) : [];
            } else {
                $on      = in_array( $id, [ 'low_stock', 'no_stock' ], true ) ? get_option( 'woocommerce_notify_' . $id, 'yes' ) === 'yes' : self::is_on( $id );
                $subject = $id === 'back_in_stock' ? self::l( '{product_name} is back in stock', '{product_name} חזר למלאי' ) : ( $id === 'review_request' ? self::l( 'How was your order from {store_name}?', 'איך הייתה ההזמנה מ{store_name}?' ) : self::copy_for( $id )['title'] );
                $to      = $def['group'] === 'team' ? array_values( array_filter( array_map( 'trim', explode( ',', (string) get_option( 'woocommerce_stock_email_recipient', get_option( 'admin_email' ) ) ) ) ) ) : [];
            }
            $list[] = [
                'id'        => $id,
                'name'      => $def['name'],
                'when'      => $def['when'],
                'group'     => $def['group'],
                'lock'      => ! empty( $def['lock'] ),
                'on'        => $on,
                'custom'    => is_array( $saved['design'] ?? null ),
                'subject'   => (string) ( $saved['subject'] ?? '' ),
                'default_subject' => $subject,
                'preheader' => (string) ( $saved['preheader'] ?? '' ),
                'to'        => (array) ( $saved['to'] ?? $to ),
                'copy'      => (array) ( $saved['copy'] ?? [] ),
            ];
        }
        return [
            'emails' => $list,
            'style'  => self::style(),
            'sender' => [
                'from_name'  => html_entity_decode( (string) get_option( 'woocommerce_email_from_name' ), ENT_QUOTES ),
                'from_email' => (string) get_option( 'woocommerce_email_from_address' ),
                'reply_to'   => (string) get_option( self::REPLY, '' ),
            ],
        ];
    }

    /** What the designer needs for one email. */
    public static function get( string $id ) {
        $def = self::catalog()[ $id ] ?? null;
        if ( ! $def ) return new WP_Error( 'missing', 'That email doesn\'t exist.' );
        $tokens = [ 'first_name', 'store_name' ];
        $blocks = [];
        $targets = [ 'shop', 'account' ];
        switch ( $def['kind'] ) {
            case 'order':
                $tokens  = [ 'first_name', 'last_name', 'order_number', 'order_total', 'order_date', 'store_name' ];
                if ( $id === 'customer_note' ) $tokens[] = 'customer_note';
                $blocks  = [ 'order_items', 'order_totals', 'order_info', 'addresses', 'order_button', 'downloads' ];
                $targets = [ 'shop', 'account', 'pay' ];
                break;
            case 'account':
                $tokens = [ 'first_name', 'last_name', 'user_login', 'store_name' ];
                if ( $id === 'customer_reset_password' ) $targets[] = 'reset';
                break;
            case 'stock':
                $tokens = [ 'product_name', 'store_name' ];
                if ( $id !== 'back_in_stock' ) $tokens[] = 'stock';
                $targets[] = 'product';
                break;
            case 'review':
                $tokens = [ 'first_name', 'last_name', 'store_name' ];
                $blocks = [ 'review_links' ];
                break;
            case 'gift':
                $tokens = [ 'recipient_name', 'sender_name', 'gift_amount', 'store_name' ];
                $blocks = [ 'gift_header', 'gift_card', 'gift_message', 'gift_button' ];
                $targets = [ 'shop' ];
                break;
            case 'gift_notice':
                $tokens = [ 'first_name', 'recipient_name', 'recipient_email', 'store_name' ];
                $targets = [ 'shop' ];
                break;
        }
        $saved = self::saved( $id );
        return [
            'id'      => $id,
            'name'    => $def['name'],
            'custom'  => is_array( $saved['design'] ?? null ),
            'design'  => self::design_for( $id ),
            'tokens'  => $tokens,
            'blocks'  => $blocks,
            'targets' => $targets,
            'logo'    => self::style()['logo'],
        ];
    }

    public static function save_settings( array $in ) {
        $id  = (string) ( $in['id'] ?? '' );
        $def = self::catalog()[ $id ] ?? null;
        if ( ! $def ) return new WP_Error( 'missing', 'That email doesn\'t exist.' );
        $data = [
            'subject'   => mb_substr( sanitize_text_field( (string) ( $in['subject'] ?? '' ) ), 0, 190 ),
            'preheader' => mb_substr( sanitize_text_field( (string) ( $in['preheader'] ?? '' ) ), 0, 190 ),
        ];
        if ( $def['group'] === 'team' ) {
            $to = self::emails_list( $in['to'] ?? [] );
            if ( ! $to ) return new WP_Error( 'to', 'Add at least one address, or turn this email off.' );
            $data['to'] = $to;
        } else {
            $data['copy'] = self::emails_list( $in['copy'] ?? [] );
        }
        if ( empty( $def['lock'] ) && array_key_exists( 'on', $in ) ) {
            $on = ! empty( $in['on'] );
            $data['on'] = $on;
            if ( $id === 'review_request' && class_exists( 'Qwoo_Reviews' ) ) Qwoo_Reviews::save_settings( array_merge( Qwoo_Reviews::settings(), [ 'request' => $on ] ) );
        }
        self::put( $id, $data );
        return self::overview();
    }

    public static function save_design( array $in ) {
        $id = (string) ( $in['id'] ?? '' );
        if ( ! isset( self::catalog()[ $id ] ) ) return new WP_Error( 'missing', 'That email doesn\'t exist.' );
        $design = Qwoo_Email_Render::clean( $in['design'] ?? [] );
        if ( ! Qwoo_Email_Render::has_content( $design ) ) return new WP_Error( 'empty', 'The email is empty.' );
        self::put( $id, [ 'design' => $design ] );
        return self::get( $id );
    }

    public static function reset_design( string $id ) {
        $all = self::all();
        unset( $all[ $id ]['design'] );
        update_option( self::OPTION, $all, false );
        return self::get( $id );
    }

    /* Blocks the owner saved, for any email. */

    public static function saved_blocks(): array {
        $list = get_option( 'qwoo_email_saved_blocks', [] );
        return is_array( $list ) ? array_values( $list ) : [];
    }

    public static function save_block( array $in ) {
        $block = Qwoo_Email_Render::clean_block( $in['block'] ?? [] );
        if ( ! $block ) return new WP_Error( 'block', 'That block can\'t be saved.' );
        $list = self::saved_blocks();
        if ( count( $list ) >= 30 ) return new WP_Error( 'max', 'You can keep up to 30 saved blocks. Delete one first.' );
        array_unshift( $list, [ 'id' => 'sb' . bin2hex( random_bytes( 5 ) ), 'name' => mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 80 ), 'block' => $block ] );
        update_option( 'qwoo_email_saved_blocks', $list, false );
        return [ 'blocks' => $list ];
    }

    public static function delete_block( string $id ): array {
        $list = array_values( array_filter( self::saved_blocks(), static fn( $b ) => $b['id'] !== $id ) );
        update_option( 'qwoo_email_saved_blocks', $list, false );
        return [ 'blocks' => $list ];
    }

    public static function save_sender( array $in ) {
        $from = sanitize_email( (string) ( $in['from_email'] ?? '' ) );
        $name = mb_substr( sanitize_text_field( (string) ( $in['from_name'] ?? '' ) ), 0, 100 );
        if ( ! is_email( $from ) ) return new WP_Error( 'from', 'Enter a valid sender email.' );
        if ( $name === '' ) return new WP_Error( 'name', 'Enter the sender name for customer emails.' );
        $reply = sanitize_email( (string) ( $in['reply_to'] ?? '' ) );
        update_option( 'woocommerce_email_from_name', $name );
        update_option( 'woocommerce_email_from_address', $from );
        update_option( self::REPLY, is_email( $reply ) ? $reply : '', false );
        return self::overview();
    }

    /** An order to show: the latest real one, or an example that's never saved. */
    private static function sample_order(): WC_Order {
        $latest = wc_get_orders( [ 'limit' => 1, 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ), 'orderby' => 'date', 'order' => 'DESC' ] );
        if ( $latest ) return $latest[0];
        $order = new WC_Order();
        $order->set_billing_first_name( 'Dana' );
        $order->set_billing_last_name( 'Levi' );
        $order->set_billing_address_1( '8 Rothschild Blvd' );
        $order->set_billing_city( 'Tel Aviv' );
        $order->set_shipping_address_1( '8 Rothschild Blvd' );
        $order->set_shipping_city( 'Tel Aviv' );
        $order->set_date_created( time() );
        $item = new WC_Order_Item_Product();
        $item->set_name( 'Example product' );
        $item->set_quantity( 1 );
        $item->set_subtotal( 100 );
        $item->set_total( 100 );
        $order->add_item( $item );
        $order->set_total( 100 );
        return $order;
    }

    /** The email as it would go out, with an example order or person. */
    public static function sample_html( string $id, array $design ): string {
        $home  = class_exists( 'Qwoo_Email_Marketing' ) ? Qwoo_Email_Marketing::front_home() : home_url( '/' ); // sample links go to the storefront, never the backend
        $def   = self::catalog()[ $id ] ?? [ 'kind' => 'order' ];
        $order = in_array( $def['kind'], [ 'order', 'review' ], true ) ? self::sample_order() : null;
        $args  = [
            'customer_note' => self::l( 'Your order ships tomorrow morning.', 'ההזמנה תישלח מחר בבוקר.' ),
            'user_login'    => 'dana',
            'product_name'  => self::l( 'Example product', 'מוצר לדוגמה' ),
            'stock'         => '2',
            'review_links'  => [ [ 'label' => self::l( 'Review Example product', 'כתיבת ביקורת' ), 'url' => $home ] ],
            'product_url'   => $home,
        ];
        if ( in_array( $def['kind'], [ 'gift', 'gift_notice' ], true ) ) {
            $args += [ 'recipient_name' => 'Noa', 'recipient_email' => 'noa@example.com', 'sender_name' => self::l( 'Dana and Yoni', 'דנה ויוני' ), 'gift_amount' => html_entity_decode( wp_strip_all_tags( wc_price( 200 ) ), ENT_QUOTES ) ];
        }
        $ctx = self::context( $id, $order, null, $args );
        $ctx['urls'] += [ 'reset' => $home, 'pay' => $home, 'order' => $home ];
        if ( ! $order ) $ctx['tokens']['{first_name}'] = 'Dana';
        if ( $def['kind'] === 'gift' && class_exists( 'Qwoo_Gift_Cards' ) ) {
            $d = Qwoo_Gift_Cards::default_designs()[0];
            $ctx['gift'] = [
                'title' => $d['title'], 'mark' => $d['mark'], 'color' => Qwoo_Gift_Cards::COLORS[0], 'image' => '',
                'amount' => $args['gift_amount'], 'code' => 'K7QX-9DMP-3RTA-H2WN',
                'expiry' => sprintf( self::l( 'Valid until %s', 'בתוקף עד %s' ), wp_date( get_option( 'date_format' ), strtotime( '+5 years' ) ) ),
                'to' => 'Noa', 'from' => $args['sender_name'], 'message' => self::l( 'Happy birthday! Pick something lovely for yourself.', 'יום הולדת שמח! תבחרי לך משהו יפה.' ), 'shop' => $home,
            ];
        }
        return Qwoo_Email_Render::html( $design, $ctx );
    }

    public static function test( array $in ) {
        $id = (string) ( $in['id'] ?? '' );
        $to = sanitize_email( (string) ( $in['to'] ?? '' ) );
        if ( ! isset( self::catalog()[ $id ] ) ) return new WP_Error( 'missing', 'That email doesn\'t exist.' );
        if ( ! is_email( $to ) ) return new WP_Error( 'email', 'Enter a valid email address.' );
        $design = is_array( $in['design'] ?? null ) ? Qwoo_Email_Render::clean( $in['design'] ) : self::design_for( $id );
        $list   = array_column( self::overview()['emails'], null, 'id' );
        $row    = $list[ $id ] ?? [];
        $tokens = [ '{first_name}' => 'Dana', '{last_name}' => 'Levi', '{order_number}' => '1042', '{store_name}' => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ), '{product_name}' => self::l( 'Example product', 'מוצר לדוגמה' ), '{order_total}' => '', '{order_date}' => '', '{stock}' => '2', '{user_login}' => 'dana', '{customer_note}' => '', '{site_title}' => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ), '{order_date_short}' => '', '{recipient_name}' => 'Noa', '{recipient_email}' => 'noa@example.com', '{sender_name}' => self::l( 'Dana and Yoni', 'דנה ויוני' ), '{gift_amount}' => html_entity_decode( wp_strip_all_tags( wc_price( 200 ) ), ENT_QUOTES ) ];
        $subject = strtr( (string) ( ( $row['subject'] ?? '' ) ?: ( $row['default_subject'] ?? '' ) ), $tokens );
        $sent = wp_mail( $to, '[Test] ' . $subject, self::sample_html( $id, $design ), [ 'Content-Type: text/html; charset=UTF-8' ] );
        return $sent ? [ 'ok' => true ] : new WP_Error( 'mail', 'The test couldn\'t be sent.' );
    }
}

Qwoo_Store_Emails::init();

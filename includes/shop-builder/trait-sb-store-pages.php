<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The pages every store has: its homepage (a page with role "home", at "/")
 * and ready-made Privacy policy, Terms of service and Shipping & returns
 * pages (roles privacy / terms / returns), filled with the store's details.
 *
 * New stores get all four at setup (apply_platform_branding): Home is made
 * from the template's homepage with an opening section of the store's name,
 * its description and an "Our products" button; the legal pages start as
 * drafts for the owner to check. Older stores get their old Homepage tab
 * (hero + sections) turned into the Home page the first time the dashboard
 * or wp-admin opens; nothing of theirs is overwritten.
 *
 * The legal texts are plain-language starting points, not legal advice:
 * [brackets] mark what the owner has to fill in or check.
 */
trait SB_Store_Pages {

    /** Roles of the legal pages made and published at setup that the owner hasn't marked as read. */
    /** (A static property: trait constants need PHP 8.2.) */
    public static $legal_review_option = 'qwoo_legal_review';

    /** For the Overview: [ { role, id, title, slug } ] of the generated legal pages still to read. */
    public static function legal_review(): array {
        $roles = (array) get_option( self::$legal_review_option, [] );
        if ( ! $roles ) return [];
        $out = [];
        foreach ( self::custom_pages_of( (array) get_option( 'shop_builder_options', [] ) ) as $page ) {
            if ( in_array( $page['role'] ?? '', $roles, true ) ) {
                $out[] = [ 'role' => (string) $page['role'], 'id' => (string) $page['id'], 'title' => (string) $page['title'], 'slug' => (string) $page['slug'] ];
            }
        }
        return $out;
    }

    /**
     * Makes sure the store has its homepage (and, with $legal, the legal
     * pages). $name: the store's name when it's newer than the site title.
     * Returns whether anything was added.
     */
    public static function ensure_store_pages( $legal = false, $name = '' ) {
        if ( ! is_bool( $legal ) ) $legal = false; // called by admin_init with no arguments
        $options = (array) get_option( 'shop_builder_options', [] );
        $pages   = self::custom_pages_of( $options );
        $roles   = array_filter( array_map( static fn( $p ) => (string) ( $p['role'] ?? '' ), $pages ) );
        $add     = [];

        if ( ! in_array( 'home', $roles, true ) ) {
            $add[] = self::home_page( (array) ( $options['home'] ?? [] ), $legal, (string) $name );
        }
        $review = [];
        if ( $legal ) {
            foreach ( [ 'privacy', 'terms', 'returns' ] as $role ) {
                if ( ! in_array( $role, $roles, true ) ) {
                    // Live from the start (user, 2026-10-30); the Overview asks the owner to read them.
                    $add[]    = self::store_page_template( $role, (string) $name ) + [ 'id' => self::new_id( 'pg' ), 'parent' => '', 'status' => 'publish', 'seo' => [] ];
                    $review[] = $role;
                }
            }
        }
        if ( ! $add || count( $pages ) + count( $add ) > self::$max_pages ) {
            return false;
        }

        $options['custom_pages'] = self::instance()->sanitize_custom_pages( array_merge( $add, $pages ), $pages );
        update_option( 'shop_builder_options', $options );
        if ( $review ) update_option( self::$legal_review_option, $review, false );
        return true;
    }

    /**
     * The Home page. At setup ($fresh) its opening section says the store's
     * name and description; for an existing store it keeps the owner's hero
     * words, and the old homepage sections follow it.
     */
    private static function home_page( array $old, $fresh, $name ) {
        $facts = self::store_page_facts( $name );
        $btn   = (array) ( $old['hero_btn'] ?? [] );
        $hero  = [
            'title'    => $fresh ? '' : (string) ( $old['hero_title'] ?? '' ),
            'text'     => $fresh ? '' : (string) ( $old['hero_description'] ?? '' ),
            'btn_text' => $fresh ? '' : (string) ( $btn['text'] ?? '' ),
            'btn_url'  => $fresh ? '' : (string) ( $btn['url'] ?? '' ),
        ];
        $sections = array_values( array_filter( (array) ( $old['sections'] ?? [] ), 'is_array' ) );
        if ( ! $sections ) {
            // Nothing else on the homepage yet: the newest products.
            $sections[] = [ 'id' => self::new_id( 'sec' ), 'label' => Qwoo_I18n::t( 'Products' ), 'enabled' => true, 'style' => [], 'blocks' => [
                self::store_page_block( 'heading', [ 'title' => Qwoo_I18n::t( 'Our products' ), 'tag' => 'h2', 'alignment' => 'center' ] ),
                self::store_page_block( 'product_grid', [ 'query_type' => 'newest', 'limit' => 8, 'show_view_all' => true, 'view_all_url' => '/products', 'view_all_text' => Qwoo_I18n::t( 'View all products' ) ] ),
            ] ];
        }
        array_unshift( $sections, self::hero_section( [
            'title'    => trim( wp_strip_all_tags( $hero['title'] ) ) !== '' ? $hero['title'] : $facts['name_plain'],
            'text'     => trim( $hero['text'] ) !== '' ? $hero['text'] : $facts['description'],
            'btn_text' => $hero['btn_text'] !== '' ? $hero['btn_text'] : Qwoo_I18n::t( 'Our products' ),
            'btn_url'  => $hero['btn_url'] !== '' ? $hero['btn_url'] : '/products',
            'image_id' => (int) ( $old['hero_image_id'] ?? 0 ),
        ] ) );
        return [
            'id'         => self::new_id( 'pg' ),
            'title'      => Qwoo_I18n::t( 'Home' ),
            'slug'       => 'home',
            'parent'     => '',
            'status'     => 'publish',
            'show_title' => false,
            'role'       => 'home',
            'seo'        => [],
            'sections'   => $sections,
        ];
    }

    /** The opening section: a heading, a line of text and a button, over the photo when there is one. */
    private static function hero_section( array $h ) {
        $style = [
            'width_mode'     => 'contained',
            'min_height'     => [ 'desktop' => '520px', 'mobile' => '380px' ],
            'direction'      => 'column',
            'justify'        => 'center',
            'align_items'    => 'flex-start',
            'gap'            => '16px',
            'padding_preset' => 'medium',
        ];
        if ( $h['image_id'] > 0 ) {
            $style += [
                'bg_type'            => 'image',
                'bg_image'           => $h['image_id'],
                'bg_image_size'      => 'cover',
                'bg_image_position'  => 'center center',
                'bg_overlay_color'   => '#000000',
                'bg_overlay_opacity' => 35,
                'text_color'         => '#ffffff',
            ];
        }
        $blocks = [ self::store_page_block( 'heading', [ 'title' => $h['title'], 'tag' => 'h1', 'alignment' => 'default' ] ) ];
        if ( trim( (string) $h['text'] ) !== '' ) {
            $blocks[] = self::store_page_block( 'text_block', [ 'text' => '<p>' . esc_html( $h['text'] ) . '</p>', 'alignment' => 'default' ] );
        }
        // "secondary": the old homepage's button colour (templates keep "primary" light, for backgrounds).
        $blocks[] = self::store_page_block( 'button', [ 'text' => $h['btn_text'], 'url' => $h['btn_url'], 'style' => 'secondary', 'size' => 'lg', 'alignment' => 'default' ] );
        return [ 'id' => self::new_id( 'sec' ), 'label' => Qwoo_I18n::t( 'Opening' ), 'enabled' => true, 'style' => $style, 'blocks' => $blocks ];
    }

    private static function store_page_block( $type, array $data, array $style = [] ) {
        return [ 'id' => self::new_id( 'blk' ), 'type' => $type, 'label' => '', 'enabled' => true, 'style' => $style, 'data' => $data ];
    }

    /**
     * A new store's header, when its template has none (the storefront
     * draws the header from these sections only): the logo (or the store's
     * name) and the menu on one side; the language switcher, the phone menu
     * button, the wishlist and the cart on the other. Runs at setup only.
     */
    private static function ensure_default_header( array &$options, $logo_id, $name ) {
        if ( ! empty( $options['header']['sections'] ) ) {
            return;
        }
        $rwd    = static fn( $d, $t = null, $m = null ) => [ 'desktop' => $d, 'tablet' => $t ?? $d, 'mobile' => $m ?? $t ?? $d ];
        $hide   = static fn( $d, $t, $m ) => [ 'hide_on' => [ 'desktop' => $d, 'tablet' => $t, 'mobile' => $m ] ];
        $row    = static fn( $justify, $gap, $wrap = 'wrap' ) => [
            'direction' => $rwd( 'row' ), 'wrap' => $rwd( $wrap ), 'justify' => $rwd( $justify ), 'align_items' => $rwd( 'center' ), 'gap' => $rwd( $gap ),
        ];
        $nested = static fn( array $style, array $blocks ) => [ 'id' => self::new_id( 'blk' ), 'type' => 'section', 'label' => '', 'enabled' => true, 'style' => $style, 'blocks' => $blocks ];

        $name = trim( (string) $name ) !== '' ? trim( (string) $name ) : html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
        $logo = (int) $logo_id > 0
            ? self::store_page_block( 'image_block', [
                'images'       => [ [ 'image' => (int) $logo_id, 'alt' => $name, 'link_url' => '/' ] ],
                'layout'       => $rwd( 'row' ),
                'image_height' => $rwd( '40px', '36px', '32px' ),
                'object_fit'   => 'contain',
                'alignment'    => $rwd( 'default' ),
            ] )
            : self::store_page_block( 'text_block', [ 'text' => '<p><a href="/"><strong>' . esc_html( $name ) . '</strong></a></p>', 'font_size' => $rwd( '20px', '18px' ) ] );
        $menu = [ 'menu_id' => '', 'layout' => $rwd( 'horizontal' ), 'enable_mobile_menu' => true, 'icon' => 'menu', 'drawer_title' => Qwoo_I18n::t( 'Menu' ), 'swipe' => 'auto' ];

        $left  = $nested( [ 'width_mode' => 'full', 'padding_preset' => 'none' ] + $row( 'flex-start', '10' ), [
            $logo,
            self::store_page_block( 'menu_widget', $menu + [ 'mobile_menu_breakpoint' => '767', 'drawer_show_languages' => true ], $hide( false, true, true ) ),
        ] );
        $right = $nested( [ 'width_mode' => 'full', 'padding_preset' => 'none' ] + $row( 'flex-start', '5', 'nowrap' ), [
            self::store_page_block( 'language_switcher', [ 'display' => 'flag' ], $hide( false, true, true ) ),
            self::store_page_block( 'menu_widget', $menu + [ 'mobile_menu_breakpoint' => 'custom', 'mobile_menu_custom_breakpoint' => 1024, 'drawer_show_languages' => true ], $hide( true, false, false ) ),
            self::store_page_block( 'account_widget', [ 'icon' => 'person_outline' ] ),
            self::store_page_block( 'mini_wishlist', [ 'icon' => 'heart_outline', 'drawer_title' => Qwoo_I18n::t( 'Wishlist' ), 'swipe' => 'none' ] ),
            self::store_page_block( 'mini_cart', [ 'icon' => 'cart', 'click_action' => 'drawer', 'drawer_title' => Qwoo_I18n::t( 'Cart' ), 'swipe' => 'auto' ] ),
        ] );
        $section = [ 'id' => self::new_id( 'sec' ), 'label' => Qwoo_I18n::t( 'Header' ), 'enabled' => true, 'style' => [
            'width_mode'     => 'contained',
            'padding_preset' => 'custom',
            'padding'        => $rwd( [ 'top' => '5', 'right' => '15', 'bottom' => '5', 'left' => '15' ] ),
        ] + $row( 'space-between', '20' ), 'blocks' => [ $left, $right ] ];

        $header = (array) ( $options['header'] ?? [] );
        $clean  = self::instance()->sanitize_options( [ 'header' => [ 'sections' => [ $section ] ] + $header ] );
        if ( ! empty( $clean['header']['sections'] ) ) {
            $options['header'] = $header;
            $options['header']['sections'] = $clean['header']['sections'];
        }
    }

    /** One text section holding the whole page, for the ready-made pages. */
    private static function text_section( $html ) {
        return [ 'id' => self::new_id( 'sec' ), 'label' => '', 'enabled' => true, 'style' => [], 'blocks' => [ self::store_page_block( 'text_block', [ 'text' => $html ] ) ] ];
    }

    /** What the store is called and how to reach it, ready for the templates. */
    private static function store_page_facts( $name = '' ) {
        $name      = trim( (string) $name ) !== '' ? trim( (string) $name ) : html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
        $new_order = (array) get_option( 'woocommerce_new_order_settings', [] );
        $email     = (string) ( $new_order['recipient'] ?? '' ) ?: (string) get_option( 'admin_email' );
        $email     = is_email( $email ) ? $email : '';
        $street    = trim( (string) get_option( 'woocommerce_store_address' ) );
        $country   = explode( ':', (string) get_option( 'woocommerce_default_country', '' ) )[0];
        $countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : [];
        $address   = $street === '' ? '' : implode( ', ', array_filter( [
            $street,
            trim( (string) get_option( 'woocommerce_store_city' ) ),
            trim( (string) get_option( 'woocommerce_store_postcode' ) ),
            html_entity_decode( (string) ( $countries[ $country ] ?? $country ), ENT_QUOTES ),
        ] ) );
        return [
            'name_plain'  => $name,
            'name'        => $name !== '' ? esc_html( $name ) : Qwoo_I18n::t( '[your store name]' ),
            'email'       => $email !== '' ? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' : Qwoo_I18n::t( '[your email address]' ),
            // A country alone isn't an address.
            'address'     => $address !== '' ? esc_html( $address ) : Qwoo_I18n::t( '[your business address]' ),
            'today'       => self::long_date(),
            'description' => html_entity_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES ),
        ];
    }

    /** A ready-made page: { title, slug, role, show_title, sections }, or null. */
    public static function store_page_template( $role, $name = '' ) {
        $titles = [
            'privacy' => [ 'Privacy policy', 'privacy-policy' ],
            'terms'   => [ 'Terms of service', 'terms-of-service' ],
            'returns' => [ 'Shipping & returns', 'shipping-and-returns' ],
        ];
        if ( ! isset( $titles[ $role ] ) ) {
            return null;
        }
        $f    = self::store_page_facts( $name );
        $lang = Qwoo_Store_Language::get();
        $html = self::legal_text( $role, $lang );
        // No address yet: "{name}, {address}." becomes "{name}." (a bracket would show on a live page).
        if ( strpos( $f['address'], '[' ) === 0 ) $html = str_replace( ', {address}', '', $html );
        $html = strtr( $html, [ '{name}' => $f['name'], '{email}' => $f['email'], '{address}' => $f['address'], '{today}' => $f['today'] ] );
        $html = self::fill_brackets( $html, $lang );
        return [
            'title'      => Qwoo_I18n::t( $titles[ $role ][0] ),
            'slug'       => $titles[ $role ][1],
            'role'       => $role,
            'show_title' => true,
            'sections'   => [ self::text_section( preg_replace( '/>\s+</', '><', trim( $html ) ) ) ],
        ];
    }

    /**
     * The [brackets] filled with the store's own settings (currency, country, taxes) or common
     * choices (14 days to return…), so the pages can go live at setup (user, 2026-10-30): the owner
     * is asked on the Overview to read them. Anything left in brackets loses them.
     */
    private static function fill_brackets( $html, $lang ) {
        $country_code = explode( ':', (string) get_option( 'woocommerce_default_country', '' ) )[0];
        $countries    = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : [];
        $country      = html_entity_decode( (string) ( $countries[ $country_code ] ?? '' ), ENT_QUOTES );
        $code         = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
        $currencies   = function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : [];
        $currency     = html_entity_decode( (string) ( $currencies[ $code ] ?? $code ), ENT_QUOTES );
        $with_tax     = get_option( 'woocommerce_prices_include_tax' ) === 'yes' || ! ( function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() );
        if ( $lang === 'he' ) {
            $country = $country_code === 'IL' || $country === '' ? 'מדינת ישראל' : $country;
            $map = [
                ' [יש לפרט שירותים נוספים, כמו כלי ניתוח או שיווק]' => '',
                ' [אם יש כלי ניתוח או פרסום, יש לכתוב כאן את שמם ואיך אפשר לסרב להם.]' => ' אם נשתמש בכלי ניתוח או פרסום, נבקש את הסכמתך קודם, ואפשר לשנות את הבחירה בכל זמן.',
                '[הזמנות ומבצעים]' => 'הזמנות ומבצעים',
                ' [למשל 7 שנים]' => '',
                '[30]' => '30',
                'ב[מטבע]' => $code === 'ILS' ? 'בשקלים' : 'ב' . $currency,
                '[כוללים / לא כוללים]' => $with_tax ? 'כוללים' : 'לא כוללים',
                '[בעת ההזמנה / במסירה]' => 'בעת ההזמנה',
                'דיני [מדינת ישראל]' => 'דיני ' . $country,
                'ב[עיר]' => 'במחוז שבו נמצא העסק',
                '[מדינות או אזורים]' => $country === 'מדינת ישראל' ? 'כל הארץ' : $country,
                '[1–3]' => '1–3',
                '[3–7]' => '3–7',
                ' [משלוח חינם בהזמנות מעל …]' => '',
                ' [עם מספר מעקב]' => ' עם פרטי המעקב',
                '[14]' => '14',
                '[7]' => '7',
                '[פריטים שאי אפשר להחזיר, למשל: כרטיסי מתנה, מוצרים בהתאמה אישית או מוצרי היגיינה.]' => 'כרטיסי מתנה, מוצרים בהתאמה אישית ומוצרי היגיינה אינם ניתנים להחזרה, אלא אם הגיעו פגומים.',
                ' [דמי המשלוח בהחזרה על הלקוח / עלינו.]' => ' דמי המשלוח בהחזרה על הלקוח, אלא אם הפריט הגיע פגום או שגוי.',
            ];
        } else {
            $country = $country !== '' ? $country : 'the country where we\'re based';
            $map = [
                ' [list any others, like analytics or marketing tools]' => '',
                ' [If you use analytics or advertising tools, name them here and how to opt out.]' => ' If we use analytics or advertising tools, we ask for your consent first, and you can change your choice at any time.',
                '[orders and offers]' => 'orders and offers',
                ' [for example, 7 years]' => '',
                '[30]' => '30',
                '[currency]' => $currency !== '' ? $currency : 'the store\'s currency',
                '[include / don\'t include]' => $with_tax ? 'include' : 'don\'t include',
                '[when you order / on delivery]' => 'when you order',
                'laws of [country]' => 'laws of ' . $country,
                'courts of [city / country]' => 'courts of ' . $country,
                '[countries or areas]' => $country,
                '[1–3]' => '1–3',
                '[3–7]' => '3–7',
                ' [Free shipping on orders over …]' => '',
                ' [with a tracking number]' => ' with its tracking details',
                '[14]' => '14',
                '[7]' => '7',
                '[Items that can\'t be returned, for example: gift cards, personalised or hygiene products.]' => 'Gift cards, personalised items and hygiene products can\'t be returned unless they arrived damaged.',
                ' [Return shipping is paid by the customer / by us.]' => ' Return shipping is paid by the customer, unless the item arrived damaged or wrong.',
            ];
        }
        $html = strtr( $html, $map );
        // Anything still in brackets: its words stay, the brackets go.
        return preg_replace( '/\[([^\[\]<>]{1,200})\]/u', '$1', $html );
    }

    /** Today, written out in the store's language ("9 October 2026" / "9 באוקטובר 2026"). */
    private static function long_date() {
        if ( Qwoo_Store_Language::get() !== 'he' ) {
            return wp_date( 'F j, Y' );
        }
        $months = [ 'ינואר', 'פברואר', 'מרץ', 'אפריל', 'מאי', 'יוני', 'יולי', 'אוגוסט', 'ספטמבר', 'אוקטובר', 'נובמבר', 'דצמבר' ];
        return wp_date( 'j' ) . ' ב' . $months[ (int) wp_date( 'n' ) - 1 ] . ' ' . wp_date( 'Y' );
    }

    private static function legal_text( $role, $lang = 'en' ) {
        if ( $lang === 'he' ) {
            return self::legal_text_he( $role );
        }
        if ( $role === 'privacy' ) {
            return <<<'HTML'
<p><em>Last updated: {today}</em></p>
<p>This policy explains what personal information {name} ("we") collects when you visit or buy from our store, how we use it and the choices you have.</p>
<h2>Who we are</h2>
<p>{name}, {address}. For any question about your information, write to us at {email}.</p>
<h2>What we collect</h2>
<ul>
<li><strong>Order details:</strong> your name, email, phone number, billing and shipping address, and what you bought.</li>
<li><strong>Account details:</strong> if you create an account, your login details and order history.</li>
<li><strong>Payment:</strong> payments are handled by our payment provider. We don't see or store your full card number.</li>
<li><strong>Messages:</strong> what you send us through our forms, email or chat.</li>
<li><strong>Device and usage:</strong> basic technical information (like your browser and pages viewed) that keeps the store working and secure.</li>
</ul>
<h2>How we use it</h2>
<ul>
<li>To process, ship and support your orders, and to send you order updates.</li>
<li>To answer your messages and provide customer service.</li>
<li>To prevent fraud and keep the store secure.</li>
<li>To send you news and offers, only if you agreed to receive them. You can stop at any time.</li>
<li>To meet our legal obligations, such as keeping invoices.</li>
</ul>
<h2>Who we share it with</h2>
<p>Only with the services that help us run the store, and only what they need: payment providers, shipping companies, email and hosting providers [list any others, like analytics or marketing tools]. We don't sell your personal information.</p>
<h2>Cookies and local storage</h2>
<p>We use cookies and your browser's storage to keep your cart, your login and your cookie choice, and to make the store work offline and faster. [If you use analytics or advertising tools, name them here and how to opt out.]</p>
<h2>Notifications</h2>
<p>If you allow notifications, we use them to tell you about [orders and offers]. You can turn them off in your browser or device settings at any time.</p>
<h2>How long we keep it</h2>
<p>We keep order information for as long as the law requires for accounting and tax [for example, 7 years], and other information only as long as we need it for the reasons above.</p>
<h2>Your rights</h2>
<p>You can ask to see the information we hold about you, correct it, delete it, or receive a copy. You can also object to how we use it or withdraw your consent. Write to us at {email} and we'll answer within [30] days. You may also complain to your local data protection authority.</p>
<h2>Changes</h2>
<p>We may update this policy from time to time. The date at the top shows when it last changed.</p>
HTML;
        }
        if ( $role === 'terms' ) {
            return <<<'HTML'
<p><em>Last updated: {today}</em></p>
<p>These terms apply to every purchase from {name} ("we"). By placing an order you agree to them.</p>
<h2>About us</h2>
<p>{name}, {address}. Contact: {email}.</p>
<h2>Orders</h2>
<p>After you place an order you get an email confirming we received it. We may cancel an order, for example if a product is out of stock or its price was shown by mistake. If you already paid, we refund you in full.</p>
<h2>Prices and payment</h2>
<p>Prices are shown in [currency] and [include / don't include] taxes. Shipping costs are shown at checkout before you pay. Payment is taken [when you order / on delivery].</p>
<h2>Shipping</h2>
<p>Delivery times and costs are explained on our Shipping &amp; returns page. Delivery times are estimates and may change because of things outside our control.</p>
<h2>Returns and refunds</h2>
<p>You can return products as explained on our Shipping &amp; returns page. This doesn't limit any rights you have under consumer law where you live.</p>
<h2>Products</h2>
<p>We do our best to show our products accurately. Colours and sizes may look slightly different on your screen, and handmade items may vary a little from the photos.</p>
<h2>Accounts</h2>
<p>If you create an account, keep your password safe. You're responsible for what's done with your account.</p>
<h2>Liability</h2>
<p>As far as the law allows, we're not responsible for indirect losses, and our responsibility for an order is limited to what you paid for it. Nothing here limits liability that can't be limited by law.</p>
<h2>Law</h2>
<p>These terms are governed by the laws of [country]. Disputes go to the courts of [city / country], unless consumer law where you live says otherwise.</p>
<h2>Changes</h2>
<p>We may update these terms. The version on this page when you order is the one that applies to that order.</p>
HTML;
        }
        return <<<'HTML'
<p><em>Last updated: {today}</em></p>
<h2>Shipping</h2>
<ul>
<li><strong>Where we ship:</strong> [countries or areas].</li>
<li><strong>Preparing your order:</strong> [1–3] business days.</li>
<li><strong>Delivery time:</strong> [3–7] business days after it ships.</li>
<li><strong>Cost:</strong> shown at checkout. [Free shipping on orders over …]</li>
</ul>
<p>When your order ships, we send you an email [with a tracking number].</p>
<h2>Returns</h2>
<p>Not happy with your order? You can return it within [14] days of delivery. Items must be unused, in their original condition and packaging.</p>
<p>[Items that can't be returned, for example: gift cards, personalised or hygiene products.]</p>
<h2>How to return</h2>
<ol>
<li>Write to us at {email} with your order number and what you'd like to return.</li>
<li>We'll reply with the return address and instructions.</li>
<li>Send the item back. [Return shipping is paid by the customer / by us.]</li>
</ol>
<h2>Refunds</h2>
<p>Once we receive and check the return, we refund you to the original payment method within [14] days.</p>
<h2>Damaged or wrong items</h2>
<p>If something arrived damaged or isn't what you ordered, write to us within [7] days with a photo, and {name} will make it right at no cost to you.</p>
HTML;
    }

    /** The same pages in Hebrew: starting points, not legal advice ([סוגריים] = to fill in or check). */
    private static function legal_text_he( $role ) {
        if ( $role === 'privacy' ) {
            return <<<'HTML'
<p><em>עודכן לאחרונה: {today}</em></p>
<p>המדיניות הזו מסבירה איזה מידע אישי {name} ("אנחנו") אוספים כשמבקרים בחנות או קונים בה, איך אנחנו משתמשים בו ואילו בחירות יש לך.</p>
<h2>מי אנחנו</h2>
<p>{name}, {address}. בכל שאלה על המידע שלך אפשר לכתוב לנו: {email}.</p>
<h2>מה אנחנו אוספים</h2>
<ul>
<li><strong>פרטי הזמנה:</strong> שם, אימייל, טלפון, כתובת לחיוב ולמשלוח, ומה קנית.</li>
<li><strong>פרטי חשבון:</strong> אם פתחת חשבון, פרטי ההתחברות והיסטוריית ההזמנות.</li>
<li><strong>תשלום:</strong> התשלומים מטופלים על ידי ספק התשלומים שלנו. אנחנו לא רואים ולא שומרים את מספר הכרטיס המלא.</li>
<li><strong>הודעות:</strong> מה ששולחים לנו בטפסים, באימייל או בצ׳אט.</li>
<li><strong>מכשיר ושימוש:</strong> מידע טכני בסיסי (כמו הדפדפן והעמודים שנצפו) ששומר על החנות עובדת ומאובטחת.</li>
</ul>
<h2>איך אנחנו משתמשים בו</h2>
<ul>
<li>כדי לטפל בהזמנות, לשלוח אותן ולתת עליהן שירות, ולשלוח עדכונים על ההזמנה.</li>
<li>כדי לענות להודעות ולתת שירות לקוחות.</li>
<li>כדי למנוע הונאות ולשמור על אבטחת החנות.</li>
<li>כדי לשלוח חדשות ומבצעים, רק אם הסכמת לקבל אותם. אפשר להפסיק בכל זמן.</li>
<li>כדי לעמוד בחובות שלנו לפי החוק, כמו שמירת חשבוניות.</li>
</ul>
<h2>עם מי אנחנו משתפים אותו</h2>
<p>רק עם השירותים שעוזרים לנו להפעיל את החנות, ורק את מה שהם צריכים: ספקי תשלום, חברות משלוחים, ספקי אימייל ואחסון [יש לפרט שירותים נוספים, כמו כלי ניתוח או שיווק]. אנחנו לא מוכרים מידע אישי.</p>
<h2>עוגיות ואחסון בדפדפן</h2>
<p>אנחנו משתמשים בעוגיות ובאחסון של הדפדפן כדי לשמור את העגלה, את ההתחברות ואת הבחירה לגבי עוגיות, וכדי שהחנות תעבוד מהר יותר וגם בלי חיבור. [אם יש כלי ניתוח או פרסום, יש לכתוב כאן את שמם ואיך אפשר לסרב להם.]</p>
<h2>התראות</h2>
<p>אם אישרת התראות, אנחנו משתמשים בהן כדי לעדכן על [הזמנות ומבצעים]. אפשר לכבות אותן בכל זמן בהגדרות הדפדפן או המכשיר.</p>
<h2>כמה זמן אנחנו שומרים אותו</h2>
<p>את פרטי ההזמנות אנחנו שומרים כל עוד החוק מחייב לצורכי הנהלת חשבונות ומס [למשל 7 שנים], ומידע אחר רק כל עוד הוא נחוץ למטרות שלמעלה.</p>
<h2>הזכויות שלך</h2>
<p>אפשר לבקש לעיין במידע שאנחנו שומרים עליך, לתקן אותו, למחוק אותו או לקבל עותק שלו. אפשר גם להתנגד לשימוש בו או לבטל הסכמה. כתבו לנו לכתובת {email} ונענה תוך [30] ימים. אפשר גם להגיש תלונה לרשות להגנת הפרטיות.</p>
<h2>שינויים</h2>
<p>ייתכן שנעדכן את המדיניות מדי פעם. התאריך למעלה מראה מתי היא שונתה לאחרונה.</p>
HTML;
        }
        if ( $role === 'terms' ) {
            return <<<'HTML'
<p><em>עודכן לאחרונה: {today}</em></p>
<p>התנאים האלה חלים על כל רכישה מ־{name} ("אנחנו"). ביצוע הזמנה הוא הסכמה להם.</p>
<h2>עלינו</h2>
<p>{name}, {address}. ליצירת קשר: {email}.</p>
<h2>הזמנות</h2>
<p>אחרי ביצוע הזמנה נשלח אליך אימייל שמאשר שקיבלנו אותה. אנחנו רשאים לבטל הזמנה, למשל אם מוצר אזל מהמלאי או שהמחיר שלו הוצג בטעות. אם כבר שילמת, נחזיר את מלוא הסכום.</p>
<h2>מחירים ותשלום</h2>
<p>המחירים מוצגים ב[מטבע] ו[כוללים / לא כוללים] מע״מ. דמי המשלוח מוצגים בקופה לפני התשלום. התשלום נגבה [בעת ההזמנה / במסירה].</p>
<h2>משלוחים</h2>
<p>זמני המשלוח ועלויותיו מוסברים בעמוד משלוחים והחזרות. זמני המשלוח הם הערכה ועשויים להשתנות בגלל דברים שאינם בשליטתנו.</p>
<h2>החזרות וזיכויים</h2>
<p>אפשר להחזיר מוצרים כמוסבר בעמוד משלוחים והחזרות. אין בכך כדי לגרוע מזכויות לפי חוק הגנת הצרכן.</p>
<h2>מוצרים</h2>
<p>אנחנו משתדלים להציג את המוצרים במדויק. צבעים וגדלים עשויים להיראות מעט אחרת במסך, ופריטים בעבודת יד עשויים להיות שונים מעט מהתמונות.</p>
<h2>חשבונות</h2>
<p>אם פתחת חשבון, יש לשמור על הסיסמה. האחריות על מה שנעשה בחשבון היא שלך.</p>
<h2>אחריות</h2>
<p>ככל שהחוק מתיר, איננו אחראים לנזקים עקיפים, והאחריות שלנו להזמנה מוגבלת לסכום ששולם עליה. אין באמור כדי להגביל אחריות שהחוק אינו מאפשר להגביל.</p>
<h2>דין וסמכות שיפוט</h2>
<p>על התנאים האלה חלים דיני [מדינת ישראל]. סמכות השיפוט נתונה לבתי המשפט ב[עיר], אלא אם דיני הגנת הצרכן קובעים אחרת.</p>
<h2>שינויים</h2>
<p>ייתכן שנעדכן את התנאים. הנוסח שמופיע בעמוד הזה בזמן ההזמנה הוא שחל עליה.</p>
HTML;
        }
        return <<<'HTML'
<p><em>עודכן לאחרונה: {today}</em></p>
<h2>משלוחים</h2>
<ul>
<li><strong>לאן שולחים:</strong> [מדינות או אזורים].</li>
<li><strong>הכנת ההזמנה:</strong> [1–3] ימי עסקים.</li>
<li><strong>זמן משלוח:</strong> [3–7] ימי עסקים מרגע השליחה.</li>
<li><strong>עלות:</strong> מוצגת בקופה. [משלוח חינם בהזמנות מעל …]</li>
</ul>
<p>כשההזמנה נשלחת, נשלח אליך אימייל [עם מספר מעקב].</p>
<h2>החזרות</h2>
<p>לא מרוצים מההזמנה? אפשר להחזיר אותה תוך [14] ימים ממועד הקבלה. הפריטים צריכים להיות ללא שימוש, במצבם המקורי ובאריזה המקורית.</p>
<p>[פריטים שאי אפשר להחזיר, למשל: כרטיסי מתנה, מוצרים בהתאמה אישית או מוצרי היגיינה.]</p>
<h2>איך מחזירים</h2>
<ol>
<li>כתבו לנו לכתובת {email} עם מספר ההזמנה ומה רוצים להחזיר.</li>
<li>נענה עם כתובת ההחזרה וההוראות.</li>
<li>שלחו את הפריט בחזרה. [דמי המשלוח בהחזרה על הלקוח / עלינו.]</li>
</ol>
<h2>זיכויים</h2>
<p>אחרי שנקבל ונבדוק את ההחזרה, נזכה אותך באמצעי התשלום המקורי תוך [14] ימים.</p>
<h2>פריטים פגומים או שגויים</h2>
<p>אם משהו הגיע פגום או שאינו מה שהזמנת, כתבו לנו תוך [7] ימים עם תמונה, ו־{name} יתקנו את זה בלי עלות.</p>
HTML;
    }
}

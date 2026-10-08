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
        if ( $legal ) {
            foreach ( [ 'privacy', 'terms', 'returns' ] as $role ) {
                if ( ! in_array( $role, $roles, true ) ) {
                    $add[] = self::store_page_template( $role, (string) $name ) + [ 'id' => self::new_id( 'pg' ), 'parent' => '', 'status' => 'draft', 'seo' => [] ];
                }
            }
        }
        if ( ! $add || count( $pages ) + count( $add ) > self::$max_pages ) {
            return false;
        }

        $options['custom_pages'] = self::instance()->sanitize_custom_pages( array_merge( $add, $pages ), $pages );
        update_option( 'shop_builder_options', $options );
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
            $sections[] = [ 'id' => self::new_id( 'sec' ), 'label' => 'Products', 'enabled' => true, 'style' => [], 'blocks' => [
                self::store_page_block( 'heading', [ 'title' => 'Our products', 'tag' => 'h2', 'alignment' => 'center' ] ),
                self::store_page_block( 'product_grid', [ 'query_type' => 'newest', 'limit' => 8, 'show_view_all' => true, 'view_all_url' => '/products', 'view_all_text' => 'View all products' ] ),
            ] ];
        }
        array_unshift( $sections, self::hero_section( [
            'title'    => trim( wp_strip_all_tags( $hero['title'] ) ) !== '' ? $hero['title'] : $facts['name_plain'],
            'text'     => trim( $hero['text'] ) !== '' ? $hero['text'] : $facts['description'],
            'btn_text' => $hero['btn_text'] !== '' ? $hero['btn_text'] : 'Our products',
            'btn_url'  => $hero['btn_url'] !== '' ? $hero['btn_url'] : '/products',
            'image_id' => (int) ( $old['hero_image_id'] ?? 0 ),
        ] ) );
        return [
            'id'         => self::new_id( 'pg' ),
            'title'      => 'Home',
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
        $blocks = [ self::store_page_block( 'heading', [ 'title' => $h['title'], 'tag' => 'h1', 'alignment' => 'left' ] ) ];
        if ( trim( (string) $h['text'] ) !== '' ) {
            $blocks[] = self::store_page_block( 'text_block', [ 'text' => '<p>' . esc_html( $h['text'] ) . '</p>', 'alignment' => 'left' ] );
        }
        // "secondary": the old homepage's button colour (templates keep "primary" light, for backgrounds).
        $blocks[] = self::store_page_block( 'button', [ 'text' => $h['btn_text'], 'url' => $h['btn_url'], 'style' => 'secondary', 'size' => 'lg', 'alignment' => 'left' ] );
        return [ 'id' => self::new_id( 'sec' ), 'label' => 'Opening', 'enabled' => true, 'style' => $style, 'blocks' => $blocks ];
    }

    private static function store_page_block( $type, array $data ) {
        return [ 'id' => self::new_id( 'blk' ), 'type' => $type, 'label' => '', 'enabled' => true, 'style' => [], 'data' => $data ];
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
            'name'        => $name !== '' ? esc_html( $name ) : '[your store name]',
            'email'       => $email !== '' ? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' : '[your email address]',
            // A country alone isn't an address.
            'address'     => $address !== '' ? esc_html( $address ) : '[your business address]',
            'today'       => wp_date( 'F j, Y' ),
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
        $html = strtr( self::legal_text( $role ), [ '{name}' => $f['name'], '{email}' => $f['email'], '{address}' => $f['address'], '{today}' => $f['today'] ] );
        return [
            'title'      => $titles[ $role ][0],
            'slug'       => $titles[ $role ][1],
            'role'       => $role,
            'show_title' => true,
            'sections'   => [ self::text_section( preg_replace( '/>\s+</', '><', trim( $html ) ) ) ],
        ];
    }

    private static function legal_text( $role ) {
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
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The storefront's menus, edited in the dashboard (Design → Menus).
 *
 * Stored in shop_builder_options['menus']:
 *   An array of named menus:
 *   [
 *     { id: 'menu_…', name: 'Main menu', items: [ item… ] },
 *     { id: 'menu_…', name: 'Footer menu', items: [ item… ] }
 *   ]
 * an item:
 *   { id: mi_…, type: page | builtin | category | product | blog_category | custom,
 *     ref:   the page id, builtin key, category id or product id,
 *     label: '' = the page / category / product's own name,
 *     url:   custom links only, new_tab, children: [ item… ] }
 * Items nest without a fixed limit (MAX_MENU_DEPTH is a safety cap).
 *
 * A store that never saved menus gets the defaults: "Main menu" and "Footer menu".
 *
 * Publishing resolves every item to { label, url, new_tab, external, children }
 * and outputs the menus map and list for the storefront.
 */
trait SB_Menus {

    private static $max_menu_depth = 8;
    private static $max_menu_items = 200;
    private static $max_menus      = 30;

    /** Built-in storefront pages a menu can link to: key => [ label, path ]. */
    private static $menu_builtins = [
        'home'     => [ 'Home', '/' ],
        'products' => [ 'Products', '/products' ],
        'cart'     => [ 'Cart', '/cart' ],
        'checkout' => [ 'Checkout', '/checkout' ],
        'account'  => [ 'My account', '/my-account' ],
        'blog'     => [ 'Blog', '/blog' ],
    ];

    /** The builtins a new store's header menu starts with (not the blog: it may have no posts yet). */
    private static $menu_default_builtins = [ 'home', 'products', 'cart', 'checkout', 'account' ];

    /** The menus as stored, or the defaults for a store that never saved any. */
    private static function menus_of( array $options ) {
        $menus = $options['menus'] ?? null;
        if ( is_array( $menus ) ) {
            // Already a list of named menus
            if ( isset( $menus[0] ) || empty( $menus ) ) {
                $count = 0;
                $out   = [];
                foreach ( array_slice( $menus, 0, self::$max_menus ) as $m ) {
                    if ( ! is_array( $m ) ) continue;
                    $out[] = [
                        'id'    => is_string( $m['id'] ?? null ) && preg_match( '/^menu_[A-Za-z0-9_]{3,30}$/', $m['id'] ) ? $m['id'] : self::new_id( 'menu' ),
                        'name'  => mb_substr( sanitize_text_field( (string) ( $m['name'] ?? '' ) ), 0, 60 ) ?: Qwoo_I18n::t( 'Menu' ),
                        'items' => self::sanitize_menu_items( $m['items'] ?? [], 1, $count ),
                    ];
                }
                if ( ! empty( $out ) ) return $out;
            }
            // Legacy structure: { header: { items: [...] }, footer: { columns: [...] } }
            if ( isset( $menus['header'] ) || isset( $menus['footer'] ) ) {
                $count = 0;
                $out   = [];
                $header_items = self::sanitize_menu_items( $menus['header']['items'] ?? [], 1, $count );
                $out[] = [
                    'id'    => 'menu_header',
                    'name'  => Qwoo_I18n::t( 'Main menu' ),
                    'items' => $header_items,
                ];
                $footer_items = [];
                foreach ( (array) ( $menus['footer']['columns'] ?? [] ) as $col ) {
                    foreach ( (array) ( $col['items'] ?? [] ) as $it ) {
                        $footer_items[] = $it;
                    }
                }
                $out[] = [
                    'id'    => 'menu_footer',
                    'name'  => Qwoo_I18n::t( 'Footer menu' ),
                    'items' => self::sanitize_menu_items( $footer_items, 1, $count ),
                ];
                return $out;
            }
        }

        $item = static fn( $type, $ref ) => [ 'id' => self::new_id( 'mi' ), 'type' => $type, 'ref' => (string) $ref, 'label' => '', 'url' => '', 'new_tab' => false, 'children' => [] ];

        $main_items = [];
        foreach ( self::$menu_default_builtins as $key ) {
            $main_items[] = $item( 'builtin', $key );
        }
        $footer_items = [ $item( 'builtin', 'products' ) ];

        // Pages that had the old "Show in the menu / footer" ticks.
        foreach ( self::custom_pages_of( $options ) as $page ) {
            if ( ! empty( $page['in_menu'] ) ) $main_items[] = $item( 'page', $page['id'] );
            if ( ! empty( $page['in_footer'] ) ) $footer_items[] = $item( 'page', $page['id'] );
        }

        return [
            [
                'id'    => self::new_id( 'menu' ),
                'name'  => Qwoo_I18n::t( 'Main menu' ),
                'items' => $main_items,
            ],
            [
                'id'    => self::new_id( 'menu' ),
                'name'  => Qwoo_I18n::t( 'Footer menu' ),
                'items' => $footer_items,
            ],
        ];
    }

    /** Sanitizes posted menus (sanitize_options() calls this). */
    private function sanitize_menus( $raw ) {
        $raw = is_array( $raw ) ? $raw : [];
        if ( isset( $raw['header'] ) || isset( $raw['footer'] ) ) {
            return self::menus_of( [ 'menus' => $raw ] );
        }
        $raw   = isset( $raw['list'] ) && is_array( $raw['list'] ) ? $raw['list'] : $raw;
        $count = 0;
        $out   = [];
        foreach ( array_slice( array_values( $raw ), 0, self::$max_menus ) as $menu ) {
            if ( ! is_array( $menu ) ) continue;
            $out[] = [
                'id'    => is_string( $menu['id'] ?? null ) && preg_match( '/^menu_[A-Za-z0-9_]{3,30}$/', $menu['id'] ) ? $menu['id'] : self::new_id( 'menu' ),
                'name'  => mb_substr( sanitize_text_field( (string) ( $menu['name'] ?? '' ) ), 0, 60 ) ?: Qwoo_I18n::t( 'Menu' ),
                'items' => self::sanitize_menu_items( $menu['items'] ?? [], 1, $count ),
            ];
        }
        return $out;
    }

    private static function sanitize_menu_items( $items, $depth, &$count ) {
        $out = [];
        if ( $depth > self::$max_menu_depth ) return $out;
        foreach ( array_values( is_array( $items ) ? $items : [] ) as $item ) {
            if ( ! is_array( $item ) || ++$count > self::$max_menu_items ) continue;
            $type = (string) ( $item['type'] ?? '' );
            if ( ! in_array( $type, [ 'page', 'builtin', 'category', 'product', 'blog_category', 'custom' ], true ) ) continue;
            $ref = (string) ( $item['ref'] ?? '' );
            switch ( $type ) {
                case 'page':     $ref = preg_match( '/^pg_[A-Za-z0-9]{6,20}$/', $ref ) ? $ref : ''; break;
                case 'builtin':  $ref = isset( self::$menu_builtins[ $ref ] ) ? $ref : ''; break;
                case 'category':
                case 'blog_category':
                case 'product':  $ref = (string) absint( $ref ); break;
                default:         $ref = '';
            }
            $url = $type === 'custom' ? self::sanitize_menu_url( (string) ( $item['url'] ?? '' ) ) : '';
            if ( ( $type !== 'custom' && ( $ref === '' || $ref === '0' ) ) || ( $type === 'custom' && $url === '' ) ) continue;
            $out[] = [
                'id'       => is_string( $item['id'] ?? null ) && preg_match( '/^mi_[A-Za-z0-9]{6,20}$/', $item['id'] ) ? $item['id'] : self::new_id( 'mi' ),
                'type'     => $type,
                'ref'      => $ref,
                'label'    => mb_substr( sanitize_text_field( (string) ( $item['label'] ?? '' ) ), 0, 80 ),
                'url'      => $url,
                'new_tab'  => ! empty( $item['new_tab'] ),
                'children' => self::sanitize_menu_items( $item['children'] ?? [], $depth + 1, $count ),
            ];
        }
        return $out;
    }

    /** A custom link: an address on the store ("/sale", "/#faq") or an http(s) one. '' when it's neither. */
    private static function sanitize_menu_url( $url ) {
        $url = trim( $url );
        if ( $url === '' ) return '';
        // "#popup:{id}" opens a popup.
        if ( preg_match( '/^#popup:pp_[A-Za-z0-9]{3,20}$/', $url ) ) return $url;
        if ( $url[0] === '/' && ( $url[1] ?? '' ) !== '/' ) {
            return mb_substr( preg_replace( '/[\s<>"\'`]/u', '', $url ), 0, 500 );
        }
        $clean = esc_url_raw( $url, [ 'http', 'https', 'mailto', 'tel' ] );
        return mb_substr( $clean, 0, 500 );
    }

    /**
     * The menus as the dashboard edits them, with what each item points at
     * (name, href) so the editor and the live preview can show it.
     */
    private static function platform_menus_data( array $options ) {
        $menus   = self::menus_of( $options );
        $pages   = self::custom_pages_of( $options );
        $paths   = self::page_paths( $pages );
        $titles  = array_column( $pages, 'title', 'id' );
        $explain = function ( array $items ) use ( &$explain, $paths, $titles ) {
            foreach ( $items as $i => $item ) {
                [ $name, $href ] = self::menu_target( $item, $paths, $titles, false );
                $items[ $i ]['name']     = $name;
                $items[ $i ]['href']     = $href;
                $items[ $i ]['children'] = $explain( (array) ( $item['children'] ?? [] ) );
            }
            return $items;
        };
        foreach ( $menus as $k => $m ) {
            $menus[ $k ]['items'] = $explain( (array) ( $m['items'] ?? [] ) );
        }
        return $menus;
    }

    /**
     * [ name, href ] of what an item links to; [ '', '' ] when it's gone (or,
     * with $live, isn't on the storefront: a draft page, a hidden product).
     */
    private static function menu_target( array $item, array $paths, array $titles, $live ) {
        $ref = (string) ( $item['ref'] ?? '' );
        switch ( $item['type'] ?? '' ) {
            case 'builtin':
                // The label in the store's language ("Cart" → "עגלה").
                return isset( self::$menu_builtins[ $ref ] ) ? [ Qwoo_I18n::t( self::$menu_builtins[ $ref ][0] ), self::$menu_builtins[ $ref ][1] ] : [ '', '' ];
            case 'page':
                if ( ! isset( $paths[ $ref ] ) ) return [ '', '' ];
                return [ (string) ( $titles[ $ref ] ?? '' ), '/' . $paths[ $ref ] ];
            case 'category':
                $term = get_term( (int) $ref, 'product_cat' );
                return $term && ! is_wp_error( $term ) ? [ html_entity_decode( $term->name, ENT_QUOTES ), '/product-category/' . $term->slug ] : [ '', '' ];
            case 'blog_category':
                $term = get_term( (int) $ref, 'category' );
                return $term && ! is_wp_error( $term ) && (int) $term->term_id !== (int) get_option( 'default_category' )
                    ? [ html_entity_decode( $term->name, ENT_QUOTES ), '/blog/category/' . $term->slug ] : [ '', '' ];
            case 'product':
                $product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $ref ) : null;
                if ( ! $product || ( $live && ( $product->get_status() !== 'publish' || $product->get_catalog_visibility() === 'hidden' ) ) ) return [ '', '' ];
                return [ html_entity_decode( $product->get_name(), ENT_QUOTES ), '/product/' . get_post_field( 'post_name', $product->get_id() ) ];
            case 'custom':
                return [ (string) ( $item['url'] ?? '' ), (string) ( $item['url'] ?? '' ) ];
        }
        return [ '', '' ];
    }

    /**
     * What the storefront gets:
     * - 'menus': map of [ menu_id => resolved_items ]
     * - 'menus_list': list of [ { id, name, items } ]
     * - 'header': resolved items of the primary menu
     * - 'footer': columns
     */
    private static function published_menus( array $options ) {
        $menus  = self::menus_of( $options );
        $live   = self::published_page_map( $options );
        $paths  = array_map( static fn( $p ) => $p['path'], $live );
        $titles = array_map( static fn( $p ) => $p['title'], $live );
        $resolve = function ( array $items ) use ( &$resolve, $paths, $titles ) {
            $out = [];
            foreach ( $items as $item ) {
                [ $name, $href ] = self::menu_target( $item, $paths, $titles, true );
                if ( $href === '' ) continue;
                // Anything that isn't an address on the store ("/…") opens as a normal link.
                $external = $href[0] !== '/' || strpos( $href, '//' ) === 0;
                $out[]    = [
                    'id'       => $item['id'] ?? '',
                    'label'    => ( $item['label'] ?? '' ) !== '' ? $item['label'] : $name,
                    'url'      => $href,
                    'new_tab'  => ! empty( $item['new_tab'] ),
                    'external' => $external,
                    'children' => $resolve( (array) ( $item['children'] ?? [] ) ),
                ];
            }
            return $out;
        };

        $map  = [];
        $list = [];
        foreach ( $menus as $m ) {
            $m_id     = (string) ( $m['id'] ?? '' );
            $resolved = $resolve( (array) ( $m['items'] ?? [] ) );
            if ( $m_id !== '' ) {
                $map[ $m_id ] = $resolved;
            }
            $list[] = [
                'id'    => $m_id,
                'name'  => (string) ( $m['name'] ?? '' ),
                'items' => $resolved,
            ];
        }

        $header_items = $list[0]['items'] ?? [];
        foreach ( $list as $entry ) {
            if ( $entry['id'] === 'menu_header' || stripos( $entry['name'], 'header' ) !== false || stripos( $entry['name'], 'main' ) !== false ) {
                $header_items = $entry['items'];
                break;
            }
        }

        return [
            'header'     => $header_items,
            'footer'     => [],
            'menus'      => $map,
            'menus_list' => $list,
        ];
    }
}

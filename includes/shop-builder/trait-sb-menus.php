<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The storefront's menus, edited in the dashboard (Design → Menus).
 *
 * Stored in shop_builder_options['menus']:
 *   header: { items: [ item… ] }
 *   footer: { columns: [ { id, title, items: [ item… ] } ] }
 * an item:
 *   { id: mi_…, type: page | builtin | category | product | custom,
 *     ref:   the page id, builtin key, category id or product id,
 *     label: '' = the page / category / product's own name,
 *     url:   custom links only, new_tab, children: [ item… ] }
 * Items nest without a fixed limit (MAX_MENU_DEPTH is a safety cap).
 *
 * A store that never saved menus gets the defaults: Home, Products, Cart,
 * Checkout and My account in the header, and pages that had the old "Show in
 * the menu / footer" ticks are added (that's where those settings went).
 *
 * Publishing resolves every item to { label, url, new_tab, external,
 * children } — header.json "menu" and footer.json "columns" — and leaves
 * out links to things the storefront doesn't have (a draft page, a deleted
 * product…).
 */
trait SB_Menus {

    private static $max_menu_depth = 8;
    private static $max_menu_items = 200;

    /** Built-in storefront pages a menu can link to: key => [ label, path ]. */
    private static $menu_builtins = [
        'home'     => [ 'Home', '/' ],
        'products' => [ 'Products', '/products' ],
        'cart'     => [ 'Cart', '/cart' ],
        'checkout' => [ 'Checkout', '/checkout' ],
        'account'  => [ 'My account', '/my-account' ],
    ];

    /** The menus as stored, or the defaults for a store that never saved any. */
    private static function menus_of( array $options ) {
        $menus = $options['menus'] ?? null;
        if ( is_array( $menus ) && isset( $menus['header'], $menus['footer'] ) ) {
            return $menus;
        }
        $item = static fn( $type, $ref ) => [ 'id' => self::new_id( 'mi' ), 'type' => $type, 'ref' => (string) $ref, 'label' => '', 'url' => '', 'new_tab' => false, 'children' => [] ];

        $header = [];
        foreach ( array_keys( self::$menu_builtins ) as $key ) {
            $header[] = $item( 'builtin', $key );
        }
        $columns = [ [ 'id' => self::new_id( 'mc' ), 'title' => 'Shop', 'items' => [ $item( 'builtin', 'products' ) ] ] ];
        // Pages that had the old "Show in the menu / footer" ticks.
        $footer_pages = [];
        foreach ( self::custom_pages_of( $options ) as $page ) {
            if ( ! empty( $page['in_menu'] ) ) $header[] = $item( 'page', $page['id'] );
            if ( ! empty( $page['in_footer'] ) ) $footer_pages[] = $item( 'page', $page['id'] );
        }
        if ( $footer_pages ) {
            $columns[] = [ 'id' => self::new_id( 'mc' ), 'title' => '', 'items' => $footer_pages ];
        }
        return [ 'header' => [ 'items' => $header ], 'footer' => [ 'columns' => $columns ] ];
    }

    /** Sanitizes posted menus (sanitize_options() calls this). */
    private function sanitize_menus( $raw ) {
        $raw   = is_array( $raw ) ? $raw : [];
        $count = 0;
        $out   = [
            'header' => [ 'items' => self::sanitize_menu_items( $raw['header']['items'] ?? [], 1, $count ) ],
            'footer' => [ 'columns' => [] ],
        ];
        foreach ( array_slice( array_values( (array) ( $raw['footer']['columns'] ?? [] ) ), 0, 8 ) as $column ) {
            if ( ! is_array( $column ) ) continue;
            $out['footer']['columns'][] = [
                'id'    => is_string( $column['id'] ?? null ) && preg_match( '/^mc_[A-Za-z0-9]{6,20}$/', $column['id'] ) ? $column['id'] : self::new_id( 'mc' ),
                'title' => mb_substr( sanitize_text_field( (string) ( $column['title'] ?? '' ) ), 0, 60 ),
                'items' => self::sanitize_menu_items( $column['items'] ?? [], 1, $count ),
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
            if ( ! in_array( $type, [ 'page', 'builtin', 'category', 'product', 'custom' ], true ) ) continue;
            $ref = (string) ( $item['ref'] ?? '' );
            switch ( $type ) {
                case 'page':     $ref = preg_match( '/^pg_[A-Za-z0-9]{6,20}$/', $ref ) ? $ref : ''; break;
                case 'builtin':  $ref = isset( self::$menu_builtins[ $ref ] ) ? $ref : ''; break;
                case 'category':
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
        $menus['header']['items'] = $explain( (array) ( $menus['header']['items'] ?? [] ) );
        foreach ( $menus['footer']['columns'] as $c => $column ) {
            $menus['footer']['columns'][ $c ]['items'] = $explain( (array) ( $column['items'] ?? [] ) );
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
                return isset( self::$menu_builtins[ $ref ] ) ? self::$menu_builtins[ $ref ] : [ '', '' ];
            case 'page':
                if ( empty( $paths[ $ref ] ) ) return [ '', '' ];
                return [ (string) ( $titles[ $ref ] ?? '' ), '/' . $paths[ $ref ] ];
            case 'category':
                $term = get_term( (int) $ref, 'product_cat' );
                return $term && ! is_wp_error( $term ) ? [ html_entity_decode( $term->name, ENT_QUOTES ), '/product-category/' . $term->slug ] : [ '', '' ];
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
     * What the storefront gets: header.json "menu" and footer.json "columns",
     * every item { label, url, new_tab, external, children }.
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
                    'label'    => ( $item['label'] ?? '' ) !== '' ? $item['label'] : $name,
                    'url'      => $href,
                    'new_tab'  => ! empty( $item['new_tab'] ),
                    'external' => $external,
                    'children' => $resolve( (array) ( $item['children'] ?? [] ) ),
                ];
            }
            return $out;
        };
        $columns = [];
        foreach ( (array) ( $menus['footer']['columns'] ?? [] ) as $column ) {
            $columns[] = [ 'title' => (string) ( $column['title'] ?? '' ), 'links' => $resolve( (array) ( $column['items'] ?? [] ) ) ];
        }
        return [ 'header' => $resolve( (array) ( $menus['header']['items'] ?? [] ) ), 'footer' => $columns ];
    }
}

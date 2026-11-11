<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The shop pages (SHOP_TEMPLATE_PAGES: All products, category pages, the
 * product page, cart, checkout and My account) as block-built templates, like the blog.
 * My account never had slots: a store without one gets the default template.
 *
 * They used to be fixed pages with slots ("After Product Images"…): each
 * section carried a `location`. Such a list (no block of the page's own and
 * a slot on a section, or no sections at all) is "old" and becomes the
 * default template with every section placed where its slot was:
 * slots between parts of the page stay top-level sections, slots inside a
 * column become a nested section there. Old lists are converted wherever
 * they come in (sanitize_options: saves, imports, store copies) and in the
 * stored options (upgrade_shop_templates: main language and every extra
 * language's copy). Published configs say `template: 1`; the storefront
 * converts an older published file the same way (src/utils/shop-templates.js).
 *
 * The checkout's first two big blocks (Customer Details, Order Summary) are
 * split into one block per part (split_checkout_blocks), in an inner section
 * where the old block was, so the page looks the same.
 */
trait SB_Shop_Templates {

    /** The block types that belong to a shop page ("This page"). */
    private static function shop_page_block_types( $page ) {
        static $cache = [];
        if ( ! isset( $cache[ $page ] ) ) {
            $cache[ $page ] = [];
            foreach ( self::block_schema() as $type => $schema ) {
                if ( ( $schema['category'] ?? '' ) === 'dynamic' && in_array( $page, (array) ( $schema['pages'] ?? [] ), true ) ) {
                    $cache[ $page ][ $type ] = true;
                }
            }
        }
        return $cache[ $page ];
    }

    private static function shop_list_has_block( array $nodes, array $types ) {
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) continue;
            if ( isset( $types[ (string) ( $node['type'] ?? '' ) ] ) ) return true;
            if ( is_array( $node['blocks'] ?? null ) && self::shop_list_has_block( $node['blocks'], $types ) ) return true;
        }
        return false;
    }

    /** An old slot list: nothing of the page's own, and a slot on a section (or no sections at all). */
    public static function is_old_shop_list( $sections, $page ) {
        if ( ! is_array( $sections ) ) return false;
        if ( self::shop_list_has_block( $sections, self::shop_page_block_types( $page ) ) ) return false;
        if ( ! $sections ) return true;
        foreach ( $sections as $s ) {
            if ( is_array( $s ) && (string) ( $s['location'] ?? '' ) !== '' ) return true;
        }
        return false;
    }

    /**
     * A shop page's default template (raw, not sanitized), with an old page's
     * sections in their slots ($old: the old list; unknown slots go to the first).
     */
    public static function shop_template_sections( $page, array $old = [] ) {
        $locations = self::PAGE_SECTION_LOCATIONS[ $page ] ?? [];
        $slots     = [];
        foreach ( $old as $s ) {
            if ( ! is_array( $s ) ) continue;
            $loc = (string) ( $s['location'] ?? '' );
            if ( ! isset( $locations[ $loc ] ) ) $loc = (string) array_key_first( $locations );
            unset( $s['location'] );
            $slots[ $loc ][] = $s;
        }
        $top    = static fn( $loc ) => array_values( $slots[ $loc ] ?? [] );
        $inside = static fn( $loc ) => array_map( [ __CLASS__, 'shop_nested_section' ], array_values( $slots[ $loc ] ?? [] ) );
        $rwd    = static fn( $d, $t = null, $m = null ) => [ 'desktop' => $d, 'tablet' => $t ?? $d, 'mobile' => $m ?? $t ?? $d ];
        $pad    = static fn( $t, $r, $b, $l ) => [ 'padding_preset' => 'custom', 'padding' => $rwd( [ 'top' => $t, 'right' => $r, 'bottom' => $b, 'left' => $l ] ) ];
        $block  = static fn( $type ) => [ 'id' => self::new_id( 'blk' ), 'type' => $type, 'label' => '', 'enabled' => true, 'style' => [], 'data' => [] ];
        $sec    = static fn( array $blocks, array $style = [] ) => [ 'id' => self::new_id( 'sec' ), 'label' => '', 'enabled' => true, 'style' => $style + [ 'width_mode' => 'contained' ], 'blocks' => $blocks ];
        $col    = static fn( array $blocks, array $width, array $style = [] ) => [
            'id' => self::new_id( 'blk' ), 'type' => 'section', 'label' => '', 'enabled' => true,
            'style' => $style + [ 'width_mode' => 'custom', 'width' => $width, 'padding_preset' => 'none' ], 'blocks' => $blocks,
        ];
        $types  = static fn( array $list ) => array_map( $block, $list );
        // A block with its type's `start` values (what a newly added one begins with).
        $started = static function ( $type ) use ( $block ) {
            $start = (array) ( self::get_type_schema( $type )['start'] ?? [] );
            return array_merge( $block( $type ), [ 'style' => (array) ( $start['style'] ?? [] ), 'data' => (array) ( $start['data'] ?? [] ) ] );
        };
        // The blocks have no spacing of their own: the old pages' spacing, as block settings.
        $sides  = static fn( $t, $r, $b, $l ) => [ 'top' => $t, 'right' => $r, 'bottom' => $b, 'left' => $l ];
        $spaced = static fn( array $b ) => array_merge( $b, [ 'style' => [ 'margin' => $rwd( $sides( '20px', '', '20px', '' ) ) ] ] );
        // Sort: sticky on phones only, below the header, on the page's colour (the old Sort bar did that by itself).
        $sticky_phones = static fn( array $b ) => array_merge( $b, [ 'style' => [
            'position'    => [ 'desktop' => 'static', 'tablet' => 'static', 'mobile' => 'sticky' ],
            'box_padding' => [ 'desktop' => $sides( '', '', '', '' ), 'tablet' => $sides( '', '', '', '' ), 'mobile' => $sides( '10px', '10px', '10px', '10px' ) ],
            'bg_type'     => 'color',
            'bg_color'    => $rwd( 'global:primary' ),
        ] ] );

        switch ( $page ) {
            case 'product':
                $out = array_merge(
                    $top( 'before_breadcrumbs' ),
                    [ $sec( [ $block( 'product_breadcrumbs' ) ], $pad( '16', '16', '0', '16' ) ) ],
                    $top( 'after_breadcrumbs' ),
                    [ $sec( [
                        $col( array_merge( $inside( 'before_product_images' ), [ $block( 'product_gallery' ) ], $inside( 'after_product_images' ) ),
                            $rwd( '50%', '100%' ), [ 'gap' => $rwd( '16px' ) ] ),
                        $col( array_merge(
                            $types( [ 'product_title', 'product_rating', 'product_categories', 'product_price', 'product_description', 'product_stock' ] ),
                            $inside( 'before_add_to_cart_form' ),
                            [ $block( 'product_add_to_cart' ) ],
                            $inside( 'after_add_to_cart_form' ),
                            [ $block( 'product_wishlist' ) ],
                            $inside( 'after_product_summary' )
                        ), $rwd( '50%', '100%' ), [ 'gap' => $rwd( '16px' ) ] ),
                    ], $pad( '16', '16', '16', '16' ) + [ 'direction' => $rwd( 'row', 'column' ), 'gap' => $rwd( '24px' ) ] ) ],
                    [ $sec( [ $block( 'product_reviews' ) ], $pad( '0', '16', '0', '16' ) ) ],
                    $top( 'before_related_products' ),
                    [ $sec( [
                        array_merge( $block( 'heading' ), [ 'data' => [ 'title' => __( 'Related products', 'woocommerce' ), 'tag' => 'h2', 'alignment' => $rwd( 'center' ), 'font_size' => $rwd( '1.5rem' ) ] ] ),
                        array_merge( $block( 'carousel' ), [ 'data' => [ 'source' => 'products', 'query_type' => 'related', 'limit' => 8 ] ] ),
                    ], $pad( '0', '16', '16', '16' ) + [ 'gap' => $rwd( '16px' ) ] ) ],
                    $top( 'after_related_products' )
                );
                break;

            case 'shop':
            case 'category':
                $out = array_merge(
                    $top( 'before_breadcrumbs' ),
                    [ $sec( [ $spaced( $block( 'archive_breadcrumbs' ) ) ], $pad( '0', '16', '0', '16' ) ) ],
                    $top( 'after_breadcrumbs' ),
                    [ $sec( [ $spaced( $block( 'archive_title' ) ) ], $pad( '0', '16', '0', '16' ) ) ],
                    // Filters in a card that stays in view; a row with the Filters button (phones), the
                    // product count and Sort as a pill button; then the grid (the blocks' `start` values).
                    [ $sec( array_merge(
                        $inside( 'before_filters' ),
                        [ $started( 'archive_filters' ) ],
                        [ $col( array_merge(
                            [ [
                                'id' => self::new_id( 'blk' ), 'type' => 'section', 'label' => '', 'enabled' => true,
                                'style' => [ 'width_mode' => 'full', 'padding_preset' => 'none', 'direction' => $rwd( 'row' ), 'justify' => $rwd( 'space-between' ), 'align_items' => $rwd( 'center' ), 'gap' => $rwd( '12px' ) ],
                                'blocks' => [ $started( 'archive_filters_button' ), $started( 'archive_count' ), $started( 'archive_sort' ) ],
                            ] ],
                            $inside( 'before_products_grid' ),
                            [ $block( 'archive_products' ) ],
                            $inside( 'after_products_grid' )
                        ), $rwd( '100%' ), [ 'gap' => $rwd( '22px', '22px', '16px' ) ] ) ]
                    ), $pad( '0', '16', '20', '16' ) + [ 'direction' => $rwd( 'row', 'row', 'column' ), 'align_items' => $rwd( 'flex-start', 'flex-start', 'stretch' ), 'gap' => $rwd( '36px', '36px', '0px' ) ] ) ],
                    $top( 'after_pagination' )
                );
                break;

            case 'cart':
                $out = array_merge(
                    [ $sec( [ $block( 'cart_title' ) ], $pad( '16', '16', '0', '16' ) ) ],
                    $top( 'before_cart' ),
                    $top( 'before_cart_items' ),
                    [ $sec( [ $block( 'cart_items' ) ], $pad( '0', '16', '0', '16' ) ) ],
                    $top( 'after_cart_items' ),
                    $top( 'before_cart_totals' ),
                    [ $sec( [ $block( 'checkout_coupon' ), $block( 'cart_totals' ) ], $pad( '16', '16', '16', '16' ) + [ 'gap' => $rwd( '16px' ) ] ) ],
                    $top( 'after_cart_totals' ),
                    $top( 'after_cart' )
                );
                break;

            case 'checkout':
                $out = array_merge(
                    [ $sec( [ $block( 'checkout_title' ) ], $pad( '16', '16', '0', '16' ) ) ],
                    $top( 'before_checkout' ),
                    [ $sec( [
                        $block( 'checkout_login' ),
                        [
                            'id' => self::new_id( 'blk' ), 'type' => 'section', 'label' => '', 'enabled' => true,
                            'style' => [ 'width_mode' => 'full', 'padding_preset' => 'none', 'direction' => $rwd( 'row', 'row', 'column' ), 'justify' => $rwd( 'space-between' ), 'align_items' => $rwd( 'flex-start' ), 'gap' => $rwd( '16px' ) ],
                            'blocks' => [
                                $col( array_merge( $inside( 'before_customer_details' ),
                                    $types( [ 'checkout_contact', 'checkout_delivery_mode', 'checkout_shipping_address', 'checkout_billing_address', 'checkout_coupon', 'checkout_note' ] ),
                                    $inside( 'after_customer_details' ) ), $rwd( '57%', '57%', '100%' ) ),
                                $col( array_merge( $inside( 'before_order_review' ),
                                    $types( [ 'checkout_items', 'checkout_shipping', 'checkout_payment', 'checkout_totals' ] ),
                                    $inside( 'before_place_order' ),
                                    [ array_merge( $block( 'checkout_place_order' ), [ 'data' => [ 'show_total' => false ] ] ) ] ), $rwd( '41%', '41%', '100%' ) ),
                            ],
                        ],
                    ], $pad( '16', '16', '16', '16' ) + [ 'gap' => $rwd( '8px' ) ] ) ],
                    $top( 'after_checkout' )
                );
                break;

            case 'account':
                // Visitors: sign in and sign up side by side; customers: the menu beside the parts.
                $out = [
                    $sec( [ $block( 'account_title' ) ], $pad( '16', '16', '0', '16' ) ),
                    $sec( [
                        $col( [ $block( 'login_form' ) ], $rwd( '50%', '50%', '100%' ) ),
                        $col( [ $block( 'signup_form' ) ], $rwd( '50%', '50%', '100%' ) ),
                    ], $pad( '16', '16', '24', '16' ) + [ 'audience' => 'guests', 'direction' => $rwd( 'row', 'row', 'column' ), 'align_items' => $rwd( 'flex-start' ), 'gap' => $rwd( '24px' ) ] ),
                    $sec( [
                        $col( [ $block( 'account_menu' ) ], $rwd( '25%', '30%', '100%' ) ),
                        $col( $types( [ 'account_welcome', 'account_orders', 'account_details' ] ), $rwd( '75%', '70%', '100%' ), [ 'gap' => $rwd( '16px' ) ] ),
                    ], $pad( '16', '16', '24', '16' ) + [ 'audience' => 'customers', 'direction' => $rwd( 'row', 'row', 'column' ), 'align_items' => $rwd( 'flex-start' ), 'gap' => $rwd( '24px' ) ] ),
                ];
                break;

            default:
                return [];
        }
        return array_values( $out );
    }

    /** The parts each of the first checkout blocks becomes (by its own switches). */
    private static function checkout_block_parts( $type, array $data ) {
        $off = static fn( $v ) => in_array( $v, [ false, 0, '0', 'false' ], true );
        if ( $type === 'checkout_details' ) {
            return array_merge( [ 'checkout_contact', 'checkout_shipping_address', 'checkout_billing_address' ],
                $off( $data['show_coupon'] ?? true ) ? [] : [ 'checkout_coupon' ] );
        }
        return array_merge( $off( $data['show_items'] ?? true ) ? [] : [ 'checkout_items' ], [ 'checkout_shipping', 'checkout_payment' ] );
    }

    /**
     * A checkout list with Customer Details / Order Summary blocks: each becomes
     * an inner section (with the old block's own settings) holding its parts.
     */
    public static function split_checkout_blocks( array $nodes, &$changed = false ) {
        $out = [];
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                $out[] = $node;
                continue;
            }
            $type = (string) ( $node['type'] ?? '' );
            if ( $type === 'checkout_details' || $type === 'checkout_summary' ) {
                $data  = is_array( $node['data'] ?? null ) ? $node['data'] : [];
                $style = is_array( $node['style'] ?? null ) ? $node['style'] : [];
                $out[] = [
                    'id'      => self::new_id( 'blk' ),
                    'type'    => 'section',
                    'label'   => (string) ( $node['label'] ?? '' ),
                    'enabled' => ! isset( $node['enabled'] ) || ( ! empty( $node['enabled'] ) && $node['enabled'] !== 'false' ),
                    'style'   => $style + [ 'width_mode' => 'full', 'padding_preset' => 'none' ],
                    'blocks'  => array_map( static fn( $t ) => [ 'id' => self::new_id( 'blk' ), 'type' => $t, 'label' => '', 'enabled' => true, 'style' => [], 'data' => [] ],
                        self::checkout_block_parts( $type, $data ) ),
                ];
                $changed = true;
                continue;
            }
            if ( is_array( $node['blocks'] ?? null ) ) {
                $node['blocks'] = self::split_checkout_blocks( $node['blocks'], $changed );
            }
            $out[] = $node;
        }
        return $out;
    }

    /**
     * One coupon block everywhere (2026-11-09): a Cart Coupon becomes a Coupon Box (same fields),
     * and a Cart Totals with its coupon on gets a Coupon Box before it (its coupon then off).
     * $deep: also inner lists (sanitize_blocks walks those itself).
     */
    public static function merge_cart_coupons( array $nodes, &$changed = false, $deep = true ) {
        $on  = static fn( $v ) => in_array( $v, [ true, 1, '1', 'true' ], true );
        $out = [];
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                $out[] = $node;
                continue;
            }
            $type = (string) ( $node['type'] ?? '' );
            if ( $type === 'cart_coupon' ) {
                $node['type'] = 'checkout_coupon';
                $changed      = true;
            } elseif ( $type === 'cart_totals' && is_array( $node['data'] ?? null ) && $on( $node['data']['show_coupon'] ?? false ) ) {
                $card  = $node['data']['card'] ?? true;
                $out[] = [
                    'id'      => self::new_id( 'blk' ),
                    'type'    => 'checkout_coupon',
                    'label'   => '',
                    'enabled' => ! isset( $node['enabled'] ) || ( ! empty( $node['enabled'] ) && $node['enabled'] !== 'false' ),
                    'style'   => [],
                    'data'    => [ 'show_title' => true, 'card' => $card ],
                ];
                $node['data']['show_coupon'] = false;
                $changed = true;
            }
            if ( $deep && is_array( $node['blocks'] ?? null ) ) {
                $node['blocks'] = self::merge_cart_coupons( $node['blocks'], $changed );
            }
            $out[] = $node;
        }
        return $out;
    }

    /**
     * Related Products (2026-11-11): the block is gone; a saved one becomes a Heading (when its
     * heading was on) and a Carousel or a Product Grid that shows "Related to this page's product",
     * with every setting kept (the Related block's fields were the Carousel's own).
     * $deep: also inner lists (sanitize_blocks walks those itself).
     */
    public static function related_as_carousel( array $nodes, &$changed = false, $deep = true ) {
        $off = static fn( $v ) => in_array( $v, [ false, 0, '0', 'false' ], true );
        $out = [];
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                $out[] = $node;
                continue;
            }
            if ( (string) ( $node['type'] ?? '' ) === 'product_related' ) {
                $d       = is_array( $node['data'] ?? null ) ? $node['data'] : [];
                $enabled = ! isset( $node['enabled'] ) || ( ! empty( $node['enabled'] ) && $node['enabled'] !== 'false' );
                if ( ! $off( $d['show_title'] ?? true ) ) {
                    $heading = [
                        'title'     => trim( (string) ( $d['title'] ?? '' ) ) ?: __( 'Related products', 'woocommerce' ),
                        'tag'       => 'h2',
                        'alignment' => $d['title_align'] ?? [ 'desktop' => 'center' ],
                        // The old heading's size when none was set.
                        'font_size' => [ 'desktop' => '1.5rem' ],
                    ];
                    $keys = [ 'title_size' => 'font_size', 'title_weight' => 'font_weight', 'title_font' => 'title_font', 'title_line_height' => 'title_line_height',
                            'title_color' => 'title_color', 'title_spacing' => 'title_spacing', 'title_case' => 'title_case' ];
                    foreach ( $keys as $from => $to ) {
                        $v = $d[ $from ] ?? '';
                        $set = is_array( $v ) ? (bool) array_filter( $v, static fn( $x ) => $x !== '' && $x !== null ) : ( $v !== '' && $v !== null );
                        if ( $set ) $heading[ $to ] = $v;
                    }
                    $out[] = [
                        'id' => self::new_id( 'blk' ), 'type' => 'heading', 'label' => '', 'enabled' => $enabled,
                        'style' => [ 'margin' => [ 'desktop' => [ 'top' => '', 'right' => '', 'bottom' => '16px', 'left' => '' ] ] ],
                        'data'  => $heading,
                    ];
                }
                $grid = ( $d['display'] ?? 'carousel' ) === 'grid';
                foreach ( [ 'title', 'show_title', 'title_align', 'title_size', 'title_weight', 'title_font', 'title_line_height', 'title_color', 'title_spacing', 'title_case', 'display' ] as $key ) {
                    unset( $d[ $key ] );
                }
                if ( $grid ) {
                    $node['type'] = 'product_grid';
                    $node['data'] = [ 'query_type' => 'related', 'limit' => $d['limit'] ?? 8, 'hide_out_of_stock' => ! empty( $d['hide_out_of_stock'] ), 'card_template' => $d['card_template'] ?? '' ]
                            + ( isset( $d['items_per_view'] ) ? [ 'columns' => $d['items_per_view'] ] : [] )
                            + ( isset( $d['gap'] ) ? [ 'gap' => $d['gap'] ] : [] );
                } else {
                    $node['type'] = 'carousel';
                    $node['data'] = [ 'source' => 'products', 'query_type' => 'related' ] + $d;
                }
                $changed = true;
            }
            if ( $deep && is_array( $node['blocks'] ?? null ) ) {
                $node['blocks'] = self::related_as_carousel( $node['blocks'], $changed );
            }
            $out[] = $node;
        }
        return $out;
    }

    /** Whether the checkout is in steps: the page's own value when it was sent, else the stored one. */
    private static function checkout_steps_on( array $page_opts ) {
        if ( array_key_exists( 'steps', $page_opts ) ) return ! empty( $page_opts['steps'] ) && $page_opts['steps'] !== 'false';
        $stored = get_option( 'shop_builder_options', [] );
        return ! empty( $stored['checkout']['steps'] );
    }

    /**
     * Checkout steps as containers (2026-11-11). Before, each checkout block had a Step field
     * (Automatic / 1 / 2 / 3 / every step) and the Checkout Steps block only drew the bar. Now
     * the Checkout Steps block holds one inner section per step. A checkout in steps without
     * such a block (a saved one, or the switch just turned on) gets it where its first step
     * block was: Details / Delivery / Payment, each with the blocks that showed in that step,
     * in their order. Blocks of "every step" stay where they are. The old bar block's look and
     * step names are kept. With steps off nothing moves (the old Step values are only dropped).
     */
    public static function checkout_steps_as_containers( array $sections, $steps_on, &$changed = false ) {
        $has = false;
        $old = false;
        $scan = static function ( array $nodes ) use ( &$scan, &$has, &$old ) {
            foreach ( $nodes as $n ) {
                if ( ! is_array( $n ) ) continue;
                if ( ( $n['type'] ?? '' ) === 'checkout_steps' && array_filter( (array) ( $n['blocks'] ?? [] ), 'is_array' ) ) $has = true;
                if ( is_array( $n['data'] ?? null ) && array_key_exists( 'step', $n['data'] ) ) $old = true;
                if ( is_array( $n['blocks'] ?? null ) ) $scan( $n['blocks'] );
            }
        };
        $scan( $sections );

        if ( $has || ! $steps_on ) {
            if ( ! $old ) return $sections;
            $strip = static function ( array $nodes ) use ( &$strip ) {
                foreach ( $nodes as $i => $n ) {
                    if ( ! is_array( $n ) ) continue;
                    if ( is_array( $n['data'] ?? null ) ) unset( $nodes[ $i ]['data']['step'] );
                    if ( is_array( $n['blocks'] ?? null ) ) $nodes[ $i ]['blocks'] = $strip( $n['blocks'] );
                }
                return $nodes;
            };
            $changed = true;
            return $strip( $sections );
        }

        $steps  = [ 1 => [], 2 => [], 3 => [] ];
        $bar    = null;
        $placed = false;
        $gap    = null;
        $marker = '__checkout_steps__';
        $is_box  = static fn( $type ) => $type === 'section' || ( $type !== '' && self::holds_blocks( $type ) );
        $step_of = static function ( array $n ) {
            $step = (string) ( $n['data']['step'] ?? 'auto' );
            if ( in_array( $step, [ '1', '2', '3' ], true ) ) return (int) $step;
            return $step === 'all' ? 0 : (int) ( self::CHECKOUT_AUTO_STEP[ (string) ( $n['type'] ?? '' ) ] ?? 0 );
        };
        // The Checkout Steps block goes into the list that has the most step blocks (the form's
        // column, not a single block above it), where that list's first step block was.
        $counts = [];
        $count = static function ( array $nodes, $path ) use ( &$count, &$counts, $is_box, $step_of ) {
            foreach ( $nodes as $j => $n ) {
                if ( ! is_array( $n ) ) continue;
                $type = (string) ( $n['type'] ?? '' );
                if ( $type === 'checkout_steps' ) continue;
                if ( $is_box( $type ) ) {
                    if ( is_array( $n['blocks'] ?? null ) ) $count( $n['blocks'], $path . '/' . $j );
                } elseif ( $step_of( $n ) ) {
                    $counts[ $path ] = ( $counts[ $path ] ?? 0 ) + 1;
                }
            }
        };
        foreach ( $sections as $i => $section ) {
            if ( is_array( $section ) && is_array( $section['blocks'] ?? null ) ) $count( $section['blocks'], (string) $i );
        }
        $home = $counts ? (string) array_search( max( $counts ), $counts, true ) : '';

        $pull = static function ( array $nodes, array $parent_style, $path ) use ( &$pull, &$steps, &$bar, &$placed, &$gap, $marker, $is_box, $step_of, $home ) {
            $out = [];
            foreach ( $nodes as $j => $n ) {
                if ( ! is_array( $n ) ) {
                    $out[] = $n;
                    continue;
                }
                $type = (string) ( $n['type'] ?? '' );
                if ( $type === 'checkout_steps' ) {
                    if ( $bar === null ) $bar = $n;
                    continue;
                }
                if ( $is_box( $type ) ) {
                    if ( is_array( $n['blocks'] ?? null ) ) $n['blocks'] = $pull( $n['blocks'], is_array( $n['style'] ?? null ) ? $n['style'] : [], $path . '/' . $j );
                    $out[] = $n;
                    continue;
                }
                $at = $step_of( $n );
                if ( is_array( $n['data'] ?? null ) ) unset( $n['data']['step'] );
                if ( ! $at ) {
                    $out[] = $n;
                    continue;
                }
                if ( ! $placed && $path === $home ) {
                    $placed = true;
                    $gap    = $parent_style['gap'] ?? null;
                    $out[]  = $marker;
                }
                $steps[ $at ][] = $n;
            }
            return $out;
        };
        foreach ( $sections as $i => $section ) {
            if ( is_array( $section ) && is_array( $section['blocks'] ?? null ) ) {
                $sections[ $i ]['blocks'] = $pull( $section['blocks'], is_array( $section['style'] ?? null ) ? $section['style'] : [], (string) $i );
            }
        }
        $changed = true;
        if ( ! $placed ) return $sections; // no step blocks at all (the old bar, if any, is dropped)

        $data   = is_array( $bar['data'] ?? null ) ? $bar['data'] : [];
        $labels = [ 1 => (string) ( $data['details_label'] ?? '' ), 2 => (string) ( $data['delivery_label'] ?? '' ), 3 => (string) ( $data['payment_label'] ?? '' ) ];
        unset( $data['details_label'], $data['delivery_label'], $data['payment_label'], $data['step'] );
        $held = [];
        foreach ( $steps as $n => $blocks ) {
            $held[] = [
                'id' => self::new_id( 'blk' ), 'type' => 'section', 'label' => $labels[ $n ], 'enabled' => true,
                'style'  => [ 'width_mode' => 'full', 'padding_preset' => 'none' ] + ( $gap ? [ 'gap' => $gap ] : [] ),
                'blocks' => $blocks,
            ];
        }
        $container = [
            'id'      => preg_match( '/^blk_[a-zA-Z0-9]{6,20}$/', (string) ( $bar['id'] ?? '' ) ) ? $bar['id'] : self::new_id( 'blk' ),
            'type'    => 'checkout_steps',
            'label'   => (string) ( $bar['label'] ?? '' ),
            'enabled' => true,
            'style'   => is_array( $bar['style'] ?? null ) ? $bar['style'] : [],
            'data'    => $data,
            'blocks'  => $held,
        ];
        $place = static function ( array $nodes ) use ( &$place, $marker, $container ) {
            foreach ( $nodes as $i => $n ) {
                if ( $n === $marker ) {
                    $nodes[ $i ] = $container;
                } elseif ( is_array( $n ) && is_array( $n['blocks'] ?? null ) ) {
                    $nodes[ $i ]['blocks'] = $place( $n['blocks'] );
                }
            }
            return $nodes;
        };
        return $place( $sections );
    }

    /**
     * The old "Page Numbers" block (archive_pagination) leaves the list: page numbers are now
     * the Products Grid's own (Pagination, 'numbers' by default), so the page looks the same.
     */
    public static function drop_page_numbers_block( array $nodes, &$changed = false ) {
        $out = [];
        foreach ( $nodes as $node ) {
            if ( is_array( $node ) && (string) ( $node['type'] ?? '' ) === 'archive_pagination' ) {
                $changed = true;
                continue;
            }
            if ( is_array( $node ) && is_array( $node['blocks'] ?? null ) ) {
                $node['blocks'] = self::drop_page_numbers_block( $node['blocks'], $changed );
            }
            $out[] = $node;
        }
        return $out;
    }

    /** An old top-level section as a section inside a column. */
    private static function shop_nested_section( array $s ) {
        return [
            'id'      => self::new_id( 'blk' ),
            'type'    => 'section',
            'label'   => (string) ( $s['label'] ?? '' ),
            'enabled' => ! isset( $s['enabled'] ) || ( ! empty( $s['enabled'] ) && $s['enabled'] !== 'false' ),
            'style'   => is_array( $s['style'] ?? null ) ? $s['style'] : [],
            'blocks'  => is_array( $s['blocks'] ?? null ) ? $s['blocks'] : [],
        ];
    }

    /** One shop page's options with its old lists (default and layouts) turned into templates. */
    public static function shop_page_as_template( array $page_opts, $page, &$changed = false ) {
        if ( array_key_exists( 'sections', $page_opts ) && self::is_old_shop_list( $page_opts['sections'], $page ) ) {
            $page_opts['sections'] = self::shop_template_sections( $page, (array) $page_opts['sections'] );
            $changed = true;
        }
        if ( is_array( $page_opts['layouts'] ?? null ) ) {
            foreach ( $page_opts['layouts'] as $i => $layout ) {
                if ( is_array( $layout ) && self::is_old_shop_list( $layout['sections'] ?? [], $page ) ) {
                    $page_opts['layouts'][ $i ]['sections'] = self::shop_template_sections( $page, (array) ( $layout['sections'] ?? [] ) );
                    $changed = true;
                }
            }
        }
        if ( $page === 'shop' || $page === 'category' ) {
            if ( is_array( $page_opts['sections'] ?? null ) ) {
                $page_opts['sections'] = self::drop_page_numbers_block( $page_opts['sections'], $changed );
            }
            foreach ( (array) ( $page_opts['layouts'] ?? [] ) as $i => $layout ) {
                if ( is_array( $layout['sections'] ?? null ) ) {
                    $page_opts['layouts'][ $i ]['sections'] = self::drop_page_numbers_block( $layout['sections'], $changed );
                }
            }
        }
        if ( $page === 'cart' ) {
            if ( is_array( $page_opts['sections'] ?? null ) ) {
                $page_opts['sections'] = self::merge_cart_coupons( $page_opts['sections'], $changed );
            }
            foreach ( (array) ( $page_opts['layouts'] ?? [] ) as $i => $layout ) {
                if ( is_array( $layout['sections'] ?? null ) ) {
                    $page_opts['layouts'][ $i ]['sections'] = self::merge_cart_coupons( $layout['sections'], $changed );
                }
            }
        }
        if ( $page === 'checkout' ) {
            $steps_on = self::checkout_steps_on( $page_opts );
            if ( is_array( $page_opts['sections'] ?? null ) ) {
                $page_opts['sections'] = self::split_checkout_blocks( $page_opts['sections'], $changed );
                $page_opts['sections'] = self::checkout_steps_as_containers( $page_opts['sections'], $steps_on, $changed );
            }
            foreach ( (array) ( $page_opts['layouts'] ?? [] ) as $i => $layout ) {
                if ( is_array( $layout['sections'] ?? null ) ) {
                    $page_opts['layouts'][ $i ]['sections'] = self::split_checkout_blocks( $layout['sections'], $changed );
                    $page_opts['layouts'][ $i ]['sections'] = self::checkout_steps_as_containers( $page_opts['layouts'][ $i ]['sections'], $steps_on, $changed );
                }
            }
        }
        if ( $page === 'product' ) {
            if ( is_array( $page_opts['sections'] ?? null ) ) {
                $page_opts['sections'] = self::related_as_carousel( $page_opts['sections'], $changed );
            }
            foreach ( (array) ( $page_opts['layouts'] ?? [] ) as $i => $layout ) {
                if ( is_array( $layout['sections'] ?? null ) ) {
                    $page_opts['layouts'][ $i ]['sections'] = self::related_as_carousel( $layout['sections'], $changed );
                }
            }
        }
        if ( empty( $page_opts['template'] ) ) {
            $page_opts['template'] = 1;
            $changed = true;
        }
        return $page_opts;
    }

    /** Every shop page in a whole options array (and each extra language's copy). */
    public static function shop_templates_walk( array $options, &$changed = false ) {
        foreach ( self::SHOP_TEMPLATE_PAGES as $page ) {
            $current = is_array( $options[ $page ] ?? null ) ? $options[ $page ] : [];
            if ( ! array_key_exists( 'sections', $current ) ) {
                $current['sections'] = [];
            }
            $was       = false;
            $converted = self::shop_page_as_template( $current, $page, $was );
            if ( $was ) {
                $changed = true;
                $converted['sections'] = self::instance()->sanitize_sections( $converted['sections'], $page );
                foreach ( (array) ( $converted['layouts'] ?? [] ) as $i => $layout ) {
                    $converted['layouts'][ $i ]['sections'] = self::instance()->sanitize_sections( $layout['sections'] ?? [], $page );
                }
            }
            $options[ $page ] = $converted;
        }
        foreach ( (array) ( $options['i18n'] ?? [] ) as $lang => $tr ) {
            foreach ( self::SHOP_TEMPLATE_PAGES as $page ) {
                $copy = $tr['pages'][ $page ] ?? null;
                if ( ! is_array( $copy ) ) continue;
                $was  = false;
                $copy = self::shop_page_as_template( $copy, $page, $was );
                if ( $was ) {
                    if ( isset( $copy['sections'] ) ) $copy['sections'] = self::instance()->sanitize_sections( $copy['sections'], $page );
                    foreach ( (array) ( $copy['layouts'] ?? [] ) as $i => $layout ) {
                        $copy['layouts'][ $i ]['sections'] = self::instance()->sanitize_sections( $layout['sections'] ?? [], $page );
                    }
                    $options['i18n'][ $lang ]['pages'][ $page ] = $copy;
                    $changed = true;
                }
            }
        }
        return self::merge_archive_layouts( $options, $changed );
    }

    /**
     * Product archives (2026-11-09): All products and the category pages are one Store builder page,
     * `shop`. Its sections are the main layout of every products page; its layouts take over where
     * they match (LAYOUT_PAGES['shop']). Layouts made for the category page move to it, ids and
     * conditions kept (after the shop's own); the category page's own sections stay stored, unused
     * (the user chose to drop that design; it can be brought back from there). Also each extra
     * language's copies and section_sync keys ("category@lay_…" → "shop@lay_…"). Nothing changes
     * when there is nothing to move.
     */
    public static function merge_archive_layouts( array $options, &$changed = false ) {
        $append = static function ( array $shop, array $layouts ) {
            $list = array_values( array_filter( (array) ( $shop['layouts'] ?? [] ), 'is_array' ) );
            $at   = [];
            foreach ( $list as $i => $l ) $at[ (string) ( $l['id'] ?? '' ) ] = $i;
            foreach ( $layouts as $l ) {
                if ( ! is_array( $l ) ) continue;
                $id = (string) ( $l['id'] ?? '' );
                if ( $id !== '' && isset( $at[ $id ] ) ) {
                    $list[ $at[ $id ] ] = $l;
                } else {
                    $list[] = $l;
                }
            }
            $shop['layouts'] = array_slice( $list, 0, self::MAX_LAYOUTS );
            return $shop;
        };

        $moved = array_values( array_filter( (array) ( $options['category']['layouts'] ?? [] ), 'is_array' ) );
        if ( isset( $options['category']['layouts'] ) ) {
            unset( $options['category']['layouts'] );
            $changed = true;
        }
        if ( $moved ) {
            $options['shop'] = $append( is_array( $options['shop'] ?? null ) ? $options['shop'] : [], $moved );
        }

        foreach ( (array) ( $options['i18n'] ?? [] ) as $lang => $tr ) {
            if ( ! isset( $tr['pages']['category']['layouts'] ) ) continue;
            $own = array_values( array_filter( (array) $tr['pages']['category']['layouts'], 'is_array' ) );
            unset( $options['i18n'][ $lang ]['pages']['category']['layouts'] );
            $changed = true;
            if ( ! $own ) continue;
            // A language without its own All products copy starts from the main one.
            $base = $tr['pages']['shop'] ?? ( is_array( $options['shop'] ?? null ) ? $options['shop'] : [] );
            $options['i18n'][ $lang ]['pages']['shop'] = $append( (array) $base, $own );
        }

        foreach ( (array) ( $options['section_sync'] ?? [] ) as $code => $map ) {
            foreach ( (array) $map as $key => $source ) {
                if ( strpos( (string) $key, 'category@' ) !== 0 ) continue;
                $options['section_sync'][ $code ][ 'shop@' . substr( (string) $key, 9 ) ] = $source;
                unset( $options['section_sync'][ $code ][ $key ] );
                $changed = true;
            }
        }
        return $options;
    }

    /**
     * The stored Store builder: shop pages still in the old slot shape become
     * templates (cheap to call: nothing is written when all of them are).
     * Saved versions convert when they're opened or restored (their lists
     * come in through here or sanitize_options again).
     */
    public static function upgrade_shop_templates() {
        $options = get_option( 'shop_builder_options', [] );
        if ( ! is_array( $options ) ) return;
        $changed = false;
        $options = self::shop_templates_walk( $options, $changed );
        if ( $changed ) {
            update_option( 'shop_builder_options', $options );
        }
    }
}

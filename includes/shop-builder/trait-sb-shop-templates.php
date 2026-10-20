<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The shop pages (SHOP_TEMPLATE_PAGES: All products, category pages, the
 * product page, cart and checkout) as block-built templates, like the blog.
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
                    [ $sec( [ $block( 'product_related' ) ], $pad( '0', '16', '16', '16' ) ) ],
                    $top( 'after_related_products' )
                );
                break;

            case 'shop':
            case 'category':
                $out = array_merge(
                    $top( 'before_breadcrumbs' ),
                    [ $sec( [ $block( 'archive_breadcrumbs' ) ], $pad( '0', '16', '0', '16' ) ) ],
                    $top( 'after_breadcrumbs' ),
                    [ $sec( [ $block( 'archive_title' ) ], $pad( '0', '16', '0', '16' ) ) ],
                    [ $sec( [
                        $col( array_merge( $inside( 'before_filters' ), [ $block( 'archive_filters' ) ] ), $rwd( '25%', '25%', '100%' ), [ 'gap' => $rwd( '25px' ) ] ),
                        $col( array_merge(
                            $types( [ 'archive_count', 'archive_sort' ] ),
                            $inside( 'before_products_grid' ),
                            [ $block( 'archive_products' ) ],
                            $inside( 'after_products_grid' ),
                            [ $block( 'archive_pagination' ) ]
                        ), $rwd( '75%', '75%', '100%' ), [ 'gap' => $rwd( '16px' ) ] ),
                    ], $pad( '0', '16', '20', '16' ) + [ 'direction' => $rwd( 'row', 'row', 'column' ), 'gap' => $rwd( '20px' ) ] ) ],
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
                    [ $sec( [ $block( 'cart_totals' ) ], $pad( '16', '16', '16', '16' ) ) ],
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
                                $col( array_merge( $inside( 'before_customer_details' ), [ $block( 'checkout_details' ) ], $inside( 'after_customer_details' ) ), $rwd( '57%', '57%', '100%' ) ),
                                $col( array_merge( $inside( 'before_order_review' ), [ $block( 'checkout_summary' ) ], $inside( 'before_place_order' ), [ $block( 'checkout_place_order' ) ] ), $rwd( '41%', '41%', '100%' ) ),
                            ],
                        ],
                    ], $pad( '16', '16', '16', '16' ) + [ 'gap' => $rwd( '8px' ) ] ) ],
                    $top( 'after_checkout' )
                );
                break;

            default:
                return [];
        }
        return array_values( $out );
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

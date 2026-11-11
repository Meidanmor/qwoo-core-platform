<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Product attributes for the platform dashboard (used by Qwoo_Platform_Dashboard):
 * the store's shared attributes (WooCommerce's pa_… taxonomies, like "Material:
 * Steel, Gold") and the attributes a product shows as plain details.
 *
 * Product attributes are always shared: a new name or value typed on a product
 * is added to the store's list, so it works in shop filters and in the Store
 * builder's Details picker. Options (the ones that make variants) stay in
 * variable_plan. An attribute's key (pa_…) never changes after it's made:
 * filters and published designs point at it.
 */
trait Qwoo_Platform_Attributes {

    private static $attr_max_values   = 300; // values in one shared attribute
    private static $attr_max_details  = 30;  // attributes on one product
    private static $attr_max_per_item = 30;  // values of one attribute on one product

    /* ---------------- the shared list ---------------- */

    /** { values: bool } → items [ { id, name, taxonomy, count, products, values? [names] } ], by name. */
    private static function action_attributes_list( array $params ) {
        $with = ! empty( $params['values'] );
        $out  = [];
        foreach ( wc_get_attribute_taxonomies() as $a ) {
            $taxonomy = self::attr_registered( $a->attribute_name );
            $terms    = self::attr_terms( $taxonomy );
            $item     = [
                'id'       => (int) $a->attribute_id,
                'name'     => html_entity_decode( $a->attribute_label ?: $a->attribute_name, ENT_QUOTES ),
                'taxonomy' => $taxonomy,
                'count'    => count( $terms ),
                'products' => self::attr_product_count( $taxonomy ),
            ];
            if ( $with ) {
                $item['values'] = array_map( static fn( $t ) => html_entity_decode( $t->name, ENT_QUOTES ), $terms );
            }
            $out[] = $item;
        }
        usort( $out, static fn( $x, $y ) => strcasecmp( $x['name'], $y['name'] ) );
        return [ 'items' => $out ];
    }

    /** { name } → the new attribute. */
    private static function action_attribute_create( array $params ) {
        $name = self::attr_clean_name( $params['name'] ?? '' );
        if ( is_wp_error( $name ) ) {
            return $name;
        }
        if ( self::attr_by_label( $name ) ) {
            return self::bad( "\"$name\" is already in the list." );
        }
        $id = self::attr_create( $name );
        return is_wp_error( $id ) ? $id : self::attribute_full( $id );
    }

    private static function action_attribute_get( array $params ) {
        $a = self::find_attribute( $params['id'] ?? 0 );
        return is_wp_error( $a ) ? $a : self::attribute_full( (int) $a->id );
    }

    /**
     * { id, fields: { name, values: [ { id (0 = new), name } ] in order } }.
     * Values left out are deleted, unless a product uses them as an option
     * (they make its variants): then nothing changes.
     */
    private static function action_attribute_save( array $params ) {
        $a = self::find_attribute( $params['id'] ?? 0 );
        if ( is_wp_error( $a ) ) {
            return $a;
        }
        $f    = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];
        $name = self::attr_clean_name( $f['name'] ?? '' );
        if ( is_wp_error( $name ) ) {
            return $name;
        }
        $other = self::attr_by_label( $name );
        if ( $other && (int) $other->attribute_id !== (int) $a->id ) {
            return self::bad( "\"$name\" is already in the list." );
        }
        $taxonomy = self::attr_registered( $a->slug );
        $existing = [];
        foreach ( self::attr_terms( $taxonomy ) as $term ) {
            $existing[ (int) $term->term_id ] = $term;
        }

        // Check everything first: a refused save changes nothing.
        $input = array_values( array_filter( (array) ( $f['values'] ?? [] ), 'is_array' ) );
        if ( count( $input ) > self::$attr_max_values ) {
            return self::bad( 'An attribute can have up to ' . self::$attr_max_values . ' values.' );
        }
        $values = [];
        $seen   = [];
        foreach ( $input as $v ) {
            $id    = absint( $v['id'] ?? 0 );
            $value = self::attr_clean_value( $v['name'] ?? '' );
            if ( is_wp_error( $value ) ) {
                return $value;
            }
            if ( $value === '' ) {
                continue;
            }
            if ( $id && ! isset( $existing[ $id ] ) ) {
                return self::bad( 'One of the values doesn\'t exist any more. Reload the page and try again.' );
            }
            $lower = mb_strtolower( $value );
            if ( isset( $seen[ $lower ] ) ) {
                return self::bad( "\"$value\" is there twice." );
            }
            $seen[ $lower ] = true;
            $values[]       = [ 'id' => $id, 'name' => $value ];
        }
        $gone = array_diff( array_keys( $existing ), array_column( $values, 'id' ) );
        foreach ( $gone as $id ) {
            $used = self::attr_option_products( $taxonomy, $id );
            if ( $used ) {
                $value = html_entity_decode( $existing[ $id ]->name, ENT_QUOTES );
                return self::bad( "\"$value\" is an option of " . self::attr_products_text( $used ) . ' (it makes their variants). Remove it there first.' );
            }
        }

        $result = wc_update_attribute( (int) $a->id, [
            'name'         => $name,
            'slug'         => $a->slug,
            'type'         => $a->type ?: 'select',
            'order_by'     => 'menu_order', // the owner's order
            'has_archives' => (bool) $a->has_archives,
        ] );
        if ( is_wp_error( $result ) ) {
            return self::bad( $result->get_error_message() );
        }
        foreach ( $gone as $id ) {
            wp_delete_term( $id, $taxonomy );
        }
        foreach ( $values as $order => $v ) {
            if ( $v['id'] ) {
                if ( html_entity_decode( $existing[ $v['id'] ]->name, ENT_QUOTES ) !== $v['name'] ) {
                    // The slug stays: variants and filter addresses keep working.
                    $done = wp_update_term( $v['id'], $taxonomy, [ 'name' => $v['name'] ] );
                    if ( is_wp_error( $done ) ) {
                        return self::bad( "\"{$v['name']}\" couldn't be saved: " . $done->get_error_message() );
                    }
                }
                $term_id = $v['id'];
            } else {
                $term_id = self::attr_term( $taxonomy, $v['name'] );
                if ( is_wp_error( $term_id ) ) {
                    return $term_id;
                }
            }
            update_term_meta( $term_id, 'order', $order );
        }
        delete_transient( 'wc_attribute_taxonomies' );
        WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
        return self::attribute_full( (int) $a->id );
    }

    /** Deletes an attribute and its values, unless products use it as an option. */
    private static function action_attribute_delete( array $params ) {
        $a = self::find_attribute( $params['id'] ?? 0 );
        if ( is_wp_error( $a ) ) {
            return $a;
        }
        $taxonomy = self::attr_registered( $a->slug );
        $used     = self::attr_option_products( $taxonomy );
        if ( $used ) {
            return self::bad( "\"{$a->name}\" is an option of " . self::attr_products_text( $used ) . ' (it makes their variants). Remove it there first.' );
        }
        $products = self::attr_product_ids( $taxonomy );
        $result   = wc_delete_attribute( (int) $a->id );
        if ( ! $result ) {
            return self::bad( 'The attribute couldn\'t be deleted. Please try again.' );
        }
        // Take it off the products too (WooCommerce leaves it in their attribute list).
        foreach ( $products as $id ) {
            $product = wc_get_product( $id );
            if ( $product ) {
                $list = $product->get_attributes( 'edit' );
                unset( $list[ $taxonomy ] );
                $product->set_attributes( $list );
                $product->save();
            }
        }
        return [ 'deleted' => (int) $a->id ];
    }

    private static function find_attribute( $id ) {
        $a = wc_get_attribute( absint( $id ) );
        if ( ! $a ) {
            return self::error( 'qwoo_dashboard_not_found', 'This attribute doesn\'t exist any more.', 404 );
        }
        return $a;
    }

    private static function attribute_full( $id ) {
        $a        = wc_get_attribute( $id );
        $taxonomy = self::attr_registered( $a->slug );
        $values   = [];
        $counts   = self::attr_value_counts( $taxonomy );
        foreach ( self::attr_terms( $taxonomy ) as $term ) {
            $values[] = [
                'id'       => (int) $term->term_id,
                'name'     => html_entity_decode( $term->name, ENT_QUOTES ),
                'products' => $counts[ (int) $term->term_id ] ?? 0,
            ];
        }
        return [
            'id'       => (int) $a->id,
            'name'     => html_entity_decode( $a->name, ENT_QUOTES ),
            'taxonomy' => $taxonomy,
            'values'   => $values,
            'products' => self::attr_product_count( $taxonomy ),
            'options'  => count( self::attr_option_products( $taxonomy ) ),
        ];
    }

    /* ---------------- on a product ---------------- */

    /**
     * The attributes a product shows as details: [ { name, values: [names] } ].
     * Simple products: all of them; products with options: the ones that
     * don't make variants.
     */
    private static function product_details( WC_Product $p ) {
        $out = [];
        foreach ( $p->get_attributes() as $attr ) {
            if ( $p->is_type( 'variable' ) && $attr->get_variation() ) {
                continue;
            }
            if ( $attr->is_taxonomy() ) {
                if ( ! taxonomy_exists( $attr->get_name() ) ) {
                    continue;
                }
                // In the shared list's order; the name from the list itself (WooCommerce's label cache misses one made in this request).
                $ids    = array_map( 'intval', $attr->get_options() );
                $values = [];
                foreach ( self::attr_terms( $attr->get_name() ) as $term ) {
                    if ( in_array( (int) $term->term_id, $ids, true ) ) {
                        $values[] = html_entity_decode( $term->name, ENT_QUOTES );
                    }
                }
                $shared = $attr->get_id() ? wc_get_attribute( $attr->get_id() ) : null;
                $out[]  = [ 'name' => html_entity_decode( $shared ? $shared->name : wc_attribute_label( $attr->get_name() ), ENT_QUOTES ), 'values' => $values, 'visible' => (bool) $attr->get_visible() ];
            } else {
                // Made in wp-admin on this product only: it becomes shared on the next save here.
                $out[] = [ 'name' => $attr->get_name(), 'values' => array_values( $attr->get_options() ), 'own' => true, 'visible' => (bool) $attr->get_visible() ];
            }
        }
        return $out;
    }

    /**
     * Checks params.fields.details ([ { name, values: [names] } ], in order):
     * [ lower-case name => { name, values } ], or null when not sent (the
     * product's details are left alone). $options are the lower-case names
     * of the product's options, which a detail can't share.
     */
    private static function details_plan( array $f, array $options = [] ) {
        if ( ! array_key_exists( 'details', $f ) ) {
            return null;
        }
        $input = array_values( array_filter( (array) $f['details'], 'is_array' ) );
        if ( count( $input ) > self::$attr_max_details ) {
            return self::bad( 'A product can have up to ' . self::$attr_max_details . ' attributes.' );
        }
        $plan = [];
        foreach ( $input as $d ) {
            $name = self::attr_clean_name( $d['name'] ?? '' );
            if ( is_wp_error( $name ) ) {
                return $name;
            }
            $lower = mb_strtolower( $name );
            if ( isset( $plan[ $lower ] ) ) {
                return self::bad( "\"$name\" is there twice." );
            }
            if ( in_array( $lower, $options, true ) ) {
                return self::bad( "\"$name\" is already an option of this product. Use another attribute." );
            }
            $values = [];
            foreach ( (array) ( $d['values'] ?? [] ) as $value ) {
                $value = self::attr_clean_value( is_scalar( $value ) ? $value : '' );
                if ( is_wp_error( $value ) ) {
                    return $value;
                }
                if ( $value !== '' ) {
                    $values[ mb_strtolower( $value ) ] = $value;
                }
            }
            if ( ! $values ) {
                return self::bad( "Add a value to \"$name\", or remove it." );
            }
            if ( count( $values ) > self::$attr_max_per_item ) {
                return self::bad( "\"$name\" can have up to " . self::$attr_max_per_item . ' values on one product.' );
            }
            // visible: listed in the product's details (default); off = only for the shop's filters.
            $plan[ $lower ] = [ 'name' => $name, 'values' => array_values( $values ), 'visible' => ! array_key_exists( 'visible', $d ) || ! empty( $d['visible'] ) ];
        }
        return $plan;
    }

    /** The product attributes of a details plan, adding new attributes and values to the shared list. */
    private static function build_details( array $plan, $position = 0 ) {
        $list = [];
        foreach ( $plan as $d ) {
            $a  = self::attr_by_label( $d['name'] );
            $id = $a ? (int) $a->attribute_id : self::attr_create( $d['name'] );
            if ( is_wp_error( $id ) ) {
                throw new WC_Data_Exception( 'qwoo_attribute', $id->get_error_message() );
            }
            $taxonomy = self::attr_registered( wc_get_attribute( $id )->slug );
            $ids      = [];
            foreach ( $d['values'] as $value ) {
                $term = self::attr_term( $taxonomy, $value );
                if ( is_wp_error( $term ) ) {
                    throw new WC_Data_Exception( 'qwoo_attribute', $term->get_error_message() );
                }
                $ids[] = $term;
            }
            $attr = new WC_Product_Attribute();
            $attr->set_id( $id );
            $attr->set_name( $taxonomy );
            $attr->set_options( $ids );
            $attr->set_position( $position++ );
            $attr->set_visible( $d['visible'] ?? true );
            $attr->set_variation( false );
            $list[ $taxonomy ] = $attr;
        }
        return $list;
    }

    /* ---------------- tidy up: options kept on products move to the shared list ---------------- */

    /** Why a product was left out of the tidy up (shown to the owner). */
    private static $tidy_skip_meta = '_qwoo_options_skip';
    /** What the product had before (attributes, default choices, each variation's choices), to put back. */
    private static $tidy_copy_meta = '_qwoo_options_before';

    /**
     * { op: report }  what would move: { products, groups: [ { name, products,
     *                 target (the shared attribute's name, '' = a new one),
     *                 new_values } ], skipped: [ { id, name, why } ] }
     * { op: run }     moves a few products per call: { done, left, skipped }
     * { op: undo, id } puts one product back as it was before its move.
     */
    private static function action_options_tidy( array $params ) {
        $op = (string) ( $params['op'] ?? 'report' );
        if ( $op === 'undo' ) {
            return self::tidy_undo( absint( $params['id'] ?? 0 ) );
        }
        $ids = self::tidy_product_ids();
        if ( $op !== 'run' ) {
            return self::tidy_report( $ids );
        }
        if ( class_exists( 'Qwoo_Owner_Alerts' ) && method_exists( 'Qwoo_Owner_Alerts', 'mute' ) ) {
            Qwoo_Owner_Alerts::mute();
        }
        $start = microtime( true );
        $done  = 0;
        foreach ( $ids as $i => $id ) {
            if ( $done >= 15 || microtime( true ) - $start > 12 ) {
                break;
            }
            $product = wc_get_product( $id );
            $result  = $product ? self::tidy_product( $product ) : self::bad( 'The product is gone.' );
            if ( is_wp_error( $result ) ) {
                update_post_meta( $id, self::$tidy_skip_meta, $result->get_error_message() );
            } else {
                $done++;
            }
            unset( $ids[ $i ] );
        }
        return [ 'done' => $done, 'left' => count( $ids ), 'skipped' => self::tidy_skipped() ];
    }

    /** Products (not in the trash, not skipped before) that keep an attribute for themselves. */
    private static function tidy_product_ids() {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_product_attributes'
             WHERE p.post_type = 'product' AND p.post_status <> 'trash' AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s )
             ORDER BY p.ID ASC LIMIT 5000",
            '%' . $wpdb->esc_like( '"is_taxonomy";i:0;' ) . '%',
            '%' . $wpdb->esc_like( '"is_taxonomy";s:1:"0";' ) . '%'
        ) );
        $out = [];
        foreach ( array_map( 'intval', $ids ) as $id ) {
            if ( get_post_meta( $id, self::$tidy_skip_meta, true ) === '' ) {
                $out[] = $id;
            }
        }
        return $out;
    }

    private static function tidy_skipped() {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 200", self::$tidy_skip_meta ) );
        $out  = [];
        foreach ( $rows ?: [] as $row ) {
            if ( get_post_status( (int) $row->post_id ) && get_post_status( (int) $row->post_id ) !== 'trash' ) {
                $out[] = [ 'id' => (int) $row->post_id, 'name' => html_entity_decode( get_the_title( (int) $row->post_id ), ENT_QUOTES ), 'why' => (string) $row->meta_value ];
            }
        }
        return $out;
    }

    private static function tidy_report( array $ids ) {
        $groups = []; // lower-case name => [ name, products, values ]
        $count  = 0;
        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) {
                continue;
            }
            $has = false;
            foreach ( $product->get_attributes() as $attr ) {
                if ( $attr->is_taxonomy() ) {
                    continue;
                }
                $has   = true;
                $name  = trim( (string) $attr->get_name() );
                $lower = mb_strtolower( $name );
                $groups[ $lower ] ??= [ 'name' => $name, 'products' => 0, 'values' => [] ];
                $groups[ $lower ]['products']++;
                foreach ( $attr->get_options() as $option ) {
                    $groups[ $lower ]['values'][ mb_strtolower( trim( (string) $option ) ) ] = trim( (string) $option );
                }
            }
            $count += $has ? 1 : 0;
        }
        $out = [];
        foreach ( $groups as $lower => $g ) {
            $shared = self::attr_by_label( $g['name'] );
            $have   = [];
            if ( $shared ) {
                foreach ( self::attr_terms( self::attr_registered( $shared->attribute_name ) ) as $term ) {
                    $have[ mb_strtolower( html_entity_decode( $term->name, ENT_QUOTES ) ) ] = true;
                }
            }
            $new   = array_values( array_diff_key( $g['values'], $have ) );
            $out[] = [
                'name'       => $g['name'],
                'products'   => $g['products'],
                'target'     => $shared ? html_entity_decode( $shared->attribute_label ?: $shared->attribute_name, ENT_QUOTES ) : '',
                'new_values' => array_slice( $new, 0, 12 ),
                'new_count'  => count( $new ),
            ];
        }
        usort( $out, static fn( $x, $y ) => $y['products'] <=> $x['products'] );
        return [ 'products' => $count, 'groups' => array_slice( $out, 0, 100 ), 'skipped' => self::tidy_skipped() ];
    }

    /**
     * Moves one product's own attributes to the shared list: the attribute
     * with the same name (or a new one), its values added, and every
     * variation's choice pointed at the shared value. Prices, stock, photos
     * and everything else stay. Nothing is written when anything doesn't fit.
     */
    private static function tidy_product( WC_Product $p ) {
        $attributes = $p->get_attributes();
        $taken      = []; // taxonomies the product already uses
        foreach ( $attributes as $attr ) {
            if ( $attr->is_taxonomy() ) {
                $taken[ $attr->get_name() ] = true;
            }
        }
        $new = [];
        $map = []; // old key => [ taxonomy, [ old value (sanitized / lower-case) => slug ] ]
        foreach ( $attributes as $key => $attr ) {
            if ( $attr->is_taxonomy() ) {
                $new[] = clone $attr;
                continue;
            }
            $name = self::attr_clean_name( $attr->get_name() );
            if ( is_wp_error( $name ) ) {
                return $name;
            }
            $shared = self::attr_by_label( $name );
            $id     = $shared ? (int) $shared->attribute_id : self::attr_create( $name );
            if ( is_wp_error( $id ) ) {
                return $id;
            }
            $taxonomy = self::attr_registered( wc_get_attribute( $id )->slug );
            if ( isset( $taken[ $taxonomy ] ) ) {
                return self::bad( "It has \"$name\" twice. Open the product and keep one." );
            }
            $taken[ $taxonomy ] = true;
            $ids                = [];
            $values             = [];
            foreach ( $attr->get_options() as $option ) {
                $value = self::attr_clean_value( $option );
                if ( is_wp_error( $value ) ) {
                    return $value;
                }
                if ( $value === '' ) {
                    continue;
                }
                $term = self::attr_term( $taxonomy, $value );
                if ( is_wp_error( $term ) ) {
                    return $term;
                }
                $slug                                        = get_term( $term, $taxonomy )->slug;
                $ids[ $term ]                                = (int) $term;
                $values[ sanitize_title( (string) $option ) ] = $slug;
                $values[ mb_strtolower( (string) $option ) ]  = $slug;
            }
            if ( ! $ids ) {
                continue; // an attribute with no values: dropped
            }
            $moved = new WC_Product_Attribute();
            $moved->set_id( $id );
            $moved->set_name( $taxonomy );
            $moved->set_options( array_values( $ids ) );
            $moved->set_position( $attr->get_position() );
            $moved->set_visible( $attr->get_visible() );
            $moved->set_variation( $attr->get_variation() );
            $new[]       = $moved;
            $map[ $key ] = [ $taxonomy, $values ];
        }
        if ( ! $map ) {
            return true;
        }
        $pick = static function ( $stored, array $values ) {
            $stored = (string) $stored;
            return $stored === '' ? '' : ( $values[ sanitize_title( $stored ) ] ?? $values[ mb_strtolower( $stored ) ] ?? null );
        };

        // Every variation's new choices, checked before anything is written.
        $copy   = [ 'time' => time(), 'attributes' => get_post_meta( $p->get_id(), '_product_attributes', true ), 'defaults' => get_post_meta( $p->get_id(), '_default_attributes', true ), 'variations' => [] ];
        $writes = [];
        foreach ( $p->is_type( 'variable' ) ? $p->get_children() : [] as $child ) {
            $variation = wc_get_product( $child );
            if ( ! $variation ) {
                continue;
            }
            $old                          = (array) $variation->get_attributes( 'edit' );
            $copy['variations'][ $child ] = $old;
            $next                         = [];
            foreach ( $old as $key => $stored ) {
                if ( ! isset( $map[ $key ] ) ) {
                    $next[ $key ] = $stored;
                    continue;
                }
                $slug = $pick( $stored, $map[ $key ][1] );
                if ( $slug === null ) {
                    return self::bad( 'One of its variants has a choice ("' . sanitize_text_field( (string) $stored ) . '") that isn\'t in the option\'s list.' );
                }
                $next[ $map[ $key ][0] ] = $slug;
            }
            $writes[] = [ $variation, $next ];
        }
        $defaults = [];
        foreach ( (array) $p->get_default_attributes( 'edit' ) as $key => $stored ) {
            if ( ! isset( $map[ $key ] ) ) {
                $defaults[ $key ] = $stored;
            } elseif ( ( $slug = $pick( $stored, $map[ $key ][1] ) ) ) {
                $defaults[ $map[ $key ][0] ] = $slug;
            }
        }

        try {
            update_post_meta( $p->get_id(), self::$tidy_copy_meta, $copy );
            $p->set_attributes( $new );
            $p->set_default_attributes( $defaults );
            $p->save();
            foreach ( $writes as [ $variation, $next ] ) {
                $variation->set_attributes( $next );
                $variation->save();
            }
            if ( $p->is_type( 'variable' ) ) {
                WC_Product_Variable::sync( $p->get_id() );
            }
            wc_delete_product_transients( $p->get_id() );
        } catch ( Exception $e ) {
            return self::bad( $e->getMessage() );
        }
        return true;
    }

    /** Puts a product back as it was before its move (the shared attributes and values it added stay in the list). */
    private static function tidy_undo( $id ) {
        $product = self::find_product( $id );
        if ( is_wp_error( $product ) ) {
            return $product;
        }
        $copy = get_post_meta( $id, self::$tidy_copy_meta, true );
        if ( ! is_array( $copy ) || ! is_array( $copy['attributes'] ?? null ) ) {
            return self::bad( 'There is nothing to put back for this product.' );
        }
        update_post_meta( $id, '_product_attributes', $copy['attributes'] );
        update_post_meta( $id, '_default_attributes', is_array( $copy['defaults'] ?? null ) ? $copy['defaults'] : [] );
        foreach ( (array) ( $copy['variations'] ?? [] ) as $child => $old ) {
            $variation = wc_get_product( (int) $child );
            if ( $variation && $variation->is_type( 'variation' ) && (int) $variation->get_parent_id() === (int) $id ) {
                $variation->set_attributes( (array) $old );
                $variation->save();
            }
        }
        delete_post_meta( $id, self::$tidy_copy_meta );
        if ( $product->is_type( 'variable' ) ) {
            WC_Product_Variable::sync( $id );
        }
        wc_delete_product_transients( $id );
        clean_post_cache( $id );
        return [ 'restored' => (int) $id ];
    }

    /* ---------------- helpers ---------------- */

    private static function attr_clean_name( $name ) {
        $name = trim( sanitize_text_field( is_scalar( $name ) ? (string) $name : '' ) );
        if ( $name === '' || mb_strlen( $name ) > 50 ) {
            return self::bad( 'Give the attribute a name, like Material (up to 50 characters).' );
        }
        return $name;
    }

    private static function attr_clean_value( $value ) {
        $value = trim( sanitize_text_field( (string) $value ) );
        if ( mb_strlen( $value ) > 100 ) {
            return self::bad( 'Values can be up to 100 characters.' );
        }
        if ( strpos( $value, WC_DELIMITER ) !== false ) {
            return self::bad( 'Values can\'t contain "' . WC_DELIMITER . '".' );
        }
        return $value;
    }

    /** A product attribute's name. A shared one's comes straight from the list: WooCommerce's label cache misses one made in this request. */
    private static function attr_label( WC_Product_Attribute $attr ) {
        if ( ! $attr->is_taxonomy() ) {
            return (string) $attr->get_name();
        }
        $shared = $attr->get_id() ? wc_get_attribute( $attr->get_id() ) : null;
        return html_entity_decode( $shared ? $shared->name : wc_attribute_label( $attr->get_name() ), ENT_QUOTES );
    }

    /** The shared attribute with this name (any letter case), or null. */
    private static function attr_by_label( $name ) {
        $lower = mb_strtolower( $name );
        foreach ( wc_get_attribute_taxonomies() as $a ) {
            if ( mb_strtolower( html_entity_decode( $a->attribute_label ?: $a->attribute_name, ENT_QUOTES ) ) === $lower ) {
                return $a;
            }
        }
        return null;
    }

    /**
     * Adds a shared attribute; its id. The key comes from the name when it's
     * plain Latin and short (WooCommerce allows 28 characters), else a short
     * made-up one (Hebrew names, for example).
     */
    private static function attr_create( $name ) {
        $base = wc_sanitize_taxonomy_name( $name );
        if ( $base === '' || strlen( $base ) > 24 || preg_match( '/[^a-z0-9_-]/', $base ) ) {
            $base = 'attr-' . substr( md5( $name . wp_rand() ), 0, 8 );
        }
        $slug = $base;
        for ( $n = 2; wc_check_if_attribute_name_is_reserved( $slug ) || taxonomy_exists( 'pa_' . $slug ) || wc_attribute_taxonomy_id_by_name( $slug ); $n++ ) {
            $slug = substr( $base, 0, 24 ) . '-' . $n;
        }
        $id = wc_create_attribute( [ 'name' => $name, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ] );
        if ( is_wp_error( $id ) ) {
            return self::bad( "\"$name\" couldn't be added: " . $id->get_error_message() );
        }
        self::attr_registered( $slug );
        return (int) $id;
    }

    /** The attribute's taxonomy, registered for this request when it was just made ($slug with or without pa_). */
    private static function attr_registered( $slug ) {
        return self::mig_attribute_taxonomy( preg_replace( '/^pa_/', '', (string) $slug ) );
    }

    /** A value's term id (added when missing). */
    private static function attr_term( $taxonomy, $value ) {
        $orders = [];
        foreach ( self::attr_terms( $taxonomy ) as $term ) {
            if ( mb_strtolower( html_entity_decode( $term->name, ENT_QUOTES ) ) === mb_strtolower( $value ) ) {
                return (int) $term->term_id;
            }
            $orders[] = get_term_meta( $term->term_id, 'order', true );
        }
        $new = wp_insert_term( $value, $taxonomy );
        if ( is_wp_error( $new ) ) {
            return self::bad( "\"$value\" couldn't be added: " . $new->get_error_message() );
        }
        // After the owner's order, and in the order they were typed (S, M, L stays S, M, L).
        // Beside values that have no order yet: last, by name, as those are.
        $next = in_array( '', $orders, true ) ? 9999 : ( $orders ? max( array_map( 'intval', $orders ) ) + 1 : 0 );
        update_term_meta( $new['term_id'], 'order', $next );
        return (int) $new['term_id'];
    }

    /** Values in the owner's order (WooCommerce's term meta "order"), then by name. */
    private static function attr_terms( $taxonomy ) {
        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 1000 ] );
        if ( is_wp_error( $terms ) ) {
            return [];
        }
        $order = [];
        foreach ( $terms as $term ) {
            $o                         = get_term_meta( $term->term_id, 'order', true );
            $order[ $term->term_id ] = $o === '' ? 9999 : (int) $o;
        }
        usort( $terms, static fn( $x, $y ) => $order[ $x->term_id ] <=> $order[ $y->term_id ] ?: strcasecmp( $x->name, $y->name ) );
        return $terms;
    }

    /** Products (not in the trash) that have any value of the attribute. */
    private static function attr_product_ids( $taxonomy ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = %s AND p.post_type = 'product' AND p.post_status <> 'trash'",
            $taxonomy
        ) );
        return array_map( 'intval', $ids );
    }

    /** term id => products (drafts too; WordPress's own count has published ones only). */
    private static function attr_value_counts( $taxonomy ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tt.term_id, COUNT(DISTINCT tr.object_id) AS n FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = %s AND p.post_type = 'product' AND p.post_status <> 'trash'
             GROUP BY tt.term_id",
            $taxonomy
        ) );
        $out = [];
        foreach ( $rows ?: [] as $row ) {
            $out[ (int) $row->term_id ] = (int) $row->n;
        }
        return $out;
    }

    private static function attr_product_count( $taxonomy ) {
        return count( self::attr_product_ids( $taxonomy ) );
    }

    /** Products that use the attribute (or one of its values) as an option: their names. */
    private static function attr_option_products( $taxonomy, $term_id = 0 ) {
        $ids   = $term_id ? get_objects_in_term( $term_id, $taxonomy ) : self::attr_product_ids( $taxonomy );
        $names = [];
        foreach ( is_wp_error( $ids ) ? [] : array_slice( $ids, 0, 3000 ) as $id ) {
            $meta = get_post_meta( (int) $id, '_product_attributes', true );
            if ( ! empty( $meta[ $taxonomy ]['is_variation'] ) && get_post_status( (int) $id ) !== 'trash' && get_post_type( (int) $id ) === 'product' ) {
                $names[] = html_entity_decode( get_the_title( (int) $id ), ENT_QUOTES );
            }
        }
        return $names;
    }

    private static function attr_products_text( array $names ) {
        $first = '"' . $names[0] . '"';
        return count( $names ) === 1 ? $first : $first . ' and ' . ( count( $names ) - 1 ) . ' more products';
    }
}

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
                $out[]  = [ 'name' => html_entity_decode( $shared ? $shared->name : wc_attribute_label( $attr->get_name() ), ENT_QUOTES ), 'values' => $values ];
            } else {
                // Made in wp-admin on this product only: it becomes shared on the next save here.
                $out[] = [ 'name' => $attr->get_name(), 'values' => array_values( $attr->get_options() ), 'own' => true ];
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
            $plan[ $lower ] = [ 'name' => $name, 'values' => array_values( $values ) ];
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
            $attr->set_visible( true );
            $attr->set_variation( false );
            $list[ $taxonomy ] = $attr;
        }
        return $list;
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
        foreach ( self::attr_terms( $taxonomy ) as $term ) {
            if ( mb_strtolower( html_entity_decode( $term->name, ENT_QUOTES ) ) === mb_strtolower( $value ) ) {
                return (int) $term->term_id;
            }
        }
        $new = wp_insert_term( $value, $taxonomy );
        if ( is_wp_error( $new ) ) {
            return self::bad( "\"$value\" couldn't be added: " . $new->get_error_message() );
        }
        update_term_meta( $new['term_id'], 'order', 9999 ); // after the owner's order
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

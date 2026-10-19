<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Loop templates: the owner's own card designs for products, categories,
 * blog posts and reviews (Store builder → Card designs).
 *
 *   options['loops']         = [ { id: lt_…, type: product|category|post|review, name, sections } ]
 *   options['loops_default'] = { product: lt_… }   the card on All products and category pages
 *
 * A card is built like any page: sections with blocks, where the kind's own
 * blocks (lp_*, lc_*, lpo_*, lr_*: 'pages' => [ 'loop_{type}' ]) show the
 * card's item. Blocks that list items (Product Grid, Featured Products,
 * Category Grid, Testimonials, Latest Blog Posts, Blog Posts Grid) pick one
 * in their `card_template` field ('' = the built-in card).
 *
 * Publishing puts the chosen card's sections into each block as `card`
 * (and shop.json / category.json as `product_card`), so the storefront
 * needs nothing else to draw it. Shared by every language for now.
 */
trait SB_Loops {

    /** id → published sections, while publishing (output_blocks() reads it). */
    private static $publish_loops = null;

    /** The loop templates, cleaned. */
    private function sanitize_loops( $raw ) {
        $out = [];
        foreach ( array_slice( array_values( is_array( $raw ) ? $raw : [] ), 0, self::MAX_LOOPS ) as $loop ) {
            if ( ! is_array( $loop ) ) continue;
            $type = (string) ( $loop['type'] ?? '' );
            if ( ! isset( self::LOOP_TYPES[ $type ] ) ) continue;
            $out[] = [
                'id'       => preg_match( '/^lt_[A-Za-z0-9]{6,20}$/', (string) ( $loop['id'] ?? '' ) ) ? $loop['id'] : self::new_id( 'lt' ),
                'type'     => $type,
                'name'     => mb_substr( sanitize_text_field( (string) ( $loop['name'] ?? '' ) ), 0, 60 ) ?: 'Card',
                'sections' => $this->sanitize_sections( $loop['sections'] ?? [], 'loop_' . $type ),
            ];
        }
        return $out;
    }

    /** { type: id } of the cards used where no block chooses (only ids of that kind). */
    private static function sanitize_loops_default( $raw, array $loops ) {
        $out = [];
        foreach ( [ 'product' ] as $type ) {
            $id = (string) ( is_array( $raw ) ? ( $raw[ $type ] ?? '' ) : '' );
            foreach ( $loops as $loop ) {
                if ( $loop['id'] === $id && $loop['type'] === $type ) $out[ $type ] = $id;
            }
        }
        return $out;
    }

    /** The templates for the dashboard, without sections (they go into `pages` as "loop_{type}@{id}"). */
    private static function platform_loops_data( array $options, array &$pages, array &$refs ) {
        $out = [];
        foreach ( (array) ( $options['loops'] ?? [] ) as $loop ) {
            $key           = 'loop_' . $loop['type'] . '@' . $loop['id'];
            $pages[ $key ] = array_values( (array) ( $loop['sections'] ?? [] ) );
            self::collect_sections_refs( $pages[ $key ], $refs );
            $out[] = [ 'id' => $loop['id'], 'type' => $loop['type'], 'name' => $loop['name'] ];
        }
        return $out;
    }

    /**
     * Before a language's configs are staged: every template's published
     * sections (their images staged like a page's), for output_blocks().
     */
    private function prepare_publish_loops( &$batch, array $options, array &$path_to_label, array &$kept_section_images ) {
        self::$publish_loops = [];
        $loops = (array) ( $options['loops'] ?? [] );
        if ( ! $loops ) return;
        $resolver = $this->github_image_resolver( $batch, 'Card designs', $path_to_label, $kept_section_images );
        $out = [];
        foreach ( $loops as $loop ) {
            $out[ $loop['id'] ] = [ 'type' => $loop['type'], 'sections' => self::output_sections( (array) ( $loop['sections'] ?? [] ), $resolver ) ];
        }
        self::$publish_loops = $out;
    }

    /** A block's published `card`: the sections of the template it chose, or null. */
    private static function published_card( $type, array $data ) {
        // The carousel has one card field per kind of item it can show.
        $field = 'card_template';
        if ( $type === 'carousel' ) {
            $source = $data['source'] ?? 'slides';
            if ( $source === 'categories' ) $field = 'category_card_template';
            elseif ( $source !== 'products' ) return null;
        }
        $id = (string) ( $data[ $field ] ?? '' );
        if ( $id === '' || empty( self::$publish_loops[ $id ] ) ) return null;
        $spec = self::get_type_schema( $type )['fields'][ $field ] ?? null;
        if ( ! $spec || self::$publish_loops[ $id ]['type'] !== ( $spec['loop'] ?? '' ) ) return null;
        return self::$publish_loops[ $id ]['sections'];
    }

    /** shop.json / category.json: the product card chosen for those pages. */
    private static function published_shop_card( array $options ) {
        $id = (string) ( $options['loops_default']['product'] ?? '' );
        if ( $id === '' || empty( self::$publish_loops[ $id ] ) || self::$publish_loops[ $id ]['type'] !== 'product' ) return null;
        return self::$publish_loops[ $id ]['sections'];
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Layouts with conditions, and the blog's template pages.
 *
 * A page in LAYOUT_PAGES has its default layout (its own `sections`) and up
 * to MAX_LAYOUTS more, each used where its conditions match: a product page
 * layout for some products or categories, a category page layout for some
 * categories, a blog layout for some blog categories. The storefront picks
 * the first layout that matches, in the owner's order.
 *
 *   options[page]['layouts'] = [ { id: lay_…, name, conditions: { products: [ids],
 *                                  categories: [ids], blog_categories: [ids] }, sections } ]
 *
 * Published in config/{page}.json as `layouts`, each condition list with the
 * slugs too (the storefront matches category pages by their address).
 *
 * The template pages (TEMPLATE_PAGES: blog, blog_post) are built from
 * sections with dynamic blocks; until the owner saves one, it starts from
 * default_template_sections() (the storefront has the same default).
 */
trait SB_Layouts {

    /** Every page that keeps a `sections` list. */
    private static function sectionable_pages() {
        return array_merge( [ 'home' ], array_keys( self::PAGE_SECTION_LOCATIONS ), self::TEMPLATE_PAGES );
    }

    /** A template page's starting sections (like the storefront's built-in layout). */
    public static function default_template_sections( $page ) {
        $block   = static fn( $type, array $data = [] ) => [ 'id' => self::new_id( 'blk' ), 'type' => $type, 'label' => '', 'enabled' => true, 'style' => [], 'data' => $data ];
        $section = static fn( array $blocks, array $style ) => [ 'id' => self::new_id( 'sec' ), 'label' => '', 'enabled' => true, 'style' => $style, 'blocks' => $blocks ];
        if ( $page === 'blog_post' ) {
            $raw = [ $section( [
                $block( 'post_back' ),
                $block( 'post_meta' ),
                $block( 'post_title' ),
                $block( 'post_featured_image' ),
                $block( 'post_content' ),
                $block( 'post_more' ),
            ], [ 'width_mode' => 'custom', 'width' => [ 'desktop' => '760px' ], 'padding_preset' => 'small', 'gap' => [ 'desktop' => '16px' ] ] ) ];
        } elseif ( $page === 'blog' ) {
            $raw = [ $section( [
                $block( 'blog_title' ),
                $block( 'blog_categories' ),
                $block( 'blog_posts' ),
            ], [ 'padding_preset' => 'small', 'gap' => [ 'desktop' => '20px' ] ] ) ];
        } else {
            return [];
        }
        return self::instance()->sanitize_sections( $raw, $page );
    }

    /** A page's sections as the editor starts them: the saved ones, or a template page's defaults. */
    private static function editor_sections( array $options, $page ) {
        if ( isset( $options[ $page ]['sections'] ) ) {
            return array_values( (array) $options[ $page ]['sections'] );
        }
        return in_array( $page, self::TEMPLATE_PAGES, true ) ? self::default_template_sections( $page ) : [];
    }

    /** { terms, privacy }: the published legal pages' addresses ("/terms"), '' when not published. */
    private static function legal_page_paths( array $options ) {
        $out = [ 'terms' => '', 'privacy' => '' ];
        foreach ( self::published_page_map( $options ) as $page ) {
            $role = (string) ( $page['role'] ?? '' );
            if ( isset( $out[ $role ] ) && $out[ $role ] === '' ) {
                $out[ $role ] = '/' . $page['path'];
            }
        }
        return $out;
    }

    /** { id: name } of the blog categories (not WordPress's default one), for the layout conditions. */
    private static function blog_category_labels() {
        $terms = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false, 'exclude' => [ (int) get_option( 'default_category' ) ], 'number' => 500 ] );
        $out   = [];
        foreach ( is_wp_error( $terms ) ? [] : $terms as $t ) {
            $out[ (int) $t->term_id ] = html_entity_decode( $t->name, ENT_QUOTES );
        }
        return (object) $out;
    }

    /* ---------------- layouts ---------------- */

    /** A page's layouts, cleaned: [ { id, name, conditions, sections } ]. */
    private function sanitize_layouts( $raw, $page ) {
        $kinds = self::LAYOUT_PAGES[ $page ] ?? [];
        $out   = [];
        foreach ( array_slice( array_values( is_array( $raw ) ? $raw : [] ), 0, self::MAX_LAYOUTS ) as $layout ) {
            if ( ! is_array( $layout ) ) continue;
            $conditions = [];
            foreach ( $kinds as $kind ) {
                $ids = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $layout['conditions'][ $kind ] ?? [] ) ), static fn( $id ) => $id > 0 ) ) );
                $conditions[ $kind ] = array_slice( $ids, 0, 200 );
            }
            $out[] = [
                'id'         => preg_match( '/^lay_[A-Za-z0-9]{6,20}$/', (string) ( $layout['id'] ?? '' ) ) ? $layout['id'] : self::new_id( 'lay' ),
                'name'       => mb_substr( sanitize_text_field( (string) ( $layout['name'] ?? '' ) ), 0, 60 ) ?: 'Layout',
                'conditions' => $conditions,
                'sections'   => $this->sanitize_sections( $layout['sections'] ?? [], $page ),
            ];
        }
        return $out;
    }

    /** The layouts for the dashboard, without sections (they go into `pages` as "page@id"). */
    private static function platform_layouts_data( array $options, array &$pages, array &$refs ) {
        $out = [];
        foreach ( array_keys( self::LAYOUT_PAGES ) as $page ) {
            $out[ $page ] = [];
            foreach ( (array) ( $options[ $page ]['layouts'] ?? [] ) as $layout ) {
                $pages[ $page . '@' . $layout['id'] ] = array_values( (array) ( $layout['sections'] ?? [] ) );
                self::collect_sections_refs( $pages[ $page . '@' . $layout['id'] ], $refs );
                foreach ( (array) ( $layout['conditions']['products'] ?? [] ) as $id ) $refs['products'][ (int) $id ] = true;
                foreach ( (array) ( $layout['conditions']['categories'] ?? [] ) as $id ) $refs['categories'][ (int) $id ] = true;
                $out[ $page ][] = [ 'id' => $layout['id'], 'name' => $layout['name'], 'conditions' => (array) $layout['conditions'] ];
            }
        }
        return $out;
    }

    /**
     * What the storefront gets: each layout with its sections and, for every
     * condition list, the ids and (categories) the slugs.
     */
    private static function published_layouts( array $layouts, callable $image_cb ) {
        $out = [];
        foreach ( $layouts as $layout ) {
            $conditions = [];
            foreach ( (array) ( $layout['conditions'] ?? [] ) as $kind => $ids ) {
                $ids = array_values( array_map( 'intval', (array) $ids ) );
                $conditions[ $kind ] = $ids;
                $taxonomy = [ 'categories' => 'product_cat', 'blog_categories' => 'category' ][ $kind ] ?? '';
                if ( $taxonomy !== '' && $ids ) {
                    $slugs = [];
                    foreach ( $ids as $id ) {
                        $term = get_term( $id, $taxonomy );
                        if ( $term && ! is_wp_error( $term ) ) $slugs[] = urldecode( $term->slug );
                    }
                    $conditions[ $kind . '_slugs' ] = $slugs;
                }
            }
            $out[] = [
                'id'         => $layout['id'],
                'name'       => $layout['name'],
                'conditions' => $conditions,
                'sections'   => self::output_sections( (array) ( $layout['sections'] ?? [] ), $image_cb ),
            ];
        }
        return $out;
    }
}

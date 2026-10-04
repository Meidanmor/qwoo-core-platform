<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Server side of the section builder UI.
 *
 * The builder itself (sections, blocks, every field control) is rendered
 * entirely in JS from the field schema — see assets/admin/js/shop-builder/
 * sb-builder.js + sb-builder-fields.js. PHP only outputs a mount point per
 * page and localizes what the JS can't know on its own: the stored
 * sections, and display data for everything they reference (image
 * thumbnails, product and category names).
 */
trait SB_Admin_Fields {

    /**
     * A small "type a slug, open a preview of that exact entity" control for
     * hookable pages without a single canonical URL (Category, Product).
     * `{slug}` is filled in client-side (bindEntityPreviewLinks() in sb-core.js).
     */
    private function render_entity_preview_control( $route_template, $placeholder ) {
        ?>
        <div class="qwoo-entity-preview">
            <input type="text" class="qwoo-entity-preview-input" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
            <button type="button" class="button qwoo-entity-preview-btn" data-route-template="<?php echo esc_attr( $route_template ); ?>">
                Preview with this slug &#8599;
            </button>
        </div>
        <?php
    }

    /** One builder mount point. JS fills it from shopBuilder.pages[$page_slug]. */
    private function render_builder_mount( $page_slug ) {
        ?>
        <div class="qwoo-builder" data-page="<?php echo esc_attr( $page_slug ); ?>">
            <p class="qwoo-builder-loading">Loading builder…</p>
        </div>
        <?php
    }

    /**
     * A hooks-style tab body (Shop, Category, Product): heading, description,
     * optional entity preview, and the builder (which renders one drop zone
     * per location in PAGE_SECTION_LOCATIONS[$page_slug]).
     */
    private function render_hooks_tab_content( $page_slug, $heading, $description, $entity_route_template = null, $entity_placeholder = '' ) {
        ?>
        <h2><?php echo esc_html( $heading ); ?></h2>
        <p class="description"><?php echo wp_kses_post( $description ); ?></p>

        <?php if ( $entity_route_template ) : ?>
            <?php $this->render_entity_preview_control( $entity_route_template, $entity_placeholder ); ?>
        <?php endif; ?>

        <?php $this->render_builder_mount( $page_slug ); ?>
        <?php
    }

    /* -------------------------------------------------------------
       Data localized for the JS builder
       ------------------------------------------------------------- */

    /** Walks a data object by its specs, collecting referenced IDs. */
    private static function collect_field_refs( array $specs, $data, array &$refs ) {
        $data = is_array( $data ) ? $data : [];
        foreach ( $specs as $key => $spec ) {
            $value = $data[ $key ] ?? null;
            switch ( $spec['type'] ?? '' ) {
                case 'image':
                case 'video':
                    if ( $value ) $refs['media'][ (int) $value ] = true;
                    break;
                case 'products':
                    foreach ( (array) $value as $id ) $refs['products'][ (int) $id ] = true;
                    break;
                case 'categories':
                    foreach ( (array) $value as $id ) $refs['categories'][ (int) $id ] = true;
                    break;
                case 'tags':
                    foreach ( (array) $value as $id ) $refs['tags'][ (int) $id ] = true;
                    break;
                case 'repeater':
                    foreach ( (array) $value as $item ) self::collect_field_refs( $spec['fields'] ?? [], $item, $refs );
                    break;
            }
        }
    }

    private static function collect_block_refs( array $blocks, array &$refs ) {
        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) continue;
            if ( ( $block['type'] ?? '' ) === 'section' ) {
                self::collect_field_refs( self::SECTION_STYLE_FIELDS, $block['style'] ?? [], $refs );
                self::collect_block_refs( (array) ( $block['blocks'] ?? [] ), $refs );
                continue;
            }
            $schema = self::get_type_schema( $block['type'] ?? '' );
            if ( $schema ) self::collect_field_refs( $schema['fields'], $block['data'] ?? [], $refs );
            // Block background image / video / poster.
            self::collect_field_refs( self::BLOCK_COMMON_FIELDS, $block['style'] ?? [], $refs );
        }
    }

    /** Admin display payload for one attachment (full URL + thumbnail + size). */
    private static function admin_media_payload( $attachment_id ) {
        $url = wp_get_attachment_url( $attachment_id );
        if ( ! $url ) return null;
        $meta     = wp_get_attachment_metadata( $attachment_id );
        $is_image = strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ) === 0;
        // Videos get no thumbnail (the admin shows their file name instead).
        $thumb    = $is_image ? ( wp_get_attachment_image_url( $attachment_id, 'medium' ) ?: $url ) : '';
        return [
                'url'    => $url,
                'thumb'  => $thumb,
                'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : null,
                'height' => isset( $meta['height'] ) ? (int) $meta['height'] : null,
        ];
    }

    /**
     * Everything the JS builder needs about the stored sections:
     *   pages:  { home: [...sections], shop: [...], ... }   (stored v3 shape)
     *   media:  { attachmentId: {url, thumb, width, height} }
     *   labels: { products: {id: name}, categories: {id: name} }
     */
    private static function builder_bootstrap_data() {
        $options = get_option( 'shop_builder_options', [] );
        $pages   = [];
        $refs    = [ 'media' => [], 'products' => [], 'categories' => [], 'tags' => [] ];

        foreach ( self::sectionable_pages() as $page_slug ) {
            $sections = array_values( (array) ( $options[ $page_slug ]['sections'] ?? [] ) );
            $pages[ $page_slug ] = $sections;
            self::collect_sections_refs( $sections, $refs );
        }

        return [ 'pages' => (object) $pages ] + self::refs_payload( $refs );
    }

    /** Collects referenced IDs from a list of sections (top-level shape). */
    private static function collect_sections_refs( array $sections, array &$refs ) {
        foreach ( $sections as $section ) {
            if ( ! is_array( $section ) ) continue;
            self::collect_field_refs( self::SECTION_STYLE_FIELDS, $section['style'] ?? [], $refs );
            self::collect_block_refs( (array) ( $section['blocks'] ?? [] ), $refs );
        }
    }

    /**
     * Admin display data for collected references: media previews and
     * product/category/tag names. Used by the bootstrap data and whenever
     * sections are loaded later (restoring a version, inserting a template).
     */
    private static function refs_payload( array $refs ) {
        $refs  = $refs + [ 'media' => [], 'products' => [], 'categories' => [], 'tags' => [] ];
        $media = [];
        foreach ( array_keys( $refs['media'] ) as $id ) {
            $payload = self::admin_media_payload( $id );
            if ( $payload ) $media[ $id ] = $payload;
        }

        $products = [];
        if ( $refs['products'] && function_exists( 'wc_get_product' ) ) {
            foreach ( array_keys( $refs['products'] ) as $id ) {
                $product = wc_get_product( $id );
                if ( $product ) $products[ $id ] = $product->get_name();
            }
        }

        $categories = [];
        foreach ( array_keys( $refs['categories'] ) as $id ) {
            $term = get_term( $id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) $categories[ $id ] = $term->name;
        }

        $tags = [];
        foreach ( array_keys( $refs['tags'] ) as $id ) {
            $term = get_term( $id, 'product_tag' );
            if ( $term && ! is_wp_error( $term ) ) $tags[ $id ] = $term->name;
        }

        return [
                // Force JSON objects (not arrays) for ID-keyed maps even when empty.
                'media'  => (object) $media,
                'labels' => [ 'products' => (object) $products, 'categories' => (object) $categories, 'tags' => (object) $tags ],
        ];
    }
}

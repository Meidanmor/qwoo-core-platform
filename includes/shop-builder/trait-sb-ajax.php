<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * All wp_ajax_ callbacks registered in the constructor: product/category
 * search (used by Select2 fields), icon set generation, and the "Save
 * Draft" autosave endpoint.
 */
trait SB_Ajax {

    /* -------------------------
       Product Search AJAX
       ------------------------- */
    public function add_featured_products_search_ajax() {
        check_ajax_referer( 'shop_builder_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $term = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : '';

        $products = wc_get_products( [
                'limit'  => 20,
                'status' => 'publish',
                's'      => $term,
        ] );

        $results = [];
        foreach ( $products as $product ) {
            $results[] = [
                    'id'    => $product->get_id(),
                    'text'  => $product->get_name(),
                    'thumb' => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' )
            ];
        }

        wp_send_json( $results );
    }

    /* -------------------------
       Category Search AJAX (for the Category Grid section)
       ------------------------- */
    public function ajax_category_search() {
        check_ajax_referer( 'shop_builder_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $term = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : '';

        $terms = get_terms( [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'name__like' => $term,
                'number'     => 20,
        ] );

        $results = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term_obj ) {
                $thumb_id = get_term_meta( $term_obj->term_id, 'thumbnail_id', true );
                $results[] = [
                        'id'    => $term_obj->term_id,
                        'text'  => $term_obj->name,
                        'thumb' => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '',
                ];
            }
        }

        wp_send_json( $results );
    }

    /* -------------------------
       Product Tag Search AJAX (Product Grid / Carousel "With tags" queries)
       ------------------------- */
    public function ajax_tag_search() {
        check_ajax_referer( 'shop_builder_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $term  = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
        $terms = get_terms( [
                'taxonomy'   => 'product_tag',
                'hide_empty' => false,
                'name__like' => $term,
                'number'     => 20,
        ] );

        $results = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term_obj ) {
                $results[] = [ 'id' => $term_obj->term_id, 'text' => $term_obj->name . ' (' . $term_obj->count . ')', 'thumb' => '' ];
            }
        }

        wp_send_json( $results );
    }

    /* -------------------------
       Icon set generation + sync AJAX
       ------------------------- */
    public function ajax_generate_and_sync_icons() {
        check_ajax_referer( 'shop_builder_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $options = get_option( 'shop_builder_options', [] );
        $icon_attachment_id = intval( $options['branding']['app_icon_id'] ?? 0 );

        if ( ! $icon_attachment_id ) {
            wp_send_json_error( 'No App Icon has been set yet. Upload one in the Branding tab and save first.' );
        }

        require_once __DIR__ . '/../class-icon-generator.php';

        $result = Qwoo_Icon_Generator::generate_from_attachment( $icon_attachment_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $sync = Qwoo_Icon_Generator::sync_to_github( $result['files'] );

        if ( $sync === true ) {
            $msg = 'Icon set generated and pushed to GitHub.';
            if ( ! empty( $result['warnings'] ) ) {
                $msg .= ' Note: ' . implode( ' ', $result['warnings'] );
            }
            wp_send_json_success( $msg );
        } elseif ( $sync === 'no_changes' ) {
            wp_send_json_success( 'Icon set unchanged — nothing to push.' );
        } else {
            wp_send_json_error( 'Failed to push icon set to GitHub. Check the error log.' );
        }
    }

    /* -------------------------
       Save Draft (AJAX)
       ------------------------- */
    public function ajax_save_settings() {
        check_ajax_referer( 'shop_builder_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! isset( $_POST['shop_builder_options'] ) ) {
            wp_send_json_error( 'No data received' );
        }

        $input = wp_unslash( $_POST['shop_builder_options'] );
        if ( ! is_array( $input ) ) $input = [];

        // The section builder doesn't post its fields as form inputs (a
        // large page would blow past PHP's max_input_vars and get silently
        // truncated) — it sends its whole state as one JSON string instead:
        // { "home": [...sections], "shop": [...], ... }.
        if ( isset( $_POST['sb_sections'] ) ) {
            $pages = json_decode( wp_unslash( $_POST['sb_sections'] ), true );
            if ( ! is_array( $pages ) ) {
                wp_send_json_error( 'The section builder data could not be read. Nothing was saved.' );
            }
            foreach ( self::sectionable_pages() as $page_slug ) {
                if ( isset( $pages[ $page_slug ] ) && is_array( $pages[ $page_slug ] ) ) {
                    $input[ $page_slug ]['sections'] = $pages[ $page_slug ];
                }
            }
        }

        $this->save_input( $input );
        wp_send_json_success( 'Draft saved successfully!' );
    }

    /**
     * Sanitizes and stores a draft (option groups + page sections), and
     * records a version. Shared by Save Draft and the platform dashboard.
     */
    public function save_input( array $input ) {
        $new_options      = $this->sanitize_options( $input );
        $existing_options = get_option( 'shop_builder_options', [] );
        $updated_options  = array_replace_recursive( is_array( $existing_options ) ? $existing_options : [], $new_options );

        // array_replace_recursive merges numeric-keyed lists element by
        // element — force a full replace for every list so deletions and
        // reorders from the client are respected.
        foreach ( self::sectionable_pages() as $page_slug ) {
            if ( isset( $new_options[ $page_slug ]['sections'] ) ) {
                $updated_options[ $page_slug ]['sections'] = $new_options[ $page_slug ]['sections'];
            }
        }
        if ( isset( $new_options['header']['navigation'] ) ) {
            $updated_options['header']['navigation'] = $new_options['header']['navigation'];
        }
        if ( isset( $new_options['contact']['methods'] ) ) {
            $updated_options['contact']['methods'] = $new_options['contact']['methods'];
        }
        if ( isset( $new_options['custom_pages'] ) ) {
            $updated_options['custom_pages'] = $new_options['custom_pages'];
        }

        update_option( 'shop_builder_options', $updated_options );
        self::record_revision( $updated_options );
        return $updated_options;
    }
}

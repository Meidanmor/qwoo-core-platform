<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Form block submissions.
 *
 *   POST /wp-json/qwoo/v1/forms/submit
 *   { page, block_id, values: { key: value, ... }, hp }
 *
 * The request only identifies WHICH form was submitted (page + block id);
 * everything else — which fields exist, which are required, allowed
 * options, and where the email goes — is read from the saved Shop Builder
 * options on the server, never trusted from the client. That is also why
 * a form's recipient email is a `private` field and never published.
 *
 * Every accepted submission is stored as a `qwoo_form_entry` post (listed
 * under Q-Woo Settings -> Form Entries); contact/custom forms are also
 * emailed to the form's recipient (or the site admin email).
 */
trait SB_Forms {

    public function register_form_entry_post_type() {
        register_post_type( self::FORM_ENTRY_POST_TYPE, [
                'labels'          => [
                        'name'          => 'Form Entries',
                        'singular_name' => 'Form Entry',
                        'menu_name'     => 'Form Entries',
                        'all_items'     => 'Form Entries',
                        'edit_item'     => 'View Form Entry',
                        'search_items'  => 'Search Entries',
                        'not_found'     => 'No form entries yet.',
                ],
                'public'          => false,
                'show_ui'         => true,
                'show_in_menu'    => 'qwoo-settings',
                'show_in_rest'    => false,
                'supports'        => [ 'title', 'editor' ],
                'capability_type' => 'post',
                'capabilities'    => [ 'create_posts' => 'do_not_allow' ],
                'map_meta_cap'    => true,
        ] );
    }

    public function form_entry_columns( $columns ) {
        return [
                'cb'         => $columns['cb'] ?? '',
                'title'      => 'Entry',
                'qwoo_form'  => 'Form',
                'qwoo_page'  => 'Page',
                'date'       => 'Date',
        ];
    }

    public function form_entry_column_content( $column, $post_id ) {
        $meta = get_post_meta( $post_id, '_qwoo_form', true );
        if ( ! is_array( $meta ) ) return;
        if ( $column === 'qwoo_form' ) echo esc_html( ucfirst( $meta['form_type'] ?? '' ) );
        if ( $column === 'qwoo_page' ) echo esc_html( ucfirst( $meta['page'] ?? '' ) );
    }

    public function register_form_submit_endpoint() {
        register_rest_route( 'qwoo/v1', '/forms/submit', [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_form_submit' ],
                'permission_callback' => '__return_true',
        ] );
    }

    /** Finds an enabled form block by id anywhere in a page's sections tree. */
    private static function find_form_block( array $blocks_or_sections, $block_id ) {
        foreach ( $blocks_or_sections as $node ) {
            if ( ! is_array( $node ) || isset( $node['enabled'] ) && ! $node['enabled'] ) continue;
            if ( ( $node['type'] ?? '' ) === 'form' && ( $node['id'] ?? '' ) === $block_id ) return $node;
            if ( ! empty( $node['blocks'] ) ) {
                $found = self::find_form_block( $node['blocks'], $block_id );
                if ( $found ) return $found;
            }
        }
        return null;
    }

    /**
     * The same search over everything the Store builder saved: a form can also sit in a
     * layout, a popup, a drawer (a block that holds sections), a custom page or a card
     * design, where the storefront names the place ("popup", "drawer") and not a saved page.
     * Anything turned off on the way down is skipped. Block ids are random, so one id is one form.
     */
    private static function find_form_anywhere( array $tree, $block_id, int $depth = 0 ) {
        if ( $depth > 40 ) return null;
        if ( isset( $tree['enabled'] ) && ! $tree['enabled'] ) return null;
        if ( ( $tree['type'] ?? '' ) === 'form' && ( $tree['id'] ?? '' ) === $block_id ) return $tree;
        foreach ( $tree as $child ) {
            if ( ! is_array( $child ) ) continue;
            $found = self::find_form_anywhere( $child, $block_id, $depth + 1 );
            if ( $found ) return $found;
        }
        return null;
    }

    /**
     * The field definitions a form accepts. Newsletter/contact forms have a
     * fixed shape (mirrored in the frontend's FormBlock.vue); custom forms
     * use the fields configured in the builder.
     */
    public static function form_field_definitions( array $data ) {
        switch ( $data['form_type'] ?? 'newsletter' ) {
            case 'contact':
                return [
                        [ 'key' => 'name',    'label' => 'Name',    'field_type' => 'text',     'required' => true,  'options' => '' ],
                        [ 'key' => 'email',   'label' => 'Email',   'field_type' => 'email',    'required' => true,  'options' => '' ],
                        [ 'key' => 'phone',   'label' => 'Phone',   'field_type' => 'tel',      'required' => false, 'options' => '' ],
                        [ 'key' => 'message', 'label' => 'Message', 'field_type' => 'textarea', 'required' => true,  'options' => '' ],
                ];
            case 'custom':
                return array_values( (array) ( $data['fields'] ?? [] ) );
            default: // newsletter
                $fields = [];
                if ( ! empty( $data['collect_name'] ) ) {
                    $fields[] = [ 'key' => 'name', 'label' => 'Name', 'field_type' => 'text', 'required' => false, 'options' => '' ];
                }
                $fields[] = [ 'key' => 'email', 'label' => 'Email', 'field_type' => 'email', 'required' => true, 'options' => '' ];
                return $fields;
        }
    }

    /**
     * Validates one submitted value against its field definition.
     * Returns [clean_value, error_message|null].
     */
    private static function validate_form_value( array $field, $raw ) {
        $type     = $field['field_type'] ?? 'text';
        $required = ! empty( $field['required'] );
        $label    = $field['label'] ?? 'This field';
        $options  = array_values( array_filter( array_map( 'trim', explode( "\n", (string) ( $field['options'] ?? '' ) ) ), 'strlen' ) );

        if ( $type === 'checkbox' ) {
            $value = ! empty( $raw ) && $raw !== 'false';
            return [ $value ? 'Yes' : 'No', ( $required && ! $value ) ? "Please check \"{$label}\"." : null ];
        }

        if ( $type === 'checkboxes' ) {
            $values = array_values( array_intersect( array_map( 'strval', (array) $raw ), $options ) );
            return [ implode( ', ', $values ), ( $required && ! $values ) ? "Please choose at least one option for \"{$label}\"." : null ];
        }

        $value = is_scalar( $raw ) ? trim( (string) $raw ) : '';
        $value = $type === 'textarea' ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
        $value = mb_substr( $value, 0, $type === 'textarea' ? 5000 : 500 );

        if ( $value === '' ) {
            return [ '', $required ? "\"{$label}\" is required." : null ];
        }

        switch ( $type ) {
            case 'email':
                return is_email( $value ) ? [ $value, null ] : [ $value, 'Please enter a valid email address.' ];
            case 'url':
                $url = esc_url_raw( $value, [ 'http', 'https' ] );
                return $url ? [ $url, null ] : [ $value, 'Please enter a valid URL.' ];
            case 'number':
                return is_numeric( $value ) ? [ $value, null ] : [ $value, "\"{$label}\" must be a number." ];
            case 'tel':
                return preg_match( '/^[0-9+().\s-]{5,30}$/', $value ) ? [ $value, null ] : [ $value, 'Please enter a valid phone number.' ];
            case 'date':
                $d = DateTime::createFromFormat( 'Y-m-d', $value );
                return ( $d && $d->format( 'Y-m-d' ) === $value ) ? [ $value, null ] : [ $value, 'Please enter a valid date.' ];
            case 'select':
            case 'radio':
                return in_array( $value, $options, true ) ? [ $value, null ] : [ $value, "Please choose a valid option for \"{$label}\"." ];
        }

        return [ $value, null ];
    }

    public function rest_form_submit( WP_REST_Request $request ) {
        $params   = $request->get_json_params() ?: $request->get_params();
        $page     = sanitize_key( $params['page'] ?? '' );
        $block_id = is_string( $params['block_id'] ?? null ) ? $params['block_id'] : '';
        $values   = is_array( $params['values'] ?? null ) ? $params['values'] : [];

        // Honeypot filled in = a bot. Pretend it worked, store nothing.
        if ( ! empty( $params['hp'] ) ) {
            return rest_ensure_response( [ 'success' => true, 'message' => 'Thanks!' ] );
        }

        $limited = function_exists( 'qwoo_rate_limit_check' ) ? qwoo_rate_limit_check( 'form_submit', 5, 10 * MINUTE_IN_SECONDS ) : true;
        if ( is_wp_error( $limited ) ) return $limited;

        if ( ! preg_match( '/^blk_[a-zA-Z0-9]{6,20}$/', $block_id ) ) {
            return new WP_Error( 'qwoo_form_not_found', 'This form is no longer available.', [ 'status' => 404 ] );
        }

        // The page's own sections first; then every other place a form can sit (layouts, popups, drawers, custom pages).
        $options = (array) get_option( 'shop_builder_options', [] );
        $block   = in_array( $page, self::sectionable_pages(), true ) ? self::find_form_block( (array) ( $options[ $page ]['sections'] ?? [] ), $block_id ) : null;
        if ( ! $block ) {
            $block = self::find_form_anywhere( $options, $block_id );
        }
        if ( ! $block ) {
            return new WP_Error( 'qwoo_form_not_found', 'This form is no longer available.', [ 'status' => 404 ] );
        }

        $data      = $block['data'] ?? [];
        $form_type = $data['form_type'] ?? 'newsletter';
        $fields    = self::form_field_definitions( $data );

        $clean  = [];
        $errors = [];
        $reply_to = '';
        foreach ( $fields as $field ) {
            $key = $field['key'] ?? '';
            if ( $key === '' ) continue;
            [ $value, $error ] = self::validate_form_value( $field, $values[ $key ] ?? null );
            if ( $error ) $errors[ $key ] = $error;
            $clean[ $key ] = [ 'label' => $field['label'] ?? $key, 'value' => $value ];
            if ( ! $reply_to && ( $field['field_type'] ?? '' ) === 'email' && is_email( $value ) ) $reply_to = $value;
        }

        if ( $errors ) {
            return new WP_Error( 'qwoo_form_invalid', 'Please fix the highlighted fields.', [ 'status' => 422, 'fields' => $errors ] );
        }

        $success_message = ( $data['success_message'] ?? '' ) ?: 'Thanks! We got your message.';

        // Newsletter: one entry per email address.
        if ( $form_type === 'newsletter' && $reply_to ) {
            $existing = get_posts( [
                    'post_type'      => self::FORM_ENTRY_POST_TYPE,
                    'post_status'    => 'any',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => [
                            [ 'key' => '_qwoo_form_type', 'value' => 'newsletter' ],
                            [ 'key' => '_qwoo_form_email', 'value' => strtolower( $reply_to ) ],
                    ],
            ] );
            // The Form block's own wording (its button or message) is the record of what they signed up for.
            Qwoo_Email_Marketing::subscribe( strtolower( $reply_to ), '', 'form', 'Signed up with the newsletter form', Qwoo_Push_Engine::request_lang() );
            if ( $existing ) {
                return rest_ensure_response( [ 'success' => true, 'message' => $success_message ] );
            }
        }

        $lines = [];
        foreach ( $clean as $item ) {
            $lines[] = $item['label'] . ': ' . ( $item['value'] === '' ? '—' : $item['value'] );
        }
        $body = implode( "\n", $lines );

        $type_labels = [ 'newsletter' => 'Newsletter signup', 'contact' => 'Contact form', 'custom' => 'Form' ];
        $post_id = wp_insert_post( [
                'post_type'    => self::FORM_ENTRY_POST_TYPE,
                'post_status'  => 'private',
                'post_title'   => ( $type_labels[ $form_type ] ?? 'Form' ) . ( $reply_to ? " — {$reply_to}" : '' ),
                'post_content' => $body,
        ], true );

        if ( ! is_wp_error( $post_id ) ) {
            update_post_meta( $post_id, '_qwoo_form', [
                    'page'      => $page,
                    'block_id'  => $block_id,
                    'form_type' => $form_type,
                    'fields'    => $clean,
            ] );
            update_post_meta( $post_id, '_qwoo_form_type', $form_type );
            if ( $reply_to ) update_post_meta( $post_id, '_qwoo_form_email', strtolower( $reply_to ) );
        } else {
            error_log( 'Qwoo: failed to store form entry — ' . $post_id->get_error_message() );
        }

        // "Collect in your Inbox": the message becomes a conversation the owner answers from the dashboard
        // (Customers → Inbox), who is told on their phone and by email there. It needs the shopper's email.
        if ( $form_type !== 'newsletter' && ! empty( $data['to_inbox'] ) && $reply_to && class_exists( 'Qwoo_Inbox' ) && Qwoo_Inbox::from_form( $clean, $reply_to, $page, $data ) ) {
            return rest_ensure_response( [ 'success' => true, 'message' => $success_message ] );
        }

        if ( $form_type !== 'newsletter' ) {
            $to      = is_email( $data['recipient_email'] ?? '' ) ? $data['recipient_email'] : get_option( 'admin_email' );
            $subject = ( $data['email_subject'] ?? '' ) ?: Qwoo_I18n::t( 'New form submission' );
            $headers = $reply_to ? [ 'Reply-To: ' . $reply_to ] : [];
            if ( ! wp_mail( $to, $subject, $body . "\n\n" . Qwoo_I18n::t( '— Sent from the "{page}" page form.', [ 'page' => $page ] ), $headers ) ) {
                error_log( "Qwoo: could not email form submission for block {$block_id}. The entry is still saved under Form Entries." );
            }
        }

        return rest_ensure_response( [ 'success' => true, 'message' => $success_message ] );
    }
}

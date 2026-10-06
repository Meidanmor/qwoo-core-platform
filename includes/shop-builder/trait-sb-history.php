<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Builder history: saved versions and section templates.
 *
 *   Versions   Every Save Draft that changes the builder's sections stores
 *              a snapshot of all pages' sections (last MAX_REVISIONS kept,
 *              newest first). A pushed version is flagged. Restoring loads
 *              the snapshot into the builder as unsaved changes — nothing is
 *              overwritten until the user saves.
 *
 *   Templates  A section saved under a name, insertable on any page as an
 *              independent copy (fresh ids; editing the copy doesn't change
 *              the template or other copies).
 *
 * Both live in their own non-autoloaded options, so they never weigh on
 * normal page loads. All endpoints: shop_builder_nonce + manage_options.
 */
trait SB_History {

    private function history_guard() {
        check_ajax_referer( 'shop_builder_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
    }

    /** The posted version/template id (mixed case, so not sanitize_key()). */
    private static function posted_id() {
        $id = isset( $_POST['id'] ) && is_string( $_POST['id'] ) ? wp_unslash( $_POST['id'] ) : '';
        return preg_match( '/^(rev|tpl)_[A-Za-z0-9]{6,20}$/', $id ) ? $id : '';
    }

    /* =====================================================================
       Versions
       ===================================================================== */

    private static function get_revisions() {
        $list = get_option( self::REVISIONS_OPTION, [] );
        return is_array( $list ) ? array_values( $list ) : [];
    }

    /** Snapshot of every page's sections, as stored. */
    private static function sections_snapshot( array $options ) {
        $pages = [];
        foreach ( self::sectionable_pages() as $page_slug ) {
            $pages[ $page_slug ] = array_values( (array) ( $options[ $page_slug ]['sections'] ?? [] ) );
        }
        // The owner's pages, by page id.
        foreach ( self::custom_pages_of( $options ) as $page ) {
            $pages[ $page['id'] ] = array_values( (array) ( $page['sections'] ?? [] ) );
        }
        return $pages;
    }

    /** Called after a successful save: stores a version if the sections changed. */
    private static function record_revision( array $options ) {
        $pages = self::sections_snapshot( $options );
        $hash  = md5( wp_json_encode( $pages ) );
        $list  = self::get_revisions();

        if ( $list && ( $list[0]['hash'] ?? '' ) === $hash ) return;

        $user = wp_get_current_user();
        array_unshift( $list, [
                'id'     => self::new_id( 'rev' ),
                'time'   => time(),
                'user'   => $user && $user->exists() ? $user->display_name : '',
                'hash'   => $hash,
                'pushed' => 0,
                'pages'  => $pages,
        ] );

        update_option( self::REVISIONS_OPTION, array_slice( $list, 0, self::MAX_REVISIONS ), false );
    }

    /** Called after a successful push: flags the version that is now live. */
    private static function mark_revision_pushed() {
        $list = self::get_revisions();
        if ( ! $list ) return;
        $live = md5( wp_json_encode( self::sections_snapshot( get_option( 'shop_builder_options', [] ) ) ) );
        foreach ( $list as $i => $rev ) {
            if ( ( $rev['hash'] ?? '' ) === $live ) {
                $list[ $i ]['pushed'] = time();
                update_option( self::REVISIONS_OPTION, $list, false );
                return;
            }
        }
    }

    public function ajax_list_revisions() {
        $this->history_guard();

        $out = [];
        foreach ( self::get_revisions() as $rev ) {
            $counts = [];
            foreach ( (array) ( $rev['pages'] ?? [] ) as $page => $sections ) {
                $counts[ $page ] = count( (array) $sections );
            }
            $out[] = [
                    'id'     => $rev['id'],
                    'time'   => (int) $rev['time'],
                    'date'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $rev['time'] ),
                    'ago'    => human_time_diff( (int) $rev['time'] ) . ' ago',
                    'user'   => $rev['user'] ?? '',
                    'pushed' => ! empty( $rev['pushed'] ),
                    'counts' => $counts,
            ];
        }
        wp_send_json_success( $out );
    }

    public function ajax_get_revision() {
        $this->history_guard();

        $id = self::posted_id();
        foreach ( self::get_revisions() as $rev ) {
            if ( ( $rev['id'] ?? '' ) !== $id ) continue;

            $refs  = [];
            $pages = (array) ( $rev['pages'] ?? [] );
            foreach ( $pages as $sections ) self::collect_sections_refs( (array) $sections, $refs );

            wp_send_json_success( [ 'pages' => (object) $pages ] + self::refs_payload( $refs ) );
        }
        wp_send_json_error( 'That version no longer exists.' );
    }

    /* =====================================================================
       Templates
       ===================================================================== */

    private static function get_templates() {
        $list = get_option( self::TEMPLATES_OPTION, [] );
        return is_array( $list ) ? array_values( $list ) : [];
    }

    /** id + name + date for every template (the builder's template picker). */
    private static function template_summaries() {
        return array_map( function ( $t ) {
            return [
                    'id'     => $t['id'],
                    'name'   => $t['name'],
                    'date'   => wp_date( get_option( 'date_format' ), (int) ( $t['time'] ?? 0 ) ),
                    'blocks' => count( (array) ( $t['section']['blocks'] ?? [] ) ),
            ];
        }, self::get_templates() );
    }

    public function ajax_save_template() {
        $this->history_guard();

        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $node = json_decode( wp_unslash( $_POST['section'] ?? '' ), true );
        if ( $name === '' ) wp_send_json_error( 'Please give the template a name.' );
        if ( ! is_array( $node ) ) wp_send_json_error( 'The section could not be read.' );

        // A nested section block is stored as a regular top-level section.
        unset( $node['type'], $node['location'] );
        $clean = $this->sanitize_sections( [ $node ], 'home' );
        if ( ! $clean ) wp_send_json_error( 'The section could not be read.' );
        $section = $clean[0];
        unset( $section['location'] );

        $list = self::get_templates();
        if ( count( $list ) >= self::MAX_TEMPLATES ) {
            wp_send_json_error( 'You already have ' . self::MAX_TEMPLATES . ' templates — delete one first.' );
        }

        $user = wp_get_current_user();
        array_unshift( $list, [
                'id'      => self::new_id( 'tpl' ),
                'name'    => mb_substr( $name, 0, 80 ),
                'time'    => time(),
                'user'    => $user && $user->exists() ? $user->display_name : '',
                'section' => $section,
        ] );
        update_option( self::TEMPLATES_OPTION, $list, false );

        wp_send_json_success( self::template_summaries() );
    }

    public function ajax_get_template() {
        $this->history_guard();

        $id = self::posted_id();
        foreach ( self::get_templates() as $t ) {
            if ( ( $t['id'] ?? '' ) !== $id ) continue;
            $refs = [];
            self::collect_sections_refs( [ $t['section'] ], $refs );
            wp_send_json_success( [ 'section' => $t['section'] ] + self::refs_payload( $refs ) );
        }
        wp_send_json_error( 'That template no longer exists.' );
    }

    public function ajax_delete_template() {
        $this->history_guard();

        $id   = self::posted_id();
        $list = array_values( array_filter( self::get_templates(), function ( $t ) use ( $id ) {
            return ( $t['id'] ?? '' ) !== $id;
        } ) );
        update_option( self::TEMPLATES_OPTION, $list, false );

        wp_send_json_success( self::template_summaries() );
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The "Push to Live Website" flow: staging every page's JSON plus any
 * section/branding/hero images into a single GitHub batch commit.
 */
trait SB_Github_Push {

    /** Repo folder holding every image used inside sections/blocks. */
    private static $sections_image_dir = 'public/sections/';

    /**
     * Returns an image resolver (for output_sections()) that stages each
     * referenced attachment into public/sections/ in the batch and points the
     * public JSON at the frontend-relative copy ("/sections/{id}-{file}"), so
     * the storefront serves section images from its own deploy instead of
     * hot-linking WordPress.
     *
     * Every staged path is recorded in $kept_paths — after all pages are
     * processed, anything else under public/sections/ is no longer used by
     * any page and gets deleted in the same commit (see handle_github_push()).
     * Unchanged files cost nothing: aps_github_batch_put_file() skips blobs
     * that already match what's in the repo.
     */
    private function github_image_resolver( &$batch, $label, array &$path_to_label, array &$kept_paths ) {
        $cache = [];

        return function ( $attachment_id ) use ( &$batch, $label, &$path_to_label, &$kept_paths, &$cache ) {
            if ( array_key_exists( $attachment_id, $cache ) ) return $cache[ $attachment_id ];

            // Videos stay on WordPress: they're far too large for the
            // frontend repository (GitHub rejects files over 100MB and every
            // push would re-upload them).
            if ( strpos( (string) get_post_mime_type( $attachment_id ), 'video/' ) === 0 ) {
                return $cache[ $attachment_id ] = self::attachment_payload( $attachment_id, wp_get_attachment_url( $attachment_id ) );
            }

            $file = get_attached_file( $attachment_id );
            if ( ! $file || ! file_exists( $file ) ) {
                error_log( "Qwoo: section image attachment {$attachment_id} missing on disk, falling back to its WordPress URL." );
                return $cache[ $attachment_id ] = self::attachment_payload( $attachment_id, wp_get_attachment_url( $attachment_id ) );
            }

            $filename  = sanitize_file_name( basename( $file ) );
            $repo_path = self::$sections_image_dir . "{$attachment_id}-{$filename}";

            if ( ! isset( $kept_paths[ $repo_path ] ) ) {
                $bytes = file_get_contents( $file );
                if ( $bytes === false ) {
                    error_log( "Qwoo: could not read section image {$attachment_id}, falling back to its WordPress URL." );
                    return $cache[ $attachment_id ] = self::attachment_payload( $attachment_id, wp_get_attachment_url( $attachment_id ) );
                }
                aps_github_batch_put_file( $batch, $repo_path, $bytes );
                $kept_paths[ $repo_path ]      = true;
                $path_to_label[ $repo_path ]   = "{$label} (section image)";
            }

            // "public/sections/x.png" is served by the frontend at "/sections/x.png".
            $public_url = '/' . substr( $repo_path, strlen( 'public/' ) );
            return $cache[ $attachment_id ] = self::attachment_payload( $attachment_id, $public_url );
        };
    }

    /**
     * Stages deletion of every file under public/sections/ that no page
     * references anymore (replaced or removed images).
     */
    private static function delete_unused_section_images( &$batch, array $kept_paths, array &$path_to_label ) {
        foreach ( array_keys( $batch['existing'] ) as $path ) {
            if ( strpos( $path, self::$sections_image_dir ) !== 0 ) continue;
            if ( isset( $kept_paths[ $path ] ) ) continue;

            $batch['tree_updates'][] = [ 'path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null ];
            $batch['deleted'][]      = $path;
            $path_to_label[ $path ]  = 'Unused section images (removed)';
        }
    }

    public function handle_github_push() {
        check_ajax_referer( 'shop_builder_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $result = $this->push_all_pages();
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    /**
     * Pushes every page's settings (and their images) to the content repo in
     * one commit. Used by the "Push to Live Website" button and by the
     * platform after it applies a new store's branding.
     *
     * @return array [ 'summary', 'updated_labels', 'skipped_labels', 'failed_labels' ] or [ 'error' => message ].
     */
    public function push_all_pages() {
        $options       = get_option( 'shop_builder_options', [] );
        $allowed_pages = self::publishable_pages();

        $page_labels = [
                'header'   => 'Header',
                'footer'   => 'Footer',
                'home'     => 'Homepage',
                'checkout' => 'Checkout',
                'branding' => 'Branding',
                'pwa'      => 'PWA Settings',
                'shop'     => 'Shop Archive',
                'category' => 'Category Archive',
                'product'  => 'Product Page',
        ];

        $has_any_page_data = false;
        foreach ( $allowed_pages as $page_slug ) {
            if ( isset( $options[ $page_slug ] ) ) {
                $has_any_page_data = true;
                break;
            }
        }

        if ( ! $has_any_page_data ) {
            return [ 'error' => 'Nothing to push yet — click Save Draft first, then try again.' ];
        }

        $batch = aps_github_start_batch();

        if ( ! $batch ) {
            return [ 'error' => 'Failed to push to GitHub: could not reach the repository. '
                    . 'Double-check the GitHub Owner/Repo/Token in Technical Settings.' ];
        }

        // Maps a staged path back to the human label it belongs to, so the
        // single combined batch result can still be reported per-page.
        $path_to_label = [];
        // Every public/sections/ file referenced by any page in this push.
        $kept_section_images = [];

        // The owner's pages in the header and footer menus.
        $menus = self::custom_page_menus( $options );
        foreach ( [ 'header', 'footer' ] as $slot ) {
            if ( $menus[ $slot ] || isset( $options[ $slot ] ) ) {
                $options[ $slot ]          = is_array( $options[ $slot ] ?? null ) ? $options[ $slot ] : [];
                $options[ $slot ]['pages'] = $menus[ $slot ];
            }
        }

        foreach ( $allowed_pages as $page_slug ) {
            if ( ! isset( $options[ $page_slug ] ) ) continue;

            $page_data = $options[ $page_slug ];
            $path      = "public/config/{$page_slug}.json";
            $label     = $page_labels[ $page_slug ] ?? ucfirst( $page_slug );
            $path_to_label[ $path ] = $label;

            if ( $page_slug === 'home' ) {
                $attachment_id = $page_data['hero_image_id'] ?? 0;
                unset( $page_data['hero_image_id'] );

                $attachment_path = $attachment_id ? get_attached_file( $attachment_id ) : '';

                if ( $attachment_path && file_exists( $attachment_path ) ) {
                    $image_folder = 'public/homepage-hero';
                    $filename     = sanitize_file_name( basename( $attachment_path ) );
                    $image_path   = "{$image_folder}/{$filename}";
                    $image_data   = file_get_contents( $attachment_path );

                    if ( $image_data !== false ) {
                        // Scoped to the whole folder: it only ever holds one
                        // hero image, so anything else in there is stale.
                        aps_github_batch_delete_stale_prefix( $batch, $image_folder . '/', $image_path );
                        aps_github_batch_put_file( $batch, $image_path, $image_data );
                        $path_to_label[ $image_path ] = "{$label} (hero image)";

                        $page_data['hero_image'] = wp_get_attachment_url( $attachment_id );
                    } else {
                        error_log( 'Qwoo: could not read hero image file, pushing home.json without it.' );
                    }
                } elseif ( $attachment_id ) {
                    error_log( 'Qwoo: hero image attachment missing on disk, pushing home.json without it.' );
                }
            }

            if ( $page_slug === 'branding' ) {
                $image_fields = [ 'logo_id' => 'logo', 'app_icon_id' => 'app_icon' ];

                foreach ( $image_fields as $id_field => $url_field ) {
                    $attachment_id = $page_data[ $id_field ] ?? 0;
                    unset( $page_data[ $id_field ] );
                    if ( empty( $attachment_id ) ) continue;

                    $attachment_path = get_attached_file( $attachment_id );
                    if ( ! $attachment_path || ! file_exists( $attachment_path ) ) {
                        error_log( "Qwoo: {$id_field} attachment missing on disk, skipping." );
                        continue;
                    }

                    $image_folder = 'public/branding';
                    $filename     = sanitize_file_name( basename( $attachment_path ) );
                    $image_path   = "{$image_folder}/{$filename}";
                    $image_data   = file_get_contents( $attachment_path );

                    if ( $image_data === false ) {
                        error_log( "Qwoo: could not read {$id_field} file, skipping." );
                        continue;
                    }

                    // Scoped to this exact target path, NOT the whole folder —
                    // logo and app icon share 'public/branding'.
                    aps_github_batch_delete_stale_prefix( $batch, $image_path, $image_path );
                    aps_github_batch_put_file( $batch, $image_path, $image_data );

                    $field_label = $url_field === 'app_icon' ? 'app icon' : $url_field;
                    $path_to_label[ $image_path ] = "{$label} ({$field_label})";

                    $page_data[ $url_field ] = wp_get_attachment_url( $attachment_id );
                }
            }

            if ( isset( $page_data['sections'] ) ) {
                $resolver = $this->github_image_resolver( $batch, $label, $path_to_label, $kept_section_images );
                $page_data['sections'] = self::output_sections( (array) $page_data['sections'], $resolver );
            }

            $content = aps_normalize_json( json_encode( $page_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            aps_github_batch_put_file( $batch, $path, $content );
        }

        $this->stage_custom_pages( $batch, $options, $path_to_label, $kept_section_images );

        if ( ! empty( $batch['failed'] ) ) {
            return [ 'error' => 'Failed to push to GitHub: could not upload ' . implode( ', ', $batch['failed'] )
                    . '. Check the PHP error log for details.' ];
        }

        // Only clean up once every page staged successfully, so a partial
        // failure can never delete an image a page still points to.
        self::delete_unused_section_images( $batch, $kept_section_images, $path_to_label );

        $result = aps_github_finish_batch( $batch, 'Update shop config from WP' );

        if ( $result === false ) {
            return [ 'error' => 'Failed to push to GitHub — the commit could not be created. '
                    . 'Check the PHP error log for details.' ];
        }

        $to_labels = function ( $paths ) use ( $path_to_label ) {
            $labels = array_map( function ( $p ) use ( $path_to_label ) {
                return $path_to_label[ $p ] ?? $p;
            }, $paths );
            return array_values( array_unique( $labels ) );
        };

        $updated_labels = $result === 'no_changes' ? [] : $to_labels( array_merge( $batch['updated'], $batch['deleted'] ) );
        $skipped_labels = array_values( array_diff(
                $to_labels( array_merge( $batch['skipped'], $result === 'no_changes' ? $batch['updated'] : [] ) ),
                $updated_labels
        ) );

        // The version now on the live site (Versions list shows it).
        self::mark_revision_pushed();
        self::remember_published_pages( $options );

        if ( $result === 'no_changes' || ( empty( $updated_labels ) && empty( $skipped_labels ) ) ) {
            return [
                    'summary'        => 'Nothing changed — everything already up to date on GitHub.',
                    'updated_labels' => [],
                    'skipped_labels' => $skipped_labels,
                    'failed_labels'  => [],
            ];
        }

        return [
                'summary'        => 'Updated: ' . count( $updated_labels ) . ', Skipped (no changes): ' . count( $skipped_labels ),
                'updated_labels' => $updated_labels,
                'skipped_labels' => $skipped_labels,
                'failed_labels'  => [],
        ];
    }
}

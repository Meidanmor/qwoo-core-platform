<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Step-by-step schema migrations for the stored sections:
 *   v1 (flat typed rows)  -> v2 (styled containers + blocks[])
 *   v2 (nested style objects) -> v3 (flat, responsive field specs)
 *
 * Runs early on admin_init (see class-shop-settings-builder.php), gated by
 * `_schema_version` on shop_builder_options so it never re-runs. Before
 * rewriting anything it snapshots the untouched options to
 * `shop_builder_options_v{N}_backup` (not autoloaded) so a bad migration
 * can be undone by hand without a database backup.
 */
trait SB_Migration {

    public function maybe_migrate_sections() {
        $options = get_option( 'shop_builder_options', [] );
        if ( ! is_array( $options ) ) $options = [];
        $version = (int) ( $options['_schema_version'] ?? 1 );

        if ( $version >= self::SCHEMA_VERSION ) {
            return;
        }

        $backup_key = "shop_builder_options_v{$version}_backup";
        if ( ! empty( $options ) && false === get_option( $backup_key, false ) ) {
            add_option( $backup_key, $options, '', 'no' );
        }

        foreach ( self::sectionable_pages() as $page_slug ) {
            if ( empty( $options[ $page_slug ]['sections'] ) || ! is_array( $options[ $page_slug ]['sections'] ) ) {
                continue;
            }
            $sections = $options[ $page_slug ]['sections'];

            if ( $version < 2 ) {
                $sections = self::migrate_page_sections_v1_to_v2( $sections );
            }
            if ( $version < 3 ) {
                $sections = array_map( function ( $section ) {
                    return self::migrate_section_v2_to_v3( $section );
                }, array_values( array_filter( $sections, 'is_array' ) ) );
            }

            // Normalize into exactly the v3 stored shape.
            $options[ $page_slug ]['sections'] = $this->sanitize_sections( $sections, $page_slug );
        }

        $options['_schema_version'] = self::SCHEMA_VERSION;
        update_option( 'shop_builder_options', $options );

        error_log( "Qwoo: Shop Builder data migrated from schema v{$version} to v" . self::SCHEMA_VERSION . ". Previous data backed up to {$backup_key}." );
    }

    /* -----------------------------------------------------------------
       v2 -> v3: nested style objects become flat, responsive fields.
       ----------------------------------------------------------------- */

    /** Converts a v2 container `style` (top-level section or nested section block). */
    private static function migrate_section_style_v2_to_v3( $old ) {
        if ( ! is_array( $old ) ) return [];
        if ( isset( $old['bg_type'] ) || isset( $old['padding_preset'] ) ) return $old; // already v3

        $padding = $old['padding'] ?? [];
        $bg      = $old['background'] ?? [];
        $width   = $old['width'] ?? [];
        $nest    = $old['nesting'] ?? [];

        return [
                'padding_preset' => $padding['mode'] ?? 'medium',
                'padding'        => [
                        'desktop' => $padding['custom'] ?? [],
                        'tablet'  => [],
                        'mobile'  => $padding['custom_mobile'] ?? [],
                ],
                'bg_type'            => $bg['type'] ?? 'none',
                'bg_color'           => $bg['color'] ?? '',
                'bg_gradient_color1' => $bg['gradient']['color1'] ?? '',
                'bg_gradient_color2' => $bg['gradient']['color2'] ?? '',
                'bg_gradient_angle'  => $bg['gradient']['angle'] ?? 180,
                'bg_image'           => $bg['image_id'] ?? 0,
                'bg_overlay_color'   => $bg['overlay_color'] ?? '',
                'width_mode'    => $width['mode'] ?? 'contained',
                'width'         => [ 'desktop' => ! empty( $width['custom_px'] ) ? intval( $width['custom_px'] ) . 'px' : '' ],
                'min_height'    => [ 'desktop' => $old['min_height'] ?? '', 'tablet' => '', 'mobile' => $old['min_height_mobile'] ?? '' ],
                'direction'     => [ 'desktop' => ( $nest['flex_direction'] ?? '' ) ?: 'column' ],
                'wrap'          => [ 'desktop' => ( $nest['flex_wrap'] ?? '' ) ?: 'nowrap' ],
                'justify'       => [ 'desktop' => ( $nest['justify_content'] ?? '' ) ?: 'flex-start' ],
                'align_items'   => [ 'desktop' => ( $nest['align_items'] ?? '' ) ?: 'stretch' ],
                'align_content' => ( $nest['align_content'] ?? '' ) ?: 'normal',
        ];
    }

    /** Converts a v2 non-section block `style` (padding above/below -> space above/below). */
    private static function migrate_block_style_v2_to_v3( $old ) {
        if ( ! is_array( $old ) ) return [];
        if ( isset( $old['margin_top'] ) ) return $old;
        return [
                'margin_top'    => [ 'desktop' => $old['padding_top'] ?? '', 'tablet' => '', 'mobile' => $old['padding_top_mobile'] ?? '' ],
                'margin_bottom' => [ 'desktop' => $old['padding_bottom'] ?? '', 'tablet' => '', 'mobile' => $old['padding_bottom_mobile'] ?? '' ],
        ];
    }

    /** Field renames inside a block's `data` between v2 and v3. */
    private static function migrate_block_data_v2_to_v3( $type, $data ) {
        if ( ! is_array( $data ) ) return [];

        switch ( $type ) {
            case 'heading':
                if ( empty( $data['title_color'] ) && ! empty( $data['color'] ) ) $data['title_color'] = $data['color'];
                unset( $data['color'] );
                break;

            case 'image_block':
                foreach ( (array) ( $data['images'] ?? [] ) as $i => $img ) {
                    if ( isset( $img['image_id'] ) && ! isset( $img['image'] ) ) {
                        $data['images'][ $i ]['image'] = $img['image_id'];
                    }
                    unset( $data['images'][ $i ]['image_id'] );
                }
                // v2 always showed the whole image at its natural ratio.
                if ( ! isset( $data['object_fit'] ) ) $data['object_fit'] = 'contain';
                break;

            case 'spacer':
                $map = [ 'small' => '24px', 'medium' => '48px', 'large' => '96px' ];
                if ( is_string( $data['height'] ?? null ) && isset( $map[ $data['height'] ] ) ) {
                    $data['height'] = $map[ $data['height'] ];
                }
                break;

            case 'advantages':
                foreach ( (array) ( $data['items'] ?? [] ) as $i => $item ) {
                    if ( isset( $item['custom_icon_id'] ) && ! isset( $item['custom_icon'] ) ) {
                        $data['items'][ $i ]['custom_icon'] = $item['custom_icon_id'];
                    }
                    unset( $data['items'][ $i ]['custom_icon_id'] );
                }
                break;

            case 'testimonials':
                foreach ( (array) ( $data['items'] ?? [] ) as $i => $item ) {
                    if ( empty( $item['review_text'] ) && ! empty( $item['quote'] ) ) {
                        $data['items'][ $i ]['review_text'] = $item['quote'];
                    }
                    unset( $data['items'][ $i ]['quote'] );
                }
                break;
        }

        return $data;
    }

    private static function migrate_blocks_v2_to_v3( $blocks ) {
        $out = [];
        foreach ( (array) $blocks as $block ) {
            if ( ! is_array( $block ) ) continue;
            if ( ( $block['type'] ?? '' ) === 'section' ) {
                $block['style']  = self::migrate_section_style_v2_to_v3( $block['style'] ?? [] );
                $block['blocks'] = self::migrate_blocks_v2_to_v3( $block['blocks'] ?? [] );
            } else {
                $block['style'] = self::migrate_block_style_v2_to_v3( $block['style'] ?? [] );
                $block['data']  = self::migrate_block_data_v2_to_v3( $block['type'] ?? '', $block['data'] ?? [] );
            }
            $out[] = $block;
        }
        return $out;
    }

    private static function migrate_section_v2_to_v3( $section ) {
        $section['style']  = self::migrate_section_style_v2_to_v3( $section['style'] ?? [] );
        $section['blocks'] = self::migrate_blocks_v2_to_v3( $section['blocks'] ?? [] );
        return $section;
    }

    /* -----------------------------------------------------------------
       v1 -> v2 (kept for very old installs; runs before v2 -> v3).
       ----------------------------------------------------------------- */

    private static function migrate_page_sections_v1_to_v2( array $old_sections ) {
        $new_sections = [];
        foreach ( $old_sections as $old ) {
            if ( ! is_array( $old ) ) continue;
            $new_sections[] = self::migrate_one_section_v1_to_v2( $old );
        }
        return $new_sections;
    }

    private static function migration_new_block_id() {
        return 'blk_' . substr( wp_generate_password( 12, false ), 0, 10 );
    }

    private static function migration_new_block( $type, array $data ) {
        return [
                'id'      => self::migration_new_block_id(),
                'type'    => $type,
                'enabled' => true,
                'data'    => $data,
        ];
    }

    /**
     * Converts one v1 row (flat `type`/`kind`/`data`) into one v2 container
     * row (`style`/`blocks[]`). Deliberately maps 1 old row -> 1 new
     * container (not N separate containers) so the visual grouping and
     * order the admin already set up survives untouched — a v1 `cta`
     * section becomes one v2 container holding a heading + text + image +
     * button block, in that order, not four separate top-level sections.
     */
    private static function migrate_one_section_v1_to_v2( array $old ) {
        $type   = $old['type'] ?? '';
        $data   = is_array( $old['data'] ?? null ) ? $old['data'] : [];
        $blocks = [];

        switch ( $type ) {
            // Already block-shaped in v1 — just drop straight in. The
            // widget types (featured_products/category_grid/testimonials/
            // advantages) additionally had a `title` field that no longer
            // exists on their v2 schema; split it into a leading heading
            // block instead, so the visible title survives even though the
            // field it lived on didn't.
            case 'text_block':
            case 'image_block':
            case 'spacer':
            case 'featured_products':
            case 'category_grid':
            case 'testimonials':
            case 'advantages':
                if ( ! empty( $data['title'] ) ) {
                    $blocks[] = self::migration_new_block( 'heading', [
                            'title' => $data['title'],
                            'tag'   => 'h2',
                    ] );
                    unset( $data['title'] );
                }
                $blocks[] = self::migration_new_block( $type, $data );
                break;

            case 'banner':
                if ( ! empty( $data['text'] ) ) {
                    $blocks[] = self::migration_new_block( 'text_block', [
                            'text'       => $data['text'],
                            'text_color' => $data['text_color'] ?? '',
                    ] );
                }
                if ( ! empty( $data['link_text'] ) || ! empty( $data['link_url'] ) ) {
                    $blocks[] = self::migration_new_block( 'button', [
                            'text' => $data['link_text'] ?? '',
                            'url'  => $data['link_url'] ?? '',
                    ] );
                }
                break;

            case 'newsletter_signup':
                if ( ! empty( $data['title'] ) || ! empty( $data['subtitle'] ) ) {
                    $blocks[] = self::migration_new_block( 'heading', [
                            'title'    => $data['title'] ?? '',
                            'subtitle' => $data['subtitle'] ?? '',
                            'tag'      => 'h2',
                    ] );
                }
                $blocks[] = self::migration_new_block( 'form', [
                        'form_type'   => 'newsletter',
                        'submit_text' => $data['button_text'] ?? 'Subscribe',
                ] );
                break;

            case 'cta':
                if ( ! empty( $data['title'] ) || ! empty( $data['pretitle'] ) ) {
                    $blocks[] = self::migration_new_block( 'heading', [
                            'title'    => $data['title'] ?? '',
                            'subtitle' => $data['pretitle'] ?? '',
                            'tag'      => 'h2',
                    ] );
                }
                if ( ! empty( $data['text'] ) ) {
                    $blocks[] = self::migration_new_block( 'text_block', [ 'text' => $data['text'] ] );
                }
                if ( ! empty( $data['image_id'] ) ) {
                    $blocks[] = self::migration_new_block( 'image_block', [
                            'layout' => 'row',
                            'images' => [ [ 'image_id' => $data['image_id'], 'link_url' => '', 'alt' => '' ] ],
                    ] );
                }
                if ( ! empty( $data['button_text'] ) || ! empty( $data['button_url'] ) ) {
                    $blocks[] = self::migration_new_block( 'button', [
                            'text' => $data['button_text'] ?? '',
                            'url'  => $data['button_url'] ?? '',
                    ] );
                }
                break;

            case 'custom':
                if ( ! empty( $data['title'] ) || ! empty( $data['pretitle'] ) ) {
                    $blocks[] = self::migration_new_block( 'heading', [
                            'title'    => $data['title'] ?? '',
                            'subtitle' => $data['pretitle'] ?? '',
                            'tag'      => 'h2',
                            'color'    => $data['text_color'] ?? '',
                    ] );
                }
                if ( ! empty( $data['text'] ) ) {
                    $blocks[] = self::migration_new_block( 'text_block', [
                            'text'       => $data['text'],
                            'text_color' => $data['text_color'] ?? '',
                    ] );
                }
                // bg_image_id and image_id both become their own image
                // block, in that order, rather than trying to preserve
                // custom's old "one behind, one beside" two-column layout —
                // that layout concept doesn't exist once these are
                // independent blocks; re-arrange them in the builder after
                // migration if the two-column look needs to be rebuilt.
                foreach ( [ 'bg_image_id', 'image_id' ] as $img_field ) {
                    if ( ! empty( $data[ $img_field ] ) ) {
                        $blocks[] = self::migration_new_block( 'image_block', [
                                'layout' => 'row',
                                'images' => [ [ 'image_id' => $data[ $img_field ], 'link_url' => '', 'alt' => '' ] ],
                        ] );
                    }
                }
                if ( ! empty( $data['button_text'] ) || ! empty( $data['button_url'] ) ) {
                    $blocks[] = self::migration_new_block( 'button', [
                            'text'       => $data['button_text'] ?? '',
                            'url'        => $data['button_url'] ?? '',
                            'bg_color'   => $data['button_bg_color'] ?? '',
                            'text_color' => $data['button_text_color'] ?? '',
                    ] );
                }
                break;

            default:
                // Unknown/already-removed type — nothing to carry forward
                // as a block, but the container row itself is kept (with
                // whatever section_bg_color it had) so it doesn't silently
                // vanish without a trace in the admin; it'll just show up
                // as an empty section ready for new blocks.
                break;
        }

        // v1's only container-level style was `section_bg_color` on the row
        // itself — carry it into the new style.background.color, leaving
        // every other new style option (padding/width/min-height) at its
        // default.
        $bg = $old['section_bg_color'] ?? '';

        $new_section = [
                'id'      => preg_match( '/^sec_[a-zA-Z0-9]{6,20}$/', $old['id'] ?? '' )
                        ? $old['id']
                        : ( 'sec_' . substr( wp_generate_password( 12, false ), 0, 10 ) ),
                'enabled' => ! empty( $old['enabled'] ),
                'style'   => [
                        'padding'    => [ 'mode' => 'medium', 'custom' => [ 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ] ],
                        'background' => $bg !== '' ? [ 'type' => 'color', 'color' => $bg ] : [ 'type' => 'none' ],
                        'width'      => [ 'mode' => 'contained' ],
                        'min_height' => '',
                ],
                'blocks'  => $blocks,
        ];

        if ( isset( $old['location'] ) ) {
            $new_section['location'] = $old['location'];
        }

        return $new_section;
    }
}

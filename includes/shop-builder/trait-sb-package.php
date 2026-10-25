<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The Store builder's part of a store export file (Qwoo_Store_Package).
 *
 * Export: every Store builder setting (pages, menus, header & footer,
 * branding, layouts, the extra languages' copies, saved section templates)
 * as stored, plus what they point at: media, products, categories, tags
 * and blog categories (with their slugs, so a store that didn't bring the
 * products in can still find its own by address).
 *
 * Import: the IDs are swapped for this store's (package_walk() with a map
 * callback), then everything goes through the same sanitizer as a draft
 * save. "replace" puts the file's Store builder in place of this one's
 * (saved as a version first, so it can be rolled back; the store's app
 * name is kept); "add" only adds the file's pages whose address is free.
 * Nothing is published: the owner publishes when ready.
 */
trait SB_Package {

    /** Top-level keys that hold one media ID. */
    private static $package_media_keys = [ 'logo_id', 'app_icon_id', 'hero_image_id', 'image_id', 'bg_image_id', 'custom_icon_id' ];

    /**
     * The options with every referenced ID passed through $map( $kind, $id ),
     * kind: media | product | category | tag | blog_category. $map returns the
     * ID to keep (0 drops the reference).
     */
    public static function package_walk( $value, callable $map ) {
        if ( ! is_array( $value ) ) return $value;
        foreach ( $value as $key => $item ) {
            if ( $key === 'sections' && is_array( $item ) ) {
                $value[ $key ] = self::package_walk_sections( $item, $map );
            } elseif ( is_string( $key ) && in_array( $key, self::$package_media_keys, true ) && is_numeric( $item ) ) {
                $value[ $key ] = (int) $item > 0 ? (int) $map( 'media', (int) $item ) : 0;
            } elseif ( $key === 'layouts' && is_array( $item ) && $item === array_values( $item ) ) {
                foreach ( $item as $i => $layout ) {
                    if ( ! is_array( $layout ) ) continue;
                    foreach ( [ 'products' => 'product', 'categories' => 'category', 'blog_categories' => 'blog_category' ] as $cond => $kind ) {
                        if ( isset( $layout['conditions'][ $cond ] ) ) {
                            $layout['conditions'][ $cond ] = self::package_ids( (array) $layout['conditions'][ $cond ], $kind, $map );
                        }
                    }
                    if ( isset( $layout['sections'] ) && is_array( $layout['sections'] ) ) {
                        $layout['sections'] = self::package_walk_sections( $layout['sections'], $map );
                    }
                    $item[ $i ] = $layout;
                }
                $value[ $key ] = $item;
            } elseif ( $key === 'menus' && is_array( $item ) ) {
                $value[ $key ] = self::package_walk_menus( $item, $map );
            } elseif ( is_array( $item ) ) {
                $value[ $key ] = self::package_walk( $item, $map );
            }
        }
        return $value;
    }

    private static function package_ids( array $ids, $kind, callable $map ) {
        $out = [];
        foreach ( $ids as $id ) {
            $new = (int) $id > 0 ? (int) $map( $kind, (int) $id ) : 0;
            if ( $new > 0 ) $out[] = $new;
        }
        return array_values( array_unique( $out ) );
    }

    /** Menus: [ { id, name, items: [ { type, ref, children } ] } ] (or { list: [...] }). */
    private static function package_walk_menus( array $menus, callable $map ) {
        $items = static function ( $list ) use ( &$items, $map ) {
            if ( ! is_array( $list ) ) return $list;
            foreach ( $list as $i => $it ) {
                if ( ! is_array( $it ) ) continue;
                $kind = [ 'category' => 'category', 'blog_category' => 'blog_category', 'product' => 'product' ][ $it['type'] ?? '' ] ?? '';
                if ( $kind !== '' && (int) ( $it['ref'] ?? 0 ) > 0 ) {
                    $new = (int) $map( $kind, (int) $it['ref'] );
                    $it['ref'] = $new > 0 ? (string) $new : '';
                }
                if ( isset( $it['children'] ) ) $it['children'] = $items( $it['children'] );
                $list[ $i ] = $it;
            }
            return $list;
        };
        foreach ( $menus as $i => $menu ) {
            if ( is_array( $menu ) && isset( $menu['items'] ) ) {
                $menu['items'] = $items( $menu['items'] );
                $menus[ $i ]   = $menu;
            } elseif ( $i === 'list' && is_array( $menu ) ) {
                $menus[ $i ] = self::package_walk_menus( $menu, $map );
            }
        }
        return $menus;
    }

    private static function package_walk_sections( array $sections, callable $map ) {
        foreach ( $sections as $i => $section ) {
            if ( ! is_array( $section ) ) continue;
            $section['style'] = self::package_walk_fields( self::SECTION_STYLE_FIELDS, $section['style'] ?? [], $map );
            if ( isset( $section['blocks'] ) && is_array( $section['blocks'] ) ) {
                $section['blocks'] = self::package_walk_blocks( $section['blocks'], $map );
            }
            $sections[ $i ] = $section;
        }
        return $sections;
    }

    private static function package_walk_blocks( array $blocks, callable $map ) {
        foreach ( $blocks as $i => $block ) {
            if ( ! is_array( $block ) ) continue;
            if ( ( $block['type'] ?? '' ) === 'section' ) {
                $block['style'] = self::package_walk_fields( self::SECTION_STYLE_FIELDS, $block['style'] ?? [], $map );
                $block['blocks'] = self::package_walk_blocks( (array) ( $block['blocks'] ?? [] ), $map );
            } else {
                $schema = self::get_type_schema( $block['type'] ?? '' );
                if ( $schema ) $block['data'] = self::package_walk_fields( $schema['fields'], $block['data'] ?? [], $map );
                $block['style'] = self::package_walk_fields( self::BLOCK_COMMON_FIELDS, $block['style'] ?? [], $map );
            }
            $blocks[ $i ] = $block;
        }
        return $blocks;
    }

    private static function package_walk_fields( array $specs, $data, callable $map ) {
        if ( ! is_array( $data ) ) return $data;
        foreach ( $specs as $key => $spec ) {
            if ( ! array_key_exists( $key, $data ) ) continue;
            $value = $data[ $key ];
            switch ( $spec['type'] ?? '' ) {
                case 'image':
                case 'video':
                    // One ID, or one per screen size.
                    if ( is_array( $value ) ) {
                        foreach ( $value as $d => $id ) {
                            if ( is_numeric( $id ) && (int) $id > 0 ) $value[ $d ] = (int) $map( 'media', (int) $id );
                        }
                    } elseif ( is_numeric( $value ) && (int) $value > 0 ) {
                        $value = (int) $map( 'media', (int) $value );
                    }
                    break;
                case 'products':
                    $value = self::package_ids( (array) $value, 'product', $map );
                    break;
                case 'categories':
                    $value = self::package_ids( (array) $value, 'category', $map );
                    break;
                case 'tags':
                    $value = self::package_ids( (array) $value, 'tag', $map );
                    break;
                case 'product_tags':
                    $out = [];
                    foreach ( (array) $value as $tag ) {
                        if ( ! is_array( $tag ) ) continue;
                        $new = (int) ( $tag['product'] ?? 0 ) > 0 ? (int) $map( 'product', (int) $tag['product'] ) : 0;
                        if ( $new > 0 ) $out[] = [ 'product' => $new ] + $tag;
                    }
                    $value = $out;
                    break;
                case 'repeater':
                    foreach ( (array) $value as $j => $item ) {
                        $value[ $j ] = self::package_walk_fields( $spec['fields'] ?? [], $item, $map );
                    }
                    break;
            }
            $data[ $key ] = $value;
        }
        return $data;
    }

    /**
     * The Store builder for an export file: { options, templates, refs }.
     * refs: { media: [ids], product|category|tag|blog_category: { id: slug } }.
     */
    public static function package_builder_export() {
        $options = get_option( 'shop_builder_options', [] );
        $options = is_array( $options ) ? $options : [];
        $templates = self::get_templates();
        $found = [ 'media' => [], 'product' => [], 'category' => [], 'tag' => [], 'blog_category' => [] ];
        $record = static function ( $kind, $id ) use ( &$found ) {
            $found[ $kind ][ (int) $id ] = true;
            return (int) $id;
        };
        self::package_walk( $options, $record );
        self::package_walk( [ 'sections' => array_column( $templates, 'section' ) ], $record );

        $refs = [ 'media' => array_keys( $found['media'] ) ];
        foreach ( array_keys( $found['product'] ) as $id ) {
            $slug = get_post_field( 'post_name', $id );
            if ( $slug ) $refs['product'][ $id ] = $slug;
        }
        foreach ( [ 'category' => 'product_cat', 'tag' => 'product_tag', 'blog_category' => 'category' ] as $kind => $taxonomy ) {
            foreach ( array_keys( $found[ $kind ] ) as $id ) {
                $term = get_term( $id, $taxonomy );
                if ( $term && ! is_wp_error( $term ) ) $refs[ $kind ][ $id ] = $term->slug;
            }
        }
        return [ 'options' => $options, 'templates' => $templates, 'refs' => $refs ];
    }

    /**
     * Brings the file's Store builder in ($data from package_builder_export(),
     * IDs mapped by $map). Returns [ 'pages' => how many pages came, 'skipped' => [titles] ].
     */
    public static function package_builder_import( array $data, callable $map, $mode ) {
        $in = self::package_walk( is_array( $data['options'] ?? null ) ? $data['options'] : [], $map );
        $current = get_option( 'shop_builder_options', [] );
        $current = is_array( $current ) ? $current : [];

        if ( $mode !== 'replace' ) {
            // Add: only the file's pages whose address is still free (new IDs when theirs are taken).
            $have_ids   = array_column( (array) ( $current['custom_pages'] ?? [] ), 'id' );
            $have_slugs = [];
            foreach ( (array) ( $current['custom_pages'] ?? [] ) as $p ) {
                $have_slugs[ (string) ( $p['parent'] ?? '' ) . '/' . (string) ( $p['slug'] ?? '' ) ] = true;
            }
            $add = [];
            $skipped = [];
            $renamed = [];
            foreach ( (array) ( $in['custom_pages'] ?? [] ) as $p ) {
                if ( ! is_array( $p ) ) continue;
                if ( ( $p['role'] ?? '' ) !== '' ) {
                    // There's only one homepage, privacy page…: this store keeps its own.
                    $skipped[] = (string) ( $p['title'] ?? '' );
                    continue;
                }
                $parent = (string) ( $p['parent'] ?? '' );
                $parent = $renamed[ $parent ] ?? $parent;
                if ( $parent !== '' && ! in_array( $parent, $have_ids, true ) ) $parent = '';
                if ( isset( $have_slugs[ $parent . '/' . (string) ( $p['slug'] ?? '' ) ] ) ) {
                    $skipped[] = (string) ( $p['title'] ?? '' );
                    continue;
                }
                if ( in_array( $p['id'] ?? '', $have_ids, true ) ) {
                    $renamed[ $p['id'] ] = self::new_id( 'pg' );
                    $p['id'] = $renamed[ $p['id'] ];
                }
                $p['parent'] = $parent;
                $p['status'] = 'draft';
                $have_ids[]  = $p['id'];
                $add[]       = $p;
            }
            if ( $add ) {
                self::instance()->save_input( [ 'custom_pages' => array_merge( array_values( (array) ( $current['custom_pages'] ?? [] ) ), $add ) ] );
            }
            return [ 'pages' => count( $add ), 'skipped' => array_values( array_filter( $skipped ) ) ];
        }

        // Replace: everything the draft save knows, cleaned by its sanitizer.
        $input = array_intersect_key( $in, array_flip( array_merge( self::$platform_option_groups, [ 'custom_pages', 'menus', 'loops', 'loops_default', 'popups' ], self::sectionable_pages() ) ) );
        // Every page is sent, so a page the file has no sections or layouts for ends up with none.
        foreach ( self::sectionable_pages() as $page ) {
            $input[ $page ]             = is_array( $input[ $page ] ?? null ) ? $input[ $page ] : [];
            $input[ $page ]['sections'] = is_array( $input[ $page ]['sections'] ?? null ) ? $input[ $page ]['sections'] : [];
        }
        foreach ( array_keys( self::LAYOUT_PAGES ) as $page ) {
            $input[ $page ]['layouts'] = is_array( $input[ $page ]['layouts'] ?? null ) ? $input[ $page ]['layouts'] : [];
        }
        $input['custom_pages'] = is_array( $input['custom_pages'] ?? null ) ? $input['custom_pages'] : [];
        $input['menus']        = is_array( $input['menus'] ?? null ) ? $input['menus'] : [];
        // Card designs (their sections were walked like a page's).
        $input['loops']         = is_array( $input['loops'] ?? null ) ? $input['loops'] : [];
        $input['loops_default'] = is_array( $input['loops_default'] ?? null ) ? $input['loops_default'] : [];
        // Popups (their sections were walked like a page's).
        $input['popups']        = is_array( $input['popups'] ?? null ) ? $input['popups'] : [];
        // The app's name is this store's, not the file's.
        foreach ( [ 'name', 'short_name' ] as $key ) {
            if ( isset( $input['pwa'] ) && is_array( $input['pwa'] ) ) $input['pwa'][ $key ] = (string) ( $current['pwa'][ $key ] ?? '' );
        }
        $clean = self::instance()->sanitize_options( $input );
        $new   = $current;
        foreach ( $clean as $key => $value ) {
            $new[ $key ] = $value;
        }

        // The extra languages' copies, cleaned the same way.
        $tr_out = [];
        foreach ( (array) ( $in['i18n'] ?? [] ) as $lang => $tr ) {
            if ( ! is_string( $lang ) || ! preg_match( '/^[a-z]{2}$/', $lang ) || ! is_array( $tr ) ) continue;
            $one = [];
            $pages_in = array_intersect_key( (array) ( $tr['pages'] ?? [] ), array_flip( self::$translatable_keys ) );
            if ( $pages_in ) {
                $c = self::instance()->sanitize_options( $pages_in );
                foreach ( self::$translatable_keys as $key ) {
                    if ( isset( $c[ $key ] ) ) $one['pages'][ $key ] = $c[ $key ];
                }
            }
            if ( isset( $tr['menus'] ) ) {
                $c = self::instance()->sanitize_options( [ 'menus' => $tr['menus'] ] );
                if ( isset( $c['menus'] ) ) $one['menus'] = $c['menus'];
            }
            $own_pages = [];
            $main_ids  = array_column( (array) ( $new['custom_pages'] ?? [] ), null, 'id' );
            foreach ( (array) ( $tr['custom_pages'] ?? [] ) as $id => $copy ) {
                if ( ! isset( $main_ids[ $id ] ) || ! is_array( $copy ) ) continue;
                $c = self::instance()->sanitize_options( [ 'custom_pages' => [ array_merge( $main_ids[ $id ], array_intersect_key( $copy, array_flip( [ 'title', 'show_title', 'status', 'seo', 'sections' ] ) ) ) ] ] );
                $page = $c['custom_pages'][0] ?? null;
                if ( $page ) $own_pages[ $id ] = array_intersect_key( $page, array_flip( [ 'title', 'show_title', 'status', 'seo', 'sections' ] ) );
            }
            if ( $own_pages ) $one['custom_pages'] = $own_pages;
            if ( $one ) $tr_out[ $lang ] = $one;
        }
        $new['i18n'] = $tr_out;
        unset( $new['section_sync'] );
        update_option( 'shop_builder_options', $new );
        foreach ( (array) ( $in['section_sync'] ?? [] ) as $lang => $sync ) {
            if ( is_string( $lang ) && preg_match( '/^[a-z]{2}$/', $lang ) && is_array( $sync ) ) {
                self::save_section_sync( $lang, $sync );
            }
        }
        if ( isset( $clean['checkout']['allow_signup'] ) ) {
            Qwoo_Checkout_Extras::sync_signup( ! empty( $clean['checkout']['allow_signup'] ) );
        }
        self::record_revision( get_option( 'shop_builder_options', [] ) );

        // Saved section templates (their sections cleaned like any page's).
        $templates = [];
        foreach ( array_slice( (array) ( $data['templates'] ?? [] ), 0, self::MAX_TEMPLATES ) as $t ) {
            if ( ! is_array( $t ) || ! is_array( $t['section'] ?? null ) ) continue;
            $walked = self::package_walk( [ 'sections' => [ $t['section'] ] ], $map );
            $c      = self::instance()->sanitize_options( [ 'home' => [ 'sections' => $walked['sections'] ] ] );
            $section = $c['home']['sections'][0] ?? null;
            if ( ! $section ) continue;
            $templates[] = [
                'id'      => self::platform_valid_id( $t['id'] ?? '' ) ? $t['id'] : self::new_id( 'tpl' ),
                'name'    => mb_substr( sanitize_text_field( (string) ( $t['name'] ?? '' ) ), 0, 60 ) ?: 'Template',
                'time'    => (int) ( $t['time'] ?? time() ),
                'user'    => '',
                'section' => $section,
            ];
        }
        if ( $templates ) update_option( self::TEMPLATES_OPTION, $templates, false );

        return [ 'pages' => count( (array) ( $new['custom_pages'] ?? [] ) ), 'skipped' => [] ];
    }
}

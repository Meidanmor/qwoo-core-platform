<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Everything that turns raw posted data into safe, stored data — and stored
 * data into the public JSON the storefront reads.
 *
 * Both directions are driven by the field specs in class-sb-constants.php:
 *   sanitize_fields()  raw input  -> stored shape (image fields = attachment IDs)
 *   output_fields()    stored     -> public shape (images = {url,width,height},
 *                                    responsive values fully inherited,
 *                                    private fields removed)
 */
trait SB_Sanitizers {

    /* =====================================================================
       Generic field engine
       ===================================================================== */

    /** Sanitizes a data object against a [field => spec] map. */
    private static function sanitize_fields( array $specs, $raw ) {
        $raw   = self::fold_old_margins( $specs, is_array( $raw ) ? $raw : [] );
        $clean = [];
        foreach ( $specs as $key => $spec ) {
            $clean[ $key ] = self::sanitize_field_value( $spec, $raw[ $key ] ?? null );
        }

        // URL fields restricted to certain hosts — the rule can depend on a
        // sibling field (e.g. the Video block's `source`). A URL that breaks
        // its rule is dropped.
        foreach ( $specs as $key => $spec ) {
            if ( empty( $spec['url_hosts'] ) || ! is_string( $clean[ $key ] ) || $clean[ $key ] === '' ) continue;
            $rule = $spec['url_hosts'];
            if ( is_array( $rule ) ) {
                $rule = $rule['map'][ (string) ( $clean[ $rule['field'] ] ?? '' ) ] ?? 'none';
            }
            if ( ! self::url_matches_host_rule( $clean[ $key ], $rule ) ) $clean[ $key ] = '';
        }
        return $clean;
    }

    /**
     * Host rules for `url_hosts`:
     *   youtube / vimeo  https links to those sites only
     *   own              this WordPress site, the storefront domain, or a
     *                    root-relative path on the storefront
     *   any              no restriction; none: always rejected
     */
    private static function url_matches_host_rule( $url, $rule ) {
        if ( $rule === 'any' ) return true;
        if ( $rule === 'own' && $url[0] === '/' && ( $url[1] ?? '' ) !== '/' ) return true;

        $parts = wp_parse_url( $url );
        $host  = strtolower( $parts['host'] ?? '' );
        if ( ! $host ) return false;
        // YouTube/Vimeo: https only. Own hosts may be http on a local setup.
        if ( $rule !== 'own' && ( $parts['scheme'] ?? '' ) !== 'https' ) return false;

        switch ( $rule ) {
            case 'youtube':
                return in_array( $host, [ 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com' ], true );
            case 'vimeo':
                return in_array( $host, [ 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com' ], true );
            case 'own':
                $own = [ strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ];
                $tech = get_option( 'qwoo_technical_settings', [] );
                if ( ! empty( $tech['frontend_domain'] ) ) {
                    $own[] = strtolower( (string) wp_parse_url( $tech['frontend_domain'], PHP_URL_HOST ) );
                }
                return in_array( $host, array_filter( $own ), true );
        }
        return false;
    }

    private static function is_device_map( $value ) {
        return is_array( $value ) && (
                        array_key_exists( 'desktop', $value ) ||
                        array_key_exists( 'tablet', $value ) ||
                        array_key_exists( 'mobile', $value )
                );
    }

    /** The default for one device of a field (tablet/mobile default to '' = inherit). */
    private static function device_default( array $spec, $device ) {
        $default = $spec['default'] ?? null;
        if ( is_array( $default ) && self::is_device_map( $default ) ) {
            return $default[ $device ] ?? '';
        }
        return $device === 'desktop' ? $default : '';
    }

    /** BLOCK_SCHEMA with its text defaults in the store's language. */
    public static function localized_block_schema() {
        $schema = self::block_schema();
        if ( ! class_exists( 'Qwoo_Store_Language' ) || Qwoo_Store_Language::get() === 'en' ) {
            return $schema;
        }
        foreach ( $schema as &$block ) {
            foreach ( (array) ( $block['fields'] ?? [] ) as $key => $spec ) {
                if ( isset( $spec['default'] ) && is_string( $spec['default'] ) && in_array( $spec['type'] ?? 'text', [ 'text', 'textarea' ], true ) ) {
                    $block['fields'][ $key ]['default'] = Qwoo_I18n::t( $spec['default'] );
                }
            }
        }
        unset( $block );
        return $schema;
    }

    private static function sanitize_field_value( array $spec, $value ) {
        if ( ! empty( $spec['responsive'] ) ) {
            // A plain scalar (e.g. legacy non-responsive data) becomes the desktop value.
            if ( ! self::is_device_map( $value ) ) {
                $value = $value === null ? [] : [ 'desktop' => $value ];
            }
            $out = [];
            foreach ( self::DEVICES as $device ) {
                $is_inheriting = $device !== 'desktop';
                $v = $value[ $device ] ?? null;
                if ( $v === null ) $v = self::device_default( $spec, $device );
                $out[ $device ] = self::sanitize_scalar( $spec, $v, $is_inheriting );
            }
            return $out;
        }

        if ( $value === null ) {
            $value = $spec['default'] ?? null;
            // A text default ("View all") in the store's language.
            if ( is_string( $value ) && in_array( $spec['type'] ?? 'text', [ 'text', 'textarea' ], true ) && class_exists( 'Qwoo_I18n' ) ) {
                $value = Qwoo_I18n::t( $value );
            }
        }
        return self::sanitize_scalar( $spec, $value, false );
    }

    /**
     * Product tags on an image ("shop the look"): [ { product, x, y, mx, my } ].
     * x / y: where the product is in the image, in % from its left / top edge;
     * mx / my: the same on the phone image, '' when it's the same spot.
     */
    private static function sanitize_product_tags( $value, $max ) {
        $pct = static function ( $v, $allow_empty ) {
            if ( $allow_empty && ( $v === '' || $v === null ) ) return '';
            return is_numeric( $v ) ? round( min( 100, max( 0, (float) $v ) ), 2 ) : ( $allow_empty ? '' : 50 );
        };
        $out = [];
        foreach ( is_array( $value ) ? array_values( $value ) : [] as $tag ) {
            if ( count( $out ) >= max( 1, $max ) ) break;
            $product = is_array( $tag ) ? absint( $tag['product'] ?? 0 ) : 0;
            if ( ! $product ) continue;
            $out[] = [
                    'product' => $product,
                    'x'       => $pct( $tag['x'] ?? 50, false ),
                    'y'       => $pct( $tag['y'] ?? 50, false ),
                    // Own spots on tablets and phones ('' = the bigger screen's spot).
                    'tx'      => $pct( $tag['tx'] ?? '', true ),
                    'ty'      => $pct( $tag['ty'] ?? '', true ),
                    'mx'      => $pct( $tag['mx'] ?? '', true ),
                    'my'      => $pct( $tag['my'] ?? '', true ),
            ];
        }
        return $out;
    }

    /**
     * Sanitizes one (non-responsive) value. $allow_empty: '' is a valid,
     * meaningful value (a tablet/mobile value inheriting from the device above).
     */
    private static function sanitize_scalar( array $spec, $value, $allow_empty ) {
        $type = $spec['type'] ?? 'text';

        switch ( $type ) {
            case 'text':
                return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );

            case 'hidden':
                return sanitize_key( is_scalar( $value ) ? (string) $value : '' );

            case 'textarea':
                return sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );

            case 'rich_text':
                return wp_kses( is_scalar( $value ) ? (string) $value : '', self::RICH_TEXT_ALLOWED_TAGS );

            case 'html':
                return self::sanitize_html_value( is_scalar( $value ) ? (string) $value : '' );

            case 'url':
                return self::sanitize_url_value( $value );

            case 'email':
                return sanitize_email( is_scalar( $value ) ? (string) $value : '' );

            case 'product_tags':
                return self::sanitize_product_tags( $value, (int) ( $spec['max_items'] ?? 6 ) );

            case 'number':
                if ( $allow_empty && ( $value === '' || $value === null ) ) return '';
                if ( ! is_numeric( $value ) ) {
                    $value = $spec['default'] ?? ( $spec['min'] ?? 0 );
                    if ( is_array( $value ) ) $value = $value['desktop'] ?? 0;
                }
                $n = (int) round( (float) $value );
                if ( isset( $spec['min'] ) ) $n = max( (int) $spec['min'], $n );
                if ( isset( $spec['max'] ) ) $n = min( (int) $spec['max'], $n );
                return $n;

            case 'length':
                return self::sanitize_css_length( $value, ! empty( $spec['allow_negative'] ), ! empty( $spec['unitless'] ) );

            case 'color':
                return self::sanitize_color_value( $value );

            case 'font':
                // A brand font (Branding → Brand fonts), or '' for the item's default.
                return Qwoo_Brand_Fonts::sanitize_choice( $value );

            case 'loop_template':
                // A card design (trait-sb-loops.php), or '' for the built-in card.
                $value = is_scalar( $value ) ? trim( (string) $value ) : '';
                return preg_match( '/^lt_[A-Za-z0-9]{6,20}$/', $value ) ? $value : '';

            case 'menu':
                $value = is_scalar( $value ) ? trim( (string) $value ) : '';
                return preg_match( '/^menu_[A-Za-z0-9_]{3,30}$/', $value ) ? $value : ( in_array( $value, [ 'menu_header', 'menu_footer' ], true ) ? $value : '' );

            case 'select':
                $options = is_array( $spec['options'] ?? null ) ? $spec['options'] : [];
                $value   = is_scalar( $value ) ? (string) $value : '';
                if ( ! empty( $options ) && array_key_exists( $value, $options ) ) return $value;
                if ( empty( $options ) && ( $spec['options'] ?? '' ) === 'menus' ) {
                    return preg_match( '/^menu_[A-Za-z0-9_]{3,30}$/', $value ) || in_array( $value, [ 'menu_header', 'menu_footer' ], true ) ? $value : '';
                }
                if ( $allow_empty && $value === '' ) return '';
                $default = $spec['default'] ?? null;
                if ( is_array( $default ) ) $default = $default['desktop'] ?? null;
                if ( $default !== null && ! empty( $options ) && array_key_exists( (string) $default, $options ) ) return (string) $default;
                $first = array_key_first( $options );
                return $first === null ? '' : (string) $first;

            case 'toggle':
                // On a smaller screen, '' = the same as the bigger screen.
                if ( $allow_empty && ( $value === '' || $value === null ) ) return '';
                return ! empty( $value ) && $value !== 'false' && $value !== '0';

            case 'image':
                $id = self::sanitize_attachment_id( $value );
                if ( $id && ( $spec['accept'] ?? '' ) === 'svg' && get_post_mime_type( $id ) !== 'image/svg+xml' ) return 0;
                return $id;

            case 'products':
            case 'categories':
            case 'tags':
                if ( ! is_array( $value ) ) return [];
                $ids = array_values( array_unique( array_filter( array_map( 'intval', $value ), function ( $id ) { return $id > 0; } ) ) );
                return array_slice( $ids, 0, 50 );

            case 'icon':
                $set   = self::ICON_SETS[ $spec['icon_set'] ?? 'advantage' ] ?? self::ADVANTAGE_ICONS;
                $value = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
                if ( $value === 'custom' || array_key_exists( $value, $set ) ) return $value;
                $default = $spec['default'] ?? '';
                return array_key_exists( $default, $set ) ? $default : (string) array_key_first( $set );

            case 'video':
                $id = self::sanitize_attachment_id( $value );
                return ( $id && strpos( (string) get_post_mime_type( $id ), 'video/' ) === 0 ) ? $id : 0;

            case 'datetime':
                // <input type="datetime-local"> value, in the store's timezone.
                $value = is_scalar( $value ) ? trim( (string) $value ) : '';
                return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : '';

            case 'sides':
                $value = is_array( $value ) ? $value : [];
                $out   = [];
                foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
                    $out[ $side ] = self::sanitize_css_length( $value[ $side ] ?? '', ! empty( $spec['allow_negative'] ) );
                }
                return $out;

            case 'devices':
                $value = is_array( $value ) ? $value : [];
                $out   = [];
                foreach ( self::DEVICES as $device ) {
                    $out[ $device ] = ! empty( $value[ $device ] ) && $value[ $device ] !== 'false';
                }
                return $out;

            case 'anchor':
                $value = is_scalar( $value ) ? (string) $value : '';
                $value = preg_replace( '/[^A-Za-z0-9_-]/', '', str_replace( ' ', '-', trim( ltrim( $value, '#' ) ) ) );
                if ( $value !== '' && ! preg_match( '/^[A-Za-z]/', $value ) ) $value = 'a-' . $value;
                return substr( $value, 0, 64 );

            case 'repeater':
                $items = [];
                if ( is_array( $value ) ) {
                    foreach ( array_slice( array_values( $value ), 0, (int) ( $spec['max_items'] ?? 20 ) ) as $item ) {
                        $item = self::sanitize_fields( $spec['fields'] ?? [], $item );
                        if ( ! empty( $spec['require'] ) && empty( $item[ $spec['require'] ] ) ) continue;
                        $items[] = $item;
                    }
                }
                return $items;
        }

        // Unknown type — never pass raw input through.
        return '';
    }

    private static function sanitize_attachment_id( $value ) {
        $id = is_scalar( $value ) ? intval( $value ) : 0;
        return ( $id > 0 && get_post_type( $id ) === 'attachment' ) ? $id : 0;
    }

    /** Text block HTML: whitelisted tags; links opening in a new tab get rel=noopener. */
    private static function sanitize_html_value( $html ) {
        $html = wp_kses( $html, self::HTML_ALLOWED_TAGS );
        return preg_replace_callback( '/<a\s[^>]*target=("|\')_blank\1[^>]*>/i', function ( $m ) {
            return stripos( $m[0], 'rel=' ) === false
                    ? substr( $m[0], 0, -1 ) . ' rel="noopener noreferrer">'
                    : $m[0];
        }, $html );
    }

    /** Accepts absolute http(s)/mailto/tel URLs plus site-relative "/path" and "#anchor". */
    private static function sanitize_url_value( $value ) {
        $value = is_scalar( $value ) ? trim( (string) $value ) : '';
        if ( $value === '' ) return '';
        if ( $value[0] === '#' ) {
            return '#' . preg_replace( '/[^A-Za-z0-9_-]/', '', substr( $value, 1 ) );
        }
        return esc_url_raw( $value, [ 'http', 'https', 'mailto', 'tel' ] );
    }

    /**
     * A CSS length for free-typed sizing values: a number (px assumed) or
     * number + px/%/rem/em/vh/vw, or 'auto'. '' is valid (= unset/inherit).
     * $unitless allows bare ratios like line-height 1.6 to stay unitless.
     */
    private static function sanitize_css_length( $value, $allow_negative = false, $unitless = false ) {
        $value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
        if ( $value === '' ) return '';
        if ( $value === 'auto' && ! $unitless ) return 'auto';
        $sign = $allow_negative ? '-?' : '';
        return preg_match( '/^' . $sign . '\d+(\.\d+)?(px|%|rem|em|vh|vw)?$/', $value ) ? $value : '';
    }

    /* =====================================================================
       Section / block tree
       ===================================================================== */

    private static function new_id( $prefix ) {
        return $prefix . '_' . substr( wp_generate_password( 12, false ), 0, 10 );
    }

    /**
     * Per-block fix-ups that go beyond single-field validation. Runs after
     * sanitize_fields() on a block's data.
     */
    private static function post_sanitize_block_data( $type, array $data ) {
        if ( $type === 'advantages' || $type === 'icon_list' ) {
            $fallback = $type === 'advantages' ? 'shipping' : 'check';
            foreach ( $data['items'] as &$item ) {
                // "custom" selected but no image uploaded -> fall back to a built-in icon.
                if ( $item['icon'] === 'custom' && empty( $item['custom_icon'] ) ) $item['icon'] = $fallback;
                if ( $item['icon'] !== 'custom' ) $item['custom_icon'] = 0;
            }
            unset( $item );
        }

        if ( $type === 'icon' ) {
            if ( $data['icon'] === 'custom' && empty( $data['custom_icon'] ) ) $data['icon'] = 'star';
            if ( $data['icon'] !== 'custom' ) $data['custom_icon'] = 0;
        }

        if ( $type === 'testimonials' ) {
            foreach ( $data['items'] as &$item ) {
                $item['rating'] = (int) $item['rating'];
            }
            unset( $item );
        }

        if ( $type === 'form' ) {
            $used = [];
            foreach ( $data['fields'] as &$field ) {
                // Stable machine key per field, used as the submitted value's
                // name — derived from the label once, then kept even if the
                // label is edited later (so existing entries stay readable).
                $key = $field['key'] ?: sanitize_key( str_replace( ' ', '_', $field['label'] ) );
                $key = $key ?: 'field';
                $base = $key;
                $n = 2;
                while ( isset( $used[ $key ] ) ) $key = $base . '_' . $n++;
                $used[ $key ] = true;
                $field['key'] = $key;

                if ( in_array( $field['field_type'], self::FORM_FIELD_TYPES_WITH_OPTIONS, true ) ) {
                    $lines = array_filter( array_map( 'trim', explode( "\n", $field['options'] ) ), 'strlen' );
                    $field['options'] = implode( "\n", array_slice( array_values( array_unique( $lines ) ), 0, 50 ) );
                } else {
                    $field['options'] = '';
                }
            }
            unset( $field );
        }

        return $data;
    }

    /**
     * Sanitizes a container's `blocks` list. $depth is the depth of the
     * container that OWNS this list (top-level section = 1); `section`
     * blocks beyond MAX_NESTING_DEPTH are dropped.
     */
    private function sanitize_blocks( $raw_blocks, $depth = 1 ) {
        if ( ! is_array( $raw_blocks ) ) return [];

        $clean = [];
        foreach ( array_slice( array_values( $raw_blocks ), 0, self::MAX_BLOCKS ) as $block ) {
            if ( ! is_array( $block ) ) continue;

            $type   = sanitize_key( $block['type'] ?? '' );
            $schema = self::get_type_schema( $type );
            if ( ! $schema ) continue; // reject unknown/removed types

            $id = preg_match( '/^blk_[a-zA-Z0-9]{6,20}$/', $block['id'] ?? '' ) ? $block['id'] : self::new_id( 'blk' );

            $row = [
                    'id'      => $id,
                    'type'    => $type,
                    'label'   => sanitize_text_field( $block['label'] ?? '' ),
                    'enabled' => ! empty( $block['enabled'] ) && $block['enabled'] !== 'false',
            ];

            if ( $type === 'section' ) {
                if ( $depth >= self::MAX_NESTING_DEPTH ) continue;
                $row['style']  = self::sanitize_fields( self::SECTION_STYLE_FIELDS, $block['style'] ?? [] );
                $row['blocks'] = $this->sanitize_blocks( $block['blocks'] ?? [], $depth + 1 );
            } else {
                $row['style'] = self::sanitize_fields( self::BLOCK_COMMON_FIELDS, $block['style'] ?? [] );
                $row['data']  = self::post_sanitize_block_data( $type, self::sanitize_fields( $schema['fields'], $block['data'] ?? [] ) );
            }

            $clean[] = $row;
        }

        return $clean;
    }

    /**
     * Sanitizes a page's `sections` list. (The shop pages' old `location`
     * slots are placed before this, by shop_page_as_template().)
     */
    private function sanitize_sections( $raw_sections, $page_slug = 'home' ) {
        if ( ! is_array( $raw_sections ) ) return [];

        $clean = [];
        foreach ( array_slice( array_values( $raw_sections ), 0, self::MAX_SECTIONS ) as $section ) {
            if ( ! is_array( $section ) ) continue;

            $id = preg_match( '/^sec_[a-zA-Z0-9]{6,20}$/', $section['id'] ?? '' ) ? $section['id'] : self::new_id( 'sec' );

            $row = [
                    'id'      => $id,
                    'label'   => sanitize_text_field( $section['label'] ?? '' ),
                    'enabled' => ! empty( $section['enabled'] ) && $section['enabled'] !== 'false',
                    'style'   => self::sanitize_fields( self::SECTION_STYLE_FIELDS, $section['style'] ?? [] ),
                    'blocks'  => $this->sanitize_blocks( $section['blocks'] ?? [] ),
            ];

            $clean[] = $row;
        }

        return $clean;
    }

    /* =====================================================================
       Public output (REST preview + GitHub push)
       ===================================================================== */

    /**
     * Fills empty tablet/mobile values from the next larger device, so the
     * frontend always receives three concrete values and never has to know
     * about inheritance. `sides` values inherit per side.
     */
    private static function resolve_responsive( $value, $type ) {
        if ( ! self::is_device_map( $value ) ) {
            return [ 'desktop' => $value, 'tablet' => $value, 'mobile' => $value ];
        }

        $out  = [];
        $prev = null;
        foreach ( self::DEVICES as $device ) {
            $v = $value[ $device ] ?? '';
            if ( $type === 'sides' ) {
                $v = is_array( $v ) ? $v : [];
                foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
                    if ( ( $v[ $side ] ?? '' ) === '' && $prev !== null ) $v[ $side ] = $prev[ $side ] ?? '';
                    $v[ $side ] = $v[ $side ] ?? '';
                }
            } elseif ( ( $v === '' || ( $v === 0 && ( $type === 'image' || $type === 'video' ) ) ) && $prev !== null ) {
                // An image left empty (0) on a smaller screen uses the bigger screen's.
                $v = $prev;
            }
            $out[ $device ] = $v;
            $prev = $v;
        }
        return $out;
    }

    /**
     * Converts a stored data object into its public shape. $image_cb maps an
     * attachment ID to the public image payload (or null).
     */
    private static function output_fields( array $specs, $data, callable $image_cb ) {
        $data = self::fold_old_margins( $specs, is_array( $data ) ? $data : [] );
        $out  = [];

        foreach ( $specs as $key => $spec ) {
            if ( ! empty( $spec['private'] ) ) continue;
            if ( ! array_key_exists( $key, $data ) ) continue;

            $value = $data[ $key ];
            $type  = $spec['type'] ?? 'text';

            if ( $type === 'repeater' ) {
                $items = [];
                foreach ( is_array( $value ) ? $value : [] as $item ) {
                    $items[] = self::output_fields( $spec['fields'] ?? [], $item, $image_cb );
                }
                $out[ $key ] = $items;
                continue;
            }

            if ( ( $type === 'image' || $type === 'video' ) && ! empty( $spec['responsive'] ) ) {
                // One image per screen size: inherit the IDs first, then make each one public.
                $ids = self::resolve_responsive( self::is_device_map( $value ) ? array_map( 'intval', $value ) : (int) $value, $type );
                $media = array_map( static function ( $id ) use ( $image_cb ) { return $id ? $image_cb( (int) $id ) : null; }, $ids );
                // Published as the desktop image ({ url, width, height }, what storefronts
                // before per-screen images read) plus `tablet` and `mobile`.
                $out[ $key ] = ( $media['desktop'] || $media['tablet'] || $media['mobile'] )
                    ? array_merge( $media['desktop'] ?: [ 'url' => '' ], [ 'tablet' => $media['tablet'], 'mobile' => $media['mobile'] ] )
                    : null;
                continue;
            }

            if ( $type === 'image' || $type === 'video' ) {
                $value = $value ? $image_cb( (int) $value ) : null;
            }

            if ( $type === 'datetime' ) {
                $value = self::datetime_to_iso( $value );
            }

            if ( ! empty( $spec['responsive'] ) ) {
                $value = self::resolve_responsive( $value, $type );
            }

            if ( ! empty( $spec['screens'] ) && is_array( $value ) ) {
                // A look that can differ per screen size: published as the desktop value (what
                // storefronts before per-screen looks read), plus _screens[key] = { tablet, mobile }
                // only when another screen size differs.
                $out[ $key ] = $value['desktop'] ?? '';
                if ( ( $value['tablet'] ?? '' ) !== $out[ $key ] || ( $value['mobile'] ?? '' ) !== $out[ $key ] ) {
                    $out['_screens'][ $key ] = [ 'tablet' => $value['tablet'] ?? '', 'mobile' => $value['mobile'] ?? '' ];
                }
                continue;
            }

            $out[ $key ] = $value;
        }

        if ( isset( $specs['margin'] ) && is_array( $out['margin'] ?? null ) ) {
            // Storefronts from before the Margin field read margin_top / margin_bottom.
            foreach ( [ 'top', 'bottom' ] as $side ) {
                $out[ 'margin_' . $side ] = array_map( static function ( $sides ) use ( $side ) {
                    return is_array( $sides ) ? ( $sides[ $side ] ?? '' ) : '';
                }, $out['margin'] );
            }
        }

        return $out;
    }

    /**
     * Position was one value plus "Not Sticky On" (`sticky_off`, devices) in its
     * first build (2026-10-21); it's per screen size now: each screen size left
     * out becomes 'static'.
     */
    private static function fold_old_sticky( array $specs, array $raw ) {
        if ( ! isset( $specs['position'] ) || ! array_key_exists( 'sticky_off', $raw ) ) return $raw;
        $off = is_array( $raw['sticky_off'] ) ? $raw['sticky_off'] : [];
        unset( $raw['sticky_off'] );
        $pos = $raw['position'] ?? 'static';
        if ( self::is_device_map( $pos ) || $pos !== 'sticky' ) return $raw;
        $map = [];
        foreach ( self::DEVICES as $device ) {
            $map[ $device ] = ! empty( $off[ $device ] ) && $off[ $device ] !== 'false' ? 'static' : 'sticky';
        }
        $raw['position'] = $map;
        return $raw;
    }

    /**
     * "Space Above" / "Space Below" (margin_top / margin_bottom) became one
     * Margin field with four sides (2026-10). Data saved before then, and
     * templates published before then, are read as that field.
     * Also folds the first sticky build's fields (fold_old_sticky()).
     */
    private static function fold_old_margins( array $specs, array $raw ) {
        $raw = self::fold_old_sticky( $specs, $raw );
        if ( ! isset( $specs['margin'] ) || array_key_exists( 'margin', $raw ) ) return $raw;
        if ( ! array_key_exists( 'margin_top', $raw ) && ! array_key_exists( 'margin_bottom', $raw ) ) return $raw;
        $at = static function ( $v, $device ) {
            if ( self::is_device_map( $v ) ) $v = $v[ $device ] ?? '';
            elseif ( $device !== 'desktop' ) $v = '';
            return is_scalar( $v ) ? (string) $v : '';
        };
        $margin = [];
        foreach ( self::DEVICES as $device ) {
            $margin[ $device ] = [ 'top' => $at( $raw['margin_top'] ?? '', $device ), 'right' => '', 'bottom' => $at( $raw['margin_bottom'] ?? '', $device ), 'left' => '' ];
        }
        unset( $raw['margin_top'], $raw['margin_bottom'] );
        $raw['margin'] = $margin;
        return $raw;
    }

    /**
     * "2026-12-31T23:59" in the store's timezone -> ISO 8601 with offset
     * ("2026-12-31T23:59:00+02:00"), so every visitor counts down to the
     * same moment. Mirrored by datetimeToIso() in sb-builder-fields.js.
     */
    private static function datetime_to_iso( $value ) {
        if ( ! is_string( $value ) || $value === '' ) return '';
        try {
            return ( new DateTimeImmutable( $value, wp_timezone() ) )->format( 'Y-m-d\TH:i:sP' );
        } catch ( Exception $e ) {
            return '';
        }
    }

    /** Recursively converts a blocks list to public shape (disabled blocks dropped). */
    private static function output_blocks( array $blocks, callable $image_cb ) {
        $out = [];
        foreach ( $blocks as $block ) {
            if ( empty( $block['enabled'] ) ) continue;
            $type   = $block['type'] ?? '';
            $schema = self::get_type_schema( $type );
            if ( ! $schema ) continue;

            $row = [ 'id' => $block['id'], 'type' => $type, 'enabled' => true ];

            if ( $type === 'section' ) {
                $row['style']  = self::output_fields( self::SECTION_STYLE_FIELDS, $block['style'] ?? [], $image_cb );
                $row['blocks'] = self::output_blocks( $block['blocks'] ?? [], $image_cb );
            } else {
                $row['style'] = self::output_fields( self::BLOCK_COMMON_FIELDS, $block['style'] ?? [], $image_cb );
                $row['data']  = self::output_fields( $schema['fields'], $block['data'] ?? [], $image_cb );
                // The card design it chose, ready to draw (trait-sb-loops.php).
                $card = self::published_card( $type, (array) ( $block['data'] ?? [] ) );
                if ( $card !== null ) $row['data']['card'] = $card;
                self::add_inline_svgs( $type, (array) ( $block['data'] ?? [] ), $row['data'] );
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * SVG icons published as code too (`svg` next to the image's url), so the
     * storefront draws them in the page and can colour them. Per block type:
     * [ repeater ('' = the block itself), image field, toggle that must be on
     * (or null), the field that asks for the block's colour ].
     */
    private static $inline_svg_fields = [
            'icon_list' => [ 'items', 'custom_icon', null, 'svg_recolor' ],
            'icon'      => [ '', 'custom_icon', null, 'recolor' ],
            'image_block' => [ 'images', 'image', 'svg_inline', 'svg_color' ],
    ];

    private static $inline_svg_cache = [];

    private static function add_inline_svgs( $type, array $raw, array &$data ) {
        $spec = self::$inline_svg_fields[ $type ] ?? null;
        if ( ! $spec || ( $spec[2] && empty( $raw[ $spec[2] ] ) ) ) return;
        [ $list, $field, , $recolor_field ] = $spec;
        $recolor = ! empty( $raw[ $recolor_field ] );
        if ( $list === '' ) {
            $svg = self::inline_svg( (int) ( $raw[ $field ] ?? 0 ), $recolor );
            if ( $svg !== null && is_array( $data[ $field ] ?? null ) ) $data[ $field ]['svg'] = $svg;
            return;
        }
        // Published items are the stored items in the same order (output_fields keeps them all).
        foreach ( array_values( (array) ( $raw[ $list ] ?? [] ) ) as $i => $item ) {
            $svg = self::inline_svg( (int) ( $item[ $field ] ?? 0 ), $recolor );
            if ( $svg !== null && is_array( $data[ $list ][ $i ][ $field ] ?? null ) ) $data[ $list ][ $i ][ $field ]['svg'] = $svg;
        }
    }

    /** An SVG attachment's markup for the page (Qwoo_Svg_Sanitizer::inline_markup), or null. */
    private static function inline_svg( $id, $recolor = false ) {
        if ( $id <= 0 ) return null;
        $key = $id . ( $recolor ? 'c' : '' );
        if ( array_key_exists( $key, self::$inline_svg_cache ) ) return self::$inline_svg_cache[ $key ];
        $svg  = null;
        $file = get_post_mime_type( $id ) === 'image/svg+xml' ? get_attached_file( $id ) : '';
        if ( $file && is_readable( $file ) && filesize( $file ) <= 512 * 1024 && class_exists( 'Qwoo_Svg_Sanitizer' ) ) {
            $raw = file_get_contents( $file );
            if ( is_string( $raw ) ) $svg = Qwoo_Svg_Sanitizer::inline_markup( $raw, 'i' . $id, $recolor );
        }
        return self::$inline_svg_cache[ $key ] = $svg;
    }

    /** Converts a page's stored sections to public shape (disabled sections dropped). */
    private static function output_sections( array $sections, callable $image_cb ) {
        $out = [];
        foreach ( $sections as $section ) {
            if ( empty( $section['enabled'] ) ) continue;
            $row = [
                    'id'      => $section['id'],
                    'enabled' => true,
                    'style'   => self::output_fields( self::SECTION_STYLE_FIELDS, $section['style'] ?? [], $image_cb ),
                    'blocks'  => self::output_blocks( $section['blocks'] ?? [], $image_cb ),
            ];
            if ( isset( $section['location'] ) ) $row['location'] = $section['location'];
            $out[] = $row;
        }
        return $out;
    }

    /** Public image payload for an attachment, served from $url. */
    private static function attachment_payload( $attachment_id, $url ) {
        if ( ! $url ) return null;
        $meta = wp_get_attachment_metadata( $attachment_id );
        return [
                'url'    => $url,
                'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : null,
                'height' => isset( $meta['height'] ) ? (int) $meta['height'] : null,
        ];
    }

    /* =====================================================================
       register_setting() sanitize callback
       ===================================================================== */

    // Pages that carry a `sections` list: sectionable_pages() in trait-sb-layouts.php.

    /**
     * A header's scrolling settings (the main header's, and each header
     * template's own). Sticky: in the page, stays at the top. Fixed: floats
     * over the first section (a hero), with its own colours once scrolled or
     * on pages that don't start with one. wp-admin's form doesn't send the
     * mode or colours, so missing ones keep what was saved.
     */
    private static function sanitize_header_settings( array $in, array $old ) {
        $mode = $in['sticky_mode'] ?? ( $old['sticky_mode'] ?? 'sticky' );
        return [
                'sticky'      => ! empty( $in['sticky'] ),
                'sticky_mode' => in_array( $mode, [ 'sticky', 'fixed' ], true ) ? $mode : 'sticky',
                'solid_bg'    => self::sanitize_color_value( $in['solid_bg'] ?? ( $old['solid_bg'] ?? '' ) ),
                'solid_text'  => self::sanitize_color_value( $in['solid_text'] ?? ( $old['solid_text'] ?? '' ) ),
                // The gap above the header while it stays. (`away_rows`, "Sections that scroll away", was removed: the user's call.)
                'sticky_top'  => self::sanitize_css_length( $in['sticky_top'] ?? ( $old['sticky_top'] ?? '' ) ),
                // The whole header's background and padding (around its sections); empty = the site's background, no padding.
                'bg'          => self::sanitize_color_value( $in['bg'] ?? ( $old['bg'] ?? '' ) ),
                'padding'     => self::sanitize_header_padding( $in['padding'] ?? ( $old['padding'] ?? [] ) ),
        ];
    }

    private static function sanitize_header_padding( $value ) {
        $value = is_array( $value ) ? $value : [];
        $out   = [];
        foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
            $out[ $side ] = self::sanitize_css_length( $value[ $side ] ?? '' );
        }
        return $out;
    }

    public function sanitize_options( $input ) {
        $input    = is_array( $input ) ? $input : [];
        $existing = get_option( 'shop_builder_options', [] );
        $clean    = [];

        // Header
        if ( isset( $input['header'] ) ) {
            $header = [];
            $ann = $input['header']['announcement'] ?? [];
            $header['announcement'] = [
                    'enabled'    => ! empty( $ann['enabled'] ),
                    'text'       => sanitize_text_field( $ann['text'] ?? '' ),
                    'bg_color'   => self::sanitize_color_value( $ann['bg_color'] ?? '' ),
                    'text_color' => self::sanitize_color_value( $ann['text_color'] ?? '' ),
            ];
            // Sticky: in the page, stays at the top. Fixed: floats over the
            // first section (a hero), with its own colours once scrolled or on
            // pages that don't start with one. wp-admin's form doesn't send
            // the mode or colours, so missing ones keep what was saved.
            $header['settings'] = self::sanitize_header_settings( (array) ( $input['header']['settings'] ?? [] ), (array) ( $existing['header']['settings'] ?? [] ) );
            if ( isset( $input['header']['navigation'] ) && is_array( $input['header']['navigation'] ) ) {
                $nav = [];
                foreach ( $input['header']['navigation'] as $key => $item ) {
                    $nav[] = [
                            'key'     => sanitize_key( $item['key'] ?? $key ),
                            'label'   => sanitize_text_field( $item['label'] ?? '' ),
                            'enabled' => ! empty( $item['enabled'] ),
                    ];
                }
                $header['navigation'] = $nav;
            }
            $clean['header'] = $header;
        }

        // Footer
        if ( isset( $input['footer'] ) ) {
            $clean['footer'] = [
                    'footer_text' => wp_kses( (string) ( $input['footer']['footer_text'] ?? '' ), self::RICH_TEXT_ALLOWED_TAGS ),
            ];
        }

        // Home (hero fields — sections handled below with every other page)
        if ( isset( $input['home'] ) ) {
            $home = $input['home'];
            $clean['home'] = [
                    'hero_title'       => wp_kses( (string) ( $home['hero_title'] ?? '' ), self::RICH_TEXT_ALLOWED_TAGS ),
                    'hero_description' => wp_kses( (string) ( $home['hero_description'] ?? '' ), self::RICH_TEXT_ALLOWED_TAGS ),
                    'hero_btn'         => [
                            'text' => sanitize_text_field( $home['hero_btn']['text'] ?? '' ),
                            'url'  => self::sanitize_url_value( $home['hero_btn']['url'] ?? '' ),
                    ],
                    'hero_image_id'    => self::sanitize_attachment_id( $home['hero_image_id'] ?? 0 ),
            ];
        }

        // Sections: home + every hookable page. If a page's sections weren't
        // part of this submission at all (e.g. a plain options.php post,
        // where the builder's JSON isn't included), keep what's stored
        // instead of wiping them.
        // An older shop page (sections in slots, from an import or an older dashboard) becomes its template first.
        foreach ( self::SHOP_TEMPLATE_PAGES as $page_slug ) {
            if ( is_array( $input[ $page_slug ] ?? null ) && ( isset( $input[ $page_slug ]['sections'] ) || isset( $input[ $page_slug ]['layouts'] ) ) ) {
                $input[ $page_slug ] = self::shop_page_as_template( $input[ $page_slug ], $page_slug );
            }
        }
        foreach ( self::sectionable_pages() as $page_slug ) {
            if ( isset( $input[ $page_slug ]['sections'] ) ) {
                $sections = $this->sanitize_sections( $input[ $page_slug ]['sections'], $page_slug );
            } elseif ( isset( $existing[ $page_slug ]['sections'] ) ) {
                $sections = $existing[ $page_slug ]['sections'];
            } else {
                continue;
            }
            $clean[ $page_slug ] = ( $clean[ $page_slug ] ?? [] ) + [ 'sections' => $sections ];
            if ( in_array( $page_slug, self::SHOP_TEMPLATE_PAGES, true ) ) {
                $clean[ $page_slug ]['template'] = 1;
            }
        }

        // Layouts with conditions (trait-sb-layouts.php); kept as stored when not sent.
        foreach ( array_keys( self::LAYOUT_PAGES ) as $page_slug ) {
            if ( isset( $input[ $page_slug ]['layouts'] ) ) {
                $layouts = $this->sanitize_layouts( $input[ $page_slug ]['layouts'], $page_slug, (array) ( $existing[ $page_slug ]['layouts'] ?? [] ) );
            } elseif ( isset( $existing[ $page_slug ]['layouts'] ) ) {
                $layouts = $existing[ $page_slug ]['layouts'];
            } else {
                continue;
            }
            $clean[ $page_slug ] = ( $clean[ $page_slug ] ?? [] ) + [ 'layouts' => $layouts ];
        }

        // The owner's own pages (trait-sb-pages.php) and the menus (trait-sb-menus.php).
        if ( isset( $input['custom_pages'] ) ) {
            $clean['custom_pages'] = $this->sanitize_custom_pages( $input['custom_pages'], self::custom_pages_of( $existing ) );
        }
        if ( isset( $input['menus'] ) ) {
            $clean['menus'] = $this->sanitize_menus( $input['menus'] );
        }
        // Card designs (trait-sb-loops.php); kept as stored when not sent.
        if ( isset( $input['loops'] ) ) {
            $clean['loops'] = $this->sanitize_loops( $input['loops'] );
        }
        if ( isset( $input['loops_default'] ) ) {
            $clean['loops_default'] = self::sanitize_loops_default( $input['loops_default'], $clean['loops'] ?? (array) ( $existing['loops'] ?? [] ) );
        }

        // Checkout (its sections are set above: merged, not replaced)
        if ( isset( $input['checkout']['checkout_notice'] ) || isset( $input['checkout']['require_terms'] ) ) {
            $clean['checkout'] = [
                    'checkout_notice' => sanitize_textarea_field( $input['checkout']['checkout_notice'] ?? '' ),
                    // Customers tick "I agree to the terms and the privacy policy" (when those pages are published).
                    'require_terms'   => ! empty( $input['checkout']['require_terms'] ),
                    // Guests can tick "Create an account" (WooCommerce emails them a link to set a password).
                    'allow_signup'    => ! empty( $input['checkout']['allow_signup'] ),
            ] + ( $clean['checkout'] ?? [] );
        }

        // Branding
        if ( isset( $input['branding'] ) ) {
            $colors = [];
            foreach ( self::GLOBAL_COLOR_KEYS as $key => $meta ) {
                $colors[ $key ] = sanitize_hex_color( $input['branding']['global_colors'][ $key ] ?? '' ) ?: '';
            }
            // Logo (any aspect ratio) and app icon (square) are separate on purpose.
            $clean['branding'] = [
                    'global_colors' => $colors,
                    'logo_id'       => self::sanitize_attachment_id( $input['branding']['logo_id'] ?? 0 ),
                    'app_icon_id'   => self::sanitize_attachment_id( $input['branding']['app_icon_id'] ?? 0 ),
            ];
            // Brand fonts: only from the dashboard (wp-admin's Branding form keeps them as they are).
            if ( isset( $input['branding']['fonts'] ) ) {
                $clean['branding']['fonts'] = Qwoo_Brand_Fonts::sanitize( $input['branding']['fonts'] );
            }
        }

        // PWA manifest
        if ( isset( $input['pwa'] ) ) {
            $clean['pwa'] = [
                    'name'             => sanitize_text_field( $input['pwa']['name'] ?? '' ),
                    'short_name'       => sanitize_text_field( ( $input['pwa']['short_name'] ?? '' ) ?: ( $input['pwa']['name'] ?? '' ) ),
                    'description'      => sanitize_textarea_field( $input['pwa']['description'] ?? '' ),
                    // Manifest colors must be opaque (no #rrggbbaa).
                    'theme_color'      => self::sanitize_color_value( $input['pwa']['theme_color'] ?? '', false ),
                    'background_color' => self::sanitize_color_value( $input['pwa']['background_color'] ?? '', false ),
            ];
        }

        // Contact button
        if ( isset( $input['contact'] ) ) {
            $clean['contact'] = [
                    'enabled' => ! empty( $input['contact']['enabled'] ),
                    'methods' => $this->sanitize_contact_methods( $input['contact']['methods'] ?? [] ),
            ];
        }

        // Internal bookkeeping — never user-editable.
        $clean['_schema_version'] = $existing['_schema_version'] ?? self::SCHEMA_VERSION;

        return $clean;
    }
}

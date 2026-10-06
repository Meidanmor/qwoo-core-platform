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
        $raw   = is_array( $raw ) ? $raw : [];
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

        if ( $value === null ) $value = $spec['default'] ?? null;
        return self::sanitize_scalar( $spec, $value, false );
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

            case 'select':
                $options = $spec['options'] ?? [];
                $value   = is_scalar( $value ) ? (string) $value : '';
                if ( array_key_exists( $value, $options ) ) return $value;
                if ( $allow_empty && $value === '' ) return '';
                $default = $spec['default'] ?? null;
                if ( is_array( $default ) ) $default = $default['desktop'] ?? null;
                if ( $default !== null && array_key_exists( (string) $default, $options ) ) return (string) $default;
                $first = array_key_first( $options );
                return $first === null ? '' : (string) $first;

            case 'toggle':
                return ! empty( $value ) && $value !== 'false' && $value !== '0';

            case 'image':
                return self::sanitize_attachment_id( $value );

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
                    $out[ $side ] = self::sanitize_css_length( $value[ $side ] ?? '' );
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
     * Sanitizes a page's `sections` list. Pages listed in
     * PAGE_SECTION_LOCATIONS also get a validated `location` slug (unknown
     * falls back to the first slot, so a section can never end up unplaced).
     */
    private function sanitize_sections( $raw_sections, $page_slug = 'home' ) {
        if ( ! is_array( $raw_sections ) ) return [];

        $locations = self::PAGE_SECTION_LOCATIONS[ $page_slug ] ?? null;

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

            if ( $locations !== null ) {
                $loc = sanitize_key( $section['location'] ?? '' );
                $row['location'] = array_key_exists( $loc, $locations ) ? $loc : ( array_key_first( $locations ) ?: '' );
            }

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
            } elseif ( $v === '' && $prev !== null ) {
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
        $data = is_array( $data ) ? $data : [];
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

            if ( $type === 'image' || $type === 'video' ) {
                $value = $value ? $image_cb( (int) $value ) : null;
            }

            if ( $type === 'datetime' ) {
                $value = self::datetime_to_iso( $value );
            }

            if ( ! empty( $spec['responsive'] ) ) {
                $value = self::resolve_responsive( $value, $type );
            }

            $out[ $key ] = $value;
        }

        return $out;
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
            }
            $out[] = $row;
        }
        return $out;
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

    /** Pages that carry a `sections` list. */
    private static function sectionable_pages() {
        return array_merge( [ 'home' ], array_keys( self::PAGE_SECTION_LOCATIONS ) );
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
            $header['settings'] = [
                    'sticky'      => ! empty( $input['header']['settings']['sticky'] ),
                    'show_search' => ! empty( $input['header']['settings']['show_search'] ),
            ];
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
        foreach ( self::sectionable_pages() as $page_slug ) {
            if ( isset( $input[ $page_slug ]['sections'] ) ) {
                $sections = $this->sanitize_sections( $input[ $page_slug ]['sections'], $page_slug );
            } elseif ( isset( $existing[ $page_slug ]['sections'] ) ) {
                $sections = $existing[ $page_slug ]['sections'];
            } else {
                continue;
            }
            $clean[ $page_slug ] = ( $clean[ $page_slug ] ?? [] ) + [ 'sections' => $sections ];
        }

        // The owner's own pages (trait-sb-pages.php).
        if ( isset( $input['custom_pages'] ) ) {
            $clean['custom_pages'] = $this->sanitize_custom_pages( $input['custom_pages'], self::custom_pages_of( $existing ) );
        }

        // Checkout
        if ( isset( $input['checkout'] ) ) {
            $clean['checkout'] = [
                    'checkout_notice' => sanitize_textarea_field( $input['checkout']['checkout_notice'] ?? '' ),
            ];
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

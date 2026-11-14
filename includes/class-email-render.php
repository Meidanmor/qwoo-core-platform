<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The email designer's output, shared by marketing emails and the store's
 * automatic emails (Qwoo_Store_Emails).
 *
 *   design = { settings: { bg, content_bg, color, link, button_bg, button_color, width, font }, rows: [ row ] }
 *   row    = { id, layout: '1' | '1-1' | ..., r: { bg, padV, gap, stack, hideOn }, cols: [ [ block ] ] }
 *   block  = { id, type, data }
 *
 * Designs from before rows ({ blocks: [...] }) are read as one row per block.
 * clean() is what stands between the dashboard and the database; html() makes
 * tables and inline styles, the only thing every mail app shows the same.
 *
 * html()'s $ctx: preheader, footer (required part, HTML), link (callable that
 * wraps every address), tokens ([ '{first_name}' => 'Dana' ]), urls ([ 'order' => ... ]),
 * order (WC_Order), email (WC_Email), admin (bool), note, review_links, is_buyer (callable).
 */
class Qwoo_Email_Render {

    const TYPES   = [ 'logo', 'heading', 'text', 'image', 'button', 'divider', 'spacer', 'video', 'menu', 'products', 'coupon', 'social', 'footer', 'order_items', 'order_totals', 'order_info', 'addresses', 'order_button', 'downloads', 'review_links', 'gift_header', 'gift_card', 'gift_message', 'gift_button', 'booking_details' ];
    const LAYOUTS = [ '1' => [ 1 ], '1-1' => [ 1, 1 ], '1-2' => [ 1, 2 ], '2-1' => [ 2, 1 ], '1-1-1' => [ 1, 1, 1 ], '1-3' => [ 1, 3 ], '3-1' => [ 3, 1 ], '1-1-1-1' => [ 1, 1, 1, 1 ] ];
    const TARGETS = [ '', 'order', 'account', 'shop', 'pay', 'reset', 'product', 'booking', 'calendar' ];
    const SHOW_IF = [ '', 'coupon', 'first', 'paid', 'shipping', 'note', 'buyers', 'new' ];
    const MAX_ROWS = 60;

    const FONTS = [
        ''          => "-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif",
        'Georgia'   => "Georgia,'Times New Roman',serif",
        'Arial'     => 'Arial,Helvetica,sans-serif',
        'Helvetica' => "Helvetica,Arial,sans-serif",
        'Verdana'   => 'Verdana,Geneva,sans-serif',
        'Tahoma'    => 'Tahoma,Arial,sans-serif',
    ];

    /* ---------------- cleaning ---------------- */

    private static function hex( $v, string $fallback ): string {
        return sanitize_hex_color( (string) $v ) ?: $fallback;
    }

    private static function num( $v, $default, $min, $max ) {
        $n = is_numeric( $v ) ? (float) $v : (float) $default;
        return min( $max, max( $min, $n ) );
    }

    private static function line( $v, int $max ): string {
        return mb_substr( sanitize_text_field( (string) $v ), 0, $max );
    }

    /** An address, kept only when it's a full one (http, https, mailto). */
    private static function url( $v ): string {
        return esc_url_raw( trim( (string) $v ), [ 'http', 'https', 'mailto' ] );
    }

    private static function clean_html( string $html ): string {
        $html = str_replace( [ '<div>', '</div>' ], [ '<p>', '</p>' ], $html );
        $html = wp_kses( $html, [ 'a' => [ 'href' => true ], 'b' => [], 'strong' => [], 'em' => [], 'i' => [], 'u' => [], 'br' => [], 'p' => [], 'ul' => [], 'ol' => [], 'li' => [] ], [ 'http', 'https', 'mailto' ] );
        // wp_kses leaves "alert(1)" of a javascript: link: what isn't a full address stops being a link.
        return (string) preg_replace( '/<a\s+href="(?!https?:|mailto:)[^"]*"\s*>/i', '<a>', (string) $html );
    }

    private static function links( $raw ): array {
        $out = [];
        foreach ( array_slice( (array) $raw, 0, 8 ) as $link ) {
            $url = self::url( $link['url'] ?? '' );
            if ( $url !== '' ) $out[] = [ 'label' => self::line( $link['label'] ?? '', 30 ), 'url' => $url ];
        }
        return $out;
    }

    public static function clean_block( $block ): ?array {
        $type = (string) ( $block['type'] ?? '' );
        if ( $type === 'tracking' ) $type = 'order_button';
        if ( ! in_array( $type, self::TYPES, true ) ) return null;
        $d     = is_array( $block['data'] ?? null ) ? $block['data'] : [];
        $align = in_array( $d['align'] ?? '', [ 'start', 'center', 'end' ], true ) ? $d['align'] : 'start';
        $data  = [
            'padV'   => (int) self::num( $d['padV'] ?? null, 10, 0, 80 ),
            'padH'   => (int) self::num( $d['padH'] ?? null, 24, 0, 60 ),
            'hideOn' => in_array( $d['hideOn'] ?? '', [ 'phone', 'desktop' ], true ) ? $d['hideOn'] : '',
            'showIf' => in_array( $d['showIf'] ?? '', self::SHOW_IF, true ) ? $d['showIf'] : '',
        ];
        $font = isset( self::FONTS[ $d['font'] ?? '' ] ) ? (string) ( $d['font'] ?? '' ) : '';
        $text = [ 'font' => $font, 'weight' => in_array( (int) ( $d['weight'] ?? 0 ), [ 400, 500, 600, 700 ], true ) ? (int) $d['weight'] : 0, 'color' => sanitize_hex_color( (string) ( $d['color'] ?? '' ) ) ?: '', 'lh' => self::num( $d['lh'] ?? null, 0, 0, 3 ) ];
        switch ( $type ) {
            case 'logo':
                $data += [ 'url' => self::url( $d['url'] ?? '' ), 'width' => (int) self::num( $d['width'] ?? null, 160, 40, 400 ), 'align' => $align ];
                break;
            case 'heading':
                $data += $text + [ 'text' => mb_substr( sanitize_textarea_field( (string) ( $d['text'] ?? '' ) ), 0, 200 ), 'size' => (int) self::num( $d['size'] ?? null, 26, 14, 60 ), 'align' => $align ];
                break;
            case 'text':
                $data += $text + [ 'html' => self::clean_html( (string) ( $d['html'] ?? '' ) ), 'size' => (int) self::num( $d['size'] ?? null, 16, 10, 28 ), 'align' => $align ];
                break;
            case 'image':
                $data += [ 'url' => self::url( $d['url'] ?? '' ), 'alt' => self::line( $d['alt'] ?? '', 200 ), 'link' => self::url( $d['link'] ?? '' ), 'width' => (int) self::num( $d['width'] ?? null, 100, 10, 100 ), 'radius' => (int) self::num( $d['radius'] ?? null, 8, 0, 60 ), 'align' => $align ];
                break;
            case 'button':
            case 'order_button':
                $data += [
                    'label'  => self::line( $d['label'] ?? '', 80 ),
                    'link'   => self::url( $d['link'] ?? '' ),
                    'to'     => in_array( $d['to'] ?? '', self::TARGETS, true ) ? (string) ( $d['to'] ?? '' ) : '',
                    'bg'     => sanitize_hex_color( (string) ( $d['bg'] ?? '' ) ) ?: '',
                    'color'  => sanitize_hex_color( (string) ( $d['color'] ?? '' ) ) ?: '',
                    'size'   => (int) self::num( $d['size'] ?? null, 16, 11, 24 ),
                    'radius' => (int) self::num( $d['radius'] ?? null, 8, 0, 40 ),
                    'btnV'   => (int) self::num( $d['btnV'] ?? null, 13, 4, 30 ),
                    'btnH'   => (int) self::num( $d['btnH'] ?? null, 26, 8, 60 ),
                    'full'   => ! empty( $d['full'] ),
                    'align'  => $align,
                ];
                break;
            case 'divider':
                $data += [ 'color' => self::hex( $d['color'] ?? '', '#e5e5ea' ), 'thick' => (int) self::num( $d['thick'] ?? null, 1, 1, 8 ) ];
                break;
            case 'spacer':
                $data['height'] = (int) self::num( $d['height'] ?? null, 24, 4, 120 );
                break;
            case 'video':
                $data += [ 'url' => self::url( $d['url'] ?? '' ), 'cover' => self::url( $d['cover'] ?? '' ), 'label' => self::line( $d['label'] ?? '', 80 ) ];
                break;
            case 'menu':
            case 'social':
                $data += [ 'links' => self::links( $d['links'] ?? [] ), 'color' => sanitize_hex_color( (string) ( $d['color'] ?? '' ) ) ?: '', 'size' => (int) self::num( $d['size'] ?? null, 14, 10, 20 ), 'align' => isset( $d['align'] ) ? $align : 'center' ];
                break;
            case 'products':
                $data += [
                    'source' => in_array( $d['source'] ?? '', [ 'chosen', 'best', 'new', 'related' ], true ) ? $d['source'] : 'chosen',
                    'ids'    => array_slice( array_values( array_filter( array_map( 'intval', (array) ( $d['ids'] ?? [] ) ) ) ), 0, 8 ),
                    'count'  => (int) self::num( $d['count'] ?? null, 4, 1, 8 ),
                    'cols'   => (int) self::num( $d['cols'] ?? null, 2, 1, 4 ),
                    'price'  => ! array_key_exists( 'price', $d ) || ! empty( $d['price'] ),
                    'title'  => self::line( $d['title'] ?? '', 120 ),
                    'button' => self::line( $d['button'] ?? '', 40 ),
                ];
                break;
            case 'coupon':
                $data += [ 'code' => self::line( $d['code'] ?? '', 40 ), 'text' => self::line( $d['text'] ?? '', 200 ), 'color' => sanitize_hex_color( (string) ( $d['color'] ?? '' ) ) ?: '' ];
                break;
            case 'footer':
                $data += [ 'text' => mb_substr( sanitize_textarea_field( (string) ( $d['text'] ?? '' ) ), 0, 500 ), 'color' => self::hex( $d['color'] ?? '', '#77748b' ), 'size' => (int) self::num( $d['size'] ?? null, 12, 10, 18 ), 'align' => isset( $d['align'] ) ? $align : 'center' ];
                break;
            case 'order_items':
                $data += [ 'images' => ! empty( $d['images'] ), 'sku' => ! empty( $d['sku'] ) ];
                break;
            case 'order_info':
                $data += [ 'color' => self::hex( $d['color'] ?? '', '#77748b' ), 'size' => (int) self::num( $d['size'] ?? null, 13, 10, 20 ), 'align' => $align ];
                break;
            case 'addresses':
                $data['which'] = in_array( $d['which'] ?? '', [ 'both', 'billing', 'shipping' ], true ) ? $d['which'] : 'both';
                break;
            case 'review_links':
                $data += [ 'bg' => sanitize_hex_color( (string) ( $d['bg'] ?? '' ) ) ?: '', 'color' => sanitize_hex_color( (string) ( $d['color'] ?? '' ) ) ?: '' ];
                break;
            case 'booking_details':
                $data += [
                    'bg'       => self::hex( $d['bg'] ?? '', '#f7f5f2' ),
                    'border'   => self::hex( $d['border'] ?? '', '#e8e2da' ),
                    'label'    => self::hex( $d['label'] ?? '', '#77748b' ),
                    'radius'   => (int) self::num( $d['radius'] ?? null, 12, 0, 40 ),
                    'size'     => (int) self::num( $d['size'] ?? null, 15, 12, 20 ),
                    'calendar' => ! array_key_exists( 'calendar', $d ) || ! empty( $d['calendar'] ),
                ];
                break;
            case 'gift_header':
                $data += [ 'size' => (int) self::num( $d['size'] ?? null, 28, 16, 48 ), 'radius' => (int) self::num( $d['radius'] ?? null, 12, 0, 40 ), 'text' => self::hex( $d['text'] ?? '', '#ffffff' ) ];
                break;
            case 'gift_card':
                $data += [ 'radius' => (int) self::num( $d['radius'] ?? null, 14, 0, 40 ), 'text' => self::hex( $d['text'] ?? '', '#ffffff' ), 'label' => self::line( $d['label'] ?? '', 40 ) ];
                break;
            case 'gift_message':
                $data += $text + [ 'size' => (int) self::num( $d['size'] ?? null, 18, 12, 28 ), 'align' => $align ];
                break;
            case 'gift_button':
                $data += [
                    'label'  => self::line( $d['label'] ?? '', 80 ),
                    'bg'     => sanitize_hex_color( (string) ( $d['bg'] ?? '' ) ) ?: '',
                    'color'  => sanitize_hex_color( (string) ( $d['color'] ?? '' ) ) ?: '',
                    'size'   => (int) self::num( $d['size'] ?? null, 16, 11, 24 ),
                    'radius' => (int) self::num( $d['radius'] ?? null, 8, 0, 40 ),
                    'btnV'   => (int) self::num( $d['btnV'] ?? null, 13, 4, 30 ),
                    'btnH'   => (int) self::num( $d['btnH'] ?? null, 26, 8, 60 ),
                    'full'   => ! array_key_exists( 'full', $d ) || ! empty( $d['full'] ),
                    'align'  => $align,
                ];
                break;
        }
        return [ 'id' => sanitize_key( (string) ( $block['id'] ?? '' ) ) ?: 'b' . bin2hex( random_bytes( 4 ) ), 'type' => $type, 'data' => $data ];
    }

    public static function clean( $raw ): array {
        $raw      = is_array( $raw ) ? $raw : [];
        $settings = is_array( $raw['settings'] ?? null ) ? $raw['settings'] : [];
        $out      = [
            'settings' => [
                'bg'           => self::hex( $settings['bg'] ?? '', '#f4f4f7' ),
                'content_bg'   => self::hex( $settings['content_bg'] ?? '', '#ffffff' ),
                'color'        => self::hex( $settings['color'] ?? '', '#1d1b2e' ),
                'link'         => self::hex( $settings['link'] ?? '', '#5b4cf5' ),
                'button_bg'    => self::hex( $settings['button_bg'] ?? '', '#1d1b2e' ),
                'button_color' => self::hex( $settings['button_color'] ?? '', '#ffffff' ),
                'width'        => (int) self::num( $settings['width'] ?? null, 600, 480, 700 ),
                'font'         => isset( self::FONTS[ $settings['font'] ?? '' ] ) ? (string) ( $settings['font'] ?? '' ) : '',
            ],
            'rows'     => [],
        ];
        $rows = $raw['rows'] ?? null;
        if ( ! is_array( $rows ) ) {
            // A design from before rows.
            $rows = [];
            foreach ( (array) ( $raw['blocks'] ?? [] ) as $block ) {
                $rows[] = [ 'layout' => '1', 'cols' => [ [ $block ] ] ];
            }
        }
        foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
            $layout = isset( self::LAYOUTS[ $row['layout'] ?? '' ] ) ? (string) $row['layout'] : '1';
            $cols   = [];
            foreach ( array_keys( self::LAYOUTS[ $layout ] ) as $i ) {
                $col = [];
                foreach ( array_slice( (array) ( $row['cols'][ $i ] ?? [] ), 0, 20 ) as $block ) {
                    $clean = self::clean_block( $block );
                    if ( $clean ) $col[] = $clean;
                }
                $cols[] = $col;
            }
            $r = is_array( $row['r'] ?? null ) ? $row['r'] : [];
            $out['rows'][] = [
                'id'     => sanitize_key( (string) ( $row['id'] ?? '' ) ) ?: 'r' . bin2hex( random_bytes( 4 ) ),
                'layout' => $layout,
                'r'      => [
                    'bg'     => sanitize_hex_color( (string) ( $r['bg'] ?? '' ) ) ?: '',
                    'padV'   => (int) self::num( $r['padV'] ?? null, 0, 0, 80 ),
                    'gap'    => (int) self::num( $r['gap'] ?? null, 0, 0, 40 ),
                    'stack'  => ! array_key_exists( 'stack', $r ) || ! empty( $r['stack'] ),
                    'hideOn' => in_array( $r['hideOn'] ?? '', [ 'phone', 'desktop' ], true ) ? $r['hideOn'] : '',
                ],
                'cols'   => $cols,
            ];
        }
        return $out;
    }

    /** Is there anything in it beyond lines and space? */
    public static function has_content( array $design ): bool {
        foreach ( (array) ( $design['rows'] ?? [] ) as $row ) {
            foreach ( (array) $row['cols'] as $col ) {
                foreach ( $col as $block ) {
                    if ( ! in_array( $block['type'], [ 'divider', 'spacer' ], true ) ) return true;
                }
            }
        }
        return false;
    }

    /* ---------------- output ---------------- */

    private static function shows( array $d, array $ctx ): bool {
        $order = $ctx['order'] ?? null;
        switch ( $d['showIf'] ?? '' ) {
            case 'coupon':
                return $order && $order->get_coupon_codes();
            case 'paid':
                return $order && $order->is_paid();
            case 'shipping':
                return $order && $order->get_shipping_method() !== '';
            case 'note':
                return trim( (string) ( $ctx['note'] ?? '' ) ) !== '';
            case 'first':
                return $order && count( wc_get_orders( [ 'billing_email' => $order->get_billing_email(), 'status' => wc_get_is_paid_statuses(), 'limit' => 2, 'return' => 'ids' ] ) ) <= 1;
            case 'buyers':
            case 'new':
                $buyer = isset( $ctx['is_buyer'] ) ? (bool) ( $ctx['is_buyer'] )() : null;
                return $buyer === null || $buyer === ( $d['showIf'] === 'buyers' );
        }
        return true;
    }

    public static function html( array $design, array $ctx ): string {
        $design = isset( $design['rows'] ) ? $design : self::clean( $design );
        $s      = $design['settings'];
        $main   = class_exists( 'Qwoo_I18n' ) ? Qwoo_I18n::default_code() : 'en';
        $rtl    = in_array( $main, [ 'he', 'ar' ], true );
        $link   = $ctx['link'] ?? static fn( $u ) => $u;
        $font   = self::FONTS[ $s['font'] ?? '' ] ?? self::FONTS[''];
        $width  = (int) ( $s['width'] ?? 600 );
        $env    = [ 's' => $s, 'font' => $font, 'link' => $link, 'start' => $rtl ? 'right' : 'left', 'end' => $rtl ? 'left' : 'right', 'width' => $width, 'ctx' => $ctx ];
        $body   = '';
        foreach ( (array) $design['rows'] as $row ) {
            $body .= self::row_html( $row, $env );
        }
        $tokens = (array) ( $ctx['tokens'] ?? [] );
        $tokens += [ '{store_name}' => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ), '{first_name}' => '' ];
        $body   = strtr( $body, array_map( static fn( $v ) => esc_html( (string) $v ), $tokens ) );
        $body   = self::front_photos( $body );
        $footer = (string) ( $ctx['footer'] ?? '' );

        return '<!doctype html><html dir="' . ( $rtl ? 'rtl' : 'ltr' ) . '" lang="' . esc_attr( $main ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">'
            . '<style>@media only screen and (max-width:' . ( $width + 20 ) . 'px){.qm-wrap{width:100%!important}.qm-col{display:block!important;width:100%!important;box-sizing:border-box}.qm-hp{display:none!important;max-height:0;overflow:hidden}.qm-hd{display:table!important}}</style></head>'
            . '<body style="margin:0;padding:0;background:' . esc_attr( $s['bg'] ) . ';">'
            . '<div style="display:none;max-height:0;overflow:hidden;">' . esc_html( strtr( (string) ( $ctx['preheader'] ?? '' ), $tokens ) ) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . esc_attr( $s['bg'] ) . ';"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" class="qm-wrap" width="' . $width . '" cellpadding="0" cellspacing="0" style="width:100%;max-width:' . $width . 'px;background:' . esc_attr( $s['content_bg'] ) . ';border-radius:12px;overflow:hidden;">' . $body . '</table>'
            . ( $footer !== '' ? '<table role="presentation" class="qm-wrap" width="' . $width . '" cellpadding="0" cellspacing="0" style="width:100%;max-width:' . $width . 'px;"><tr><td style="padding:16px 24px;font-family:' . $font . ';font-size:12px;line-height:1.6;color:#77748b;text-align:center;">' . $footer . '</td></tr></table>' : '' )
            . '</td></tr></table></body></html>';
    }

    /**
     * Photos in an email load from the storefront's address (it serves /wp-content/uploads itself),
     * like on the storefront: the backend's address never shows in an email, and its host
     * turns away bursts of direct photo requests.
     */
    private static function front_photos( string $html ): string {
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? rtrim( (string) Qwoo_Technical_Settings::get_primary_frontend_domain(), '/' ) : '';
        if ( $front === '' || strpos( $html, '/wp-content/uploads/' ) === false ) return $html;
        $from = array_unique( array_filter( [
            untrailingslashit( content_url( 'uploads' ) ),
            class_exists( 'Qwoo_Technical_Settings' ) ? untrailingslashit( (string) Qwoo_Technical_Settings::uploads_backend_base() ) : '',
        ] ) );
        $to = $front . '/wp-content/uploads';
        foreach ( $from as $base ) {
            if ( $base !== $to ) $html = str_replace( $base . '/', $to . '/', $html );
        }
        return $html;
    }

    private static function hide_attrs( string $hide ): array {
        if ( $hide === 'phone' ) return [ 'class' => 'qm-hp', 'style' => '' ];
        if ( $hide === 'desktop' ) return [ 'class' => 'qm-hd', 'style' => 'display:none;mso-hide:all;' ];
        return [ 'class' => '', 'style' => '' ];
    }

    private static function row_html( array $row, array $env ): string {
        $sizes = self::LAYOUTS[ $row['layout'] ] ?? [ 1 ];
        $total = array_sum( $sizes );
        $r     = $row['r'] ?? [];
        $gap   = (int) ( $r['gap'] ?? 0 );
        $cells = '';
        $any   = false;
        foreach ( $row['cols'] as $i => $col ) {
            $inner = '';
            foreach ( $col as $block ) {
                $inner .= self::block_html( $block, $env, $sizes[ $i ] / $total );
            }
            if ( $inner !== '' ) $any = true;
            $share  = round( 100 * $sizes[ $i ] / $total, 2 );
            $cells .= '<td class="' . ( ! empty( $r['stack'] ) && count( $sizes ) > 1 ? 'qm-col' : '' ) . '" width="' . $share . '%" valign="top" style="width:' . $share . '%;' . ( $gap && $i ? 'padding-' . $env['start'] . ':' . $gap . 'px;' : '' ) . '">' . $inner . '</td>';
        }
        if ( ! $any ) return '';
        $hide = self::hide_attrs( (string) ( $r['hideOn'] ?? '' ) );
        return '<tr><td style="padding:' . (int) ( $r['padV'] ?? 0 ) . 'px 0;' . ( ! empty( $r['bg'] ) ? 'background:' . esc_attr( $r['bg'] ) . ';' : '' ) . '"><table role="presentation" class="' . $hide['class'] . '" width="100%" cellpadding="0" cellspacing="0" style="width:100%;' . $hide['style'] . '"><tr>' . $cells . '</tr></table></td></tr>';
    }

    private static function block_html( array $block, array $env, float $share ): string {
        $d = $block['data'];
        if ( ! self::shows( $d, $env['ctx'] ) ) return '';
        $cell = self::cell( $block['type'], $d, $env, $share );
        if ( $cell === '' ) return '';
        $hide = self::hide_attrs( (string) ( $d['hideOn'] ?? '' ) );
        $padV = (int) ( $d['padV'] ?? 10 );
        $padH = (int) ( $d['padH'] ?? 24 );
        return '<table role="presentation" class="' . $hide['class'] . '" width="100%" cellpadding="0" cellspacing="0" style="width:100%;' . $hide['style'] . '"><tr><td style="padding:' . $padV . 'px ' . $padH . 'px;">' . $cell . '</td></tr></table>';
    }

    private static function align( string $a, array $env ): string {
        return $a === 'center' ? 'center' : ( $a === 'end' ? $env['end'] : $env['start'] );
    }

    private static function text_style( array $d, array $env, int $default_weight = 400, float $default_lh = 1.6 ): string {
        $font = ! empty( $d['font'] ) && isset( self::FONTS[ $d['font'] ] ) ? self::FONTS[ $d['font'] ] : $env['font'];
        return 'font-family:' . $font . ';font-size:' . (int) $d['size'] . 'px;font-weight:' . ( (int) ( $d['weight'] ?? 0 ) ?: $default_weight ) . ';line-height:' . ( (float) ( $d['lh'] ?? 0 ) ?: $default_lh ) . ';color:' . esc_attr( ( $d['color'] ?? '' ) ?: $env['s']['color'] ) . ';text-align:' . self::align( (string) ( $d['align'] ?? 'start' ), $env ) . ';';
    }

    private static function button( array $d, array $env, string $url ): string {
        $s     = $env['s'];
        $style = 'display:' . ( ! empty( $d['full'] ) ? 'block;text-align:center' : 'inline-block' ) . ';padding:' . (int) ( $d['btnV'] ?? 13 ) . 'px ' . (int) ( $d['btnH'] ?? 26 ) . 'px;border-radius:' . (int) ( $d['radius'] ?? 8 ) . 'px;background:' . esc_attr( ( $d['bg'] ?? '' ) ?: $s['button_bg'] ) . ';color:' . esc_attr( ( $d['color'] ?? '' ) ?: $s['button_color'] ) . ';font-family:' . $env['font'] . ';font-size:' . (int) ( $d['size'] ?? 16 ) . 'px;font-weight:bold;text-decoration:none;';
        return '<div style="text-align:' . self::align( (string) ( $d['align'] ?? 'center' ), $env ) . ';"><a href="' . esc_url( ( $env['link'] )( $url ) ) . '" style="' . $style . '">' . esc_html( (string) ( $d['label'] ?? '' ) ) . '</a></div>';
    }

    private static function cell( string $type, array $d, array $env, float $share ): string {
        $s    = $env['s'];
        $link = $env['link'];
        $font = $env['font'];
        $ctx  = $env['ctx'];
        $order = $ctx['order'] ?? null;
        switch ( $type ) {
            case 'logo':
                $url = (string) ( $d['url'] ?? '' ) ?: (string) ( $ctx['logo'] ?? '' );
                if ( $url === '' ) {
                    return '<div style="font-family:' . $font . ';font-size:22px;font-weight:bold;color:' . esc_attr( $s['color'] ) . ';text-align:' . self::align( (string) $d['align'], $env ) . ';">{store_name}</div>';
                }
                $front = class_exists( 'Qwoo_Technical_Settings' ) ? rtrim( Qwoo_Technical_Settings::get_primary_frontend_domain(), '/' ) : '';
                $img   = '<img src="' . esc_url( $url ) . '" alt="{store_name}" width="' . (int) $d['width'] . '" style="display:inline-block;width:' . (int) $d['width'] . 'px;max-width:100%;height:auto;border:0;">';
                return '<div style="text-align:' . self::align( (string) $d['align'], $env ) . ';">' . ( $front !== '' ? '<a href="' . esc_url( $link( $front . '/' ) ) . '">' . $img . '</a>' : $img ) . '</div>';
            case 'heading':
                return '<h1 style="margin:0;' . self::text_style( $d, $env, 700, 1.25 ) . '">' . nl2br( esc_html( (string) $d['text'] ) ) . '</h1>';
            case 'text':
                $html = preg_replace_callback( '/href="([^"]*)"/', static fn( $m ) => 'href="' . esc_attr( $link( html_entity_decode( $m[1] ) ) ) . '" style="color:' . esc_attr( $s['link'] ) . ';"', (string) $d['html'] );
                $html = str_replace( '<p>', '<p style="margin:0 0 12px;">', (string) $html );
                return '<div style="' . self::text_style( $d, $env ) . '">' . $html . '</div>';
            case 'image':
                if ( $d['url'] === '' ) return '';
                $w   = (int) round( ( $env['width'] * $share - 2 * (int) $d['padH'] ) * $d['width'] / 100 );
                $img = '<img src="' . esc_url( $d['url'] ) . '" alt="' . esc_attr( $d['alt'] ) . '" width="' . $w . '" style="display:inline-block;width:' . (int) $d['width'] . '%;max-width:' . $w . 'px;height:auto;border:0;border-radius:' . (int) $d['radius'] . 'px;">';
                return '<div style="text-align:' . self::align( (string) $d['align'], $env ) . ';">' . ( $d['link'] !== '' ? '<a href="' . esc_url( $link( $d['link'] ) ) . '">' . $img . '</a>' : $img ) . '</div>';
            case 'button':
            case 'order_button':
                $to  = (string) ( $d['to'] ?? '' );
                $url = $type === 'order_button' ? (string) ( $ctx['urls']['order'] ?? '' ) : ( $to !== '' ? (string) ( $ctx['urls'][ $to ] ?? '' ) : (string) $d['link'] );
                return $url === '' || (string) $d['label'] === '' ? '' : self::button( $d, $env, $url );
            case 'divider':
                return '<div style="border-top:' . (int) $d['thick'] . 'px solid ' . esc_attr( $d['color'] ) . ';font-size:0;line-height:0;">&nbsp;</div>';
            case 'spacer':
                return '<div style="height:' . (int) $d['height'] . 'px;font-size:0;line-height:0;">&nbsp;</div>';
            case 'video':
                if ( $d['url'] === '' ) return '';
                $label = (string) $d['label'] !== '' ? $d['label'] : '▶';
                if ( $d['cover'] !== '' ) {
                    return '<a href="' . esc_url( $link( $d['url'] ) ) . '"><img src="' . esc_url( $d['cover'] ) . '" alt="' . esc_attr( $label ) . '" width="' . (int) round( $env['width'] * $share ) . '" style="display:block;width:100%;height:auto;border:0;border-radius:8px;"></a>';
                }
                return self::button( [ 'label' => $label, 'align' => 'center' ], $env, $d['url'] );
            case 'menu':
            case 'social':
                $parts = [];
                foreach ( (array) $d['links'] as $l ) {
                    $parts[] = '<a href="' . esc_url( $link( $l['url'] ) ) . '" style="color:' . esc_attr( ( $d['color'] ?? '' ) ?: $s['link'] ) . ';text-decoration:none;font-family:' . $font . ';font-size:' . (int) $d['size'] . 'px;font-weight:600;margin:0 8px;">' . esc_html( $l['label'] ) . '</a>';
                }
                return $parts ? '<div style="text-align:' . self::align( (string) $d['align'], $env ) . ';">' . implode( '', $parts ) . '</div>' : '';
            case 'products':
                return self::products( $d, $env );
            case 'coupon':
                if ( $d['code'] === '' ) return '';
                $c = ( $d['color'] ?? '' ) ?: $s['button_bg'];
                return '<div style="padding:18px;border:2px dashed ' . esc_attr( $c ) . ';border-radius:10px;text-align:center;font-family:' . $font . ';color:' . esc_attr( $s['color'] ) . ';">'
                    . ( $d['text'] !== '' ? '<div style="font-size:15px;margin-bottom:6px;">' . esc_html( $d['text'] ) . '</div>' : '' )
                    . '<div style="font-size:24px;font-weight:bold;letter-spacing:2px;color:' . esc_attr( $c ) . ';" dir="ltr">' . esc_html( $d['code'] ) . '</div></div>';
            case 'footer':
                return '<div style="font-family:' . $font . ';font-size:' . (int) $d['size'] . 'px;line-height:1.6;color:' . esc_attr( $d['color'] ) . ';text-align:' . self::align( (string) $d['align'], $env ) . ';">' . nl2br( esc_html( (string) $d['text'] ) ) . '</div>';
            case 'order_info':
                if ( ! $order ) return '';
                $date = $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '';
                return '<div style="font-family:' . $font . ';font-size:' . (int) $d['size'] . 'px;color:' . esc_attr( $d['color'] ) . ';text-align:' . self::align( (string) $d['align'], $env ) . ';">#' . esc_html( $order->get_order_number() ) . ( $date ? ' · ' . esc_html( $date ) : '' ) . '</div>';
            case 'order_items':
                return $order ? self::order_items( $d, $env, $order ) : '';
            case 'order_totals':
                return $order ? self::order_totals( $env, $order ) : '';
            case 'addresses':
                return $order ? self::addresses( $d, $env, $order ) : '';
            case 'downloads':
                if ( ! $order || ! $order->has_downloadable_item() || ! $order->is_download_permitted() ) return '';
                $rows = '';
                foreach ( $order->get_downloadable_items() as $item ) {
                    $rows .= '<div style="padding:4px 0;"><a href="' . esc_url( $item['download_url'] ) . '" style="color:' . esc_attr( $s['link'] ) . ';font-weight:600;">' . esc_html( $item['download_name'] ) . '</a></div>';
                }
                return '<div style="font-family:' . $font . ';font-size:14px;">' . $rows . '</div>';
            case 'gift_header':
            case 'gift_card':
            case 'gift_message':
            case 'gift_button':
                return is_array( $ctx['gift'] ?? null ) ? self::gift( $type, $d, $env, $ctx['gift'] ) : '';
            case 'booking_details':
                return is_array( $ctx['booking'] ?? null ) ? self::booking( $d, $env, $ctx['booking'] ) : '';
            case 'review_links':
                $out = '';
                foreach ( (array) ( $ctx['review_links'] ?? [] ) as $l ) {
                    $out .= '<div style="padding:5px 0;">' . self::button( [ 'label' => $l['label'], 'bg' => $d['bg'] ?? '', 'color' => $d['color'] ?? '', 'align' => 'center', 'size' => 15 ], $env, (string) $l['url'] ) . '</div>';
                }
                return $out;
        }
        return '';
    }

    /**
     * The gift card's blocks. ctx.gift is the card (Qwoo_Gift_Cards::gift_ctx),
     * or { template: true } for the storefront's preview: then {gc_*} places
     * stand for the shopper's values, which the page escapes and fills in.
     */
    private static function gift( string $type, array $d, array $env, array $g ): string {
        $tpl   = ! empty( $g['template'] );
        $font  = $env['font'];
        $color = $tpl ? '{gc_color}' : esc_attr( (string) ( $g['color'] ?? '#2f4a3a' ) );
        $he    = class_exists( 'Qwoo_I18n' ) && Qwoo_I18n::default_code() === 'he';
        $say   = static fn( $en, $hebrew ) => $he ? $hebrew : $en;
        $val   = static fn( $key, $token ) => $tpl ? $token : esc_html( (string) ( $g[ $key ] ?? '' ) );
        $r     = (int) ( $d['radius'] ?? 12 );
        switch ( $type ) {
            case 'gift_header':
                $img   = '';
                $style = 'display:block;width:100%;height:auto;border:0;border-radius:' . $r . 'px ' . $r . 'px 0 0;';
                if ( $tpl ) {
                    $img = '<div style="{gc_img_show}"><img src="{gc_img}" alt="" width="' . (int) ( $env['width'] - 48 ) . '" style="' . $style . '"></div>';
                } elseif ( ( $g['image'] ?? '' ) !== '' ) {
                    $img = '<img src="' . esc_url( (string) $g['image'] ) . '" alt="" width="' . (int) ( $env['width'] - 48 ) . '" style="' . $style . '">';
                }
                $round = $tpl ? '{gc_band_radius}' : ( $img !== '' ? '0 0 ' . $r . 'px ' . $r . 'px' : $r . 'px' );
                $mark  = $tpl ? '<div style="font-size:34px;line-height:1.3;{gc_mark_show}">{gc_mark}</div>' : ( ( $g['mark'] ?? '' ) !== '' ? '<div style="font-size:34px;line-height:1.3;">' . esc_html( (string) $g['mark'] ) . '</div>' : '' );
                return $img . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td bgcolor="' . $color . '" style="background:' . $color . ';border-radius:' . $round . ';padding:28px 24px;text-align:center;font-family:' . $font . ';">'
                    . $mark . '<div style="font-size:' . (int) $d['size'] . 'px;font-weight:700;line-height:1.25;color:' . esc_attr( $d['text'] ) . ';">' . $val( 'title', '{gc_title}' ) . '</div></td></tr></table>';
            case 'gift_card':
                $label = (string) $d['label'] !== '' ? esc_html( (string) $d['label'] ) : esc_html( $say( 'Gift card', 'כרטיס מתנה' ) );
                $txt   = esc_attr( $d['text'] );
                return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;"><tr><td bgcolor="' . $color . '" style="background:' . $color . ';border-radius:' . $r . 'px;padding:20px 22px;font-family:' . $font . ';color:' . $txt . ';">'
                    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="font-size:13px;color:' . $txt . ';font-family:' . $font . ';">' . $label . '</td><td style="font-size:13px;color:' . $txt . ';text-align:' . $env['end'] . ';font-family:' . $font . ';">{store_name}</td></tr></table>'
                    . '<div style="font-size:38px;font-weight:700;line-height:1.1;padding:14px 0 12px;color:' . $txt . ';" dir="ltr">' . $val( 'amount', '{gc_amount}' ) . '</div>'
                    . '<div dir="ltr" style="display:inline-block;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:17px;letter-spacing:2px;color:' . $txt . ';background:rgba(255,255,255,.18);padding:8px 12px;border-radius:8px;">' . $val( 'code', '{gc_code}' ) . '</div>'
                    . '<div style="font-size:12px;color:' . $txt . ';padding-top:12px;">' . $val( 'expiry', '{gc_expiry}' ) . '</div>'
                    . '</td></tr></table>';
            case 'gift_message':
                $style = self::text_style( $d, $env, 400, 1.5 );
                $align = self::align( (string) $d['align'], $env );
                $hi    = $tpl ? str_replace( '%s', '{gc_to}', esc_html( $say( 'Hi %s,', 'היי %s,' ) ) ) : esc_html( sprintf( $say( 'Hi %s,', 'היי %s,' ), (string) ( $g['to'] ?? '' ) ) );
                $msg   = $tpl ? '<div style="' . $style . '{gc_msg_show}">{gc_message}</div>' : ( trim( (string) ( $g['message'] ?? '' ) ) !== '' ? '<div style="' . $style . '">' . nl2br( esc_html( (string) $g['message'] ) ) . '</div>' : '' );
                $from_style = 'font-family:' . $font . ';font-size:14px;color:#77748b;padding-top:6px;text-align:' . $align . ';';
                $from  = $tpl ? '<div style="' . $from_style . '{gc_from_show}">' . str_replace( '%s', '{gc_from}', esc_html( $say( 'From %s', 'מאת %s' ) ) ) . '</div>'
                    : ( ( $g['from'] ?? '' ) !== '' ? '<div style="' . $from_style . '">' . esc_html( sprintf( $say( 'From %s', 'מאת %s' ), (string) $g['from'] ) ) . '</div>' : '' );
                return '<div style="font-family:' . $font . ';font-size:15px;color:' . esc_attr( $env['s']['color'] ) . ';padding-bottom:8px;text-align:' . $align . ';">' . $hi . '</div>' . $msg . $from;
            case 'gift_button':
                if ( (string) $d['label'] === '' ) return '';
                return self::button( [ 'bg' => ( $d['bg'] ?? '' ) ?: $color ] + $d, $env, (string) ( $g['shop'] ?? home_url( '/' ) ) );
        }
        return '';
    }

    /** A booking's card (Qwoo_Appointments::email_card): label / value rows, then Add to calendar links. */
    private static function booking( array $d, array $env, array $bk ): string {
        $font  = $env['font'];
        $link  = $env['link'];
        $rows  = '';
        foreach ( (array) ( $bk['rows'] ?? [] ) as $r ) {
            $value = ! empty( $r['link'] )
                ? '<a href="' . esc_url( $link( (string) $r['v'] ) ) . '" style="color:' . esc_attr( $env['s']['link'] ) . ';word-break:break-all;">' . esc_html( (string) $r['v'] ) . '</a>'
                : esc_html( (string) $r['v'] );
            $rows .= '<tr><td style="padding:5px 0;font-family:' . $font . ';font-size:13px;color:' . esc_attr( $d['label'] ) . ';vertical-align:top;width:34%;text-align:' . $env['start'] . ';">' . esc_html( (string) $r['k'] ) . '</td>'
                . '<td style="padding:5px 0;font-family:' . $font . ';font-size:' . (int) $d['size'] . 'px;font-weight:600;color:' . esc_attr( $env['s']['color'] ) . ';text-align:' . $env['start'] . ';">' . $value . '</td></tr>';
        }
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;"><tr><td style="background:' . esc_attr( $d['bg'] ) . ';border:1px solid ' . esc_attr( $d['border'] ) . ';border-radius:' . (int) $d['radius'] . 'px;padding:14px 18px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr></table>';
        if ( ! empty( $d['calendar'] ) && ! empty( $bk['google'] ) ) {
            $he   = class_exists( 'Qwoo_I18n' ) && Qwoo_I18n::default_code() === 'he';
            $pill = static fn( $url, $label ) => '<a href="' . esc_url( $link( (string) $url ) ) . '" style="display:inline-block;margin:6px 4px 0;padding:8px 14px;border:1px solid ' . esc_attr( $d['border'] ) . ';border-radius:8px;font-family:' . $font . ';font-size:13px;font-weight:600;color:' . esc_attr( $env['s']['color'] ) . ';text-decoration:none;">' . esc_html( $label ) . '</a>';
            $out .= '<div style="padding-top:10px;text-align:' . $env['start'] . ';font-family:' . $font . ';font-size:13px;color:' . esc_attr( $d['label'] ) . ';">' . esc_html( $he ? 'הוספה ליומן:' : 'Add to your calendar:' ) . '<br>'
                . $pill( $bk['google'], 'Google' ) . $pill( $bk['outlook'] ?? '', 'Outlook' )
                . '<span style="display:inline-block;margin:6px 4px 0;font-size:12px;">' . esc_html( $he ? 'Apple ואחרים: הקובץ המצורף' : 'Apple and others: the attached file' ) . '</span></div>';
        }
        return $out;
    }

    /** What the block's "which products" comes to. */
    private static function product_list( array $d, array $ctx ): array {
        $count = (int) $d['count'];
        $base  = [ 'status' => 'publish', 'limit' => $count, 'visibility' => 'catalog' ];
        switch ( $d['source'] ) {
            case 'best':
                return wc_get_products( $base + [ 'orderby' => 'popularity', 'order' => 'DESC' ] );
            case 'new':
                return wc_get_products( $base + [ 'orderby' => 'date', 'order' => 'DESC' ] );
            case 'related':
                $order = $ctx['order'] ?? null;
                if ( ! $order ) return wc_get_products( $base + [ 'orderby' => 'popularity', 'order' => 'DESC' ] );
                $ids = [];
                foreach ( $order->get_items() as $item ) {
                    $ids = array_merge( $ids, wc_get_related_products( (int) $item->get_product_id(), $count ) );
                }
                return array_filter( array_map( 'wc_get_product', array_slice( array_unique( $ids ), 0, $count ) ) );
        }
        return array_filter( array_map( 'wc_get_product', array_slice( (array) $d['ids'], 0, $count ) ) );
    }

    /**
     * The products a Products block would show now, for the dashboard's picture of the email:
     * [ { id, name, price, image } ]. With no order at hand, "related to this order" gives the
     * best sellers, like the email itself does.
     */
    public static function preview_products( array $in ): array {
        $source = in_array( $in['source'] ?? '', [ 'best', 'new', 'chosen', 'related' ], true ) ? (string) $in['source'] : 'best';
        $d      = [ 'source' => $source, 'count' => max( 1, min( 8, (int) ( $in['count'] ?? 4 ) ) ), 'ids' => array_slice( array_filter( array_map( 'absint', (array) ( $in['ids'] ?? [] ) ) ), 0, 8 ) ];
        $out    = [];
        foreach ( self::product_list( $d, [] ) as $product ) {
            if ( ! $product || $product->get_status() !== 'publish' ) continue;
            $img   = wp_get_attachment_image_url( $product->get_image_id(), 'medium' );
            $out[] = [
                'id'    => (int) $product->get_id(),
                'name'  => html_entity_decode( $product->get_name(), ENT_QUOTES ),
                'price' => (float) $product->get_price() > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_price() ) ), ENT_QUOTES ) : '',
                'image' => $img ? self::front_photos( (string) $img ) : '',
            ];
        }
        return [ 'products' => $out ];
    }

    private static function products( array $d, array $env ): string {
        $s     = $env['s'];
        $font  = $env['font'];
        $link  = $env['link'];
        $front = class_exists( 'Qwoo_Technical_Settings' ) ? rtrim( Qwoo_Technical_Settings::get_primary_frontend_domain(), '/' ) : '';
        $cols  = (int) $d['cols'];
        $share = round( 100 / $cols, 2 );
        $cells = '';
        $i     = 0;
        foreach ( self::product_list( $d, $env['ctx'] ) as $product ) {
            if ( ! $product || $product->get_status() !== 'publish' ) continue;
            $url   = $front . '/product/' . $product->get_slug();
            $img   = wp_get_attachment_image_url( $product->get_image_id(), 'medium' );
            $price = (float) $product->get_price() > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_price() ) ), ENT_QUOTES ) : '';
            if ( $i && $i % $cols === 0 ) $cells .= '</tr><tr>';
            $cells .= '<td class="qm-col" width="' . $share . '%" valign="top" style="padding:6px;font-family:' . $font . ';text-align:center;color:' . esc_attr( $s['color'] ) . ';">'
                . ( $img ? '<a href="' . esc_url( $link( $url ) ) . '"><img src="' . esc_url( $img ) . '" alt="" width="250" style="width:100%;max-width:250px;height:auto;border:0;border-radius:8px;"></a>' : '' )
                . '<div style="font-size:15px;font-weight:bold;margin:8px 0 2px;">' . esc_html( html_entity_decode( $product->get_name(), ENT_QUOTES ) ) . '</div>'
                . ( $d['price'] && $price !== '' ? '<div style="font-size:14px;margin-bottom:8px;">' . esc_html( $price ) . '</div>' : '' )
                . ( $d['button'] !== '' ? '<a href="' . esc_url( $link( $url ) ) . '" style="color:' . esc_attr( $s['link'] ) . ';font-size:14px;">' . esc_html( $d['button'] ) . '</a>' : '' )
                . '</td>';
            $i++;
        }
        if ( ! $i ) return '';
        while ( $i % $cols ) {
            $cells .= '<td width="' . $share . '%"></td>';
            $i++;
        }
        $title = (string) $d['title'] !== '' ? '<div style="font-family:' . $font . ';font-size:18px;font-weight:bold;color:' . esc_attr( $s['color'] ) . ';text-align:center;margin-bottom:10px;">' . esc_html( $d['title'] ) . '</div>' : '';
        return $title . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table>';
    }

    /** The order's lines, with what payment methods print around them (bank details, for example). */
    private static function order_items( array $d, array $env, $order ): string {
        $ctx   = $env['ctx'];
        $s     = $env['s'];
        $font  = $env['font'];
        $mail  = $ctx['email'] ?? null;
        $admin = ! empty( $ctx['admin'] );
        $hook  = static function ( string $name ) use ( $order, $admin, $mail ) {
            if ( ! $mail ) return '';
            ob_start();
            do_action( $name, $order, $admin, false, $mail );
            $out = (string) ob_get_clean();
            return trim( $out ) === '' ? '' : '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;margin:10px 0;">' . wp_kses_post( $out ) . '</div>';
        };
        $rows = '';
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $image   = ! empty( $d['images'] ) && $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '';
            $meta    = wc_display_item_meta( $item, [ 'echo' => false, 'before' => '', 'after' => '', 'separator' => ' · ', 'autop' => false ] );
            $sku     = ! empty( $d['sku'] ) && $product && $product->get_sku() !== '' ? '<div style="font-size:12px;color:#77748b;">' . esc_html( $product->get_sku() ) . '</div>' : '';
            $rows   .= '<tr>'
                . ( $image ? '<td width="52" valign="top" style="padding:10px 0;border-bottom:1px solid #eeeef2;"><img src="' . esc_url( $image ) . '" alt="" width="44" height="44" style="display:block;width:44px;height:44px;object-fit:cover;border:0;border-radius:6px;"></td>' : '' )
                . '<td valign="top" style="padding:10px 6px;border-bottom:1px solid #eeeef2;font-family:' . $font . ';font-size:14px;color:' . esc_attr( $s['color'] ) . ';">' . esc_html( $item->get_name() )
                . ( $meta !== '' ? '<div style="font-size:12px;color:#77748b;">' . wp_kses_post( $meta ) . '</div>' : '' ) . $sku . '</td>'
                . '<td valign="top" align="center" style="padding:10px 6px;border-bottom:1px solid #eeeef2;font-family:' . $font . ';font-size:14px;color:#77748b;white-space:nowrap;">× ' . (int) $item->get_quantity() . '</td>'
                . '<td valign="top" style="padding:10px 0;border-bottom:1px solid #eeeef2;font-family:' . $font . ';font-size:14px;font-weight:bold;color:' . esc_attr( $s['color'] ) . ';text-align:' . $env['end'] . ';white-space:nowrap;">' . wp_kses_post( $order->get_formatted_line_subtotal( $item ) ) . '</td></tr>';
        }
        return $hook( 'woocommerce_email_before_order_table' )
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>'
            . $hook( 'woocommerce_email_after_order_table' );
    }

    private static function order_totals( array $env, $order ): string {
        $s    = $env['s'];
        $font = $env['font'];
        $rows = '';
        $all  = $order->get_order_item_totals();
        $last = array_key_last( $all );
        foreach ( $all as $key => $total ) {
            $strong = $key === $last ? 'font-weight:bold;font-size:16px;' : 'font-size:14px;';
            $rows  .= '<tr><td style="padding:3px 0;font-family:' . $font . ';' . $strong . 'color:' . esc_attr( $s['color'] ) . ';">' . wp_kses_post( rtrim( (string) $total['label'], ':' ) ) . '</td><td style="padding:3px 0;font-family:' . $font . ';' . $strong . 'color:' . esc_attr( $s['color'] ) . ';text-align:' . $env['end'] . ';">' . wp_kses_post( (string) $total['value'] ) . '</td></tr>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>';
    }

    private static function addresses( array $d, array $env, $order ): string {
        $s    = $env['s'];
        $font = $env['font'];
        $he   = class_exists( 'Qwoo_I18n' ) && Qwoo_I18n::default_code() === 'he';
        $one  = static function ( string $title, string $html ) use ( $font, $s ) {
            return $html === '' ? '' : '<td class="qm-col" valign="top" style="padding:0 0 8px;font-family:' . $font . ';font-size:13px;line-height:1.5;color:' . esc_attr( $s['color'] ) . ';"><div style="font-weight:bold;margin-bottom:3px;">' . esc_html( $title ) . '</div>' . wp_kses_post( $html ) . '</td>';
        };
        $cells = '';
        if ( $d['which'] !== 'shipping' ) $cells .= $one( $he ? 'כתובת לחיוב' : 'Billing address', (string) $order->get_formatted_billing_address() );
        if ( $d['which'] !== 'billing' ) $cells .= $one( $he ? 'כתובת למשלוח' : 'Shipping address', (string) $order->get_formatted_shipping_address() );
        return $cells === '' ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table>';
    }
}

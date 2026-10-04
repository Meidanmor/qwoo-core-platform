<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Everything about the "global:{key}" color-reference system: reading the
 * saved palette, sanitizing any color field (literal hex or a global
 * reference), and rendering the color-picker control used across the whole
 * admin UI (section fields, header announcement, PWA colors, row-level
 * section_bg_color, and the palette definitions themselves).
 */
trait SB_Color_Helpers {

    private static function get_global_colors() {
        $options  = get_option( 'shop_builder_options', [] );
        $saved    = $options['branding']['global_colors'] ?? [];
        $defaults = [ 'primary' => '#1976D2', 'secondary' => '#9C27B0', 'accent' => '#FF4081', 'text' => '#111111' ];

        $colors = [];
        foreach ( self::GLOBAL_COLOR_KEYS as $key => $meta ) {
            $colors[ $key ] = ! empty( $saved[ $key ] ) ? $saved[ $key ] : $defaults[ $key ];
        }
        return $colors;
    }

    /**
     * Shared sanitizer for every color field (block/section colors, header
     * announcement, PWA colors). Accepts:
     *   - '#rgb' / '#rrggbb'
     *   - '#rgba' / '#rrggbbaa' (color with opacity) when $allow_alpha
     *   - 'global:{key}' — a reference to a GLOBAL_COLOR_KEYS palette color
     * Returns lowercase '#rrggbb' or '#rrggbbaa' (a fully opaque alpha is
     * dropped), the global reference, or '' (unset / invalid).
     */
    private static function sanitize_color_value( $value, $allow_alpha = true ) {
        $value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
        if ( $value === '' ) return '';

        // Legacy value from the short-lived "Transparent" chip.
        if ( $value === 'transparent' ) return $allow_alpha ? '#00000000' : '';

        if ( strpos( $value, 'global:' ) === 0 ) {
            $key = substr( $value, 7 );
            return array_key_exists( $key, self::GLOBAL_COLOR_KEYS ) ? $value : '';
        }

        if ( $value[0] !== '#' ) $value = '#' . $value;
        if ( preg_match( '/^#[0-9a-f]{3,4}$/', $value ) ) {
            $value = '#' . implode( '', array_map( function ( $c ) { return $c . $c; }, str_split( substr( $value, 1 ) ) ) );
        }
        if ( ! preg_match( '/^#([0-9a-f]{6}|[0-9a-f]{8})$/', $value ) ) return '';
        if ( strlen( $value ) === 9 && ( ! $allow_alpha || substr( $value, 7 ) === 'ff' ) ) {
            $value = substr( $value, 0, 7 );
        }
        return $value;
    }

    /**
     * Renders one color control. The value lives in a hidden input (so it
     * can genuinely be "unset" = ''); the swatch opens the custom picker in
     * sb-color-fields.js (saturation/brightness, hue, opacity, HEX input,
     * global palette chips), and the hex field next to it accepts typed
     * values. Builder fields use the matching JS template
     * (colorFieldTemplate()) so both render identical markup.
     *
     * $value: '' = unset (the frontend uses its own default).
     * $picker_seed: where the picker starts when nothing is chosen yet.
     * $allow_global: offer the Branding palette chips (off for the palette
     *   definitions themselves).
     * $allow_alpha: allow colors with opacity (#rrggbbaa). Defaults to
     *   $allow_global; off for the palette definitions and the PWA manifest
     *   colors, which must be opaque.
     */
    private static function render_color_field( $full_name, $label, $value, $picker_seed = '#000000', $allow_global = true, $allow_alpha = null ) {
        if ( $allow_alpha === null ) $allow_alpha = $allow_global;
        $is_global  = is_string( $value ) && strpos( $value, 'global:' ) === 0;
        $global_key = $is_global ? substr( $value, 7 ) : '';
        ?>
        <div class="qwoo-color-field">
            <span class="field-label"><?php echo esc_html( $label ); ?></span>
            <span class="qwoo-color-control"
                  data-global-key="<?php echo esc_attr( $global_key ); ?>"
                  data-seed="<?php echo esc_attr( $picker_seed ); ?>"
                  data-allow-alpha="<?php echo $allow_alpha ? '1' : '0'; ?>"
                  data-allow-global="<?php echo $allow_global ? '1' : '0'; ?>">
                <button type="button" class="qwoo-color-swatch-btn qwoo-color-swatch-btn--unset"
                        aria-label="<?php echo esc_attr( 'Pick ' . $label ); ?>" aria-haspopup="dialog" aria-expanded="false"></button>
                <input type="hidden" class="qwoo-color-value" name="<?php echo esc_attr( $full_name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
                <?php /* Swatch + hex are filled in from the hidden value by bindColorFieldControls(). */ ?>
                <input type="text" class="qwoo-color-hex" value="" placeholder="Default" spellcheck="false" autocomplete="off" maxlength="24" aria-label="Hex color" />
                <button type="button" class="button-link qwoo-color-reset" style="<?php echo $value ? '' : 'display:none;'; ?>">Reset</button>
            </span>
        </div>
        <?php
    }

    private function render_section_color_field( $name, $field, $label, $value ) {
        self::render_color_field( "{$name}[{$field}]", $label, $value );
    }
}

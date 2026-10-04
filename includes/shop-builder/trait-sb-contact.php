<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Everything about the floating Contact Button: placeholder text per method
 * type, turning a saved value into a usable href, sanitizing the posted
 * methods array, and building the public payload consumed by the
 * /qwoo/v1/contact-options REST endpoint.
 */
trait SB_Contact {

    public static function contact_placeholder( $type ) {
        switch ( $type ) {
            case 'whatsapp': return 'Phone number, no + or spaces (e.g. 15551234567)';
            case 'phone':    return 'Phone number (e.g. +1 555 123 4567)';
            case 'email':    return 'name@yourstore.com';
            case 'telegram': return '@yourusername';
            case 'custom':   return 'Full URL (e.g. https://m.me/yourpage)';
            default:         return '';
        }
    }

    /**
     * Turns a saved method's raw value into a usable href. Centralized here
     * so the "how do we parse a WhatsApp number" logic lives in exactly one
     * place, reused by both sanitize_options() (validating enough to store)
     * and get_enabled_contact_methods() (building the final REST payload).
     */
    private static function contact_href( $type, $value ) {
        switch ( $type ) {
            case 'whatsapp':
                $digits = preg_replace( '/\D/', '', $value );
                return $digits ? 'https://wa.me/' . $digits : '';

            case 'phone':
                $digits = preg_replace( '/[^\d+]/', '', $value );
                return $digits ? 'tel:' . $digits : '';

            case 'email':
                return is_email( $value ) ? 'mailto:' . $value : '';

            case 'telegram':
                $username = ltrim( trim( $value ), '@' );
                $username = preg_replace( '#^https?://(t\.me|telegram\.me)/#i', '', $username );
                $username = trim( $username, '/' );
                return $username ? 'https://t.me/' . $username : '';

            case 'custom':
                return esc_url_raw( $value );

            default:
                return '';
        }
    }

    /**
     * Sanitizes the posted contact.methods array. Rows with no type/value
     * are dropped entirely rather than saved empty — keeps "does this
     * method have real data" a simple check downstream.
     */
    private function sanitize_contact_methods( $posted_methods ) {
        $clean = [];

        if ( ! is_array( $posted_methods ) ) return $clean;

        foreach ( $posted_methods as $row ) {
            if ( ! is_array( $row ) ) continue;

            $type = sanitize_key( $row['type'] ?? '' );
            if ( ! in_array( $type, self::CONTACT_METHOD_TYPES, true ) ) continue;

            $value = trim( wp_unslash( $row['value'] ?? '' ) );
            if ( $value === '' ) continue;

            $method = [
                    'type'    => $type,
                    'enabled' => ! empty( $row['enabled'] ),
                    'value'   => $type === 'email' ? sanitize_email( $value ) : sanitize_text_field( $value ),
            ];

            if ( $type === 'custom' ) {
                $method['label'] = sanitize_text_field( $row['label'] ?? '' );
                $method['icon']  = esc_url_raw( $row['icon'] ?? '' );
            }

            $clean[] = $method;
        }

        return $clean;
    }

    /**
     * Public: returns only the data the frontend actually needs — enabled
     * methods with a ready-to-use href — and only if the master toggle is
     * on. Used by the /qwoo/v1/contact-options REST endpoint. Delivered
     * live via REST rather than pushed to a GitHub JSON file on purpose:
     * this data doesn't need an offline fallback the way products do.
     */
    public static function get_enabled_contact_methods() {
        $options = get_option( 'shop_builder_options', [] );
        $contact = $options['contact'] ?? [];

        if ( empty( $contact['enabled'] ) || empty( $contact['methods'] ) ) {
            return [];
        }

        $result = [];

        foreach ( $contact['methods'] as $method ) {
            if ( empty( $method['enabled'] ) || empty( $method['value'] ) ) continue;

            $href = self::contact_href( $method['type'], $method['value'] );
            if ( ! $href ) continue;

            $result[] = [
                    'type'  => $method['type'],
                    'href'  => $href,
                    'label' => $method['type'] === 'custom' ? ( $method['label'] ?? '' ) : '',
                    'icon'  => $method['type'] === 'custom' ? ( $method['icon'] ?? '' ) : '',
            ];
        }

        return $result;
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The store's language: what shoppers see (the storefront, WooCommerce's
 * texts and emails, and qwoo's own messages). Chosen at sign-up and in the
 * dashboard (Settings → Store details).
 *
 * Changing it switches WordPress' locale (WPLANG), installs WordPress' and
 * WooCommerce's language packs, and publishes public/config/languages.json,
 * which the storefront reads at build time (a new build follows the push).
 */
class Qwoo_Store_Language {

    /** Code => WordPress locale. */
    const LANGS = [ 'en' => 'en_US', 'he' => 'he_IL' ];

    const OPTION = 'qwoo_store_lang';

    /** The main language's code ('en' when never set). */
    public static function get() {
        $code = (string) get_option( self::OPTION, 'en' );
        return isset( self::LANGS[ $code ] ) ? $code : 'en';
    }

    public static function locale( $code = null ) {
        return self::LANGS[ $code ?? self::get() ] ?? 'en_US';
    }

    /** What the storefront build reads (config/languages.json). */
    public static function config_json() {
        return wp_json_encode( [ 'main' => self::get(), 'extra' => [], 'prefixes' => (object) [] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    }

    /**
     * Sets the main language. $publish: also push languages.json now (a
     * store setup publishes it with everything else instead).
     *
     * @return true|WP_Error
     */
    public static function set( $code, $publish = true ) {
        if ( ! isset( self::LANGS[ $code ] ) ) {
            return new WP_Error( 'qwoo_lang', 'Unknown language.' );
        }
        $locale = self::LANGS[ $code ];
        $warnings = [];
        if ( $locale !== 'en_US' ) {
            $warnings = self::install_packs( $locale );
        }
        update_option( self::OPTION, $code );
        update_option( 'WPLANG', $locale === 'en_US' ? '' : $locale );
        self::localize_defaults( $code );

        if ( $publish && function_exists( 'aps_commit_to_github' ) ) {
            $pushed = aps_commit_to_github( self::config_json(), 'public/config/languages.json', 'Store language: ' . $code );
            if ( $pushed === false ) {
                $warnings[] = 'The storefront couldn\'t be updated. Publish from the Store builder to try again.';
            }
        }
        return $warnings ? new WP_Error( 'qwoo_lang_partial', implode( ' ', $warnings ), [ 'saved' => true ] ) : true;
    }

    /** WordPress' and WooCommerce's translations for a locale. @return string[] problems */
    private static function install_packs( $locale ) {
        $problems = [];
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        if ( ! in_array( $locale, get_available_languages(), true ) && ! wp_download_language_pack( $locale ) ) {
            $problems[] = 'WordPress\' translation couldn\'t be installed.';
        }

        // WooCommerce: its language pack from translate.wordpress.org.
        if ( defined( 'WC_VERSION' ) && ! is_readable( WP_LANG_DIR . "/plugins/woocommerce-{$locale}.mo" ) ) {
            $api     = translations_api( 'plugins', [ 'slug' => 'woocommerce', 'version' => WC_VERSION ] );
            $package = '';
            if ( ! is_wp_error( $api ) ) {
                foreach ( (array) ( $api['translations'] ?? [] ) as $translation ) {
                    if ( ( $translation['language'] ?? '' ) === $locale ) {
                        $package = (string) $translation['package'];
                        break;
                    }
                }
            }
            if ( $package !== '' ) {
                $upgrader = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
                $result   = $upgrader->upgrade( (object) [ 'type' => 'plugin', 'slug' => 'woocommerce', 'language' => $locale, 'version' => WC_VERSION, 'package' => $package, 'autoupdate' => true ] );
                if ( ! $result || is_wp_error( $result ) ) {
                    $problems[] = 'WooCommerce\'s translation couldn\'t be installed.';
                }
            } else {
                $problems[] = 'WooCommerce\'s translation isn\'t available.';
            }
        }
        return $problems;
    }

    /**
     * Texts WooCommerce saved in English at setup (payment method names,
     * instructions) follow the new language while the owner hasn't changed them.
     */
    private static function localize_defaults( $code ) {
        $texts = [
            'woocommerce_cod_settings'  => [ 'title' => 'Cash on delivery', 'description' => 'Pay with cash upon delivery.', 'instructions' => 'Pay with cash upon delivery.' ],
            'woocommerce_bacs_settings' => [ 'title' => 'Direct bank transfer', 'description' => 'Make your payment directly into our bank account. Please use your Order ID as the payment reference. Your order will not be shipped until the funds have cleared in our account.' ],
        ];
        // WooCommerce's default category ("Uncategorized"), while it has its first name.
        $default_cat = (int) get_option( 'default_product_cat' );
        $term        = $default_cat ? get_term( $default_cat, 'product_cat' ) : null;
        if ( $term && ! is_wp_error( $term ) && in_array( $term->name, [ 'Uncategorized', Qwoo_I18n::t( 'Uncategorized', 'he' ) ], true ) ) {
            wp_update_term( $term->term_id, 'product_cat', [ 'name' => Qwoo_I18n::t( 'Uncategorized', $code ) ] );
        }

        foreach ( $texts as $option => $fields ) {
            $settings = get_option( $option );
            if ( ! is_array( $settings ) ) {
                continue;
            }
            $changed = false;
            foreach ( $fields as $field => $english ) {
                $current = (string) ( $settings[ $field ] ?? '' );
                $known   = [ $english, Qwoo_I18n::t( $english, 'he' ) ];
                if ( $current === '' || in_array( $current, $known, true ) ) {
                    $settings[ $field ] = Qwoo_I18n::t( $english, $code );
                    $changed            = true;
                }
            }
            if ( $changed ) {
                update_option( $option, $settings );
            }
        }
    }
}

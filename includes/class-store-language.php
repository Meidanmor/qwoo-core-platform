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

    /** What the storefront build reads (config/languages.json): the main language and the live extra ones. */
    public static function config_json() {
        $live     = self::live_extra();
        $prefixes = [];
        foreach ( $live as $code ) {
            $prefixes[ $code ] = self::prefix( $code );
        }
        return wp_json_encode( [ 'main' => self::get(), 'extra' => $live, 'prefixes' => (object) $prefixes ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
    }

    /* ---------------- extra languages (the "extra_languages" premium addon) ---------------- */

    const EXTRA_OPTION = 'qwoo_languages';

    /** Addresses a prefix can't take: the storefront's own pages. */
    const RESERVED = [ 'product', 'products', 'product-category', 'cart', 'checkout', 'my-account', 'blog', 'thank-you', 'review', 'forgot-password', 'reset-password', 'auth', 'config', 'data', 'wp-json', 'assets', 'icons', 'sections', 'branding', 'homepage-hero', 'sitemap.xml', 'robots.txt', 'api' ];

    /** { extra: [codes], prefixes: { code: prefix }, live: { code: bool } } */
    public static function extra_settings() {
        $s     = get_option( self::EXTRA_OPTION, [] );
        $s     = is_array( $s ) ? $s : [];
        $main  = self::get();
        $extra = array_values( array_filter( (array) ( $s['extra'] ?? [] ), static fn( $c ) => isset( self::LANGS[ $c ] ) && $c !== $main ) );
        return [ 'extra' => $extra, 'prefixes' => (array) ( $s['prefixes'] ?? [] ), 'live' => (array) ( $s['live'] ?? [] ) ];
    }

    /** Whether the store's plan includes extra languages. */
    public static function allowed() {
        return class_exists( 'Qwoo_Platform_Dashboard' ) && Qwoo_Platform_Dashboard::has_feature( 'extra_languages' );
    }

    /** Extra languages the owner added (whether live or not). Empty without the addon. */
    public static function extra() {
        return self::allowed() ? self::extra_settings()['extra'] : [];
    }

    /** Extra languages shoppers can see now. */
    public static function live_extra() {
        $s = self::extra_settings();
        return array_values( array_filter( self::extra(), static fn( $c ) => ! empty( $s['live'][ $c ] ) ) );
    }

    /** The address prefix of an extra language ("en" → /en/…), the code by default. */
    public static function prefix( $code ) {
        $p = (string) ( self::extra_settings()['prefixes'][ $code ] ?? '' );
        return $p !== '' ? $p : $code;
    }

    /** Why a prefix can't be used ('' when it can). $taken: prefixes of the other languages. */
    public static function prefix_problem( $prefix, array $taken = [] ) {
        if ( ! preg_match( '/^[a-z]{2,10}(-[a-z0-9]{2,8})?$/', $prefix ) ) {
            return 'Use 2 to 10 small English letters, like en or us.';
        }
        if ( in_array( $prefix, self::RESERVED, true ) || in_array( $prefix, $taken, true ) ) {
            return 'That address is already used by your store.';
        }
        // A top-level page of the store with the same address.
        $options = (array) get_option( 'shop_builder_options', [] );
        foreach ( (array) ( $options['custom_pages'] ?? [] ) as $page ) {
            if ( is_array( $page ) && empty( $page['parent'] ) && sanitize_title( (string) ( $page['slug'] ?? '' ) ?: (string) ( $page['title'] ?? '' ) ) === $prefix ) {
                return 'One of your pages already uses that address.';
            }
        }
        return '';
    }

    /**
     * Saves the extra languages: { extra: [ { code, prefix, live } ] }.
     * @return true|WP_Error
     */
    public static function save_extra( array $rows ) {
        if ( $rows && ! self::allowed() ) {
            return new WP_Error( 'qwoo_lang_plan', 'Extra languages are part of the Pro and Plus plans.' );
        }
        $main = self::get();
        $out  = [ 'extra' => [], 'prefixes' => [], 'live' => [] ];
        foreach ( $rows as $row ) {
            $code = (string) ( $row['code'] ?? '' );
            if ( ! isset( self::LANGS[ $code ] ) || $code === $main || in_array( $code, $out['extra'], true ) ) {
                continue;
            }
            $prefix  = strtolower( trim( (string) ( $row['prefix'] ?? '' ) ) ) ?: $code;
            $problem = self::prefix_problem( $prefix, array_values( $out['prefixes'] ) );
            if ( $problem !== '' ) {
                return new WP_Error( 'qwoo_lang_prefix', $problem );
            }
            $out['extra'][]           = $code;
            $out['prefixes'][ $code ] = $prefix;
            $out['live'][ $code ]     = ! empty( $row['live'] );
        }
        $warnings = [];
        foreach ( $out['extra'] as $code ) {
            if ( self::LANGS[ $code ] !== 'en_US' ) {
                $warnings = array_merge( $warnings, self::install_packs( self::LANGS[ $code ] ) );
            }
        }
        update_option( self::EXTRA_OPTION, $out );
        return $warnings ? new WP_Error( 'qwoo_lang_partial', implode( ' ', $warnings ), [ 'saved' => true ] ) : true;
    }

    /* ---------------- the language of this request ---------------- */

    private static $request_lang = null;

    /**
     * The language a storefront request asks for (header X-Qwoo-Lang, sent by
     * the storefront on pages of an extra language): a live extra language,
     * else '' (the main language).
     */
    public static function request_lang() {
        if ( self::$request_lang !== null ) {
            return self::$request_lang;
        }
        $asked = isset( $_SERVER['HTTP_X_QWOO_LANG'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_X_QWOO_LANG'] ) ) : '';
        self::$request_lang = $asked !== '' && $asked !== self::get() && in_array( $asked, self::live_extra(), true ) ? $asked : '';
        return self::$request_lang;
    }

    /** For tests and internal requests. */
    public static function use_request_lang( $code ) {
        self::$request_lang = (string) $code;
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
        // The new main language stops being an extra one (its translations stay saved).
        $extra = get_option( self::EXTRA_OPTION, [] );
        if ( is_array( $extra ) && in_array( $code, (array) ( $extra['extra'] ?? [] ), true ) ) {
            $extra['extra'] = array_values( array_diff( (array) $extra['extra'], [ $code ] ) );
            unset( $extra['prefixes'][ $code ], $extra['live'][ $code ] );
            update_option( self::EXTRA_OPTION, $extra );
        }
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
    public static function install_packs( $locale ) {
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

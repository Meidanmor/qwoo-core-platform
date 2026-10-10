<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * "Move your store in" for the platform dashboard (used by
 * Qwoo_Platform_Dashboard): brings an old WordPress / WooCommerce store in
 * through its YallaFlow Mover plugin (Qwoo_Migration holds the connection).
 *
 *   migrate_check   { code }   connects and reads what the old store has
 *   migrate_start   { what }   starts (or resumes) the move; what: orders,
 *                              coupons, reviews, blog, remove_existing
 *   migrate_run                works for about RUN_SECONDS and says how far
 *                              it got; the platform calls it until done
 *   migrate_status / migrate_cancel / migrate_forget
 *
 * The move goes phase by phase (PHASES), each page by page in the old
 * site's ID order; the state (option Qwoo_Migration::OPTION) remembers the
 * phase and the last old ID done, so a run that stops halfway goes on
 * where it was. Every item carries its old ID (Qwoo_Migration::META):
 * running the move again updates items instead of adding copies.
 *
 * Nothing is sent while moving: no emails (orders, accounts), no review
 * requests, no stock changes, no "on sale" alerts.
 */
trait Qwoo_Platform_Migration {

    private static $mig_seconds = 20;

    /** phase => [ label, part of: always | an option in 'what' ]. Run in this order. */
    private static $mig_phases = [
        'settings'        => [ 'Store settings', 'always' ],
        'remove'          => [ 'Removing the products already here', 'remove_existing' ],
        'attributes'      => [ 'Product options', 'always' ],
        'product_cat'     => [ 'Product categories', 'always' ],
        'product_tag'     => [ 'Product tags', 'always' ],
        'products'        => [ 'Products', 'always' ],
        'links'           => [ 'Related products', 'always' ],
        'customers'       => [ 'Customers', 'always' ],
        'coupons'         => [ 'Discount codes', 'coupons' ],
        'orders'          => [ 'Orders', 'orders' ],
        'reviews'         => [ 'Reviews', 'reviews' ],
        'post_categories' => [ 'Blog categories', 'blog' ],
        'posts'           => [ 'Blog posts', 'blog' ],
    ];

    private static $mig_options = [ 'orders', 'coupons', 'reviews', 'blog', 'remove_existing' ];

    /** WooCommerce settings brought over (the Mover sends the same list; nothing about payments). */
    private static $mig_settings = [
        'woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_city', 'woocommerce_store_postcode',
        'woocommerce_default_country', 'woocommerce_currency', 'woocommerce_currency_pos', 'woocommerce_price_thousand_sep',
        'woocommerce_price_decimal_sep', 'woocommerce_price_num_decimals', 'woocommerce_weight_unit', 'woocommerce_dimension_unit',
        'woocommerce_allowed_countries', 'woocommerce_all_except_countries', 'woocommerce_specific_allowed_countries',
        'woocommerce_ship_to_countries', 'woocommerce_specific_ship_to_countries', 'woocommerce_default_customer_address',
        'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on', 'woocommerce_shipping_tax_class',
        'woocommerce_tax_round_at_subtotal', 'woocommerce_tax_display_shop', 'woocommerce_tax_display_cart', 'woocommerce_tax_total_display',
        'woocommerce_enable_coupons', 'woocommerce_calc_discounts_sequentially', 'woocommerce_manage_stock', 'woocommerce_hold_stock_minutes',
        'woocommerce_notify_low_stock', 'woocommerce_notify_no_stock', 'woocommerce_notify_low_stock_amount', 'woocommerce_notify_no_stock_amount',
        'woocommerce_hide_out_of_stock_items', 'woocommerce_stock_format', 'woocommerce_enable_guest_checkout',
        'woocommerce_enable_checkout_login_reminder', 'woocommerce_enable_signup_and_login_from_checkout',
        'woocommerce_enable_myaccount_registration', 'woocommerce_enable_shipping_calc', 'woocommerce_shipping_cost_requires_address',
        'woocommerce_ship_to_destination',
    ];

    private static $mig_address_fields = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state', 'email', 'phone' ];

    /** Shipping methods the dashboard can show; others are listed for the owner to set up again. */
    private static $mig_methods = [ 'flat_rate', 'free_shipping', 'local_pickup' ];

    /** Accounts that are never replaced by a customer of the old store. */
    private static $mig_staff = [ 'administrator', 'shop_manager', 'editor', 'author', 'contributor' ];

    /** @var array The move's state while a run works. */
    private static $mig = [];
    private static $mig_cache = [];
    private static $mig_deadline = 0.0;
    private static $mig_rated = [];

    /* ---------------- state ---------------- */

    private static function mig_state() {
        $s = get_option( Qwoo_Migration::OPTION, [] );
        return is_array( $s ) ? $s : [];
    }

    private static function mig_save() {
        self::$mig['updated'] = time();
        update_option( Qwoo_Migration::OPTION, self::$mig, false );
    }

    /** The phases this move runs, in order. */
    private static function mig_run_phases( array $what ) {
        $out = [];
        foreach ( self::$mig_phases as $key => [ $label, $part ] ) {
            if ( $part === 'always' || in_array( $part, $what, true ) ) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /** How many items a phase has, from the old site's counts. */
    private static function mig_total( $phase, array $s ) {
        $c = (array) ( $s['summary']['counts'] ?? [] );
        switch ( $phase ) {
            case 'settings':
                return 1;
            case 'remove':
                return count( (array) ( $s['remove_ids'] ?? [] ) );
            case 'links':
                return (int) ( $c['products'] ?? 0 );
            default:
                return (int) ( $c[ $phase ] ?? 0 );
        }
    }

    /** What the dashboard sees (never the secret). */
    private static function mig_public( ?array $s = null ) {
        $s = $s ?? self::mig_state();
        if ( empty( $s['source'] ) ) {
            return [ 'connected' => false, 'status' => 'none', 'existing_products' => self::mig_existing_count() ];
        }
        $what   = (array) ( $s['what'] ?? [] );
        $run    = self::mig_run_phases( $what );
        $phases = [];
        foreach ( $run as $i => $key ) {
            $done     = (array) ( $s['counts'][ $key ] ?? [] );
            $phases[] = [
                'key'     => $key,
                'label'   => self::$mig_phases[ $key ][0],
                'total'   => self::mig_total( $key, $s ),
                'done'    => (int) ( $done['done'] ?? 0 ),
                'created' => (int) ( $done['created'] ?? 0 ),
                'updated' => (int) ( $done['updated'] ?? 0 ),
                'skipped' => (int) ( $done['skipped'] ?? 0 ),
                'state'   => $s['status'] === 'done' || $i < (int) ( $s['phase'] ?? 0 ) ? 'done' : ( $i === (int) ( $s['phase'] ?? 0 ) && $s['status'] === 'running' ? 'now' : 'waiting' ),
            ];
        }
        return [
            'connected'         => ! empty( $s['secret'] ),
            'status'            => (string) ( $s['status'] ?? 'checked' ), // checked | running | done | cancelled | failed
            'source'            => array_intersect_key( (array) $s['source'], array_flip( [ 'name', 'url', 'host' ] ) ),
            'summary'           => $s['summary'] ?? null,
            'what'              => $what,
            'phases'            => $phases,
            'problems'          => array_values( (array) ( $s['problems'] ?? [] ) ),
            'error'             => (string) ( $s['error'] ?? '' ),
            'started'           => (int) ( $s['started'] ?? 0 ),
            'finished'          => (int) ( $s['finished'] ?? 0 ),
            'updated'           => (int) ( $s['updated'] ?? 0 ),
            'runs'              => (int) ( $s['runs'] ?? 0 ),
            'existing_products' => self::mig_existing_count(),
            'notes'             => array_values( (array) ( $s['notes'] ?? [] ) ),
        ];
    }

    /** Products in this store that didn't come from the old one. */
    private static function mig_existing_count() {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')
             AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s )",
            Qwoo_Migration::META
        ) );
    }

    private static function mig_secret( array $s ) {
        return Qwoo_Migration::unseal( (string) ( $s['secret'] ?? '' ) );
    }

    /* ---------------- dashboard actions ---------------- */

    private static function action_migrate_status( array $params ) {
        return self::mig_public();
    }

    /** { code }: connects to the old site and reads what it has. */
    private static function action_migrate_check( array $params ) {
        $s = self::mig_state();
        if ( ( $s['status'] ?? '' ) === 'running' ) {
            return self::error( 'qwoo_dashboard_busy', 'A move is running. Wait for it to finish, or stop it first.', 409 );
        }
        $code = Qwoo_Migration::parse_code( $params['code'] ?? '' );
        if ( is_wp_error( $code ) ) {
            return $code;
        }
        $here = wp_parse_url( home_url() );
        $home = strtolower( (string) ( $here['host'] ?? '' ) ) . ( ! empty( $here['port'] ) ? ':' . (int) $here['port'] : '' );
        if ( $code['host'] === $home ) {
            return self::bad( 'That code is from this store. Create the code on your old site.' );
        }
        $summary = Qwoo_Migration::fetch( $code['endpoint'], $code['secret'], 'summary' );
        if ( is_wp_error( $summary ) ) {
            return $summary;
        }
        if ( empty( $summary['site'] ) || ! is_array( $summary['counts'] ?? null ) ) {
            return self::bad( 'Your old site didn\'t answer like YallaFlow Mover. Update the plugin there and try again.' );
        }
        if ( empty( $summary['site']['woocommerce'] ) ) {
            return self::bad( 'WooCommerce isn\'t active on your old site, so there\'s nothing to bring in. Activate it there and try again.' );
        }
        $site   = (array) $summary['site'];
        $source = [
            'name'     => mb_substr( sanitize_text_field( (string) ( $site['name'] ?? $code['name'] ) ), 0, 100 ),
            'url'      => esc_url_raw( (string) ( $site['url'] ?? '' ) ),
            'host'     => $code['host'],
            'endpoint' => $code['endpoint'],
            // Items from this source carry "<key>:<old id>".
            'key'      => substr( md5( $code['host'] ), 0, 8 ),
        ];
        $same = ( $s['source']['key'] ?? '' ) === $source['key'];
        $new  = [
            'source'  => $source,
            'secret'  => Qwoo_Migration::seal( $code['secret'] ),
            'summary' => self::mig_clean_summary( $summary ),
            'status'  => 'checked',
            'checked' => time(),
            // A move from the same store again keeps what it learned (maps, runs).
            'maps'    => $same ? (array) ( $s['maps'] ?? [] ) : [],
            'runs'    => $same ? (int) ( $s['runs'] ?? 0 ) : 0,
            'what'    => $same ? (array) ( $s['what'] ?? [] ) : [],
        ];
        update_option( Qwoo_Migration::OPTION, $new, false );
        return self::mig_public( $new );
    }

    private static function mig_clean_summary( array $summary ) {
        $site   = (array) $summary['site'];
        $counts = [];
        foreach ( (array) $summary['counts'] as $key => $value ) {
            if ( $key === 'product_types' && is_array( $value ) ) {
                foreach ( $value as $type => $n ) {
                    $counts['product_types'][ sanitize_key( $type ) ] = (int) $n;
                }
            } elseif ( is_scalar( $value ) ) {
                $counts[ sanitize_key( $key ) ] = (int) $value;
            }
        }
        $text = static fn( $key, $max = 100 ) => mb_substr( sanitize_text_field( (string) ( $site[ $key ] ?? '' ) ), 0, $max );
        return [
            'site'   => [
                'name'        => $text( 'name' ),
                'url'         => esc_url_raw( (string) ( $site['url'] ?? '' ) ),
                'wordpress'   => $text( 'wordpress', 20 ),
                'woocommerce' => $text( 'woocommerce', 20 ),
                'currency'    => strtoupper( $text( 'currency', 3 ) ),
                'country'     => $text( 'country', 10 ),
                'language'    => $text( 'language', 20 ),
                'seo'         => $text( 'seo', 20 ),
            ],
            'counts' => $counts,
        ];
    }

    /** { what: [...] }: starts the move (or goes on with a stopped one). */
    private static function action_migrate_start( array $params ) {
        $s = self::mig_state();
        if ( empty( $s['source'] ) || empty( $s['secret'] ) || self::mig_secret( $s ) === '' ) {
            return self::bad( 'Connect your old store first: paste a new code from YallaFlow Mover.' );
        }
        if ( ( $s['status'] ?? '' ) === 'running' ) {
            return self::mig_public( $s );
        }
        $resume = in_array( $s['status'] ?? '', [ 'failed', 'cancelled' ], true ) && ! empty( $params['resume'] );
        if ( ! $resume ) {
            $what = array_values( array_intersect( self::$mig_options, array_map( 'strval', (array) ( $params['what'] ?? [] ) ) ) );
            $s['what']       = $what;
            $s['phase']      = 0;
            $s['cursor']     = 0;
            $s['counts']     = [];
            $s['problems']   = [];
            $s['notes']      = [];
            $s['started']    = time();
            $s['finished']   = 0;
            $s['remove_ids'] = [];
            $s['runs']       = (int) ( $s['runs'] ?? 0 ) + 1;
            if ( in_array( 'remove_existing', $what, true ) ) {
                global $wpdb;
                $s['remove_ids'] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')
                     AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s ) ORDER BY p.ID LIMIT 5000",
                    Qwoo_Migration::META
                ) ) );
            }
        }
        $s['status'] = 'running';
        $s['error']  = '';
        update_option( Qwoo_Migration::OPTION, $s, false );
        return self::mig_public( $s );
    }

    private static function action_migrate_cancel( array $params ) {
        $s = self::mig_state();
        if ( ( $s['status'] ?? '' ) === 'running' ) {
            $s['status'] = 'cancelled';
            update_option( Qwoo_Migration::OPTION, $s, false );
        }
        return self::mig_public( $s );
    }

    /** Disconnects: the secret is forgotten (what came in stays). */
    private static function action_migrate_forget( array $params ) {
        $s = self::mig_state();
        if ( ( $s['status'] ?? '' ) === 'running' ) {
            return self::error( 'qwoo_dashboard_busy', 'Stop the move first.', 409 );
        }
        if ( ! empty( $s['secret'] ) ) {
            $secret = self::mig_secret( $s );
            if ( $secret !== '' ) {
                Qwoo_Migration::fetch( $s['source']['endpoint'], $secret, 'done' );
            }
        }
        delete_option( Qwoo_Migration::OPTION );
        return self::mig_public( [] );
    }

    /** Works for about $mig_seconds, then answers how far the move is. */
    private static function action_migrate_run( array $params ) {
        $s = self::mig_state();
        if ( ( $s['status'] ?? '' ) !== 'running' ) {
            return self::mig_public( $s );
        }
        $lock = 'qwoo_migration_lock';
        if ( get_transient( $lock ) ) {
            return self::mig_public( $s ) + [ 'busy' => true ];
        }
        set_transient( $lock, 1, 3 * MINUTE_IN_SECONDS );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 180 );
        }
        wp_raise_memory_limit( 'admin' );

        self::$mig          = $s;
        self::$mig_deadline = microtime( true ) + max( 5, (int) self::$mig_seconds );
        self::$mig_rated    = [];
        self::mig_quiet();
        $phases = self::mig_run_phases( (array) ( $s['what'] ?? [] ) );
        try {
            while ( self::mig_time_left() > 2 && self::$mig['status'] === 'running' ) {
                $index = (int) ( self::$mig['phase'] ?? 0 );
                if ( ! isset( $phases[ $index ] ) ) {
                    self::mig_finish();
                    break;
                }
                $phase = $phases[ $index ];
                $method = 'mig_phase_' . $phase;
                $done   = self::$method();
                if ( is_wp_error( $done ) ) {
                    $data = (array) $done->get_error_data();
                    self::mig_save();
                    if ( ! empty( $data['retry'] ) ) {
                        // The old site didn't answer: the platform tries again in a while.
                        return $done;
                    }
                    self::$mig['status'] = 'failed';
                    self::$mig['error']  = $done->get_error_message();
                    break;
                }
                if ( $done ) {
                    self::$mig['phase']  = $index + 1;
                    self::$mig['cursor'] = 0;
                }
                self::mig_save();
            }
        } catch ( Throwable $e ) {
            self::$mig['status'] = 'failed';
            self::$mig['error']  = 'The move stopped: ' . mb_substr( wp_strip_all_tags( $e->getMessage() ), 0, 300 );
        } finally {
            foreach ( array_keys( self::$mig_rated ) as $product_id ) {
                WC_Comments::clear_transients( $product_id );
            }
            // Someone may have pressed Stop meanwhile.
            if ( ( self::mig_state()['status'] ?? '' ) === 'cancelled' && self::$mig['status'] === 'running' ) {
                self::$mig['status'] = 'cancelled';
            }
            self::mig_save();
            delete_transient( $lock );
        }
        return self::mig_public( self::$mig );
    }

    private static function mig_time_left() {
        return self::$mig_deadline - microtime( true );
    }

    /**
     * Nothing goes out while moving, and old orders don't count again: no
     * emails (WooCommerce's are switched off, so it doesn't note them as
     * failed), no review requests, no stock, sales or code-use changes.
     */
    private static function mig_quiet() {
        add_filter( 'pre_wp_mail', '__return_false', 999 );
        foreach ( WC()->mailer()->get_emails() as $email ) {
            add_filter( 'woocommerce_email_enabled_' . $email->id, '__return_false', 999 );
        }
        self::quiet_sale_alerts();
        remove_action( 'woocommerce_order_status_completed', [ 'Qwoo_Reviews', 'schedule_request' ] );
        add_filter( 'woocommerce_can_reduce_order_stock', '__return_false', 999 );
        add_filter( 'woocommerce_payment_complete_reduce_order_stock', '__return_false', 999 );
        add_filter( 'woocommerce_can_restore_order_stock', '__return_false', 999 );
        foreach ( [ 'completed', 'processing', 'on-hold', 'cancelled', 'pending' ] as $status ) {
            remove_action( 'woocommerce_order_status_' . $status, 'wc_update_total_sales_counts' );
            remove_action( 'woocommerce_order_status_' . $status, 'wc_update_coupon_usage_counts' );
            remove_action( 'woocommerce_order_status_' . $status, 'wc_maybe_reduce_stock_levels' );
            remove_action( 'woocommerce_order_status_' . $status, 'wc_maybe_increase_stock_levels' );
        }
        remove_action( 'woocommerce_payment_complete', 'wc_maybe_reduce_stock_levels' );
        remove_action( 'woocommerce_order_status_completed', 'wc_paying_customer' );
    }

    /** Imported HTML, cleaned like the dashboard's, without links left empty (e.g. by a gallery shortcode). */
    private static function mig_html( $html ) {
        $html = self::clean_description( (string) $html );
        $html = preg_replace( '#<a\b[^>]*>\s*</a>#i', '', $html );
        $html = preg_replace( '/[ \t]*\n[\s]*/', "\n", $html );
        $html = preg_replace( '#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html );
        return trim( $html );
    }

    private static function mig_finish() {
        self::$mig['status']   = 'done';
        self::$mig['finished'] = time();
        // New orders are numbered after the old ones.
        $max = (int) ( self::$mig['max_number'] ?? 0 );
        if ( $max > 0 ) {
            global $wpdb;
            $current = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
            if ( $max >= $current ) {
                // MySQL only (elsewhere the numbers just carry on from the IDs).
                $quiet = $wpdb->suppress_errors( true );
                $wpdb->query( $wpdb->prepare( "ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = %d", $max + 1 ) );
                $wpdb->suppress_errors( $quiet );
            }
        }
        wc_delete_product_transients();
        // The old site's code stops working, and this store forgets it.
        $secret = self::mig_secret( self::$mig );
        if ( $secret !== '' ) {
            Qwoo_Migration::fetch( self::$mig['source']['endpoint'], $secret, 'done' );
        }
        unset( self::$mig['secret'] );
    }

    /* ---------------- helpers ---------------- */

    private static function mig_ref( $old ) {
        return self::$mig['source']['key'] . ':' . (int) $old;
    }

    /** Asks the old site for the current phase's next page. */
    private static function mig_fetch( $type, $limit = 0 ) {
        $secret = self::mig_secret( self::$mig );
        if ( $secret === '' ) {
            return new WP_Error( 'qwoo_migration_code', 'The connection to your old site was lost. Paste a new code to go on.', [ 'status' => 400 ] );
        }
        return Qwoo_Migration::fetch( self::$mig['source']['endpoint'], $secret, $type, (int) ( self::$mig['cursor'] ?? 0 ), $limit );
    }

    private static function mig_count( $phase, $what ) {
        self::$mig['counts'][ $phase ][ $what ] = (int) ( self::$mig['counts'][ $phase ][ $what ] ?? 0 ) + 1;
        if ( $what !== 'done' ) {
            self::$mig['counts'][ $phase ]['done'] = (int) ( self::$mig['counts'][ $phase ]['done'] ?? 0 ) + 1;
        }
    }

    /** A problem for the owner (the same text once; 200 at most). */
    private static function mig_problem( $text ) {
        $text = mb_substr( wp_strip_all_tags( (string) $text ), 0, 300 );
        $list = (array) ( self::$mig['problems'] ?? [] );
        if ( count( $list ) < 200 && ! in_array( $text, $list, true ) ) {
            $list[] = $text;
        }
        self::$mig['problems'] = $list;
    }

    private static function mig_note( $text ) {
        $list = (array) ( self::$mig['notes'] ?? [] );
        if ( ! in_array( $text, $list, true ) ) {
            $list[] = $text;
        }
        self::$mig['notes'] = $list;
    }

    /**
     * Goes through the old site's pages of $type, one item at a time, until
     * the time is up. Returns true when the phase is done (or a WP_Error).
     */
    private static function mig_pages( $type, $phase, callable $each, $limit = 0 ) {
        while ( self::mig_time_left() > 3 ) {
            $page = self::mig_fetch( $type, $limit );
            if ( is_wp_error( $page ) ) {
                return $page;
            }
            $items = (array) ( $page['items'] ?? [] );
            foreach ( $items as $item ) {
                if ( self::mig_time_left() < 3 ) {
                    return false; // this page again next time, from the cursor
                }
                if ( ! is_array( $item ) || empty( $item['id'] ) ) {
                    continue;
                }
                try {
                    $result = $each( $item );
                    self::mig_count( $phase, $result ?: 'skipped' );
                } catch ( Throwable $e ) {
                    self::mig_count( $phase, 'skipped' );
                    self::mig_problem( sprintf( '%s %d: %s', self::$mig_phases[ $phase ][0], (int) $item['id'], $e->getMessage() ) );
                }
                self::$mig['cursor'] = (int) $item['id'];
            }
            self::mig_save();
            if ( empty( $page['next'] ) ) {
                return true;
            }
            self::$mig['cursor'] = (int) $page['next'];
        }
        return false;
    }

    /** A post (of $types) brought in from the old ID, or 0. */
    private static function mig_post( $types, $old ) {
        global $wpdb;
        $types = (array) $types;
        $ref   = self::mig_ref( $old );
        $key   = 'p:' . implode( ',', $types ) . ':' . $ref;
        if ( ! isset( self::$mig_cache[ $key ] ) ) {
            $in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
            self::$mig_cache[ $key ] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
                 WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type IN ($in) AND p.post_status <> 'trash' LIMIT 1",
                array_merge( [ Qwoo_Migration::META, $ref ], $types )
            ) );
        }
        return self::$mig_cache[ $key ];
    }

    private static function mig_term( $taxonomy, $old ) {
        global $wpdb;
        if ( ! $old ) {
            return 0;
        }
        $ref = self::mig_ref( $old );
        $key = 't:' . $taxonomy . ':' . $ref;
        if ( ! isset( self::$mig_cache[ $key ] ) ) {
            self::$mig_cache[ $key ] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT m.term_id FROM {$wpdb->termmeta} m JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = m.term_id
                 WHERE m.meta_key = %s AND m.meta_value = %s AND tt.taxonomy = %s LIMIT 1",
                Qwoo_Migration::META, $ref, $taxonomy
            ) );
        }
        return self::$mig_cache[ $key ];
    }

    private static function mig_user( $old ) {
        global $wpdb;
        if ( ! $old ) {
            return 0;
        }
        $key = 'u:' . self::mig_ref( $old );
        if ( ! isset( self::$mig_cache[ $key ] ) ) {
            self::$mig_cache[ $key ] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                Qwoo_Migration::META, self::mig_ref( $old )
            ) );
        }
        return self::$mig_cache[ $key ];
    }

    private static function mig_comment( $old ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            Qwoo_Migration::META, self::mig_ref( $old )
        ) );
    }

    private static function mig_hpos() {
        return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /** An order (or refund, $type) brought in from the old ID, or 0. Works with both order storages. */
    private static function mig_order( $old, $type = 'shop_order' ) {
        global $wpdb;
        $ref = self::mig_ref( $old );
        if ( self::mig_hpos() ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT m.order_id FROM {$wpdb->prefix}wc_orders_meta m JOIN {$wpdb->prefix}wc_orders o ON o.id = m.order_id
                 WHERE m.meta_key = %s AND m.meta_value = %s AND o.type = %s LIMIT 1",
                Qwoo_Migration::META, $ref, $type
            ) );
        }
        return self::mig_post( [ $type ], $old );
    }

    /** The path of an old address, relative to the old site's home ('' when it isn't on it). */
    private static function mig_old_path( $link ) {
        $link = (string) $link;
        if ( $link === '' ) {
            return '';
        }
        $home = (string) wp_parse_url( (string) ( self::$mig['summary']['site']['url'] ?? '' ), PHP_URL_PATH );
        $path = rawurldecode( (string) wp_parse_url( $link, PHP_URL_PATH ) );
        $home = trim( $home, '/' );
        $path = trim( $path, '/' );
        if ( $home !== '' ) {
            if ( strpos( $path . '/', $home . '/' ) !== 0 ) {
                return '';
            }
            $path = trim( substr( $path, strlen( $home ) ), '/' );
        }
        return mb_substr( $path, 0, 400 );
    }

    /** Remembers an old address when it differs from the storefront's ($new without slashes). */
    private static function mig_remember_path( $kind, $id, $link, $new ) {
        $old    = self::mig_old_path( $link );
        $update = $kind === 'term' ? 'update_term_meta' : 'update_post_meta';
        $delete = $kind === 'term' ? 'delete_term_meta' : 'delete_post_meta';
        if ( $old !== '' && $old !== rawurldecode( $new ) ) {
            $update( $id, Qwoo_Migration::OLD_PATH, $old );
        } else {
            $delete( $id, Qwoo_Migration::OLD_PATH );
        }
    }

    /** The SEO fields from Yoast / Rank Math, as this store keeps them. */
    private static function mig_seo( $id, $kind, $seo ) {
        $seo = is_array( $seo ) ? $seo : [];
        $c   = [
            'title'       => mb_substr( sanitize_text_field( (string) ( $seo['title'] ?? '' ) ), 0, Qwoo_Seo::MAX_TITLE ),
            'description' => mb_substr( sanitize_text_field( (string) ( $seo['description'] ?? '' ) ), 0, Qwoo_Seo::MAX_DESCRIPTION ),
            'image_id'    => 0,
            'keyphrase'   => mb_substr( sanitize_text_field( (string) ( $seo['keyphrase'] ?? '' ) ), 0, 100 ),
            'noindex'     => ! empty( $seo['noindex'] ),
        ];
        if ( $c['title'] === '' && $c['description'] === '' && $c['keyphrase'] === '' && ! $c['noindex'] ) {
            return;
        }
        Qwoo_Seo::save( (int) $id, $kind, $c );
    }

    /**
     * An image from the old site, in this store's media (once per address;
     * resized and cleaned like an upload). Returns its ID, or 0.
     */
    private static function mig_image( $img ) {
        global $wpdb;
        $url = is_array( $img ) ? (string) ( $img['url'] ?? '' ) : (string) $img;
        $url = esc_url_raw( $url, [ 'https', 'http' ] );
        if ( $url === '' ) {
            return 0;
        }
        $hash = md5( $url );
        $key  = 'img:' . $hash;
        if ( isset( self::$mig_cache[ $key ] ) ) {
            return self::$mig_cache[ $key ];
        }
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type = 'attachment' LIMIT 1",
            Qwoo_Migration::SRC_META, $hash
        ) );
        if ( ! $id ) {
            if ( strpos( $url, 'https://' ) !== 0 && ! Qwoo_Migration::allow_local() ) {
                self::mig_problem( 'An image wasn\'t copied because its address isn\'t https: ' . $url );
                return self::$mig_cache[ $key ] = 0;
            }
            $resp = Qwoo_Migration::http( 'GET', $url, [ 'timeout' => 30, 'limit_response_size' => self::MAX_IMAGE_BYTES + 1, 'redirection' => 2 ] );
            $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
            $body = $code === 200 ? (string) wp_remote_retrieve_body( $resp ) : '';
            if ( $body === '' ) {
                self::mig_problem( 'An image couldn\'t be downloaded (' . ( is_wp_error( $resp ) ? $resp->get_error_message() : 'HTTP ' . $code ) . '): ' . $url );
                return self::$mig_cache[ $key ] = 0;
            }
            $name   = rawurldecode( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) );
            $upload = self::action_image_upload( [ 'data' => base64_encode( $body ), 'name' => $name ] );
            unset( $body );
            if ( is_wp_error( $upload ) ) {
                self::mig_problem( 'An image wasn\'t copied (' . $upload->get_error_message() . '): ' . $url );
                return self::$mig_cache[ $key ] = 0;
            }
            $id = (int) $upload['id'];
            update_post_meta( $id, Qwoo_Migration::SRC_META, $hash );
        }
        if ( is_array( $img ) ) {
            $alt = sanitize_text_field( (string) ( $img['alt'] ?? '' ) );
            if ( $alt !== '' ) {
                update_post_meta( $id, '_wp_attachment_image_alt', mb_substr( $alt, 0, 300 ) );
            }
            $title = sanitize_text_field( (string) ( $img['title'] ?? '' ) );
            if ( $title !== '' && get_the_title( $id ) !== $title ) {
                wp_update_post( [ 'ID' => $id, 'post_title' => mb_substr( $title, 0, 200 ) ] );
            }
        }
        return self::$mig_cache[ $key ] = $id;
    }

    private static function mig_text( $value, $max = 200 ) {
        return mb_substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, $max );
    }

    private static function mig_num( $value ) {
        $value = is_scalar( $value ) ? trim( (string) $value ) : '';
        return $value === '' ? '' : wc_format_decimal( $value );
    }

    /* ---------------- settings ---------------- */

    private static function mig_phase_settings() {
        $data = self::mig_fetch( 'settings' );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $maps = (array) ( self::$mig['maps'] ?? [] );

        // WooCommerce's options (the same list as the Mover's).
        $old_currency = get_woocommerce_currency();
        foreach ( (array) ( $data['options'] ?? [] ) as $name => $value ) {
            if ( ! in_array( $name, self::$mig_settings, true ) ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $value = array_values( array_filter( array_map( static fn( $v ) => strtoupper( preg_replace( '/[^A-Za-z0-9:_-]/', '', (string) $v ) ), $value ) ) );
            } else {
                $value = sanitize_text_field( (string) $value );
            }
            update_option( $name, $value );
        }
        if ( get_woocommerce_currency() !== $old_currency ) {
            self::mig_note( 'Your store\'s currency is now ' . get_woocommerce_currency() . ', like your old store.' );
        }

        // Shipping classes.
        $classes = [];
        foreach ( (array) ( $data['shipping_classes'] ?? [] ) as $c ) {
            $id = self::mig_term_item( 'product_shipping_class', (array) $c, false );
            if ( $id ) {
                $classes[ (int) $c['id'] ] = $id;
            }
        }
        $maps['shipping_class'] = $classes;

        // Tax classes and rates.
        $slugs = WC_Tax::get_tax_class_slugs();
        foreach ( (array) ( $data['tax_classes'] ?? [] ) as $c ) {
            $slug = sanitize_title( (string) ( $c['slug'] ?? '' ) );
            if ( $slug !== '' && ! in_array( $slug, $slugs, true ) ) {
                WC_Tax::create_tax_class( self::mig_text( $c['name'] ?? $slug, 100 ), $slug );
            }
        }
        $slugs = WC_Tax::get_tax_class_slugs();
        $rates = (array) ( $maps['tax_rate'] ?? [] );
        foreach ( (array) ( $data['tax_rates'] ?? [] ) as $r ) {
            $class = sanitize_title( (string) ( $r['class'] ?? '' ) );
            $row   = [
                'tax_rate_country'  => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $r['country'] ?? '' ) ), 0, 2 ) ),
                'tax_rate_state'    => strtoupper( self::mig_text( $r['state'] ?? '', 200 ) ),
                'tax_rate'          => wc_format_decimal( (string) ( $r['rate'] ?? '0' ), 4 ),
                'tax_rate_name'     => self::mig_text( $r['name'] ?? '', 200 ),
                'tax_rate_priority' => max( 1, (int) ( $r['priority'] ?? 1 ) ),
                'tax_rate_compound' => ! empty( $r['compound'] ) ? 1 : 0,
                'tax_rate_shipping' => ! empty( $r['shipping'] ) ? 1 : 0,
                'tax_rate_order'    => (int) ( $r['order'] ?? 0 ),
                'tax_rate_class'    => in_array( $class, $slugs, true ) ? $class : '',
            ];
            $old = (int) ( $r['id'] ?? 0 );
            $id  = (int) ( $rates[ $old ] ?? 0 );
            if ( $id && WC_Tax::_get_tax_rate( $id ) ) {
                WC_Tax::_update_tax_rate( $id, $row );
            } else {
                $id = self::mig_same_rate( $row, (array) ( $r['postcodes'] ?? [] ), (array) ( $r['cities'] ?? [] ) ) ?: WC_Tax::_insert_tax_rate( $row );
            }
            $rates[ $old ] = (int) $id;
            $clean = static fn( $list ) => implode( ';', array_slice( array_map( static fn( $v ) => self::mig_text( $v, 100 ), (array) $list ), 0, 1000 ) );
            WC_Tax::_update_tax_rate_postcodes( $id, $clean( $r['postcodes'] ?? [] ) );
            WC_Tax::_update_tax_rate_cities( $id, $clean( $r['cities'] ?? [] ) );
        }
        $maps['tax_rate'] = $rates;

        // Shipping zones and their methods.
        $zones = (array) ( $maps['zone'] ?? [] );
        foreach ( (array) ( $data['zones'] ?? [] ) as $z ) {
            $zones = self::mig_zone( (array) $z, $zones, $classes );
        }
        $maps['zone'] = $zones;

        self::$mig['maps'] = $maps;
        self::mig_count( 'settings', 'updated' );
        return true;
    }

    /** A rate already here with the same details (e.g. VAT set at sign-up): its ID, or 0. */
    private static function mig_same_rate( array $row, array $postcodes, array $cities ) {
        global $wpdb;
        if ( $postcodes || $cities ) {
            return 0;
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT r.tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates r WHERE r.tax_rate_country = %s AND r.tax_rate_state = %s AND r.tax_rate_class = %s AND r.tax_rate = %s
             AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}woocommerce_tax_rate_locations l WHERE l.tax_rate_id = r.tax_rate_id ) LIMIT 1",
            $row['tax_rate_country'], $row['tax_rate_state'], $row['tax_rate_class'], $row['tax_rate']
        ) );
    }

    private static function mig_zone( array $z, array $zones, array $classes ) {
        $old  = (int) ( $z['id'] ?? 0 );
        $name = self::mig_text( $z['name'] ?? '', 200 );
        if ( $old === 0 ) {
            $zone = new WC_Shipping_Zone( 0 ); // "everywhere else"
            if ( empty( $z['methods'] ) ) {
                return $zones;
            }
        } else {
            $id = (int) ( $zones[ $old ] ?? 0 );
            if ( ! $id ) {
                foreach ( WC_Shipping_Zones::get_zones() as $existing ) {
                    if ( mb_strtolower( $existing['zone_name'] ) === mb_strtolower( $name ) ) {
                        $id = (int) $existing['zone_id'];
                        break;
                    }
                }
            }
            $zone = new WC_Shipping_Zone( $id ?: null );
            if ( $id && ! $zone->get_id() ) {
                $zone = new WC_Shipping_Zone( null );
            }
            $zone->set_zone_name( $name ?: 'Zone ' . $old );
            $zone->set_zone_order( (int) ( $z['order'] ?? 0 ) );
            $zone->clear_locations();
            foreach ( array_slice( (array) ( $z['locations'] ?? [] ), 0, 500 ) as $l ) {
                $type = (string) ( $l['type'] ?? '' );
                if ( in_array( $type, [ 'country', 'state', 'continent', 'postcode' ], true ) ) {
                    $zone->add_location( self::mig_text( $l['code'] ?? '', 100 ), $type );
                }
            }
            $zone->save();
            $zones[ $old ] = (int) $zone->get_id();
        }
        // Its methods come again from the old zone.
        foreach ( $zone->get_shipping_methods() as $instance_id => $method ) {
            if ( in_array( $method->id, self::$mig_methods, true ) ) {
                $zone->delete_shipping_method( $instance_id );
            }
        }
        global $wpdb;
        foreach ( (array) ( $z['methods'] ?? [] ) as $m ) {
            $type = sanitize_key( (string) ( $m['method_id'] ?? '' ) );
            if ( ! in_array( $type, self::$mig_methods, true ) ) {
                self::mig_problem( sprintf( 'The shipping method “%s” in “%s” wasn\'t copied: set it up again under Settings → Shipping.', self::mig_text( $m['title'] ?? $type, 100 ), $name ?: 'Everywhere else' ) );
                continue;
            }
            $instance = (int) $zone->add_shipping_method( $type );
            if ( ! $instance ) {
                continue;
            }
            $settings = [];
            foreach ( (array) ( $m['settings'] ?? [] ) as $key => $value ) {
                $key = (string) $key;
                if ( ! preg_match( '/^[a-z0-9_]{1,60}$/', $key ) || ! is_scalar( $value ) ) {
                    continue;
                }
                // Costs per shipping class name the class by its ID.
                if ( preg_match( '/^class_cost_(\d+)$/', $key, $mm ) ) {
                    if ( empty( $classes[ (int) $mm[1] ] ) ) {
                        continue;
                    }
                    $key = 'class_cost_' . $classes[ (int) $mm[1] ];
                }
                $settings[ $key ] = sanitize_text_field( (string) $value );
            }
            update_option( "woocommerce_{$type}_{$instance}_settings", $settings );
            $wpdb->update( $wpdb->prefix . 'woocommerce_shipping_zone_methods', [ 'is_enabled' => ! empty( $m['enabled'] ) ? 1 : 0, 'method_order' => (int) ( $m['order'] ?? 0 ) ], [ 'instance_id' => $instance ] );
        }
        WC_Cache_Helper::get_transient_version( 'shipping', true );
        return $zones;
    }

    /* ---------------- products already here ---------------- */

    private static function mig_phase_remove() {
        $ids = array_values( (array) ( self::$mig['remove_ids'] ?? [] ) );
        $pos = (int) ( self::$mig['cursor'] ?? 0 );
        while ( $pos < count( $ids ) && self::mig_time_left() > 3 ) {
            $product = wc_get_product( (int) $ids[ $pos ] );
            if ( $product && ! $product->get_meta( Qwoo_Migration::META ) ) {
                $product->delete( false ); // to the trash
                self::mig_count( 'remove', 'updated' );
            } else {
                self::mig_count( 'remove', 'skipped' );
            }
            $pos++;
            self::$mig['cursor'] = $pos;
        }
        return $pos >= count( $ids );
    }

    /* ---------------- terms ---------------- */

    /**
     * A term from the old site: updated when it came in before, else one with
     * the same slug is used, else it's added. Returns its ID here, or 0.
     */
    private static function mig_term_item( $taxonomy, array $t, $with_extras = true ) {
        $old  = (int) ( $t['id'] ?? 0 );
        $name = trim( wp_specialchars_decode( self::mig_text( $t['name'] ?? '', 200 ), ENT_QUOTES ) );
        if ( ! $old || $name === '' ) {
            return 0;
        }
        $slug = sanitize_title( rawurldecode( (string) ( $t['slug'] ?? '' ) ) ) ?: sanitize_title( $name );
        $args = [ 'slug' => $slug, 'description' => wp_kses_post( (string) ( $t['description'] ?? '' ) ) ];
        $id   = self::mig_term( $taxonomy, $old );
        if ( ! $id && $taxonomy === 'category' && ! empty( $t['default'] ) ) {
            $id = (int) get_option( 'default_category' );
        }
        if ( ! $id ) {
            $same = get_term_by( 'slug', $slug, $taxonomy );
            $id   = $same ? (int) $same->term_id : 0;
        }
        if ( $id && get_term( $id, $taxonomy ) ) {
            $result = wp_update_term( $id, $taxonomy, [ 'name' => $name ] + $args );
            $state  = 'updated';
        } else {
            $result = wp_insert_term( $name, $taxonomy, $args );
            $state  = 'created';
            if ( is_wp_error( $result ) && $result->get_error_code() === 'term_exists' ) {
                $result = [ 'term_id' => (int) $result->get_error_data() ];
                $state  = 'updated';
            }
        }
        if ( is_wp_error( $result ) ) {
            throw new RuntimeException( $result->get_error_message() );
        }
        $id = (int) $result['term_id'];
        update_term_meta( $id, Qwoo_Migration::META, self::mig_ref( $old ) );
        self::$mig_cache[ 't:' . $taxonomy . ':' . self::mig_ref( $old ) ] = $id;
        if ( $with_extras ) {
            update_term_meta( $id, '_qwoo_mig_parent', (int) ( $t['parent'] ?? 0 ) );
            if ( isset( $t['order'] ) ) {
                update_term_meta( $id, 'order', (int) $t['order'] );
            }
            if ( $taxonomy === 'product_cat' && ! empty( $t['image'] ) ) {
                $image = self::mig_image( $t['image'] );
                if ( $image ) {
                    update_term_meta( $id, 'thumbnail_id', $image );
                }
            }
            $term = get_term( $id, $taxonomy );
            if ( $taxonomy === 'product_cat' ) {
                self::mig_remember_path( 'term', $id, $t['link'] ?? '', 'product-category/' . $term->slug );
                self::mig_seo( $id, 'term', $t['seo'] ?? [] );
            } elseif ( $taxonomy === 'category' ) {
                self::mig_remember_path( 'term', $id, $t['link'] ?? '', 'blog/category/' . $term->slug );
            }
        }
        self::$mig_last_state = $state;
        return $id;
    }

    private static $mig_last_state = 'updated';

    /** Categories and tags, page by page; at the end every category gets its parent. */
    private static function mig_terms_phase( $type, $taxonomy ) {
        $done = self::mig_pages( $type, $type, static function ( $t ) use ( $taxonomy ) {
            return self::mig_term_item( $taxonomy, $t ) ? self::$mig_last_state : 'skipped';
        } );
        if ( $done === true && is_taxonomy_hierarchical( $taxonomy ) ) {
            global $wpdb;
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT m.term_id, m.meta_value FROM {$wpdb->termmeta} m JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = m.term_id WHERE m.meta_key = '_qwoo_mig_parent' AND tt.taxonomy = %s",
                $taxonomy
            ) );
            foreach ( $rows as $row ) {
                $parent = (int) $row->meta_value ? self::mig_term( $taxonomy, (int) $row->meta_value ) : 0;
                $term   = get_term( (int) $row->term_id, $taxonomy );
                if ( $term && (int) $term->parent !== $parent && $parent !== (int) $row->term_id ) {
                    wp_update_term( (int) $row->term_id, $taxonomy, [ 'parent' => $parent ] );
                }
            }
        }
        return $done;
    }

    private static function mig_phase_product_cat() {
        return self::mig_terms_phase( 'product_cat', 'product_cat' );
    }

    private static function mig_phase_product_tag() {
        return self::mig_terms_phase( 'product_tag', 'product_tag' );
    }

    private static function mig_phase_post_categories() {
        return self::mig_terms_phase( 'post_categories', 'category' );
    }

    /** The shared product options (Size, Color…) and their choices. */
    private static function mig_phase_attributes() {
        $data = self::mig_fetch( 'attributes' );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        foreach ( (array) ( $data['items'] ?? [] ) as $a ) {
            $slug = wc_sanitize_taxonomy_name( (string) ( $a['slug'] ?? '' ) );
            $name = self::mig_text( $a['name'] ?? $slug, 100 );
            if ( $slug === '' || strlen( $slug ) > 28 ) {
                self::mig_problem( sprintf( 'The product option “%s” wasn\'t copied (its name is too long).', $name ) );
                self::mig_count( 'attributes', 'skipped' );
                continue;
            }
            $state = 'updated';
            if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
                $created = wc_create_attribute( [
                    'name'         => $name,
                    'slug'         => $slug,
                    'type'         => 'select',
                    'order_by'     => in_array( $a['order_by'] ?? '', [ 'menu_order', 'name', 'name_num', 'id' ], true ) ? $a['order_by'] : 'menu_order',
                    'has_archives' => false,
                ] );
                if ( is_wp_error( $created ) ) {
                    self::mig_problem( sprintf( 'The product option “%s” wasn\'t copied: %s', $name, $created->get_error_message() ) );
                    self::mig_count( 'attributes', 'skipped' );
                    continue;
                }
                $state = 'created';
            }
            self::mig_attribute_taxonomy( $slug );
            foreach ( (array) ( $a['terms'] ?? [] ) as $t ) {
                $id = self::mig_attribute_term( 'pa_' . $slug, (string) ( $t['slug'] ?? '' ), (string) ( $t['name'] ?? '' ) );
                if ( $id && isset( $t['order'] ) ) {
                    update_term_meta( $id, 'order', (int) $t['order'] );
                }
            }
            self::mig_count( 'attributes', $state );
        }
        return true;
    }

    /** WooCommerce registers attribute taxonomies on load: one made in this request needs it now. */
    private static function mig_attribute_taxonomy( $slug ) {
        $taxonomy = 'pa_' . $slug;
        if ( ! taxonomy_exists( $taxonomy ) ) {
            register_taxonomy( $taxonomy, [ 'product' ], [ 'hierarchical' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false, 'public' => false ] );
        }
        return $taxonomy;
    }

    /** A choice of a shared option by slug (added when missing). */
    private static function mig_attribute_term( $taxonomy, $slug, $name ) {
        $slug = sanitize_title( rawurldecode( $slug ) );
        $name = trim( wp_specialchars_decode( sanitize_text_field( $name ), ENT_QUOTES ) ) ?: $slug;
        if ( $slug === '' && $name === '' ) {
            return 0;
        }
        $term = get_term_by( 'slug', $slug ?: sanitize_title( $name ), $taxonomy );
        if ( $term ) {
            return (int) $term->term_id;
        }
        $new = wp_insert_term( $name, $taxonomy, $slug !== '' ? [ 'slug' => $slug ] : [] );
        if ( is_wp_error( $new ) ) {
            return $new->get_error_code() === 'term_exists' ? (int) $new->get_error_data() : 0;
        }
        return (int) $new['term_id'];
    }

    /* ---------------- products ---------------- */

    private static function mig_phase_products() {
        return self::mig_pages( 'products', 'products', [ __CLASS__, 'mig_product' ], 10 );
    }

    private static function mig_product( array $p ) {
        $type = (string) ( $p['type'] ?? '' );
        $name = trim( wp_specialchars_decode( self::mig_text( $p['name'] ?? '', 200 ), ENT_QUOTES ) );
        if ( ! in_array( $type, self::EDITABLE_TYPES, true ) ) {
            if ( $type === 'grouped' ) {
                self::mig_problem( sprintf( '“%s” is a grouped product, which YallaFlow doesn\'t support yet: it wasn\'t copied.', $name ) );
            } elseif ( $type === 'external' ) {
                self::mig_problem( sprintf( '“%s” is an external (affiliate) product, which YallaFlow doesn\'t support yet: it wasn\'t copied.', $name ) );
            } else {
                self::mig_problem( sprintf( '“%s” is a “%s” product (from another plugin), which YallaFlow doesn\'t support yet: it wasn\'t copied.', $name, $type ) );
            }
            return 'skipped';
        }
        if ( $name === '' ) {
            return 'skipped';
        }
        $id    = self::mig_post( [ 'product' ], $p['id'] );
        $class = $type === 'variable' ? 'WC_Product_Variable' : 'WC_Product_Simple';
        if ( $id ) {
            $existing = wc_get_product( $id );
            $product  = $existing && $existing->get_type() === $type ? $existing : new $class( $id );
        } else {
            $product = new $class();
        }
        $state  = $id ? 'updated' : 'created';
        $status = (string) ( $p['status'] ?? 'publish' );

        $product->set_name( $name );
        $product->set_slug( sanitize_title( rawurldecode( (string) ( $p['slug'] ?? '' ) ) ) ?: sanitize_title( $name ) );
        $product->set_status( $status === 'publish' ? 'publish' : 'draft' );
        $product->set_featured( ! empty( $p['featured'] ) );
        if ( in_array( $p['catalog_visibility'] ?? '', [ 'visible', 'catalog', 'search', 'hidden' ], true ) ) {
            $product->set_catalog_visibility( $p['catalog_visibility'] );
        }
        $product->set_description( self::mig_html( $p['description'] ?? '' ) );
        $product->set_short_description( self::mig_html( $p['short_description'] ?? '' ) );
        $product->set_sold_individually( ! empty( $p['sold_individually'] ) );
        $product->set_reviews_allowed( ! array_key_exists( 'reviews_allowed', $p ) || ! empty( $p['reviews_allowed'] ) );
        $product->set_purchase_note( wp_kses_post( (string) ( $p['purchase_note'] ?? '' ) ) );
        $product->set_menu_order( (int) ( $p['menu_order'] ?? 0 ) );
        $product->set_total_sales( max( 0, (int) ( $p['total_sales'] ?? 0 ) ) );
        if ( ! empty( $p['date_created'] ) ) {
            $product->set_date_created( (int) $p['date_created'] );
        }
        self::mig_stock_fields( $product, $p, $name );

        $cats = array_values( array_filter( array_map( static fn( $old ) => self::mig_term( 'product_cat', (int) $old ), (array) ( $p['categories'] ?? [] ) ) ) );
        $product->set_category_ids( $cats );
        $product->set_tag_ids( array_values( array_filter( array_map( static fn( $old ) => self::mig_term( 'product_tag', (int) $old ), (array) ( $p['tags'] ?? [] ) ) ) ) );

        // Photos.
        $product->set_image_id( ! empty( $p['image'] ) ? self::mig_image( $p['image'] ) : 0 );
        $gallery = [];
        foreach ( array_slice( (array) ( $p['gallery'] ?? [] ), 0, self::MAX_GALLERY ) as $img ) {
            $gid = self::mig_image( $img );
            if ( $gid && $gid !== $product->get_image_id() ) {
                $gallery[] = $gid;
            }
        }
        $product->set_gallery_image_ids( $gallery );

        // Options (Size, Color…).
        $attributes = [];
        foreach ( array_slice( (array) ( $p['attributes'] ?? [] ), 0, self::MAX_ATTRIBUTES * 2 ) as $i => $a ) {
            $attr = self::mig_product_attribute( (array) $a, $i );
            if ( $attr ) {
                $attributes[] = $attr;
            }
        }
        $product->set_attributes( $attributes );
        $defaults = [];
        foreach ( (array) ( $p['default_attributes'] ?? [] ) as $key => $value ) {
            $defaults[ sanitize_title( (string) $key ) ] = self::mig_text( $value, 200 );
        }
        $product->set_default_attributes( $defaults );

        $id = $product->save();
        update_post_meta( $id, Qwoo_Migration::META, self::mig_ref( $p['id'] ) );
        self::$mig_cache[ 'p:product:' . self::mig_ref( $p['id'] ) ] = $id;
        // Related products link up once all products are here.
        $links = [ 'upsells' => array_map( 'intval', (array) ( $p['upsells'] ?? [] ) ), 'cross_sells' => array_map( 'intval', (array) ( $p['cross_sells'] ?? [] ) ) ];
        if ( $links['upsells'] || $links['cross_sells'] ) {
            update_post_meta( $id, '_qwoo_mig_links', $links );
        }
        if ( ! empty( $p['downloadable'] ) ) {
            self::mig_problem( sprintf( '“%s” is a download: its files weren\'t copied.', $name ) );
        }
        $post = get_post( $id );
        self::mig_remember_path( 'post', $id, $p['link'] ?? '', 'product/' . $post->post_name );
        self::mig_seo( $id, 'post', $p['seo'] ?? [] );

        if ( $type === 'variable' ) {
            self::mig_variations( $product, (array) ( $p['variations'] ?? [] ), $name );
        }
        wc_delete_product_transients( $id );
        return $state;
    }

    /** Price, stock, size and shipping fields, shared by products and their combinations. */
    private static function mig_stock_fields( WC_Product $product, array $p, $name ) {
        $sku = self::mig_text( $p['sku'] ?? '', 100 );
        if ( $sku !== '' && ! wc_product_has_unique_sku( $product->get_id(), $sku ) ) {
            self::mig_problem( sprintf( 'The SKU “%s” of “%s” is used by another product here, so it was left empty.', $sku, $name ) );
            $sku = '';
        }
        $product->set_sku( $sku );
        $product->set_regular_price( self::mig_num( $p['regular_price'] ?? '' ) );
        $product->set_sale_price( self::mig_num( $p['sale_price'] ?? '' ) );
        $product->set_date_on_sale_from( ! empty( $p['date_on_sale_from'] ) ? (int) $p['date_on_sale_from'] : null );
        $product->set_date_on_sale_to( ! empty( $p['date_on_sale_to'] ) ? (int) $p['date_on_sale_to'] : null );
        if ( in_array( $p['tax_status'] ?? '', [ 'taxable', 'shipping', 'none' ], true ) ) {
            $product->set_tax_status( $p['tax_status'] );
        }
        $class = sanitize_title( (string) ( $p['tax_class'] ?? '' ) );
        $product->set_tax_class( in_array( $class, WC_Tax::get_tax_class_slugs(), true ) || ( $class === 'parent' && $product->is_type( 'variation' ) ) ? $class : '' );
        $manage = ! empty( $p['manage_stock'] ) && $p['manage_stock'] !== 'parent';
        $product->set_manage_stock( $manage );
        if ( $manage ) {
            $product->set_stock_quantity( (float) ( $p['stock_quantity'] ?? 0 ) );
        }
        if ( in_array( $p['stock_status'] ?? '', [ 'instock', 'outofstock', 'onbackorder' ], true ) ) {
            $product->set_stock_status( $p['stock_status'] );
        }
        if ( in_array( $p['backorders'] ?? '', [ 'no', 'notify', 'yes' ], true ) ) {
            $product->set_backorders( $p['backorders'] );
        }
        $product->set_low_stock_amount( isset( $p['low_stock_amount'] ) && $p['low_stock_amount'] !== '' && $p['low_stock_amount'] !== null ? (int) $p['low_stock_amount'] : '' );
        foreach ( [ 'weight', 'length', 'width', 'height' ] as $f ) {
            $product->{"set_$f"}( self::mig_num( $p[ $f ] ?? '' ) );
        }
        $product->set_virtual( ! empty( $p['virtual'] ) );
        $class_slug = sanitize_title( (string) ( $p['shipping_class'] ?? '' ) );
        $term       = $class_slug !== '' ? get_term_by( 'slug', $class_slug, 'product_shipping_class' ) : null;
        $product->set_shipping_class_id( $term ? (int) $term->term_id : 0 );
    }

    private static function mig_product_attribute( array $a, $position ) {
        $name    = self::mig_text( $a['name'] ?? '', 100 );
        $options = array_slice( (array) ( $a['options'] ?? [] ), 0, self::MAX_OPTIONS * 2 );
        $attr    = new WC_Product_Attribute();
        $slug    = wc_sanitize_taxonomy_name( (string) ( $a['taxonomy'] ?? '' ) );
        if ( $slug !== '' && wc_attribute_taxonomy_id_by_name( $slug ) ) {
            $taxonomy = self::mig_attribute_taxonomy( $slug );
            $ids      = [];
            foreach ( $options as $o ) {
                $tid = self::mig_attribute_term( $taxonomy, (string) ( $o['slug'] ?? '' ), (string) ( $o['name'] ?? '' ) );
                if ( $tid ) {
                    $ids[] = $tid;
                }
            }
            $attr->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
            $attr->set_name( $taxonomy );
            $attr->set_options( $ids );
        } else {
            if ( $name === '' ) {
                return null;
            }
            $attr->set_id( 0 );
            $attr->set_name( $name );
            $attr->set_options( array_values( array_filter( array_map( static fn( $o ) => self::mig_text( $o['name'] ?? '', 200 ), $options ), 'strlen' ) ) );
        }
        $attr->set_visible( ! empty( $a['visible'] ) );
        $attr->set_variation( ! empty( $a['variation'] ) );
        $attr->set_position( (int) ( $a['position'] ?? $position ) );
        return $attr->get_options() ? $attr : null;
    }

    private static function mig_variations( WC_Product $product, array $variations, $name ) {
        $keep = [];
        foreach ( array_slice( $variations, 0, self::MAX_VARIATIONS * 2 ) as $v ) {
            if ( ! is_array( $v ) || empty( $v['id'] ) ) {
                continue;
            }
            $vid       = self::mig_post( [ 'product_variation' ], $v['id'] );
            $variation = $vid ? new WC_Product_Variation( $vid ) : new WC_Product_Variation();
            $variation->set_parent_id( $product->get_id() );
            $attributes = [];
            foreach ( (array) ( $v['attributes'] ?? [] ) as $key => $value ) {
                $key = sanitize_title( (string) $key );
                $attributes[ $key ] = strpos( $key, 'pa_' ) === 0 ? sanitize_title( rawurldecode( (string) $value ) ) : self::mig_text( $value, 200 );
            }
            $variation->set_attributes( $attributes );
            $variation->set_status( ( $v['status'] ?? 'publish' ) === 'private' ? 'private' : 'publish' );
            $variation->set_description( wp_kses_post( (string) ( $v['description'] ?? '' ) ) );
            $variation->set_menu_order( (int) ( $v['menu_order'] ?? 0 ) );
            self::mig_stock_fields( $variation, $v, $name );
            if ( ( $v['manage_stock'] ?? '' ) === 'parent' ) {
                $variation->set_manage_stock( false );
            }
            $variation->set_image_id( ! empty( $v['image'] ) ? self::mig_image( $v['image'] ) : 0 );
            $id = $variation->save();
            update_post_meta( $id, Qwoo_Migration::META, self::mig_ref( $v['id'] ) );
            $keep[] = $id;
        }
        // Combinations the old store no longer has go.
        foreach ( $product->get_children() as $child ) {
            if ( ! in_array( (int) $child, $keep, true ) && get_post_meta( $child, Qwoo_Migration::META, true ) ) {
                $old = wc_get_product( $child );
                if ( $old ) {
                    $old->delete( true );
                }
            }
        }
        WC_Product_Variable::sync( $product->get_id() );
    }

    /** Upsells and cross-sells, now that every product is here. */
    private static function mig_phase_links() {
        global $wpdb;
        while ( self::mig_time_left() > 3 ) {
            $ids = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_qwoo_mig_links' ORDER BY post_id LIMIT 50" ) );
            if ( ! $ids ) {
                return true;
            }
            foreach ( $ids as $id ) {
                $links   = (array) get_post_meta( $id, '_qwoo_mig_links', true );
                $product = wc_get_product( $id );
                delete_post_meta( $id, '_qwoo_mig_links' );
                if ( ! $product ) {
                    continue;
                }
                $map = static fn( $list ) => array_values( array_filter( array_map( static fn( $old ) => self::mig_post( [ 'product' ], (int) $old ), (array) $list ) ) );
                $product->set_upsell_ids( $map( $links['upsells'] ?? [] ) );
                $product->set_cross_sell_ids( $map( $links['cross_sells'] ?? [] ) );
                $product->save();
                self::mig_count( 'links', 'updated' );
            }
        }
        return false;
    }

    /* ---------------- customers ---------------- */

    private static function mig_phase_customers() {
        return self::mig_pages( 'customers', 'customers', [ __CLASS__, 'mig_customer' ] );
    }

    /** Password hashes WordPress here can check (phpass, bcrypt, WordPress 6.8's, argon2, old md5). */
    private static function mig_hash_ok( $hash ) {
        return (bool) preg_match( '/^(\$P\$|\$H\$|\$wp\$2y\$|\$2[aby]\$|\$argon2(i|id)\$)/', $hash ) || (bool) preg_match( '/^[a-f0-9]{32}$/', $hash );
    }

    private static function mig_customer( array $c ) {
        global $wpdb;
        $email = sanitize_email( (string) ( $c['email'] ?? '' ) );
        if ( ! is_email( $email ) ) {
            self::mig_problem( sprintf( 'A customer without a valid email (%s) wasn\'t copied.', self::mig_text( $c['login'] ?? '', 60 ) ) );
            return 'skipped';
        }
        $id   = self::mig_user( $c['id'] );
        $user = $id ? get_userdata( $id ) : get_user_by( 'email', $email );
        if ( $user && array_intersect( (array) $user->roles, self::$mig_staff ) ) {
            self::mig_problem( sprintf( 'The customer %s has the same email as a staff account here, so it wasn\'t copied.', $email ) );
            return 'skipped';
        }
        $hash  = (string) ( $c['pass'] ?? '' );
        $first = self::mig_text( $c['first_name'] ?? '', 100 );
        $last  = self::mig_text( $c['last_name'] ?? '', 100 );
        if ( $user ) {
            // Someone who already signed in here keeps what they have.
            if ( get_user_meta( $user->ID, Qwoo_Migration::TOUCHED, true ) ) {
                update_user_meta( $user->ID, Qwoo_Migration::META, self::mig_ref( $c['id'] ) );
                return 'skipped';
            }
            $id    = (int) $user->ID;
            $state = 'updated';
            wp_update_user( [ 'ID' => $id, 'first_name' => $first, 'last_name' => $last, 'display_name' => self::mig_text( $c['display_name'] ?? '', 200 ) ?: trim( "$first $last" ) ] );
            // The hash only follows when it's still the one brought in before.
            $set_hash = ! get_user_meta( $id, Qwoo_Migration::META, true ) ? false : hash_equals( (string) get_user_meta( $id, Qwoo_Migration::PASS, true ), md5( (string) $user->user_pass ) );
        } else {
            $login = sanitize_user( (string) ( $c['login'] ?? '' ), true ) ?: sanitize_user( strstr( $email, '@', true ), true );
            $login = mb_substr( $login ?: 'customer', 0, 50 );
            $base  = $login;
            for ( $n = 2; username_exists( $login ); $n++ ) {
                $login = $base . $n;
            }
            $registered = (string) ( $c['registered'] ?? '' );
            $id         = wp_insert_user( [
                'user_login'      => $login,
                'user_email'      => $email,
                'user_pass'       => wp_generate_password( 32, true, true ),
                'role'            => 'customer',
                'first_name'      => $first,
                'last_name'       => $last,
                'display_name'    => self::mig_text( $c['display_name'] ?? '', 200 ) ?: trim( "$first $last" ) ?: $login,
                'user_registered' => preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $registered ) ? $registered : current_time( 'mysql', true ),
            ] );
            if ( is_wp_error( $id ) ) {
                throw new RuntimeException( $id->get_error_message() );
            }
            $state    = 'created';
            $set_hash = true;
        }
        if ( $set_hash ) {
            if ( $hash !== '' && strlen( $hash ) <= 255 && self::mig_hash_ok( $hash ) ) {
                $wpdb->update( $wpdb->users, [ 'user_pass' => $hash ], [ 'ID' => $id ] );
                clean_user_cache( $id );
                update_user_meta( $id, Qwoo_Migration::PASS, md5( $hash ) );
                delete_user_meta( $id, Qwoo_Migration::RESET );
            } else {
                // Their old password can't be checked here: they set a new one when they first sign in.
                update_user_meta( $id, Qwoo_Migration::RESET, 1 );
                self::mig_note( 'Some customers\' passwords couldn\'t come over. They\'re asked to set a new one the first time they sign in.' );
            }
        }
        foreach ( [ 'billing', 'shipping' ] as $kind ) {
            $address = (array) ( $c['address'][ $kind ] ?? [] );
            foreach ( self::$mig_address_fields as $f ) {
                if ( array_key_exists( $f, $address ) ) {
                    $value = $f === 'email' ? sanitize_email( (string) $address[ $f ] ) : self::mig_text( $address[ $f ], 200 );
                    update_user_meta( $id, $kind . '_' . $f, in_array( $f, [ 'country', 'state' ], true ) ? strtoupper( $value ) : $value );
                }
            }
        }
        if ( ! empty( $c['paying'] ) ) {
            update_user_meta( $id, 'paying_customer', 1 );
        }
        update_user_meta( $id, Qwoo_Migration::META, self::mig_ref( $c['id'] ) );
        self::$mig_cache[ 'u:' . self::mig_ref( $c['id'] ) ] = (int) $id;
        return $state;
    }

    /* ---------------- discount codes ---------------- */

    private static function mig_phase_coupons() {
        return self::mig_pages( 'coupons', 'coupons', [ __CLASS__, 'mig_coupon' ] );
    }

    private static function mig_coupon( array $c ) {
        $code = wc_format_coupon_code( self::mig_text( $c['code'] ?? '', 100 ) );
        if ( $code === '' ) {
            return 'skipped';
        }
        $id = self::mig_post( [ 'shop_coupon' ], $c['id'] ) ?: (int) wc_get_coupon_id_by_code( $code );
        $coupon = new WC_Coupon( $id ?: 0 );
        $state  = $id ? 'updated' : 'created';
        $types  = array_keys( wc_get_coupon_types() );
        $posts  = static fn( $list ) => array_values( array_filter( array_map( static fn( $old ) => self::mig_post( [ 'product', 'product_variation' ], (int) $old ), (array) $list ) ) );
        $terms  = static fn( $list ) => array_values( array_filter( array_map( static fn( $old ) => self::mig_term( 'product_cat', (int) $old ), (array) $list ) ) );
        $coupon->set_code( $code );
        $coupon->set_description( sanitize_textarea_field( (string) ( $c['description'] ?? '' ) ) );
        $coupon->set_discount_type( in_array( $c['discount_type'] ?? '', $types, true ) ? $c['discount_type'] : 'fixed_cart' );
        $coupon->set_amount( self::mig_num( $c['amount'] ?? 0 ) ?: 0 );
        $coupon->set_date_expires( ! empty( $c['date_expires'] ) ? (int) $c['date_expires'] : null );
        if ( ! empty( $c['date_created'] ) ) {
            $coupon->set_date_created( (int) $c['date_created'] );
        }
        $coupon->set_usage_count( max( 0, (int) ( $c['usage_count'] ?? 0 ) ) );
        $coupon->set_individual_use( ! empty( $c['individual_use'] ) );
        $coupon->set_product_ids( $posts( $c['product_ids'] ?? [] ) );
        $coupon->set_excluded_product_ids( $posts( $c['excluded_product_ids'] ?? [] ) );
        $coupon->set_usage_limit( max( 0, (int) ( $c['usage_limit'] ?? 0 ) ) );
        $coupon->set_usage_limit_per_user( max( 0, (int) ( $c['usage_limit_per_user'] ?? 0 ) ) );
        $coupon->set_limit_usage_to_x_items( ! empty( $c['limit_usage_to_x_items'] ) ? (int) $c['limit_usage_to_x_items'] : null );
        $coupon->set_free_shipping( ! empty( $c['free_shipping'] ) );
        $coupon->set_product_categories( $terms( $c['product_categories'] ?? [] ) );
        $coupon->set_excluded_product_categories( $terms( $c['excluded_product_categories'] ?? [] ) );
        $coupon->set_exclude_sale_items( ! empty( $c['exclude_sale_items'] ) );
        $coupon->set_minimum_amount( self::mig_num( $c['minimum_amount'] ?? '' ) );
        $coupon->set_maximum_amount( self::mig_num( $c['maximum_amount'] ?? '' ) );
        $coupon->set_email_restrictions( array_values( array_filter( array_map( static fn( $e ) => sanitize_text_field( (string) $e ), (array) ( $c['email_restrictions'] ?? [] ) ) ) ) );
        // Who used it: customers by their new ID, guests by email.
        $used = [];
        foreach ( array_slice( (array) ( $c['used_by'] ?? [] ), 0, 5000 ) as $who ) {
            $who = (string) $who;
            if ( ctype_digit( $who ) ) {
                $new = self::mig_user( (int) $who );
                if ( $new ) {
                    $used[] = (string) $new;
                }
            } elseif ( is_email( $who ) ) {
                $used[] = sanitize_email( $who );
            }
        }
        $coupon->set_status( ( $c['status'] ?? '' ) === 'publish' ? 'publish' : 'draft' );
        $id = $coupon->save();
        update_post_meta( $id, Qwoo_Migration::META, self::mig_ref( $c['id'] ) );
        // WooCommerce keeps "used by" as one meta row per use (its setter isn't saved).
        delete_post_meta( $id, '_used_by' );
        foreach ( $used as $who ) {
            add_post_meta( $id, '_used_by', $who );
        }
        return $state;
    }

    /* ---------------- orders ---------------- */

    private static function mig_phase_orders() {
        remove_action( 'woocommerce_order_status_completed', [ 'Qwoo_Reviews', 'schedule_request' ] );
        return self::mig_pages( 'orders', 'orders', [ __CLASS__, 'mig_order_item' ], 20 );
    }

    /** Tax amounts per rate, with the rates' new IDs. */
    private static function mig_taxes( $taxes ) {
        $map = (array) ( self::$mig['maps']['tax_rate'] ?? [] );
        $out = [ 'total' => [], 'subtotal' => [] ];
        foreach ( [ 'total', 'subtotal' ] as $k ) {
            foreach ( (array) ( $taxes[ $k ] ?? [] ) as $rate => $amount ) {
                $out[ $k ][ (int) ( $map[ (int) $rate ] ?? $rate ) ] = wc_format_decimal( (string) $amount );
            }
        }
        return $out;
    }

    private static function mig_order_item( array $o ) {
        $id       = self::mig_order( $o['id'] );
        $existing = $id ? wc_get_order( $id ) : null;
        $order    = $existing instanceof WC_Order ? $existing : new WC_Order();
        $state    = $existing ? 'updated' : 'created';

        $statuses = array_map( static fn( $s ) => substr( $s, 3 ), array_keys( wc_get_order_statuses() ) );
        $status   = sanitize_key( (string) ( $o['status'] ?? '' ) );
        if ( $status === 'checkout-draft' ) {
            return 'skipped';
        }
        if ( ! in_array( $status, $statuses, true ) ) {
            $to = ! empty( $o['date_completed'] ) ? 'completed' : 'processing';
            self::mig_note( sprintf( 'Orders with the status “%s” came in as %s.', $status, wc_get_order_status_name( $to ) ) );
            $status = $to;
        }
        if ( $existing ) {
            // One by one (WooCommerce's "remove all" query is MySQL-only).
            foreach ( $order->get_items( [ 'line_item', 'shipping', 'fee', 'coupon', 'tax' ] ) as $item_id => $item ) {
                $order->remove_item( $item_id );
            }
        }
        $order->set_currency( strtoupper( self::mig_text( $o['currency'] ?? '', 3 ) ) ?: get_woocommerce_currency() );
        $order->set_prices_include_tax( ! empty( $o['prices_include_tax'] ) );
        $order->set_customer_id( self::mig_user( (int) ( $o['customer_id'] ?? 0 ) ) );
        $order->set_customer_note( sanitize_textarea_field( (string) ( $o['customer_note'] ?? '' ) ) );
        foreach ( [ 'billing', 'shipping' ] as $kind ) {
            foreach ( self::$mig_address_fields as $f ) {
                $setter = "set_{$kind}_{$f}";
                if ( isset( $o[ $kind ][ $f ] ) && is_callable( [ $order, $setter ] ) ) {
                    $value = $f === 'email' ? sanitize_email( (string) $o[ $kind ][ $f ] ) : self::mig_text( $o[ $kind ][ $f ], 200 );
                    $order->$setter( $value );
                }
            }
        }
        $order->set_payment_method( sanitize_key( (string) ( $o['payment_method'] ?? '' ) ) );
        $order->set_payment_method_title( self::mig_text( $o['payment_method_title'] ?? '', 200 ) );
        $order->set_transaction_id( self::mig_text( $o['transaction_id'] ?? '', 200 ) );
        $order->set_created_via( self::mig_text( $o['created_via'] ?? '', 50 ) ?: 'checkout' );
        foreach ( [ 'date_created', 'date_paid', 'date_completed' ] as $d ) {
            $order->{"set_$d"}( ! empty( $o[ $d ] ) ? (int) $o[ $d ] : null );
        }

        foreach ( (array) ( $o['lines'] ?? [] ) as $l ) {
            $item    = new WC_Order_Item_Product();
            $product = self::mig_post( [ 'product' ], (int) ( $l['product_id'] ?? 0 ) );
            $item->set_props( [
                'name'         => self::mig_text( $l['name'] ?? '', 200 ),
                'product_id'   => $product,
                'variation_id' => self::mig_post( [ 'product_variation' ], (int) ( $l['variation_id'] ?? 0 ) ),
                'quantity'     => (float) ( $l['quantity'] ?? 1 ),
                'subtotal'     => wc_format_decimal( (string) ( $l['subtotal'] ?? 0 ) ),
                'subtotal_tax' => wc_format_decimal( (string) ( $l['subtotal_tax'] ?? 0 ) ),
                'total'        => wc_format_decimal( (string) ( $l['total'] ?? 0 ) ),
                'total_tax'    => wc_format_decimal( (string) ( $l['total_tax'] ?? 0 ) ),
                'taxes'        => self::mig_taxes( $l['taxes'] ?? [] ),
                'tax_class'    => sanitize_title( (string) ( $l['tax_class'] ?? '' ) ),
            ] );
            foreach ( array_slice( (array) ( $l['meta'] ?? [] ), 0, 30 ) as $m ) {
                $item->add_meta_data( self::mig_text( $m['key'] ?? '', 100 ), self::mig_text( $m['value'] ?? '', 500 ) );
            }
            $order->add_item( $item );
        }
        foreach ( (array) ( $o['shipping_lines'] ?? [] ) as $l ) {
            $item = new WC_Order_Item_Shipping();
            $item->set_props( [
                'method_title' => self::mig_text( $l['method_title'] ?? '', 200 ),
                'method_id'    => sanitize_key( (string) ( $l['method_id'] ?? '' ) ),
                'total'        => wc_format_decimal( (string) ( $l['total'] ?? 0 ) ),
                'total_tax'    => wc_format_decimal( (string) ( $l['total_tax'] ?? 0 ) ),
                'taxes'        => self::mig_taxes( $l['taxes'] ?? [] ),
            ] );
            $order->add_item( $item );
        }
        foreach ( (array) ( $o['fees'] ?? [] ) as $l ) {
            $item = new WC_Order_Item_Fee();
            $item->set_props( [
                'name'       => self::mig_text( $l['name'] ?? '', 200 ),
                'tax_status' => ( $l['tax_status'] ?? '' ) === 'none' ? 'none' : 'taxable',
                'tax_class'  => sanitize_title( (string) ( $l['tax_class'] ?? '' ) ),
                'total'      => wc_format_decimal( (string) ( $l['total'] ?? 0 ) ),
                'total_tax'  => wc_format_decimal( (string) ( $l['total_tax'] ?? 0 ) ),
                'taxes'      => self::mig_taxes( $l['taxes'] ?? [] ),
            ] );
            $order->add_item( $item );
        }
        foreach ( (array) ( $o['coupons'] ?? [] ) as $l ) {
            $item = new WC_Order_Item_Coupon();
            $item->set_props( [
                'code'         => wc_format_coupon_code( self::mig_text( $l['code'] ?? '', 100 ) ),
                'discount'     => wc_format_decimal( (string) ( $l['discount'] ?? 0 ) ),
                'discount_tax' => wc_format_decimal( (string) ( $l['discount_tax'] ?? 0 ) ),
            ] );
            $order->add_item( $item );
        }
        $map = (array) ( self::$mig['maps']['tax_rate'] ?? [] );
        foreach ( (array) ( $o['tax_lines'] ?? [] ) as $l ) {
            $item = new WC_Order_Item_Tax();
            $item->set_props( [
                'rate_id'            => (int) ( $map[ (int) ( $l['rate_id'] ?? 0 ) ] ?? ( $l['rate_id'] ?? 0 ) ),
                'label'              => self::mig_text( $l['label'] ?? '', 200 ),
                'compound'           => ! empty( $l['compound'] ),
                'tax_total'          => wc_format_decimal( (string) ( $l['tax_total'] ?? 0 ) ),
                'shipping_tax_total' => wc_format_decimal( (string) ( $l['shipping_tax_total'] ?? 0 ) ),
                'rate_code'          => self::mig_text( $l['rate_code'] ?? '', 200 ),
                'rate_percent'       => (float) ( $l['rate_percent'] ?? 0 ),
            ] );
            $order->add_item( $item );
        }
        $order->set_discount_total( wc_format_decimal( (string) ( $o['discount_total'] ?? 0 ) ) );
        $order->set_discount_tax( wc_format_decimal( (string) ( $o['discount_tax'] ?? 0 ) ) );
        $order->set_shipping_total( wc_format_decimal( (string) ( $o['shipping_total'] ?? 0 ) ) );
        $order->set_shipping_tax( wc_format_decimal( (string) ( $o['shipping_tax'] ?? 0 ) ) );
        $order->set_cart_tax( wc_format_decimal( (string) ( $o['cart_tax'] ?? 0 ) ) );
        $order->set_total( wc_format_decimal( (string) ( $o['total'] ?? 0 ) ) );

        // Already happened on the old store: no stock changes, sales counts or emails now.
        foreach ( [ 'set_order_stock_reduced', 'set_recorded_sales', 'set_recorded_coupon_usage_counts', 'set_new_order_email_sent' ] as $flag ) {
            if ( is_callable( [ $order, $flag ] ) ) {
                $order->$flag( true );
            }
        }
        $order->update_meta_data( Qwoo_Migration::META, self::mig_ref( $o['id'] ) );
        $order->update_meta_data( Qwoo_Reviews::SENT_META, time() );
        $order->set_status( $status );
        $id = $order->save();

        $number = self::mig_text( $o['number'] ?? '', 40 );
        if ( $number !== '' && $number !== (string) $id ) {
            $order->update_meta_data( Qwoo_Migration::ORDER_NUMBER, $number );
        } else {
            $order->delete_meta_data( Qwoo_Migration::ORDER_NUMBER );
        }
        if ( ctype_digit( $number ) ) {
            self::$mig['max_number'] = max( (int) ( self::$mig['max_number'] ?? 0 ), (int) $number );
        }

        // Notes added on the old store since the last move.
        $until = (int) $order->get_meta( '_qwoo_mig_notes_until' );
        $last  = $until;
        foreach ( (array) ( $o['notes'] ?? [] ) as $n ) {
            $when = (int) ( $n['date'] ?? 0 );
            if ( $when <= $until ) {
                continue;
            }
            $comment = wp_insert_comment( [
                'comment_post_ID'      => $id,
                'comment_author'       => self::mig_text( $n['by'] ?? '', 100 ) ?: 'WooCommerce',
                'comment_author_email' => '',
                'comment_content'      => wp_kses_post( (string) ( $n['content'] ?? '' ) ),
                'comment_agent'        => 'WooCommerce',
                'comment_type'         => 'order_note',
                'comment_approved'     => 1,
                'comment_date'         => $when ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $when ) ) : current_time( 'mysql' ),
                'comment_date_gmt'     => $when ? gmdate( 'Y-m-d H:i:s', $when ) : current_time( 'mysql', true ),
            ] );
            if ( $comment && ! empty( $n['customer'] ) ) {
                add_comment_meta( $comment, 'is_customer_note', 1 );
            }
            $last = max( $last, $when );
        }
        $order->update_meta_data( '_qwoo_mig_notes_until', $last );
        $order->save_meta_data();

        // Refunds (recorded only: the money was already returned on the old store).
        foreach ( (array) ( $o['refunds'] ?? [] ) as $r ) {
            if ( empty( $r['id'] ) || self::mig_order( (int) $r['id'], 'shop_order_refund' ) ) {
                continue;
            }
            $refund = new WC_Order_Refund();
            $amount = wc_format_decimal( (string) ( $r['amount'] ?? 0 ) );
            $refund->set_parent_id( $id );
            $refund->set_currency( $order->get_currency() );
            $refund->set_amount( $amount );
            $refund->set_total( $amount * -1 );
            $refund->set_reason( self::mig_text( $r['reason'] ?? '', 300 ) );
            $refund->set_refunded_payment( true );
            if ( ! empty( $r['date'] ) ) {
                $refund->set_date_created( (int) $r['date'] );
            }
            $refund->update_meta_data( Qwoo_Migration::META, self::mig_ref( $r['id'] ) );
            $refund->save();
        }
        return $state;
    }

    /* ---------------- reviews ---------------- */

    private static function mig_phase_reviews() {
        $done = self::mig_pages( 'reviews', 'reviews', [ __CLASS__, 'mig_review' ], 100 );
        if ( $done === true && ! empty( self::$mig['counts']['reviews']['created'] ) && ! Qwoo_Reviews::settings()['enabled'] ) {
            Qwoo_Reviews::save_settings( [ 'enabled' => true ] + Qwoo_Reviews::settings() );
            self::mig_note( 'Reviews are now on, so your old store\'s reviews show on your products.' );
        }
        return $done;
    }

    private static function mig_review( array $r ) {
        $product = self::mig_post( [ 'product' ], (int) ( $r['product_id'] ?? 0 ) );
        if ( ! $product ) {
            return 'skipped';
        }
        $text = trim( wp_kses( (string) ( $r['content'] ?? '' ), [] ) );
        if ( ! empty( $r['parent'] ) ) {
            // A reply: the shop's first reply shows under the review.
            $parent = self::mig_comment( (int) $r['parent'] );
            if ( ! $parent || empty( $r['staff'] ) || $text === '' ) {
                return 'skipped';
            }
            if ( ! Qwoo_Reviews::reply_of( $parent ) ) {
                update_comment_meta( $parent, Qwoo_Reviews::REPLY_META, [ 'text' => mb_substr( $text, 0, Qwoo_Reviews::MAX_TEXT ), 'date' => (int) strtotime( (string) $r['date_gmt'] . ' UTC' ) ] );
            }
            return 'updated';
        }
        $rating = max( 0, min( 5, (int) ( $r['rating'] ?? 0 ) ) );
        if ( ! $rating ) {
            return 'skipped'; // a comment, not a review
        }
        $gmt  = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ( $r['date_gmt'] ?? '' ) ) ? $r['date_gmt'] : current_time( 'mysql', true );
        $data = [
            'comment_post_ID'      => $product,
            'comment_author'       => self::mig_text( $r['author'] ?? '', 100 ),
            'comment_author_email' => sanitize_email( (string) ( $r['email'] ?? '' ) ),
            'comment_content'      => mb_substr( $text, 0, Qwoo_Reviews::MAX_TEXT ),
            'comment_type'         => 'review',
            'comment_approved'     => 1,
            'user_id'              => self::mig_user( (int) ( $r['user_id'] ?? 0 ) ),
            'comment_date_gmt'     => $gmt,
            'comment_date'         => get_date_from_gmt( $gmt ),
        ];
        $id = self::mig_comment( (int) $r['id'] );
        if ( $id ) {
            wp_update_comment( [ 'comment_ID' => $id ] + $data );
            $state = 'updated';
        } else {
            $id = wp_insert_comment( $data );
            if ( ! $id ) {
                return 'skipped';
            }
            add_comment_meta( $id, Qwoo_Migration::META, self::mig_ref( $r['id'] ), true );
            $state = 'created';
        }
        update_comment_meta( $id, 'rating', $rating );
        update_comment_meta( $id, 'verified', ! empty( $r['verified'] ) ? 1 : 0 );
        self::$mig_rated[ $product ] = true;
        return $state;
    }

    /* ---------------- blog ---------------- */

    private static function mig_phase_posts() {
        return self::mig_pages( 'posts', 'posts', [ __CLASS__, 'mig_post_item' ], 10 );
    }

    /** Images in a post's text come into this store's media (the text keeps only those). */
    private static function mig_content_images( $html ) {
        $html = (string) $html;
        // Downloaded first, then swapped in (no downloads from inside a regex callback).
        $ids = [];
        if ( preg_match_all( '#<img\b[^>]*\ssrc=["\']([^"\']+)["\']#i', $html, $all ) ) {
            foreach ( array_slice( array_unique( $all[1] ), 0, 40 ) as $src ) {
                $ids[ $src ] = self::mig_image( html_entity_decode( $src ) );
            }
        }
        return preg_replace_callback( '#<img\b[^>]*>#i', static function ( $m ) use ( $ids ) {
            if ( ! preg_match( '#\ssrc=["\']([^"\']+)["\']#i', $m[0], $src ) ) {
                return '';
            }
            $id = (int) ( $ids[ $src[1] ] ?? 0 );
            if ( ! $id ) {
                return '';
            }
            $alt = preg_match( '#\salt=["\']([^"\']*)["\']#i', $m[0], $a ) ? $a[1] : '';
            $img = wp_get_attachment_image_src( $id, 'large' );
            return $img ? sprintf( '<img src="%s" alt="%s" width="%d" height="%d">', esc_url( $img[0] ), esc_attr( html_entity_decode( $alt ) ), (int) $img[1], (int) $img[2] ) : '';
        }, $html );
    }

    private static function mig_post_item( array $p ) {
        $title = trim( wp_specialchars_decode( self::mig_text( $p['title'] ?? '', 200 ), ENT_QUOTES ) );
        if ( $title === '' ) {
            return 'skipped';
        }
        $id      = self::mig_post( [ 'post' ], $p['id'] );
        $status  = in_array( $p['status'] ?? '', [ 'publish', 'future' ], true ) ? $p['status'] : 'draft';
        $content = Qwoo_Blog::clean_content( self::mig_content_images( (string) ( $p['content'] ?? '' ) ) );
        $cats    = array_values( array_filter( array_map( static fn( $old ) => self::mig_term( 'category', (int) $old ), (array) ( $p['categories'] ?? [] ) ) ) );
        $gmt     = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ( $p['date_gmt'] ?? '' ) ) && $p['date_gmt'] !== '0000-00-00 00:00:00' ? $p['date_gmt'] : '';
        $admins  = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ] );
        $data    = [
            'post_type'     => 'post',
            'post_title'    => $title,
            'post_name'     => sanitize_title( rawurldecode( (string) ( $p['slug'] ?? '' ) ) ) ?: sanitize_title( $title ),
            'post_status'   => $status,
            'post_content'  => $content,
            'post_excerpt'  => mb_substr( sanitize_textarea_field( (string) ( $p['excerpt'] ?? '' ) ), 0, 300 ),
            'post_category' => $cats ?: [ (int) get_option( 'default_category' ) ],
            'post_author'   => (int) ( $admins[0] ?? 0 ),
        ];
        if ( $gmt !== '' ) {
            $data['post_date_gmt'] = $gmt;
            $data['post_date']     = get_date_from_gmt( $gmt );
            $data['edit_date']     = true;
        }
        if ( $id ) {
            $data['ID'] = $id;
            $result     = wp_update_post( wp_slash( $data ), true );
            $state      = 'updated';
        } else {
            $result = wp_insert_post( wp_slash( $data ), true );
            $state  = 'created';
        }
        if ( is_wp_error( $result ) ) {
            throw new RuntimeException( $result->get_error_message() );
        }
        $id = (int) $result;
        update_post_meta( $id, Qwoo_Migration::META, self::mig_ref( $p['id'] ) );
        $cover = ! empty( $p['image'] ) ? self::mig_image( $p['image'] ) : 0;
        $cover ? set_post_thumbnail( $id, $cover ) : delete_post_thumbnail( $id );
        self::mig_remember_path( 'post', $id, $p['link'] ?? '', 'blog/' . get_post_field( 'post_name', $id ) );
        self::mig_seo( $id, 'post', $p['seo'] ?? [] );
        return $state;
    }
}

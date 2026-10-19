<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Store export / import files ("Copy your store" in the dashboard's Settings).
 *
 * An export is one .zip the owner downloads and can bring into another store
 * (or keep as a copy). It holds the chosen parts:
 *   builder   Store builder: pages, menus, header & footer, branding, layouts,
 *             the extra languages' copies, saved section templates
 *   products  products (photos and videos, options, SEO, translations),
 *             product categories, tags and shared options
 *   blog      posts and blog categories
 *   coupons   discount codes (without who used them)
 *   settings  currency, units, shipping zones, taxes, reviews, SEO and
 *             language settings
 * Never: the store's name or addresses, payment keys, customers, orders,
 * reviews (all customers' data) or the owner's account.
 *
 * Inside the zip:
 *   manifest.json            { format, app, made, source, parts, counts, index }
 *   builder.json, settings.json, attributes.json
 *   data/{type}/{n}.json     items in the YallaFlow Mover's shape (the import
 *                            runs through the same importer as a move), in
 *                            ID order: index[type] = [ { file, first, last } ]
 *   media.json               { id: { file, mime, name, alt, title } }
 *   media/{id}.{ext}         the photos and videos (as uploaded)
 *
 * Exports are built in steps (export_step(), ~20s each; the dashboard keeps
 * calling it: no cron) in a staging folder, zipped at the end, and handed
 * out in chunks through the platform (the store's own address is never
 * shown). Uploads come the same way, in chunks. Files live in
 * uploads/qwoo-package/ (closed to the web) and are removed after KEEP.
 */
class Qwoo_Store_Package {

    const FORMAT        = 1;
    const EXPORT_OPTION = 'qwoo_package_export';
    const IMPORT_OPTION = 'qwoo_package_import';
    const DIR           = 'qwoo-package';
    const CHUNK         = 5242880;      // 5 MB per download / upload piece
    const MAX_FILE      = 1073741824;   // 1 GB
    const MAX_MEDIA     = 20971520;     // 20 MB a file (the upload limits are lower)
    const PAGE          = 50;
    const KEEP          = 6 * HOUR_IN_SECONDS;
    const SECONDS       = 20;
    const PARTS         = [ 'builder', 'products', 'blog', 'coupons', 'settings' ];

    /** WooCommerce settings that come along (the move's list without the shop's own address). */
    const SETTINGS = [
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

    /** Export steps per part, in order (settings first: the import needs the languages before the rest). */
    const STEPS = [
        'settings' => [ 'settings' ],
        'products' => [ 'attributes', 'product_cat', 'product_tag', 'products' ],
        'coupons'  => [ 'coupons' ],
        'blog'     => [ 'post_categories', 'posts' ],
        'builder'  => [ 'builder' ],
    ];

    const LABELS = [
        'settings'        => 'Store settings',
        'attributes'      => 'Product options',
        'product_cat'     => 'Product categories',
        'product_tag'     => 'Product tags',
        'products'        => 'Products',
        'coupons'         => 'Discount codes',
        'post_categories' => 'Blog categories',
        'posts'           => 'Blog posts',
        'builder'         => 'Store builder',
        'media'           => 'Photos and videos',
        'zip'             => 'Making the file',
    ];

    const POST_STATUSES    = [ 'publish', 'draft', 'pending', 'private', 'future' ];
    const PRODUCT_STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    /** @var array The export's state while a step works. */
    private static $ex = [];
    private static $deadline = 0.0;

    /* ================================================================
       Files
       ================================================================ */

    /** The private folder (made on first use, closed to the web). */
    public static function dir() {
        $dir = wp_upload_dir( null, false )['basedir'] . '/' . self::DIR;
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        if ( ! file_exists( $dir . '/index.php' ) ) {
            file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
        }
        if ( ! file_exists( $dir . '/.htaccess' ) ) {
            file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
        }
        return $dir;
    }

    private static function valid_id( $id ) {
        return is_string( $id ) && preg_match( '/^[a-f0-9]{24}$/', $id );
    }

    private static function rmdir_all( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        $items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $items as $item ) {
            $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
        }
        @rmdir( $dir );
    }

    /** Removes old files (older than KEEP) that no running export or import uses. */
    public static function cleanup() {
        $dir    = self::dir();
        $export = (string) ( self::export_state()['id'] ?? '' );
        $import = (string) ( self::import_state()['id'] ?? '' );
        foreach ( (array) glob( $dir . '/*-*' ) as $path ) {
            $name = basename( (string) $path );
            if ( ! preg_match( '/^(ex|in)-([a-f0-9]{24})(\.zip)?$/', $name, $m ) ) continue;
            $age = time() - (int) @filemtime( $path );
            // The current import's file stays while it's used; the current export's for KEEP.
            $importing = ( self::import_state()['status'] ?? '' ) === 'importing';
            if ( ( $m[1] === 'in' && $m[2] === $import && ( $importing || $age < self::KEEP ) ) || ( $m[1] === 'ex' && $m[2] === $export && $age < self::KEEP ) ) {
                continue;
            }
            is_dir( $path ) ? self::rmdir_all( $path ) : @unlink( $path );
            if ( $m[1] === 'in' && $m[2] === $import ) delete_option( self::IMPORT_OPTION );
        }
    }

    /* ================================================================
       Export
       ================================================================ */

    public static function export_state() {
        $s = get_option( self::EXPORT_OPTION, [] );
        return is_array( $s ) ? $s : [];
    }

    private static function export_save() {
        self::$ex['updated'] = time();
        update_option( self::EXPORT_OPTION, self::$ex, false );
    }

    private static function export_steps( array $parts ) {
        $steps = [];
        foreach ( self::STEPS as $part => $list ) {
            if ( in_array( $part, $parts, true ) ) {
                $steps = array_merge( $steps, $list );
            }
        }
        return array_merge( $steps, [ 'media', 'zip' ] );
    }

    /** What the dashboard sees. */
    public static function export_public( ?array $s = null ) {
        $s = $s ?? self::export_state();
        if ( empty( $s['id'] ) ) {
            return [ 'status' => 'none' ];
        }
        $steps = self::export_steps( (array) $s['parts'] );
        $i     = min( (int) ( $s['step'] ?? 0 ), count( $steps ) - 1 );
        $ready = ( $s['status'] ?? '' ) === 'ready' && is_file( self::dir() . '/ex-' . $s['id'] . '.zip' );
        return [
            'status'   => $ready ? 'ready' : ( ( $s['status'] ?? '' ) === 'ready' ? 'none' : (string) $s['status'] ), // building | ready | failed
            'parts'    => array_values( (array) $s['parts'] ),
            'counts'   => (object) ( $s['counts'] ?? [] ),
            'step'     => self::LABELS[ $steps[ $i ] ] ?? '',
            'progress' => $ready ? 100 : (int) floor( 100 * $i / max( 1, count( $steps ) ) ),
            'size'     => (int) ( $s['size'] ?? 0 ),
            'chunks'   => (int) ceil( (int) ( $s['size'] ?? 0 ) / self::CHUNK ),
            'started'  => (int) ( $s['started'] ?? 0 ),
            'finished' => (int) ( $s['finished'] ?? 0 ),
            'error'    => (string) ( $s['error'] ?? '' ),
        ];
    }

    /** Starts a new export of $parts (a running one is answered as it is). */
    public static function export_start( array $parts ) {
        $parts = array_values( array_intersect( self::PARTS, array_map( 'strval', $parts ) ) );
        if ( ! $parts ) {
            return new WP_Error( 'qwoo_package_parts', 'Choose what to put in the file.', [ 'status' => 400 ] );
        }
        $s = self::export_state();
        if ( ( $s['status'] ?? '' ) === 'building' && time() - (int) ( $s['updated'] ?? 0 ) < 10 * MINUTE_IN_SECONDS ) {
            return self::export_public( $s );
        }
        self::export_discard();
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'qwoo_package_zip', 'This server can\'t make zip files. Contact support.', [ 'status' => 500 ] );
        }
        $id = bin2hex( random_bytes( 12 ) );
        wp_mkdir_p( self::dir() . '/ex-' . $id . '/data' );
        self::$ex = [
            'id'       => $id,
            'parts'    => $parts,
            'status'   => 'building',
            'step'     => 0,
            'cursor'   => 0,
            'counts'   => [],
            'index'    => [],
            'media'    => [],
            'media_at' => 0,
            'meta'     => [],
            'started'  => time(),
            'finished' => 0,
            'size'     => 0,
            'error'    => '',
        ];
        self::export_save();
        return self::export_public( self::$ex );
    }

    /** Works for about SECONDS, then says how far the export is. */
    public static function export_step() {
        $s = self::export_state();
        if ( ( $s['status'] ?? '' ) !== 'building' ) {
            return self::export_public( $s );
        }
        $lock = 'qwoo_package_export_lock';
        if ( get_transient( $lock ) ) {
            return self::export_public( $s ) + [ 'busy' => true ];
        }
        set_transient( $lock, 1, 3 * MINUTE_IN_SECONDS );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 180 );
        }
        wp_raise_memory_limit( 'admin' );
        self::$ex       = $s;
        self::$deadline = microtime( true ) + self::SECONDS;
        $steps          = self::export_steps( (array) $s['parts'] );
        try {
            while ( self::time_left() > 3 && isset( $steps[ (int) self::$ex['step'] ] ) ) {
                $method = 'ex_' . $steps[ (int) self::$ex['step'] ];
                if ( self::$method() ) {
                    self::$ex['step']++;
                    self::$ex['cursor'] = 0;
                }
                self::export_save();
            }
            if ( ! isset( $steps[ (int) self::$ex['step'] ] ) ) {
                self::$ex['status']   = 'ready';
                self::$ex['finished'] = time();
            }
        } catch ( Throwable $e ) {
            self::$ex['status'] = 'failed';
            self::$ex['error']  = 'The file couldn\'t be made: ' . mb_substr( wp_strip_all_tags( $e->getMessage() ), 0, 300 );
            self::rmdir_all( self::dir() . '/ex-' . self::$ex['id'] );
        } finally {
            self::export_save();
            delete_transient( $lock );
        }
        return self::export_public( self::$ex );
    }

    /** One piece of the finished file ($bytes long, at most CHUNK): { index, data (base64), size, done }. */
    public static function export_chunk( $index, $bytes = self::CHUNK ) {
        $bytes = max( 262144, min( self::CHUNK, (int) $bytes ) );
        $s    = self::export_state();
        $path = ! empty( $s['id'] ) ? self::dir() . '/ex-' . $s['id'] . '.zip' : '';
        if ( ( $s['status'] ?? '' ) !== 'ready' || $path === '' || ! is_file( $path ) ) {
            return new WP_Error( 'qwoo_package_gone', 'The file is gone. Make it again.', [ 'status' => 404 ] );
        }
        $size   = (int) filesize( $path );
        $offset = max( 0, (int) $index ) * $bytes;
        if ( $offset >= $size ) {
            return new WP_Error( 'qwoo_package_range', 'That part of the file doesn\'t exist.', [ 'status' => 416 ] );
        }
        $fh = fopen( $path, 'rb' );
        fseek( $fh, $offset );
        $data = (string) fread( $fh, $bytes );
        fclose( $fh );
        return [ 'index' => (int) $index, 'data' => base64_encode( $data ), 'size' => $size, 'done' => $offset + strlen( $data ) >= $size ];
    }

    public static function export_discard() {
        $s = self::export_state();
        if ( ! empty( $s['id'] ) && self::valid_id( $s['id'] ) ) {
            self::rmdir_all( self::dir() . '/ex-' . $s['id'] );
            @unlink( self::dir() . '/ex-' . $s['id'] . '.zip' );
        }
        delete_option( self::EXPORT_OPTION );
        return [ 'status' => 'none' ];
    }

    private static function time_left() {
        return self::$deadline - microtime( true );
    }

    /* ---------------- writing ---------------- */

    private static function staging() {
        return self::dir() . '/ex-' . self::$ex['id'];
    }

    private static function put( $rel, $data ) {
        $path = self::staging() . '/' . $rel;
        wp_mkdir_p( dirname( $path ) );
        if ( file_put_contents( $path, wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) === false ) {
            throw new RuntimeException( 'The server\'s disk may be full.' );
        }
    }

    /** One page of items of $type (sorted by ID). */
    private static function put_page( $type, array $items ) {
        if ( ! $items ) return;
        $n    = count( (array) ( self::$ex['index'][ $type ] ?? [] ) ) + 1;
        $file = sprintf( 'data/%s/%04d.json', $type, $n );
        self::put( $file, $items );
        self::$ex['index'][ $type ][] = [ 'file' => $file, 'first' => (int) $items[0]['id'], 'last' => (int) end( $items )['id'] ];
        self::$ex['counts'][ $type ]  = (int) ( self::$ex['counts'][ $type ] ?? 0 ) + count( $items );
    }

    /** Goes through IDs page by page ($ids( $after, $limit ), $item( $id ) → array|null). True when done. */
    private static function paged( $type, callable $ids, callable $item, $limit = self::PAGE ) {
        while ( self::time_left() > 3 ) {
            $list  = $ids( (int) self::$ex['cursor'], $limit );
            $items = [];
            foreach ( $list as $id ) {
                $one = $item( (int) $id );
                if ( $one ) $items[] = $one;
            }
            self::put_page( $type, $items );
            if ( $list ) self::$ex['cursor'] = (int) end( $list );
            if ( count( $list ) < $limit ) return true;
            self::export_save();
        }
        return false;
    }

    /** A media file to bring along: its reference in the data ({ id, package, alt, title }), or null. */
    private static function image( $id ) {
        $id = (int) $id;
        if ( ! $id || get_post_type( $id ) !== 'attachment' || ! get_attached_file( $id ) ) {
            return null;
        }
        self::$ex['media'][ $id ] = true;
        return [ 'id' => $id, 'package' => 1, 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ), 'title' => get_the_title( $id ) ];
    }

    private static function post_ids( $type, array $statuses, $after, $limit ) {
        global $wpdb;
        $in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ($in) AND ID > %d ORDER BY ID ASC LIMIT %d",
            array_merge( [ $type ], $statuses, [ $after, $limit ] )
        ) ) );
    }

    private static function term_ids( $taxonomy, $after, $limit ) {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.term_id > %d ORDER BY t.term_id ASC LIMIT %d",
            $taxonomy, $after, $limit
        ) ) );
    }

    private static function date( $date ) {
        return $date instanceof WC_DateTime ? $date->getTimestamp() : null;
    }

    /** This store's own SEO fields, in the importer's shape. */
    private static function seo( $id, $kind ) {
        $c = Qwoo_Seo::custom( (int) $id, $kind );
        $out = array_filter( [
            'title'       => (string) ( $c['title'] ?? '' ),
            'description' => (string) ( $c['description'] ?? '' ),
            'keyphrase'   => (string) ( $c['keyphrase'] ?? '' ),
            'noindex'     => ! empty( $c['noindex'] ),
        ] );
        $image = ! empty( $c['image_id'] ) ? self::image( $c['image_id'] ) : null;
        if ( $image ) $out['image'] = $image;
        return $out;
    }

    /** Images in a text: their addresses become references to the file's media ("qwoo-pkg:ID"). */
    private static function text_images( $html ) {
        return preg_replace_callback( '#(<img\b[^>]*\ssrc=["\'])([^"\']+)(["\'])#i', static function ( $m ) {
            $url = html_entity_decode( $m[2] );
            $id  = attachment_url_to_postid( $url ) ?: attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url ) );
            return $id && self::image( $id ) ? $m[1] . 'qwoo-pkg:' . (int) $id . $m[3] : $m[0];
        }, (string) $html );
    }

    /* ---------------- steps ---------------- */

    private static function ex_settings() {
        $options = [];
        foreach ( self::SETTINGS as $name ) {
            $value = get_option( $name, null );
            if ( $value !== null ) $options[ $name ] = $value;
        }
        $zones = [];
        $all   = WC_Shipping_Zones::get_zones();
        $rest  = new WC_Shipping_Zone( 0 );
        $all[] = [ 'zone_id' => 0, 'zone_name' => $rest->get_zone_name(), 'zone_order' => 0, 'zone_locations' => $rest->get_zone_locations(), 'shipping_methods' => $rest->get_shipping_methods() ];
        foreach ( $all as $z ) {
            $methods = [];
            foreach ( (array) $z['shipping_methods'] as $m ) {
                $methods[] = [ 'method_id' => $m->id, 'enabled' => $m->enabled === 'yes', 'order' => (int) $m->method_order, 'title' => (string) $m->title, 'settings' => (array) $m->instance_settings ];
            }
            $zones[] = [
                'id'        => (int) $z['zone_id'],
                'name'      => (string) $z['zone_name'],
                'order'     => (int) $z['zone_order'],
                'locations' => array_map( static fn( $l ) => [ 'code' => (string) $l->code, 'type' => (string) $l->type ], (array) $z['zone_locations'] ),
                'methods'   => $methods,
            ];
        }
        $classes = [];
        foreach ( WC()->shipping()->get_shipping_classes() as $c ) {
            $classes[] = [ 'id' => (int) $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description ];
        }
        global $wpdb;
        $rates = [];
        foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_order, tax_rate_id", ARRAY_A ) as $r ) {
            $locations = $wpdb->get_results( $wpdb->prepare( "SELECT location_code, location_type FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id = %d", $r['tax_rate_id'] ), ARRAY_A );
            $pick      = static fn( $type ) => array_values( array_map( static fn( $l ) => $l['location_code'], array_filter( $locations, static fn( $l ) => $l['location_type'] === $type ) ) );
            $rates[]   = [
                'id' => (int) $r['tax_rate_id'], 'country' => (string) $r['tax_rate_country'], 'state' => (string) $r['tax_rate_state'],
                'rate' => (string) $r['tax_rate'], 'name' => (string) $r['tax_rate_name'], 'priority' => (int) $r['tax_rate_priority'],
                'compound' => (bool) $r['tax_rate_compound'], 'shipping' => (bool) $r['tax_rate_shipping'], 'order' => (int) $r['tax_rate_order'],
                'class' => (string) $r['tax_rate_class'], 'postcodes' => $pick( 'postcode' ), 'cities' => $pick( 'city' ),
            ];
        }
        $tax_classes = [];
        foreach ( WC_Tax::get_tax_classes() as $name ) {
            $tax_classes[] = [ 'name' => $name, 'slug' => sanitize_title( $name ) ];
        }
        $seo = Qwoo_Seo::settings();
        $qwoo = [
            'reviews'   => Qwoo_Reviews::settings(),
            // Not Google's verification code: that belongs to this store's address.
            'seo'       => [
                'title_pattern'    => (string) $seo['title_pattern'],
                'home_title'       => (string) $seo['home_title'],
                'home_description' => (string) $seo['home_description'],
                'image'            => ! empty( $seo['image_id'] ) ? self::image( $seo['image_id'] ) : null,
            ],
            'language'  => Qwoo_Store_Language::get(),
            'languages' => Qwoo_Store_Language::extra_settings(),
        ];
        self::put( 'settings.json', [ 'options' => $options, 'zones' => $zones, 'shipping_classes' => $classes, 'tax_classes' => $tax_classes, 'tax_rates' => $rates, 'qwoo' => $qwoo ] );
        self::$ex['counts']['settings'] = 1;
        return true;
    }

    private static function ex_attributes() {
        $items = [];
        foreach ( wc_get_attribute_taxonomies() as $a ) {
            $taxonomy = wc_attribute_taxonomy_name( $a->attribute_name );
            $terms    = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'menu_order' ] );
            $items[]  = [
                'id' => (int) $a->attribute_id, 'name' => (string) $a->attribute_label, 'slug' => (string) $a->attribute_name,
                'type' => (string) $a->attribute_type, 'order_by' => (string) $a->attribute_orderby,
                'terms' => is_wp_error( $terms ) ? [] : array_map( static fn( $t ) => [ 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'description' => $t->description, 'order' => (int) get_term_meta( $t->term_id, 'order', true ) ], $terms ),
            ];
        }
        self::put( 'attributes.json', [ 'items' => $items, 'next' => null ] );
        self::$ex['counts']['attributes'] = count( $items );
        return true;
    }

    private static function term_item( WP_Term $t ) {
        $item = [
            'id'          => (int) $t->term_id,
            'name'        => $t->name,
            'slug'        => $t->slug,
            'parent'      => (int) $t->parent,
            'description' => $t->description,
            'order'       => (int) get_term_meta( $t->term_id, 'order', true ),
            'seo'         => in_array( $t->taxonomy, [ 'product_cat', 'category' ], true ) ? self::seo( $t->term_id, 'term' ) : [],
            'i18n'        => Qwoo_Translations::get( $t->term_id, true ),
        ];
        $thumb = (int) get_term_meta( $t->term_id, 'thumbnail_id', true );
        if ( $thumb ) $item['image'] = self::image( $thumb );
        return $item;
    }

    private static function terms_step( $type, $taxonomy ) {
        $default = (int) get_option( 'default_category' );
        return self::paged( $type, static fn( $after, $limit ) => self::term_ids( $taxonomy, $after, $limit ), static function ( $id ) use ( $taxonomy, $default ) {
            $t = get_term( $id, $taxonomy );
            return $t instanceof WP_Term ? self::term_item( $t ) + ( $taxonomy === 'category' ? [ 'default' => $id === $default ] : [] ) : null;
        } );
    }

    private static function ex_product_cat() {
        return self::terms_step( 'product_cat', 'product_cat' );
    }

    private static function ex_product_tag() {
        return self::terms_step( 'product_tag', 'product_tag' );
    }

    private static function ex_post_categories() {
        return self::terms_step( 'post_categories', 'category' );
    }

    private static function stock_fields( WC_Product $p ) {
        return [
            'sku' => $p->get_sku( 'edit' ), 'regular_price' => $p->get_regular_price( 'edit' ), 'sale_price' => $p->get_sale_price( 'edit' ),
            'date_on_sale_from' => self::date( $p->get_date_on_sale_from( 'edit' ) ), 'date_on_sale_to' => self::date( $p->get_date_on_sale_to( 'edit' ) ),
            'tax_status' => $p->get_tax_status( 'edit' ), 'tax_class' => $p->get_tax_class( 'edit' ), 'manage_stock' => $p->get_manage_stock( 'edit' ),
            'stock_quantity' => $p->get_stock_quantity( 'edit' ), 'stock_status' => $p->get_stock_status( 'edit' ), 'backorders' => $p->get_backorders( 'edit' ),
            'low_stock_amount' => $p->get_low_stock_amount( 'edit' ), 'weight' => $p->get_weight( 'edit' ), 'length' => $p->get_length( 'edit' ),
            'width' => $p->get_width( 'edit' ), 'height' => $p->get_height( 'edit' ), 'shipping_class' => $p->get_shipping_class(),
            'virtual' => $p->get_virtual( 'edit' ), 'downloadable' => $p->get_downloadable( 'edit' ),
        ];
    }

    private static function product_attributes( WC_Product $p ) {
        $out = [];
        foreach ( $p->get_attributes() as $key => $a ) {
            if ( ! $a instanceof WC_Product_Attribute ) continue;
            $options = $a->is_taxonomy()
                ? array_map( static fn( $t ) => [ 'slug' => $t->slug, 'name' => $t->name ], $a->get_terms() ?: [] )
                : array_map( static fn( $o ) => [ 'slug' => $o, 'name' => $o ], $a->get_options() );
            $out[] = [
                'key' => (string) $key, 'name' => $a->is_taxonomy() ? wc_attribute_label( $a->get_name() ) : $a->get_name(),
                'taxonomy' => $a->is_taxonomy() ? substr( $a->get_name(), 3 ) : '', 'options' => array_values( $options ),
                'visible' => $a->get_visible(), 'variation' => $a->get_variation(), 'position' => $a->get_position(),
            ];
        }
        return $out;
    }

    private static function ex_products() {
        return self::paged( 'products', static fn( $after, $limit ) => self::post_ids( 'product', self::PRODUCT_STATUSES, $after, $limit ), static function ( $id ) {
            $p = wc_get_product( $id );
            if ( ! $p ) return null;
            $gallery = array_values( array_filter( array_map( [ __CLASS__, 'image' ], $p->get_gallery_image_ids( 'edit' ) ) ) );
            // The gallery's full order with videos (Qwoo_Media_Support), when it has any.
            $order = array_filter( array_map( 'absint', explode( ',', (string) $p->get_meta( Qwoo_Media_Support::GALLERY_META ) ) ) );
            $item = [
                'id' => $id, 'type' => $p->get_type(), 'name' => $p->get_name( 'edit' ), 'slug' => $p->get_slug( 'edit' ),
                'status' => $p->get_status( 'edit' ), 'featured' => $p->get_featured( 'edit' ), 'catalog_visibility' => $p->get_catalog_visibility( 'edit' ),
                'description' => self::text_images( $p->get_description( 'edit' ) ), 'short_description' => self::text_images( $p->get_short_description( 'edit' ) ),
                'sold_individually' => $p->get_sold_individually( 'edit' ), 'reviews_allowed' => $p->get_reviews_allowed( 'edit' ),
                'purchase_note' => $p->get_purchase_note( 'edit' ), 'menu_order' => $p->get_menu_order( 'edit' ),
                'date_created' => self::date( $p->get_date_created( 'edit' ) ),
                'categories' => array_map( 'intval', $p->get_category_ids( 'edit' ) ), 'tags' => array_map( 'intval', $p->get_tag_ids( 'edit' ) ),
                'upsells' => array_map( 'intval', $p->get_upsell_ids( 'edit' ) ), 'cross_sells' => array_map( 'intval', $p->get_cross_sell_ids( 'edit' ) ),
                'attributes' => self::product_attributes( $p ), 'default_attributes' => (array) $p->get_default_attributes( 'edit' ),
                'image' => self::image( $p->get_image_id( 'edit' ) ), 'gallery' => $gallery,
                'seo' => self::seo( $id, 'post' ), 'i18n' => Qwoo_Translations::get( $id ),
                'media_order' => $order ? array_values( array_filter( array_map( [ __CLASS__, 'image' ], $order ) ) ) : [],
            ] + self::stock_fields( $p );
            if ( $p->is_type( 'variable' ) ) {
                $item['variations'] = [];
                foreach ( $p->get_children() as $vid ) {
                    $v = wc_get_product( $vid );
                    if ( ! $v instanceof WC_Product_Variation ) continue;
                    $item['variations'][] = [
                        'id' => (int) $vid, 'status' => $v->get_status( 'edit' ), 'attributes' => (array) $v->get_attributes( 'edit' ),
                        'description' => $v->get_description( 'edit' ), 'menu_order' => $v->get_menu_order( 'edit' ), 'image' => self::image( $v->get_image_id( 'edit' ) ),
                    ] + self::stock_fields( $v );
                }
            }
            return $item;
        }, 20 );
    }

    private static function ex_coupons() {
        return self::paged( 'coupons', static fn( $after, $limit ) => self::post_ids( 'shop_coupon', [ 'publish', 'draft', 'pending', 'future' ], $after, $limit ), static function ( $id ) {
            $c = new WC_Coupon( $id );
            if ( ! $c->get_id() ) return null;
            // Who used it and how often are customers' data: a copy starts unused.
            return [
                'id' => $id, 'code' => $c->get_code( 'edit' ), 'status' => get_post_status( $id ), 'description' => $c->get_description( 'edit' ),
                'discount_type' => $c->get_discount_type( 'edit' ), 'amount' => $c->get_amount( 'edit' ),
                'date_expires' => self::date( $c->get_date_expires( 'edit' ) ), 'date_created' => self::date( $c->get_date_created( 'edit' ) ),
                'usage_count' => 0, 'individual_use' => $c->get_individual_use( 'edit' ),
                'product_ids' => array_map( 'intval', $c->get_product_ids( 'edit' ) ), 'excluded_product_ids' => array_map( 'intval', $c->get_excluded_product_ids( 'edit' ) ),
                'usage_limit' => $c->get_usage_limit( 'edit' ), 'usage_limit_per_user' => $c->get_usage_limit_per_user( 'edit' ),
                'limit_usage_to_x_items' => $c->get_limit_usage_to_x_items( 'edit' ), 'free_shipping' => $c->get_free_shipping( 'edit' ),
                'product_categories' => array_map( 'intval', $c->get_product_categories( 'edit' ) ),
                'excluded_product_categories' => array_map( 'intval', $c->get_excluded_product_categories( 'edit' ) ),
                'exclude_sale_items' => $c->get_exclude_sale_items( 'edit' ), 'minimum_amount' => $c->get_minimum_amount( 'edit' ),
                'maximum_amount' => $c->get_maximum_amount( 'edit' ), 'email_restrictions' => [], 'used_by' => [],
            ];
        } );
    }

    private static function ex_posts() {
        return self::paged( 'posts', static fn( $after, $limit ) => self::post_ids( 'post', self::POST_STATUSES, $after, $limit ), static function ( $id ) {
            $post = get_post( $id );
            if ( ! $post ) return null;
            return [
                'id' => $id, 'title' => $post->post_title, 'slug' => $post->post_name, 'status' => $post->post_status,
                'date' => $post->post_date, 'date_gmt' => $post->post_date_gmt, 'content' => self::text_images( $post->post_content ),
                'excerpt' => wp_strip_all_tags( $post->post_excerpt ), 'categories' => array_map( 'intval', wp_get_post_categories( $id ) ),
                'image' => self::image( get_post_thumbnail_id( $id ) ), 'seo' => self::seo( $id, 'post' ), 'i18n' => Qwoo_Translations::get( $id ),
            ];
        }, 20 );
    }

    private static function ex_builder() {
        $data = Shop_Settings_Builder::package_builder_export();
        foreach ( (array) ( $data['refs']['media'] ?? [] ) as $id ) {
            self::image( $id );
        }
        self::put( 'builder.json', $data );
        self::$ex['counts']['pages'] = count( (array) ( $data['options']['custom_pages'] ?? [] ) );
        return true;
    }

    /** Copies the media files into the folder, a few at a time. */
    private static function ex_media() {
        $ids = array_keys( (array) self::$ex['media'] );
        sort( $ids );
        $pos = (int) self::$ex['media_at'];
        while ( $pos < count( $ids ) && self::time_left() > 3 ) {
            $id   = (int) $ids[ $pos ];
            $path = (string) get_attached_file( $id );
            $size = $path !== '' && is_file( $path ) ? (int) filesize( $path ) : 0;
            $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            if ( $size > 0 && $size <= self::MAX_MEDIA && preg_match( '/^(jpe?g|png|webp|gif|svg|mp4|webm)$/', $ext ) ) {
                $file = 'media/' . $id . '.' . $ext;
                wp_mkdir_p( self::staging() . '/media' );
                if ( ! copy( $path, self::staging() . '/' . $file ) ) {
                    throw new RuntimeException( 'The server\'s disk may be full.' );
                }
                self::$ex['meta'][ $id ] = [
                    'file'  => $file,
                    'mime'  => (string) get_post_mime_type( $id ),
                    'name'  => pathinfo( $path, PATHINFO_FILENAME ),
                    'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
                    'title' => get_the_title( $id ),
                ];
            }
            $pos++;
            self::$ex['media_at'] = $pos;
            if ( $pos % 20 === 0 ) self::export_save();
        }
        self::$ex['counts']['media'] = count( (array) self::$ex['meta'] );
        return $pos >= count( $ids );
    }

    /** Puts everything into the zip (media stored as they are: they're compressed already). */
    private static function ex_zip() {
        $dir = self::staging();
        self::put( 'media.json', (object) self::$ex['meta'] );
        $counts = (array) self::$ex['counts'];
        self::put( 'manifest.json', [
            'format' => self::FORMAT,
            'app'    => 'yallaflow',
            'made'   => time(),
            'source' => [
                'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
                'key'  => self::source_key(),
                'lang' => Qwoo_Store_Language::get(),
            ],
            'parts'  => array_values( (array) self::$ex['parts'] ),
            'counts' => (object) $counts,
            'index'  => (object) self::$ex['index'],
        ] );
        $target = self::dir() . '/ex-' . self::$ex['id'] . '.zip';
        $zip    = new ZipArchive();
        if ( $zip->open( $target, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            throw new RuntimeException( 'The zip file couldn\'t be made.' );
        }
        $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $files as $file ) {
            $rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) + 1 ) );
            $zip->addFile( $file->getPathname(), $rel );
            if ( strpos( $rel, 'media/' ) === 0 && method_exists( $zip, 'setCompressionName' ) ) {
                $zip->setCompressionName( $rel, ZipArchive::CM_STORE );
            }
        }
        if ( ! $zip->close() ) {
            throw new RuntimeException( 'The zip file couldn\'t be saved. The server\'s disk may be full.' );
        }
        self::rmdir_all( $dir );
        self::$ex['size'] = (int) filesize( $target );
        return true;
    }

    /** This store, as a source: the same in every file it makes (items keep "<key>:<id>" when imported). */
    public static function source_key() {
        return 'pk' . substr( md5( (string) wp_parse_url( home_url(), PHP_URL_HOST ) . '|' . wp_salt( 'auth' ) ), 0, 8 );
    }

    /* ================================================================
       Import: upload, check, read
       ================================================================ */

    public static function import_state() {
        $s = get_option( self::IMPORT_OPTION, [] );
        return is_array( $s ) ? $s : [];
    }

    private static function import_path( array $s ) {
        return ! empty( $s['id'] ) && self::valid_id( $s['id'] ) ? self::dir() . '/in-' . $s['id'] . '.zip' : '';
    }

    /** What the dashboard sees about the uploaded file. */
    public static function import_public( ?array $s = null ) {
        $s = $s ?? self::import_state();
        if ( empty( $s['id'] ) ) {
            return [ 'status' => 'none' ];
        }
        return [
            'status'   => (string) $s['status'], // uploading | uploaded | checked | importing
            'received' => (int) ( $s['received'] ?? 0 ),
            'size'     => (int) ( $s['size'] ?? 0 ),
            'file'     => $s['file'] ?? null,
        ];
    }

    /**
     * One piece of an upload: { index (from 0), total, size (whole file), name, data (base64) }.
     * Piece 0 starts a new upload (a waiting one is dropped).
     */
    public static function upload( array $params ) {
        $index = max( 0, (int) ( $params['index'] ?? 0 ) );
        $total = max( 1, (int) ( $params['total'] ?? 1 ) );
        $size  = (int) ( $params['size'] ?? 0 );
        $bytes = base64_decode( (string) ( $params['data'] ?? '' ), true );
        if ( $bytes === false || $bytes === '' || strlen( $bytes ) > self::CHUNK ) {
            return new WP_Error( 'qwoo_package_piece', 'A piece of the file didn\'t arrive. Try again.', [ 'status' => 400 ] );
        }
        $s = self::import_state();
        if ( $index === 0 ) {
            if ( ( $s['status'] ?? '' ) === 'importing' ) {
                return new WP_Error( 'qwoo_package_busy', 'An import is running. Wait for it to finish first.', [ 'status' => 409 ] );
            }
            if ( $size <= 0 || $size > self::MAX_FILE ) {
                return new WP_Error( 'qwoo_package_size', 'The file can be up to 1 GB.', [ 'status' => 400 ] );
            }
            $free = function_exists( 'disk_free_space' ) ? @disk_free_space( self::dir() ) : false;
            if ( $free !== false && $free < $size * 3 ) {
                return new WP_Error( 'qwoo_package_disk', 'There isn\'t enough room on your store\'s server for this file. Contact support.', [ 'status' => 507 ] );
            }
            self::import_discard();
            self::cleanup();
            $s = [ 'id' => bin2hex( random_bytes( 12 ) ), 'status' => 'uploading', 'size' => $size, 'total' => $total, 'next' => 0, 'received' => 0, 'started' => time() ];
        }
        if ( ( $s['status'] ?? '' ) !== 'uploading' || $index !== (int) ( $s['next'] ?? -1 ) || $total !== (int) $s['total'] ) {
            return new WP_Error( 'qwoo_package_order', 'The upload got mixed up. Choose the file again.', [ 'status' => 409 ] );
        }
        $path = self::import_path( $s );
        if ( file_put_contents( $path, $bytes, FILE_APPEND ) === false ) {
            return new WP_Error( 'qwoo_package_disk', 'The file couldn\'t be saved on your store\'s server. Contact support.', [ 'status' => 507 ] );
        }
        $s['received'] += strlen( $bytes );
        $s['next']      = $index + 1;
        if ( $s['received'] > $s['size'] ) {
            self::import_discard();
            return new WP_Error( 'qwoo_package_size', 'The file is bigger than it said. Choose it again.', [ 'status' => 400 ] );
        }
        if ( $s['next'] >= $s['total'] ) {
            if ( $s['received'] !== $s['size'] ) {
                self::import_discard();
                return new WP_Error( 'qwoo_package_size', 'Part of the file is missing. Choose it again.', [ 'status' => 400 ] );
            }
            $s['status'] = 'uploaded';
        }
        update_option( self::IMPORT_OPTION, $s, false );
        return self::import_public( $s );
    }

    public static function import_discard() {
        $s = self::import_state();
        if ( ( $s['status'] ?? '' ) === 'importing' ) {
            return new WP_Error( 'qwoo_package_busy', 'An import is running. Stop it first.', [ 'status' => 409 ] );
        }
        $path = self::import_path( $s );
        if ( $path !== '' ) @unlink( $path );
        delete_option( self::IMPORT_OPTION );
        self::$zip = null;
        return [ 'status' => 'none' ];
    }

    /** @var ZipArchive|null The import file while reading. */
    private static $zip = null;
    private static $pages = [];

    private static function zip() {
        if ( self::$zip ) return self::$zip;
        $path = self::import_path( self::import_state() );
        if ( $path === '' || ! is_file( $path ) ) {
            throw new RuntimeException( 'The uploaded file is gone. Upload it again.' );
        }
        $zip = new ZipArchive();
        if ( $zip->open( $path, ZipArchive::RDONLY ) !== true ) {
            throw new RuntimeException( 'This isn\'t a YallaFlow store file.' );
        }
        return self::$zip = $zip;
    }

    /** A JSON entry of the file (null when missing). */
    private static function json( $name, $max = 52428800 ) {
        $zip  = self::zip();
        $stat = $zip->statName( $name );
        if ( ! $stat ) return null;
        if ( (int) $stat['size'] > $max ) {
            throw new RuntimeException( 'Part of the file is too big: ' . $name );
        }
        $data = json_decode( (string) $zip->getFromName( $name ), true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Checks the uploaded file: only the entries a store file has, sizes in
     * reason, a manifest this store understands. Returns what it holds.
     */
    public static function inspect() {
        $s = self::import_state();
        if ( ! in_array( $s['status'] ?? '', [ 'uploaded', 'checked' ], true ) ) {
            return new WP_Error( 'qwoo_package_none', 'Upload the file first.', [ 'status' => 400 ] );
        }
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'qwoo_package_zip', 'This server can\'t open zip files. Contact support.', [ 'status' => 500 ] );
        }
        try {
            $zip   = self::zip();
            $total = 0;
            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $stat = $zip->statIndex( $i );
                $name = (string) ( $stat['name'] ?? '' );
                if ( ! preg_match( '#^(manifest|builder|settings|attributes|media)\.json$|^data/[a-z_]{3,20}/\d{4}\.json$|^media/\d{1,12}\.(jpe?g|png|webp|gif|svg|mp4|webm)$#', $name ) ) {
                    throw new RuntimeException( 'This isn\'t a YallaFlow store file (it has other files in it).' );
                }
                $total += (int) $stat['size'];
                if ( (int) $stat['size'] > self::MAX_FILE || $total > 3 * self::MAX_FILE ) {
                    throw new RuntimeException( 'This file is too big to bring in.' );
                }
            }
            $m = self::json( 'manifest.json', 5242880 );
            if ( ! $m || ( $m['app'] ?? '' ) !== 'yallaflow' ) {
                throw new RuntimeException( 'This isn\'t a YallaFlow store file.' );
            }
            if ( (int) ( $m['format'] ?? 0 ) > self::FORMAT ) {
                throw new RuntimeException( 'This file was made by a newer version of YallaFlow. Update your store first.' );
            }
            $key = (string) ( $m['source']['key'] ?? '' );
            if ( ! preg_match( '/^pk[a-f0-9]{8}$/', $key ) ) {
                throw new RuntimeException( 'This isn\'t a YallaFlow store file.' );
            }
            if ( $key === self::source_key() ) {
                throw new RuntimeException( 'This file was made from this store. Bring it into another store.' );
            }
            $parts  = array_values( array_intersect( self::PARTS, (array) ( $m['parts'] ?? [] ) ) );
            $counts = [];
            foreach ( (array) ( $m['counts'] ?? [] ) as $k => $v ) {
                if ( is_scalar( $v ) ) $counts[ sanitize_key( $k ) ] = max( 0, (int) $v );
            }
            $s['file'] = [
                'name'   => mb_substr( sanitize_text_field( (string) ( $m['source']['name'] ?? '' ) ), 0, 100 ),
                'lang'   => sanitize_key( (string) ( $m['source']['lang'] ?? '' ) ),
                'made'   => (int) ( $m['made'] ?? 0 ),
                'parts'  => $parts,
                'counts' => $counts,
            ];
            $s['key']    = $key;
            $s['status'] = 'checked';
            update_option( self::IMPORT_OPTION, $s, false );
            return self::import_public( $s );
        } catch ( Throwable $e ) {
            self::import_discard();
            return new WP_Error( 'qwoo_package_bad', $e->getMessage(), [ 'status' => 400 ] );
        }
    }

    /** The import has begun / ended (the file stays until the end, then goes). */
    public static function import_mark( $status ) {
        $s = self::import_state();
        if ( empty( $s['id'] ) ) return;
        if ( $status === 'done' ) {
            self::$zip = null;
            $path = self::import_path( $s );
            if ( $path !== '' ) @unlink( $path );
            delete_option( self::IMPORT_OPTION );
            return;
        }
        $s['status'] = $status;
        update_option( self::IMPORT_OPTION, $s, false );
    }

    /**
     * A page of $type for the importer, like the Mover answers:
     * { items (ID > $after, at most $limit), next (cursor) | null }.
     * settings / attributes / builder: the whole entry.
     */
    public static function fetch( $type, $after = 0, $limit = 0 ) {
        try {
            if ( in_array( $type, [ 'settings', 'attributes', 'builder' ], true ) ) {
                return self::json( $type . '.json' ) ?? new WP_Error( 'qwoo_package_part', 'The file has no ' . $type . '.', [ 'status' => 400 ] );
            }
            $index = (array) ( self::json( 'manifest.json', 5242880 )['index'][ $type ] ?? [] );
            $limit = $limit > 0 ? (int) $limit : self::PAGE;
            $items = [];
            $more  = false;
            foreach ( $index as $page ) {
                if ( (int) ( $page['last'] ?? 0 ) <= $after ) continue;
                $file = (string) ( $page['file'] ?? '' );
                if ( ! preg_match( '#^data/[a-z_]{3,20}/\d{4}\.json$#', $file ) ) continue;
                if ( ! isset( self::$pages[ $file ] ) ) {
                    self::$pages = [ $file => self::json( $file ) ?? [] ];
                }
                foreach ( self::$pages[ $file ] as $item ) {
                    if ( ! is_array( $item ) || (int) ( $item['id'] ?? 0 ) <= $after ) continue;
                    if ( count( $items ) >= $limit ) {
                        $more = true;
                        break 2;
                    }
                    $items[] = $item;
                }
            }
            return [ 'items' => $items, 'next' => $more && $items ? (int) end( $items )['id'] : null ];
        } catch ( Throwable $e ) {
            return new WP_Error( 'qwoo_package_read', $e->getMessage(), [ 'status' => 400 ] );
        }
    }

    /** A media file of the import: [ bytes, meta ] or null. */
    public static function media( $old_id ) {
        static $map = null;
        try {
            if ( $map === null ) $map = self::json( 'media.json' ) ?? [];
            $meta = $map[ (int) $old_id ] ?? $map[ (string) (int) $old_id ] ?? null;
            if ( ! is_array( $meta ) || ! preg_match( '#^media/\d{1,12}\.(jpe?g|png|webp|gif|svg|mp4|webm)$#', (string) ( $meta['file'] ?? '' ) ) ) {
                return null;
            }
            $stat = self::zip()->statName( $meta['file'] );
            if ( ! $stat || (int) $stat['size'] > self::MAX_MEDIA ) return null;
            $bytes = self::zip()->getFromName( $meta['file'] );
            return $bytes === false ? null : [ $bytes, $meta ];
        } catch ( Throwable $e ) {
            return null;
        }
    }
}

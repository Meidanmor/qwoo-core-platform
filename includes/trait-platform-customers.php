<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Customers for the platform dashboard (used by Qwoo_Platform_Dashboard).
 *
 * A customer is a storefront account (role customer or subscriber), or a
 * guest: everyone who ordered without an account, grouped by billing
 * email. A guest who later makes an account with the same email is shown
 * as that account. Keys: "u<user id>" for accounts, "g<order id>" for
 * guests (any of their orders: the email comes from it).
 *
 * Orders that never went through (pending payment, failed, drafts) don't
 * make anyone a customer and aren't counted. "Spent" is paid orders
 * (processing, completed) minus refunds.
 */
trait Qwoo_Platform_Customers {

    private static $customer_roles     = [ 'customer', 'subscriber' ];
    private static $customer_skip      = [ 'wc-pending', 'wc-failed', 'wc-checkout-draft', 'trash', 'auto-draft', 'draft' ];
    private static $customer_open      = [ 'processing', 'on-hold' ];
    private static $customer_notes_key = 'qwoo_customer_notes'; // guests' private notes: md5(email) => text
    private static $customer_note_meta = '_qwoo_customer_note';  // accounts' private note
    private static $customer_sorts     = [ 'recent', 'spent', 'orders', 'name' ];
    private static $customer_filters   = [ 'accounts', 'guests', 'repeat' ];

    /* ---------------- dashboard actions ---------------- */

    /**
     * { page, search (name, email or phone), filter: '' | accounts | guests
     * | repeat, sort: recent | spent | orders | name, per_page (20; up to
     * 500 for the export) }.
     */
    private static function action_customers_list( array $params ) {
        $page     = max( 1, min( 10000, (int) ( $params['page'] ?? 1 ) ) );
        $per_page = max( 1, min( 500, (int) ( $params['per_page'] ?? 20 ) ) );
        $search   = mb_strtolower( trim( sanitize_text_field( (string) ( $params['search'] ?? '' ) ) ) );
        $search   = mb_substr( $search, 0, 100 );
        $filter   = in_array( $params['filter'] ?? '', self::$customer_filters, true ) ? $params['filter'] : '';
        $sort     = in_array( $params['sort'] ?? '', self::$customer_sorts, true ) ? $params['sort'] : 'recent';

        $all  = self::customer_groups();
        $rows = array_values( array_filter( $all, static function ( $c ) use ( $search, $filter ) {
            if ( $filter === 'accounts' && ! $c['account'] ) return false;
            if ( $filter === 'guests' && $c['account'] ) return false;
            if ( $filter === 'repeat' && $c['orders'] < 2 ) return false;
            if ( $search === '' ) return true;
            $digits = preg_replace( '/\D/', '', $search );
            return mb_strpos( mb_strtolower( $c['name'] ), $search ) !== false
                || mb_strpos( $c['email'], $search ) !== false
                || ( strlen( $digits ) >= 3 && strpos( preg_replace( '/\D/', '', $c['phone'] ), $digits ) !== false );
        } ) );
        usort( $rows, static function ( $a, $b ) use ( $sort ) {
            switch ( $sort ) {
                case 'spent':
                    return $b['spent'] <=> $a['spent'] ?: $b['last'] <=> $a['last'];
                case 'orders':
                    return $b['orders'] <=> $a['orders'] ?: $b['last'] <=> $a['last'];
                case 'name':
                    return strcasecmp( $a['name'] ?: $a['email'], $b['name'] ?: $b['email'] );
            }
            return $b['last'] <=> $a['last'];
        } );

        $total = count( $rows );
        return [
            'items'    => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
            'total'    => $total,
            'pages'    => (int) ceil( $total / $per_page ),
            'page'     => $page,
            'counts'   => [
                'all'      => count( $all ),
                'accounts' => count( array_filter( $all, static fn( $c ) => $c['account'] ) ),
            ],
            'currency' => get_woocommerce_currency(),
        ];
    }

    /** { key }: profile, addresses, private note, totals and orders. */
    private static function action_customer_get( array $params ) {
        $customer = self::find_customer( $params['key'] ?? '' );
        return is_wp_error( $customer ) ? $customer : self::customer_full( $customer );
    }

    /**
     * { key, fields: { note, and for accounts first_name, last_name, phone,
     * billing { first_name, last_name, company, address_1, address_2, city,
     * state, postcode, country, phone }, shipping { the same, no phone } } }.
     * The note and the details are each saved only when sent. A guest's
     * details come from their orders, so only the note changes. The email
     * address isn't changed here (it's how they sign in).
     */
    private static function action_customer_save( array $params ) {
        $customer = self::find_customer( $params['key'] ?? '' );
        if ( is_wp_error( $customer ) ) {
            return $customer;
        }
        $f    = is_array( $params['fields'] ?? null ) ? $params['fields'] : [];
        $note = array_key_exists( 'note', $f ) ? trim( sanitize_textarea_field( (string) $f['note'] ) ) : null;
        if ( $note !== null && mb_strlen( $note ) > 2000 ) {
            return self::bad( 'The note can be up to 2,000 characters.' );
        }
        if ( $note !== null ) {
            if ( $customer['account'] ) {
                $note === '' ? delete_user_meta( $customer['user'], self::$customer_note_meta ) : update_user_meta( $customer['user'], self::$customer_note_meta, $note );
            } else {
                self::set_guest_note( $customer['email'], $note );
            }
        }

        // Details (accounts only): saved when sent.
        if ( $customer['account'] && is_array( $f['billing'] ?? null ) ) {
            $countries = WC()->countries->get_countries();
            $text      = static fn( $value, $max = 200 ) => mb_substr( trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) ), 0, $max );
            $address   = [];
            foreach ( [ 'billing', 'shipping' ] as $type ) {
                $in = is_array( $f[ $type ] ?? null ) ? $f[ $type ] : [];
                foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ] as $key ) {
                    $address[ $type ][ $key ] = $text( $in[ $key ] ?? '' );
                }
                $country = strtoupper( $address[ $type ]['country'] );
                if ( $country !== '' && ! isset( $countries[ $country ] ) ) {
                    return self::bad( 'Choose the country from the list.' );
                }
                $address[ $type ]['country'] = $country;
                $states                      = $country !== '' ? WC()->countries->get_states( $country ) : [];
                if ( $address[ $type ]['state'] !== '' && is_array( $states ) && $states && ! isset( $states[ $address[ $type ]['state'] ] ) ) {
                    return self::bad( 'Choose the state or region from the list.' );
                }
            }
            $phone = self::clean_phone( $f['phone'] ?? '' );
            if ( $phone === false ) {
                return self::bad( 'The phone number can have digits, spaces, + ( ) and - only.' );
            }
            try {
                $wc = new WC_Customer( $customer['user'] );
                $wc->set_first_name( $text( $f['first_name'] ?? '', 100 ) );
                $wc->set_last_name( $text( $f['last_name'] ?? '', 100 ) );
                $wc->set_billing_phone( $phone );
                foreach ( $address as $type => $values ) {
                    foreach ( $values as $key => $value ) {
                        $wc->{"set_{$type}_{$key}"}( $value );
                    }
                }
                $wc->save();
            } catch ( Exception $e ) {
                return self::bad( $e->getMessage() );
            }
        }
        return self::customer_full( self::find_customer( $customer['key'] ) );
    }

    /**
     * { key }: removes a customer's personal data (privacy requests): their
     * orders keep the amounts but lose names, addresses, emails and phone
     * numbers, and an account is deleted with its saved cards. Refused
     * while an order still needs shipping (processing or on hold). The
     * platform asks for the owner's password first.
     */
    private static function action_customer_delete( array $params ) {
        $customer = self::find_customer( $params['key'] ?? '' );
        if ( is_wp_error( $customer ) ) {
            return $customer;
        }
        $orders = self::customer_orders( $customer, -1 );
        foreach ( $orders as $order ) {
            if ( in_array( $order->get_status(), self::$customer_open, true ) ) {
                return self::bad( 'This customer has orders that aren\'t finished (processing or on hold). Complete or cancel them first.' );
            }
        }
        if ( ! class_exists( 'WC_Privacy_Erasers', false ) ) {
            include_once WC_ABSPATH . 'includes/class-wc-privacy-erasers.php';
        }
        foreach ( $orders as $order ) {
            WC_Privacy_Erasers::remove_order_personal_data( $order );
            if ( $order->get_customer_id() ) {
                $order->set_customer_id( 0 );
                $order->save();
            }
        }
        if ( $customer['account'] ) {
            WC_Privacy_Erasers::customer_data_eraser( $customer['email'], 1 );
            foreach ( WC_Payment_Tokens::get_customer_tokens( $customer['user'] ) as $token ) {
                WC_Payment_Tokens::delete( $token->get_id() );
            }
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $customer['user'] );
        }
        self::set_guest_note( $customer['email'], '' );
        return [ 'deleted' => true, 'orders' => count( $orders ) ];
    }

    /* ---------------- helpers ---------------- */

    /**
     * Every customer: [ key => { key, account, user, name, email, phone,
     * orders, spent, first, last, registered } ] (dates as timestamps).
     */
    private static function customer_groups() {
        $groups = [];
        $add    = static function ( $key, array $row ) use ( &$groups ) {
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = $row;
                return;
            }
            $g               = &$groups[ $key ];
            $g['orders']    += $row['orders'];
            $g['paid']      += $row['paid'];
            $g['spent']     += $row['spent'];
            $g['first']      = $g['first'] && $row['first'] ? min( $g['first'], $row['first'] ) : ( $g['first'] ?: $row['first'] );
            $g['last']       = max( $g['last'], $row['last'] );
            $g['last_order'] = max( $g['last_order'], $row['last_order'] );
        };

        // Accounts by email, to fold their guest orders in.
        $accounts = [];
        $users    = get_users( [ 'role__in' => self::$customer_roles, 'fields' => [ 'ID', 'user_email', 'user_registered' ], 'number' => 50000 ] );
        foreach ( $users as $u ) {
            $accounts[ mb_strtolower( $u->user_email ) ] = (int) $u->ID;
        }
        $is_customer = array_flip( array_values( $accounts ) );

        foreach ( self::customer_order_totals() as $row ) {
            $user  = (int) $row['user_id'];
            $email = mb_strtolower( trim( (string) $row['email'] ) );
            if ( $email === 'deleted@site.invalid' ) {
                continue; // personal data removed
            }
            if ( ! isset( $is_customer[ $user ] ) ) {
                $user = $accounts[ $email ] ?? 0; // staff, a deleted account, or a guest
            }
            if ( ! $user && $email === '' ) {
                continue;
            }
            $add( $user ? 'u' . $user : 'e' . $email, [
                'user'       => $user,
                'email'      => $email,
                'orders'     => (int) $row['orders'],
                'paid'       => (int) $row['paid'],
                'spent'      => (float) $row['spent'],
                'first'      => $row['first'] ? (int) strtotime( $row['first'] . ' UTC' ) : 0,
                'last'       => $row['last'] ? (int) strtotime( $row['last'] . ' UTC' ) : 0,
                'last_order' => (int) $row['last_order'],
            ] );
        }
        // Accounts that haven't ordered yet.
        foreach ( $users as $u ) {
            if ( ! isset( $groups[ 'u' . $u->ID ] ) ) {
                $groups[ 'u' . $u->ID ] = [ 'user' => (int) $u->ID, 'email' => '', 'orders' => 0, 'paid' => 0, 'spent' => 0.0, 'first' => 0, 'last' => 0, 'last_order' => 0 ];
            }
        }

        // Names and phones: accounts from their profile, guests from their latest order.
        $registered = [];
        foreach ( $users as $u ) {
            $registered[ (int) $u->ID ] = [ mb_strtolower( $u->user_email ), (int) strtotime( $u->user_registered . ' UTC' ) ];
        }
        update_meta_cache( 'user', array_keys( $registered ) );
        $guests = self::order_contacts( array_column( array_filter( $groups, static fn( $g ) => ! $g['user'] ), 'last_order' ) );

        $out = [];
        foreach ( $groups as $g ) {
            if ( $g['user'] ) {
                $id    = $g['user'];
                $first = (string) ( get_user_meta( $id, 'first_name', true ) ?: get_user_meta( $id, 'billing_first_name', true ) );
                $last  = (string) ( get_user_meta( $id, 'last_name', true ) ?: get_user_meta( $id, 'billing_last_name', true ) );
                $key   = 'u' . $id;
                $row   = [
                    'key'        => $key,
                    'account'    => true,
                    'name'       => trim( $first . ' ' . $last ),
                    'email'      => $registered[ $id ][0] ?? $g['email'],
                    'phone'      => (string) get_user_meta( $id, 'billing_phone', true ),
                    'registered' => $registered[ $id ][1] ?? 0,
                ];
            } else {
                $contact = $guests[ $g['last_order'] ] ?? [ 'name' => '', 'phone' => '' ];
                $key     = 'g' . $g['last_order'];
                $row     = [ 'key' => $key, 'account' => false, 'name' => $contact['name'], 'email' => $g['email'], 'phone' => $contact['phone'], 'registered' => 0 ];
            }
            $out[ $key ] = $row + [
                'orders' => $g['orders'],
                'paid'   => $g['paid'],
                'spent'  => round( $g['spent'], wc_get_price_decimals() ),
                'first'  => $g['first'],
                // Accounts without orders sort by when they joined.
                'last'   => $g['last'] ?: ( $row['registered'] ?? 0 ),
            ];
        }
        return $out;
    }

    /**
     * Order totals by customer id and email, in one query, for either
     * order storage (custom order tables or posts): user_id, email, orders,
     * spent (paid orders minus refunds), first, last (GMT), last_order.
     */
    private static function customer_order_totals() {
        global $wpdb;
        $paid = "'" . implode( "','", array_map( static fn( $s ) => esc_sql( 'wc-' . $s ), wc_get_is_paid_statuses() ) ) . "'";
        $skip = "'" . implode( "','", array_map( 'esc_sql', self::$customer_skip ) ) . "'";
        if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
            $t = OrderUtil::get_table_for_orders();
            return (array) $wpdb->get_results(
                "SELECT o.customer_id AS user_id, LOWER(o.billing_email) AS email, COUNT(*) AS orders, SUM(CASE WHEN o.status IN ($paid) THEN 1 ELSE 0 END) AS paid,
                    SUM(CASE WHEN o.status IN ($paid) THEN o.total_amount + COALESCE(r.refunded, 0) ELSE 0 END) AS spent,
                    MIN(o.date_created_gmt) AS first, MAX(o.date_created_gmt) AS last, MAX(o.id) AS last_order
                FROM $t o
                LEFT JOIN (SELECT parent_order_id, SUM(total_amount) AS refunded FROM $t WHERE type = 'shop_order_refund' GROUP BY parent_order_id) r ON r.parent_order_id = o.id
                WHERE o.type = 'shop_order' AND o.status NOT IN ($skip)
                GROUP BY o.customer_id, LOWER(o.billing_email)",
                ARRAY_A
            );
        }
        return (array) $wpdb->get_results(
            "SELECT CAST(COALESCE(cu.meta_value, 0) AS UNSIGNED) AS user_id, LOWER(COALESCE(em.meta_value, '')) AS email, COUNT(*) AS orders, SUM(CASE WHEN p.post_status IN ($paid) THEN 1 ELSE 0 END) AS paid,
                SUM(CASE WHEN p.post_status IN ($paid) THEN CAST(COALESCE(tot.meta_value, 0) AS DECIMAL(19,4)) + COALESCE(r.refunded, 0) ELSE 0 END) AS spent,
                MIN(p.post_date_gmt) AS first, MAX(p.post_date_gmt) AS last, MAX(p.ID) AS last_order
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} cu ON cu.post_id = p.ID AND cu.meta_key = '_customer_user'
            LEFT JOIN {$wpdb->postmeta} em ON em.post_id = p.ID AND em.meta_key = '_billing_email'
            LEFT JOIN {$wpdb->postmeta} tot ON tot.post_id = p.ID AND tot.meta_key = '_order_total'
            LEFT JOIN (
                SELECT rp.post_parent, SUM(CAST(rm.meta_value AS DECIMAL(19,4))) AS refunded
                FROM {$wpdb->posts} rp JOIN {$wpdb->postmeta} rm ON rm.post_id = rp.ID AND rm.meta_key = '_order_total'
                WHERE rp.post_type = 'shop_order_refund' GROUP BY rp.post_parent
            ) r ON r.post_parent = p.ID
            WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ($skip)
            GROUP BY user_id, email",
            ARRAY_A
        );
    }

    /** [ order id => { name, phone } ] from the billing details of these orders. */
    private static function order_contacts( array $ids ) {
        global $wpdb;
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
        if ( ! $ids ) {
            return [];
        }
        $in  = implode( ',', $ids );
        $out = [];
        if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
            $t = $wpdb->prefix . 'wc_order_addresses';
            foreach ( (array) $wpdb->get_results( "SELECT order_id, first_name, last_name, phone FROM $t WHERE address_type = 'billing' AND order_id IN ($in)", ARRAY_A ) as $r ) {
                $out[ (int) $r['order_id'] ] = [ 'name' => trim( $r['first_name'] . ' ' . $r['last_name'] ), 'phone' => (string) $r['phone'] ];
            }
            return $out;
        }
        $meta = [];
        foreach ( (array) $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ('_billing_first_name', '_billing_last_name', '_billing_phone')", ARRAY_A ) as $r ) {
            $meta[ (int) $r['post_id'] ][ $r['meta_key'] ] = (string) $r['meta_value'];
        }
        foreach ( $meta as $id => $m ) {
            $out[ $id ] = [ 'name' => trim( ( $m['_billing_first_name'] ?? '' ) . ' ' . ( $m['_billing_last_name'] ?? '' ) ), 'phone' => $m['_billing_phone'] ?? '' ];
        }
        return $out;
    }

    /**
     * A customer from a key: { key, account, user, email }. A guest key whose
     * email now belongs to an account gives that account.
     */
    private static function find_customer( $key ) {
        $key  = (string) $key;
        $gone = self::error( 'qwoo_dashboard_not_found', 'This customer doesn\'t exist any more.', 404 );
        if ( preg_match( '/^u(\d{1,10})$/', $key, $m ) ) {
            $user = get_userdata( (int) $m[1] );
            if ( ! $user || ! array_intersect( self::$customer_roles, (array) $user->roles ) ) {
                return $gone;
            }
            return [ 'key' => 'u' . $user->ID, 'account' => true, 'user' => (int) $user->ID, 'email' => mb_strtolower( $user->user_email ) ];
        }
        if ( preg_match( '/^g(\d{1,10})$/', $key, $m ) ) {
            $order = wc_get_order( (int) $m[1] );
            $email = $order && $order->get_type() === 'shop_order' ? mb_strtolower( trim( $order->get_billing_email() ) ) : '';
            if ( $email === '' || $email === 'deleted@site.invalid' ) {
                return $gone;
            }
            $user = get_user_by( 'email', $email );
            if ( $user && array_intersect( self::$customer_roles, (array) $user->roles ) ) {
                return [ 'key' => 'u' . $user->ID, 'account' => true, 'user' => (int) $user->ID, 'email' => $email ];
            }
            return [ 'key' => $key, 'account' => false, 'user' => 0, 'email' => $email ];
        }
        return $gone;
    }

    /** A customer's orders, newest first: an account's own, plus any placed as a guest with its email. */
    private static function customer_orders( array $customer, $limit = 100 ) {
        $args   = [ 'type' => 'shop_order', 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'status' => array_keys( wc_get_order_statuses() ) ];
        $orders = [];
        if ( $customer['account'] ) {
            foreach ( wc_get_orders( $args + [ 'customer_id' => $customer['user'] ] ) as $o ) {
                $orders[ $o->get_id() ] = $o;
            }
        }
        foreach ( wc_get_orders( $args + [ 'billing_email' => $customer['email'], 'customer_id' => 0 ] ) as $o ) {
            $orders[ $o->get_id() ] = $o;
        }
        uasort( $orders, static fn( $a, $b ) => $b->get_id() <=> $a->get_id() );
        return array_values( $limit > 0 ? array_slice( $orders, 0, $limit, true ) : $orders );
    }

    private static function customer_full( array $customer ) {
        $orders = self::customer_orders( $customer );
        $groups = self::customer_groups();
        $stats  = $groups[ $customer['key'] ] ?? null;
        if ( ! $stats && ! $customer['account'] ) {
            // Any guest key gives the same customer: find the row by email.
            foreach ( $groups as $g ) {
                if ( ! $g['account'] && $g['email'] === $customer['email'] ) {
                    $stats = $g;
                    break;
                }
            }
        }
        $address = static function ( array $fields ) {
            $text = WC()->countries->get_formatted_address( $fields, "\n" );
            return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES ) );
        };
        $fields = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ];

        if ( $customer['account'] ) {
            $wc       = new WC_Customer( $customer['user'] );
            $billing  = [];
            $shipping = [];
            foreach ( $fields as $f ) {
                $billing[ $f ]  = (string) $wc->{"get_billing_$f"}();
                $shipping[ $f ] = (string) $wc->{"get_shipping_$f"}();
            }
            $first = $wc->get_first_name() ?: $wc->get_billing_first_name();
            $last  = $wc->get_last_name() ?: $wc->get_billing_last_name();
            $data  = [
                'first_name' => $wc->get_first_name(),
                'last_name'  => $wc->get_last_name(),
                'name'       => trim( $first . ' ' . $last ),
                'phone'      => $wc->get_billing_phone(),
                'registered' => $wc->get_date_created() ? $wc->get_date_created()->getTimestamp() : 0,
                'note'       => (string) get_user_meta( $customer['user'], self::$customer_note_meta, true ),
                'countries'  => WC()->countries->get_countries(),
            ];
        } else {
            // A guest: the latest order's details.
            $latest   = $orders[0] ?? null;
            $billing  = $latest ? $latest->get_address( 'billing' ) : [];
            $shipping = $latest && $latest->has_shipping_address() ? $latest->get_address( 'shipping' ) : [];
            $data     = [
                'name'       => $latest ? trim( $latest->get_billing_first_name() . ' ' . $latest->get_billing_last_name() ) : '',
                'phone'      => $latest ? $latest->get_billing_phone() : '',
                'registered' => 0,
                'note'       => self::guest_note( $customer['email'] ),
            ];
        }
        $open = count( array_filter( $orders, static fn( $o ) => in_array( $o->get_status(), self::$customer_open, true ) ) );
        return $data + [
            'key'           => $customer['key'],
            'account'       => $customer['account'],
            'email'         => $customer['email'],
            'billing'       => array_intersect_key( $billing, array_flip( $fields ) ) + array_fill_keys( $fields, '' ),
            'shipping'      => array_intersect_key( $shipping, array_flip( $fields ) ) + array_fill_keys( $fields, '' ),
            'billing_text'  => $billing ? $address( $billing ) : '',
            'shipping_text' => $shipping && array_filter( $shipping ) ? $address( $shipping ) : '',
            'stats'         => [
                'orders' => $stats['orders'] ?? 0,
                'paid'   => $stats['paid'] ?? 0, // paid orders, for the average
                'spent'  => $stats['spent'] ?? 0,
                'first'  => $stats['first'] ?? 0,
                'last'   => $stats['last'] ?? 0,
            ],
            'orders'        => array_map( [ __CLASS__, 'order_summary' ], $orders ),
            'open_orders'   => $open,
            'currency'      => get_woocommerce_currency(),
        ];
    }

    /** Digits, spaces, + ( ) - and . only; false otherwise. */
    private static function clean_phone( $phone ) {
        $phone = trim( is_scalar( $phone ) ? (string) $phone : '' );
        if ( $phone === '' ) {
            return '';
        }
        return preg_match( '/^[\d\s+().\-]{3,30}$/', $phone ) ? $phone : false;
    }

    private static function guest_note( $email ) {
        $notes = get_option( self::$customer_notes_key, [] );
        return is_array( $notes ) ? (string) ( $notes[ md5( $email ) ] ?? '' ) : '';
    }

    private static function set_guest_note( $email, $note ) {
        $notes = get_option( self::$customer_notes_key, [] );
        $notes = is_array( $notes ) ? $notes : [];
        if ( $note === '' ) {
            unset( $notes[ md5( $email ) ] );
        } else {
            $notes[ md5( $email ) ] = $note;
            $notes                  = array_slice( $notes, -5000, null, true );
        }
        update_option( self::$customer_notes_key, $notes, false );
    }
}

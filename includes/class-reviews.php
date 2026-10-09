<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Product reviews for the storefront.
 *
 * - The owner turns reviews on or off (dashboard → Reviews). Off by default.
 * - Only verified buyers can review: a signed-in customer who bought the
 *   product, or anyone with a link from the review-request email (signed
 *   with the store's salt, tied to that order, product and email).
 * - Every review waits for the owner's approval (comment_approved 0); the
 *   owner gets an email (optional) and sees a count in the dashboard.
 * - The owner can reply publicly (comment meta, shown under the review).
 * - Review-request emails go out a few days after an order is completed,
 *   once per order. No cron: the storefront's API requests check every 15
 *   minutes and send after the response is out.
 *
 * Reviews are WooCommerce's own (comment type "review", meta rating and
 * verified), so product ratings stay in WooCommerce's usual fields. They can
 * only be added here: WordPress's comment form and comment REST routes are
 * closed for products.
 *
 *   GET  qwoo/v1/reviews?product=&page=      approved reviews, average, and
 *                                            whether the signed-in visitor can review
 *   POST qwoo/v1/reviews                     { product_id, rating, title, text, order?, token? }
 *   GET  qwoo/v1/reviews/request?o=&p=&t=    the product behind a review-request link
 */
class Qwoo_Reviews {

    const OPTION       = 'qwoo_reviews';
    const TITLE_META   = '_qwoo_review_title';
    const REPLY_META   = '_qwoo_review_reply';
    const QUEUE_OPTION = 'qwoo_review_requests'; // [ order id => when to ask ]
    const QUEUE_MAX    = 5000;
    const SENT_META    = '_qwoo_review_sent';    // on orders: when it was asked
    const PER_PAGE     = 10;
    const MAX_TITLE    = 100;
    const MAX_TEXT     = 3000;
    const LINK_DAYS    = 180;                    // how long a review-request link works
    const CHECK_KEY    = 'qwoo_review_requests_checked';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'maybe_send_requests' ] );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'schedule_request' ] );
        add_filter( 'preprocess_comment', [ __CLASS__, 'block_comment' ] );
        add_filter( 'rest_pre_insert_comment', [ __CLASS__, 'block_rest_comment' ], 10, 2 );
        add_filter( 'comments_open', [ __CLASS__, 'comments_open' ], 20, 2 );
    }

    /* ---------------- settings ---------------- */

    /** { enabled, notify (email the owner), request (ask buyers), request_days }. */
    public static function settings() {
        $s = get_option( self::OPTION, [] );
        $s = is_array( $s ) ? $s : [];
        return [
            'enabled'      => ! empty( $s['enabled'] ),
            'notify'       => ! array_key_exists( 'notify', $s ) || ! empty( $s['notify'] ),
            'request'      => ! empty( $s['request'] ),
            'request_days' => max( 0, min( 60, (int) ( $s['request_days'] ?? 7 ) ) ), // 0: right away
        ];
    }

    public static function save_settings( array $in ) {
        $s = [
            'enabled'      => ! empty( $in['enabled'] ),
            'notify'       => ! empty( $in['notify'] ),
            'request'      => ! empty( $in['request'] ),
            'request_days' => max( 0, min( 60, (int) ( $in['request_days'] ?? 7 ) ) ),
        ];
        update_option( self::OPTION, $s, true );
        // WooCommerce's own switches agree: star ratings, required, verified owners only.
        update_option( 'woocommerce_enable_reviews', $s['enabled'] ? 'yes' : 'no' );
        update_option( 'woocommerce_enable_review_rating', 'yes' );
        update_option( 'woocommerce_review_rating_required', 'yes' );
        update_option( 'woocommerce_review_rating_verification_required', 'yes' );
        update_option( 'woocommerce_review_rating_verification_label', 'yes' );
        return self::settings();
    }

    /** Where owner emails go: the new-order recipient from Settings, else the admin email. */
    public static function owner_email() {
        $s  = get_option( 'woocommerce_new_order_settings', [] );
        $to = trim( explode( ',', (string) ( is_array( $s ) ? ( $s['recipient'] ?? '' ) : '' ) )[0] );
        return is_email( $to ) ? $to : (string) get_option( 'admin_email' );
    }

    /* ---------------- reviews only through here ---------------- */

    public static function block_comment( $data ) {
        if ( get_post_type( (int) ( $data['comment_post_ID'] ?? 0 ) ) === 'product' ) {
            wp_die( 'Reviews are written on the store\'s product pages.', 403 );
        }
        return $data;
    }

    public static function block_rest_comment( $prepared, $request ) {
        if ( get_post_type( (int) ( $prepared['comment_post_ID'] ?? 0 ) ) === 'product' ) {
            return new WP_Error( 'qwoo_reviews_closed', 'Reviews are written on the store\'s product pages.', [ 'status' => 403 ] );
        }
        return $prepared;
    }

    public static function comments_open( $open, $post_id ) {
        return get_post_type( $post_id ) === 'product' ? false : $open;
    }

    /* ---------------- storefront routes ---------------- */

    public static function routes() {
        register_rest_route( 'qwoo/v1', '/reviews', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'rest_list' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'rest_submit' ],
                // Rate limits are counted in the callbacks: WordPress also runs permission
                // callbacks to build every response's Allow header, which would count GETs.
                'permission_callback' => '__return_true',
            ],
        ] );
        register_rest_route( 'qwoo/v1', '/reviews/request', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'rest_request' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /** { enabled, average, count, stars: { 5: n … 1: n }, items, page, pages, can }. */
    public static function rest_list( WP_REST_Request $r ) {
        $product = self::find_product( $r->get_param( 'product' ) );
        if ( is_wp_error( $product ) ) {
            return $product;
        }
        if ( ! self::settings()['enabled'] ) {
            return rest_ensure_response( [ 'enabled' => false ] );
        }
        $page  = max( 1, min( 500, (int) $r->get_param( 'page' ) ) );
        $args  = [ 'post_id' => $product->get_id(), 'status' => 'approve', 'type' => 'review' ];
        $total = (int) get_comments( $args + [ 'count' => true ] );
        $items = get_comments( $args + [ 'number' => self::PER_PAGE, 'offset' => ( $page - 1 ) * self::PER_PAGE, 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ] );
        $stars = array_fill_keys( [ 5, 4, 3, 2, 1 ], 0 );
        foreach ( (array) $product->get_rating_counts() as $rating => $n ) {
            if ( isset( $stars[ (int) $rating ] ) ) {
                $stars[ (int) $rating ] = (int) $n;
            }
        }
        return rest_ensure_response( [
            'enabled' => true,
            'average' => round( (float) $product->get_average_rating(), 2 ),
            'count'   => $total,
            'stars'   => $stars,
            'items'   => array_map( [ __CLASS__, 'public_item' ], $items ),
            'page'    => $page,
            'pages'   => (int) ceil( $total / self::PER_PAGE ),
            'can'     => self::can_review( $product->get_id() ),
        ] );
    }

    /** A review as the storefront shows it: the author as "Dana L.", never the email. */
    public static function public_item( WP_Comment $c ) {
        return [
            'id'       => (int) $c->comment_ID,
            'rating'   => (int) get_comment_meta( $c->comment_ID, 'rating', true ),
            'title'    => (string) get_comment_meta( $c->comment_ID, self::TITLE_META, true ),
            'text'     => (string) $c->comment_content,
            'author'   => self::short_name( $c->comment_author ),
            'date'     => (int) strtotime( $c->comment_date_gmt . ' UTC' ),
            'verified' => (bool) get_comment_meta( $c->comment_ID, 'verified', true ),
            'reply'    => self::reply_of( (int) $c->comment_ID ),
        ];
    }

    /** { text, date } or null. */
    public static function reply_of( $comment_id ) {
        $reply = get_comment_meta( $comment_id, self::REPLY_META, true );
        return is_array( $reply ) && ( $reply['text'] ?? '' ) !== '' ? [ 'text' => (string) $reply['text'], 'date' => (int) ( $reply['date'] ?? 0 ) ] : null;
    }

    /** "Dana Levi" → "Dana L."; empty → "Customer". */
    private static function short_name( $name ) {
        $parts = preg_split( '/\s+/u', trim( (string) $name ), -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $parts ) {
            return 'Customer';
        }
        return count( $parts ) > 1 ? $parts[0] . ' ' . mb_strtoupper( mb_substr( end( $parts ), 0, 1 ) ) . '.' : $parts[0];
    }

    /** What the signed-in visitor can do: ok | login | not_bought | reviewed. */
    private static function can_review( $product_id ) {
        $user = function_exists( 'qwoo_get_authenticated_user' ) ? qwoo_get_authenticated_user() : false;
        if ( ! $user ) {
            return 'login';
        }
        if ( self::already_reviewed( $product_id, (int) $user->ID, $user->user_email ) ) {
            return 'reviewed';
        }
        return wc_customer_bought_product( $user->user_email, $user->ID, $product_id ) ? 'ok' : 'not_bought';
    }

    /** Any review of this product by this person (waiting, approved or hidden), so each person reviews once. */
    private static function already_reviewed( $product_id, $user_id, $email ) {
        $base = [ 'post_id' => $product_id, 'type' => 'review', 'status' => [ 'hold', 'approve' ], 'count' => true ];
        if ( $user_id && get_comments( $base + [ 'user_id' => $user_id ] ) ) {
            return true;
        }
        return $email !== '' && (bool) get_comments( $base + [ 'author_email' => $email ] );
    }

    /**
     * { product_id, rating (1–5), title (optional), text, and for a link
     * from the review-request email order + token }.
     */
    public static function rest_submit( WP_REST_Request $r ) {
        if ( ! self::settings()['enabled'] ) {
            return new WP_Error( 'qwoo_reviews_off', 'Reviews are turned off in this store.', [ 'status' => 403 ] );
        }
        $limited = qwoo_rate_limit_check( 'review', 10, HOUR_IN_SECONDS );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }
        $product = self::find_product( $r->get_param( 'product_id' ) );
        if ( is_wp_error( $product ) ) {
            return $product;
        }
        $rating = (int) $r->get_param( 'rating' );
        if ( $rating < 1 || $rating > 5 ) {
            return self::bad( 'Choose from 1 to 5 stars.' );
        }
        $title = trim( sanitize_text_field( (string) $r->get_param( 'title' ) ) );
        $text  = trim( sanitize_textarea_field( (string) $r->get_param( 'text' ) ) );
        if ( mb_strlen( $title ) > self::MAX_TITLE ) {
            return self::bad( 'The title can be up to ' . self::MAX_TITLE . ' characters.' );
        }
        if ( mb_strlen( $text ) < 3 || mb_strlen( $text ) > self::MAX_TEXT ) {
            return self::bad( 'Write a few words (up to ' . number_format( self::MAX_TEXT ) . ' characters).' );
        }

        $token = (string) $r->get_param( 'token' );
        if ( $token !== '' ) {
            $order = self::order_from_link( $r->get_param( 'order' ), $product->get_id(), $token );
            if ( is_wp_error( $order ) ) {
                return $order;
            }
            $user_id = (int) $order->get_customer_id();
            $email   = mb_strtolower( $order->get_billing_email() );
            $name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        } else {
            // Signed in through the REST nonce (like the wishlist), so another site can't post as them.
            $user = wp_get_current_user();
            if ( ! $user || ! $user->ID ) {
                return new WP_Error( 'qwoo_reviews_login', 'Sign in to write a review.', [ 'status' => 401 ] );
            }
            if ( ! wc_customer_bought_product( $user->user_email, $user->ID, $product->get_id() ) ) {
                return new WP_Error( 'qwoo_reviews_buyers', 'Only customers who bought this product can review it.', [ 'status' => 403 ] );
            }
            $user_id = (int) $user->ID;
            $email   = mb_strtolower( $user->user_email );
            $name    = trim( $user->first_name . ' ' . $user->last_name ) ?: trim( get_user_meta( $user->ID, 'billing_first_name', true ) . ' ' . get_user_meta( $user->ID, 'billing_last_name', true ) );
        }
        if ( self::already_reviewed( $product->get_id(), $user_id, $email ) ) {
            return new WP_Error( 'qwoo_reviews_done', 'You\'ve already reviewed this product. Thank you!', [ 'status' => 409 ] );
        }

        $id = wp_insert_comment( [
            'comment_post_ID'      => $product->get_id(),
            'comment_author'       => mb_substr( $name ?: 'Customer', 0, 100 ),
            'comment_author_email' => $email,
            'comment_author_url'   => '',
            'comment_content'      => $text,
            'comment_type'         => 'review',
            'comment_approved'     => 0, // waits for the owner
            'user_id'              => $user_id,
            'comment_author_IP'    => '',
            'comment_agent'        => '',
        ] );
        if ( ! $id ) {
            return new WP_Error( 'qwoo_reviews_failed', 'The review couldn\'t be saved. Try again.', [ 'status' => 500 ] );
        }
        add_comment_meta( $id, 'rating', $rating, true );
        add_comment_meta( $id, 'verified', 1, true );
        if ( $title !== '' ) {
            add_comment_meta( $id, self::TITLE_META, $title, true );
        }
        if ( self::settings()['notify'] ) {
            $product_name = $product->get_name();
            self::after_response( static function () use ( $product_name, $rating, $title, $text, $name ) {
                self::email_owner( $product_name, $rating, $title, $text, $name );
            } );
        }
        return rest_ensure_response( [ 'ok' => true, 'message' => 'Thanks! Your review will show once the store approves it.' ] );
    }

    /** The product behind a review-request link: { enabled, product { id, name, slug, image }, name, reviewed }. */
    public static function rest_request( WP_REST_Request $r ) {
        $limited = qwoo_rate_limit_check( 'review_link', 60, HOUR_IN_SECONDS );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }
        $product = self::find_product( $r->get_param( 'p' ) );
        if ( is_wp_error( $product ) ) {
            return $product;
        }
        $order = self::order_from_link( $r->get_param( 'o' ), $product->get_id(), (string) $r->get_param( 't' ) );
        if ( is_wp_error( $order ) ) {
            return $order;
        }
        $image = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
        return rest_ensure_response( [
            'enabled'  => self::settings()['enabled'],
            'product'  => [ 'id' => $product->get_id(), 'name' => html_entity_decode( $product->get_name(), ENT_QUOTES ), 'slug' => $product->get_slug(), 'image' => $image ?: '' ],
            'name'     => $order->get_billing_first_name(),
            'reviewed' => self::already_reviewed( $product->get_id(), (int) $order->get_customer_id(), mb_strtolower( $order->get_billing_email() ) ),
        ] );
    }

    /* ---------------- links in the review-request email ---------------- */

    public static function link_token( WC_Order $order, $product_id ) {
        $data = 'qwoo-review|' . $order->get_id() . '|' . (int) $product_id . '|' . mb_strtolower( $order->get_billing_email() );
        return substr( hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ), 0, 32 );
    }

    /** The completed order a link belongs to, if the link is genuine, recent and for a product in it. */
    private static function order_from_link( $order_id, $product_id, $token ) {
        $bad   = new WP_Error( 'qwoo_reviews_link', 'This review link isn\'t valid any more.', [ 'status' => 403 ] );
        $order = wc_get_order( absint( $order_id ) );
        if ( ! $order || $order->get_type() !== 'shop_order' || $order->get_status() !== 'completed' || ! is_email( $order->get_billing_email() ) ) {
            return $bad;
        }
        if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $token ) || ! hash_equals( self::link_token( $order, $product_id ), (string) $token ) ) {
            return $bad;
        }
        $done = $order->get_date_completed() ?: $order->get_date_created();
        if ( $done && $done->getTimestamp() < time() - self::LINK_DAYS * DAY_IN_SECONDS ) {
            return $bad;
        }
        foreach ( $order->get_items() as $item ) {
            if ( (int) $item->get_product_id() === (int) $product_id ) {
                return $order;
            }
        }
        return $bad;
    }

    /* ---------------- review-request emails ---------------- */

    /**
     * Requests waiting to go out: [ order id => when ]. An option rather
     * than an order-meta query, which works the same for both order
     * storages (and costs no query when nothing is due).
     */
    public static function queue() {
        $queue = get_option( self::QUEUE_OPTION, [] );
        return is_array( $queue ) ? $queue : [];
    }

    /**
     * An order is completed: ask for a review after the owner's delay (once
     * per order). A delay of 0 days sends it right away, after the response
     * of the request that completed the order.
     */
    public static function schedule_request( $order_id ) {
        $s = self::settings();
        if ( ! $s['enabled'] || ! $s['request'] ) {
            return;
        }
        $order = wc_get_order( $order_id );
        $queue = self::queue();
        if ( ! $order || isset( $queue[ (int) $order_id ] ) || $order->get_meta( self::SENT_META ) || ! is_email( $order->get_billing_email() ) ) {
            return;
        }
        if ( ! $s['request_days'] ) {
            $id = (int) $order_id;
            self::after_response( static function () use ( $id ) {
                self::send_request( $id );
            } );
            return;
        }
        $queue[ (int) $order_id ] = time() + $s['request_days'] * DAY_IN_SECONDS;
        // A very busy store: the oldest waiting requests make room.
        update_option( self::QUEUE_OPTION, array_slice( $queue, -self::QUEUE_MAX, null, true ), false );
    }

    /** Every 15 minutes at most: up to 5 due requests, sent after the API response is out. */
    public static function maybe_send_requests() {
        if ( get_transient( self::CHECK_KEY ) ) {
            return;
        }
        set_transient( self::CHECK_KEY, 1, 15 * MINUTE_IN_SECONDS );
        $queue = self::queue();
        $due   = array_keys( array_filter( $queue, static fn( $when ) => (int) $when <= time() ) );
        if ( ! $due ) {
            return;
        }
        $s = self::settings();
        if ( ! $s['enabled'] || ! $s['request'] ) {
            // Turned off: what's waiting is dropped, not sent later.
            update_option( self::QUEUE_OPTION, [], false );
            return;
        }
        $ids = array_slice( $due, 0, 5 );
        // Out of the queue before sending, so a failure never sends twice.
        update_option( self::QUEUE_OPTION, array_diff_key( $queue, array_flip( $ids ) ), false );
        self::after_response( static function () use ( $ids ) {
            foreach ( $ids as $id ) {
                self::send_request( (int) $id );
            }
        } );
    }

    /** Sends one order's review request (or drops it when nothing is left to review). Returns whether it was sent. */
    public static function send_request( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( self::SENT_META ) ) {
            return false;
        }
        // Marked first, so a failure never sends it twice.
        $order->update_meta_data( self::SENT_META, time() );
        $order->save_meta_data();
        $email = $order->get_billing_email();
        $front = function_exists( 'qwoo_email_frontend_url' ) ? qwoo_email_frontend_url( $order ) : '';
        if ( $order->get_status() !== 'completed' || ! is_email( $email ) || $front === '' || ! self::settings()['enabled'] ) {
            return false;
        }

        $links = [];
        foreach ( $order->get_items() as $item ) {
            $id      = (int) $item->get_product_id();
            $product = $id ? wc_get_product( $id ) : null;
            if ( ! $product || isset( $links[ $id ] ) || $product->get_status() !== 'publish' || self::already_reviewed( $id, (int) $order->get_customer_id(), mb_strtolower( $email ) ) ) {
                continue;
            }
            $links[ $id ] = [
                'name' => html_entity_decode( $product->get_name(), ENT_QUOTES ),
                'url'  => rtrim( $front, '/' ) . '/review?' . http_build_query( [ 'o' => $order->get_id(), 'p' => $id, 't' => self::link_token( $order, $id ) ] ),
            ];
        }
        if ( ! $links ) {
            return false;
        }

        $store = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $first = $order->get_billing_first_name();
        $html  = '<p>' . esc_html( $first !== '' ? "Hi $first," : 'Hi,' ) . '</p>';
        $html .= '<p>' . esc_html( "Thank you for your order from $store. We'd love to hear what you think. It only takes a minute:" ) . '</p>';
        foreach ( $links as $link ) {
            $html .= '<p><a href="' . esc_url( $link['url'] ) . '" style="display:inline-block;padding:10px 18px;border-radius:8px;background:#1d1b2e;color:#ffffff;text-decoration:none;font-weight:bold;">'
                . esc_html( 'Review ' . $link['name'] ) . '</a></p>';
        }
        $html .= '<p style="color:#888888;font-size:12px;">' . esc_html( 'You\'re getting this one-time email because you ordered from us.' ) . '</p>';

        $mailer = WC()->mailer();
        return (bool) $mailer->send( $email, sprintf( 'How was your order from %s?', $store ), $mailer->wrap_message( 'How was your order?', $html ) );
    }

    /* ---------------- helpers ---------------- */

    private static function email_owner( $product_name, $rating, $title, $text, $name ) {
        $platform = get_option( 'qwoo_platform_connection', [] );
        $platform = is_array( $platform ) ? (string) ( $platform['platform_url'] ?? '' ) : '';
        $stars    = str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating );
        $body     = sprintf( "%s reviewed %s: %s\n\n", $name ?: 'A customer', html_entity_decode( $product_name, ENT_QUOTES ), $stars )
            . ( $title !== '' ? $title . "\n" : '' ) . $text . "\n\n"
            . ( $platform !== '' ? 'Approve or hide it in your dashboard: ' . rtrim( $platform, '/' ) . '/app/#/reviews' : 'Approve or hide it in your dashboard.' )
            . "\n\nIt shows on your store only after you approve it.";
        wp_mail( self::owner_email(), sprintf( 'New review waiting for approval: %s', html_entity_decode( $product_name, ENT_QUOTES ) ), $body );
    }

    /** Runs $job after the response is sent, when the server allows it (else at the end of the request). */
    private static function after_response( callable $job ) {
        add_action( 'shutdown', static function () use ( $job ) {
            if ( function_exists( 'fastcgi_finish_request' ) ) {
                fastcgi_finish_request();
            } elseif ( function_exists( 'litespeed_finish_request' ) ) {
                litespeed_finish_request();
            }
            try {
                $job();
            } catch ( Throwable $e ) {
                error_log( 'Qwoo reviews: ' . $e->getMessage() );
            }
        } );
    }

    private static function find_product( $id ) {
        $product = wc_get_product( absint( $id ) );
        if ( ! $product || $product->get_status() !== 'publish' || $product->is_type( 'variation' ) ) {
            return new WP_Error( 'qwoo_reviews_product', 'This product isn\'t available.', [ 'status' => 404 ] );
        }
        return $product;
    }

    private static function bad( $message ) {
        return new WP_Error( 'qwoo_reviews_invalid', $message, [ 'status' => 400 ] );
    }
}

Qwoo_Reviews::init();

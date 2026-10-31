<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tells the platform about things the owner wants on their phone: a new
 * order, a new review waiting for approval, a product running low or out of
 * stock. The platform sends them to the owner's devices (QP_Owner_Push, the
 * installable dashboard); WooCommerce's emails go out as before.
 *
 * Events are collected during the request and sent in one signed call
 * (POST /stores/{id}/owner-alert, the publish key) after the answer went
 * out, so checkout never waits for it. A failed call is only logged.
 *
 *   new order   the first time an order is processing, on hold or completed
 *               (paid card orders, cash on delivery, bank transfer); once per
 *               order (meta _qwoo_owner_alerted)
 *   review      a shopper's review saved (Qwoo_Reviews::rest_submit)
 *   low stock   WooCommerce's own low / no stock notice (the product's
 *               threshold, else the store's), only when an order took the
 *               stock: the owner's own stock edits don't alert
 *
 * Nothing is sent while a move or a store copy comes in (mute()).
 */
class Qwoo_Owner_Alerts {

    const DONE_META = '_qwoo_owner_alerted';

    /** @var array Events of this request. */
    private static $events = [];
    private static $muted = false;
    /** An order took stock in this request (low stock alerts only then). */
    private static $order_stock = false;
    private static $hooked = false;

    public static function init() {
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'order_status' ], 20, 4 );
        add_action( 'woocommerce_reduce_order_stock', static function () { self::$order_stock = true; } );
        add_action( 'woocommerce_low_stock', [ __CLASS__, 'low_stock' ] );
        add_action( 'woocommerce_no_stock', [ __CLASS__, 'no_stock' ] );
    }

    /** No alerts for the rest of this request (imports). */
    public static function mute() {
        self::$muted  = true;
        self::$events = [];
    }

    public static function order_status( $order_id, $from, $to, $order = null ) {
        if ( self::$muted || ! in_array( $to, [ 'processing', 'on-hold', 'completed' ], true ) ) {
            return;
        }
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order || $order->get_type() !== 'shop_order' || $order->get_meta( self::DONE_META ) ) {
            return;
        }
        // Orders brought in by a move keep their old number: they're never new.
        if ( $order->get_meta( '_qwoo_mig_id' ) ) {
            return;
        }
        $order->update_meta_data( self::DONE_META, time() );
        $order->save_meta_data();
        self::add( [
            'type'   => 'order',
            'id'     => $order->get_id(),
            'number' => (string) $order->get_order_number(),
            'total'  => self::money( (float) $order->get_total(), $order->get_currency() ),
            'items'  => (int) $order->get_item_count(),
        ] );
    }

    /** A shopper's review was saved (waiting for the owner's approval). */
    public static function review( $product, $rating ) {
        if ( self::$muted || ! $product ) {
            return;
        }
        self::add( [
            'type'       => 'review',
            'product_id' => (int) $product->get_id(),
            'product'    => wp_strip_all_tags( $product->get_name() ),
            'rating'     => (int) $rating,
        ] );
    }

    public static function low_stock( $product ) {
        self::stock( $product, false );
    }

    public static function no_stock( $product ) {
        self::stock( $product, true );
    }

    private static function stock( $product, $out ) {
        if ( self::$muted || ! $product instanceof WC_Product ) {
            return;
        }
        $id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
        self::add( [
            'type'       => 'low_stock',
            'product_id' => (int) $id,
            'product'    => wp_strip_all_tags( $product->get_name() ),
            'stock'      => (int) $product->get_stock_quantity(),
            'out'        => (bool) $out,
        ] );
    }

    /** "₪380" as plain text (no HTML, no entities). */
    private static function money( $amount, $currency ) {
        $html = wc_price( $amount, [ 'currency' => $currency ] );
        return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
    }

    private static function add( array $event ) {
        if ( ! Qwoo_Platform_Connection::is_connected() ) {
            return;
        }
        self::$events[] = $event;
        if ( ! self::$hooked ) {
            self::$hooked = true;
            add_action( 'shutdown', [ __CLASS__, 'send' ], 50 );
        }
    }

    /** After the answer went out: one call with this request's events. */
    public static function send() {
        $events = array_values( array_filter( self::$events, static fn( $e ) => $e['type'] !== 'low_stock' || self::$order_stock ) );
        self::$events = [];
        if ( self::$muted || ! $events ) {
            return;
        }
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } elseif ( function_exists( 'litespeed_finish_request' ) ) {
            litespeed_finish_request();
        }
        try {
            Qwoo_Platform_Connection::post( 'owner-alert', [ 'events' => array_slice( $events, 0, 20 ) ], 10 );
        } catch ( Throwable $e ) {
            error_log( 'Qwoo owner alerts: ' . $e->getMessage() );
        }
    }
}

Qwoo_Owner_Alerts::init();

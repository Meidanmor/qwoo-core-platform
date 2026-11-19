<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Dashboard → Settings → Couriers, and sending orders (Qwoo_Couriers).
 * Each action checks what it gets; keys never come back to the dashboard.
 */
trait Qwoo_Platform_Couriers {

    private static function couriers_answer( $result ) {
        return is_wp_error( $result ) ? self::bad( $result->get_error_message() ) : $result;
    }

    private static function action_couriers_get() {
        return Qwoo_Couriers::dashboard_state();
    }

    /** { courier, token|key, client|name } or { defaults: {…} }. */
    private static function action_couriers_save( array $params ) {
        return self::couriers_answer( Qwoo_Couriers::save( $params ) );
    }

    private static function action_couriers_test( array $params ) {
        return self::couriers_answer( Qwoo_Couriers::test( $params ) );
    }

    private static function action_couriers_disconnect( array $params ) {
        return self::couriers_answer( Qwoo_Couriers::disconnect( $params ) );
    }

    /** The ids the dashboard sent: up to one batch, no repeats. */
    private static function courier_ids( array $params ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $params['ids'] ?? [ $params['id'] ?? 0 ] ) ) ) ) );
        return array_slice( $ids, 0, Qwoo_Couriers::BATCH );
    }

    /**
     * { ids[] (or id), courier, service, packages, cod, note, complete }.
     * Each order on its own: { results: [ { id, number, ok, error?, summary } ], order? }
     * (order: the full order, when one order was sent).
     */
    private static function action_order_ship( array $params ) {
        $ids = self::courier_ids( $params );
        if ( ! $ids ) return self::bad( 'Choose the orders to send.' );
        $opt = [
            'courier'  => in_array( $params['courier'] ?? '', [ 'hfd', 'lionwheel' ], true ) ? $params['courier'] : '',
            'service'  => in_array( $params['service'] ?? '', [ 'door', 'pickup', 'collect' ], true ) ? $params['service'] : '',
        ];
        if ( isset( $params['packages'] ) && $params['packages'] !== '' ) $opt['packages'] = (int) $params['packages'];
        if ( count( $ids ) === 1 && isset( $params['cod'] ) ) $opt['cod'] = mb_substr( (string) $params['cod'], 0, 12 );
        if ( isset( $params['note'] ) ) $opt['note'] = (string) $params['note'];
        if ( array_key_exists( 'complete', $params ) ) $opt['complete'] = ! empty( $params['complete'] );

        $results = [];
        foreach ( $ids as $id ) {
            $order = self::find_order( $id );
            if ( is_wp_error( $order ) ) {
                $results[] = [ 'id' => $id, 'ok' => false, 'error' => 'This order doesn\'t exist.' ];
                continue;
            }
            $done = Qwoo_Couriers::ship( $order, $opt );
            $order = wc_get_order( $id );
            $results[] = [
                'id'      => $id,
                'number'  => (string) $order->get_order_number(),
                'ok'      => ! is_wp_error( $done ),
                'error'   => is_wp_error( $done ) ? $done->get_error_message() : '',
                'summary' => self::order_summary( $order ),
            ];
        }
        $out = [ 'results' => $results ];
        if ( count( $ids ) === 1 && ! empty( $results[0]['summary'] ) ) $out['order'] = self::order_full( wc_get_order( $ids[0] ) );
        return $out;
    }

    /**
     * { ids[] }: the labels, one file per order (no merging):
     * { labels: [ { id, number, pdf (base64) | url } ], errors: [ { id, number, error } ] }.
     */
    private static function action_order_label( array $params ) {
        $ids = self::courier_ids( $params );
        if ( ! $ids ) return self::bad( 'Choose the orders to print.' );
        $labels = [];
        $errors = [];
        foreach ( $ids as $id ) {
            $order = self::find_order( $id );
            if ( is_wp_error( $order ) ) continue;
            $label = Qwoo_Couriers::label( $order );
            $row   = [ 'id' => $id, 'number' => (string) $order->get_order_number() ];
            if ( is_wp_error( $label ) ) {
                $errors[] = $row + [ 'error' => $label->get_error_message() ];
            } elseif ( isset( $label['pdf'] ) ) {
                $labels[] = $row + [ 'pdf' => base64_encode( $label['pdf'] ) ];
            } else {
                $labels[] = $row + [ 'url' => $label['url'] ];
            }
        }
        return [ 'labels' => $labels, 'errors' => $errors ];
    }

    private static function action_order_ship_cancel( array $params ) {
        $order = self::find_order( $params['id'] ?? 0 );
        if ( is_wp_error( $order ) ) return $order;
        $done = Qwoo_Couriers::cancel( $order );
        if ( is_wp_error( $done ) ) return self::bad( $done->get_error_message() );
        return self::order_full( wc_get_order( $order->get_id() ) );
    }

    /** { id, number, url, courier, complete }: a tracking number from any company (empty: removed). */
    private static function action_order_tracking_save( array $params ) {
        $order = self::find_order( $params['id'] ?? 0 );
        if ( is_wp_error( $order ) ) return $order;
        $done = Qwoo_Couriers::save_tracking( $order, $params );
        if ( is_wp_error( $done ) ) return self::bad( $done->get_error_message() );
        return self::order_full( wc_get_order( $order->get_id() ) );
    }
}

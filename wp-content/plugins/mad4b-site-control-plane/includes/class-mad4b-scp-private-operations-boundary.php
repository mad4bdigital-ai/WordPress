<?php
/**
 * Governed private operational post types and their JetEngine relations.
 *
 * Loads before Site Control Plane's read-only/foreign REST fastpaths. The
 * WordPress site database is authoritative; no global company IDs are used.
 * This policy is intentional even while Site Profile onboarding is pending.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Private_Operations_Boundary {
    private const PRIVATE_TYPES = array( 'tour_orders', 'tour_bookings' );
    private static $relation_cache = array();

    private static function is_private_type( $post_type ) {
        return is_string( $post_type ) && in_array( $post_type, self::PRIVATE_TYPES, true );
    }

    private static function authorized() {
        if ( ! is_user_logged_in() ) { return false; }
        if ( current_user_can( 'manage_options' )
            || current_user_can( 'mad4b_manage_company_operations' ) ) {
            return true;
        }
        $roles = (array) wp_get_current_user()->roles;
        return current_user_can( 'edit_others_posts' )
            && (bool) array_intersect( array( 'master_admin', 'operator', 'admin_developer' ), $roles );
    }

    public static function post_type_args( $args, $post_type ) {
        if ( ! self::is_private_type( $post_type ) || ! is_array( $args ) ) { return $args; }
        $args['public'] = false;
        $args['publicly_queryable'] = false;
        $args['has_archive'] = false;
        $args['exclude_from_search'] = true;
        $args['show_in_nav_menus'] = false;
        $args['query_var'] = false;
        // Do not modify show_ui, show_in_menu or show_in_rest. Existing
        // internal editors and authenticated adapters must continue to work.
        return $args;
    }

    private static function private_relation( $id ) {
        $id = absint( $id );
        if ( ! $id ) { return false; }
        if ( array_key_exists( $id, self::$relation_cache ) ) {
            return self::$relation_cache[$id];
        }
        global $wpdb;
        // Site-local JetEngine definition; no assumption about relation ID.
        $table = $wpdb->prefix . 'jet_post_types';
        $serialized = $wpdb->get_var( $wpdb->prepare(
            "SELECT args FROM {$table} WHERE id=%d AND status='relation' LIMIT 1", $id
        ) );
        $args = is_string( $serialized ) ? maybe_unserialize( $serialized ) : null;
        $parent = is_array( $args ) ? (string) ( $args['parent_object'] ?? '' ) : '';
        $child  = is_array( $args ) ? (string) ( $args['child_object'] ?? '' ) : '';
        $private = false;
        foreach ( self::PRIVATE_TYPES as $type ) {
            if ( in_array( 'posts::' . $type, array( $parent, $child ), true ) ) {
                $private = true;
                break;
            }
        }
        self::$relation_cache[$id] = $private;
        return $private;
    }

    public static function rest( $result, $server, $request ) {
        if ( ! $request instanceof WP_REST_Request ) { return $result; }
        $route = (string) $request->get_route();
        $protect = (bool) preg_match(
            '~^/wp/v2/(tour_orders|tour_bookings)(?:/|$)~', $route
        );
        if ( ! $protect && preg_match( '~^/jet-rel/([0-9]+)(?:/|$)~', $route, $m ) ) {
            $protect = self::private_relation( (int) $m[1] );
        }
        if ( ! $protect ) { return $result; }
        if ( ! headers_sent() ) {
            header( 'Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate', true );
            header( 'Pragma: no-cache', true );
        }
        if ( self::authorized() ) { return $result; }
        return new WP_Error(
            'mad4b_private_operations_forbidden',
            'Private company operations records require authorized staff.',
            array( 'status' => is_user_logged_in() ? 403 : 401 )
        );
    }

    public static function query( $query ) {
        if ( ! $query instanceof WP_Query || self::authorized() ) { return; }
        if ( is_admin() && ! wp_doing_ajax() ) { return; }
        $type = $query->get( 'post_type' );
        if ( self::is_private_type( $type ) ) {
            $query->set( 'post__in', array( 0 ) );
        } elseif ( 'any' === $type ) {
            $public = array_diff( get_post_types( array( 'public' => true ), 'names' ), self::PRIVATE_TYPES );
            $query->set( 'post_type', $public ? array_values( $public ) : 'post' );
        } elseif ( is_array( $type ) && array_intersect( $type, self::PRIVATE_TYPES ) ) {
            $safe = array_values( array_diff( $type, self::PRIVATE_TYPES ) );
            $query->set( 'post_type', $safe ? $safe : 'post' );
            if ( ! $safe ) { $query->set( 'post__in', array( 0 ) ); }
        }
    }

    public static function public_template() {
        if ( self::authorized() ) { return; }
        if ( ! is_singular( self::PRIVATE_TYPES )
            && ! is_post_type_archive( self::PRIVATE_TYPES ) ) { return; }
        global $wp_query;
        if ( $wp_query instanceof WP_Query ) { $wp_query->set_404(); }
        status_header( 404 );
        nocache_headers();
        wp_die( 'Not Found', 'Not Found', array( 'response' => 404 ) );
    }

    public static function boot() {
        add_filter( 'register_post_type_args', array( __CLASS__, 'post_type_args' ), 10, 2 );
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest' ), -1000, 3 );
        add_action( 'pre_get_posts', array( __CLASS__, 'query' ), -1000 );
        add_action( 'template_redirect', array( __CLASS__, 'public_template' ), -1000 );
    }
}
MAD4B_SCP_Private_Operations_Boundary::boot();

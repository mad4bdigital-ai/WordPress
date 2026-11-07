<?php
/**
 * Plugin Name: MAD4B Shared Secondary Listing Lazy Load
 * Description: Uses native JetEngine signed AJAX for secondary, public, non-filtered listing grids; site-specific opt-outs.
 * Version: 1.0.3
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function mad4b_secondary_grid_lazy_context() {
    if ( get_option( 'mad4b_secondary_grid_lazy_policy', 'auto' ) === 'off'
        || is_admin() || wp_doing_ajax() || is_user_logged_in()
        || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
        || ( defined( 'WP_CLI' ) && WP_CLI )
        || is_preview() || ! is_singular() ) { return false; }
    if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor )
        && \Elementor\Plugin::$instance->editor->is_edit_mode() ) { return false; }
    $main_id = get_queried_object_id();
    $type = $main_id ? get_post_type( $main_id ) : false;
    $post_type = $type ? get_post_type_object( $type ) : null;
    if ( ! $post_type || empty( $post_type->public ) || preg_match( '/(?:booking|order|quote|checkout|payment|customer|reservation|rate)/i', $type ) ) { return false; }
    // Search, date, market, filter, and booking requests retain fully server-rendered grids.
    foreach ( array_keys( (array) $_GET ) as $param ) {
        if ( ! preg_match( '/^(?:utm_[a-z0-9_]+|gclid|fbclid|msclkid)$/i', (string) $param ) ) { return false; }
    }
    return true;
}

/**
 * Rendering a JetEngine lazy placeholder before evaluating a Query Has Items
 * condition can make Elementor hide the entire section. In that case no
 * IntersectionObserver can recover its contents. Only automatic deferral is
 * vetoed; editors' explicit native lazy-load settings are left untouched.
 *
 * The check is semantic and document-scoped: no domain, term, query, or
 * Elementor widget IDs are hard-coded. A missing source document fails open
 * for rendering (i.e. disables auto-deferral), never for content visibility.
 */
function mad4b_secondary_grid_query_visibility_dependency( $query_id, $document_id = 0 ) {
    static $cache = [];
    $query_id = absint( $query_id );
    $document_id = absint( $document_id );
    if ( ! $query_id ) { return true; }
    if ( ! $document_id && class_exists( '\Elementor\Plugin' ) ) {
        $manager = \Elementor\Plugin::$instance->documents ?? null;
        $document = is_object( $manager ) ? $manager->get_current() : null;
        if ( is_object( $document ) && method_exists( $document, 'get_main_id' ) ) {
            $document_id = absint( $document->get_main_id() );
        }
    }
    if ( ! $document_id ) { return true; }
    if ( isset( $cache[ $document_id ][ $query_id ] ) ) {
        return $cache[ $document_id ][ $query_id ];
    }
    $raw = get_post_meta( $document_id, '_elementor_data', true );
    $elements = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
    if ( ! is_array( $elements ) ) {
        return $cache[ $document_id ][ $query_id ] = true;
    }
    $has_dependency = static function ( $nodes ) use ( &$has_dependency, $query_id ) {
        foreach ( (array) $nodes as $element ) {
            if ( ! is_array( $element ) ) { continue; }
            $settings = (array) ( $element['settings'] ?? [] );
            if ( ( $settings['jedv_enabled'] ?? '' ) === 'yes' ) {
                foreach ( (array) ( $settings['jedv_conditions'] ?? [] ) as $condition ) {
                    if ( is_array( $condition )
                        && ( $condition['jedv_condition'] ?? '' ) === 'query-has-items'
                        && absint( $condition['query_id'] ?? 0 ) === $query_id ) {
                        return true;
                    }
                }
            }
            if ( $has_dependency( $element['elements'] ?? [] ) ) { return true; }
        }
        return false;
    };
    return $cache[ $document_id ][ $query_id ] = $has_dependency( $elements );
}

function mad4b_secondary_grid_lazy_eligible( $settings ) {
    static $decisions = [];
    if ( ! is_array( $settings ) || ! mad4b_secondary_grid_lazy_context() ) { return false; }
    $query_id = absint( $settings['custom_query_id'] ?? 0 );
    $listing_id = absint( $settings['lisitng_id'] ?? 0 ); // JetEngine's own spelling.
    if ( ! $query_id || ! $listing_id || empty( $settings['custom_query'] ) || ! filter_var( $settings['custom_query'], FILTER_VALIDATE_BOOLEAN ) ) { return false; }
    foreach ( [ 'is_archive_template', 'use_load_more', 'pagination', 'use_load_more_pagination' ] as $key ) {
        if ( ! empty( $settings[ $key ] ) && filter_var( $settings[ $key ], FILTER_VALIDATE_BOOLEAN ) ) { return false; }
    }
    $setting_mode = (string) get_post_meta( $listing_id, '_mad4b_secondary_lazy_mode', true );
    if ( 'off' === $setting_mode ) { return false; }
    if ( mad4b_secondary_grid_query_visibility_dependency( $query_id ) ) { return false; }
    $columns = (int) ( $settings['columns'] ?? 0 );
    $context_post_type = (string) get_post_type( get_queried_object_id() );
    $key = $listing_id . '|' . $query_id . '|' . $context_post_type . '|' . $setting_mode . '|' . $columns;
    if ( array_key_exists( $key, $decisions ) ) { return $decisions[ $key ]; }
    $decisions[ $key ] = false;
    if ( ! class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) { return false; }
    $query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( $query_id );
    if ( ! $query || ! in_array( $query->query_type, [ 'terms', 'posts' ], true ) ) { return false; }
    $name = (string) $query->name;
    // Marketing-related lists are deferred; rates, extras, schedules and booking selectors never are.
    if ( preg_match( '/(?:rates?|pricing|prices?|quotes?|bookings?|orders?|costs?|suppliers?|availability|occupancy|packages?|tiers?|durations?|day[ _-]?(?:time|of[ _-]?week)|extras?)/i', $name ) ) { return false; }
    $semantic = preg_match( '/related|similar|associated|recommended|suggested|(?:single|current).*(?:countr|govern|destination|region|locat|cit(?:y|ies))|relacionad|similares|connex|verwandt|correl|مرتبط|مشابه|وجهات|مدن|دول/iu', $name );
    if ( $setting_mode !== 'on' && ( ! $semantic || $columns < 2 ) ) { return false; }
    $query->setup_query();
    $args = is_array( $query->final_query ?? null ) ? $query->final_query : [];
    $allow = false;
    if ( 'terms' === $query->query_type ) {
        $taxes = (array) ( $args['taxonomy'] ?? [] );
        $allow = ! empty( $taxes );
        foreach ( $taxes as $taxonomy ) {
            $object = is_string( $taxonomy ) ? get_taxonomy( $taxonomy ) : null;
            if ( ! $object || empty( $object->public ) || ! is_object_in_taxonomy( $context_post_type, $taxonomy ) ) { $allow = false; break; }
        }
    } elseif ( 'posts' === $query->query_type ) {
        $types = (array) ( $args['post_type'] ?? [] );
        $statuses = (array) ( $args['post_status'] ?? [ 'publish' ] );
        $allow = ! empty( $types ) && count( $statuses ) === 1 && 'publish' === reset( $statuses );
        foreach ( $types as $post_type ) {
            $object = is_string( $post_type ) ? get_post_type_object( $post_type ) : null;
            if ( ! $object || empty( $object->public ) || preg_match( '/(?:rate|price|cost|product|order|booking|customer|checkout)/i', (string) $post_type ) ) { $allow = false; break; }
        }
    }
    $allow = (bool) apply_filters( 'mad4b/secondary_listing_lazy/eligible', $allow, $settings, $query );
    return $decisions[ $key ] = $allow;
}

add_filter( 'jet-engine/listing/grid/is_lazy_load', static function ( $enabled, $settings ) {
    if ( $enabled ) { return true; } // Preserve the site's explicit JetEngine lazy setting.
    return mad4b_secondary_grid_lazy_eligible( $settings );
}, 50, 2 );

add_filter( 'jet-engine/listing/grid/lazy-load/options', static function ( $options, $settings ) {
    if ( is_array( $options ) && empty( $settings['lazy_load'] ) && mad4b_secondary_grid_lazy_eligible( $settings ) ) {
        $px = (int) get_option( 'mad4b_secondary_grid_lazy_prefetch_px', 900 );
        $options['offset'] = max( 0, min( 1000, $px ) ) . 'px';
    }
    return $options;
}, 50, 2 );

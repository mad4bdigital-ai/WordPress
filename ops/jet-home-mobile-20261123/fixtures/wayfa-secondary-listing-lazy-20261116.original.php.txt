<?php
/**
 * Plugin Name: MAD4B Shared Secondary Listing Lazy Load
 * Description: Uses native JetEngine signed AJAX for secondary, public, non-filtered listing grids; site-specific opt-outs.
 * Version: 1.0.10
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Public placement family, dynamically resolved from WordPress native query.
 * A falsy return means the page is never eligible for automatic deferral.
 */
function mad4b_secondary_grid_lazy_public_render_family() {
    if ( is_singular() ) {
        $type = (string) get_post_type( get_queried_object_id() );
        $object = $type ? get_post_type_object( $type ) : null;
        return $object && ! empty( $object->public )
            && ! preg_match( '/(?:booking|order|quote|checkout|payment|customer|reservation|rate)/i', $type )
            ? 'singular' : '';
    }
    if ( is_tax() || is_category() || is_tag() ) {
        $term = get_queried_object();
        $tax = is_object( $term ) && isset( $term->taxonomy ) ? get_taxonomy( $term->taxonomy ) : false;
        return $tax && ! empty( $tax->public ) ? 'taxonomy' : '';
    }
    if ( is_post_type_archive() ) {
        $post_type = get_queried_object();
        return is_object( $post_type ) && ! empty( $post_type->public )
            && ! preg_match( '/(?:booking|order|quote|checkout|payment|customer|reservation|rate)/i', $post_type->name ?? '' )
            ? 'post_type_archive' : '';
    }
    return '';
}

/** The non-home rollout can be audited without changing existing single pages. */
function mad4b_secondary_grid_lazy_public_expansion_allowed() {
    if ( is_front_page() ) { return true; }
    $mode = (string) get_option( 'mad4b_secondary_grid_lazy_public_context_mode', 'canary' );
    if ( $mode === 'auto' ) { return true; }
    if ( $mode !== 'canary' ) { return false; }
    return wp_doing_ajax() ? mad4b_secondary_grid_inner_enabled()
        : (string) ( $_GET['utm_mad4b_inner'] ?? '' ) === '1';
}

function mad4b_secondary_grid_lazy_context() {
    if ( get_option( 'mad4b_secondary_grid_lazy_policy', 'auto' ) === 'off'
        || is_admin() || wp_doing_ajax() || is_user_logged_in()
        || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
        || ( defined( 'WP_CLI' ) && WP_CLI )
        || is_preview() ) { return false; }
    if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor )
        && \Elementor\Plugin::$instance->editor->is_edit_mode() ) { return false; }
    $family = mad4b_secondary_grid_lazy_public_render_family();
    if ( ! $family || ( $family !== 'singular' && ! mad4b_secondary_grid_lazy_public_expansion_allowed() ) ) { return false; }
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
    if ( ! array_key_exists( $document_id, $cache ) ) {
        $raw = get_post_meta( $document_id, '_elementor_data', true );
        $elements = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
        $dependencies = [];
        if ( is_array( $elements ) ) {
            $collect = static function ( $nodes ) use ( &$collect, &$dependencies ) {
                foreach ( $nodes as $element ) {
                    if ( ! is_array( $element ) ) { continue; }
                    $settings = (array) ( $element['settings'] ?? [] );
                    if ( ( $settings['jedv_enabled'] ?? '' ) === 'yes' ) {
                        foreach ( (array) ( $settings['jedv_conditions'] ?? [] ) as $condition ) {
                            if ( is_array( $condition ) && ( $condition['jedv_condition'] ?? '' ) === 'query-has-items' ) {
                                $dependencies[ absint( $condition['query_id'] ?? 0 ) ] = true;
                            }
                        }
                    }
                    if ( ! empty( $element['elements'] ) ) { $collect( (array) $element['elements'] ); }
                }
            };
            $collect( $elements );
            unset( $collect );
        }
        // An unreadable document keeps all grids server-rendered.
        $cache[ $document_id ] = is_array( $elements ) ? $dependencies : null;
    }
    return null === $cache[ $document_id ] || isset( $cache[ $document_id ][ $query_id ] );
}

/**
 * Prove a grid's own Query Has Items visibility was evaluated by a visible
 * Elementor ancestor before the widget renders. Generic document-wide
 * condition matching is insufficient: it would allow unrelated widgets to
 * become placeholders and could hide a section with a dynamic condition.
 * Source is read from the current native Elementor document, not a list of IDs.
 */
function mad4b_secondary_grid_lazy_parent_condition_proven( $query_id, $listing_id, $document_id ) {
    $raw = get_post_meta( $document_id, '_elementor_data', true );
    $tree = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
    if ( ! is_array( $tree ) || ! $query_id || ! $listing_id ) { return false; }
    $matches = [];
    $walk = static function ( $nodes, $proof = false, $veto = false ) use ( &$walk, &$matches, $query_id, $listing_id ) {
        foreach ( (array) $nodes as $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $settings = (array) ( $node['settings'] ?? [] );
            $next_proof = $proof;
            $next_veto = $veto;
            if ( ( $settings['jedv_enabled'] ?? '' ) === 'yes' ) {
                $conditions = (array) ( $settings['jedv_conditions'] ?? [] );
                $type = (string) ( $settings['jedv_type'] ?? 'show' );
                $positive = count( $conditions ) === 1
                    && in_array( $type, [ '', 'show' ], true )
                    && is_array( reset( $conditions ) )
                    && ( reset( $conditions )['jedv_condition'] ?? '' ) === 'query-has-items'
                    && absint( reset( $conditions )['query_id'] ?? 0 ) === $query_id;
                $next_proof = $proof || $positive;
                if ( ! $positive ) { $next_veto = true; }
            }
            if ( ( $node['widgetType'] ?? '' ) === 'jet-listing-grid'
                && absint( $settings['custom_query_id'] ?? 0 ) === $query_id
                && absint( $settings['lisitng_id'] ?? 0 ) === $listing_id ) {
                $matches[] = $next_proof && ! $next_veto
                    && ( $settings['jedv_enabled'] ?? '' ) !== 'yes';
            }
            if ( ! empty( $node['elements'] ) ) {
                $walk( (array) $node['elements'], $next_proof, $next_veto );
            }
        }
    };
    $walk( $tree );
    return count( $matches ) === 1 && $matches[0] === true;
}

/**
 * Extra admission for heavy, public, non-filtered term collections on safe pages.
 * JetEngine still controls the signed AJAX render and Swiper reinitialization.
 * This does not change stored Elementor settings or copy them via WPML.
 */
function mad4b_secondary_grid_lazy_heavy_terms_eligible( $settings, $query, $query_id, $listing_id, $document_id, $public_context_verified = false ) {
    if ( ( ! mad4b_secondary_grid_lazy_public_render_family() && ! $public_context_verified )
        || ( ! is_front_page() && ! mad4b_secondary_grid_lazy_public_expansion_allowed() )
        || ! is_object( $query ) || ( $query->query_type ?? '' ) !== 'terms'
        || (string) get_option( 'mad4b_secondary_grid_lazy_heavy_terms_mode', 'auto' ) === 'off'
        || ( (string) get_option( 'mad4b_secondary_grid_lazy_heavy_terms_mode', 'auto' ) === 'canary'
            && ! mad4b_secondary_grid_inner_enabled() )
        || ( $settings['mad4b_placement'] ?? '' ) === 'above_fold'
        || (int) ( $settings['columns'] ?? 0 ) < 3
        || ! mad4b_secondary_grid_lazy_parent_condition_proven( $query_id, $listing_id, $document_id ) ) {
        return false;
    }
    // Inherit company / term / listing policy rather than creating an
    // independent enable switch for the same JetEngine renderer.
    if ( (string) get_post_meta( $listing_id, '_mad4b_adaptive_listing_mode', true ) === 'native' ) {
        return false;
    }
    if ( class_exists( 'MAD4B_Company_Policy_Registry_V2' ) ) {
        $policy = MAD4B_Company_Policy_Registry_V2::render_policy( $settings );
        if ( ( $policy['values']['strategy'] ?? 'off' ) !== 'ajax_only' ) { return false; }
    }
    if ( ! method_exists( $query, 'setup_query' ) || ! method_exists( $query, 'get_items_total_count' )
        || get_post_status( $listing_id ) !== 'publish'
        || preg_match( '/(?:rate|price|quote|booking|order|cost|supplier|availability|occupancy|package|tier|duration|extra)/i', (string) ( $query->name ?? '' ) ) ) {
        return false;
    }
    $query->setup_query();
    $args = is_array( $query->final_query ?? null ) ? $query->final_query : [];
    $taxonomies = (array) ( $args['taxonomy'] ?? [] );
    if ( ! $taxonomies ) { return false; }
    foreach ( $taxonomies as $taxonomy ) {
        $tax = is_string( $taxonomy ) ? get_taxonomy( $taxonomy ) : false;
        if ( ! $tax || empty( $tax->public ) ) { return false; }
    }
    // A single language/site policy can tighten the threshold without copying
    // Elementor templates or enumerating individual Queries/Widgets.
    $threshold = max( 18, min( 100, (int) get_option( 'mad4b_secondary_grid_lazy_heavy_terms_threshold', 24 ) ) );
    $total = (int) $query->get_items_total_count();
    return $total >= $threshold && mad4b_secondary_grid_lazy_has_enough_results( $settings, $query )
        && (bool) apply_filters(
        'mad4b/secondary_listing_lazy/heavy_terms_eligible', true, $settings, $query, $total
    );
}

/**
 * Decide if enough actual WPML/JetEngine results remain to justify AJAX.
 * Uses the existing company rows + Elementor responsive columns. A small
 * collection is always cheaper and safer to render as ordinary card HTML.
 */
function mad4b_secondary_grid_lazy_has_enough_results( $settings, $query ) {
    if ( ! is_array( $settings ) || ! is_object( $query )
        || ! method_exists( $query, 'get_items_total_count' ) ) { return false; }
    $device = class_exists( 'MAD4B_Company_Policy_Registry_V2' )
        ? MAD4B_Company_Policy_Registry_V2::device() : ( wp_is_mobile() ? 'mobile' : 'desktop' );
    $desktop = absint( $settings['columns'] ?? 1 );
    $tablet = absint( $settings['columns_tablet'] ?? 0 ) ?: $desktop;
    $mobile = absint( $settings['columns_mobile'] ?? 0 ) ?: $tablet;
    $columns = $device === 'mobile' ? $mobile : ( $device === 'tablet' ? $tablet : $desktop );
    if ( $columns < 1 || $columns > 12 ) { return false; }
    $policy = class_exists( 'MAD4B_Company_Policy_Registry_V2' )
        ? MAD4B_Company_Policy_Registry_V2::render_policy( $settings ) : [];
    $rows = absint( $policy['values']['rows'] ?? 2 );
    if ( $rows < 1 || $rows > 6 ) { return false; }
    $minimum = max( 4, min( 100, (int) get_option( 'mad4b_secondary_grid_lazy_min_items', 8 ) ) );
    $threshold = max( $minimum, $columns * $rows + 1 );
    $actual = (int) $query->get_items_total_count();
    return $actual >= $threshold;
}

/**
 * A device+locale+listing-template measurement admitted by the company.
 * Absence, CSS epoch drift or a changed Elementor listing source fails closed.
 * No listing ID, height, language or template is embedded in this engine.
 */
function mad4b_secondary_grid_lazy_measured_mobile_height( $settings, $band = 'mobile' ) {
    // Viewport width is not known reliably at PHP render time. All mobile-UA
    // cache entries must carry BOTH Elementor breakpoint bands, independent
    // of whether the UA says phone, tablet, or foldable.
    if ( ! is_array( $settings ) || ! wp_is_mobile()
        || ! in_array( $band, [ 'mobile', 'wide' ], true ) ) { return 0; }
    $listing_id = absint( $settings['lisitng_id'] ?? 0 );
    if ( ! $listing_id || get_post_status( $listing_id ) !== 'publish' ) { return 0; }
    $lang = (string) apply_filters( 'wpml_current_language', '' );
    if ( ! preg_match( '/^[a-z]{2}(?:-[a-z]{2})?$/D', $lang ) ) { return 0; }
    $raw = (string) get_post_meta( $listing_id, '_elementor_data', true );
    if ( strlen( $raw ) < 200 ) { return 0; }
    $registry = get_option( 'mad4b_secondary_grid_lazy_layout_proofs_v1', [] );
    if ( ! is_array( $registry )
        || ( $registry['contract'] ?? '' ) !== 'mad4b.native-listing.layout-proof.v1'
        || (int) ( $registry['css_epoch'] ?? -1 ) !== (int) get_option( 'mad4b_css_ai_epoch_v1', 0 )
        || (string) ( $registry['site_host'] ?? '' ) !== (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) {
        return 0;
    }
    // Bind the CSS media boundary to Elementor's active site kit. Changes
    // invalidate all mobile-UA proofs; no hard-coded 767px assumption.
    $kit_id = absint( get_option( 'elementor_active_kit', 0 ) );
    $kit_settings = $kit_id ? get_post_meta( $kit_id, '_elementor_page_settings', true ) : [];
    $breakpoint = is_array( $kit_settings ) ? absint( $kit_settings['viewport_mobile'] ?? 0 ) : 0;
    if ( $breakpoint < 480 || $breakpoint > 1100
        || (int) ( $registry['mobile_breakpoint'] ?? 0 ) !== $breakpoint ) { return 0; }
    $key = $listing_id . '|' . $lang . '|' . $band;
    $entry = $registry['entries'][ $key ] ?? null;
    if ( ! is_array( $entry ) || ( $entry['status'] ?? '' ) !== 'pass'
        || (int) ( $entry['listing_id'] ?? 0 ) !== $listing_id
        || ( $entry['locale'] ?? '' ) !== $lang || ( $entry['device'] ?? '' ) !== $band
        || ! isset( $entry['listing_source_sha256'] )
        || ! is_string( $entry['listing_source_sha256'] )
        || ! hash_equals( hash( 'sha256', $raw ), $entry['listing_source_sha256'] ) ) {
        return 0;
    }
    // Bind proof to the actual generated Elementor stylesheet, not only to a
    // global CSS revision that other unrelated page jobs might update.
    $style_sha = $entry['listing_css_sha256'] ?? null;
    $css = WP_CONTENT_DIR . '/uploads/elementor/css/post-' . $listing_id . '.css';
    if ( ! is_string( $style_sha ) || ! preg_match( '/^[a-f0-9]{64}$/D', $style_sha )
        || is_link( $css ) || ! is_file( $css ) || @filesize( $css ) < 100
        || @filesize( $css ) > 1048576 || ! hash_equals( $style_sha, (string) @hash_file( 'sha256', $css ) ) ) {
        return 0;
    }
    $sampled = array_values( array_unique( array_map( 'absint', (array) ( $entry['sampled_widths'] ?? [] ) ) ) );
    sort( $sampled );
    if ( $band === 'mobile' ) {
        if ( count( $sampled ) < 3 || $sampled[0] > 320 || end( $sampled ) < $breakpoint ) { return 0; }
    } elseif ( count( $sampled ) < 4 || $sampled[0] > $breakpoint + 1 || end( $sampled ) < 1920 ) {
        return 0;
    }
    $height = absint( $entry['height_px'] ?? 0 );
    return $height >= 100 && $height <= 1600 ? $height : 0;
}

/* Reserve the browser-measured card footprint before JetEngine's signed
 * lazy placeholder is replaced. Ordinary SSR, unmeasured templates and other
 * company or Elementor widgets remain fully native and unchanged. */
add_action( 'elementor/frontend/widget/before_render', static function ( $widget ) {
    if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' )
        || $widget->get_name() !== 'jet-listing-grid'
        || ! method_exists( $widget, 'get_settings_for_display' )
        || ! method_exists( $widget, 'add_render_attribute' ) ) { return; }
    $settings = $widget->get_settings_for_display();
    if ( ! is_array( $settings ) || ! mad4b_secondary_grid_lazy_eligible( $settings ) ) { return; }
    // Enqueue only for an actually admitted native Elementor listing. Global
    // AUTO policy alone must not load the Swiper adapter on every public page.
    if ( mad4b_secondary_grid_inner_enabled() ) {
        wp_enqueue_script( 'mad4b-secondary-inner-native-v1' );
    }
    $height = mad4b_secondary_grid_lazy_measured_mobile_height( $settings );
    $wide_height = mad4b_secondary_grid_lazy_measured_mobile_height( $settings, 'wide' );
    if ( ! $height || ! $wide_height ) { return; }
    $widget->add_render_attribute( '_wrapper', 'class', 'mad4b-native-measured-lazy' );
    $widget->add_render_attribute( '_wrapper', 'style',
        '--mad4b-measured-card-height:' . $height . 'px;'
        . '--mad4b-measured-wide-height:' . $wide_height . 'px;' );
}, 12 );

add_action( 'wp_head', static function () {
    if ( ! mad4b_secondary_grid_lazy_context() || ! wp_is_mobile() ) { return; }
    // Elementor's current mobile boundary is part of the verified option.
    $proof = get_option( 'mad4b_secondary_grid_lazy_layout_proofs_v1', [] );
    $kit = absint( get_option( 'elementor_active_kit', 0 ) );
    $kit_settings = $kit ? get_post_meta( $kit, '_elementor_page_settings', true ) : [];
    $boundary = is_array( $kit_settings ) ? absint( $kit_settings['viewport_mobile'] ?? 0 ) : 0;
    if ( ! is_array( $proof ) || (int) ( $proof['mobile_breakpoint'] ?? 0 ) !== $boundary
        || $boundary < 480 || $boundary > 1100 ) { return; }
    echo '<style id="mad4b-native-lazy-layout-proof-v1" data-no-optimize="1">'
       // Flex ancestors grow from the native lazy spinner width unless
       // the verified child makes the Elementor Template full-width first.
       . '.elementor-widget-template:has(.mad4b-native-measured-lazy){'
       . 'inline-size:100%!important;min-inline-size:0!important}'
       . '@media(max-width:' . $boundary . 'px){'
       . '.mad4b-native-measured-lazy .jet-listing-grid--lazy-load:not(.jet-listing-grid--lazy-load-completed){'
       . 'min-block-size:var(--mad4b-measured-card-height)!important;inline-size:100%!important;box-sizing:border-box}}'
       . '@media(min-width:' . ( $boundary + 1 ) . 'px){'
       . '.mad4b-native-measured-lazy .jet-listing-grid--lazy-load:not(.jet-listing-grid--lazy-load-completed){'
       . 'min-block-size:var(--mad4b-measured-wide-height)!important;inline-size:100%!important;box-sizing:border-box}}'
       . '</style>';
}, 18 );

/**
 * Provider-neutral filter scope: public archives are not proven independent
 * from JetSmartFilters just because no query parameter is yet applied.
 * A provider may admit a particular verified archive with an explicit hook;
 * no page, Query Builder, company or Elementor IDs are embedded here.
 */
function mad4b_secondary_grid_lazy_unfiltered_render_scope( $document_id = 0 ) {
    if ( is_front_page() ) { return true; }
    if ( is_tax() || is_category() || is_tag() || is_post_type_archive() ) {
        return (bool) apply_filters( 'mad4b/secondary_listing_lazy/archive_filter_independent', false, $document_id );
    }
    $document_id = absint( $document_id );
    if ( ! $document_id || get_post_status( $document_id ) !== 'publish' ) { return false; }
    $raw = get_post_meta( $document_id, '_elementor_data', true );
    $nodes = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
    if ( ! is_array( $nodes ) ) { return false; }
    $filter_widgets = false;
    $walk = static function ( $elements ) use ( &$walk, &$filter_widgets ) {
        foreach ( (array) $elements as $element ) {
            if ( ! is_array( $element ) ) { continue; }
            $name = (string) ( $element['widgetType'] ?? '' );
            if ( preg_match( '/(?:jet.*filter|filter.*jet|smart[-_]?filter)/i', $name ) ) {
                $filter_widgets = true;
                return;
            }
            if ( ! empty( $element['elements'] ) ) { $walk( $element['elements'] ); }
            if ( $filter_widgets ) { return; }
        }
    };
    $walk( $nodes );
    return ! $filter_widgets && (bool) apply_filters(
        'mad4b/secondary_listing_lazy/verified_unfiltered_document', true, $document_id
    );
}

function mad4b_secondary_grid_lazy_eligible( $settings ) {
    static $decisions = [];
    if ( ! is_array( $settings ) || ! mad4b_secondary_grid_lazy_context()
        || ( $settings['mad4b_placement'] ?? '' ) === 'above_fold' ) { return false; }
    $query_id = absint( $settings['custom_query_id'] ?? 0 );
    $listing_id = absint( $settings['lisitng_id'] ?? 0 ); // JetEngine's own spelling.
    if ( ! $query_id || ! $listing_id || empty( $settings['custom_query'] ) || ! filter_var( $settings['custom_query'], FILTER_VALIDATE_BOOLEAN ) ) { return false; }
    foreach ( [ 'is_archive_template', 'use_load_more', 'pagination', 'use_load_more_pagination' ] as $key ) {
        if ( ! empty( $settings[ $key ] ) && filter_var( $settings[ $key ], FILTER_VALIDATE_BOOLEAN ) ) { return false; }
    }
    $setting_mode = (string) get_post_meta( $listing_id, '_mad4b_secondary_lazy_mode', true );
    if ( 'off' === $setting_mode ) { return false; }
    $document_id = 0;
    if ( class_exists( 'Elementor\\Plugin' ) ) {
        $manager = \Elementor\Plugin::$instance->documents ?? null;
        $document = is_object( $manager ) ? $manager->get_current() : null;
        if ( is_object( $document ) && method_exists( $document, 'get_main_id' ) ) {
            $document_id = absint( $document->get_main_id() );
        }
    }
    if ( ! mad4b_secondary_grid_lazy_unfiltered_render_scope( $document_id ) ) { return false; }
    $dependency = mad4b_secondary_grid_query_visibility_dependency( $query_id, $document_id );
    if ( $dependency ) {
        if ( ! $document_id || ! class_exists( 'Jet_Engine\\Query_Builder\\Manager' ) ) { return false; }
        $heavy_query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( $query_id );
        $eligible = mad4b_secondary_grid_lazy_heavy_terms_eligible(
            $settings, $heavy_query, $query_id, $listing_id, $document_id
        );
        // A native placeholder with an unknown first card height produces a
        // large mobile layout shift when JetEngine injects the first batch.
        // Keep eager SSR until the company measures this listing's layout.
        if ( $eligible && wp_is_mobile() && ! wp_doing_ajax() ) {
            return mad4b_secondary_grid_lazy_measured_mobile_height( $settings ) > 0
                && mad4b_secondary_grid_lazy_measured_mobile_height( $settings, 'wide' ) > 0;
        }
        return $eligible;
    }
    $columns = (int) ( $settings['columns'] ?? 0 );
    $context_post_type = (string) get_post_type( get_queried_object_id() );
    $key = $document_id . '|' . $listing_id . '|' . $query_id . '|' . $context_post_type . '|' . $setting_mode . '|' . $columns;
    if ( array_key_exists( $key, $decisions ) ) { return $decisions[ $key ]; }
    $decisions[ $key ] = false;
    if ( ! class_exists( '\Jet_Engine\Query_Builder\Manager' ) ) { return false; }
    $query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( $query_id );
    if ( ! $query || ! in_array( $query->query_type, [ 'terms', 'posts' ], true ) ) { return false; }
    $name = (string) $query->name;
    // Marketing-related lists are deferred; rates, extras, schedules and booking selectors never are.
    if ( preg_match( '/(?:rates?|pricing|prices?|quotes?|bookings?|orders?|costs?|suppliers?|availability|occupancy|packages?|tiers?|durations?|day[ _-]?(?:time|of[ _-]?week)|extras?|filters?|filtros?|filtres?)/i', $name ) ) { return false; }
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
    $allow = $allow && mad4b_secondary_grid_lazy_has_enough_results( $settings, $query );
    // Generic secondary grids need the same two-band mobile CSS proof as
    // large conditional sliders. Never replace an unmeasured mobile card with
    // a 36px native spinner even when a Query Builder result count is large.
    if ( $allow && wp_is_mobile() && ! wp_doing_ajax() ) {
        $allow = mad4b_secondary_grid_lazy_measured_mobile_height( $settings ) > 0
            && mad4b_secondary_grid_lazy_measured_mobile_height( $settings, 'wide' ) > 0;
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

/**
 * Native inner-page progressive rendering for heavy Query Builder term Sliders.
 * Adds NO endpoint or duplicate widget. JetEngine validates the original
 * Elementor widget and signs subsequent listing_load_more requests.
 * The canary switch protects existing cache cohorts until browser coverage.
 */
function mad4b_secondary_grid_inner_enabled() {
    $mode = (string) get_option( 'mad4b_secondary_grid_lazy_inner_mode', 'off' );
    if ( $mode === 'auto' ) { return true; }
    if ( $mode !== 'canary' ) { return false; }
    if ( ! wp_doing_ajax() ) {
        return (string) ( $_GET['utm_mad4b_inner'] ?? '' ) === '1';
    }
    $ref = (string) ( $_SERVER['HTTP_REFERER'] ?? '' );
    $parts = wp_parse_url( $ref );
    if ( ! is_array( $parts ) || ! isset( $parts['host'] )
        || strtolower( (string) $parts['host'] ) !== strtolower( (string) wp_parse_url( home_url('/'), PHP_URL_HOST ) ) ) {
        return false;
    }
    parse_str( (string) ( $parts['query'] ?? '' ), $params );
    return (string) ( $params['utm_mad4b_inner'] ?? '' ) === '1';
}

/**
 * Verify public WordPress context for native JetEngine AJAX on all page types.
 * `queried_id` is provided by the native listing protocol. A missing, private,
 * or non-public object fails closed, never widening data access.
 */
function mad4b_secondary_grid_inner_public_context_verified() {
    // CLI can verify a native payload without changing browser privileges.
    if ( ! wp_doing_ajax() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        return (bool) mad4b_secondary_grid_lazy_public_render_family();
    }
    $data = (array) ( $_REQUEST['page_settings'] ?? [] );
    $value = (string) ( $data['queried_id'] ?? '' );
    if ( ! preg_match( '/^([1-9][0-9]*)\|(WP_Post|WP_Term)$/D', $value, $parts ) ) { return false; }
    $id = absint( $parts[1] );
    if ( $parts[2] === 'WP_Term' ) {
        $term = get_term( $id );
        if ( ! $term || is_wp_error( $term ) ) { return false; }
        $taxonomy = get_taxonomy( $term->taxonomy ?? '' );
        return (bool) ( $taxonomy && ! empty( $taxonomy->public ) );
    }
    if ( get_post_status( $id ) !== 'publish' ) { return false; }
    $type = (string) get_post_type( $id );
    $post_type = $type ? get_post_type_object( $type ) : null;
    return (bool) ( $post_type && ! empty( $post_type->public )
        && ! preg_match( '/(?:booking|order|quote|checkout|payment|customer|reservation|rate)/i', $type ) );
}

/** Resolve widget by its native immutable Elementor ID, not guessed markup. */
function mad4b_secondary_grid_inner_source_widget( $document_id, $element_id ) {
    if ( get_post_status( $document_id ) !== 'publish' || ! $element_id ) { return false; }
    $type = (string) get_post_type( $document_id );
    if ( $type !== 'elementor_library' ) {
        $object = $type ? get_post_type_object( $type ) : null;
        if ( ! $object || empty( $object->public )
            || preg_match( '/(?:booking|order|quote|checkout|payment|customer|reservation|rate)/i', $type ) ) {
            return false;
        }
    }
    $source = get_post_meta( $document_id, '_elementor_data', true );
    $nodes = is_string( $source ) ? json_decode( $source, true ) : $source;
    if ( ! is_array( $nodes ) ) { return false; }
    $matched = [];
    $walk = static function ( $elements ) use ( &$walk, &$matched, $element_id ) {
        foreach ( (array) $elements as $element ) {
            if ( ! is_array( $element ) ) { continue; }
            if ( ( $element['id'] ?? '' ) === $element_id
                && ( $element['widgetType'] ?? '' ) === 'jet-listing-grid' ) {
                $matched[] = (array) ( $element['settings'] ?? [] );
            }
            if ( ! empty( $element['elements'] ) ) { $walk( $element['elements'] ); }
        }
    };
    $walk( $nodes );
    return count( $matched ) === 1 ? $matched[0] : false;
}

/** Device-specific batch size is inherited from the existing company policy. */
function mad4b_secondary_grid_inner_batch( $settings ) {
    if ( ! is_array( $settings ) ) { return 0; }
    $device = class_exists( 'MAD4B_Company_Policy_Registry_V2' )
        ? MAD4B_Company_Policy_Registry_V2::device() : ( wp_is_mobile() ? 'mobile' : 'desktop' );
    // Tablet User-Agent inherits tablet Elementor columns while honoring the
    // existing company device filter; no browser-width trust in signed AJAX.
    if ( $device === 'mobile' && preg_match( '/(?:ipad|tablet|android(?!.*mobile))/i', (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) ) {
        $device = 'tablet';
    }
    $device = apply_filters( 'mad4b/secondary_listing_lazy/inner_device', $device, $settings );
    if ( ! in_array( $device, [ 'mobile', 'tablet', 'desktop' ], true ) ) { return 0; }
    $columns = $device === 'mobile'
        ? (int) ( ( $settings['columns_mobile'] ?? 0 ) ?: ( ( $settings['columns_tablet'] ?? 0 ) ?: ( $settings['columns'] ?? 1 ) ) )
        : ( $device === 'tablet'
            ? (int) ( ( $settings['columns_tablet'] ?? 0 ) ?: ( $settings['columns'] ?? 2 ) )
            : (int) ( $settings['columns'] ?? 3 ) );
    if ( $columns < 1 || $columns > 12 ) { return 0; }
    $policy = class_exists( 'MAD4B_Company_Policy_Registry_V2' )
        ? MAD4B_Company_Policy_Registry_V2::render_policy( $settings ) : [];
    if ( ( $policy['values']['strategy'] ?? 'off' ) !== 'ajax_only' ) { return 0; }
    $rows = (int) ( $policy['values']['rows'] ?? 2 );
    if ( $rows < 1 || $rows > 6 ) { return 0; }
    return max( $columns + 1, min( 36, $columns * $rows ) );
}

/** Check the actual saved widget, page context and Query Builder relation. */
function mad4b_secondary_grid_inner_admit( $settings, $document_id, $element_id ) {
    if ( ! mad4b_secondary_grid_inner_enabled() ) { return 0; }
    $document_id = absint( $document_id );
    if ( ! mad4b_secondary_grid_inner_public_context_verified()
        || ( ! is_front_page() && ! mad4b_secondary_grid_lazy_public_expansion_allowed() ) ) { return 0; }
    $source = mad4b_secondary_grid_inner_source_widget( $document_id, (string) $element_id );
    if ( ! $source || ! is_array( $settings ) ) { return 0; }
    $query_id = absint( $source['custom_query_id'] ?? 0 );
    $listing_id = absint( $source['lisitng_id'] ?? 0 );
    if ( ! $query_id || ! $listing_id
        || $query_id !== absint( $settings['custom_query_id'] ?? 0 )
        || $listing_id !== absint( $settings['lisitng_id'] ?? 0 )
        || empty( $source['custom_query'] ) ) { return 0; }
    foreach ( [ 'is_archive_template', 'pagination', 'use_load_more', 'use_load_more_pagination', 'use_random_posts_num' ] as $key ) {
        if ( ! empty( $source[$key] ) && filter_var( $source[$key], FILTER_VALIDATE_BOOLEAN ) ) { return 0; }
    }
    if ( ! mad4b_secondary_grid_lazy_parent_condition_proven( $query_id, $listing_id, $document_id ) ) { return 0; }
    if ( ! class_exists( 'Jet_Engine\\Query_Builder\\Manager' ) ) { return 0; }
    $query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( $query_id );
    if ( ! mad4b_secondary_grid_lazy_heavy_terms_eligible( $source, $query, $query_id, $listing_id, $document_id, true ) ) { return 0; }
    $batch = mad4b_secondary_grid_inner_batch( $source );
    if ( ! $batch || $batch >= (int) $query->get_items_total_count() ) { return 0; }
    return $batch;
}

/** Mutation happens after JetEngine validated the saved widget + request. */
add_action( 'jet-engine/ajax-handlers/before-call-handler', static function () {
    if ( ! wp_doing_ajax() || (string) ( $_REQUEST['handler'] ?? '' ) !== 'get_listing'
        || empty( $_REQUEST['widget_settings'] ) || ! is_array( $_REQUEST['widget_settings'] ) ) { return; }
    $request = (array) ( $_REQUEST['page_settings'] ?? [] );
    $document_id = absint( $request['post_id'] ?? 0 );
    $element_id = sanitize_key( (string) ( $request['element_id'] ?? '' ) );
    $settings = (array) $_REQUEST['widget_settings'];
    $batch = mad4b_secondary_grid_inner_admit( $settings, $document_id, $element_id );
    if ( ! $batch ) { return; }
    $id = 'mad4b-native-inner-' . $document_id . '-' . $element_id;
    $_REQUEST['widget_settings']['use_load_more'] = 'yes';
    $_REQUEST['widget_settings']['load_more_type'] = 'click';
    $_REQUEST['widget_settings']['load_more_id'] = $id;
    $_REQUEST['widget_settings']['posts_num'] = (string) $batch;
    $_REQUEST['widget_settings']['_mad4b_inner_batch'] = $batch;
    $_REQUEST['widget_settings']['_mad4b_inner_original_loop'] = $settings['infinite'] ?? 'true';
    // Swiper's native infinite loop cannot be enabled until all pages exist.
    $_REQUEST['widget_settings']['infinite'] = 'false';
}, 26, 0 );

/** Limit only the trusted native Query Builder instance during first render. */
add_action( 'jet-engine/query-builder/listings/on-query', static function ( $query, $settings ) {
    if ( (string) ( $_REQUEST['handler'] ?? '' ) !== 'get_listing'
        || ! is_object( $query ) || $query->query_type !== 'terms'
        || empty( $settings['_mad4b_inner_batch'] )
        || absint( $settings['custom_query_id'] ?? 0 ) !== absint( $query->id ?? 0 )
        || ! method_exists( $query, 'set_filtered_prop' ) ) { return; }
    $batch = absint( $settings['_mad4b_inner_batch'] );
    if ( $batch >= 2 && $batch <= 36 ) { $query->set_filtered_prop( '_items_per_page', $batch ); }
}, 8, 2 );

/** Before Query Builder derives _page, restore the signed first-page batch. */
add_action( 'jet-engine/query-builder/query/after-query-setup', static function ( $query ) {
    if ( (string) ( $_REQUEST['handler'] ?? '' ) !== 'listing_load_more'
        || ! is_object( $query ) || $query->query_type !== 'terms'
        || ! preg_match('/^mad4b-native-inner-[0-9]+-[a-z0-9]+$/', (string) ( $_REQUEST['widget_settings']['load_more_id'] ?? '' ))
        || absint( $_REQUEST['query']['query_id'] ?? 0 ) !== absint( $query->id ?? 0 )
        || ! method_exists( $query, 'set_filtered_prop' ) ) { return; }
    $settings = (array) $_REQUEST['widget_settings'];
    $batch = absint( $settings['posts_num'] ?? 0 );
    if ( $batch >= 2 && $batch <= 36
        && absint( $settings['custom_query_id'] ?? 0 ) === absint( $query->id ?? 0 ) ) {
        $query->set_filtered_prop( '_items_per_page', $batch );
    }
}, 8, 1 );

/** Native click transport; not a second or cloned slider. */
add_action( 'jet-engine/listing/grid/after', static function ( $render ) {
    if ( ! wp_doing_ajax() || (string) ( $_REQUEST['handler'] ?? '' ) !== 'get_listing' ) { return; }
    $settings = is_object($render) && method_exists($render,'get_settings') ? $render->get_settings() : [];
    if ( empty($settings['_mad4b_inner_batch']) || empty($settings['load_more_id']) ) { return; }
    $id = (string) $settings['load_more_id'];
    if ( ! preg_match( '/^mad4b-native-inner-[0-9]+-[a-z0-9]+$/', $id ) ) { return; }
    echo '<button type="button" hidden tabindex="-1" aria-hidden="true"'
        . ' data-mad4b-inner-original-loop="' . esc_attr( (string) ( $settings['_mad4b_inner_original_loop'] ?? 'false' ) ) . '"'
        . ' data-mad4b-retry-label="' . esc_attr( __( 'Reload to continue', 'jet-engine' ) ) . '"'
        . ' id="' . esc_attr($id) . '"></button>';
}, 150, 1 );

/** Register only: Elementor's admitted widget enqueues the original adapter.
 * Unrelated public pages no longer carry this Slider-only JavaScript. */
add_action( 'wp_enqueue_scripts', static function () {
    if ( ! mad4b_secondary_grid_inner_enabled() || ! mad4b_secondary_grid_lazy_context() ) { return; }
    wp_register_script( 'mad4b-secondary-inner-native-v1',
        plugin_dir_url(__FILE__) . 'assets/mad4b-secondary-inner-native-v1.js',
        ['jquery'], '1.0.8', true );
}, 999 );

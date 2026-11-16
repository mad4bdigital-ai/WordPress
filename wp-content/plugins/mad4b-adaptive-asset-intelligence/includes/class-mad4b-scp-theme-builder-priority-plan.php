<?php
/**
 * Theme Builder-first, top-to-bottom priority inventory.
 * Read only: does not infer that any candidate template is active for a route.
 */
defined( 'ABSPATH' ) || exit;

final class MAD4B_SCP_Theme_Builder_Priority_Plan {
    const CONTRACT = 'mad4b.theme-builder-priority.v1';
    const MAX_TEMPLATES = 240;
    const MAX_ITEMS_PER_TEMPLATE = 400;

    private static function stage( string $type ): array {
        if ( 'header' === $type ) return [ 0, 'P0', 'header', 'initial_candidate' ];
        if ( in_array( $type, [ 'single', 'single-page', 'single-post', 'archive', 'search-results', 'error-404' ], true ) )
            return [ 1, 'P1', 'main_template', 'initial_candidate' ];
        if ( 'footer' === $type ) return [ 3, 'P3', 'footer', 'later_candidate' ];
        if ( 'popup' === $type ) return [ 4, 'P4', 'interaction_template', 'conditional_candidate' ];
        return [ 5, 'P5', 'other', 'not_assigned_to_priority' ];
    }

    private static function flatten( array $items, array &$out, int &$total, array &$signals,
        array $path = [], int $depth = 0 ): void {
        if ( $depth > 35 ) return;
        foreach ( $items as $i => $node ) {
            if ( ! is_array( $node ) ) continue;
            ++$total;
            $ordinal_path = array_merge( $path, [ (int) $i + 1 ] );
            $s = $node['settings'] ?? [];
            if ( ! is_array( $s ) ) $s = [];
            $is_conditional = ! empty( $s['jedv_enabled'] );
            $widget = (string) ( $node['widgetType'] ?? '' );
            $name = $widget ?: (string) ( $node['elType'] ?? 'unknown' );
            $jet_enabled = filter_var( $s['jedv_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN );
            $jet_rules = (array) ( $s['jedv_conditions'] ?? [] );
            $has_filter_ui = str_starts_with( $widget, 'jet-smart-filters-' );
            $is_listing = in_array( $widget, [ 'jet-listing-grid', 'jet-listing-dynamic-listing' ], true );
            $has_display_config = ! empty( $s['display_condition_list'] );
            $signals['jet_visibility_active'] += $jet_enabled ? 1 : 0;
            $signals['jet_visibility_saved_rules'] += count( $jet_rules );
            $signals['jet_visibility_inactive_config'] += ! $jet_enabled && count( $jet_rules ) > 0 ? 1 : 0;
            $signals['display_condition_configured'] += $has_display_config ? 1 : 0;
            $signals['smart_filter_widgets'] += $has_filter_ui ? 1 : 0;
            $signals['listing_widgets'] += $is_listing ? 1 : 0;
            $signals['query_builder_bindings'] += ! empty( $s['custom_query_id'] ) ? 1 : 0;
            $signals['filter_provider_bindings'] += $has_filter_ui && ! empty( $s['content_provider'] ) ? 1 : 0;

            if ( count( $out ) < self::MAX_ITEMS_PER_TEMPLATE ) {
                $entry = [
                    'order' => implode( '.', $ordinal_path ),
                    'id' => sanitize_key( (string) ( $node['id'] ?? '' ) ),
                    'depth' => $depth,
                    'type' => $name,
                    'conditional' => $is_conditional,
                    'rule_count' => $is_conditional ? count( (array) ( $s['jedv_conditions'] ?? [] ) ) : 0,
                    'dynamic' => ! empty( $s['__dynamic__'] ),
                    'responsive_variants' => ! empty( $s['hide_desktop'] )
                        || ! empty( $s['hide_tablet'] ) || ! empty( $s['hide_mobile'] ),
                    'template_dependency' => 0,
                    'query_dependency' => 0,
                    'evaluation' => $jet_enabled ? 'jetengine_native_visibility_at_render' : 'normal_render',
                    'provider_signals' => array_values( array_filter( [
                        $jet_enabled ? 'jetengine_dynamic_visibility_active' : null,
                        ! $jet_enabled && count( $jet_rules ) ? 'jetengine_visibility_saved_inactive' : null,
                        $has_display_config ? 'other_display_condition_settings_saved_unverified' : null,
                        $has_filter_ui ? 'jetsmartfilters_interactive_provider' : null,
                        $is_listing ? 'jetengine_listing' : null,
                        ! empty( $s['custom_query_id'] ) ? 'jetengine_query_builder_reference' : null,
                    ] ) ),
                    'provider_condition_count' => $jet_enabled ? count( $jet_rules ) : 0,
                    'saved_condition_count' => count( $jet_rules ),
                    'filter_id' => 0,
                    'filter_provider' => '',
                    'filter_target_query_key' => '',
                    'filter_additional_providers' => [],
                    'visibility_query_references' => [],

                ];
                if ( $has_filter_ui ) {
                    $filter_ids = array_values( array_filter( array_map( 'absint', (array) ( $s['filter_id'] ?? [] ) ) ) );
                    $entry['filter_id'] = $filter_ids ? $filter_ids[0] : 0;
                    $entry['filter_provider'] = sanitize_key( (string) ( $s['content_provider'] ?? '' ) );
                    // This is a JetSmartFilters provider query key, NOT a Query Builder database ID.
                    $entry['filter_target_query_key'] = sanitize_text_field( substr( (string) ( $s['query_id'] ?? '' ), 0, 100 ) );
                    if ( ! empty( $s['additional_providers_enabled'] ) ) {
                        foreach ( array_slice( (array) ( $s['additional_providers_list'] ?? [] ), 0, 8 ) as $v ) {
                            if ( ! is_array( $v ) ) continue;
                            $entry['filter_additional_providers'][] = [
                                'provider' => sanitize_key( (string) ( $v['additional_provider'] ?? '' ) ),
                                'target' => sanitize_text_field( substr( (string) ( $v['additional_query_id'] ?? '' ), 0, 80 ) ),
                            ];
                        }
                    }
                }
                if ( $jet_enabled ) {
                    foreach ( array_slice( $jet_rules, 0, 15 ) as $rule ) {
                        if ( ! is_array( $rule ) ) continue;
                        $ref = absint( $rule['query_id'] ?? 0 );
                        if ( $ref ) $entry['visibility_query_references'][] = $ref;
                    }
                    $entry['visibility_query_references'] = array_values( array_unique( $entry['visibility_query_references'] ) );
                }
                if ( 'template' === $widget ) $entry['template_dependency'] = absint( $s['template_id'] ?? 0 );
                if ( 'jet-listing-grid' === $widget ) {
                    $entry['query_dependency'] = absint( $s['custom_query_id'] ?? 0 );
                    $entry['template_dependency'] = absint( $s['lisitng_id'] ?? 0 );
                }
                // Never infer device-specific physical Y positions from stored Elementor JSON.
                $out[] = $entry;
            }
            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) )
                self::flatten( $node['elements'], $out, $total, $signals, $ordinal_path, $depth + 1 );
        }
    }

    public static function compile(): array {
        global $wpdb;
        $types = [ 'header','single','single-page','single-post','archive',
            'search-results','error-404','footer','popup' ];
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $sql = "SELECT p.ID, p.post_title, t.meta_value AS template_type,
                  d.meta_value AS elementor_data, c.meta_value AS conditions_data
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} t ON t.post_id=p.ID
                    AND t.meta_key='_elementor_template_type'
                LEFT JOIN {$wpdb->postmeta} d ON d.post_id=p.ID
                    AND d.meta_key='_elementor_data'
                LEFT JOIN {$wpdb->postmeta} c ON c.post_id=p.ID
                    AND c.meta_key='_elementor_conditions'
                WHERE p.post_type='elementor_library' AND p.post_status='publish'
                  AND t.meta_value IN ($placeholders)
                ORDER BY p.ID ASC LIMIT %d";
        $arguments = array_merge( $types, [ self::MAX_TEMPLATES + 1 ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $arguments ), ARRAY_A );
        if ( ! is_array( $rows ) ) return [ 'state' => 'query_failed', 'contract' => self::CONTRACT ];
        $truncated = count( $rows ) > self::MAX_TEMPLATES;
        $rows = array_slice( $rows, 0, self::MAX_TEMPLATES );
        $translations = [];
        if ( $rows ) {
            $ids = array_map( static fn($r) => (int) $r['ID'], $rows );
            $sql_ids = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // WPML is optional: missing translation table simply yields no language inheritance.
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'icl_translations' ) ) ) {
                $tr = $wpdb->get_results( $wpdb->prepare(
                    "SELECT element_id,trid,language_code,source_language_code
                     FROM {$wpdb->prefix}icl_translations
                     WHERE element_type='post_elementor_library' AND element_id IN ($sql_ids)", $ids ), ARRAY_A );
                foreach ( (array) $tr as $x ) $translations[(int) $x['element_id']] = $x;
            }
        }
        $groups = [];
        foreach ( $rows as $row ) {
            $id = (int) $row['ID'];
            $conditions = maybe_unserialize( $row['conditions_data'] ?? '' );
            $trid = (int) ( $translations[$id]['trid'] ?? 0 );
            if ( $trid && is_array( $conditions ) && $conditions )
                $groups[$trid] = $id;
        }
        $templates = [];
        $assigned = 0; $translated = 0; $unassigned = 0;
        foreach ( $rows as $row ) {
            $id = (int) $row['ID'];
            $type = (string) $row['template_type'];
            $rank = self::stage( $type );
            $conditions = maybe_unserialize( $row['conditions_data'] ?? '' );
            $conditions = is_array( $conditions ) ? array_values( array_filter( $conditions, 'is_string' ) ) : [];
            $trid = (int) ( $translations[$id]['trid'] ?? 0 );
            $anchor = $trid && isset( $groups[$trid] ) ? (int) $groups[$trid] : 0;
            $state = $conditions ? 'assigned_by_theme_builder'
                : ( $anchor && $anchor !== $id ? 'translation_of_assigned_template' : 'unassigned' );
            if ( 'assigned_by_theme_builder' === $state ) ++$assigned;
            elseif ( 'translation_of_assigned_template' === $state ) ++$translated;
            else ++$unassigned;
            $tree = json_decode( (string) ( $row['elementor_data'] ?? '' ), true );
            $preview = []; $total = 0;
            $signals = [
                'jet_visibility_active' => 0, 'jet_visibility_saved_rules' => 0,
                'jet_visibility_inactive_config' => 0, 'display_condition_configured' => 0,
                'smart_filter_widgets' => 0, 'listing_widgets' => 0,
                'query_builder_bindings' => 0, 'filter_provider_bindings' => 0,
            ];
            if ( is_array( $tree ) ) self::flatten( $tree, $preview, $total, $signals );
            $templates[] = [
                'document_id' => $id,
                'name' => sanitize_text_field( (string) $row['post_title'] ),
                'template_type' => $type, 'priority' => $rank[1],
                'location' => $rank[2], 'stage_order' => $rank[0],
                'load_candidate' => $rank[3], 'assignment' => $state,
                'assignment_source_id' => $conditions ? $id : $anchor,
                'language' => sanitize_key( (string) ( $translations[$id]['language_code'] ?? '' ) ),
                'condition_count' => $conditions ? count( $conditions ) : 0,
                'node_total' => $total,
                'provider_discovery' => $signals,
                'top_to_bottom' => $preview,
                'preview_truncated' => $total > count( $preview ),
                'priority_is_candidate' => true,
                'route_eligibility_verified' => false,
                'visual_y_verified' => false,
            ];
        }
        $providers = [
            'jet_visibility_active' => 0, 'jet_visibility_saved_rules' => 0,
            'jet_visibility_inactive_config' => 0, 'display_condition_configured' => 0,
            'smart_filter_widgets' => 0, 'listing_widgets' => 0,
            'query_builder_bindings' => 0, 'filter_provider_bindings' => 0,
        ];
        foreach ( $templates as $doc ) {
            foreach ( $doc['provider_discovery'] as $name => $value ) $providers[$name] += $value;
        }
        usort( $templates, static function($a,$b) {
            $x = $a['assignment']==='unassigned' ? 1 : 0;
            $y = $b['assignment']==='unassigned' ? 1 : 0;
            return [ $x, $a['stage_order'], $a['language'], $a['document_id'] ]
                <=> [ $y, $b['stage_order'], $b['language'], $b['document_id'] ];
        } );
        return [
            'contract' => self::CONTRACT,
            'provider_discovery' => $providers,
            'provider_semantics' => [
                'elementor_theme_conditions' => 'template_assignment_rules',
                'jetengine_dynamic_visibility' => 'activated_only_with_jedv_enabled',
                'jetengine_listing_grid' => 'nested_listing_and_query_builder_dependencies',
                'jetsmartfilters' => 'interactive_filter_and_provider_target_not_visibility_rule',
                'other_display_condition_list' => 'stored_configuration_not_proof_of_active_visibility',
            ],
            'selection_basis' => 'elementor_theme_builder_type_conditions_wpml_translation_group',
            'ordering_basis' => 'stored_elementor_tree_preorder_not_measured_visual_y',
            'route_selection' => 'elementor_pro_conditions_must_resolve_per_page',
            'responsive_selection' => 'browser_worker_y_coordinate_required',
            'render_changes_enabled' => false,
            'preload_automatic_enabled' => false,
            'published_theme_builder_templates' => count( $templates ),
            'assigned_templates' => $assigned,
            'translation_variants' => $translated,
            'unassigned_templates' => $unassigned,
            'truncated' => $truncated,
            'priority_stages' => [
                [ 'priority' => 'P0', 'area' => 'header' ],
                [ 'priority' => 'P1', 'area' => 'matched_single_or_archive' ],
                [ 'priority' => 'P2', 'area' => 'within_template_top_to_bottom' ],
                [ 'priority' => 'P3', 'area' => 'footer' ],
                [ 'priority' => 'P4', 'area' => 'conditional_popups' ],
            ],
            'templates' => $templates,
        ];
    }
}

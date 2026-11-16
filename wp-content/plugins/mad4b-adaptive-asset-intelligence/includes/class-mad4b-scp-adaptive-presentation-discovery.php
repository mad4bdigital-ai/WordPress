<?php
/**
 * MAD4B: company-scoped, read-only predictive presentation discovery.
 * Scans Elementor's stored tree, not rendered visitor output or booking prices.
 */
defined( 'ABSPATH' ) || exit;

final class MAD4B_SCP_Adaptive_Presentation_Discovery {
    const OPTION = 'mad4b_adaptive_presentation_discovery_v1';
    const DIRTY = 'mad4b_adaptive_presentation_scan_needed_v1';
    const CONTRACT = 'mad4b.presentation-discovery.v1';

    public static function status(): array {
        $identity = MAD4B_SCP_Adaptive_Asset_Intelligence::identity();
        if ( ! $identity ) return [ 'state' => 'site_profile_unavailable' ];
        $data = get_option( self::OPTION, [] );
        if ( ! is_array( $data ) || ( $data['site_uuid'] ?? '' ) !== $identity['uuid']
            || ( $data['site_host'] ?? '' ) !== $identity['host'] ) {
            return [ 'state' => 'not_scanned', 'contract' => self::CONTRACT,
                'site_uuid' => $identity['uuid'], 'planning_only' => true ];
        }
        $changed = (int) get_option( self::DIRTY, 0 );
        $data['state'] = $changed !== 0
            ? 'refresh_queued' : ( ( $data['truncated'] ?? false ) ? 'partial' : 'observed' );
        return $data;
    }

    public static function queue_refresh(): void {
        if ( ! MAD4B_SCP_Adaptive_Asset_Intelligence::scope() ) return;
        // Nonzero Dirty means unacknowledged content drift. Increment even
        // within one second so a concurrent scan cannot erase a later edit.
        // This monotonic marker is NOT compared with scan timestamps.
        $previous = (int) get_option( self::DIRTY, 0 );
        update_option( self::DIRTY, max( time(), $previous + 1 ), false );
        if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' )
            && ! wp_next_scheduled( 'mad4b_adaptive_presentation_scan' ) ) {
            wp_schedule_single_event( time() + 90, 'mad4b_adaptive_presentation_scan' );
        }
    }

    public static function scan(): array {
        $identity = MAD4B_SCP_Adaptive_Asset_Intelligence::identity();
        if ( ! $identity ) return [ 'state' => 'site_profile_unavailable' ];
        $dirty_revision_at_start = (int) get_option( self::DIRTY, 0 );
        global $wpdb;
        $conditions = [];
        if ( class_exists( '\Jet_Engine\Modules\Dynamic_Visibility\Module' ) ) {
            $module = \Jet_Engine\Modules\Dynamic_Visibility\Module::instance();
            if ( isset( $module->conditions ) && method_exists( $module->conditions, 'get_conditions_for_options' ) ) {
                $available = $module->conditions->get_conditions_for_options();
                if ( is_array( $available ) ) $conditions = array_fill_keys( array_keys( $available ), true );
            }
        }
        $counts = [ 'documents' => 0, 'nodes' => 0, 'widgets' => 0,
            'conditional_elements' => 0, 'rules' => 0, 'dynamic_bindings' => 0,
            'query_dependencies' => 0, 'sensitive_widgets' => 0 ];
        $widget_types = [];
        $rule_types = [];
        $unknown_rules = [];
        $query_refs = [];
        $last_id = 0;
        $truncated = false;
        $guard = 0;
        // Stable source revision, independent of scanner timestamps. Capture
        // every *published* Elementor document including exact rule values.
        $graph_digest = hash_init('sha256');
        $published_digest_documents = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.ID, p.post_type, p.post_status, pm.meta_value FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
                 WHERE pm.meta_key='_elementor_data'
                   AND p.post_status IN ('publish','draft','private')
                   AND p.ID > %d
                 ORDER BY p.ID ASC LIMIT 80", $last_id ), ARRAY_A );
            if ( ! is_array( $rows ) ) return [ 'state' => 'database_read_failed' ];
            foreach ( $rows as $row ) {
                $last_id = (int) $row['ID'];
                $tree = json_decode( (string) $row['meta_value'], true );
                if ( ! is_array( $tree ) ) continue;
                if (($row['post_status'] ?? '') === 'publish') {
                    hash_update($graph_digest,(string)$row['ID'].'|'.(string)$row['post_type'].'|'.
                        hash('sha256',(string)$row['meta_value']).';');
                    ++$published_digest_documents;
                }
                ++$counts['documents'];
                self::traverse( $tree, $counts, $widget_types, $rule_types,
                    $unknown_rules, $query_refs, $conditions, 0 );
            }
            ++$guard;
            if ( $guard >= 1000 && count( $rows ) === 80 ) {
                $truncated = true;
                break;
            }
        } while ( count( $rows ) === 80 );
        arsort( $widget_types );
        arsort( $rule_types );
        $assets = [];
        $unknown_widgets = [];
        if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
            $manager = \Elementor\Plugin::$instance->widgets_manager;
            foreach ( $widget_types as $name => $n ) {
                $widget = $manager->get_widget_types( $name );
                if ( ! is_object( $widget ) ) {
                    $unknown_widgets[] = $name;
                    continue;
                }
                // Third-party widgets can read uninitialized Elementor settings here.
                // Never construct a fake widget context or claim a dependent handle.
                try {
                    $css = method_exists( $widget, 'get_style_depends' ) ? (array) $widget->get_style_depends() : [];
                    $js = method_exists( $widget, 'get_script_depends' ) ? (array) $widget->get_script_depends() : [];
                } catch ( \Throwable $error ) {
                    $unknown_widgets[] = $name . ':runtime_context_required';
                    continue;
                }
                $css = array_values( array_filter( $css, 'is_string' ) );
                $js = array_values( array_filter( $js, 'is_string' ) );
                if ( $css || $js ) $assets[ $name ] = [ 'css' => array_slice( $css, 0, 20 ),
                    'js' => array_slice( $js, 0, 20 ) ];
            }
        }
        $priority = class_exists( 'MAD4B_SCP_Theme_Builder_Priority_Plan', false )
            ? MAD4B_SCP_Theme_Builder_Priority_Plan::compile()
            : [ 'state' => 'priority_provider_unavailable' ];
        $priority_summary = $priority;
        unset( $priority_summary['templates'] );
        if ( isset( $priority['templates'] ) && is_array( $priority['templates'] ) ) {
            update_option( 'mad4b_adaptive_theme_builder_priority_v1', $priority, false );
        }
        $snapshot = [
            'theme_builder_priority' => $priority_summary,
            'contract' => self::CONTRACT,
            'site_uuid' => $identity['uuid'], 'site_host' => $identity['host'],
            'environment' => $identity['environment'],
            'scanned_at' => time(), 'state' => $truncated ? 'partial' : 'observed',
            'truncated' => $truncated, 'counts' => $counts,
            'published_graph_digest' => hash_final($graph_digest),
            'published_digest_documents' => $published_digest_documents,
            'registered_condition_types' => count( $conditions ),
            'condition_types_seen' => $rule_types,
            'unknown_condition_types' => array_values( array_keys( $unknown_rules ) ),
            'unknown_widget_types' => array_slice( $unknown_widgets, 0, 50 ),
            'widget_types' => array_slice( $widget_types, 0, 35, true ),
            'queried_builder_references' => count( $query_refs ),
            'asset_dependencies_by_widget' => array_slice( $assets, 0, 50, true ),
            'available_systems' => [
                'elementor' => class_exists( '\Elementor\Plugin' ),
                'jetengine' => class_exists( '\Jet_Engine\Modules\Dynamic_Visibility\Module' ),
                'semantic_structure_intelligence' => class_exists( 'MAD4B_SCP_Semantic_Structure_Intelligence' ),
                'external_object_cache' => wp_using_ext_object_cache(),
            ],
            'plan' => [
                'pre_calculation' => 'document_tree_and_condition_graph_observed',
                'pre_page_load' => 'asset_handles_observed_only',
                'render' => 'preserve_native_contextual_condition_evaluation',
                'early_skip_enabled' => false,
                'automatic_asset_rewrite_enabled' => false,
                'personalized_pricing_cached' => false,
            ],
            'planning_only' => true, 'read_only' => true,
        ];
        update_option( self::OPTION, $snapshot, false );
        // A source edit can arrive between reading Dirty and clearing it.
        // Use the options-table compare-and-set, never a blind update_option()
        // after the scan. It must not erase a newer revision from another WP
        // process. WordPress option cache and the existing policy hook are
        // refreshed only after an acknowledged transition to zero.
        $dirty_at_end = (int) get_option( self::DIRTY, 0 );
        $acknowledged = $dirty_at_end === $dirty_revision_at_start;
        if ( $acknowledged && $dirty_at_end !== 0 ) {
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                '0', self::DIRTY, (string) $dirty_revision_at_start
            ) );
            $acknowledged = ( 1 === $updated );
            wp_cache_delete( self::DIRTY, 'options' );
            if ( $acknowledged ) {
                do_action( 'update_option_' . self::DIRTY, $dirty_at_end, 0, self::DIRTY );
                do_action( 'updated_option', self::DIRTY, $dirty_at_end, 0 );
            }
        }
        if ( ! $acknowledged ) {
            $snapshot['state'] = 'refresh_queued';
            if ( function_exists( 'wp_schedule_single_event' )
                && ! wp_next_scheduled( 'mad4b_adaptive_presentation_scan' ) ) {
                wp_schedule_single_event( time() + 90, 'mad4b_adaptive_presentation_scan' );
            }
        }
        return $snapshot;
    }

    private static function traverse( array $nodes, array &$counts, array &$widgets,
        array &$rules, array &$unknown, array &$queries, array $known, int $depth ): void {
        if ( $depth > 40 ) return;
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) continue;
            ++$counts['nodes'];
            $type = (string) ( $node['widgetType'] ?? '' );
            if ( $type !== '' ) {
                ++$counts['widgets'];
                $widgets[ $type ] = ( $widgets[ $type ] ?? 0 ) + 1;
                if ( preg_match( '/(?:rates?|price|cost|booking|checkout|payment|customer|order|extra)/i', $type ) )
                    ++$counts['sensitive_widgets'];
            }
            $settings = $node['settings'] ?? [];
            if ( ! is_array( $settings ) ) $settings = [];
            if ( ! empty( $settings['__dynamic__'] ) ) ++$counts['dynamic_bindings'];
            if ( ! empty( $settings['jedv_enabled'] ) ) {
                ++$counts['conditional_elements'];
                foreach ( (array) ( $settings['jedv_conditions'] ?? [] ) as $rule ) {
                    if ( ! is_array( $rule ) ) continue;
                    ++$counts['rules'];
                    $kind = sanitize_key( (string) ( $rule['jedv_condition'] ?? '' ) );
                    if ( $kind === '' ) $kind = 'undefined';
                    $rules[ $kind ] = ( $rules[ $kind ] ?? 0 ) + 1;
                    if ( ! isset( $known[ $kind ] ) ) $unknown[ $kind ] = true;
                }
            }
            $qid = absint( $settings['custom_query_id'] ?? 0 );
            if ( $qid ) {
                ++$counts['query_dependencies'];
                $queries[ $qid ] = true;
            }
            if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) )
                self::traverse( $node['elements'], $counts, $widgets,
                    $rules, $unknown, $queries, $known, $depth + 1 );
        }
    }
}

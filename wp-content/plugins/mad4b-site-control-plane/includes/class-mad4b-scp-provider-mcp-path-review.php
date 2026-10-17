<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Provider MCP path evidence, not a remote executor.
 *
 * A MAD4B adapter registration is not proof the provider lacks a native
 * MCP client. Only the local WordPress Abilities registry is inspected here;
 * external provider-owned MCP servers require an independent scoped probe.
 */
final class MAD4B_SCP_Provider_MCP_Path_Review {
    const CONTRACT = 'mad4b.provider-mcp-path-review.v1';

    public static function families() {
        return array(
            'elementor' => array(
                'label' => 'Elementor',
                'read' => array( 'elementor/get-document', 'elementor/validate-document' ),
                'write' => array( 'elementor/update-widget-settings' ),
            ),
            'jetsmartfilters' => array(
                'label' => 'JetSmartFilters',
                'read' => array( 'jetsmartfilters/get-filter', 'jetsmartfilters/get-query-binding' ),
                'write' => array( 'jetsmartfilters/update-filter-meta' ),
            ),
            'jetengine' => array(
                'label' => 'JetEngine',
                'read' => array( 'jetengine/native-tools-inventory', 'jetengine/get-native-configuration' ),
                'write' => array( 'jetengine/create-cpt', 'jetengine/create-query' ),
            ),
            'wpml' => array(
                'label' => 'WPML',
                'read' => array(),
                'write' => array(),
            ),
        );
    }

    /**
     * Review a single family's observed (not assumed) WP Ability registration.
     * Missing native WordPress Abilities NEVER means an external MCP server
     * or the actual provider is incapable of writing.
     */
    public static function classify( $family, array $observed ) {
        $families = self::families();
        if ( ! isset( $families[ $family ] ) ) return array(
            'family' => (string) $family, 'state' => 'UNKNOWN_PROVIDER', 'authorizing' => false,
            'production_mutation_allowed' => false,
        );
        $spec = $families[ $family ];
        $registered_adapter = array();
        $registered_provider_native = array();
        $origin_unverified = array();
        $other_local = array();
        foreach ( $observed as $tool_name => $row ) {
            if ( 0 === strpos( (string) $tool_name, $family . '/' )
                && ! in_array( $tool_name, array_merge( $spec['read'], $spec['write'] ), true ) )
                $other_local[] = (string) $tool_name;
        }
        foreach ( array_merge( $spec['read'], $spec['write'] ) as $name ) {
            if ( ! isset( $observed[ $name ] ) || ! is_array( $observed[ $name ] ) ) continue;
            $row = $observed[ $name ];
            if ( 'mad4b-adapter' === (string) ( $row['origin'] ?? '' ) ) {
                $registered_adapter[] = $name;
            } elseif ( 'provider-native-wordpress-ability' === (string) ( $row['origin'] ?? '' ) ) {
                $registered_provider_native[] = $name;
            } else {
                $origin_unverified[] = $name;
            }
        }
        $status = $registered_provider_native ? 'NATIVE_WP_ABILITY_DISCOVERED_REVIEW_REQUIRED'
            : ( $registered_adapter ? 'MAD4B_ADAPTER_DISCOVERED_NATIVE_UNKNOWN'
                : ( $origin_unverified ? 'ABILITY_ORIGIN_UNVERIFIED'
                    : ( $other_local ? 'ADDITIONAL_LOCAL_ABILITIES_UNVERIFIED' : 'NATIVE_TRANSPORT_DISCOVERY_REQUIRED' ) ) );
        return array(
            'family' => $family, 'label' => $spec['label'],
            'state' => $status, 'native_wp_abilities' => $registered_provider_native,
            'mad4b_adapter_abilities' => $registered_adapter,
            'origin_unverified_abilities' => $origin_unverified,
            'additional_local_abilities' => array_slice( $other_local, 0, 40 ),
            'expected_read_abilities' => $spec['read'], 'expected_write_abilities' => $spec['write'],
            'external_native_mcp_state' => 'NOT_PROBED',
            'external_native_client_required_for_exhaustive_claim' => true,
            'execution_preference' => $registered_provider_native
                ? 'REVIEW_NATIVE_FIRST_THROUGH_GOVERNED_AUTHORIZATION'
                : ( $registered_adapter ? 'REVIEW_ADAPTER_WHILE_NATIVE_REMAINS_UNKNOWN'
                    : 'DISCOVER_PROVIDER_NATIVE_AND_ADAPTER_INDEPENDENTLY' ),
            'write_authorized' => false,
            'authorizing' => false, 'mutation_performed' => false,
            'production_mutation_allowed' => false,
        );
    }

    public static function observe_local() {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) )
            return new WP_Error( 'mad4b_mcp_path_admin_denied', 'Administrator required.' );
        $observed = array();
        $other_namespaces = array();
        $known = array();
        foreach ( self::families() as $spec ) foreach ( array_merge( $spec['read'], $spec['write'] ) as $name )
            $known[ $name ] = true;
        // This call observes registered WordPress Abilities; it is NOT a
        // tools/list exchange with each independent provider MCP server.
        if ( function_exists( 'wp_get_abilities' ) ) {
            $registry = wp_get_abilities();
            if ( is_array( $registry ) ) foreach ( $registry as $name => $ability ) {
                if ( ! is_object( $ability ) ) continue;
                if ( ! isset( $known[ $name ] ) ) {
                    if ( preg_match( '/^([a-z][a-z0-9_-]{1,48})\\/[a-z0-9_-]{1,120}$/D', (string) $name, $parts ) ) {
                        $namespace = $parts[1];
                        if ( 'mad4b' !== $namespace && ! isset( self::families()[ $namespace ] ) ) {
                            $other_namespaces[ $namespace ] = min( 100, (int) ( $other_namespaces[ $namespace ] ?? 0 ) + 1 );
                        } elseif ( isset( self::families()[ $namespace ] ) ) {
                            // Native or adapter origin cannot be inferred from namespace alone.
                            $observed[ $name ] = array( 'origin' => 'origin-unverified' );
                        }
                    }
                    continue;
                }
                $category = method_exists( $ability, 'get_category' )
                    ? (string) $ability->get_category() : '';
                $origin = 0 === strpos( $category, 'mad4b-' )
                    ? 'mad4b-adapter' : 'origin-unverified';
                // A non-MAD4B category alone does not authenticate a provider
                // native publisher. Only explicit, exact provider proof may
                // upgrade this origin classification in a later review.
                $observed[ $name ] = array( 'origin' => $origin );
            }
        }
        $rows = array();
        foreach ( array_keys( self::families() ) as $family )
            $rows[] = self::classify( $family, $observed );
        return array(
            'contract' => self::CONTRACT, 'scope' => 'local_wordpress_abilities_only',
            'external_native_mcp_discovery_performed' => false,
            'registered_abilities_examined' => count( $observed ),
            'providers' => $rows,
            'other_discovered_ability_namespaces' => $other_namespaces,
            'authorization_preserved' => true,
            'write_authorized' => false, 'mutation_performed' => false,
        );
    }

    public static function render() {
        $report = self::observe_local();
        echo '<section id="mad4b-native-mcp-paths"><h2>Provider MCP routes — evidence, not approval</h2>';
        echo '<p>Check native MCP discovery before treating MAD4B adapter restrictions as provider limitations. This tab inspects local WordPress Abilities only. External provider MCP clients are NOT probed and no write route is automatically authorized.</p>';
        if ( is_wp_error( $report ) ) {
            echo '<p role="alert">' . esc_html( $report->get_error_code() ) . '</p></section>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Provider</th><th>Local discovery state</th><th>Observed local tools and origin</th><th>Next step</th></tr></thead><tbody>';
        foreach ( $report['providers'] as $row ) {
            echo '<tr><th scope="row">' . esc_html( $row['label'] ) . '</th><td><code>' . esc_html( $row['state'] ) . '</code></td>';
            echo '<td>Adapter: ' . esc_html( implode( ', ', $row['mad4b_adapter_abilities'] ) ?: 'none observed' )
                . '; Other local: ' . esc_html( implode( ', ', array_merge( $row['origin_unverified_abilities'], $row['additional_local_abilities'] ) ) ?: 'none observed' ) . '</td>';
            echo '<td>' . esc_html( str_replace( '_', ' ', $row['execution_preference'] ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ( ! empty( $report['other_discovered_ability_namespaces'] ) ) {
            echo '<h3>Other registered tool namespaces (unverified origin)</h3><ul>';
            foreach ( array_slice( $report['other_discovered_ability_namespaces'], 0, 40, true ) as $name => $count ) {
                echo '<li><code>' . esc_html( $name ) . '</code>: ' . esc_html( (string) $count ) . ' locally registered ability names — native ownership and execution unverified</li>';
            }
            echo '</ul>';
        }
        echo '<p class="description">Order: independently discover native provider MCP tools; confirm exact native schema, origin and permissions; otherwise review the MAD4B adapter. Any mutation still needs MAD4B site-bound OAuth, approval, audit and independent readback. No direct ungoverned native bypass.</p></section>';
    }
}

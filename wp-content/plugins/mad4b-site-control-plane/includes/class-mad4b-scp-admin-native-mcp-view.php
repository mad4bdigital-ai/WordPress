<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Presentation-only bridge for the separately governed plugin graph and MCP
 * candidate inventory. A WordPress Admin GET must never probe peer endpoints.
 */
final class MAD4B_SCP_Admin_Native_MCP_View {
    const PAGE_SIZE = 25;

    public static function graph_model( array $graph, $offset = 0 ) {
        $offset = max( 0, (int) $offset );
        $items = isset( $graph['items'] ) && is_array( $graph['items'] ) ? $graph['items'] : array();
        $rows = array();
        foreach ( array_slice( $items, $offset, self::PAGE_SIZE ) as $item ) {
            if ( ! is_array( $item ) ) continue;
            $reads = isset( $item['registered_adapter_read_abilities'] ) && is_array( $item['registered_adapter_read_abilities'] )
                ? array_values( $item['registered_adapter_read_abilities'] ) : array();
            $writes = isset( $item['registered_adapter_write_abilities'] ) && is_array( $item['registered_adapter_write_abilities'] )
                ? $item['registered_adapter_write_abilities'] : array();
            $rows[] = array(
                'plugin_file' => (string) ( $item['plugin_file'] ?? '' ),
                'version' => (string) ( $item['version'] ?? '' ),
                'active' => ! empty( $item['active'] ),
                'coverage_state' => (string) ( $item['coverage_state'] ?? 'unknown' ),
                'registered_read_count' => count( $reads ),
                'registered_write_count' => count( $writes ),
                'read_ability' => isset( $reads[0] ) && is_string( $reads[0] ) ? $reads[0] : '',
                'certified_provider' => ! empty( $item['provider_certification_ok'] ),
                'dependency_gap' => ! empty( $item['dependency_gap'] ),
                'unreviewed_count' => (int) ( $item['unreviewed_namespace_candidates_count'] ?? 0 ),
            );
        }
        return array(
            'state' => (string) ( $graph['state'] ?? 'UNAVAILABLE' ),
            'complete' => ! empty( $graph['complete'] ),
            'sha' => (string) ( $graph['graph_sha256'] ?? '' ),
            'count' => count( $items ), 'offset' => $offset,
            'next_offset' => $offset + self::PAGE_SIZE < count( $items ) ? $offset + self::PAGE_SIZE : null,
            'rows' => $rows,
            'executing' => false, 'network_probed' => false,
        );
    }

    public static function peer_model( array $inventory, array $trust ) {
        $health = isset( $inventory['peer_transport_health'] ) && is_array( $inventory['peer_transport_health'] )
            ? $inventory['peer_transport_health'] : array();
        $ids = isset( $inventory['pinned_peer_ids'] ) && is_array( $inventory['pinned_peer_ids'] )
            ? array_slice( $inventory['pinned_peer_ids'], 0, 24 ) : array();
        $rows = array();
        foreach ( $ids as $id ) {
            if ( ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id ) ) continue;
            $peer = isset( $health[$id] ) && is_array( $health[$id] ) ? $health[$id] : array();
            $catalog = isset( $peer['catalog'] ) && is_array( $peer['catalog'] ) ? $peer['catalog'] : array();
            $read = isset( $peer['read'] ) && is_array( $peer['read'] ) ? $peer['read'] : array();
            $rows[] = array(
                'id' => $id,
                'catalog_state' => (string) ( $catalog['state'] ?? 'UNAVAILABLE' ),
                'read_state' => (string) ( $read['state'] ?? 'UNAVAILABLE' ),
                'retry_after_seconds' => max( 0, (int) ( $peer['retry_after_seconds'] ?? 0 ) ),
            );
        }
        return array(
            'rows' => $rows,
            'reviewed_source_pin_state' => (string) ( $trust['state'] ?? 'UNAVAILABLE' ),
            'reviewed_source_pin_count' => (int) ( $trust['validated_source_pin_count'] ?? 0 ),
            'external_live_discovery' => false,
            'oauth_checked' => false, 'write_certified' => false, 'mutation_performed' => false,
        );
    }

    private static function tab_url( $tab, array $args = array() ) {
        return add_query_arg( $args, MAD4B_SCP_Admin_Experience::tab_url(
            MAD4B_SCP_Adapter_Coverage_Admin_UI::PAGE_SLUG, $tab
        ) );
    }

    public static function render_graph() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $graph = MAD4B_SCP_Plugin_Discovery::capability_graph();
        $offset = isset( $_GET['offset'] ) && is_scalar( $_GET['offset'] ) && preg_match( '/^[0-9]{1,6}$/D', (string) $_GET['offset'] )
            ? (int) $_GET['offset'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pure read.
        $model = self::graph_model( is_array( $graph ) ? $graph : array(), $offset );
        echo '<h2>Installed plugin capability graph</h2>';
        echo '<p class="description">Registration is not runtime authorization. No plugin callback, external HTTP request or mutation is executed by this view.</p>';
        echo '<p>Discovery: <code>' . esc_html( $model['state'] ) . '</code> · Observed: ' . esc_html( (string) $model['count'] ) . ' · Graph SHA-256: <code>' . esc_html( $model['sha'] ?: 'unavailable' ) . '</code></p>';
        if ( ! $model['complete'] ) echo '<div class="notice notice-warning inline"><p>Inventory is partial. Route plans are ineligible until discovery is complete.</p></div>';
        echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">Plugin / version</th><th scope="col">State</th><th scope="col">Abilities registered</th><th scope="col">Review evidence</th><th scope="col">Read route</th></tr></thead><tbody>';
        foreach ( $model['rows'] as $row ) {
            echo '<tr><th scope="row"><code>' . esc_html( $row['plugin_file'] ) . '</code><br>' . esc_html( $row['version'] ) . '</th>';
            echo '<td>' . esc_html( $row['active'] ? 'active' : 'inactive' ) . ' · <code>' . esc_html( $row['coverage_state'] ) . '</code></td>';
            echo '<td>Read: ' . esc_html( (string) $row['registered_read_count'] ) . ' / Write registered: ' . esc_html( (string) $row['registered_write_count'] ) . '</td>';
            echo '<td>Provider certified: ' . esc_html( $row['certified_provider'] ? 'yes' : 'no' ) . '<br>Dependencies missing: ' . esc_html( $row['dependency_gap'] ? 'yes' : 'no' ) . '<br>Unreviewed candidates: ' . esc_html( (string) $row['unreviewed_count'] ) . '</td><td>';
            if ( '' !== $row['read_ability'] && $model['complete'] && '' !== $model['sha'] ) {
                $link = self::tab_url( 'route-preview', array(
                    'plugin_file' => $row['plugin_file'], 'ability' => $row['read_ability'],
                    'dimension' => 'read', 'expected_graph_sha256' => $model['sha'],
                ) );
                echo '<a href="' . esc_url( $link ) . '">Preview read route</a>';
            } else echo 'Not eligible for preview';
            echo '</td></tr>';
        }
        if ( ! $model['rows'] ) echo '<tr><td colspan="5">No graph rows available on this page.</td></tr>';
        echo '</tbody></table></div>';
        if ( $model['offset'] > 0 ) echo '<a class="button" href="' . esc_url( self::tab_url( 'capabilities', array( 'offset' => max( 0, $model['offset'] - self::PAGE_SIZE ) ) ) ) . '">Previous</a> ';
        if ( null !== $model['next_offset'] ) echo '<a class="button" href="' . esc_url( self::tab_url( 'capabilities', array( 'offset' => $model['next_offset'] ) ) ) . '">Next</a>';
    }

    public static function render_route_preview() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $keys = array( 'plugin_file', 'ability', 'dimension', 'expected_graph_sha256' );
        $input = array();
        foreach ( $keys as $key ) $input[$key] = isset( $_GET[$key] ) && is_scalar( $_GET[$key] )
            ? sanitize_text_field( (string) wp_unslash( $_GET[$key] ) ) : '';
        $graph = MAD4B_SCP_Plugin_Discovery::capability_graph();
        $plan = MAD4B_SCP_Plugin_Discovery::plan_from_graph( is_array( $graph ) ? $graph : array(), $input );
        echo '<h2>Read-only route preview</h2><p>No operation is executed and no owner approval or grant is created.</p>';
        echo '<table class="widefat striped"><tbody>';
        foreach ( array( 'plugin_file', 'ability', 'dimension', 'state', 'dispatcher', 'graph_sha256' ) as $key ) {
            echo '<tr><th scope="row">' . esc_html( $key ) . '</th><td><code>' . esc_html( (string) ( $plan[$key] ?? '' ) ) . '</code></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><a href="' . esc_url( self::tab_url( 'capabilities' ) ) . '">Back to capability graph</a></p>';
    }

    public static function render_peers() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $inventory = class_exists( 'MAD4B_SCP_MCP_Hybrid', false )
            ? MAD4B_SCP_MCP_Hybrid::candidate_inventory() : array();
        $trust = class_exists( 'MAD4B_SCP_External_Adapter_Trust', false )
            ? MAD4B_SCP_External_Adapter_Trust::status() : array();
        $model = self::peer_model( is_array( $inventory ) ? $inventory : array(), is_array( $trust ) ? $trust : array() );
        echo '<h2>Native MCP peers — locally pinned status</h2>';
        echo '<p class="description">No tools/list, provider OAuth handshake or remote tool is called on page load. These observations do not certify read behavior, credentials or Write.</p>';
        echo '<p>External adapter source pin: <code>' . esc_html( $model['reviewed_source_pin_state'] ) . '</code> (' . esc_html( (string) $model['reviewed_source_pin_count'] ) . ' verified manifest entries)</p>';
        echo '<div class="mad4b-scp-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">Pinned peer</th><th scope="col">Catalog circuit</th><th scope="col">Read circuit</th><th scope="col">Retry after</th></tr></thead><tbody>';
        foreach ( $model['rows'] as $row ) echo '<tr><th scope="row"><code>' . esc_html( $row['id'] ) . '</code></th><td>' . esc_html( $row['catalog_state'] ) . '</td><td>' . esc_html( $row['read_state'] ) . '</td><td>' . esc_html( (string) $row['retry_after_seconds'] ) . ' s</td></tr>';
        if ( ! $model['rows'] ) echo '<tr><td colspan="4">No owner-pinned native peer is available on this build.</td></tr>';
        echo '</tbody></table></div>';
        $connection = MAD4B_SCP_Admin_Workspace::link( 'mad4b-control-plane-connection', array( 'tab' => 'oauth' ) );
        $history = MAD4B_SCP_Admin_Workspace::link( 'mad4b-control-plane', array( 'tab' => 'audit' ) );
        echo '<p>Auth audience, scope, expiry and canary freshness are not probed in this passive view. Inspect the governed connection and audit surfaces for independent evidence.</p>';
        if ( '' !== $connection ) echo '<p><a href="' . esc_url( $connection ) . '">Connection and OAuth diagnostics</a></p>';
        if ( '' !== $history ) echo '<p><a href="' . esc_url( $history ) . '">Governance and audit history</a></p>';
    }
}

<?php
/**
 * Plugin Name: MAD4B Company Execution Reports
 * Description: Read-only Company Management view of live local Hub Queue receipts, no report sync timer.
 * Version: 0.4.0
 */
defined( 'ABSPATH' ) || exit;
final class MAD4B_Company_Execution_Reports_V1 {
    const CONTRACT = 'mad4b.company-vps-reports-summary.v1';
    const MAX_ROWS = 200;
    public static function admin_menu() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        add_submenu_page(
            'mad4b-company-brand-readiness',
            'Company Execution Reports', 'Execution Reports',
            'manage_options', 'mad4b-company-execution-reports',
            array( __CLASS__, 'render' )
        );
    }
    private static function cell( $value ) {
        echo esc_html( is_scalar( $value ) ? (string) $value : '—' );
    }
    /** Query the existing local Hub Queue. Never infer original Broker 313. */
    public static function live_data() {
        if ( ! current_user_can( 'manage_options' )
            || ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return array();
        $site = MAD4B_SCP_Site_Profile::status();
        if ( ! is_array( $site )
            || empty( $site['site_uuid'] )
            || empty( $site['origin_match'] )
            || empty( $site['deployment_binding_match'] )
            || ( $site['environment'] ?? '' ) !== 'staging' ) return array();
        global $wpdb;
        if ( ! $wpdb instanceof wpdb ) return array();
        $hostname = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! is_string( $hostname ) || $hostname === '' ) return array();
        $target = 'site:' . $hostname;
        $table = $wpdb->prefix . 'mad4b_hub_bulk_tasks';
        $sql = $wpdb->prepare(
            "SELECT task_id,batch_id,state,stage,operation,payload_json,result_json FROM {$table} WHERE target_ref=%s ORDER BY id DESC LIMIT %d",
            $target, self::MAX_ROWS
        );
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) return array();

        $tasks = array();
        $verified = 0;
        $snapshots = 0;
        foreach ( $rows as $row ) {
            $payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
            if ( ! is_array( $payload )
                || ( $payload['assigned_executor'] ?? '' ) !== 'vps_runner' ) continue;
            $native = ( $payload['universal_vps_contract'] ?? '' ) === 'mad4b.hub.universal-vps.v1';
            $context = $payload['enqueue_policy_context'] ?? null;
            $shared = is_array( $context )
                && ( $context['contract'] ?? '' ) === 'mad4b.execution-policy-context-at-enqueue.v1'
                && ( $context['site_uuid'] ?? '' ) === $site['site_uuid']
                && ( $context['environment'] ?? '' ) === 'staging'
                && is_int( $context['revision'] ?? null )
                && $context['revision'] >= 0
                && is_array( $context['transport_modes'] ?? null )
                && in_array( $context['transport_modes']['vps_mcp_stdio'] ?? '', array(
                    'registered_operator', 'signed_broker', 'manual_approval', 'read_only'
                ), true );
            if ( ! $native && ! $shared ) continue;
            $receipt = json_decode( (string) ( $row['result_json'] ?? '' ), true );
            $verified_receipt = is_array( $receipt ) && ( $receipt['verified'] ?? false ) === true;
            $native_receipt = $native && ( $row['stage'] ?? '' ) === 'INTEGRATION_QA'
                && ! empty( $receipt['evidence_ref'] );
            $health_receipt = $shared && ( $row['operation'] ?? '' ) === 'site_health'
                && ( $receipt['operation'] ?? '' ) === 'site_health'
                && ( $receipt['site_origin'] ?? '' ) === home_url();
            $accepted = ( $row['state'] ?? '' ) === 'COMPLETED'
                && $verified_receipt && ( $native_receipt || $health_receipt );
            if ( $accepted ) ++$verified;
            $policy = $payload['execution_policy_snapshot'] ?? null;
            $recorded = is_array( $policy )
                && ( $policy['contract'] ?? '' ) === 'mad4b.execution-policy-snapshot.v1'
                && ( $policy['site_uuid'] ?? '' ) === $site['site_uuid']
                && ( $policy['environment'] ?? '' ) === 'staging'
                && ( $policy['transport'] ?? '' ) === 'vps_mcp_stdio'
                && ( $policy['mode'] ?? '' ) === 'registered_operator'
                && isset( $policy['revision'] ) && is_int( $policy['revision'] )
                && $policy['revision'] >= 0;
            $mode = $recorded ? $policy['mode']
                : ( $shared ? $context['transport_modes']['vps_mcp_stdio'] : null );
            $revision = $recorded ? $policy['revision']
                : ( $shared ? $context['revision'] : null );
            $recorded = $recorded || $shared;
            if ( $recorded ) ++$snapshots;
            $tasks[] = array(
                'task_id' => (string) ( $row['task_id'] ?? '' ),
                'batch_id' => (string) ( $row['batch_id'] ?? '' ),
                'state' => $accepted ? 'LOCAL_EXECUTION_VERIFIED' : (string) ( $row['state'] ?? 'UNKNOWN' ),
                'policy_mode_at_enqueue' => $mode,
                'policy_revision_at_enqueue' => $revision,
                'policy_at_enqueue_recorded' => $recorded,
                'policy_at_execution_time_attested' => false,
                'original_313_certified' => false,
            );
        }
        $company = $hostname === 'tours.dev.mad4b.com' ? 'tours-central' : $hostname;
        $group = array(
            'company' => $company,
            'environment' => 'staging',
            'total' => count( $tasks ),
            'local_verified' => $verified,
            'policy_at_enqueue_recorded' => $snapshots,
            'broker_313_certified' => 0,
            'historical_policy_proven' => 0,
            'tasks' => $tasks,
        );
        return array(
            'contract' => self::CONTRACT,
            'observed_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'company_count' => 1,
            'task_count' => count( $tasks ),
            'companies' => array( $group ),
            'source' => 'local_hub_queue_live',
        );
    }
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) )
            wp_die( esc_html__( 'You do not have permission to view company execution reports.', 'mad4b' ) );
        $data = self::live_data();
        echo '<div class="wrap"><h1>MAD4B — Company Execution Reports</h1>';
        echo '<p>Live local Hub Queue results. Hub receipts and original Broker 313 are independent.</p>';
        if ( ( $data['contract'] ?? '' ) !== self::CONTRACT ) {
            echo '<div class="notice notice-warning"><p>Local Hub Queue is unavailable or site scope is unverified.</p></div></div>';
            return;
        }
        echo '<p><strong>Live read:</strong> ';
        self::cell( $data['observed_utc'] );
        echo ' &nbsp; <strong>Companies:</strong> ';
        self::cell( $data['company_count'] );
        echo ' &nbsp; <strong>Latest local tasks:</strong> ';
        self::cell( $data['task_count'] );
        echo '</p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ( array( 'Company', 'Environment', 'Tasks', 'Local verified', 'Policy recorded at enqueue', 'Original Broker 313' ) as $h )
            echo '<th scope="col">' . esc_html( $h ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $data['companies'] as $c ) {
            echo '<tr>';
            foreach ( array( 'company', 'environment', 'total', 'local_verified', 'policy_at_enqueue_recorded', 'broker_313_certified' ) as $key ) {
                echo '<td>'; self::cell( $c[$key] ?? '' ); echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table><h2>Local task receipts</h2><table class="widefat striped"><thead><tr>';
        foreach ( array( 'Company', 'Task ID', 'Execution status', 'Policy at enqueue', 'Original Broker 313' ) as $h )
            echo '<th scope="col">' . esc_html( $h ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $data['companies'] as $c ) {
            foreach ( $c['tasks'] as $task ) {
                echo '<tr><td>'; self::cell( $c['company'] ); echo '</td><td><code>';
                self::cell( $task['task_id'] ); echo '</code></td><td>';
                self::cell( $task['state'] ); echo '</td><td>';
                self::cell( $task['policy_at_enqueue_recorded']
                    ? ( $task['policy_mode_at_enqueue'] . ' (rev ' . $task['policy_revision_at_enqueue'] . ')' )
                    : 'Not recorded' ); echo '</td><td>';
                self::cell( $task['original_313_certified'] ? 'Certified' : 'Not certified' ); echo '</td></tr>';
            }
        }
        echo '</tbody></table><p><em>Up to ' . esc_html( (string) self::MAX_ROWS ) .
            ' recent site-scoped Hub Queue rows are inspected. Other company results require their own verified sources.</em></p></div>';
    }
}
add_action( 'admin_menu', array( 'MAD4B_Company_Execution_Reports_V1', 'admin_menu' ), 90 );

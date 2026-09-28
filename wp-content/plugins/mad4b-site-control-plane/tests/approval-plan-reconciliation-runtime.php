<?php

define( 'ABSPATH', '/srv/wordpress/' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }

class MAD4B_SCP_Schema {
    public static function is_ready() { return true; }
    public static function tables() { return array( 'approvals' => 'wp_mad4b_approvals' ); }
}

class MAD4B_SCP_Agent_Registry {
    public static function get_agent_by_public_id( $public_id ) {
        return array( 'id' => 7, 'public_id' => (string) $public_id, 'status' => 'enabled' );
    }
}

class MAD4B_SCP_Approval_Tickets {
    const CANDIDATE_BINDING_CONTRACT = 'mad4b.approval-candidate-binding.v2';
    public static function canonical_payload_hash( $agent, $server, $ability, $provider, $target, $input, $ticket_class = 'mutation' ) {
        return str_repeat( 'a', 64 );
    }
}

class MAD4B_Test_WPDB {
    public $rows = array();
    public function prepare( $sql ) {
        return $sql;
    }
    public function get_results( $sql, $format ) {
        return $this->rows;
    }
}
$wpdb = new MAD4B_Test_WPDB();

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-approval-repository.php';

function mad4b_approval_reconcile_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

$agent = '33333333-3333-4333-8333-333333333333';
$ticket = '11111111-1111-4111-8111-111111111111';
$sha = str_repeat( 'b', 40 );
$build = str_repeat( 'c', 64 );
$profile = str_repeat( 'd', 64 );
$site_uuid = '22222222-2222-4222-8222-222222222222';
$candidate = array(
    'ready' => true,
    'source_commit_sha' => $sha,
    'build_fingerprint' => $build,
    'site_uuid' => $site_uuid,
    'site_profile_revision' => 5,
    'site_profile_digest' => $profile,
    'environment' => 'staging',
    'host' => 'staging.client.test',
);
$base = array(
    'id' => 20,
    'ticket_id' => $ticket,
    'status' => 'pending',
    'ticket_class' => 'mutation',
    'agent_id' => 7,
    'server_id' => 'mad4b-write',
    'ability_name' => 'elementor/update-widget-settings',
    'provider' => 'elementor',
    'target_fingerprint' => str_repeat( 'e', 64 ),
    'payload_sha256' => str_repeat( 'a', 64 ),
    'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
    'candidate_binding_contract' => MAD4B_SCP_Approval_Tickets::CANDIDATE_BINDING_CONTRACT,
    'candidate_sha' => $sha,
    'build_fingerprint' => $build,
    'site_uuid' => $site_uuid,
    'site_profile_revision' => 5,
    'site_profile_digest' => $profile,
    'binding_environment' => 'staging',
    'binding_host' => 'staging.client.test',
);

$wpdb->rows = array();
$result = MAD4B_SCP_Approval_Repository::reconcile_plan(
    $candidate, $agent, 'elementor/update-widget-settings', 'elementor',
    str_repeat( 'e', 64 ), array( 'post_id' => 1 )
);
mad4b_approval_reconcile_assert( is_array( $result ) && empty( $result['found'] ), 'No exact ticket must reconcile as not found.' );
mad4b_approval_reconcile_assert( ! empty( $result['retry_plan_safe'] ), 'No exact ticket may allow a fresh approval plan.' );

$wpdb->rows = array( $base );
$result = MAD4B_SCP_Approval_Repository::reconcile_plan(
    $candidate, $agent, 'elementor/update-widget-settings', 'elementor',
    str_repeat( 'e', 64 ), array( 'post_id' => 1 )
);
mad4b_approval_reconcile_assert( ! empty( $result['found'] ) && $ticket === $result['ticket_id'], 'Existing exact ticket must be recovered.' );
mad4b_approval_reconcile_assert( 'pending' === $result['effective_status'], 'Exact pending ticket status drifted.' );
mad4b_approval_reconcile_assert( ! empty( $result['candidate_binding_exact'] ), 'Exact candidate binding was not recognized.' );
mad4b_approval_reconcile_assert( empty( $result['retry_plan_safe'] ), 'Pending exact ticket must prohibit approval-plan replay.' );
mad4b_approval_reconcile_assert( 'use_existing_ticket_or_human_handoff' === $result['next_action'], 'Pending exact ticket next action drifted.' );

$expired_approved = $base;
$expired_approved['status'] = 'approved';
$expired_approved['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
$wpdb->rows = array( $expired_approved );
$result = MAD4B_SCP_Approval_Repository::reconcile_plan(
    $candidate, $agent, 'elementor/update-widget-settings', 'elementor',
    str_repeat( 'e', 64 ), array( 'post_id' => 1 )
);
mad4b_approval_reconcile_assert( 'expired' === $result['effective_status'], 'Expired approved ticket must not remain effectively approved.' );
mad4b_approval_reconcile_assert( ! empty( $result['retry_plan_safe'] ), 'Expired approved ticket may be replaced after postcondition reconciliation.' );
mad4b_approval_reconcile_assert( 'create_new_plan_if_operation_is_still_required' === $result['next_action'], 'Expired approved ticket must direct the client to a fresh exact plan.' );

$stale_approved = $base;
$stale_approved['status'] = 'approved';
$stale_approved['candidate_sha'] = str_repeat( 'f', 40 );
$wpdb->rows = array( $stale_approved );
$result = MAD4B_SCP_Approval_Repository::reconcile_plan(
    $candidate, $agent, 'elementor/update-widget-settings', 'elementor',
    str_repeat( 'e', 64 ), array( 'post_id' => 1 )
);
mad4b_approval_reconcile_assert( 'stale' === $result['effective_status'], 'Old-build approved ticket must reconcile as stale.' );
mad4b_approval_reconcile_assert( ! empty( $result['retry_plan_safe'] ), 'Stale approved ticket may be replaced on the current candidate.' );

$fresh_after_expired = $base;
$fresh_after_expired['id'] = 21;
$fresh_after_expired['ticket_id'] = '55555555-5555-4555-8555-555555555555';
$wpdb->rows = array( $fresh_after_expired, $expired_approved );
$result = MAD4B_SCP_Approval_Repository::reconcile_plan(
    $candidate, $agent, 'elementor/update-widget-settings', 'elementor',
    str_repeat( 'e', 64 ), array( 'post_id' => 1 )
);
mad4b_approval_reconcile_assert( 'pending' === $result['effective_status'], 'Fresh exact ticket must win over expired historical ticket.' );
mad4b_approval_reconcile_assert( empty( $result['duplicate_matches_detected'] ), 'Expired historical ticket must not create a false active duplicate.' );
mad4b_approval_reconcile_assert( 'use_existing_ticket_or_human_handoff' === $result['next_action'], 'Fresh replacement ticket next action drifted.' );

$stale = $base;
$stale['candidate_sha'] = str_repeat( 'f', 40 );
$wpdb->rows = array( $stale );
$result = MAD4B_SCP_Approval_Repository::reconcile_plan(
    $candidate, $agent, 'elementor/update-widget-settings', 'elementor',
    str_repeat( 'e', 64 ), array( 'post_id' => 1 )
);
mad4b_approval_reconcile_assert( 'stale' === $result['effective_status'], 'Old-build exact ticket must reconcile as stale.' );
mad4b_approval_reconcile_assert( ! empty( $result['retry_plan_safe'] ), 'Stale old-build approval plan may be replaced on the current candidate.' );

$duplicate = $base;
$duplicate['id'] = 19;
$duplicate['ticket_id'] = '44444444-4444-4444-8444-444444444444';
$wpdb->rows = array( $base, $duplicate );
$result = MAD4B_SCP_Approval_Repository::reconcile_plan(
    $candidate, $agent, 'elementor/update-widget-settings', 'elementor',
    str_repeat( 'e', 64 ), array( 'post_id' => 1 )
);
mad4b_approval_reconcile_assert( ! empty( $result['duplicate_matches_detected'] ), 'Duplicate exact payload plans must be detected.' );
mad4b_approval_reconcile_assert( empty( $result['retry_plan_safe'] ), 'Duplicate exact payload plans must prohibit another retry.' );
mad4b_approval_reconcile_assert( 'inspect_duplicate_exact_payload_tickets_before_any_retry' === $result['next_action'], 'Duplicate exact payload next action drifted.' );
mad4b_approval_reconcile_assert( ! empty( $result['read_only'] ) && empty( $result['mutation_performed'] ), 'Approval plan reconciliation must remain read-only.' );

echo "mad4b.approval-plan-reconciliation.runtime.v1: PASS\n";

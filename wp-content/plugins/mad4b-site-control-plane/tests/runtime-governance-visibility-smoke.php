<?php
/**
 * Disposable runtime proof for NHI governance visibility and exact approval planning.
 * Runs only in CI after baseline + reversible mutation smoke.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

foreach ( array( 'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan' ) as $ability_name ) {
	$check( wp_has_ability( $ability_name ), 'Governance ability is missing: ' . $ability_name );
	$ability = wp_get_ability( $ability_name );
	$check( is_object( $ability ) && method_exists( $ability, 'execute' ) && method_exists( $ability, 'get_meta' ), 'Governance ability is not executable: ' . $ability_name );
	$meta = $ability->get_meta();
	$check( empty( $meta['public'] ) && empty( $meta['mcp']['public'] ), 'Governance ability leaked to a public/default MCP surface: ' . $ability_name );
	$check( isset( $meta['mcp']['surface'] ) && 'admin' === $meta['mcp']['surface'], 'Governance ability is not bound to the admin surface: ' . $ability_name );
}

$agent = MAD4B_SCP_Agent_Registry::create_agent(
	array(
		'slug' => 'ci-governance-visibility-agent',
		'label' => 'CI Governance Visibility Agent',
		'status' => 'enabled',
		'environment' => 'all',
		'wp_user_id' => get_current_user_id(),
	)
);
$check( is_array( $agent ) && ! empty( $agent['public_id'] ), 'Unable to create governance visibility test agent.' );

$subject_fingerprint = hash( 'sha256', 'ci-governance-visibility-subject' );
$bound = MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], 'ci', $subject_fingerprint, 'CI governance visibility subject' );
$check( true === $bound, 'Unable to bind governance visibility test subject.' );

// Grant creation must bind the exact server, ability and certified provider.
$wrong_server = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-admin', 'mad4b/content-update-post', 'core', array(), 'allow', 'all' );
$check( is_wp_error( $wrong_server ) && 'mad4b_grant_server_ability_mismatch' === $wrong_server->get_error_code(), 'Grant creation accepted an ability on the wrong MCP server.' );

$wrong_provider = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-content', 'mad4b/content-update-post', 'elementor', array(), 'allow', 'all' );
$check( is_wp_error( $wrong_provider ) && 'mad4b_grant_provider_mismatch' === $wrong_provider->get_error_code(), 'Grant creation accepted the wrong provider for a mounted ability.' );

$breakglass = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-breakglass', 'mad4b/database-raw-query', 'core', array(), 'allow', 'all' );
$check( is_wp_error( $breakglass ) && 'mad4b_breakglass_grant_creation_denied' === $breakglass->get_error_code(), 'Generic grant creation unexpectedly opened Breakglass authority.' );

$allow_post = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-content', 'mad4b/content-update-post', 'core', array(), 'allow', 'all' );
$check( true === $allow_post, 'Unable to create correct exact content grant.' );
$deny_post = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-content', 'mad4b/content-update-post', 'core', array(), 'deny', 'all' );
$check( true === $deny_post, 'Unable to create exact deny grant for precedence test.' );

$allow_undo = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', array(), 'allow', 'all' );
$check( true === $allow_undo, 'Unable to create exact undo grant.' );

$conditional_db = MAD4B_SCP_Agent_Registry::grant_ability(
	$agent['public_id'],
	'mad4b-admin',
	'mad4b/database-update',
	'core',
	array( 'table' => 'posts', 'max_affected' => 1 ),
	'allow',
	'all'
);
$check( true === $conditional_db, 'Unable to create constrained DB grant for conditional preview.' );

// agent-list exposes only bounded governance summaries, not subject fingerprints or raw authority material.
$agent_list_ability = wp_get_ability( 'mad4b/agent-list' );
$list = $agent_list_ability->execute( array( 'status' => 'enabled', 'limit' => 200 ) );
$check( ! is_wp_error( $list ) && isset( $list['agents'] ) && is_array( $list['agents'] ), 'agent-list failed.' );
$listed = null;
foreach ( $list['agents'] as $candidate ) {
	if ( isset( $candidate['public_id'] ) && $agent['public_id'] === $candidate['public_id'] ) { $listed = $candidate; break; }
}
$check( is_array( $listed ), 'agent-list did not return the test agent.' );
$check( ! array_key_exists( 'subject_fingerprint', $listed ) && ! array_key_exists( 'subject_identifier', $listed ), 'agent-list leaked subject identity material.' );
$check( isset( $listed['subjects'] ) && 1 === (int) $listed['subjects'], 'agent-list subject count is incorrect.' );
$check( isset( $listed['grants'] ) && 4 === (int) $listed['grants'], 'agent-list grant count is incorrect.' );

// Effective access must collapse duplicate allow+deny rows into one tuple and apply deny precedence.
$effective_ability = wp_get_ability( 'mad4b/agent-effective-access' );
$preview = $effective_ability->execute( array( 'agent_public_id' => $agent['public_id'], 'server_id' => '' ) );
$check( ! is_wp_error( $preview ) && isset( $preview['effective'] ) && is_array( $preview['effective'] ), 'agent-effective-access failed.' );
$post_entry = null;
$post_entry_count = 0;
$db_entry = null;
$undo_entry = null;
foreach ( $preview['effective'] as $entry ) {
	if ( 'mad4b/content-update-post' === $entry['ability'] ) { $post_entry = $entry; ++$post_entry_count; }
	if ( 'mad4b/database-update' === $entry['ability'] ) $db_entry = $entry;
	if ( 'mad4b/mutation-undo' === $entry['ability'] ) $undo_entry = $entry;
}
$check( 1 === $post_entry_count, 'Effective-access preview returned duplicate rows for one server/ability/provider tuple.' );
$check( is_array( $post_entry ) && 'deny' === $post_entry['grant'] && 'denied' === $post_entry['decision'] && empty( $post_entry['effective'] ), 'Effective-access preview did not apply exact deny precedence.' );
$check( isset( $post_entry['grant_ids'] ) && is_array( $post_entry['grant_ids'] ) && ! empty( $post_entry['grant_ids'] ), 'Effective-access preview omitted winning grant evidence.' );
$check( is_array( $db_entry ) && 'conditional' === $db_entry['decision'] && empty( $db_entry['effective'] ) && 'unresolved_without_target' === $db_entry['constraint_state'], 'Constrained grant was not reported as conditional/fail-closed.' );
$check( is_array( $undo_entry ) && 'allowed' === $undo_entry['decision'] && ! empty( $undo_entry['effective'] ), 'Exact unconstrained undo grant was not visible as allowed.' );

$scope_preview = $effective_ability->execute(
	array(
		'agent_public_id' => $agent['public_id'],
		'token_scopes' => array( 'ability:mad4b/database-update' ),
	)
);
$check( ! is_wp_error( $scope_preview ), 'Token-scope simulation failed.' );
$scope_undo = null;
foreach ( $scope_preview['effective'] as $entry ) if ( 'mad4b/mutation-undo' === $entry['ability'] ) $scope_undo = $entry;
$check( is_array( $scope_undo ) && 'denied' === $scope_undo['scope'] && 'denied' === $scope_undo['decision'] && empty( $scope_undo['effective'] ), 'Token scope simulation widened NHI authority.' );

$wildcard_preview = $effective_ability->execute(
	array(
		'agent_public_id' => $agent['public_id'],
		'token_scopes' => array( 'ability:*' ),
	)
);
$check( is_wp_error( $wildcard_preview ) && in_array( $wildcard_preview->get_error_code(), array( 'mad4b_effective_access_scope_invalid', 'ability_invalid_input' ), true ), 'Wildcard scope simulation was not rejected.' );

// approval-plan may create only a pending exact-operation ticket for an operation whose impact policy requires approval.
$plan_ability = wp_get_ability( 'mad4b/approval-plan' );
$planned_mutation_id = wp_generate_uuid4();
$undo_input = array( 'mutation_id' => $planned_mutation_id, 'reason' => 'CI exact approval planning payload' );
$plan = $plan_ability->execute(
	array(
		'agent_public_id' => $agent['public_id'],
		'server_id' => 'mad4b-admin',
		'ability' => 'mad4b/mutation-undo',
		'provider' => 'core',
		'input' => $undo_input,
		'reason' => 'CI plans but never auto-approves exact undo',
		'ttl' => 600,
	)
);
$check( ! is_wp_error( $plan ), 'approval-plan failed for exact high-impact operation: ' . ( is_wp_error( $plan ) ? $plan->get_error_message() : '' ) );
$check( 'pending' === $plan['status'] && empty( $plan['auto_approved'] ) && 'high' === $plan['impact'] && 'mutation' === $plan['ticket_class'], 'approval-plan did not return a pending high-impact mutation ticket.' );
$stored_ticket = MAD4B_SCP_Approval_Tickets::get( $plan['ticket_id'] );
$check( is_array( $stored_ticket ) && 'pending' === $stored_ticket['status'] && 0 === (int) $stored_ticket['approved_by'], 'approval-plan auto-approved or corrupted the pending ticket.' );

$wrong_plan_provider = $plan_ability->execute(
	array(
		'agent_public_id' => $agent['public_id'],
		'server_id' => 'mad4b-admin',
		'ability' => 'mad4b/mutation-undo',
		'provider' => 'elementor',
		'input' => $undo_input,
		'reason' => 'CI wrong provider must fail',
	)
);
$check( is_wp_error( $wrong_plan_provider ) && 'mad4b_approval_provider_mismatch' === $wrong_plan_provider->get_error_code(), 'approval-plan accepted a provider that does not own the mounted ability.' );

$readonly_plan = $plan_ability->execute(
	array(
		'agent_public_id' => $agent['public_id'],
		'server_id' => 'mad4b-admin',
		'ability' => 'mad4b/agent-list',
		'provider' => 'core',
		'input' => array(),
		'reason' => 'CI readonly target must fail',
	)
);
$check( is_wp_error( $readonly_plan ) && 'mad4b_approval_readonly_target' === $readonly_plan->get_error_code(), 'approval-plan accepted a readonly ability.' );

$target_mismatch = $plan_ability->execute(
	array(
		'agent_public_id' => $agent['public_id'],
		'server_id' => 'mad4b-admin',
		'ability' => 'mad4b/mutation-undo',
		'provider' => 'core',
		'target_fingerprint' => 'caller-asserted-wrong-target',
		'input' => $undo_input,
		'reason' => 'CI target assertion must match central resolver',
	)
);
$check( is_wp_error( $target_mismatch ) && 'mad4b_approval_target_mismatch' === $target_mismatch->get_error_code(), 'approval-plan trusted caller target fingerprint over the central resolver.' );

// Low-impact draft-to-draft core content updates must not accumulate unnecessary approval tickets.
$low_agent = MAD4B_SCP_Agent_Registry::create_agent(
	array( 'slug' => 'ci-low-impact-plan-agent', 'label' => 'CI Low Impact Plan Agent', 'status' => 'enabled', 'environment' => 'all' )
);
$check( is_array( $low_agent ), 'Unable to create low-impact planning agent.' );
$low_grant = MAD4B_SCP_Agent_Registry::grant_ability( $low_agent['public_id'], 'mad4b-content', 'mad4b/content-update-post', 'core', array(), 'allow', 'all' );
$check( true === $low_grant, 'Unable to create low-impact content grant.' );
$low_plan = $plan_ability->execute(
	array(
		'agent_public_id' => $low_agent['public_id'],
		'server_id' => 'mad4b-content',
		'ability' => 'mad4b/content-update-post',
		'provider' => 'core',
		'input' => array( 'post_id' => 999999, 'expected_modified_gmt' => '2026-01-01 00:00:00', 'post_title' => 'planning only' ),
		'reason' => 'CI low impact should not create approval',
	)
);
$check( is_wp_error( $low_plan ) && 'mad4b_approval_not_required' === $low_plan->get_error_code(), 'approval-plan created unnecessary authority for a low-impact operation.' );

echo "mad4b.site-control-plane.runtime-governance-visibility.v1: PASS\n";

<?php
/**
 * Disposable runtime proof for the governed reversible post mutation pilot.
 * Runs only in CI after the baseline runtime smoke.
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress is not loaded.' );
}

$check = static function ( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
};

$check( class_exists( 'MAD4B_SCP_Mutation_Manager' ), 'Mutation manager is unavailable.' );
$check( class_exists( 'MAD4B_SCP_Governed_Ability_Overrides' ), 'Governed ability override layer is unavailable.' );
$check( wp_has_ability( 'mad4b/content-update-post' ), 'Governed post update ability is missing.' );
$check( wp_has_ability( 'mad4b/mutation-get' ), 'Mutation evidence ability is missing.' );
$check( wp_has_ability( 'mad4b/mutation-undo' ), 'Mutation undo ability is missing.' );

$post_update = wp_get_ability( 'mad4b/content-update-post' );
$post_meta = $post_update->get_meta();
$check(
	isset( $post_meta['mcp']['mad4b_reversible_contract'] ) && 'mad4b.rollback.post.v1' === $post_meta['mcp']['mad4b_reversible_contract'],
	'WordPress ability registration did not receive the governed reversible callback metadata.'
);

$undo_ability = wp_get_ability( 'mad4b/mutation-undo' );
$undo_meta = $undo_ability->get_meta();
$check( empty( $undo_meta['public'] ) && empty( $undo_meta['mcp']['public'] ), 'Mutation undo leaked to a default/public surface.' );
$check( ! empty( $undo_meta['annotations']['destructive'] ), 'Mutation undo must be annotated destructive.' );

// Provision an isolated CI NHI. No production authority is created by plugin activation or migration.
$subject_type = 'ci';
$subject_identifier = 'mad4b-reversible-runtime-agent';
$subject_fingerprint = hash( 'sha256', $subject_type . "\0" . $subject_identifier );
$agent = MAD4B_SCP_Agent_Registry::create_agent(
	array(
		'slug' => 'ci-reversible-agent',
		'label' => 'CI Reversible Agent',
		'status' => 'enabled',
		'environment' => 'all',
		'wp_user_id' => get_current_user_id(),
	)
);
$check( is_array( $agent ) && ! empty( $agent['public_id'] ), 'Unable to create the disposable reversible-test NHI.' );
$bound = MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], $subject_type, $subject_fingerprint, 'CI reversible subject' );
$check( true === $bound, 'Unable to bind the disposable reversible-test subject.' );

foreach (
	array(
		array( 'mad4b-content', 'mad4b/content-update-post' ),
		array( 'mad4b-admin', 'mad4b/mutation-undo' ),
	) as $grant_spec
) {
	$grant = MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], $grant_spec[0], $grant_spec[1], 'core', array(), 'allow', 'all' );
	$check( true === $grant, 'Unable to create exact CI grant for ' . $grant_spec[1] );
}

$approval_ticket_id = '';
add_filter(
	'mad4b_scp_authenticated_subject_context',
	static function ( $context ) use ( $subject_type, $subject_identifier, &$approval_ticket_id ) {
		return array(
			'authenticated' => true,
			'subject_type' => $subject_type,
			'subject_identifier' => $subject_identifier,
			'token_scopes' => array(
				'ability:mad4b/content-update-post',
				'ability:mad4b/mutation-undo',
			),
			'approval_ticket_id' => $approval_ticket_id,
			'auth_method' => 'ci',
			'wp_user_id' => get_current_user_id(),
			'request_id' => 'ci-reversible-runtime-request',
			'origin' => 'ci',
		);
	},
	999
);

if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) define( 'MAD4B_MCP_MUTATION_ENABLED', true );
$check( MAD4B_SCP_Policy::can_mutate(), 'Exact CI NHI did not satisfy the global mutation gate.' );

$post_id = wp_insert_post(
	array(
		'post_type' => 'post',
		'post_status' => 'draft',
		'post_title' => 'MAD4B reversible before',
		'post_content' => 'before-content',
		'post_excerpt' => 'before-excerpt',
	),
	true
);
$check( ! is_wp_error( $post_id ) && $post_id > 0, 'Unable to create disposable post.' );

// 1. Execute the real WordPress Ability path. Draft->draft title/content edit is low impact and requires no approval by default.
$before = get_post( $post_id );
$first_input = array(
	'post_id' => $post_id,
	'expected_modified_gmt' => $before->post_modified_gmt,
	'post_title' => 'MAD4B reversible after one',
	'post_content' => 'after-content-one',
);
$first = $post_update->execute( $first_input );
$check( ! is_wp_error( $first ), 'Governed post mutation failed: ' . ( is_wp_error( $first ) ? $first->get_error_message() : '' ) );
$check( ! empty( $first['verified'] ) && ! empty( $first['reversible'] ) && ! empty( $first['mutation_id'] ), 'Governed post mutation did not return verified reversible evidence.' );
$check( ! empty( $first['before_sha256'] ) && ! empty( $first['after_sha256'] ) && ! hash_equals( $first['before_sha256'], $first['after_sha256'] ), 'Mutation before/after hashes were not distinct.' );
$after_one = get_post( $post_id );
$check( 'MAD4B reversible after one' === $after_one->post_title && 'after-content-one' === $after_one->post_content, 'Readback does not contain the governed mutation.' );

// 2. Read bounded mutation evidence; rollback payload must never be returned through the normal inspection ability.
$mutation_get = wp_get_ability( 'mad4b/mutation-get' );
$evidence = $mutation_get->execute( array( 'mutation_id' => $first['mutation_id'] ) );
$check( ! is_wp_error( $evidence ) && ! empty( $evidence['mutation'] ), 'Mutation evidence ability failed.' );
$check( 'verified' === $evidence['mutation']['status'], 'Mutation evidence did not record verified state.' );
$check( ! array_key_exists( 'rollback_payload', $evidence['mutation'] ), 'Mutation evidence leaked rollback payload.' );
$check( ! array_key_exists( 'rollback_payload_sha256', $evidence['mutation'] ), 'Mutation evidence leaked rollback payload integrity material.' );

// 3. Undo is high-impact. Approve the exact undo request, place only the opaque ticket ID in authenticated context, then execute the real ability.
$undo_input_one = array(
	'mutation_id' => $first['mutation_id'],
	'reason' => 'CI verifies drift-safe undo',
);
$ticket_one = MAD4B_SCP_Approval_Tickets::create_pending(
	$agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', '', $undo_input_one, 'mutation', 'CI reversible undo approval', 600
);
$check( is_array( $ticket_one ) && 'pending' === $ticket_one['status'], 'Unable to create first undo approval ticket.' );
$approved_one = MAD4B_SCP_Approval_Tickets::approve( $ticket_one['ticket_id'] );
$check( is_array( $approved_one ) && 'approved' === $approved_one['status'], 'Unable to approve first undo ticket.' );
$approval_ticket_id = $ticket_one['ticket_id'];
$undone = $undo_ability->execute( $undo_input_one );
$check( ! is_wp_error( $undone ), 'Approved undo failed: ' . ( is_wp_error( $undone ) ? $undone->get_error_message() : '' ) );
$check( isset( $undone['status'] ) && 'undone' === $undone['status'] && ! empty( $undone['verified'] ), 'Undo did not return verified undone state.' );
$restored = get_post( $post_id );
$check( 'MAD4B reversible before' === $restored->post_title && 'before-content' === $restored->post_content, 'Undo did not restore the exact before-state.' );
$original_record = MAD4B_SCP_Mutation_Manager::get( $first['mutation_id'] );
$check( is_array( $original_record ) && 'undone' === $original_record['status'], 'Original mutation was not marked undone.' );
$check( ! empty( $undone['recovery_mutation_id'] ), 'Undo did not create child recovery evidence.' );

// 4. Create a second governed mutation, then simulate a newer human change. Automatic undo must refuse to overwrite it.
$approval_ticket_id = '';
$current = get_post( $post_id );
$second_input = array(
	'post_id' => $post_id,
	'expected_modified_gmt' => $current->post_modified_gmt,
	'post_title' => 'MAD4B reversible after two',
);
$second = $post_update->execute( $second_input );
$check( ! is_wp_error( $second ) && ! empty( $second['mutation_id'] ), 'Second governed mutation failed.' );

$manual = wp_update_post(
	array(
		'ID' => $post_id,
		'post_title' => 'Human change after AI mutation',
	),
	true
);
$check( ! is_wp_error( $manual ), 'Unable to simulate newer human state.' );

$undo_input_two = array(
	'mutation_id' => $second['mutation_id'],
	'reason' => 'CI expects drift rejection',
);
$ticket_two = MAD4B_SCP_Approval_Tickets::create_pending(
	$agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', '', $undo_input_two, 'mutation', 'CI drift rejection approval', 600
);
$check( is_array( $ticket_two ), 'Unable to create drift-test undo approval.' );
$approved_two = MAD4B_SCP_Approval_Tickets::approve( $ticket_two['ticket_id'] );
$check( is_array( $approved_two ) && 'approved' === $approved_two['status'], 'Unable to approve drift-test ticket.' );
$approval_ticket_id = $ticket_two['ticket_id'];
$drift = $undo_ability->execute( $undo_input_two );
$check( is_wp_error( $drift ) && 'mad4b_undo_state_drift' === $drift->get_error_code(), 'Undo did not fail closed on newer target state.' );
$after_drift = get_post( $post_id );
$check( 'Human change after AI mutation' === $after_drift->post_title, 'Rejected undo overwrote newer human work.' );

$used_ticket = MAD4B_SCP_Approval_Tickets::get( $ticket_two['ticket_id'] );
$check( is_array( $used_ticket ) && 'used' === $used_ticket['status'], 'Attempted high-impact undo did not consume its single-use approval capability.' );

wp_delete_post( $post_id, true );

echo "mad4b.site-control-plane.runtime-reversible-mutation.v1: PASS\n";

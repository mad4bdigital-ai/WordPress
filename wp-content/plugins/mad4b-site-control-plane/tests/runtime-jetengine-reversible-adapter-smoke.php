<?php
/** Disposable runtime proof for exact packaged JetEngine reversible field mutation. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };

$adapter = MAD4B_SCP_Adapter_Registry::instance()->get( 'jetengine' );
$check( $adapter instanceof MAD4B_SCP_JetEngine_Adapter && $adapter->is_available(), 'JetEngine adapter/runtime is unavailable.' );
$status = $adapter->status();
$check( ! empty( $status['provider_certification']['runtime_contract_ok'] ), 'Exact packaged JetEngine provider contract is not certified at runtime.' );
$check( wp_has_ability( 'jetengine/get-post-meta' ) && wp_has_ability( 'jetengine/update-post-meta' ), 'JetEngine abilities are missing.' );
$write_ability = wp_get_ability( 'jetengine/update-post-meta' );
$meta = $write_ability->get_meta();
$check( isset( $meta['mcp']['mad4b_reversible_contract'] ) && 'mad4b.rollback.jetengine-post-meta.v1' === $meta['mcp']['mad4b_reversible_contract'], 'JetEngine writer is not bound to exact reversible contract.' );

$field = 'ci_reversible_field';
add_filter( 'mad4b_scp_jetengine_field_write_allowed', static function ( $allowed, $candidate ) use ( $field ) { return $field === $candidate; }, 999, 2 );

$subject_type = 'ci';
$subject_identifier = 'mad4b-jetengine-reversible-agent';
$subject_fingerprint = hash( 'sha256', $subject_type . "\0" . $subject_identifier );
$agent = MAD4B_SCP_Agent_Registry::create_agent( array(
	'slug' => 'ci-jetengine-reversible-agent', 'label' => 'CI JetEngine Reversible Agent', 'status' => 'enabled', 'environment' => 'all', 'wp_user_id' => get_current_user_id(),
) );
$check( is_array( $agent ), 'Unable to create JetEngine CI agent.' );
$check( true === MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], $subject_type, $subject_fingerprint, 'CI JetEngine subject' ), 'Unable to bind JetEngine CI subject.' );
$check( true === MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-content', 'jetengine/update-post-meta', 'jetengine', array(), 'allow', 'all' ), 'Unable to grant exact JetEngine content authority.' );
$check( true === MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', array(), 'allow', 'all' ), 'Unable to grant exact undo authority.' );

$approval_ticket_id = '';
add_filter( 'mad4b_scp_authenticated_subject_context', static function () use ( $subject_type, $subject_identifier, &$approval_ticket_id ) {
	return array(
		'authenticated' => true, 'subject_type' => $subject_type, 'subject_identifier' => $subject_identifier,
		'token_scopes' => array( 'ability:jetengine/update-post-meta', 'ability:mad4b/mutation-undo' ),
		'approval_ticket_id' => $approval_ticket_id, 'auth_method' => 'ci', 'wp_user_id' => get_current_user_id(),
		'request_id' => 'ci-jetengine-reversible-request', 'origin' => 'ci',
	);
}, 999 );
if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) define( 'MAD4B_MCP_MUTATION_ENABLED', true );
$check( MAD4B_SCP_Policy::can_mutate(), 'JetEngine CI NHI did not satisfy mutation authority.' );

$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'JetEngine reversible fixture' ), true );
$check( ! is_wp_error( $post_id ) && $post_id > 0, 'Unable to create JetEngine disposable post.' );
update_post_meta( $post_id, $field, 'before' );
$get_ability = wp_get_ability( 'jetengine/get-post-meta' );
$before = $get_ability->execute( array( 'post_id' => $post_id, 'field' => $field ) );
$check( ! is_wp_error( $before ) && 'before' === $before['value'], 'Unable to read initial JetEngine field.' );

$first_input = array( 'post_id' => $post_id, 'field' => $field, 'value' => 'after-one', 'expected_sha256' => $before['sha256'], 'allow_create' => false );
$ticket_write = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-content', 'jetengine/update-post-meta', 'jetengine', '', $first_input, 'mutation', 'CI approves exact JetEngine write', 600 );
$check( is_array( $ticket_write ) && is_array( MAD4B_SCP_Approval_Tickets::approve( $ticket_write['ticket_id'] ) ), 'Unable to approve exact JetEngine mutation.' );
$approval_ticket_id = $ticket_write['ticket_id'];
$first = $write_ability->execute( $first_input );
$check( ! is_wp_error( $first ), 'JetEngine reversible write failed: ' . ( is_wp_error( $first ) ? $first->get_error_message() : '' ) );
$check( ! empty( $first['mutation_id'] ) && ! empty( $first['verified'] ) && ! empty( $first['reversible'] ), 'JetEngine writer did not return durable reversible evidence.' );
$record = MAD4B_SCP_Mutation_Manager::get( $first['mutation_id'] );
$check( is_array( $record ) && 'jetengine' === $record['provider'] && 'jetengine-post-meta' === $record['target_type'] && 'verified' === $record['status'], 'JetEngine mutation evidence is incorrect.' );

$undo_ability = wp_get_ability( 'mad4b/mutation-undo' );
$approval_ticket_id = '';
$undo_input = array( 'mutation_id' => $first['mutation_id'], 'reason' => 'CI restores exact JetEngine field' );
$ticket_undo = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', '', $undo_input, 'mutation', 'CI approves JetEngine undo', 600 );
$check( is_array( $ticket_undo ) && is_array( MAD4B_SCP_Approval_Tickets::approve( $ticket_undo['ticket_id'] ) ), 'Unable to approve JetEngine undo.' );
$approval_ticket_id = $ticket_undo['ticket_id'];
$undone = $undo_ability->execute( $undo_input );
$check( ! is_wp_error( $undone ) && 'undone' === $undone['status'], 'JetEngine adapter undo failed.' );
$check( 'before' === get_post_meta( $post_id, $field, true ), 'JetEngine undo did not restore the exact prior field value.' );

// Prove newly-created exact fields restore to absence, not empty-string metadata.
$approval_ticket_id = '';
$new_field = 'ci_reversible_created_field';
add_filter( 'mad4b_scp_jetengine_field_write_allowed', static function ( $allowed, $candidate ) use ( $new_field ) { return $allowed || $new_field === $candidate; }, 1000, 2 );
add_filter( 'mad4b_scp_allow_jetengine_meta_create', static function ( $allowed, $candidate ) use ( $new_field ) { return $new_field === $candidate; }, 999, 2 );
$create_input = array( 'post_id' => $post_id, 'field' => $new_field, 'value' => 'created-value', 'allow_create' => true );
$ticket_create = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-content', 'jetengine/update-post-meta', 'jetengine', '', $create_input, 'mutation', 'CI approves exact JetEngine field creation', 600 );
$check( is_array( $ticket_create ) && is_array( MAD4B_SCP_Approval_Tickets::approve( $ticket_create['ticket_id'] ) ), 'Unable to approve JetEngine field creation.' );
$approval_ticket_id = $ticket_create['ticket_id'];
$created = $write_ability->execute( $create_input );
$check( ! is_wp_error( $created ) && metadata_exists( 'post', $post_id, $new_field ), 'JetEngine exact field creation failed.' );
$approval_ticket_id = '';
$undo_create_input = array( 'mutation_id' => $created['mutation_id'], 'reason' => 'CI restores field absence' );
$ticket_create_undo = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', '', $undo_create_input, 'mutation', 'CI approves JetEngine field-absence restore', 600 );
$check( is_array( $ticket_create_undo ) && is_array( MAD4B_SCP_Approval_Tickets::approve( $ticket_create_undo['ticket_id'] ) ), 'Unable to approve JetEngine field creation undo.' );
$approval_ticket_id = $ticket_create_undo['ticket_id'];
$undone_create = $undo_ability->execute( $undo_create_input );
$check( ! is_wp_error( $undone_create ) && ! metadata_exists( 'post', $post_id, $new_field ), 'JetEngine rollback did not restore field absence.' );

// Drift after a second exact write must deny undo without overwriting the human value.
$approval_ticket_id = '';
$current = $get_ability->execute( array( 'post_id' => $post_id, 'field' => $field ) );
$second_input = array( 'post_id' => $post_id, 'field' => $field, 'value' => 'after-two', 'expected_sha256' => $current['sha256'], 'allow_create' => false );
$ticket_second = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-content', 'jetengine/update-post-meta', 'jetengine', '', $second_input, 'mutation', 'CI approves second JetEngine write', 600 );
$check( is_array( $ticket_second ) && is_array( MAD4B_SCP_Approval_Tickets::approve( $ticket_second['ticket_id'] ) ), 'Unable to approve second JetEngine write.' );
$approval_ticket_id = $ticket_second['ticket_id'];
$second = $write_ability->execute( $second_input );
$check( ! is_wp_error( $second ), 'Second JetEngine write failed.' );
update_post_meta( $post_id, $field, 'human-after-ai' );
$approval_ticket_id = '';
$undo_drift_input = array( 'mutation_id' => $second['mutation_id'], 'reason' => 'CI expects JetEngine drift denial' );
$ticket_drift = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', '', $undo_drift_input, 'mutation', 'CI approves drift-test undo', 600 );
$check( is_array( $ticket_drift ) && is_array( MAD4B_SCP_Approval_Tickets::approve( $ticket_drift['ticket_id'] ) ), 'Unable to approve JetEngine drift-test undo.' );
$approval_ticket_id = $ticket_drift['ticket_id'];
$drift = $undo_ability->execute( $undo_drift_input );
$check( is_wp_error( $drift ) && 'mad4b_undo_state_drift' === $drift->get_error_code(), 'JetEngine adapter undo did not deny newer provider state.' );
$check( 'human-after-ai' === get_post_meta( $post_id, $field, true ), 'Rejected JetEngine undo overwrote newer human state.' );

wp_delete_post( $post_id, true );
echo "mad4b.site-control-plane.runtime-jetengine-reversible-adapter.v1: PASS\n";

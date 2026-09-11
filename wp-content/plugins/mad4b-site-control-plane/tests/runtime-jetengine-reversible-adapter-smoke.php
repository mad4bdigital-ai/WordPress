<?php
/**
 * Disposable runtime proof for the exact packaged JetEngine adapter boundary.
 *
 * The reversible implementation exists, but JetEngine 3.8.11.2 also exposes a native
 * MCP REST plane. Under C1 that is a parallel privileged mutation authority, so normal
 * MAD4B mutation must fail closed until that provider-native plane is safely isolated.
 */
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

$peer = MAD4B_SCP_MCP_Peer_Governance::status();
$check( ! empty( $peer['inventory_ready'] ), 'MCP peer inventory is unavailable with JetEngine active.' );
$check( ! empty( $peer['foreign_mcp_detected'] ) && ! empty( $peer['write_side_channel_detected'] ), 'JetEngine native MCP plane was not detected as an unreviewed parallel authority.' );
$check( in_array( 'mcp_foreign_transport_unreviewed', $peer['blockers'], true ), 'JetEngine native MCP transport did not produce the foreign-transport blocker.' );
$jetengine_foreign_route = false;
$foreign = isset( $peer['foreign_transport_inventory'] ) && is_array( $peer['foreign_transport_inventory'] ) ? $peer['foreign_transport_inventory'] : array();
foreach ( isset( $foreign['foreign_routes'] ) && is_array( $foreign['foreign_routes'] ) ? $foreign['foreign_routes'] : array() as $route ) {
	$route = strtolower( (string) $route );
	if ( 0 === strpos( $route, '/jet-engine/v1/' ) && false !== strpos( $route, 'mcp' ) ) { $jetengine_foreign_route = true; break; }
}
$check( $jetengine_foreign_route, 'Foreign MCP evidence was not attributable to the certified JetEngine namespace.' );

$coverage = MAD4B_SCP_Plugin_Discovery::coverage();
$jetengine_coverage = null;
foreach ( $coverage['plugins'] as $item ) if ( 'jet-engine/jet-engine.php' === $item['plugin_file'] ) { $jetengine_coverage = $item; break; }
$check( is_array( $jetengine_coverage ), 'JetEngine was not present in adapter coverage discovery.' );
$check( 'adapter_present_side_channel_blocked' === $jetengine_coverage['coverage_state'], 'JetEngine coverage incorrectly claims mutation readiness while its native MCP plane is active.' );
$check( 'mcp_foreign_transport_unreviewed' === $jetengine_coverage['side_channel_blocker'], 'JetEngine coverage did not expose the native MCP blocker.' );
$check( isset( $jetengine_coverage['support_request']['reason_code'] ) && 'parallel_mcp_write_plane_requires_isolation' === $jetengine_coverage['support_request']['reason_code'], 'JetEngine support request does not require native MCP isolation.' );

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

$approval_ticket_id = '';
add_filter( 'mad4b_scp_authenticated_subject_context', static function () use ( $subject_type, $subject_identifier, &$approval_ticket_id ) {
	return array(
		'authenticated' => true, 'subject_type' => $subject_type, 'subject_identifier' => $subject_identifier,
		'token_scopes' => array( 'ability:jetengine/update-post-meta' ), 'approval_ticket_id' => $approval_ticket_id,
		'auth_method' => 'ci', 'wp_user_id' => get_current_user_id(), 'request_id' => 'ci-jetengine-side-channel-request', 'origin' => 'ci',
	);
}, 999 );
if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) define( 'MAD4B_MCP_MUTATION_ENABLED', true );
$check( MAD4B_SCP_Policy::can_mutate(), 'JetEngine CI NHI did not satisfy the global mutation gate.' );

$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'JetEngine side-channel fixture' ), true );
$check( ! is_wp_error( $post_id ) && $post_id > 0, 'Unable to create JetEngine disposable post.' );
update_post_meta( $post_id, $field, 'before' );
$get_ability = wp_get_ability( 'jetengine/get-post-meta' );
$before = $get_ability->execute( array( 'post_id' => $post_id, 'field' => $field ) );
$check( ! is_wp_error( $before ) && 'before' === $before['value'], 'Unable to read initial JetEngine field.' );
$input = array( 'post_id' => $post_id, 'field' => $field, 'value' => 'should-not-write', 'expected_sha256' => $before['sha256'], 'allow_create' => false );
$ticket = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-content', 'jetengine/update-post-meta', 'jetengine', '', $input, 'mutation', 'CI proves native MCP blocks JetEngine mutation', 600 );
$check( is_array( $ticket ) && 'pending' === $ticket['status'], 'Unable to create exact JetEngine approval ticket.' );
$approved = MAD4B_SCP_Approval_Tickets::approve( $ticket['ticket_id'] );
$check( is_array( $approved ) && 'approved' === $approved['status'], 'Unable to approve exact JetEngine ticket.' );
$approval_ticket_id = $ticket['ticket_id'];

$t = MAD4B_SCP_Schema::tables();
global $wpdb;
$mutations_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['mutations']}" );
$decision = MAD4B_SCP_Authorization::authorize_mutation( 'jetengine/update-post-meta', 'mad4b-content', 'jetengine', $input );
$check( is_wp_error( $decision ) && 'mcp_write_side_channel_detected' === $decision->get_error_code(), 'Central authorization did not fail closed on JetEngine native MCP.' );
$ticket_after_direct = MAD4B_SCP_Approval_Tickets::get( $ticket['ticket_id'] );
$check( is_array( $ticket_after_direct ) && 'approved' === $ticket_after_direct['status'], 'Side-channel denial consumed the single-use approval ticket.' );
$check( 'before' === get_post_meta( $post_id, $field, true ), 'Central side-channel denial changed provider state.' );
$check( $mutations_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['mutations']}" ), 'Side-channel denial created a mutation envelope.' );

$result = $write_ability->execute( $input );
$check( is_wp_error( $result ) && 'ability_invalid_permissions' === $result->get_error_code(), 'WordPress Ability path did not deny JetEngine mutation at permission boundary.' );
$ticket_after_ability = MAD4B_SCP_Approval_Tickets::get( $ticket['ticket_id'] );
$check( is_array( $ticket_after_ability ) && 'approved' === $ticket_after_ability['status'], 'Ability-level side-channel denial consumed approval.' );
$check( 'before' === get_post_meta( $post_id, $field, true ), 'Denied JetEngine ability changed provider state.' );
$check( $mutations_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['mutations']}" ), 'Denied JetEngine ability created a mutation envelope.' );

wp_delete_post( $post_id, true );
echo "mad4b.site-control-plane.runtime-jetengine-side-channel-boundary.v1: PASS\n";

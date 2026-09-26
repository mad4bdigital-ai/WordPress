<?php
/** Disposable runtime proof for the generic reversible adapter envelope using a non-copy Media relationship mutation. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };

$reset_request_ticket_overlay = static function () {
	$reflection = new ReflectionClass( 'MAD4B_SCP_Identity_Context' );
	$property = $reflection->getProperty( 'request_approval_ticket_id' );
	$property->setAccessible( true );
	$property->setValue( null, '' );
};

$check( class_exists( 'MAD4B_SCP_Reversible_Adapter_Mutations' ), 'Generic reversible adapter manager is unavailable.' );
$check( wp_has_ability( 'media/set-featured' ) && wp_has_ability( 'mad4b/mutation-undo' ), 'Required reversible Media relationship abilities are missing.' );
$media_featured = wp_get_ability( 'media/set-featured' );
$media_meta = $media_featured->get_meta();
$check( isset( $media_meta['mcp']['mad4b_reversible_contract'] ) && 'mad4b.rollback.featured-image.v1' === $media_meta['mcp']['mad4b_reversible_contract'], 'Featured-image mutation is not bound to its exact reversible contract.' );

$subject_type = 'ci';
$subject_identifier = 'mad4b-reversible-adapter-agent';
$subject_fingerprint = hash( 'sha256', $subject_type . "\0" . $subject_identifier );
$agent = MAD4B_SCP_Agent_Registry::create_agent( array(
	'slug' => 'ci-reversible-adapter-agent',
	'label' => 'CI Reversible Adapter Agent',
	'status' => 'enabled',
	'environment' => 'all',
	'wp_user_id' => get_current_user_id(),
) );
$check( is_array( $agent ) && ! empty( $agent['public_id'] ), 'Unable to create reversible adapter CI agent.' );
$check( true === MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], $subject_type, $subject_fingerprint, 'CI reversible adapter subject' ), 'Unable to bind reversible adapter CI subject.' );
$check( true === MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-content', 'media/set-featured', 'media', array(), 'allow', 'all' ), 'Unable to grant exact featured-image mutation authority.' );
$check( true === MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', array(), 'allow', 'all' ), 'Unable to grant exact undo authority.' );

$approval_ticket_id = '';
add_filter( 'mad4b_scp_authenticated_subject_context', static function () use ( $subject_type, $subject_identifier, &$approval_ticket_id ) {
	return array(
		'authenticated' => true,
		'subject_type' => $subject_type,
		'subject_identifier' => $subject_identifier,
		'token_scopes' => array( 'ability:media/set-featured', 'ability:mad4b/mutation-undo' ),
		'approval_ticket_id' => $approval_ticket_id,
		'auth_method' => 'ci',
		'wp_user_id' => get_current_user_id(),
		'request_id' => 'ci-reversible-adapter-request',
		'origin' => 'ci',
	);
}, 999 );
if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) define( 'MAD4B_MCP_MUTATION_ENABLED', true );
$check( MAD4B_SCP_Policy::can_mutate(), 'Reversible adapter CI identity did not satisfy mutation authority.' );

$post_id = wp_insert_post( array(
	'post_type' => 'post',
	'post_status' => 'draft',
	'post_title' => 'MAD4B Featured Image Rollback Fixture',
), true );
$check( ! is_wp_error( $post_id ) && $post_id > 0, 'Unable to create disposable featured-image post.' );

$upload = wp_upload_bits(
	'mad4b-reversible-adapter.png',
	null,
	base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WlW9pAAAAAASUVORK5CYII=' )
);
$check( empty( $upload['error'] ) && ! empty( $upload['file'] ) && ! empty( $upload['url'] ), 'Unable to create disposable image file.' );
$attachment_id = wp_insert_attachment( array(
	'post_title' => 'MAD4B Featured Image Fixture',
	'post_mime_type' => 'image/png',
	'post_status' => 'inherit',
	'guid' => $upload['url'],
), $upload['file'], 0, true );
$check( ! is_wp_error( $attachment_id ) && $attachment_id > 0, 'Unable to create disposable image attachment.' );
update_attached_file( $attachment_id, $upload['file'] );
wp_update_attachment_metadata( $attachment_id, array(
	'width' => 1,
	'height' => 1,
	'file' => basename( $upload['file'] ),
	'sizes' => array(),
	'image_meta' => array(),
) );
$check( wp_attachment_is_image( $attachment_id ), 'Disposable attachment is not recognized as an image.' );
$check( 0 === (int) get_post_thumbnail_id( $post_id ), 'Disposable post unexpectedly started with a featured image.' );

$first_input = array(
	'post_id' => $post_id,
	'attachment_id' => $attachment_id,
	'expected_thumbnail_id' => 0,
);
$first = $media_featured->execute( $first_input );
$check( ! is_wp_error( $first ), 'Reversible featured-image mutation failed: ' . ( is_wp_error( $first ) ? $first->get_error_message() : '' ) );
$check( ! empty( $first['mutation_id'] ) && ! empty( $first['verified'] ) && ! empty( $first['reversible'] ), 'Featured-image mutation did not return durable reversible evidence.' );
$check( 'mad4b.rollback.featured-image.v1' === $first['restore_contract'], 'Featured-image mutation returned the wrong restore contract.' );
$record = MAD4B_SCP_Mutation_Manager::get( $first['mutation_id'] );
$check( is_array( $record ) && 'verified' === $record['status'] && 'media' === $record['provider'] && 'post-featured-image' === $record['target_type'], 'Featured-image mutation evidence was not persisted correctly.' );
$check( (string) $post_id === (string) $record['target_id'], 'Featured-image mutation recorded the wrong target identity.' );
$check( 'mad4b-content' === $record['server_id'], 'Featured-image mutation did not record the actual governed specialist transport coordinate.' );
$check( (int) $attachment_id === (int) get_post_thumbnail_id( $post_id ), 'Featured-image provider readback did not contain the mutation.' );

$undo_ability = wp_get_ability( 'mad4b/mutation-undo' );
$undo_input = array( 'mutation_id' => $first['mutation_id'], 'reason' => 'CI restores reversible featured-image relationship' );
$undo_target = MAD4B_SCP_Authorization::target_fingerprint( 'mad4b/mutation-undo', 'core', $undo_input );
$check( is_string( $undo_target ) && preg_match( '/^[a-f0-9]{64}$/', $undo_target ), 'Unable to resolve featured-image undo target fingerprint.' );
$ticket = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', $undo_target, $undo_input, 'mutation', 'CI featured-image undo approval', 600 );
$check( is_array( $ticket ) && 'pending' === $ticket['status'], 'Unable to plan featured-image undo approval.' );
$approved = MAD4B_SCP_Approval_Tickets::approve( $ticket['ticket_id'] );
$check( is_array( $approved ) && 'approved' === $approved['status'], 'Unable to approve featured-image undo.' );
$approval_ticket_id = $ticket['ticket_id'];
$undone = $undo_ability->execute( $undo_input );
$check( ! is_wp_error( $undone ) && 'undone' === $undone['status'] && ! empty( $undone['verified'] ), 'Generic adapter undo failed.' );
$check( 0 === (int) get_post_thumbnail_id( $post_id ), 'Featured-image undo did not restore the exact initial relationship.' );

// Second mutation/undo is a separate request in the live transport. Preserve all
// durable mutation/audit/grant state while resetting only CI's request-local
// approval overlay before assigning a distinct high-impact undo ticket.
$reset_request_ticket_overlay();
$approval_ticket_id = '';
$second = $media_featured->execute( array(
	'post_id' => $post_id,
	'attachment_id' => $attachment_id,
	'expected_thumbnail_id' => 0,
) );
$check( ! is_wp_error( $second ) && ! empty( $second['mutation_id'] ), 'Second featured-image mutation failed.' );
$check( (int) $attachment_id === (int) get_post_thumbnail_id( $post_id ), 'Second featured-image mutation did not reach provider state.' );

// Simulate a newer human relationship decision after the AI mutation. Returning
// the post to no featured image is still a valid newer state and must prevent
// the old AI rollback from writing over it.
$check( true === delete_post_thumbnail( $post_id ) || 0 === (int) get_post_thumbnail_id( $post_id ), 'Unable to create newer human featured-image state.' );
$check( 0 === (int) get_post_thumbnail_id( $post_id ), 'Newer human featured-image state was not applied.' );

$undo_two_input = array( 'mutation_id' => $second['mutation_id'], 'reason' => 'CI expects featured-image drift rejection' );
$undo_two_target = MAD4B_SCP_Authorization::target_fingerprint( 'mad4b/mutation-undo', 'core', $undo_two_input );
$check( is_string( $undo_two_target ) && preg_match( '/^[a-f0-9]{64}$/', $undo_two_target ), 'Unable to resolve featured-image drift undo target fingerprint.' );
$ticket_two = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', $undo_two_target, $undo_two_input, 'mutation', 'CI featured-image drift undo approval', 600 );
$check( is_array( $ticket_two ), 'Unable to create featured-image drift-test approval.' );
$check( is_array( MAD4B_SCP_Approval_Tickets::approve( $ticket_two['ticket_id'] ) ), 'Unable to approve featured-image drift-test ticket.' );
$approval_ticket_id = $ticket_two['ticket_id'];
$drift = $undo_ability->execute( $undo_two_input );
$check( is_wp_error( $drift ) && 'mad4b_undo_state_drift' === $drift->get_error_code(), 'Generic adapter undo did not fail closed on newer featured-image state.' );
$check( 0 === (int) get_post_thumbnail_id( $post_id ), 'Rejected adapter undo overwrote newer human featured-image state.' );
$failed_ticket = MAD4B_SCP_Approval_Tickets::get( $ticket_two['ticket_id'] );
$check( is_array( $failed_ticket ) && 'failed' === $failed_ticket['status'], 'Rejected high-impact adapter undo must terminalize the claimed single-use approval as failed.' );

wp_delete_attachment( $attachment_id, true );
wp_delete_post( $post_id, true );
if ( ! empty( $upload['file'] ) && file_exists( $upload['file'] ) ) @unlink( $upload['file'] );
echo "mad4b.site-control-plane.runtime-reversible-adapter.v2: PASS\n";

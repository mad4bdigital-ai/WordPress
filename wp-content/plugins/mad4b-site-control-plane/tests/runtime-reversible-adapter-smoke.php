<?php
/** Disposable runtime proof for the generic reversible adapter envelope using WordPress Media. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };

$check( class_exists( 'MAD4B_SCP_Reversible_Adapter_Mutations' ), 'Generic reversible adapter manager is unavailable.' );
$check( wp_has_ability( 'media/get' ) && wp_has_ability( 'media/update-metadata' ) && wp_has_ability( 'mad4b/mutation-undo' ), 'Required reversible Media abilities are missing.' );
$media_update = wp_get_ability( 'media/update-metadata' );
$media_meta = $media_update->get_meta();
$check( isset( $media_meta['mcp']['mad4b_reversible_contract'] ) && 'mad4b.rollback.media-metadata.v1' === $media_meta['mcp']['mad4b_reversible_contract'], 'Media mutation is not bound to its exact reversible contract.' );

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
$check( true === MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-content', 'media/update-metadata', 'media', array(), 'allow', 'all' ), 'Unable to grant exact Media mutation authority.' );
$check( true === MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', array(), 'allow', 'all' ), 'Unable to grant exact undo authority.' );

$approval_ticket_id = '';
add_filter( 'mad4b_scp_authenticated_subject_context', static function () use ( $subject_type, $subject_identifier, &$approval_ticket_id ) {
	return array(
		'authenticated' => true,
		'subject_type' => $subject_type,
		'subject_identifier' => $subject_identifier,
		'token_scopes' => array( 'ability:media/update-metadata', 'ability:mad4b/mutation-undo' ),
		'approval_ticket_id' => $approval_ticket_id,
		'auth_method' => 'ci',
		'wp_user_id' => get_current_user_id(),
		'request_id' => 'ci-reversible-adapter-request',
		'origin' => 'ci',
	);
}, 999 );
if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) define( 'MAD4B_MCP_MUTATION_ENABLED', true );
$check( MAD4B_SCP_Policy::can_mutate(), 'Reversible adapter CI identity did not satisfy mutation authority.' );

$attachment_id = wp_insert_attachment( array(
	'post_title' => 'MAD4B Media Before',
	'post_excerpt' => 'before caption',
	'post_content' => 'before description',
	'post_mime_type' => 'image/jpeg',
	'post_status' => 'inherit',
), false, 0, true );
$check( ! is_wp_error( $attachment_id ) && $attachment_id > 0, 'Unable to create disposable attachment.' );
update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'before alt' );

$media_get = wp_get_ability( 'media/get' );
$before = $media_get->execute( array( 'attachment_id' => $attachment_id ) );
$check( ! is_wp_error( $before ) && ! empty( $before['sha256'] ), 'Unable to read initial Media state.' );
$first_input = array(
	'attachment_id' => $attachment_id,
	'expected_sha256' => $before['sha256'],
	'title' => 'MAD4B Media After One',
	'caption' => 'after caption one',
	'description' => 'after description one',
	'alt' => 'after alt one',
);
$first = $media_update->execute( $first_input );
$check( ! is_wp_error( $first ), 'Reversible Media mutation failed: ' . ( is_wp_error( $first ) ? $first->get_error_message() : '' ) );
$check( ! empty( $first['mutation_id'] ) && ! empty( $first['verified'] ) && ! empty( $first['reversible'] ), 'Media mutation did not return durable reversible evidence.' );
$check( 'mad4b.rollback.media-metadata.v1' === $first['restore_contract'], 'Media mutation returned the wrong restore contract.' );
$record = MAD4B_SCP_Mutation_Manager::get( $first['mutation_id'] );
$check( is_array( $record ) && 'verified' === $record['status'] && 'media' === $record['provider'] && 'media' === $record['target_type'], 'Media mutation evidence was not persisted correctly.' );
$check( 'mad4b-content' === $record['server_id'], 'Media mutation did not record the actual governed specialist transport coordinate.' );

$after = $media_get->execute( array( 'attachment_id' => $attachment_id ) );
$check( 'MAD4B Media After One' === $after['media']['title'] && 'after alt one' === $after['media']['alt'], 'Media provider readback did not contain the mutation.' );

$undo_ability = wp_get_ability( 'mad4b/mutation-undo' );
$undo_input = array( 'mutation_id' => $first['mutation_id'], 'reason' => 'CI restores reversible Media metadata' );
$ticket = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', '', $undo_input, 'mutation', 'CI Media undo approval', 600 );
$check( is_array( $ticket ) && 'pending' === $ticket['status'], 'Unable to plan Media undo approval.' );
$approved = MAD4B_SCP_Approval_Tickets::approve( $ticket['ticket_id'] );
$check( is_array( $approved ) && 'approved' === $approved['status'], 'Unable to approve Media undo.' );
$approval_ticket_id = $ticket['ticket_id'];
$undone = $undo_ability->execute( $undo_input );
$check( ! is_wp_error( $undone ) && 'undone' === $undone['status'] && ! empty( $undone['verified'] ), 'Generic adapter undo failed.' );
$restored = $media_get->execute( array( 'attachment_id' => $attachment_id ) );
$check( 'MAD4B Media Before' === $restored['media']['title'], 'Media undo did not restore title.' );
$check( 'before caption' === $restored['media']['caption'] && 'before description' === $restored['media']['description'] && 'before alt' === $restored['media']['alt'], 'Media undo did not restore exact metadata state.' );

// Second mutation followed by human drift must be rejected without overwrite.
$approval_ticket_id = '';
$current = $media_get->execute( array( 'attachment_id' => $attachment_id ) );
$second = $media_update->execute( array(
	'attachment_id' => $attachment_id,
	'expected_sha256' => $current['sha256'],
	'alt' => 'after alt two',
) );
$check( ! is_wp_error( $second ) && ! empty( $second['mutation_id'] ), 'Second Media mutation failed.' );
update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'human alt after AI' );

$undo_two_input = array( 'mutation_id' => $second['mutation_id'], 'reason' => 'CI expects adapter drift rejection' );
$ticket_two = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', '', $undo_two_input, 'mutation', 'CI Media drift undo approval', 600 );
$check( is_array( $ticket_two ), 'Unable to create Media drift-test approval.' );
$check( is_array( MAD4B_SCP_Approval_Tickets::approve( $ticket_two['ticket_id'] ) ), 'Unable to approve Media drift-test ticket.' );
$approval_ticket_id = $ticket_two['ticket_id'];
$drift = $undo_ability->execute( $undo_two_input );
$check( is_wp_error( $drift ) && 'mad4b_undo_state_drift' === $drift->get_error_code(), 'Generic adapter undo did not fail closed on newer Media state.' );
$check( 'human alt after AI' === get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ), 'Rejected adapter undo overwrote newer human Media state.' );
$used = MAD4B_SCP_Approval_Tickets::get( $ticket_two['ticket_id'] );
$check( is_array( $used ) && 'used' === $used['status'], 'Attempted high-impact adapter undo did not consume its single-use approval ticket.' );

wp_delete_attachment( $attachment_id, true );
echo "mad4b.site-control-plane.runtime-reversible-adapter.v1: PASS\n";

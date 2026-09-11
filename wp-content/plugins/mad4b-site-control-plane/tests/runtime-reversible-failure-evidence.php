<?php
/** Runtime proof that a provider error after a real side effect preserves bounded reversible evidence. */
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );
$check = static function ( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); };

final class MAD4B_CI_Failure_Evidence_Adapter extends MAD4B_SCP_Adapter_Base {
	const OPTION = 'mad4b_ci_failure_evidence_state';
	public function id() { return 'ci-failure-evidence'; }
	public function label() { return 'CI Failure Evidence'; }
	public function is_available() { return true; }
	public function ability_names() { return array( 'read' => array(), 'content' => array( 'ci/failure-evidence-write' ), 'admin' => array() ); }
	public function reversible_contracts() { return array( 'ci/failure-evidence-write' => 'mad4b.rollback.ci-failure-evidence.v1' ); }
	public function register_abilities() {}
	protected function mutation_requires_certification() { return false; }
	protected function detect_plugin_version() { return '1.0.0-ci'; }
	public function mutate_then_fail( $input ) {
		update_option( self::OPTION, array( 'value' => 'after-side-effect' ), false );
		return new WP_Error( 'mad4b_ci_provider_failed_after_write', 'CI provider intentionally fails after changing state.' );
	}
	public function capture_reversible_state( $ability_name, array $input ) {
		$state = get_option( self::OPTION, array( 'value' => 'missing' ) );
		return array( 'target_type' => 'ci-option', 'target_id' => self::OPTION, 'target' => array( 'option' => self::OPTION ), 'state' => is_array( $state ) ? $state : array( 'value' => (string) $state ) );
	}
	public function read_reversible_state( $ability_name, array $target ) {
		$state = get_option( self::OPTION, array( 'value' => 'missing' ) );
		return is_array( $state ) ? $state : array( 'value' => (string) $state );
	}
	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) {
		update_option( self::OPTION, $state, false );
		return true;
	}
}

$check( class_exists( 'MAD4B_SCP_Reversible_Adapter_Mutations' ), 'reversible adapter manager unavailable' );
$check( defined( 'MAD4B_SCP_VERSION' ) && '0.4.0-rc.26' === MAD4B_SCP_VERSION, 'failure-evidence regression must run against rc.26' );

$subject_type = 'ci-failure-evidence';
$subject_identifier = 'ci-failure-evidence-subject';
$subject_fingerprint = hash( 'sha256', $subject_type . "\0" . $subject_identifier );
$approval_ticket_id = '';
$request_id = 'ci-failure-evidence-request';
$agent = MAD4B_SCP_Agent_Registry::create_agent( array(
	'slug' => 'ci-failure-evidence-' . substr( wp_generate_uuid4(), 0, 8 ),
	'label' => 'CI Failure Evidence Agent',
	'status' => 'enabled',
	'environment' => 'all',
	'wp_user_id' => get_current_user_id(),
) );
$check( is_array( $agent ) && ! empty( $agent['public_id'] ), 'unable to create failure-evidence CI agent' );
$check( true === MAD4B_SCP_Agent_Registry::bind_subject( $agent['public_id'], $subject_type, $subject_fingerprint, 'CI failure evidence subject' ), 'unable to bind failure-evidence subject' );
$check( true === MAD4B_SCP_Agent_Registry::grant_ability( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', array(), 'allow', 'all' ), 'unable to grant undo authority' );

add_filter( 'mad4b_scp_authenticated_subject_context', static function () use ( $subject_type, $subject_identifier, &$approval_ticket_id, &$request_id ) {
	return array(
		'authenticated' => true,
		'subject_type' => $subject_type,
		'subject_identifier' => $subject_identifier,
		'token_scopes' => array( 'ability:mad4b/mutation-undo' ),
		'approval_ticket_id' => $approval_ticket_id,
		'auth_method' => 'ci',
		'wp_user_id' => get_current_user_id(),
		'request_id' => $request_id,
		'origin' => 'ci',
	);
}, 999 );
if ( ! defined( 'MAD4B_MCP_MUTATION_ENABLED' ) ) define( 'MAD4B_MCP_MUTATION_ENABLED', true );
$check( MAD4B_SCP_Policy::can_mutate(), 'failure-evidence identity did not satisfy mutation authority' );

$adapter = new MAD4B_CI_Failure_Evidence_Adapter();
$check( MAD4B_SCP_Adapter_Registry::instance()->register( $adapter ), 'unable to register disposable failure-evidence adapter' );
update_option( MAD4B_CI_Failure_Evidence_Adapter::OPTION, array( 'value' => 'before-side-effect' ), false );

$error = MAD4B_SCP_Reversible_Adapter_Mutations::execute( $adapter, 'ci/failure-evidence-write', 'mutate_then_fail', array( 'value' => 'after-side-effect' ) );
$check( is_wp_error( $error ), 'provider failure did not return WP_Error' );
$check( 'mad4b_ci_provider_failed_after_write' === $error->get_error_code(), 'original provider error code was not preserved' );
$data = $error->get_error_data( $error->get_error_code() );
$check( is_array( $data ) && isset( $data['mad4b_mutation_evidence'] ) && is_array( $data['mad4b_mutation_evidence'] ), 'bounded mutation failure evidence missing from error' );
$evidence = $data['mad4b_mutation_evidence'];
$check( 'mad4b.mutation-failure-evidence.v1' === $evidence['contract'], 'unexpected mutation failure evidence contract' );
$check( ! empty( $evidence['mutation_id'] ), 'failure evidence omitted mutation_id' );
$check( 'verification_failed' === $evidence['mutation_status'], 'side-effecting provider failure was not classified verification_failed' );
$check( ! empty( $evidence['restore_available'] ), 'side-effecting provider failure did not advertise safe restore availability' );
$check( ! empty( $evidence['before_sha256'] ) && ! empty( $evidence['observed_after_sha256'] ) && ! hash_equals( $evidence['before_sha256'], $evidence['observed_after_sha256'] ), 'failure evidence did not bind distinct before/observed-after hashes' );
$check( false === strpos( wp_json_encode( $data ), 'rollback_payload' ), 'failure response leaked rollback payload material' );

$record = MAD4B_SCP_Mutation_Manager::get( $evidence['mutation_id'] );
$check( is_array( $record ) && 'verification_failed' === $record['status'], 'durable mutation record did not preserve verification_failed status' );
$check( hash_equals( $evidence['observed_after_sha256'], $record['after_sha256'] ), 'durable record after hash disagrees with bounded failure evidence' );
$check( MAD4B_SCP_Reversible_Adapter_Mutations::can_undo_record( $record ), 'recoverable verification failure is not eligible for drift-safe undo' );
$current = get_option( MAD4B_CI_Failure_Evidence_Adapter::OPTION, array() );
$check( is_array( $current ) && 'after-side-effect' === $current['value'], 'fixture did not retain provider side effect before undo' );

$undo = wp_get_ability( 'mad4b/mutation-undo' );
$check( is_object( $undo ), 'mutation-undo ability unavailable' );
$undo_input = array( 'mutation_id' => $evidence['mutation_id'], 'reason' => 'CI restores a provider failure after observed side effect' );
$undo_target = MAD4B_SCP_Authorization::target_fingerprint( 'mad4b/mutation-undo', 'core', $undo_input );
$ticket = MAD4B_SCP_Approval_Tickets::create_pending( $agent['public_id'], 'mad4b-admin', 'mad4b/mutation-undo', 'core', $undo_target, $undo_input, 'mutation', 'CI failure-evidence recovery approval', 600 );
$check( is_array( $ticket ) && 'pending' === $ticket['status'], 'unable to create recovery approval ticket' );
$approved = MAD4B_SCP_Approval_Tickets::approve( $ticket['ticket_id'] );
$check( is_array( $approved ) && 'approved' === $approved['status'], 'unable to approve recovery ticket' );
$approval_ticket_id = $ticket['ticket_id'];
$request_id = 'ci-failure-evidence-undo-request';
$undone = $undo->execute( $undo_input );
$check( ! is_wp_error( $undone ) && 'undone' === $undone['status'] && ! empty( $undone['verified'] ), 'governed undo did not restore recoverable failure: ' . ( is_wp_error( $undone ) ? $undone->get_error_message() : '' ) );
$restored = get_option( MAD4B_CI_Failure_Evidence_Adapter::OPTION, array() );
$check( is_array( $restored ) && 'before-side-effect' === $restored['value'], 'recoverable failure undo did not restore exact before state' );
$used = MAD4B_SCP_Approval_Tickets::get( $approval_ticket_id );
$check( is_array( $used ) && 'used' === $used['status'], 'recovery ticket did not terminalize as used' );

delete_option( MAD4B_CI_Failure_Evidence_Adapter::OPTION );
echo "mad4b.site-control-plane.reversible-failure-evidence.v1: PASS\n";

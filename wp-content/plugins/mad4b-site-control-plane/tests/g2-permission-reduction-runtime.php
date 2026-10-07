<?php
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['g2_perm_abilities'] = array();
$GLOBALS['g2_perm_agents'] = array(
	'11111111-1111-4111-8111-111111111111' => array(
		'id' => 9,
		'public_id' => '11111111-1111-4111-8111-111111111111',
		'status' => 'enabled',
		'revision' => 3,
	),
);
$GLOBALS['g2_perm_subjects'] = array(
	'oauth:' . str_repeat( 'a', 64 ) => array(
		'agent_id' => 9,
		'status' => 'enabled',
	),
);
$GLOBALS['g2_perm_grants'] = array(
	55 => array(
		'id' => 55,
		'agent_id' => 9,
		'effect' => 'allow',
		'server_id' => 'mad4b-admin',
		'ability_name' => 'mad4b/audit-storage-status',
		'provider' => 'core',
		'environment' => 'staging',
	),
);
$GLOBALS['g2_perm_audit'] = array();

function add_action() { return true; }
function current_user_can() { return true; }
function wp_has_ability( $name ) { return isset( $GLOBALS['g2_perm_abilities'][ $name ] ); }
function wp_register_ability( $name, $args ) { $GLOBALS['g2_perm_abilities'][ $name ] = $args; return true; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

final class MAD4B_SCP_Agent_Registry {
	public static function get_agent_by_public_id( $public_id ) {
		return isset( $GLOBALS['g2_perm_agents'][ $public_id ] ) ? $GLOBALS['g2_perm_agents'][ $public_id ] : null;
	}
	public static function disable_agent( $public_id, $expected_revision ) {
		if ( ! isset( $GLOBALS['g2_perm_agents'][ $public_id ] ) ) return new WP_Error( 'missing', 'missing' );
		$a = $GLOBALS['g2_perm_agents'][ $public_id ];
		if ( (int) $a['revision'] !== (int) $expected_revision ) return new WP_Error( 'stale', 'stale' );
		$a['status'] = 'disabled';
		$a['revision'] = (int) $a['revision'] + 1;
		$GLOBALS['g2_perm_agents'][ $public_id ] = $a;
		return $a;
	}
	public static function subject_binding( $type, $fingerprint ) {
		$key = sanitize_key( $type ) . ':' . strtolower( trim( (string) $fingerprint ) );
		return isset( $GLOBALS['g2_perm_subjects'][ $key ] ) ? $GLOBALS['g2_perm_subjects'][ $key ] : null;
	}
	public static function set_subject_status( $public_id, $type, $fingerprint, $status ) {
		$agent = self::get_agent_by_public_id( $public_id );
		if ( ! $agent ) return new WP_Error( 'missing', 'missing' );
		$key = sanitize_key( $type ) . ':' . strtolower( trim( (string) $fingerprint ) );
		if ( ! isset( $GLOBALS['g2_perm_subjects'][ $key ] ) ) return new WP_Error( 'subject_missing', 'subject missing' );
		if ( (int) $GLOBALS['g2_perm_subjects'][ $key ]['agent_id'] !== (int) $agent['id'] ) return new WP_Error( 'wrong_agent', 'wrong agent' );
		$GLOBALS['g2_perm_subjects'][ $key ]['status'] = sanitize_key( $status );
		return true;
	}
	public static function grants_for_agent( $agent_id, $server_id = '' ) {
		$out = array();
		foreach ( $GLOBALS['g2_perm_grants'] as $row ) {
			if ( (int) $row['agent_id'] !== (int) $agent_id ) continue;
			if ( '' !== $server_id && $server_id !== (string) $row['server_id'] ) continue;
			$out[] = $row;
		}
		return $out;
	}
	public static function revoke_allow_grant_by_id( $public_id, $grant_id, $server_id = '' ) {
		$agent = self::get_agent_by_public_id( $public_id );
		if ( ! $agent ) return new WP_Error( 'missing', 'missing' );
		if ( ! isset( $GLOBALS['g2_perm_grants'][ $grant_id ] ) ) return true;
		$row = $GLOBALS['g2_perm_grants'][ $grant_id ];
		if ( (int) $row['agent_id'] !== (int) $agent['id'] || 'allow' !== (string) $row['effect'] ) return new WP_Error( 'invalid_grant', 'invalid grant' );
		if ( '' !== $server_id && $server_id !== (string) $row['server_id'] ) return new WP_Error( 'server_mismatch', 'server mismatch' );
		unset( $GLOBALS['g2_perm_grants'][ $grant_id ] );
		return true;
	}
	public static function grant_ability() { throw new RuntimeException( 'Authority expansion must never be called.' ); }
	public static function bind_subject() { throw new RuntimeException( 'Subject enable/bind must never be called.' ); }
	public static function create_agent() { throw new RuntimeException( 'Agent creation must never be called.' ); }
}

final class MAD4B_SCP_Audit {
	public static function storage_status() { return array( 'ready' => true ); }
	public static function record( $ability, array $summary, $status = 'ok', $join_transaction = false ) {
		$GLOBALS['g2_perm_audit'][] = array( 'ability' => $ability, 'summary' => $summary, 'status' => $status );
		return array( 'id' => count( $GLOBALS['g2_perm_audit'] ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g2-permission-changes.php';

function g2p_check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

MAD4B_SCP_G2_Permission_Changes::register_abilities();
g2p_check(
	array( 'mad4b/agent-permission-plan', 'mad4b/agent-permission-apply' ) === array_keys( $GLOBALS['g2_perm_abilities'] ),
	'Permission ability registration drifted.'
);
g2p_check( true === $GLOBALS['g2_perm_abilities']['mad4b/agent-permission-plan']['meta']['annotations']['readonly'], 'Plan must remain read-only.' );
g2p_check( false === $GLOBALS['g2_perm_abilities']['mad4b/agent-permission-apply']['meta']['annotations']['readonly'], 'Apply must remain a write.' );
g2p_check( true === $GLOBALS['g2_perm_abilities']['mad4b/agent-permission-apply']['meta']['annotations']['destructive'], 'Apply must remain destructive/authority-reducing.' );

$base = array(
	'agent_public_id' => '11111111-1111-4111-8111-111111111111',
	'operation' => 'disable_agent',
	'expected_revision' => 3,
	'reason' => 'fixture authority reduction',
);
$plan = MAD4B_SCP_G2_Permission_Changes::plan( $base );
g2p_check( is_array( $plan ) && true === $plan['eligible'], 'Disable-agent plan should be eligible.' );
g2p_check( false === $plan['authority_expansion'] && false === $plan['grant_creation_allowed'] && false === $plan['enable_or_restore_allowed'], 'Plan widened authority.' );
g2p_check( 64 === strlen( $plan['plan_sha256'] ), 'Plan digest missing.' );

$bad = $base;
$bad['plan_sha256'] = str_repeat( '0', 64 );
$bad['confirmation'] = MAD4B_SCP_G2_Permission_Changes::CONFIRMATION;
$result = MAD4B_SCP_G2_Permission_Changes::apply( $bad );
g2p_check( is_wp_error( $result ) && 'mad4b_g2_permission_plan_drift' === $result->get_error_code(), 'Plan drift must fail closed.' );
g2p_check( 'enabled' === $GLOBALS['g2_perm_agents'][ $base['agent_public_id'] ]['status'], 'Failed plan drift mutated agent.' );

$apply = $base;
$apply['plan_sha256'] = $plan['plan_sha256'];
$apply['confirmation'] = MAD4B_SCP_G2_Permission_Changes::CONFIRMATION;
$result = MAD4B_SCP_G2_Permission_Changes::apply( $apply );
g2p_check( is_array( $result ) && true === $result['mutation_performed'] && true === $result['readback']['verified'], 'Disable-agent apply/readback failed.' );
g2p_check( 'disabled' === $GLOBALS['g2_perm_agents'][ $base['agent_public_id'] ]['status'], 'Agent was not disabled.' );
g2p_check( 4 === $GLOBALS['g2_perm_agents'][ $base['agent_public_id'] ]['revision'], 'Agent revision was not advanced.' );
g2p_check( 1 === count( $GLOBALS['g2_perm_audit'] ), 'Authority reduction audit missing.' );

$GLOBALS['g2_perm_agents'][ $base['agent_public_id'] ]['status'] = 'enabled';
$grantInput = array(
	'agent_public_id' => $base['agent_public_id'],
	'operation' => 'revoke_allow_grant',
	'expected_revision' => 4,
	'server_id' => 'mad4b-admin',
	'grant_id' => 55,
	'reason' => 'fixture revoke grant',
);
$grantPlan = MAD4B_SCP_G2_Permission_Changes::plan( $grantInput );
g2p_check( is_array( $grantPlan ) && true === $grantPlan['eligible'], 'Grant revoke plan should be eligible.' );
$grantInput['plan_sha256'] = $grantPlan['plan_sha256'];
$grantInput['confirmation'] = MAD4B_SCP_G2_Permission_Changes::CONFIRMATION;
$grantApply = MAD4B_SCP_G2_Permission_Changes::apply( $grantInput );
g2p_check( is_array( $grantApply ) && true === $grantApply['readback']['verified'], 'Grant revoke readback failed.' );
g2p_check( ! isset( $GLOBALS['g2_perm_grants'][55] ), 'Allow grant still exists after revoke.' );

$subjectInput = array(
	'agent_public_id' => $base['agent_public_id'],
	'operation' => 'disable_subject',
	'expected_revision' => 4,
	'subject_type' => 'oauth',
	'subject_fingerprint' => str_repeat( 'a', 64 ),
	'reason' => 'fixture disable subject',
);
$subjectPlan = MAD4B_SCP_G2_Permission_Changes::plan( $subjectInput );
g2p_check( is_array( $subjectPlan ) && true === $subjectPlan['eligible'], 'Subject disable plan should be eligible.' );
$subjectInput['plan_sha256'] = $subjectPlan['plan_sha256'];
$subjectInput['confirmation'] = MAD4B_SCP_G2_Permission_Changes::CONFIRMATION;
$subjectApply = MAD4B_SCP_G2_Permission_Changes::apply( $subjectInput );
g2p_check( is_array( $subjectApply ) && true === $subjectApply['readback']['verified'], 'Subject disable readback failed.' );
g2p_check( 'disabled' === $GLOBALS['g2_perm_subjects']['oauth:' . str_repeat( 'a', 64 )]['status'], 'Subject was not disabled.' );

echo json_encode(
	array(
		'contract' => 'mad4b.feature007-g2-permission-reduction-runtime.v1',
		'status' => 'PASS',
		'authority_expansion' => false,
		'apply_paths' => array( 'disable_agent', 'disable_subject', 'revoke_allow_grant' ),
		'audit_events' => count( $GLOBALS['g2_perm_audit'] ),
	)
) . "\n";

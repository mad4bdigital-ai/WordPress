<?php
define( 'ABSPATH', __DIR__ . '/' );
function add_action( ...$args ) {}
$GLOBALS['mad4b_registered_abilities']=array();
function wp_register_ability( $name, $args ) { $GLOBALS['mad4b_registered_abilities'][$name]=$args; }
function wp_has_ability( $name ) { return isset( $GLOBALS['mad4b_registered_abilities'][$name] ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return (string) $v; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_get_environment_type() { return 'staging'; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code=$code; $this->message=$message; $this->data=$data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class MAD4B_SCP_Policy { public static function can_read() { return true; } }
class MAD4B_SCP_Environment { public static function effective() { return 'staging'; } }
class MAD4B_SCP_Live_Acceptance_Observer {
	public static function build_provenance_identity_status() {
		return array(
			'identity_ready' => true,
			'source_commit_sha' => str_repeat( 'a', 40 ),
			'build_fingerprint' => str_repeat( 'b', 64 ),
			'package_manifest_digest' => str_repeat( 'c', 64 ),
		);
	}
}
class MAD4B_SCP_MCP_Peer_Governance {
	public static $blocked = false;
	public static function status() {
		return array(
			'contract' => 'mad4b.mcp-peer-governance.v2',
			'inventory_ready' => true,
			'foreign_transport_inventory_ready' => true,
			'write_side_channel_detected' => self::$blocked,
			'foreign_transport_unreviewed' => false,
			'blockers' => self::$blocked ? array( 'mcp_write_side_channel_detected' ) : array(),
		);
	}
}
class MAD4B_SCP_Identity_Context {
	public static function current() {
		return array(
			'authenticated' => true,
			'auth_method' => 'oauth2_bearer',
			'subject_fingerprint' => str_repeat( 'd', 64 ),
			'issuer_fingerprint' => hash( 'sha256', 'oauth-issuer' . "\0" . 'https://issuer.test' ),
		);
	}
}
class MAD4B_SCP_Multi_Authority_Registry {
	public static function snapshot() {
		return array(
			'contract' => 'mad4b.multi-authority-registry.v1',
			'authority_snapshot_sha256' => str_repeat( 'e', 64 ),
			'authorities' => array(
				array(
					'authority_id' => 'oauth-authority:v1:test',
					'authority_type' => 'local',
					'issuer' => 'https://issuer.test',
					'issuer_fingerprint' => hash( 'sha256', 'oauth-issuer' . "\0" . 'https://issuer.test' ),
					'trusted' => true,
					'advertised' => true,
					'allowed_subject_count' => 1,
					'resource_server_ids' => array( 'mad4b-chatgpt' ),
				),
			),
		);
	}
}
class MAD4B_SCP_OAuth_Resource_Bridge {
	public static function status() { return array( 'contract' => 'mad4b.oauth-resource-bridge.v1', 'effective' => true ); }
	public static function issuer_fingerprint_for_issuer( $issuer ) { return hash( 'sha256', 'oauth-issuer' . "\0" . rtrim( trim( (string) $issuer ), '/' ) ); }
}
class MAD4B_SCP_Transport_Context { public static function current_server_id() { return 'mad4b-chatgpt'; } }
class MAD4B_SCP_Authorization {
	public static function authority_status() { return array( 'status' => 'ready_read_only', 'blockers' => array() ); }
}
class MAD4B_SCP_Servers {
	public static $raw_query_mounted = false;
	public static function write_tools() { return self::$raw_query_mounted ? array( 'media/set-featured', 'mad4b/database-raw-query' ) : array( 'media/set-featured' ); }
	public static function chatgpt_base_tools() { return array( 'mad4b/read-discover', 'mad4b/write-discover' ); }
}
class MAD4B_SCP_Host_Bridge {
	public static $generic_shell = false;
	public static function capabilities() {
		return array(
			'contract' => 'mad4b.host-bridge.v1',
			'operations' => array(),
			'generic_shell_available' => self::$generic_shell,
			'raw_sql_available' => false,
			'caller_executable_paths_allowed' => false,
			'caller_command_strings_allowed' => false,
			'production_authorized' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}
}
class MAD4B_SCP_Governed_Runtime_Gates {
	public static function raw_sql_write_enabled() { return false; }
	public static function raw_sql_ddl_enabled() { return false; }
	public static function raw_sql_breakglass_enabled() { return false; }
}
class MAD4B_SCP_Policy_Resolution {
	public static function current_operating_mode() { return 'SINGLE_OWNER_HARDENED'; }
	public static function config_digest() { return str_repeat( 'f', 64 ); }
	public static function environment_allowed( $env ) { return 'staging' === $env; }
	public static function resolve( array $facts ) {
		if ( ! empty( $facts['kill_switch_active'] ) ) return array( 'contract' => 'mad4b.policy-resolution.v1', 'decision' => 'DENY', 'reason_code' => 'kill_switch_active' );
		return array( 'contract' => 'mad4b.policy-resolution.v1', 'decision' => 'ALLOW', 'reason_code' => 'policy_allow' );
	}
}
class MAD4B_SCP_Operator_Doctor {
	public static function doctor( $input = array() ) {
		return array(
			'contract' => 'mad4b.operator-doctor.v1',
			'healthy' => true,
			'finding_count' => 0,
			'findings' => array(),
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-production-certification.php';
MAD4B_SCP_Production_Certification::register_ability();

function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
check( isset( $GLOBALS['mad4b_registered_abilities'][ MAD4B_SCP_Production_Certification::ABILITY ] ), 'evidence_ability_not_registered' );
check( isset( $GLOBALS['mad4b_registered_abilities'][ MAD4B_SCP_Production_Certification::STATUS_ABILITY ] ), 'status_ability_not_registered' );
check( isset( $GLOBALS['mad4b_registered_abilities'][ MAD4B_SCP_Production_Certification::PLAN_ABILITY ] ), 'plan_ability_not_registered' );
$status_ability = $GLOBALS['mad4b_registered_abilities'][ MAD4B_SCP_Production_Certification::STATUS_ABILITY ];
check( ! empty( $status_ability['meta']['annotations']['readonly'] ) && empty( $status_ability['meta']['annotations']['destructive'] ), 'status_ability_not_read_only' );
$plan_ability = $GLOBALS['mad4b_registered_abilities'][ MAD4B_SCP_Production_Certification::PLAN_ABILITY ];
check( ! empty( $plan_ability['meta']['annotations']['readonly'] ) && empty( $plan_ability['meta']['annotations']['destructive'] ), 'plan_ability_not_read_only' );
$stages = array(
	'provider_side_channel_inventory',
	'multi_authority_canary',
	'policy_resolution_canary',
	'security_fault_canary',
	'operator_doctor',
);
foreach ( $stages as $stage ) {
	$row = MAD4B_SCP_Production_Certification::execute( array( 'stage_id' => $stage ) );
	check( ! is_wp_error( $row ), $stage . ' returned WP_Error' );
	check( ! empty( $row['ready'] ), $stage . ' was not ready: ' . json_encode( $row ) );
	check( false === $row['mutation_performed'] && false === $row['production_mutation'] && false === $row['authorizing'], $stage . ' widened authority or mutation' );
	check( str_repeat( 'a', 40 ) === $row['candidate_identity']['source_commit_sha'], $stage . ' lost candidate binding' );
	check( 64 === strlen( $row['producer_evidence_sha256'] ), $stage . ' evidence digest missing' );
}
$multi_authority = MAD4B_SCP_Production_Certification::execute( array( 'stage_id' => 'multi_authority_canary' ) );
check( ! is_wp_error( $multi_authority ) && ! empty( $multi_authority['ready'] ), 'multi_authority_canary_not_ready_for_identity_readback' );
$multi_evidence = isset( $multi_authority['producer_evidence'] ) && is_array( $multi_authority['producer_evidence'] ) ? $multi_authority['producer_evidence'] : array();
check( 'oauth-authority:v1:test' === ( isset( $multi_evidence['authority_id'] ) ? $multi_evidence['authority_id'] : '' ), 'multi-authority canary lost canonical authority identity' );
check( 'local' === ( isset( $multi_evidence['authority_type'] ) ? $multi_evidence['authority_type'] : '' ), 'multi-authority canary lost canonical authority type' );
check( 'https://issuer.test' === ( isset( $multi_evidence['issuer'] ) ? $multi_evidence['issuer'] : '' ), 'multi-authority canary lost canonical issuer binding' );
$expected_issuer_fingerprint = hash( 'sha256', 'oauth-issuer' . "\0" . 'https://issuer.test' );
check(
	isset( $multi_evidence['registry_issuer_fingerprint'] )
	&& hash_equals( $expected_issuer_fingerprint, (string) $multi_evidence['registry_issuer_fingerprint'] )
	&& isset( $multi_evidence['issuer_fingerprint'] )
	&& hash_equals( $expected_issuer_fingerprint, (string) $multi_evidence['issuer_fingerprint'] ),
	'multi-authority canary canonical issuer fingerprint drifted'
);
$session = MAD4B_SCP_Production_Certification::plan();
check( ! is_wp_error( $session ) && 'mad4b.production-certification-session-plan.v1' === $session['contract'], 'certification session plan unavailable' );
check( count( $stages ) === $session['local_evidence_count'] && 0 < $session['external_evidence_required_count'], 'certification session did not separate local and external evidence' );
check( $session['stage_count'] === $session['local_evidence_count'] + $session['external_evidence_required_count'], 'certification session stage accounting drifted' );
check( 64 === strlen( $session['plan_sha256'] ) && 0 === strpos( $session['session_id'], 'pc-' ), 'certification session identity missing' );
check( 'mad4b/production-readiness-evaluate' === $session['terminal_evaluator'], 'certification session terminal evaluator drifted' );
check( false === $session['production_ready'] && false === $session['production_authorized'] && false === $session['mutation_performed'], 'certification session became authorizing or mutating' );
$session_repeat = MAD4B_SCP_Production_Certification::plan();
check( ! is_wp_error( $session_repeat ) && hash_equals( $session['plan_sha256'], $session_repeat['plan_sha256'] ) && hash_equals( $session['session_id'], $session_repeat['session_id'] ), 'certification session plan is not deterministic for one exact candidate' );
$status = MAD4B_SCP_Production_Certification::status();
check( ! is_wp_error( $status ) && 'mad4b.production-certification-status.v1' === $status['contract'], 'certification status unavailable' );
check( count( $stages ) === $status['local_runtime_stage_count'] && count( $stages ) === $status['local_ready_count'], 'local certification status did not execute all read-only canaries' );
check( $status['stage_count'] > $status['local_runtime_stage_count'] && 0 < $status['external_evidence_required_count'], 'external certification requirements were not exposed' );
check( false === $status['production_ready'] && false === $status['production_authorized'] && false === $status['mutation_performed'], 'certification status became authorizing or inferred readiness' );
check( ! empty( $status['optional_capabilities_disabled'] ), 'core profile did not expose fail-closed optional capability set' );
MAD4B_SCP_MCP_Peer_Governance::$blocked = true;
$blocked = MAD4B_SCP_Production_Certification::execute( array( 'stage_id' => 'provider_side_channel_inventory' ) );
check( empty( $blocked['ready'] ) && in_array( 'mcp_write_side_channel_detected', $blocked['blockers'], true ), 'side-channel blocker did not fail closed' );
$blocked_status = MAD4B_SCP_Production_Certification::status();
check( 1 === $blocked_status['local_blocked_count'] && count( $stages ) - 1 === $blocked_status['local_ready_count'], 'status matrix did not surface the blocked local canary' );
MAD4B_SCP_Servers::$raw_query_mounted = true;
$security_blocked = MAD4B_SCP_Production_Certification::execute( array( 'stage_id' => 'security_fault_canary' ) );
check( empty( $security_blocked['ready'] ) && in_array( 'raw_sql_on_write_surface', $security_blocked['blockers'], true ), 'raw SQL surface blocker did not fail closed' );
MAD4B_SCP_Servers::$raw_query_mounted = false;
echo "mad4b.production-certification-runtime.v1: PASS\n";

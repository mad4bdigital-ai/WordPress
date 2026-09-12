<?php

namespace WP\MCP\Domain\Utils {
	class McpNameSanitizer {
		public static function sanitize_name( $name ) { return str_replace( '/', '-', (string) $name ); }
	}
}

namespace {

define( 'ABSPATH', __DIR__ );
define( 'MAD4B_SCP_VERSION', '0.4.0-rc.29' );
$GLOBALS['mad4b_zero_touch_abilities'] = array();
$GLOBALS['mad4b_zero_touch_options'] = array();
$GLOBALS['mad4b_zero_touch_environment'] = 'staging';
$GLOBALS['mad4b_zero_touch_snapshot'] = 'sha256:' . str_repeat( 'a', 64 );
$GLOBALS['mad4b_zero_touch_source'] = str_repeat( '1', 40 );
$GLOBALS['mad4b_zero_touch_build'] = str_repeat( '2', 64 );
$GLOBALS['mad4b_zero_touch_inventory'] = str_repeat( '3', 64 );
$GLOBALS['mad4b_zero_touch_session'] = str_repeat( '4', 64 );
$GLOBALS['mad4b_zero_touch_observed'] = gmdate( 'Y-m-d H:i:s', time() - 5 );

function add_action() { return true; }
function add_filter() { return true; }
function wp_register_ability( $name, $args ) { $GLOBALS['mad4b_zero_touch_abilities'][ $name ] = $args; return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_get_environment_type() { return $GLOBALS['mad4b_zero_touch_environment']; }
function home_url( $path = '' ) { return ( 'staging' === $GLOBALS['mad4b_zero_touch_environment'] ? 'https://staging.egypttourgates.com' : 'https://egypttourgates.com' ) . $path; }
function site_url( $path = '' ) { return home_url( $path ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mad4b_zero_touch_options'] ) ? $GLOBALS['mad4b_zero_touch_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['mad4b_zero_touch_options'][ $name ] = $value; return true; }

class WP_Error {}
class MAD4B_SCP_Skill_Registry {
	public static function levels() { return array( 'site' ); }
	public static function status() { return array(); }
	public static function portable_snapshot() { return array(); }
}
class MAD4B_SCP_Skill_Snapshot_Identity {
	public static function build() { return array( 'identity_token' => $GLOBALS['mad4b_zero_touch_snapshot'] ); }
}
class MAD4B_SCP_Live_Acceptance_Observer {
	public static function build_provenance_status() {
		return array( 'runtime_manifest_match' => true, 'stale' => false, 'source_commit_sha' => $GLOBALS['mad4b_zero_touch_source'], 'build_fingerprint' => $GLOBALS['mad4b_zero_touch_build'] );
	}
	public static function external_handshake_attestation_status() {
		return array(
			'verified' => true,
			'real_external_session' => true,
			'finalizer_subject_binding_verified' => true,
			'client_id' => 'https://chatgpt.com/oauth/client.json',
			'server_id' => 'mad4b-chatgpt',
			'inventory_match' => true,
			'write_inventory_fingerprint_match' => true,
			'build_fingerprint_match' => true,
			'finalizer_context_digest' => str_repeat( '5', 64 ),
			'external_tool_inventory_fingerprint' => $GLOBALS['mad4b_zero_touch_inventory'],
			'observed_at' => $GLOBALS['mad4b_zero_touch_observed'],
		);
	}
}
class MAD4B_SCP_External_Handshake_Evidence {
	const OPTION = 'mad4b_scp_external_handshake_evidence';
	public static function status() {
		return array(
			'verified' => true,
			'tool_inventory_match' => true,
			'build_fingerprint_match' => true,
			'client_id' => 'https://chatgpt.com/oauth/client.json',
			'server_id' => 'mad4b-chatgpt',
			'tool_inventory_fingerprint' => $GLOBALS['mad4b_zero_touch_inventory'],
		);
	}
}

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-skill-abilities.php';

MAD4B_SCP_Skill_Abilities::register_abilities();
$description = isset( $GLOBALS['mad4b_zero_touch_abilities']['mad4b/skills-export-status']['description'] ) ? (string) $GLOBALS['mad4b_zero_touch_abilities']['mad4b/skills-export-status']['description'] : '';
if ( false === strpos( $description, MAD4B_SCP_Skill_Abilities::SNAPSHOT_ANCHOR_PREFIX . ' ' . $GLOBALS['mad4b_zero_touch_snapshot'] ) ) {
	fwrite( STDERR, "snapshot anchor missing from external tool metadata\n" ); exit( 1 );
}
$tool_name = \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( 'mad4b/skills-export-status' );
$observed = MAD4B_SCP_Skill_Abilities::snapshot_attestation_from_tools( array( array( 'name' => $tool_name, 'description' => $description ) ) );
if ( empty( $observed['anchor_present'] ) || empty( $observed['identity_match'] ) ) {
	fwrite( STDERR, "matching external snapshot anchor was rejected\n" ); exit( 1 );
}
$mismatch = MAD4B_SCP_Skill_Abilities::snapshot_attestation_from_tools( array( array( 'name' => $tool_name, 'description' => 'MAD4B-SNAPSHOT-ANCHOR sha256:' . str_repeat( 'b', 64 ) . '.' ) ) );
if ( empty( $mismatch['anchor_present'] ) || ! empty( $mismatch['identity_match'] ) ) {
	fwrite( STDERR, "mismatched external snapshot anchor was accepted\n" ); exit( 1 );
}

$GLOBALS['mad4b_zero_touch_options'][ MAD4B_SCP_External_Handshake_Evidence::OPTION ] = array(
	'mcp_session_fingerprint' => $GLOBALS['mad4b_zero_touch_session'],
);
$GLOBALS['mad4b_zero_touch_options'][ MAD4B_SCP_Skill_Abilities::DYNAMIC_SNAPSHOT_OPTION ] = array(
	'contract' => MAD4B_SCP_Skill_Abilities::DYNAMIC_SNAPSHOT_CONTRACT,
	'candidate_sha' => $GLOBALS['mad4b_zero_touch_source'],
	'build_fingerprint' => $GLOBALS['mad4b_zero_touch_build'],
	'snapshot_identity' => $GLOBALS['mad4b_zero_touch_snapshot'],
	'mcp_session_fingerprint' => $GLOBALS['mad4b_zero_touch_session'],
	'tool_inventory_fingerprint' => $GLOBALS['mad4b_zero_touch_inventory'],
	'tool_count' => 10,
	'observer_observed_at' => $GLOBALS['mad4b_zero_touch_observed'],
	'observed_at' => gmdate( 'c', time() - 5 ),
);

$ready = MAD4B_SCP_Skill_Abilities::dynamic_snapshot_status();
if ( empty( $ready['ready'] ) || 'dynamic_authenticated_mcp_tools_list' !== $ready['evidence_mode'] || ! empty( $ready['self_certified'] ) ) {
	fwrite( STDERR, "exact zero-touch snapshot evidence did not close\n" ); var_export( $ready ); exit( 1 );
}

$GLOBALS['mad4b_zero_touch_snapshot'] = 'sha256:' . str_repeat( 'b', 64 );
$blocked = MAD4B_SCP_Skill_Abilities::dynamic_snapshot_status();
if ( ! empty( $blocked['ready'] ) || ! in_array( 'snapshot_binding_changed', $blocked['blockers'], true ) ) {
	fwrite( STDERR, "snapshot drift did not fail closed\n" ); exit( 1 );
}
$GLOBALS['mad4b_zero_touch_snapshot'] = 'sha256:' . str_repeat( 'a', 64 );

$GLOBALS['mad4b_zero_touch_build'] = str_repeat( '7', 64 );
$blocked = MAD4B_SCP_Skill_Abilities::dynamic_snapshot_status();
if ( ! empty( $blocked['ready'] ) || ! in_array( 'build_fingerprint_mismatch', $blocked['blockers'], true ) ) {
	fwrite( STDERR, "build drift did not fail closed\n" ); exit( 1 );
}
$GLOBALS['mad4b_zero_touch_build'] = str_repeat( '2', 64 );

$GLOBALS['mad4b_zero_touch_options'][ MAD4B_SCP_Skill_Abilities::DYNAMIC_SNAPSHOT_OPTION ]['observed_at'] = gmdate( 'c', time() - 1000 );
$blocked = MAD4B_SCP_Skill_Abilities::dynamic_snapshot_status();
if ( ! empty( $blocked['ready'] ) || ! in_array( 'stale_external_snapshot_attestation', $blocked['blockers'], true ) ) {
	fwrite( STDERR, "stale snapshot evidence did not fail closed\n" ); exit( 1 );
}
$GLOBALS['mad4b_zero_touch_options'][ MAD4B_SCP_Skill_Abilities::DYNAMIC_SNAPSHOT_OPTION ]['observed_at'] = gmdate( 'c', time() - 5 );

$GLOBALS['mad4b_zero_touch_environment'] = 'production';
$blocked = MAD4B_SCP_Skill_Abilities::dynamic_snapshot_status();
if ( ! empty( $blocked['ready'] ) || ! in_array( 'wrong_target', $blocked['blockers'], true ) ) {
	fwrite( STDERR, "Production satisfied zero-touch external snapshot acceptance\n" ); exit( 1 );
}

echo "mad4b.zero-touch-external-snapshot-runtime.v1: PASS\n";
}

<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_VERSION', 'fixture' );

$GLOBALS['g2_abilities'] = array();
function add_action() { return true; }
function current_user_can() { return true; }
function wp_has_ability( $name ) { return isset( $GLOBALS['g2_abilities'][ $name ] ); }
function wp_register_ability( $name, $args ) { $GLOBALS['g2_abilities'][ $name ] = $args; return true; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function site_url() { return 'https://fixture.invalid'; }
function untrailingslashit( $value ) { return rtrim( (string) $value, "/\\" ); }
function get_current_user_id() { return 7; }
function get_post( $id ) {
	if ( 42 !== (int) $id ) return null;
	return (object) array(
		'post_title' => 'After title',
		'post_content' => 'After content',
		'post_excerpt' => 'After excerpt',
		'post_status' => 'draft',
	);
}
function wp_update_post() { throw new RuntimeException( 'G2 read-only runtime attempted post mutation.' ); }
function update_option() { throw new RuntimeException( 'G2 read-only runtime attempted option mutation.' ); }
function delete_option() { throw new RuntimeException( 'G2 read-only runtime attempted option deletion.' ); }

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

final class MAD4B_SCP_Mutation_Manager {
	public static function get( $id ) {
		$state = array(
			'post_title' => 'After title',
			'post_content' => 'After content',
			'post_excerpt' => 'After excerpt',
			'post_status' => 'draft',
		);
		return array(
			'mutation_id' => $id,
			'parent_mutation_id' => '',
			'agent_id' => 9,
			'ability_name' => 'mad4b/content-update-post',
			'provider' => 'core',
			'target_type' => 'post',
			'target_id' => '42',
			'status' => 'verified',
			'reversible' => 1,
			'before_sha256' => hash( 'sha256', 'before' ),
			'after_sha256' => hash( 'sha256', wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'undo_expires_at' => '2099-01-01 00:00:00',
			'verification_code' => 'readback_match',
			'error_code' => '',
			'approval_ticket_id' => 'ticket-1',
		);
	}
	public static function undo_post_mutation() { throw new RuntimeException( 'Recovery preview invoked undo.' ); }
}

final class MAD4B_SCP_Identity_Context {
	public static function current() { return array( 'subject_type' => 'oauth', 'subject_fingerprint' => str_repeat( 'a', 64 ) ); }
}
final class MAD4B_SCP_Agent_Registry {
	public static function resolve_agent() { return array( 'id' => 9, 'public_id' => '11111111-1111-4111-8111-111111111111' ); }
	public static function get_agent_by_public_id( $public_id ) {
		return '11111111-1111-4111-8111-111111111111' === $public_id
			? array( 'id' => 9, 'public_id' => $public_id, 'slug' => 'fixture-agent', 'label' => 'Fixture Agent', 'status' => 'enabled' )
			: null;
	}
	public static function subjects_for_agent() {
		return array(
			array(
				'subject_type' => 'oauth',
				'subject_fingerprint' => str_repeat( 'b', 64 ),
				'label' => 'Fixture OAuth',
				'status' => 'enabled',
			),
		);
	}
	public static function grant_ability() { throw new RuntimeException( 'G2 read-only runtime attempted to grant authority.' ); }
	public static function set_subject_status() { throw new RuntimeException( 'G2 read-only runtime attempted to mutate subject state.' ); }
}
final class MAD4B_SCP_Governance_Abilities {
	public static function agent_effective_access() {
		return array(
			'agent' => array( 'public_id' => '11111111-1111-4111-8111-111111111111', 'label' => 'Fixture Agent' ),
			'effective' => array(
				array(
					'grant_ids' => array( 77 ),
					'server_id' => 'mad4b-admin',
					'ability' => 'mad4b/audit-storage-status',
					'provider' => 'core',
					'grant' => 'allow',
					'scope' => 'not_simulated',
					'mounted' => true,
					'provider_matches_mount' => true,
					'provider_runtime' => array( 'state' => 'n/a' ),
					'impact' => 'low',
					'approval_required' => false,
					'resource_schema_version' => '1',
					'resource_constraints' => array( 'post_id' => 42 ),
					'constraint_state' => 'unresolved_without_target',
					'decision' => 'allowed',
					'effective' => true,
				),
			),
			'allowed_count' => 1,
			'conditional_count' => 0,
			'denied_count' => 0,
		);
	}
}
final class MAD4B_SCP_Local_OAuth_Server {
	public static function metadata() {
		return array(
			'scopes_supported' => array( 'mad4b:read', 'mad4b:authority:step-up', 'server:mad4b-breakglass', 'offline_access' ),
			'code_challenge_methods_supported' => array( 'S256' ),
			'grant_types_supported' => array( 'authorization_code', 'refresh_token' ),
			'revocation_endpoint' => 'https://fixture.invalid/revoke',
			'client_id_metadata_document_supported' => true,
		);
	}
}
final class MAD4B_SCP_MCP_Client_Profile_Registry {
	public static function profiles() {
		return array(
			array(
				'id' => 'generic-mcp',
				'display_name' => 'Generic MCP',
				'match_any' => array(),
				'transports' => array( 'streamable_http' ),
				'authentication' => array( 'oauth_discovery', 'bearer_header' ),
				'authority_effect' => 'none',
			),
		);
	}
	public static function detect_request_profile() { return array( 'profile' => self::profiles()[0], 'matched' => false, 'authoritative' => false ); }
}
final class MAD4B_SCP_Schema {
	public static function tables() { return array( 'mutations' => 'wp_mad4b_mutations', 'agents' => 'wp_mad4b_agents' ); }
}
final class MAD4B_SCP_Audit {
	public static function verify_chain() { return true; }
	public static function storage_status() { return array( 'ready' => true, 'transactional' => true, 'event_count' => 1 ); }
}

final class G2_Fake_Wpdb {
	public function prepare( $sql, $args = null ) { return $sql; }
	public function get_row( $sql, $format = null ) {
		if ( false !== strpos( $sql, 'COUNT(*) AS mutation_count' ) ) return array( 'mutation_count' => 1, 'last_mutation_at' => '2026-10-07 12:00:00' );
		return null;
	}
	public function get_results( $sql, $format = null ) {
		if ( false === strpos( $sql, 'FROM wp_mad4b_mutations m' ) ) return array();
		return array(
			array(
				'id' => 100,
				'mutation_id' => '22222222-2222-4222-8222-222222222222',
				'parent_mutation_id' => '',
				'request_id' => 'req-1',
				'agent_id' => 9,
				'subject_type' => 'oauth',
				'subject_fingerprint' => str_repeat( 'c', 64 ),
				'server_id' => 'mad4b-admin',
				'ability_name' => 'mad4b/content-update-post',
				'provider' => 'core',
				'target_type' => 'post',
				'target_id' => '42',
				'approval_ticket_id' => 'ticket-1',
				'impact' => 'high',
				'status' => 'verified',
				'reversible' => 1,
				'before_sha256' => hash( 'sha256', 'before' ),
				'after_sha256' => hash( 'sha256', 'after' ),
				'undo_expires_at' => '2099-01-01 00:00:00',
				'verification_code' => 'readback_match',
				'error_code' => '',
				'created_at' => '2026-10-07 12:00:00',
				'updated_at' => '2026-10-07 12:00:01',
				'agent_public_id' => '11111111-1111-4111-8111-111111111111',
				'agent_slug' => 'fixture-agent',
				'agent_label' => 'Fixture Agent',
			),
		);
	}
}
$GLOBALS['wpdb'] = new G2_Fake_Wpdb();

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-g2-governance-experience.php';

function g2_check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

MAD4B_SCP_G2_Governance_Experience::register_abilities();
$expected = array(
	'mad4b/recovery-preview',
	'mad4b/agent-access-workspace',
	'mad4b/consent-profile-status',
	'mad4b/change-history-search',
);
g2_check( $expected === array_keys( $GLOBALS['g2_abilities'] ), 'G2 ability registration drifted.' );
foreach ( $GLOBALS['g2_abilities'] as $ability ) {
	g2_check( true === $ability['meta']['annotations']['readonly'], 'G2 ability became writable.' );
	g2_check( false === $ability['meta']['annotations']['destructive'], 'G2 ability became destructive.' );
	g2_check( false === $ability['meta']['public'] && false === $ability['meta']['show_in_rest'], 'G2 ability leaked to public/default surface.' );
}

$recovery = MAD4B_SCP_G2_Governance_Experience::recovery_preview(
	array( 'mutation_id' => '22222222-2222-4222-8222-222222222222', 'reason' => 'fixture' )
);
g2_check( is_array( $recovery ) && true === $recovery['eligible_by_repository_evidence'], 'Recovery fixture should be preview-eligible.' );
g2_check( 'after_state_match' === $recovery['readback_state'], 'Recovery preview did not bind current readback.' );
g2_check( false === $recovery['execution_available_here'] && false === $recovery['rollback_payload_exposed'], 'Recovery preview widened execution or payload exposure.' );
g2_check( ! isset( $recovery['approval_ticket_id'] ) && 64 === strlen( $recovery['approval_ticket_sha256'] ), 'Recovery preview exposed raw approval ticket identifier.' );
g2_check( 64 === strlen( $recovery['plan_sha256'] ), 'Recovery plan digest missing.' );

$access = MAD4B_SCP_G2_Governance_Experience::agent_access_workspace(
	array( 'agent_public_id' => '11111111-1111-4111-8111-111111111111', 'server_id' => 'mad4b-admin' )
);
g2_check( 1 === $access['allowed_count'] && false === $access['permission_apply_available_here'], 'Access workspace authority boundary drifted.' );
g2_check( 'bbbbbbbbbbbb…' === $access['subjects'][0]['fingerprint_hint'], 'Subject fingerprint was not redacted.' );
g2_check( false === $access['subjects'][0]['raw_identifier_exposed'], 'Raw subject identifier exposure detected.' );
g2_check( ! isset( $access['effective_access'][0]['grant_ids'] ) && ! isset( $access['effective_access'][0]['resource_constraints'] ), 'Access workspace exposed raw grant internals.' );
g2_check( false === $access['effective_access'][0]['raw_grant_ids_exposed'] && false === $access['effective_access'][0]['raw_resource_constraints_exposed'], 'Access workspace redaction flags drifted.' );
g2_check( 64 === strlen( $access['effective_access'][0]['resource_constraints_sha256'] ), 'Access workspace resource constraint digest missing.' );

$consent = MAD4B_SCP_G2_Governance_Experience::consent_profile_status( array( 'client_hint' => 'fixture' ) );
g2_check( false === $consent['generic_full_access_supported'] && true === $consent['new_scopes_require_external_consent'], 'Consent boundary drifted.' );
foreach ( $consent['presets'] as $preset ) {
	g2_check( ! in_array( 'server:mad4b-breakglass', $preset['scopes'], true ), 'Exceptional scope leaked into preset.' );
	g2_check( false === $preset['mutation_authority'], 'Consent preset created mutation authority.' );
}

$history = MAD4B_SCP_G2_Governance_Experience::change_history_search( array( 'limit' => 25 ) );
g2_check( 1 === $history['count'] && false === $history['history_is_rollback_authority'], 'History rollback boundary drifted.' );
g2_check( false === $history['rollback_payload_exposed'] && false === $history['secret_or_token_values_exposed'], 'History exposed protected values.' );
g2_check( ! isset( $history['items'][0]['evidence']['approval_ticket_id'] ) && 64 === strlen( $history['items'][0]['evidence']['approval_ticket_sha256'] ), 'History exposed raw approval ticket identifier.' );
g2_check( 'cccccccccccc…' === $history['items'][0]['subject']['fingerprint_hint'], 'History subject fingerprint was not redacted.' );
g2_check( 64 === strlen( $history['export_sha256'] ) && true === $history['audit_chain_ready'], 'History evidence integrity missing.' );

echo json_encode(
	array(
		'contract' => 'mad4b.feature007-g2-governance-experience-runtime.v1',
		'status' => 'PASS',
		'abilities' => count( $GLOBALS['g2_abilities'] ),
		'authorizing' => false,
		'mutation_performed' => false,
		'live_acceptance' => false,
	)
) . "\n";

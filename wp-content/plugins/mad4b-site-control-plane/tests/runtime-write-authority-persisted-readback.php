<?php
/** Prove persisted governed-write authority survives a completely new WP request. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fail = static function ( $message ) {
	fwrite( STDERR, '[MAD4B persisted write authority] ' . $message . PHP_EOL );
	exit( 1 );
};

if ( 'staging' !== wp_get_environment_type() ) $fail( 'WordPress environment is not staging.' );
if ( ! current_user_can( 'manage_options' ) ) $fail( 'Administrator context is required.' );

$status = MAD4B_SCP_Staging_Write_Authority::status();
if ( empty( $status['ready'] ) || 'ready' !== ( isset( $status['state'] ) ? (string) $status['state'] : '' ) ) $fail( 'Persisted authority was not restored: ' . wp_json_encode( $status ) );
if ( empty( $status['restored_from_persisted_authority'] ) ) $fail( 'Independent request did not report persisted authority restoration.' );
if ( ! MAD4B_SCP_Staging_Write_Authority::effective() ) $fail( 'Persisted authority is not effective on the independent request.' );
if ( ! empty( $status['stale_allow_grants_revoked'] ) ) $fail( 'Restored authority reports unexpected stale grant revocation.' );

$agent = isset( $status['agent_public_id'] ) ? MAD4B_SCP_Agent_Registry::get_agent_by_public_id( $status['agent_public_id'] ) : null;
if ( ! is_array( $agent ) || empty( $agent['id'] ) ) $fail( 'Persisted governed-write agent is unavailable.' );
$grant_snapshot = static function () use ( $agent ) {
	$rows = MAD4B_SCP_Agent_Registry::grants_for_agent( $agent['id'], 'mad4b-write' );
	$canonical = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) continue;
		$canonical[] = array(
			'id' => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'effect' => isset( $row['effect'] ) ? (string) $row['effect'] : '',
			'ability_name' => isset( $row['ability_name'] ) ? (string) $row['ability_name'] : '',
			'provider' => isset( $row['provider'] ) ? (string) $row['provider'] : '',
			'environment' => isset( $row['environment'] ) ? (string) $row['environment'] : '',
		);
	}
	usort( $canonical, static function ( $a, $b ) { return strcmp( $a['ability_name'] . "\0" . $a['provider'] . "\0" . $a['id'], $b['ability_name'] . "\0" . $b['provider'] . "\0" . $b['id'] ); } );
	return array( 'rows' => $canonical, 'hash' => hash( 'sha256', wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
};

$before = $grant_snapshot();
$live = MAD4B_SCP_Live_Truth::current_authority_status();
if ( empty( $live['ready'] ) || empty( $live['runtime_reconciled'] ) ) $fail( 'Live Truth did not accept persisted authority: ' . wp_json_encode( $live['blockers'] ?? array() ) );
if ( (int) ( $live['exact_grants_existing'] ?? 0 ) !== (int) ( $live['write_tool_count'] ?? -1 ) ) $fail( 'Exact grant count does not match current write-tool count.' );
if ( ! empty( $live['wildcard_grants'] ) || ! empty( $live['grant_blockers'] ) ) $fail( 'Grant blockers appeared on the independent request.' );
$cert = MAD4B_SCP_Live_Truth::current_write_certification();
if ( empty( $cert['ready'] ) ) $fail( 'Fresh write certification is not ready: ' . wp_json_encode( $cert['blockers'] ?? array() ) );
MAD4B_SCP_Connection_Status::status();
MAD4B_SCP_Skill_Abilities::skills_export_status();
$after = $grant_snapshot();
if ( ! hash_equals( $before['hash'], $after['hash'] ) || $before['rows'] !== $after['rows'] ) $fail( 'Independent read/status request mutated exact mad4b-write grants.' );

echo "mad4b.site-control-plane.persisted-write-authority-independent-request.v1: PASS\n";

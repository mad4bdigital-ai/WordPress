<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fail = static function ( $message ) {
	fwrite( STDERR, '[MAD4B Dynamic Skills smoke] ' . $message . PHP_EOL );
	exit( 1 );
};

if ( 'staging' !== wp_get_environment_type() ) $fail( 'WordPress environment is not staging.' );

$autoconfig = MAD4B_SCP_Skill_Autoconfig::status();
if ( empty( $autoconfig['configured'] ) ) $fail( 'Skill editor was not auto-configured.' );
if ( empty( $autoconfig['app_mapping_configured'] ) ) $fail( 'Staging OpenAI App mapping was not auto-configured.' );
if ( empty( $autoconfig['app_mapping_matches_staging'] ) ) $fail( 'Staging OpenAI App mapping does not match the governed Staging App.' );
if ( 'staging_auto' !== $autoconfig['app_mapping_source'] ) $fail( 'Expected automatic Staging App mapping source.' );

$registry = MAD4B_SCP_Skill_Registry::status();
if ( empty( $registry['editor_enabled'] ) ) $fail( 'Skill editor is not enabled on Staging.' );
if ( ! empty( $registry['scripts_editor_enabled'] ) ) $fail( 'Scripts editor must remain disabled.' );
if ( empty( $registry['storage_initialized'] ) || empty( $registry['storage_writable'] ) ) $fail( 'Skill storage is not initialized and writable.' );
if ( empty( $registry['portable_app_id_configured'] ) ) $fail( 'Portable App mapping is unavailable through the registry.' );

$seed = MAD4B_SCP_Skill_Seeder::status();
if ( ! isset( $seed['state'] ) || 'ready' !== $seed['state'] ) $fail( 'Seed pack is not ready.' );

$provider = MAD4B_SCP_Skill_Provider_Discovery::status();
if ( ! isset( $provider['state'] ) || 'ready' !== $provider['state'] ) $fail( 'Provider Skill reconciliation is not ready.' );
if ( ! empty( $provider['provider_plugin_mutation'] ) ) $fail( 'Provider discovery reported plugin mutation.' );
if ( ! empty( $provider['deletes_skills'] ) ) $fail( 'Provider discovery reported Skill deletion.' );

$base = array(
	array( 'site', '_site', 'wordpress-site-diagnostics' ),
	array( 'connection', 'mad4b-chatgpt', 'wordpress-connection-diagnostics' ),
	array( 'workflow', 'archive-audit', 'wordpress-archive-audit' ),
	array( 'workflow', 'change-safety', 'wordpress-change-safety' ),
);
foreach ( $base as $identity ) {
	$skill = MAD4B_SCP_Skill_Registry::get_skill( $identity[0], $identity[1], $identity[2] );
	if ( is_wp_error( $skill ) || empty( $skill['enabled'] ) ) $fail( 'Missing or disabled base Skill: ' . implode( ':', $identity ) );
}

foreach ( array(
	array( 'provider', 'elementor', 'elementor-dynamic-content' ),
	array( 'provider', 'jet-engine', 'jetengine-content-modeling' ),
) as $identity ) {
	$skill = MAD4B_SCP_Skill_Registry::get_skill( $identity[0], $identity[1], $identity[2] );
	if ( is_wp_error( $skill ) ) $fail( 'Expected provider handoff seed is missing: ' . implode( ':', $identity ) );
	if ( ! empty( $skill['enabled'] ) ) $fail( 'Provider handoff seed must remain disabled when provider is absent: ' . implode( ':', $identity ) );
}

$required_read = array(
	'mad4b/skills-list',
	'mad4b/skill-get',
	'mad4b/skills-export-status',
	'mad4b/skills-runtime-certification',
);
foreach ( $required_read as $ability ) if ( ! wp_has_ability( $ability ) ) $fail( 'Missing read ability: ' . $ability );

foreach ( array( 'mad4b/skill-create', 'mad4b/skill-update', 'mad4b/skill-delete', 'mad4b/skill-write' ) as $ability ) {
	if ( wp_has_ability( $ability ) ) $fail( 'Forbidden Skill write ability is registered: ' . $ability );
}

$cert = MAD4B_SCP_Skill_Runtime_Certification::observe();
if ( empty( $cert['ready'] ) || ! isset( $cert['state'] ) || 'ready' !== $cert['state'] ) {
	$fail( 'Automatic runtime certification is blocked: ' . wp_json_encode( isset( $cert['blockers'] ) ? $cert['blockers'] : array() ) );
}
if ( empty( $cert['local_runtime_only'] ) ) $fail( 'Certification trust boundary is not declared local-runtime-only.' );
if ( ! empty( $cert['external_client_snapshot_verified'] ) ) $fail( 'WordPress must not claim remote ChatGPT snapshot verification.' );
if ( empty( $cert['evidence_digest'] ) ) $fail( 'Runtime certification evidence digest is missing.' );

$readback = MAD4B_SCP_Skill_Runtime_Certification::status();
if ( empty( $readback['ready'] ) || ! hash_equals( (string) $cert['evidence_digest'], (string) $readback['evidence_digest'] ) ) $fail( 'Persisted runtime certification readback mismatch.' );

echo 'mad4b.runtime-dynamic-skills.v1: PASS' . PHP_EOL;

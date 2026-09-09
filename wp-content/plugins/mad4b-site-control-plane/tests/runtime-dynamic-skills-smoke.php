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
if ( ! isset( $seed['seed_version'] ) || 2 !== (int) $seed['seed_version'] ) $fail( 'Canonical seed version is not v2.' );
if ( ! empty( $seed['overwrites_user_owned'] ) ) $fail( 'Seeder must never overwrite user-owned Skills.' );
if ( empty( $seed['refreshes_only_digest_clean_managed'] ) ) $fail( 'Managed seed refresh must remain digest-clean only.' );

$provider = MAD4B_SCP_Skill_Provider_Discovery::status();
if ( ! isset( $provider['state'] ) || 'ready' !== $provider['state'] ) $fail( 'Provider Skill reconciliation is not ready.' );
if ( ! empty( $provider['provider_plugin_mutation'] ) ) $fail( 'Provider discovery reported plugin mutation.' );
if ( ! empty( $provider['deletes_skills'] ) ) $fail( 'Provider discovery reported Skill deletion.' );

$seed_identities = array(
	array( 'site', '_site', 'wordpress-site-diagnostics', true ),
	array( 'connection', 'mad4b-chatgpt', 'wordpress-connection-diagnostics', true ),
	array( 'provider', 'elementor', 'elementor-dynamic-content', false ),
	array( 'provider', 'jet-engine', 'jetengine-content-modeling', false ),
	array( 'workflow', 'archive-audit', 'wordpress-archive-audit', true ),
	array( 'workflow', 'change-safety', 'wordpress-change-safety', true ),
);
$workspace = getenv( 'GITHUB_WORKSPACE' );
if ( ! is_string( $workspace ) || '' === $workspace ) $fail( 'GITHUB_WORKSPACE is unavailable for canonical seed parity proof.' );

foreach ( $seed_identities as $identity ) {
	$skill = MAD4B_SCP_Skill_Registry::get_skill( $identity[0], $identity[1], $identity[2] );
	if ( is_wp_error( $skill ) ) $fail( 'Missing canonical runtime Skill: ' . implode( ':', array_slice( $identity, 0, 3 ) ) );
	if ( (bool) $identity[3] !== ! empty( $skill['enabled'] ) ) $fail( 'Unexpected canonical Skill enabled state: ' . $identity[2] );

	$portable_path = wp_normalize_path( $workspace . '/plugins/mad4b-wordpress/skills/' . $identity[2] . '/SKILL.md' );
	if ( ! is_file( $portable_path ) ) $fail( 'Portable canonical Skill is missing: ' . $identity[2] );
	$portable_content = file_get_contents( $portable_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! is_string( $portable_content ) || ! hash_equals( hash( 'sha256', $portable_content ), hash( 'sha256', (string) $skill['content'] ) ) || $portable_content !== (string) $skill['content'] ) {
		$fail( 'Runtime/portable canonical Skill byte drift: ' . $identity[2] );
	}
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

$snapshot_identity = MAD4B_SCP_Skill_Snapshot_Identity::build();
if ( empty( $snapshot_identity['ready'] ) ) $fail( 'Deterministic snapshot identity is not ready.' );
if ( empty( $snapshot_identity['snapshot_digest'] ) || empty( $snapshot_identity['identity_token'] ) ) $fail( 'Deterministic snapshot digest/token is missing.' );
if ( 0 !== strpos( (string) $snapshot_identity['identity_token'], 'sha256:' ) ) $fail( 'Snapshot identity token is not SHA-256 qualified.' );

$cert = MAD4B_SCP_Skill_Runtime_Certification::observe();
if ( empty( $cert['ready'] ) || ! isset( $cert['state'] ) || 'ready' !== $cert['state'] ) {
	$fail( 'Automatic runtime certification is blocked: ' . wp_json_encode( isset( $cert['blockers'] ) ? $cert['blockers'] : array() ) );
}
if ( empty( $cert['local_runtime_only'] ) ) $fail( 'Certification trust boundary is not declared local-runtime-only.' );
if ( ! empty( $cert['external_client_snapshot_verified'] ) ) $fail( 'WordPress must not claim remote ChatGPT snapshot verification.' );
if ( empty( $cert['evidence_digest'] ) ) $fail( 'Runtime certification evidence digest is missing.' );
if ( empty( $cert['snapshot_identity_token'] ) || ! hash_equals( (string) $snapshot_identity['identity_token'], (string) $cert['snapshot_identity_token'] ) ) $fail( 'Certification snapshot identity token mismatch.' );

$readback = MAD4B_SCP_Skill_Runtime_Certification::status();
if ( empty( $readback['ready'] ) || ! hash_equals( (string) $cert['evidence_digest'], (string) $readback['evidence_digest'] ) ) $fail( 'Persisted runtime certification readback mismatch.' );

if ( ! class_exists( 'ZipArchive' ) ) $fail( 'ZipArchive is unavailable for portable export proof.' );
$export = MAD4B_SCP_Skill_Exporter::build_temp_zip();
if ( is_wp_error( $export ) ) $fail( 'Portable export failed: ' . $export->get_error_code() );
if ( empty( $export['path'] ) || ! is_file( $export['path'] ) ) $fail( 'Portable export file is missing.' );
if ( empty( $export['identity_token'] ) || ! hash_equals( (string) $snapshot_identity['identity_token'], (string) $export['identity_token'] ) ) $fail( 'Exporter result identity token mismatch.' );
if ( ! isset( $export['skill_count'] ) || (int) $export['skill_count'] > MAD4B_SCP_Skill_Exporter::MAX_EXPORT_SKILLS ) $fail( 'Exporter Skill count is outside the bounded limit.' );
if ( ! isset( $export['resource_count'] ) || (int) $export['resource_count'] > MAD4B_SCP_Skill_Exporter::MAX_EXPORT_RESOURCES ) $fail( 'Exporter resource count is outside the bounded limit.' );
if ( ! isset( $export['uncompressed_payload_bytes'] ) || (int) $export['uncompressed_payload_bytes'] > MAD4B_SCP_Skill_Exporter::MAX_EXPORT_UNCOMPRESSED_BYTES ) $fail( 'Exporter payload bytes exceed the bounded limit.' );

$zip = new ZipArchive();
if ( true !== $zip->open( $export['path'] ) ) { @unlink( $export['path'] ); $fail( 'Portable export ZIP could not be reopened.' ); }
$token_file = trim( (string) $zip->getFromName( 'MAD4B-SNAPSHOT-ID.txt' ) );
$meta_json = $zip->getFromName( 'MAD4B-SNAPSHOT.json' );
$app_json = $zip->getFromName( '.app.json' );
$zip->close();
@unlink( $export['path'] );

if ( '' === $token_file || ! hash_equals( (string) $snapshot_identity['identity_token'], $token_file ) ) $fail( 'MAD4B-SNAPSHOT-ID.txt does not match runtime identity.' );
$meta = json_decode( (string) $meta_json, true );
if ( ! is_array( $meta ) || empty( $meta['identity_token'] ) || ! hash_equals( $token_file, (string) $meta['identity_token'] ) ) $fail( 'MAD4B-SNAPSHOT.json identity token mismatch.' );
if ( ! isset( $meta['resource_count'], $meta['uncompressed_payload_bytes'], $meta['export_limits'] ) ) $fail( 'MAD4B-SNAPSHOT.json is missing bounded export evidence.' );
if ( (int) $meta['resource_count'] !== (int) $export['resource_count'] || (int) $meta['uncompressed_payload_bytes'] !== (int) $export['uncompressed_payload_bytes'] ) $fail( 'Export result and embedded payload counters disagree.' );
$app = json_decode( (string) $app_json, true );
$zip_app_id = is_array( $app ) && isset( $app['apps']['mad4b-wordpress']['id'] ) ? (string) $app['apps']['mad4b-wordpress']['id'] : '';
if ( '' === $zip_app_id || ! hash_equals( MAD4B_SCP_Skill_Autoconfig::staging_app_id(), $zip_app_id ) ) $fail( 'Portable .app.json does not bind the governed Staging App.' );

echo 'mad4b.runtime-dynamic-skills.v2: PASS' . PHP_EOL;

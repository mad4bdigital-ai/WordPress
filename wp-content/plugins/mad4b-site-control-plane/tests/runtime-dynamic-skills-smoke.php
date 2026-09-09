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
if ( empty( $autoconfig['app_mapping_origin_bound'] ) ) $fail( 'Staging OpenAI App mapping is not bound to the governed ETG origin.' );
if ( ! isset( $autoconfig['expected_staging_host'], $autoconfig['observed_host'] ) || ! hash_equals( (string) $autoconfig['expected_staging_host'], (string) $autoconfig['observed_host'] ) ) $fail( 'Observed Staging host does not match the governed ETG Staging host.' );
if ( ! hash_equals( MAD4B_SCP_Skill_Autoconfig::staging_app_host(), (string) $autoconfig['observed_host'] ) ) $fail( 'Autoconfig Staging host contract mismatch.' );
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
	'mad4b/write-authority-status',
	'mad4b/write-runtime-certification',
	'mad4b/rest-compatibility-status',
);
foreach ( $required_read as $ability ) if ( ! wp_has_ability( $ability ) ) $fail( 'Missing read ability: ' . $ability );

foreach ( array( 'mad4b/skill-create', 'mad4b/skill-update', 'mad4b/skill-delete', 'mad4b/skill-write' ) as $ability ) {
	if ( wp_has_ability( $ability ) ) $fail( 'Forbidden Skill write ability is registered: ' . $ability );
}

// Register a disposable WPML-compatible route directly on the REST server. The
// probe traverses rest_do_request + every rest_pre_dispatch guard and must
// preserve both query parameters exactly.
$rest_server = rest_get_server();
$rest_server->register_route( 'wpml/v1', '/rest/status', array(
	array(
		'methods' => 'GET',
		'callback' => static function ( $request ) {
			return new WP_REST_Response( array(
				'status' => '1' === (string) $request->get_param( 'test_get_parameter' ) ? 'valid' : 'invalid',
				'get_parameters' => null !== $request->get_param( 'cachebuster' ) ? 'valid' : 'invalid',
			), 200 );
		},
		'permission_callback' => '__return_true',
	),
), true );
$rest_compat = MAD4B_SCP_REST_Compatibility::status();
if ( empty( $rest_compat['rest_enabled'] ) ) $fail( 'REST API is disabled in the disposable Staging runtime.' );
if ( ! empty( $rest_compat['control_plane_disables_rest'] ) ) $fail( 'Control Plane reports REST disablement.' );
if ( ! empty( $rest_compat['control_plane_filters_rest_enabled'] ) ) $fail( 'Control Plane unexpectedly owns a rest_enabled callback.' );
if ( ! empty( $rest_compat['control_plane_filters_rest_authentication_errors'] ) ) $fail( 'Control Plane unexpectedly owns a global rest_authentication_errors callback.' );
if ( ! empty( $rest_compat['rest_enabled_hook']['truncated'] ) || ! empty( $rest_compat['rest_authentication_errors_hook']['truncated'] ) ) $fail( 'REST hook evidence was truncated.' );
if ( empty( $rest_compat['wpml']['route_registered'] ) || empty( $rest_compat['wpml']['ready'] ) || empty( $rest_compat['wpml']['query_parameters_preserved'] ) ) $fail( 'WPML-compatible REST probe did not preserve query parameters.' );
if ( ! empty( $rest_compat['wpml']['control_plane_block_detected'] ) ) $fail( 'Control Plane blocked the WPML-compatible REST probe.' );

$write_authority = MAD4B_SCP_Staging_Write_Authority::reconcile();
if ( empty( $write_authority['ready'] ) || 'ready' !== $write_authority['state'] ) $fail( 'Governed Staging write authority is not ready: ' . wp_json_encode( $write_authority ) );
if ( empty( $write_authority['mutation_gate_configured'] ) ) $fail( 'Staging mutation gate was not configured.' );
if ( empty( $write_authority['all_remote_writes_require_exact_approval'] ) ) $fail( 'Remote Staging writes are not forced through exact approvals.' );
if ( ! empty( $write_authority['breakglass_included'] ) || ! empty( $write_authority['breakglass_auto_enable'] ) ) $fail( 'Breakglass leaked into Staging write authority.' );
if ( empty( $write_authority['write_tool_count'] ) ) $fail( 'Write authority inventory is empty.' );

$expected_core_writes = array(
	'mad4b/content-update-post',
	'mad4b/plugin-activate',
	'mad4b/plugin-deactivate',
	'mad4b/filesystem-write',
	'mad4b/filesystem-patch',
	'mad4b/database-update',
	'mad4b/mutation-undo',
	'mad4b/approval-plan',
);
$write_tools = MAD4B_SCP_Staging_Write_Authority::write_tools();
foreach ( $expected_core_writes as $ability ) if ( ! in_array( $ability, $write_tools, true ) ) $fail( 'Expected core write action is missing from mad4b-write: ' . $ability );
if ( in_array( 'mad4b/database-raw-query', $write_tools, true ) ) $fail( 'Breakglass raw query leaked into mad4b-write.' );
foreach ( $write_tools as $ability ) {
	if ( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability ) ) $fail( 'Write action is not mounted on mad4b-write: ' . $ability );
	if ( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $ability ) ) $fail( 'Write action is not exposed through the same ChatGPT Plugin transport: ' . $ability );
	$write_ability = wp_get_ability( $ability );
	$write_meta = is_object( $write_ability ) && method_exists( $write_ability, 'get_meta' ) ? $write_ability->get_meta() : array();
	if ( ! isset( $write_meta['annotations']['readonly'] ) || false !== $write_meta['annotations']['readonly'] ) $fail( 'Write inventory contains an ability without readonly=false: ' . $ability );
}

// The approval planner is itself a governed mutation, but cannot require a
// ticket to create the first pending ticket. Prove its bootstrap is narrowly
// constrained to this agent + mad4b-write + mutation class and denies breakglass.
$planner = MAD4B_SCP_Staging_Write_Planning_Guard::status();
if ( empty( $planner['remote_planner_requires_nhi'] ) || empty( $planner['remote_planner_requires_exact_mad4b_write_grant'] ) || empty( $planner['remote_planner_budgeted'] ) ) $fail( 'Approval planner is missing NHI/grant/budget governance.' );
if ( ! isset( $planner['remote_planner_requires_prior_ticket'] ) || false !== $planner['remote_planner_requires_prior_ticket'] ) $fail( 'Approval planner bootstrap incorrectly requires a prior ticket.' );
if ( 'mad4b-write' !== $planner['target_server'] || 'mutation' !== $planner['target_ticket_class'] || ! empty( $planner['breakglass_target_allowed'] ) || empty( $planner['creates_pending_ticket_only'] ) || ! empty( $planner['auto_approves'] ) ) $fail( 'Approval planner target boundary is unsafe.' );
$planner_ability = wp_get_ability( 'mad4b/approval-plan' );
$planner_meta = is_object( $planner_ability ) && method_exists( $planner_ability, 'get_meta' ) ? $planner_ability->get_meta() : array();
if ( empty( $planner_meta['mcp']['mad4b_approval_bootstrap_operation'] ) || empty( $planner_meta['mcp']['mad4b_creates_pending_ticket_only'] ) ) $fail( 'Approval planner registration was not wrapped by the governed bootstrap guard.' );
$valid_plan = array(
	'agent_public_id' => $write_authority['agent_public_id'],
	'server_id' => 'mad4b-write',
	'ability' => 'mad4b/content-update-post',
	'provider' => 'core',
	'input' => array( 'post_id' => 1, 'expected_modified_gmt' => '2000-01-01 00:00:00' ),
	'ticket_class' => 'mutation',
	'reason' => 'CI bootstrap contract proof',
);
if ( true !== MAD4B_SCP_Staging_Write_Planning_Guard::validate_remote_plan_input( $valid_plan ) ) $fail( 'Valid self-agent mad4b-write approval plan was denied by bootstrap guard.' );
$cross_agent = $valid_plan;
$cross_agent['agent_public_id'] = '00000000-0000-0000-0000-000000000000';
if ( ! is_wp_error( MAD4B_SCP_Staging_Write_Planning_Guard::validate_remote_plan_input( $cross_agent ) ) ) $fail( 'Approval planner accepted another agent.' );
$breakglass_plan = $valid_plan;
$breakglass_plan['ability'] = 'mad4b/database-raw-query';
if ( ! is_wp_error( MAD4B_SCP_Staging_Write_Planning_Guard::validate_remote_plan_input( $breakglass_plan ) ) ) $fail( 'Approval planner accepted breakglass.' );

$fake_identity = array(
	'authenticated' => true,
	'auth_method' => 'oauth2_bearer',
	'token_scopes' => array( 'mad4b:read' ),
);
if ( MAD4B_SCP_Staging_Write_Authority::remote_scope_delegation_allowed( $fake_identity, 'mad4b-write', 'mad4b/content-update-post', array() ) ) $fail( 'Read OAuth identity crossed into write authority without an approval ticket.' );
if ( ! MAD4B_SCP_Staging_Write_Authority::remote_scope_delegation_allowed( $fake_identity, 'mad4b-write', 'mad4b/content-update-post', array( '_mad4b_approval_ticket_id' => '11111111-1111-1111-1111-111111111111' ) ) ) $fail( 'Syntactically valid approval envelope did not unlock scope delegation for later exact ticket consumption.' );

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

$write_cert = MAD4B_SCP_Write_Runtime_Certification::observe();
if ( empty( $write_cert['ready'] ) || 'ready' !== $write_cert['state'] ) $fail( 'Governed write certification is blocked: ' . wp_json_encode( isset( $write_cert['blockers'] ) ? $write_cert['blockers'] : array() ) );
if ( empty( $write_cert['exact_approval_required_for_remote_write'] ) ) $fail( 'Write certification does not require exact remote approvals.' );
if ( 'pending_ticket_creation_only' !== $write_cert['approval_planner_bootstrap_exception'] ) $fail( 'Write certification did not record the bounded planner bootstrap exception.' );
if ( ! empty( $write_cert['external_client_tools_verified'] ) ) $fail( 'WordPress must not claim external client tool refresh.' );
if ( (int) $write_cert['write_tool_count'] !== count( $write_tools ) ) $fail( 'Write certification inventory count mismatch.' );
if ( empty( $write_cert['checks']['control_plane_not_on_rest_enabled_hook'] ) || empty( $write_cert['checks']['control_plane_not_on_rest_authentication_hook'] ) ) $fail( 'Write certification did not prove REST global-hook isolation.' );

if ( ! class_exists( 'ZipArchive' ) ) $fail( 'ZipArchive is unavailable for portable export proof.' );
$export = MAD4B_SCP_Skill_Exporter::build_temp_zip();
if ( is_wp_error( $export ) ) $fail( 'Portable export failed: ' . $export->get_error_code() );
if ( empty( $export['path'] ) || ! is_file( $export['path'] ) ) $fail( 'Portable export file is missing.' );
if ( empty( $export['identity_token'] ) || ! hash_equals( (string) $snapshot_identity['identity_token'], (string) $export['identity_token'] ) ) $fail( 'Exporter result identity token mismatch.' );
if ( empty( $export['governed_write_ready'] ) || empty( $export['write_certification_ready'] ) ) $fail( 'Portable export did not carry governed Write readiness.' );
if ( ! isset( $export['plugin_capabilities'] ) || ! in_array( 'Write', $export['plugin_capabilities'], true ) ) $fail( 'Portable export did not declare Write capability on certified Staging.' );
if ( ! isset( $export['skill_count'] ) || (int) $export['skill_count'] > MAD4B_SCP_Skill_Exporter::MAX_EXPORT_SKILLS ) $fail( 'Exporter Skill count is outside the bounded limit.' );
if ( ! isset( $export['resource_count'] ) || (int) $export['resource_count'] > MAD4B_SCP_Skill_Exporter::MAX_EXPORT_RESOURCES ) $fail( 'Exporter resource count is outside the bounded limit.' );
if ( ! isset( $export['uncompressed_payload_bytes'] ) || (int) $export['uncompressed_payload_bytes'] > MAD4B_SCP_Skill_Exporter::MAX_EXPORT_UNCOMPRESSED_BYTES ) $fail( 'Exporter payload bytes exceed the bounded limit.' );

$zip = new ZipArchive();
if ( true !== $zip->open( $export['path'] ) ) { @unlink( $export['path'] ); $fail( 'Portable export ZIP could not be reopened.' ); }
$token_file = trim( (string) $zip->getFromName( 'MAD4B-SNAPSHOT-ID.txt' ) );
$meta_json = $zip->getFromName( 'MAD4B-SNAPSHOT.json' );
$app_json = $zip->getFromName( '.app.json' );
$plugin_json = $zip->getFromName( 'plugin.json' );
$zip->close();
@unlink( $export['path'] );

if ( '' === $token_file || ! hash_equals( (string) $snapshot_identity['identity_token'], $token_file ) ) $fail( 'MAD4B-SNAPSHOT-ID.txt does not match runtime identity.' );
$meta = json_decode( (string) $meta_json, true );
if ( ! is_array( $meta ) || empty( $meta['identity_token'] ) || ! hash_equals( $token_file, (string) $meta['identity_token'] ) ) $fail( 'MAD4B-SNAPSHOT.json identity token mismatch.' );
if ( empty( $meta['governed_write_ready'] ) || empty( $meta['write_certification_ready'] ) ) $fail( 'MAD4B-SNAPSHOT.json is missing governed Write certification.' );
if ( ! isset( $meta['resource_count'], $meta['uncompressed_payload_bytes'], $meta['export_limits'] ) ) $fail( 'MAD4B-SNAPSHOT.json is missing bounded export evidence.' );
if ( (int) $meta['resource_count'] !== (int) $export['resource_count'] || (int) $meta['uncompressed_payload_bytes'] !== (int) $export['uncompressed_payload_bytes'] ) $fail( 'Export result and embedded payload counters disagree.' );
$app = json_decode( (string) $app_json, true );
$zip_app_id = is_array( $app ) && isset( $app['apps']['mad4b-wordpress']['id'] ) ? (string) $app['apps']['mad4b-wordpress']['id'] : '';
if ( '' === $zip_app_id || ! hash_equals( MAD4B_SCP_Skill_Autoconfig::staging_app_id(), $zip_app_id ) ) $fail( 'Portable .app.json does not bind the governed Staging App.' );
$plugin = json_decode( (string) $plugin_json, true );
$capabilities = is_array( $plugin ) && isset( $plugin['extensions']['com.openai']['interface']['capabilities'] ) && is_array( $plugin['extensions']['com.openai']['interface']['capabilities'] ) ? $plugin['extensions']['com.openai']['interface']['capabilities'] : array();
if ( ! in_array( 'Read', $capabilities, true ) || ! in_array( 'Write', $capabilities, true ) ) $fail( 'Runtime portable plugin.json does not declare Read + Write on certified Staging.' );

echo 'mad4b.runtime-dynamic-skills.v4: PASS' . PHP_EOL;

<?php

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$fail = static function ( $message ) {
	fwrite( STDERR, '[MAD4B Dynamic Skills smoke] ' . $message . PHP_EOL );
	exit( 1 );
};

if ( 'staging' !== wp_get_environment_type() ) $fail( 'WordPress environment is not staging.' );

$profile = MAD4B_SCP_Site_Profile::status();
if ( empty( $profile['configured'] ) || empty( $profile['origin_match'] ) || empty( $profile['environment_match'] ) ) $fail( 'Exact generic Site Profile is not enrolled.' );
if ( empty( $profile['skills_enabled'] ) ) $fail( 'Site Profile did not enable Skills.' );
if ( empty( $profile['write_enabled'] ) ) $fail( 'Site Profile did not enable governed writes.' );
if ( '' === MAD4B_SCP_Site_Profile::chatgpt_app_id() ) $fail( 'Site Profile has no ChatGPT App ID.' );

$autoconfig = MAD4B_SCP_Skill_Autoconfig::status();
if ( empty( $autoconfig['configured'] ) ) $fail( 'Skill editor was not auto-configured from the enrolled Site Profile.' );
if ( empty( $autoconfig['app_mapping_configured'] ) ) $fail( 'Profile-bound OpenAI App mapping was not configured.' );
if ( empty( $autoconfig['app_mapping_matches_profile'] ) ) $fail( 'OpenAI App mapping does not match the enrolled Site Profile.' );
if ( empty( $autoconfig['app_mapping_origin_bound'] ) ) $fail( 'OpenAI App mapping is not bound to the enrolled origin.' );
if ( ! isset( $autoconfig['expected_profile_host'], $autoconfig['observed_host'] ) || ! hash_equals( (string) $autoconfig['expected_profile_host'], (string) $autoconfig['observed_host'] ) ) $fail( 'Observed host does not match the enrolled Site Profile host.' );
if ( 'site_profile' !== $autoconfig['app_mapping_source'] ) $fail( 'Expected Site Profile App mapping source.' );
if ( ! hash_equals( MAD4B_SCP_Site_Profile::profile_digest(), (string) $autoconfig['profile_digest'] ) ) $fail( 'Skill autoconfig profile digest drifted.' );

$registry = MAD4B_SCP_Skill_Registry::status();
if ( empty( $registry['editor_enabled'] ) ) $fail( 'Skill editor is not enabled on enrolled Staging.' );
if ( ! empty( $registry['scripts_editor_enabled'] ) ) $fail( 'Scripts editor must remain disabled.' );
if ( empty( $registry['storage_initialized'] ) || empty( $registry['storage_writable'] ) ) $fail( 'Skill storage is not initialized and writable.' );
if ( empty( $registry['portable_app_id_configured'] ) ) $fail( 'Portable App mapping is unavailable through the registry.' );

$seed = MAD4B_SCP_Skill_Seeder::status();
if ( ! isset( $seed['state'] ) || 'ready' !== $seed['state'] ) $fail( 'Seed pack is not ready.' );
$seed_manifest_path = MAD4B_SCP_DIR . 'config/skill-seed-manifest.json';
$seed_manifest_raw = is_file( $seed_manifest_path ) ? file_get_contents( $seed_manifest_path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$seed_manifest = is_string( $seed_manifest_raw ) ? json_decode( $seed_manifest_raw, true ) : null;
if ( ! is_array( $seed_manifest ) || 'mad4b.skill-seed-manifest.v1' !== ( isset( $seed_manifest['contract'] ) ? (string) $seed_manifest['contract'] : '' ) ) $fail( 'Canonical seed manifest is unavailable or invalid.' );
$expected_seed_version = isset( $seed_manifest['seed_version'] ) ? (int) $seed_manifest['seed_version'] : 0;
if ( $expected_seed_version < 1 || ! isset( $seed['seed_version'] ) || $expected_seed_version !== (int) $seed['seed_version'] ) $fail( 'Runtime seed version drifted from canonical seed manifest.' );
if ( ! empty( $seed['overwrites_user_owned'] ) ) $fail( 'Seeder must never overwrite user-owned Skills.' );
if ( empty( $seed['refreshes_only_digest_clean_managed'] ) ) $fail( 'Managed seed refresh must remain digest-clean only.' );

$provider = MAD4B_SCP_Skill_Provider_Discovery::status();
if ( ! isset( $provider['state'] ) || 'ready' !== $provider['state'] ) $fail( 'Provider Skill reconciliation is not ready.' );
if ( ! empty( $provider['provider_plugin_mutation'] ) ) $fail( 'Provider discovery reported plugin mutation.' );
if ( ! empty( $provider['deletes_skills'] ) ) $fail( 'Provider discovery reported Skill deletion.' );

$seed_identities = array();
foreach ( isset( $seed_manifest['skills'] ) && is_array( $seed_manifest['skills'] ) ? $seed_manifest['skills'] : array() as $row ) {
	if ( ! is_array( $row ) ) $fail( 'Canonical seed manifest contains an invalid row.' );
	$seed_identities[] = array(
		isset( $row['level'] ) ? (string) $row['level'] : '',
		isset( $row['target'] ) ? (string) $row['target'] : '',
		isset( $row['name'] ) ? (string) $row['name'] : '',
		! array_key_exists( 'enabled', $row ) || (bool) $row['enabled'],
	);
}
if ( empty( $seed_identities ) ) $fail( 'Canonical seed manifest contains no runtime identities.' );

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

$content_skill = MAD4B_SCP_Skill_Registry::get_skill( 'workflow', 'content-authoring', 'wordpress-content-authoring' );
if ( is_wp_error( $content_skill ) ) $fail( 'Governed content-authoring Skill is unavailable.' );
$context_policy = isset( $content_skill['context_policy'] ) && is_array( $content_skill['context_policy'] ) ? $content_skill['context_policy'] : array();
if ( 'brand_core' !== ( isset( $context_policy['preset'] ) ? (string) $context_policy['preset'] : '' ) || empty( $context_policy['brand_context_required'] ) ) {
	$fail( 'Content-authoring Skill must require the canonical Brand Core policy.' );
}
if ( empty( $content_skill['context_policy_sha256'] ) ) $fail( 'Content-authoring Skill must expose a Context policy digest.' );
$expected_content_mutations = MAD4B_SCP_Context_Preflight::brand_bearing_mutation_abilities();
$actual_content_mutations = isset( $context_policy['allowed_mutation_abilities'] ) && is_array( $context_policy['allowed_mutation_abilities'] ) ? $context_policy['allowed_mutation_abilities'] : array();
sort( $expected_content_mutations, SORT_STRING );
sort( $actual_content_mutations, SORT_STRING );
if ( $expected_content_mutations !== $actual_content_mutations ) {
	$fail( 'Content-authoring Skill mutation allowlist drifted from the central Brand-bearing mutation classifier.', array( 'expected' => $expected_content_mutations, 'actual' => $actual_content_mutations ) );
}

$required_read = array(
	'mad4b/skills-list',
	'mad4b/skill-get',
	'mad4b/skills-export-status',
	'mad4b/skills-runtime-certification',
	'mad4b/write-authority-status',
	'mad4b/write-runtime-certification',
	'mad4b/rest-compatibility-status',
	'mad4b/site-bootstrap-snapshot',
);
foreach ( $required_read as $ability ) if ( ! wp_has_ability( $ability ) ) $fail( 'Missing read ability: ' . $ability );

// Existing-site bootstrap must be safe to execute through the same read-only
// runtime used by remote ChatGPT. A small page must never fail merely because
// the full site inventory is larger than the MCP response envelope.
$bootstrap_ability = wp_get_ability( 'mad4b/site-bootstrap-snapshot' );
if ( ! is_object( $bootstrap_ability ) || ! method_exists( $bootstrap_ability, 'execute' ) ) $fail( 'Site bootstrap ability is not executable.' );
$bootstrap_page = $bootstrap_ability->execute( array( 'max_items' => 1 ) );
if ( is_wp_error( $bootstrap_page ) ) $fail( 'Site bootstrap page execution failed: ' . $bootstrap_page->get_error_code() );
if ( ! is_array( $bootstrap_page ) || 'mad4b.site-content-bootstrap.v1' !== ( isset( $bootstrap_page['contract'] ) ? (string) $bootstrap_page['contract'] : '' ) ) {
	$fail( 'Site bootstrap page returned an invalid contract.' );
}
$bootstrap_snapshot_errors = isset( $bootstrap_page['snapshot_errors'] ) && is_array( $bootstrap_page['snapshot_errors'] ) ? $bootstrap_page['snapshot_errors'] : array();
foreach ( $bootstrap_snapshot_errors as $bootstrap_snapshot_error ) {
	if ( is_array( $bootstrap_snapshot_error ) && 'identity_observation_exception' === (string) ( $bootstrap_snapshot_error['code'] ?? '' ) ) {
		$fail( 'Site bootstrap identity observation raised an exception.' );
	}
}
if ( empty( $bootstrap_page['identity']['canonical_origin'] ) || MAD4B_SCP_Site_Profile::site_origin() !== (string) $bootstrap_page['identity']['canonical_origin'] ) {
	$fail( 'Site bootstrap identity canonical origin drifted from the Site Profile.' );
}
if ( empty( $bootstrap_page['bounded'] ) || ! empty( $bootstrap_page['mutation_performed'] ) || ! empty( $bootstrap_page['authorizing'] ) ) {
	$fail( 'Site bootstrap page crossed the bounded read-only contract.' );
}
if ( ! isset( $bootstrap_page['transport']['contract'] ) || 'mad4b.site-bootstrap-transport.v1' !== (string) $bootstrap_page['transport']['contract'] ) {
	$fail( 'Site bootstrap page did not expose the transport safety contract.' );
}
if ( empty( $bootstrap_page['transport']['compact_projection'] ) ) $fail( 'Site bootstrap page is not compacted for remote transport.' );
if ( ! isset( $bootstrap_page['transport']['response_byte_budget'], $bootstrap_page['transport']['response_bytes'] ) ) $fail( 'Site bootstrap page omitted response budget evidence.' );
if ( (int) $bootstrap_page['transport']['response_bytes'] > (int) $bootstrap_page['transport']['response_byte_budget'] ) $fail( 'Site bootstrap page exceeded its response byte budget.' );
if ( ! isset( $bootstrap_page['returned_item_count'] ) || (int) $bootstrap_page['returned_item_count'] > 1 ) $fail( 'Site bootstrap page exceeded requested item scope.' );
$bootstrap_wire = wp_json_encode( $bootstrap_page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( ! is_string( $bootstrap_wire ) || strlen( $bootstrap_wire ) > MAD4B_SCP_Site_Bootstrap::MAX_TRANSPORT_RESPONSE_BYTES ) {
	$fail( 'Serialized site bootstrap page exceeded the runtime transport budget.' );
}
if ( empty( $bootstrap_page['complete'] ) ) {
	$bootstrap_blockers = isset( $bootstrap_page['blocking_reasons'] ) && is_array( $bootstrap_page['blocking_reasons'] ) ? $bootstrap_page['blocking_reasons'] : array();
	if ( ! in_array( 'inventory_pagination_required_or_active', $bootstrap_blockers, true )
		&& ! in_array( 'inventory_items_partially_unreadable', $bootstrap_blockers, true )
		&& ! in_array( 'inventory_observations_partially_unreadable', $bootstrap_blockers, true ) ) {
		$fail( 'Partial site bootstrap page omitted a truthful partial-evidence blocker.' );
	}
}
if ( ! empty( $bootstrap_page['pagination']['next_after_id'] ) ) {
	$bootstrap_cursor = (int) $bootstrap_page['pagination']['next_after_id'];
	$bootstrap_next = $bootstrap_ability->execute( array( 'max_items' => MAD4B_SCP_Site_Bootstrap::MAX_TRANSPORT_PAGE_ITEMS, 'after_id' => $bootstrap_cursor ) );
	if ( is_wp_error( $bootstrap_next ) ) $fail( 'Site bootstrap continuation execution failed: ' . $bootstrap_next->get_error_code() );
	$next_ids = array();
	foreach ( isset( $bootstrap_next['items'] ) && is_array( $bootstrap_next['items'] ) ? $bootstrap_next['items'] : array() as $next_item ) {
		$next_id = isset( $next_item['object_id'] ) ? (int) $next_item['object_id'] : 0;
		if ( $next_id <= $bootstrap_cursor ) $fail( 'Site bootstrap continuation repeated or regressed an object ID.' );
		$next_ids[] = $next_id;
	}
	$sorted_next_ids = $next_ids;
	sort( $sorted_next_ids, SORT_NUMERIC );
	if ( $next_ids !== $sorted_next_ids ) $fail( 'Site bootstrap continuation is not ordered by ascending object ID.' );
	if ( ! empty( $bootstrap_next['pagination']['next_after_id'] )
		&& (int) $bootstrap_next['pagination']['next_after_id'] <= $bootstrap_cursor ) {
		$fail( 'Site bootstrap continuation cursor did not advance monotonically.' );
	}
}

// Stress the transport projection independently of site content so CI proves
// that rich provider/link/taxonomy observations cannot overflow the response.
$synthetic_item = array(
	'contract' => MAD4B_SCP_Site_Bootstrap::ITEM_CONTRACT,
	'content_id' => MAD4B_SCP_Site_Profile::site_uuid() . ':post:999001',
	'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
	'locale' => 'en',
	'language' => 'en',
	'object_type' => 'post',
	'object_id' => 999001,
	'public_url' => home_url( '/synthetic-bootstrap-item/' ),
	'canonical_url' => home_url( '/synthetic-bootstrap-item/' ),
	'title' => str_repeat( 'Synthetic bootstrap title ', 80 ),
	'status' => 'publish',
	'content_fingerprint' => hash( 'sha256', 'synthetic-bootstrap-item' ),
	'taxonomy_assignments' => array(),
	'internal_link_count' => 80,
	'outbound_link_count' => 80,
	'internal_links' => array(),
	'outbound_links' => array(),
	'seo' => array(
		'source' => 'synthetic',
		'indexability' => 'unknown',
		'provider_observations' => array( 'payload' => str_repeat( 'seo-data-', 1000 ) ),
	),
	'indexability' => 'unknown',
	'structured_data' => array( 'payload' => str_repeat( 'structured-data-', 1000 ) ),
	'provider_observations' => array( 'payload' => str_repeat( 'provider-data-', 1000 ) ),
	'published_gmt' => '2026-09-29 00:00:00',
	'modified_gmt' => '2026-09-29 00:00:00',
);
for ( $i = 0; $i < 80; $i++ ) {
	$synthetic_item['internal_links'][] = home_url( '/internal-' . $i . '/?q=' . str_repeat( 'x', 300 ) );
	$synthetic_item['outbound_links'][] = 'https://external.example/item-' . $i . '/?q=' . str_repeat( 'y', 300 );
	$synthetic_item['taxonomy_assignments'][] = array(
		'taxonomy' => 'category',
		'term_id' => $i + 1,
		'slug' => 'synthetic-term-' . $i,
		'name' => str_repeat( 'Synthetic Term ', 20 ) . $i,
	);
}
$synthetic_base = array(
	'contract' => MAD4B_SCP_Site_Bootstrap::CONTRACT,
	'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
	'site_identity_source' => 'enrolled_site_profile',
	'identity' => array(),
	'wordpress_version' => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
	'active_languages' => array( 'en' ),
	'content_types' => array(),
	'taxonomies' => array(),
	'redirect_observations' => array(),
	'inventory_scope' => array( 'post_types' => array( 'post' ), 'post_statuses' => array( 'publish' ), 'max_items' => 1000, 'effective_page_items' => 5 ),
	'pagination' => array( 'after_id' => 0, 'requested_max_items' => 1000, 'effective_page_items' => 5, 'last_scanned_id' => 999001, 'query_has_more' => true ),
	'transport' => array(
		'contract' => 'mad4b.site-bootstrap-transport.v1',
		'response_byte_budget' => MAD4B_SCP_Site_Bootstrap::MAX_TRANSPORT_RESPONSE_BYTES,
		'page_item_cap' => MAD4B_SCP_Site_Bootstrap::MAX_TRANSPORT_PAGE_ITEMS,
		'compact_projection' => true,
	),
	'observed_at' => gmdate( 'c' ),
	'historical_source_attribution' => 'synthetic_ci',
	'backfill_performed' => false,
	'intent_claims_created' => false,
	'artifacts_created' => false,
	'mutation_performed' => false,
	'authorizing' => false,
	'item_errors' => array(),
	'item_error_count' => 0,
	'item_errors_truncated' => false,
	'snapshot_errors' => array(),
	'snapshot_error_count' => 0,
);
$synthetic_snapshot = MAD4B_SCP_Site_Bootstrap::finalize_snapshot( $synthetic_base, array( $synthetic_item ), 20, 1000 );
$synthetic_wire = wp_json_encode( $synthetic_snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( ! is_string( $synthetic_wire ) || strlen( $synthetic_wire ) > MAD4B_SCP_Site_Bootstrap::MAX_TRANSPORT_RESPONSE_BYTES ) {
	$fail( 'Synthetic rich bootstrap snapshot exceeded transport budget.' );
}
if ( empty( $synthetic_snapshot['transport']['truncated'] ) ) $fail( 'Synthetic rich bootstrap snapshot did not record transport compaction.' );
if ( 'returned_page_only' !== ( isset( $synthetic_snapshot['collision_analysis']['scope'] ) ? (string) $synthetic_snapshot['collision_analysis']['scope'] : '' ) ) {
	$fail( 'Synthetic paged bootstrap collision analysis was not page-scoped.' );
}
if ( empty( $synthetic_snapshot['pagination']['next_after_id'] ) ) $fail( 'Synthetic paged bootstrap snapshot omitted continuation cursor.' );

foreach ( array( 'mad4b/skill-create', 'mad4b/skill-update', 'mad4b/skill-delete', 'mad4b/skill-write' ) as $ability ) {
	if ( wp_has_ability( $ability ) ) $fail( 'Forbidden Skill write ability is registered: ' . $ability );
}

// Register a disposable WPML-compatible route directly on the REST server. The
// low-level register_route() API expects the full route path; WordPress' public
// register_rest_route() wrapper performs this namespace prefixing itself.
// The deep diagnostic intentionally short-circuits when WPML is not active, so
// this synthetic provider fixture must explicitly model an active WPML runtime.
if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) define( 'ICL_SITEPRESS_VERSION', 'ci-fixture' );
$rest_server = rest_get_server();
$rest_server->register_route( 'wpml/v1', '/wpml/v1/rest/status', array(
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
// Behavioral proof belongs to the explicit, bounded diagnostic surface.
$deep = MAD4B_SCP_REST_Compatibility::deep_diagnostic( array( 'provider' => 'wpml' ) );
if ( is_wp_error( $deep ) ) $fail( 'Explicit WPML deep diagnostic failed: ' . $deep->get_error_code() );
if ( empty( $deep['ready'] ) || empty( $deep['query_parameters_preserved'] ) || 'behavior_verified' !== (string) $deep['state'] ) {
	$fail( 'Explicit WPML deep diagnostic did not prove query parameter pass-through.' );
}
if ( empty( $deep['active_probe_performed'] ) || empty( $deep['internal_rest_dispatch_performed'] ) || 1 !== (int) $deep['provider_self_calls_started'] ) {
	$fail( 'Explicit WPML deep diagnostic did not report its one bounded internal dispatch.' );
}
if ( ! empty( $deep['loopback_http_performed'] ) || ! empty( $deep['automatic_retry_allowed'] ) || ! empty( $deep['authorizing'] ) || ! empty( $deep['acceptance_evidence_persisted'] ) ) {
	$fail( 'Explicit WPML deep diagnostic crossed its non-authorizing boundary.' );
}

$rest_compat = MAD4B_SCP_REST_Compatibility::status();
if ( empty( $rest_compat['rest_enabled'] ) ) $fail( 'REST API is disabled in the disposable Staging runtime.' );
if ( ! empty( $rest_compat['control_plane_disables_rest'] ) ) $fail( 'Control Plane reports REST disablement.' );
if ( ! empty( $rest_compat['control_plane_filters_rest_enabled'] ) ) $fail( 'Control Plane unexpectedly owns a rest_enabled callback.' );
if ( ! empty( $rest_compat['control_plane_filters_rest_authentication_errors'] ) ) $fail( 'Control Plane unexpectedly owns a global rest_authentication_errors callback.' );
if ( ! empty( $rest_compat['rest_enabled_hook']['truncated'] ) || ! empty( $rest_compat['rest_authentication_errors_hook']['truncated'] ) ) $fail( 'REST hook evidence was truncated.' );
if ( true !== $rest_compat['wpml']['route_registered'] || empty( $rest_compat['wpml']['ready'] ) ) $fail( 'Passive WPML route observation did not see the already-materialized route.' );
if ( null !== $rest_compat['wpml']['query_parameters_preserved'] ) $fail( 'Passive WPML status fabricated behavioral evidence.' );
if ( ! empty( $rest_compat['wpml']['active_probe_performed'] ) || ! empty( $rest_compat['wpml']['internal_rest_dispatch_performed'] ) || 0 !== (int) $rest_compat['wpml']['provider_self_calls_started'] ) $fail( 'Passive WPML status started provider work.' );
if ( ! empty( $rest_compat['wpml']['control_plane_block_detected'] ) ) $fail( 'Control Plane blocked the WPML-compatible REST route.' );
$wpml_receipt = MAD4B_SCP_WPML_Response_Contract::receipt_status();
if ( ! empty( $wpml_receipt['observed'] ) ) $fail( 'Internal rest_do_request incorrectly minted external WPML evidence.' );

$write_reconciliation = MAD4B_SCP_Staging_Write_Authority::reconcile();
if ( empty( $write_reconciliation['ready'] ) || 'ready' !== $write_reconciliation['state'] ) $fail( 'Governed write authority is not ready: ' . wp_json_encode( $write_reconciliation ) );
$write_authority = MAD4B_SCP_Staging_Write_Authority::status();
if ( empty( $write_authority['mutation_gate_configured'] ) ) $fail( 'Governed mutation gate was not configured.' );
if ( ! isset( $write_authority['approval_policy_contract'] ) || 'mad4b.remote-write-approval-policy.v2' !== (string) $write_authority['approval_policy_contract'] ) $fail( 'Write authority did not expose remote approval policy v2.' );
if ( ! array_key_exists( 'all_remote_writes_require_exact_approval', $write_authority ) || false !== $write_authority['all_remote_writes_require_exact_approval'] ) $fail( 'Write authority did not expose bounded standing-exception truth.' );
if ( empty( $write_authority['normal_remote_writes_require_exact_approval'] ) ) $fail( 'Normal remote governed writes are not forced through exact approvals.' );
if ( 'exact_approval_with_bounded_standing_exceptions' !== (string) $write_authority['remote_write_approval_policy'] ) $fail( 'Write authority remote approval policy is not the v2 bounded standing-exception contract.' );
$prior_approval_exceptions = isset( $write_authority['remote_write_prior_approval_exceptions'] ) && is_array( $write_authority['remote_write_prior_approval_exceptions'] ) ? array_values( $write_authority['remote_write_prior_approval_exceptions'] ) : array();
$expected_prior_approval_exceptions = array(
	MAD4B_SCP_Staging_Write_Authority::CANDIDATE_BOOTSTRAP_ABILITY,
	MAD4B_SCP_Context_Authority::AI_REVIEW_ABILITY,
	MAD4B_SCP_AI_Approval::ABILITY,
);
if ( $expected_prior_approval_exceptions !== $prior_approval_exceptions ) $fail( 'Write authority prior-approval exception declarations are not limited to candidate bootstrap + bounded Context AI review + bounded AI approval delegation.' );
if ( empty( $write_authority['ai_approval_standing_delegation_defined'] ) ) $fail( 'Write authority does not declare the bounded AI approval delegation.' );
if ( MAD4B_SCP_AI_Approval::DELEGATION_CONTRACT !== ( isset( $write_authority['ai_approval_standing_delegation_contract'] ) ? (string) $write_authority['ai_approval_standing_delegation_contract'] : '' ) ) $fail( 'Write authority AI approval delegation contract mismatch.' );
if ( ! empty( $write_authority['ai_approval_standing_delegation_configured'] ) ) $fail( 'Fresh generic Staging unexpectedly enabled AI approval without an explicit AI delegation policy.' );
if ( ! empty( $write_authority['breakglass_included'] ) || ! empty( $write_authority['breakglass_auto_enable'] ) ) $fail( 'Breakglass leaked into governed write authority.' );
if ( empty( $write_authority['write_tool_count'] ) ) $fail( 'Write authority inventory is empty.' );
if ( empty( $write_authority['site_uuid'] ) || ! hash_equals( MAD4B_SCP_Site_Profile::site_uuid(), (string) $write_authority['site_uuid'] ) ) $fail( 'Write authority is not bound to the enrolled site UUID.' );
if ( ! isset( $write_authority['site_profile_revision'] ) || MAD4B_SCP_Site_Profile::revision() !== (int) $write_authority['site_profile_revision'] ) $fail( 'Write authority Site Profile revision mismatch.' );
if ( empty( $write_authority['site_profile_digest'] ) || ! hash_equals( MAD4B_SCP_Site_Profile::profile_digest(), (string) $write_authority['site_profile_digest'] ) ) $fail( 'Write authority Site Profile digest mismatch.' );

// Regression: once an explicit governed reconciliation has produced a ready
// authority, every ordinary inspection/status path must be grant-write-free.
// Capture the exact canonical grant set, execute the same read surfaces used by
// ChatGPT/Live Truth, then require byte-stable grant identity afterwards.
if ( false !== has_action( 'wp_abilities_api_init', array( 'MAD4B_SCP_Staging_Write_Authority', 'reconcile' ) ) ) {
	$fail( 'Destructive authority reconciliation is still attached to wp_abilities_api_init.' );
}
if ( false !== has_action( 'admin_init', array( 'MAD4B_SCP_Staging_Write_Authority', 'reconcile' ) ) ) {
	$fail( 'Destructive authority reconciliation is still attached to admin_init.' );
}
$authority_agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( (string) $write_authority['agent_public_id'] );
if ( ! is_array( $authority_agent ) || empty( $authority_agent['id'] ) ) $fail( 'Canonical write authority agent is unavailable for read-only boundary proof.' );
$grant_snapshot = static function () use ( $authority_agent ) {
	$rows = MAD4B_SCP_Agent_Registry::grants_for_agent( (int) $authority_agent['id'], 'mad4b-write' );
	$normalized = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$normalized[] = array(
			'id' => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'effect' => isset( $row['effect'] ) ? (string) $row['effect'] : '',
			'server_id' => isset( $row['server_id'] ) ? (string) $row['server_id'] : '',
			'ability_name' => isset( $row['ability_name'] ) ? (string) $row['ability_name'] : '',
			'provider' => isset( $row['provider'] ) ? (string) $row['provider'] : '',
			'environment' => isset( $row['environment'] ) ? (string) $row['environment'] : '',
		);
	}
	usort( $normalized, static function ( $a, $b ) {
		return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
	} );
	return array(
		'rows' => $normalized,
		'hash' => hash( 'sha256', wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
	);
};
$grants_before_reads = $grant_snapshot();

$read_authority = MAD4B_SCP_Staging_Write_Authority::status();
if ( ! is_array( $read_authority ) ) $fail( 'Read-only authority status did not return an array.' );
$live_authority = MAD4B_SCP_Live_Truth::current_authority_status();
if ( ! is_array( $live_authority ) ) $fail( 'Live Truth authority status did not return an array.' );
$live_certification = MAD4B_SCP_Live_Truth::current_write_certification();
if ( ! is_array( $live_certification ) ) $fail( 'Live Truth write certification did not return an array.' );
$certification_status = MAD4B_SCP_Write_Runtime_Certification::status();
if ( ! is_array( $certification_status ) ) $fail( 'Write runtime certification status did not return an array.' );

$grants_after_reads = $grant_snapshot();
if ( ! hash_equals( $grants_before_reads['hash'], $grants_after_reads['hash'] ) || $grants_before_reads['rows'] !== $grants_after_reads['rows'] ) {
	$fail( 'Read/status authority paths created, revoked, or changed exact grants.' );
}

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
$stable_external_writes = MAD4B_SCP_Servers::external_write_tools();
$direct_chatgpt_tools = MAD4B_SCP_Servers::chatgpt_tools();
foreach ( array( 'mad4b/write-discover', 'mad4b/write-info', 'mad4b/write-execute' ) as $transport_ability ) {
	if ( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $transport_ability ) ) $fail( 'Governed write transport is not mounted on ChatGPT: ' . $transport_ability );
}
foreach ( $write_tools as $ability ) {
	if ( ! MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $ability ) ) $fail( 'Write action is not mounted on mad4b-write: ' . $ability );
	if ( ! in_array( $ability, $stable_external_writes, true ) ) $fail( 'Runtime-eligible write is missing from the stable logical write catalog: ' . $ability );
	if ( MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-chatgpt', $ability ) || in_array( $ability, $direct_chatgpt_tools, true ) ) $fail( 'Underlying write schema leaked directly into ChatGPT tools/list: ' . $ability );
	$write_ability = wp_get_ability( $ability );
	$write_meta = is_object( $write_ability ) && method_exists( $write_ability, 'get_meta' ) ? $write_ability->get_meta() : array();
	if ( ! isset( $write_meta['annotations']['readonly'] ) || false !== $write_meta['annotations']['readonly'] ) $fail( 'Write inventory contains an ability without readonly=false: ' . $ability );
}

// The approval planner is itself a governed mutation, but cannot require a
// ticket to create the first pending ticket. Its target universe is exactly the
// dedicated governed-write Agent or the configured *normal* Developer Agent.
// Developer Breakglass remains outside this bootstrap.
$planner = MAD4B_SCP_Staging_Write_Planning_Guard::status();
if ( empty( $planner['remote_planner_requires_nhi'] ) || empty( $planner['remote_planner_requires_exact_mad4b_write_grant'] ) || empty( $planner['remote_planner_budgeted'] ) ) $fail( 'Approval planner is missing NHI/grant/budget governance.' );
if ( ! isset( $planner['remote_planner_requires_prior_ticket'] ) || false !== $planner['remote_planner_requires_prior_ticket'] ) $fail( 'Approval planner bootstrap incorrectly requires a prior ticket.' );
if ( 'mad4b-write_or_bounded_mad4b-developer' !== $planner['target_server']
	|| 'dedicated_governed_write_or_configured_developer_agent' !== $planner['target_agent']
	|| empty( $planner['developer_dispatch_planning_enabled'] )
	|| ! empty( $planner['developer_breakglass_target_allowed'] )
	|| 'mutation' !== $planner['target_ticket_class']
	|| ! empty( $planner['breakglass_target_allowed'] )
	|| empty( $planner['creates_pending_ticket_only'] )
	|| ! empty( $planner['auto_approves'] ) ) $fail( 'Approval planner target boundary is unsafe.' );
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
if ( empty( $write_cert['normal_remote_write_exact_approval_required'] ) ) $fail( 'Write certification does not require exact approvals for normal remote writes.' );
$bootstrap_exception = isset( $write_cert['candidate_bootstrap_prior_approval_exception'] ) ? (string) $write_cert['candidate_bootstrap_prior_approval_exception'] : '';
if ( empty( $write_cert['exact_approval_required_for_remote_write'] ) && MAD4B_SCP_Staging_Write_Authority::CANDIDATE_BOOTSTRAP_ABILITY !== $bootstrap_exception ) $fail( 'Write certification relaxed exact approval outside the bounded candidate bootstrap ability.' );
if ( ! empty( $write_cert['exact_approval_required_for_remote_write'] ) && '' !== $bootstrap_exception ) $fail( 'Write certification reported a bootstrap exception while claiming all remote writes require exact approval.' );
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
if ( ! isset( $export['plugin_capabilities'] ) || ! in_array( 'Write', $export['plugin_capabilities'], true ) ) $fail( 'Portable export did not declare Write capability on certified governed Staging.' );
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
if ( empty( $meta['context_enforcement']['context_required_skills_require_policy_digest'] ) || empty( $meta['context_enforcement']['context_required_skills_require_server_preflight'] ) || empty( $meta['context_enforcement']['brand_bearing_writes_require_exact_context_receipt'] ) || empty( $meta['context_enforcement']['portable_skill_does_not_grant_write_authority'] ) ) {
	$fail( 'MAD4B-SNAPSHOT.json is missing portable Context enforcement evidence.' );
}
$exported_content_skill = null;
foreach ( isset( $meta['skills'] ) && is_array( $meta['skills'] ) ? $meta['skills'] : array() as $exported_skill ) {
	if ( isset( $exported_skill['name'] ) && 'wordpress-content-authoring' === (string) $exported_skill['name'] ) { $exported_content_skill = $exported_skill; break; }
}
if ( ! is_array( $exported_content_skill ) || empty( $exported_content_skill['context_required'] ) ) $fail( 'Portable snapshot is missing the governed content-authoring Skill.' );
if ( empty( $exported_content_skill['context_policy_sha256'] ) || ! hash_equals( (string) $content_skill['context_policy_sha256'], (string) $exported_content_skill['context_policy_sha256'] ) ) $fail( 'Portable content-authoring policy digest drifted.' );
if ( 'brand_core' !== ( isset( $exported_content_skill['context_policy']['preset'] ) ? (string) $exported_content_skill['context_policy']['preset'] : '' ) ) $fail( 'Portable content-authoring Skill lost Brand Core policy.' );
if ( 'mad4b.context-preflight.v1' !== ( isset( $exported_content_skill['context_preflight_contract'] ) ? (string) $exported_content_skill['context_preflight_contract'] : '' ) ) $fail( 'Portable content-authoring Skill lost Context Preflight contract.' );
if ( 'mad4b.content-context-receipt.v1' !== ( isset( $exported_content_skill['context_receipt_contract'] ) ? (string) $exported_content_skill['context_receipt_contract'] : '' ) ) $fail( 'Portable content-authoring Skill lost Context Receipt contract.' );
if ( (int) $meta['resource_count'] !== (int) $export['resource_count'] || (int) $meta['uncompressed_payload_bytes'] !== (int) $export['uncompressed_payload_bytes'] ) $fail( 'Export result and embedded payload counters disagree.' );
$app = json_decode( (string) $app_json, true );
$zip_app_id = is_array( $app ) && isset( $app['apps']['mad4b-wordpress']['id'] ) ? (string) $app['apps']['mad4b-wordpress']['id'] : '';
if ( '' === $zip_app_id || ! hash_equals( MAD4B_SCP_Site_Profile::chatgpt_app_id(), $zip_app_id ) ) $fail( 'Portable .app.json does not bind the enrolled Site Profile App.' );
$plugin = json_decode( (string) $plugin_json, true );
$capabilities = is_array( $plugin ) && isset( $plugin['extensions']['com.openai']['interface']['capabilities'] ) && is_array( $plugin['extensions']['com.openai']['interface']['capabilities'] ) ? $plugin['extensions']['com.openai']['interface']['capabilities'] : array();
if ( ! in_array( 'Read', $capabilities, true ) || ! in_array( 'Write', $capabilities, true ) ) $fail( 'Runtime portable plugin.json does not declare Read + Write on certified governed Staging.' );

echo 'mad4b.runtime-dynamic-skills.v8: PASS' . PHP_EOL;

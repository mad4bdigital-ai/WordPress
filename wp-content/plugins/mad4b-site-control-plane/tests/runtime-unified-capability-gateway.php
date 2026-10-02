<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );

$fail = static function ( $message, $data = null ) {
	throw new RuntimeException( $message . ( null !== $data ? ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) : '' ) );
};

if ( ! class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) ) $fail( 'Unified capability gateway is unavailable.' );
if ( ! class_exists( 'MAD4B_SCP_Ability_Catalog_Transport' ) ) $fail( 'Ability catalog transport is unavailable.' );

$negotiated = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array(
	'action' => 'negotiate',
	'client_capabilities' => array(
		'dynamic_tool_refresh' => true,
		'tools_list_changed' => true,
		'tools_list_pagination' => true,
		'max_response_bytes' => 65536,
		'observed_rtt_ms' => 180,
		'recent_error_rate' => 0.0,
		'max_parallel_schema_fetches' => 4,
	),
), 'rest' );
if ( is_wp_error( $negotiated ) ) $fail( 'Negotiation failed.', $negotiated->get_error_code() );
if ( ! empty( $negotiated['client_claims_authoritative'] ) || 'none' !== ( $negotiated['authority_effect'] ?? '' ) ) $fail( 'Client compatibility claims changed authority.', $negotiated );
if ( empty( $negotiated['transport']['rest_gateway_proven_by_request'] ) || 'rest_gateway' !== ( $negotiated['transport']['catalog'] ?? '' ) ) $fail( 'REST transport proof was not reflected in negotiation.', $negotiated );
if ( 'fixed_dispatch' !== ( $negotiated['exposure_mode'] ?? '' ) ) $fail( 'Read-only CLI context unexpectedly gained dynamic projection authority.', $negotiated );
$dispatch_preparation = isset( $negotiated['fixed_dispatch_preparation'] ) && is_array( $negotiated['fixed_dispatch_preparation'] ) ? $negotiated['fixed_dispatch_preparation'] : array();
$required_identity = array(
	'expected_input_schema_sha256',
	'expected_execution_lane',
	'expected_classification_sha256',
	'expected_authority_scope_sha256',
	'preparation_receipt',
);
if (
	empty( $dispatch_preparation['required'] )
	|| 'mad4b.preparation-receipt.v1' !== ( $dispatch_preparation['receipt_contract'] ?? '' )
	|| 'mad4b.capability-descriptor.v2' !== ( $dispatch_preparation['descriptor_contract'] ?? '' )
	|| 'mad4b.ability-classification.v2' !== ( $dispatch_preparation['classification_contract'] ?? '' )
	|| $required_identity !== ( $dispatch_preparation['required_identity_fields'] ?? array() )
	|| empty( $dispatch_preparation['live_authority_revalidation'] )
	|| 'none' !== ( $dispatch_preparation['authority_effect'] ?? '' )
) $fail( 'Negotiation did not advertise the exact signed fixed-dispatch preparation contract.', $dispatch_preparation );

$search = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array(
	'action' => 'search',
	'task' => 'diagnostics health',
	'limit' => 8,
), 'rest' );
if ( is_wp_error( $search ) ) $fail( 'Task capability search failed.', $search->get_error_code() );
$names = array_column( $search['items'] ?? array(), 'ability_name' );
if ( ! in_array( 'mad4b/diagnostics-health', $names, true ) ) $fail( 'Task search did not return diagnostics-health.', $search );
$search_rows = array_values( array_filter( $search['items'] ?? array(), static function ( $row ) {
	return is_array( $row ) && 'mad4b/diagnostics-health' === ( $row['ability_name'] ?? '' );
} ) );
$search_row = $search_rows[0] ?? array();
if (
	empty( $search_row['preparation_required'] )
	|| ! array_key_exists( 'schema_loaded', $search_row )
	|| false !== $search_row['schema_loaded']
	|| isset( $search_row['input_schema_sha256'] )
	|| isset( $search_row['classification_sha256'] )
	|| isset( $search_row['execution_eligible'] )
) {
	$fail( 'Task search loaded schema/authority pins before explicit preparation.', $search_row );
}

if ( wp_has_ability( 'mad4b-ci/arabic-metadata-fixture' ) ) {
	$arabic_search = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array(
		'action' => 'search',
		'task' => 'محتوى عربي',
		'limit' => 8,
	), 'rest' );
	if ( is_wp_error( $arabic_search ) ) $fail( 'Arabic metadata search failed.', $arabic_search->get_error_code() );
	$arabic_rows = array_values( array_filter( $arabic_search['items'] ?? array(), static function ( $row ) {
		return is_array( $row ) && 'mad4b-ci/arabic-metadata-fixture' === ( $row['ability_name'] ?? '' );
	} ) );
	$arabic_row = $arabic_rows[0] ?? array();
	$encoded_arabic_row = wp_json_encode( $arabic_row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if (
		! is_string( $encoded_arabic_row )
		|| '' === $encoded_arabic_row
		|| strlen( (string) ( $arabic_row['label'] ?? '' ) ) > 160
		|| strlen( (string) ( $arabic_row['description'] ?? '' ) ) > 320
		|| isset( $arabic_row['input_schema_sha256'] )
		|| isset( $arabic_row['classification_sha256'] )
	) {
		$fail( 'Arabic metadata-only discovery produced invalid UTF-8 or loaded schema pins.', $arabic_row );
	}
}

$prepared = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array(
	'action' => 'prepare',
	'ability_names' => array( 'mad4b/diagnostics-health' ),
	'client_capabilities' => array( 'max_inline_schema_bytes' => 131072 ),
), 'rest' );
if ( is_wp_error( $prepared ) ) $fail( 'Ability preparation failed.', $prepared->get_error_code() );
$item = $prepared['abilities'][0] ?? array();
if ( 'mad4b/read-execute' !== ( $item['execution']['dispatch_tool'] ?? '' ) ) $fail( 'Read Ability did not map to the governed read dispatcher.', $item );
if ( empty( $item['input_schema_sha256'] ) || ! hash_equals( (string) $item['input_schema_sha256'], (string) ( $item['execution']['expected_input_schema_sha256'] ?? '' ) ) ) $fail( 'Read dispatcher did not receive the exact prepared input schema pin.', $item );
if ( empty( $item['schema_sha256'] ) || empty( $item['snapshot'] ) || ! isset( $item['schema']->inputSchema ) ) $fail( 'Lazy schema preparation did not return an inline exact schema.', $item );
if ( empty( $item['preparation_receipt'] ) || true !== MAD4B_SCP_Preparation_Receipt::verify( $item['preparation_receipt'], 'mad4b/diagnostics-health' ) ) $fail( 'Real WordPress preparation receipt did not verify.' );
if ( ! is_wp_error( MAD4B_SCP_Preparation_Receipt::verify( $item['preparation_receipt'] . '0', 'mad4b/diagnostics-health' ) ) ) $fail( 'Forged real WordPress receipt verified.' );
$dispatcher = new MAD4B_SCP_Abilities();
$receipt_input = array( 'ability_name' => 'mad4b/diagnostics-health', 'expected_input_schema_sha256' => $item['input_schema_sha256'], 'expected_execution_lane' => 'read', 'expected_classification_sha256' => $item['classification_sha256'], 'expected_authority_scope_sha256' => $item['authority_scope_sha256'], 'preparation_receipt' => $item['preparation_receipt'] . '0' );
$denied_receipt = $dispatcher->read_execute( $receipt_input );
if ( ! is_wp_error( $denied_receipt ) || 'mad4b_preparation_receipt_invalid' !== $denied_receipt->get_error_code() ) $fail( 'Real WordPress dispatcher accepted forged preparation evidence.' );
$valid_prepared_input = $receipt_input;
$valid_prepared_input['preparation_receipt'] = $item['preparation_receipt'];
foreach ( array( 'preparation_receipt', 'expected_authority_scope_sha256' ) as $mandatory_evidence ) {
	$missing_prepared = $valid_prepared_input;
	unset( $missing_prepared[ $mandatory_evidence ] );
	$denied_missing = $dispatcher->read_execute( $missing_prepared );
	if ( ! is_wp_error( $denied_missing ) || 'mad4b_dispatch_preparation_required' !== $denied_missing->get_error_code() ) {
		$fail( 'Real WordPress direct dispatcher accepted missing signed preparation evidence.', array( 'field' => $mandatory_evidence, 'result' => $denied_missing ) );
	}
}
$write_transport = wp_get_ability( 'mad4b/write-execute' );
if ( ! is_object( $write_transport ) || ! method_exists( $write_transport, 'validate_input' ) ) $fail( 'Real WordPress governed write dispatcher is unavailable for schema validation.' );
$write_transport_schema = $write_transport->get_input_schema();
foreach ( array( '_mad4b_approval_ticket_id', '_mad4b_context_receipt' ) as $governance_field ) {
	if ( empty( $write_transport_schema['properties'][ $governance_field ] ) ) $fail( 'Write dispatcher schema does not expose governed envelope field.', $governance_field );
}
if ( true !== ( $write_transport_schema['additionalProperties'] ?? null ) && false !== ( $write_transport_schema['additionalProperties'] ?? null ) ) {
	$fail( 'Write dispatcher additionalProperties contract is not explicit.', $write_transport_schema );
}
if ( false !== $write_transport_schema['additionalProperties'] ) $fail( 'Write dispatcher reopened arbitrary top-level input properties.', $write_transport_schema );
$governed_transport_input = array(
	'ability_name' => 'mad4b/plugin-package-apply',
	'expected_input_schema_sha256' => str_repeat( 'a', 64 ),
	'expected_execution_lane' => 'write',
	'expected_classification_sha256' => str_repeat( 'b', 64 ),
	'expected_authority_scope_sha256' => str_repeat( 'c', 64 ),
	'preparation_receipt' => 'schema-validation-fixture',
	'_mad4b_approval_ticket_id' => '11111111-1111-4111-8111-111111111111',
	'_mad4b_context_receipt' => array(
		'contract' => 'mad4b.content-context-receipt.v1',
		'receipt_sha256' => str_repeat( 'd', 64 ),
	),
	'input' => array(),
);
$governed_valid = $write_transport->validate_input( $governed_transport_input );
if ( true !== $governed_valid ) $fail( 'Real WordPress Ability validation rejected explicit governance envelope fields.', is_wp_error( $governed_valid ) ? $governed_valid->get_error_code() : $governed_valid );
$unknown_governance_input = $governed_transport_input;
$unknown_governance_input['_mad4b_unreviewed_control'] = true;
$unknown_valid = $write_transport->validate_input( $unknown_governance_input );
if ( ! is_wp_error( $unknown_valid ) || 'ability_invalid_input' !== $unknown_valid->get_error_code() ) $fail( 'Real WordPress Ability validation accepted an unknown dispatcher control field.', $unknown_valid );
$bad_ticket_input = $governed_transport_input;
$bad_ticket_input['_mad4b_approval_ticket_id'] = 'not-a-ticket';
$bad_ticket_valid = $write_transport->validate_input( $bad_ticket_input );
if ( ! is_wp_error( $bad_ticket_valid ) || 'ability_invalid_input' !== $bad_ticket_valid->get_error_code() ) $fail( 'Real WordPress Ability validation accepted a malformed approval ticket id.', $bad_ticket_valid );
echo "PASS governed write envelope schema: explicit approval/context controls survive validation while unknown controls fail closed\n";

$digest = (string) $item['schema_sha256'];
if ( ! empty( $item['wire']['sha256'] ) ) {
	$wire_schema = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array(
		'action' => 'schema',
		'ability_name' => 'mad4b/diagnostics-health',
		'schema_format' => 'wire',
	), 'rest' );
	if (
		is_wp_error( $wire_schema )
		|| 'wire' !== ( $wire_schema['schema_format'] ?? '' )
		|| ! hash_equals( (string) $item['wire']['sha256'], (string) ( $wire_schema['schema_sha256'] ?? '' ) )
	) {
		$fail( 'Ability-name wire schema resolution used the wrong schema identity.', $wire_schema );
	}
}

$cached = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array(
	'action' => 'prepare',
	'ability_names' => array( 'mad4b/diagnostics-health' ),
	'known_schemas' => array( 'mad4b/diagnostics-health' => $digest ),
), 'rest' );
if ( is_wp_error( $cached ) ) $fail( 'Cached preparation failed.', $cached->get_error_code() );
$cached_item = $cached['abilities'][0] ?? array();
if ( 'reusable' !== ( $cached_item['schema_cache_state'] ?? '' ) || isset( $cached_item['schema'] ) ) $fail( 'Matching schema fingerprint was not reused.', $cached_item );

if ( wp_has_ability( 'mad4b-ci/readonly-projection-fixture' ) ) {
	$dynamic_only = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array(
		'action' => 'prepare',
		'ability_names' => array( 'mad4b-ci/readonly-projection-fixture' ),
	), 'rest' );
	if ( is_wp_error( $dynamic_only ) ) $fail( 'Classified dynamic-only read preparation failed.', $dynamic_only->get_error_code() );
	$dynamic_only_item = $dynamic_only['abilities'][0] ?? array();
	$fixture_execution = $dispatcher->read_execute( array( 'ability_name' => 'mad4b-ci/readonly-projection-fixture', 'input' => array(), 'expected_input_schema_sha256' => $dynamic_only_item['input_schema_sha256'], 'expected_execution_lane' => 'read', 'expected_classification_sha256' => $dynamic_only_item['classification_sha256'], 'expected_authority_scope_sha256' => $dynamic_only_item['authority_scope_sha256'], 'preparation_receipt' => $dynamic_only_item['preparation_receipt'] ) );
	if ( is_wp_error( $fixture_execution ) || empty( $fixture_execution['result']['ok'] ) ) $fail( 'Valid real WordPress preparation evidence did not reach the governed read fixture.', $fixture_execution );

	if (
		'governed_dispatch' !== ( $dynamic_only_item['execution']['state'] ?? '' )
		|| 'mad4b/read-execute' !== ( $dynamic_only_item['execution']['dispatch_tool'] ?? '' )
		|| empty( $dynamic_only_item['projection_eligible'] )
	) {
		$fail( 'Classified third-party read Ability lost its governed dispatcher.', $dynamic_only_item );
	}
}

if ( wp_has_ability( 'mad4b/chatgpt-tool-projection-apply' ) ) {
	$enrollment = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array(
		'action' => 'prepare',
		'ability_names' => array( 'mad4b/chatgpt-tool-projection-apply' ),
	), 'rest' );
	if ( is_wp_error( $enrollment ) ) $fail( 'Enrollment-style preparation failed.', $enrollment->get_error_code() );
	$enrollment_item = $enrollment['abilities'][0] ?? array();
	if ( ! in_array( $enrollment_item['execution']['state'] ?? '', array( 'requires_operation_resolution', 'blocked' ), true ) || ! empty( $enrollment_item['execution']['direct_ability_dispatch'] ) ) {
		$fail( 'Enrollment Ability was incorrectly described as directly dispatchable.', $enrollment_item );
	}
	if ( 'blocked' === $enrollment_item['execution']['state'] && ( ! empty( $enrollment_item['execution_eligible'] ) || 'governed_execution_boundary_required' !== $enrollment_item['execution']['blocker'] ) ) $fail( 'Unwrapped enrollment target did not retain exact execution denial.', $enrollment_item );
}

if ( wp_has_ability( 'mad4b-ci/unclassified-projection-fixture' ) ) {
	$unsafe = MAD4B_SCP_Unified_Capability_Gateway::dispatch( array(
		'action' => 'prepare',
		'ability_names' => array( 'mad4b-ci/unclassified-projection-fixture' ),
	), 'rest' );
	if ( is_wp_error( $unsafe ) ) $fail( 'Unclassified preparation should remain inspectable.', $unsafe->get_error_code() );
	$unsafe_item = $unsafe['abilities'][0] ?? array();
	if ( 'blocked' !== ( $unsafe_item['execution']['state'] ?? '' ) || ! empty( $unsafe_item['projection_eligible'] ) ) $fail( 'Unclassified Ability did not remain fail-closed.', $unsafe_item );
}

$protected = MAD4B_SCP_OAuth_Resource_Bridge::resource_for_route( '/mad4b/v1/capability-gateway' );
if ( ! hash_equals( MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier(), $protected ) ) $fail( 'REST capability gateway is not bound to the governed OAuth resource.' );

fwrite( STDOUT, 'mad4b.unified-capability-gateway.runtime.v1: PASS' . PHP_EOL );


foreach ( array( 'mad4b/content-update-post', 'mad4b/plugin-activate' ) as $name ) {
 $row = MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( $name );
 if ( is_wp_error( $row ) ) $fail( 'Core original-lane fixture unavailable.', $name );
 $descriptor = MAD4B_SCP_Unified_Capability_Gateway::describe_execution( $row );
 if ( ! empty( $row['execution_eligible'] ) && MAD4B_SCP_Servers::ability_is_mounted( 'mad4b-write', $name ) ) {
  if ( 'mad4b/write-execute' !== ( $descriptor['dispatch_tool'] ?? '' ) || $row['execution_lane'] !== ( $descriptor['expected_execution_lane'] ?? '' ) || $row['classification_sha256'] !== ( $descriptor['expected_classification_sha256'] ?? '' ) ) $fail( 'Core mutation lost original classification in fixed dispatcher.', $descriptor );
 } elseif ( 'blocked' !== $descriptor['state'] ) $fail( 'Ineligible core mutation advertised execution.', $descriptor );
}
$manifest = MAD4B_SCP_Unified_Capability_Gateway::public_manifest();
if ( ! in_array( 'authenticated_wordpress_session', $manifest['rest_auth_modes'], true ) || false !== $manifest['server_tools_list_changed'] || 'fixed_dispatch' !== $manifest['primary_execution_mode'] ) $fail( 'Live auth/refresh manifest is inconsistent.', $manifest );
echo "PASS original content/admin lane and stable refresh policy on real WordPress\n";

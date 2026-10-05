<?php

$root = dirname( __DIR__ );
$runner = file_get_contents( $root . '/includes/class-mad4b-scp-provider-behavioral-recertification.php' );
$adapter = file_get_contents( $root . '/includes/adapters/class-mad4b-scp-provider-canary-adapter.php' );
$jetengine = file_get_contents( $root . '/includes/adapters/class-mad4b-scp-jetengine-adapter.php' );
$elementor = file_get_contents( $root . '/includes/adapters/class-mad4b-scp-elementor-adapter.php' );
$catalog_raw = file_get_contents( $root . '/config/provider-capability-contracts.json' );
$catalog = is_string( $catalog_raw ) ? json_decode( $catalog_raw, true ) : null;

if ( ! is_string( $runner ) || ! is_string( $adapter ) || ! is_string( $jetengine ) || ! is_string( $elementor ) || ! is_array( $catalog ) ) {
	fwrite( STDERR, "Unable to read behavioral recertification sources.\n" );
	exit( 1 );
}

$required = array(
	"const ABILITY = 'mad4b/provider-behavioral-recertify'",
	"'surface' => 'write'",
	"'bounded_write' !==",
	"ACTIVATION_SHADOW",
	"ability_is_mounted( 'mad4b-write', \$target_ability )",
	"capture_reversible_state",
	"read_reversible_state",
	"restore_reversible_state",
	"'executing' !==",
	"'used' !==",
	"candidate_binding( \$ticket_id )",
	"mad4b/provider-behavioral-recertification-attempt",
	"mad4b/provider-behavioral-recertification-executed",
	"hash_hmac( 'sha256'",
	"wp_salt( 'auth' )",
	"read_only_verifier' => true",
	"'authorizing' => false",
	"'activation_granted' => false",
	"'mutation_granted' => false",
	"probe_context_allows",
	"activate_probe_context",
	"target_input_digest",
	"finally",
	"clear_probe_context",
);
foreach ( $required as $needle ) {
	if ( false === strpos( $runner, $needle ) ) {
		fwrite( STDERR, "Missing recertification contract marker: {$needle}\n" );
		exit( 1 );
	}
}

$forbidden = array(
	"'high_risk_write' ===",
	"provider-canary-execute",
	"browser_acceptance",
	"production_auto_enable",
);
foreach ( $forbidden as $needle ) {
	if ( false !== strpos( $runner, $needle ) ) {
		fwrite( STDERR, "Unexpected authority coupling in recertification runner: {$needle}\n" );
		exit( 1 );
	}
}

if ( false === strpos( $adapter, 'MAD4B_SCP_Provider_Behavioral_Recertification::boot_early();' ) || false === strpos( $adapter, 'MAD4B_SCP_Provider_Behavioral_Recertification_Adapter::boot();' ) ) {
	fwrite( STDERR, "Behavioral recertification bootstrap is not bound to the internal adapter lifecycle.\n" );
	exit( 1 );
}

$jetengine_required = array(
	"'jetengine/update-post-meta', 'Update JetEngine Post Meta', 'update_post_meta_value'",
	'public function update_post_meta( $input )',
	'return $this->update_post_meta_value( $input );',
	"'jetengine/update-post-meta' !== \$ability_name",
	"'_listing_data' !== \$field",
	"validate_listing_data_write",
);
foreach ( $jetengine_required as $needle ) {
	if ( false === strpos( $jetengine, $needle ) ) {
		fwrite( STDERR, "JetEngine behavioral recertification bridge is incomplete: {$needle}\n" );
		exit( 1 );
	}
}

if ( false !== strpos( $jetengine, "'jetengine/update-post-meta', 'Update JetEngine Post Meta', 'update_post_meta'," ) ) {
	fwrite( STDERR, "JetEngine internal recertification writer must not replace the governed public Ability callback.\n" );
	exit( 1 );
}

$elementor_required = array(
	"exact_provider_certified( 'elementor/update-widget-settings', \$input )",
	"exact_provider_certified( 'elementor/update-widget-settings', null, \$record )",
	"MAD4B_SCP_Provider_Behavioral_Recertification::probe_context_allows( 'elementor', \$ability_name, \$target_input )",
	"'elementor/update-widget-settings' !== (string) \$ability_name",
	"recertification_recovery",
);
foreach ( $elementor_required as $needle ) {
	if ( false === strpos( $elementor, $needle ) ) {
		fwrite( STDERR, "Elementor bounded recertification bridge is incomplete: {$needle}\n" );
		exit( 1 );
	}
}

$elementor_catalog = isset( $catalog['providers']['elementor'] ) && is_array( $catalog['providers']['elementor'] ) ? $catalog['providers']['elementor'] : array();
if ( empty( $elementor_catalog ) || 'elementor' !== (string) ( $elementor_catalog['adapter_id'] ?? '' ) ) {
	fwrite( STDERR, "Elementor is missing from the capability-first provider catalog.\n" );
	exit( 1 );
}
$elementor_capabilities = isset( $elementor_catalog['capabilities'] ) && is_array( $elementor_catalog['capabilities'] ) ? $elementor_catalog['capabilities'] : array();
$expected_risks = array(
	'document.read' => 'read',
	'widget_settings.bounded-write' => 'bounded_write',
	'subtree.clone.high-risk-write' => 'high_risk_write',
	'element.move.high-risk-write' => 'high_risk_write',
	'element.delete.high-risk-write' => 'high_risk_write',
	'dynamic_tag.bind.high-risk-write' => 'high_risk_write',
	'etg_dynamic_tag.bind.high-risk-write' => 'high_risk_write',
);
foreach ( $expected_risks as $capability_id => $risk ) {
	if ( $risk !== (string) ( $elementor_capabilities[ $capability_id ]['risk'] ?? '' ) ) {
		fwrite( STDERR, "Elementor capability risk mapping is incomplete: {$capability_id}\n" );
		exit( 1 );
	}
}
if ( 'mad4b.rollback.elementor-widget-settings.v1' !== (string) ( $elementor_capabilities['widget_settings.bounded-write']['rollback_contract'] ?? '' ) ) {
	fwrite( STDERR, "Elementor bounded writer rollback contract is not exact.\n" );
	exit( 1 );
}

fwrite( STDOUT, "Provider behavioral recertification runner contract: PASS\n" );

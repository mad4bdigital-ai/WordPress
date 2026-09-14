<?php

$root = dirname( __DIR__ );
$runner = file_get_contents( $root . '/includes/class-mad4b-scp-provider-behavioral-recertification.php' );
$adapter = file_get_contents( $root . '/includes/adapters/class-mad4b-scp-provider-canary-adapter.php' );
$jetengine = file_get_contents( $root . '/includes/adapters/class-mad4b-scp-jetengine-adapter.php' );

if ( ! is_string( $runner ) || ! is_string( $adapter ) || ! is_string( $jetengine ) ) {
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

fwrite( STDOUT, "Provider behavioral recertification runner contract: PASS\n" );

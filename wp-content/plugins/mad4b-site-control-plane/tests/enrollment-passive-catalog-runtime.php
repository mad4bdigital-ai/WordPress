<?php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array(); $GLOBALS['abilities'] = array();
function add_action( $hook, $callback, $priority = 10, $argc = 1 ) { $GLOBALS['hooks'][ $hook ][ $priority ][ implode( '::', (array) $callback ) ] = $callback; }
function has_action( $hook, $callback ) { foreach ( $GLOBALS['hooks'][ $hook ] ?? array() as $p => $rows ) if ( isset( $rows[ implode( '::', $callback ) ] ) ) return $p; return false; }
function did_action( $hook ) { return 0; }
function wp_has_ability( $name ) { return isset( $GLOBALS['abilities'][ $name ] ); }
function wp_register_ability( $name, $args ) { if ( wp_has_ability( $name ) ) throw new RuntimeException( 'Duplicate ability' ); $GLOBALS['abilities'][ $name ] = $args; }
class MAD4B_SCP_Abilities {}
class MAD4B_SCP_Adapter_Registry { static function instance() { return new self(); } }
class MAD4B_SCP_Servers {}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-grant-reconciliation-plan.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-write-candidate-binding.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-multi-authority-registry.php';
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-mcp-registration-bridge.php';
// Deliberately skip the full lifecycle boot, as passive admin diagnostics do.
MAD4B_SCP_MCP_Registration_Bridge::boot_early();
$definitions = array(
	array( 'MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan', 'register_ability' ),
	array( 'MAD4B_SCP_Staging_Write_Candidate_Binding', 'register_audit_ability' ),
	array( 'MAD4B_SCP_Multi_Authority_Registry', 'register_ability' ),
);
foreach ( $definitions as $callback ) {
	if ( false === has_action( 'wp_abilities_api_init', $callback ) ) throw new RuntimeException( 'Passive catalog definition was not bound: ' . $callback[0] );
	call_user_func( $callback );
}
foreach ( $GLOBALS['abilities'] as $name => $args ) if ( true !== $args['meta']['annotations']['readonly'] ) throw new RuntimeException( 'Passive bootstrap added a mutator' );
if ( count( $GLOBALS['abilities'] ) !== 3 || wp_has_ability( MAD4B_SCP_Staging_Write_Candidate_Binding::ABILITY ) ) throw new RuntimeException( 'Passive bootstrap widened authority' );
// Normal lifecycle wiring is idempotent after the early bridge.
MAD4B_SCP_Staging_Write_Grant_Reconciliation_Plan::boot(); MAD4B_SCP_Staging_Write_Candidate_Binding::boot(); MAD4B_SCP_Multi_Authority_Registry::boot();
foreach ( $definitions as $callback ) call_user_func( $callback );
echo "Enrollment passive catalog runtime: PASS\n";

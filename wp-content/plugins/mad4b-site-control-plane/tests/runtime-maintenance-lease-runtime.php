<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mad4b_test_options'] = array();
class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $c, $m = '', $d = null ) { $this->code=$c; $this->message=$m; $this->data=$d; }
	public function get_error_code(){ return $this->code; }
	public function get_error_message(){ return $this->message; }
	public function get_error_data(){ return $this->data; }
}
function is_wp_error( $v ){ return $v instanceof WP_Error; }
function sanitize_key( $v ){ return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function absint( $v ){ return abs( (int) $v ); }
function get_option( $k, $d = false ){ return array_key_exists( $k, $GLOBALS['mad4b_test_options'] ) ? $GLOBALS['mad4b_test_options'][ $k ] : $d; }
function add_option( $k, $v, $deprecated = '', $autoload = null ){ if ( array_key_exists( $k, $GLOBALS['mad4b_test_options'] ) ) return false; $GLOBALS['mad4b_test_options'][$k]=$v; return true; }
function update_option( $k, $v, $autoload = null ){ $GLOBALS['mad4b_test_options'][$k]=$v; return true; }
function delete_option( $k ){ unset( $GLOBALS['mad4b_test_options'][$k] ); return true; }
function wp_generate_uuid4(){ static $n=0; ++$n; return sprintf( '11111111-1111-4111-8111-%012d', $n ); }

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-maintenance-lease.php';

function ok( $condition, $message ){ if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }

$clear = MAD4B_SCP_Runtime_Maintenance_Lease::preflight( 'self_update_replacement' );
ok( 'CLEAR' === $clear['classification'] && ! empty( $clear['safe_to_acquire'] ), 'empty maintenance lane must classify CLEAR' );
ok( empty( $clear['automatic_mutation_retry_allowed'] ), 'maintenance preflight must never authorize blind mutation retry' );

$token = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'runtime_convergence' );
ok( is_string( $token ) && '' !== $token, 'first worker must acquire lease' );
$status = MAD4B_SCP_Runtime_Maintenance_Lease::status();
ok( ! empty( $status['active'] ) && 'runtime_convergence' === $status['owner'], 'lease status must expose owner' );
$active_preflight = MAD4B_SCP_Runtime_Maintenance_Lease::preflight( 'self_update_replacement' );
ok( 'ACTIVE_RETRYABLE' === $active_preflight['classification'], 'active shared fence must classify ACTIVE_RETRYABLE' );
ok( empty( $active_preflight['safe_to_acquire'] ) && ! empty( $active_preflight['retryable'] ), 'active shared fence must block acquisition but remain bounded-retryable' );
ok( 'runtime_convergence' === $active_preflight['owner'], 'preflight must expose bounded owner evidence' );


$contender = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'schema_lifecycle' );
ok( is_wp_error( $contender ) && 'mad4b_runtime_maintenance_busy' === $contender->get_error_code(), 'competing worker must fail closed' );

$shared = $GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Maintenance_Lease::OPTION ];
$shared['expires_at'] = time() - 1;
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Maintenance_Lease::OPTION ] = $shared;
foreach ( MAD4B_SCP_Runtime_Maintenance_Lease::legacy_options() as $option ) {
	$row = $GLOBALS['mad4b_test_options'][ $option ];
	$row['expires_at'] = time() - 1;
	$GLOBALS['mad4b_test_options'][ $option ] = $row;
}
$contender = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'schema_lifecycle' );
ok( is_wp_error( $contender ) && 'mad4b_runtime_maintenance_busy' === $contender->get_error_code(), 'expired soft lease must remain fenced until hard deadline' );
$soft_preflight = MAD4B_SCP_Runtime_Maintenance_Lease::preflight( 'self_update_replacement' );
ok( 'ACTIVE_RETRYABLE' === $soft_preflight['classification'], 'soft-expired shared fence must remain ACTIVE_RETRYABLE until hard deadline' );
ok( ! empty( $soft_preflight['soft_lease_expired'] ), 'soft-expired preflight evidence missing' );


$refresh = MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $token, 'runtime_convergence' );
ok( true === $refresh, 'owner must renew lease' );
ok( MAD4B_SCP_Runtime_Maintenance_Lease::owned( $token, 'runtime_convergence' ), 'renewed token must retain ownership' );

$wrong = MAD4B_SCP_Runtime_Maintenance_Lease::refresh( 'wrong-token', 'runtime_convergence' );
ok( is_wp_error( $wrong ) && 'mad4b_runtime_maintenance_lease_lost' === $wrong->get_error_code(), 'stale token must be fenced' );

MAD4B_SCP_Runtime_Maintenance_Lease::release( $token, 'runtime_convergence' );
ok( false === get_option( MAD4B_SCP_Runtime_Maintenance_Lease::OPTION, false ), 'shared lease must be released' );
foreach ( MAD4B_SCP_Runtime_Maintenance_Lease::legacy_options() as $option ) ok( false === get_option( $option, false ), 'legacy fence must be released' );

$schema = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'schema_lifecycle' );
ok( is_string( $schema ) && '' !== $schema, 'next owner must acquire after verified release' );
MAD4B_SCP_Runtime_Maintenance_Lease::release( $schema, 'schema_lifecycle' );

// A pre-hard-fence runtime may leave only a legacy token with expires_at.
// New code must not steal it immediately at nominal expiry because the old
// worker can still be inside a slow dbDelta/filesystem phase.
$legacy_option = MAD4B_SCP_Runtime_Maintenance_Lease::legacy_options()[0];
$GLOBALS['mad4b_test_options'][ $legacy_option ] = array(
	'token' => 'legacy-worker-token',
	'owner' => 'legacy_runtime',
	'expires_at' => time() - 1,
);
$legacy_status = MAD4B_SCP_Runtime_Maintenance_Lease::status();
ok( ! empty( $legacy_status['active'] ), 'legacy-only maintenance fence must be visible to transport status' );
ok( ! empty( $legacy_status['legacy_only_fence'] ), 'legacy-only maintenance fence must be classified explicitly' );
ok( $legacy_option === $legacy_status['fence_source'], 'legacy-only status must expose the active fence source' );
ok( ! empty( $legacy_status['legacy_expiry_grace_applied'] ), 'legacy-only status must report bounded expiry grace' );
$legacy_preflight = MAD4B_SCP_Runtime_Maintenance_Lease::preflight( 'self_update_replacement' );
ok( 'LEGACY_GRACE' === $legacy_preflight['classification'], 'legacy-only fence inside grace must classify LEGACY_GRACE' );
ok( ! empty( $legacy_preflight['retryable'] ) && empty( $legacy_preflight['safe_to_acquire'] ), 'legacy grace must remain fenced without force release' );

ok( empty( $legacy_status['fence_token_conflict'] ), 'one legacy fence must not report a token conflict' );

$other_legacy_option = MAD4B_SCP_Runtime_Maintenance_Lease::legacy_options()[1];
$GLOBALS['mad4b_test_options'][ $other_legacy_option ] = array(
	'token' => 'different-legacy-worker-token',
	'owner' => 'other_legacy_runtime',
	'expires_at' => time() + 60,
);
$conflict_status = MAD4B_SCP_Runtime_Maintenance_Lease::status();
ok( ! empty( $conflict_status['active'] ), 'conflicting active fences must keep transport closed' );
ok( ! empty( $conflict_status['fence_token_conflict'] ), 'different active fence tokens must be diagnosed explicitly' );
ok( 2 === (int) $conflict_status['active_fence_count'], 'conflicting legacy fences must expose their exact active count' );
$conflict_preflight = MAD4B_SCP_Runtime_Maintenance_Lease::preflight( 'self_update_replacement' );
ok( 'FENCE_CONFLICT' === $conflict_preflight['classification'], 'conflicting tokens must classify FENCE_CONFLICT' );
ok( ! empty( $conflict_preflight['operator_action_required'] ) && empty( $conflict_preflight['retryable'] ), 'fence conflict must require operator inspection' );

unset( $GLOBALS['mad4b_test_options'][ $other_legacy_option ] );

$legacy_busy = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'schema_lifecycle' );
ok( is_wp_error( $legacy_busy ) && 'mad4b_runtime_maintenance_busy' === $legacy_busy->get_error_code(), 'recently expired legacy lease must receive bounded overrun fencing' );
$GLOBALS['mad4b_test_options'][ $legacy_option ]['expires_at'] = time() - MAD4B_SCP_Runtime_Maintenance_Lease::LEGACY_EXPIRY_GRACE - 1;
$legacy_expired_status = MAD4B_SCP_Runtime_Maintenance_Lease::status();
ok( empty( $legacy_expired_status['active'] ), 'legacy-only fence must stop blocking transport after bounded overrun grace' );
$stale_preflight = MAD4B_SCP_Runtime_Maintenance_Lease::preflight( 'self_update_replacement' );
ok( 'STALE_RECLAIMABLE' === $stale_preflight['classification'] && ! empty( $stale_preflight['safe_to_acquire'] ), 'hard-expired fence must be reclaimable only through normal acquire' );
$after_grace = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'schema_lifecycle' );
ok( is_string( $after_grace ) && '' !== $after_grace, 'legacy lease may be reclaimed only after overrun grace expires' );
MAD4B_SCP_Runtime_Maintenance_Lease::release( $after_grace, 'schema_lifecycle' );

$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Maintenance_Lease::OPTION ] = array(
	'owner' => 'broken_without_token',
	'expires_at' => time() + 60,
);
$malformed = MAD4B_SCP_Runtime_Maintenance_Lease::preflight( 'self_update_replacement' );
ok( 'STALE_REPAIR_REQUIRED' === $malformed['classification'], 'malformed maintenance residue must require repair' );
ok( ! empty( $malformed['operator_action_required'] ) && empty( $malformed['safe_to_acquire'] ), 'malformed maintenance residue must fail closed' );

echo "mad4b.runtime-maintenance-lease.v1: PASS\n";

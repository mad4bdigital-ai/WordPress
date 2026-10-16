<?php
/** Error containment tests independent of WordPress and the provider ecosystem. */
define( 'ABSPATH', '/' );
function add_action( $name, $callback, $priority = 10 ) { return true; }
class MAD4B_SCP_REST_Compatibility { public static function status() { throw new RuntimeException( 'private runtime secret not for clients' ); } }
class MAD4B_SCP_Admin_Query_Performance { public static function status() { return array( 'ready' => false ); } }
class MAD4B_SCP_Provider_Closure_Matrix { public static function matrix() { throw new RuntimeException( 'provider crash' ); } }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-convergence.php';
function check_optional( $ok, $msg ) { if ( ! $ok ) { fwrite(STDERR, 'FAIL ' . $msg . PHP_EOL); exit(1); } }
$method = new ReflectionMethod( 'MAD4B_SCP_Runtime_Convergence', 'read_optional_observation' );
$method->setAccessible( true );
$incidents = array();
$rest = $method->invokeArgs( null, array( 'MAD4B_SCP_REST_Compatibility', 'rest_compatibility', &$incidents ) );
check_optional( $rest === array(), 'rest fallback' );
check_optional( in_array( 'rest_compatibility_internal_exception', $incidents, true ), 'typed rest incident' );
$providers = $method->invokeArgs( null, array( 'MAD4B_SCP_Provider_Closure_Matrix', 'provider_closure', &$incidents ) );
check_optional( $providers === array(), 'provider fallback' );
check_optional( in_array( 'provider_closure_internal_exception', $incidents, true ), 'typed provider incident' );
$performance = $method->invokeArgs( null, array( 'MAD4B_SCP_Admin_Query_Performance', 'query_performance', &$incidents ) );
check_optional( $performance['ready'] === false, 'valid observation not upgraded' );
$missing = $method->invokeArgs( null, array( 'MAD4B_SCP_NotRegistered', 'missing', &$incidents ) );
check_optional( $missing === array() && in_array( 'missing_unavailable', $incidents, true ), 'missing provider fail closed' );
check_optional( count($incidents) === 3, 'no fabricated diagnostics' );
echo "OPTIONAL_OBSERVATION_FAIL_CLOSED_PASS\n";

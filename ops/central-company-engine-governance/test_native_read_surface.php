<?php
/**
 * Isolated Core integration contract tests.
 * No WordPress instance, database or filesystem mutation.
 */
define( 'ABSPATH', '/synthetic-fixture/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__, 2 ) . '/wp-content/plugins/mad4b-site-control-plane/' );
class WP_Error {
    public $code;
    public function __construct( $code, $message ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['ability_hooks'] = array();
$GLOBALS['registered_ability'] = array();
function add_action( $name, $callable, $priority ) { $GLOBALS['ability_hooks'][$name] = $callable; }
function wp_register_ability( $name, $args ) { $GLOBALS['registered_ability'][$name] = $args; return true; }
function wp_has_ability( $name ) { return isset( $GLOBALS['registered_ability'][$name] ); }
class MAD4B_SCP_Site_Profile {
    public static function status() {
        return array( 'site_uuid' => 'fixture-site-1', 'origin_match' => true,
                      'deployment_binding_match' => true, 'same_origin_clone_protection' => true );
    }
    public static function current_environment() { return 'staging'; }
}
function home_url() { return 'https://fixture.example.test'; }
class MAD4B_SCP_Operation_Registry {
    public static function operation( $id ) {
        if ( ! in_array( $id, array( 'company.plugin-sync.request', 'wordpress.runtime.converge' ), true ) ) {
            return new WP_Error( 'mad4b_operation_not_registered', '' );
        }
        return array( 'planner' => 'mad4b/test-plan', 'executor' => 'mad4b/test-apply',
                      'pipeline_profile' => 'governed_mutation_v1' );
    }
}
require MAD4B_SCP_DIR . 'includes/class-mad4b-scp-central-company-engine-governance.php';
$class = 'MAD4B_SCP_Central_Company_Engine_Governance';
$class::boot();
assert( isset( $GLOBALS['ability_hooks']['wp_abilities_api_init'] ) );
call_user_func( $GLOBALS['ability_hooks']['wp_abilities_api_init'] );
$ability = $GLOBALS['registered_ability'][$class::ABILITY] ?? null;
$preflight_ability = $GLOBALS['registered_ability']['mad4b/central-company-engine-native-preflight'] ?? null;
if ( ! is_array( $preflight_ability ) ||
     ( $preflight_ability['permission_callback'][1] ?? null ) !== 'can_read' ||
     ( $preflight_ability['meta']['annotations']['readonly'] ?? null ) !== true ) {
    throw new RuntimeException( 'missing_or_unsafe_preflight_ability' );
}
if ( ! is_array( $ability ) ||
     ( $ability['permission_callback'][1] ?? null ) !== 'can_read' ||
     ( $ability['meta']['annotations']['readonly'] ?? null ) !== true ||
     ( $ability['meta']['public'] ?? null ) !== false ) {
    throw new RuntimeException( 'unsafe_or_missing_native_read_ability' );
}
$data = json_decode( file_get_contents( MAD4B_SCP_DIR . 'config/central-company-engine-registry.json' ), true );
$got = $class::assess_manifest( $data );
if ( is_wp_error( $got ) || ( $got['engine_count'] ?? null ) !== 9 ||
     ( $got['core_operations_mapped'] ?? null ) !== 2 ||
     ( $got['mutation_authorized'] ?? null ) !== false ||
     ( $got['dispatcher_created'] ?? null ) !== false ) {
    throw new RuntimeException( 'native_core_projection_invalid' );
}
$bad = $data;
$bad['core_dispatch'] = 'custom_dispatcher';
if ( ! is_wp_error( $class::assess_manifest( $bad ) ) ) {
    throw new RuntimeException( 'parallel_dispatcher_not_denied' );
}
$bad = $data;
$bad['engines'][0]['depends_on'][] = 'company.drift_recovery';
if ( ! is_wp_error( $class::assess_manifest( $bad ) ) ) {
    throw new RuntimeException( 'dependency_cycle_not_denied' );
}
$bad = $data;
$bad['engines'][0]['native_operation'] = 'company.plugin-sync.request';
if ( ! is_wp_error( $class::assess_manifest( $bad ) ) ) {
    throw new RuntimeException( 'duplicate_operation_not_denied' );
}
if ( ! is_wp_error( $class::status( array( 'authorized' => true ) ) ) ) {
    throw new RuntimeException( 'caller_authority_injection_not_denied' );
}
$schema_preflight = $class::native_preflight( array( 'engine_id' => 'company.schema_evolution' ) );
$code_preflight = $class::native_preflight( array( 'engine_id' => 'company.code_reconciliation' ) );
foreach ( array( $schema_preflight, $code_preflight ) as $preflight ) {
    if ( is_wp_error( $preflight ) || ( $preflight['status'] ?? '' ) !== 'BLOCKED_PENDING_NATIVE_EVIDENCE' ||
        ( $preflight['site_uuid'] ?? '' ) !== 'fixture-site-1' ||
        ( $preflight['company_scope_authority_verified'] ?? null ) !== false ||
        ( $preflight['can_apply'] ?? null ) !== false ||
        ( $preflight['production_mutation'] ?? null ) !== false ||
        ( $preflight['core_registry_mapping'] ?? null ) !== false ) {
        throw new RuntimeException( 'unregistered_engine_preflight_not_fail_closed' );
    }
}
$identity_missing = $class::native_preflight( array( 'engine_id' => 'company.plugin_sync' ) );
if ( is_wp_error( $identity_missing ) ||
    ( $identity_missing['core_registry_mapping'] ?? null ) !== true ||
    ( $identity_missing['can_apply'] ?? null ) !== false ) {
    throw new RuntimeException( 'mapped_operation_claimed_authority' );
}
$invalid_scope = $class::native_preflight( array( 'engine_id' => 'company.schema_evolution',
    'authorized' => true ) );
if ( ! is_wp_error( $invalid_scope ) ) {
    throw new RuntimeException( 'client_authority_injection_not_refused_by_preflight' );
}
echo "PASS central company native read abilities, 9 engines / 2 core mappings, owner/cycle/scope guards, no execution\n";

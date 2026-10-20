<?php
/** Hermetic runtime coordinator tests; no WordPress server or package writes. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
class WP_Error {
    private $code, $data;
    function __construct( $code, $message = '', $data = null ) { $this->code = $code; $this->data = $data; }
    function get_error_code() { return $this->code; }
    function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_salt( $v ) { return 'test-only-hmac-key'; }
function wp_generate_uuid4() { return '11111111-1111-4111-8111-111111111111'; }
function current_user_can( $cap ) { return true; }
function get_current_user_id() { return 7; }
$GLOBALS['options'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $v, $autoload = false ) { $GLOBALS['options'][$key] = $v; return true; }
function add_option( $key, $v, $deprecated = '', $autoload = false ) {
    if ( array_key_exists( $key, $GLOBALS['options'] ) ) return false;
    $GLOBALS['options'][$key] = $v; return true;
}
function wp_cache_delete( $key, $group ) {}
function maybe_serialize( $v ) { return serialize( $v ); }
class MAD4B_Test_Options_DB {
    public $options = 'wp_options';
    public $race = false;
    function prepare( $sql, ...$args ) { return $args; }
    function query( $args ) {
        list( $next, $key, $expected ) = $args;
        if ( $this->race ) { $this->race = false; $GLOBALS['options'][$key]['state'] = 'another_worker'; }
        if ( serialize( get_option( $key ) ) !== $expected ) return 0;
        $GLOBALS['options'][$key] = unserialize( $next ); return 1;
    }
}
$GLOBALS['wpdb'] = new MAD4B_Test_Options_DB();
class MAD4B_SCP_Site_Profile {
    public static $env = 'staging';
    static function configured() { return true; }
    static function current_environment() { return self::$env; }
    static function origin_enrolled() { return true; }
    static function site_urls_match_enrollment() { return true; }
    static function user_is_enrolled( $id ) { return 7 === $id; }
    static function write_enabled() { return true; }
}
class MAD4B_SCP_Policy { static function can_admin() { return true; } static function can_breakglass() { return false; } }
class MAD4B_SCP_Local_OAuth_Server { const CHATGPT_CIMD_CLIENT_ID = 'https://chatgpt.com/oauth/client.json'; }
class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE = 'mad4b:authority:step-up';
    public static $scope = true, $client = true;
    static function verified_bearer_active() { return true; }
    static function verified_bearer_has_scope( $scope ) { return self::$scope && self::AUTHORITY_STEP_UP_SCOPE === $scope; }
    static function verified_bearer_client_is( $client ) { return self::$client && self::CHATGPT() === $client; }
    static function CHATGPT() { return MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID; }
}
class MAD4B_SCP_Runtime_Maintenance_Lease {
    public static $active = false;
    static function status() { return array( 'active' => self::$active ); }
}
class MAD4B_SCP_Post_Update_Continuation {
    public static $active = false;
    static function status() { return array( 'active' => self::$active, 'state' => self::$active ? 'executing' : 'consumed' ); }
}
class MAD4B_SCP_Audit {
    public static $records = array(), $fail = false;
    static function record( $ability, $record ) {
        if ( self::$fail ) return new WP_Error( 'audit_unavailable' );
        self::$records[] = $record; return array( 'persisted' => true );
    }
}
class MAD4B_SCP_Provider_Contracts {
    static function installed_version( $provider ) { return '0.7.0'; }
}
class MAD4B_SCP_Self_Update {
    static function cached_status() { return array( 'current' => $GLOBALS['pair']['control_plane'] ); }
    static function native_plan( $input ) {
        return array( 'current' => $GLOBALS['pair']['control_plane'], 'target' => array_merge(
            $GLOBALS['target'], array( 'runtime_release_set' => array( 'mcp_adapter' => array(
                'version' => '0.7.0', 'archive_sha256' => str_repeat( 'f', 64 ), 'archive_bytes' => 100,
                'package_url' => 'https://github.com/WordPress/mcp-adapter/releases/download/v0.7.0/mcp-adapter.zip' ) ) ) ),
            'plan_sha256' => str_repeat( 'e', 64 ), 'eligible' => true, 'blockers' => array() );
    }
    static function native_apply( $input, $bootstrap ) {
        check( $bootstrap && MAD4B_SCP_Runtime_Release_Set::control_plane_bootstrap_active(), 'bound runtime context was lost' );
        $admission = MAD4B_SCP_Runtime_Release_Set::revalidate_control_plane_bootstrap( $input );
        check( true === $admission, 'runtime bootstrap lost permission' );
        return new WP_Error( 'mad4b_self_update_plan_changed' );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-release-set.php';
function check( $ok, $why ) { if ( ! $ok ) throw new RuntimeException( $why ); }
function invoke( $name, ...$args ) {
    $m = new ReflectionMethod( 'MAD4B_SCP_Runtime_Release_Set', $name ); $m->setAccessible( true );
    return $m->invokeArgs( null, $args );
}
$GLOBALS['pair'] = array( 'control_plane' => array( 'version' => '1.0.0', 'source_commit_sha' => str_repeat( 'a', 40 ),
    'build_fingerprint' => str_repeat( 'b', 64 ), 'package_manifest_digest' => str_repeat( 'c', 64 ) ),
    'mcp_adapter' => array( 'version' => '0.7.0' ) );
$GLOBALS['target'] = array_merge( $GLOBALS['pair']['control_plane'], array( 'source_commit_sha' => str_repeat( 'd', 40 ),
    'archive_sha256' => str_repeat( 'e', 64 ), 'size_bytes' => 100 ) );
$transaction = array( 'contract' => MAD4B_SCP_Runtime_Release_Set::TRANSACTION_CONTRACT,
    'transaction_id' => wp_generate_uuid4(), 'state' => 'control_plane_apply_failed',
    'error_code' => 'mad4b_self_update_plan_changed', 'previous' => $GLOBALS['pair'],
    'release_set_sha256' => str_repeat( '1', 64 ) );
$model = 'MAD4B_SCP_Runtime_Release_Set';
check( $model::transaction_recovery_from_signals( $transaction, $GLOBALS['pair'], true )['eligible'], 'known premutation error should reconcile unchanged pair' );
foreach ( array( 'control_plane_update_pending', 'control_plane_updated_awaiting_resume', 'awaiting_runtime_readback', 'mcp_adapter_apply_failed' ) as $state ) {
    $bad = $transaction; $bad['state'] = $state;
    check( ! $model::transaction_recovery_from_signals( $bad, $GLOBALS['pair'], true )['eligible'], 'active or partially applied transition admitted: ' . $state );
}
$bad = $transaction; $bad['error_code'] = 'unknown_timeout';
check( ! $model::transaction_recovery_from_signals( $bad, $GLOBALS['pair'], true )['eligible'], 'unknown failure inferred not-started' );
$bad['replacement_state'] = 'not_started'; $bad['control_plane_result'] = array( 'readback_verified' => true );
check( ! $model::transaction_recovery_from_signals( $bad, $GLOBALS['pair'], true )['eligible'], 'prior component success discarded' );
foreach ( array( 'version', 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest' ) as $key ) {
    $mixed = $GLOBALS['pair']; $mixed['control_plane'][$key] = 'different';
    check( ! $model::transaction_recovery_from_signals( $transaction, $mixed, true )['eligible'], 'mixed current identity admitted: ' . $key );
}
check( ! $model::transaction_recovery_from_signals( $transaction, $GLOBALS['pair'], false )['eligible'], 'active lease or continuation ignored' );
$stored = invoke( 'write_transaction', $transaction );
check( is_array( $stored ), 'initial atomic add failed' );
$plan = $model::plan( array( 'reason' => 'test exact reconciliation' ) );
check( $plan['eligible'] && 'reconcile_failed_transaction' === $plan['operation'], 'safe stale release set could not plan recovery' );
// An unknown failure against the *same* release cannot replay a component.
$unknown = $transaction; $unknown['release_set_sha256'] = $plan['release_set_sha256'];
$unknown['error_code'] = 'unknown_timeout';
$GLOBALS['options'][$model::TRANSACTION_OPTION] = array();
unset( $GLOBALS['options'][$model::TRANSACTION_OPTION] );
$unknown_stored = invoke( 'write_transaction', $unknown );
$blocked = $model::plan( array( 'reason' => 'no replay unknown outcome' ) );
check( ! $blocked['eligible'] && in_array( 'runtime_transaction_reconciliation_required', $blocked['blockers'], true ), 'same-release unknown failure replayed component' );
$pending = $unknown; $pending['state'] = 'control_plane_update_pending'; unset( $pending['error_code'] );
unset( $GLOBALS['options'][$model::TRANSACTION_OPTION] ); invoke( 'write_transaction', $pending );
check( ! $model::plan( array( 'reason' => 'no replay running worker' ) )['eligible'], 'pending worker replayed without readback' );
$GLOBALS['options'][$model::TRANSACTION_OPTION] = $stored;
$apply = array( 'reason' => $plan['reason'], 'expected_plan_sha256' => $plan['plan_sha256'], 'confirmation' => $model::CONFIRMATION );
MAD4B_SCP_Runtime_Maintenance_Lease::$active = true;
check( is_wp_error( $model::bootstrap_apply( $apply ) ), 'lease changed after review did not block apply' );
MAD4B_SCP_Runtime_Maintenance_Lease::$active = false;
MAD4B_SCP_Audit::$fail = true;
check( is_wp_error( $model::bootstrap_apply( $apply ) ) && 'control_plane_apply_failed' === get_option( $model::TRANSACTION_OPTION )['state'], 'audit failure lost original failure record' );
MAD4B_SCP_Audit::$fail = false;
$GLOBALS['wpdb']->race = true;
check( is_wp_error( $model::bootstrap_apply( $apply ) ) && 'another_worker' === get_option( $model::TRANSACTION_OPTION )['state'], 'stale recovery overwrote another worker' );
$GLOBALS['options'][$model::TRANSACTION_OPTION] = $stored;
$done = $model::bootstrap_apply( $apply );
check( ! is_wp_error( $done ) && 'failed_transaction_reconciled_replan_required' === $done['state'], 'exact reconciliation failed' );
check( 'cancelled' === get_option( $model::TRANSACTION_OPTION )['state'] && ! empty( MAD4B_SCP_Audit::$records ), 'failure was not retained with append-only evidence' );
check( is_wp_error( $model::bootstrap_apply( $apply ) ), 'old plan replayed after reconciliation' );
$next = $model::plan( array( 'reason' => 'test independent update plan' ) );
check( $next['eligible'] && 'update_control_plane' === $next['operation'], 'fresh update not available after metadata reconciliation' );
$fail = $model::bootstrap_apply( array( 'reason' => $next['reason'], 'expected_plan_sha256' => $next['plan_sha256'], 'confirmation' => $model::CONFIRMATION ) );
check( is_wp_error( $fail ) && 'mad4b_self_update_plan_changed' === $fail->get_error_code(), 'component error was lost' );
check( ! $model::control_plane_bootstrap_active(), 'component context leaked after failed invocation' );
check( 'not_started' === get_option( $model::TRANSACTION_OPTION )['replacement_state'], 'premutation proof was not persisted' );
check( is_wp_error( $model::revalidate_control_plane_bootstrap( array() ) ), 'context-free runtime bootstrap allowed' );
MAD4B_SCP_OAuth_Resource_Bridge::$scope = false;
check( is_wp_error( $model::can_bootstrap_apply() ), 'missing step-up scope allowed' );
MAD4B_SCP_OAuth_Resource_Bridge::$scope = true;
MAD4B_SCP_OAuth_Resource_Bridge::$client = false;
check( is_wp_error( $model::can_bootstrap_apply() ), 'foreign client allowed' );
MAD4B_SCP_OAuth_Resource_Bridge::$client = true;
MAD4B_SCP_Site_Profile::$env = 'production';
check( is_wp_error( $model::can_bootstrap_apply() ), 'Production allowed' );
MAD4B_SCP_Site_Profile::$env = 'staging';
$GLOBALS['options'][$model::TRANSACTION_OPTION]['integrity'] = 'invalid';
$invalid = $model::plan( array( 'reason' => 'test invalid signature' ) );
check( ! $invalid['eligible'] && in_array( 'runtime_transaction_integrity_invalid', $invalid['blockers'], true ), 'corrupt transaction treated as absent' );
// Finalization checks both components, and cannot delete a newer transaction.
$final = $transaction; $final['state'] = 'awaiting_runtime_readback'; unset( $final['error_code'] );
$final['target'] = array( 'control_plane' => $GLOBALS['target'] );
unset( $GLOBALS['options'][$model::TRANSACTION_OPTION] );
$final_stored = invoke( 'write_transaction', $final );
$final_plan = array( 'transaction' => invoke( 'public_transaction', $final_stored ),
    'target' => $final['target'], 'release_set_sha256' => $final['release_set_sha256'],
    'runtime_readback' => array( 'ready' => true ) );
check( is_wp_error( invoke( 'commit_transaction', $final_plan, 'test' ) ), 'adapter readiness finalized the wrong Control Plane' );
$old_pair = $GLOBALS['pair']; $GLOBALS['pair']['control_plane'] = $GLOBALS['target'];
$GLOBALS['wpdb']->race = true;
check( is_wp_error( invoke( 'commit_transaction', $final_plan, 'test' ) )
    && 'another_worker' === get_option( $model::TRANSACTION_OPTION )['state'], 'finalize destroyed a newer worker record' );
$GLOBALS['options'][$model::TRANSACTION_OPTION] = $final_stored;
check( ! is_wp_error( invoke( 'commit_transaction', $final_plan, 'test' ) )
    && 'committed' === get_option( $model::TRANSACTION_OPTION )['state'], 'verified exact pair failed finalization' );
$GLOBALS['pair'] = $old_pair;
echo "RUNTIME_UPDATE_RECOVERY_PASS\n";

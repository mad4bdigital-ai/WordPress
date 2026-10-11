<?php
/** Offline site-configurable wp-admin -> original MCP planner contract fixtures. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
 private $code;
 public function __construct( $code, $message = '' ) { $this->code = $code; }
 public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function get_current_user_id() { return 11; }
function wp_generate_uuid4() { return '9ed0395e-1c6d-425e-bf8a-d0d67f8ed65b'; }
function wp_has_ability( $name ) { return in_array( $name, array( 'mad4b/target-plan', 'mad4b/target-apply' ), true ); }
class Test_Ability {
 public static $reject = false;
 public function validate_input( $input ) {
  return !self::$reject && isset( $input['mode'] ) && in_array( $input['mode'], array('safe','advanced'), true )
    ? true : new WP_Error( 'wp_ability_input_schema_rejected' );
 }
}
function wp_get_ability( $name ) { return wp_has_ability($name) ? new Test_Ability() : null; }
$GLOBALS['store'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['store'][$key] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['store'][$key] = $value; return true; }
function add_option( $key, $value, $deprecated = '', $autoload = false ) {
 if ( array_key_exists( $key, $GLOBALS['store'] ) ) return false;
 $GLOBALS['store'][$key] = $value; return true;
}
function delete_option( $key ) { unset( $GLOBALS['store'][$key] ); return true; }
class MAD4B_SCP_Operation_Registry {
 public static $risk = 'high';
 public static function operation( $id ) {
  return 'wordpress.settings.example' === $id
    ? array( 'id' => $id, 'planner' => 'mad4b/target-plan',
      'executor' => 'mad4b/target-apply', 'target_kind' => 'settings', 'risk' => self::$risk )
    : new WP_Error( 'unregistered_operation' );
 }
 public static function status() {
  return array( 'operations' => array( self::operation( 'wordpress.settings.example' ) ) );
 }
}
class MAD4B_SCP_Admin_Route_Registry {
 public static function routes() {
  return array( 'mad4b-example-settings' => array( 'required_capability' => 'manage_options' ) );
 }
}
class MAD4B_SCP_Site_Profile {
 public static $site = array();
 public static function status() { return self::$site; }
 public static function user_is_enrolled( $id ) { return 11 === $id; }
}
class MAD4B_SCP_Policy {
 public static function can_mutate() { return true; }
}
class MAD4B_SCP_OAuth_Resource_Bridge {
 const AUTHORITY_STEP_UP_SCOPE = 'mad4b:authority:step-up';
 public static $enabled = true;
 public static function verified_bearer_active() { return self::$enabled; }
 public static function verified_bearer_has_scope( $scope ) { return self::$enabled && self::AUTHORITY_STEP_UP_SCOPE === $scope; }
 public static function verified_bearer_client_is( $client ) {
  return self::$enabled && 'verified-chatgpt' === $client;
 }
}
class MAD4B_SCP_Local_OAuth_Server { const CHATGPT_CIMD_CLIENT_ID = 'verified-chatgpt'; }
class MAD4B_SCP_Authorization {
 public static $allowed = true;
 public static function authorize_mutation( $ability, $category, $provider, $input ) {
  return self::$allowed ? true : new WP_Error( 'missing_scoped_grant' );
 }
}
class MAD4B_SCP_Audit {
 public static $fail = false;
 public static function record( $event, $payload, $status ) {
  return self::$fail ? new WP_Error( 'audit_rejected' ) : true;
 }
}
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-admin-operation-profiles.php';
function check( $ok, $message ) {
 if ( !$ok ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
$c = 'MAD4B_SCP_Admin_Operation_Profiles';
MAD4B_SCP_Site_Profile::$site = array(
 'configured_environment' => 'staging', 'environment' => 'staging',
 'wordpress_environment' => 'staging', 'wordpress_environment_explicit' => true,
 'site_uuid' => '49c562d1-8f2f-456f-b454-26816c6ba4cb',
 'profile_digest' => str_repeat( 'a', 64 ),
 'canonical_origin' => 'https://staging.example.test', 'authority_ready' => true,
 'origin_match' => true
);
$profile = array( 'enabled' => true, 'route_slug' => 'mad4b-example-settings',
 'approval_mode' => 'owner_confirm', 'defaults' => array('mode'=>'safe'),
 'variables' => array( 'mode' => array( 'type'=>'string',
     'required'=>true, 'enum'=>array('safe','advanced') ),
    'dry_run' => array( 'type'=>'boolean', 'required'=>false, 'enum'=>array() ) ) );
$reason = 'Approved Staging admin workflow mapping';
$args = array( 'operation_id'=>'wordpress.settings.example', 'reason'=>$reason, 'profile'=>$profile );
$plan = $c::plan( $args );
check( !is_wp_error($plan) && preg_match('/^[a-f0-9]{64}$/D',$plan['plan_sha256']), 'source-bound plan' );
check( $plan['auto_write_allowed']===false, 'no auto privilege gain' );
check( $c::plan($args)['plan_sha256'] === $plan['plan_sha256'], 'deterministic canonical plan' );
$bad=$profile; $bad['variables']['api_key']=array('type'=>'string','required'=>false);
check( is_wp_error($c::normalize($bad)), 'secret named fields blocked' );
$bad=$profile; $bad['defaults']['mode']='unregistered-mode';
check( is_wp_error($c::normalize($bad)), 'arbitrary enum defaults denied' );
$bad=$profile; $bad['route_slug']='../../wp-config.php';
check( is_wp_error($c::normalize($bad)), 'route path traversal denied' );
$bad=$args; $bad['operation_id']='wordpress.unknown.action';
check( is_wp_error($c::plan($bad)), 'unregistered executor denied' );
$bad=$args; $bad['profile']['route_slug']='third-party-unknown';
check( is_wp_error($c::plan($bad)), 'unregistered admin route denied' );
$original = MAD4B_SCP_Site_Profile::$site;
MAD4B_SCP_Site_Profile::$site['environment']='production';
check( is_wp_error($c::plan($args)), 'Production profile not configurable' );
MAD4B_SCP_Site_Profile::$site=$original;
MAD4B_SCP_OAuth_Resource_Bridge::$enabled=false;
check( is_wp_error($c::apply($args+array('expected_plan_sha256'=>$plan['plan_sha256'],'confirmation'=>$c::CONFIRM))), 'unverified OAuth denied' );
MAD4B_SCP_OAuth_Resource_Bridge::$enabled=true;
MAD4B_SCP_Authorization::$allowed=false;
check( is_wp_error($c::apply($args+array('expected_plan_sha256'=>$plan['plan_sha256'],'confirmation'=>$c::CONFIRM))), 'scoped grant required' );
MAD4B_SCP_Authorization::$allowed=true;
$write=$args+array('expected_plan_sha256'=>$plan['plan_sha256'],'confirmation'=>$c::CONFIRM);
MAD4B_SCP_Audit::$fail=true;
check( is_wp_error($c::apply($write)), 'failed audit denied' );
check( !get_option($c::OPTION,false), 'failed audit rolls back option' );
MAD4B_SCP_Audit::$fail=false;
add_option($c::LOCK,'other', '',false);
check( is_wp_error($c::apply($write)), 'concurrent profile change denied' );
delete_option($c::LOCK);
$receipt=$c::apply($write);
check( !is_wp_error($receipt) && $receipt['readback_verified'] && !$receipt['execution_performed'], 'approved profile saved, no operation executed' );
check( is_wp_error($c::apply($write)), 'same plan cannot replay after committed state' );
$handoff=$c::resolve(array('operation_id'=>'wordpress.settings.example','values'=>array('mode'=>'advanced','dry_run'=>true)));
check( !is_wp_error($handoff) && $handoff['planner_input']['mode']==='advanced', 'typed runtime parameter override' );
check( $handoff['next_ability']==='mad4b/target-plan' && $handoff['original_executor']==='mad4b/target-apply', 'original MCP planner/executor bound' );
MAD4B_SCP_Operation_Registry::$risk='critical';
check( is_wp_error($c::resolve(array('operation_id'=>'wordpress.settings.example','values'=>array()))),
 'Updated source-owned operation policy invalidates previously saved profile' );
$stale=$c::discover(array());
check($stale['operations'][0]['stale_profile_requires_reapproval'] && !$stale['operations'][0]['enabled'],
 'Stale provider risk/schema marked for owner reapproval, not auto-migrated');
MAD4B_SCP_Operation_Registry::$risk='high';
$manual=$profile;$manual['approval_mode']='manual_only';
$manual_args=array('operation_id'=>'wordpress.settings.example','reason'=>$reason,'profile'=>$manual);
$manual_plan=$c::plan($manual_args);
check(!is_wp_error($manual_plan),'Manual-only policy can be configured');
$manual_apply=$c::apply($manual_args+array('expected_plan_sha256'=>$manual_plan['plan_sha256'],
 'confirmation'=>$c::CONFIRM));
check(!is_wp_error($manual_apply),'Owner can save stricter manual-only profile');
check(is_wp_error($c::resolve(array('operation_id'=>'wordpress.settings.example','values'=>array()))),
 'Manual-only setting cannot be remotely resolved into execution');
$restore=$c::plan($args);
check(!is_wp_error($restore),'Owner can plan restoring MCP after fresh approval');
$restore_write=$c::apply($args+array('expected_plan_sha256'=>$restore['plan_sha256'],
 'confirmation'=>$c::CONFIRM));
check(!is_wp_error($restore_write),'Restoring owner-confirm requires another governed change');
Test_Ability::$reject=true;
check( is_wp_error($c::resolve(array('operation_id'=>'wordpress.settings.example','values'=>array()))), 'original planner schema is authoritative' );
Test_Ability::$reject=false;
check( is_wp_error($c::resolve(array('operation_id'=>'wordpress.settings.example','values'=>array('secret'=>'x')))), 'unknown runtime field blocked' );
$wp_registered_settings=array('normal_option'=>array(), 'secret/unsafe'=>array());
$menu=array(array('Dashboard','manage_options','index.php'),array('Foreign','unavailable','foreign.php'));
$submenu=array('tools.php'=>array(array('Existing','manage_options','tools.php')));
function get_post_types($filter,$output='names') { return array('post','page','tour'); }
$signals=$c::observe_ui();
check(in_array('normal_option',$signals['core_settings'],true) &&
 !in_array('secret/unsafe',$signals['core_settings'],true), 'bounded registered setting names only');
check($signals['admin_menu_materialized'] && in_array('index.php',$signals['menu_routes'],true) &&
 !in_array('foreign.php',$signals['menu_routes'],true), 'WordPress menu capability filtering');
check(in_array('tour',$signals['post_types'],true), 'dynamic plugin custom post type signals');
check(!$signals['unknown_actions_remotely_executable'], 'passive discovery grants no execution');
$read=$c::discover(array());
check( !is_wp_error($read) && $read['count']===1 && $read['operations'][0]['customized'], 'dynamic operation discovery retains installed profile' );
MAD4B_SCP_Site_Profile::$site['canonical_origin']='https://changed.example';
check( is_wp_error($c::resolve(array('operation_id'=>'wordpress.settings.example','values'=>array()))), 'profile invalidated on changed origin' );
echo "PASS: source-scoped admin workflow configuration, OAuth, CAS, variable schema, readback, rollback and original ability handoff\n";

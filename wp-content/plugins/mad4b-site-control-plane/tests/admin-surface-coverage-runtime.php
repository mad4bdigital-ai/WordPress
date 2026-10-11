<?php
/** Read-only passive WordPress administrative surface inventory refusal tests. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
 private $code;
 public function __construct( $code, $message = '' ) { $this->code = $code; }
 public function get_error_code() { return $this->code; }
}
function is_wp_error( $result ) { return $result instanceof WP_Error; }
function current_user_can( $cap ) { return in_array( $cap, array( 'manage_options', 'edit_posts' ), true ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_post_types( $args, $output = 'names' ) {
 return array( 'post' => (object) array( 'show_in_rest' => true ),
  'tour' => (object) array( 'show_in_rest' => false ) );
}
function get_taxonomies( $args, $output = 'names' ) {
 return array( 'category' => (object) array( 'show_in_rest' => true ) );
}
function _get_cron_array() {
 return array( 1000 => array( 'mad4b_housekeeping' => array( 'x'=>array('args'=>array('secret')) ) ) );
}
class WP_Block_Type_Registry {
 public static function get_instance() { return new self(); }
 public function get_all_registered() { return array( 'core/paragraph'=>new stdClass(), 'example/block'=>new stdClass() ); }
}
class Test_REST_Server {
 public function get_routes() {
  return array( '/wp/v2/posts' => array( array( 'methods'=>'GET,POST',
   'args'=>array('id'=>array('type'=>'integer')) ) ), '/vendor/private' => array( array( 'methods'=>'GET' ) ) );
 }
}
class MAD4B_SCP_Site_Profile {
 public static $site;
 public static function status() { return self::$site; }
}
class MAD4B_SCP_Admin_Route_Registry {
 public static function routes() {
  return array( 'mad4b-settings'=>array('required_capability'=>'manage_options'),
   'untrusted-admin'=>array('required_capability'=>'super_admin') );
 }
}
class MAD4B_SCP_Operation_Registry {
 public static function status() {
  return array( 'operations'=>array(
   array( 'id'=>'wordpress.settings.approved', 'planner_registered'=>true,'executor_registered'=>true ),
   array( 'id'=>'wordpress.settings.unavailable', 'planner_registered'=>false,'executor_registered'=>false ) ) );
 }
}
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-admin-surface-coverage.php';
function check_surface( $ok, $reason ) {
 if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $reason . "\n" ); exit( 1 ); }
}
$c = 'MAD4B_SCP_Admin_Surface_Coverage';
MAD4B_SCP_Site_Profile::$site = array( 'site_uuid'=>'staging-uuid',
 'profile_digest'=>str_repeat('a',64), 'canonical_origin'=>'https://staging.example.test' );
$GLOBALS['wp_rest_server'] = new Test_REST_Server();
$GLOBALS['wp_registered_settings'] = array(
 'blogname'=>array('show_in_rest'=>true),
 'smtp_api_key'=>array('show_in_rest'=>false) );
$GLOBALS['menu'] = array( array('Dashboard','manage_options','index.php'),
 array('Restricted','super_admin','restricted.php') );
$GLOBALS['submenu'] = array('tools.php'=>array(
 array('Edit','edit_posts','edit.php') ));
$GLOBALS['wp_filter'] = array(
 'wp_ajax_test_action'=>new stdClass(),
 'wp_ajax_nopriv_leak'=>new stdClass(),
 'admin_post_test_save'=>new stdClass(),
 'save_post'=>new stdClass() );
$observed = $c::observations();
check_surface( isset($observed['ajax_actions']['test_action']), 'AJAX metadata detected' );
check_surface( !isset($observed['ajax_actions']['nopriv_leak']), 'Unprivileged AJAX not merged with enrolled write abilities' );
check_surface( isset($observed['admin_post_actions']['test_save']), 'admin-post metadata detected' );
check_surface( isset($observed['registered_settings']['blogname']), 'Settings registration discovered without values' );
check_surface( !isset($observed['admin_menus']['restricted.php']), 'UI items capability filtered' );
check_surface( !isset($observed['admin_routes']['untrusted-admin']), 'Registered routes capability filtered' );
check_surface( isset($observed['blocks']['example/block']), 'Plugin blocks dynamically discovered' );
check_surface( isset($observed['cron_hooks']['mad4b_housekeeping']), 'Scheduled hooks discovered without cron args' );
check_surface( !isset($observed['cron_hooks']['x']), 'Cron args never exposed as action names' );
$sum = $c::summarize($observed, MAD4B_SCP_Site_Profile::$site);
check_surface( $sum['highest_coverage_level']===2 && !$sum['ui_discovery_proves_execution'], 'Observation capped below execution' );
check_surface( count($sum['items'])>10, 'Broader source coverage' );
$approved = null;$setting = null;$ajax=null;
foreach ($sum['items'] as $item) {
 if ($item['kind']==='governed_operations' && $item['name']==='wordpress.settings.approved') $approved=$item;
 if ($item['kind']==='registered_settings' && $item['name']==='blogname') $setting=$item;
 if ($item['kind']==='ajax_actions' && $item['name']==='test_action') $ajax=$item;
 if ($item['name']==='smtp_api_key')
  check_surface(false,'Sensitive setting identifier must be redacted');
 check_surface(!$item['execution_authorized_by_observation'] && !$item['operation_write_verified'],
  'No observed surface can assert a completed write');
}
check_surface($approved['coverage_level']===2,'Registered planner visible as L2, not L3');
check_surface($setting['coverage_level']===1,'REST settings schema visibility is L1');
check_surface($ajax['coverage_level']===0,'Opaque AJAX hook never auto executable');
$hidden = $c::inventory(array());
check_surface(!isset($hidden['items']) && $hidden['returned_count']>10,'Summary-only inventory');
$fast=$c::inventory(array('scan_mode'=>'fast','include_details'=>true));
check_surface($fast['scan_mode']==='fast' && in_array('ajax_actions',$fast['not_scanned_kinds'],true),
 'Fast scan identifies categories deliberately not inspected');
check_surface($fast['counts_by_kind']['ajax_actions']===0 &&
 $fast['returned_count'] < $hidden['returned_count'],
 'Fast mode reduces expensive work rather than claiming full breadth');
check_surface(is_wp_error($c::inventory(array('scan_mode'=>'unchecked'))),'Unknown scan mode denied');
$one=$c::summarize($observed,MAD4B_SCP_Site_Profile::$site);
$changed=$observed;$changed['registered_settings']['blogname']['show_in_rest']=false;
$two=$c::summarize($changed,MAD4B_SCP_Site_Profile::$site);
check_surface($one['snapshot_sha256']!==$two['snapshot_sha256'],
 'Changing registered source schema invalidates old snapshot');
check_surface($c::summarize($observed,MAD4B_SCP_Site_Profile::$site,'fast')['snapshot_sha256'] !==
 $one['snapshot_sha256'],'Snapshot binds declared scan mode');
$full = $c::inventory(array('include_details'=>true));
check_surface(isset($full['items']),'Detailed inventory permitted');
check_surface(is_wp_error($c::inventory(array('inject'=>'random'))),'Unknown discovery argument refused');
$proposal=$c::blueprint(array('surface_id'=>$ajax['surface_id'],
 'snapshot_sha256'=>$full['snapshot_sha256'],'purpose'=>'Safely review this admin action'));
check_surface(!is_wp_error($proposal) && !$proposal['execution_performed'] &&
 !$proposal['authority_created'] && $proposal['next_state']==='source_owner_adapter_review',
 'Exact snapshot returns reviewed adapter proposal not execution');
check_surface(in_array('hook_callback_source_review',$proposal['family_acceptance_requirements'],true) &&
 $proposal['risk_class']==='unknown_external_or_write_effect',
 'Opaque AJAX proposed adapter requires hook source review and effect policy');
$setting_blueprint=$c::blueprint(array('surface_id'=>$setting['surface_id'],
 'snapshot_sha256'=>$full['snapshot_sha256'],'purpose'=>'Certify registered Settings API entry'));
check_surface(!is_wp_error($setting_blueprint) &&
 in_array('nonsecret_field_allowlist',$setting_blueprint['family_acceptance_requirements'],true),
 'Settings adapter proposal includes explicit safe-field allowlist');
$approved_blueprint=$c::blueprint(array('surface_id'=>$approved['surface_id'],
 'snapshot_sha256'=>$full['snapshot_sha256'],'purpose'=>'Reuse original governed WP Ability'));
check_surface(!is_wp_error($approved_blueprint) &&
 $approved_blueprint['next_state']==='use_existing_canonical_planner',
 'Already registered planner is reused, not replaced with new adapter');
MAD4B_SCP_Site_Profile::$site['canonical_origin']='https://foreign.example.test';
check_surface(is_wp_error($c::blueprint(array('surface_id'=>$ajax['surface_id'],
 'snapshot_sha256'=>$full['snapshot_sha256'],'purpose'=>'Same operation'))),
 'Profile/host identity drift invalidates blueprint');
MAD4B_SCP_Site_Profile::$site['canonical_origin']='https://staging.example.test';
check_surface(is_wp_error($c::blueprint(array('surface_id'=>str_repeat('f',64),
 'snapshot_sha256'=>$full['snapshot_sha256'],'purpose'=>'Unknown surface'))),
 'Unknown surface cannot be bootstrapped');
$excess = array( 'ajax_actions'=>array() );
for($i=0;$i<140;$i++)$excess['ajax_actions']['action_'.$i]=array();
$bounded=$c::summarize($excess);
check_surface($bounded['returned_count']===96 && $bounded['truncated_by_kind']['ajax_actions'],
 'Large hook catalog bounded and transparently marked');
echo "PASS: 23 passive admin surface, redaction, capability, blueprint/stale-snapshot and denial assertions\n";

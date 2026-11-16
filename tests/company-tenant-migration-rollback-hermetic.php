<?php
/**
 * Original tenant role migration: owner/preflight/CAS/lock/rollback in a
 * hermetic WordPress in-memory simulation. Never opens real DB or edits users.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['tenant_actions']=array();
$GLOBALS['tenant_routes']=array();
$GLOBALS['tenant_opts']=array();
$GLOBALS['tenant_admin']=false;
$GLOBALS['tenant_plugin_admin']=false;
$GLOBALS['tenant_owner_browser']=false;
$GLOBALS['tenant_authority_ready']=true;
$GLOBALS['tenant_audit_fail_result']=false;
$GLOBALS['tenant_audit_events']=array();
$GLOBALS['tenant_role_ops']=array();
$GLOBALS['tenant_lock_count']=0;
$GLOBALS['tenant_lock_release_count']=0;
$GLOBALS['tenant_user_mutations']=0;
class WP_Error {
 private $name;private $metadata;
 public function __construct($name,$message='',$metadata=array()){
  $this->name=$name;$this->metadata=$metadata;
 }
 public function get_error_code(){return $this->name;}
 public function get_error_data(){return $this->metadata;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
class WP_REST_Request {
 private $params;
 public function __construct(array $params=array()){$this->params=$params;}
 public function get_param($k){return $this->params[$k]??null;}
}
class FakeRole {
 public $capabilities=array();
 public function __construct(array $caps){$this->capabilities=$caps;}
 public function add_cap($c,$grant=true) {
   $this->capabilities[$c]=(bool)$grant;
   $GLOBALS['tenant_role_ops'][]='add:'.$c;
 }
 public function remove_cap($c) {
   unset($this->capabilities[$c]);$GLOBALS['tenant_role_ops'][]='remove:'.$c;
 }
 public function has_cap($c){return !empty($this->capabilities[$c]);}
}
class FakeRoles {
 public function for_site($id){$GLOBALS['tenant_master_role']->capabilities=
     $GLOBALS['tenant_opts']['wp_user_roles']['master_admin']['capabilities'];}
}
$GLOBALS['tenant_master_role']=new FakeRole(array(
    'read'=>true,'manage_options'=>true,'activate_plugins'=>true,
    'manage_woocommerce'=>true,
));
$GLOBALS['tenant_opts']['wp_user_roles']=array(
 'master_admin'=>array('capabilities'=>$GLOBALS['tenant_master_role']->capabilities));
function wp_roles(){return new FakeRoles();}
function get_role($slug){return $slug==='master_admin'?$GLOBALS['tenant_master_role']:false;}
function get_current_user_id(){return 3;}
function get_current_blog_id(){return 1;}
function get_option($name,$default=null){
 return array_key_exists($name,$GLOBALS['tenant_opts'])
  ?$GLOBALS['tenant_opts'][$name]:$default;
}
function update_option($name,$value,$autoload=false){
 if($name==='wp_user_roles' || $name==='mad4b_company_tenant_migration_receipt_v1') {
    $GLOBALS['tenant_opts'][$name]=$value;
    return true;
 }
 ++$GLOBALS['tenant_user_mutations'];throw new RuntimeException('Unknown option mutation: '.$name);
}
function delete_option($name){unset($GLOBALS['tenant_opts'][$name]);return true;}
function get_users($args=array()){return array();}
function count_users(){return array('total_users'=>0);}
function get_user_meta($id,$name,$single=false){return null;}
function wp_get_environment_type(){return 'staging';}
function did_action($hook){return 0;}
function post_type_exists($type){return false;}
function taxonomy_exists($type){return false;}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function current_time($format,$gmt=false){return '2026-11-15 00:00:00';}
function current_user_can($cap){
 return $cap==='manage_options'?$GLOBALS['tenant_admin']:
   ($cap==='activate_plugins'?$GLOBALS['tenant_plugin_admin']:false);
}
function add_action($name,$callback,$priority=10){$GLOBALS['tenant_actions'][$name]=$callback;}
function register_rest_route($ns,$path,$cfg){
 $GLOBALS['tenant_routes'][trim($ns,'/').'/'.ltrim($path,'/')]=$cfg;
}
function rest_ensure_response($payload){return $payload;}
class FakeTenantDB {
 public $prefix='wp_';
 public $posts='wp_posts';public $postmeta='wp_postmeta';
 public function esc_like($term){return $term;}
 public function prepare($sql,...$args){return $sql;}
 public function get_var($query) {
   if(strpos($query,'GET_LOCK')!==false){++$GLOBALS['tenant_lock_count'];return 1;}
   return 0;
 }
 public function query($query){
   if(strpos($query,'RELEASE_LOCK')!==false)++$GLOBALS['tenant_lock_release_count'];
   return 1;
 }
 public function get_col($sql){return array();}
}
$GLOBALS['wpdb']=new FakeTenantDB();
class MAD4B_SCP_Company_Master_Admin {
 const MIGRATION='mad4b_company_master_admin_role_version';
 const VERSION='20261028_v3';
}
class MAD4B_SCP_Company_Owner_Activation {
 public static function browserOwnerAuthorized(){return $GLOBALS['tenant_owner_browser'];}
}
class MAD4B_SCP_Site_Profile {
 public static function status() {
  return array(
   'authority_ready'=>$GLOBALS['tenant_authority_ready'],
   'site_uuid'=>'00000000-0000-4000-8000-000000000001',
   'origin_match'=>true,'deployment_binding_match'=>true,
   'same_origin_clone_protection'=>true,'revision'=>1,
   'write_enabled'=>false,'oauth_enabled'=>false,'skills_enabled'=>false,
   'mutation_pending_audit'=>false
  );
 }
}
class MAD4B_SCP_Audit {
 public static function storage_status(){return array('ready'=>true);}
 public static function record($action,$details,$status){
  $GLOBALS['tenant_audit_events'][]=$action;
  if($GLOBALS['tenant_audit_fail_result'] &&
    $action==='mad4b/company-tenant-migration-result')
   return new WP_Error('tenant_audit_write_failed');
  return array('appended'=>true);
 }
}
function check_tenant($condition,$reason){
 if(!$condition)throw new RuntimeException('FAIL TENANT MIGRATION: '.$reason);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-tenant-migration.php';
$c='MAD4B_SCP_Company_Tenant_Migration';
check_tenant(isset($GLOBALS['tenant_actions']['rest_api_init']),
 'Original REST owner was not registered');
call_user_func($GLOBALS['tenant_actions']['rest_api_init']);
check_tenant(count($GLOBALS['tenant_routes'])===2,
 'Exactly two tenant migration REST entrypoints must exist');
$routes=array(
 'mad4b/v1/company-tenant-migration/plan'=>'GET',
 'mad4b/v1/company-tenant-migration/commit'=>'POST',
);
foreach($routes as $name=>$method) {
 $r=$GLOBALS['tenant_routes'][$name]??null;
 check_tenant(is_array($r) && $r['methods']===$method
  && $r['permission_callback']===array($c,'permission'),
  'Original Owner REST permission or method drift');
 check_tenant(call_user_func($r['permission_callback']) instanceof WP_Error,
  'Guest cannot access tenant role plan or commit');
}
$deny=$c::commit(new WP_REST_Request());
check_tenant($deny instanceof WP_Error
 && $deny->get_error_code()==='mad4b_company_migration_browser_owner_required'
 && $GLOBALS['tenant_lock_count']===0,
 'Ownerless commit must reject before plan or SQL lock');
$GLOBALS['tenant_admin']=true;
check_tenant($c::permission() instanceof WP_Error,
 'WordPress admin without activate_plugins must not migrate company');
$GLOBALS['tenant_plugin_admin']=true;
$GLOBALS['tenant_owner_browser']=true;
$blocked=null;
$GLOBALS['tenant_authority_ready']=false;
$blocked=$c::plan();
check_tenant($blocked['can_apply']===false
 && in_array('site_authority_authority_ready',$blocked['blockers'],true),
 'Enrolled exact Site Profile authority is mandatory');
$GLOBALS['tenant_authority_ready']=true;
$plan=$c::plan();
check_tenant($plan['can_apply']===true
 && $plan['requires_browser_owner_cookie_nonce']===true
 && $plan['migrates_customer_data']===false
 && $plan['mutation_performed']===false,
 'Ready plan must remain nonmutating and site-bound');
$stale=$c::commit(new WP_REST_Request(array('confirmation'=>$c::CONFIRM,
 'expected_digest'=>str_repeat('0',64))));
check_tenant($stale instanceof WP_Error
 && $stale->get_error_code()==='mad4b_company_migration_stale_owner_consent'
 && $GLOBALS['tenant_lock_count']===0,
 'An unrecognized plan fingerprint must not acquire mutation lock');
$ok=$c::commit(new WP_REST_Request(array(
 'confirmation'=>$c::CONFIRM,'expected_digest'=>$plan['expected_digest'])));
check_tenant(is_array($ok) && $ok['status']==='committed'
 && $ok['existing_users_modified']===false
 && $ok['native_content_modified']===false
 && $GLOBALS['tenant_master_role']->has_cap('mad4b_manage_company_operations')
 && !$GLOBALS['tenant_master_role']->has_cap('manage_options')
 && !$GLOBALS['tenant_master_role']->has_cap('activate_plugins')
 && !$GLOBALS['tenant_master_role']->has_cap('manage_woocommerce')
 && $GLOBALS['tenant_lock_count']===1
 && $GLOBALS['tenant_lock_release_count']===1,
 'Commit must remove privileged role powers and release original SQL lock');
$receipt=get_option($c::RECEIPT);
check_tenant(is_array($receipt) && $receipt['status']==='committed'
 && $receipt['plan_digest']===$plan['expected_digest']
 && $GLOBALS['tenant_user_mutations']===0,
 'Migration receipt must match exact plan and not modify users/content');
// Introduce a new unsafe cap through an external actor, then force an audit
// receipt failure: original class must rollback its own role snapshot.
$GLOBALS['tenant_master_role']->capabilities['manage_options']=true;
$GLOBALS['tenant_opts']['wp_user_roles']=array(
 'master_admin'=>array('capabilities'=>$GLOBALS['tenant_master_role']->capabilities));
$GLOBALS['tenant_audit_fail_result']=true;
$retry_plan=$c::plan();
$before_caps=$GLOBALS['tenant_master_role']->capabilities;
$before_receipt=get_option($c::RECEIPT);
$failed=$c::commit(new WP_REST_Request(array(
 'confirmation'=>$c::CONFIRM,
 'expected_digest'=>$retry_plan['expected_digest'])));
check_tenant($failed instanceof WP_Error
 && $failed->get_error_code()==='mad4b_company_migration_rolled_back'
 && $GLOBALS['tenant_master_role']->capabilities===$before_caps
 && get_option($c::RECEIPT)===$before_receipt
 && $GLOBALS['tenant_lock_count']===2
 && $GLOBALS['tenant_lock_release_count']===2
 && $GLOBALS['tenant_user_mutations']===0,
 'Audit failure must restore original role and receipt while unlocking site');
echo "PASS: COMPANY_TENANT_ROLE_MIGRATION native permissions, digest CAS, audit, rollback\n";

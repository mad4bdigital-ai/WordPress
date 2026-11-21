<?php
/**
 * Original Company Migration Decisions: two-person CAS/SQL transaction and
 * policy-only apply, with a fake in-memory WordPress table.
 * No real WP users, external provider, SQL DB or document is modified.
 */
define('ABSPATH',__DIR__.'/');
define('ARRAY_A','ARRAY_A');
$GLOBALS['decision_actor']=0;
$GLOBALS['decision_owner']=false;
$GLOBALS['decision_site']='00000000-0000-4000-8000-123456789abc';
$GLOBALS['decision_auth']=true;
$GLOBALS['decision_cap']=true;
$GLOBALS['decision_fail_audit']=false;
$GLOBALS['decision_audits']=array();
$GLOBALS['decision_routes']=array();
$GLOBALS['decision_hooks']=array();
$GLOBALS['decision_external_writes']=0;
class WP_Error {
 private $code;public function __construct($code,$text='',$info=array()){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_REST_Request {
 private $params;
 public function __construct($params=array()){$this->params=$params;}
 public function get_param($name){return $this->params[$name]??null;}
}
class FakeDecisionDB {
 public $prefix='wp_';
 public $rows=array();
 public $insert_id=0;
 public $locks=0;
 public $unlocks=0;
 private $snapshot=null;
 public function prepare($query,...$args){
  $fmt=str_replace('%s',"'%s'",$query);
  return vsprintf($fmt,array_map(static function($value){
   return addslashes((string)$value);
  },$args));
 }
 public function esc_like($v){return $v;}
 public function get_var($sql){
  if(strpos($sql,'GET_LOCK')!==false){++$this->locks;return 1;}
  if(strpos($sql,'RELEASE_LOCK')!==false){++$this->unlocks;return 1;}
  if(strpos($sql,'SHOW TABLES LIKE')!==false)return 'wp_mad4b_company_migration_decisions';
  if(strpos($sql,'SELECT COUNT(*)')!==false){
   return count(array_filter($this->rows,static function($row){
    return in_array($row['state'],array('proposed','approved'),true);
   }));
  }
  throw new RuntimeException('Unexpected SELECT get_var: '.$sql);
 }
 public function query($sql){
  if($sql==='START TRANSACTION'){$this->snapshot=$this->rows;return true;}
  if($sql==='ROLLBACK'){
   if($this->snapshot!==null)$this->rows=$this->snapshot;
   $this->snapshot=null;return true;
  }
  if($sql==='COMMIT'){$this->snapshot=null;return true;}
  throw new RuntimeException('No live SQL in test: '.$sql);
 }
 public function insert($table,$data){
  if($table!=='wp_mad4b_company_migration_decisions')throw new RuntimeException('Foreign table');
  $this->insert_id++;
  $this->rows[$this->insert_id]=array_merge(array(
   'id'=>$this->insert_id,'reviewed_by'=>0,'applied_by'=>0,
   'prior_applied_id'=>0,
  ),$data);
  return 1;
 }
 public function update($table,$values,$where){
  if($table!=='wp_mad4b_company_migration_decisions')throw new RuntimeException('Foreign table');
  $id=$where['id']??0;
  if(!isset($this->rows[$id]))return 0;
  foreach($where as $key=>$expect)
   if((string)($this->rows[$id][$key]??'')!==(string)$expect)return 0;
  $this->rows[$id]=array_replace($this->rows[$id],$values);
  return 1;
 }
 public function get_row($sql,$style=null){
  $rows=$this->rows;
  if(preg_match('/ WHERE id=(\d+)/',$sql,$m))$rows=array_filter($rows,
   static function($row)use($m){return (int)$row['id']===(int)$m[1];});
  if(preg_match("/site_uuid='([^']+)'/",$sql,$m))$rows=array_filter($rows,
   static function($row)use($m){return $row['site_uuid']===$m[1];});
  if(preg_match("/asset_key='([^']+)'/",$sql,$m))$rows=array_filter($rows,
   static function($row)use($m){return $row['asset_key']===$m[1];});
  if(preg_match("/state='([^']+)'/",$sql,$m))$rows=array_filter($rows,
   static function($row)use($m){return $row['state']===$m[1];});
  krsort($rows);
  return reset($rows) ?: null;
 }
}
$GLOBALS['wpdb']=new FakeDecisionDB();
function get_option($name,$default=null){
 return $name==='mad4b_company_migration_decisions_schema_v1'?'1':$default;
}
function wp_get_environment_type(){return 'staging';}
function add_action($name,$cb,$priority=10){
 $GLOBALS['decision_hooks'][$name][]=$cb;
}
function register_rest_route($ns,$route,$cfg){
 $GLOBALS['decision_routes'][$ns.'/'.ltrim($route,'/')]=$cfg;
}
function current_user_can($cap){
 return $GLOBALS['decision_actor']>0 && $GLOBALS['decision_cap']
  && in_array($cap,array('mad4b_manage_company_operations','manage_options','activate_plugins'),true);
}
function get_current_user_id(){return $GLOBALS['decision_actor'];}
function current_time($format,$gmt=false){return '2026-11-15 00:00:00';}
function wp_strip_all_tags($v){return strip_tags($v);}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function rest_ensure_response($payload){return $payload;}
class MAD4B_SCP_Site_Profile {
 public static function status(){
  return array('site_uuid'=>$GLOBALS['decision_site'],
   'authority_ready'=>$GLOBALS['decision_auth'],
   'origin_match'=>true,
   'deployment_binding_match'=>true,
   'same_origin_clone_protection'=>true);
 }
}
class MAD4B_SCP_Company_Owner_Activation {
 public static function browserOwnerAuthorized(){return $GLOBALS['decision_owner'];}
}
class MAD4B_SCP_Migration_Decision_Catalog {
 public static function asset_supported($name){return $name==='test_asset';}
 public static function choices(){return array('keep'=>true,'replace'=>true);}
 public static function comparison($name){
  return self::asset_supported($name)?array('existing_sha256'=>str_repeat('a',64),
   'target_sha256'=>str_repeat('b',64)):null;
 }
 public static function legal_page($id){return false;}
 public static function definitions(){return array();}
 public static function special_pages(){return array();}
}
class MAD4B_SCP_Audit {
 public static function record($name,$data,$status){
  if($GLOBALS['decision_fail_audit'])return new WP_Error('audit_unavailable');
  $GLOBALS['decision_audits'][]=$name;
  return array('recorded'=>true);
 }
}
function check_decision($cond,$why){
 if(!$cond)throw new RuntimeException('FAIL MIGRATION DECISIONS: '.$why);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-migration-decisions.php';
$c='MAD4B_SCP_Company_Migration_Decisions';
check_decision(count($GLOBALS['decision_hooks']['rest_api_init']??array())===1,
 'Original migration decisions must register its own REST callbacks');
call_user_func($GLOBALS['decision_hooks']['rest_api_init'][0]);
$urls=array(
 'mad4b/v1/company-migration-decisions/plan'=>'GET',
 'mad4b/v1/company-migration-decisions/action'=>'POST');
check_decision(count($GLOBALS['decision_routes'])===2,'Exactly two tenant-scoped routes');
foreach($urls as $url=>$method){
 $r=$GLOBALS['decision_routes'][$url]??null;
 check_decision(is_array($r)&&$r['methods']===$method
  && $r['permission_callback']===array($c,'permission'),
  'REST owner or HTTP method drift '.$url);
}
check_decision($c::can_propose()===false
 && $c::permission() instanceof WP_Error,
 'Anonymous or unprivileged actor cannot propose migration');
$GLOBALS['decision_actor']=1;
$source=str_repeat('a',64);$target=str_repeat('b',64);
$reason='Reviewed source versus target before tenant-only migration';
$proposed=$c::propose('test_asset','keep',$reason,$source,$target,0);
check_decision(is_array($proposed)&&$proposed['status']==='proposed'
 && $proposed['external_asset_changed']===false
 && count($GLOBALS['wpdb']->rows)===1,
 'Source-backed proposal should only write the decision table');
$id=$proposed['id'];
check_decision($c::review($id,'approve')->get_error_code()==='mad4b_decision_owner_browser_required',
 'Ownerless review cannot approve existing company migration');
$GLOBALS['decision_owner']=true;
check_decision($c::review($id,'approve')->get_error_code()==='mad4b_decision_four_eyes',
 'An applicant cannot review the same proposal even with Owner browser');
$GLOBALS['decision_actor']=2;
$approved=$c::review($id,'approve');
check_decision(is_array($approved)&&$approved['status']==='approved'
 && $GLOBALS['wpdb']->rows[$id]['reviewed_by']===2,
 'Independent Owner approval must bind actual separate principal');
$bad=$c::apply($id,'invalid');
check_decision($bad instanceof WP_Error
 && $bad->get_error_code()==='mad4b_decision_apply_confirmation',
 'Explicit target policy apply text required');
$applied=$c::apply($id,$c::APPLY);
check_decision(is_array($applied)&&$applied['status']==='policy_applied'
 && $applied['provider_changes']==='PENDING_SEPARATE_CERTIFIED_ADAPTER'
 && $applied['external_asset_changed']===false
 && $GLOBALS['wpdb']->rows[$id]['state']==='applied',
 'Owner apply changes only decision registry; no external content or provider');
$badRevert=$c::revert($id,'wrong confirm');
check_decision($badRevert instanceof WP_Error
 && $badRevert->get_error_code()==='mad4b_decision_revert_confirmation',
 'Revert must require separate exact confirmation');
$reverted=$c::revert($id,$c::REVERT);
check_decision(is_array($reverted) && $reverted['status']==='policy_reverted'
 && $GLOBALS['wpdb']->rows[$id]['state']==='reverted',
 'Native revert must restore previous tenant policy state');
$GLOBALS['decision_actor']=1;$GLOBALS['decision_owner']=false;
$GLOBALS['decision_fail_audit']=true;
$failed=$c::propose('test_asset','replace',$reason,$source,$target,0);
check_decision($failed instanceof WP_Error
 && $failed->get_error_code()==='audit_unavailable'
 && count($GLOBALS['wpdb']->rows)===1,
 'Failed append-only audit must roll back new proposal and not create a policy');
$GLOBALS['decision_fail_audit']=false;
$GLOBALS['decision_auth']=false;
$unready=$c::propose('test_asset','replace',$reason,$source,$target,0);
check_decision($unready instanceof WP_Error
 && $unready->get_error_code()==='mad4b_decision_site_unready',
 'Site UUID without authority must never authorize mutation');
check_decision($GLOBALS['wpdb']->locks===$GLOBALS['wpdb']->unlocks
 && $GLOBALS['decision_external_writes']===0,
 'Every SQL lock must be released and unrelated company data preserved');
echo "PASS: COMPANY_MIGRATION_DECISIONS tenant-only CAS, two-person approval, audit rollback\n";

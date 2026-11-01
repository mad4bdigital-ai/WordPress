<?php
/* Read-only regression with in-memory WordPress and schema stubs. */
define('ABSPATH',__DIR__.'/');
define('ARRAY_A','ARRAY_A');
class WP_Error {
 private $code; public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function absint($x){return abs((int)$x);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function user_can($user,$cap,$id=0){return (bool)($GLOBALS['caps'][$cap]??false);}
function get_current_user_id(){return $GLOBALS['current_uid'];}
function get_taxonomy($name){return $name==='category'?(object)['cap'=>(object)['manage_terms'=>'manage_categories','assign_terms'=>'assign_categories']]:null;}
function apply_filters($name,$value){return $value;}
function esc_sql($x){return $x;}
class MAD4B_SCP_Delegation_Mode {
 public static function verified_wordpress_user($identity){
  return !empty($identity['authenticated_wp'])?(object)['ID'=>$GLOBALS['current_uid']]:null;
 }
 public static function authorize_ability($identity,$ability,$input){return !empty($GLOBALS['caps']['manage_options'])?true:new WP_Error('wp_cap_denied');}
}
class MAD4B_SCP_Policy {public static function can_mutate(){return $GLOBALS['write_enabled'];}}
class MAD4B_SCP_Site_Profile {public static function site_uuid(){return $GLOBALS['site_uuid'];}}
class MAD4B_SCP_Agent_Registry {
 public static function resolve_agent($identity){
  if(($identity['subject_type']??'')!=='nhi')return new WP_Error('nhi_unavailable');
  return ['id'=>57,'public_id'=>'actor-test'];
 }
}
class MAD4B_SCP_Schema {public static function tables(){return ['mutations'=>'wp_test_mutations'];}}
class FakeDb {
 public function get_results($sql,$format) {
  if(empty($GLOBALS['schema_ready']))return [];
  return [
   ['Field'=>'principal_type','Null'=>'NO'],
   ['Field'=>'agent_id','Null'=>'YES'],
   ['Field'=>'wp_user_id','Null'=>'NO'],
  ];
 }
}
$wpdb=new FakeDb();
require dirname(__DIR__).'/includes/class-mad4b-scp-mutation-principal.php';
function ok($x,$message){if(!$x)throw new RuntimeException($message);}
$GLOBALS['current_uid']=2;$GLOBALS['site_uuid']='site-test-123';
$GLOBALS['write_enabled']=true;$GLOBALS['schema_ready']=true;
$GLOBALS['caps']=['manage_options'=>true,'delete_post'=>true,'edit_post'=>true,'manage_categories'=>true,'assign_categories'=>true];
$id=['authenticated_wp'=>true,'subject_type'=>'','request_id'=>'req'];
$ability='mad4b/content-create-post';
$x=MAD4B_SCP_Mutation_Principal::resolve($id,$ability,['post_type'=>'post']);
ok(!is_wp_error($x)&&$x['principal_type']==='wordpress_user'&&$x['agent_id']===null,'WP principal type with null agent');
ok($x['wp_user_id']===2&&$x['subject_fingerprint']===MAD4B_SCP_Mutation_Principal::fingerprint('site-test-123',2),'site-scoped identity');
$adapter=new class {public function read_reversible_state($ability,$target){return ['post_id'=>24];}};
$record=['principal_type'=>'wordpress_user','ability_name'=>$ability,'wp_user_id'=>2,'subject_fingerprint'=>$x['subject_fingerprint']];
$undo=MAD4B_SCP_Mutation_Principal::owner_for_undo($id,$record,$adapter,[]);
ok(!is_wp_error($undo)&&$undo['agent_id']===null,'same WP actor undo');
$GLOBALS['caps']['delete_post']=false;
$denied=MAD4B_SCP_Mutation_Principal::owner_for_undo($id,$record,$adapter,[]);
ok(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_undo_wordpress_post_denied','deletion capability checked');
$GLOBALS['caps']['delete_post']=true;$GLOBALS['current_uid']=3;
$denied=MAD4B_SCP_Mutation_Principal::owner_for_undo($id,$record,$adapter,[]);
ok(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_undo_wordpress_owner_denied','cross-user undo denied');
$GLOBALS['current_uid']=2;$GLOBALS['site_uuid']='different-site';
$denied=MAD4B_SCP_Mutation_Principal::owner_for_undo($id,$record,$adapter,[]);
ok(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_undo_wordpress_site_denied','cross-site undo denied');
$GLOBALS['site_uuid']='site-test-123';$GLOBALS['write_enabled']=false;
$denied=MAD4B_SCP_Mutation_Principal::resolve($id,$ability,[]);
ok(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_principal_write_denied','site kill switch');
$GLOBALS['write_enabled']=true;$GLOBALS['schema_ready']=false;
$denied=MAD4B_SCP_Mutation_Principal::resolve($id,$ability,[]);
ok(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_principal_journal_schema_unready','migration gate');
$GLOBALS['schema_ready']=true;
$denied=MAD4B_SCP_Mutation_Principal::resolve($id,'mad4b/developer-breakglass-shell',[]);
ok(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_principal_native_ability_unmapped','shell not treated as reversible WordPress adapter');
$untrusted=['subject_type'=>'nhi','subject_fingerprint'=>str_repeat('b',64)];
$a=MAD4B_SCP_Mutation_Principal::resolve($untrusted,$ability,[]);
ok(!is_wp_error($a)&&$a['agent_id']===57&&$a['principal_type']==='independent_nhi','NHI still available');
$legacy=['agent_id'=>57,'ability_name'=>$ability,'wp_user_id'=>0];
$owner=MAD4B_SCP_Mutation_Principal::owner_for_undo($untrusted,$legacy,$adapter,[]);
ok(!is_wp_error($owner)&&$owner['agent_id']===57,'legacy NHI undo unchanged');
echo "DUAL_PRINCIPAL_RUNTIME: PASS (WP actor + NHI, same-user undo, cross-site/actor denial, kill switch and schema gate)\n";

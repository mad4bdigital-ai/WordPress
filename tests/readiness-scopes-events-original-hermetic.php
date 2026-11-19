<?php
/** Original Readiness Scopes + Integrations, native bounded cohorts and no-mail gate. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$msg='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$v));}
function sanitize_text_field($v){return trim((string)$v);}
function absint($v){return abs((int)$v);}
function get_current_blog_id(){return 3;}
function get_current_user_id(){return 17;}
function current_user_can($cap){return $GLOBALS['caps'][$cap]??false;}
function get_option($key,$fallback=null){return $GLOBALS['opts'][$key]??$fallback;}
function add_option($key,$data,$x='',$autoload=false){
 if(array_key_exists($key,$GLOBALS['opts']))return false;
 $GLOBALS['opts'][$key]=$data;return true;
}
function update_option($key,$data,$autoload=false){$GLOBALS['opts'][$key]=$data;return true;}
function add_action($name,$handler,$priority=10){$GLOBALS['actions'][$name][]=$handler;}
function do_action($hook,...$args){$GLOBALS['emissions'][]=[$hook,$args];}
function register_rest_route($ns,$route,$args){$GLOBALS['routes'][$ns.$route]=$args;}
function rest_ensure_response($value){return ['original_rest_response'=>$value];}
function source_assert($condition,$why){
 if(!$condition){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['cases']=($GLOBALS['cases']??0)+1;
}
class OriginalRequest implements ArrayAccess {
 private $params;
 function __construct($params){$this->params=$params;}
 function get_param($key){return $this->params[$key]??null;}
 function get_json_params(){return $this->params;}
 public function __get($key){return $this->params[$key]??null;}
 #[\ReturnTypeWillChange]
 public function offsetExists($key){return array_key_exists($key,$this->params);}
 #[\ReturnTypeWillChange]
 public function offsetGet($key){return $this->params[$key]??null;}
 #[\ReturnTypeWillChange]
 public function offsetSet($key,$value){throw new RuntimeException("read-only request");}
 #[\ReturnTypeWillChange]
 public function offsetUnset($key){throw new RuntimeException("read-only request");}
}
class MAD4B_SCP_Content_Readiness_Alerts {
 const STORE='readiness_source_issues';
 static function rest_local_client_write_permission(){return false;}
}
class MAD4B_SCP_Readiness_Workflow_Options {
 const GROUPS=['media'=>'Media'];
 const OPTION='readiness_workflow_settings';
 static function group_for_issue($issue){return 'media';}
 static function assigned_user($issue){return 17;}
 static function settings(){return ['board_id'=>1,'stage_id'=>1,'board_tasks'=>false,'bitflows_notifications'=>true,'email_notifications'=>false,
 'owners'=>['media'=>17],'owner_roles'=>['media'=>'editor']];}
 static function validate_board_stage($board,$stage){return false;}
}
class MAD4B_SCP_Readiness_PreActivation {
 static function execution_locked(){return true;}
}
$GLOBALS['opts']=[];
$GLOBALS['caps']=['manage_options'=>true,'mad4b_manage_company_operations'=>true];
$GLOBALS['actions']=[];$GLOBALS['emissions']=[];$GLOBALS['routes']=[];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-readiness-integrations.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-readiness-scopes.php';
$c='MAD4B_SCP_Readiness_Scopes';
$integrations='MAD4B_SCP_Readiness_Integrations';
$items=[];
for($i=1;$i<=120;$i++){
 $id='aaaa'.str_pad(dechex($i),60,'0',STR_PAD_LEFT);
 $items[$id]=['id'=>$id,'status'=>'open','source'=>'original-readiness',
 'code'=>'missing_media','severity'=>'high','source_evidence'=>['language'=>'en'],
 'post_type'=>'post','object_id'=>$i,'label'=>'Fixture '.$i];
}
$GLOBALS['opts'][MAD4B_SCP_Content_Readiness_Alerts::STORE]=$items;
$scopes=$c::build();
$totals=array_map(static function($s){return $s['total'];},$scopes);
source_assert(array_sum($totals)===120,'All 120 original source issues remain in dynamic Work Scopes');
source_assert(max($totals)<=50,
 'The four-character SHA prefix collision may not produce an oversized >50 item work packet');
$batch=$c::batches($scopes);
source_assert(count($batch)===1 &&array_sum(array_column($batch,'issue_count'))===120,
 'Many bounded packets retain one original site/group/language/severity work batch');
$again=$c::build();
source_assert(array_keys($again)===array_keys($scopes),
 'Source issue order changes cannot create random packet identities');
$pre=$c::rest_digest_preview(new OriginalRequest(['batch_id'=>key($batch)]));
source_assert($pre['original_rest_response']['dry_run']===true
 &&$pre['original_rest_response']['hook_invoked']===false
 &&$pre['original_rest_response']['ready_for_external_delivery']===false,
 'Even configured Bitflows cannot show external delivery ready while original PreActivation is locked');
$blocked=$c::rest_notify_digest(new OriginalRequest(['batch_id'=>key($batch)]));
source_assert(is_wp_error($blocked)&&$blocked->get_error_code()==='readiness_preactivation_lock'
 &&!$GLOBALS['emissions'],
 'Original work batch POST cannot bypass PreActivation to emit email');
$scope_id=key($scopes);
$blocked_sync=$c::rest_sync(new OriginalRequest(['scope_id'=>$scope_id]));
source_assert(is_wp_error($blocked_sync)&&!$GLOBALS['emissions'],
 'Original task sync cannot invoke Fluent Boards while preactivation locked');
$integrations::routes();
source_assert(isset($GLOBALS['routes']['mad4b-content-readiness/v1/admin/integrations/dispatch'])
 &&$GLOBALS['routes']['mad4b-content-readiness/v1/admin/integrations/dispatch']['methods']==='POST',
 'Original integration registration offers a separately governed, never public mutation route');
source_assert(is_wp_error($integrations::dispatch_event(null)),
 'Retired per-issue outbound dispatch cannot accidentally send an external message');
$GLOBALS['emissions']=[];
$selected=current($items);$id=$selected['id'];
$first=$integrations::record_changes([],[$id=>$selected]);
source_assert($first['baseline_created']===true &&!$GLOBALS['emissions'],
 'Fresh install takes baseline without replaying historical source issues');
$selected=current($items);$id=$selected['id'];
$record=$integrations::record_changes([],[$id=>$selected]);
source_assert($record['recorded']===1 &&$record['emitted']===0
 &&count($GLOBALS['emissions'])===1
 &&$GLOBALS['emissions'][0][0]==='mad4b/readiness/events_recorded',
 'Original source transition is journaled locally, never a direct outbound notification');
$duplicate=$integrations::record_changes([],[$id=>$selected]);
source_assert($duplicate['recorded']===0&&count($GLOBALS['emissions'])===1,
 'Repeated source transition cannot duplicate an alert in the same revision');
echo 'PASS: READINESS_SCOPES_EVENTS '.($GLOBALS['cases']??0).
 ' original collision, cohort, event-journal and delivery-denial cases'.PHP_EOL;

<?php
/** Exercise actual sealed checkpoint/CAS and provider traversal across many requests. */
define('ABSPATH',__DIR__.'/');
class WP_Error { private $code; function __construct($c,$m=''){ $this->code=$c; } function get_error_code(){return $this->code;} }
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($x,$f=0){return json_encode($x,$f);}
function wp_salt($x){return 'dedicated-test-only-secret-not-a-real-key';}
function maybe_serialize($x){return $x;}
function wp_cache_delete($x,$g){}
$GLOBALS['store']=array();
function get_option($n,$d=null){return $GLOBALS['store'][$n]??$d;}
function add_option($n,$v,$d='',$a=false){if(isset($GLOBALS['store'][$n]))return false;$GLOBALS['store'][$n]=$v;return true;}
class ScanDB {
 public $options='wp_options'; public $fail=false;
 function prepare($q,...$args){return $args;}
 function query($args){if($this->fail)return 0;list($next,$name,$expected)=$args;if(($GLOBALS['store'][$name]??null)!==$expected)return 0;$GLOBALS['store'][$name]=$next;return 1;}
}
$GLOBALS['wpdb']=new ScanDB;
class MAD4B_SCP_Distributed_Lock {
 static $held=false; static $lost=false;
 static function catalog_name($n){return $n;}
 static function acquire($n){if(self::$held)return new WP_Error('busy');self::$held=true;return true;}
 static function owns($n){return self::$held&&!self::$lost;}
 static function release($n){self::$held=false;}
}
class MAD4B_SCP_Audit {static $ok=true;static $events=array();static function record($n,$d){self::$events[]=$d;return self::$ok;}}
require dirname(__DIR__).'/includes/class-mad4b-scp-drive-scan-continuation.php';
function assert_scan($v,$m){if(!$v)throw new RuntimeException($m);}
$root=array('id'=>'folder-root','name'=>'Brand');$scope=array('checkpoint_owner'=>'site-brand-actor-source','integrity'=>'site-brand-actor-a','source'=>'root-recursive-policy-a','grant'=>'account-a','registry_revision'=>6);
$changed=false;$scope_valid=true;$mode='normal';$normalizations=0;$listings=0;
$provider=static function($action,$data)use(&$changed,&$scope_valid,&$mode,&$normalizations,&$listings){
 if('begin'===$action)return 'private-google-change-token';
 if('verify'===$action)return !$changed;
 if('revalidate'===$action)return $scope_valid;
 if('normalize'===$action){++$normalizations;if('normalize_timeout'===$mode)return new WP_Error('mad4b_google_drive_scan_time_budget_exhausted');return array('file_id'=>$data['id'],'normalized_text'=>'private Brand draft '.$data['id'],'content_complete'=>'incomplete'!==$mode,'content_hash'=>hash('sha256',$data['id']));}
 ++$listings;if('list_timeout'===$mode)return new WP_Error('mad4b_google_drive_scan_time_budget_exhausted');
 $files=array();$offset=''===$data['token']?0:10;
 if('folder-root'===$data['id']){
  if(0===$offset)$files[]=array('id'=>'subfolder','name'=>'Sub','mimeType'=>'application/vnd.google-apps.folder','parents'=>array('folder-root'));
  for($i=$offset;$i<$offset+10;++$i)$files[]=array('id'=>'file-'.$i,'name'=>'Doc '.$i,'mimeType'=>'text/plain','parents'=>array('folder-root'));
 }else for($i=20;$i<25;++$i)$files[]=array('id'=>'file-'.$i,'name'=>'Doc '.$i,'mimeType'=>'text/plain','parents'=>array('subfolder'));
 if('duplicate'===$mode)$files[]=$files[1];
 if('foreign'===$mode)$files[1]['parents']=array('outside-source');
 if('shortcut'===$mode)$files[1]['mimeType']='application/vnd.google-apps.shortcut';
 return array('files'=>$files,'nextPageToken'=>'folder-root'===$data['id']&&0===$offset?'private-page-token':'');
};
$c='MAD4B_SCP_Drive_Scan_Continuation';
$first=$c::run($scope,$root,true,'',false,$provider);
assert_scan(is_array($first)&&!$first['complete'],'initial page must be incomplete');
$before=$normalizations;$replay=$c::run($scope,$root,true,'',false,$provider);
assert_scan($replay===$first&&$normalizations===$before,'duplicate start changed progress');
$prior=$first;$steps=0;
while(!$prior['complete']&&$steps++<40){
 $next=$c::run($scope,$root,true,$prior['scan_cursor'],false,$provider);
 assert_scan(is_array($next),'normal continuation failed');
 $before=$normalizations;$same=$c::run($scope,$root,true,$prior['scan_cursor'],false,$provider);
 assert_scan($same===$next&&$before===$normalizations,'page replay duplicated normalization');
 $prior=$next;
}
assert_scan($prior['complete']&&25===count($prior['assets'])&&25===$normalizations,'multi-folder 25-file scan did not complete exactly once');
$final=$c::run($scope,$root,true,$prior['scan_cursor'],true,$provider);
assert_scan($final===$prior,'final verified inventory changed');
$raw=$GLOBALS['store'][$c::option_name($scope)];
assert_scan(false===strpos($raw,'private Brand')&&false===strpos($raw,'private-page-token')&&false===strpos($raw,'private-google-change-token'),'checkpoint leaked content or provider tokens');
assert_scan(false===strpos(json_encode(MAD4B_SCP_Audit::$events),'private'),'audit leaked checkpoint bodies/tokens');
foreach(array('integrity','source','grant','registry_revision')as$key){$other=$scope;$other[$key].='changed';assert_scan(is_wp_error($c::run($other,$root,true,$prior['scan_cursor'],true,$provider)),'cross-site/brand/actor/source/grant/registry resume admitted');}
$changed=true;
assert_scan(is_wp_error($c::run($scope,$root,true,$prior['scan_cursor'],true,$provider)),'provider change admitted final inventory');
assert_scan(is_wp_error($c::run($scope,$root,true,'',false,$provider)),'empty cursor hid provider changes in complete readback');$changed=false;
$scope_valid=false;assert_scan(is_wp_error($c::run($scope,$root,true,$prior['scan_cursor'],true,$provider)),'scope revocation during provider call admitted');$scope_valid=true;
assert_scan(is_wp_error($c::run($scope,$root,true,$first['scan_cursor'],false,$provider)),'stale non-last cursor admitted');
assert_scan(is_wp_error($c::run($scope,$root,true,str_repeat('0',100),false,$provider)),'forged cursor admitted');
$GLOBALS['store'][$c::option_name($scope)]=$raw.'tampered';
assert_scan(is_wp_error($c::run($scope,$root,true,$prior['scan_cursor'],true,$provider)),'tampered ciphertext admitted');
$GLOBALS['store']=array();assert_scan(is_wp_error($c::run($scope,$root,true,$prior['scan_cursor'],true,$provider)),'evicted checkpoint accepted cursor');
foreach(array('duplicate','foreign','shortcut','list_timeout')as$mode){$GLOBALS['store']=array();$result=$c::run($scope,$root,true,'',false,$provider);assert_scan(is_wp_error($result)&&!$GLOBALS['store'],'unsafe provider page persisted');}
$mode='normal';$GLOBALS['store']=array();$result=$c::run($scope,$root,true,'',false,$provider);$baseline=$GLOBALS['store'];
foreach(array('incomplete','normalize_timeout')as$mode){assert_scan(is_wp_error($c::run($scope,$root,true,$result['scan_cursor'],false,$provider))&&$baseline===$GLOBALS['store'],'incomplete/failed content advanced checkpoint');}
$mode='normal';$GLOBALS['wpdb']->fail=true;
assert_scan(is_wp_error($c::run($scope,$root,true,$result['scan_cursor'],false,$provider))&&$baseline===$GLOBALS['store'],'CAS failure advanced checkpoint');$GLOBALS['wpdb']->fail=false;
MAD4B_SCP_Audit::$ok=false;
assert_scan(is_wp_error($c::run($scope,$root,true,$result['scan_cursor'],false,$provider))&&$baseline===$GLOBALS['store'],'audit failure advanced checkpoint');MAD4B_SCP_Audit::$ok=true;
MAD4B_SCP_Distributed_Lock::$lost=true;
assert_scan(is_wp_error($c::run($scope,$root,true,$result['scan_cursor'],false,$provider))&&$baseline===$GLOBALS['store'],'lost lock advanced checkpoint');MAD4B_SCP_Distributed_Lock::$lost=false;
MAD4B_SCP_Distributed_Lock::$held=true;assert_scan(is_wp_error($c::run($scope,$root,true,$result['scan_cursor'],false,$provider)),'concurrent request entered held checkpoint');MAD4B_SCP_Distributed_Lock::$held=false;
// Re-seal a simulated expired generation with the same real crypto envelope.
$open=new ReflectionMethod($c,'open');$open->setAccessible(true);$seal=new ReflectionMethod($c,'seal');$seal->setAccessible(true);$digest=new ReflectionMethod($c,'digest');$digest->setAccessible(true);
$sha=$digest->invoke(null,$scope);$state=$open->invoke(null,$GLOBALS['store'][$c::option_name($scope)],$sha);$state['expires_at']=time()-1;
$GLOBALS['store'][$c::option_name($scope)]=$seal->invoke(null,$state,$sha);
assert_scan(is_wp_error($c::run($scope,$root,true,$result['scan_cursor'],false,$provider)),'expired checkpoint admitted continuation');
echo "DRIVE_SCAN_CONTINUATION: PASS; sealed multi-request 25-file fixture, not live certification\n";
// Explicit current-generation restart replaces only encrypted diagnostic state.
$GLOBALS['store']=array();$mode='normal';$fresh=$c::run($scope,$root,true,'',false,$provider);
assert_scan(is_wp_error($c::run($scope,$root,true,'forged',false,$provider,true)),'restart accepted a foreign cursor');
$restart=$c::run($scope,$root,true,$fresh['scan_cursor'],false,$provider,true);
assert_scan(is_array($restart)&&$restart['scan_generation']!==$fresh['scan_generation'],'restart did not establish a new generation');
assert_scan(is_wp_error($c::run($scope,$root,true,$fresh['scan_cursor'],false,$provider)),'superseded generation remained resumable');
assert_scan(1===preg_match('/^[a-f0-9]{64}$/D',$restart['scan_generation']),'scan generation does not match original Context receipt format');
// Real planner/apply and native controller consume the same sealed final result.
function sanitize_text_field($x){return (string)$x;}
class MAD4B_SCP_Context_Authority {
 static $revision=6;static $writes=0;
 static function source($id){return array('source_id'=>$id,'provider'=>'google_drive');}
 static function assets(){return array();}
 static function registry_revision(){return self::$revision;}
 static function replace_source_assets($source,$assets,$scan){if($scan['expected_registry_revision']!==self::$revision)return new WP_Error('stale');++self::$revision;++self::$writes;return array('count'=>count($assets));}
}
class MAD4B_SCP_Context_Provider_Gateway {
 static function scan_source($source,$input=array()){
  $scope=$GLOBALS['scope'];$scope['registry_revision']=MAD4B_SCP_Context_Authority::$revision;
  return MAD4B_SCP_Drive_Scan_Continuation::run($scope,$GLOBALS['root'],true,$input['scan_cursor']??'',!empty($input['final_only']),$GLOBALS['provider'],!empty($input['restart_scan']));
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-brand-context-builder.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-drive-scan-admin.php';
$GLOBALS['store']=array();$id=str_repeat('a',64);
$plan=MAD4B_SCP_Drive_Scan_Admin::step(array('source_id'=>$id));$steps=0;
while(!$plan['scan_complete']&&$steps++<40)$plan=MAD4B_SCP_Brand_Context_Builder::source_scan_plan(array('source_id'=>$id,'scan_cursor'=>$plan['continuation']['scan_cursor']));
assert_scan($plan['scan_complete']&&0===MAD4B_SCP_Context_Authority::$writes,'planning mutated Context or failed finalization');
$input=array('source_id'=>$id,'scan_operation'=>'apply','scan_cursor'=>$plan['continuation']['scan_cursor'],
 'expected_plan_sha256'=>$plan['plan_sha256'],'expected_provider_inventory_digest'=>$plan['provider_inventory_digest'],'expected_registry_revision'=>6);
assert_scan(is_wp_error(MAD4B_SCP_Drive_Scan_Admin::step($input))&&0===MAD4B_SCP_Context_Authority::$writes,'native unconfirmed Apply wrote assets');
$input['confirm_complete_scan']='1';$forged=$input;$forged['expected_plan_sha256']=str_repeat('0',64);
assert_scan(is_wp_error(MAD4B_SCP_Drive_Scan_Admin::step($forged))&&0===MAD4B_SCP_Context_Authority::$writes,'native forged plan wrote assets');
$applied=MAD4B_SCP_Drive_Scan_Admin::step($input);
assert_scan(!is_wp_error($applied)&&25===$applied['count']&&1===MAD4B_SCP_Context_Authority::$writes,'exact finalized inventory did not reach original Apply');
assert_scan(is_wp_error(MAD4B_SCP_Brand_Context_Builder::source_scan_apply($input))&&1===MAD4B_SCP_Context_Authority::$writes,'post-commit registry change did not invalidate checkpoint replay');
echo "DRIVE_SCAN_PLAN_APPLY_PARITY: PASS; exact plan/registry and native confirmation\n";

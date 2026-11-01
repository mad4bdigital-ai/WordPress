<?php
// Isolated company code reconciliation Core contract. No WP site, filesystem mutation or network.
define( 'ABSPATH', '/isolated/' );
class WP_Error {
 public $code;
 public function __construct($code,$message){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
$GLOBALS['hooks']=array();$GLOBALS['abilities']=array();
function add_action($hook,$callback,$priority){$GLOBALS['hooks'][$hook]=$callback;}
function wp_has_ability($name){return isset($GLOBALS['abilities'][$name]);}
function wp_register_ability($name,$args){$GLOBALS['abilities'][$name]=$args;return true;}
class MAD4B_SCP_Execution_Receipt {
 public static function verify($receipt){
   if(($receipt['test_authentic']??false)!==true)return new WP_Error('bad_signature','');
   return array('valid'=>true,'cryptographic_signature_verified'=>true);
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-company-code-reconciliation.php';
$c='MAD4B_SCP_Company_Code_Reconciliation';
$c::boot();
call_user_func($GLOBALS['hooks']['wp_abilities_api_init']);
foreach(array($c::PLAN,$c::COMPARE) as $ability){
 $item=$GLOBALS['abilities'][$ability]??null;
 if(!is_array($item)||($item['meta']['annotations']['readonly']??null)!==true||
    ($item['permission_callback'][1]??'')!=='can_read'){
    throw new RuntimeException('read_permission_or_ability_missing');
 }
}
function canon($a){
 if(!is_array($a))return $a;
 $keys=array_keys($a);
 $list=empty($a)||$keys===range(0,count($a)-1);
 if($list)return array_map('canon',$a);
 ksort($a,SORT_STRING);
 foreach($a as $k=>$v)$a[$k]=canon($v);
 return $a;
}
function evidence($site,$uuid,$files){
 ksort($files,SORT_STRING);
 $manifest=array(
 'contract'=>'mad4b.company-code-inventory-page.v1',
 'company_id'=>'company-01','site_uuid'=>$uuid,'origin'=>$site,'environment'=>'staging',
 'page_index'=>0,'page_count'=>1,'inventory_sha256'=>str_repeat('a',64),'files'=>$files);
 $receipt=array('ability'=>'mad4b/company-code-inventory-page',
 'provider_id'=>'central_company_management','approval_required'=>false,
 'resource_set_sha256'=>hash('sha256',json_encode(canon($manifest),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
 'target_fingerprint'=>hash('sha256','company-01|'.$uuid.'|'.$site),'test_authentic'=>true);
 return array('manifest'=>$manifest,'receipt'=>$receipt);
}
$src=evidence('https://tours.dev.mad4b.com','source-uuid-00001',array(
 'plugins/mad4b-bridge/main.php'=>array('sha256'=>str_repeat('a',64),'owner'=>'shared_candidate'),
 'plugins/vendor/start.php'=>array('sha256'=>str_repeat('b',64),'owner'=>'vendor_owned'),
 'mu-plugins/mad4b-company-owner.php'=>array('sha256'=>str_repeat('c',64),'owner'=>'protected'),
));
$dst=evidence('https://staging.egypttourgates.com','target-uuid-00001',array(
 'plugins/mad4b-bridge/main.php'=>array('sha256'=>str_repeat('d',64),'owner'=>'shared_candidate'),
 'plugins/vendor/start.php'=>array('sha256'=>str_repeat('b',64),'owner'=>'vendor_owned'),
 'mu-plugins/mad4b-company-owner.php'=>array('sha256'=>str_repeat('c',64),'owner'=>'protected'),
));
$input=array('company_id'=>'company-01','source'=>$src,'target'=>$dst);
$plan=$c::plan($input);$diff=$c::compare($input);
if(is_wp_error($plan)||is_wp_error($diff)||$plan['complete_inventory_verified']!==false||
   $diff['presence']['different']!==1||$diff['presence']['exact']!==2||
   $diff['native_apply_authorized']!==false||$diff['writes_to_sites']!==false){
 throw new RuntimeException('real_diff_fixture_failed');
}
$decisions=array();
foreach($diff['diff_rows'] as $row)$decisions[$row['path']]=$row['decision'];
if($decisions['mu-plugins/mad4b-company-owner.php']!=='PROTECT_READ_ONLY' ||
   $decisions['plugins/vendor/start.php']!=='VENDOR_LIFECYCLE_ONLY' ||
   $decisions['plugins/mad4b-bridge/main.php']!=='PLAN_PROVIDER_SEMANTIC_DIFF'){
 throw new RuntimeException('ownership_decision_wrong');
}
function refused($result){if(!is_wp_error($result))throw new RuntimeException('unsafe_input_accepted');}
$bad=$input;$bad['company_id']='company-02';refused($c::compare($bad));
$bad=$input;$bad['target']['receipt']['resource_set_sha256']=str_repeat('f',64);refused($c::compare($bad));
$bad=$input;$bad['target']['receipt']['test_authentic']=false;refused($c::compare($bad));
$bad=$input;$bad['target']['receipt']['ability']='mad4b/unrelated-write';refused($c::compare($bad));
$bad=$input;$bad['target']['manifest']['site_uuid']=$src['manifest']['site_uuid'];
$bad['target']['receipt']['target_fingerprint']=hash('sha256','company-01|'.$src['manifest']['site_uuid'].'|'.$bad['target']['manifest']['origin']);
refused($c::compare($bad));
$bad=$input;$bad['target']['manifest']['files']['plugins/../wp-config.php']=array('sha256'=>str_repeat('a',64),'owner'=>'protected');
refused($c::compare($bad));
$bad=$input;$bad['target']['manifest']['environment']='production';refused($c::compare($bad));
$bad=$input;$bad['authorized']=true;refused($c::compare($bad));
echo "PASS real SHA diff + ownership decisions, original Core signature boundary, cross-company/path/production/injection refusal\n";

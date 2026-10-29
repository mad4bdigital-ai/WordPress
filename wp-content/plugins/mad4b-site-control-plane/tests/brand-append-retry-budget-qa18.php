<?php
define('ABSPATH', __DIR__);
class WP_Error { private $code; function __construct($code,$message=''){ $this->code=$code; } function get_error_code(){return $this->code;} }
function is_wp_error($v){ return $v instanceof WP_Error; }
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function home_url($path=''){return 'https://qa18.staging.example/'.$path;}
function wp_json_encode($data,$flags=0){return json_encode($data,$flags);}
class MAD4B_SCP_Environment{static function effective(){return 'staging';}}
$opts=[];
function get_option($key,$default=false){global $opts;return array_key_exists($key,$opts)?$opts[$key]:$default;}
function add_option($key,$value,$deprecated='',$autoload=false){global $opts;if(array_key_exists($key,$opts))return false;$opts[$key]=$value;return true;}
function update_option($key,$value,$autoload=false){global $opts;if(!isset($opts[$key])||$opts[$key]===$value)return false;$opts[$key]=$value;return true;}
function delete_option($key){global $opts;if(!array_key_exists($key,$opts))return false;unset($opts[$key]);return true;}
function current_user_can($cap){return false;}
require __DIR__.'/../includes/class-mad4b-scp-recovery-attempt-budget.php';
function qa($yes,$msg){if(!$yes){fwrite(STDERR,"FAIL ".$msg."\n");exit(1);}}
$input=['category'=>'tone_of_voice','content'=>'content A','expected_plan_sha256'=>str_repeat('a',64),'evidence_digest'=>str_repeat('b',64),'draft_preflight_sha256'=>str_repeat('c',64)];
$lane='brand_draft_append';
$before=MAD4B_SCP_Recovery_Attempt_Budget::status($lane,$input);
qa(!is_wp_error($before) && $before['attempts_reserved']===0,'append lane declared and empty');
$r=MAD4B_SCP_Recovery_Attempt_Budget::reserve($lane,$input);
qa(!is_wp_error($r)&&$r['attempt']===1,'first append reservation');
$pending=MAD4B_SCP_Recovery_Attempt_Budget::status($lane,$input);
qa(!$pending['retry_eligible'] && $pending['last_outcome']==='pending','pending cannot retry');
qa(MAD4B_SCP_Recovery_Attempt_Budget::finish($r,'succeeded')===true,'finish succeeded');
$success=MAD4B_SCP_Recovery_Attempt_Budget::status($lane,$input);
qa($success['attempts_reserved']===1&&!$success['retry_eligible'],'success cannot retry');
$repeat=MAD4B_SCP_Recovery_Attempt_Budget::reserve($lane,$input);
qa(is_wp_error($repeat)&&$repeat->get_error_code()==='mad4b_retry_prior_not_reconciled','duplicate cannot re-reserve');
$changed=$input;$changed['draft_preflight_sha256']=str_repeat('d',64);
$independent=MAD4B_SCP_Recovery_Attempt_Budget::status($lane,$changed);
qa(!is_wp_error($independent) && $independent['attempts_reserved']===0 && $independent['scope_sha256']!==$success['scope_sha256'],'preflight scoped separately');
$changed=$input;$changed['evidence_digest']=str_repeat('d',64);
$e=MAD4B_SCP_Recovery_Attempt_Budget::status($lane,$changed);
qa(!is_wp_error($e)&&$e['scope_sha256']!==$success['scope_sha256'],'evidence bound');
foreach(['draft_preflight_sha256','evidence_digest'] as $required){
 $missing=$input;unset($missing[$required]);
 $result=MAD4B_SCP_Recovery_Attempt_Budget::status($lane,$missing);
 qa(is_wp_error($result)&&$result->get_error_code()==='mad4b_retry_binding_missing','missing '.$required.' rejected');
}
$create=MAD4B_SCP_Recovery_Attempt_Budget::status('brand_draft_create',$input);
qa(!is_wp_error($create)&&$create['attempts_reserved']===0,'create lane unchanged');
$basis=['contract'=>MAD4B_SCP_Recovery_Attempt_Budget::CONTRACT,'site'=>home_url('/'),'environment'=>'staging','lane'=>'brand_draft_create','category'=>'tone_of_voice','source'=>'','artifact'=>'','expected_plan_sha256'=>str_repeat('a',64)];
qa($create['scope_sha256']===hash('sha256',json_encode($basis,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),'legacy create journal stable');
$mat=MAD4B_SCP_Recovery_Attempt_Budget::status('materialize_brand_draft',['artifact_id'=>'00000000-0000-4000-8000-000000000000']);
qa(!is_wp_error($mat),'materialize lane retained');
$reset=MAD4B_SCP_Recovery_Attempt_Budget::reset($lane,$input,$success['journal_sha256'],true);
qa(is_wp_error($reset)&&$reset->get_error_code()==='mad4b_retry_reset_authority_required','admin gate retained');
qa(MAD4B_SCP_Recovery_Attempt_Budget::LIMIT===3,'retry bound retained');
$adapter_source=file_get_contents(__DIR__.'/../includes/adapters/class-mad4b-scp-context-adapter.php');
$schema_start=strrpos($adapter_source,"'context/recovery-attempt-status',");
$schema_end=strpos($adapter_source,"'context/google-drive-status',",$schema_start);
qa(false!==$schema_start&&false!==$schema_end&&$schema_end>$schema_start,'recovery read ability discoverable');
$schema_block=substr($adapter_source,$schema_start,$schema_end-$schema_start);
foreach(['draft_preflight_sha256','evidence_digest'] as $required){
 qa(false!==strpos($schema_block,"'".$required."' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' )"),'append retry read schema exposes '.$required);
}
echo "QA18_RETRY_BUDGET_PASS\n";

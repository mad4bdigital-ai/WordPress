<?php
define('ABSPATH','/var/www/html/');
$GLOBALS['authorized']=true;
$GLOBALS['abilities']=[];
$GLOBALS['hooks']=[];
function add_action($name,$callback,$priority=10){$GLOBALS['hooks'][$name][]=['callback'=>$callback,'priority'=>$priority];}
$GLOBALS['profile']=[
  'configured'=>true,'origin_match'=>true,
  'environment_match'=>true,'deployment_binding_match'=>true,
  'site_uuid'=>'12345678-1234-4123-8123-123456789abc',
  'environment'=>'staging','canonical_origin'=>'https://tours.dev.mad4b.com',
];
$GLOBALS['test_options']=[];
$GLOBALS['read_error']=false;
function current_user_can($cap){return $GLOBALS['authorized'] && $cap==='manage_options';}
function wp_get_environment_type(){return 'staging';}
function home_url(){return 'https://tours.dev.mad4b.com';}
function wp_has_ability($name){return isset($GLOBALS['abilities'][$name]);}
function wp_register_ability($name,$config){$GLOBALS['abilities'][$name]=$config;}
function get_option($key,$default=false){return $GLOBALS['test_options'][$key]??$default;}
class WP_Error {
 public $code;
 public function __construct($code,$text){$this->code=$code;}
}
class MAD4B_SCP_Site_Profile {
 public static function status(){return $GLOBALS['profile'];}
}
class FakeDB {
 public $options='wp_options';
 public $last_error='';
 public $results=null;
 public function prepare($sql,...$params){
  if(count($params)!==2 || $params[1]!==1001)throw new Exception('NOT_BOUNDED');
  return $sql;
 }
 public function esc_like($value){return addcslashes($value,'%_');}
 public function get_col($sql){
  if($GLOBALS['read_error']){ $this->last_error='read denied';return null;}
  if(is_array($this->results))return $this->results;
  return array_values(array_filter(array_keys($GLOBALS['test_options']),function($key){
   return strpos($key,MAD4B_SCP_Original_Job_Readback::JOB_PREFIX)===0;
  }));
 }
}
$wpdb=new FakeDB;
require '/work/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-original-job-readback.php';
$cls='MAD4B_SCP_Original_Job_Readback';
$cls::boot();
$cls::boot();
if(count($GLOBALS['hooks']['wp_abilities_api_init']??[])!==1)throw new Exception('hook duplicated');
if($GLOBALS['hooks']['wp_abilities_api_init'][0]['priority']!==32)throw new Exception('read hook priority drift');
call_user_func($GLOBALS['hooks']['wp_abilities_api_init'][0]['callback']);
$cls::register();
if(count($GLOBALS['abilities'])!==1)throw new Exception('read ability duplicate');
$ability=$GLOBALS['abilities'][$cls::ABILITY];
if($ability['meta']['annotations']['readonly']!==true
   || $ability['meta']['public']!==false
   || $ability['meta']['show_in_rest']!==false)throw new Exception('read ability exposure');
$asserts=0;
function verify($ok,$label){global $asserts; $asserts++; if(!$ok)throw new Exception('FAIL:'.$label);}
function request($hash){
 return [
   'site_uuid'=>'12345678-1234-4123-8123-123456789abc',
   'company'=>'tours-central',
   'origin'=>'https://tours.dev.mad4b.com','environment'=>'staging',
   'command_sha256'=>[$hash]
 ];
}
function job($id,$hash,$state='QUEUED_FOR_VPS_BROKER_CLAIM'){
 return [
   'contract'=>'mad4b.central-vps-broker.v1',
   'job_id'=>$id,'command_sha256'=>$hash,
   'target_scope'=>'wordpress','job_type'=>'signed_vps_wpcli',
   'company'=>'tours-central','site_uuid'=>'12345678-1234-4123-8123-123456789abc',
   'environment'=>'staging','canonical_origin'=>'https://tours.dev.mad4b.com',
   'broker_host_id'=>'srv2030908','expires_epoch'=>time()+300,
   'state'=>$state,'executed'=>false,
 ];
}
$hash=str_repeat('a',64);
$id=str_repeat('b',32);
$jobkey=$cls::JOB_PREFIX.$id;
$GLOBALS['test_options'][$jobkey]=job($id,$hash);
$valid=$cls::read(request($hash));
verify(is_array($valid),'valid result');
verify($valid['state']==='READ_ONLY__ORIGINAL312_INDEPENDENT_SIGNATURE_REQUIRED','read-only result');
verify($valid['commands'][0]['job_count']===1,'one job');
verify($valid['origin']==='https://tours.dev.mad4b.com','origin bound');
verify(is_int($valid['observed_epoch']) && abs(time()-$valid['observed_epoch'])<=1,
 'bounded observation epoch');
verify($valid['commands'][0]['record']['claim_present']===false,'not claimed');
verify($valid['original312_eligible']===false,'not signing');
verify(!array_key_exists('job_id',$valid['commands'][0]['record']),'job id not leaked');
verify(!array_key_exists('grant_id',$valid['commands'][0]['record']),'grant id not leaked');
verify($valid['authority_created']===false && $valid['job_or_claim_mutated']===false,'no authority');

$claimkey=$cls::CLAIM_PREFIX.$id;
$routekey=$cls::ROUTE_PREFIX.$id;
$GLOBALS['test_options'][$jobkey]['state']='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY';
$GLOBALS['test_options'][$jobkey]['claimed_epoch']=12345;
$GLOBALS['test_options'][$claimkey]=[
 'contract'=>'mad4b.central-vps-broker.v1',
 'job_id'=>$id,'host_id'=>'srv2030908',
 'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
 'claimed_epoch'=>12345,'production_mutation_performed'=>false,
];
$GLOBALS['test_options'][$routekey]=['decision'=>'CLAIM'];
$claimed=$cls::read(request($hash));
verify($claimed['commands'][0]['record']['claim_exact']===true,'proper claim');
verify($claimed['commands'][0]['record']['route_reserved']===true,'route reserved');

$GLOBALS['test_options'][$claimkey]['claimed_epoch']=12346;
$badclaim=$cls::read(request($hash));
verify($badclaim['commands'][0]['record']['claim_exact']===false,'drift detected');
$GLOBALS['test_options'][$claimkey]['claimed_epoch']=12345;
$GLOBALS['test_options'][$claimkey]['contract']='untrusted';
verify($cls::read(request($hash))['commands'][0]['record']['claim_exact']===false,
 'invalid claim contract');
$GLOBALS['test_options'][$claimkey]['contract']='mad4b.central-vps-broker.v1';

$badsite=request($hash);
$badsite['origin']='https://foreign.invalid';
verify($cls::read($badsite) instanceof WP_Error,'foreign origin reject');
$reordered=array_reverse(request($hash),true);
verify(is_array($cls::read($reordered)),'order-agnostic key set');
$badcompany=request($hash);$badcompany['company']='different-company';
$companyresult=$cls::read($badcompany);
verify(is_array($companyresult) && $companyresult['commands'][0]['conflicting_scope_count']===1,
 'company-bound command scope');
$badcompany=request($hash);$badcompany['company']='../../bad';
verify($cls::read($badcompany) instanceof WP_Error,'reject invalid company');
$extra=request($hash);$extra['enable_write']=true;
verify($cls::read($extra) instanceof WP_Error,'reject extra authority key');
$invalid=request($hash);$invalid['command_sha256']=['not-a-sha'];
verify($cls::read($invalid) instanceof WP_Error,'reject invalid digest');
$duplicate=request($hash);$duplicate['command_sha256']=[$hash,$hash];
verify($cls::read($duplicate) instanceof WP_Error,'reject repeated digest');
$too_many=request($hash);$too_many['command_sha256']=array_fill(0,25,$hash);
verify($cls::read($too_many) instanceof WP_Error,'reject overlimit');

$GLOBALS['authorized']=false;
verify($cls::read(request($hash)) instanceof WP_Error,'role denied');
$GLOBALS['authorized']=true;
$GLOBALS['profile']['deployment_binding_match']=false;
verify($cls::read(request($hash)) instanceof WP_Error,'unverified source site');
$GLOBALS['profile']['deployment_binding_match']=true;

$wpdb->results=array_fill(0,1001,$jobkey);
verify($cls::read(request($hash)) instanceof WP_Error,'truncated DB reject');
$wpdb->results=[$cls::JOB_PREFIX.'not-valid'];
verify($cls::read(request($hash)) instanceof WP_Error,'invalid stored job key');
$wpdb->results=null;
$GLOBALS['read_error']=true;
verify($cls::read(request($hash)) instanceof WP_Error,'DB error fail close');
$GLOBALS['read_error']=false;
$wpdb->last_error='';

$other_id=str_repeat('c',32);
$GLOBALS['test_options'][$cls::JOB_PREFIX.$other_id]=job($other_id,$hash);
$multiple=$cls::read(request($hash));
verify($multiple['commands'][0]['job_count']===2,'duplicate job detected');
verify($multiple['commands'][0]['record']===null,'ambiguous job not disclosed');
unset($GLOBALS['test_options'][$cls::JOB_PREFIX.$other_id]);

$GLOBALS['test_options'][$jobkey]['target_scope']='host';
$nonwp=$cls::read(request($hash));
verify($nonwp['commands'][0]['conflicting_scope_count']===1,
 'non-wordpress job cannot satisfy plan readback');
$GLOBALS['test_options'][$jobkey]['target_scope']='wordpress';
$GLOBALS['test_options'][$jobkey]['site_uuid']='00000000-0000-4000-8000-000000000000';
$foreign=$cls::read(request($hash));
verify($foreign['commands'][0]['conflicting_scope_count']===1,'foreign scope collision');
verify($foreign['commands'][0]['record']===null,'foreign job hidden');

echo "PASS first-party job/claim READ ONLY candidate: ".$asserts." assertions; no grants or WordPress mutations\n";

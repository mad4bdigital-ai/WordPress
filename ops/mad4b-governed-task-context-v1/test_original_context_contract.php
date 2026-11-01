<?php
declare(strict_types=1);
define('ABSPATH','/tmp/');
class WP_Error {
 private string $code;
 public function __construct($c,$m='',$meta=[]){$this->code=$c;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v,$o=0){return json_encode($v,$o);}
class MAD4B_Content_Hub {public static bool $grant=true;public static function permission(){return self::$grant;}}
class MAD4B_SCP_Policy {public static bool $grant=true;public static function can_mutate(){return self::$grant;}}
class MAD4B_Hub_Workflow_Origin {public static function executor_guard($task,$executor){return true;}}
class MAD4B_Hub_Bulk_Jobs_Queue {public static function finish($input){return ['acknowledged'=>true];}}
class MAD4B_Hub_Workflow_Operations {public static function inspect($input){return ['job_revision'=>3];}}
class MAD4B_SCP_Content_Jobs {
 public static array $job=[
  'job_id'=>'11111111-1111-4111-8111-111111111111','site_uuid'=>'22222222-2222-4222-8222-222222222222',
  'tenant_id'=>'original-tenant','brand_id'=>'original-brand','stage'=>'INFORMATION_GAIN',
  'job_revision'=>3,'state'=>'RUNNING','content_type'=>'research','language'=>'en','country'=>'eg',
  'writer_profile_version'=>2,'current_artifact_id'=>'artifact-1'];
 public static function get_job($input){return ['job'=>self::$job];}
}
require __DIR__.'/plugin/includes/class-mad4b-hub-original-context-evidence.php';
function check($v,$m){if(!$v)throw new RuntimeException('FAIL '.$m);echo 'PASS '.$m.PHP_EOL;}
function task($op='workflow_inspect'){
 return ['job_id'=>MAD4B_SCP_Content_Jobs::$job['job_id'],'stage'=>'INFORMATION_GAIN',
  'operation'=>$op,'target_ref'=>'qa:original','payload'=>[
  'content_job_revision'=>3,'assigned_executor'=>'mcp_client','stage_input'=>[]]];
}
$base=['items'=>[task()]];$b=MAD4B_Hub_Original_Context_Evidence::bind_batch($base);
check(!is_wp_error($b),'trusted original readback certificates created');
$p=$b['items'][0]['payload'];
check(strlen($p['source_snapshot_sha256'])===64&&strlen($p['policy_snapshot_sha256'])===64&&strlen($p['capability_snapshot_sha256'])===64,'three versioned original digests');
check(MAD4B_Hub_Original_Context_Evidence::verify($b['items'][0])===true,'independent original re-read accepts unchanged source');
$second=MAD4B_Hub_Original_Context_Evidence::bind_batch(['items'=>[task()]]);
check($second['items'][0]['payload']['source_snapshot_sha256']===$p['source_snapshot_sha256'],'repeatable authoritative source digest');
$t=task();$t['payload']['source_snapshot_sha256']=str_repeat('f',64);
check(is_wp_error(MAD4B_Hub_Original_Context_Evidence::bind_batch(['items'=>[$t]])),'caller-supplied source digest denied');
$t=$b['items'][0];$t['payload']['policy_snapshot_sha256']=str_repeat('a',64);
check(is_wp_error(MAD4B_Hub_Original_Context_Evidence::verify($t)),'forged policy digest denied');
$t=$b['items'][0];$t['payload']['capability_snapshot_sha256']=str_repeat('a',64);
check(is_wp_error(MAD4B_Hub_Original_Context_Evidence::verify($t)),'forged capability digest denied');
$t=$b['items'][0];$t['payload']['source_snapshot_sha256']=str_repeat('a',64);
check(is_wp_error(MAD4B_Hub_Original_Context_Evidence::verify($t)),'forged source digest denied');
$t=$b['items'][0];$t['payload']['effect_type']='publish';
check(is_wp_error(MAD4B_Hub_Original_Context_Evidence::verify($t)),'effect escalation denied');
$t=$b['items'][0];$t['payload']['assigned_executor']='vps_runner';
check(is_wp_error(MAD4B_Hub_Original_Context_Evidence::verify($t)),'executor lane rebind denied');
MAD4B_SCP_Content_Jobs::$job['current_artifact_id']='artifact-2';
check(is_wp_error(MAD4B_Hub_Original_Context_Evidence::verify($b['items'][0])),'original source artifact drift denies claim');
MAD4B_SCP_Content_Jobs::$job['current_artifact_id']='artifact-1';
MAD4B_SCP_Content_Jobs::$job['job_revision']=4;
check(is_wp_error(MAD4B_Hub_Original_Context_Evidence::verify($b['items'][0])),'original job revision drift denies claim');
MAD4B_SCP_Content_Jobs::$job['job_revision']=3;
$u=task('stage_work');
check(is_wp_error(MAD4B_Hub_Original_Context_Evidence::bind_batch(['items'=>[$u]])),'commercial/unverified stage cannot self-certify');
$u=task('content_job_read');
$cert=MAD4B_Hub_Original_Context_Evidence::bind_batch(['items'=>[$u]]);
check(!is_wp_error($cert)&&MAD4B_Hub_Original_Context_Evidence::verify($cert['items'][0])===true,'original content_job_read supported');
MAD4B_SCP_Policy::$grant=false;
check(is_wp_error(MAD4B_Hub_Original_Context_Evidence::verify($cert['items'][0])),'policy authorization withdrawal denies claim');
MAD4B_SCP_Policy::$grant=true;
MAD4B_Content_Hub::$grant=false;
check(is_wp_error(MAD4B_Hub_Original_Context_Evidence::verify($cert['items'][0])),'content hub authority withdrawal denies claim');
echo 'PASS 15 original source context contract assertions'.PHP_EOL;

<?php
/** Offline safeguard for original job transition policy; no WordPress writes. */
define('ABSPATH','/offline');
function untrailingslashit($s){return rtrim((string)$s,'/');}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
$env='staging';
$home='https://tours.dev.mad4b.com';
$policy=null;
function get_option($name,$default=null){
 global $home,$policy;
 if($name==='home')return $home;
 if($name==='mad4b_translation_execution_policy_v1')return $policy;
 return $default;
}
function wp_get_environment_type(){global $env;return $env;}
function add_action($hook,$fn,$priority=10,$args=1){return true;}
require dirname(__DIR__).'/wp-content/mu-plugins/423-mad4b-translation-execution-governance.php';
$cls=MAD4B_Translation_Execution_Governance::class;
$jobs=[];
for($i=1;$i<=42;$i++){
 $jobs['job-'.sprintf('%04d',$i)]=['language'=>$i%3===0?'fr':($i%3===1?'de':'ar'),
  'source_post_id'=>2000+$i];
}
$policy=['contract'=>$cls::CONTRACT,'site_uuid'=>$cls::SITE_UUID,
 'mode'=>'notification_only','resume_on'=>null,'resume_requires'=>'explicit_governed_admin_action',
 'jobs'=>$jobs,'jobs_sha256'=>hash('sha256',wp_json_encode($jobs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
function check($v,$label){if(!$v){fwrite(STDERR,"FAIL: $label\n");exit(2);}}
$entry=$jobs['job-0001'];
$row=['job_id'=>'job-0001','site_uuid'=>$cls::SITE_UUID,'content_type'=>'translation',
 'target_post_type'=>'tours-and-activities','language'=>$entry['language'],
 'subject'=>'Translate post #2001 for company','state'=>'QUEUED','stage'=>'INTAKE'];
check($cls::is_deferred_job($row),'matched original policy job is deferred');
check($cls::transition_denied($row,'PROCESSING','INTAKE',''),'cannot execute deferred translation');
check($cls::transition_denied($row,'QUEUED','READY',''),'cannot change deferred stage');
check($cls::transition_denied($row,'QUEUED','INTAKE','123'),'cannot attach arbitrary artifact');
check(!$cls::transition_denied($row,'QUEUED','INTAKE',''),'no-op existing state allowed');
$wrong=$row;$wrong['site_uuid']='different';
check(!$cls::transition_denied($wrong,'PROCESSING','INTAKE',''),'wrong-site job not under this policy');
$wrong=$row;$wrong['job_id']='unknown';
check(!$cls::transition_denied($wrong,'PROCESSING','INTAKE',''),'unlisted job not deferred');
$env='production';
check(!$cls::transition_denied($row,'PROCESSING','INTAKE',''),'staging-only source policy not extended to production');
echo json_encode(['state'=>'PASS','cases'=>8,'wordpress_runtime_loaded'=>false,
 'site_mutated'=>false,'policy_applied_to_wrong_site'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;

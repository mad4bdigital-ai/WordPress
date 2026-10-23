<?php
define('ABSPATH', '/');
define('MAD4B_SCP_DIR', dirname(__DIR__) . '/');
function add_action() {}
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string)$v)); }
function wp_json_encode($v, $opts=0) { return json_encode($v, $opts); }
require_once __DIR__ . '/../includes/class-mad4b-scp-content-jobs.php';
require_once __DIR__ . '/../includes/class-mad4b-scp-semantic-content-field-contracts.php';
function check($c, $label) { if (!$c) {fwrite(STDERR, "FAIL $label\n"); exit(1);} }
$job=['job_id'=>'2df68bc3-e54c-4edb-9c47-1c8a00000123','job_revision'=>4,'state'=>'BLOCKED','stage'=>'KNOWLEDGE_DISPATCH','subject'=>'Royal Lily test','target_post_type'=>'tours-and-activities','language'=>'en','target_post_id'=>null];
$coverage=['ready'=>false,'missing_required_context_sets'=>['brand_strategy','tone_of_voice','editorial_guidelines'],'context_fingerprint'=>str_repeat('a',64),'authority_manifest_fingerprint'=>str_repeat('b',64),'registry_revision'=>7];
$route=['ready'=>false,'state'=>'RECONCILIATION_REQUIRED','next_ability'=>'context/review-queue','snapshot_sha256'=>str_repeat('c',64),'categories'=>[['category'=>'brand_strategy','state'=>'REVIEW_EXISTING_ASSET','next_ability'=>'context/review-queue','dependencies'=>[]],['category'=>'tone_of_voice','state'=>'WAIT_UPSTREAM_AUTHORITY','next_ability'=>'context/brand-core-control-loop','dependencies'=>['brand_strategy']],['category'=>'editorial_guidelines','state'=>'WAIT_UPSTREAM_AUTHORITY','next_ability'=>'context/brand-core-control-loop','dependencies'=>['brand_strategy','tone_of_voice']]]];
$blocked=MAD4B_SCP_Content_Jobs::dependency_reduce($job,$coverage,$route);
check($blocked['state']==='RECOVER_BRAND_CORE' && !$blocked['resume_ready'],'missing Brand blocks');
check(count($blocked['recovery_steps'])===3,'dependency chain complete');
check(!$blocked['auto_approve_unreviewed_assets'] && !$blocked['mutation_performed'],'read-only fail closed');
check($blocked['next_transition']===[],'blocked job not blindly mutated');
check($blocked['original_task']['subject']===$job['subject'],'job kept');
$coverage['ready']=true;$coverage['missing_required_context_sets']=[];
$route['ready']=true;$route['state']='READY';$route['categories']=[];
$ready=MAD4B_SCP_Content_Jobs::dependency_reduce($job,$coverage,$route);
check($ready['state']==='RESUME_READY' && $ready['resume_ready'],'resume ready');
check($ready['next_transition']['input']['job_id']===$job['job_id'],'same job');
check($ready['next_transition']['input']['expected_revision']===4,'revision CAS');
check($ready['next_transition']['input']['state']==='RUNNING','resumes without creating duplicate');
$job['state']='CANCELLED';$terminal=MAD4B_SCP_Content_Jobs::dependency_reduce($job,$coverage,$route);
check($terminal['state']==='TERMINAL' && !$terminal['resume_ready'],'terminal cannot resume');
$input=['brand_id'=>'brand','subject'=>str_repeat('Internal pending test subject ',8),'language'=>'en','country'=>'eg','content_type'=>'tour','target_post_type'=>'tours-and-activities','reason'=>'Track pending intake'];
$c=MAD4B_SCP_Semantic_Content_Field_Contracts::classify('mad4b/content-job-create',$input);
check(!empty($c['field_contract_known'])&&!$c['required']&&!$c['review_required'],'internal intake classified operational');
echo "content-job-dependency-recovery-contract: PASS\n";

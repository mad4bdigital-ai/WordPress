<?php
/** Native isolated state-matrix fixture; never writes WordPress or Context. */
define( 'ABSPATH', __DIR__ );
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\\-]/i','',(string)$v)); }
function is_wp_error($v){ return $v instanceof WP_Error; }
class WP_Error {
 private $c;
 function __construct($c,$m=''){ $this->c=$c; }
 function get_error_code(){ return $this->c; }
}
require __DIR__ . '/../includes/class-mad4b-scp-brand-context-reconstruction.php';
$scenarios = array(
 array('brand_strategy', 'live', array('ready'=>false), 'OWNER_AUTHORITY_REQUIRED'),
 array('brand_strategy', 'missing_file', array('ready'=>true), 'OWNER_AUTHORITY_REQUIRED'),
 array('brand_strategy', 'conflicting_authorities', array('conflict'=>true), 'HUMAN_ARBITRATION'),
 array('tone_of_voice', 'live', array('ready'=>true), 'READY'),
 array('tone_of_voice', 'live', array('dependency_ready'=>false), 'WAIT_DEPENDENCY'),
 array('editorial_guidelines', 'live', array('dependency_ready'=>false), 'WAIT_DEPENDENCY'),
 array('tone_of_voice', 'provider_unavailable', array('dependency_ready'=>true), 'SOURCE_RECOVERY'),
 array('tone_of_voice', 'provider_timeout', array('dependency_ready'=>true), 'SOURCE_RECOVERY'),
 array('tone_of_voice', 'stale_version', array('dependency_ready'=>true), 'RESCAN_REQUIRED'),
 array('tone_of_voice', 'receipt_drift', array('dependency_ready'=>true), 'RESCAN_REQUIRED'),
 array('tone_of_voice', 'malformed_file', array('dependency_ready'=>true), 'NORMALIZATION_REQUIRED'),
 array('tone_of_voice', 'draft_not_materialized', array('dependency_ready'=>true), 'MATERIALIZATION_RECONCILE'),
 array('tone_of_voice', 'review_denied', array('dependency_ready'=>true), 'HUMAN_REVIEW'),
 array('tone_of_voice', 'asset_rights_unverified', array('dependency_ready'=>true), 'RIGHTS_REVIEW'),
 array('tone_of_voice', 'repeated_failure', array('dependency_ready'=>true), 'CIRCUIT_OPEN'),
 array('tone_of_voice', 'live', array('dependency_ready'=>true,'generation_ready'=>false), 'EVIDENCE_COLLECTION'),
 array('tone_of_voice', 'live', array('dependency_ready'=>true,'generation_ready'=>true,'writable_source'=>false), 'DESTINATION_RECOVERY'),
 array('tone_of_voice', 'live', array('dependency_ready'=>true,'generation_ready'=>true,'writable_source'=>true), 'DRAFT_PREPARATION'),
 array('tone_of_voice', 'assistant_unavailable', array('dependency_ready'=>true,'generation_ready'=>true,'writable_source'=>true), 'HUMAN_DRAFT_PREPARATION'),
);
$count=0;
foreach ($scenarios as $case){
 $result=MAD4B_SCP_Brand_Context_Reconstruction::classify($case[0],$case[2],$case[1],0,'assistant_unavailable'!==$case[1]);
 if(is_wp_error($result)||$result['state']!==$case[3]||
    !empty($result['automatic_approval'])||!empty($result['mutation_eligible'])||!empty($result['owner_decision_required'])===false && $result['state']!=='READY'){
   throw new RuntimeException('Scenario mismatch: '.json_encode($case).' '.json_encode($result));
 }
 ++$count;
}
$exhausted=MAD4B_SCP_Brand_Context_Reconstruction::classify('tone_of_voice',array('generation_ready'=>true,'writable_source'=>true),'live',3);
if(is_wp_error($exhausted)||$exhausted['state']!=='CIRCUIT_OPEN'||$exhausted['retry_budget_remaining']!==0)throw new RuntimeException('Retry breaker failed');
$bad=MAD4B_SCP_Brand_Context_Reconstruction::classify('unsupported',array(),'live');
if(!is_wp_error($bad)||$bad->get_error_code()!=='mad4b_brand_reconstruction_category_invalid')throw new RuntimeException('Unsupported brand context category');
$bad=MAD4B_SCP_Brand_Context_Reconstruction::classify('tone_of_voice',array(),'unknown_scenario');
if(!is_wp_error($bad)||$bad->get_error_code()!=='mad4b_brand_reconstruction_scenario_invalid')throw new RuntimeException('Unsupported scenario');
$draftProcedure=MAD4B_SCP_Brand_Context_Reconstruction::procedure_for_state('DRAFT_PREPARATION');
$writeCount=0;
foreach ($draftProcedure as $step) {
  if ($step['lane']==='write') {
    ++$writeCount;
    if (empty($step['approval_required']) || empty($step['fresh_context_and_site_binding_required']) ||
        empty($step['independent_readback_required'])) throw new RuntimeException('Unbound draft write step');
  }
}
if ($writeCount!==2) throw new RuntimeException('Missing two separately governed Brand draft writes');
foreach (array('OWNER_AUTHORITY_REQUIRED','HUMAN_ARBITRATION','RIGHTS_REVIEW','CIRCUIT_OPEN') as $state) {
  foreach (MAD4B_SCP_Brand_Context_Reconstruction::procedure_for_state($state) as $step)
    if ($step['lane']!=='read') throw new RuntimeException('Human authority gate exposed an automated write');
}
$recoveryProcedure=MAD4B_SCP_Brand_Context_Reconstruction::procedure_for_state('MATERIALIZATION_RECONCILE');
if (count($recoveryProcedure)!==3 || $recoveryProcedure[0]['lane']!=='read' ||
    $recoveryProcedure[1]['ability']!=='context/reconcile-brand-materialization' ||
    empty($recoveryProcedure[1]['exact_new_ticket_required'])) {
  throw new RuntimeException('Unverified provider re-creation may duplicate a draft');
}
// Independent live-evidence planning: a caller flag never certifies an agent.
function wp_json_encode($v,$flags=0) { return json_encode($v,$flags); }
class MAD4B_SCP_Skill_Runtime_Certification {
 static function current_status(){ return array('ready'=>false,'historical_evidence_only'=>true); }
}
class MAD4B_SCP_Brand_Context_Builder {
 static function expected_categories(){ return array('brand_strategy'=>'Brand Strategy','tone_of_voice'=>'Tone of Voice','editorial_guidelines'=>'Editorial Guidelines'); }
 static function convergence_plan($args=array()) {
   return array('registry_revision'=>4,'authority_manifest_fingerprint'=>str_repeat('a',64),'plan_sha256'=>str_repeat('b',64),
     'writable_sources'=>array(array('source_id'=>'managed')),
     'actions'=>array(
       array('category'=>'brand_strategy','state'=>'blocked','blockers'=>array('non_generatable_brand_authority_missing')),
       array('category'=>'tone_of_voice','state'=>'ready_to_create','blockers'=>array()),
       array('category'=>'editorial_guidelines','state'=>'blocked','blockers'=>array('approved_tone_of_voice_required_for_editorial_generation')),
     ));
 }
}
class MAD4B_SCP_Context_Authority {
 static function brand_core_coverage(){
   return array('ready'=>false,'registry_revision'=>4,'authority_manifest_fingerprint'=>str_repeat('a',64),'coverage'=>array(
     'brand_strategy'=>array('ready'=>true,'conflict'=>false,'observed_assets'=>array()),
     'tone_of_voice'=>array('ready'=>false,'conflict'=>false,
       'observed_assets'=>array(array('reasons'=>array('content_incomplete')))),
     'editorial_guidelines'=>array('ready'=>false,'conflict'=>false,'observed_assets'=>array()),
   ));
 }
}
$live=MAD4B_SCP_Brand_Context_Reconstruction::plan(array('scenario'=>'live','assistant_available'=>true));
if(is_wp_error($live)||$live['state']!=='evidence_bound_plan'||!empty($live['assistant_certification_verified']) ||
   !empty($live['assistant_effectively_available']) || empty($live['read_only']) || !empty($live['mutation_performed'])) {
  throw new RuntimeException('Live reconstruction plan failed read-only certification');
}
$states=array();
foreach($live['states'] as $record) $states[$record['category']]=$record;
if($states['brand_strategy']['state']!=='READY' ||
   $states['tone_of_voice']['state']!=='NORMALIZATION_REQUIRED' ||
   empty($states['tone_of_voice']['detected_from_live_evidence']) ||
   $states['editorial_guidelines']['state']!=='WAIT_DEPENDENCY') {
  throw new RuntimeException('Real Context reasons did not select safe reconstruction order');
}
// A half-updated authority registry must invalidate plans instead of
// silently emitting a repair path bound to an obsolete source.
class ContextStateFixture {
 static $drift=false;
}
// Isolated source-scan challenge for absence evidence; no write is performed.
$missing = MAD4B_SCP_Brand_Context_Reconstruction::classify(
  'tone_of_voice', array('dependency_ready'=>true), 'missing_file', 0, true
);
if (is_wp_error($missing) || $missing['state']!=='SOURCE_DISCOVERY') {
  throw new RuntimeException('Missing file was recreated without source discovery');
}
$procedure=MAD4B_SCP_Brand_Context_Reconstruction::procedure_for_state('SOURCE_DISCOVERY');
if (count($procedure)!==4 || $procedure[0]['lane']!=='read' ||
    $procedure[2]['lane']!=='write' || empty($procedure[2]['exact_new_ticket_required'])) {
  throw new RuntimeException('Source discovery can mutate without exact source-scan approval');
}
$simulation=MAD4B_SCP_Brand_Context_Reconstruction::plan(array('scenario'=>'provider_timeout'));
if(is_wp_error($simulation)||$simulation['state']!=='simulation_only'||
   empty($simulation['scenario_is_hypothetical'])||!empty($simulation['authorizing'])) {
  throw new RuntimeException('Hypothetical provider timeout became authorization');
}
echo "PASS ".$count." Brand reconstruction scenarios, retries, no automatic authority\n";

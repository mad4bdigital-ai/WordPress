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
echo "PASS ".$count." Brand reconstruction scenarios, retries, no automatic authority\n";

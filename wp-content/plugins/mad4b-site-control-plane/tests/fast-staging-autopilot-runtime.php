<?php
define('ABSPATH', '/');
function __( $text, $domain = '' ) { return $text; }
require_once dirname(__DIR__) . '/includes/class-mad4b-scp-staging-autopilot.php';
function ensure_fast($ok,$name) { if (!$ok) { fwrite(STDERR, "FAIL $name\n"); exit(1); } }
$site=array('configured_environment'=>'staging','wordpress_environment'=>'staging','wordpress_environment_explicit'=>true,
  'authority_ready'=>true,'origin_match'=>true,'environment_match'=>true,
  'deployment_binding_configured'=>true,'deployment_binding_match'=>true,
  'deployment_binding_bound'=>true,'same_origin_clone_protection'=>true);
$job=array('ready'=>true,'checkpoint_identity_current'=>true,'state'=>'completed',
  'checkpoint'=>array('operation_id'=>'op-unique-1','seed_cursor'=>11));
$a=MAD4B_SCP_Staging_Autopilot::fast_plan($site,$job);
ensure_fast($a['next_step']==='governed_write' && !$a['automatic_approval'], 'checkpoint_is_not_write_authorization');
ensure_fast($a['steps'][3]['owner_review_required'] && !$a['steps'][3]['auto_execution'], 'owner_gate_not_bypassed');
ensure_fast(!$a['production_mutation'] && !$a['mutation_performed'], 'staging_safety');
$b=MAD4B_SCP_Staging_Autopilot::fast_plan($site,array('ready'=>false,'state'=>'running',
  'checkpoint_identity_current'=>true, 'checkpoint'=>array('operation_id'=>'op-unique-1','seed_cursor'=>6)));
ensure_fast($b['next_step']==='managed_skills' && $b['steps'][2]['state']==='resume_existing_checkpoint', 'resume_existing_checkpoint');
ensure_fast($b['steps'][2]['existing_operation_id']==='op-unique-1', 'preserve_exact_operation');
$b_paused=MAD4B_SCP_Staging_Autopilot::fast_plan($site,array('ready'=>false,'state'=>'awaiting_exact_resume',
  'checkpoint_identity_current'=>true,'resume_eligible_after_fresh_permission'=>true,
  'checkpoint'=>array('operation_id'=>'op-unique-1','seed_cursor'=>6)));
ensure_fast($b_paused['steps'][2]['state']==='resume_existing_checkpoint'
    && $b_paused['state']==='existing_checkpoint_awaiting_authorized_resume', 'between_batches_recovery');
$b_foreign=MAD4B_SCP_Staging_Autopilot::fast_plan($site,array('ready'=>false,'state'=>'awaiting_exact_resume',
  'checkpoint_identity_current'=>false,'resume_eligible_after_fresh_permission'=>true,
  'checkpoint'=>array('operation_id'=>'op-unique-1','seed_cursor'=>6)));
ensure_fast($b_foreign['steps'][2]['state']==='needs_exact_reconciliation', 'no_stale_build_resume');
$c=MAD4B_SCP_Staging_Autopilot::fast_plan($site,array('state'=>'completed','ready'=>true,'checkpoint_identity_current'=>false));
ensure_fast($c['next_step']==='managed_skills', 'stale_checkpoint_not_ready');
$d=MAD4B_SCP_Staging_Autopilot::fast_plan(array_merge($site,array('deployment_binding_match'=>false)),$job);
ensure_fast($d['next_step']==='deployment_binding', 'binding_drift_first');
$e=MAD4B_SCP_Staging_Autopilot::fast_plan(array_merge($site,array('configured_environment'=>'production')),$job);
ensure_fast($e['next_step']==='site_profile' && !$e['production_mutation'], 'production_not_eligible');
// Conversation context may propose a task; all candidate hashes come from live
// verified WordPress provenance, not from a prompt or a previous SHA.
$provenance=array('identity_ready'=>true,'source_commit_sha'=>str_repeat('a',40),
    'build_fingerprint'=>str_repeat('b',64),'package_manifest_digest'=>str_repeat('c',64));
$verified_job=array('state'=>'awaiting_exact_resume','checkpoint_identity_current'=>true,
    'resume_eligible_after_fresh_permission'=>true,
    'checkpoint'=>array('operation_id'=>'8f5a032a-efd1-4ee3-97fd-ce489b93c047','stage'=>'seed_reconciliation','seed_cursor'=>6));
$run=MAD4B_SCP_Staging_Autopilot::source_bound_runbook($site,$verified_job,$provenance);
ensure_fast($run['candidate_verified'] && $run['managed_skills']['resume_after_fresh_permission'], 'source_bound_checkpoint');
ensure_fast($run['managed_skills']['candidate_input']['expected_source_commit_sha']===str_repeat('a',40), 'sha_derived_from_live');
ensure_fast($run['managed_skills']['checkpoint_cursor']===6, 'cursor_derived_from_live');
ensure_fast(!$run['governed_write']['previous_approval_reused'] && !$run['write_executed'], 'write_scope_not_inferred');
ensure_fast(!$run['plugin_capabilities']['uncertified_writes_automatically_eligible'], 'plugins_no_approval');
$stale=$verified_job;$stale['checkpoint_identity_current']=false;
ensure_fast(!MAD4B_SCP_Staging_Autopilot::source_bound_runbook($site,$stale,$provenance)['managed_skills']['resume_after_fresh_permission'],'stale_checkpoint_denied');
$bad_id=$verified_job;$bad_id['checkpoint']['operation_id']='incorrect';
ensure_fast(!MAD4B_SCP_Staging_Autopilot::source_bound_runbook($site,$bad_id,$provenance)['managed_skills']['resume_after_fresh_permission'],'malformed_id_denied');
$missing=$provenance;$missing['identity_ready']=false;
ensure_fast(!MAD4B_SCP_Staging_Autopilot::source_bound_runbook($site,$verified_job,$missing)['candidate_verified'],'missing_source_denied');
$prod=$site;$prod['configured_environment']='production';
ensure_fast(!MAD4B_SCP_Staging_Autopilot::source_bound_runbook($prod,$verified_job,$provenance)['candidate_verified'],'production_denied');
$bad_origin=$site;$bad_origin['origin_match']=false;
ensure_fast(!MAD4B_SCP_Staging_Autopilot::source_bound_runbook($bad_origin,$verified_job,$provenance)['candidate_verified'],'origin_mismatch_denied');
// The fast endpoint must not invoke Full Authority, live Skills enumeration,
 // or provider discovery. A transport request should need only two local reads.
class MAD4B_SCP_Site_Profile {
  public static function status() { return $GLOBALS['fast_autopilot_site']; }
}
class MAD4B_SCP_Remote_Operation_Parity {
  public static function managed_skills_reconciliation_status() { return $GLOBALS['fast_autopilot_job']; }
}
class MAD4B_SCP_Live_Acceptance_Observer {
  public static function build_provenance_identity_status() { return $GLOBALS['fast_autopilot_identity']; }
}
class MAD4B_SCP_Full_Staging_Authority {
  public static function status() { throw new RuntimeException('FULL_AUTHORITY_UNEXPECTED'); }
}
class MAD4B_SCP_Skill_Runtime_Certification {
  public static function current_status() { throw new RuntimeException('LIVE_SKILLS_SCAN_UNEXPECTED'); }
}
$GLOBALS['fast_autopilot_identity']=$provenance;
$GLOBALS['fast_autopilot_site']=$site;
$GLOBALS['fast_autopilot_job']=$job;
$fast=MAD4B_SCP_Staging_Autopilot::fast_status(array());
ensure_fast($fast['plan']['next_step']==='governed_write' && empty($fast['incidents']), 'fast_no_deep_scan');
ensure_fast($fast['checkpoint']['operation_id']==='op-unique-1' && $fast['checkpoint']['identity_current'], 'checkpoint_identity');
ensure_fast(!$fast['authorizing'] && !$fast['mutation_performed'], 'fast_no_authority');
ensure_fast($fast['runbook']['candidate_verified']===true && $fast['runbook']['write_executed']===false,'fast_runbook_integration');

echo "FAST_STAGING_AUTOPILOT_RUNTIME_PASS\n";

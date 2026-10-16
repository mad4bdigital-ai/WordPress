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
  'checkpoint'=>array('operation_id'=>'op-unique-1','seed_cursor'=>6)));
ensure_fast($b['next_step']==='managed_skills' && $b['steps'][2]['state']==='resume_existing_checkpoint', 'resume_existing_checkpoint');
ensure_fast($b['steps'][2]['existing_operation_id']==='op-unique-1', 'preserve_exact_operation');
$c=MAD4B_SCP_Staging_Autopilot::fast_plan($site,array('state'=>'completed','ready'=>true,'checkpoint_identity_current'=>false));
ensure_fast($c['next_step']==='managed_skills', 'stale_checkpoint_not_ready');
$d=MAD4B_SCP_Staging_Autopilot::fast_plan(array_merge($site,array('deployment_binding_match'=>false)),$job);
ensure_fast($d['next_step']==='deployment_binding', 'binding_drift_first');
$e=MAD4B_SCP_Staging_Autopilot::fast_plan(array_merge($site,array('configured_environment'=>'production')),$job);
ensure_fast($e['next_step']==='site_profile' && !$e['production_mutation'], 'production_not_eligible');
// The fast endpoint must not invoke Full Authority, live Skills enumeration,
 // or provider discovery. A transport request should need only two local reads.
class MAD4B_SCP_Site_Profile {
  public static function status() { return $GLOBALS['fast_autopilot_site']; }
}
class MAD4B_SCP_Remote_Operation_Parity {
  public static function managed_skills_reconciliation_status() { return $GLOBALS['fast_autopilot_job']; }
}
class MAD4B_SCP_Full_Staging_Authority {
  public static function status() { throw new RuntimeException('FULL_AUTHORITY_UNEXPECTED'); }
}
class MAD4B_SCP_Skill_Runtime_Certification {
  public static function current_status() { throw new RuntimeException('LIVE_SKILLS_SCAN_UNEXPECTED'); }
}
$GLOBALS['fast_autopilot_site']=$site;
$GLOBALS['fast_autopilot_job']=$job;
$fast=MAD4B_SCP_Staging_Autopilot::fast_status(array());
ensure_fast($fast['plan']['next_step']==='governed_write' && empty($fast['incidents']), 'fast_no_deep_scan');
ensure_fast($fast['checkpoint']['operation_id']==='op-unique-1' && $fast['checkpoint']['identity_current'], 'checkpoint_identity');
ensure_fast(!$fast['authorizing'] && !$fast['mutation_performed'], 'fast_no_authority');
echo "FAST_STAGING_AUTOPILOT_RUNTIME_PASS\n";

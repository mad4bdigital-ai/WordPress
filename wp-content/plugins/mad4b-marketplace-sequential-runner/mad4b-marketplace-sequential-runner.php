<?php
/**
 * Plugin Name: Marketplace Sequential Runner
 * Description: Local-only governed sequential Spec Kit state controller; fail closed until a certified executor proves actual acceptance.
 * Version: 0.1.0
 * Requires Plugins: mad4b-site-control-plane
 * Text Domain: mad4b-marketplace-runner
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Marketplace_Sequential_Runner {
 const OPTION='mad4b_marketplace_sequential_runner_v1';
 const LOCK='mad4b_marketplace_sequential_runner_lock_v1';
 const HOOK='mad4b/marketplace/sequential-tick';
 const SCHEMA='mad4b.marketplace-sequential-runner.v1';
 const STAGES=array('000-baseline-and-contracts','001-identity-membership','002-product-partner-rights','003-supplier-portal','004-inbound-and-import','005-outbound-syndication','006-pricing-source-arbitration','007-booking-fulfillment-finance','008-governance-release');
 const ACCEPTANCE=array(0=>4,1=>7,2=>6,3=>6,4=>8,5=>7,6=>8,7=>7,8=>8);
 public static function local_ok(){return function_exists('wp_get_environment_type') && wp_get_environment_type()==='local' && class_exists('MAD4B_SCP_Agent_Registry') && class_exists('MAD4B_SCP_Skill_Registry');}
 public static function stage_hash($index){
  $i=(int)$index;if($i<0||$i>8)return new WP_Error('runner_stage_invalid');
  $path=__DIR__.'/frozen-spec-manifest.json';
  $m=is_file($path)?json_decode((string)file_get_contents($path),true):null;
  $record=is_array($m)?($m['stages'][$i]??null):null;
  return ($record['stage']??'')===self::STAGES[$i] && preg_match('/^[a-f0-9]{64}$/D',(string)($record['spec_sha256']??''))?$record['spec_sha256']:new WP_Error('runner_frozen_manifest_invalid');
 }
 private static function snapshot(){ $v=get_option(self::OPTION, array());return is_array($v)?$v:array(); }
 public static function status(){
  $v=self::snapshot();return array('contract'=>self::SCHEMA,'environment'=>wp_get_environment_type(),
   'local_eligible'=>self::local_ok(),'run'=>$v,'mutation_performed'=>false,
   'native_queue'=>'MAD4B remote-work supports whitelisted operations only; sequential stage work uses original Policy/Skill gate and this versioned journal',
   'worker'=>'bounded local verifier; no arbitrary model-generated commands');
 }
 private static function persist($record,$expected){
  global $wpdb;
  $old=self::snapshot();if((int)($old['revision']??0)!==(int)$expected)return new WP_Error('runner_stale_revision');
  $record['revision']=(int)$expected+1;
  if(!$old){
   if(!add_option(self::OPTION,$record,'',false))return new WP_Error('runner_init_raced');
  }else{
   // Strict compare-and-swap; never blindly overwrite another worker's current checkpoint.
   $prior=maybe_serialize($old);$after=maybe_serialize($record);
   $ok=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",$after,self::OPTION,$prior));
   if($ok!==1)return new WP_Error('runner_cas_failed');
   wp_cache_delete(self::OPTION,'options');
  }
  $actual=self::snapshot();return ((int)($actual['revision']??0)===$record['revision']&&($actual['run_id']??'')===$record['run_id'])?$actual:new WP_Error('runner_readback_mismatch');
 }
 private static function add_history(&$r,$event,$reason=''){
  $r['history'][]=['at'=>gmdate('c'),'event'=>$event,'stage'=>$r['stage_index'],'reason'=>sanitize_key($reason)];
  $r['history']=array_slice($r['history'],-80);
 }
 public static function start_readonly_pilot($source='owner_request'){
  if(PHP_SAPI!=='cli')return new WP_Error('runner_cli_only_start');
  if(!self::local_ok())return new WP_Error('runner_local_governance_required');
  if(!empty(self::snapshot()))return new WP_Error('runner_existing_run','A previous run exists; must reconcile or inspect before creating another.');
  $hash=self::stage_hash(0);if(is_wp_error($hash))return $hash;
  $r=['run_id'=>'marketplace-'.wp_generate_uuid4(),'state'=>'queued','stage_index'=>0,
   'stage'=>self::STAGES[0],'plan_sha256'=>$hash,'mode'=>'read_only_pilot',
   'authority'=>'readonly_diagnostics_only','executor'=>'fixed_allowlist_local',
   'acceptance_verified'=>false,'approval_receipt'=>null,'last_evidence'=>null,
   'queued_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'history'=>[]];
  self::add_history($r,'created',$source);return self::persist($r,0);
 }
 public static function tick($worker_id='local-guarded-worker'){
  if(!self::local_ok())return new WP_Error('runner_local_governance_required');
  $r=self::snapshot();if(!$r)return ['state'=>'uninitialized','mutation_performed'=>false];
  $original=$r['revision'];
  if(in_array($r['state'],['awaiting_acceptance','awaiting_authority','blocked','paused','completed'],true))
   return ['state'=>$r['state'],'stage'=>$r['stage'],'mutation_performed'=>false,'next_action'=>'manual_review_or_certified_executor_required'];
  if($r['state']!=='queued')return new WP_Error('runner_not_runnable');
  if(!hash_equals((string)self::stage_hash($r['stage_index']),(string)$r['plan_sha256'])){
   $r['state']='blocked';self::add_history($r,'stopped','spec_drift');return self::persist($r,$original);
  }
  if($r['stage_index']!==0||$r['mode']!=='read_only_pilot'){
   $r['state']='awaiting_authority';self::add_history($r,'stopped','certified_coding_executor_missing');return self::persist($r,$original);
  }
  // Stage 00 has a *fixed* verifier, not an AI shell or automatic contract approval.
  $r['state']='awaiting_acceptance';self::add_history($r,'stage_zero_admitted','readonly_verification_required');
  $r['updated_at']=gmdate('c');return self::persist($r,$original);
 }
 public static function evidence($expected_revision,$data){
  if(PHP_SAPI!=='cli')return new WP_Error('runner_cli_only_evidence');
  if(!self::local_ok())return new WP_Error('runner_local_governance_required');
  $r=self::snapshot();if(($r['revision']??0)!==(int)$expected_revision)return new WP_Error('runner_stale_revision');
  if($r['stage_index']!==0||$r['state']!=='awaiting_acceptance')return new WP_Error('runner_not_awaiting_evidence');
  // The worker only submits checksums for *whitelisted fixed verifiers*, and cannot approve subsequent stages.
  $reportPath=__DIR__.'/reports/'.$r['run_id'].'-stage-00.json';
  if(!is_file($reportPath)||is_link($reportPath)||filesize($reportPath)>32768)
   return new WP_Error('runner_fixed_report_missing');
  $report=json_decode((string)file_get_contents($reportPath),true);
  if(!is_array($report)||($report['run_id']??'')!==$r['run_id']||
    ($report['stage']??'')!==self::STAGES[0]||
    ($report['spec_sha256']??'')!==$r['plan_sha256']||
    ($report['checks']['spec_status']??'')!=='PASS'||
    ($report['checks']['skill_status']??'')!=='PASS')
   return new WP_Error('runner_fixed_report_untrusted');
  $a=$data['spec_check']??'';$b=$data['skill_check']??'';
  if(!hash_equals((string)($report['checks']['spec_check']??''),(string)$a)||
     !hash_equals((string)($report['checks']['skill_check']??''),(string)$b))
   return new WP_Error('runner_evidence_report_mismatch');
  if(!preg_match('/^[a-f0-9]{64}$/D',(string)$a)||!preg_match('/^[a-f0-9]{64}$/D',(string)$b))return new WP_Error('runner_evidence_invalid');
  if(($data['spec_status']??'')!=='PASS'||($data['skill_status']??'')!=='PASS')return new WP_Error('runner_evidence_failing');
  $r['last_evidence']=['spec_sha256'=>$a,'skills_sha256'=>$b,'timestamp'=>gmdate('c'),'verification_scope'=>'spec_and_skill_integrity_only'];
  $r['state']='awaiting_authority';self::add_history($r,'fixed_checks_passed','contract_freeze_not_approved');
  return self::persist($r,$expected_revision);
 }
 public static function ingest_fixed_report(){
  if(PHP_SAPI!=='cli')return new WP_Error('runner_cli_only_evidence');
  $r=self::snapshot();
  if(($r['state']??'')!=='awaiting_acceptance'||($r['stage_index']??-1)!==0)
   return array('state'=>$r['state']??'uninitialized','no_effect'=>true);
  $path=__DIR__.'/reports/'.$r['run_id'].'-stage-00.json';
  if(!is_file($path)||is_link($path))return new WP_Error('runner_fixed_report_missing');
  $doc=json_decode((string)file_get_contents($path),true);
  $proof=$doc['checks']??array();
  return self::evidence($r['revision'],array('spec_check'=>$proof['spec_check']??'',
   'skill_check'=>$proof['skill_check']??'',
   'spec_status'=>$proof['spec_status']??'', 'skill_status'=>$proof['skill_status']??''));
 }
 // Always callable when MCP initializes during a foreign REST zero-touch
 // request (where Control Plane intentionally skips loading the policy).
 // Never autoload the heavy authority runtime or substitute a weaker cap.
 public static function can_read_status($input=null){
  return class_exists('MAD4B_SCP_Policy',false)
   && is_callable(['MAD4B_SCP_Policy','can_read'])
   && (bool)MAD4B_SCP_Policy::can_read();
 }
 public static function boot(){
  add_action(self::HOOK,[__CLASS__,'tick']);
  add_action('mad4b/marketplace/ingest-fixed-report',[__CLASS__,'ingest_fixed_report']);
  // The Control Plane owns mad4b-read at priority 10. A passive REST/MCP
  // bootstrap can skip that owner; register only if still absent afterward.
  // WordPress requires categories on their own init action, before abilities.
  add_action('wp_abilities_api_categories_init',function(){
   if(!function_exists('wp_register_ability_category')||
      !function_exists('wp_has_ability_category')||
      wp_has_ability_category('mad4b-read'))return;
   wp_register_ability_category('mad4b-read',[
    'label'=>'MAD4B Read',
    'description'=>'Read-only discovery and diagnostics.'
   ]);
  },15);
  add_action('wp_abilities_api_init',function(){
   if(!function_exists('wp_register_ability')||
      !function_exists('wp_has_ability_category')||
      !wp_has_ability_category('mad4b-read')||
      wp_has_ability('mad4b/marketplace-runner-status'))return;
   wp_register_ability('mad4b/marketplace-runner-status',[
    'label'=>'Marketplace Sequential Runner Status','description'=>'Read-only local governed sequential execution journal.',
    'category'=>'mad4b-read','execute_callback'=>[__CLASS__,'status'],
    'permission_callback'=>[__CLASS__,'can_read_status'],
    'input_schema'=>['type'=>'object','additionalProperties'=>false],
    'output_schema'=>['type'=>'object','additionalProperties'=>true],
    'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
      'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]]);
  },80);
 }
}
MAD4B_Marketplace_Sequential_Runner::boot();

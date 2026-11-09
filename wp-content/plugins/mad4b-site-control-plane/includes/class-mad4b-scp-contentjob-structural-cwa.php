<?php
/**
 * Original WordPress Structural-Only ContentJob CWA owner.
 * Separate from editorial WPML approvals and generic owner certificates.
 * Only originally rendered exact WPML Elementor delta, never arbitrary JSON.
 */
defined('ABSPATH')||exit;
final class MAD4B_SCP_Structural_Only_CWA_Adapter implements MAD4B_Central_Enrichment_Write_Adapter {
 const CONTRACT='mad4b.contentjob.original-structural-cwa.v1';
 private string $job;
 public function __construct(string $job){$this->job=$job;}
 private function facts():array {
  if(wp_get_environment_type()!=='staging' ||
    rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com')
    throw new RuntimeException('structural_cwa_staging_only');
  $qa=MAD4B_SCP_Content_Job_Structural_QA::receipt($this->job);
  if(($qa['state']??'')!=='STRUCTURAL_DOCUMENT_ATTESTED' ||
     ($qa['editorial_review_completed']??true)!==false ||
     ($qa['cwa_write_authorized']??true)!==false ||
     ($qa['no_rights_bearing_delta']??false)!==true)
    throw new RuntimeException('structural_original_qa_stale_or_invalid');
  $site=(string)($qa['site_uuid']??'');
  $browser=get_option('mad4b_cj_structural_browser_'.hash('sha256',$site.'|'.$this->job),null);
  $capture=strtotime((string)($browser['captured_at_utc']??''));
  if(!is_array($browser)||$capture===false||$capture<time()-1800||
    $capture>time()+60 ||
    ($browser['contract']??'')!=='mad4b.structural.browser-preview.v1' ||
    ($browser['site_uuid']??'')!==$site ||
    ($browser['job_id']??'')!==$this->job ||
    ($browser['job_revision']??0)!==$qa['job_revision'] ||
    ($browser['source_post_id']??0)!==$qa['source_post_id'] ||
    ($browser['target_post_id']??0)!==$qa['target_post_id'] ||
    ($browser['source_sha256']??'')!==$qa['source_sha256'] ||
    ($browser['target_sha256']??'')!==$qa['target_sha256'] ||
    ($browser['structural_document_evidence_sha256']??'')!==$qa['evidence_sha256']||
    ($browser['preview_anchor_unique']??false)!==true||
    ($browser['browser_hash_navigation_passed']??false)!==true||
    ($browser['target_template_rendered']??false)!==true||
    ($browser['script_errors_count']??-1)!==0||
    ($browser['rights_bearing_mutation']??true)!==false||
    ($browser['preview_only']??false)!==true||
    ($browser['actual_postwrite_render_verified']??true)!==false||
    ($browser['editorial_translation_approved']??true)!==false||
    !MAD4B_SCP_Central_Write_Acceptance::sha($browser['screenshot_sha256']??null))
    throw new RuntimeException('independent_original_browser_preview_not_qualified');
  $graph=MAD4B_SCP_Content_Jobs::relationship_plan(['job_id'=>$this->job]);
  $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$this->job]);
  if(($graph['state']??'')!=='READY'||is_wp_error($job)||
    ($job['job']['state']??'')!=='QUEUED'||
    (int)($job['job']['job_revision']??0)!==$qa['job_revision']||
    (int)($job['job']['current_artifact_id']??0)!==$qa['target_post_id']||
    (int)($graph['source_reference']['id']??0)!==$qa['source_post_id']||
    (int)($graph['family']['target_id']??0)!==$qa['target_post_id']||
    (string)($graph['family']['target_locale']??'')!==$browser['locale']||
    (string)($graph['site_uuid']??'')!==$site)
    throw new RuntimeException('original_wpml_structural_job_or_family_changed');
  if(!current_user_can('edit_post',$qa['source_post_id'])||
     !current_user_can('edit_post',$qa['target_post_id'])||
     !MAD4B_SCP_Policy::can_mutate()||
     !function_exists('mad4b_wpml_auto_sync_allowed_v1')||
     !mad4b_wpml_auto_sync_allowed_v1($qa['source_post_id'],$qa['target_post_id']))
    throw new RuntimeException('native_wp_structural_edit_permission_denied');
  $preview=MAD4B_WPML_Design_Reconciliation_V1::reconcile_exact(
    $qa['source_post_id'],$qa['target_post_id'],false,
    $qa['source_sha256'],$qa['target_sha256']);
  $selected=$preview['translations'][0]??null;
  if(is_wp_error($preview)||!is_array($selected)||
    count($preview['translations']??[])!==1 ||
    ($selected['target']??0)!==$qa['target_post_id']||
    ($selected['would_update']??null)!==true||
    !empty($selected['issues']??[])||
    !empty($selected['provider_meta_keys']??[])||
    ($preview['unresolved_refs_or_widgets']??-1)!==0)
    throw new RuntimeException('original_exact_structural_preview_not_qualified');
  return [$qa,$browser,$graph,$job['job']];
 }
 public function source(array $claim):array {
  [$qa,$browser,$graph,$job]=$this->facts();
  $proof=hash('sha256',wp_json_encode([
     self::CONTRACT,$qa['evidence_sha256'],$browser['evidence_sha256'],
     $graph['family']['identity_sha256'],$qa['planned_sha256'],
     $job['job_revision']],JSON_UNESCAPED_SLASHES));
  $snapshot=hash('sha256',implode('|',[$qa['source_sha256'],
     $qa['target_sha256'],$qa['planned_sha256'],$proof]));
  $capture=strtotime($browser['captured_at_utc']);
  return ['contract'=>self::CONTRACT,'site_uuid'=>$qa['site_uuid'],
   'environment'=>'staging','subject_id'=>'post:'.$qa['target_post_id'],
   'ability'=>'mad4b/content-job-structural-cwa-apply',
   'job_id'=>$this->job,'source_sha256'=>$snapshot,
   'revision'=>(string)$qa['job_revision'],
   'reference_id'=>'wpml-structural:'.$browser['locale'].':'.$qa['target_post_id'],
   'evidence_sha256'=>$proof,'evidence_verified_at'=>(int)$capture,
   'independent_verification'=>true,
   'quality_pass'=>true, // Original immutable structural and real browser PREVIEW evidence
   'rights_pass'=>true,  // Only because source-owned delta excludes all rights-bearing fields
  ];
 }
 public function authorize(array $claim,array $source):bool {
  try{$facts=$this->facts();}catch(Throwable $e){return false;}
  if(get_option('mad4b_contentjob_structural_cwa_enabled','no')!=='yes'||
     get_option('mad4b_contentjob_structural_cwa_exact_job','')!==$this->job)
    return false;
  $ability=wp_get_ability('mad4b/content-job-structural-cwa-apply');
  return is_object($ability)&&
    $ability->check_permissions(['job_id'=>$this->job])===true &&
    MAD4B_SCP_Central_Write_Acceptance::permitted()&&
    $source['job_id']===$this->job;
 }
 public function apply(array $claim,array $source):array {
  [$qa]=$this->facts();
  $result=MAD4B_WPML_Design_Reconciliation_V1::reconcile_exact(
    $qa['source_post_id'],$qa['target_post_id'],true,
    $qa['source_sha256'],$qa['target_sha256']);
  if(is_wp_error($result))return ['success'=>false,'reason'=>$result->get_error_code()];
  $rows=(array)($result['translations']??[]);
  $row=$rows[0]??[];
  $updated=count($rows)===1 && ($row['updated']??null)===true &&
    (int)($row['target']??0)===$qa['target_post_id'] &&
    !empty($row['rollback_option']) &&
    empty($row['provider_meta_keys']) &&
    ($result['unresolved_refs_or_widgets']??-1)===0;
  return ['success'=>$updated,'reason'=>$updated?'structural_original_provider_wrote_exact_target':
    'structural_provider_result_not_exact',
    'job_id'=>$this->job,'target_id'=>$qa['target_post_id'],
    'source_id'=>$qa['source_post_id'],
    'expected_source_sha256'=>$qa['source_sha256'],
    'expected_planned_sha256'=>$qa['planned_sha256'],
    'rollback_option'=>$row['rollback_option']??'',
    'original_provider'=>'MAD4B_WPML_Design_Reconciliation_V1::reconcile_exact'];
 }
 public function readback(array $claim,array $source,array $result):bool {
  $target=(int)($result['target_id']??0);
  $sourceId=(int)($result['source_id']??0);
  if(!$target||!$sourceId||($result['job_id']??'')!==$this->job||
     (int)(MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$this->job])['job']['current_artifact_id']??0)!==$target||
     !hash_equals((string)($result['expected_source_sha256']??''),
       hash('sha256',(string)get_post_meta($sourceId,'_elementor_data',true))))
    return false;
  $actual=(string)get_post_meta($target,'_elementor_data',true);
  $decoded=json_decode($actual,true);
  if(!is_array($decoded)||!hash_equals((string)($result['expected_planned_sha256']??''),
       hash('sha256',wp_json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))))return false;
  $found=0;
  $walk=function($nodes)use(&$walk,&$found){
    foreach((array)$nodes as $node) {
      if(!is_array($node))continue;
      if(($node['settings']['_element_id']??'')==='state_governorate')$found++;
      $walk($node['elements']??[]);
    }
  };
  $walk($decoded);
  if($found!==1)return false;
  if(!class_exists('Elementor\Plugin'))return false;
  ob_start();
  try{$html=\Elementor\Plugin::instance()->frontend->get_builder_content_for_display($target,true);}
  finally{ob_end_clean();}
  return is_string($html)&&strlen($html)>200 &&
    substr_count($html,'id="state_governorate"')===1;
 }
 public function run():array {
  try{$s=$this->source([]);}catch(Throwable $e){
   return ['contract'=>self::CONTRACT,'state'=>'HOLD',
    'reason'=>$e->getMessage(),'mutation_performed'=>false];
  }
  if(!$this->authorize([],$s))
   return ['contract'=>self::CONTRACT,'state'=>'HOLD',
    'reason'=>'structural_original_cwa_not_authorized','mutation_performed'=>false];
  $scope=MAD4B_SCP_Central_Write_Acceptance::site_scope();
  $claim=['contract'=>MAD4B_SCP_Central_Write_Acceptance::CONTRACT]+$s;
  $claim['verified_at']=$s['evidence_verified_at'];
  $claim['idempotency_key']=MAD4B_SCP_Central_Write_Acceptance::intent_key($s,$scope,$s['evidence_sha256']);
  return MAD4B_SCP_Central_Write_Acceptance::execute($claim,$this);
 }
}

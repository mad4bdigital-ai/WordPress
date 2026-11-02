<?php
/**
 * Two source-owned ContentJob writeback adapters: native Elementor structural
 * reconciliation and exact JetEngine typed metadata. Never write arbitrary
 * Elementor JSON, unsupported metadata, translated text, or supplier prices.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Relationship_Owner_Write_Adapter implements MAD4B_Central_Enrichment_Write_Adapter {
 const CONTRACT='mad4b.contentjob.owner-writeback.v1';
 const CERT_CONTRACT='mad4b.contentjob.typed-owner-certification.v1';
 /**
  * Pure original-owner certificate validator. The trusted context is built
  * locally from WPML/ContentJob/native provider readbacks, not client input.
  * This function by itself does NOT authorize a mutation.
  */
 public static function certificateValid($proof,array $trusted,int $now=0):bool {
  if(!is_array($proof))return false;
  $now=$now?:time();
  if(($proof['contract']??'')!==self::CERT_CONTRACT)return false;
  foreach(['site_uuid','job_id','original_ability','native_source_sha256',
     'binding_sha256','provider_id'] as $key){
   if(!isset($trusted[$key],$proof[$key])||
     !is_string($proof[$key])||
     !hash_equals((string)$trusted[$key],(string)$proof[$key]))return false;
  }
  foreach(['source_post_id','target_post_id'] as $key)
   if(!isset($trusted[$key],$proof[$key]) ||
      !is_int($proof[$key]) ||$proof[$key]!==$trusted[$key])return false;
  $verified=$proof['verified_at']??null;
  if(!is_int($verified)||$verified<=0||
    $verified>$now+60||
    $verified<$now-MAD4B_SCP_Central_Write_Acceptance::DEFAULT_TTL)
    return false;
  if(($proof['independent_verification']??null)!==true ||
    ($proof['quality_pass']??null)!==true ||
    ($proof['rights_pass']??null)!==true)return false;
  return MAD4B_SCP_Central_Write_Acceptance::sha(
      $proof['evidence_sha256']??null);
 }

 private string $jobId; private string $kind; private string $field;
 public function __construct(string $jobId,string $kind,string $field=''){
  $this->jobId=$jobId;$this->kind=$kind;$this->field=$field;
 }
 private function owner():array {
  $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$this->jobId]);
  if(is_wp_error($job)||!is_array($job['job']??null))throw new RuntimeException('native_job_missing');
  $j=$job['job'];
  if(in_array($j['state'],['COMPLETED','CANCELLED'],true))
   throw new RuntimeException('native_contentjob_terminal');
  $plan=MAD4B_SCP_Content_Jobs::relationship_plan(['job_id'=>$this->jobId]);
  if(is_wp_error($plan)||($plan['state']??null)!=='READY')
   throw new RuntimeException('native_contentjob_artifact_not_bound');
  $src=$plan['source_reference']??[];
  $family=$plan['family']??[];
  $source=(int)($src['id']??0);$target=(int)($family['target_id']??0);
  if(!$source||!$target||($src['kind']??null)!=='post'||
   (int)($j['current_artifact_id']??0)!==$target||
   get_post_type($source)!==get_post_type($target))
   throw new RuntimeException('original_wpml_contentjob_binding_changed');
  $typed=$plan['extensions']['native_typed_relations']??[];
  if(($typed['state']??'')!=='INSPECTED')
   throw new RuntimeException('typed_source_provider_not_inspected');
  if($this->kind==='elementor'){
   if(!class_exists('MAD4B_WPML_Design_Reconciliation_V1',false))
    throw new RuntimeException('original_elementor_design_provider_missing');
   $inspection=$typed['relationships']['elementor_template_refs']??[];
   if(!in_array($inspection['status']??'',['REVIEW_WITH_OWNER_ADAPTER'],true))
    throw new RuntimeException('no_certified_template_reference_candidate');
   $preview=MAD4B_WPML_Design_Reconciliation_V1::reconcile($source,false);
   if(is_wp_error($preview)||!is_array($preview)||
      ($preview['unresolved_refs_or_widgets']??1)!==0)
    throw new RuntimeException('elementor_owner_plan_unavailable_or_unresolved');
   $chosen=null;$other=false;
   foreach($preview['translations']??[] as $row){
    if((int)($row['target']??0)===$target){$chosen=$row;continue;}
    if(!empty($row['would_update']))$other=true;
   }
   if(!$chosen ||empty($chosen['would_update'])||$other||
     !empty($chosen['issues']??[])||
     empty($inspection['target_has_document']))
    throw new RuntimeException('elementor_plan_not_exact_single_target');
   $value=['source'=>$source,'target'=>$target,'kind'=>$this->kind,
     'owner_plan'=>$chosen,'original_contract'=>$preview['contract'],
     'source_document_sha256'=>$inspection['source_document_sha256'],
     'target_document_sha256'=>$inspection['target_document_sha256']];
   $ability='elementor/set-dynamic-tag';
  }elseif($this->kind==='typed_meta'){
   if($this->field==='' || strlen($this->field)>80 ||
     !preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]+$/D',$this->field))
    throw new RuntimeException('typed_meta_field_invalid');
   $meta=$typed['relationships']['typed_meta']??[];
   $matched=array_values(array_filter((array)($meta['items']??[]),
     fn($x)=>($x['meta_key']??null)===$this->field));
   if(count($matched)!==1||
      ($matched[0]['state']??'')!=='CANDIDATE_REQUIRES_ORIGINAL_META_ABILITY'||
      (int)($matched[0]['existing_target_id']??-1)!==0||
      (int)($matched[0]['expected_target_id']??0)<1)
    throw new RuntimeException('typed_meta_not_exact_missing_target_reference');
   $v=$matched[0];
   $jet=class_exists('MAD4B_SCP_Adapter_Registry',false)?
      MAD4B_SCP_Adapter_Registry::instance()->get('jetengine'):null;
   if(!is_object($jet)||!$jet->is_available()||
      !method_exists($jet,'get_post_meta_value'))
    throw new RuntimeException('jetengine_owner_not_available');
   $read=$jet->get_post_meta_value(['post_id'=>$target,'field'=>$this->field]);
   if(is_wp_error($read)||!empty($read['exists']))
    throw new RuntimeException('jetengine_original_target_meta_not_empty');
   $value=['source'=>$source,'target'=>$target,'kind'=>$this->kind,
    'field'=>$this->field,'referenced_post'=>(int)$v['source_id'],
    'localized_reference'=>(int)$v['expected_target_id'],
    'target_meta_before_sha256'=>$read['sha256'],'target_meta_absent'=>true];
   $ability='jetengine/update-post-meta';
  }else throw new RuntimeException('unsupported_original_owner_kind');
  // Owner-local evidence is a native readback of provider-managed WPML
  // binding, never a client-provided QA switch or job-title guess.
  if(!class_exists('MAD4B_WPML_ContentJob_Adapter',false)||
     ($src['evidence_owner']??'')!=='mad4b.wpml.contentjob-binding.v1')
    throw new RuntimeException('first_party_wpml_job_evidence_missing');
  $binding=MAD4B_WPML_ContentJob_Adapter::binding(['job_id'=>$this->jobId]);
  if(is_wp_error($binding)||($binding['target_post_id']??0)!==$target||
    ($binding['source_post_id']??0)!==$source ||
    ($binding['readback_verified']??null)!==true ||
    !empty($binding['review_blockers']))
    throw new RuntimeException('native_wpml_owner_readback_or_review_pending');
  $snapshot=['job_id'=>$this->jobId,'job_revision'=>(int)$j['job_revision'],
    'site_uuid'=>$plan['site_uuid'],'family_sha256'=>$family['identity_sha256'],
    'owner_ability'=>$ability,'preview'=>$value,'binding'=>$binding];
  $hash=hash('sha256',wp_json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  // WPML readback verifies translation identity, NOT rights, field quality
  // or Elementor/provider QA. Only a separately certified source-owning
  // provider can attest those independently; never synthesize booleans from
  // a successful translation or the PHP clock.
  $trusted=[
    'site_uuid'=>$plan['site_uuid'],'job_id'=>$this->jobId,
    'source_post_id'=>$source,'target_post_id'=>$target,
    'original_ability'=>$ability,'native_source_sha256'=>$hash,
    'binding_sha256'=>hash('sha256',
      wp_json_encode($binding,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
    'provider_id'=>$this->kind==='elementor'?'elementor':'jetengine'
  ];
  $proof=apply_filters('mad4b/contentjob/typed_owner_certified_evidence',
    null,$this->kind,$this->jobId,$trusted+['owner_preview'=>$value]);
  if(!self::certificateValid($proof,$trusted))
    throw new RuntimeException('original_owner_independent_quality_rights_evidence_missing');
  return [$plan,$j,$value,$ability,$hash,$binding,$proof];
 }
 public function source(array $claim):array {
  [$plan,$job,$value,$ability,$hash,$binding,$proof]=$this->owner();
  $reference=$this->kind==='typed_meta'?'field:'.$this->field:'elementor-structural';
  return ['site_uuid'=>$plan['site_uuid'],'environment'=>$plan['environment'],
   'subject_id'=>'post:'.(int)$plan['family']['target_id'],'ability'=>$ability,
   'job_id'=>$this->jobId,'source_sha256'=>$hash,
   'revision'=>(string)$job['job_revision'],'reference_id'=>$reference,
   'evidence_sha256'=>$proof['evidence_sha256'],
   'evidence_verified_at'=>$proof['verified_at'],
   'independent_verification'=>$proof['independent_verification'],
   'quality_pass'=>$proof['quality_pass'],'rights_pass'=>$proof['rights_pass']];
 }
 public function authorize(array $claim,array $source):bool {
  try {[$plan,$job,$value,$ability]=$this->owner();}
  catch(Throwable $e){return false;}
  if(!current_user_can('edit_post',(int)$plan['family']['target_id'])||
    !MAD4B_SCP_Policy::can_mutate())return false;
  if($this->kind==='elementor')
   return wp_has_ability('elementor/set-dynamic-tag') &&
      class_exists('MAD4B_WPML_Design_Reconciliation_V1',false);
  $jet=MAD4B_SCP_Adapter_Registry::instance()->get('jetengine');
  return wp_has_ability('jetengine/update-post-meta')&&is_object($jet)&&
    $jet->can_edit_post(['post_id'=>$value['target']])===true;
 }
 public function apply(array $claim,array $source):array {
  [$plan,$job,$v]=$this->owner();
  if($this->kind==='elementor'){
   $r=MAD4B_WPML_Design_Reconciliation_V1::reconcile($v['source'],true);
   if(is_wp_error($r))return ['success'=>false,'reason'=>$r->get_error_code()];
   $changed=array_values(array_filter((array)$r['translations'],fn($x)=>!empty($x['updated'])));
   $success=count($changed)===1 && (int)$changed[0]['target']===$v['target']
     &&($r['unresolved_refs_or_widgets']??1)===0;
   return ['success'=>$success,'target'=>$v['target'],'source'=>$v['source'],
    'owner'=>'MAD4B_WPML_Design_Reconciliation_V1',
    'owner_result'=>$changed];
  }
  $args=['post_id'=>$v['target'],'field'=>$v['field'],
   'value'=>$v['localized_reference'],'allow_create'=>true];
  $ability=wp_get_ability('jetengine/update-post-meta');
  if(!$ability)return ['success'=>false,'reason'=>'owner_ability_missing'];
  $r=$ability->execute($args);
  if(is_wp_error($r))return ['success'=>false,'reason'=>$r->get_error_code()];
  return ['success'=>(int)($r['value']??0)===$v['localized_reference'],
    'target'=>$v['target'],'field'=>$v['field'],
    'value'=>$v['localized_reference'],'owner'=>'jetengine/update-post-meta'];
 }
 public function readback(array $claim,array $source,array $result):bool {
  $target=(int)($result['target']??0);
  $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$this->jobId]);
  if(!$target||is_wp_error($j)||
    (int)($j['job']['current_artifact_id']??0)!==$target||
    (int)$j['job']['job_revision']!==(int)$source['revision'])return false;
  if($this->kind==='typed_meta')
   return (int)get_post_meta($target,$result['field'],true)===(int)$result['value'];
  $src=(int)($result['source']??0);
  $p=MAD4B_WPML_Design_Reconciliation_V1::reconcile($src,false);
  if(is_wp_error($p)||($p['unresolved_refs_or_widgets']??1)!==0)return false;
  foreach($p['translations']??[] as $x)
   if((int)($x['target']??0)===$target)return empty($x['would_update']);
  return false;
 }
 public function plan():array {
  try {$source=$this->source([]);}
  catch(Throwable $e){return ['contract'=>self::CONTRACT,'state'=>'HOLD',
    'kind'=>$this->kind,'reason'=>$e->getMessage(),'mutation_performed'=>false];}
  return ['contract'=>self::CONTRACT,'state'=>'READY','kind'=>$this->kind,
   'job_id'=>$this->jobId,'reference_id'=>$source['reference_id'],
   'source_sha256'=>$source['source_sha256'],
   'original_ability'=>$source['ability'],'mutation_performed'=>false];
 }
 public function run(string $expectedSha):array {
  $source=$this->source([]);
  if(!hash_equals($source['source_sha256'],$expectedSha))
   return ['contract'=>self::CONTRACT,'state'=>'HOLD','reason'=>'owner_source_changed'];
  $scope=MAD4B_SCP_Central_Write_Acceptance::site_scope();
  $claim=['contract'=>MAD4B_SCP_Central_Write_Acceptance::CONTRACT]+$source;
  $claim['verified_at']=$source['evidence_verified_at'];
  $claim['idempotency_key']=MAD4B_SCP_Central_Write_Acceptance::intent_key(
   $source,$scope,$source['evidence_sha256']);
  return MAD4B_SCP_Central_Write_Acceptance::execute($claim,$this);
 }
}

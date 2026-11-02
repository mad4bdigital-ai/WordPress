<?php
/**
 * ContentJob relationship writeback: first-party media owner/CWA bridge.
 * No generic WordPress metadata executor; no automatic asset-rights claims.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Relationship_Media_Write_Adapter implements MAD4B_Central_Enrichment_Write_Adapter {
 const CONTRACT='mad4b.contentjob.media-writeback.v1';
 private string $jobId;
 public function __construct(string $jobId){$this->jobId=$jobId;}
 private function binding():array {
  $plan=MAD4B_SCP_Content_Jobs::relationship_plan(['job_id'=>$this->jobId]);
  if(is_wp_error($plan)||!is_array($plan)||
    ($plan['state']??'')!=='READY')throw new RuntimeException('native_contentjob_artifact_not_bound');
  $typed=$plan['extensions']['native_typed_relations']??[];
  $image=$typed['relationships']['featured_image']??[];
  $src=$plan['source_reference']??[];
  $family=$plan['family']??[];
  if(($typed['state']??'')!=='INSPECTED'||
     ($image['status']??'')!=='CANDIDATE_REQUIRES_MEDIA_RIGHTS'||
     ($src['kind']??'')!=='post'||
     (int)($family['target_id']??0)<1 ||
     (int)($image['actual_target_attachment_id']??0)!==0)
    throw new RuntimeException('native_media_owner_candidate_not_ready');
  $target=(int)$family['target_id'];$source=(int)$src['id'];
  $localized=(int)($image['expected_target_attachment_id']??0);
  $origin=(int)($image['source_attachment_id']??0);
  $verified=MAD4B_SCP_Content_Relationship_Graph::resolve(
    'post',$origin,'attachment',(string)$family['target_locale']);
  if(($verified['state']??'')!=='RESOLVED'||
    (int)($verified['target_id']??0)!==$localized||
    !wp_attachment_is_image($localized)||!wp_attachment_is_image($origin)||
    (int)get_post_thumbnail_id($target)!==0||
    (int)get_post_thumbnail_id($source)!==$origin)
    throw new RuntimeException('media_translation_or_empty_target_changed');
  // Registered first-party evidence must come from the original Media
  // provider's rights/alt/asset certification, never client request flags.
  $proof=apply_filters('mad4b/contentjob/media_owner_verified_evidence',null,
    $localized,$origin,$target,$this->jobId);
  if(!is_array($proof) ||
    ($proof['contract']??'')!=='mad4b.media.rights-qa.v1'||
    ($proof['site_uuid']??null)!==($plan['site_uuid']??null)||
    ($proof['job_id']??null)!==$this->jobId||
    (int)($proof['target_post_id']??0)!==$target||
    (int)($proof['attachment_id']??0)!==$localized ||
    ($proof['rights_pass']??null)!==true||
    ($proof['independent_verification']??null)!==true||
    ($proof['quality_pass']??null)!==true||
    !is_int($proof['verified_at']??null)||
    !MAD4B_SCP_Central_Write_Acceptance::sha($proof['evidence_sha256']??null)||
    trim((string)get_post_meta($localized,'_wp_attachment_image_alt',true))==='')
    throw new RuntimeException('media_owner_rights_or_alt_certification_missing');
  $h=hash('sha256',wp_json_encode([
   $plan['job_id'],$plan['job_revision'],$plan['site_uuid'],
   $plan['family']['identity_sha256'],$plan['source_reference'],
   $source,$target,$origin,$localized,
   (string)get_post_meta($localized,'_wp_attached_file',true),
   (string)get_post_meta($localized,'_wp_attachment_image_alt',true),
   $proof['evidence_sha256']
  ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  return [$plan,$image,$proof,$h];
 }
 public function source(array $claim):array {
  [$plan,$image,$proof,$hash]=$this->binding();
  return ['site_uuid'=>$plan['site_uuid'],'environment'=>$plan['environment'],
   'subject_id'=>'post:'.(int)$plan['family']['target_id'],
   'ability'=>'media/set-featured','job_id'=>$this->jobId,
   'source_sha256'=>$hash,'revision'=>(string)$plan['job_revision'],
   'reference_id'=>'attachment:'.(int)$image['expected_target_attachment_id'],
   'evidence_sha256'=>$proof['evidence_sha256'],
   'evidence_verified_at'=>$proof['verified_at'],
   'independent_verification'=>$proof['independent_verification'],
   'quality_pass'=>$proof['quality_pass'],'rights_pass'=>$proof['rights_pass']];
 }
 public function authorize(array $claim,array $source):bool {
  try {[$plan,$image]=$this->binding();}
  catch(Throwable $e){return false;}
  $media=class_exists('MAD4B_SCP_Adapter_Registry',false)?
     MAD4B_SCP_Adapter_Registry::instance()->get('media'):null;
  $args=['post_id'=>(int)$plan['family']['target_id'],
    'attachment_id'=>(int)$image['expected_target_attachment_id'],
    'expected_thumbnail_id'=>0];
  $ability=wp_get_ability('media/set-featured');
  // The original Ability permission preflight is mandatory BEFORE CWA's
  // atomic claim. Otherwise an NHI/approval denial becomes an avoidable
  // UNKNOWN attempt despite no underlying WordPress mutation.
  return is_object($media)&&$media->is_available()&&
    method_exists($media,'can_set_featured') &&
    $media->can_set_featured($args)===true &&
    current_user_can('edit_post',$args['post_id']) &&
    MAD4B_SCP_Policy::can_mutate()===true &&
    is_object($ability) && method_exists($ability,'check_permissions') &&
    $ability->check_permissions($args)===true;
 }
 public function apply(array $claim,array $source):array {
  [$plan,$image]=$this->binding();
  $args=['post_id'=>(int)$plan['family']['target_id'],
    'attachment_id'=>(int)$image['expected_target_attachment_id'],
    'expected_thumbnail_id'=>0];
  $ability=wp_get_ability('media/set-featured');
  if(!$ability)return ['success'=>false,'reason'=>'owner_ability_missing'];
  $result=$ability->execute($args);
  if(is_wp_error($result))return ['success'=>false,'reason'=>$result->get_error_code()];
  return ['success'=>($result['verified']??false)===true,
    'post_id'=>$args['post_id'],'attachment_id'=>$args['attachment_id'],
    'owner_ability'=>'media/set-featured','owner_result'=>$result];
 }
 public function readback(array $claim,array $source,array $result):bool {
  $post=(int)($result['post_id']??0);$attachment=(int)($result['attachment_id']??0);
  if(!$post||!$attachment||!wp_attachment_is_image($attachment)||
    (int)get_post_thumbnail_id($post)!==$attachment)return false;
  $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$this->jobId]);
  return !is_wp_error($j)&&($j['job']['site_uuid']??'')===$source['site_uuid'] &&
   (int)($j['job']['current_artifact_id']??0)===$post &&
   ($j['job']['job_revision']??null)===(int)$source['revision'];
 }
 public function plan():array {
  try {$source=$this->source([]);}
  catch(Throwable $e){return ['contract'=>self::CONTRACT,'state'=>'HOLD',
    'reason'=>$e->getMessage(),'write_performed'=>false];}
  return ['contract'=>self::CONTRACT,'state'=>'READY',
   'job_id'=>$this->jobId,'subject_id'=>$source['subject_id'],
   'reference_id'=>$source['reference_id'],
   'source_sha256'=>$source['source_sha256'],
   'evidence_sha256'=>$source['evidence_sha256'],
   'original_ability'=>'media/set-featured',
   'write_performed'=>false];
 }
 public function run(string $expectedSourceSha):array {
  $source=$this->source([]);
  if(!hash_equals($source['source_sha256'],$expectedSourceSha))
   return ['contract'=>self::CONTRACT,'state'=>'HOLD',
     'reason'=>'original_plan_source_sha_changed','write_performed'=>false];
  $scope=MAD4B_SCP_Central_Write_Acceptance::site_scope();
  $claim=['contract'=>MAD4B_SCP_Central_Write_Acceptance::CONTRACT]+$source;
  $claim['verified_at']=$source['evidence_verified_at'];
  $claim['idempotency_key']=MAD4B_SCP_Central_Write_Acceptance::intent_key(
   $source,$scope,$source['evidence_sha256']);
  return MAD4B_SCP_Central_Write_Acceptance::execute($claim,$this);
 }
}

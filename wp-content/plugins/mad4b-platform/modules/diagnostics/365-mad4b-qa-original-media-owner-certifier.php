<?php
/**
 * Staging-only first-party media content identity certifier.
 * Restricts certification to original MAD4B QA assets with a separately
 * stored on-disk content hash + generation attestation.
 * Never infers ownership from ordinary image metadata/WordPress upload.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_QA_Media_Owner_Certifier {
 const CONTRACT='mad4b.media.rights-qa.v1';
 const ASSET='mad4b.qa.original-media-origin.v1';
 const META='_mad4b_qa_original_media_attestation_v1';
 public static function evidence($existing,$attachmentId,$originalId,$targetId,$jobId) {
  if($existing!==null || wp_get_environment_type()!=='staging' ||
    home_url()!=='https://tours.dev.mad4b.com')return $existing;
  $scope=MAD4B_SCP_Central_Write_Acceptance::site_scope();
  if(empty($scope['site_uuid']) ||!class_exists('MAD4B_SCP_Content_Jobs'))return null;
  $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>(string)$jobId]);
  if(is_wp_error($j)||($j['job']['site_uuid']??'')!==$scope['site_uuid'] ||
   ($j['job']['content_type']??'')!=='translation')return null;
  $proof=(array)get_post_meta((int)$attachmentId,self::META,true);
  if(($proof['contract']??'')!==self::ASSET ||
    ($proof['site_uuid']??'')!==$scope['site_uuid']||
    ($proof['job_id']??'')!==$jobId ||
    (int)($proof['source_attachment_id']??0)!==(int)$originalId ||
    (int)($proof['target_attachment_id']??0)!==(int)$attachmentId ||
    (int)($proof['target_post_id']??0)!==(int)$targetId ||
    ($proof['origin_kind']??'')!=='original_generated_qa_artwork'||
    ($proof['created_by_user']??0)!==26||
    ($proof['rights_basis']??'')!=='first_party_original'||
    ($proof['quality_pass']??null)!==true ||
    !is_int($proof['verified_at']??null)||
    $proof['verified_at']<time()-259200||
    $proof['verified_at']>time()+60||
    !MAD4B_SCP_Central_Write_Acceptance::sha($proof['content_sha256']??null)
  )return null;
  $file=get_attached_file((int)$attachmentId);
  $orig=get_attached_file((int)$originalId);
  $uploads=wp_get_upload_dir();
  $base=realpath($uploads['basedir']??'');
  $real=$file?realpath($file):false;
  $o=$orig?realpath($orig):false;
  if(!$base ||!$real||!$o||
    !str_starts_with($real,$base.DIRECTORY_SEPARATOR)||
    !str_starts_with($o,$base.DIRECTORY_SEPARATOR)||
    !is_readable($real)||!is_readable($o)||
    hash_file('sha256',$real)!==$proof['content_sha256']||
    hash_file('sha256',$o)!==$proof['content_sha256']||
    !wp_attachment_is_image((int)$attachmentId)||
    !wp_attachment_is_image((int)$originalId)||
    trim((string)get_post_meta((int)$attachmentId,'_wp_attachment_image_alt',true))===''
  )return null;
  $nativeHash=hash('sha256',wp_json_encode([
    self::ASSET,$proof,
    hash_file('sha256',$real),
    (string)get_post_meta((int)$attachmentId,'_wp_attachment_image_alt',true),
    (string)get_post_mime_type((int)$attachmentId)
  ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return [
   'contract'=>self::CONTRACT,'site_uuid'=>$scope['site_uuid'],
   'job_id'=>$jobId,'target_post_id'=>(int)$targetId,
   'attachment_id'=>(int)$attachmentId,
   'verified_at'=>$proof['verified_at'],'evidence_sha256'=>$nativeHash,
   'rights_pass'=>true,'independent_verification'=>true,'quality_pass'=>true
  ];
 }
 public static function register():void {
  add_filter('mad4b/contentjob/media_owner_verified_evidence',
    [__CLASS__,'evidence'],10,5);
 }
}
MAD4B_SCP_QA_Media_Owner_Certifier::register();

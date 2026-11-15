<?php
/**
 * MAD4B original Universal Media Apply adapter: licensed source import.
 * The Host-attested provider, original review ledger and CCT credit registry
 * remain the sole execution and public-attribution authorities.
 * This file is not a standalone plugin or an HTTP/unauthenticated handler.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Licensed_Media_Source_V1', false)) {
final class MAD4B_Licensed_Media_Source_V1 {
 const CONTRACT='mad4b.licensed-tour-source.v1';
 const STAGING='/tmp/mad4b-licensed-media-queue/';
 const REVIEW_PREFIX='mad4b_universal_media_source_review_';
 private static function err(string $s):WP_Error {return new WP_Error('mad4b_licensed_source_'.$s,$s);}
 private static function json(array $a):string {return (string)wp_json_encode($a,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
 private static function original_asset(array $payload):array {
  return $payload['asset']??$payload;
 }
 public static function preflight(array $asset,array $target):array|WP_Error {
  $required=['contract','site_uuid','sha256','local_filename','source_url',
    'license_url','license_label','author','subject','expected_gallery_sha256',
    'intended_use'];
  $keys=array_keys($asset);sort($keys);sort($required);
  if($keys!==$required||($asset['contract']??'')!==self::CONTRACT)
   return self::err('manifest_schema_invalid');
  if(($target['kind']??'')!=='post'||($target['type']??'')!=='tours-and-activities'
   ||get_post_status((int)$target['id'])!=='publish')return self::err('tour_target_not_published');
  $site=class_exists('MAD4B_SCP_Site_Profile',false)?MAD4B_SCP_Site_Profile::status():[];
  if(!is_array($site)||empty($site['site_uuid'])
    ||!hash_equals((string)$site['site_uuid'],(string)$asset['site_uuid'])
    ||empty($site['origin_match'])||empty($site['environment_match'])
    ||empty($site['deployment_binding_match']))return self::err('site_identity_not_bound');
  $sha=$asset['sha256'];$file=$asset['local_filename'];
  if(!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha)
    ||!is_string($file)||!hash_equals($sha.'.jpg',$file))
   return self::err('immutable_original_filename_invalid');
  $root=realpath(self::STAGING);$path=$root?realpath($root.'/'.$file):false;
  if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)
    ||!is_file($path)||!is_readable($path)||filesize($path)<20000||filesize($path)>10000000)
   return self::err('staging_source_missing_or_invalid');
  if(!hash_equals($sha,(string)hash_file('sha256',$path)))return self::err('source_original_digest_drift');
  $info=@getimagesize($path);
  if(!is_array($info)||($info[2]??null)!==IMAGETYPE_JPEG||$info[0]<1200||$info[1]<700)
   return self::err('genuine_photo_dimensions_invalid');
  foreach(['source_url','license_url'] as $k){$u=wp_parse_url((string)$asset[$k]);
   if(!is_array($u)||($u['scheme']??'')!=='https'||!empty($u['user'])||!empty($u['pass']))
    return self::err('https_provenance_required');
  }
  if(wp_parse_url((string)$asset['source_url'],PHP_URL_HOST)!=='commons.wikimedia.org'
    ||!preg_match('~^https://commons\.wikimedia\.org/wiki/File:[^?#]{4,220}$~D',(string)$asset['source_url'])
    ||wp_parse_url((string)$asset['license_url'],PHP_URL_HOST)!=='creativecommons.org')
   return self::err('licensed_source_publisher_invalid');
  if(!preg_match('~^https://creativecommons\.org/licenses/by/(3\.0|4\.0)/$~D',(string)$asset['license_url']))
   return self::err('license_scheme_not_reviewed');
  $license='CC BY '.(str_contains($asset['license_url'],'/3.0/')?'3.0':'4.0');
  if(($asset['license_label']??'')!==$license
    ||!is_string($asset['author'])||mb_strlen(trim($asset['author']))<5
    ||mb_strlen($asset['author'])>160
    ||!is_string($asset['subject'])||mb_strlen(trim($asset['subject']))<10
    ||mb_strlen($asset['subject'])>180
    ||($asset['intended_use']??'')!=='illustrative_nile_cruise_vessel_not_guaranteed')
   return self::err('source_editorial_and_license_metadata_invalid');
  $trid=apply_filters('wpml_element_trid',null,(int)$target['id'],'post_tours-and-activities');
  $family=$trid?apply_filters('wpml_get_element_translations',[],$trid,'post_tours-and-activities'):[];
  if(!isset($family['en'],$family['es'])||(int)$family['en']->element_id!==(int)$target['id']
    ||get_post_status((int)$family['es']->element_id)!=='publish')return self::err('wpml_en_es_family_not_verified');
  $gallery=(string)get_post_meta((int)$target['id'],'gallery',true);
  if(!is_string($asset['expected_gallery_sha256'])
    ||!hash_equals(hash('sha256',$gallery),$asset['expected_gallery_sha256']))
   return self::err('published_gallery_changed_since_review');
  return ['contract'=>self::CONTRACT,'status'=>'source_preflight_verified',
   'source_sha256'=>$sha,'original_file'=>$path,'source_page'=>$asset['source_url'],
   'license_label'=>$license,'original_dimensions'=>[$info[0],$info[1]],
   'source_tour_id'=>(int)$target['id'],'spanish_tour_id'=>(int)$family['es']->element_id,
   'rights_review_approval'=>false,'image_imported'=>false,'tour_hero_changed'=>false];
 }
 private static function approved_actor():array|WP_Error {
  if(!class_exists('MAD4B_Universal_Media_Apply_V1',false))return self::err('original_router_not_loaded');
  $actor=MAD4B_Universal_Media_Apply_V1::signed_media_source_reviewer();
  if(is_wp_error($actor))return $actor;
  return $actor;
 }
 private static function key(array $asset,array $target):string {
  return self::REVIEW_PREFIX.hash('sha256',self::json(['asset'=>$asset,'target'=>$target]));
 }
 private static function wpml_copy(int $id):int|WP_Error {
  // WPML's *already initialized* source duplication object, not a second
  // custom translation handler or manual icl_translations table mutation.
  $hook=$GLOBALS['wp_filter']['add_attachment']??null;
  foreach((array)($hook->callbacks??[]) as $entries){
   foreach($entries as $item){$fn=$item['function']??null;
    if(!is_array($fn)||!is_object($fn[0])||!($fn[0] instanceof WPML_Media_Attachments_Duplication))continue;
    if(!method_exists($fn[0],'create_duplicate_attachment'))continue;
    $result=$fn[0]->create_duplicate_attachment($id,0,'es');
    return is_int($result)&&$result>0?$result:self::err('wpml_original_duplication_failed');
   }
  }
  return self::err('wpml_original_media_provider_unavailable');
 }
 public static function run(array $payload,array $target,string $action):array|WP_Error {
  if(!in_array($action,['preflight','review','apply'],true))return self::err('unsupported_action');
  if($action==='review'){
   $keys=array_keys($payload);sort($keys);$expect=['asset','review'];sort($expect);
   if($keys!==$expect||!is_array($payload['asset'])||!is_array($payload['review']))
    return self::err('review_schema_invalid');
  }
  $asset=$action==='review'?$payload['asset']:$payload;
  $check=self::preflight($asset,$target);
  if(is_wp_error($check))return $check;
  if($action==='preflight')return $check;
  $actor=self::approved_actor();if(is_wp_error($actor))return $actor;
  $key=self::key($asset,$target);
  if($action==='review'){
   $review=$payload['review'];$expect=['decision','reviewed_sha256','visual_match_verified',
     'rights_verified','visual_description','rights_evidence'];$keys=array_keys($review);
   sort($keys);sort($expect);
   if($keys!==$expect||($review['decision']??'')!=='approve'
     ||($review['visual_match_verified']??false)!==true||($review['rights_verified']??false)!==true
     ||!hash_equals((string)$asset['sha256'],(string)$review['reviewed_sha256'])
     ||!is_string($review['visual_description'])||mb_strlen(trim($review['visual_description']))<70
     ||!is_string($review['rights_evidence'])||mb_strlen(trim($review['rights_evidence']))<90
     ||!str_contains($review['rights_evidence'],$asset['source_url'])
     ||!str_contains($review['rights_evidence'],$asset['license_url'])
     ||!str_contains($review['rights_evidence'],$asset['author']))
    return self::err('signed_photo_and_rights_review_incomplete');
   $old=get_option($key,null);
   if(is_array($old))return self::err('review_already_exists_reconcile');
   $receipt=['contract'=>self::CONTRACT,'status'=>'approved',
    'site_uuid'=>$actor['site_uuid'],'agent_public_id'=>$actor['agent_public_id'],
    'asset_sha256'=>$asset['sha256'],'scope_sha256'=>substr($key,strlen(self::REVIEW_PREFIX)),
    'review_sha256'=>hash('sha256',self::json($review)),
    'approved_utc'=>gmdate('c'),'expires_at'=>time()+86400];
   $rec=MAD4B_SCP_Audit::record('mad4b/signed-licensed-source-photo-review',[
    'site_uuid'=>$actor['site_uuid'],'agent_public_id'=>$actor['agent_public_id'],
    'post_id'=>$target['id'],'image_sha256'=>$asset['sha256'],
    'review_sha256'=>$receipt['review_sha256']]);
   if(is_wp_error($rec))return $rec;
   if(!add_option($key,$receipt,'','no'))return self::err('review_ledger_insert_failed');
   return ['status'=>'original_media_review_approved','rights_verified'=>true,
    'write_executed'=>false,'review_sha256'=>$receipt['review_sha256']];
  }
  $receipt=get_option($key,[]);
  if(!is_array($receipt)||($receipt['status']??'')!=='approved'
    ||(int)($receipt['expires_at']??0)<=time()
    ||!hash_equals((string)$actor['site_uuid'],(string)($receipt['site_uuid']??''))
    ||!hash_equals((string)$actor['agent_public_id'],(string)($receipt['agent_public_id']??''))
    ||!hash_equals((string)$asset['sha256'],(string)($receipt['asset_sha256']??'')))
   return self::err('fresh_signed_source_review_required');
  if(!class_exists('MAD4B_Media_Attribution_Registry_V1',false)
    ||!class_exists('MAD4B_SCP_Distributed_Lock',false))return self::err('original_credit_and_lock_provider_missing');
  $lock=MAD4B_SCP_Distributed_Lock::catalog_name('licensed-source-import:'.$asset['sha256']);
  $got=MAD4B_SCP_Distributed_Lock::acquire($lock);
  if(is_wp_error($got))return $got;
  $created=0;$created_es=0;$raw_path='';$credit_key='licensed_'.substr($asset['sha256'],0,16);
  $oldcredit=null;$credit_changed=false;$result=null;
  try {
   if(get_option($key,[])!==$receipt)throw new RuntimeException('approval_used_or_changed');
   $oldcredit=MAD4B_Media_Attribution_Registry_V1::credit_record($credit_key);
   if($oldcredit && ((string)$oldcredit['source_sha256']!==$asset['sha256']
     ||(string)$oldcredit['author']!==$asset['author']))throw new RuntimeException('existing_rights_credit_conflict');
   $duplicates=get_posts(['post_type'=>'attachment','post_status'=>'inherit',
    'posts_per_page'=>5,'fields'=>'ids','suppress_filters'=>true,
    'meta_query'=>[['key'=>'_mad4b_verified_source_sha256','value'=>$asset['sha256']]]]);
   $id=0;
   foreach($duplicates as $dup){$f=get_attached_file((int)$dup);
    if(is_file((string)$f)&&hash_equals($asset['sha256'],(string)hash_file('sha256',$f))){$id=(int)$dup;break;}}
   if(!$id){
    $bytes=file_get_contents($check['original_file']);
    if(!is_string($bytes)||!hash_equals($asset['sha256'],hash('sha256',$bytes)))
     throw new RuntimeException('source_file_changed_under_review');
    $stored=wp_upload_bits(sanitize_file_name($asset['subject']).'-'.substr($asset['sha256'],0,16).'.jpg',null,$bytes);
    if(!empty($stored['error'])||!is_file((string)($stored['file']??'')))
     throw new RuntimeException('native_media_upload_failed');
    $raw_path=$stored['file'];
    $id=wp_insert_attachment(['post_title'=>sanitize_text_field($asset['subject']),
       'post_status'=>'inherit','post_mime_type'=>'image/jpeg',
       'post_excerpt'=>'Photo: '.sanitize_text_field($asset['author']).' · '.sanitize_text_field($asset['license_label'])
           .' · '.esc_url_raw($asset['source_url']),
       'post_content'=>'Source: '.esc_url_raw($asset['source_url']).' · License: '.esc_url_raw($asset['license_url'])],
        $raw_path,0,true);
    if(is_wp_error($id)||!is_int($id)||$id<1)throw new RuntimeException('native_attachment_insert_failed');
    $created=$id;
    require_once ABSPATH.'wp-admin/includes/image.php';
    wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$raw_path));
    update_post_meta($id,'_wp_attachment_image_alt','Nile cruise passenger ships near Luxor, illustrative vessels');
    update_post_meta($id,'_mad4b_verified_source_sha256',$asset['sha256']);
    update_post_meta($id,'_mad4b_license_source_url',$asset['source_url']);
    update_post_meta($id,'_mad4b_license',$asset['license_label']);
    update_post_meta($id,'_mad4b_creator',$asset['author']);
    $source_language=apply_filters('wpml_element_language_details',null,['element_id'=>$id,'element_type'=>'post_attachment']);
    if(!is_object($source_language)||($source_language->language_code??'')!=='en'){
     do_action('wpml_set_element_language_details',['element_id'=>$id,'element_type'=>'post_attachment','language_code'=>'en']);
    }
    $spanish=self::wpml_copy($id);
    if(is_wp_error($spanish))throw new RuntimeException($spanish->get_error_code());
    $created_es=$spanish;
   }
   if(!is_file((string)get_attached_file($id))
     ||!hash_equals($asset['sha256'],(string)hash_file('sha256',get_attached_file($id))))
    throw new RuntimeException('source_photo_post_upload_digest_drift');
   $translations=MAD4B_WPML_Attachment_Family_Readback_V1::translations($id);
   if(empty($translations['en'])||empty($translations['es']))throw new RuntimeException('wpml_photo_translation_incomplete');
   foreach(['en','es'] as $language){$lid=(int)$translations[$language]->element_id;$file=get_attached_file($lid);
    if(!is_file((string)$file)||!hash_equals($asset['sha256'],(string)hash_file('sha256',$file)))
     throw new RuntimeException('wpml_photo_binary_mismatch');}
   $credit_changed=true;
   if(!MAD4B_Media_Attribution_Registry_V1::upsert_credit($credit_key,[
     'subject'=>$asset['subject'],'author'=>$asset['author'],'source_url'=>$asset['source_url'],
     'license_url'=>$asset['license_url'],'license_label'=>$asset['license_label'],
     'source_sha256'=>$asset['sha256'],'attachment_id'=>$id]))throw new RuntimeException('original_rights_credit_persist_failed');
   $record=MAD4B_Media_Attribution_Registry_V1::credit_record($credit_key);
   if(!is_array($record)||($record['cct_status']??'')!=='publish'
     ||(string)$record['source_sha256']!==$asset['sha256'])
    throw new RuntimeException('original_rights_credit_readback_failed');
   $audit=MAD4B_SCP_Audit::record('mad4b/signed-source-image-library-import',[
      'site_uuid'=>$actor['site_uuid'],'post_id'=>$target['id'],
      'attachment_id'=>$id,'source_sha256'=>$asset['sha256'],
      'license_label'=>$asset['license_label'],'credit_key'=>$credit_key]);
   if(is_wp_error($audit))throw new RuntimeException('native_image_import_audit_failed');
   $receipt['status']='applied';$receipt['attachment_id']=$id;
   $receipt['credit_key']=$credit_key;$receipt['receipt_sha256']=hash('sha256',self::json($receipt));
   if(!update_option($key,$receipt,false))throw new RuntimeException('review_receipt_not_consumed');
   $result=['contract'=>self::CONTRACT,'status'=>'verified_license_library_import',
      'source_sha256'=>$asset['sha256'],'attachment_id'=>$id,
      'wpml_en_id'=>(int)$translations['en']->element_id,
      'wpml_es_id'=>(int)$translations['es']->element_id,
      'credit_key'=>$credit_key,'original_host_approval_consumed'=>true,
      'tour_hero_changed'=>false,'tour_gallery_changed'=>false,
      'rollback_supported'=>true,'write_executed'=>true];
  } catch(Throwable $e) {
   $restore_ok=!$credit_changed || MAD4B_Media_Attribution_Registry_V1::restore_credit($credit_key,$oldcredit);
   if($created_es)wp_delete_attachment($created_es,true);
   if($created)wp_delete_attachment($created,true);
   elseif($raw_path && is_file($raw_path))@unlink($raw_path);
   $result=self::err($restore_ok?'import_failed_rolled_back':'import_failed_credit_reconcile_required');
  } finally {MAD4B_SCP_Distributed_Lock::release($lock);}
  return $result;
 }
}
}

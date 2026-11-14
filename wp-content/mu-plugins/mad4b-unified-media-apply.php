<?php
/**
 * Plugin Name: MAD4B Universal Media Apply Router
 * Description: Signed, typed media routing for public posts, taxonomies and registered JetEngine media adapters.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Universal_Media_Apply_V1',false)) {
final class MAD4B_Universal_Media_Apply_V1 {
 const CONTRACT='mad4b.media.apply.v1';
 public static function adapters():array {
  $builtins=[
   'tour_association'=>['target_kind'=>'post','types'=>['tours-and-activities'],'storage'=>'_mad4b_tour_media_association_v1','signed_write'=>true],
   'term_featured_image'=>['target_kind'=>'term','types'=>'registered_public','storage'=>'featured_image','signed_write'=>true],
   'post_featured_image'=>['target_kind'=>'post','types'=>'registered_public','storage'=>'_thumbnail_id','signed_write'=>true],
   'post_registered_image'=>['target_kind'=>'post','types'=>'registered_public','storage'=>'registered_meta','signed_write'=>true],
   'term_registered_image'=>['target_kind'=>'term','types'=>'registered_public','storage'=>'registered_meta','signed_write'=>true],
   'post_registered_composite'=>['target_kind'=>'post','types'=>'registered_public','storage'=>'registered_composite','signed_write'=>true],
   'term_registered_composite'=>['target_kind'=>'term','types'=>'registered_public','storage'=>'registered_composite','signed_write'=>true],
  ];
  $all=apply_filters('mad4b/media/apply/adapters',$builtins);
  return is_array($all)?$all:$builtins;
 }
 private static function err(string $code):WP_Error {return new WP_Error($code,$code);}
 public static function check(array $input):array|WP_Error {
  $expected=['contract','target','adapter','action','payload'];$keys=array_keys($input);sort($expected);sort($keys);
  if($keys!==$expected || ($input['contract']??null)!==self::CONTRACT)return self::err('mad4b_unified_media_schema_invalid');
  $target=$input['target'];if(!is_array($target))return self::err('mad4b_unified_media_target_invalid');
  $keys=array_keys($target);sort($keys);$expected=['kind','type','id'];sort($expected);
  if($keys!==$expected||!in_array($target['kind'],['post','term'],true)||!is_string($target['type'])||!preg_match('/^[a-zA-Z0-9_-]{2,80}$/D',$target['type'])||!is_int($target['id'])||$target['id']<1)return self::err('mad4b_unified_media_target_invalid');
  $kind=$target['kind'];$type=$target['type'];$id=$target['id'];
  if($kind==='post') {
   $post=get_post($id);$registered=get_post_type_object($type);
   if(!$post||$post->post_type!==$type||!$registered||!$registered->public)return self::err('mad4b_unified_media_post_not_public');
  } else {
   $term=get_term($id,$type);$registered=get_taxonomy($type);
   if(!$term instanceof WP_Term||!$registered||!$registered->public)return self::err('mad4b_unified_media_term_not_public');
  }
  $name=$input['adapter'];$registry=self::adapters();
  if(!is_string($name)||!preg_match('/^[a-z][a-z0-9_-]{2,63}$/D',$name)||!isset($registry[$name]))return self::err('mad4b_unified_media_adapter_not_registered');
  $definition=$registry[$name];
  if(($definition['target_kind']??null)!==$kind||((($definition['types']??null)!=='registered_public')&&!in_array($type,(array)($definition['types']??[]),true)))return self::err('mad4b_unified_media_adapter_target_mismatch');
  if(!in_array($input['action'],['preflight','review','apply','revoke'],true)||!is_array($input['payload']))return self::err('mad4b_unified_media_action_invalid');
  return ['contract'=>self::CONTRACT,'target'=>$target,'adapter'=>$name,'action'=>$input['action'],'signed_write_supported'=>!empty($definition['signed_write'])];
 }
 /** Media fields may be contributed by JetEngine and WP register_meta
  * adapters, but never taken from untrusted request bodies alone.
  */
 public static function allowed_fields(string $kind,string $type):array {
  $registered=[];
  $meta=get_registered_meta_keys($kind==='term'?'term':'post',$type);
  foreach((array)$meta as $name=>$schema){
   if(!is_string($name) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]{1,79}$/D',$name))continue;
   if(!in_array(($schema['type']??''),['integer','string'],true))continue;
   if(empty($schema['single']))continue;
   // Generic registered business fields are not image slots, even if
   // represented as a string or number. A specialized JetEngine adapter may
   // explicitly register exceptional custom image field names.
   if(!preg_match('/(?:image|photo|thumbnail|logo|cover|hero|poster|media)/i',$name))continue;
   $registered[]=$name;
  }
  // JetEngine meta definitions are authoritative for media fields that do
  // not register themselves through WordPress register_meta (notably terms).
  // Gallery/repeater fields are intentionally excluded from scalar writes.
  if(function_exists('jet_engine')){
   $je=jet_engine();$jet_fields=[];
   try{
    if($kind==='term' && isset($je->taxonomies))
     $jet_fields=$je->taxonomies->get_meta_fields_for_object($type);
    elseif($kind==='post' && isset($je->meta_boxes))
     $jet_fields=$je->meta_boxes->get_meta_fields_for_object($type);
   }catch(Throwable $ignored){$jet_fields=[];}
   foreach((array)$jet_fields as $f){
    if(!is_array($f)||($f['type']??'')!=='media')continue;
    $name=$f['name']??'';
    if(is_string($name)&&preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]{1,79}$/D',$name))$registered[]=$name;
   }
  }
  $fields=apply_filters('mad4b/media/apply/fields',$registered,$kind,$type);
  if(!is_array($fields))return [];
  return array_slice(array_values(array_unique(array_filter($fields,static fn($x)=>
    is_string($x)&&preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]{1,79}$/D',$x)))),0,100);
 }
 public static function local_attachment_preflight(array $payload,array $target,string $adapter):array|WP_Error {
  $required=['contract','site_uuid','field','attachment_id','sha256','expected_value_sha256'];
  $keys=array_keys($payload);sort($keys);sort($required);
  if($keys!==$required||($payload['contract']??'')!=='mad4b.media.local-attachment.v1')return self::err('mad4b_unified_media_attachment_schema_invalid');
  if(!class_exists('MAD4B_SCP_Host_Agent_Bridge',false))return self::err('mad4b_unified_media_host_bridge_missing');
  $site=MAD4B_SCP_Host_Agent_Bridge::site();
  if(!$site||!hash_equals((string)$site['site_uuid'],(string)$payload['site_uuid']))return self::err('mad4b_unified_media_site_mismatch');
  $kind=$target['kind'];$type=$target['type'];$id=$target['id'];$field=$payload['field'];
  if(!is_string($field)||!in_array($field,self::allowed_fields($kind,$type),true))return self::err('mad4b_unified_media_field_not_registered');
  $aid=$payload['attachment_id'];$sha=$payload['sha256'];$prev=$payload['expected_value_sha256'];
  if(!is_int($aid)||$aid<1||get_post_type($aid)!=='attachment'||!str_starts_with((string)get_post_mime_type($aid),'image/')||
   !is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha)||!is_string($prev)||!preg_match('/^[a-f0-9]{64}$/D',$prev))return self::err('mad4b_unified_media_attachment_identity_invalid');
  $file=get_attached_file($aid);
  if(!is_string($file)||!is_file($file)||!hash_equals($sha,hash_file('sha256',$file)))return self::err('mad4b_unified_media_attachment_hash_mismatch');
  $getter=$kind==='post'?'get_post_meta':'get_term_meta';
  $old=$getter($id,$field,true);
  $fingerprint=hash('sha256',wp_json_encode($old,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  if(!hash_equals($prev,$fingerprint))return self::err('mad4b_unified_media_value_drift');
  return ['contract'=>self::CONTRACT,'status'=>'existing_attachment_preflight_valid',
    'target'=>$target,'adapter'=>$adapter,'field'=>$field,'attachment_id'=>$aid,
    'attachment_sha256'=>$sha,'old_value_sha256'=>$fingerprint,
    'write_executed'=>false,'rights_verified'=>false,'approval_verified'=>false];
 }
 const REVIEW_OPTION='mad4b_media_reviewed_asset_v1_';
 private static function approval_key(array $asset):string {
  return self::REVIEW_OPTION.hash('sha256',wp_json_encode($asset,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 }
 private static function signed_ai():array|WP_Error {
  if(!class_exists('MAD4B_SCP_Host_Agent_Bridge',false)||!MAD4B_SCP_Host_Agent_Bridge::active())
   return self::err('mad4b_media_signed_host_required');
  $policy=class_exists('MAD4B_SCP_Context_Authority',false)
      ?MAD4B_SCP_Context_Authority::ai_review_policy_status():[];
  $agent=MAD4B_SCP_Host_Agent_Bridge::agent();
  if(!is_array($policy)||!($policy['ready']??false)||($policy['mode']??'')!=='ai_only'
      ||!is_array($agent)||!hash_equals((string)($policy['ai_agent_public_id']??''),(string)($agent['public_id']??'')))
   return self::err('mad4b_media_ai_reviewer_not_delegated');
  return ['agent_public_id'=>$agent['public_id'],'site_uuid'=>MAD4B_SCP_Host_Agent_Bridge::site()['site_uuid']];
 }
 private static function review_term(array $payload,array $target):array|WP_Error {
  $keys=array_keys($payload);sort($keys);$expected=['asset','review'];sort($expected);
  if($keys!==$expected || !is_array($payload['asset']) || !is_array($payload['review']))
   return self::err('mad4b_media_review_schema_invalid');
  $asset=$payload['asset'];$review=$payload['review'];
  if((int)($asset['term_id']??0)!==$target['id']||($asset['taxonomy']??'')!==$target['type'])
   return self::err('mad4b_media_review_target_mismatch');
  $check=MAD4B_Licensed_Term_Media_Apply_V1::validate($asset);
  if(is_wp_error($check))return $check;
  $expected=['decision','reviewed_sha256','subject_description','geographic_evidence','license_verified','visual_match_verified'];
  $keys=array_keys($review);sort($keys);sort($expected);
  if($keys!==$expected || ($review['decision']??'')!=='approve'
      || ($review['license_verified']??false)!==true || ($review['visual_match_verified']??false)!==true
      ||!hash_equals((string)$asset['sha256'],(string)$review['reviewed_sha256'])
      || mb_strlen((string)$review['subject_description'])<45
      ||mb_strlen((string)$review['geographic_evidence'])<25)
   return self::err('mad4b_media_review_evidence_incomplete');
  $actor=self::signed_ai();if(is_wp_error($actor))return $actor;
  if(!class_exists('MAD4B_SCP_Audit'))return self::err('mad4b_media_audit_unavailable');
  $key=self::approval_key($asset);
  $existing=get_option($key,[]);
  if(is_array($existing) && ($existing['status']??'')==='approved' && (int)($existing['expires_at']??0)>time())
   return ['status'=>'review_already_approved','approval_sha256'=>$existing['approval_sha256'],'expires_at'=>$existing['expires_at'],'write_executed'=>false];
  $approved=['contract'=>'mad4b.media.reviewed-asset.v1','status'=>'approved',
   'site_uuid'=>$actor['site_uuid'],'agent_public_id'=>$actor['agent_public_id'],
   'asset_sha256'=>$asset['sha256'],'asset_contract_sha256'=>substr($key,strlen(self::REVIEW_OPTION)),
   'subject_description'=>sanitize_text_field($review['subject_description']),
   'geographic_evidence'=>sanitize_text_field($review['geographic_evidence']),
   'approved_at'=>gmdate('c'),'expires_at'=>time()+86400];
  $approved['approval_sha256']=hash('sha256',wp_json_encode($approved,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $audit=MAD4B_SCP_Audit::record('mad4b/media-ai-license-review-approved',[
   'site_uuid'=>$actor['site_uuid'],'agent_public_id'=>$actor['agent_public_id'],
   'target_kind'=>'term','type'=>$target['type'],'id'=>$target['id'],
   'asset_sha256'=>$asset['sha256'],'approval_sha256'=>$approved['approval_sha256'],
   'license_url'=>$asset['license_url'],'source_url'=>$asset['source_url']]);
  if(is_wp_error($audit))return $audit;
  if(!add_option($key,$approved,'','no'))return self::err('mad4b_media_review_ledger_write_failed');
  return ['status'=>'review_approved_signed','approval_sha256'=>$approved['approval_sha256'],
   'agent_public_id'=>$actor['agent_public_id'],'expires_at'=>$approved['expires_at'],'write_executed'=>false];
 }
 private static function apply_term(array $asset,array $target):array|WP_Error {
  if((int)($asset['term_id']??0)!==$target['id']||($asset['taxonomy']??'')!==$target['type'])
   return self::err('mad4b_media_apply_target_mismatch');
  $validation=MAD4B_Licensed_Term_Media_Apply_V1::validate($asset);
  if(is_wp_error($validation))return $validation;
  $actor=self::signed_ai();if(is_wp_error($actor))return $actor;
  $key=self::approval_key($asset);$approved=get_option($key,[]);
  if(!is_array($approved)||($approved['status']??'')!=='approved'||(int)($approved['expires_at']??0)<=time()
     ||!hash_equals((string)($approved['agent_public_id']??''),(string)$actor['agent_public_id'])
     ||!hash_equals((string)($approved['site_uuid']??''),(string)$actor['site_uuid'])
     ||!hash_equals((string)($approved['asset_sha256']??''),(string)$asset['sha256']))
   return self::err('mad4b_media_apply_approved_review_missing');
  if(!class_exists('MAD4B_Media_Attribution_Registry_V1',false))
   return self::err('mad4b_media_attribution_registry_missing');
  // Exact translated term family only; never update another taxonomy or tenant.
  $id=$target['id'];$trid=apply_filters('wpml_element_trid',null,$id,'tax_'.$target['type']);
  $translations=$trid?apply_filters('wpml_get_element_translations',[],$trid,'tax_'.$target['type']):[];
  $ids=[$id];foreach((array)$translations as $tr){$tid=(int)($tr->element_id??0);
   if($tid>0&&get_term($tid,$target['type']) instanceof WP_Term&&!in_array($tid,$ids,true))$ids[]=$tid;}
  $previous=[];foreach($ids as $tid){$previous[$tid]=(string)get_term_meta($tid,'featured_image',true);
   if($previous[$tid]!==$asset['expected_featured_image'])return self::err('mad4b_media_apply_translation_preimage_drift');}
  $lock='mad4b_media_apply_lock_'.$target['type'].'_'.$id;
  if(!add_option($lock,['at'=>time(),'approval'=>$approved['approval_sha256']],'','no'))return self::err('mad4b_media_apply_concurrent_operation');
  $created=0;$upload_path='';$changed=[];$credits_before=null;$credit_key='';
  $credits_updated=false;$result=null;
  try{
   $attachment_ids=get_posts(['post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>3,'fields'=>'ids',
    'meta_query'=>[['key'=>'_mad4b_verified_source_sha256','value'=>$asset['sha256']]],'suppress_filters'=>true]);
   $aid=0;
   foreach($attachment_ids as $candidate){$f=get_attached_file($candidate);
    if(is_file((string)$f)&&hash_equals($asset['sha256'],hash_file('sha256',$f))){$aid=(int)$candidate;break;}}
   if(!$aid){
    $bytes=file_get_contents($validation['source_file']);
    if(!is_string($bytes))throw new Exception('asset_read_failed');
    $file=wp_upload_bits(sanitize_file_name($asset['subject']).'-licensed-'.substr($asset['sha256'],0,12).'.jpg',null,$bytes);
    if(!empty($file['error']))throw new Exception('media_upload_failed');
    $upload_path=$file['file'];
    $aid=wp_insert_attachment(['post_title'=>sanitize_text_field($asset['subject']).' — '.sanitize_text_field($asset['author']),
      'post_status'=>'inherit','post_mime_type'=>'image/jpeg',
      'post_excerpt'=>'Photo: '.sanitize_text_field($asset['author']).' · '.sanitize_text_field($asset['license_label']).' · '.esc_url_raw($asset['source_url']),
      'post_content'=>'Source: '.esc_url_raw($asset['source_url']).' License: '.esc_url_raw($asset['license_url'])],$upload_path,0,true);
    if(is_wp_error($aid))throw new Exception('attachment_create_failed');
    $created=(int)$aid;
    require_once ABSPATH.'wp-admin/includes/image.php';
    wp_update_attachment_metadata($aid,wp_generate_attachment_metadata($aid,$upload_path));
    update_post_meta($aid,'_wp_attachment_image_alt',sanitize_text_field($asset['subject']).', Egypt');
    update_post_meta($aid,'_mad4b_verified_source_sha256',$asset['sha256']);
    update_post_meta($aid,'_mad4b_license_source_url',$asset['source_url']);
    update_post_meta($aid,'_mad4b_license',$asset['license_label']);
    update_post_meta($aid,'_mad4b_creator',$asset['author']);
   }
   $url=wp_get_attachment_url($aid);
   if(!is_string($url)||!str_starts_with($url,home_url('/'))||!is_file((string)get_attached_file($aid)))
     throw new Exception('attachment_readback_invalid');
   foreach($ids as $tid){
    if((string)get_term_meta($tid,'featured_image',true)!==$previous[$tid])throw new Exception('concurrent_term_change');
    if(!update_term_meta($tid,'featured_image',$url))throw new Exception('term_meta_save_failed');
    $changed[]=$tid;clean_term_cache($tid,$target['type']);
   }
   $credit_key='licensed_'.substr($asset['sha256'],0,16);
   $credits_before=MAD4B_Media_Attribution_Registry_V1::credit_record($credit_key);
   // Mark the CCT snapshot for compensation BEFORE writing: a partially failed
   // JetEngine insert must also be removed during the enclosing media rollback.
   $credits_updated=true;
   if(!MAD4B_Media_Attribution_Registry_V1::upsert_credit($credit_key,[
     'subject'=>$asset['subject'],'author'=>$asset['author'],'source_url'=>$asset['source_url'],
     'license_url'=>$asset['license_url'],'license_label'=>$asset['license_label'],
     'source_sha256'=>$asset['sha256'],'attachment_id'=>$aid]))throw new Exception('credits_cct_update_failed');
   $saved_credit=MAD4B_Media_Attribution_Registry_V1::credit_record($credit_key);
   if(!is_array($saved_credit)||$saved_credit['author']!==$asset['author'])throw new Exception('credits_cct_readback_failed');
   foreach($ids as $tid)if(get_term_meta($tid,'featured_image',true)!==$url)throw new Exception('image_readback_mismatch');
   $receipt=['status'=>'applied_verified','site_uuid'=>$actor['site_uuid'],'adapter'=>'term_featured_image',
    'term_ids'=>$ids,'attachment_id'=>$aid,'image_url'=>$url,'image_sha256'=>$asset['sha256'],
    'approval_sha256'=>$approved['approval_sha256'],'license'=>$asset['license_label'],
    'previous_values'=>$previous,'rollback'=>'restore_term_meta_and_remove_new_attachment_if_unused',
    'new_attachment'=>$created!==0,'attribution_visible'=>true,'write_executed'=>true];
   $audit=MAD4B_SCP_Audit::record('mad4b/signed-media-apply',[
    'site_uuid'=>$actor['site_uuid'],'approval_sha256'=>$approved['approval_sha256'],
    'target_kind'=>'term','type'=>$target['type'],'id'=>$id,'sha256'=>$asset['sha256'],'attachment_id'=>$aid]);
   if(is_wp_error($audit))throw new Exception('audit_receipt_failed');
   $receipt['receipt_sha256']=hash('sha256',wp_json_encode($receipt,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
   $approved['status']='applied';$approved['receipt_sha256']=$receipt['receipt_sha256'];
   if(!update_option($key,$approved,false))throw new Exception('approval_consumption_failed');
   $result=$receipt;
  }catch(Throwable $e){
   foreach($changed as $tid){if($previous[$tid]==='')delete_term_meta($tid,'featured_image');else update_term_meta($tid,'featured_image',$previous[$tid]);clean_term_cache($tid,$target['type']);}
   $credits_rollback_ok=!$credits_updated || MAD4B_Media_Attribution_Registry_V1::restore_credit($credit_key,$credits_before);
   if($created)wp_delete_attachment($created,true);elseif($upload_path && is_file($upload_path))@unlink($upload_path);
   $result=self::err($credits_rollback_ok?'mad4b_media_apply_failed_rolled_back':'mad4b_media_apply_cct_rollback_requires_reconciliation');
  }finally{delete_option($lock);}
  return $result;
 }
 /** Reviewed, one-use local-attachment approval for native/JetEngine media. */
 private static function local_review_key(array $target,string $adapter,array $asset):string {
  return self::REVIEW_OPTION.'local_'.hash('sha256',wp_json_encode([
    'target'=>$target,'adapter'=>$adapter,'asset'=>$asset],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 }
 private static function image_slot(array $target,string $adapter,string $field):array|WP_Error {
  $kind=$target['kind'];$type=$target['type'];
  if($adapter==='post_featured_image'){
   if($kind!=='post'||$field!=='_thumbnail_id')return self::err('mad4b_media_image_slot_mismatch');
   return ['format'=>'id','field'=>'_thumbnail_id'];
  }
  if(!in_array($adapter,['post_registered_image','term_registered_image'],true))return self::err('mad4b_media_image_slot_adapter_unknown');
  if(!in_array($field,self::allowed_fields($kind,$type),true))return self::err('mad4b_media_image_slot_unregistered');
  $schema=get_registered_meta_keys($kind==='post'?'post':'term',$type);
  $declared=$schema[$field]??[];
  $format=($declared['type']??'')==='integer'?'id':'url';
  // A company JetEngine adapter may explicitly choose a storage format;
  // absent a registered image slot there is no write authorization.
  if(function_exists('jet_engine')){
   $je=jet_engine();$jet_fields=[];
   try{
    if($kind==='term' && isset($je->taxonomies))$jet_fields=$je->taxonomies->get_meta_fields_for_object($type);
    elseif($kind==='post' && isset($je->meta_boxes))$jet_fields=$je->meta_boxes->get_meta_fields_for_object($type);
   }catch(Throwable $ignored){$jet_fields=[];}
   foreach((array)$jet_fields as $definition){
    if(!is_array($definition)||($definition['name']??'')!==$field||($definition['type']??'')!=='media')continue;
    $value_format=$definition['value_format']??'';
    if(in_array($value_format,['id','url'],true))$format=$value_format;
   }
  }
  $mapped=apply_filters('mad4b/media/apply/field_format',$format,$kind,$type,$field);
  if(!in_array($mapped,['id','url'],true))return self::err('mad4b_media_image_slot_format_invalid');
  return ['format'=>$mapped,'field'=>$field];
 }
 private static function reviewed_local(array $payload,array $target,string $adapter,string $action):array|WP_Error {
  $asset=$action==='review'?($payload['asset']??null):$payload;
  if(!is_array($asset))return self::err('mad4b_media_attachment_review_asset_invalid');
  $field=$asset['field']??null;
  if(!is_string($field))return self::err('mad4b_media_attachment_field_invalid');
  $slot=self::image_slot($target,$adapter,$field);
  if(is_wp_error($slot))return $slot;
  // Scoped intrinsic _thumbnail_id is not necessarily registered in WP meta.
  $add=static function(array $fields,string $kind,string $type):array{
   if($kind==='post')$fields[]='_thumbnail_id';return $fields;
  };
  if($adapter==='post_featured_image')add_filter('mad4b/media/apply/fields',$add,10,3);
  try{$preflight=self::local_attachment_preflight($asset,$target,$adapter);}
  finally{if($adapter==='post_featured_image')remove_filter('mad4b/media/apply/fields',$add,10);}
  if(is_wp_error($preflight))return $preflight;
  if($action==='preflight')return $preflight;
  $actor=self::signed_ai();if(is_wp_error($actor))return $actor;
  $key=self::local_review_key($target,$adapter,$asset);
  if($action==='review'){
   $expected=['asset','review'];$keys=array_keys($payload);sort($expected);sort($keys);
   if($keys!==$expected||!is_array($payload['review']))return self::err('mad4b_media_attachment_review_schema_invalid');
   $review=$payload['review'];
   $expected=['decision','reviewed_sha256','visual_match_verified','rights_verified','subject_description','rights_evidence'];
   $keys=array_keys($review);sort($keys);sort($expected);
   if($keys!==$expected||($review['decision']??'')!=='approve'
     ||($review['visual_match_verified']??false)!==true||($review['rights_verified']??false)!==true
     ||!hash_equals((string)$preflight['attachment_sha256'],(string)($review['reviewed_sha256']??''))
     ||!is_string($review['subject_description'])||mb_strlen($review['subject_description'])<35
     ||!is_string($review['rights_evidence'])||mb_strlen($review['rights_evidence'])<35)
    return self::err('mad4b_media_attachment_review_evidence_missing');
   $existing=get_option($key,[]);
   if(is_array($existing)&&($existing['status']??'')==='applied')return self::err('mad4b_media_attachment_review_already_consumed');
   if(is_array($existing)&&($existing['status']??'')==='approved'&&($existing['expires_at']??0)>time())
    return ['status'=>'review_already_approved','approval_sha256'=>$existing['approval_sha256'],'write_executed'=>false];
   $approved=['contract'=>'mad4b.media.local-image-review.v1','status'=>'approved',
    'site_uuid'=>$actor['site_uuid'],'agent_public_id'=>$actor['agent_public_id'],
    'target'=>$target,'adapter'=>$adapter,'field'=>$field,
    'attachment_sha256'=>$preflight['attachment_sha256'],
    'original_value_sha256'=>$preflight['old_value_sha256'],
    'visual_evidence_sha256'=>hash('sha256',$review['subject_description']),
    'rights_evidence_sha256'=>hash('sha256',$review['rights_evidence']),
    'reviewed_at'=>gmdate('c'),'expires_at'=>time()+86400];
   $approved['approval_sha256']=hash('sha256',wp_json_encode($approved,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
   $audit=MAD4B_SCP_Audit::record('mad4b/media-local-image-review',[
    'site_uuid'=>$actor['site_uuid'],'agent_public_id'=>$actor['agent_public_id'],
    'kind'=>$target['kind'],'type'=>$target['type'],'id'=>$target['id'],
    'adapter'=>$adapter,'field'=>$field,'sha256'=>$preflight['attachment_sha256'],
    'approval_sha256'=>$approved['approval_sha256']]);
   if(is_wp_error($audit))return $audit;
   if(!add_option($key,$approved,'','no'))return self::err('mad4b_media_attachment_review_save_failed');
   return ['status'=>'review_approved_signed','approval_sha256'=>$approved['approval_sha256'],
     'expires_at'=>$approved['expires_at'],'write_executed'=>false];
  }
  if($action!=='apply')return self::err('mad4b_media_attachment_action_not_supported');
  $approved=get_option($key,[]);
  if(!is_array($approved)||($approved['status']??'')!=='approved'||(int)($approved['expires_at']??0)<=time()
   ||!hash_equals((string)($approved['site_uuid']??''),(string)$actor['site_uuid'])
   ||!hash_equals((string)($approved['agent_public_id']??''),(string)$actor['agent_public_id'])
   ||!hash_equals((string)($approved['attachment_sha256']??''),(string)$preflight['attachment_sha256'])
   ||!hash_equals((string)($approved['original_value_sha256']??''),(string)$preflight['old_value_sha256']))
   return self::err('mad4b_media_attachment_approved_review_missing');
  $id=$target['id'];$kind=$target['kind'];$type=$target['type'];$aid=$asset['attachment_id'];
  $getter=$kind==='post'?'get_post_meta':'get_term_meta';
  $writer=$kind==='post'?'update_post_meta':'update_term_meta';
  $deleter=$kind==='post'?'delete_post_meta':'delete_term_meta';
  $new=$slot['format']==='id'?(string)$aid:(string)wp_get_attachment_url($aid);
  if($new==='' || ($slot['format']==='url'&&!str_starts_with($new,home_url('/'))))
   return self::err('mad4b_media_attachment_url_invalid');
  $lock='mad4b_media_local_apply_lock_'.hash('sha256',$kind.':'.$type.':'.$id.':'.$field);
  if(!add_option($lock,['at'=>time(),'approval'=>$approved['approval_sha256']],'','no'))
   return self::err('mad4b_media_attachment_concurrent_write');
  $old=$getter($id,$field,true);$changed=false;$result=null;
  try{
   $current=hash('sha256',wp_json_encode($getter($id,$field,true),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
   if(!hash_equals($asset['expected_value_sha256'],$current))throw new Exception('preimage_changed');
   if((string)$old!==(string)$new){
    if($adapter==='post_featured_image'){
     if(!set_post_thumbnail($id,$aid))throw new Exception('featured_image_write_failed');
    } elseif(!$writer($id,$field,$new))throw new Exception('registered_image_write_failed');
    $changed=true;
   }
   if((string)$getter($id,$field,true)!==(string)$new)throw new Exception('image_readback_failed');
   $receipt=['contract'=>self::CONTRACT,'status'=>'applied_verified','adapter'=>$adapter,
    'site_uuid'=>$actor['site_uuid'],'target'=>$target,'field'=>$field,'format'=>$slot['format'],
    'attachment_id'=>$aid,'attachment_sha256'=>$preflight['attachment_sha256'],
    'approval_sha256'=>$approved['approval_sha256'],'previous_value'=>$old,
    'new_value'=>$new,'changed'=>$changed,'write_executed'=>true,'rollback_supported'=>true];
   $audit=MAD4B_SCP_Audit::record('mad4b/signed-media-apply',[
     'site_uuid'=>$actor['site_uuid'],'kind'=>$kind,'type'=>$type,'id'=>$id,
     'field'=>$field,'attachment_id'=>$aid,'approval_sha256'=>$approved['approval_sha256']]);
   if(is_wp_error($audit))throw new Exception('audit_failed');
   $receipt['receipt_sha256']=hash('sha256',wp_json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
   $approved['status']='applied';$approved['receipt_sha256']=$receipt['receipt_sha256'];
   if(!update_option($key,$approved,false))throw new Exception('consume_approval_failed');
   if($kind==='term')clean_term_cache($id,$type);else clean_post_cache($id);
   $result=$receipt;
  }catch(Throwable $e){
   if($changed){
    if($old===''||$old===null)$deleter($id,$field);
    else $writer($id,$field,$old);
    if($kind==='term')clean_term_cache($id,$type);else clean_post_cache($id);
   }
   $result=self::err('mad4b_media_attachment_apply_failed_rolled_back');
  }finally{delete_option($lock);}
  return $result;
 }
 public static function dispatch(array $input):array|WP_Error {
  // Route never exposes a generic meta-update, file write or direct SQL.
  $check=self::check($input);if(is_wp_error($check))return $check;
  $adapter=$check['adapter'];$action=$check['action'];$payload=$input['payload'];$id=$check['target']['id'];
  if($adapter==='term_featured_image') {
   if(!class_exists('MAD4B_Licensed_Term_Media_Apply_V1',false))return self::err('mad4b_unified_media_term_adapter_not_installed');
   $asset=$action==='review'?($payload['asset']??[]):$payload;
   if((int)($asset['term_id']??0)!==$id ||($asset['taxonomy']??'')!==$check['target']['type'])return self::err('mad4b_unified_media_payload_target_mismatch');
   if($action==='review')return self::review_term($payload,$check['target']);
   if($action==='apply')return self::apply_term($payload,$check['target']);
   if($action==='preflight'){
    $result=MAD4B_Licensed_Term_Media_Apply_V1::validate($payload);
    if(is_wp_error($result))return $result;
    return ['status'=>'preflight_valid_approval_pending','router'=>self::CONTRACT,'adapter'=>$adapter,'target'=>$check['target'],'preflight'=>$result,'write_executed'=>false];
   }
   // An actual licensed taxonomy image must be approved in the existing
   // governance ledger. A license label or signed caller is insufficient.
   return self::err('mad4b_unified_media_term_signed_approval_executor_pending');
  }
  if(in_array($adapter,['post_registered_composite','term_registered_composite'],true)) {
   if(!class_exists('MAD4B_Composite_Media_Adapter_V1',false))return self::err('mad4b_composite_adapter_missing');
   return MAD4B_Composite_Media_Adapter_V1::run($payload,$check['target'],$adapter,$action);
  }
  if(in_array($adapter,['post_featured_image','post_registered_image','term_registered_image'],true))
   return self::reviewed_local($payload,$check['target'],$adapter,$action);
  if($adapter==='tour_association') {
   if(!class_exists('MAD4B_Tour_Media_Association_V1',false))return self::err('mad4b_unified_media_tour_adapter_not_installed');
   if($action==='preflight')return ['status'=>'supported','write_executed'=>false,'target'=>$check['target'],'adapter'=>$adapter];
   if((int)($payload['canonical_post_id']??0)!==$id||($payload['action']??'')!==$action)return self::err('mad4b_unified_media_payload_target_mismatch');
   if(!class_exists('MAD4B_SCP_Host_Agent_Bridge',false)||!MAD4B_SCP_Host_Agent_Bridge::active())return self::err('mad4b_unified_media_signed_host_required');
   return MAD4B_Tour_Media_Association_V1::execute($payload);
  }
  // Custom JetEngine storage adapters are registered by exact name but
  // cannot become dynamic arbitrary callbacks through a signed envelope.
  return self::err('mad4b_unified_media_adapter_dispatch_not_implemented');
 }
 public static function cli($args,$assoc):void {
  if($assoc||count($args)!==1||$args[0]!=='preflight')WP_CLI::error('Only read-only preflight is exposed directly; writes require host-signed dispatch.');
  $raw=stream_get_contents(STDIN,24577);$input=json_decode((string)$raw,true);
  if(!is_array($input))WP_CLI::error('Invalid JSON manifest');
  if(($input['action']??'')!=='preflight')WP_CLI::error('Preflight action required');
  $res=self::dispatch($input);if(is_wp_error($res))WP_CLI::error($res->get_error_code());
  WP_CLI::line(wp_json_encode($res,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 }
}
add_filter('mad4b/term_media/signed_transport_registered',static function($ready){
 if($ready)return true;
 if(!class_exists('MAD4B_Licensed_Term_Media_Apply_V1',false)
  ||!class_exists('MAD4B_SCP_Host_Agent_Bridge',false)
  ||!class_exists('MAD4B_SCP_Context_Authority',false)
  ||!MAD4B_SCP_Host_Agent_Bridge::binding_ready())return false;
 $review=MAD4B_SCP_Context_Authority::ai_review_policy_status();
 return is_array($review)&&($review['ready']??false)===true
   &&($review['mode']??'')==='ai_only';
},10,1);
if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b media-apply',[MAD4B_Universal_Media_Apply_V1::class,'cli']);
}

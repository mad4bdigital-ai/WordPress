<?php
/**
 * Plugin Name: MAD4B Media Rights Extraction Intake
 * Description: Bounded, idempotent JetEngine CCT discovery of new local image attachments; never auto-licenses or publishes.
 * Version: 1.7.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Media_Rights_Extraction_Intake_V1', false)) {
final class MAD4B_Media_Rights_Extraction_Intake_V1 {
 const HOOK = 'mad4b_media_rights_extract_attachment_v1';
 const PUBLISHED_TOUR_HOOK = 'mad4b_media_rights_review_published_tour_v1';
 const RIGHTS_ORIGIN_INDEX = 'mad4b_media_rights_origin_sha';
 const PREFIX = 'discovered_attachment_';
 const MAX_BYTES = 15728640;
 public static function schedule(int $attachment_id): bool {
  if ($attachment_id < 1 || get_post_type($attachment_id) !== 'attachment' || !str_starts_with((string)get_post_mime_type($attachment_id),'image/')) return false;
  if (!wp_next_scheduled(self::HOOK,[$attachment_id])) {
   return (bool) wp_schedule_single_event(time()+15,self::HOOK,[$attachment_id]);
  }
  return true;
 }
 private static function confirmed_source_url(int $attachment_id): string {
  // Source fields can be stale in persistent object cache after external
  // import or transaction recovery. Read the authoritative exact meta row.
  global $wpdb;
  $keys=['_mad4b_import_source_url','_mad4b_source_media_origin_v1'];
  $valid=[];
  foreach($keys as $key){
   $rows=$wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 2",$attachment_id,$key));
   if(count($rows)!==1 || !is_string($rows[0]))continue;
   $source=trim($rows[0]);$origin=wp_parse_url($source);
   if(!is_array($origin)||($origin['scheme']??'')!=='https'||empty($origin['host'])||isset($origin['user'])||isset($origin['pass'])||strlen($source)>2048)continue;
   $valid[$key]=esc_url_raw($source);
  }
  // If two independent importers disagree, do not silently choose a source.
  if(count(array_unique(array_values($valid)))>1)return '';
  return $valid?reset($valid):'';
 }
 public static function changed_meta($meta_id,$object_id,$key,$value): void {
  if (in_array((string)$key,['_wp_attached_file','_wp_attachment_metadata','_mad4b_verified_source_sha256','_mad4b_license_source_url','_mad4b_license','_mad4b_creator','_mad4b_import_source_url','_mad4b_remote_media_source_sha256','_mad4b_remote_media_manifest_sha256','_mad4b_remote_media_recovery_stage','_mad4b_media_operator_responsibility_v1','_mad4b_source_media_origin_v1','_mad4b_source_media_parent_url_v1','_mad4b_source_media_license_status_v1'],true)) {
   self::schedule((int)$object_id);
  }
 }
 /** Embedded creator/copyright data is a REVIEW trigger, never proof
  * of commercial permission or that the camera metadata is authentic.
  */
 private static function has_embedded_rights_attribution(int $attachment_id): bool {
  $meta=wp_get_attachment_metadata($attachment_id);
  $image=is_array($meta)?(array)($meta['image_meta']??[]):[];
  $claim=[];
  foreach(['copyright','credit'] as $field){
   $value=trim((string)($image[$field]??''));
   if($value!==''&&mb_strlen($value)>=5&&mb_strlen($value)<=600)
    $claim[$field]=$value;
  }
  // Do not manufacture credentials from camera/software tags or guesses.
  // An embedded credit/copyright may be wrong and still needs human review.
  return (bool)apply_filters('mad4b/media_rights/intake/embedded_attribution_review',
    !empty($claim),$attachment_id,$claim);
 }
 public static function process(int $attachment_id, bool $verified_published_use=false): array {
  // This additional input can ONLY create an unpublished rights-review draft.
  // It is not identity, origin, license, creator or publication authorization.
  if($verified_published_use){
   if(!class_exists('MAD4B_Media_Visual_Lineage_V1',false))return ['status'=>'lineage_engine_unavailable'];
   $origin=MAD4B_Media_Visual_Lineage_V1::origin($attachment_id);
   if(empty($origin['verified'])||(int)($origin['origin_attachment_id']??0)!==$attachment_id)
    return ['status'=>'not_verified_original','attachment_id'=>$attachment_id];
  }
  if ($attachment_id<1 || get_post_type($attachment_id)!=='attachment' || !str_starts_with((string)get_post_mime_type($attachment_id),'image/')) return ['status'=>'not_image'];
  $post=get_post($attachment_id);$file=get_attached_file($attachment_id);
  $uploads=wp_get_upload_dir();$root=realpath((string)($uploads['basedir']??''));$real=$file?realpath($file):false;
  if (!$root || !$real || !str_starts_with($real,$root.DIRECTORY_SEPARATOR) || !is_file($real)) return ['status'=>'file_not_local'];
  $size=filesize($real);if (!$size || $size>self::MAX_BYTES) return ['status'=>'size_not_eligible'];
  if (!class_exists('Jet_Engine\\Modules\\Custom_Content_Types\\Module',false)) return ['status'=>'cct_unavailable'];
  $factory=\Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->get_content_types('media_rights');
  if (!$factory || !$factory->db->is_table_exists()) return ['status'=>'cct_unavailable'];
  $sha=hash_file('sha256',$real);
  if (!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha)) return ['status'=>'hash_failed'];
  // Host and WP-Cron can run concurrently. Synchronize review rows by
  // physical bytes, not attachment IDs or translation aliases.
  if(!class_exists('MAD4B_SCP_Distributed_Lock',false))return ['status'=>'native_source_lock_unavailable'];
  $mutex=MAD4B_SCP_Distributed_Lock::catalog_name('media-rights-original-sha:'.$sha);
  $held=MAD4B_SCP_Distributed_Lock::acquire($mutex);
  if(is_wp_error($held))return ['status'=>'rights_review_original_locked_retry'];
  try {
  // A reviewed, published image always wins over an unverified intake candidate.
  global $wpdb;$table=$factory->db->table();
  $published=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE source_sha256=%s AND cct_status=%s",$sha,'publish'));
  if ($published>0) return ['status'=>'already_licensed','attachment_id'=>$attachment_id];
  // A web-scaled WordPress image is a derivative of the preserved
  // original_image, NOT an independently licensed image. Accept this alias
  // only when the actual unmodified original bytes, importer metadata and
  // a published, exact-attachment rights CCT record independently agree.
  // Filename similarity, uploader claims, and draft rows never suffice.
  $wpMeta=wp_get_attachment_metadata($attachment_id);
  $originalName=is_array($wpMeta)?($wpMeta['original_image']??null):null;
  if(is_string($originalName) && $originalName!==''
     &&!str_contains($originalName,'/')&&!str_contains($originalName,'\\')
     &&$originalName===wp_basename($originalName)
     &&preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{3,190}$/D',$originalName)){
   $rawOriginal=realpath(dirname($real).DIRECTORY_SEPARATOR.$originalName);
   if($rawOriginal && is_file($rawOriginal)
      &&dirname($rawOriginal)===dirname($real)
      &&filesize($rawOriginal)>0&&filesize($rawOriginal)<=self::MAX_BYTES){
    $originalSha=(string)hash_file('sha256',$rawOriginal);
    $licenseOwner=$attachment_id;
    $sourceSha=(string)get_post_meta($attachment_id,'_mad4b_verified_source_sha256',true);
    $wpmlAlias=false;
    // WPML translation attachments may deliberately omit importer metadata.
    // The original-language attachment owns the published license. Trust it
    // ONLY with a reciprocal native WPML family and byte-exact photo match.
    // An explicitly mismatching local source claim must never be overridden.
    if($sourceSha==='' && class_exists('MAD4B_WPML_Attachment_Family_Readback_V1',false)){
     $family=MAD4B_WPML_Attachment_Family_Readback_V1::translations($attachment_id);
     $default=(string)apply_filters('wpml_default_language','en');
     $candidate=(int)($family[$default]->element_id??0);
     if($candidate>0&&$candidate!==$attachment_id
        &&get_post_type($candidate)==='attachment'){
      $reciprocal=MAD4B_WPML_Attachment_Family_Readback_V1::translations($candidate);
      $roundtrip=false;
      foreach((array)$reciprocal as $row)
       if((int)($row->element_id??0)===$attachment_id){$roundtrip=true;break;}
      $candidateFile=get_attached_file($candidate);
      $candidateReal=$candidateFile?realpath($candidateFile):false;
      $candidateMeta=wp_get_attachment_metadata($candidate);
      $candidateName=is_array($candidateMeta)?($candidateMeta['original_image']??null):null;
      $candidateSource=(string)get_post_meta($candidate,'_mad4b_verified_source_sha256',true);
      $sameOriginal=$candidateName===$originalName;
      $sameServed=$candidateReal&&is_file($candidateReal)
       &&hash_equals($sha,(string)hash_file('sha256',$candidateReal));
      if($roundtrip&&$sameOriginal&&$sameServed
         &&preg_match('/^[a-f0-9]{64}$/D',$candidateSource)
         &&hash_equals($candidateSource,$originalSha)){
       $licenseOwner=$candidate;$sourceSha=$candidateSource;$wpmlAlias=true;
      }
     }
    }
    if(preg_match('/^[a-f0-9]{64}$/D',$sourceSha)
       &&hash_equals($sourceSha,$originalSha)){
     $pub=$wpdb->get_results($wpdb->prepare(
       "SELECT _ID,attachment_id,source_sha256,source_url,author,license_url,license_label FROM {$table} WHERE source_sha256=%s AND cct_status=%s AND attachment_id=%d LIMIT 2",
       $sourceSha,'publish',$licenseOwner),ARRAY_A);
     if(is_array($pub)&&$wpdb->last_error===''&&count($pub)===1){
      $credit=$pub[0];
      $source=(string)get_post_meta($licenseOwner,'_mad4b_license_source_url',true);
      $label=(string)get_post_meta($licenseOwner,'_mad4b_license',true);
      $creator=(string)get_post_meta($licenseOwner,'_mad4b_creator',true);
      $aliasMetaAligned=true;
      if($wpmlAlias){
       foreach(['_mad4b_license_source_url','_mad4b_license','_mad4b_creator'] as $key){
        $local=(string)get_post_meta($attachment_id,$key,true);
        $owner=(string)get_post_meta($licenseOwner,$key,true);
        if($local!==''&&!hash_equals($local,$owner)){$aliasMetaAligned=false;break;}
       }
      }
      if($aliasMetaAligned&&$source!==''&&$label!==''&&$creator!==''
         &&hash_equals($source,(string)($credit['source_url']??''))
         &&hash_equals($label,(string)($credit['license_label']??''))
         &&hash_equals($creator,(string)($credit['author']??''))
         &&wp_parse_url((string)($credit['license_url']??''),PHP_URL_SCHEME)==='https')
       return ['status'=>'already_licensed_wordpress_scaled_derivative',
         'attachment_id'=>$attachment_id,'licensed_attachment_id'=>$licenseOwner,
         'verified_wpml_sibling'=>$wpmlAlias,
         'served_sha256'=>$sha,'licensed_original_sha256'=>$sourceSha,
         'published_credit_id'=>(int)$credit['_ID'],
         'rights_granted_by_intake'=>false,'new_draft_created'=>false];
     }
    }
   }
  }
  // The registry is needed for externally sourced media, not every internal
  // upload, generated image, resized crop or unsourced company photograph.
  // Import provenance arrives asynchronously; its meta hook will retry.
  $has_external_provenance=self::confirmed_source_url($attachment_id)!=='';
  if(!$has_external_provenance){
   $markers=['_mad4b_remote_media_source_sha256','_mad4b_remote_media_manifest_sha256'];
   foreach($markers as $marker){
    $value=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 1",$attachment_id,$marker));
    if(is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)){$has_external_provenance=true;break;}
   }
  }
  $embeddedAttribution=!$has_external_provenance
   &&self::has_embedded_rights_attribution($attachment_id);
  if(!$has_external_provenance&&!$embeddedAttribution&&!$verified_published_use)
   return ['status'=>'no_external_provenance','attachment_id'=>$attachment_id];
  $key=self::PREFIX.$attachment_id;
  $factory->db->set_format_flag(ARRAY_A);
  $existing=$factory->db->query(['credit_key'=>$key],2);
  if (count($existing)>1) return ['status'=>'duplicate_key_requires_repair'];
  if ($existing) {
   if(!hash_equals($sha,(string)($existing[0]['source_sha256']??'')))
    return ['status'=>'same_attachment_new_original_requires_review','attachment_id'=>$attachment_id];
   // Eventual consistency: when remote-import provenance arrives after the
   // attachment hook, update only the *draft* origin, never reviewed rights.
   $source=self::confirmed_source_url($attachment_id);
   $valid=$source!=='';
   if ($valid && ($existing[0]['cct_status']??'')==='draft' && (int)($existing[0]['attachment_id']??0)===$attachment_id && ($existing[0]['source_url']??'')!==$source) {
    $factory->db->update(['source_url'=>esc_url_raw($source)],['_ID'=>(int)$existing[0]['_ID']]);
    $updated=$factory->db->query(['credit_key'=>$key],2);
    if(count($updated)!==1 || ($updated[0]['source_url']??'')!==esc_url_raw($source))return ['status'=>'provenance_readback_failed'];
    return ['status'=>'pending_source_linked','attachment_id'=>$attachment_id];
   }
   return ['status'=>'already_pending','attachment_id'=>$attachment_id];
  }
  // A shared image in several tours can have multiple genuine WordPress
  // originals (or WPML copies) with identical file bytes. One rights review
  // row is enough; the primary attachment ID is not an ownership claim.
  $same=$wpdb->get_results($wpdb->prepare(
   "SELECT _ID,attachment_id,source_sha256 FROM {$table} WHERE source_sha256=%s AND cct_status=%s LIMIT 2",
   $sha,'draft'),ARRAY_A);
  if(!is_array($same)||$wpdb->last_error!=='')
   return ['status'=>'rights_index_query_failed','attachment_id'=>$attachment_id];
  if(count($same)>1)return ['status'=>'duplicate_sha_needs_admin_repair'];
  if($same){
   $matched=(int)$same[0]['attachment_id'];
   return ['status'=>'already_pending_same_original','attachment_id'=>$attachment_id,
    'registered_attachment_id'=>$matched,'same_sha_attachment_alias'=>$matched!==$attachment_id,
    'source_sha256'=>$sha,'cct_id'=>(int)$same[0]['_ID'],
    'no_extra_rights_granted'=>true];
  }
  $title=trim((string)($post->post_title??''));
  // External imports may set provenance *after* creating the attachment.
  // Preserve the source URL only if the import metadata refers to the exact
  // current attachment; this is evidence of origin, NOT a reuse license.
  $source=self::confirmed_source_url($attachment_id);
  $row=['credit_key'=>$key,'subject'=>sanitize_text_field($title?:'Image #'.$attachment_id),
   'author'=>'','source_url'=>$source,'license_url'=>'','license_label'=>'',
   'source_sha256'=>$sha,'attachment_id'=>$attachment_id,'cct_status'=>'draft'];
  $insert=$factory->db->insert($row);
  if (!$insert) return ['status'=>'cct_insert_failed'];
  $check=$factory->db->query(['credit_key'=>$key],2);
  if(count($check)!==1 || (int)($check[0]['attachment_id']??0)!==$attachment_id || ($check[0]['cct_status']??'')!=='draft') return ['status'=>'cct_readback_failed'];
  // Not publicly displayed; unknown provenance is never represented as licensed.
  return ['status'=>$verified_published_use&&!$has_external_provenance&&!$embeddedAttribution
    ?'pending_published_tour_rights_review'
    :($embeddedAttribution?'pending_embedded_credit_review':'pending_rights_review'),
   'attachment_id'=>$attachment_id,'credit_key'=>$key,'cct_id'=>(int)$insert,
   'photo_rights_verified'=>false,'embedded_creator_claim_is_not_license'=>true];
  } finally {
   MAD4B_SCP_Distributed_Lock::release($mutex);
  }
 }
 /** Turn only photo originals actually used by published tours into CCT
  * drafts. Existing picture ownership, content, price and public URLs are
  * untouched. Dynamic JetEngine media schema, bounded pagination, no
  * hardcoded tour/post/media IDs and no remote downloads.
  */
 public static function review_published_tour_batch(int $after=0,int $limit=12):array {
  if(!defined('WP_CLI')||WP_CLI!==true||PHP_SAPI!=='cli'||get_current_user_id()!==0)
   return ['status'=>'verified_host_wpcli_required'];
  return self::review_published_tour_internal($after,$limit);
 }
 private static function review_published_tour_internal(int $after=0,int $limit=12):array {
  if(!class_exists('MAD4B_Gallery_Resolution_Engine_V1',false)
   ||!class_exists('MAD4B_Media_Visual_Lineage_V1',false)
   ||!class_exists('MAD4B_SCP_Provider_Field_Intelligence',false))
   return ['status'=>'native_media_introspection_unavailable'];
  $after=max(0,$after);$limit=max(1,min(15,$limit));
  $discovered=MAD4B_SCP_Provider_Field_Intelligence::discover([
   'object'=>'post','subtype'=>'tours-and-activities','only_media'=>true,'limit'=>500]);
  if(is_wp_error($discovered))return ['status'=>'schema_discovery_failed'];
  $fields=[];
  foreach(array_slice((array)($discovered['fields']??[]),0,500) as $f){
   if(!is_array($f)||empty($f['declared'])||($f['provider']??'')!=='jetengine'
     ||!in_array(($f['field_type']??''),['image','media','gallery'],true))continue;
   $key=(string)($f['key']??'');
   if(preg_match('/^[a-z_][a-z0-9_]{1,99}$/D',$key))$fields[$key]=true;
   if(count($fields)>90)break;
  }
  if(!$fields)return ['status'=>'no_declared_media_fields'];
  // Actual gallery and Elementor hero are JetEngine-owned, not arbitrary
  // serialized postmeta. Feature image is supported independently.
  global $wpdb;
  $posts=$wpdb->get_col($wpdb->prepare(
   "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s AND ID>%d ORDER BY ID ASC LIMIT %d",
   'tours-and-activities','publish',$after,$limit));
  if(!is_array($posts)||$wpdb->last_error!=='')return ['status'=>'post_query_failed'];
  $counts=[];$scanned=0;$fieldReads=0;$originals=[];$unresolved=0;
  foreach($posts as $rawPost){$post=(int)$rawPost;$scanned++;
   $media=[];$thumb=(int)get_post_thumbnail_id($post);
   if($thumb>0)$media[$thumb]=true;
   foreach(array_keys($fields) as $key){
    $src=get_post_meta($post,$key,true);if(!is_string($src)||$src==='')continue;
    $fieldReads++;
    $pieces=array_values(array_filter(array_map('trim',explode(',',$src)),'strlen'));
    if(count($pieces)>150){$unresolved++;continue;}
    $result=MAD4B_Gallery_Resolution_Engine_V1::inspect($src,$post);
    if(empty($result['valid_source'])||(int)($result['unresolved']??0)>0){$unresolved++;continue;}
    $ids=array_values(array_filter(array_map('intval',explode(',',(string)($result['render_value']??'')))));
    if(count($ids)!==count($pieces)){$unresolved++;continue;}
    foreach($ids as $id)$media[$id]=true;
   }
   foreach(array_keys($media) as $aid){
    if(get_post_type($aid)!=='attachment')continue;
    $origin=MAD4B_Media_Visual_Lineage_V1::origin($aid);
    $id=(int)($origin['origin_attachment_id']??0);
    $sha=(string)($origin['origin_sha256']??'');
    if(empty($origin['verified'])||!preg_match('/^[a-f0-9]{64}$/D',$sha)||$id<1){$unresolved++;continue;}
    $originals[$sha]=$id;
   }
  }
  $blocked=0;
  foreach($originals as $id){
   $res=self::process($id,true);$status=(string)($res['status']??'missing');
   $counts[$status]=($counts[$status]??0)+1;
   if(in_array($status,['rights_review_original_locked_retry','native_source_lock_unavailable','rights_index_query_failed','duplicate_sha_needs_admin_repair','cct_insert_failed','cct_readback_failed'],true))$blocked++;
  }
  return [
   'contract'=>'mad4b.published-tour-media-rights-intake.v1',
   'status'=>'review_only','tour_count'=>$scanned,'last_tour_id'=>$posts?(int)end($posts):$after,
   'done'=>count($posts)<$limit,'declared_media_fields'=>count($fields),
   'nonempty_field_reads'=>$fieldReads,'unique_source_originals'=>count($originals),
   'rejected_unresolved_fields'=>$unresolved,'rights_draft_operations_blocked'=>$blocked,'statuses'=>$counts,
   'rights_granted'=>false,'new_public_media'=>false,'auto_approval'=>false];
 }
 /** Coalesce new publication and JetEngine image edits into one
  * delayed, bounded review job per tour. No outbound fetch, no licensing,
  * no synchronous image processing during an editor save.
  */
 public static function schedule_published_tour(int $post_id):bool {
  if($post_id<1||get_post_type($post_id)!=='tours-and-activities'
   ||get_post_status($post_id)!=='publish'||wp_is_post_revision($post_id))return false;
  if(wp_next_scheduled(self::PUBLISHED_TOUR_HOOK,[$post_id]))return true;
  // Deterministic spread of mass edits rather than hundreds of jobs firing
  // on the same second, improving VPS CPU and cache stability.
  $delay=120+(($post_id%60)*20);
  return (bool)wp_schedule_single_event(time()+$delay,self::PUBLISHED_TOUR_HOOK,[$post_id]);
 }
 public static function schedule_on_tour_save(int $id,$post=null,$updated=false):void {
  self::schedule_published_tour((int)$id);
 }
 public static function schedule_on_tour_media_change($meta_id,$object_id,$key,$value):void {
  if(!is_string($key)||!preg_match('/(?:image|gallery|media|photo|grid|vertical|cover|thumbnail)/i',$key))return;
  $id=absint($object_id);
  if($id>0 && get_post_type($id)==='tours-and-activities')self::schedule_published_tour($id);
 }
 public static function review_published_tour_event(int $post_id):void {
  if($post_id<1||get_post_type($post_id)!=='tours-and-activities'
    ||get_post_status($post_id)!=='publish')return;
  // Follow the same native media-discovery path as manual WP-CLI, but
  // inspect exactly one published tour. This never alters public media.
  $r=self::review_published_tour_internal(max(0,$post_id-1),1);
  if(($r['rights_draft_operations_blocked']??0)>0)
   wp_schedule_single_event(time()+360,self::PUBLISHED_TOUR_HOOK,[$post_id]);
  do_action('mad4b/media_rights/intake/published_tour_result',$r,$post_id);
 }
 /** Keep the ORIGINAL JetEngine CCT indexed as its rights-review
  * workload grows. This is an additive index only, never a duplicate CCT.
  * Schema repair is an explicit host WP-CLI operation, not a frontend task.
  */
 public static function origin_index_status():array {
  if(!class_exists('Jet_Engine\\Modules\\Custom_Content_Types\\Module',false))
   return ['status'=>'cct_unavailable'];
  $factory=\Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->get_content_types('media_rights');
  if(!$factory||!$factory->db->is_table_exists())return ['status'=>'cct_unavailable'];
  $table=(string)$factory->db->table();
  if(!preg_match('/^[a-zA-Z0-9_]{1,100}$/D',$table))return ['status'=>'invalid_cct_table_name'];
  global $wpdb;
  $rows=$wpdb->get_results("SHOW INDEX FROM `{$table}`",ARRAY_A);
  if(!is_array($rows)||$wpdb->last_error!=='')return ['status'=>'index_discovery_unavailable'];
  $columns=[];
  foreach($rows as $row){
   if(($row['Key_name']??'')!==self::RIGHTS_ORIGIN_INDEX)continue;
   $columns[(int)($row['Seq_in_index']??0)]=[
    'field'=>(string)($row['Column_name']??''),
    'prefix'=>(int)($row['Sub_part']??0)];
  }
  ksort($columns);
  $ready=($columns[1]['field']??'')==='source_sha256'
    &&($columns[1]['prefix']??0)===64
    &&($columns[2]['field']??'')==='cct_status'
    &&($columns[2]['prefix']??0)===16
    &&count($columns)===2;
  return ['contract'=>'mad4b.media-rights-origin-index.v1',
   'status'=>$ready?'ready':($columns?'incompatible':'missing'),
   'index'=>self::RIGHTS_ORIGIN_INDEX,'columns'=>$columns,
   'schema_mutated'=>false];
 }
 public static function reconcile_origin_index():array {
  if(!defined('WP_CLI')||WP_CLI!==true||PHP_SAPI!=='cli'||get_current_user_id()!==0)
   return ['status'=>'verified_host_cli_required'];
  if(!class_exists('MAD4B_SCP_Site_Profile',false)
   ||!class_exists('MAD4B_SCP_Distributed_Lock',false))
   return ['status'=>'native_site_identity_unavailable'];
  $profile=MAD4B_SCP_Site_Profile::status();
  if(empty($profile['site_uuid'])||empty($profile['origin_match'])
   ||empty($profile['environment_match'])||empty($profile['deployment_binding_match']))
   return ['status'=>'site_binding_not_verified'];
  $before=self::origin_index_status();
  if(($before['status']??'')==='ready')return ['status'=>'already_indexed','index_ready'=>true];
  if(($before['status']??'')!=='missing')return ['status'=>'source_schema_index_incompatible','index'=>$before];
  $lock=MAD4B_SCP_Distributed_Lock::catalog_name('media-rights-cct-index-reconcile');
  $held=MAD4B_SCP_Distributed_Lock::acquire($lock);
  if(is_wp_error($held))return ['status'=>'origin_index_reconcile_locked'];
  try {
   $fresh=self::origin_index_status();
   if(($fresh['status']??'')==='ready')return ['status'=>'already_indexed','index_ready'=>true];
   if(($fresh['status']??'')!=='missing')return ['status'=>'source_schema_index_incompatible','index'=>$fresh];
   $factory=\Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->get_content_types('media_rights');
   $table=(string)$factory->db->table();
   if(!preg_match('/^[a-zA-Z0-9_]{1,100}$/D',$table))return ['status'=>'invalid_cct_table_name'];
   global $wpdb;
   $columns=$wpdb->get_results("SHOW COLUMNS FROM `{$table}`",ARRAY_A);
   if(!is_array($columns)||$wpdb->last_error!=='')return ['status'=>'column_discovery_failed'];
   $types=[];foreach($columns as $c)$types[(string)($c['Field']??'')]=strtolower((string)($c['Type']??''));
   if(!preg_match('/^(?:text|varchar\([0-9]+\))$/D',$types['source_sha256']??'')
    ||!preg_match('/^(?:text|varchar\([0-9]+\))$/D',$types['cct_status']??''))
    return ['status'=>'origin_index_columns_invalid'];
   // Text-prefix lookup avoids a full table scan for each original image,
   // while retaining the JetEngine CCT definition and all existing rows.
   $index=self::RIGHTS_ORIGIN_INDEX;
   $sql="ALTER TABLE `{$table}` ADD INDEX `{$index}` (`source_sha256`(64), `cct_status`(16))";
   $changed=$wpdb->query($sql);
   if($changed===false)return ['status'=>'index_reconcile_database_error'];
   $after=self::origin_index_status();
   if(($after['status']??'')!=='ready')return ['status'=>'index_readback_failed','index'=>$after];
   return ['status'=>'index_reconciled','index_ready'=>true,'additive_schema_only'=>true,'rights_modified'=>false];
  }finally {MAD4B_SCP_Distributed_Lock::release($lock);}
 }
 public static function reconcile_batch(int $after=0,int $limit=25): array {
  $limit=max(1,min(25,$limit));$after=max(0,$after);
  global $wpdb;
  $rows=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_mime_type LIKE %s AND ID>%d ORDER BY ID ASC LIMIT %d",'attachment','image/%',$after,$limit));
  $out=[];foreach($rows as $id)$out[]=['id'=>(int)$id,'result'=>self::process((int)$id)['status']];
  return ['processed'=>count($out),'last_id'=>$rows?(int)end($rows):$after,'results'=>$out,'bounded'=>true];
 }
 public static function cli($args,$assoc): void {
  if(count($args)!==1 || !in_array($args[0],['status','scan','published-review','index-doctor','index-reconcile'],true))WP_CLI::error('Usage: wp mad4b media-rights-intake status|scan|published-review|index-doctor|index-reconcile [--after=ID --limit=12]');
  if($args[0]==='status') {WP_CLI::line(wp_json_encode(['state'=>'REGISTERED','hook'=>self::HOOK,'cct'=>'media_rights','automatic_enqueue'=>true,'auto_approval'=>false,'source_url_required_for_spontaneous_intake'=>true,'verified_published_origins_can_be_drafted_without_source_url'=>true,'batch_limit'=>25,'published_tour_batch_limit'=>15,'published_tour_event'=>self::PUBLISHED_TOUR_HOOK,'deferred_by_post_id'=>true]));return;}
  if(in_array($args[0],['index-doctor','index-reconcile'],true)){
   $r=$args[0]==='index-reconcile'?self::reconcile_origin_index():self::origin_index_status();
   WP_CLI::line(wp_json_encode($r));return;
  }
  $out=$args[0]==='published-review'
   ?self::review_published_tour_batch((int)($assoc['after']??0),(int)($assoc['limit']??12))
   :self::reconcile_batch((int)($assoc['after']??0),(int)($assoc['limit']??25));
  WP_CLI::line(wp_json_encode($out));
 }
}
add_action('save_post_tours-and-activities',
 [MAD4B_Media_Rights_Extraction_Intake_V1::class,'schedule_on_tour_save'],100,3);
add_action('added_post_meta',
 [MAD4B_Media_Rights_Extraction_Intake_V1::class,'schedule_on_tour_media_change'],11,4);
add_action('updated_post_meta',
 [MAD4B_Media_Rights_Extraction_Intake_V1::class,'schedule_on_tour_media_change'],11,4);
add_action(MAD4B_Media_Rights_Extraction_Intake_V1::PUBLISHED_TOUR_HOOK,
 [MAD4B_Media_Rights_Extraction_Intake_V1::class,'review_published_tour_event'],10,1);
add_action('add_attachment',[MAD4B_Media_Rights_Extraction_Intake_V1::class,'schedule'],10,1);
add_action('added_post_meta',[MAD4B_Media_Rights_Extraction_Intake_V1::class,'changed_meta'],10,4);
add_action('updated_post_meta',[MAD4B_Media_Rights_Extraction_Intake_V1::class,'changed_meta'],10,4);
add_action(MAD4B_Media_Rights_Extraction_Intake_V1::HOOK,[MAD4B_Media_Rights_Extraction_Intake_V1::class,'process'],10,1);
if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b media-rights-intake',[MAD4B_Media_Rights_Extraction_Intake_V1::class,'cli']);
}

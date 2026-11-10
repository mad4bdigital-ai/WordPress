<?php
/**
 * Plugin Name: MAD4B Media Rights Extraction Intake
 * Description: Bounded, idempotent JetEngine CCT discovery of new local image attachments; never auto-licenses or publishes.
 * Version: 1.2.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Media_Rights_Extraction_Intake_V1', false)) {
final class MAD4B_Media_Rights_Extraction_Intake_V1 {
 const HOOK = 'mad4b_media_rights_extract_attachment_v1';
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
 public static function process(int $attachment_id): array {
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
  // A reviewed, published image always wins over an unverified intake candidate.
  global $wpdb;$table=$factory->db->table();
  $published=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE source_sha256=%s AND cct_status=%s",$sha,'publish'));
  if ($published>0) return ['status'=>'already_licensed','attachment_id'=>$attachment_id];
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
  if(!$has_external_provenance)return ['status'=>'no_external_provenance','attachment_id'=>$attachment_id];
  $key=self::PREFIX.$attachment_id;
  $factory->db->set_format_flag(ARRAY_A);
  $existing=$factory->db->query(['credit_key'=>$key],2);
  if (count($existing)>1) return ['status'=>'duplicate_key_requires_repair'];
  if ($existing) {
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
  return ['status'=>'pending_rights_review','attachment_id'=>$attachment_id,'credit_key'=>$key,'cct_id'=>(int)$insert];
 }
 public static function reconcile_batch(int $after=0,int $limit=25): array {
  $limit=max(1,min(25,$limit));$after=max(0,$after);
  global $wpdb;
  $rows=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_mime_type LIKE %s AND ID>%d ORDER BY ID ASC LIMIT %d",'attachment','image/%',$after,$limit));
  $out=[];foreach($rows as $id)$out[]=['id'=>(int)$id,'result'=>self::process((int)$id)['status']];
  return ['processed'=>count($out),'last_id'=>$rows?(int)end($rows):$after,'results'=>$out,'bounded'=>true];
 }
 public static function cli($args,$assoc): void {
  if(count($args)!==1 || !in_array($args[0],['status','scan'],true))WP_CLI::error('Usage: wp mad4b media-rights-intake status|scan [--after=ID --limit=25]');
  if($args[0]==='status') {WP_CLI::line(wp_json_encode(['state'=>'REGISTERED','hook'=>self::HOOK,'cct'=>'media_rights','automatic_enqueue'=>true,'auto_approval'=>false,'external_provenance_required'=>true,'batch_limit'=>25]));return;}
  $out=self::reconcile_batch((int)($assoc['after']??0),(int)($assoc['limit']??25));
  WP_CLI::line(wp_json_encode($out));
 }
}
add_action('add_attachment',[MAD4B_Media_Rights_Extraction_Intake_V1::class,'schedule'],10,1);
add_action('added_post_meta',[MAD4B_Media_Rights_Extraction_Intake_V1::class,'changed_meta'],10,4);
add_action('updated_post_meta',[MAD4B_Media_Rights_Extraction_Intake_V1::class,'changed_meta'],10,4);
add_action(MAD4B_Media_Rights_Extraction_Intake_V1::HOOK,[MAD4B_Media_Rights_Extraction_Intake_V1::class,'process'],10,1);
if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b media-rights-intake',[MAD4B_Media_Rights_Extraction_Intake_V1::class,'cli']);
}

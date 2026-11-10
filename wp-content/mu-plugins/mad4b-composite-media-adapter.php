<?php
defined('ABSPATH') || exit;
/** Candidate adapter only. Loaded by the copied router during verification; not deployed. */
final class MAD4B_Composite_Media_Adapter_V1 {
 private const LEDGER='mad4b_composite_media_review_v1_';
 private static function err(string $s):WP_Error {return new WP_Error('mad4b_composite_'.$s);}
 private static function keys_match(array $value,array $expected):bool {
  $keys=array_keys($value);sort($keys);sort($expected);return $keys===$expected;
 }
 private static function hash($v):string {return hash('sha256',wp_json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
 private static function read_raw(array $target,string $field):array|WP_Error {
  global $wpdb;
  $table=$target['kind']==='term'?$wpdb->termmeta:$wpdb->postmeta;
  $col=$target['kind']==='term'?'term_id':'post_id';
  $rows=$wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_value FROM {$table} WHERE {$col}=%d AND meta_key=%s ORDER BY meta_id LIMIT 2",$target['id'],$field),ARRAY_A);
  if(!is_array($rows)||count($rows)!==1)return self::err('meta_missing_or_duplicate');
  return ['meta_id'=>(int)$rows[0]['meta_id'],'serialized'=>$rows[0]['meta_value'],'value'=>maybe_unserialize($rows[0]['meta_value'])];
 }
 private static function actor():array|WP_Error {
  if(!class_exists('MAD4B_SCP_Host_Agent_Bridge',false)||!MAD4B_SCP_Host_Agent_Bridge::active()||!class_exists('MAD4B_SCP_Context_Authority',false))return self::err('signed_host_required');
  $policy=MAD4B_SCP_Context_Authority::ai_review_policy_status();
  $agent=MAD4B_SCP_Host_Agent_Bridge::agent();$site=MAD4B_SCP_Host_Agent_Bridge::site();
  if(!is_array($policy)||!($policy['ready']??false)||($policy['mode']??'')!=='ai_only'||!is_array($agent)||!is_array($site)||!hash_equals((string)($policy['ai_agent_public_id']??''),(string)($agent['public_id']??'')))return self::err('ai_delegate_unavailable');
  return ['site_uuid'=>$site['site_uuid'],'agent_public_id'=>$agent['public_id']];
 }
 private static function context(array $payload,array $target,string $adapter):array|WP_Error {
  if(!self::keys_match($payload,['contract','site_uuid','field','attachment_id','sha256','expected_value_sha256','operation']))return self::err('payload_keys_invalid');
  if(($payload['contract']??'')!=='mad4b.media.composite-attachment.v1')return self::err('contract_invalid');
  $actor=self::actor();if(is_wp_error($actor))return $actor;
  if(!is_string($payload['site_uuid'])||!hash_equals((string)$actor['site_uuid'],$payload['site_uuid']))return self::err('site_mismatch');
  $field=$payload['field'];
  if(!is_string($field)||!preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]{1,79}$/D',$field))return self::err('field_invalid');
  if(!class_exists('MAD4B_Composite_Media_Integrity_V1',false))return self::err('field_registry_missing');
  $schema=MAD4B_Composite_Media_Integrity_V1::fields($target['kind'],$target['type'])[$field]??null;
  if(!is_array($schema)||!in_array($schema['type'],['gallery','repeater'],true))return self::err('field_not_registered');
  if($adapter!==$target['kind'].'_registered_composite')return self::err('adapter_mismatch');
  $aid=$payload['attachment_id'];$sha=$payload['sha256'];
  if(!is_int($aid)||$aid<1||get_post_type($aid)!=='attachment'||!str_starts_with((string)get_post_mime_type($aid),'image/')||!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha))return self::err('attachment_invalid');
  $file=get_attached_file($aid);
  if(!is_string($file)||!is_file($file)||!hash_equals($sha,hash_file('sha256',$file)))return self::err('attachment_hash_invalid');
  $url=wp_get_attachment_url($aid);$host=wp_parse_url($url,PHP_URL_HOST);
  if(!is_string($url)||!is_string($host)||strtolower($host)!==strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)))return self::err('attachment_not_local');
  $raw=self::read_raw($target,$field);if(is_wp_error($raw))return $raw;
  if(!is_string($payload['expected_value_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$payload['expected_value_sha256'])||!hash_equals(self::hash($raw['value']),$payload['expected_value_sha256']))return self::err('raw_meta_drift');
  $op=$payload['operation'];if(!is_array($op))return self::err('operation_invalid');
  if($schema['type']==='gallery'){
   // Empty galleries have no persisted encoding; require an explicit schema format before the adapter can write.
   if(($raw['value']===[] || $raw['value']===null) || ($raw['value']==='' && !in_array($schema['format']??null,['id','url'],true)))return self::err('empty_gallery_format_unresolved');
   if(!self::keys_match($op,['action'])||$op['action']!=='append_gallery')return self::err('gallery_operation_invalid');
   // Only use the existing persisted representation, not a caller-selected storage format.
   if(is_string($raw['value']) && ($raw['value']==='' && ($schema['format']??null)==='url' || $raw['value']!=='' && !ctype_digit(trim(explode(',',$raw['value'])[0]))))$value=$url;
   elseif(is_array($raw['value']) && isset($raw['value'][0]) && is_string($raw['value'][0]) && !ctype_digit($raw['value'][0]))$value=$url;
   else $value=$aid;
   $patchOp=['action'=>'append_gallery','media'=>$value,'expected_sha256'=>self::hash($raw['value'])];
   if(is_array($raw['value']) && isset($raw['value'][0]) && is_array($raw['value'][0])){$patchOp['attachment_id']=$aid;$patchOp['url']=$url;}
  }else{
   if(!self::keys_match($op,['action','row','field']) || !in_array($op['action'],['set_repeater_media','append_repeater_gallery'],true))return self::err('repeater_operation_invalid');
   $row=$op['row'];$nested=$op['field'];
   if(!(is_int($row)||is_string($row))||!is_string($nested))return self::err('repeater_path_invalid');
   $subtype=$op['action']==='set_repeater_media'?'media':'gallery';
   $format=null;$declared=false;
   foreach($schema['repeater_fields'] as $f){if(($f['name']??null)===$nested && ($f['type']??null)===$subtype){$declared=true;$format=$f['value_format']??null;}}
   if(!$declared)return self::err('repeater_slot_not_registered');
   if(!is_array($raw['value'])||!isset($raw['value'][$row])||!is_array($raw['value'][$row])||!array_key_exists($nested,$raw['value'][$row]))return self::err('repeater_slot_missing');
   $current=$raw['value'][$row][$nested];
   if(!in_array($format,['id','url'],true)){
    if(is_int($current)&&$current>0 || is_string($current)&&ctype_digit($current))$format='id';
    elseif(is_string($current)&&filter_var($current,FILTER_VALIDATE_URL))$format='url';
    else return self::err('repeater_format_unresolved');
   }
   $value=$format==='url'?$url:$aid;
   $patchOp=['action'=>$op['action'],'row'=>$row,'field'=>$nested,'media'=>$value,'expected_sha256'=>self::hash($raw['value'])];
   if($subtype==='gallery'){$patchOp['attachment_id']=$aid;$patchOp['url']=$url;}
  }
  if(!class_exists('MAD4B_Composite_Media_Patch_V1',false))return self::err('patch_engine_missing');
  $planned=MAD4B_Composite_Media_Patch_V1::plan($raw['value'],$schema,$patchOp);
  if(!($planned['ok']??false))return self::err('patch_'.$planned['error']);
  if(!$planned['changed'])return self::err('unchanged_write_not_allowed');
  $identity=['site_uuid'=>$actor['site_uuid'],'target'=>$target,'adapter'=>$adapter,'field'=>$field,'operation'=>$op,'attachment_id'=>$aid,'attachment_sha256'=>$sha,'old_sha256'=>$planned['old_sha256'],'new_sha256'=>$planned['new_sha256']];
  return ['actor'=>$actor,'identity'=>$identity,'raw'=>$raw,'plan'=>$planned,'field'=>$field,'sha256'=>$sha];
 }
 /** Restore the exact raw preimage only through the original signed actor and receipt. */
 private static function revoke(array $payload,array $target,string $adapter):array|WP_Error {
  if(!self::keys_match($payload,['field','identity_sha256','receipt_sha256']))return self::err('revoke_schema_invalid');
  $field=$payload['field'];$identitySha=$payload['identity_sha256'];$receiptSha=$payload['receipt_sha256'];
  if(!is_string($field)||!preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]{1,79}$/D',$field)||!is_string($identitySha)||!preg_match('/^[a-f0-9]{64}$/D',$identitySha)||!is_string($receiptSha)||!preg_match('/^[a-f0-9]{64}$/D',$receiptSha))return self::err('revoke_identity_invalid');
  $actor=self::actor();if(is_wp_error($actor))return $actor;
  if(!class_exists('MAD4B_SCP_Audit',false))return self::err('audit_unavailable');
  $idxKey='mad4b_composite_restore_index_v1_'.self::hash([$actor['site_uuid'],$target,$adapter,$field,$identitySha]);
  $idx=get_option($idxKey,false);
  if(!is_array($idx)||($idx['status']??'')!=='applied'||!hash_equals((string)($idx['agent_public_id']??''),(string)$actor['agent_public_id'])||!hash_equals((string)($idx['receipt_sha256']??''),$receiptSha))return self::err('revoke_not_authorized');
  $backupKey=$idx['backup_key']??'';$reviewKey=$idx['approval_key']??'';
  if(!is_string($backupKey)||!is_string($reviewKey))return self::err('revoke_index_invalid');
  $backup=get_option($backupKey,false);$approval=get_option($reviewKey,false);
  if(!is_array($backup)||($backup['state']??'')!=='applied'||!is_array($approval)||($approval['status']??'')!=='applied'||!hash_equals((string)($approval['receipt_sha256']??''),$receiptSha)||!hash_equals((string)($backup['identity_sha256']??''),$identitySha))return self::err('revoke_receipt_mismatch');
  $raw=self::read_raw($target,$field);if(is_wp_error($raw))return $raw;
  if((int)$raw['meta_id']!==(int)$backup['meta_id']||!hash_equals((string)$backup['expected_new_sha256'],self::hash($raw['value'])))return self::err('revoke_value_drift');
  global $wpdb;$table=$target['kind']==='term'?$wpdb->termmeta:$wpdb->postmeta;
  $lock='mad4b_composite_media_lock_'.self::hash([$target,$field]);
  if(!add_option($lock,['time'=>time()],'','no'))return self::err('concurrent_write');
  $before=$raw['serialized'];$old=$backup['original_raw_meta'];$changed=false;
  $savedLedger=[$reviewKey=>$approval,$backupKey=>$backup,$idxKey=>$idx];
  try{
   $count=$wpdb->query($wpdb->prepare("UPDATE {$table} SET meta_value=%s WHERE meta_id=%d AND BINARY meta_value=BINARY %s",$old,$raw['meta_id'],$before));
   if($count!==1)throw new RuntimeException('revoke_cas_failed');$changed=true;
   wp_cache_delete($target['id'],$target['kind']==='term'?'term_meta':'post_meta');
   $verified=self::read_raw($target,$field);
   if(is_wp_error($verified)||$verified['serialized']!==$old)throw new RuntimeException('revoke_readback_failed');
   $audit=MAD4B_SCP_Audit::record('mad4b/composite-media-restored',['identity_sha256'=>$identitySha,'site_uuid'=>$actor['site_uuid'],'meta_id'=>$raw['meta_id'],'receipt_sha256'=>$receiptSha]);
   if(is_wp_error($audit))throw new RuntimeException('revoke_audit_failed');
   $approval['status']='restored';$backup['state']='restored';$idx['status']='restored';
   if(!update_option($reviewKey,$approval,false)||!update_option($backupKey,$backup,false)||!update_option($idxKey,$idx,false))throw new RuntimeException('revoke_ledger_update_failed');
   if($target['kind']==='term')clean_term_cache($target['id'],$target['type']);else clean_post_cache($target['id']);
   return ['status'=>'restored_verified','identity_sha256'=>$identitySha,'previous_receipt_sha256'=>$receiptSha,'write_executed'=>true];
  }catch(Throwable $e){
   if($changed){
    $back=$wpdb->query($wpdb->prepare("UPDATE {$table} SET meta_value=%s WHERE meta_id=%d AND BINARY meta_value=BINARY %s",$before,$raw['meta_id'],$old));
    wp_cache_delete($target['id'],$target['kind']==='term'?'term_meta':'post_meta');
    if($back!==1)return self::err('revoke_rollback_failed_manual_recovery');
   }
   foreach($savedLedger as $k=>$v){if(get_option($k,false)!==$v && !update_option($k,$v,false))return self::err('revoke_ledger_recovery_failed');}
   return self::err('revoke_failed_no_change');
  }finally{delete_option($lock);}
 }
 public static function run(array $payload,array $target,string $adapter,string $action):array|WP_Error {
  if($action==='revoke')return self::revoke($payload,$target,$adapter);
  $asset=$action==='review'?($payload['asset']??null):$payload;
  if(!is_array($asset))return self::err('asset_missing');
  $cx=self::context($asset,$target,$adapter);if(is_wp_error($cx))return $cx;
  $key=self::LEDGER.self::hash($cx['identity']);
  if($action==='preflight')return ['status'=>'preflight_valid','identity_sha256'=>self::hash($cx['identity']),'new_sha256'=>$cx['plan']['new_sha256'],'write_executed'=>false];
  if(!class_exists('MAD4B_SCP_Audit',false))return self::err('audit_unavailable');
  if($action==='review'){
   if(!self::keys_match($payload,['asset','review']))return self::err('review_schema_invalid');
   $review=$payload['review'];
   if(!is_array($review)||!self::keys_match($review,['decision','reviewed_sha256','visual_match_verified','rights_verified','subject_description','rights_evidence'])||($review['decision']??'')!=='approve'||($review['visual_match_verified']??false)!==true||($review['rights_verified']??false)!==true||!hash_equals($cx['sha256'],(string)($review['reviewed_sha256']??''))||mb_strlen((string)($review['subject_description']??''))<35||mb_strlen((string)($review['rights_evidence']??''))<35)return self::err('review_evidence_invalid');
   $approved=['identity'=>$cx['identity'],'agent_public_id'=>$cx['actor']['agent_public_id'],'status'=>'approved','expires_at'=>time()+86400,'evidence_sha256'=>self::hash($review)];
   if(get_option($key,false)!==false)return self::err('approval_already_exists');
   $audit=MAD4B_SCP_Audit::record('mad4b/composite-media-reviewed',['identity_sha256'=>self::hash($cx['identity']),'site_uuid'=>$cx['actor']['site_uuid']]);
   if(is_wp_error($audit))return $audit;
   if(!add_option($key,$approved,'','no'))return self::err('approval_write_failed');
   return ['status'=>'approved','identity_sha256'=>self::hash($cx['identity']),'write_executed'=>false];
  }
  if($action!=='apply')return self::err('action_unsupported');
  $approved=get_option($key,false);
  if(!is_array($approved)||($approved['status']??'')!=='approved'||($approved['expires_at']??0)<=time()||!hash_equals((string)($approved['agent_public_id']??''),(string)$cx['actor']['agent_public_id'])||!hash_equals(self::hash($approved['identity']??null),self::hash($cx['identity'])))return self::err('approval_missing');
  global $wpdb;
  $table=$target['kind']==='term'?$wpdb->termmeta:$wpdb->postmeta;
  $lock='mad4b_composite_media_lock_'.self::hash([$target,$cx['field']]);
  if(!add_option($lock,['time'=>time()],'','no'))return self::err('concurrent_write');
  $old=$cx['raw']['serialized'];$new=maybe_serialize($cx['plan']['value']);$id=$cx['raw']['meta_id'];$changed=false;
  $backupKey='mad4b_composite_backup_v1_'.self::hash([$cx['identity'],$id]);
  $indexKey='mad4b_composite_restore_index_v1_'.self::hash([$cx['actor']['site_uuid'],$target,$adapter,$cx['field'],self::hash($cx['identity'])]);
  $index=['site_uuid'=>$cx['actor']['site_uuid'],'agent_public_id'=>$cx['actor']['agent_public_id'],'status'=>'prepared','approval_key'=>$key,'backup_key'=>$backupKey];
  $backup=['site_uuid'=>$cx['actor']['site_uuid'],'identity_sha256'=>self::hash($cx['identity']),'meta_id'=>$id,'original_raw_meta'=>$old,'expected_new_sha256'=>$cx['plan']['new_sha256'],'created_at'=>gmdate('c'),'state'=>'prepared'];
  if(!add_option($backupKey,$backup,'','no')){delete_option($lock);return self::err('backup_creation_failed');}
  if(!add_option($indexKey,$index,'','no')){delete_option($backupKey);delete_option($lock);return self::err('revoke_index_creation_failed');}
  try {
   $count=$wpdb->query($wpdb->prepare("UPDATE {$table} SET meta_value=%s WHERE meta_id=%d AND BINARY meta_value=BINARY %s",$new,$id,$old));
   if($count!==1)throw new RuntimeException('atomic_compare_swap_failed');
   $changed=true;
   wp_cache_delete($target['id'],$target['kind']==='term'?'term_meta':'post_meta');
   $now=self::read_raw($target,$cx['field']);
   if(is_wp_error($now)||!hash_equals(self::hash($cx['plan']['value']),self::hash($now['value'])))throw new RuntimeException('readback_failed');
   $receipt=['site_uuid'=>$cx['actor']['site_uuid'],'target'=>$target,'adapter'=>$adapter,'identity_sha256'=>self::hash($cx['identity']),'old_sha256'=>$cx['plan']['old_sha256'],'new_sha256'=>$cx['plan']['new_sha256'],'meta_id'=>$id,'status'=>'applied_verified','rollback_preimage'=>$old];
   $audit=MAD4B_SCP_Audit::record('mad4b/composite-media-applied',['identity_sha256'=>$receipt['identity_sha256'],'site_uuid'=>$cx['actor']['site_uuid'],'meta_id'=>$id]);
   if(is_wp_error($audit))throw new RuntimeException('audit_failed');
   $approved['status']='applied';$approved['receipt_sha256']=self::hash($receipt);
   if(!update_option($key,$approved,false))throw new RuntimeException('approval_consumption_failed');
   $backup['state']='applied';$backup['receipt_sha256']=$approved['receipt_sha256'];
   if(!update_option($backupKey,$backup,false))throw new RuntimeException('backup_ledger_update_failed');
   $index['status']='applied';$index['receipt_sha256']=$approved['receipt_sha256'];
   if(!update_option($indexKey,$index,false))throw new RuntimeException('index_ledger_update_failed');
   if($target['kind']==='term')clean_term_cache($target['id'],$target['type']);else clean_post_cache($target['id']);
   return ['status'=>'applied_verified','receipt_sha256'=>$approved['receipt_sha256'],'identity_sha256'=>$receipt['identity_sha256'],'write_executed'=>true,'rollback_preimage_stored'=>true];
  }catch(Throwable $e){
   if($changed){
    $reverted=$wpdb->query($wpdb->prepare("UPDATE {$table} SET meta_value=%s WHERE meta_id=%d AND BINARY meta_value=BINARY %s",$old,$id,$new));
    wp_cache_delete($target['id'],$target['kind']==='term'?'term_meta':'post_meta');
    if($reverted!==1){$backup['state']='rollback_failed';update_option($backupKey,$backup,false);return self::err('rollback_failed_manual_recovery_required');}
   }
   // A metadata rollback must also return the review ledger to its approved
   // state, so the one-time approval is not falsely consumed by a failed apply.
   if(($approved['status']??'')==='applied'){
    $approved['status']='approved';unset($approved['receipt_sha256']);
    if(!update_option($key,$approved,false))return self::err('approval_ledger_recovery_failed');
   }
   delete_option($backupKey);delete_option($indexKey);
   return self::err('apply_failed_rolled_back_or_no_write');
  }finally{delete_option($lock);}
 }
}

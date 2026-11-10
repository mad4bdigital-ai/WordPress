<?php
// Exact, one-time portability normalization for already copied gallery files.
// This does not import, license, reassign, remove or reorder any images.
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(2);
$mode=(string)($args[0]??'preview');
if(!in_array($mode,['preview','apply'],true))WP_CLI::error('Expected preview or apply');
$site=(array)get_option('mad4b_scp_site_profile_v2',[]);
if(($site['site_uuid']??'')!=='a3a7cc36-db7e-4a14-8456-b41857def05c')WP_CLI::error('company_scope_mismatch');
$terms=[122,123,560,721,2404,2509];
$old_prefix='https://staging.egypttourgates.com/wp-content/uploads/';
$uploads=wp_get_upload_dir();$root=realpath($uploads['basedir']);
if(!$root || !empty($uploads['error']))WP_CLI::error('uploads_not_ready');
$new_prefix=trailingslashit($uploads['baseurl']);
if(!str_starts_with($new_prefix,'https://wayfatours.com/wp-content/uploads/'))WP_CLI::error('unexpected_target_uploads');
global $wpdb;$out=[];$all=[];
foreach($terms as $id){
 $term=get_term($id,'destinations');
 if(!$term instanceof WP_Term)WP_CLI::error('destination_missing_'.$id);
 $rows=$wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_value FROM {$wpdb->termmeta} WHERE term_id=%d AND meta_key='image_gellery'",$id),ARRAY_A);
 if(count($rows)!==1)WP_CLI::error('unexpected_meta_count_'.$id);
 $before=$rows[0]['meta_value'];
 if(!is_string($before) || $before==='')WP_CLI::error('empty_gallery_'.$id);
 $urls=explode(',',$before);$mapped=[];
 foreach($urls as $url){
  if(!str_starts_with($url,$old_prefix))WP_CLI::error('noncanonical_gallery_url_'.$id);
  $relative=rawurldecode(substr($url,strlen($old_prefix)));
  if($relative===''||str_contains($relative,'..')||str_contains($relative,'\\'))WP_CLI::error('unsafe_relative_media_path_'.$id);
  $file=realpath($root.'/'.$relative);
  if(!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file)||!is_readable($file))WP_CLI::error('unavailable_local_media_'.$id);
  $mapped[]=$new_prefix.substr($url,strlen($old_prefix));
 }
 $after=implode(',',$mapped);
 if($after===$before)WP_CLI::error('migration_already_applied_'.$id);
 $all[]=['id'=>$id,'meta_id'=>(int)$rows[0]['meta_id'],'original'=>$before,'replacement'=>$after,'count'=>count($urls)];
 $out[]=['term_id'=>$id,'name'=>$term->name,'media_count'=>count($urls),'old_sha256'=>hash('sha256',$before),'new_sha256'=>hash('sha256',$after)];
}
if($mode==='apply'){
 $changed=[];
 foreach($all as $item){
  $n=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->termmeta} SET meta_value=%s WHERE meta_id=%d AND term_id=%d AND meta_value=%s",$item['replacement'],$item['meta_id'],$item['id'],$item['original']));
  if($n!==1){
   foreach(array_reverse($changed) as $undo){$wpdb->query($wpdb->prepare("UPDATE {$wpdb->termmeta} SET meta_value=%s WHERE meta_id=%d AND meta_value=%s",$undo['original'],$undo['meta_id'],$undo['replacement']));wp_cache_delete($undo['id'],'term_meta');}
   WP_CLI::error('concurrent_or_failed_gallery_update_'.$item['id']);
  }
  $changed[]=$item;wp_cache_delete($item['id'],'term_meta');clean_term_cache($item['id'],'destinations');
 }
 foreach($all as $item){$raw=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_id=%d",$item['meta_id']));if($raw!==$item['replacement'])WP_CLI::error('gallery_readback_failed_'.$item['id']);}
}
WP_CLI::line(wp_json_encode(['contract'=>'mad4b.gallery-portability.v1','site_uuid'=>$site['site_uuid'],'mode'=>$mode,'all_local_media_verified'=>true,'gallery_items'=>array_sum(array_column($out,'media_count')),'items'=>$out,'changed'=>$mode==='apply'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

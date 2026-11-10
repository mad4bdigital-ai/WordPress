<?php
// Exact post gallery URL portability correction: retain IDs and ordering.
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(2);
$mode=(string)($args[0]??'preview');
if(!in_array($mode,['preview','apply'],true))WP_CLI::error('expected_preview_or_apply');
$site=(array)get_option('mad4b_scp_site_profile_v2',[]);
if(($site['site_uuid']??'')!=='a3a7cc36-db7e-4a14-8456-b41857def05c')WP_CLI::error('site_identity_mismatch');
$ids=[46502,46501,46500,46499,46498,46497,46496,46495];
$oldhost='staging.egypttourgates.com';
global $wpdb;$changes=[];$summary=[];
foreach($ids as $post_id){
 $p=get_post($post_id);if(!$p||$p->post_type!=='properties'||$p->post_status!=='publish')WP_CLI::error('post_preimage_mismatch_'.$post_id);
 $rows=$wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='property_gallery'",$post_id),ARRAY_A);
 if(count($rows)!==1)WP_CLI::error('gallery_storage_count_mismatch_'.$post_id);
 $raw=$rows[0]['meta_value'];$gallery=maybe_unserialize($raw);
 if(!is_array($gallery)||!array_is_list($gallery)||count($gallery)<1||count($gallery)>40)WP_CLI::error('gallery_data_shape_unexpected_'.$post_id);
 $new=$gallery;
 foreach($gallery as $idx=>$row){
  if(!is_array($row)||array_keys($row)!==['id','url'])WP_CLI::error('gallery_row_schema_'.$post_id);
  $aid=(int)$row['id'];$old_url=(string)$row['url'];$new_url=(string)wp_get_attachment_url($aid);$file=get_attached_file($aid);
  if($aid<1||get_post_type($aid)!=='attachment'||!is_file((string)$file)||!is_readable((string)$file))WP_CLI::error('attachment_not_local_'.$post_id);
  if((string)wp_parse_url($old_url,PHP_URL_HOST)!==$oldhost||!str_starts_with($new_url,'https://wayfatours.com/wp-content/uploads/'))WP_CLI::error('host_scope_mismatch_'.$post_id);
  if(rawurldecode((string)wp_parse_url($old_url,PHP_URL_PATH))!==rawurldecode((string)wp_parse_url($new_url,PHP_URL_PATH)))WP_CLI::error('attachment_path_discrepancy_'.$post_id);
  $new[$idx]['url']=$new_url;
 }
 $replacement=maybe_serialize($new);
 if($raw===$replacement)WP_CLI::error('already_migrated_'.$post_id);
 $changes[]=['post_id'=>$post_id,'meta_id'=>(int)$rows[0]['meta_id'],'before'=>$raw,'after'=>$replacement,'count'=>count($gallery)];
 $summary[]=['post_id'=>$post_id,'images'=>count($gallery),'old_sha256'=>hash('sha256',$raw),'new_sha256'=>hash('sha256',$replacement)];
}
if($mode==='apply'){
 $done=[];
 foreach($changes as $c){
  $n=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND post_id=%d AND meta_value=%s",$c['after'],$c['meta_id'],$c['post_id'],$c['before']));
  if($n!==1){
   foreach(array_reverse($done) as $r){$wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND meta_value=%s",$r['before'],$r['meta_id'],$r['after']));wp_cache_delete($r['post_id'],'post_meta');}
   WP_CLI::error('compare_and_swap_failure_'.$c['post_id']);
  }
  $done[]=$c;wp_cache_delete($c['post_id'],'post_meta');clean_post_cache($c['post_id']);
 }
 foreach($changes as $c){$saved=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id=%d",$c['meta_id']));if($saved!==$c['after'])WP_CLI::error('post_gallery_readback_failure_'.$c['post_id']);}
}
WP_CLI::line(wp_json_encode(['contract'=>'mad4b.property-gallery-portability.v1','site_uuid'=>$site['site_uuid'],'mode'=>$mode,'images'=>array_sum(array_column($summary,'images')),'posts'=>$summary,'changed'=>$mode==='apply'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

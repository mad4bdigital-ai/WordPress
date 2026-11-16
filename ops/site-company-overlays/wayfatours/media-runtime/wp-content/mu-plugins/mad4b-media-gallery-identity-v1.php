<?php
/**
 * Plugin Name: MAD4B Media Gallery Identity Guard
 * Description: Resolve source attachment identities to tenant-local media after template transfer; never overwrite valid company galleries.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Media_Gallery_Identity_V1',false)) {
final class MAD4B_Media_Gallery_Identity_V1 {
 const OPTION='mad4b_media_gallery_identity_manifest_v1';
 private static bool $busy=false;
 private static array $resolution=[];
 static function manifest():array {$v=get_option(self::OPTION,[]);return is_array($v)?$v:[];}
 static function local_media(array $entry):int {
  $file=$entry['relative_file']??'';$sha=$entry['sha256']??'';$lang=$entry['language']??'';
  if(!is_string($file)||!preg_match('~^[0-9]{4}/[0-9]{2}/[^\\\\\x00]{3,300}$~D',$file)
     ||str_contains($file,'..')||!is_string($sha)||!preg_match('/^[0-9a-f]{64}$/D',$sha))return 0;
  $key=hash('sha256',$file.$sha.$lang);
  if(isset(self::$resolution[$key]))return self::$resolution[$key];
  global $wpdb;
  $ids=$wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type='attachment' AND m.meta_key='_wp_attached_file' AND m.meta_value=%s ORDER BY p.ID ASC LIMIT 32",$file));
  $local=[];
  foreach($ids as $id){$id=(int)$id;$path=get_attached_file($id);if(!$path || !is_file($path)||get_post_type($id)!=='attachment')continue;
   if(hash_file('sha256',$path)!==$sha)continue;
   $code=apply_filters('wpml_element_language_code',null,['element_id'=>$id,'element_type'=>'post_attachment']);
   if($code===$lang)$local[]=$id;
  }
  return self::$resolution[$key]=($local?(int)$local[0]:0);
 }
 static function inspect(int $post):array {
  $manifest=self::manifest();$cfg=$manifest['templates'][(string)$post]??null;
  if(!is_array($cfg)||($cfg['meta_key']??'')!=='dfsb_background_gallery'
     ||get_post_type($post)!=='elementor_library')return ['status'=>'unmanaged','post'=>$post];
  $current=get_post_meta($post,'dfsb_background_gallery',true);
  if(!is_array($current)||count($current)!==count($cfg['images']??[]))
   return ['status'=>'needs_review','post'=>$post,'reason'=>'gallery_shape_mismatch'];
  $valid=true;foreach($current as $id){$id=absint($id);$p=$id?get_attached_file($id):null;if(!$p||!is_file($p)||get_post_type($id)!=='attachment'){$valid=false;break;}}
  if($valid)return ['status'=>'valid_local_gallery','post'=>$post,'gallery'=>$current];
  $source=array_map(static fn($x)=>absint($x['source_id']??0),(array)$cfg['images']);
  if(array_values(array_map('absint',$current))!==$source)
   return ['status'=>'needs_review','post'=>$post,'reason'=>'unknown_source_ids'];
  $dest=[];foreach($cfg['images'] as $item){$id=self::local_media((array)$item);if(!$id)return ['status'=>'needs_review','post'=>$post,'reason'=>'missing_checksum_match','source_id'=>$item['source_id']??0];$dest[]=$id;}
  if(count(array_unique($dest))!==count($dest))return ['status'=>'needs_review','post'=>$post,'reason'=>'duplicate_local_images'];
  return ['status'=>'remappable','post'=>$post,'source'=>$source,'local'=>$dest];
 }
 static function repair(int $post):array {
  if(self::$busy)return ['status'=>'busy'];
  $review=self::inspect($post);
  if(($review['status']??'')!=='remappable')return $review;
  self::$busy=true;
  try{
   $before=get_post_meta($post,'dfsb_background_gallery',true);
   if(!update_post_meta($post,'dfsb_background_gallery',$review['local'],$before))return ['status'=>'update_failed','post'=>$post];
   $after=get_post_meta($post,'dfsb_background_gallery',true);
   return $after===$review['local']?['status'=>'repaired','post'=>$post,'local'=>$after]:['status'=>'readback_failed','post'=>$post];
  }finally{self::$busy=false;}
 }
 static function changed($metaId,$post,$key,$value):void {
  if(self::$busy||$key!=='dfsb_background_gallery'||!isset(self::manifest()['templates'][(string)$post]))return;
  $result=self::repair((int)$post);
  if(in_array($result['status']??'',['needs_review','readback_failed','update_failed'],true)){
   error_log('MAD4B_MEDIA_GALLERY_PARITY '.wp_json_encode(['post'=>(int)$post,'result'=>$result]));
  }
 }
 static function cli(array $args):void {
  $mode=$args[0]??'audit';if(!in_array($mode,['audit','repair'],true))WP_CLI::error('Use audit or repair');
  $manifest=self::manifest();$results=[];
  foreach(array_keys((array)($manifest['templates']??[])) as $post){$results[]=$mode==='repair'?self::repair((int)$post):self::inspect((int)$post);}
  WP_CLI::line(wp_json_encode(['site'=>home_url(),'contract'=>$manifest['contract']??'unconfigured','results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
 }
 static function boot():void {
  add_action('added_post_meta',[__CLASS__,'changed'],50,4);
  add_action('updated_post_meta',[__CLASS__,'changed'],50,4);
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI')) WP_CLI::add_command('mad4b media-identity',[__CLASS__,'cli']);
 }
}
MAD4B_Media_Gallery_Identity_V1::boot();
}

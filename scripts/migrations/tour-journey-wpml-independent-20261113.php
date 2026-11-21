<?php
defined('ABSPATH') || exit;
$locale=(string)getenv('TOUR_DEST_LOCALE');
$action=(string)getenv('TOUR_DEST_ACTION');
if(get_option('home')!=='https://tours.dev.mad4b.com'
 || !in_array($locale,['fr','de'],true)
 || !in_array($action,['preflight','apply','verify'],true))exit(2);
$ids=$locale==='fr'?[70002,68818]:[70007,68821];
[$post,$listing]=$ids;
if(get_post_type($post)!=='elementor_library' || get_post_type($listing)!=='jet-engine')exit(3);
$meta=(string)get_post_meta($post,'_elementor_data',true);
$data=json_decode($meta,true);
if(!is_array($data)||count($data)!==5)exit(4);
$found=0;
$walk=function(&$nodes)use(&$walk,$listing,&$found){
 foreach($nodes as &$node){
  if(($node['widgetType']??'')==='jet-listing-grid'
    && in_array((int)($node['settings']['lisitng_id']??0),[45927,$listing],true)){
      $found++;$node['settings']['lisitng_id']=(string)$listing;
  }
  if(isset($node['elements'])&&is_array($node['elements']))$walk($node['elements']);
 }
 unset($node);
};
$walk($data);
if($found!==1)exit(5);
$next=wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
if(!is_string($next))exit(6);
$mode=get_post_meta($post,'_last_translation_edit_mode',true);
if($mode!==''&&$mode!=='translation-editor')exit(7);
$out=['site'=>get_option('home'),'locale'=>$locale,'post'=>$post,'listing'=>$listing,
  'before_hash'=>hash('sha256',$meta),'needs_update'=>json_decode($meta,true)!=$data,
  'translation_mode'=>$mode,'action'=>$action];
if($action==='apply'){
 $changed=false;
 if($mode===''){$changed=(bool)update_post_meta($post,'_last_translation_edit_mode','translation-editor');}
 if(json_decode($meta,true)!=$data){
  if(!update_post_meta($post,'_elementor_data',wp_slash($next),$meta))exit(8);
  $changed=true;
 }
 if($changed)delete_post_meta($post,'_elementor_element_cache');
}
$read=json_decode((string)get_post_meta($post,'_elementor_data',true),true);
if($action==='verify' || $action==='apply'){
 if($read!=$data || get_post_meta($post,'_last_translation_edit_mode',true)!=='translation-editor')exit(9);
 $out['verified']=true;
}
echo wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";

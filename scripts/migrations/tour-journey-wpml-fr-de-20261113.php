<?php
/**
 * WPML localization gap repair for the existing native Tour Related Destinations template.
 * Scoped to Tours.dev, EN template 48366, missing FR/DE template variants.
 * Use TOUR_DEST_LOCALE=fr|de and TOUR_DEST_ACTION=preflight|apply|verify.
 */
defined('ABSPATH') || exit;
$site=(string)getenv('TOUR_DEST_SITE');
$lang=(string)getenv('TOUR_DEST_LOCALE');
$action=(string)getenv('TOUR_DEST_ACTION');
if($site!=='https://tours.dev.mad4b.com'||get_option('home')!==$site
 || !in_array($lang,['fr','de'],true)
 || !in_array($action,['preflight','apply','verify'],true)){fwrite(STDERR,"SCOPE_CONFLICT\n");exit(2);}
global $wpdb;
$base=48366;
$doc=$lang==='fr'?35119:35121;
$template=get_post($base);
if(!$template||$template->post_type!=='elementor_library'||$template->post_status!=='publish'
 ||get_post_type($doc)!=='elementor_library'){fwrite(STDERR,"SOURCE_TEMPLATE_INVALID\n");exit(3);}
$trid=(int)$wpdb->get_var($wpdb->prepare(
 "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type='post_elementor_library' AND language_code='en'",$base));
if($trid<=0){fwrite(STDERR,"BASE_TRID_MISSING\n");exit(4);}
$newExisting=(int)$wpdb->get_var($wpdb->prepare(
 "SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE trid=%d AND element_type='post_elementor_library' AND language_code=%s",
 $trid,$lang));
$cityId=(int)apply_filters('wpml_object_id',45927,'jet-engine',false,$lang);
if(!$cityId || get_post_type($cityId)!=='jet-engine' || get_post_status($cityId)!=='publish'){fwrite(STDERR,"LOCAL_LISTING_NOT_FOUND\n");exit(5);}
$raw=(string)get_post_meta($base,'_elementor_data',true);
$nodes=json_decode($raw,true);
if(!is_array($nodes)||count($nodes)!==5){fwrite(STDERR,"BASE_JSON_INVALID\n");exit(6);}
$cityFound=0;$walk=function(&$n)use(&$walk,&$cityFound,$cityId){
 foreach($n as &$v){
  if(($v['widgetType']??'')==='jet-listing-grid' &&
      (int)($v['settings']['lisitng_id']??0)===45927){
    $cityFound++;
    $v['settings']['lisitng_id']=(string)$cityId;
  }
  if(isset($v['elements'])&&is_array($v['elements']))$walk($v['elements']);
 }
 unset($v);
};
$walk($nodes);
if($cityFound!==1){fwrite(STDERR,"CITY_GRID_MISMATCH {$cityFound}\n");exit(7);}
$translatedJSON=wp_json_encode($nodes,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
if(!is_string($translatedJSON)||!is_array(json_decode($translatedJSON,true)))exit(8);
$docRow=$wpdb->get_row($wpdb->prepare(
 "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_elementor_data' LIMIT 1",$doc),ARRAY_A);
if(!$docRow)exit(9);
$docNodes=json_decode((string)$docRow['meta_value'],true);
if(!is_array($docNodes))exit(10);
$found=0;$review=[];
$update=function(&$children)use(&$update,&$found,&$review,$newExisting){
 foreach($children as &$n){
  if(($n['widgetType']??'')==='template'
     && strpos(' '.trim((string)($n['settings']['css_classes']??'')).' ',' tour-journey-related-editor ')!==false){
    $found++;
    $ref=(int)($n['settings']['template_id']??0);
    if(!in_array($ref,array_filter([48366,$newExisting]),true))$review[]='TEMPLATE_ID_CONFLICT';
    $marker=(string)($n['settings']['_css_classes']??'');
    if($marker!=='' && $marker!=='mad4b-wpml-needs-translation')$review[]='UNKNOWN_MARKER';
    $n['settings']['_css_classes']='';
  }
  if(isset($n['elements'])&&is_array($n['elements']))$update($n['elements']);
 }
 unset($n);
};
$update($docNodes);
if($found!==1||$review){fwrite(STDERR,"MAIN_TEMPLATE_CONFLICT ".wp_json_encode(['found'=>$found,'issues'=>$review])."\n");exit(11);}
$summary=['site'=>$site,'locale'=>$lang,'action'=>$action,'base'=>$base,'target_document'=>$doc,
    'wpml_trid'=>$trid,'native_city_listing'=>$cityId,'existing_translation_id'=>$newExisting,
    'source_data_hash'=>hash('sha256',$raw),'localized_data_hash'=>hash('sha256',$translatedJSON),
    'parent_data_hash'=>hash('sha256',(string)$docRow['meta_value'])];
if($action==='preflight'){
 echo wp_json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";return;
}
if($action==='verify'&&$newExisting<=0){fwrite(STDERR,"NO_TRANSLATED_TEMPLATE\n");exit(12);}
$newId=$newExisting;
if($action==='apply' && !$newExisting){
 // Create a real WPML translation of the native Elementor library template.
 $title=$lang==='fr'?'Destinations associées à la visite':'Reiseziele dieser Tour';
 $newId=wp_insert_post([
    'post_type'=>'elementor_library',
    'post_status'=>'draft',
    'post_title'=>'Tour Related Destinations Listing — '.$lang,
    'post_name'=>'tour-related-destinations-listing-'.$lang,
    'post_content'=>$title,
    'post_author'=>(int)$template->post_author,
 ],true);
 if(is_wp_error($newId)||!$newId){fwrite(STDERR,"TRANSLATION_INSERT_FAILED: ".(is_wp_error($newId)?$newId->get_error_message():"empty result")."\n");exit(13);}
 foreach(['_elementor_edit_mode','_elementor_template_type','_elementor_version','_elementor_pro_version','_elementor_page_settings'] as $key){
   $value=get_post_meta($base,$key,true);
   if($value!==''&&$value!==null)update_post_meta($newId,$key,wp_slash($value));
 }
 update_post_meta($newId,'_elementor_data',wp_slash($translatedJSON));
 do_action('wpml_set_element_language_details',[
    'element_id'=>$newId,
    'element_type'=>'post_elementor_library',
    'trid'=>$trid,
    'language_code'=>$lang,
    'source_language_code'=>'en',
 ]);
 if((int)$wpdb->get_var($wpdb->prepare(
    "SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE trid=%d AND language_code=%s AND element_type='post_elementor_library'",
    $trid,$lang))!==(int)$newId){fwrite(STDERR,"WPML_LINK_READBACK_FAILED\n");exit(14);}
 $pub=wp_update_post(['ID'=>$newId,'post_status'=>'publish'],true);
 if(is_wp_error($pub)||get_post_status($newId)!=='publish'){fwrite(STDERR,"PUBLISH_FAILED\n");exit(15);}
}
if(!$newId)exit(16);
if($action==='apply'){
 $documentChanges=0;
 $updateID=function(&$children)use(&$updateID,$newId,&$documentChanges){
   foreach($children as &$n){
     if(($n['widgetType']??'')==='template'
       && strpos(' '.trim((string)($n['settings']['css_classes']??'')).' ',' tour-journey-related-editor ')!==false){
        if((int)($n['settings']['template_id']??0)!==$newId){
          $n['settings']['template_id']=(string)$newId;
          $documentChanges++;
        }
        if((string)($n['settings']['_css_classes']??'')!==''){
          $n['settings']['_css_classes']='';
          $documentChanges++;
        }
     }
     if(isset($n['elements'])&&is_array($n['elements']))$updateID($n['elements']);
   }unset($n);
 };
 $updateID($docNodes);
 $newDocJson=wp_json_encode($docNodes,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 $before=(string)$docRow['meta_value'];
 if($documentChanges>0){
   $result=$wpdb->query($wpdb->prepare(
     "UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND meta_value=%s",
     $newDocJson,(int)$docRow['meta_id'],$before));
   if($result!==1){fwrite(STDERR,"DOCUMENT_CAS_CONFLICT\n");exit(17);}
   wp_cache_delete($doc,'post_meta');
   delete_post_meta($doc,'_elementor_element_cache');
 }
}
$read=(string)get_post_meta($newId,'_elementor_data',true);
$child=(string)get_post_meta($doc,'_elementor_data',true);
$loc=json_decode($read,true);
if(!is_array($loc)||count($loc)!==5||substr_count($child,'"template_id":"'.$newId.'"')!==1){
  fwrite(STDERR,"FINAL_READBACK_FAILED\n");exit(18);
}
$gridIds=[];
$walkGrids=function($items)use(&$walkGrids,&$gridIds){
  foreach($items as $item){
    if(($item['widgetType']??'')==='jet-listing-grid')$gridIds[]=(int)($item['settings']['lisitng_id']??0);
    $walkGrids($item['elements']??[]);
  }
};
$walkGrids($loc);
if(count($gridIds)!==5||!in_array($cityId,$gridIds,true)
 ||get_post_meta($newId,'_last_translation_edit_mode',true)!=='translation-editor'){
 fwrite(STDERR,"LOCAL_LISTING_READBACK_FAILED\n");exit(19);
}
$summary['created_id']=$newId;
$summary['post_status']=get_post_status($newId);
$summary['document_link_verified']=true;
$summary['localized_listing_verified']=true;
echo wp_json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";

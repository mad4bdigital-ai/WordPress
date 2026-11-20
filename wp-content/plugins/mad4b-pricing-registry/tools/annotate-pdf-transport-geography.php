<?php
/**
 * Exact-source PDF geography annotation, NOT guessed pickup/dropoff points.
 * Reversible 51×active languages, company self Drafts only.
 */
defined('ABSPATH') && defined('WP_CLI') && WP_CLI || exit(2);
$site=rtrim(home_url('/'),'/');
$isWayfa=$site==='https://wayfatours.com';
if($site!=='https://tours.dev.mad4b.com'&&!$isWayfa)
    WP_CLI::error('site_not_allowed');
if($isWayfa&&!(defined('MAD4B_PDF_GEO_WAYFA_APPROVED') &&
    MAD4B_PDF_GEO_WAYFA_APPROVED===true))
    WP_CLI::error('wayfa_approval_required');
$apply=defined('MAD4B_PDF_GEO_APPLY')&&MAD4B_PDF_GEO_APPLY===true;
$rollback=defined('MAD4B_PDF_GEO_ROLLBACK')&&MAD4B_PDF_GEO_ROLLBACK===true;
$source='MAD4B_Transport_Tour_Rate_Routing_V1';
if(!class_exists($source)||!($fixture=$source::source()))
    WP_CLI::error('source_route_contract_missing');
$keys=[
 '_mad4b_pdf_route_origin_slug_v1',
 '_mad4b_pdf_route_destination_slugs_v1',
 '_mad4b_pdf_route_shape_v1',
 '_mad4b_pdf_route_origin_term_id_v1',
 '_mad4b_pdf_route_destination_term_id_v1',
 '_mad4b_pdf_route_pickup_point_verified_v1',
 '_mad4b_pdf_route_dropoff_point_verified_v1',
 '_mad4b_pdf_route_review_required_v1',
 '_mad4b_pdf_route_schema_v1'
];
$active=array_keys((array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]));
sort($active);
$wanted=$isWayfa?['en','es']:['ar','de','en','es','fr','it'];
if($active!==$wanted) WP_CLI::error('wpml_active_language_contract_changed');
$posts=get_posts([
    'post_type'=>'transportations','post_status'=>'draft',
    'posts_per_page'=>600,'fields'=>'ids','suppress_filters'=>true,
    'meta_key'=>'_mad4b_transfer_pdf_2026_sha256',
    'meta_value'=>$source::PDF
]);
if(count($posts)!==51*count($wanted))
    WP_CLI::error('source_post_matrix_incomplete');
$backupKey='mad4b_pdf_transport_route_annotations_backup_20261119_v1';
$backup=get_option($backupKey,false);
$before=[];$plans=[];$groupCount=[];
foreach($posts as $id){
 $id=(int)$id;
 $sourceKey=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_source_key',true);
 $lang=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_editorial_language',true);
 $detail=apply_filters('wpml_post_language_details',null,$id);
 $trid=(int)apply_filters('wpml_element_trid',null,$id,'post_transportations');
 if(!isset($fixture['route']['entries'][$sourceKey]) ||
    !in_array($lang,$active,true) || ($detail['language_code']??null)!==$lang ||
    !$trid || get_post_status($id)!=='draft' ||
    get_post_meta($id,'trans_use_in_tour_rate_generation',true)!=='1' ||
    get_post_meta($id,'_mad4b_transfer_pdf_2026_provider_id',true)!=='self' ||
    get_post_meta($id,'_mad4b_transfer_pdf_2026_source_trust',true)!=='trusted_original_pdf' ||
    get_post_meta($id,'_mad4b_transfer_pdf_2026_not_sellable',true)!=1)
    WP_CLI::error('source_post_identity_invalid_'.$id);
 $groupCount[$trid][$lang][]=1;
 $entry=$fixture['route']['entries'][$sourceKey];
 $origin=get_term_by('slug',$entry['origin_slug'],'destinations');
 if(!($origin instanceof WP_Term))WP_CLI::error('source_origin_taxonomy_missing_'.$sourceKey);
 $dest=0;
 if(count($entry['dest_slugs'])===1 &&
    in_array($entry['route_shape'],['one_way_city','round_trip'],true)){
    $term=get_term_by('slug',$entry['dest_slugs'][0],'destinations');
    if(!($term instanceof WP_Term))
        WP_CLI::error('confirmed_destination_taxonomy_missing_'.$sourceKey);
    $dest=(int)$term->term_id;
 }
 $new=[
  $keys[0]=>$entry['origin_slug'],
  $keys[1]=>$entry['dest_slugs'],
  $keys[2]=>$entry['route_shape'],
  $keys[3]=>(int)$origin->term_id,
  $keys[4]=>$dest,
  $keys[5]=>'0',
  $keys[6]=>'0',
  $keys[7]=>'1',
  $keys[8]=>'mad4b.transport-pdf-route-evidence.v1'
 ];
 $old=[];
 foreach($keys as $key)
     $old[$key]=[
       'present'=>metadata_exists('post',$id,$key),
       'value'=>get_post_meta($id,$key,true)
     ];
 $before[$id]=['source_key'=>$sourceKey,'language'=>$lang,'trid'=>$trid,'old'=>$old];
 $plans[$id]=$new;
}
if(count($groupCount)!==51)
    WP_CLI::error('translation_groups_incomplete');
foreach($groupCount as $trid=>$langs)
 if(count($langs)!==count($active)||array_diff(array_keys($langs),$active))
     WP_CLI::error('translation_group_missing_language_'.$trid);
$wpml=get_option('icl_sitepress_settings',[]);
if(!is_array($wpml)) WP_CLI::error('wpml_settings_unavailable');
$priorCopy=[];
foreach($keys as $key)$priorCopy[$key]=$wpml['translation-management']['custom_fields_translation'][$key]??null;
if($rollback){
 if(!is_array($backup)||count($backup['original']??[])!==count($before))
    WP_CLI::error('rollback_backup_missing');
 foreach($backup['original'] as $id=>$record){
    if(!isset($before[$id])||
       $record['source_key']!==$before[$id]['source_key']||
       $record['language']!==$before[$id]['language']||
       $record['trid']!==$before[$id]['trid'])
        WP_CLI::error('rollback_identity_conflict_'.$id);
 }
 $restored=0;
 foreach($backup['original'] as $id=>$record)
  foreach($record['old'] as $key=>$v){
    if($v['present'])update_post_meta($id,$key,$v['value']);
    else delete_post_meta($id,$key);
    ++$restored;
  }
 foreach($backup['wpml_copy_before'] as $key=>$v){
     if($v===null)
       unset($wpml['translation-management']['custom_fields_translation'][$key]);
     else $wpml['translation-management']['custom_fields_translation'][$key]=$v;
 }
 update_option('icl_sitepress_settings',$wpml);
 WP_CLI::log(wp_json_encode(['status'=>'rolled_back','meta_fields_restored'=>$restored]));
 return;
}
if(!$apply){
 $explicit=0;foreach($plans as $plan)if($plan[$keys[4]]>0)$explicit++;
 WP_CLI::log(wp_json_encode(['status'=>'dry_run','site'=>$site,
    'source_posts'=>count($posts),'confirmed_city_destination_posts'=>$explicit,
    'source_groups'=>count($groupCount),
    'source_pickup_points_invented'=>false,
    'existing_native_pickup_dropoff_untouched'=>true,
    'commercial_price_changes'=>0]));
 return;
}
if($backup===false){
 if(!add_option($backupKey,[
  'created_at'=>gmdate('c'),'source_sha'=>$source::PDF,
  'original'=>$before,'wpml_copy_before'=>$priorCopy
 ],'',false))WP_CLI::error('backup_write_failed');
}elseif(count($backup['original']??[])!==count($before))
    WP_CLI::error('backup_contract_conflict');
$admin=get_user_by('id',1);
if(!$admin||!user_can($admin,'edit_others_posts'))
    WP_CLI::error('admin_authority_unavailable');
wp_set_current_user($admin->ID);
$matches=static function($actual,$expected):bool{
    if(is_array($expected))return is_array($actual)&&$actual===$expected;
    return is_scalar($actual)&&(string)$actual===(string)$expected;
};
$changed=0;
foreach($plans as $id=>$plan)
 foreach($plan as $key=>$value){
    if(!$matches(get_post_meta($id,$key,true),$value)){
        update_post_meta($id,$key,$value);
        ++$changed;
    }
 }
foreach($keys as $key)
 $wpml['translation-management']['custom_fields_translation'][$key]=1;
update_option('icl_sitepress_settings',$wpml);
$checkWpml=get_option('icl_sitepress_settings',[]);
foreach($keys as $key)
 if(($checkWpml['translation-management']['custom_fields_translation'][$key]??null)!==1)
    WP_CLI::error('wpml_copy_readback_failed_'.$key);
foreach($plans as $id=>$plan){
 foreach($plan as $key=>$value)
    if(!$matches(get_post_meta($id,$key,true),$value))
       WP_CLI::error('route_annotation_readback_failed_'.$id.'_'.$key);
 if(get_post_status($id)!=='draft')
     WP_CLI::error('source_published_unexpectedly_'.$id);
}
WP_CLI::log(wp_json_encode(['status'=>'route_evidence_annotated',
 'site'=>$site,'source_posts'=>count($posts),'source_groups'=>51,
 'meta_cells_changed'=>$changed,
 'exact_points_verified'=>0,'review_required'=>count($posts),
 'native_pickup_dropoff_fields_changed'=>0,
 'rate_post_changes'=>0,'checkout_authorized'=>false,
 'backup'=>$backupKey]));

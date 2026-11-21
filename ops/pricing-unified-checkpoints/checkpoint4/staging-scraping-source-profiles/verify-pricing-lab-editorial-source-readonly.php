<?php
/**
 * Reproducible date-scoped original CP4 staging source readback.
 * Run via WP-CLI as a genuine admin with commerce_manage_pricing.
 * No source import, Rate creation, supplier approval or wp_update_post.
 */
if(!defined('WP_CLI')||!WP_CLI||!current_user_can('commerce_manage_pricing'))
  throw new RuntimeException('ORIGINAL_PRICING_REVIEW_PERMISSION_REQUIRED');
$site=home_url();
if(!in_array($site,['https://tours.dev.mad4b.com','https://wayfatours.com'],true))
  throw new RuntimeException('ORIGINAL_SOURCE_SITE_NOT_SUPPORTED');
$checks=[[60437,2354,'hotel-aswan-02'],[60629,2353,'resort-sharm-03']];
$out=[];
foreach($checks as [$tour,$tier,$key]){
 $report=MAD4B_Package_Pricing_Lab_Readiness_V1::inspect($tour,$tier);
 if(is_wp_error($report))throw new RuntimeException('ORIGINAL_EDITORIAL_LAB_INSPECT_FAILED_'.$tour);
 $matched=[];
 foreach((array)($report['stages']??[]) as $stage)
    if(($stage['key']??'')===$key)$matched[]=$stage;
 if(count($matched)!==1)throw new RuntimeException('CANONICAL_STAGE_NOT_UNIQUE_'.$tour);
 $stage=$matched[0];
 $review=(array)($stage['editorial_source_review']??[]);
 $drafts=(array)($review['draft_property_alternatives']??[]);
 $units=(array)($review['draft_units']??[]);
 if($site==='https://tours.dev.mad4b.com'){
   if($tour===60437&&(count($drafts)!==2||
      count((array)$drafts[0]['draft_units'])!==4||
      count((array)$drafts[1]['draft_units'])!==5))
      throw new RuntimeException('STAGING_ASWAN_DRAFT_SOURCE_REVIEW_NOT_SHOWN');
   if($tour===60629&&(count($units)!==6||
      empty($review['brand_review'])||empty($review['content_review'])||
      ($review['content_review_state']??'')!=='resolved_source_only'))
      throw new RuntimeException('STAGING_MARINA_SOURCE_GEO_RESOLUTION_NOT_SHOWN');
   if(($review['content_review_state']??'')!=='resolved_source_only')
      throw new RuntimeException('STAGING_ASWAN_SOURCE_GEO_RESOLUTION_NOT_SHOWN');
 }elseif($drafts||$units)
   throw new RuntimeException('CROSS_SITE_DRAFT_SOURCE_LEAK');
 if(!empty($stage['selected_property_id'])||
    !empty($stage['selected_unit_count'])||
    ($stage['supplier_receipt_approved']??true)!==false||
    !empty($stage['native_rate_stage_written']))
   throw new RuntimeException('READ_ONLY_SOURCE_REVIEW_FALSE_COMMERCIAL');
 $out[]=['site'=>$site,'tour'=>$tour,'stage'=>$key,
   'draft_property_count'=>count($drafts),'draft_units_count'=>count($units),
   'commercially_assigned'=>false,'read_only'=>true];
}
echo 'CP4_LAB_DRAFT_SOURCE_REVIEW_PASS '.wp_json_encode($out,JSON_UNESCAPED_SLASHES).PHP_EOL;

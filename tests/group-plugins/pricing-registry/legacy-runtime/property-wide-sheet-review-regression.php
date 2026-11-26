<?php
/** Read-only regression for native, supplier-sheet Property-scoped Draft Rates. */
defined('ABSPATH') || exit;
$checks=[];$chk=static function($key,$ok)use(&$checks){$checks[$key]=(bool)$ok;};
$site=parse_url(home_url('/'),PHP_URL_HOST);
$expected=match($site){'tours.dev.mad4b.com'=>47,'wayfatours.com'=>56,default=>0};
$rows=get_posts(['post_type'=>'property-rates','post_status'=>['publish','draft','pending','private'],
 'posts_per_page'=>180,'fields'=>'ids','suppress_filters'=>true,
 'meta_query'=>[['key'=>'_mad4b_supplier_sheet_import_key_v1','compare'=>'EXISTS']]]);
$chk('native_stay_pricing_active',class_exists('MAD4B_Stay_Layered_Pricing_V1',false));
$chk('native_review_and_source_panel',class_exists('MAD4B_Stay_Layers_Admin_V1',false)&&
 class_exists('MAD4B_Stay_Pricing_Controls_Panel_V1',false));
$chk('known_import_site_has_expected_rows',$expected===0 || count($rows)===$expected);
$chk('imported_records_bounded',count($rows)<=180);
$chk('original_source_priority',MAD4B_Stay_Layered_Pricing_V1::PRIORITY===
 ['unit_rate','property_rate','unit_direct','property_direct']);
$chk('cost_priority_independent',MAD4B_Stay_Layered_Pricing_V1::COST_PRIORITY===
 ['unit_rate','property_rate']);
$sourceKeys=[];$properties=[];$actual=0;$annual=0;
foreach($rows as $id){
 $id=(int)$id;
 $source=get_post_meta($id,'_mad4b_supplier_sheet_proposal_v1',true);
 $key=(string)get_post_meta($id,'_mad4b_supplier_sheet_import_key_v1',true);
 $period=(string)get_post_meta($id,'_mad4b_supplier_sheet_season_slug_v1',true);
 $window=MAD4B_Stay_Layered_Pricing_V1::effectiveRateWindow($id);
 $properties[(int)get_post_meta($id,'prop_related_property_id',true)]=true;
 $sourceKeys[$key]=true;
 if(is_array($source)&&$source['source_cell']??null)$actual++;
 if(!empty($window['valid']))$annual++;
 $valid=$period==='winter' || $period==='summer';
 if(!$valid || get_post_status($id)!=='draft' ||
    !preg_match('/^[a-f0-9]{64}$/D',$key)||
    get_post_type($id)!=='property-rates' ||
    (int)get_post_meta($id,'prop_related_unit_id',true)!==0 ||
    get_post_meta($id,'mad4b_rate_scope_v1',true)!=='property'||
    get_post_meta($id,'mad4b_rate_price_kind_v1',true)!=='cost'||
    get_post_meta($id,'prop_price_unit',true)!=='per_person'||
    get_post_meta($id,'currency',true)!=='USD'||
    get_post_meta($id,'stop_sale',true)!=='1'||
    get_post_meta($id,'valid_from',true)!==''||
    get_post_meta($id,'valid_to',true)!==''||
    !get_post_meta($id,'_shared_generation_supplier_proposal',true)||
    get_post_meta($id,'_mad4b_supplier_sheet_quote_unapproved_v1',true)!=='1'||
    !is_array($source)||
    ($source['supplier_contract_verified']??true)!==false||
    ($source['cost_receipt_approved']??true)!==false||
    ($source['nightly_basis_verified']??true)!==false||
    empty($source['source_cell']) ||
    empty($window['valid']) ||
    $window['source']!=='season_term_meta')
   $checks['all_rates_are_source_anchored_drafts']=false;
}
if(!isset($checks['all_rates_are_source_anchored_drafts']))
 $chk('all_rates_are_source_anchored_drafts',true);
$chk('all_cells_provenance_present',$actual===count($rows));
$chk('every_rate_resolves_native_calendar',$annual===count($rows));
$chk('all_import_identity_keys_unique',count($sourceKeys)===count($rows));
$chk('multiple_verified_property_entities',$expected===0||count($properties)>=10);
if($rows){
 $id=(int)$rows[0];
 $block=MAD4B_Stay_Layers_Admin_V1::prevent_unverified_sheet_publication(
  ['post_type'=>'property-rates','post_status'=>'publish'],['ID'=>$id]);
 $chk('draft_rate_cannot_accidentally_publish',$block['post_status']==='draft');
 $allowed=MAD4B_Stay_Layers_Admin_V1::prevent_unverified_sheet_publication(
  ['post_type'=>'properties','post_status'=>'publish'],['ID'=>$id]);
 $chk('unrelated_content_publication_untouched',$allowed['post_status']==='publish');
 $orig=get_post_meta($id,'prop_related_property_id',true);
 $admin=MAD4B_Stay_Pricing_Controls_Panel_V1::rates(['property_id'=>$orig]);
 $visible=!is_wp_error($admin)&&array_filter($admin['items'],
  static fn($r)=>$r['id']===$id && $r['source_cell']!=='' &&
      $r['season_window_source']==='season_term_meta' &&
      $r['supplier_quote_pending_review']===true);
 $chk('authenticated_native_admin_shows_source_and_term_window',!empty($visible));
 $resolve=MAD4B_Stay_Layered_Pricing_V1::preview([
  'property_id'=>(int)$orig,'unit_id'=>0,'checkin'=>'2026-12-05',
  'nights'=>2,'adults'=>2,'children'=>0,'board_type'=>'bed-breakfast']);
 $chk('no_vendor_cost_activated_by_draft',
  is_array($resolve)&&$resolve['cost_reference']===null&&
  $resolve['checkout_ready']===false);
 // Transient metadata filter, zero writes: explicit override beats inherited
 // Season, and a partial override must NOT silently inherit.
 $overrides=['valid_from'=>'2026-12-01','valid_to'=>'2026-12-18'];
 $metaFilter=static function($v,$obj,$key,$single)use(&$overrides,$id){
   if((int)$obj!==$id||!array_key_exists($key,$overrides))return $v;
   return $single?$overrides[$key]:[$overrides[$key]];
 };
 add_filter('get_post_metadata',$metaFilter,99,4);
 try {
  $explicit=MAD4B_Stay_Layered_Pricing_V1::effectiveRateWindow($id);
  $chk('explicit_dates_override_term_window',
   !empty($explicit['valid'])&&$explicit['source']==='explicit'&&
   $explicit['start']==='2026-12-01');
  $overrides['valid_to']='';
  $partial=MAD4B_Stay_Layered_Pricing_V1::effectiveRateWindow($id);
  $chk('partial_explicit_dates_fail_closed',
   empty($partial['valid'])&&$partial['reason']==='partial_or_invalid_custom_dates');
 } finally {remove_filter('get_post_metadata',$metaFilter,99);}
}
$review=MAD4B_Package_Stage_Rate_Guard_V1::review(60623,2354,'2026-12-05','2026-12-19');
$chk('native_package_guard_still_blocking',empty($review['ready'])&&!empty($review['blockers']));
$fail=array_keys(array_filter($checks,static fn($v)=>!$v));
echo wp_json_encode([
 'contract'=>'mad4b.property-wide-sheet-review-regression.v1','site'=>home_url(),
 'rates_checked'=>count($rows),'property_count'=>count($properties),
 'passed'=>count($checks)-count($fail),'total'=>count($checks),'failures'=>$fail,
 'rates_published'=>0,'cost_receipts_approved'=>0,'checkout_authorized'=>false
],JSON_UNESCAPED_SLASHES)."\n";
if($fail&&class_exists('WP_CLI'))WP_CLI::halt(1);


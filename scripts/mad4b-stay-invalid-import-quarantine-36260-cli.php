<?php
/**
 * MAD4B one-off editorial quarantine of imported corrupt Property "value".
 *
 * Scoped to Wayfatours site IDs and linked Units. No hard-delete, no pricing
 * mutation, no stock mutation. Requires owner-attested explicit apply mode.
 * Records exact prior statuses for reversible, guarded rollback.
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)
    throw new RuntimeException('WP-CLI only');
const MAD4B_BAD_PROPERTY=36260;
const MAD4B_BAD_UNIT_IDS=[37553,37554,37555];
const MAD4B_BAD_PROPERTY_RECEIPT='_mad4b_stay_imported_property_quarantine_v1';
$mode=getenv('MAD4B_STAY_IDENTITY_QUARANTINE_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply','rollback-preview','rollback'],true))
    WP_CLI::error('invalid_mode');
if(in_array($mode,['apply','rollback'],true)
    &&getenv('MAD4B_STAY_IDENTITY_QUARANTINE_OWNER_APPROVED')!=='1')
    WP_CLI::error('owner_attestation_required');

global $wpdb;
$pid=MAD4B_BAD_PROPERTY;
$ids=array_merge([$pid],MAD4B_BAD_UNIT_IDS);
$property=get_post($pid);
if(!$property||$property->post_type!=='properties'
    ||$property->post_name!=='value'
    ||$property->post_title!=='#VALUE!')
    WP_CLI::error('source_identity_does_not_match_corrupt_import');
$description=wp_strip_all_tags((string)get_post_meta($pid,'prop__description',true));
if(strpos($description,'Hotel Stanford')===false
    ||strpos($description,'New York')===false)
    WP_CLI::error('corrupt_import_description_not_confirmed');
$native=(array)$wpdb->get_col($wpdb->prepare(
   "SELECT child_object_id FROM {$wpdb->prefix}jet_rel_default
    WHERE rel_id=%d AND parent_object_id=%d ORDER BY child_object_id",
    223,$pid));
$native=array_map('intval',$native);
$expected=MAD4B_BAD_UNIT_IDS;
sort($native);sort($expected);
if($native!==$expected)
    WP_CLI::error('native_jetengine_relation_changed');
foreach(MAD4B_BAD_UNIT_IDS as $id){
    $p=get_post($id);
    if(!$p||$p->post_type!=='units')
        WP_CLI::error('linked_unit_changed_'.$id);
}
$existing=get_post_meta($pid,MAD4B_BAD_PROPERTY_RECEIPT,true);
$isRollback=in_array($mode,['rollback','rollback-preview'],true);
if($isRollback){
    if(!is_array($existing)
        ||($existing['schema']??'')!=='mad4b.stay-import-identity-quarantine.v1'
        ||!is_array($existing['original_post_status']??null))
        WP_CLI::error('no_quarantine_receipt');
    $desired=(array)$existing['original_post_status'];
    foreach($ids as $id){
        if(get_post_status($id)!=='draft'
           ||!in_array((string)($desired[$id]??''),['publish','draft'],true))
           WP_CLI::error('manual_change_after_quarantine_'.$id);
    }
    if($mode==='rollback'){
        foreach($ids as $id){
            $ok=wp_update_post(['ID'=>$id,'post_status'=>(string)$desired[$id]],true);
            if(is_wp_error($ok))WP_CLI::error('rollback_failed_'.$id);
        }
        delete_post_meta($pid,MAD4B_BAD_PROPERTY_RECEIPT);
    }
    WP_CLI::line(wp_json_encode(['mode'=>$mode,'ids'=>$ids,
       'status'=>$mode==='rollback'?'restored':'rollback_eligible',
       'original_status'=>$desired]));
    return;
}
if($existing)WP_CLI::error('already_quarantined');
foreach($ids as $id){
    if(get_post_status($id)!=='publish')WP_CLI::error('not_published_before_apply_'.$id);
}
$publishedRates=(int)(wp_count_posts('property-rates')->publish??0);
if($publishedRates!==0)WP_CLI::error('published_property_rates_exist');
$snapshot=[];
foreach($ids as $id)$snapshot[$id]=get_post_status($id);
if($mode==='apply'){
    $lock='mad4b_stay_invalid_property_quarantine_lock_36260';
    if(!add_option($lock,(string)time(),'','no'))
        WP_CLI::error('parallel_quarantine_operation_detected');
    try {
        $receipt=[
          'schema'=>'mad4b.stay-import-identity-quarantine.v1',
          'property_id'=>$pid,
          'linked_unit_ids'=>MAD4B_BAD_UNIT_IDS,
          'original_post_status'=>$snapshot,
          'original_property_title'=>$property->post_title,
          'reason'=>'invalid_property_name_source_description_refers_to_hotel_stanford_new_york',
          'sales_mode'=>'do_not_offer_unverified_import',
          'recorded_at'=>gmdate('c'),
          'owner_approved'=>true,
          'prices_media_and_unit_metadata_preserved'=>true
        ];
        if(!add_post_meta($pid,MAD4B_BAD_PROPERTY_RECEIPT,$receipt,true))
            WP_CLI::error('could_not_save_original_state');
        // Draft child Units first to avoid any interim sellable child page.
        foreach(array_merge(MAD4B_BAD_UNIT_IDS,[$pid]) as $id){
            $ok=wp_update_post(['ID'=>$id,'post_status'=>'draft'],true);
            if(is_wp_error($ok)||get_post_status($id)!=='draft')
                WP_CLI::error('quarantine_update_failed_'.$id);
        }
    }finally {
        delete_option($lock);
    }
}
WP_CLI::line(wp_json_encode([
  'mode'=>$mode,'property_id'=>$pid,'linked_units'=>MAD4B_BAD_UNIT_IDS,
  'status'=>$mode==='apply'?'quarantined_draft':'eligible',
  'original_status'=>$snapshot,'property_title_unchanged'=>get_post_field('post_title',$pid),
  'all_draft'=>$mode==='apply'?count(array_filter($ids,static fn($id)=>get_post_status($id)==='draft'))===4:null
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

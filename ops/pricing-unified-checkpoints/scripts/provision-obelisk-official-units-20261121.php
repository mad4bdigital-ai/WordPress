<?php
// Obelisk Nile Hotel: primary-source editorial Unit catalog, NO RATE.
if (!defined('ABSPATH') || !defined('WP_CLI')) return;
$id=36181;
$expected='obelisk-nile-hotel';
$source='https://obeliskhotels.com/rooms/';
$place='https://obeliskhotels.com/contact/';
$address='Corniche El Nile St, Aswan, Egypt';
$categories=[
    'Romantic Terraced Nile Suite'=>'suite',
    'Terraced Nile view room'=>'room',
    'Obelisk Nile Shore Room'=>'room',
    'Standard Garden View Room'=>'room',
];
$fail=static function($code){WP_CLI::error('OBELISK_SOURCE_WRITE_STOP '.$code);};
$post=get_post($id);
if (!$post || $post->post_type!=='properties' ||
    $post->post_name!==$expected || $post->post_status!=='publish')$fail('property_identity_or_status');
if (!current_user_can('edit_post',$id) || !current_user_can('edit_posts'))$fail('author_permission');
$terms=wp_get_object_terms($id,'destinations',['fields'=>'slugs']);
if(is_wp_error($terms)||!in_array('aswan-gov',$terms,true)||
    in_array('abu-simbel-temples',$terms,true))$fail('destination_source_inconsistent');
$city=get_term_by('slug','aswan-city','destinations');
if(!$city instanceof WP_Term)$fail('exact_city_term_unavailable');
$originalAddress=get_post_meta($id,'property_map',true);
if($originalAddress!=='' && $originalAddress!==$address)$fail('existing_map_conflict');
$originalEvidence=get_post_meta($id,'_mad4b_property_location_evidence_v1',true);
if($originalEvidence && (!is_array($originalEvidence) ||
   ($originalEvidence['address']??'')!==$address))$fail('existing_location_evidence_conflict');
$native=jet_engine()->relations->get_active_relations()[223]??null;
if(!$native || $native->get_args('parent_object')!=='posts::properties' ||
    $native->get_args('child_object')!=='posts::units')$fail('native_relation_223_contract_mismatch');
$linked=$native->get_children($id);
foreach((array)$linked as $rel){
    $child=(int)($rel['child_object_id']??0);
    $receipt=get_post_meta($child,'_mad4b_room_source_receipt_v1',true);
    if(is_array($receipt)&&($receipt['source_url']??'')===$source &&
       (int)($receipt['property_id']??0)===$id &&
       !in_array(($receipt['room_category']??''),array_keys($categories),true))
         $fail('foreign_canonical_room_category');
}
$existing=[];
foreach(get_posts(['post_type'=>'units','post_status'=>'any','posts_per_page'=>300,
       'fields'=>'ids','suppress_filters'=>true,
       'meta_key'=>'_mad4b_room_source_receipt_v1','meta_compare'=>'EXISTS']) as $unit){
    $receipt=get_post_meta($unit,'_mad4b_room_source_receipt_v1',true);
    if(!is_array($receipt)||($receipt['source_url']??'')!==$source ||
        (int)($receipt['property_id']??0)!==$id)continue;
    $label=(string)($receipt['room_category']??'');
    if(isset($existing[$label]))$fail('duplicate_room_receipt_'.$label);
    $existing[$label]=(int)$unit;
}
$created=[];
$previousTerms=wp_get_object_terms($id,'destinations',['fields'=>'ids']);
if(is_wp_error($previousTerms))$fail('terms_unreadable');
$originalMap=get_post_meta($id,'property_map',true);
$preview=[
    'site'=>home_url(),'property'=>$id,'title'=>$post->post_title,
    'old_map'=>$originalMap,'old_city_term'=>in_array((int)$city->term_id,
                array_map('intval',$previousTerms),true),
    'original_destination_terms'=>array_map('intval',$previousTerms),
    'categories'=>array_keys($categories),'existing'=>$existing,
    'official_source'=>$source,'official_address_source'=>$place
];
if(!empty($GLOBALS['mad4b_obelisk_preflight'])){
    WP_CLI::line(wp_json_encode(['preflight'=>$preview],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    return;
}
if(!empty($GLOBALS['mad4b_obelisk_require_clean']) && $existing)$fail('not_clean_initial_state');
try{
    // Source confidence covers the physical address; no fictional map GPS.
    if(!metadata_exists('post',$id,'property_map')){
        if(!add_post_meta($id,'property_map',$address,true))$fail('map_write_failed');
    }
    if(!$originalEvidence){
        $receipt=['schema'=>'mad4b.property-location-evidence.v1',
            'address'=>$address,
            'locality'=>'aswan-city',
            'official_property_source'=>$place,
            'secondary_geographic_reference'=>'https://www.google.com/travel/hotels/entity/ChoI8eTsztTsq_mCARoNL2cvMTFrOHJzeGRyOBAB',
            'reviewed_on'=>'2026-11-21',
            'precision'=>'street_address_only',
            'latitude'=>null,'longitude'=>null,
            'status'=>'source_confirmed_city_address_ungeocoded',
            'commercial_rates_approved'=>false];
        if(!add_post_meta($id,'_mad4b_property_location_evidence_v1',$receipt,true))
            $fail('evidence_write_failed');
    }
    if(!in_array((int)$city->term_id,array_map('intval',$previousTerms),true)){
        $result=wp_set_object_terms($id,[(int)$city->term_id],'destinations',true);
        if(is_wp_error($result) || !in_array((int)$city->term_id,array_map('intval',$result),true))
            $fail('aswan_city_term_write_failed');
    }
    foreach($categories as $category=>$kind){
        if(isset($existing[$category]))continue;
        $title='Obelisk Nile Hotel — '.$category;
        $unit=wp_insert_post([
            'post_type'=>'units','post_status'=>'draft','post_title'=>$title,
            'post_content'=>'Official hotel room category. Occupancy, bed configuration, real inventory and supplier cost must be verified before booking.',
            'post_author'=>get_current_user_id()
        ],true);
        if(is_wp_error($unit) || !(int)$unit || get_post_status($unit)!=='draft')
            $fail('unit_draft_creation_failed');
        $unit=(int)$unit;$created[]=$unit;
        update_post_meta($unit,'unit_kind',$kind);
        update_post_meta($unit,'unit_readiness','pending_review');
        update_post_meta($unit,'unit_available','false');
        $receipt=[
           'schema'=>'mad4b.hotel-unit-source.v1','property_id'=>$id,
           'room_category'=>$category,'source_url'=>$source,
           'source_type'=>'official-room-page-heading',
           'source_confidence'=>'official_current_page',
           'verified_on'=>'2026-11-21',
           'commercial_status'=>'pending_supplier_review',
           'occupancy_verified'=>false,'inventory_verified'=>false,
           'rates_verified'=>false,'images_verified'=>false,
           'price_and_units_intentionally_unset'=>true
        ];
        update_post_meta($unit,'_mad4b_room_source_receipt_v1',$receipt);
        $relResult=$native->update($id,$unit);
        if(!$relResult)$fail('native_relation_failed');
    }
    $out=[];
    foreach($categories as $category=>$kind){
        $unit=$existing[$category]??null;
        if(!$unit){
            foreach($created as $createdUnit){
                $rec=get_post_meta($createdUnit,'_mad4b_room_source_receipt_v1',true);
                if(is_array($rec)&&($rec['room_category']??'')===$category){$unit=$createdUnit;break;}
            }
        }
        if(!$unit || get_post_status($unit)!=='draft')$fail('draft_unit_status_lost');
        if(get_post_meta($unit,'unit_start_price',true)!=='')$fail('unexpected_unit_price');
        if(get_post_meta($unit,'unit_max_occupancy',true)!=='')$fail('unexpected_capacity');
        if(in_array((string)get_post_meta($unit,'unit_available',true),['true','1','yes'],true))
            $fail('unexpected_inventory_flag');
        $found=$native->get_parents($unit);
        $ok=false;
        foreach((array)$found as $rel)if((int)($rel['parent_object_id']??0)===$id)$ok=true;
        if(!$ok)$fail('relation_readback_failed');
        $out[]=['unit_id'=>$unit,'name'=>$category,'status'=>get_post_status($unit),
                'unit_kind'=>$kind,'supplier_cost'=>null,'occupancy'=>null];
    }
    $finalTerms=wp_get_object_terms($id,'destinations',['fields'=>'ids']);
    if(!in_array((int)$city->term_id,array_map('intval',$finalTerms),true))
        $fail('aswan_city_rollback_or_failed');
    WP_CLI::line(wp_json_encode([
        'status'=>'EDITORIAL_SOURCE_DRAFTS_CREATED','site'=>home_url(),
        'property_id'=>$id,'city_term_id'=>(int)$city->term_id,
        'address'=>get_post_meta($id,'property_map',true),
        'units'=>$out,'newly_created_unit_ids'=>$created,
        'commercial_source_approved'=>false,'supplier_cost_total'=>null,
        'tour_rates_modified'=>false
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}catch(Throwable $e){
    // No silent partial success. Caller has full prechange snapshot in root-only archive.
    WP_CLI::error('OBELISK_WRITE_INTERRUPTED '.$e->getMessage());
}

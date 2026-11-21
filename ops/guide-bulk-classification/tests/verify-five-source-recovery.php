<?php
if (!defined('ABSPATH')||!defined('WP_CLI')) exit(1);
wp_set_current_user(1);
$core='MAD4B_Guide_Service_Composition_V1';
$bulk='MAD4B_Guide_Bulk_Classification_V1';
$expected=[55113=>1,55260=>1,60443=>8,60451=>1,60455=>1];
foreach($expected as $tour=>$days) {
    $source=$core::source($tour);
    if(is_wp_error($source)||count($source['days'])!==$days)
        WP_CLI::error('Day-unit recovery mismatch for '.$tour.' '.(is_wp_error($source)?$source->get_error_code():'count mismatch'));
    $actual=$bulk::detail($tour);
    if(is_wp_error($actual)||$actual['day_count']!==$days)
        WP_CLI::error('Bulk detail mismatch for '.$tour);
    if($tour===60443){
        if($source['day_unit_basis']!=='explicit_day_labels'||count($source['days'])!==8)
            WP_CLI::error('Multi-day program incorrectly aggregated');
    } else {
        if($source['day_unit_basis']!=='explicit_single_day_duration_taxonomy')
            WP_CLI::error('Missing bound verified taxonomy duration for '.$tour);
    }
    WP_CLI::log('PASS '.$tour.' day_count='.$days.' basis='.$source['day_unit_basis']);
}
foreach([60451,60455,60457,60461] as $tour) {
    $source=$core::source($tour);
    $detail=$bulk::detail($tour);
    if(is_wp_error($detail)||count($detail['days'])!==1 ||
       $detail['days'][0]['suggested_status']!=='full_day' ||
       $detail['days'][0]['confidence']!=='high' ||
       $detail['days'][0]['reason']!=='guide_and_guided_visit_included_with_one_day_duration' ||
       !$detail['days'][0]['bulk_eligible'])
         WP_CLI::error('Unproven full-day Guide candidate '.$tour);
    WP_CLI::log('PASS '.$tour.' sourced_high_guide_and_duration');
}
foreach([55113,55260,60443] as $tour) {
    $detail=$bulk::detail($tour);
    if(is_wp_error($detail)||$detail['high_new_candidates']!==0)
        WP_CLI::error('Weak evidence was promoted to HIGH '.$tour);
    WP_CLI::log('PASS '.$tour.' no_high_unverified_service');
}
$source=$core::source(60451);
$changed=false;
$fn=static function($value,$objectId,$key,$single) use (&$changed) {
    if ((int)$objectId===60451 && $key==='includes' && $changed) {
        return [[
            'item-0'=>['included_item'=>'Only hotel transfer; Guide inclusion withdrawn'],
        ]];
    }
    return $value;
};
add_filter('get_post_metadata',$fn,25000,4);
$changed=true;
$modified=$core::source(60451);
remove_filter('get_post_metadata',$fn,25000);
if (is_wp_error($modified)||$modified['source_hash']===$source['source_hash'])
    WP_CLI::error('Included Guide coverage change did not invalidate review hash');
WP_CLI::log('PASS modified_inclusions_invalidate_day_review_source_hash');
WP_CLI::success('GUIDE_FIVE_RECOVERY_AND_EVIDENCE_ACCEPTED');

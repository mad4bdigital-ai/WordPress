<?php
if(!defined('ABSPATH') || !defined('WP_CLI'))exit(1);
wp_set_current_user(1);
if(!class_exists('MAD4B_Dynamic_Dimensions_V1'))
    WP_CLI::error('Missing preview provider');
global $wpdb;
$posts=get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish',
    'posts_per_page'=>300,'suppress_filters'=>true,'fields'=>'ids']);
$checks=0;$translated=0;$noOther=0;$examples=[];$fail=[];
foreach($posts as $id){
    $termIds=array_map('intval',(array)$wpdb->get_col($wpdb->prepare(
    "SELECT tt.term_id FROM {$wpdb->term_relationships} tr
    JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
    WHERE tr.object_id=%d AND tt.taxonomy='package_levels'",$id)));
    if(!$termIds)continue;
    foreach($termIds as $termId){
        $actual=MAD4B_Dynamic_Dimensions_V1::package_for_tour((int)$id,$termId);
        $checks++;
        if(is_wp_error($actual)||(int)$actual!==$termId)
            $fail[]="Assigned package rejected tour=$id term=$termId";
        $canonical=apply_filters('wpml_object_id',$termId,'package_levels',false,'en');
        if(is_numeric($canonical) && (int)$canonical!==$termId){
            $en=MAD4B_Dynamic_Dimensions_V1::package_for_tour((int)$id,(int)$canonical);
            $checks++;$translated++;
            if(is_wp_error($en)||(int)$en!==$termId)
                $fail[]="Translated package not resolved tour=$id en=$canonical assigned=$termId";
            if(count($examples)<5)$examples[]=['tour'=>(int)$id,'requested'=>(int)$canonical,'assigned'=>$termId];
        }
        // Pick a truly different canonical package: no acceptance.
        $alternative=get_terms(['taxonomy'=>'package_levels','hide_empty'=>false,
            'number'=>30,'fields'=>'ids']);
        foreach((array)(is_wp_error($alternative)?[]:$alternative) as $other){
            $other=(int)$other;
            $otherCanonical=apply_filters('wpml_object_id',$other,'package_levels',false,'en');
            $firstCanonical=apply_filters('wpml_object_id',$termId,'package_levels',false,'en');
            if((int)$otherCanonical===(int)$firstCanonical)continue;
            $resolved=MAD4B_Dynamic_Dimensions_V1::package_for_tour((int)$id,$other);
            // Some tours offer multiple packages; only reject other levels not assigned.
            $assignedCanonical=array_map(static fn($x)=>(int)apply_filters(
                'wpml_object_id',$x,'package_levels',false,'en'),$termIds);
            if(in_array((int)$otherCanonical,$assignedCanonical,true))continue;
            $checks++;$noOther++;
            if(!is_wp_error($resolved)||$resolved->get_error_code()!=='dimension_package_not_offered')
                $fail[]="Unassigned package accepted tour=$id requested=$other";
            break;
        }
    }
}
if($fail)WP_CLI::error(wp_json_encode(array_slice($fail,0,15)));
echo 'PACKAGE_TRANSLATION_ACCEPTANCE '.wp_json_encode([
    'site'=>home_url(),'tours'=>count($posts),'checks'=>$checks,
    'translated'=>$translated,'unassigned_rejected'=>$noOther,
    'samples'=>$examples,'failures'=>count($fail),
],JSON_UNESCAPED_UNICODE).PHP_EOL;
WP_CLI::success('WPML_PACKAGE_ORIGINAL_RELATIONSHIPS_VERIFIED');

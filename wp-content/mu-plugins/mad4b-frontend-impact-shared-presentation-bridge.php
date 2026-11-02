<?php
/**
 * Plugin Name: MAD4B Frontend Impact — Shared Product & Presentation Adapter
 * Version: 1.0.0
 * Description: Read-only authority/context bridge for MAD4B frontend impact score.
 */
defined('ABSPATH') || exit;
add_filter('mad4b/frontend_impact/score_context',static function($rank,$subject) {
    if(!is_array($rank)||!is_array($subject)||
       ($subject['taxonomy']??'')!=='destinations')return $rank;
    $term_id=(int)($subject['localized_parent_id']??0);
    if($term_id<1)return $rank;
    $term=get_term($term_id,'destinations');
    if(!$term instanceof WP_Term)return $rank;

    // The core owns editorial and commercial product inclusion; MU owns
    // surface-level navigation decisions. Both are OPTIONAL in multi-site use.
    $editorial=function_exists('shared_presentation_destination_editorial_allowed_v1')
        ?shared_presentation_destination_editorial_allowed_v1($term_id):null;
    $commercial=function_exists('shared_presentation_destination_commercial_allowed_v1')
        ?shared_presentation_destination_commercial_allowed_v1($term_id):null;
    $surface=class_exists('Presentation_Policy_Context_v1')
        ?Presentation_Policy_Context_v1::decide('tours','navigation','discover',$term_id):null;
    $navigation=is_array($surface)?(bool)($surface['allowed']??false):null;
    $rank['shared_product_core']=[
        'available'=>($editorial!==null && $commercial!==null),
        'editorial_allowed'=>$editorial,
        'commercial_allowed'=>$commercial
    ];
    $rank['mu_presentation']=[
        'available'=>is_array($surface),
        'navigation_allowed'=>$navigation,
        'state'=>is_array($surface)?(string)($surface['state']??'unknown'):'unknown',
        'reason'=>is_array($surface)?(string)($surface['reason']??'unknown'):'unavailable'
    ];
    // A denied public surface must NOT receive a top visible-navigation priority,
    // regardless of a stale public HTML fragment or unverified translation.
    if($editorial===false || $navigation===false){
        $rank['score']=min(25,(int)($rank['score']??0));
        $rank['confidence']='presentation_policy_excluded';
        $rank['evidence']='source_policy_denies_navigation';
    }
    // Commercial-only classification, never a price or booking authorization.
    $rank['commercial_surface']='unverified';
    if($commercial===true)$rank['commercial_surface']='eligible_by_shared_core';
    elseif($commercial===false)$rank['commercial_surface']='editorial_only_or_denied';
    return $rank;
},20,2);

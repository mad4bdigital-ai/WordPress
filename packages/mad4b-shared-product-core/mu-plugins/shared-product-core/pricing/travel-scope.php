<?php
/** Physical service geography is separate from traveler nationality/residence and booking location. Lab only. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_travel_scope($origin_destination_term_id, $service_country_term_ids, $traveler_context = []) {
    if(!is_array($service_country_term_ids)||!is_array($traveler_context))return new WP_Error('invalid_travel_scope_inputs');
    $countries=[];
    foreach($service_country_term_ids as $id){
        $term=get_term(absint($id),'destinations');
        if(!$term||is_wp_error($term))return new WP_Error('invalid_service_destination');
        if(!(int)$term->parent)continue; // Continent is a taxonomy grouping, not a service country.
        $c=shared_product_country_from_destination($id);
        if(is_wp_error($c))return $c;
        $countries[$c]=$c;
    }
    if(!$countries)return new WP_Error('service_country_missing');
    $origin=null;
    if($origin_destination_term_id!==null && $origin_destination_term_id!==''){
        $origin=shared_product_country_from_destination($origin_destination_term_id);
        if(is_wp_error($origin))return $origin;
    }
    $scope='undetermined';
    if($origin!==null){
        $scope=(count($countries)===1 && isset($countries[$origin]))?'domestic':'international';
    }
    // Customer nationality, residence and present location are attributes, not geographical trip classification.
    return ['scope'=>$scope,'origin_country_term_id'=>$origin,'service_country_term_ids'=>array_values($countries),
       'traveler_context'=>$traveler_context,'classification_basis'=>'service_origin_and_countries_only'];
}
/** Existing tour location fields are optional; absent origin must never imply international travel. */
function shared_product_tour_travel_scope($rate_id, $traveler_context = []) {
    $context=shared_product_tour_context($rate_id);if(is_wp_error($context))return $context;
    $tour_id=(int)$context['tour_id'];$terms=wp_get_post_terms($tour_id,'destinations',['fields'=>'ids']);
    if(is_wp_error($terms))return $terms;
    $origin=get_post_meta($rate_id,'related_origin_destination_term_id',true);
    if(!$origin)$origin=get_post_meta($tour_id,'tour_origin_location_term',true);
    if(is_array($origin))return new WP_Error('ambiguous_tour_origin');
    $result=shared_product_travel_scope($origin?:null,$terms,$traveler_context);
    if(is_wp_error($result))return $result;
    // A locally delivered activity with one verified service country is domestic even without a pickup term.
    // Package/cruise boundaries require explicit origin or itinerary evidence.
    if($result['scope']==='undetermined' && count($result['service_country_term_ids'])===1
       && in_array($context['tour_mode']??'',['activity','extra_trip'],true)) {
        $result['scope']='domestic';
        $result['classification_basis']='single_country_local_activity';
    }
    return $result;
}

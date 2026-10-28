<?php
/**
 * REV111: Tour itinerary belongs to the Tour post; reusable cruise
 * programs belong to the registered 'programs' taxonomy.
 * Reads native JetEngine meta/term relationships, never duration-options.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local'
 || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

function shared_product_program_canonical_term_v1(int $termId):int {
    if(!$termId || !term_exists($termId,'programs'))return 0;
    $en=apply_filters('wpml_object_id',$termId,'programs',false,'en');
    $id=absint($en?:$termId);
    return term_exists($id,'programs')?$id:0;
}
function shared_product_cruise_assigned_programs_v1(int $tourId):array {
    if(get_post_type($tourId)!=='tours-and-activities'
      || !function_exists('shared_product_program_mode_supported')
      || !shared_product_program_mode_supported(get_post_meta($tourId,'tour_mode',true)))return [];
    $native=wp_get_post_terms($tourId,'programs',['fields'=>'ids']);
    $ids=is_wp_error($native)?[]:$native;
    $raw=get_post_meta($tourId,'related_program_terms',true);
    if(is_string($raw))$raw=maybe_unserialize($raw);
    if(is_array($raw)) {
        foreach($raw as $value){
            if(is_scalar($value)&&ctype_digit((string)$value))$ids[]=absint($value);
        }
    }
    $out=[];
    foreach($ids as $rawId){
        $id=shared_product_program_canonical_term_v1(absint($rawId));
        if(!$id)continue;
        $term=get_term($id,'programs');
        if(!$term instanceof WP_Term)continue;
        // A grouping parent with no content is not an actual selectable program.
        $hasContent=(bool)get_term_meta($id,'program_itinerary',true)
            || (bool)get_term_meta($id,'template_shortcode',true)
            || (bool)get_term_meta($id,'program_description',true);
        if(!$hasContent)continue;
        $out[$id]=$term->name;
    }
    asort($out,SORT_NATURAL|SORT_FLAG_CASE);
    return $out;
}
function shared_product_tour_program_allowed_v1(int $tourId,int $programId):bool {
    if(!$programId)return false;
    $canonical=shared_product_program_canonical_term_v1($programId);
    if(!$canonical)return false;
    return array_key_exists($canonical,shared_product_cruise_assigned_programs_v1($tourId));
}
function shared_product_tour_itinerary_contract_v1(int $tourId):array {
    if(get_post_type($tourId)!=='tours-and-activities')
        return ['state'=>'unknown_tour','authoritative_source'=>null,'selectable_programs'=>[]];
    $mode=(string)get_post_meta($tourId,'tour_mode',true);
    $cruise=function_exists('shared_product_program_mode_supported')
        &&shared_product_program_mode_supported($mode);
    $items=get_post_meta($tourId,'itinerary_item',true);
    $count=0;
    if(is_array($items))foreach($items as $key=>$row)
        if(str_starts_with((string)$key,'item-')&&is_array($row))$count++;
    $linked=$cruise?shared_product_cruise_assigned_programs_v1($tourId):[];
    // An explicitly linked reusable program takes precedence, even if a
    // historic post itinerary still exists for content compatibility.
    if(!$cruise || !$linked){
        $state=$count ? ($cruise?'single_post_cruise_itinerary':'single_tour_itinerary')
            : ($cruise?'cruise_itinerary_missing':'post_itinerary_missing');
        return [
            'mode'=>$mode,
            'authoritative_source'=>'tour_post_meta:itinerary_item',
            'itinerary_item_count'=>$count,
            'linked_program_ids'=>[],
            'selectable_programs'=>[],
            'customer_program_choice'=>false,
            'state'=>$state,
            'quote_authority'=>false,
        ];
    }
    $labels=[];
    foreach($linked as $id=>$name){
        $term=get_term($id,'programs');
        if($term instanceof WP_Term && function_exists('shared_product_rate_facet_term_label'))
            $name=shared_product_rate_facet_term_label($term);
        $labels[(string)$id]=$name;
    }
    return [
        'mode'=>$mode,
        'authoritative_source'=>'taxonomy:programs',
        'program_content_meta'=>'program_itinerary',
        'itinerary_item_count'=>$count,
        'linked_program_ids'=>array_map('intval',array_keys($linked)),
        'selectable_programs'=>$labels,
        'customer_program_choice'=>count($linked)>1,
        'state'=>count($linked)>1?'multiple_reusable_cruise_programs':'single_reusable_cruise_program',
        'quote_authority'=>false,
    ];
}
/**
 * Rate contract: an embedded single-itinerary Cruise has no separate
 * program term; only a multi-program Cruise requires a linked program Rate.
 */
function shared_product_cruise_rate_program_valid_v1(int $tourId,int $rateId):bool {
    if(get_post_type($tourId)!=='tours-and-activities'
        ||get_post_type($rateId)!=='tour-rates')return false;
    // Common Tours and Activities have no program-selection contract.
    // Avoid reading large itinerary metadata for every eligible retail Rate.
    if(!shared_product_program_mode_supported(get_post_meta($tourId,'tour_mode',true)))
        return true;
    $source=shared_product_tour_itinerary_contract_v1($tourId);
    if(($source['state']??'')==='cruise_itinerary_missing')return false;
    $program=absint(get_post_meta($rateId,'related_program_term_id',true));
    if(($source['authoritative_source']??'')!=='taxonomy:programs')return true;
    if(!empty($source['customer_program_choice']))
        return $program>0&&shared_product_tour_program_allowed_v1($tourId,$program);
    // One reusable program means no customer choice, so an older Rate may
    // have no program FK. If an actual programs term is set, it must match.
    $canonical=shared_product_program_canonical_term_v1($program);
    return !$canonical || shared_product_tour_program_allowed_v1($tourId,$program);
}
/**
 * Read-only migration evidence. Old Rate IDs in duration-options remain in
 * storage for backward compatibility, but NEVER become program taxonomy IDs.
 */
function shared_product_tour_rate_program_audit_v1(int $tourId):array {
    if(get_post_type($tourId)!=='tours-and-activities')
        return ['status'=>'unknown_tour','count'=>0,'rows'=>[]];
    $source=shared_product_tour_itinerary_contract_v1($tourId);
    $ids=get_posts([
        'post_type'=>'tour-rates','post_status'=>['publish','draft'],
        'posts_per_page'=>150,'fields'=>'ids','suppress_filters'=>true,
        'meta_query'=>[['key'=>'related_tour_id','value'=>(string)$tourId,'compare'=>'=']]
    ]);
    $rows=[];$counts=[];
    foreach($ids as $rateId){
        $rateId=absint($rateId);
        $id=absint(get_post_meta($rateId,'related_program_term_id',true));
        $valid=$id?shared_product_tour_program_allowed_v1($tourId,$id):false;
        $taxonomy=shared_product_program_canonical_term_v1($id)?'programs':
            (term_exists($id,'duration-options')?'duration-options':null);
        if(($source['authoritative_source']??'')==='taxonomy:programs'
           && !empty($source['customer_program_choice'])){
            $status=$id && $valid?'shared_program_linked':
                ($id?'invalid_or_unassigned_program_reference':'missing_program_reference');
        }elseif($id===0){
            $status='no_rate_program_required';
        }elseif($taxonomy==='duration-options'){
            $status='legacy_duration_reference_not_program';
        }elseif($taxonomy==='programs'){
            $status=$valid?'single_shared_program_reference':'unlinked_shared_program_reference';
        }else $status='unrecognized_legacy_reference';
        $counts[$status]=($counts[$status]??0)+1;
        $rows[]=['rate_id'=>$rateId,'stored_reference_id'=>$id,
            'reference_taxonomy'=>$taxonomy,'classification'=>$status];
    }
    ksort($counts);
    return ['status'=>'read_only','tour_id'=>$tourId,'source_state'=>$source['state']??'',
        'count'=>count($rows),'truncated'=>count($ids)>=150,
        'classes'=>$counts,'rows'=>$rows,'write_authorized'=>false];
}
add_action('rest_api_init',static function(){
    register_rest_route('shared-product/v1','/tour-program-source',[
        'methods'=>WP_REST_Server::READABLE,
        'permission_callback'=>static fn()=>current_user_can('manage_options')
            && wp_get_environment_type()==='local'
            && defined('SHARED_PRODUCT_LAB_ONLY')&&SHARED_PRODUCT_LAB_ONLY,
        'args'=>['tour_id'=>['required'=>true,'type'=>'integer','minimum'=>1]],
        'callback'=>static function(WP_REST_Request $request){
            $id=absint($request['tour_id']);
            if(get_post_type($id)!=='tours-and-activities')
                return new WP_Error('program_tour_unknown','No Tour source',['status'=>404]);
            $r=shared_product_tour_itinerary_contract_v1($id);
            $r['tour_id']=$id;
            if(function_exists('shared_product_tour_duration_source_v1'))
                $r['duration_source']=shared_product_tour_duration_source_v1($id);
            $r['rate_program_reconciliation']=shared_product_tour_rate_program_audit_v1($id);
            $r['writes_authorized']=false;
            $resp=new WP_REST_Response($r);
            $resp->header('Cache-Control','private, no-store, max-age=0');
            return $resp;
        }
    ]);
},25);

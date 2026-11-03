<?php
/**
 * REV109 — Admin source-bound Rate/Program/Package/Date eligibility readback.
 *
 * Calls the existing Retail Rate eligibility engine; it is neither a new
 * price source nor a reservation/inventory promise.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local'
    || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

function shared_presentation_rate_filter_preview_v1(int $filterId, int $tourId, array $choices = []) {
    $manifest = shared_presentation_native_typed_manifest_v1();
    $contract = $manifest[$filterId] ?? null;
    if (!is_array($contract) || !in_array('tour_rates',
        (array)($contract['provider_slugs']??[]), true)) {
        return new WP_Error('source_not_mounted_on_rates','Filter is not a verified Tour Rate consumer');
    }
    $name = (string)($contract['query_var']??'');
    $isDate = (string)($contract['filter_type']??'') === 'date-range'
        && $name === 'advanced_date__any__tour_rate_start_date__tour_rate_end_date';
    $isProgram = (string)($contract['data_source']??'')==='query_builder'
        && $name==='related_program_term_id'
        && ($contract['state']??'')==='native_terms_query_validated';
    $isPackage = (string)($contract['data_source']??'')==='query_builder'
        && $name==='related_package_term_id'
        && ($contract['state']??'')==='native_terms_query_validated';
    if (!$isDate && !$isProgram && !$isPackage)
        return new WP_Error('source_contract_not_supported','The mounted source does not own this Rate preview');
    if (get_post_type($tourId)!=='tours-and-activities' || get_post_status($tourId)!=='publish')
        return new WP_Error('invalid_tour','Published Tour source required');
    $allowKeys=['date','package_term_id','program_term_id'];
    if (array_diff(array_keys($choices),$allowKeys))
        return new WP_Error('invalid_filter_keys','Unsupported source filters');
    $dateRaw=(string)($choices['date']??'');
    $date=null;
    if ($dateRaw !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$dateRaw))
            return new WP_Error('invalid_date','Strict YYYY-MM-DD required');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$dateRaw,new DateTimeZone('UTC'));
        $errors=DateTimeImmutable::getLastErrors();
        if (!$date || ($errors!==false && ($errors['warning_count']||$errors['error_count']))
            || $date->format('Y-m-d')!==$dateRaw)
            return new WP_Error('invalid_date','Calendar date is invalid');
    }
    $program = absint($choices['program_term_id']??0);
    $package = absint($choices['package_term_id']??0);
    $isCruise = function_exists('shared_product_program_mode_supported')
        && shared_product_program_mode_supported(get_post_meta($tourId,'tour_mode',true));
    if ($program && (!function_exists('shared_product_tour_program_allowed_v1')
        || !shared_product_tour_program_allowed_v1($tourId,$program)))
        return new WP_Error('program_not_applicable','Program is not linked to this Cruise Tour');
    $eligible = function_exists('shared_product_eligible_tour_rate_ids')
        ? shared_product_eligible_tour_rate_ids($tourId) : [];
    if (count($eligible)>300)
        return new WP_Error('rate_preview_limit','Review the source before scanning an oversized rate set');
    $assigned = wp_get_object_terms($tourId,'package_levels',['fields'=>'ids']);
    $allowedPackages = is_wp_error($assigned) ? [] : array_fill_keys(array_map('intval',$assigned),true);
    $matched=[];
    $packages=[];
    $programs=[];
    foreach ($eligible as $rateId) {
        $rateId=absint($rateId);
        if (get_post_type($rateId)!=='tour-rates' || get_post_status($rateId)!=='publish'
            || (int)get_post_meta($rateId,'related_tour_id',true)!==$tourId) continue;
        $p=(int)get_post_meta($rateId,'related_package_term_id',true);
        $g=(int)get_post_meta($rateId,'related_program_term_id',true);
        if (!$p || !isset($allowedPackages[$p])) continue;
        if ($package && $p!==$package) continue;
        if ($isCruise && (!$g || !shared_product_tour_program_allowed_v1($tourId,$g)))continue;
        if ($program && shared_product_program_canonical_term_v1($g)!==shared_product_program_canonical_term_v1($program))continue;
        if ($date) {
            $from=(int)get_post_meta($rateId,'tour_rate_start_date',true);
            $to=(int)get_post_meta($rateId,'tour_rate_end_date',true);
            $at=$date->getTimestamp();
            if ($from<=0 || $to<$from || $from>$at || $to<$at) continue;
        }
        $matched[]=$rateId;
        $tier=get_term($p,'package_levels');
        if ($tier instanceof WP_Term) $packages[(string)$p]=
            function_exists('shared_product_rate_facet_term_label')
                ?shared_product_rate_facet_term_label($tier):$tier->name;
        if ($isCruise && $g) {
            $canonical=shared_product_program_canonical_term_v1($g);
            $t=get_term($canonical,'programs');
            if($t instanceof WP_Term) $programs[(string)$canonical]=
                function_exists('shared_product_rate_facet_term_label')
                    ?shared_product_rate_facet_term_label($t):$t->name;
        }
    }
    return [
        'filter_id'=>$filterId,
        'tour_id'=>$tourId,
        'source_query_var'=>$name,
        'rate_provider'=>'jet-engine/tour_rates',
        'eligible_rate_count'=>count($eligible),
        'matched_rate_count'=>count($matched),
        'matched_rate_ids'=>$matched,
        'package_options'=>$packages,
        'program_options'=>$programs,
        'program_enabled_for_tour'=>$isCruise,
        'date_checked'=>$dateRaw?:null,
        'selected_package_term_id'=>$package ?: null,
        'selected_program_term_id'=>$program ?: null,
        'authority'=>'existing_shared_product_eligible_tour_rate_ids',
        'supplier_inventory_confirmed'=>false,
        'final_booking_price_confirmed'=>false,
        'writes_authorized'=>false
    ];
}
add_action('rest_api_init',static function():void {
    register_rest_route('shared-product/v1','/native-filter-contracts/(?P<id>\d+)/eligibility',[
        'methods'=>WP_REST_Server::READABLE,
        'permission_callback'=>static fn():bool => current_user_can('manage_options')
            && wp_get_environment_type()==='local'
            && defined('SHARED_PRODUCT_LAB_ONLY') && SHARED_PRODUCT_LAB_ONLY,
        'args'=>[
            'id'=>['type'=>'integer','required'=>true,'minimum'=>1],
            'tour_id'=>['type'=>'integer','required'=>true,'minimum'=>1],
            'date'=>['type'=>'string','required'=>false],
            'package_term_id'=>['type'=>'integer','required'=>false,'minimum'=>0],
            'program_term_id'=>['type'=>'integer','required'=>false,'minimum'=>0]
        ],
        'callback'=>static function(WP_REST_Request $r) {
            $opts=[];
            foreach(['date','package_term_id','program_term_id'] as $key) {
                if($r->has_param($key)) $opts[$key]=$r->get_param($key);
            }
            $result=shared_presentation_rate_filter_preview_v1(
                absint($r['id']),absint($r['tour_id']),$opts);
            if (is_wp_error($result))
                return new WP_Error($result->get_error_code(),
                    $result->get_error_message(),['status'=>422]);
            $out=new WP_REST_Response($result);
            $out->header('Cache-Control','private, no-store, max-age=0');
            return $out;
        }
    ]);
},25);

<?php
/**
 * Source-backed hotel, cruise, sleeper-train discoverability (READ-ONLY).
 * Test native taxonomy and station order without fake approval or pricing.
 */
defined('ABSPATH')||exit;
if(!defined('WP_CLI')||!WP_CLI)return;
$checks=0;$fail=[];
$test=static function(bool $v,string $why)use(&$checks,&$fail){
    $checks++;if(!$v)$fail[]=$why;
};
$site=home_url();
$wayfa=str_contains($site,'wayfatours.com');
$source=str_contains($site,'tours.dev.mad4b.com');
$test($wayfa||$source,'company context');
$test(current_user_can('commerce_manage_pricing'),'authorized pricing actor');
$test(class_exists('MAD4B_Package_Stage_Sourcing_V1',false),
    'source discovery class registered');
$test(class_exists('MAD4B_Package_Stage_Rate_Guard_V1',false),
    'native generator guard loaded');
$test(class_exists('MAD4B_Package_Pricing_Lab_Readiness_V1',false),
    'read-only Pricing Lab loaded');
$ratesBefore=count(get_posts(['post_type'=>'tour-rates','post_status'=>'any',
    'fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>true]));
$observed=['site'=>$site,'discovery'=>[],'train'=>[],'cruise'=>[]];
if($wayfa){
    foreach([
        60437=>['city'=>'aswan','name'=>'hotel-aswan-02','min'=>5],
        60627=>['city'=>'luxor','name'=>'hotel-luxor-02','min'=>11],
        60629=>['city'=>'sharm','name'=>'resort-sharm-03','min'=>43],
    ] as $tour=>$rule){
        $plan=get_post_meta($tour,'_mad4b_package_accommodation_source_plan_v1',true);
        $stage=null;
        foreach((array)($plan['stages']??[]) as $s)
            if(($s['stage_key']??'')===$rule['name']){$stage=$s;break;}
        $test(is_array($stage),'saved native stage found '.$rule['name']);
        if(!is_array($stage))continue;
        $seenIds=[];
        foreach([2353,2354,2356] as $tier){
            $x=MAD4B_Package_Stage_Sourcing_V1::inspect($tour,$stage,$tier);
            $test($x['type']==='published_hotel_property_pool',
                'source pool contract '.$tour.'/'.$tier);
            $test($x['status']==='editorial_discovery_only' &&
                !$x['ready'] && $x['supplier_cost_total']===null && !$x['writes'],
                'no price/approval or writes');
            $test($x['source_property_count']>=$rule['min'],
                'all known source hotel options found '.$tour.'/'.$tier);
            $test($x['source_property_count']===count($x['properties']),
                'unique original hotel count');
            $test($x['tier_matched_count']===0 &&
                $x['tier_unclassified_count']===$x['source_property_count'],
                'native tier contract has not been invented');
            foreach($x['properties'] as $property){
                $test($property['native_published_unit_count']===0,
                    'draft Room Unit templates are not live published stock');
                $test($property['native_unit_count']===
                    $property['native_published_unit_count']+
                    $property['native_draft_unit_count'],
                    'native relation count excludes unrelated or trashed posts');
                $drafts=(array)($property['source_unit_draft_editors']??[]);
                $test(count($drafts)===$property['native_draft_unit_count'],
                    'all draft Unit editors are discoverable at original CPT');
                foreach($drafts as $draft){
                    $uid=(int)($draft['id']??0);
                    $test(get_post_type($uid)==='units' &&
                          get_post_status($uid)==='draft' &&
                          get_post_meta($uid,'unit_start_price',true)==='' &&
                          get_post_meta($uid,'unit_available',true)!=='true' &&
                          empty($draft['supplier_cost_verified']),
                          'original linked room draft is unpriced and unbookable '.$uid);
                }
                $test(!$property['tier_eligible']&&
                    $property['supplier_cost_verified']===false,
                    'not a supplier-priced candidate');
                if($rule['city']==='aswan'){
                    $terms=wp_get_object_terms($property['id'],'destinations',
                        ['fields'=>'slugs']);
                    $test(!in_array('abu-simbel-temples',(array)$terms,true),
                        'Abu Simbel hotel never offered for Aswan overnight');
                }
            }
            $seenIds[$tier]=array_column($x['properties'],'id');
            $observed['discovery'][]=[
                'tour'=>$tour,'stage'=>$rule['name'],'tier'=>$tier,
                'hotels'=>$x['source_property_count'],
                'tier_mapped'=>$x['tier_matched_count'],
                'units_missing'=>$x['missing_native_units_count'],
                'abou_simbel_excluded'=>$x['excluded_wrong_locality']];
        }
        $test($seenIds[2353]===$seenIds[2354]&&
            $seenIds[2354]===$seenIds[2356],
            'unclassified source pool consistent across tiers');
    }
    $aswan=MAD4B_Package_Stage_Rate_Guard_V1::stageScope(60437,[
        'destination_key'=>'aswan','lodging_kind'=>'hotel']);
    foreach([36336,36283,36160] as $wrongHotel){
        $elig=MAD4B_Package_Stage_Rate_Guard_V1::propertyEligibility(
            $wrongHotel,2353,$aswan,'hotel');
        $test($elig['reason']==='property_in_abu_simbel_not_aswan_city',
            'native Generator rejects Abu Simbel hotel in Aswan '.$wrongHotel);
    }
    $plan=get_post_meta(60623,'_mad4b_package_accommodation_source_plan_v1',true);
    $legs=[];
    foreach((array)($plan['stages']??[]) as $s){
        if(($s['lodging_kind']??'')!=='sleeper_train')continue;
        $x=MAD4B_Package_Stage_Sourcing_V1::inspect(60623,$s,2354);
        $day=(int)$s['source_day_start'];
        $expected=$day===2?54552:($day===6?54553:0);
        $test($x['type']==='published_sleeper_train_route_candidates',
            'native specialized sleeper source');
        $test($x['route']['status']==='itinerary_route_proposal',
            'train route verified from original itinerary+prior stay');
        $test(count($x['train_candidates'])===1 &&
            (int)$x['train_candidates'][0]['id']===$expected,
            'true published route-matched sleeper train '.$day);
        $test($x['train_candidates'][0]['published_transport_rate_count']===0 &&
            !$x['train_candidates'][0]['cabin_supplier_contract_verified'] &&
            !$x['schedule_verified'] && !$x['checkout_ready'],
            'train tariff and cabin remain unverified');
        $test($x['cost_total']===null && !$x['writes'],
            'no synthetic train price or binding');
        $legs[]=['stage'=>$s['stage_key'],'day'=>$day,
            'route'=>$x['route'],'train_id'=>$expected,'rate_count'=>0];
    }
    $test(count($legs)===2,'two train nights treated individually');
    $observed['train']=$legs;
    $plan=get_post_meta(57562,'_mad4b_package_accommodation_source_plan_v1',true);
    foreach($plan['stages'] as $s)if(($s['lodging_kind']??'')==='cruise'){
        $x=MAD4B_Package_Stage_Sourcing_V1::inspect(57562,$s,2354);
        $test($x['type']==='cruise_native_program_source' &&
            $x['program_id']>0 && $x['program_days']===4 &&
            $x['source_verified_direction_only'] && !$x['sailing_confirmed'] &&
            $x['supplier_cost_total']===null,
            'original cruise program directions, not fabricated ship booking');
        $observed['cruise'][]=$x;
    }
    $lab=MAD4B_Package_Pricing_Lab_Readiness_V1::inspect(60623,2354);
    $test(is_array($lab),'Pricing Lab integrated without a write');
    if(is_array($lab)){
        $stages=array_values(array_filter($lab['stages'],static fn($s)=>
            $s['kind']==='sleeper_train'));
        $test(count($stages)===2,'Pricing Lab has both train-stage source views');
        foreach($stages as $s)$test(count($s['source_discovery']['train_candidates'])===1,
            'Pricer view references source-matched native train');
    }
}else{
    // The source may be receiving native Package plans from independently
    // reconciled content jobs; absence is NOT a permanent invariant.
    $plans=MAD4B_Package_Pricing_Lab_Readiness_V1::packages();
    $test(is_array($plans),'source Package catalog is readable');
    $observed['source_saved_plan_count']=count($plans);
    foreach($plans as $plan){
        $t=(int)($plan['id']??0);
        $v=MAD4B_Package_Stage_Rate_Guard_V1::review(
            $t,2354,'2027-02-01','2027-02-28');
        $test(!empty($v['applies']) && !$v['ready'] &&
            !empty($v['blockers']) && $v['cost_total']===null,
            'newly saved source plan is not falsely commercially approved');
        $observed['source_saved_plans'][]=[
            'tour_id'=>$t,'stage_count'=>count($v['stages']),
            'blocker_count'=>count($v['blockers'])];
    }
}
$ratesAfter=count(get_posts(['post_type'=>'tour-rates','post_status'=>'any',
    'fields'=>'ids','posts_per_page'=>-1,'suppress_filters'=>true]));
$test($ratesBefore===$ratesAfter,'zero Rate records created');
if($fail)WP_CLI::error(wp_json_encode([
    'checks'=>$checks,'failed'=>$fail,'before'=>$ratesBefore,
    'after'=>$ratesAfter,'observed'=>$observed],JSON_UNESCAPED_SLASHES));
WP_CLI::line(wp_json_encode([
    'site'=>$site,'passed'=>$checks,'failed'=>0,
    'source_only'=>true,'rates_before'=>$ratesBefore,
    'rates_after'=>$ratesAfter,'observed'=>$observed
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

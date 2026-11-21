<?php
/**
 * MAD4B — Native Package Tour Rate generator stage-source admission.
 *
 * Execute on each company, authenticated as an authorized pricing admin:
 *   wp --user=1 eval-file /tmp/verify-package-tour-rate-generator-stage-guard-live.php
 *
 * Self-contained, read-only except exercising two native create() attempts
 * that MUST return WP_Error before acquiring a write lock or creating posts.
 * No synthetic source meta, no supplier approval and no generated Rate writes.
 */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI) return;
$checks=0;$fail=[];
$assert=static function(bool $condition,string $label)use(&$checks,&$fail):void{
    $checks++;
    if(!$condition)$fail[]=$label;
};
$guard='MAD4B_Package_Stage_Rate_Guard_V1';
$site=home_url();
$wayfa=str_contains($site,'wayfatours.com');
$source=str_contains($site,'tours.dev.mad4b.com');
$assert($wayfa||$source,'known company context');
$assert(class_exists($guard,false),'native shared guard class loaded');
$assert(class_exists('Shared_Product_Rate_Generation',false),'native Rate generation class loaded');
$assert(class_exists('MAD4B_Rate_Planner',false),'native Rate Planner class loaded');
$assert(current_user_can('commerce_manage_pricing'),'authorized pricing review');
$wpdb=$GLOBALS['wpdb'];
$rateCount=static fn():(int)$wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tour-rates'");
$before=$rateCount();
$observed=['package_reviews'=>0,'package_stage_reviews'=>0,
    'unverified_packages'=>0,'wpml_source'=>null,
    'direct_generator_rejections'=>[],'plan_rejections'=>[],
    'nonpackage_preflight'=>null];
$tours=$wayfa?[57562,60437,60439,60441,60443,60623,60627,60629]:[60629];
foreach($tours as $tour) {
    $assert($guard::applies($tour),'Package classification must apply '.$tour);
    foreach([2353,2354,2356] as $tier) {
        $v=$guard::review($tour,$tier,'2027-02-01','2027-03-31');
        $assert($v['applies']===true,'review is applicable '.$tour.'/'.$tier);
        $assert($v['ready']===false && $v['checkout_ready']===false &&
            $v['cost_total']===null,'no unverified ready/price signal '.$tour.'/'.$tier);
        $assert(!empty($v['blockers']),'explicit failure reason '.$tour.'/'.$tier);
        $assert($v['contract']===$guard::CONTRACT,'source contract '.$tour.'/'.$tier);
        $r=$guard::assertReady($tour,$tier,'2027-02-01','2027-03-31');
        $assert(is_wp_error($r)&&
            $r->get_error_code()==='rate_generator_package_stage_source_incomplete',
            'fail-closed stage admission '.$tour.'/'.$tier);
        $observed['package_reviews']++;
        $observed['package_stage_reviews']+=count($v['stages']);
        $observed['unverified_packages']++;
        if($wayfa){
            $assert(count($v['stages'])>0,'Package original stage geometry '.$tour.'/'.$tier);
            $assert(!in_array('source_stage_bindings_stale',$v['blockers'],true),
                'Package saved source and bindings digest agree '.$tour.'/'.$tier);
        }else{
            $assert(in_array('source_accommodation_stage_plan_missing',$v['blockers'],true),
                'source site has no package source stages yet');
        }
    }
}
if($wayfa){
    $assert($observed['package_reviews']===24,'8 packages x 3 native tiers');
    $assert($observed['package_stage_reviews']===84,'28 original stages x 3 tiers');
    $es=$guard::review(60661,2354,'2027-02-01','2027-03-31');
    $observed['wpml_source']=$es['source_tour_id'];
    $assert($es['source_tour_id']===60623 && count($es['stages'])===5,
        'WPML Spanish resolved only to canonical English source');
    // Only explicitly scoped Cairo/Giza survives. "Egypt" alone is not a city.
    $cairo=$guard::stageScope(57562,['destination_key'=>'cairo']);
    $luxor=$guard::stageScope(60627,['destination_key'=>'luxor']);
    $aswan=$guard::stageScope(60437,['destination_key'=>'aswan']);
    $sharm=$guard::stageScope(60629,['destination_key'=>'sharm']);
    $assert($cairo['accepted_slugs']===['cairo','giza'] &&
        $cairo['metro_exception']==='source_explicit_cairo_and_giza',
        'only documented Cairo/Giza metro scope');
    $assert(in_array('aswan-gov',$aswan['accepted_slugs'],true) &&
        in_array('sharm-el-sheikh',$sharm['accepted_slugs'],true),
        'canonical destination slugs');
    foreach([$aswan,$luxor,$sharm] as $scope) {
        $decision=$guard::propertyEligibility(36197,2353,$scope,'hotel');
        $assert($decision['eligible']===false &&
            $decision['reason']==='property_destination_mismatch',
            'Cairo hotel rejected from other destination');
    }
    $assert(!$guard::propertyEligibility(36197,2354,$cairo,'hotel')['eligible'],
        'Silver Property not valid for Gold');
    $assert(!$guard::propertyEligibility(36197,2353,$cairo,'cruise')['eligible'],
        'Nile Cruise not represented as hotel');
    $fake=$guard::itineraryNightEvidence(60627,[
        'source_day_start'=>3,'nights'=>1,
        'lodging_kind'=>'hotel','destination_key'=>'cairo']);
    $assert($fake['status']==='itinerary_destination_conflict',
        'synthetic Cairo stay conflicts with genuine Luxor overnight');
    $correct=$guard::itineraryNightEvidence(60627,[
        'source_day_start'=>3,'nights'=>1,
        'lodging_kind'=>'hotel','destination_key'=>'luxor']);
    $assert($correct['status']==='explicit_overnights_matched',
        'genuine Luxor overnight accepted');
}
$package=$wayfa?60627:60629;
foreach(['manual','auto'] as $mode) {
    $input=[
        'kind'=>'tour','parent_id'=>$package,'amount'=>'100.00',
        'currency'=>'USD','price_unit'=>'per_person',
        'valid_from'=>'2027-02-01','valid_to'=>'2027-03-31',
        'package_term_id'=>2354,
        'single_factor'=>'1','double_factor'=>'1','triple_factor'=>'1',
        'accommodation_assignment_mode'=>$mode];
    $preflight=Shared_Product_Rate_Generation::prepare($input);
    $assert(is_wp_error($preflight)&&
        $preflight->get_error_code()==='rate_generator_package_stage_source_incomplete',
        'native generator cannot bypass stage source through '.$mode);
    if(is_wp_error($preflight)&&
        $preflight->get_error_code()==='rate_generator_package_stage_source_incomplete') {
        $actual=Shared_Product_Rate_Generation::create($input);
        $assert(is_wp_error($actual)&&
            $actual->get_error_code()==='rate_generator_package_stage_source_incomplete',
            'native create must reject before insert: '.$mode);
        $observed['direct_generator_rejections'][$mode]=$actual->get_error_code();
    }
}
$preview=MAD4B_Rate_Planner::plan([
    'tour_ids'=>[$package],'from'=>'2027-02-01','to'=>'2027-03-31',
    'locale_mode'=>'inherit','auto_recheck'=>false,'accommodation'=>'exclude']);
$assert(is_array($preview),'Native Rate Planner preview returns a plan');
if(is_array($preview)){
    $assert(count($preview['candidates'])>=2,'at least two canonical package variants');
    foreach($preview['candidates'] as $row){
        $assert(empty($row['input']),'planner does not supply a Rate input when guard fails');
        $assert(!empty($row['package_stage_review']['applies']) &&
            $row['package_stage_review']['ready']===false,
            'planner retains exact native stage blocker receipt');
        $assert(in_array('package_stage_source_incomplete',$row['reasons'],true),
            'planner reports missing package-stage source');
        $observed['plan_rejections'][]=[
            'package_term_id'=>$row['package_id'],
            'rate_prepared'=>!empty($row['input']),
            'stage_blocker_count'=>count($row['package_stage_review']['blockers'])
        ];
    }
}
// Non-Packages must not be blocked by the new Package-specific contract.
$not=$wayfa?60579:64850;
$assert(!$guard::applies($not),'ordinary Tour is not a Package');
$ordinary=$guard::assertReady($not,0,'2027-02-01','2027-03-31');
$assert(is_array($ordinary)&&$ordinary['applies']===false &&
    $ordinary['ready']===true,'non-Package guard is a no-op');
$ordinaryPreview=Shared_Product_Rate_Generation::prepare([
    'kind'=>'tour','parent_id'=>$not,'amount'=>'100.00','currency'=>'USD',
    'price_unit'=>'per_person','valid_from'=>'2027-02-01',
    'valid_to'=>'2027-03-31','accommodation_assignment_mode'=>'manual']);
$assert(!is_wp_error($ordinaryPreview) ||
    $ordinaryPreview->get_error_code()!=='rate_generator_package_stage_source_incomplete',
    'nonpackage generator unaffected');
$observed['nonpackage_preflight']=[
    'tour'=>$not,'status'=>is_wp_error($ordinaryPreview)?
      $ordinaryPreview->get_error_code():'prepared_without_write'];
$after=$rateCount();
$assert($before===$after,'all previews and rejected writes preserve Rate CPT count');
if($fail)WP_CLI::error(wp_json_encode([
    'site'=>$site,'checks'=>$checks,'failure_count'=>count($fail),
    'failed'=>$fail,'rate_count_before'=>$before,'rate_count_after'=>$after,
    'observed'=>$observed],JSON_UNESCAPED_SLASHES));
WP_CLI::line(wp_json_encode([
    'site'=>$site,'contract'=>'mad4b.package-rate-native-guard-live-smoke.v1',
    'checks_passed'=>$checks,'checks_failed'=>0,
    'rate_count_before'=>$before,'rate_count_after'=>$after,
    'no_real_rate_created'=>true,'no_policy_or_source_data_changed'=>true,
    'observed'=>$observed
],JSON_UNESCAPED_SLASHES));

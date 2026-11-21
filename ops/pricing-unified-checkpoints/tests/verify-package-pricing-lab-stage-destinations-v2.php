<?php
/**
 * Read-only invariants for stage-specific Package Pricing Lab destinations.
 * Execute in the live WP-CLI context using an authorized pricing administrator.
 * Does not create or change any JetEngine metadata, Rates, or source itineraries.
 */
defined('ABSPATH') || exit;
if(!defined('WP_CLI')||!WP_CLI)return;
$class='MAD4B_Package_Pricing_Lab_Readiness_V1';
if(!class_exists($class,false))WP_CLI::error('Destination-aware Package Lab not loaded');
$assertions=0;$fail=[];
$ok=static function(bool $condition,string $message)use(&$assertions,&$fail){
    $assertions++;
    if(!$condition)$fail[]=$message;
};
$host=home_url();
$source=str_contains($host,'tours.dev.mad4b.com');
$wayfa=str_contains($host,'wayfatours.com');
$ok($wayfa||$source,'expected company');
$ok(current_user_can('commerce_manage_pricing'),'authorized pricing user');
$ids=array_column($class::tierIds(),'id');
$ok(count($ids)===3,'native Silver Gold Diamond');
if($wayfa){
    $pkg=[57562,60437,60439,60441,60443,60623,60627,60629];
    $observed=['tier_previews'=>0,'stages'=>0,'hotel_stages'=>0,
        'candidates'=>0,'unique_props'=>[],'unique_units'=>[],
        'excluded'=>0,'night_review'=>0,'night_conflict'=>0,
        'repeat_cairo_pairs'=>0,'scope_match_errors'=>0];
    $ok(array_column($class::packages(),'id')===$pkg,'eight canonical packages unchanged');
    foreach($pkg as $tour){
        foreach($ids as $tier){
            $x=$class::inspect($tour,(int)$tier);
            $ok(is_array($x),'readable tour/tier '.$tour.'/'.$tier);
            if(!is_array($x))continue;
            $ok($x['supplier_cost_total']===null &&
                $x['generated_rate_ready']===false &&
                $x['checkout_ready']===false,
                'read-only/no fabricated sellability '.$tour.'/'.$tier);
            $observed['tier_previews']++;
            $observed['stages']+=count($x['stages']);
            $observed['hotel_stages']+=(int)$x['summary']['hotel_or_resort_stages'];
            $observed['candidates']+=(int)$x['summary']['candidate_mentions'];
            $observed['excluded']+=(int)$x['summary']['destination_excluded_candidates'];
            $observed['night_review']+=(int)$x['summary']['hotel_stages_requiring_itinerary_review'];
            $observed['night_conflict']+=(int)$x['summary']['hotel_stages_with_explicit_itinerary_conflict'];
            $hotelStages=[];
            foreach($x['stages'] as $stage){
                $ok($stage['stage_selection_independent']===true &&
                    $stage['price_must_resolve_for_stage_dates']===true,
                    'stage price isolation contract');
                $ok($stage['stage_instance_key']===
                    hash('sha256',wp_json_encode([
                        $tour,$tier,$stage['key'],$stage['day_start'],
                        $stage['nights'],$stage['destination']])),
                    'stable stage key for identity/date/destination');
                $ok($stage['departure_day_number']===$stage['day_start']+$stage['nights'],
                    'stage checkout day boundary');
                if(in_array($stage['kind'],['hotel','resort'],true))
                    $hotelStages[]=$stage;
                foreach($stage['candidates'] as $p){
                    $property=(int)$p['id'];
                    $observed['unique_props'][$property]=true;
                    $scope=$stage['destination_scope'];
                    $decision=$class::propertyEligibility($property,(int)$tier,
                        $scope,$stage['kind']);
                    if(!$decision['eligible'])$observed['scope_match_errors']++;
                    $ok($decision['eligible'],'only Property in stage destination AND tier');
                    foreach($p['units'] as $unit){
                        $observed['unique_units'][(int)$unit['id']]=true;
                        $ok($unit['source_relation_match']===true,
                            'real JetEngine Property-Unit Relation 223');
                    }
                }
                if($stage['kind']==='cruise'||$stage['kind']==='sleeper_train')
                    $ok($stage['candidates']===[],
                        'nonhotel stay does not inherit hotel pool');
            }
            $cairo=array_values(array_filter($hotelStages,
                static fn($st)=>$st['destination']==='cairo'));
            $ok(count($cairo)===2,'separate arrival and return Cairo stages');
            if(count($cairo)===2){
                $observed['repeat_cairo_pairs']++;
                $ok($cairo[0]['key']!==$cairo[1]['key'] &&
                    $cairo[0]['stage_instance_key']!==$cairo[1]['stage_instance_key'] &&
                    $cairo[0]['day_start']!==$cairo[1]['day_start'],
                    'return to Cairo has independent date and stage identity');
                $ok($cairo[0]['same_destination_revisit'] &&
                    $cairo[1]['same_destination_revisit'],
                    'returning-city indicator');
            }
        }
    }
    $ok($observed['tier_previews']===24,'24 package/tier previews');
    $ok($observed['stages']===84,'original 28 stages x 3 tiers');
    $ok($observed['hotel_stages']===57,'19 hotel stages x 3 tiers');
    $ok($observed['candidates']===288,'all source-eligible hotel suggestions preserved');
    $ok(count($observed['unique_props'])===18,'all 18 existing candidate Properties preserved');
    $ok(count($observed['unique_units'])===134,'all 134 existing Units preserved');
    $ok($observed['excluded']===0,'no false rejection of valid current hotel candidates');
    $ok($observed['scope_match_errors']===0,'no wrong-destination or wrong-tier candidate');
    $ok($observed['repeat_cairo_pairs']===24,'two separate Cairo stays in each tier');

    // Negative controls: source data is not written. We intentionally pass
    // the wrong Property into the destination guard.
    $cairo=$class::stageDestinationScope(57562,[
        'destination_key'=>'cairo','lodging_kind'=>'hotel']);
    $luxor=$class::stageDestinationScope(60627,[
        'destination_key'=>'luxor','lodging_kind'=>'hotel']);
    $aswan=$class::stageDestinationScope(60437,[
        'destination_key'=>'aswan','lodging_kind'=>'hotel']);
    $sharm=$class::stageDestinationScope(60629,[
        'destination_key'=>'sharm','lodging_kind'=>'resort']);
    $ok(in_array('giza',$cairo['accepted_slugs'],true) &&
        $cairo['metro_exception']==='source_explicit_cairo_and_giza',
        'Cairo/Giza metro inclusion is explicitly sourced, not global');
    $ok(in_array('aswan-gov',$aswan['accepted_slugs'],true),
        'Aswan stage maps to canonical destination term');
    $ok(in_array('sharm-el-sheikh',$sharm['accepted_slugs'],true),
        'Sharm stage maps to canonical destination term');
    $ok($class::propertyEligibility(36197,(int)$ids[0],
        $cairo,'hotel')['eligible'],'Silver Cairo native Hotel accepted');
    $ok($class::propertyEligibility(36475,(int)$ids[0],
        $cairo,'hotel')['eligible'],'source-backed Giza accepted for Cairo metro');
    $ok($class::propertyEligibility(36197,(int)$ids[0],
        $luxor,'hotel')['reason']==='property_destination_mismatch',
        'Cairo Hotel rejected from Luxor');
    $ok($class::propertyEligibility(36197,(int)$ids[0],
        $aswan,'hotel')['reason']==='property_destination_mismatch',
        'Cairo Hotel rejected from Aswan');
    $ok($class::propertyEligibility(36197,(int)$ids[0],
        $sharm,'resort')['reason']==='property_destination_mismatch',
        'Cairo Hotel rejected from Sharm');
    $ok($class::propertyEligibility(36197,(int)$ids[1],
        $cairo,'hotel')['reason']==='property_tier_mismatch',
        'Silver Hotel rejected from Gold');
    $ok($class::propertyEligibility(36197,(int)$ids[0],
        $cairo,'cruise')['reason']==='non_hotel_stage_requires_component_provider',
        'hotel cannot be used to price a Nile Cruise');
    $cairoWrong=$class::itineraryNightEvidence(60627,[
        'source_day_start'=>3,'nights'=>1,
        'lodging_kind'=>'hotel','destination_key'=>'cairo']);
    $luxorActual=$class::itineraryNightEvidence(60627,[
        'source_day_start'=>3,'nights'=>1,
        'lodging_kind'=>'hotel','destination_key'=>'luxor']);
    $ok($cairoWrong['status']==='itinerary_destination_conflict',
        'day 3 explicit Luxor hotel overnight conflicts with Cairo stage');
    $ok($class::stageSourceAllowsCandidates($cairoWrong)===false,
        'explicit itinerary conflict blocks candidate display');
    $ok($class::stageSourceAllowsCandidates($luxorActual)===true,
        'verified overnight stage permits editorial candidates');
    $overflow=$class::itineraryNightEvidence(60627,[
        'source_day_start'=>5,'nights'=>3,
        'lodging_kind'=>'hotel','destination_key'=>'cairo']);
    $ok($overflow['status']==='invalid_stage_night_geometry' &&
        $class::stageSourceAllowsCandidates($overflow)===false,
        'overlong stage beyond itinerary days blocks candidates');
    $ok($luxorActual['status']==='explicit_overnights_matched',
        'day 3 Luxor overnight source match');
    $unconfirmed=$class::itineraryNightEvidence(57562,[
        'source_day_start'=>1,'nights'=>3,
        'lodging_kind'=>'hotel','destination_key'=>'cairo']);
    $ok($unconfirmed['requires_editor_review'] &&
        $unconfirmed['status']==='missing_explicit_overnight_evidence',
        'no invented overnight proof from Cairo sightseeing');
    $observed['unique_props']=count($observed['unique_props']);
    $observed['unique_units']=count($observed['unique_units']);
}else{
    $ok($class::packages()===[],'source has no saved package source plan at this checkpoint');
    $scope=$class::stageDestinationScope(60629,[
        'destination_key'=>'luxor','lodging_kind'=>'hotel']);
    $ok($scope['status']==='mapped','generalized exact destination mapping on source');
    $observed=['source_no_plan'=>true,'scope'=>$scope];
}
if($fail)WP_CLI::error(wp_json_encode(['failed'=>$fail,'passed'=>$assertions-count($fail),
    'total'=>$assertions,'observed'=>$observed],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
WP_CLI::line(wp_json_encode([
    'schema'=>'mad4b.package-lab-stage-destination-smoke.v2',
    'site'=>home_url(),'passed'=>$assertions,'failed'=>0,
    'write_performed'=>false,'rate_generated'=>false,
    'observed'=>$observed
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

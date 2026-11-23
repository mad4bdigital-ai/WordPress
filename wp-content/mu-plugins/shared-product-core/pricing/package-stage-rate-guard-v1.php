<?php
/**
 * MAD4B Package Tour Rate Stage Guard v1.
 *
 * Native shared Tour Rate generation source authority. A Package Rate may not
 * be generated from a tour-wide Property pool or a retail seed while its
 * individual overnight components lack destination/tier-locked source proof.
 * A read-only review never writes postmeta, creates Rates or approves prices.
 */
defined('ABSPATH') || exit;
// Source/release parity marker for seasonal eligibility integration.
if (!defined('MAD4B_PACKAGE_STAGE_SEASONAL_GUARD_V1'))
    define('MAD4B_PACKAGE_STAGE_SEASONAL_GUARD_V1', true);
if (!class_exists('MAD4B_Package_Stage_Rate_Guard_V1', false)) {
final class MAD4B_Package_Stage_Rate_Guard_V1 {
    public const SOURCE = '_mad4b_package_accommodation_source_plan_v1';
    public const BINDINGS = '_mad4b_package_stay_verified_room_program_links_v1';
    public const CONTRACT = 'mad4b.package-rate-stage-guard.v1';

    /**
     * Cost SOURCE scope, not a selling quote. The original Property Rate CPT
     * supports both Unit-specific and Property-wide rows, distinguished by
     * the native explicit scope and related Unit relation.
     */
    public static function costRateScope(int $rate,array $receipt,array $selectedUnits):array {
        if(get_post_type($rate)!=='property-rates')
            return ['valid'=>false,'scope'=>null,'reason'=>'rate_post_type_invalid'];
        $linked=(int)get_post_meta($rate,'prop_related_unit_id',true);
        $kind=(string)get_post_meta($rate,'mad4b_rate_scope_v1',true);
        $receiptUnit=(int)($receipt['unit_id']??0);
        $selected=array_values(array_unique(array_map('absint',$selectedUnits)));
        if($linked>0){
            if(!in_array($kind,['','unit'],true)||
                $receiptUnit!==$linked||!in_array($linked,$selected,true))
                return ['valid'=>false,'scope'=>'unit_rate',
                    'reason'=>'unit_cost_rate_relation_or_scope_invalid'];
            return ['valid'=>true,'scope'=>'unit_rate',
                'reason'=>'unit_cost_reference_selected'];
        }
        if($kind==='property'&&$receiptUnit===0)
            return ['valid'=>true,'scope'=>'property_rate',
                'reason'=>'property_standard_cost_reference_selected',
                'requires_native_unit_allocation'=>false,
                'requires_independent_party_and_receipt_approval'=>true];
        return ['valid'=>false,'scope'=>null,
            'reason'=>'property_cost_rate_scope_not_explicit'];
    }
    /**
     * Native commercial source IDs are English. WPML can replace
     * term-slug filters by the admin interface locale, falsely treating
     * an existing package as non-package, or mismatching destination IDs.
     * Hold the original source locale only during this internal read.
     */
    private static function withSourceLocale(callable $fn) {
        $active=apply_filters('wpml_current_language',null);
        $default=apply_filters('wpml_default_language',null);
        if(!is_string($active)||$active===''||
           !is_string($default)||$default===''||$active===$default)
            return $fn();
        do_action('wpml_switch_language',$default);
        try {return $fn();}
        finally {do_action('wpml_switch_language',$active);}
    }
    public static function canonicalTour(int $tour): int {
        if (get_post_type($tour) !== 'tours-and-activities') return 0;
        $en = (int) apply_filters('wpml_object_id', $tour, 'tours-and-activities', false, 'en');
        return $en > 0 && get_post_type($en) === 'tours-and-activities' ? $en : $tour;
    }
    public static function applies(int $tour): bool {
        return self::withSourceLocale(static fn()=>self::appliesInSourceLocale($tour));
    }
    private static function appliesInSourceLocale(int $tour): bool {
        $source = self::canonicalTour($tour);
        if (!$source) return false;
        $terms = wp_get_object_terms($source, 'tour-types', ['fields'=>'slugs']);
        return is_array($terms) && in_array('packages', $terms, true);
    }
    public static function canonicalTier(int $tier): int {
        if ($tier < 1 || !(get_term($tier, 'package_levels') instanceof WP_Term)) return 0;
        $en = (int) apply_filters('wpml_object_id', $tier, 'package_levels', false, 'en');
        return $en > 0 && get_term($en, 'package_levels') instanceof WP_Term ? $en : $tier;
    }
    /**
     * Exact destination terms, not Tour sightseeing tags, city name strings
     * or generic Egypt-wide taxonomy parents.
     */
    public static function stageScope(int $tour, array $stage): array {
        return self::withSourceLocale(static fn()=>self::stageScopeInSourceLocale($tour,$stage));
    }
    private static function stageScopeInSourceLocale(int $tour, array $stage): array {
        $source = self::canonicalTour($tour);
        $key = sanitize_key((string) ($stage['destination_key'] ?? ''));
        $map = [
            'cairo'=>['cairo'],
            'aswan'=>['aswan-gov','aswan-city'],
            'luxor'=>['luxor'],
            'sharm'=>['sharm-el-sheikh']
        ];
        $slugs = $map[$key] ?? ($key ? [$key] : []);
        $metro = false;
        if ($key === 'cairo' && $source > 0) {
            $discovery = get_post_meta($source, '_mad4b_package_hotel_candidate_discovery_v1', true);
            $city = array_map('sanitize_key', (array) (is_array($discovery) ? ($discovery['city_scope'] ?? []) : []));
            $ids = array_map('absint', (array) (is_array($discovery) ? ($discovery['source_classifications']['en'] ?? []) : []));
            $c = get_term_by('slug', 'cairo', 'destinations');
            $g = get_term_by('slug', 'giza', 'destinations');
            if ($c instanceof WP_Term && $g instanceof WP_Term &&
                in_array('cairo', $city, true) && in_array('giza', $city, true) &&
                in_array((int) $c->term_id, $ids, true) &&
                in_array((int) $g->term_id, $ids, true)) {
                $slugs[] = 'giza'; $metro = true;
            }
        }
        $termIds = []; $accepted = [];
        foreach (array_unique($slugs) as $slug) {
            $t = get_term_by('slug', $slug, 'destinations');
            if (!$t instanceof WP_Term) continue;
            $termIds[(int) $t->term_id] = (int) $t->term_id;
            $accepted[$slug] = $slug;
        }
        return [
            'destination'=>$key, 'accepted_term_ids'=>array_values($termIds),
            'accepted_slugs'=>array_values($accepted),
            'metro_exception'=>$metro ? 'source_explicit_cairo_and_giza' : null,
            'status'=>$termIds ? 'mapped' : 'unmapped'
        ];
    }
    public static function propertyEligibility(int $property, int $tier, array $scope,
        string $kind): array {
        return self::withSourceLocale(static fn()=>
            self::propertyEligibilityInSourceLocale($property,$tier,$scope,$kind));
    }
    private static function propertyEligibilityInSourceLocale(int $property, int $tier, array $scope,
        string $kind): array {
        if (get_post_type($property) !== 'properties')
            return ['eligible'=>false,'reason'=>'invalid_property_type'];
        if (get_post_status($property) !== 'publish')
            return ['eligible'=>false,'reason'=>'property_not_published'];
        if (!in_array($kind, ['hotel','resort'], true))
            return ['eligible'=>false,'reason'=>'non_hotel_stage_requires_component_provider'];
        if (($scope['status'] ?? '') !== 'mapped' || !$scope['accepted_term_ids'])
            return ['eligible'=>false,'reason'=>'stage_destination_unmapped'];
        $actual = wp_get_object_terms($property, 'destinations', ['fields'=>'ids']);
        if (is_wp_error($actual) || !is_array($actual) || !$actual)
            return ['eligible'=>false,'reason'=>'property_destination_missing'];
        $actual=array_map('intval',$actual);
        if (!array_intersect($actual, $scope['accepted_term_ids']))
            return ['eligible'=>false,'reason'=>'property_destination_mismatch'];
        if (($scope['destination'] ?? '') === 'aswan') {
            $abu = get_term_by('slug', 'abu-simbel-temples', 'destinations');
            if ($abu instanceof WP_Term && in_array((int) $abu->term_id, $actual, true))
                return ['eligible'=>false,'reason'=>'property_in_abu_simbel_not_aswan_city'];
            $city=get_term_by('slug','aswan-city','destinations');
            if(!$city instanceof WP_Term ||
                !in_array((int)$city->term_id,$actual,true))
                return ['eligible'=>false,'reason'=>'aswan_city_location_unverified'];
        }
        $levels = wp_get_object_terms($property, 'package_levels', ['fields'=>'ids']);
        if (is_wp_error($levels) || !is_array($levels) ||
            !in_array($tier, array_map([self::class,'canonicalTier'], $levels), true))
            return ['eligible'=>false,'reason'=>'property_tier_mismatch'];
        $types = wp_get_object_terms($property, 'property-types', ['fields'=>'slugs']);
        if (is_wp_error($types) || !is_array($types) ||
            !array_intersect($types, ['hotel','resort']))
            return ['eligible'=>false,'reason'=>'property_kind_mismatch'];
        return ['eligible'=>true,'reason'=>'destination_tier_property_matched'];
    }
    /**
     * Explicit overnight text is evidence only. Sightseeing city and travel
     * direction are never sufficient to infer a hotel overnight.
     */
    public static function itineraryNightEvidence(int $tour, array $stage): array {
        $source = self::canonicalTour($tour);
        $kind = sanitize_key((string) ($stage['lodging_kind'] ?? ''));
        $destination = sanitize_key((string) ($stage['destination_key'] ?? ''));
        $start = (int) ($stage['source_day_start'] ?? 0);
        $nights = (int) ($stage['nights'] ?? 0);
        $out = ['status'=>'not_applicable','night_days'=>[],'explicit_matching_nights'=>0,
            'explicit_conflicting_nights'=>0,'unconfirmed_nights'=>0,
            'source'=>'tour.itinerary_item','requires_editor_review'=>false];
        if (!in_array($kind, ['hotel','resort'], true)) return $out;
        $raw = get_post_meta($source, 'itinerary_item', true);
        $days = is_array($raw) ? array_values($raw) : [];
        if ($start < 1 || $nights < 1 || $nights > 30 || !$days ||
            $start + $nights - 1 > count($days)) {
            $out['status'] = 'invalid_stage_night_geometry';
            $out['requires_editor_review'] = true; return $out;
        }
        for ($d = $start; $d < $start + $nights; $d++) {
            $entry = $days[$d-1] ?? [];
            $title = html_entity_decode(wp_strip_all_tags(
                (string) (is_array($entry) ? ($entry['title'] ?? '') : '')), ENT_QUOTES, 'UTF-8');
            $body = html_entity_decode(wp_strip_all_tags(
                (string) (is_array($entry) ? ($entry['description'] ?? '') : '')), ENT_QUOTES, 'UTF-8');
            $observed = [];
            if (preg_match_all('/\bovernight\s+(?:stay\s+)?in\s+(cairo|giza|luxor|aswan|sharm(?:\s+el\s+sheikh)?|red\s+sea)\b/iu',
                $body, $hits)) {
                foreach ($hits[1] as $city) {
                    $city = preg_replace('/\s+/u', ' ', strtolower(trim($city)));
                    $city = match ($city) {
                        'sharm el sheikh'=>'sharm',
                        'red sea'=>'red_sea_unspecified',
                        default=>$city
                    };
                    $observed[$city] = true;
                }
            }
            $names = array_keys($observed);
            $expected = $destination === 'cairo' ? ['cairo','giza'] : [$destination];
            $unexpected = array_diff($names, array_merge($expected,['red_sea_unspecified']));
            $matched = array_intersect($names, $expected);
            $result = $unexpected ? 'conflict' : ($matched ? 'match' : 'unconfirmed');
            if ($result === 'conflict') $out['explicit_conflicting_nights']++;
            elseif ($result === 'match') $out['explicit_matching_nights']++;
            else $out['unconfirmed_nights']++;
            $out['night_days'][] = ['day'=>$d,
                'source_title'=>mb_substr($title, 0, 150),
                'observed_overnight_destinations'=>$names,'result'=>$result];
        }
        if ($out['explicit_conflicting_nights'])
            $out['status'] = 'itinerary_destination_conflict';
        elseif ($out['explicit_matching_nights'] === $nights)
            $out['status'] = 'explicit_overnights_matched';
        elseif ($out['explicit_matching_nights'])
            $out['status'] = 'partially_matched_requires_review';
        else
            $out['status'] = 'missing_explicit_overnight_evidence';
        $out['requires_editor_review'] = $out['status'] !== 'explicit_overnights_matched';
        return $out;
    }
    /** Pure readback. No caller-supplied candidate IDs or approval switches. */
    public static function review(int $tour,int $tier,string $from='',string $to=''): array {
        return self::withSourceLocale(static fn()=>
            self::reviewInSourceLocale($tour,$tier,$from,$to));
    }
    private static function reviewInSourceLocale(int $tour,int $tier,string $from='',string $to=''): array {
        $parent = self::canonicalTour($tour);
        $out = ['contract'=>self::CONTRACT, 'applies'=>false,
            'tour_id'=>$tour,'source_tour_id'=>$parent,'tier_id'=>$tier,
            'ready'=>true,'checkout_ready'=>false,'cost_total'=>null,
            'source_digest'=>null,'stages'=>[],'blockers'=>[], 'created_rate'=>false];
        if (!self::applies($tour)) return $out;
        $out['applies'] = true; $out['ready'] = false;
        $seen = [];
        $block = static function(string $code,string $key='') use (&$out,&$seen):void {
            $id=$key!==''?$key.':'.$code:$code;
            if(isset($seen[$id]))return;
            $seen[$id]=true;$out['blockers'][]=$id;
        };
        $tier = self::canonicalTier($tier);
        $out['tier_id'] = $tier;
        if (!$tier) $block('package_level_required');
        // Operations' explicit Tour assignment always outranks Company
        // defaults; Company Policy is inherited only for an unassigned Tour.
        if(class_exists('MAD4B_Company_Package_Levels_V1',false)){
            $operational=MAD4B_Company_Package_Levels_V1::resolve($parent);
            $out['package_tier_source']=$operational['source'];
            $out['effective_tier_ids']=$operational['effective_tier_ids'];
            $out['company_tier_policy_rule']=$operational['company_rule_id'];
            if(!$tier || !in_array($tier,$operational['effective_tier_ids'],true))
                $block($operational['source']==='unassigned_company_policy_missing'
                    ?'company_package_default_levels_not_configured'
                    :'tour_package_level_not_in_effective_operational_assignment');
        }else $block('package_level_operational_strategy_provider_unavailable');
        $plan = get_post_meta($parent,self::SOURCE,true);
        $binding = get_post_meta($parent,self::BINDINGS,true);
        if (!is_array($plan) || ($plan['schema']??'')!=='mad4b.package-accommodation-source-plan.v1' ||
            !is_array($plan['stages']??null) || !$plan['stages']) {
            $block('source_accommodation_stage_plan_missing');
            return $out;
        }
        if(!is_array($binding)||($binding['schema']??'')!=='mad4b.package-stay-unit-program-bindings.v1'||
            (int)($binding['tour_id']??0)!==$parent ||
            !is_array($binding['bindings']??null)) {
            $block('source_stage_bindings_missing');
            return $out;
        }
        $sourceHash = hash('sha256',wp_json_encode($plan,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        if (!hash_equals($sourceHash,(string)($binding['source_plan_sha256']??'')))
            $block('source_stage_bindings_stale');
        // An already-enrolled structured itinerary always outranks a stale
        // legacy accommodation plan. Never quote when its nights changed.
        $seed=get_post_meta($parent,'_mad4b_itinerary_overnight_seed_v1',true);
        if(is_array($seed)&&($seed['contract']??'')==='mad4b.itinerary-owner-source-plan-nightly-seed.v1'){
            $structured=get_post_meta($parent,'_mad4b_itinerary_overnight_projection_v1',true);
            $syncState=get_post_meta($parent,'_mad4b_itinerary_overnight_sync_state_v1',true);
            $currentItinerary=get_post_meta($parent,'itinerary_item',true);
            $currentDigest=hash('sha256',wp_json_encode($currentItinerary,
               JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            if(!is_array($structured) ||
                ($structured['contract']??'')!=='mad4b.itinerary-overnight-source.v1'||
                !hash_equals((string)($structured['source_digest']??''),$currentDigest))
                $block('structured_itinerary_source_digest_stale');
            elseif(!is_array($syncState)||
                 ($syncState['status']??'')!=='source_stage_geometry_matches')
                $block('structured_itinerary_stage_requalification_required');
            else{
                $projected=(array)($structured['stages']??[]);
                $currentStages=(array)($plan['stages']??[]);
                if(count($projected)!==count($currentStages))
                    $block('structured_itinerary_stage_geometry_drift');
                else foreach($projected as $i=>$span){
                    $old=$currentStages[$i]??[];
                    foreach(['stage_key','lodging_kind','destination_key','source_day_start','nights'] as $field){
                        if((string)($span[$field]??'')!==(string)($old[$field]??'')){
                            $block('structured_itinerary_stage_geometry_drift');break 2;
                        }
                    }
                }
            }
        }
        $out['source_digest'] = hash('sha256', wp_json_encode([
            'tour'=>$parent,'tier'=>$tier,'source'=>$sourceHash,
            'bindings'=>$binding,'valid_from'=>$from,'valid_to'=>$to
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $datesOK = $from!=='' && $to!=='' &&
            preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$from) &&
            preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$to) &&
            $from<=$to;
        if (!$datesOK) $block('travel_date_window_missing');
        $dayCount=(int)($plan['tour_days']??0);
        $nightCount=(int)($plan['tour_nights']??0);
        if($dayCount<2||$nightCount!==$dayCount-1||count($plan['stages'])>40)
            $block('source_tour_night_geometry_invalid');
        $byKey = [];
        foreach($binding['bindings'] as $b){
            if(!is_array($b))continue;
            $key=sanitize_key((string)($b['stage_key']??''));
            if(!$key || isset($byKey[$key])){$block('stage_binding_duplicate_or_invalid');continue;}
            $byKey[$key]=$b;
        }
        $stageKeys=[];$next=1;$nightSum=0;
        foreach($plan['stages'] as $original){
            if(!is_array($original)){$block('stage_record_invalid');continue;}
            $key=sanitize_key((string)($original['stage_key']??''));
            $kind=sanitize_key((string)($original['lodging_kind']??''));
            $begin=(int)($original['source_day_start']??0);
            $nights=(int)($original['nights']??0);
            $city=sanitize_key((string)($original['destination_key']??''));
            if(!$key||isset($stageKeys[$key])){$block('stage_duplicate_or_invalid',$key);continue;}
            $stageKeys[$key]=true;
            $stage=['key'=>$key,'kind'=>$kind,'destination'=>$city,
                'day_start'=>$begin,'nights'=>$nights,'eligible_property_ids'=>[],
                'selected_property_id'=>0,'selected_unit_ids'=>[],
                'blocked'=>[], 'source_type'=>'native_editorial_not_price_authority'];
            $flag=static function(string $code)use(&$stage,$block,$key):void{
                if(!in_array($code,$stage['blocked'],true))$stage['blocked'][]=$code;
                $block($code,$key);
            };
            $stage['supplier_resolution']=class_exists('MAD4B_Package_Company_Supplier_V1',false)
                ?MAD4B_Package_Company_Supplier_V1::resolve($parent,$original,$byKey[$key]??[])
                :['supplier_mode'=>'unresolved','identity_resolved'=>false];
            if(empty($stage['supplier_resolution']['identity_resolved']))
                $flag('stage_supplier_explicit_assignment_unresolved');
            if($begin<1||$nights<1||$begin+$nights>$dayCount||
                $begin!==$next){$flag('stage_day_night_sequence_invalid');}
            $nightSum += $nights;
            $next = $begin + $nights;
            $saved=$byKey[$key]??null;
            if(!is_array($saved)){$flag('stage_source_binding_missing');$out['stages'][]=$stage;continue;}
            if(sanitize_key((string)($saved['lodging_kind']??''))!==$kind||
                (int)($saved['nights']??0)!==$nights||
                (int)($saved['source_day_start']??0)!==$begin)
                $flag('stage_geometry_binding_mismatch');
            if(in_array($kind,['hotel','resort'],true)){
                $scope=self::stageScope($parent,$original);
                $sourceEvidence=self::itineraryNightEvidence($parent,$original);
                $stage['scope']=$scope;
                $stage['itinerary_evidence']=$sourceEvidence['status'];
                if(in_array($sourceEvidence['status'],
                    ['invalid_stage_night_geometry','itinerary_destination_conflict'],true))
                    $flag('stage_itinerary_destination_conflict');
                elseif(!empty($sourceEvidence['requires_editor_review']))
                    // An unclear overnight remains an editorial candidate
                    // in Pricing Lab, but never authorizes a commercial Rate.
                    $flag('stage_itinerary_overnight_review_required');
                if($scope['status']!=='mapped')$flag('stage_destination_unmapped');
                $raw=(array)($saved['room_candidate_refs'][(string)$tier]??[]);
                $eligible=[];$links=[];$rejections=[];$seasonalExcluded=[];
                foreach($raw as $candidate) {
                    if(!is_array($candidate))continue;
                    $property=(int)($candidate['property_id_en']??0);
                    if(!$property)continue;
                    if(isset($links[$property]))continue;
                    $decision=self::propertyEligibility($property,$tier,$scope,$kind);
                    if($decision['eligible']&&class_exists('MAD4B_Package_Seasonal_Assignment_V1',false))
                        $decision=MAD4B_Package_Seasonal_Assignment_V1::decision(
                          $parent,$key,$tier,$property,$from,$to,$begin-1,$nights);
                    $links[$property]=(array)($candidate['unit_ids_en']??[]);
                    if(!$decision['eligible']) {
                        $rejections[]=['property_id'=>$property,'reason'=>$decision['reason']];
                        if(str_starts_with((string)$decision['reason'],'stage_property_season_')||
                           str_starts_with((string)$decision['reason'],'seasonal_')||
                           str_starts_with((string)$decision['reason'],'season_calendar_'))
                            $seasonalExcluded[$property]=$decision['reason'];
                        else $flag('stage_candidate_destination_or_tier_mismatch');
                        continue;
                    }
                    $eligible[$property]=array_values(array_unique(array_filter(array_map('absint',
                        (array)($candidate['unit_ids_en']??[])))));
                }
                // Also audit saved plan-only candidates, not just detailed
                // candidate refs. An invalid stored alternative is not valid
                // commercial evidence, even when a valid Property also exists.
                foreach((array)($original['hotel_alternatives_or_by_tier'][(string)$tier]??[]) as $pid){
                    $pid=(int)$pid;if(!$pid||isset($links[$pid]))continue;
                    $decision=self::propertyEligibility($pid,$tier,$scope,$kind);
                    if($decision['eligible']&&class_exists('MAD4B_Package_Seasonal_Assignment_V1',false))
                        $decision=MAD4B_Package_Seasonal_Assignment_V1::decision(
                          $parent,$key,$tier,$pid,$from,$to,$begin-1,$nights);
                    if(!$decision['eligible']){
                        $rejections[]=['property_id'=>$pid,'reason'=>$decision['reason']];
                        if(str_starts_with((string)$decision['reason'],'stage_property_season_')||
                           str_starts_with((string)$decision['reason'],'seasonal_')||
                           str_starts_with((string)$decision['reason'],'season_calendar_'))
                            $seasonalExcluded[$pid]=$decision['reason'];
                        else $flag('stage_candidate_destination_or_tier_mismatch');
                    }else $eligible[$pid]=[];
                }
                // Additive, source-validated editorial candidates. Saved selections,
                // rate receipts and original candidate refs remain unchanged.
                $dynamicIds=apply_filters('mad4b/package_stage_candidate_ids',
                    [],$parent,$tier,$original,$scope);
                $stage['dynamic_source_candidate_ids']=[];
                foreach(array_slice(array_unique(array_filter(array_map('absint',
                        is_array($dynamicIds)?$dynamicIds:[]))),0,150) as $pid){
                    if(isset($links[$pid])||isset($eligible[$pid])||
                       isset($seasonalExcluded[$pid]))continue;
                    $decision=self::propertyEligibility($pid,$tier,$scope,$kind);
                    if(($decision['eligible']??false)===true &&
                       class_exists('MAD4B_Package_Seasonal_Assignment_V1',false))
                        $decision=MAD4B_Package_Seasonal_Assignment_V1::decision(
                          $parent,$key,$tier,$pid,$from,$to,$begin-1,$nights);
                    if(($decision['eligible']??false)!==true)continue;
                    $eligible[$pid]=[];
                    $stage['dynamic_source_candidate_ids'][]=$pid;
                }
                $stage['eligible_property_ids']=array_keys($eligible);
                $stage['rejected_candidates']=$rejections;
                $stage['season_rejected_property_ids']=array_keys($seasonalExcluded);
                $stage['seasonal_assignment_source']='stage_tier_property_season_override_or_inherit';
                if(!$eligible){
                    $flag('stage_destination_has_no_candidate_property');
                    if($seasonalExcluded)$flag('stage_all_candidates_excluded_by_season_or_calendar');
                }
                $selected=(int)($saved['selected_property_id']??0);
                $stage['selected_property_id']=$selected;
                if(!$selected){$flag('stage_property_not_selected');}
                elseif(!isset($eligible[$selected]))$flag('stage_selected_property_not_eligible');
                $units=array_values(array_unique(array_filter(array_map('absint',
                    (array)($saved['bookable_unit_ids']??[])))));
                $stage['selected_unit_ids']=$units;
                // Units are optional: a Property-scoped Standard Rate is a native source.
                $stage['unit_selection_required']=false;
                $stage['property_standard_cost_fallback_allowed']=true;
                if($selected && $units){
                    global $wpdb;
                    $table=$wpdb->prefix.'jet_rel_default';
                    foreach($units as $unit){
                        if(get_post_type($unit)!=='units' ||
                            get_post_status($unit)!=='publish' ||
                            !in_array($unit,$eligible[$selected]??[],true)) {
                            $flag('stage_unit_not_in_original_property_candidate');continue;
                        }
                        $relation=(int)$wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$table} WHERE rel_id=%d AND parent_object_id=%d AND child_object_id=%d",
                            223,$selected,$unit));
                        $receipt=get_post_meta($unit,'_mad4b_room_source_receipt_v1',true);
                        if($relation<1||!is_array($receipt)||
                            (int)($receipt['property_id']??0)!==$selected)
                            $flag('stage_unit_property_relation_unverified');
                        if((int)get_post_meta($unit,'unit_max_occupancy',true)<1)
                            $flag('stage_unit_occupancy_missing');
                        if(!in_array((string)get_post_meta($unit,'unit_available',true),
                            ['1','true','yes','on'],true))
                            $flag('stage_unit_not_available');
                    }
                }
                $stage['cost_source_priority']=['unit_rate','property_rate'];
                $stage['cost_rate_scope']=null;
                $stage['cost_reference_checkout_authorized']=false;
                $receipt=$saved['supplier_cost_receipt']??null;
                if(!is_array($receipt)||($receipt['status']??'')!=='approved')
                    $flag('stage_supplier_cost_receipt_missing');
                else{
                    $rate=(int)($receipt['rate_id']??0);
                    $scope=self::costRateScope($rate,$receipt,$units);
                    $stage['cost_rate_scope']=$scope['scope']??null;
                    $stage['cost_rate_id']=$rate?:null;
                    $currency=(string)get_post_meta($rate,'currency',true);
                    $basis=(string)get_post_meta($rate,'prop_price_unit',true);
                    $board=(int)get_post_meta($rate,'prop_board_type',true);
                    $stop=(string)get_post_meta($rate,'stop_sale',true);
                    $qa=(string)get_post_meta($rate,'_mad4b_is_test_data',true);
                    $amount=get_post_meta($rate,'base_amount',true);
                    $proposal=get_post_meta($rate,'_mad4b_supplier_sheet_proposal_v1',true);
                    $proposalUnapproved=(string)get_post_meta($rate,'_mad4b_supplier_sheet_quote_unapproved_v1',true)==='1';
                    if(is_array($proposal) && (
                        empty($proposal['supplier_contract_verified'])||
                        empty($proposal['nightly_basis_verified'])||
                        empty($proposal['cost_receipt_approved'])||
                        empty($proposal['commercial_activation_allowed'])))
                        $proposalUnapproved=true;
                    $verified=$rate>0&&!$proposalUnapproved&&
                        !get_post_meta($rate,'_shared_generation_supplier_proposal',true)&&
                        ($scope['valid']??false)===true&&
                        get_post_status($rate)==='publish'&&
                        (int)get_post_meta($rate,'prop_related_property_id',true)===$selected&&
                        !get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true)&&
                        !get_post_meta($rate,'_qa_disallow_public_checkout',true)&&
                        !in_array(strtolower($qa),['1','true','yes','on'],true)&&
                        !preg_match('/\\bQA\\s+ONLY\\b/i',(string)get_the_title($rate))&&
                        (string)get_post_meta($rate,'mad4b_rate_price_kind_v1',true)==='cost'&&
                        is_numeric($amount)&&(float)$amount>0&&
                        preg_match('/^[A-Z]{3}$/D',$currency)&&
                        in_array($basis,['per_person','per_room_night','per_cabin_night',
                            'per_person_night','per_stay'],true)&&
                        $board>0&&get_term($board,'board-type') instanceof WP_Term&&
                        $stop==='0';
                    if(!$verified)
                        $flag('stage_supplier_rate_not_verified');
                    elseif($datesOK){
                        $offset=$begin-1;
                        $first=gmdate('Y-m-d',strtotime($from.' +'.$offset.' days UTC'));
                        $last=gmdate('Y-m-d',strtotime($to.' +'.($offset+$nights-1).' days UTC'));
                        $window=class_exists('MAD4B_Stay_Layered_Pricing_V1',false)
                          ?MAD4B_Stay_Layered_Pricing_V1::effectiveRateWindow($rate)
                          :['valid'=>false];
                        if(empty($window['valid'])||$window['start']>$first||
                           $window['end']<$last)
                            $flag('stage_supplier_rate_period_uncovered');
                    }
                    // Property-wide Standard needs no Unit, but also does NOT
                    // confer occupancy, availability or independently signed cost
                    // authority. This read-only tour/tier review lacks party size.
                    if(($scope['scope']??null)==='property_rate' && $verified){
                        $stage['cost_reference_status']='property_standard_preliminary_source_only';
                        $stage['unit_selection_required']=false;
                        $flag('stage_property_standard_party_cost_receipt_recheck_required');
                    }
                }
            }elseif($kind==='cruise'){
                // A vessel IS a Property; only reusing a different hotel is invalid.
                $vessel=(int)($saved['selected_vessel_id']??0);
                $selectedSource=(int)($saved['selected_property_id']??0);
                $stage['unit_selection_required']=false;
                if($selectedSource>0&&$selectedSource!==$vessel)
                    $flag('cruise_hotel_property_reuse_forbidden');
                $program=$saved['cruise_program']??null;
                if(!is_array($program)||
                    (int)($program['program_term_id_en']??0)<1||
                    (int)($program['nights']??0)!==$nights)
                    $flag('cruise_program_missing_or_duration_mismatch');
                if($vessel<1||
                    empty($program['explicit_supplier_sailing_confirmed'])||
                    empty($program['ship_inventory_verified']))
                    $flag('cruise_vessel_program_sailing_unverified');
                if(!is_array($saved['supplier_cost_receipt']??null)||
                    ($saved['supplier_cost_receipt']['status']??'')!=='approved')
                    $flag('cruise_supplier_cost_unverified');
                // The generic native hotel-stay repeater cannot represent a
                // cruise sailing and cabin. Require dedicated provider contract.
                $flag('cruise_rate_stage_native_provider_not_connected');
            }elseif($kind==='sleeper_train'){
                if((int)($saved['selected_property_id']??0)>0)
                    $flag('sleeper_train_hotel_property_reuse_forbidden');
                $flag('sleeper_train_rate_stage_native_provider_not_connected');
            }else{$flag('unknown_lodging_component_kind');}
            $out['stages'][]=$stage;
        }
        if($next!==$dayCount || $nightSum!==$nightCount)
            $block('source_stages_do_not_cover_all_nights');
        foreach(array_keys($byKey) as $key)if(!isset($stageKeys[$key]))
            $block('orphaned_stage_binding',$key);
        // Include the effective tenant supplier identity in the cost-composition
        // fingerprint, so a new external assignment invalidates an older self
        // price receipt even when the itinerary and stay plan are unchanged.
        $supplierProof=[];
        foreach($out['stages'] as $entry){
            $v=(array)($entry['supplier_resolution']??[]);
            $supplierProof[]=[(string)($entry['key']??''),
                (string)($v['supplier_mode']??''),
                (string)($v['supplier_id']??''),
                (string)($v['source']??'')];
        }
        if($supplierProof && $out['source_digest']!==null)
            $out['source_digest']=hash('sha256',wp_json_encode([
                'base_source_digest'=>$out['source_digest'],
                'component_supplier_identity'=>$supplierProof
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $out['supplier_default_policy']='company_self_unless_explicit_external_assigned';
        $out['ready']=!$out['blockers'];
        return $out;
    }
    public static function assertReady(int $tour,int $tier,string $from='',string $to=''): array|WP_Error {
        $review=self::review($tour,$tier,$from,$to);
        if(!$review['applies'] || $review['ready'])return $review;
        return new WP_Error('rate_generator_package_stage_source_incomplete',
            'Package stages must pass destination, tier, optional Unit, itinerary and signed cost-source review before a new Tour Rate can be generated.',
            ['contract'=>self::CONTRACT,'tour_id'=>$tour,'tier_id'=>$tier,
             'blockers'=>$review['blockers'],'source_digest'=>$review['source_digest']]);
    }
}
}

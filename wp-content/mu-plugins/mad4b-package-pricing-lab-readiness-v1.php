<?php
/**
 * Plugin Name: MAD4B Package Pricing Lab — Cost Readiness
 * Version: 1.0.0
 * Description: Conditional read-only per-Package/tier hotel, unit and supplier cost gap inventory alongside existing Pricing Lab strategy simulator.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/mad4b-package-stage-sourcing-v1.php';
if(!class_exists('MAD4B_Package_Pricing_Lab_Readiness_V1',false)){
final class MAD4B_Package_Pricing_Lab_Readiness_V1 {
    const AJAX='mad4b_package_pricing_lab_read_v1';
    const SOURCE='_mad4b_package_accommodation_source_plan_v1';
    const BINDINGS='_mad4b_package_stay_verified_room_program_links_v1';
    const TIERS=['silver-package'=>'Silver','gold-package'=>'Gold','diamond-package'=>'Diamond'];
    private static function allowed():bool {
        return current_user_can('commerce_manage_pricing') &&
            (current_user_can('manage_woocommerce') ||
            (current_user_can('mad4b_manage_company_operations') &&
            current_user_can('mad4b_work_company_tasks')));
    }
    public static function canonical(int $tour):int {
        if($tour<=0||get_post_type($tour)!=='tours-and-activities')return 0;
        $en=(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,'en');
        if($en>0 && get_post_type($en)==='tours-and-activities')return $en;
        return $tour;
    }
    private static function isPackage(int $tour):bool {
        $terms=wp_get_object_terms($tour,'tour-types',['fields'=>'slugs']);
        return is_array($terms)&&in_array('packages',$terms,true);
    }
    public static function packages():array {
        $ids=get_posts(['post_type'=>'tours-and-activities',
            'post_status'=>['publish','draft','pending'],
            'posts_per_page'=>160,'fields'=>'ids','suppress_filters'=>true,
            'meta_key'=>self::SOURCE,'meta_compare'=>'EXISTS',
            'orderby'=>'ID','order'=>'ASC']);
        $out=[];
        foreach($ids as $id){
            $tour=self::canonical((int)$id);
            if(!$tour||!self::isPackage($tour)||
                !current_user_can('edit_post',$tour)||isset($out[$tour]))continue;
            $plan=get_post_meta($tour,self::SOURCE,true);
            if(!is_array($plan)||!is_array($plan['stages']??null))continue;
            $out[$tour]=['id'=>$tour,
                'title'=>wp_strip_all_tags(html_entity_decode(get_the_title($tour),ENT_QUOTES,'UTF-8')),
                'day_count'=>(int)($plan['tour_days']??0),
                'night_count'=>(int)($plan['tour_nights']??0)];
        }
        return array_values($out);
    }
    public static function tierIds():array {
        $out=[];
        foreach(self::TIERS as $slug=>$name){
            $term=get_term_by('slug',$slug,'package_levels');
            if($term instanceof WP_Term)$out[]=['id'=>(int)$term->term_id,'label'=>$name,'slug'=>$slug];
        }
        return $out;
    }
    private static function editLink(int $post):?string {
        if($post<=0||!current_user_can('edit_post',$post))return null;
        $link=get_edit_post_link($post,'raw');
        return is_string($link)&&$link!==''?$link:null;
    }
    private static function publishedRateIndex(array $properties):array {
        if(!$properties)return [];
        global $wpdb;
        $ids=array_map('absint',array_keys($properties));
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $sql=$wpdb->prepare(
            "SELECT p.ID rate_id, CAST(pm.meta_value AS UNSIGNED) property_id
            FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
            WHERE p.post_type='property-rates' AND p.post_status='publish'
            AND pm.meta_key='prop_related_property_id'
            AND CAST(pm.meta_value AS UNSIGNED) IN ({$placeholders})",
            $ids
        );
        $out=[];
        foreach((array)$wpdb->get_results($sql,ARRAY_A) as $r){
            $pid=(int)$r['property_id'];$rate=(int)$r['rate_id'];
            if(!get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true) &&
                !get_post_meta($rate,'_qa_disallow_public_checkout',true))
                $out[$pid]['published'] = ($out[$pid]['published']??0)+1;
            $amount=get_post_meta($rate,'base_amount',true);
            if(!get_post_meta($rate,'_booking_experience_qa_pricing_fixture',true) &&
                !get_post_meta($rate,'_qa_disallow_public_checkout',true) &&
                is_numeric($amount)&&(float)$amount>0 &&
                (string)get_post_meta($rate,'mad4b_rate_price_kind_v1',true)==='cost')
                $out[$pid]['cost_entries']=($out[$pid]['cost_entries']??0)+1;
        }
        return $out;
    }
    private static function nativeRelationUnits(int $property,int $relId):array {
        global $wpdb;
        if($relId<=0)return [];
        $table=$wpdb->prefix.'jet_rel_default';
        $rows=$wpdb->get_col($wpdb->prepare(
            "SELECT child_object_id FROM {$table} WHERE rel_id=%d AND parent_object_id=%d",
            $relId,$property
        ));
        return array_fill_keys(array_map('intval',(array)$rows),true);
    }
    /**
     * Stage destination is the saved itinerary-derived destination, not a
     * package-wide hotel pool. The only supported metro exception (Cairo +
     * Giza) must be present in the tour's own sourced discovery evidence.
     *
     * Never accept generic country terms or arbitrary destination parents as
     * city evidence. New cities must be mapped to exact canonical term IDs.
     */
    public static function stageDestinationScope(int $tour,array $stage):array {
        $dest=sanitize_key((string)($stage['destination_key']??''));
        $terms=[];
        $slugs=[
            'cairo'=>['cairo'],
            'aswan'=>['aswan-gov','aswan-city'],
            'luxor'=>['luxor'],
            'sharm'=>['sharm-el-sheikh'],
        ];
        $aliases=$slugs[$dest]??[$dest];
        $metro=false;
        if($dest==='cairo'){
            $discovery=get_post_meta($tour,
                '_mad4b_package_hotel_candidate_discovery_v1',true);
            $cityScope=array_map('sanitize_key',
                (array)(is_array($discovery)?($discovery['city_scope']??[]):[]));
            $classified=array_map('absint',
                (array)(is_array($discovery)?($discovery['source_classifications']['en']??[]):[]));
            $c=get_term_by('slug','cairo','destinations');
            $g=get_term_by('slug','giza','destinations');
            if($c instanceof WP_Term && $g instanceof WP_Term &&
                in_array('cairo',$cityScope,true) &&
                in_array('giza',$cityScope,true) &&
                in_array((int)$c->term_id,$classified,true) &&
                in_array((int)$g->term_id,$classified,true)){
                $aliases[]='giza';
                $metro=true;
            }
        }
        foreach(array_unique($aliases) as $slug){
            $term=get_term_by('slug',$slug,'destinations');
            if($term instanceof WP_Term)$terms[(int)$term->term_id]=$slug;
        }
        return [
            'destination'=>$dest,'accepted_term_ids'=>array_keys($terms),
            'accepted_slugs'=>array_values($terms),
            'metro_exception'=>$metro?'source_explicit_cairo_and_giza':null,
            'status'=>$terms?'mapped':'unmapped',
            'contract'=>'exact_stage_destination_terms_only'
        ];
    }
    /**
     * Independent readback per candidate. Destination and tier must BOTH
     * match, so a Cairo hotel cannot appear in Aswan/Luxor/Sharm even when a
     * saved proposal mistakenly lists it under the wrong stage.
     */
    public static function propertyEligibility(int $property,int $tierId,
        array $scope,string $kind):array {
        $type=get_post_type($property);
        if($type!=='properties')
            return ['eligible'=>false,'reason'=>'invalid_property_type'];
        if(get_post_status($property)!=='publish')
            return ['eligible'=>false,'reason'=>'property_not_published'];
        if(!in_array($kind,['hotel','resort'],true))
            return ['eligible'=>false,'reason'=>'non_hotel_stage_requires_component_provider'];
        if(($scope['status']??'unmapped')!=='mapped' ||
            empty($scope['accepted_term_ids']))
            return ['eligible'=>false,'reason'=>'stage_destination_unmapped'];
        $actual=wp_get_object_terms($property,'destinations',['fields'=>'ids']);
        if(is_wp_error($actual)||!is_array($actual)||!$actual)
            return ['eligible'=>false,'reason'=>'property_destination_missing'];
        $actual=array_map('intval',$actual);
        if(!array_intersect($actual,array_map('intval',$scope['accepted_term_ids'])))
            return ['eligible'=>false,'reason'=>'property_destination_mismatch'];
        if(($scope['destination']??'')==='aswan'){
            // A governorate-wide tag does NOT make an Abu Simbel hotel
            // an eligible Aswan-city overnight. Enforce the specific
            // excluded locality, even when the parent governorate matches.
            $abu=get_term_by('slug','abu-simbel-temples','destinations');
            if($abu instanceof WP_Term && in_array((int)$abu->term_id,$actual,true))
                return ['eligible'=>false,'reason'=>'property_in_abu_simbel_not_aswan_city'];
            // Aswan governorate is broader than the actual stay city.
            $city=get_term_by('slug','aswan-city','destinations');
            if(!$city instanceof WP_Term ||
                !in_array((int)$city->term_id,$actual,true))
                return ['eligible'=>false,'reason'=>'aswan_city_location_unverified'];
        }
        $tiers=wp_get_object_terms($property,'package_levels',['fields'=>'ids']);
        if(is_wp_error($tiers)||!is_array($tiers)||
            !in_array($tierId,array_map('intval',$tiers),true))
            return ['eligible'=>false,'reason'=>'property_tier_mismatch'];
        $kinds=wp_get_object_terms($property,'property-types',['fields'=>'slugs']);
        if(is_wp_error($kinds)||!is_array($kinds)||
            !array_intersect($kinds,['hotel','resort']))
            return ['eligible'=>false,'reason'=>'property_kind_mismatch'];
        return ['eligible'=>true,'reason'=>'destination_tier_property_matched'];
    }
    /**
     * Source-to-plan parity is independent of Property filtering.
     * A day mentioning a city in sightseeing is not proof of accommodation.
     * Only explicit overnight-IN-city text is accepted as positive evidence;
     * missing text stays "review_needed", not a fabricated mismatch or match.
     */
    /** Blocking errors prevent source candidate eligibility; absence of explicit
     * overnight evidence is a review task and does not hide editorial options. */
    public static function stageSourceAllowsCandidates(array $evidence):bool {
        return !in_array((string)($evidence['status']??''),
            ['itinerary_destination_conflict','invalid_stage_night_geometry'],true);
    }
    public static function itineraryNightEvidence(int $tour,array $stage):array {
        $start=(int)($stage['source_day_start']??0);
        $nights=(int)($stage['nights']??0);
        $kind=sanitize_key((string)($stage['lodging_kind']??''));
        $destination=sanitize_key((string)($stage['destination_key']??''));
        $out=['status'=>'not_applicable','night_days'=>[],
            'explicit_matching_nights'=>0,'explicit_conflicting_nights'=>0,
            'unconfirmed_nights'=>0,'source'=>'tour.itinerary_item',
            'requires_editor_review'=>false];
        if(!in_array($kind,['hotel','resort'],true))return $out;
        if($start<1||$nights<1||$nights>30){
            $out['status']='invalid_stage_night_geometry';
            $out['requires_editor_review']=true;return $out;
        }
        $raw=get_post_meta($tour,'itinerary_item',true);
        $days=is_array($raw)?array_values($raw):[];
        if(!$days || $start+$nights-1>count($days)){
            $out['status']='invalid_stage_night_geometry';
            $out['requires_editor_review']=true;
            return $out;
        }
        $cities=['cairo'=>'cairo','giza'=>'giza','luxor'=>'luxor',
            'aswan'=>'aswan','sharm'=>'sharm',
            'sharm el sheikh'=>'sharm','red sea'=>'red_sea_unspecified'];
        for($day=$start;$day<$start+$nights;$day++){
            $itinerary=$days[$day-1]??null;
            $description=is_array($itinerary)?(string)($itinerary['description']??''):'';
            $title=is_array($itinerary)?(string)($itinerary['title']??''):'';
            $description=html_entity_decode(wp_strip_all_tags($description),ENT_QUOTES,'UTF-8');
            $title=html_entity_decode(wp_strip_all_tags($title),ENT_QUOTES,'UTF-8');
            $hits=[];
            if(preg_match_all('/\bovernight\s+(?:stay\s+)?in\s+(cairo|giza|luxor|aswan|sharm(?:\s+el\s+sheikh)?|red\s+sea)\b/iu',
                $description,$m)){
                foreach($m[1] as $hit){
                    $name=sanitize_key(preg_replace('/\s+/u',' ',strtolower(trim($hit))));
                    $normalized=str_replace('_',' ',$name);
                    if(isset($cities[$normalized]))$hits[$cities[$normalized]]=true;
                }
            }
            // Red Sea is a region, not proof of Sharm specifically.
            $recognized=array_keys($hits);
            $expected=$destination==='cairo'?['cairo','giza']:[$destination];
            $conflicts=array_values(array_diff($recognized,
                array_merge($expected,['red_sea_unspecified'])));
            $matches=array_intersect($recognized,$expected);
            $proof=$conflicts?'conflict':($matches?'match':'unconfirmed');
            if($proof==='match')$out['explicit_matching_nights']++;
            elseif($proof==='conflict')$out['explicit_conflicting_nights']++;
            else $out['unconfirmed_nights']++;
            $out['night_days'][]=[
                'day'=>$day,'source_title'=>mb_substr($title,0,150),
                'observed_overnight_destinations'=>$recognized,
                'result'=>$proof
            ];
        }
        if($out['explicit_conflicting_nights']>0)$out['status']='itinerary_destination_conflict';
        elseif($out['explicit_matching_nights']===$nights)$out['status']='explicit_overnights_matched';
        elseif($out['explicit_matching_nights']>0)$out['status']='partially_matched_requires_review';
        else $out['status']='missing_explicit_overnight_evidence';
        $out['requires_editor_review']=$out['status']!=='explicit_overnights_matched';
        return $out;
    }
    public static function inspect(int $tour,int $tierId) {
        $tour=self::canonical($tour);
        if(!$tour||!self::isPackage($tour)||!current_user_can('edit_post',$tour))
            return new WP_Error('package_lab_tour_unavailable');
        $tiers=self::tierIds();
        if(!in_array($tierId,array_column($tiers,'id'),true))
            return new WP_Error('package_lab_tier_invalid');
        $plan=get_post_meta($tour,self::SOURCE,true);
        $bindings=get_post_meta($tour,self::BINDINGS,true);
        if(!is_array($plan)||!is_array($plan['stages']??null))
            return new WP_Error('package_lab_plan_unavailable');
        $raw=is_array($bindings)?($bindings['bindings']??[]):[];
        if(!is_array($raw))$raw=[];
        $byKey=[];
        foreach($raw as $b)if(is_array($b)&&!empty($b['stage_key']))
            $byKey[(string)$b['stage_key']]=$b;
        $stages=[];$allProps=[];
        $seenPropertyStage=[];
        $stats=['stages'=>0,'hotel_or_resort_stages'=>0,
            'stages_without_candidates'=>0,'candidate_mentions'=>0,
            'units_referenced'=>0,'unit_occupancy_recorded'=>0,
            'unit_direct_positive_prices'=>0,'unit_cost_role_positive_prices'=>0,
            'unit_available'=>0,'published_property_rates'=>0,
            'approved_supplier_stage_receipts'=>0,'native_rate_stages_written'=>0,
            'selected_stage_properties'=>0,'selected_stage_units'=>0,
            'unpriced_stages'=>0,
            'destination_excluded_candidates'=>0,
            'destination_mismatches'=>0,
            'tier_mismatches'=>0,
            'hotel_stages_requiring_itinerary_review'=>0,
            'hotel_stages_with_explicit_itinerary_conflict'=>0,
            'stage_selection_isolation_violations'=>0,
            'unique_candidate_properties'=>0,
            'properties_eligible_in_multiple_stages'=>0];
        foreach(array_slice($plan['stages'],0,40) as $p){
            if(!is_array($p))continue;
            $key=(string)($p['stage_key']??'');
            $b=$byKey[$key]??[];
            $kind=sanitize_key((string)($p['lodging_kind']??$b['lodging_kind']??'unknown'));
            $destinationScope=self::stageDestinationScope($tour,$p);
            $itineraryEvidence=self::itineraryNightEvidence($tour,$p);
            $excluded=[];
            $rows=(array)($b['room_candidate_refs'][(string)$tierId]??[]);
            $fallback=(array)($p['hotel_alternatives_or_by_tier'][(string)$tierId]??[]);
            $candidates=[];$candidateIds=[];
            foreach(array_slice($rows,0,60) as $entry){
                if(!is_array($entry))continue;
                $pid=(int)($entry['property_id_en']??0);
                if(!$pid||isset($candidateIds[$pid]))continue;
                $candidateIds[$pid]=true;
                $decision=self::propertyEligibility($pid,$tierId,$destinationScope,$kind);
                if($decision['eligible'] && !self::stageSourceAllowsCandidates($itineraryEvidence))
                    $decision=['eligible'=>false,'reason'=>'stage_itinerary_conflicts_with_destination'];
                if(!$decision['eligible']){
                    $excluded[]=['property_id'=>$pid,'title'=>get_the_title($pid),
                        'reason'=>$decision['reason']];
                    continue;
                }
                $allProps[$pid]=$pid;
                $units=array_values(array_unique(array_filter(array_map(
                    'absint',(array)($entry['unit_ids_en']??[])))));
                $candidates[]=['property_id'=>$pid,
                    'unit_ids'=>$units,
                    'rel_id'=>(int)($entry['source_relation_id']??0),
                    'review_approved_unit_ids'=>array_values(array_map('absint',
                        (array)($entry['commercially_approved_unit_ids']??[])))];
            }
            // Preserve plan-only hotel alternatives as *unverified* candidates,
            // not as inventory or cost-bearing units.
            foreach(array_slice($fallback,0,60) as $rawId){
                $pid=(int)$rawId;
                if(!$pid||isset($candidateIds[$pid]))continue;
                $candidateIds[$pid]=true;
                $decision=self::propertyEligibility($pid,$tierId,$destinationScope,$kind);
                if($decision['eligible'] && !self::stageSourceAllowsCandidates($itineraryEvidence))
                    $decision=['eligible'=>false,'reason'=>'stage_itinerary_conflicts_with_destination'];
                if(!$decision['eligible']){
                    $excluded[]=['property_id'=>$pid,'title'=>get_the_title($pid),
                        'reason'=>$decision['reason']];
                    continue;
                }
                $allProps[$pid]=$pid;
                $candidates[]=['property_id'=>$pid,'unit_ids'=>[],
                    'rel_id'=>0,'review_approved_unit_ids'=>[]];
            }
            // These are independent stay instances, even when the same Cairo
            // property is offered at trip arrival and return. Never reuse
            // selection, availability or tariff across different stage keys.
            foreach($candidates as $candidate){
                $seenPropertyStage[$candidate['property_id']][]=$key;
            }
            $stats['destination_excluded_candidates']+=count($excluded);
            foreach($excluded as $exclusion){
                if($exclusion['reason']==='property_destination_mismatch')
                    $stats['destination_mismatches']++;
                if($exclusion['reason']==='property_tier_mismatch')
                    $stats['tier_mismatches']++;
            }
            if(in_array($kind,['hotel','resort'],true)){
                if($itineraryEvidence['requires_editor_review'])
                    $stats['hotel_stages_requiring_itinerary_review']++;
                if($itineraryEvidence['status']==='itinerary_destination_conflict')
                    $stats['hotel_stages_with_explicit_itinerary_conflict']++;
            }
            $isStay=in_array($kind,['hotel','resort','cruise'],true);
            $hasHotel=in_array($kind,['hotel','resort'],true);
            $stats['stages']++;
            if($hasHotel){
                $stats['hotel_or_resort_stages']++;
                if(!$candidates)$stats['stages_without_candidates']++;
            }
            $receipt=$b['supplier_cost_receipt']??null;
            if(is_array($receipt)&&($receipt['status']??'')==='approved')
                $stats['approved_supplier_stage_receipts']++;
            if(!empty($b['native_rate_stage_written']))
                $stats['native_rate_stages_written']++;
            if((int)($b['selected_property_id']??0)>0)
                $stats['selected_stage_properties']++;
            if(!empty($b['bookable_unit_ids']))
                $stats['selected_stage_units']++;
            if($isStay && ($b['price_status']??$p['price_status']??'')!=='priced')
                $stats['unpriced_stages']++;
            $stats['candidate_mentions']+=count($candidates);
            $program=$b['cruise_program']??null;
            $stages[]=[
                'key'=>$key,'kind'=>$kind,'day_start'=>(int)($p['source_day_start']??0),
                'nights'=>max(0,(int)($p['nights']??0)),
                'destination'=>sanitize_key((string)($p['destination_key']??'')),
                'destination_scope'=>$destinationScope,
                'itinerary_evidence'=>$itineraryEvidence,
                'stage_instance_key'=>hash('sha256',wp_json_encode([$tour,$tierId,$key,
                    (int)($p['source_day_start']??0),(int)($p['nights']??0),
                    sanitize_key((string)($p['destination_key']??''))])),
                'arrival_day_offset'=>(int)($p['day_offset']??0),
                'departure_day_number'=>(int)($p['source_day_start']??0)+(int)($p['nights']??0),
                'stage_selection_independent'=>true,
                'price_must_resolve_for_stage_dates'=>true,
                'excluded_candidates'=>$excluded,
                'board'=>sanitize_key((string)($p['board']??'')),
                'price_status'=>(string)($b['price_status']??$p['price_status']??'pending_quote'),
                'selected_property_id'=>(int)($b['selected_property_id']??0),
                'selected_unit_count'=>count((array)($b['bookable_unit_ids']??[])),
                'supplier_receipt_approved'=>is_array($receipt)&&($receipt['status']??'')==='approved',
                'native_rate_stage_written'=>!empty($b['native_rate_stage_written']),
                'cruise_program_term'=>(int)(is_array($program)?($program['program_term_id_en']??0):0),
                // Separate from saved qualified Package candidates; these
                // are real-site *discovery options*, never price authority.
                'source_discovery'=>(!$candidates ||
                    in_array($kind,['sleeper_train','cruise'],true))
                    ?MAD4B_Package_Stage_Sourcing_V1::inspect($tour,$p,$tierId)
                    :['type'=>'already_matched_candidate_stage',
                      'status'=>'native_selected_tier_candidates_present',
                      'writes'=>false],
                'candidates'=>$candidates
            ];
        }
        $stats['unique_candidate_properties']=count($allProps);
        $stats['properties_eligible_in_multiple_stages']=count(array_filter(
            $seenPropertyStage,static fn($keys)=>count(array_unique($keys))>1));
        $rateIndex=self::publishedRateIndex($allProps);
        $distinctUnits=[];$detailed=[];$countedRateProps=[];
        foreach($stages as $stage){
            $out=$stage;$out['candidates']=[];
            $out['repeated_candidate_property_ids']=array_values(array_map('intval',
                array_keys(array_filter(array_intersect_key($seenPropertyStage,
                    array_fill_keys(array_column($stage['candidates'],'property_id'),true)),
                    static fn($keys)=>count(array_unique($keys))>1))));
            $out['same_destination_revisit']=count(array_filter($stages,
                static fn($x)=>$x['destination']===$stage['destination'] &&
                    $x['key']!==$stage['key']))>0;
            foreach($stage['candidates'] as $candidate){
                $pid=$candidate['property_id'];$linked=$candidate['unit_ids'];
                $relations=self::nativeRelationUnits($pid,$candidate['rel_id']);
                $unitRows=[];$unitNum=0;$capNum=0;$availableNum=0;$costNum=0;$directNum=0;
                foreach(array_slice($linked,0,150) as $id){
                    $id=(int)$id;$unitNum++;
                    $type=get_post_type($id);$status=(string)get_post_status($id);
                    $amount=get_post_meta($id,'unit_start_price',true);
                    $direct=is_numeric($amount)&&(float)$amount>0;
                    $kind=(string)get_post_meta($id,'unit_direct_price_kind',true);
                    $currency=(string)get_post_meta($id,'unit_base_currency',true);
                    $basis=(string)get_post_meta($id,'unit_direct_price_unit',true);
                    $availability=in_array((string)get_post_meta($id,'unit_available',true),
                        ['1','true','yes','on'],true);
                    $capacity=get_post_meta($id,'unit_max_occupancy',true);
                    $capacityOk=is_numeric($capacity)&&(int)$capacity>0;
                    $receipt=get_post_meta($id,'_mad4b_room_source_receipt_v1',true);
                    $propertyMatch=($type==='units' && 
                        is_array($receipt) && (int)($receipt['property_id']??0)===$pid &&
                        isset($relations[$id]));
                    $cost=$direct && $kind==='cost' && $currency!=='' && $basis!=='';
                    if($direct)$directNum++;
                    if($cost)$costNum++;
                    if($availability)$availableNum++;
                    if($capacityOk)$capNum++;
                    if(!isset($distinctUnits[$id])){
                        $distinctUnits[$id]=true;
                        $stats['units_referenced']++;
                        if($direct)$stats['unit_direct_positive_prices']++;
                        if($cost)$stats['unit_cost_role_positive_prices']++;
                        if($availability)$stats['unit_available']++;
                        if($capacityOk)$stats['unit_occupancy_recorded']++;
                    }
                    $unitRows[]=[
                        'id'=>$id,'title'=>wp_strip_all_tags(html_entity_decode(get_the_title($id),ENT_QUOTES,'UTF-8')),
                        'status'=>$status,'available'=>$availability,'capacity'=>$capacityOk?(int)$capacity:null,
                        'source_relation_match'=>$propertyMatch,
                        // A cost-kind direct amount is a recorded field, NOT an approved supplier receipt.
                        'indicative_direct_price_entered'=>$direct,
                        'declared_unit_cost_field'=>$cost,
                        'edit_url'=>self::editLink($id)
                    ];
                }
                $rates=$rateIndex[$pid]??[];
                if(!isset($countedRateProps[$pid])){
                    $countedRateProps[$pid]=true;
                    $stats['published_property_rates']+=(int)($rates['published']??0);
                }
                $out['candidates'][]=[
                    'id'=>$pid,
                    'title'=>wp_strip_all_tags(html_entity_decode(get_the_title($pid),ENT_QUOTES,'UTF-8')),
                    'status'=>(string)get_post_status($pid),
                    'native_units_linked'=>$unitNum,
                    'unit_capacity_known'=>$capNum,
                    'unit_available'=>$availableNum,
                    'unit_direct_prices_entered'=>$directNum,
                    'unit_cost_fields'=>$costNum,
                    'published_property_rate_count'=>(int)($rates['published']??0),
                    'published_property_cost_rate_count'=>(int)($rates['cost_entries']??0),
                    'approved_unit_refs_count'=>count($candidate['review_approved_unit_ids']),
                    'property_direct_cost_role'=>(string)get_post_meta($pid,'prop_direct_price_kind',true)==='cost' &&
                        is_numeric(get_post_meta($pid,'prop_start_price',true)) &&
                        (float)get_post_meta($pid,'prop_start_price',true)>0,
                    'edit_url'=>self::editLink($pid),
                    'units'=>$unitRows
                ];
            }
            $detailed[]=$out;
        }
        $nativeRates=get_posts(['post_type'=>'tour-rates','fields'=>'ids',
            'post_status'=>['publish','draft','pending'],
            'posts_per_page'=>80,'suppress_filters'=>true,
            'meta_key'=>'related_tour_id','meta_value'=>(string)$tour]);
        $statuses=[];foreach($nativeRates as $r){$s=(string)get_post_status($r);$statuses[$s]=($statuses[$s]??0)+1;}
        $context=class_exists('MAD4B_Package_Components_Editor_Context_V1',false)
            ?MAD4B_Package_Components_Editor_Context_V1::inspect($tour):[];
        $nativeGuard=class_exists('MAD4B_Package_Stage_Rate_Guard_V1',false)
            ?MAD4B_Package_Stage_Rate_Guard_V1::review($tour,$tierId)
            :['applies'=>true,'ready'=>false,
              'blockers'=>['native_package_rate_guard_unavailable'],
              'contract'=>'mad4b.package-rate-stage-guard.unavailable'];
        return [
            'contract'=>'mad4b.package-cost-readiness.v1',
            'tour_id'=>$tour,'tour_title'=>wp_strip_all_tags(html_entity_decode(get_the_title($tour),ENT_QUOTES,'UTF-8')),
            'tour_edit_url'=>self::editLink($tour),
            'tier_id'=>$tierId,'tier'=>array_values(array_filter($tiers,
                static fn($t)=>(int)$t['id']===$tierId))[0]['label'],
            'day_count'=>(int)($plan['tour_days']??0),
            'night_count'=>(int)($plan['tour_nights']??0),
            'plan_status'=>(string)($plan['source_review_status']??'not_certified'),
            'plan_recorded_at'=>(string)($plan['recorded_at']??''),
            'source_plan_warnings'=>array_values(array_filter(array_map('sanitize_key',
                (array)($plan['source_issues']??[])))),
            'source_plan_policy_error'=>sanitize_key((string)($plan['company_policy_error']??'')),
            'sources'=>['plan'=>self::SOURCE,'stay_bindings'=>self::BINDINGS,
                'property'=>'properties','unit'=>'units','property_rates'=>'property-rates',
                'tour_rate_relation'=>'related_tour_id'],
            'summary'=>$stats,
            'unit_sources_are_editorial_only'=>true,
            'native_generator_stage_admission'=>[
                'contract'=>(string)($nativeGuard['contract']??''),
                'ready'=>($nativeGuard['ready']??false)===true,
                'blockers'=>array_values((array)($nativeGuard['blockers']??[])),
                'date_window_selected'=>false,
                'rate_generation_attempted'=>false,
            ],
            'destination_validation'=>'per_stage_exact_taxonomy_plus_documented_cairo_giza_scope',
            'stage_pricing_authority'=>'separate_stage_key_and_date_occupancy',
            'candidate_exclusions_are_not_priced'=>true,
            'supplier_cost_total'=>null,
            'sale_price'=>null,
            'margin'=>null,
            'generated_rate_ready'=>false,
            'checkout_ready'=>false,
            'linked_original_rate_count'=>count($nativeRates),
            'linked_original_rate_statuses'=>$statuses,
            'guide_review'=>$context['guide']??null,
            'transport_review'=>$context['transportation']??null,
            'stages'=>$detailed,
            'note'=>'Read-only candidate and unit inventory. No cost is calculated without approved room/cabin, dates, provider cost receipts, currency and occupancy. No QA Rate or legacy Tour Rate price is used.'
        ];
    }
    public static function ajax():void {
        if(!self::allowed())wp_send_json_error(['code'=>'package_lab_forbidden'],403);
        if(!check_ajax_referer(self::AJAX,'nonce',false))
            wp_send_json_error(['code'=>'package_lab_nonce_invalid'],403);
        $tour=filter_var($_POST['tour_id']??null,FILTER_VALIDATE_INT);
        $tier=filter_var($_POST['tier_id']??null,FILTER_VALIDATE_INT);
        if(!$tour||!$tier)wp_send_json_error(['code'=>'package_lab_invalid_selection'],422);
        $data=self::inspect((int)$tour,(int)$tier);
        if(is_wp_error($data))wp_send_json_error(['code'=>$data->get_error_code()],422);
        wp_send_json_success(['result'=>$data,'saved'=>false,'write_performed'=>false]);
    }
    public static function assets():void {
        if(!is_admin()||sanitize_key((string)($_GET['page']??''))!=='mad4b-pricing-lab'||
            !self::allowed())return;
        $file=WPMU_PLUGIN_DIR.'/assets/mad4b-package-pricing-lab-readiness-v1.js';
        $style=WPMU_PLUGIN_DIR.'/assets/mad4b-package-pricing-lab-readiness-v1.css';
        if(!is_readable($file)||!is_readable($style))return;
        wp_enqueue_style('mad4b-package-pricing-lab-readiness-v1',
            content_url('mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.css'),
            [],(string)filemtime($style));
        wp_enqueue_script('mad4b-package-pricing-lab-readiness-v1',
            content_url('mu-plugins/assets/mad4b-package-pricing-lab-readiness-v1.js'),
            [],(string)filemtime($file),true);
        wp_add_inline_script('mad4b-package-pricing-lab-readiness-v1',
            'window.MAD4BPackageCostLab='.wp_json_encode([
                'endpoint'=>admin_url('admin-ajax.php'),'action'=>self::AJAX,
                'nonce'=>wp_create_nonce(self::AJAX),'packages'=>self::packages(),
                'tiers'=>self::tierIds(),
                'property_pricing_url'=>admin_url('admin.php?page=mad4b-stay-price-layers'),
            ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';',
            'before');
    }
    public static function container():void {
        if(!is_admin()||sanitize_key((string)($_GET['page']??''))!=='mad4b-pricing-lab'||
            !self::allowed())return;
        echo '<section id="mad4b-package-cost-lab-v1" class="mad4b-package-cost-lab-v1" aria-label="Package Cost & Unit Readiness">';
        echo '<div class="mad4b-pcl-heading"><h2>Package Cost &amp; Unit Readiness</h2>';
        echo '<p>Choose a Package and tier to inspect native hotel choices, linked rooms, occupancy and supplier-cost gaps. Read-only — not a quote, strategy preset, new Rate or checkout approval.</p></div>';
        echo '<div id="mad4b-package-cost-lab-inner" aria-live="polite"><p>Preparing Package inventory…</p></div>';
        echo '</section>';
    }
}
add_action('wp_ajax_'.MAD4B_Package_Pricing_Lab_Readiness_V1::AJAX,
    [MAD4B_Package_Pricing_Lab_Readiness_V1::class,'ajax']);
add_action('admin_enqueue_scripts',
    [MAD4B_Package_Pricing_Lab_Readiness_V1::class,'assets'],87);
add_action('admin_notices',
    [MAD4B_Package_Pricing_Lab_Readiness_V1::class,'container'],100);
}

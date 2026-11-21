<?php
/**
 * MAD4B Package Stage Sourcing — source-backed editorial discovery only.
 * NOT an authoring store, supplier tariff, Rate selector or booking authority.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Package_Stage_Sourcing_V1', false)) {
final class MAD4B_Package_Stage_Sourcing_V1 {
    private static array $hotelCache=[];
    private static function edit(int $id):string {
        return (string) get_edit_post_link($id,'raw');
    }
    private static function relationCount(int $property):int {
        global $wpdb;
        $name=$wpdb->prefix.'jet_rel_default';
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))return 0;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT child_object_id) FROM {$name} WHERE rel_id=%d AND parent_object_id=%d",
            223,$property));
    }
    private static function hotel(int $tour,array $stage,int $tier):array {
        $scope=class_exists('MAD4B_Package_Stage_Rate_Guard_V1',false)
            ?MAD4B_Package_Stage_Rate_Guard_V1::stageScope($tour,$stage)
            :['status'=>'unmapped','accepted_term_ids'=>[],'destination'=>''];
        $key=$tour.'|'.($scope['destination']??'').'|'.$tier;
        if(isset(self::$hotelCache[$key]))return self::$hotelCache[$key];
        $out=['type'=>'published_hotel_property_pool','status'=>'editorial_discovery_only',
            'destination'=>$scope['destination']??'','tier_id'=>$tier,
            'source'=>'native_properties_destinations_stars_package_levels_and_relation_223',
            'properties'=>[],'source_property_count'=>0,
            'tier_matched_count'=>0,'tier_unclassified_count'=>0,
            'missing_native_units_count'=>0,'excluded_wrong_locality'=>0,
            'supplier_cost_total'=>null,'ready'=>false,'writes'=>false];
        $terms=array_map('absint',(array)($scope['accepted_term_ids']??[]));
        if(($scope['status']??'unmapped')!=='mapped'||!$terms)
            return self::$hotelCache[$key]=$out;
        $ids=get_posts(['post_type'=>'properties','post_status'=>'publish',
            'fields'=>'ids','posts_per_page'=>250,'orderby'=>'ID','order'=>'ASC',
            'suppress_filters'=>true,'tax_query'=>[[
                'taxonomy'=>'destinations','field'=>'term_id',
                'terms'=>$terms,'operator'=>'IN','include_children'=>false
            ]]]);
        foreach($ids as $id){
            $id=(int)$id;
            if(get_post_meta($id,'_booking_experience_qa_pricing_fixture',true)!=='')continue;
            $kind=wp_get_object_terms($id,'property-types',['fields'=>'slugs']);
            if(is_wp_error($kind)||!array_intersect((array)$kind,['hotel','resort']))continue;
            $dest=wp_get_object_terms($id,'destinations',['fields'=>'slugs']);
            if(is_wp_error($dest))continue;
            // Aswan governorate is too broad: Abu Simbel is another city.
            if(($scope['destination']??'')==='aswan' &&
                in_array('abu-simbel-temples',(array)$dest,true)){
                $out['excluded_wrong_locality']++;continue;
            }
            $stars=wp_get_object_terms($id,'stars',['fields'=>'slugs']);
            $levels=wp_get_object_terms($id,'package_levels',['fields'=>'ids']);
            if(is_wp_error($stars))$stars=[];
            if(is_wp_error($levels))$levels=[];
            $eligible=class_exists('MAD4B_Package_Stage_Rate_Guard_V1',false)
                ? MAD4B_Package_Stage_Rate_Guard_V1::propertyEligibility($id,$tier,$scope,
                    (string)($stage['lodging_kind']??'hotel'))
                : ['eligible'=>false,'reason'=>'generator_guard_unavailable'];
            $unitCount=self::relationCount($id);
            $out['source_property_count']++;
            if($eligible['eligible'])$out['tier_matched_count']++;
            elseif(!$levels)$out['tier_unclassified_count']++;
            if(!$unitCount)$out['missing_native_units_count']++;
            $out['properties'][]=[
                'id'=>$id,'title'=>wp_strip_all_tags(html_entity_decode(
                    get_the_title($id),ENT_QUOTES,'UTF-8')),
                'stars'=>array_values((array)$stars),
                'native_tiers'=>array_values(array_map('intval',(array)$levels)),
                'native_unit_count'=>$unitCount,
                'tier_eligible'=>$eligible['eligible'],
                'tier_status'=>$eligible['eligible']?'tier_matched':
                    (!$levels?'tier_unclassified':$eligible['reason']),
                'edit_url'=>self::edit($id),
                'supplier_cost_verified'=>false
            ];
        }
        usort($out['properties'],static function($a,$b){
            if($a['tier_eligible']!==$b['tier_eligible'])return $a['tier_eligible']?-1:1;
            if($a['native_unit_count']!==$b['native_unit_count'])
                return $b['native_unit_count']<=>$a['native_unit_count'];
            return strcmp($a['title'],$b['title']);
        });
        return self::$hotelCache[$key]=$out;
    }
    /** Read source itinerary wording; this is NOT train/timetable approval. */
    private static function trainRoute(int $tour,array $stage):array {
        $source=class_exists('MAD4B_Package_Stage_Rate_Guard_V1',false)
            ?MAD4B_Package_Stage_Rate_Guard_V1::canonicalTour($tour):$tour;
        $raw=get_post_meta($source,'itinerary_item',true);
        $day=(int)($stage['source_day_start']??0);
        // JetEngine repeaters are keyed by "item-0", "item-1", not by
        // integer day. Preserve original order before day-index lookup.
        $days=is_array($raw)?array_values($raw):[];
        $entry=(array)($days[$day-1]??[]);
        $title=html_entity_decode(wp_strip_all_tags((string)($entry['title']??'')),ENT_QUOTES,'UTF-8');
        $body=html_entity_decode(wp_strip_all_tags((string)($entry['description']??'')),ENT_QUOTES,'UTF-8');
        $short=mb_substr(preg_replace('/\s+/u',' ',$title.' '.$body),0,1500);
        $to='';
        if(preg_match('/sleeper train\s+to\s+aswan|train to aswan/iu',$short))$to='aswan';
        elseif(preg_match('/sleeper train\s+(?:back\s+)?to\s+cairo|train back to cairo/iu',$short))$to='cairo';
        // In 60623 the return sleeper departs Luxor; no implication that a
        // train from Aswan itself is the booking segment.
        $from=$to==='aswan'&&preg_match('/cairo/iu',$short)?'cairo':'';
        $originEvidence=$from?'itinerary_text_explicit_city':null;
        if($to==='cairo'&&preg_match('/luxor/iu',$short)){
            $from='luxor';$originEvidence='original_itinerary_explicit_luxor';
        }
        // The outbound Day-2 source header says "to Aswan"; its previous
        // verified Cairo hotel stay ends on Day 2. Use that *saved Tour
        // stage geometry*, not a guessed origin from a sightseeing place.
        if(!$from&&$to==='aswan'){
            $plan=get_post_meta($source,'_mad4b_package_accommodation_source_plan_v1',true);
            $matches=[];
            foreach((array)($plan['stages']??[]) as $previous){
                if(!is_array($previous)||!in_array($previous['lodging_kind']??'',
                    ['hotel','resort'],true))continue;
                $end=(int)($previous['source_day_start']??0)+(int)($previous['nights']??0);
                if($end===$day&&($previous['destination_key']??'')==='cairo')
                    $matches[]=$previous;
            }
            if(count($matches)===1){$from='cairo';$originEvidence='adjacent_verified_cairo_hotel_stage';}
        }
        return ['from'=>$from,'to'=>$to,
            'origin_evidence'=>$originEvidence,'source_day'=>$day,
            'source_title'=>mb_substr($title,0,160),
            'status'=>$from&&$to?'itinerary_route_proposal':'route_not_explicitly_resolved'];
    }
    private static function stationCities(int $station):array {
        $terms=wp_get_object_terms($station,'destinations',['fields'=>'slugs']);
        if(is_wp_error($terms))return [];
        $cities=[];
        foreach((array)$terms as $slug){
            if(in_array($slug,['cairo-city','cairo'],true))$cities['cairo']=true;
            if(in_array($slug,['aswan-city','aswan-gov'],true))$cities['aswan']=true;
            if(in_array($slug,['luxor-city','luxor'],true))$cities['luxor']=true;
        }
        return array_keys($cities);
    }
    private static function train(int $tour,array $stage):array {
        $route=self::trainRoute($tour,$stage);
        $out=['type'=>'published_sleeper_train_route_candidates',
            'status'=>'editorial_discovery_only',
            'source'=>'native_transportations_stations_sleeper_taxonomy',
            'route'=>$route,'train_candidates'=>[],
            'supplier_cabin_rate_verified'=>false,'schedule_verified'=>false,
            'checkout_ready'=>false,'cost_total'=>null,'writes'=>false];
        if($route['status']!=='itinerary_route_proposal')return $out;
        $ids=get_posts(['post_type'=>'transportations','post_status'=>'publish',
            'fields'=>'ids','posts_per_page'=>100,'suppress_filters'=>true,
            'tax_query'=>[
                'relation'=>'AND',
                ['taxonomy'=>'transportation-types','field'=>'slug',
                 'terms'=>['train'],'include_children'=>false],
                ['taxonomy'=>'transportation-types','field'=>'slug',
                 'terms'=>['sleeping'],'include_children'=>false]
            ]]);
        foreach($ids as $id){
            $id=(int)$id;
            if(stripos(get_the_title($id),'QA ONLY')!==false ||
                get_post_meta($id,'_booking_experience_qa_pricing_fixture',true)!=='')continue;
            $stops=get_post_meta($id,'trans_train_stops',true);
            $pos=[];
            foreach((array)$stops as $stop){
                if(!is_array($stop))continue;
                $station=(int)($stop['station_id']??0);
                if(get_post_type($station)!=='stations' ||
                    get_post_status($station)!=='publish')continue;
                $order=$stop['sort_num']??null;
                if(!is_numeric($order))continue;
                foreach(self::stationCities($station) as $city)
                    $pos[$city][]=(int)$order;
            }
            $valid=false;
            foreach((array)($pos[$route['from']]??[]) as $from)
                foreach((array)($pos[$route['to']]??[]) as $to)
                    if($from<$to)$valid=true;
            if(!$valid)continue;
            $rates=get_posts(['post_type'=>'transport-rates','post_status'=>'publish',
                'fields'=>'ids','posts_per_page'=>20,'meta_key'=>'source_post_id',
                'meta_value'=>(string)$id,'suppress_filters'=>true]);
            $out['train_candidates'][]=[
                'id'=>$id,'title'=>wp_strip_all_tags(html_entity_decode(get_the_title($id),ENT_QUOTES,'UTF-8')),
                'number'=>(string)get_post_meta($id,'trans_train_number',true),
                'native_route_order_verified'=>true,
                'published_transport_rate_count'=>count($rates),
                'cabin_supplier_contract_verified'=>false,
                'edit_url'=>self::edit($id)
            ];
        }
        return $out;
    }
    public static function inspect(int $tour,array $stage,int $tier):array {
        $kind=sanitize_key((string)($stage['lodging_kind']??''));
        if(in_array($kind,['hotel','resort'],true))return self::hotel($tour,$stage,$tier);
        if($kind==='sleeper_train')return self::train($tour,$stage);
        if($kind==='cruise'){
            $source=class_exists('MAD4B_Package_Stage_Rate_Guard_V1',false)
                ?MAD4B_Package_Stage_Rate_Guard_V1::canonicalTour($tour):$tour;
            $binding=get_post_meta($source,
                '_mad4b_package_stay_verified_room_program_links_v1',true);
            $program=null;
            foreach((array)($binding['bindings']??[]) as $row)
                if(($row['stage_key']??'')===($stage['stage_key']??''))
                    $program=$row['cruise_program']??null;
            $programId=(int)($program['program_term_id_en']??0);
            $term=$programId?get_term($programId,'programs'):null;
            return ['type'=>'cruise_native_program_source',
                'status'=>'program_only_not_ship_sailing_priced',
                'program_id'=>$programId,
                'program_title'=>$term instanceof WP_Term?(string)$term->name:'',
                'route_from'=>$program['route_from']??null,
                'route_to'=>$program['route_to']??null,
                'program_days'=>(int)($program['native_program_day_count']??0),
                'vessel_id'=>(int)($program['vessel_id']??0),
                'cabin_unit_id'=>(int)($program['cabin_unit_id']??0),
                'source_verified_direction_only'=>!empty($program['source_first_and_last_days_direction_checked']),
                'sailing_confirmed'=>!empty($program['explicit_supplier_sailing_confirmed']),
                'supplier_cost_total'=>null,'writes'=>false];
        }
        return ['type'=>'unhandled_nonhotel_source','status'=>'needs_specialized_provider',
                'writes'=>false,'supplier_cost_total'=>null];
    }
}
}

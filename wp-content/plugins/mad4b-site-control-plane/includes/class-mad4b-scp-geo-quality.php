<?php
/**
 * First-party, source-only geo and transport readiness rules.
 * Returns candidate issues to the existing governed Readiness reconciler.
 * No geocoding, price generation, ContentJob mutation, or source write.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Geo_Quality {
    const CONTRACT = 'mad4b.geo-quality-discovery.v1';
    const CODES = [
        'geo_station_destination_unlinked',
        'geo_station_locality_unverified',
        'geo_station_coordinates_invalid',
        'geo_transport_route_stations_invalid',
        'geo_transport_price_evidence_unverified',
        'geo_transport_rate_expired',
        'geo_cruise_boarding_unverified',
        'geo_cruise_boarding_applicability_unverified',
        'geo_property_pickup_unverified'
    ];
    private static function canonical($id,$type) {
        if(!has_filter('wpml_object_id'))return (int)$id;
        $lang=apply_filters('wpml_default_language',null);
        if(!$lang)return (int)$id;
        $mapped=apply_filters('wpml_object_id',(int)$id,$type,false,$lang);
        return $mapped ? (int)$mapped : (int)$id;
    }
    private static function issue($type,$id,$code,$severity,$message,$evidence=[]) {
        return [
            'id'=>hash('sha256',get_current_blog_id().'|'.$type.'|'.$id.'|'.$code),
            'post_type'=>$type,'object_id'=>(int)$id,'code'=>$code,
            'severity'=>$severity,'label'=>$message,
            'source_evidence'=>array_slice($evidence,0,8,true)
        ];
    }
    private static function ids($type){
        $ids=get_posts(['post_type'=>$type,'post_status'=>'publish',
            'posts_per_page'=>3001,'fields'=>'ids','no_found_rows'=>true,
            'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true]);
        return count($ids)>3000?new WP_Error('geo_quality_post_cap'):array_map('intval',$ids);
    }
    private static function coordinates_valid($raw) {
        if(is_array($raw)){
            $lat=$raw['lat']??$raw['latitude']??null;
            $lng=$raw['lng']??$raw['longitude']??null;
        }elseif(is_string($raw) && preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/D',$raw,$m)){
            $lat=$m[1];$lng=$m[2];
        }else return false;
        return is_numeric($lat)&&is_numeric($lng)
            &&abs((float)$lat)<=90&&abs((float)$lng)<=180
            &&!((float)$lat===0.0&&(float)$lng===0.0);
    }
    private static function public_active_station($id) {
        return (string)get_post_meta($id,'station_active_status',true)==='1'
            &&(string)get_post_meta($id,'station_public_status',true)==='1';
    }
    private static function transport_available($id) {
        return (string)get_post_meta($id,'trans_available_status',true)==='1'
            &&(string)get_post_meta($id,'trans_is_puplic',true)==='1';
    }
    private static function cruise_term($id) {
        if(!taxonomy_exists('property-types'))return false;
        $terms=wp_get_object_terms($id,'property-types');
        if(is_wp_error($terms))return false;
        foreach($terms as $t){
            $name=strtolower((string)$t->name.' '.$t->slug);
            if(str_contains($name,'cruise')||str_contains($name,'dahabiya')||str_contains($name,'felucca'))return true;
        }
        return false;
    }
    /**
     * Existing published Tour Rate property relations are first-party evidence
     * that an accommodation can participate in a package, not proof of a berth.
     */
    private static function linked_property_rates() {
        $used=[];
        if(!post_type_exists('tour-rates'))return $used;
        $rates=self::ids('tour-rates');
        if(is_wp_error($rates))return $used;
        foreach($rates as $rate_id){
            if(self::canonical($rate_id,'tour-rates')!==$rate_id)continue;
            $raw=get_post_meta($rate_id,'related_properties_id',true);
            $refs=is_array($raw)?$raw:preg_split('/[,\s]+/',(string)$raw);
            foreach((array)$refs as $ref){
                if(!is_scalar($ref))continue;
                $id=absint($ref);
                if(!$id || get_post_type($id)!=='properties' || get_post_status($id)!=='publish')continue;
                $canonical=self::canonical($id,'properties');
                if($canonical && get_post_status($canonical)==='publish')
                    $used[$canonical][$rate_id]=true;
            }
        }
        return $used;
    }

    private static function explicit_true($value) {
        return in_array(strtolower(trim((string)$value)),['1','true','yes','required','on'],true);
    }

    /**
     * Only propose a locality from an existing, geocoded first-party taxonomy.
     * A city centre coordinate is not proof of the station's city/zone.
     */
    /**
     * The readiness scanner must read source-owned station relations, not the
     * public archive query. Public destination filters intentionally suppress
     * internal pickup localities, but this is not a missing source relation.
     * Only exact station taxonomy relationships are inspected; publication
     * visibility is never changed by scanning.
     */
    private static function station_destinations_source_owned($post_id) {
        global $wpdb;
        $query=$wpdb->prepare(
            "SELECT tt.term_id FROM {$wpdb->term_relationships} rel
              INNER JOIN {$wpdb->term_taxonomy} tt
                 ON rel.term_taxonomy_id=tt.term_taxonomy_id
             WHERE rel.object_id=%d AND tt.taxonomy=%s",
            absint($post_id),'destinations'
        );
        $ids=$wpdb->get_col($query);
        if(!is_array($ids))return new WP_Error('geo_station_source_term_query_failed');
        $terms=[];
        foreach($ids as $term_id){
            $term=get_term((int)$term_id,'destinations');
            if($term instanceof WP_Term && (int)$term->term_id===(int)$term_id)
                $terms[]=$term;
        }
        return $terms;
    }

    private static function locality_candidate($raw) {
        $point=self::parse_coordinates($raw);
        if(!$point || !taxonomy_exists('destinations'))return [];
        $terms=get_terms(['taxonomy'=>'destinations','hide_empty'=>false]);
        if(is_wp_error($terms))return [];
        $matches=[];
        foreach($terms as $term){
            $level=sanitize_key((string)get_term_meta($term->term_id,'destination_type',true));
            if(!in_array($level,['city','zone_area'],true))continue;
            $termPoint=self::parse_coordinates(get_term_meta($term->term_id,'google_location',true));
            if(!$termPoint)continue;
            $a=sin(deg2rad(($point[0]-$termPoint[0])/2))**2+
                cos(deg2rad($point[0]))*cos(deg2rad($termPoint[0]))*
                sin(deg2rad(($point[1]-$termPoint[1])/2))**2;
            $distance=6371*2*asin(min(1,sqrt(max(0,$a))));
            if($distance<=25)$matches[]=['term_id'=>(int)$term->term_id,
                'level'=>$level,'distance_km'=>round($distance,2)];
        }
        usort($matches,static fn($a,$b)=>$a['distance_km']<=>$b['distance_km']);
        return ['candidates'=>array_slice($matches,0,3),
            'auto_write_authorized'=>false,'validation'=>'needs_source_confirmation'];
    }
    private static function parse_coordinates($raw) {
        if(is_array($raw)){
            $lat=$raw['lat']??$raw['latitude']??null;
            $lng=$raw['lng']??$raw['longitude']??null;
        }elseif(is_string($raw) && preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/D',$raw,$m)){
            $lat=$m[1];$lng=$m[2];
        }else return null;
        if(!is_numeric($lat)||!is_numeric($lng)||
            abs((float)$lat)>90||abs((float)$lng)>180||
            ((float)$lat===0.0&&(float)$lng===0.0))return null;
        return [(float)$lat,(float)$lng];
    }

    public static function discover($cfg) {
        $out=['contract'=>self::CONTRACT,'complete'=>true,'issues'=>[],
            'evaluated'=>[],'skipped_property_pickup_not_required'=>0,
            'nonblocking_price_opportunities'=>0];
        if(!is_array($cfg)||empty($cfg['geo_quality_enabled']))return $out;
        $allowed=(array)($cfg['post_types']??[]);
        $include=static fn($type)=>in_array($type,$allowed,true)&&post_type_exists($type);
        if($include('stations')){
            $ids=self::ids('stations');if(is_wp_error($ids))return ['complete'=>false,'issues'=>[],'error'=>$ids->get_error_code()];
            foreach($ids as $id){
                if(self::canonical($id,'stations')!==$id||!self::public_active_station($id))continue;
                $out['evaluated']['stations']=($out['evaluated']['stations']??0)+1;
                $coords=get_post_meta($id,'station_map_location',true);
                if(!self::coordinates_valid($coords))
                    $out['issues'][]=self::issue('stations',$id,'geo_station_coordinates_invalid','high',
                        'Active public station lacks verified coordinate format; do not infer or overwrite its location.');
                $terms=self::station_destinations_source_owned($id);
                if(is_wp_error($terms))return ['complete'=>false,'issues'=>[],'error'=>'station_taxonomy_lookup_failed'];
                if(!$terms){
                    $out['issues'][]=self::issue('stations',$id,'geo_station_destination_unlinked','medium',
                        'Active station has coordinates but no Destination relationship. Review trusted city/zone taxonomy; do not auto-geocode.',
                        ['coordinate_present'=>self::coordinates_valid($coords)]);
                    continue;
                }
                $local=false;$levels=[];
                foreach($terms as $t){
                    $level=sanitize_key((string)get_term_meta($t->term_id,'destination_type',true));
                    $is_public=in_array((string)get_term_meta($t->term_id,'is_public',true),['1','true','yes'],true);
                    $internal=(string)get_term_meta($t->term_id,'_mad4b_station_locality_internal',true)==='1';
                    if(!$is_public && $internal && $level==='zone_area')$levels['verified_internal_station_zone']=true;
                    else $levels[$level?:'unknown']=true;
                    if(in_array($level,['city','town','district','zone','zone_area','neighborhood','locality'],true)
                       && ($is_public || ($internal && $level==='zone_area') ||
                          // Existing older native City terms may omit is_public.
                          ($level==='city' && get_term_meta($t->term_id,'is_public',true)===''))
                    )$local=true;
                }
                if(!$local)
                    $out['issues'][]=self::issue('stations',$id,'geo_station_locality_unverified','medium',
                        'Station is linked only to broad or unknown geography. Verify a matching City/Zone from authoritative taxonomy.',
                        array_merge(['assigned_levels'=>implode(',',array_keys($levels))],
                            self::locality_candidate($coords)));
            }
        }
        if($include('transport-rates')){
            $ids=self::ids('transport-rates');if(is_wp_error($ids))return ['complete'=>false,'issues'=>[]];
            foreach($ids as $id){
                if(self::canonical($id,'transport-rates')!==$id)continue;
                $out['evaluated']['transport-rates']=($out['evaluated']['transport-rates']??0)+1;
                $end=(string)get_post_meta($id,'valid_to',true);
                $when=$end && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$end)?strtotime($end.' 23:59:59 UTC'):false;
                if($when && $when<time()){
                    $out['issues'][]=self::issue('transport-rates',$id,'geo_transport_rate_expired','high',
                        'Published transport rate has an expired validity period. Requires supplier renewal; do not reuse as current pricing.',
                        ['valid_to'=>$end,'source_post_id'=>absint(get_post_meta($id,'source_post_id',true))]);
                }
            }
        }
        if($include('transportations')){
            $ids=self::ids('transportations');if(is_wp_error($ids))return ['complete'=>false,'issues'=>[]];
            foreach($ids as $id){
                if(self::canonical($id,'transportations')!==$id||!self::transport_available($id))continue;
                $out['evaluated']['transportations']=($out['evaluated']['transportations']??0)+1;
                $mode=get_post_meta($id,'service_mode',true);
                if(is_array($mode))$mode=reset($mode);
                $mode=sanitize_key((string)$mode);
                if($mode==='fixed_route_schedule'){
                    $from=absint(get_post_meta($id,'trans_from_station_id',true));
                    $to=absint(get_post_meta($id,'trans_to_station_id',true));
                    if(!$from||!$to||$from===$to||get_post_type($from)!=='stations'||
                       get_post_type($to)!=='stations'||get_post_status($from)!=='publish'||
                       get_post_status($to)!=='publish')
                        $out['issues'][]=self::issue('transportations',$id,'geo_transport_route_stations_invalid','high',
                            'Active fixed-route service has missing or invalid origin/destination station bindings.',
                            ['from_station_id'=>$from,'to_station_id'=>$to]);
                }
                $hasQuote=false;
                foreach(['trans_min_rate_id_default','trans_start_price_quote','trans_start_price','trans_sale_start_price'] as $key)
                    if(trim((string)get_post_meta($id,$key,true))!==''){$hasQuote=true;break;}
                if(!$hasQuote){
                    $hasCurrentRate=false;
                    if($include('transport-rates')){
                        $linked=get_posts(['post_type'=>'transport-rates','post_status'=>'publish',
                            'fields'=>'ids','posts_per_page'=>30,'suppress_filters'=>true,
                            'meta_key'=>'source_post_id','meta_value'=>$id]);
                        foreach($linked as $rate){
                            $end=(string)get_post_meta($rate,'valid_to',true);
                            if($end && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$end)
                               && strtotime($end.' 23:59:59 UTC')>=time()){$hasCurrentRate=true;break;}
                        }
                    }
                    if(!$hasCurrentRate){
                        $out['nonblocking_price_opportunities']++;
                        $out['issues'][]=self::issue('transportations',$id,'geo_transport_price_evidence_unverified','low',
                            'Active public transport has no verified current rate or quote in known native fields. Price review opportunity only; never block an otherwise valid Tour base price.',
                            ['service_mode'=>$mode,'nonblocking'=>true]);
                    }
                }
            }
        }
        if($include('properties')){
            $ids=self::ids('properties');if(is_wp_error($ids))return ['complete'=>false,'issues'=>[]];
            $linked=self::linked_property_rates();
            foreach($ids as $id){
                if(self::canonical($id,'properties')!==$id)continue;
                $out['evaluated']['properties']=($out['evaluated']['properties']??0)+1;
                // The record being published is not proof that pickup is required.
                // Legacy prop_puplic is frequently unset: do not gate discovery on it.
                $boarding=self::explicit_true(get_post_meta($id,'boarding_point_required',true));
                $pickup=self::explicit_true(get_post_meta($id,'pickup_point_required',true));
                $cruise=self::cruise_term($id);
                $linkedRates=array_keys($linked[$id]??[]);
                $hasBoarding=trim((string)get_post_meta($id,'cruise_boarding_point',true))!=='';
                $hasMap=trim((string)get_post_meta($id,'property_map',true))!=='';
                if($cruise && $boarding && !$hasBoarding && !$hasMap)
                    $out['issues'][]=self::issue('properties',$id,'geo_cruise_boarding_unverified','medium',
                        'Cruise explicitly requires boarding, but no verified berth is present.',
                        ['required_by'=>'boarding_point_required','related_rate_ids'=>array_slice($linkedRates,0,5)]);
                elseif($cruise && $linkedRates && !$boarding && !$hasBoarding)
                    $out['issues'][]=self::issue('properties',$id,'geo_cruise_boarding_applicability_unverified','medium',
                        'Cruise is linked to published Tour Rates. Confirm whether a fixed boarding berth applies before requesting location research.',
                        ['required_by'=>'tour_rate_relation','related_rate_ids'=>array_slice($linkedRates,0,5),
                         'boarding_required_unverified'=>true,'no_assumed_berth'=>true]);
                if(!$cruise && $pickup && !$hasMap &&
                    trim((string)get_post_meta($id,'property_pickup_location',true))==='')
                    $out['issues'][]=self::issue('properties',$id,'geo_property_pickup_unverified','medium',
                        'Property explicitly requires pickup but no verified pickup point is present.',
                        ['required_by'=>'pickup_point_required','no_assumed_coordinates'=>true]);
                if(!$boarding && !$pickup && !($cruise && $linkedRates))
                    $out['skipped_property_pickup_not_required']++;
            }
        }
        if(count($out['issues'])>1200)return ['complete'=>false,'issues'=>[],'error'=>'geo_quality_issue_cap'];
        return $out;
    }
}


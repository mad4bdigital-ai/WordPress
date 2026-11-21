<?php
/**
 * Plugin Name: MAD4B Dynamic Location Resolver
 * Description: Source-grounded and provider-aware, read-only resolution plans for existing Content Jobs.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Dynamic_Location_Resolver {
    const CONTRACT='mad4b.dynamic-location-resolver.v1';
    const CATEGORIES=[
        'airport_arrival','airport_or_rail_arrival','rail_arrival',
        'cruise_berth','hotel_pickup','fixed_meeting_point','city_only_departure','undetermined'
    ];
    private static function evidence($case) {
        $post_id=absint($case['post_id']??0);
        $job=(string)($case['job_id']??'');
        $type=get_post_type($post_id);
        if(!$post_id || !$job || $type!=='tours-and-activities' || get_post_status($post_id)!=='publish')
            return new WP_Error('mad4b_location_invalid_source');
        $issue=(string)($case['issue_id']??'');
        $rows=(array)get_option('mad4b_content_readiness_issues_v1',[]);
        $row=$rows[$issue]??null;
        if(!is_array($row) || ($row['status']??'')!=='open' ||
            ($row['content_job_id']??'')!==$job ||
            ($row['code']??'')!==($case['issue_code']??'') ||
            (int)($row['object_id']??0)!==$post_id)
            return new WP_Error('mad4b_location_issue_job_mismatch');
        $itinerary=(string)get_post_meta($post_id,'itinerary_item_description',true);
        $first=trim(wp_strip_all_tags(explode('|',$itinerary)[0]));
        $highlights=get_post_meta($post_id,'tour_highlights_content',true);
        $highlights=is_string($highlights)?trim(wp_strip_all_tags($highlights)):'';
        $terms=wp_get_object_terms($post_id,'destinations');
        if(is_wp_error($terms))return $terms;
        $places=[];
        foreach($terms as $term){
            $type=sanitize_key((string)get_term_meta($term->term_id,'destination_type',true));
            if(in_array($type,['continent','country'],true))continue;
            if(strlen((string)$term->name)>75)continue;
            $places[]=['name'=>(string)$term->name,'term_id'=>(int)$term->term_id,'level'=>$type];
        }
        usort($places,static fn($a,$b)=>strcmp($a['name'],$b['name']));
        $site=class_exists('MAD4B_SCP_Site_Profile')?
            (string)MAD4B_SCP_Site_Profile::site_uuid():'';
        if($site==='')return new WP_Error('mad4b_location_site_binding_missing');
        $language=(string)apply_filters('wpml_post_language_details',null,$post_id)['language_code']??'';
        if(!in_array($language,['en','ar','fr','de','es','it'],true))$language='en';
        return ['post_id'=>$post_id,'issue_id'=>$issue,'job_id'=>$job,
            'site_uuid'=>$site,'code'=>$case['issue_code']??'',
            'title'=>(string)get_the_title($post_id),
            'first_day'=>$first,'highlights'=>$highlights,
            'destinations'=>$places,'locale'=>$language,
            'source_sha256'=>hash('sha256',wp_json_encode([
                $first,$highlights,(string)get_the_title($post_id),$places,
                $case['issue_code']??'',$site,$job,$issue
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
    }
    private static function query($name,$source,$kind='candidate') {
        $name=trim(preg_replace('/\s+/u',' ',$name));
        if($name==='' || mb_strlen($name)>130)return null;
        return ['query'=>$name,'source'=>$source,'kind'=>$kind,
            'maps_url'=>'https://www.google.com/maps/search/?api=1&query='.rawurlencode($name),
            'osm_url'=>'https://www.openstreetmap.org/search?query='.rawurlencode($name)];
    }
    public static function resolve($case){
        $e=self::evidence($case);
        if(is_wp_error($e))return $e;
        $first=$e['first_day'];$high=$e['highlights'];$title=$e['title'];
        $location='undetermined';
        $reasons=[];$queries=[];$requires=['source_fact_review','supplier_confirmation'];
        $cities=array_values(array_map(static fn($p)=>$p['name'],$e['destinations']));
        $city_hint=implode(', ',array_slice($cities,0,2));
        $hotel=($e['code']==='pickup_guest_location_capture_unverified') ||
          ((bool)preg_match('/\bhotel\s+pick[ -]?up\b/iu',$high) &&
           !(bool)preg_match('/\b(?:no|not|without|excluded|not included)\b.{0,36}\bhotel\s+pick[ -]?up\b/iu',$high));
        if($hotel){
            $location='hotel_pickup';
            $reasons[]='Published tour refers to guest hotel pickup; no fixed map pin applies.';
            $requires=['guest_hotel_location_collection','transfer_service_boundary_validation'];
        } else {
            // Day-one evidence determines whether the exact airport/station is a research candidate.
            $airports=[];
            foreach(['Cairo International Airport','Luxor International Airport','Aswan International Airport'] as $named) {
                if(stripos($first,$named)!==false)$airports[]=$named.', Egypt';
            }
            if(stripos($first,'Luxor Airport')!==false && !in_array('Luxor International Airport, Egypt',$airports,true))
                $airports[]='Luxor Airport, Egypt';
            if(stripos($first,'Aswan Airport')!==false && !in_array('Aswan International Airport, Egypt',$airports,true))
                $airports[]='Aswan Airport, Egypt';
            $train=(bool)preg_match('/\b(?:train station|train terminal|railway station)\b/iu',$first);
            $airport=(bool)preg_match('/\b(?:airport|flight arrival)\b/iu',$first);
            $cruise=(bool)preg_match('/\b(?:cruise ship|cruise boat|nile cruise|embark|boarding)\b/iu',$first) ||
              (bool)preg_match('/\bNile Cruise\b/iu',$title);
            if($airport && $train)$location='airport_or_rail_arrival';
            elseif($airport)$location='airport_arrival';
            elseif($train)$location='rail_arrival';
            elseif($cruise)$location='cruise_berth';
            if($location==='undetermined' && preg_match('/\bmeeting point\s*(?:at|is|:)\s*([^.,;|]{5,85})/iu',$first,$m)){
                $location='fixed_meeting_point';
                $queries[]=self::query($m[1],'published_first_day_explicit_meeting_point');
            }
            foreach($airports as $name)$queries[]=self::query($name,'published_first_day_named_airport');
            if($train){
                $train_city='';
                foreach(['Cairo','Luxor','Aswan'] as $city)if(stripos($first,$city)!==false){$train_city=$city;break;}
                if($train_city)$queries[]=self::query($train_city.' Railway Station, Egypt','published_first_day_rail_city','research_only');
            }
            if(!$airports && $airport)$reasons[]='Arrival airport unspecified; cannot assume the exact airport from destination taxonomy.';
            if($cruise){
                $reasons[]='Cruise vessel boarding berth is not the same as airport/railway arrival.';
                $requires[]='operator_cruise_berth_confirmation';
            }
            if($location==='undetermined' && preg_match('/\bfrom\s+(Cairo|Luxor|Aswan|Marsa Alam)\b/iu',$title,$m)){
                $location='city_only_departure';
                $reasons[]='The title names the departure city but no exact pickup or meeting point.';
            }
            if(!$queries)$reasons[]='No unique location stated in first-day itinerary; manual research required.';
        }
        $queries=array_values(array_filter($queries));
        $seen=[];$queries=array_values(array_filter($queries,static function($q) use (&$seen){
            if(isset($seen[$q['query']]))return false;
            $seen[$q['query']]=true;return true;
        }));
        $key=(string)get_option('elementor_google_maps_api_key','');
        $browser_key=($key!=='' && strlen($key)>=12);
        $providers=[
            ['provider'=>'google_places_browser','mode'=>'human_interactive','available'=>$browser_key,
             'verification'=>'runtime_not_proven','requires'=>'Maps JavaScript + Places API (New)'],
            ['provider'=>'google_maps_urls','mode'=>'human_interactive','available'=>true,
             'verification'=>'search_only','requires'=>'no_api_key'],
            ['provider'=>'openstreetmap_manual','mode'=>'human_interactive','available'=>true,
             'verification'=>'search_only','requires'=>'no_api_key'],
            ['provider'=>'supplier_confirmation','mode'=>'human_verification','available'=>true,
             'verification'=>'required_for_operational_pickup','requires'=>'source_document'],
        ];
        $out=[
            'contract'=>self::CONTRACT,'site_uuid'=>$e['site_uuid'],
            'post_id'=>$e['post_id'],'issue_id'=>$e['issue_id'],'job_id'=>$e['job_id'],
            'locale'=>$e['locale'],'location_kind'=>$location,
            'source_sha256'=>$e['source_sha256'],
            'source_class'=>'published_first_day_and_tour_highlights',
            'destination_context'=>$cities,
            'queries'=>$queries,'notes'=>$reasons,'requirements'=>$requires,
            'providers'=>$providers,
            // Prefer the no-key documented URL route until browser Places
            // authorization has a verified runtime receipt, otherwise supplier.
            'selected_provider'=>($location==='hotel_pickup'||!$queries)?
                'supplier_confirmation':'google_maps_urls',
            'fallback_order'=>['google_places_browser','google_maps_urls',
                'openstreetmap_manual','supplier_confirmation'],
            'provider_execution_performed'=>false,
            'can_autofill_pickup'=>false,'can_write_origin'=>false,'can_close_alert'=>false,
            'exact_pickup_verified'=>false,'source_confidence'=>'requires_human_confirmation'
        ];
        $out['plan_sha256']=hash('sha256',wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $out;
    }
    public static function get_plan_by_id($issue_id) {
        $issue_id=(string)$issue_id;
        if(!preg_match('/^[a-f0-9]{64}$/D',$issue_id))return new WP_Error('mad4b_location_issue_id_invalid');
        $row=((array)get_option('mad4b_content_readiness_issues_v1',[]))[$issue_id]??null;
        if(!is_array($row)||($row['status']??'')!=='open' ||
           !in_array($row['code']??'', ['origin_missing','pickup_guest_location_capture_unverified'],true) ||
           ($row['post_type']??'')!=='tours-and-activities' ||
           empty($row['content_job_id']))
            return new WP_Error('mad4b_location_issue_unavailable');
        $post_id=absint($row['object_id']??0);
        $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$row['content_job_id']]);
        if(is_wp_error($job) || !is_array($job['job']??null) ||
           ($job['job']['site_uuid']??'')!==MAD4B_SCP_Site_Profile::site_uuid())
            return new WP_Error('mad4b_location_tenant_scope_invalid');
        return self::resolve(['issue_id'=>$issue_id,'post_id'=>$post_id,
            'job_id'=>$row['content_job_id'],'issue_code'=>$row['code']]);
    }
    public static function register_ability(){
        if(!function_exists('wp_register_ability') ||
           (function_exists('wp_has_ability') && wp_has_ability('mad4b/location-resolution-plan')))return;
        wp_register_ability('mad4b/location-resolution-plan',[
            'label'=>'Read Dynamic Location Resolution Plan',
            'description'=>'Read-only source-backed provider plan for an exact issue and Content Job; never asserts pickup or authorizes writeback.',
            'category'=>'mad4b-read',
            'execute_callback'=>[__CLASS__,'ability_plan'],
            'permission_callback'=>['MAD4B_SCP_Policy','can_read'],
            'input_schema'=>['type'=>'object','properties'=>[
                'issue_id'=>['type'=>'string','minLength'=>64,'maxLength'=>64]
            ],'required'=>['issue_id'],'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['public'=>false,'show_in_rest'=>false,
                'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
        ]);
    }
    public static function ability_plan($input=[]){
        return self::get_plan_by_id($input['issue_id']??'');
    }
}
add_action('wp_abilities_api_init',['MAD4B_Dynamic_Location_Resolver','register_ability'],44);

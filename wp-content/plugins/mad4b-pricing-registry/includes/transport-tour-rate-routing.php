<?php
/**
 * Route-aware, source-verified Tour Rate transportation candidate planner.
 *
 * Separates:
 *  1. editor opt-in (trans_use_in_tour_rate_generation=1)
 *  2. PDF geography at city/landmark resolution
 *  3. explicit stage origin/destination and OR/AND composition
 *  4. native publication, availability, pricing and checkout approval
 *
 * No implicit exact pickup/dropoff, reverse routes, stock or sale quotes.
 */
defined('ABSPATH') || exit;
final class MAD4B_Transport_Tour_Rate_Routing_V1 {
    public const CONTRACT='mad4b.transport-tour-rate-route-candidates.v1';
    public const META='_mad4b_transport_stages_v1';
    public const PDF='c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd';
    public const ALLOWED_JOIN=['OR','AND'];
    private const ALLOWED_SHAPE=[
      'one_way_city','round_trip','city_area','sightseeing_local',
      'sightseeing_target','facility_unspecified','facility_to_hotel',
      'hotel_unspecified_origin','airport_to_named_hotel','mixed_local',
      'open_route','ambiguous_alternatives','operational_extra',
      'multi_day_target','mixed_unpriced'
    ];
    public static function source():array {
        $r=require dirname(__DIR__).'/data/transport-pdf-2026-route-evidence.php';
        $d=require dirname(__DIR__).'/data/transport-pdf-2026-localized.php';
        if(!is_array($r)||!is_array($d) ||
           ($r['source_sha256']??null)!==self::PDF ||
           ($d['source_sha256']??null)!==self::PDF ||
           count($r['entries']??[])!==51 || count($d['rows']??[])!==51)
            return [];
        $keySource=array_column($d['rows'],null,'source_key');
        if(array_diff(array_keys($r['entries']),array_keys($keySource)) ||
           array_diff(array_keys($keySource),array_keys($r['entries'])))return [];
        foreach($r['entries'] as $key=>$item) {
            if(!in_array($item['route_shape']??'',self::ALLOWED_SHAPE,true) ||
               ($item['source_key']??'')!==$key ||
               ($item['origin_slug']??'')!==
                 ['Cairo'=>'cairo','Aswan'=>'aswan-gov','Luxor'=>'luxor'][$keySource[$key]['city']])
                return [];
        }
        return ['route'=>$r,'pdf'=>$keySource];
    }
    public static function permitted():bool {
        return class_exists('MAD4B_Transport_Self_Pricing_Controls_V1') &&
            MAD4B_Transport_Self_Pricing_Controls_V1::permitted();
    }
    private static function canonical_term(int $id):?WP_Term {
        if($id<=0)return null;
        $term=get_term($id,'destinations');
        if(!($term instanceof WP_Term))return null;
        $en=(int)apply_filters('wpml_object_id',$id,'destinations',true,'en');
        if($en>0){
            $test=get_term($en,'destinations');
            if($test instanceof WP_Term)$term=$test;
        }
        return $term;
    }
    public static function slug_from_term(int $id):string {
        $term=self::canonical_term($id);
        return $term instanceof WP_Term?(string)$term->slug:'';
    }
    /** Accept a city term or a verified child of the named city/landmark. */
    public static function term_within_slug(int $termId,string $expectedSlug):bool {
        $term=self::canonical_term($termId);
        $target=get_term_by('slug',$expectedSlug,'destinations');
        if(!$term||!($target instanceof WP_Term))return false;
        $targetId=(int)$target->term_id;
        for($level=0;$level<10&&$term instanceof WP_Term;$level++){
            if((int)$term->term_id===$targetId)return true;
            if(!$term->parent)break;
            $term=get_term((int)$term->parent,'destinations');
        }
        return false;
    }
    /**
     * Editor stage specification; no tour origin is guessed from a country,
     * nationality or taxonomy grouping.
     */
    public static function validate_stage(array $stage) {
        $allowed=['key','label','day_offset','origin_term_id',
            'destination_term_id','return_to_origin','required',
            'join','service_kind','price_policy'];
        if(array_diff(array_keys($stage),$allowed))
            return new WP_Error('transport_stage_unknown_fields');
        $key=(string)($stage['key']??'');
        if(!preg_match('/^[a-zA-Z0-9_-]{2,60}$/D',$key))
            return new WP_Error('transport_stage_invalid_key');
        $label=trim((string)($stage['label']??''));
        if($label===''||mb_strlen($label)>180)
            return new WP_Error('transport_stage_invalid_label');
        $day=filter_var($stage['day_offset']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>0,'max_range'=>365]]);
        if($day===false || $day===null)
            return new WP_Error('transport_stage_invalid_day');
        $origin=filter_var($stage['origin_term_id']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if($origin===false || $origin===null ||
           self::canonical_term((int)$origin)===null)
            return new WP_Error('transport_stage_origin_missing');
        $dest=$stage['destination_term_id']??0;
        if($dest===''||$dest===null)$dest=0;
        if(!is_scalar($dest) || !ctype_digit((string)$dest))
            return new WP_Error('transport_stage_destination_invalid');
        $dest=(int)$dest;
        if($dest>0 && self::canonical_term($dest)===null)
            return new WP_Error('transport_stage_destination_not_found');
        $join=strtoupper((string)($stage['join']??'OR'));
        if(!in_array($join,self::ALLOWED_JOIN,true))
            return new WP_Error('transport_stage_join_invalid');
        $required=$stage['required']??true;
        $return=$stage['return_to_origin']??false;
        if(!is_bool($required)||!is_bool($return))
            return new WP_Error('transport_stage_boolean_invalid');
        $price=(string)($stage['price_policy']??'inquiry');
        if(!in_array($price,['inquiry','included','additional'],true))
            return new WP_Error('transport_stage_price_policy_invalid');
        $kind=(string)($stage['service_kind']??'any');
        if(!in_array($kind,['any','transfer','sightseeing','facility','extra'],true))
            return new WP_Error('transport_stage_kind_invalid');
        return [
            'key'=>$key,'label'=>$label,'day_offset'=>$day,
            'origin_term_id'=>(int)$origin,'destination_term_id'=>$dest,
            'origin_slug'=>self::slug_from_term((int)$origin),
            'destination_slug'=>$dest?self::slug_from_term($dest):'',
            'return_to_origin'=>$return,'required'=>$required,
            'join'=>$join,'price_policy'=>$price,'service_kind'=>$kind,
        ];
    }
    private static function service_type(string $shape,string $sourceKind):string {
        if(in_array($sourceKind,['sightseeing_disposal','full_day_disposal',
            'half_day_disposal'],true))return 'sightseeing';
        if($sourceKind==='overnight_extra'||$shape==='operational_extra')return 'extra';
        if(in_array($shape,['facility_unspecified','facility_to_hotel',
          'airport_to_named_hotel','hotel_unspecified_origin'],true))return 'facility';
        return 'transfer';
    }
    private static function match(array $stage,array $e,string $sourceKind):array {
        $reason=[];
        // origin is a hard filter: never confuse matching a destination with
        // the direction of a one-way transfer.
        if(!self::term_within_slug($stage['origin_term_id'],$e['origin_slug']))
            return ['grade'=>'excluded','reason'=>['origin_city_mismatch'],'score'=>0];
        if($stage['service_kind']!=='any' &&
           $stage['service_kind']!==self::service_type($e['route_shape'],$sourceKind))
            return ['grade'=>'excluded','reason'=>['service_type_mismatch'],'score'=>0];
        $dest=$stage['destination_term_id'];
        $shape=$e['route_shape'];
        $known=$e['dest_slugs'];
        $targetMatch=false;
        foreach($known as $slug)
            if($dest>0&&self::term_within_slug($dest,$slug))$targetMatch=true;
        if($shape==='one_way_city'){
            if(!$dest)return ['grade'=>'review','reason'=>['stage_destination_required'],'score'=>30];
            if(!$targetMatch)return ['grade'=>'excluded','reason'=>['destination_mismatch_or_reverse_direction'],'score'=>0];
            $reason[]='city_pair_confirmed_pickup_dropoff_points_missing';
            return ['grade'=>'city_match','reason'=>$reason,'score'=>90];
        }
        if($shape==='round_trip'){
            if(!$dest)return ['grade'=>'review','reason'=>['stage_destination_required'],'score'=>30];
            if(!$targetMatch)return ['grade'=>'excluded','reason'=>['round_trip_destination_mismatch'],'score'=>0];
            if(!$stage['return_to_origin'])
                return ['grade'=>'review','reason'=>['round_trip_return_required'],'score'=>40];
            if(count($known)>1)
                return ['grade'=>'review','reason'=>[
                    'multi_stop_stage_confirmation_required','round_trip_points_unverified'
                ],'score'=>45];
            return ['grade'=>'city_match','reason'=>['round_trip_points_unverified'],'score'=>88];
        }
        if($shape==='ambiguous_alternatives' || $shape==='mixed_unpriced'){
            if($dest && !$targetMatch)return ['grade'=>'excluded',
                'reason'=>['alternative_destination_mismatch'],'score'=>0];
            return ['grade'=>'review','reason'=>[
                $shape==='mixed_unpriced'?'source_all_prices_missing':'alternative_route_requires_split',
                'source_pickup_dropoff_unverified'
            ],'score'=>25];
        }
        if(in_array($shape,['sightseeing_target','multi_day_target'],true)){
            if($dest&&!$targetMatch)return ['grade'=>'excluded',
                'reason'=>['destination_mismatch'],'score'=>0];
            return ['grade'=>'review',
                'reason'=>['direction_or_return_not_proven','pickup_dropoff_missing'],
                'score'=>35];
        }
        if($shape==='operational_extra')
            return ['grade'=>'review','reason'=>['operational_extra_not_route'],'score'=>5];
        // Local services may belong to a city, but that does not prove
        // a specific point-to-point stage. Do not silently auto-select them.
        if($dest && !self::term_within_slug($dest,$e['origin_slug']))
            return ['grade'=>'excluded','reason'=>['local_service_wrong_destination'],'score'=>0];
        return ['grade'=>'review','reason'=>[
            'city_area_only','pickup_dropoff_and_direction_unverified'
        ],'score'=>20];
    }
    public static function candidates(array $input,string $lang='en') {
        $stage=self::validate_stage($input);
        if(is_wp_error($stage))return $stage;
        $src=self::source();
        if(!$src)return new WP_Error('transport_route_source_integrity_failed');
        $languages=array_keys((array)apply_filters('wpml_active_languages',null,
            ['skip_missing'=>0]));
        if(!in_array($lang,$languages,true))return new WP_Error('transport_stage_language_not_active');
        $ids=get_posts([
            'post_type'=>'transportations','post_status'=>['draft','publish'],
            'posts_per_page'=>650,'fields'=>'ids','suppress_filters'=>true,
            'meta_key'=>'_mad4b_transfer_pdf_2026_sha256','meta_value'=>self::PDF
        ]);
        $byKey=[];$seen=[];
        foreach($ids as $id){
            $id=(int)$id;
            if((string)get_post_meta($id,'_mad4b_transfer_pdf_2026_editorial_language',true)!==$lang)
                continue;
            $key=(string)get_post_meta($id,'_mad4b_transfer_pdf_2026_source_key',true);
            if(!isset($src['route']['entries'][$key]))continue;
            $byKey[$key][]=$id;
        }
        $candidate=[];$review=[];$excluded=0;
        foreach($src['route']['entries'] as $key=>$e){
            if(count($byKey[$key]??[])!==1){$excluded++;continue;}
            $id=$byKey[$key][0];
            // This field is mandatory, including for preview-mode Drafts.
            if((string)get_post_meta($id,'trans_use_in_tour_rate_generation',true)!=='1'){
                $excluded++;continue;
            }
            if(get_post_meta($id,'_mad4b_transfer_pdf_2026_provider_id',true)!=='self'){
                $excluded++;continue;
            }
            $row=$src['pdf'][$key];
            $match=self::match($stage,$e,(string)$row['service_kind']);
            if($match['grade']==='excluded'){$excluded++;continue;}
            $elig=MAD4B_Transport_Tour_Component_Eligibility_V1::inspection($id);
            $warnings=array_values(array_unique(array_merge(
                $match['reason'],$elig['reason_codes'],
                ['source_currency_and_price_role_unconfirmed']
            )));
            $record=[
                'post_id'=>$id,'source_key'=>$key,
                'edit_url'=>current_user_can('edit_post',$id)
                    ?get_edit_post_link($id,'raw'):null,
                'title'=>$row['titles'][$lang]??$row['titles']['en'],
                'origin_city'=>$row['city'],'source_ar'=>$row['source_ar'],
                'origin_slug'=>$e['origin_slug'],'destination_slugs'=>$e['dest_slugs'],
                'route_shape'=>$e['route_shape'],'source_evidence'=>$e['source_note'],
                'match_grade'=>$match['grade'],'score'=>$match['score'],
                'operator'=>$stage['join'],'stage_key'=>$stage['key'],
                'requires_editor_review'=>true,
                'blocking_reasons'=>$warnings,
                'post_status'=>get_post_status($id),'opted_in'=>true,
                'commercial_ready'=>false,'rate_approved'=>false,
                'checkout_authorized'=>false
            ];
            if($match['grade']==='city_match')$candidate[]=$record;
            else $review[]=$record;
        }
        usort($candidate,static fn($a,$b)=>$b['score']<=>$a['score'] ?:
            strcmp($a['source_key'],$b['source_key']));
        usort($review,static fn($a,$b)=>$b['score']<=>$a['score'] ?:
            strcmp($a['source_key'],$b['source_key']));
        return [
            'contract'=>self::CONTRACT,'stage'=>$stage,'lang'=>$lang,
            'source_sha256'=>self::PDF,'qualified_for_draft_composition'=>$candidate,
            'needs_route_review'=>$review,'excluded_count'=>$excluded,
            'yes_field_required'=>true,'destination_match_required_for_route'=>true,
            'automatic_commercial_selection'=>false,
            'price_calculation_performed'=>false,'checkout_authorized'=>false,
        ];
    }
    public static function composition(array $input,string $lang='en') {
        $mode=(string)($input['mode']??'');
        if(!in_array($mode,['simple','stages'],true))
            return new WP_Error('transport_composition_mode_invalid');
        $stages=$input['stages']??null;
        if(!is_array($stages) || !$stages || count($stages)>30 ||
            ($mode==='simple'&&count($stages)!==1))
            return new WP_Error('transport_composition_stage_count_invalid');
        $keys=[];$out=[];$hasBlocking=false;$eligible=0;
        foreach($stages as $s) {
            if(!is_array($s))return new WP_Error('transport_stage_not_an_object');
            $r=self::candidates($s,$lang);
            if(is_wp_error($r))return $r;
            $key=$r['stage']['key'];
            if(isset($keys[$key]))return new WP_Error('transport_stage_key_duplicate');
            $keys[$key]=true;
            $candidateCount=count($r['qualified_for_draft_composition']);
            $needs=count($r['needs_route_review']);
            if(!$candidateCount && $r['stage']['required'])$hasBlocking=true;
            $eligible+=$candidateCount;
            $out[]=[
                'stage'=>$r['stage'],
                'join_operator'=>$r['stage']['join'],
                'required'=>$r['stage']['required'],
                'alternatives'=>$r['qualified_for_draft_composition'],
                'review_queue'=>$r['needs_route_review'],
                'candidate_count'=>$candidateCount,'needs_review_count'=>$needs,
                'commercial_ready'=>false
            ];
        }
        return [
            'contract'=>'mad4b.transport-tour-rate-composition.v1',
            'mode'=>$mode,
            'between_required_stages_operator'=>'AND',
            'within_stage_operator'=>'OR_or_AND_per_stage',
            'stages'=>$out,'draft_eligible_candidate_count'=>$eligible,
            'has_missing_required_stage'=>$hasBlocking,
            'candidate_ready_for_sale'=>false,
            'requires_route_points'=>true,'source_currency_verified'=>false,
            'price_calculation_performed'=>false,'checkout_authorized'=>false
        ];
    }
    public static function rest():void {
        register_rest_route('mad4b-pricing/v1','/transport-tour-rate-candidates',[
            'methods'=>'POST',
            'callback'=>static function($request){
                $payload=$request->get_json_params();
                if(!is_array($payload))return new WP_Error('transport_invalid_json',
                    'Expected JSON object',['status'=>400]);
                $lang=sanitize_key((string)($payload['lang']??'en'));
                $mode=(string)($payload['mode']??'simple');
                $stages=$payload['stages']??[];
                $rateId=(int)($payload['tour_rate_id']??0);
                if($rateId){
                    if(get_post_type($rateId)!=='tour-rates')
                        return new WP_Error('transport_tour_rate_invalid','Not a Tour Rate',['status'=>400]);
                    if(!current_user_can('edit_post',$rateId))
                        return new WP_Error('transport_tour_rate_forbidden','Not authorized',['status'=>403]);
                }
                $value=self::composition(['mode'=>$mode,'stages'=>$stages],$lang);
                return is_wp_error($value)
                    ?new WP_Error($value->get_error_code(),$value->get_error_message(),['status'=>400])
                    :rest_ensure_response($value);
            },
            'permission_callback'=>[__CLASS__,'permitted']
        ]);
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'rest']);
    }
}
MAD4B_Transport_Tour_Rate_Routing_V1::boot();

<?php
/**
 * Plugin Name: MAD4B Itinerary Overnight Source and Stage Synchronizer
 * Version: 1.2.1
 * Description: Structured itinerary overnight fields are the sole night-geometry source; editor sync is guarded, non-commercial and WPML-safe.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Itinerary_Overnight_Source_V1',false)) {
final class MAD4B_Itinerary_Overnight_Source_V1 {
    public const F_KIND='mad4b_overnight_kind';
    public const F_DEST='mad4b_overnight_destination';
    public const PLAN='_mad4b_package_accommodation_source_plan_v1';
    public const BIND='_mad4b_package_stay_verified_room_program_links_v1';
    public const PROJECTION='_mad4b_itinerary_overnight_projection_v1';
    public const STATUS='_mad4b_itinerary_overnight_sync_state_v1';
    private static array $queued=[];
    private static bool $running=false;
    private static function digest($value):string {
        return hash('sha256',wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    public static function canonical(int $post):int {
        if ($post<1 || get_post_type($post)!=='tours-and-activities')return 0;
        $en=(int)apply_filters('wpml_object_id',$post,'tours-and-activities',false,'en');
        return $en>0 && get_post_type($en)==='tours-and-activities' ? $en:$post;
    }
    public static function project(int $post) {
        $source=self::canonical($post);
        if(!$source) return new WP_Error('overnight_not_a_tour');
        return self::projectRows($source,get_post_meta($source,'itinerary_item',true));
    }
    /** Pure parser: test and preview without mutating live WordPress data. */
    public static function projectRows(int $post,$raw) {
        $source=self::canonical($post);
        if(!$source)return new WP_Error('overnight_not_a_tour');
        if(!is_array($raw)||count($raw)<2||count($raw)>45)
            return new WP_Error('overnight_itinerary_day_count_invalid');
        $days=[];$n=count($raw);
        foreach($raw as $key=>$item){
            if($key!=='item-'.count($days)||!is_array($item))
                return new WP_Error('overnight_repeater_day_keys_invalid');
            $i=count($days);
            $kind=sanitize_key((string)($item[self::F_KIND]??''));
            $dest=sanitize_key((string)($item[self::F_DEST]??''));
            if(!$kind||!$dest)return new WP_Error('overnight_structured_fields_incomplete',['day'=>$i+1]);
            if($i===$n-1){
                if($kind!=='none'||$dest!=='none')
                    return new WP_Error('overnight_last_departure_day_must_be_none');
            }elseif($kind==='none'||$dest==='none'){
                return new WP_Error('overnight_non_departure_day_without_night',['day'=>$i+1]);
            }
            $allowed=[
                'hotel'=>['cairo','luxor','aswan','sharm','hurghada'],
                'resort'=>['cairo','luxor','aswan','sharm','hurghada'],
                'cruise'=>['nile_cruise'],
                'sleeper_train'=>['train'],
                'none'=>['none']
            ];
            if(!isset($allowed[$kind])||!in_array($dest,$allowed[$kind],true))
                return new WP_Error('overnight_kind_destination_inconsistent',['day'=>$i+1]);
            $days[]=['day'=>$i+1,'type'=>$kind,'destination'=>$dest];
        }
        $spans=[];$i=0;$groupNo=0;
        while($i<$n-1){
            $start=$i;$kind=$days[$i]['type'];$dest=$days[$i]['destination'];
            do {$i++;}while($i<$n-1&&$days[$i]['type']===$kind&&$days[$i]['destination']===$dest);
            $groupNo++;
            $spans[]=[
                'stage_key'=>$kind.'-'.$dest.'-'.str_pad((string)$groupNo,2,'0',STR_PAD_LEFT),
                'lodging_kind'=>$kind, 'destination_key'=>$dest,
                'source_day_start'=>$start+1,'day_offset'=>$start,'nights'=>$i-$start,
            ];
        }
        if(array_sum(array_column($spans,'nights'))!==$n-1)
            return new WP_Error('overnight_night_sum_does_not_match');
        return [
            'contract'=>'mad4b.itinerary-overnight-source.v1',
            'tour_id'=>$source,
            'source'=>'tours-and-activities.itinerary_item',
            'day_count'=>$n,
            'night_count'=>$n-1,
            'source_digest'=>self::digest($raw),
            'days'=>$days,
            'stages'=>$spans,
            'checkout_authorized'=>false,
        ];
    }
    private static function sameGeometry(array $a,array $b):bool {
        foreach(['stage_key','lodging_kind','destination_key','source_day_start','nights'] as $field)
            if((string)($a[$field]??'')!==(string)($b[$field]??''))return false;
        return true;
    }
    private static function stageFromNew(array $span):array {
        return $span+[
            'traveler_group'=>'all','destination_term_id'=>0,
            'board'=>in_array($span['lodging_kind'],['hotel','resort'],true)
               ?(in_array($span['destination_key'],['sharm','hurghada'],true)?'ai':'bb'):'',
            'hotel_alternatives_or_by_tier'=>[],
            'candidate_provider'=>'shared_product_accommodation_assignment',
            'candidate_property_ids_confirmed'=>false,
            'component_resolution'=>'awaits_native_stage_candidates',
            'price_status'=>'pending_quote','price_amount'=>null,'room_unit_id'=>null,
            'supplier_inventory_confirmed'=>false,
            'booking_authorized'=>false,'native_rate_stage_export_allowed'=>false
        ];
    }
    private static function bindingFromNew(array $span):array {
        return [
            'stage_key'=>$span['stage_key'],
            'lodging_kind'=>$span['lodging_kind'],
            'nights'=>$span['nights'],
            'source_day_start'=>$span['source_day_start'],
            'price_status'=>'pending_quote','price'=>null,
            'native_rate_stage_written'=>false,
            'bookable_unit_ids'=>[],
            'sailing_date'=>null,'selected_vessel_id'=>null,
            'selected_cabin_unit_id'=>null,'selected_property_id'=>null,
            'room_candidate_refs'=>[],
            'cruise_program'=>null,
            'commercial_readiness'=>'structured_itinerary_pending_supplier_selection'
        ];
    }
    public static function reconcile(int $tour,string $mode='apply') {
        if(!in_array($mode,['apply','read'],true))return new WP_Error('overnight_sync_invalid_mode');
        $tour=self::canonical($tour);
        $result=self::project($tour);
        if(is_wp_error($result)){
            if($mode==='apply' && $tour)
                update_post_meta($tour,self::STATUS,[
                  'contract'=>'mad4b.itinerary-overnight-sync-state.v1',
                  'status'=>'requires_editor_review','reason'=>$result->get_error_code(),
                  'commercially_eligible'=>false]);
            return $result;
        }
        $plan=get_post_meta($tour,self::PLAN,true);
        $binding=get_post_meta($tour,self::BIND,true);
        $spans=$result['stages'];
        $matched=false;$geometryChanged=false;$canRebuild=false;$state='structured_source_verified';
        $newPlan=null;$newBinding=null;$blockers=[];
        if(is_array($plan) && !empty($plan['stages'])){
            $oldStages=$plan['stages'];
            $matched=count($oldStages)===count($spans);
            if($matched)foreach($spans as $i=>$span)
                if(!self::sameGeometry($span,$oldStages[$i])){$matched=false;break;}
            if($matched) {
                $state='source_stage_geometry_matches';
                if(!is_array($binding)||
                   !hash_equals(self::digest($plan),(string)($binding['source_plan_sha256']??''))){
                    $blockers[]='existing_binding_digest_stale';
                    $state='needs_binding_reconciliation';
                }
            }else{
                $geometryChanged=true;
                if(!is_array($binding)||($binding['schema']??'')!=='mad4b.package-stay-unit-program-bindings.v1'
                   ||!hash_equals(self::digest($plan),(string)($binding['source_plan_sha256']??''))){
                    $blockers[]='existing_binding_digest_stale';
                }else{
                    foreach((array)$binding['bindings'] as $row){
                        if(!is_array($row))continue;
                        if((int)($row['selected_property_id']??0)>0 ||
                           (array)($row['bookable_unit_ids']??[]) ||
                           !empty($row['supplier_cost_receipt'])||
                           !empty($row['native_rate_stage_written'])||
                           (int)($row['selected_cabin_unit_id']??0)>0||
                           (int)($row['selected_vessel_id']??0)>0){
                           $blockers[]='commercial_or_explicit_stage_selection_present';break;
                        }
                    }
                }
                if(!$blockers) {
                    $lookup=[];
                    foreach($oldStages as $s){
                        if(is_array($s)&&!empty($s['stage_key']))$lookup[$s['stage_key']]=$s;
                    }
                    $bindLookup=[];
                    foreach((array)$binding['bindings'] as $row)
                        if(is_array($row)&&!empty($row['stage_key']))$bindLookup[$row['stage_key']]=$row;
                    $nextStages=[];$nextBindings=[];
                    foreach($spans as $span){
                        $key=$span['stage_key'];
                        $same=isset($lookup[$key])&&self::sameGeometry($span,$lookup[$key]);
                        $nextStages[]=$same ? $lookup[$key] : self::stageFromNew($span);
                        $sameBinding=$same&&isset($bindLookup[$key])&&
                            (int)($bindLookup[$key]['nights']??0)===(int)$span['nights']&&
                            (int)($bindLookup[$key]['source_day_start']??0)===(int)$span['source_day_start'];
                        $nextBindings[]=$sameBinding?$bindLookup[$key]:self::bindingFromNew($span);
                    }
                    $newPlan=$plan;
                    $newPlan['stages']=$nextStages;
                    $newPlan['stages_count']=count($spans);
                    $newPlan['tour_days']=$result['day_count'];
                    $newPlan['tour_nights']=$result['night_count'];
                    $nightSummary=[];
                    foreach($spans as $span){
                        $sumKind=match($span['lodging_kind']) {
                            'sleeper_train'=>'train', default=>$span['lodging_kind']
                        };
                        $nightSummary[$sumKind]=($nightSummary[$sumKind]??0)+(int)$span['nights'];
                    }
                    $newPlan['night_summary']=$nightSummary;
                    $newPlan['source_type']='structured_itinerary_overnights_v1';
                    $newPlan['source_review_status']='structured_itinerary_rederived_requires_supplier_cost_and_inventory';
                    $newPlan['source_sha256']['structured_overnight_source_v1']=$result['source_digest'];
                    $newPlan['price_computed']=false;
                    $newPlan['price_status']='pending_quote';
                    $newPlan['checkout_ready']=false;
                    $newPlan['native_rate_stages_written']=false;
                    $newBinding=$binding;
                    $newBinding['stage_count']=count($nextBindings);
                    $newBinding['bindings']=$nextBindings;
                    $newBinding['source_plan_sha256']=self::digest($newPlan);
                    $newBinding['reconciled_by']='mad4b.itinerary-overnight-source.v1';
                    $canRebuild=true;
                    $state='source_stage_geometry_changed_pending_commercial_requalification';
                }else $state='requires_manual_stage_reconciliation';
            }
        }else $state='structured_source_no_existing_stage_plan';
        $status=[
            'contract'=>'mad4b.itinerary-overnight-sync-state.v1',
            'tour_id'=>$tour,'status'=>$state,
            'source_digest'=>$result['source_digest'],
            'day_count'=>$result['day_count'],
            'night_count'=>$result['night_count'],
            'stage_count'=>count($spans),
            'geometry_matches_existing_plan'=>$matched,
            'geometry_changed'=>$geometryChanged,
            'plan_rebuilt'=>$canRebuild,
            'blockers'=>$blockers,
            'commercially_eligible'=>false,
            'checkout_authorized'=>false
        ];
        if($mode==='apply'){
            if($canRebuild){
                // Fail-closed: the old plan and binding are retained when a write cannot be verified.
                $oldPlan=$plan;$oldBinding=$binding;
                if(!update_post_meta($tour,self::PLAN,wp_slash($newPlan)) ||
                   !update_post_meta($tour,self::BIND,wp_slash($newBinding))||
                   get_post_meta($tour,self::PLAN,true)!==$newPlan||
                   get_post_meta($tour,self::BIND,true)!==$newBinding){
                    update_post_meta($tour,self::PLAN,wp_slash($oldPlan));
                    update_post_meta($tour,self::BIND,wp_slash($oldBinding));
                    return new WP_Error('overnight_stages_atomic_reconciliation_failed');
                }
            }
            update_post_meta($tour,self::PROJECTION,wp_slash($result));
            update_post_meta($tour,self::STATUS,wp_slash($status));
            $es=(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,'es');
            if($es>0 && $es!==$tour && get_post_type($es)==='tours-and-activities')
                self::mirrorSpanish($tour,$es,$result);
        }
        return ['projection'=>$result,'sync'=>$status];
    }
    public static function mirrorSpanish(int $en,int $es,array $projection):void {
        $src=get_post_meta($en,'itinerary_item',true);
        $dst=get_post_meta($es,'itinerary_item',true);
        if(!is_array($src)||!is_array($dst)||array_keys($src)!==array_keys($dst)){
            update_post_meta($en,self::STATUS,[
              'contract'=>'mad4b.itinerary-overnight-sync-state.v1',
              'status'=>'spanish_itinerary_day_count_or_keys_mismatch',
              'commercially_eligible'=>false]);
            return;
        }
        $changed=false;
        foreach($src as $key=>$row){
            if(!is_array($row)||!is_array($dst[$key]))return;
            foreach([self::F_KIND,self::F_DEST] as $field){
                $expected=(string)($row[$field]??'');
                if((string)($dst[$key][$field]??'')!==$expected){
                    $dst[$key][$field]=$expected;$changed=true;
                }
            }
        }
        if($changed)update_post_meta($es,'itinerary_item',wp_slash($dst));
    }
    /**
     * Dynamic editorial candidates for an existing, structured, matching
     * itinerary overnight stage. Does not write selection or approval.
     */
    public static function stageCandidates($prior,int $tour,int $tier,array $stage,array $scope):array {
        $prior=is_array($prior)?$prior:[];
        $parent=self::canonical($tour);
        if(!$parent || !in_array((string)($stage['lodging_kind']??''),['hotel','resort'],true)
           || !in_array((string)($scope['status']??''),['mapped'],true)
           || !function_exists('shared_product_accommodation_assignment')
           || !class_exists('MAD4B_Package_Stage_Rate_Guard_V1',false))return $prior;
        $stored=get_post_meta($parent,self::PROJECTION,true);
        if(!is_array($stored)||($stored['contract']??'')!=='mad4b.itinerary-overnight-source.v1'
           || ($stored['tour_id']??0)!==$parent
           || !hash_equals((string)($stored['source_digest']??''),
                 self::digest(get_post_meta($parent,'itinerary_item',true))))
            return $prior;
        $key=(string)($stage['stage_key']??'');$geometryOK=false;
        foreach((array)($stored['stages']??[]) as $projected) {
            if(($projected['stage_key']??'')===$key&&self::sameGeometry($projected,$stage)) {
               $geometryOK=true;break;
            }
        }
        if(!$geometryOK)return $prior;
        // Discover from the OVERNIGHT STAGE city. Tour search-taxonomy
        // ancestry can omit a genuine night in Aswan or Luxor.
        static $cityTierCache=[];
        $terms=array_values(array_unique(array_filter(array_map('absint',
              (array)($scope['accepted_term_ids']??[])))));
        sort($terms,SORT_NUMERIC);
        if(!$terms)return $prior;
        $cacheKey=implode(',',$terms).'|'.$tier.'|'.($stage['lodging_kind']??'hotel');
        if(isset($cityTierCache[$cacheKey]))
            return array_values(array_unique(array_merge($prior,$cityTierCache[$cacheKey])));
        $found=get_posts([
            'post_type'=>'properties','post_status'=>'publish','fields'=>'ids',
            'posts_per_page'=>250,'orderby'=>'ID','order'=>'ASC',
            'suppress_filters'=>true,
            'tax_query'=>['relation'=>'AND',[
                'taxonomy'=>'destinations','field'=>'term_id',
                'terms'=>$terms,'include_children'=>false,'operator'=>'IN'
            ],[
                'taxonomy'=>'package_levels','field'=>'term_id',
                'terms'=>[$tier],'include_children'=>false,'operator'=>'IN'
            ]],
            'meta_query'=>[[
                'key'=>'prop_use_in_tour_rate_generation','value'=>'1','compare'=>'='
            ]],
        ]);
        $out=[];$seen=[];
        foreach((array)$found as $id){
            $id=absint($id);
            if(!$id||isset($seen[$id]))continue;
            $seen[$id]=true;
            $en=(int)apply_filters('wpml_object_id',$id,'properties',false,'en');
            if($en!==$id)continue;
            $receipt=get_post_meta($id,'_mad4b_company_sheet_property_cost_sources_v1',true);
            if(!is_array($receipt)||($receipt['contract']??'')!=='mad4b.company-property-cost-source-matrix.v1'
               || ($receipt['checkout_authorized']??true)!==false
               || !array_filter((array)($receipt['sources']??[]),
                    static fn($source)=>($source['source_tab']??'')==='Hotels'))continue;
            $valid=MAD4B_Package_Stage_Rate_Guard_V1::propertyEligibility(
                $id,$tier,$scope,(string)$stage['lodging_kind']);
            if(($valid['eligible']??false)!==true)continue;
            $out[]=$id;
            if(count($out)>=150)break;
        }
        $cityTierCache[$cacheKey]=$out;
        return array_values(array_unique(array_merge($prior,$out)));
    }
    public static function onMeta($mid,$post,$key,$value):void {
        if($key!=='itinerary_item'||self::$running)return;
        $en=self::canonical((int)$post);
        if(!$en)return;
        self::$queued[$en]=true;
    }
    public static function onSave(int $post):void {
        if(self::$running)return;
        $en=self::canonical($post);
        if($en && is_array(get_post_meta($en,self::PLAN,true)))
            self::$queued[$en]=true;
    }
    public static function onShutdown():void {
        if(self::$running || !self::$queued)return;
        self::$running=true;
        foreach(array_keys(self::$queued) as $tour) self::reconcile((int)$tour,'apply');
        self::$queued=[];self::$running=false;
    }
}
add_filter('mad4b/package_stage_candidate_ids',[MAD4B_Itinerary_Overnight_Source_V1::class,'stageCandidates'],10,5);
add_action('updated_post_meta',[MAD4B_Itinerary_Overnight_Source_V1::class,'onMeta'],100,4);
add_action('added_post_meta',[MAD4B_Itinerary_Overnight_Source_V1::class,'onMeta'],100,4);
add_action('save_post_tours-and-activities',[MAD4B_Itinerary_Overnight_Source_V1::class,'onSave'],999,1);
add_action('shutdown',[MAD4B_Itinerary_Overnight_Source_V1::class,'onShutdown'],999);
}


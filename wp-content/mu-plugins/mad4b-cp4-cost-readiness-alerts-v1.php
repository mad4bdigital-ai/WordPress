<?php
/**
 * Plugin Name: MAD4B CP4 Cost Readiness Alerts
 * Description: Source-bound, read-only CP4 supplier receipt findings for the existing MAD4B Content Readiness ledger.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_CP4_Cost_Alerts_V1', false)) {
final class MAD4B_CP4_Cost_Alerts_V1 {
    public const SOURCE='mad4b-package-cost-readiness';
    public const CONTRACT='mad4b.cp4-cost-readiness-alert.v1';
    public const LINK_META='_mad4b_package_stay_verified_room_program_links_v1';
    private static bool $complete=false;
    private static array $issued=[];
    private static array $coverage=[];
    private const KINDS=['hotel','resort','cruise','sleeper_train'];
    private const OWNER=[
      'hotel'=>'Hotel Supplier Contracts',
      'resort'=>'Hotel Supplier Contracts',
      'cruise'=>'Cruise Contracting',
      'sleeper_train'=>'Rail Supplier Contracting'
    ];
    public static function boot():void {
       add_filter('mad4b/content_readiness/external_issues',[self::class,'external_issues'],45,2);
    }
    public static function eligible(string $postStatus):bool {
       return $postStatus==='publish';
    }
    private static function canonical(int $id):int {
       if(!has_filter('wpml_object_id'))return $id;
       $lang=apply_filters('wpml_default_language',null);
       if(!is_string($lang)||$lang==='')return $id;
       $target=apply_filters('wpml_object_id',$id,'tours-and-activities',false,$lang);
       return $target?(int)$target:$id;
    }
    private static function identity(array $stage):string {
       return (string)($stage['stage_key']??'').'|'.(string)($stage['lodging_kind']??'');
    }
    private static function has_selection(array $stage):bool {
       $kind=(string)($stage['lodging_kind']??'');
       return match($kind) {
           'hotel','resort'=>(int)($stage['selected_property_id']??0)>0 &&
                  count(array_filter(array_map('intval',(array)($stage['bookable_unit_ids']??[]))))>0,
           'cruise'=>(int)($stage['selected_vessel_id']??0)>0 &&
                  (int)($stage['selected_cabin_unit_id']??0)>0 &&
                  !empty($stage['cruise_program']) && !empty($stage['sailing_date']),
           'sleeper_train'=>(int)($stage['selected_train_id']??0)>0 &&
                  in_array((string)($stage['selected_train_number']??''),['86','87'],true) &&
                  !empty($stage['selected_train_cabin_class']),
           default=>false
       };
    }
    private static function receipt_qualified(int $tour,array $stage):array {
       if(!self::has_selection($stage))
           return ['qualified'=>false,'reasons'=>['assignment_missing']];
       $receipt=$stage['supplier_cost_receipt']??null;
       if(!is_array($receipt))
           return ['qualified'=>false,'reasons'=>['supplier_cost_receipt_missing']];
       if(!class_exists('MAD4B_Package_Cost_Receipt_Gate_V1',false))
           return ['qualified'=>false,'reasons'=>['cost_receipt_validator_missing']];
       $kind=(string)$stage['lodging_kind'];
       $date=(string)($receipt['availability']['service_date']??$receipt['service_date']??'');
       $nights=(int)($stage['nights']??0);
       $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
       $last=$parsed && $parsed->format('Y-m-d')===$date && $nights>0
           ?$parsed->modify('+'.($nights-1).' days')->format('Y-m-d'):'';
       $occupancy=(array)($receipt['occupancy']??[]);
       $ctx=[
           'tour_id'=>$tour,'stage_key'=>(string)$stage['stage_key'],'kind'=>$kind,
           'currency'=>(string)($receipt['currency']??''),
           'service_date'=>$date,'last_service_date'=>$last,
           'adults'=>$occupancy['adults']??-1,'children'=>$occupancy['children']??-1,
           'infants'=>$occupancy['infants']??-1,
           'property_id'=>(int)($stage['selected_property_id']??0),
           'unit_ids'=>(array)($stage['bookable_unit_ids']??[]),
           'vessel_id'=>(int)($stage['selected_vessel_id']??0),
           'cabin_unit_id'=>(int)($stage['selected_cabin_unit_id']??0),
           'cruise_program'=>$stage['cruise_program']??null,
           'train_id'=>(int)($stage['selected_train_id']??0),
           'train_number'=>(string)($stage['selected_train_number']??''),
       ];
       $result=MAD4B_Package_Cost_Receipt_Gate_V1::validate($receipt,$ctx);
       return ['qualified'=>($result['qualified']??false)===true,
               'reasons'=>array_slice((array)($result['blockers']??[]),0,14)];
    }
    private static function issue(int $tour,array $stage,string $digest,array $validation):array {
       $kind=(string)$stage['lodging_kind'];
       $stageKey=(string)$stage['stage_key'];
       $code='cp4_cost_'.substr(hash('sha256',$stageKey.'|'.$kind),0,12);
       $id=hash('sha256',get_current_blog_id().'|tours-and-activities|'.$tour.'|'.$code);
       $owner=self::OWNER[$kind]??'Supplier Contracting';
       $index=(int)($stage['source_day_start']??0);
       $title=$kind==='sleeper_train'?'Sleeper train':($kind==='cruise'?'Nile cruise':($kind==='resort'?'Resort':'Hotel'));
       $reason=(array)($validation['reasons']??[]);
       $action=count($reason) && in_array('assignment_missing',$reason,true)
           ?'Assign original property/unit/program and collect supplier contract'
           :'Verify contract, currency, dates, occupancy, inventory and signed cost authority';
       $label='CP4 '.$title.' day '.$index.': cost receipt not qualified. '
            .$owner.' — '.$action.'.';
       $siteUuid=class_exists('MAD4B_SCP_Site_Profile',false)
          ?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
       return [
          'id'=>$id,'post_type'=>'tours-and-activities','object_id'=>$tour,
          'code'=>$code,'severity'=>'high',
          'label'=>substr($label,0,239),'source'=>self::SOURCE,
          'source_evidence'=>[
             'contract'=>self::CONTRACT,'site_uuid'=>$siteUuid,
             'stage_key'=>$stageKey,'kind'=>$kind,'source_day_start'=>$index,
             'owner_group'=>$owner,'action'=>$action,'reason_codes'=>$reason,
             'source_sha256'=>$digest,'source_is_commercial_approval'=>false,
             'checkout_authorized'=>false
          ]
       ];
    }
    public static function discover():array {
       self::$issued=[];self::$complete=false;self::$coverage=[];
       if(!post_type_exists('tours-and-activities') ||
          !class_exists('MAD4B_Package_Cost_Receipt_Gate_V1',false))
          return ['complete'=>false,'issues'=>[],'reason'=>'source_or_gate_unavailable'];
       $ids=get_posts(['post_type'=>'tours-and-activities',
           'post_status'=>['publish','draft','pending'],
           'fields'=>'ids','posts_per_page'=>161,
           'meta_key'=>'_mad4b_package_accommodation_source_plan_v1',
           'meta_compare'=>'EXISTS','suppress_filters'=>true,'orderby'=>'ID','order'=>'ASC']);
       if(count($ids)>160)
          return ['complete'=>false,'issues'=>[],'reason'=>'package_census_truncated'];
       $unique=[];
       foreach($ids as $postId) {
          $canonical=self::canonical((int)$postId);
          if($canonical<=0||get_post_type($canonical)!=='tours-and-activities')
             return ['complete'=>false,'issues'=>[],'reason'=>'canonical_tour_unresolved'];
          $unique[$canonical]=$canonical;
       }
       ksort($unique,SORT_NUMERIC);
       $rows=[];$coverage=[];$stages=0;
       foreach($unique as $tour) {
          if(!self::eligible((string)get_post_status($tour)))continue;
          $source=get_post_meta($tour,self::LINK_META,true);
          if(!is_array($source) || (int)($source['tour_id']??0)!==$tour ||
             empty($source['schema']) || !is_array($source['bindings']??null) ||
             count($source['bindings'])<1 || count($source['bindings'])>40)
             return ['complete'=>false,'issues'=>[],'reason'=>'incomplete_stage_plan','tour_id'=>$tour];
          $digest=hash('sha256',wp_json_encode($source,JSON_UNESCAPED_SLASHES));
          $coverage[$tour]=$digest;
          $keys=[];
          foreach($source['bindings'] as $stage) {
             if(!is_array($stage))return ['complete'=>false,'issues'=>[],'reason'=>'malformed_stage'];
             $identity=self::identity($stage);
             if(!preg_match('/^[a-z0-9_\-]{2,96}\|(?:hotel|resort|cruise|sleeper_train)$/D',$identity) ||
                isset($keys[$identity]) || (int)($stage['source_day_start']??0)<1)
               return ['complete'=>false,'issues'=>[],'reason'=>'stage_identity_invalid'];
             $keys[$identity]=true;$stages++;
             $v=self::receipt_qualified($tour,$stage);
             if($v['qualified'])continue;
             $row=self::issue($tour,$stage,$digest,$v);
             $rows[]=$row;self::$issued[$row['id']]=$row;
          }
       }
       if(count($rows)>600)
          return ['complete'=>false,'issues'=>[],'reason'=>'source_issue_cap'];
       self::$complete=true;self::$coverage=$coverage;
       return ['complete'=>true,'issues'=>$rows,'tours'=>count($coverage),'stages'=>$stages,
           'source'=>self::SOURCE,'commercially_approved_by_this_scan'=>false];
    }
    public static function external_issues($previous,$cfg=null) {
       if(!is_array($previous))return $previous;
       $scan=self::discover();
       if(!$scan['complete'])return $previous;
       return array_merge($previous,$scan['issues']);
    }
    public static function admissible(array $row):bool {
       if(!self::$complete)return false;
       $key=(string)($row['id']??'');
       if(!isset(self::$issued[$key]))return false;
       return $row===self::$issued[$key] &&
         preg_match('/^cp4_cost_[a-f0-9]{12}$/D',(string)($row['code']??''))===1;
    }
    public static function can_resolve(array $old):bool {
       if(!self::$complete || ($old['source']??'')!==self::SOURCE)return false;
       $tour=(int)($old['object_id']??0);
       if(!isset(self::$coverage[$tour]))return false;
       $current=get_post_meta($tour,self::LINK_META,true);
       if(!is_array($current))return false;
       $now=hash('sha256',wp_json_encode($current,JSON_UNESCAPED_SLASHES));
       return hash_equals(self::$coverage[$tour],$now);
    }
}
MAD4B_CP4_Cost_Alerts_V1::boot();
}

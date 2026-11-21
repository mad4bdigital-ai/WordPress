<?php
/** Stage-specific seasonal Property eligibility. Never asserts room inventory. */
defined('ABSPATH') || exit;
final class MAD4B_Package_Seasonal_Assignment_V1 {
 public const META='_mad4b_package_stage_seasonal_rules_v1';
 public const SCHEMA='mad4b.package-stage-seasonal-assignment.v1';
 private static array $seasonCache=[];
 private static function tour(int $id):int {
  return class_exists('MAD4B_Package_Stage_Rate_Guard_V1')?
   MAD4B_Package_Stage_Rate_Guard_V1::canonicalTour($id):0;
 }
 public static function invalidate():void {self::$seasonCache=[];}
 public static function seasons():array {
  // WPML gives us the translated Term (same logical slug), but the approved
  // calendar fields are attached to the default-language policy Term only.
  $locale=apply_filters('wpml_current_language',null);
  $key=is_string($locale)&&$locale!==''?$locale:'_';
  if(isset(self::$seasonCache[$key]))return self::$seasonCache[$key];
  $default=apply_filters('wpml_default_language',null);
  $out=[];
  foreach(['summer','winter'] as $slug){
   $t=get_term_by('slug',$slug,'season');if(!$t instanceof WP_Term)continue;
   $policy=(int)$t->term_id;
   if(get_term_meta($policy,'season_enabled',true)!=='1'){
    $code=apply_filters('wpml_element_language_code',null,
      ['element_id'=>(int)$t->term_taxonomy_id,'element_type'=>'tax_season']);
    if(!is_string($default)||$default===''||
       !is_string($code)||$code===''||$default===$code)continue;
    $mapped=(int)apply_filters('wpml_object_id',$policy,'season',false,$default);
    $back=$mapped>0?(int)apply_filters('wpml_object_id',$mapped,'season',false,$code):0;
    if($mapped<=0||$back!==$policy||
       get_term_meta($mapped,'season_enabled',true)!=='1')continue;
    $policy=$mapped;
   }
   $mode=(string)get_term_meta($policy,'season_mode',true);
   if($mode!==''&&$mode!=='travel')continue;
   $out[$slug]=['slug'=>$slug,'name'=>$t->name,'term_id'=>(int)$t->term_id,
    'policy_term_id'=>$policy,
    'sm'=>(int)get_term_meta($policy,'season_start_month',true),
    'sd'=>(int)get_term_meta($policy,'season_start_day',true),
    'em'=>(int)get_term_meta($policy,'season_end_month',true),
    'ed'=>(int)get_term_meta($policy,'season_end_day',true),
    'cross'=>get_term_meta($policy,'season_cross_year',true)==='1',
    'priority'=>(int)get_term_meta($policy,'season_priority',true)];
  }
  return self::$seasonCache[$key]=$out;
 }
 public static function seasonOn(string $date):?string {
  $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
  if(!$day||$day->format('Y-m-d')!==$date)return null;
  $year=(int)substr($date,0,4);$hits=[];
  foreach(self::seasons() as $slug=>$p)
   foreach([$year-1,$year] as $y){
    $endYear=$y+($p['cross']?1:0);
    if(!checkdate($p['sm'],$p['sd'],$y)||!checkdate($p['em'],$p['ed'],$endYear))continue;
    $a=sprintf('%04d-%02d-%02d',$y,$p['sm'],$p['sd']);
    $b=sprintf('%04d-%02d-%02d',$endYear,$p['em'],$p['ed']);
    if($a<=$date&&$date<=$b){$hits[$slug]=true;break;}
   }
  return count($hits)===1?array_key_first($hits):null;
 }
 public static function state(int $tour):array {
  $tour=self::tour($tour);
  $data=$tour?get_post_meta($tour,self::META,true):null;
  return is_array($data)&&($data['schema']??'')===self::SCHEMA&&
   (int)($data['tour_id']??0)===$tour&&
   is_array($data['rules']??null)&&is_numeric($data['revision']??null)?
   $data:['schema'=>self::SCHEMA,'tour_id'=>$tour,'revision'=>0,'rules'=>[]];
 }
 public static function decision(int $tour,string $stage,int $tier,int $property,
  string $from,string $to,int $offset,int $nights):array {
  $rules=[];
  foreach(self::state($tour)['rules'] as $r)
   if(($r['stage_key']??'')===$stage&&(int)($r['tier_id']??0)===$tier&&
     (int)($r['property_id']??0)===$property)
    $rules[(string)$r['season']]=$r['mode'];
  if(!$rules)return ['eligible'=>true,'reason'=>'season_inherit_no_override','seasons'=>[]];
  if(!preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$from)||
   !preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$to)||$from>$to||
   $offset<0||$offset>60||$nights<1||$nights>40)
   return ['eligible'=>false,'reason'=>'seasonal_date_window_required','seasons'=>[]];
  $a=DateTimeImmutable::createFromFormat('!Y-m-d',$from,new DateTimeZone('UTC'));
  $b=DateTimeImmutable::createFromFormat('!Y-m-d',$to,new DateTimeZone('UTC'));
  if(!$a||!$b||$a->format('Y-m-d')!==$from||$b->format('Y-m-d')!==$to||
   $a->diff($b)->days>731)
   return ['eligible'=>false,'reason'=>'seasonal_date_window_invalid','seasons'=>[]];
  $first=$a->modify('+'.$offset.' days');
  $last=$b->modify('+'.($offset+$nights-1).' days');
  $matched=[];
  for($d=$first;$d<=$last;$d=$d->modify('+1 day')){
   $slug=self::seasonOn($d->format('Y-m-d'));
   if($slug===null)return ['eligible'=>false,'reason'=>'season_calendar_gap_or_overlap','seasons'=>array_keys($matched)];
   $matched[$slug]=true;
   if(($rules[$slug]??'')==='excluded')
    return ['eligible'=>false,'reason'=>'stage_property_season_excluded','seasons'=>array_keys($matched)];
  }
  return ['eligible'=>true,'reason'=>'seasonal_assignment_candidate_only','seasons'=>array_keys($matched)];
 }
 public static function catalog(int $tour) {
  $tour=self::tour($tour);
  if(!$tour||!current_user_can('manage_options')||!current_user_can('edit_post',$tour))
   return new WP_Error('seasonal_assignment_forbidden');
  // Do not permit editing rules if a subsequent release restored the old
  // shared-core implementation; stale overrides would be silently ignored.
  if(!defined('MAD4B_PACKAGE_STAGE_SEASONAL_GUARD_V1')||
    !function_exists('shared_product_booking_v2_rate_effective_window'))
   return new WP_Error('seasonal_assignment_runtime_parity_missing');
  if(!MAD4B_Package_Stage_Rate_Guard_V1::applies($tour))
   return new WP_Error('seasonal_assignment_tour_not_package');
  $plan=get_post_meta($tour,MAD4B_Package_Stage_Rate_Guard_V1::SOURCE,true);
  $bindings=get_post_meta($tour,MAD4B_Package_Stage_Rate_Guard_V1::BINDINGS,true);
  if(!is_array($plan)||!is_array($bindings))return new WP_Error('seasonal_assignment_no_stages');
  $byKey=[];
  foreach((array)($bindings['bindings']??[]) as $b)if(is_array($b))
   $byKey[sanitize_key((string)($b['stage_key']??''))]=$b;
  $options=[];
  foreach((array)($plan['stages']??[]) as $s){
   $kind=sanitize_key((string)($s['lodging_kind']??''));
   if(!in_array($kind,['hotel','resort'],true))continue;
   $key=sanitize_key((string)($s['stage_key']??''));
   $scope=MAD4B_Package_Stage_Rate_Guard_V1::stageScope($tour,$s);
   foreach((array)(($byKey[$key]['room_candidate_refs']??[])) as $tier=>$refs){
    $tier=MAD4B_Package_Stage_Rate_Guard_V1::canonicalTier((int)$tier);
    if(!$tier)continue;
    foreach((array)$refs as $item){
     $property=(int)($item['property_id_en']??0);
     if(!$property)continue;
     $pass=MAD4B_Package_Stage_Rate_Guard_V1::propertyEligibility($property,$tier,$scope,$kind);
     if(empty($pass['eligible']))continue;
     $id=$key.':'.$tier.':'.$property;
     $options[$id]=['stage_key'=>$key,'tier_id'=>$tier,'property_id'=>$property,
      'name'=>get_the_title($property),'destination'=>(string)($s['destination_key']??''),
      'nights'=>(int)($s['nights']??0)];
    }
   }
   // A plan-only OR alternative must remain selectable even when no Unit
   // references were generated yet; same native destination/tier identity check.
   foreach((array)($s['hotel_alternatives_or_by_tier']??[]) as $tier=>$properties){
    $tier=MAD4B_Package_Stage_Rate_Guard_V1::canonicalTier((int)$tier);
    if(!$tier)continue;
    foreach((array)$properties as $property){
     $property=(int)$property;$id=$key.':'.$tier.':'.$property;
     if(!$property||isset($options[$id]))continue;
     $pass=MAD4B_Package_Stage_Rate_Guard_V1::propertyEligibility($property,$tier,$scope,$kind);
     if(empty($pass['eligible']))continue;
     $options[$id]=['stage_key'=>$key,'tier_id'=>$tier,'property_id'=>$property,
      'name'=>get_the_title($property),'destination'=>(string)($s['destination_key']??''),
      'nights'=>(int)($s['nights']??0),'candidate_source'=>'plan_only'];
    }
   }
  }
  $state=self::state($tour);
  return ['contract'=>self::SCHEMA,'tour_id'=>$tour,'revision'=>(int)$state['revision'],
   'seasons'=>array_values(self::seasons()),'options'=>array_values($options),
   'rules'=>$state['rules'],'assignment_only'=>true,'checkout_authorized'=>false];
 }
 public static function tours() {
  if(!current_user_can('manage_options'))return new WP_Error('seasonal_assignment_forbidden');
  $ids=get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish',
   'meta_key'=>MAD4B_Package_Stage_Rate_Guard_V1::SOURCE,'fields'=>'ids',
   'posts_per_page'=>80,'suppress_filters'=>true]);
  $rows=[];
  foreach($ids as $id){
   $id=(int)$id;
   if(self::tour($id)!==$id||!MAD4B_Package_Stage_Rate_Guard_V1::applies($id)||
      !current_user_can('edit_post',$id))continue;
   $rows[]=['id'=>$id,'title'=>get_the_title($id)];
  }
  return ['tours'=>$rows,'readonly'=>true,'checkout_authorized'=>false];
 }
 public static function save(int $tour,array $input,int $revision) {
  $cat=self::catalog($tour);if(is_wp_error($cat))return $cat;
  $tour=$cat['tour_id'];$old=self::state($tour);
  if((int)$old['revision']!==$revision)return new WP_Error('seasonal_assignment_stale_revision');
  if(count($input)>250)return new WP_Error('seasonal_assignment_rule_limit');
  $options=[];foreach($cat['options'] as $o)
   $options[$o['stage_key'].':'.$o['tier_id'].':'.$o['property_id']]=true;
  $seasons=array_column($cat['seasons'],'slug');
  $saved=[];$seen=[];
  foreach($input as $r){
   if(!is_array($r)||array_keys($r)!==['stage_key','tier_id','property_id','season','mode'])
    return new WP_Error('seasonal_assignment_schema_invalid');
   $key=sanitize_key((string)$r['stage_key']);
   $tier=(int)$r['tier_id'];$property=(int)$r['property_id'];
   $season=(string)$r['season'];$mode=(string)$r['mode'];
   if(!isset($options[$key.':'.$tier.':'.$property])||
    !in_array($season,$seasons,true)||
    !in_array($mode,['eligible','excluded'],true))
    return new WP_Error('seasonal_assignment_candidate_not_valid');
   $id=$key.':'.$tier.':'.$property.':'.$season;
   if(isset($seen[$id]))return new WP_Error('seasonal_assignment_duplicate');
   $seen[$id]=true;
   $saved[]=['stage_key'=>$key,'tier_id'=>$tier,'property_id'=>$property,'season'=>$season,'mode'=>$mode];
  }
  usort($saved,static fn($a,$b)=>strcmp(implode(':',$a),implode(':',$b)));
  if($saved===$old['rules'])
   return ['status'=>'unchanged','revision'=>$revision,'write_performed'=>false];
  $new=['schema'=>self::SCHEMA,'tour_id'=>$tour,'revision'=>$revision+1,
   'rules'=>$saved,'updated_at'=>gmdate('c'),'updated_by'=>get_current_user_id()];
  $ok=metadata_exists('post',$tour,self::META)?
   update_post_meta($tour,self::META,$new,$old):
   add_post_meta($tour,self::META,$new,true);
  if(!$ok)return new WP_Error('seasonal_assignment_concurrent_conflict');
  $check=self::state($tour);
  if($check['rules']!==$saved||$check['revision']!==$revision+1)
   return new WP_Error('seasonal_assignment_readback_failed');
  return ['status'=>'saved','revision'=>$revision+1,'rule_count'=>count($saved),
   'write_performed'=>true,'checkout_authorized'=>false];
 }
 public static function rest():void {
  register_rest_route('mad4b-pricing/v1','/package-seasonal-assignments',[
   [
    'methods'=>WP_REST_Server::READABLE,
    'permission_callback'=>static fn()=>current_user_can('manage_options'),
    'callback'=>static function(WP_REST_Request $request){
     $tour=absint($request->get_param('tour_id'));
     return rest_ensure_response($tour?self::catalog($tour):self::tours());
    }
   ],
   [
    'methods'=>WP_REST_Server::CREATABLE,
    'permission_callback'=>static fn()=>current_user_can('manage_options'),
    'callback'=>static function(WP_REST_Request $request){
     $rules=$request->get_param('rules');
     $rev=$request->get_param('revision');
     if(!is_array($rules)||!is_numeric($rev)|| (string)(int)$rev!==(string)$rev)
      return new WP_Error('seasonal_assignment_invalid_write_request');
     return rest_ensure_response(self::save(absint($request->get_param('tour_id')),$rules,(int)$rev));
    }
   ]
  ]);
 }
 public static function boot():void {
  add_action('rest_api_init',[__CLASS__,'rest']);
  foreach(['edited_term','delete_term','added_term_meta',
    'updated_term_meta','deleted_term_meta'] as $hook)
   add_action($hook,[__CLASS__,'invalidate'],5,0);
 }
}
MAD4B_Package_Seasonal_Assignment_V1::boot();


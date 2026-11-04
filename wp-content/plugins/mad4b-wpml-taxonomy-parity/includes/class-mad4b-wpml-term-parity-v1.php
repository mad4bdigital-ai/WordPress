<?php
/**
 * MAD4B WPML taxonomy parity; v1.
 * WPML TRID is the canonical term identity. No slug or name inference.
 * Hooks never grant cross-company write authority. Reconcile is idempotent.
 */
defined('ABSPATH') || exit;
final class MAD4B_WPML_Term_Parity_V1 {
 const CONTRACT='mad4b.wpml-taxonomy-parity.v1';
 private static $pending=[],$busy=false;
 public static function enabled():bool {
  return defined('ICL_SITEPRESS_VERSION') &&
    defined('MAD4B_COMMERCE_RUNTIME_ENABLED') &&
    MAD4B_COMMERCE_RUNTIME_ENABLED===true &&
    in_array(wp_get_environment_type(),['staging','production'],true);
 }
 public static function pairs():array {
  $c=apply_filters('mad4b/wpml-taxonomy-parity/pairs',[
   ['post_type'=>'tours-and-activities','taxonomy'=>'tour-types']
  ]);
  $result=[];
  foreach(array_slice(is_array($c)?$c:[],0,20) as $p){
   if(!is_array($p))continue;
   $pt=sanitize_key((string)($p['post_type']??''));
   $tx=sanitize_key((string)($p['taxonomy']??''));
   if($pt&&$tx&&post_type_exists($pt)&&taxonomy_exists($tx)
      &&is_object_in_taxonomy($pt,$tx))$result[$pt.'|'.$tx]=[$pt,$tx];
  }
  return array_values($result);
 }
 private static function terms(int $id,string $tax):array {
  global $wpdb;
  return $wpdb->get_results($wpdb->prepare(
   "SELECT r.term_taxonomy_id tt,r.term_order ordering,tr.trid,tr.language_code term_language
    FROM {$wpdb->term_relationships} r
    JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=r.term_taxonomy_id AND t.taxonomy=%s
    LEFT JOIN {$wpdb->prefix}icl_translations tr ON tr.element_id=t.term_taxonomy_id
       AND tr.element_type=%s
    WHERE r.object_id=%d ORDER BY r.term_taxonomy_id",$tax,'tax_'.$tax,$id),ARRAY_A);
 }
 public static function plan(int $id,string $tax='tour-types'):array {
  global $wpdb;
  $post=get_post($id);
  if(!$post||!in_array($post->post_status,['publish','private','future'],true))
   return ['state'=>'SKIP_POST_NOT_PUBLISHED'];
  if(!in_array([$post->post_type,$tax],self::pairs(),true))
   return ['state'=>'SKIP_UNSUPPORTED_PAIR'];
  $lang=$wpdb->get_var($wpdb->prepare(
   "SELECT language_code FROM {$wpdb->prefix}icl_translations
    WHERE element_type=%s AND element_id=%d",'post_'.$post->post_type,$id));
  if(!is_string($lang)||!preg_match('/^[a-z]{2}$/D',$lang))
   return ['state'=>'HOLD_POST_LANGUAGE_MISSING','post_id'=>$id];
  $before=[];$expected=[];$moves=[];$bad=[];
  foreach(self::terms($id,$tax) as $row){
   $tt=(int)$row['tt'];$before[]=$tt;
   if(empty($row['trid'])||empty($row['term_language'])){
    $bad[]=['tt'=>$tt,'reason'=>'SOURCE_TRID_MISSING'];continue;
   }
   if($row['term_language']===$lang){$expected[$tt]=true;continue;}
   $matches=$wpdb->get_col($wpdb->prepare(
    "SELECT t.term_taxonomy_id FROM {$wpdb->prefix}icl_translations tr
     JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=tr.element_id AND t.taxonomy=%s
     WHERE tr.element_type=%s AND tr.trid=%d AND tr.language_code=%s",
    $tax,'tax_'.$tax,(int)$row['trid'],$lang));
   if(count($matches)!==1){
    $bad[]=['tt'=>$tt,'trid'=>(int)$row['trid'],'reason'=>'TRANSLATED_TERM_NOT_UNIQUE'];continue;
   }
   $new=(int)$matches[0];$expected[$new]=true;
   $moves[]=['source_tt'=>$tt,'target_tt'=>$new,'trid'=>(int)$row['trid'],
    'term_order'=>(int)$row['ordering']];
  }
  sort($before,SORT_NUMERIC);
  $after=array_map('intval',array_keys($expected));sort($after,SORT_NUMERIC);
  return ['contract'=>self::CONTRACT,'post_id'=>$id,'post_type'=>$post->post_type,
   'taxonomy'=>$tax,'language'=>$lang,'before'=>$before,'after'=>$after,
   'moves'=>$moves,'unresolved'=>$bad,'duplicates_removed'=>count($before)-count($after),
   'state'=>$bad?'HOLD_UNRESOLVED':($before===$after?'PASS':'REPAIR_REQUIRED')];
 }
 private static function receipt(string $key,array $entry):void {
  $list=get_option($key,[]);
  if(!is_array($list))$list=[];
  $list[]=$entry;
  update_option($key,array_slice($list,-30),false);
 }
 public static function repair(int $id,string $tax='tour-types',string $cause='native'):array {
  global $wpdb;
  if(!self::enabled()||self::$busy)return ['state'=>'SKIP_NOT_ENABLED'];
  $plan=self::plan($id,$tax);
  if(($plan['state']??'')!=='REPAIR_REQUIRED')return $plan;
  self::$busy=true;
  try{
   $from=$plan['before'];$to=$plan['after'];
   if(!$to||count($from)>80||count($plan['moves'])>25)
    throw new RuntimeException('LIMIT_OR_EMPTY_TARGET');
   $affected=array_values(array_unique(array_merge($from,$to)));
   if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('TX_START_FAILED');
   try {
    $actual=array_map('intval',array_column(self::terms($id,$tax),'tt'));
    sort($actual,SORT_NUMERIC);
    if($actual!==$from)throw new RuntimeException('SOURCE_CHANGED');
    foreach(array_diff($from,$to) as $tt)
     if($wpdb->delete($wpdb->term_relationships,
       ['object_id'=>$id,'term_taxonomy_id'=>$tt],['%d','%d'])!==1)
      throw new RuntimeException('REL_DELETE_FAILED');
    foreach(array_diff($to,$from) as $tt){
     $order=0;
     foreach($plan['moves'] as $m)if($m['target_tt']===$tt){
      $order=$m['term_order'];break;
     }
     if($wpdb->insert($wpdb->term_relationships,
       ['object_id'=>$id,'term_taxonomy_id'=>$tt,'term_order'=>$order],
       ['%d','%d','%d'])!==1)throw new RuntimeException('REL_INSERT_FAILED');
    }
    $read=array_map('intval',array_column(self::terms($id,$tax),'tt'));
    sort($read,SORT_NUMERIC);
    if($read!==$to)throw new RuntimeException('TX_READBACK_FAILED');
    if($wpdb->query('COMMIT')===false)throw new RuntimeException('TX_COMMIT_FAILED');
   }catch(Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
   wp_update_term_count_now($affected,$tax);
   $termids=[];
   foreach($affected as $tt){
    $term=$wpdb->get_var($wpdb->prepare(
      "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id=%d",$tt));
    if($term)$termids[]=(int)$term;
   }
   if($termids)clean_term_cache(array_unique($termids),$tax);
   clean_object_term_cache($id,get_post_type($id));
   $verified=self::plan($id,$tax);
   if(($verified['state']??'')!=='PASS')throw new RuntimeException('READBACK_FAILED');
   $receipt=['contract'=>self::CONTRACT,'state'=>'REPAIRED','post_id'=>$id,
     'taxonomy'=>$tax,'language'=>$plan['language'],'before'=>$from,'after'=>$to,
     'duplicates_removed'=>$plan['duplicates_removed'],'cause'=>$cause,
     'at'=>gmdate('c')];
   $receipt['sha256']=hash('sha256',wp_json_encode($receipt,JSON_UNESCAPED_SLASHES));
   self::receipt('mad4b_wpml_term_parity_receipts_v1',$receipt);
   return $receipt;
  }catch(Throwable $e){
   $fail=['contract'=>self::CONTRACT,'state'=>'HOLD',
    'post_id'=>$id,'taxonomy'=>$tax,'error'=>$e->getMessage(),'at'=>gmdate('c')];
   self::receipt('mad4b_wpml_term_parity_failures_v1',$fail);
   return $fail;
  }finally{self::$busy=false;}
 }
 public static function audit(int $limit=100):array {
  global $wpdb;
  $limit=max(1,min($limit,200));$problems=[];
  foreach(self::pairs() as [$pt,$tx]){
   $ids=$wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
     JOIN {$wpdb->prefix}icl_translations pl
       ON pl.element_id=p.ID AND pl.element_type=%s
     JOIN {$wpdb->term_relationships} r ON r.object_id=p.ID
     JOIN {$wpdb->term_taxonomy} t
       ON t.term_taxonomy_id=r.term_taxonomy_id AND t.taxonomy=%s
     LEFT JOIN {$wpdb->prefix}icl_translations tr
       ON tr.element_id=t.term_taxonomy_id AND tr.element_type=%s
     WHERE p.post_type=%s AND p.post_status IN ('publish','private','future')
       AND (tr.language_code IS NULL OR tr.language_code<>pl.language_code)
     ORDER BY p.ID LIMIT %d",
     'post_'.$pt,$tx,'tax_'.$tx,$pt,$limit));
   foreach($ids as $id){
    $state=self::plan((int)$id,$tx);
    if(($state['state']??'')!=='PASS')$problems[]=$state;
   }
  }
  $state=$problems?'REPAIR_REQUIRED':'PASS';
  foreach($problems as $p)if(str_starts_with($p['state'],'HOLD')){
   $state='HOLD_UNRESOLVED';break;
  }
  return ['contract'=>self::CONTRACT,'site'=>home_url('/'),
    'state'=>$state,'runtime_enabled'=>self::enabled(),'supported_pairs'=>self::pairs(),
    'count'=>count($problems),'problems'=>$problems,'limit_per_pair'=>$limit,
    'cross_company_authority_granted'=>false,'at'=>gmdate('c')];
 }
 public static function queue_terms($id,$terms,$ttids,$tax):void {
  if(!self::enabled())return;
  foreach(self::pairs() as [$pt,$allowed])
   if($tax===$allowed&&get_post_type((int)$id)===$pt){
    self::$pending[(int)$id.'|'.$tax]=[(int)$id,$tax];return;
   }
 }
 public static function queue_save($id,$post,$update):void {
  if(!self::enabled()||!is_object($post))return;
  foreach(self::pairs() as [$pt,$tax])
   if($post->post_type===$pt)
    self::$pending[(int)$id.'|'.$tax]=[(int)$id,$tax];
 }
 public static function flush():void {
  if(!self::enabled())return;
  $todo=self::$pending;self::$pending=[];
  foreach(array_slice($todo,0,25) as $p)self::repair($p[0],$p[1],'save_or_terms');
 }
 public static function hourly():void {
  if(!self::enabled())return;
  $report=self::audit(200);
  foreach(array_slice($report['problems'],0,30) as $p)
   if($p['state']==='REPAIR_REQUIRED')
    self::repair((int)$p['post_id'],$p['taxonomy'],'scheduled_reconcile');
 }
 public static function schedule():void {
  if(self::enabled()&&!wp_next_scheduled('mad4b_wpml_term_parity_hourly'))
   wp_schedule_event(time()+300,'hourly','mad4b_wpml_term_parity_hourly');
 }
 public static function routes():void {
  register_rest_route('mad4b/v1','/wpml-taxonomy-parity',[
   'methods'=>'GET',
   'permission_callback'=>static fn()=>current_user_can('manage_options'),
   'callback'=>static function(){
    $response=rest_ensure_response(self::audit(200));
    if($response instanceof WP_REST_Response)$response->header('Cache-Control','no-store, private');
    return $response;
   }]);
 }
 public static function boot():void {
  add_action('init',[__CLASS__,'schedule'],25);
  add_action('set_object_terms',[__CLASS__,'queue_terms'],PHP_INT_MAX,4);
  add_action('save_post',[__CLASS__,'queue_save'],PHP_INT_MAX,3);
  add_action('shutdown',[__CLASS__,'flush'],PHP_INT_MAX);
  add_action('mad4b_wpml_term_parity_hourly',[__CLASS__,'hourly']);
  add_action('rest_api_init',[__CLASS__,'routes'],95);
 }
}
MAD4B_WPML_Term_Parity_V1::boot();

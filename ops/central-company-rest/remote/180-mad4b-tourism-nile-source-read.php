<?php
/**
 * Plugin Name: MAD4B Tourism Nile Source Read (MU)
 * Version: 0.1.0
 * A narrowly scoped, signed POST-only source feed. No imports, updates, sales writes or user data.
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Tourism_Nile_Source_V1 {
 const COMPANY='tourism-almallahgroup';
 const HOST='tourism.almallahgroup-mg.com';
 const ROUTE='/mad4b-company/v1/nile-source-read-v1';
 const SCOPES=['nile_cruise_categories.read','nile_cruise_catalog.read','nile_rate_history.read','nile_starting_prices.read'];
 public static function enabled(): bool {
  return strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))===self::HOST
   && class_exists('MAD4B_Company_PK_Identity_V1')
   && MAD4B_Company_PK_Identity_V1::identity()===self::COMPANY;
 }
 private static function reject($reason='Access denied',int $status=403) {
  return new WP_Error('mad4b_nile_read_forbidden',$reason,['status'=>$status]);
 }
 private static function request_fields(WP_REST_Request $req) {
  if($req->get_method()!=='POST' || $req->get_route()!==self::ROUTE ||
     strlen((string)$req->get_body())>512)return false;
  $body=json_decode((string)$req->get_body(),true);
  if(!is_array($body) || count($body)!==3 ||
    !array_key_exists('scope',$body) || !array_key_exists('page',$body) || !array_key_exists('per_page',$body) ||
    !in_array($body['scope'],self::SCOPES,true) ||
    !is_int($body['page']) || $body['page']<1 || $body['page']>100 ||
    !is_int($body['per_page']) || $body['per_page']<1 || $body['per_page']>20)return false;
  return $body;
 }
 public static function permission(WP_REST_Request $req) {
  if(!self::enabled() || !function_exists('openssl_verify'))return self::reject();
  $parts=self::request_fields($req);
  if(!$parts)return self::reject('Invalid source read scope',400);
  $company=(string)$req->get_header('x-mad4b-company');
  $stamp=(string)$req->get_header('x-mad4b-time');
  $nonce=(string)$req->get_header('x-mad4b-nonce');
  $signature=(string)$req->get_header('x-mad4b-signature');
  if($company!==self::COMPANY || !preg_match('/^[0-9]{10}$/D',$stamp) ||
    abs(time()-(int)$stamp)>120 || !preg_match('/^[a-f0-9]{32}$/D',$nonce) ||
    strlen($signature)>180)return self::reject();
  $trusted=get_option(MAD4B_Company_PK_Identity_V1::PUBLIC_TRUST_OPTION,'');
  $local=get_option(MAD4B_Company_PK_Identity_V1::PRIVATE_OPTION,'');
  if(!is_string($trusted)||!openssl_pkey_get_public($trusted)||
     !is_string($local)||!openssl_pkey_get_private($local))return self::reject('Unpaired source');
  $sig=base64_decode($signature,true);
  if(!is_string($sig))return self::reject();
  $digest=hash('sha256',(string)$req->get_body());
  $message="POST\n".self::ROUTE."\n".self::COMPANY."\n".$stamp."\n".$nonce."\n".self::HOST."\n".$digest;
  if(openssl_verify($message,$sig,openssl_pkey_get_public($trusted),OPENSSL_ALGO_SHA256)!==1)
    return self::reject('Invalid signed scope');
  // Race-safe replay control, independent of object cache and LiteSpeed.
  global $wpdb;
  $key='mad4b_nile_nonce_'.hash('sha256',$nonce.'|'.$stamp.'|'.$digest);
  $ok=$wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,%s)",
    $key,(string)time(),'no'
  ));
  if($ok!==1)return self::reject('Repeated nonce');
  if(random_int(1,40)===1){
   $wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED)<%d LIMIT 80",
    $wpdb->esc_like('mad4b_nile_nonce_').'%',time()-1800
   ));
  }
  return true;
 }
 private static function term_map(): array {
  global $wpdb;
  $rows=$wpdb->get_results("SELECT t.term_id,t.slug,t.name,tt.parent,tt.taxonomy,tt.term_taxonomy_id
    FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id
    WHERE tt.taxonomy='tour-types_jet' ORDER BY t.term_id",ARRAY_A);
  if($wpdb->last_error)throw new RuntimeException('Taxonomy query failure');
  $map=[];
  foreach($rows as $r)$map[(int)$r['term_id']]=$r;
  $roots=[];
  foreach($map as $id=>$r)if(in_array($r['slug'],['nile-cruises','niletrips'],true))$roots[]=$id;
  if(!$roots)throw new RuntimeException('Nile source taxonomy roots missing');
  $set=array_fill_keys($roots,true);
  for($i=0;$i<12;$i++){
   $found=false;
   foreach($map as $id=>$r){
    if(!isset($set[$id]) && isset($set[(int)$r['parent']])){$set[$id]=true;$found=true;}
   }
   if(!$found)break;
  }
  return array_intersect_key($map,$set);
 }
 private static function tour_ids(): array {
  global $wpdb;
  $termIds=array_keys(self::term_map());
  $marks=implode(',',array_fill(0,count($termIds),'%d'));
  $query=$wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->posts} p
    JOIN {$wpdb->term_relationships} rel ON rel.object_id=p.ID
    JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=rel.term_taxonomy_id
    WHERE p.post_type='tours-and-activities' AND p.post_status='publish'
    AND tt.taxonomy='tour-types_jet' AND tt.term_id IN ($marks) ORDER BY p.ID",$termIds);
  $ids=array_map('intval',(array)$wpdb->get_col($query));
  if($wpdb->last_error)throw new RuntimeException('Tour query failure');
  return $ids;
 }
 private static function cat_items(): array {
  global $wpdb;
  $map=self::term_map();$items=[];
  foreach($map as $id=>$t){
   $lang=$wpdb->get_results($wpdb->prepare(
     "SELECT language_code,trid FROM {$wpdb->prefix}icl_translations
      WHERE element_type=%s AND element_id=%d LIMIT 2",
      'tax_tour-types_jet',$id),ARRAY_A);
   if($wpdb->last_error||count($lang)!==1 ||
       !in_array((string)$lang[0]['language_code'],['en','ar'],true) ||
       (int)$lang[0]['trid']<=0){
     throw new RuntimeException('Source category translation authority missing');
   }
   $items[]=[
    'source_language_code'=>(string)$lang[0]['language_code'],
    'source_translation_group_id'=>(int)$lang[0]['trid'],
    'source_term_id'=>(int)$id,'taxonomy'=>'tour-types_jet',
    'slug'=>(string)$t['slug'],'name'=>sanitize_text_field((string)$t['name']),
    'parent_source_term_id'=>(int)$t['parent'],
    'source_term_taxonomy_id'=>(int)$t['term_taxonomy_id'],
    'source_company'=>self::COMPANY,
    'values_may_not_be_cross_tenant_overwritten'=>true
   ];
  }
  return $items;
 }
 private static function tour_programs(int $id): array {
  $itinerary=get_post_meta($id,'itinerary_item',true);
  $items=[];
  if(is_array($itinerary)){
   foreach(array_slice(array_values($itinerary),0,25) as $row){
    if(!is_array($row))continue;
    $items[]=[
     'day_label'=>mb_substr(sanitize_text_field((string)($row['number']??'')),0,60),
     'title'=>mb_substr(sanitize_text_field((string)($row['title']??'')),0,240),
     'description_text'=>mb_substr(wp_strip_all_tags((string)($row['description']??'')),0,4000),
     'media_source_company_only'=>true,
     'media_not_copied'=>true,
    ];
   }
  }
  $cruise=get_post_meta($id,'cruise_runs',true);
  $programs=[];
  if(is_array($cruise)){
   foreach(array_slice(array_values($cruise),0,15) as $row){
    if(!is_array($row))continue;
    $clean=[];
    $taxonomies=[
      'program'=>'programs','day'=>'schudle-days-of-weak',
      'origin'=>'location_jet','time'=>'day-times'
    ];
    foreach($taxonomies as $key=>$expectedTaxonomy){
      $value=$row[$key]??null;
      $termId=is_scalar($value) && ctype_digit((string)$value)?(int)$value:0;
      $term=$termId?get_term($termId,$expectedTaxonomy):null;
      $valid=($term instanceof WP_Term && $term->taxonomy===$expectedTaxonomy);
      $clean[$key]=$termId?(string)$termId:null;
      $clean[$key.'_label']=$valid
          ?mb_substr(sanitize_text_field((string)$term->name),0,220):null;
      $clean[$key.'_source_taxonomy']=$expectedTaxonomy;
    }
    $programs[]=$clean;
   }
  }
  return ['itinerary_days'=>$items,'cruise_run_variants'=>$programs,
    'source_programs_only'=>true,'destination_publication_allowed'=>false];
 }
 private static function source_translation(int $id): array {
  global $wpdb;
  $table=$wpdb->prefix.'icl_translations';
  $exists=$wpdb->get_var($wpdb->prepare(
    'SELECT table_name FROM information_schema.tables WHERE table_schema=%s AND table_name=%s',DB_NAME,$table));
  if($exists!==$table)return ['language_code'=>null,'source_translation_group_id'=>null];
  $row=$wpdb->get_row($wpdb->prepare(
    "SELECT language_code,trid FROM {$table} WHERE element_id=%d AND element_type=%s LIMIT 1",
    $id,'post_tours-and-activities'),ARRAY_A);
  return ['language_code'=>is_array($row)?(string)$row['language_code']:null,
    'source_translation_group_id'=>is_array($row)?(int)$row['trid']:null];
 }
 private static function catalog_items(): array {
  $items=[];
  foreach(self::tour_ids() as $id){
   $p=get_post($id);if(!$p || $p->post_status!=='publish')continue;
   $terms=wp_get_object_terms($id,'tour-types_jet',['fields'=>'ids']);
   $items[]=[
    'source_tour_id'=>$id,
    'source_company'=>self::COMPANY,
    'title'=>sanitize_text_field($p->post_title),
    'summary'=>mb_substr(wp_strip_all_tags((string)$p->post_excerpt),0,3500),
    'source_content_text'=>mb_substr(wp_strip_all_tags((string)$p->post_content),0,6000),
    'source_slug'=>$p->post_name,
    'translation_source'=>self::source_translation($id),
    'program_reference_snapshot'=>self::tour_programs($id),
    'source_taxonomy_term_ids'=>is_wp_error($terms)?[]:array_values(array_map('intval',$terms)),
    'itinerary_reference_present'=>get_post_meta($id,'itinerary_item',true)!=='',
    'cruise_run_reference_present'=>get_post_meta($id,'cruise_runs',true)!=='',
    'prices_policy_reference_present'=>get_post_meta($id,'prices_policy_template',true)!=='',
    'import_status'=>'reference_only','publish_allowed'=>false,
   ];
  }
  return $items;
 }
 private static function strict_start_price(int $id,string $field,string $currencyField): ?array {
  $raw=get_post_meta($id,$field,true);
  $money=is_scalar($raw)?trim((string)$raw):'';
  $currency=strtoupper(trim((string)get_post_meta($id,$currencyField,true)));
  // Never derive money from formatting, an absent field or a fallback currency.
  if(!preg_match('/^[0-9]{1,10}(?:\\.[0-9]{1,4})?$/D',$money)||
     (float)$money<=0||(float)$money>1000000000||
     !preg_match('/^[A-Z]{3}$/D',$currency))return null;
  return ['field'=>$field,'amount'=>$money,'currency'=>$currency,
   'source'=>'source_wordpress_product_meta','reference_only'=>true,
   'current_price_verified'=>false,'checkout_authorized'=>false];
 }
 private static function starting_price_items(): array {
  $out=[];
  foreach(self::tour_ids() as $id){
   $post=get_post($id);
   if(!$post||$post->post_status!=='publish'||$post->post_password!=='')continue;
   $fields=[];
   foreach([
    ['start_price','tour_base_currency'],
    ['egyptians_start_price','tour_egyptians_currency']
   ] as $pair){
     $v=self::strict_start_price($id,$pair[0],$pair[1]);
     if($v!==null)$fields[]=$v;
   }
   $out[]=['source_tour_id'=>$id,'source_company'=>self::COMPANY,
     'source_post_type'=>'tours-and-activities',
     'starting_price_references'=>$fields,
     'reference_only'=>true,'for_sale'=>false,
     'checkout_enabled'=>false,'validated_current_price'=>false];
  }
  return $out;
 }
 private static function price_value($postId,$key): ?string {
  $v=get_post_meta($postId,$key,true);
  if(is_array($v)||is_object($v))return null;
  $s=trim((string)$v);
  return $s!=='' && strlen($s)<=70 && preg_match('/^[0-9., \-]+$/D',$s) ? $s : null;
 }
 private static function rate_items(): array {
  global $wpdb;
  $allowed=self::tour_ids();
  if(!$allowed)return [];
  $ids=$wpdb->get_col("SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type='tour-rates' AND p.post_status='publish' ORDER BY p.ID");
  if($wpdb->last_error)throw new RuntimeException('Rate query failure');
  $set=array_fill_keys($allowed,true);$out=[];
  foreach($ids as $id){
   $id=(int)$id;
   $tour=(int)get_post_meta($id,'related_tour_id',true);
   if(!$tour || !isset($set[$tour]))continue;
   $codes=[];
   foreach(['related_program_term_id','related_day_time_term_id','related_day_of_week_term_id',
     'related_origin_destination_term_id','related_properties_id'] as $key){
      $v=get_post_meta($id,$key,true);
      $codes[$key]=is_scalar($v)?mb_substr(sanitize_text_field((string)$v),0,100):null;
   }
   $price=[];
   foreach(['single_price','double_price','triple_price','egyptians_single_price',
    'egyptians_double_price','egyptians_triple_price'] as $key) $price[$key]=self::price_value($id,$key);
   $out[]=[
    'source_rate_id'=>$id,'source_tour_id'=>$tour,'source_company'=>self::COMPANY,
    'rate_plan_references'=>$codes,
    'currency'=>mb_substr(sanitize_text_field((string)get_post_meta($id,'base_currency',true)),0,12),
    'egyptians_currency'=>mb_substr(sanitize_text_field((string)get_post_meta($id,'egyptians_currency',true)),0,12),
    'source_date_values'=>array_slice(array_map('sanitize_text_field',
      get_post_meta($id,'tour_rate_start_date',false)),0,4),
    'source_end_date'=>mb_substr(sanitize_text_field((string)get_post_meta($id,'tour_rate_end_date',true)),0,50),
    'occupancy_price_reference'=>$price,
    'reference_only'=>true,'for_sale'=>false,'checkout_enabled'=>false,
    'validated_current_price'=>false,'requires_manual_update'=>true
   ];
  }
  return $out;
 }
 public static function respond(WP_REST_Request $req) {
  $request=self::request_fields($req);
  if(!$request)return self::reject('Invalid scope',400);
  try{
   if($request['scope']===self::SCOPES[0])$all=self::cat_items();
   elseif($request['scope']===self::SCOPES[1])$all=self::catalog_items();
   elseif($request['scope']==='nile_starting_prices.read')$all=self::starting_price_items();
   else $all=self::rate_items();
  }catch(Throwable $e){
   return new WP_Error('mad4b_nile_source_unavailable','Nile source read failed',['status'=>503]);
  }
  $page=$request['page'];$limit=$request['per_page'];
  $payload=[
   'contract'=>'mad4b.nile-source.reference-page.v1',
   'owner_company'=>self::COMPANY,'origin'=>self::HOST,
   'scope'=>$request['scope'],'page'=>$page,'per_page'=>$limit,
   'total'=>count($all),'items'=>array_slice($all,($page-1)*$limit,$limit),
   'reference_only'=>true,'may_publish'=>false,'may_checkout'=>false,
   'no_customer_or_order_data'=>true,
  ];
  $hash=hash('sha256',wp_json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $ts=(string)$req->get_header('x-mad4b-time');
  $nonce=(string)$req->get_header('x-mad4b-nonce');
  $claim="SOURCE_RESPONSE\n".self::ROUTE."\n".self::COMPANY."\n".$ts."\n".$nonce."\n".self::HOST."\n".$hash;
  $private=openssl_pkey_get_private((string)get_option(MAD4B_Company_PK_Identity_V1::PRIVATE_OPTION,''));
  if(!$private || !openssl_sign($claim,$sig,$private,OPENSSL_ALGO_SHA256))
   return self::reject('Response signing failed',503);
  $response=new WP_REST_Response([
    'data'=>$payload,'sha256'=>$hash,'signature'=>base64_encode($sig),
    'timestamp'=>$ts,'nonce'=>$nonce,'scope'=>$request['scope']
  ],200);
  $response->header('Cache-Control','private, no-store, max-age=0');
  $response->header('X-LiteSpeed-Cache-Control','no-cache');
  return $response;
 }
 public static function boot(): void {
  if(!self::enabled())return;
  add_action('rest_api_init',static function(){
   register_rest_route('mad4b-company/v1','/nile-source-read-v1',[
    'methods'=>WP_REST_Server::CREATABLE,
    'permission_callback'=>[__CLASS__,'permission'],
    'callback'=>[__CLASS__,'respond']
   ]);
  });
 }
}
MAD4B_Tourism_Nile_Source_V1::boot();

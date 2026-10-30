<?php
/**
 * Plugin Name: MAD4B Central Nile Reference Connector (MU)
 * Version: 0.1.0
 * Staging-only, admin-triggered, authenticated reference snapshots. Never WooCommerce imports.
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Central_Nile_Reference_V1 {
 const COMPANY='tourism-almallahgroup';
 const REMOTE_ROUTE='/mad4b-company/v1/nile-source-read-v1';
 const REGISTRY='mad4b_nile_source_reference_v1';
 const SCOPES=['nile_cruise_categories.read','nile_cruise_catalog.read','nile_rate_history.read','nile_starting_prices.read'];
 public static function enabled(): bool {
  return class_exists('MAD4B_Central_PK_Pairing_V1')
   && MAD4B_Central_PK_Pairing_V1::enabled();
 }
 public static function permission(): bool {
  return self::enabled() && current_user_can('manage_options');
 }
 private static function failure($code,$message,int $status=409) {
  return new WP_Error($code,$message,['status'=>$status]);
 }
 public static function fetch(string $scope,int $page=1,int $perPage=20) {
  if(!self::enabled()||!in_array($scope,self::SCOPES,true)||
     $page<1||$page>100||$perPage<1||$perPage>20)
    return self::failure('mad4b_nile_invalid_scope','Invalid scope');
  $tenant=MAD4B_Central_Company_REST_V1::COMPANIES[self::COMPANY]??null;
  if(!$tenant||$tenant['host']!=='tourism.almallahgroup-mg.com')
    return self::failure('mad4b_nile_wrong_tenant','Unexpected source tenant');
  $private=openssl_pkey_get_private((string)get_option(MAD4B_Central_PK_Pairing_V1::PRIVATE_OPTION,''));
  $remote=openssl_pkey_get_public((string)get_option(MAD4B_Central_PK_Pairing_V1::trust_name(self::COMPANY),''));
  if(!$private||!$remote)return self::failure('mad4b_nile_unpaired','Source public-key trust missing');
  $body=wp_json_encode(['scope'=>$scope,'page'=>$page,'per_page'=>$perPage],JSON_UNESCAPED_SLASHES);
  $ts=(string)time();
  try{$nonce=bin2hex(random_bytes(16));}
  catch(Throwable $e){return self::failure('mad4b_nile_entropy','Nonce unavailable',503);}
  $message="POST\n".self::REMOTE_ROUTE."\n".self::COMPANY."\n".$ts."\n".$nonce."\n".$tenant['host']."\n".hash('sha256',$body);
  if(!openssl_sign($message,$signature,$private,OPENSSL_ALGO_SHA256))
    return self::failure('mad4b_nile_sign','Cannot sign scope',503);
  $resp=wp_safe_remote_post($tenant['url'].'/wp-json'.self::REMOTE_ROUTE,[
   'timeout'=>18,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,
   'limit_response_size'=>180000,
   'headers'=>[
    'Content-Type'=>'application/json',
    'Accept'=>'application/json',
    'X-Mad4b-Company'=>self::COMPANY,
    'X-Mad4b-Time'=>$ts,
    'X-Mad4b-Nonce'=>$nonce,
    'X-Mad4b-Signature'=>base64_encode($signature),
   ],
   'body'=>$body
  ]);
  if(is_wp_error($resp))return self::failure('mad4b_nile_transport','Source connection unavailable',502);
  if(wp_remote_retrieve_response_code($resp)!==200)
   return self::failure('mad4b_nile_remote_rejected','Source read was rejected',502);
  $response=json_decode(wp_remote_retrieve_body($resp),true);
  if(!is_array($response)||!isset($response['data'],$response['sha256'],$response['signature'])||
     !is_array($response['data']))
     return self::failure('mad4b_nile_untrusted_data','Source response malformed');
  $data=$response['data'];
  if(($response['nonce']??'')!==$nonce||($response['timestamp']??'')!==$ts||
      ($response['scope']??'')!==$scope||
      ($data['contract']??'')!=='mad4b.nile-source.reference-page.v1'||
      ($data['owner_company']??'')!==self::COMPANY||
      ($data['origin']??'')!==$tenant['host']||
      ($data['scope']??'')!==$scope||
      ($data['page']??null)!==$page || ($data['per_page']??null)!==$perPage ||
      ($data['reference_only']??false)!==true||
      ($data['may_publish']??true)!==false||
      ($data['may_checkout']??true)!==false||
      !is_int($data['total']??null)||$data['total']<0||$data['total']>5000||
      !is_array($data['items']??null)||count($data['items'])>$perPage)
     return self::failure('mad4b_nile_claim_mismatch','Source claims fail validation');
  foreach($data['items'] as $item){
    if(!is_array($item)||($item['source_company']??'')!==self::COMPANY)
       return self::failure('mad4b_nile_tenant_mismatch','Item belongs to another tenant');
    if($scope==='nile_rate_history.read' &&
       (($item['reference_only']??false)!==true||($item['for_sale']??true)!==false||
        ($item['checkout_enabled']??true)!==false))
       return self::failure('mad4b_nile_rate_sale_risk','Historical price item not sealed');
    if($scope==='nile_starting_prices.read'){
       if(($item['source_post_type']??null)!=='tours-and-activities' ||
          !is_int($item['source_tour_id']??null) ||
          ($item['reference_only']??false)!==true ||
          ($item['for_sale']??true)!==false ||
          ($item['checkout_enabled']??true)!==false ||
          ($item['validated_current_price']??true)!==false ||
          !is_array($item['starting_price_references']??null) ||
          count($item['starting_price_references'])>2)
         return self::failure('mad4b_nile_start_price_unsealed',
              'Source starting price reference invalid');
       $known=['start_price','egyptians_start_price'];$seenFields=[];
       foreach($item['starting_price_references'] as $value){
         if(!is_array($value)||
            !in_array($value['field']??null,$known,true) ||
            isset($seenFields[$value['field']]) ||
            !is_string($value['amount']??null) ||
            !preg_match('/^[0-9]{1,10}(?:\\.[0-9]{1,4})?$/D',$value['amount']) ||
            (float)$value['amount']<=0 ||
            !is_string($value['currency']??null) ||
            !preg_match('/^[A-Z]{3}$/D',$value['currency']) ||
            ($value['reference_only']??false)!==true ||
            ($value['checkout_authorized']??true)!==false ||
            ($value['current_price_verified']??true)!==false)
            return self::failure('mad4b_nile_start_price_projection_invalid',
                'Untrusted direct source amount');
         $seenFields[$value['field']]=true;
       }
    }
  }
  $digest=hash('sha256',wp_json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  if(!hash_equals($digest,(string)$response['sha256']))
    return self::failure('mad4b_nile_digest_mismatch','Payload digest mismatch');
  $sig=base64_decode((string)$response['signature'],true);
  $proof="SOURCE_RESPONSE\n".self::REMOTE_ROUTE."\n".self::COMPANY."\n".$ts."\n".$nonce."\n".$tenant['host']."\n".$digest;
  if(!is_string($sig)||openssl_verify($proof,$sig,$remote,OPENSSL_ALGO_SHA256)!==1)
    return self::failure('mad4b_nile_proof_invalid','Remote source signature invalid');
  return $data;
 }
 public static function request(WP_REST_Request $r) {
  $scope=(string)$r->get_param('scope');
  $page=(int)$r->get_param('page');
  if(!in_array($scope,self::SCOPES,true)||$page<1||$page>100)
    return self::failure('mad4b_nile_invalid_request','Explicit scope and page required',400);
  $data=self::fetch($scope,$page,20);
  if(is_wp_error($data))return $data;
  $resp=new WP_REST_Response([
   'contract'=>'mad4b.central.nile-preview.v1',
   'source_signature_verified'=>true,
   'data'=>$data,'imported_to_products'=>false,
   'saved_to_reference_storage'=>false
  ],200);
  $resp->header('Cache-Control','private, no-store');
  return $resp;
 }
 public static function sync(WP_REST_Request $r) {
  $scope=(string)$r->get_param('scope');
  if(!in_array($scope,self::SCOPES,true))
    return self::failure('mad4b_nile_invalid_scope','Explicit source scope required',400);
  // Collect locally first: never publish half-imported snapshots.
  $all=[];$total=null;$parts=[];
  for($page=1;$page<=100;$page++){
    $batch=self::fetch($scope,$page,20);
    if(is_wp_error($batch))return $batch;
    if($total===null)$total=$batch['total'];
    if($batch['total']!==$total)return self::failure('mad4b_nile_source_mutated','Source count shifted during snapshot');
    $all=array_merge($all,$batch['items']);
    $parts[]=hash('sha256',wp_json_encode($batch,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    if(count($all)>$total)return self::failure('mad4b_nile_snapshot_overrun','Source pages contain duplicates');
    if(count($all)===$total)break;
    if(count($batch['items'])!==20)return self::failure('mad4b_nile_snapshot_gap','Incomplete source page');
  }
  if(count($all)!==$total)return self::failure('mad4b_nile_snapshot_incomplete','Snapshot incomplete');
  $seen=[];
  foreach($all as $x){
    $field=$scope==='nile_cruise_categories.read'?'source_term_id':
      (in_array($scope,['nile_cruise_catalog.read','nile_starting_prices.read'],true)?
        'source_tour_id':'source_rate_id');
    $id=(int)($x[$field]??0);
    if($id<1||isset($seen[$id]))return self::failure('mad4b_nile_duplicate_reference','Invalid or duplicate reference ID');
    $seen[$id]=true;
  }
  // Isolated, non-autoloaded Staging option; never map to WooCommerce/CPT/rates.
  $snapshot=get_option(self::REGISTRY,[]);
  if(!is_array($snapshot))$snapshot=[];
  $snapshot[$scope]=[
   'contract'=>'mad4b.central.nile-reference-snapshot.v1',
   'source_company'=>self::COMPANY,
   'scope'=>$scope,
   'reference_only'=>true,'may_publish'=>false,'may_checkout'=>false,
   'captured_at'=>gmdate('c'),'source_count'=>$total,
   'source_page_sha256'=>$parts,
   'items'=>$all
  ];
  update_option(self::REGISTRY,$snapshot,false);
  $receipt=new WP_REST_Response([
    'contract'=>'mad4b.central.nile-reference-sync-receipt.v1',
    'scope'=>$scope,'source_company'=>self::COMPANY,
    'verified_records'=>$total,'source_pages'=>count($parts),
    'reference_storage_only'=>true,'wordpress_products_created'=>0,
    'rates_published'=>0,'checkout_enabled'=>false
  ],200);
  $receipt->header('Cache-Control','no-store, private');
  return $receipt;
 }
 public static function boot(): void {
  if(!self::enabled())return;
  add_action('rest_api_init',static function() {
   register_rest_route('mad4b-central/v1','/companies/tourism-almallahgroup/nile-preview',[
    'methods'=>WP_REST_Server::CREATABLE,
    'permission_callback'=>[__CLASS__,'permission'],
    'callback'=>[__CLASS__,'request']
   ]);
   register_rest_route('mad4b-central/v1','/companies/tourism-almallahgroup/nile-reference-sync',[
    'methods'=>WP_REST_Server::CREATABLE,
    'permission_callback'=>[__CLASS__,'permission'],
    'callback'=>[__CLASS__,'sync']
   ]);
  });
 }
}
MAD4B_Central_Nile_Reference_V1::boot();

<?php
/**
 * Plugin Name: MAD4B Ground Transport Provider Core
 * Description: Native JetEngine vehicle catalog importer and WooCommerce fare-provider connection controls.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Ground_Provider_Core_V1 {
 const OPTION='mad4b_ground_provider_profiles_v1';
 const LEDGER='mad4b_ground_provider_import_ledger_v1';
 const ALERTS='mad4b_ground_provider_content_alerts_v1';
 const CONTRACT='mad4b.ground-transport-provider.v1';
 static function definitions(): array {
  return [
   'egyptlimo'=>['title'=>'EgyptLimo Partner API','model'=>'vehicle_catalog + live_vehicle_quote',
     'source'=>'https://integration.egyptlimo.com/docs/partner-api-reference',
     'authentication'=>'oauth2_client_credentials + HMAC_SHA256','catalog_endpoint'=>'/integration/vehicle-classes'],
   '12go'=>['title'=>'12Go Partner','model'=>'scheduled_bus_seat_quote',
     'source'=>'https://agent.12go.asia/','authentication'=>'partner_access_pending','catalog_endpoint'=>null],
   'busbud'=>['title'=>'Busbud Partner API','model'=>'scheduled_bus_seat_quote',
     'source'=>'https://partner-assets.busbud.com/partner/busbud-api/','authentication'=>'partner_access_pending','catalog_endpoint'=>null],
   'mozio'=>['title'=>'Mozio Ground Transfers','model'=>'private_transfer_quote',
     'source'=>'https://www.mozio.com/providers','authentication'=>'partner_access_pending','catalog_endpoint'=>null],
   'local'=>['title'=>'Local Contracted Ground Suppliers','model'=>'manual_rate_cards',
     'source'=>'','authentication'=>'supplier_signed_contract','catalog_endpoint'=>null],
  ];
 }
 static function defaults():array {
  return ['enabled'=>false,'mode'=>'sandbox','merchant_slug'=>'','merchant_id'=>'',
   'client_id'=>'','key_id'=>'','secrets'=>[],'fare_mode'=>'on_request',
   'pricing_rule_kind'=>'not_configured','pricing_rule_value'=>'',
   'catalog_sync'=>'manual','catalog_import_status'=>'draft_review_only',
   'allow_order_creation'=>false,'allow_rate_publication'=>false];
 }
 static function can_manage():bool {
  return current_user_can('manage_woocommerce') && function_exists('wc_get_product')
   && post_type_exists('transportations') && taxonomy_exists('transportation-types');
 }
 static function profiles():array {
  $raw=get_option(self::OPTION,[]);
  return is_array($raw)?$raw:[];
 }
 static function profile(string $provider):array {
  return array_replace(self::defaults(),(array)(self::profiles()[$provider]??[]));
 }
 static function key():string {
  return hash('sha256',wp_salt('auth').'|'.self::OPTION.'|'.get_current_blog_id(),true);
 }
 static function encrypt(string $plain) {
  if(!function_exists('openssl_encrypt'))return new WP_Error('ground_crypto_missing');
  $iv=random_bytes(12);$tag='';
  $data=openssl_encrypt($plain,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,'ground-provider-v1');
  return is_string($data)?base64_encode($iv.$tag.$data):new WP_Error('ground_crypto_failed');
 }
 static function secret(string $provider,string $field) {
  $profile=self::profile($provider);
  $encoded=(string)($profile['secrets'][$field]??'');
  if($encoded==='')return '';
  $raw=base64_decode($encoded,true);
  if($raw===false||strlen($raw)<29)return '';
  $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,
   substr($raw,0,12),substr($raw,12,16),'ground-provider-v1');
  return is_string($plain)?$plain:'';
 }
 static function base_url(array $p) {
  if(($p['mode']??'')!=='sandbox'&&($p['mode']??'')!=='production')return new WP_Error('ground_mode_invalid');
  $slug=(string)($p['merchant_slug']??'');
  if(!preg_match('/^[a-z][a-z0-9-]{2,49}$/D',$slug)||str_ends_with($slug,'-'))
   return new WP_Error('ground_merchant_slug_invalid');
  $suffix=$p['mode']==='sandbox'?'.sandbox.partner-api.egyptlimo.com':'.partner-api.egyptlimo.com';
  return 'https://'.$slug.$suffix.'/api/v1';
 }
 static function ready(string $provider):array {
  $p=self::profile($provider);
  if($provider!=='egyptlimo')
   return ['enabled'=>!empty($p['enabled']),'connected'=>false,
     'state'=>$provider==='local'?'manual_supplier_contract_needed':'partner_api_not_enrolled',
     'can_import_jetengine'=>false];
  $base=self::base_url($p);
  $fields=['client_secret','signing_secret'];
  $credentialed=!is_wp_error($base);
  foreach($fields as $key)if(self::secret($provider,$key)==='')$credentialed=false;
  foreach(['client_id','merchant_id','key_id'] as $key)if(empty($p[$key]))$credentialed=false;
  $test=(array)((array)get_option('mad4b_ground_provider_test_results_v1',[]))['egyptlimo']??[];
  $verified=!empty($p['enabled'])&&$credentialed&&!empty($test['success']) &&
    hash_equals(self::configuration_fingerprint($provider),(string)($test['fingerprint']??'')) &&
    strtotime((string)($test['at']??''))>time()-86400;
  return [
   'enabled'=>!empty($p['enabled']),'credentialed'=>$credentialed,
   'connected'=>$verified, // Last signed catalog test; not a guarantee of current uptime or quotes entitlement.
   'state'=>empty($p['enabled'])?'disabled':($verified?'catalog_api_test_verified':($credentialed?'ready_to_test':'credentials_or_merchant_missing')),
   'can_import_jetengine'=>$verified,
   'mode'=>$p['mode'],
   'catalog_endpoint'=>'/integration/vehicle-classes',
  ];
 }
 static function configuration_fingerprint(string $provider):string {
  $p=self::profile($provider);
  return hash('sha256',wp_json_encode([
    $provider,$p['mode'],$p['merchant_slug'],$p['client_id'],
    $p['merchant_id'],$p['key_id'],$p['secrets']
  ]));
 }
 static function public():array {
  $out=[];foreach(self::definitions() as $id=>$d){
   $p=self::profile($id);$state=self::ready($id);
   $out[$id]=['title'=>$d['title'],'capabilities'=>$d['model'],
     'source'=>$d['source'],'state'=>$state,
     'pricing_rule_kind'=>$p['pricing_rule_kind'],
     'catalog_sync'=>$p['catalog_sync'],'fare_mode'=>$p['fare_mode'],
     'jetengine_catalog_count'=>self::catalog_count($id),
     'rate_auto_publish'=>false,'woo_order_auto_create'=>false];
  }
  return ['contract'=>self::CONTRACT,'site'=>home_url(),
    'providers'=>$out,'jetengine_cpt'=>'transportations',
    'native_rate_cpt'=>'transport-rates','prices_inferred'=>false];
 }
 static function catalog_count(string $id):int {
  global $wpdb;
  return (int)$wpdb->get_var($wpdb->prepare(
   "SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id
    WHERE m.meta_key=%s AND m.meta_value=%s AND p.post_type='transportations'",
   '_mad4b_ground_provider_id',$id));
 }
 static function save(string $id,array $raw){
  if(!self::can_manage())return new WP_Error('ground_provider_manage_forbidden');
  if(!array_key_exists($id,self::definitions()))return new WP_Error('ground_provider_unknown');
  $p=self::profile($id);
  $mode=sanitize_key((string)($raw['mode']??'sandbox'));
  $kind=sanitize_key((string)($raw['pricing_rule_kind']??'not_configured'));
  $value=trim((string)($raw['pricing_rule_value']??''));
  if(!in_array($mode,['sandbox','production'],true)||
    !in_array($kind,['not_configured','markup_percent','target_margin_percent','fixed_profit'],true)||
    !in_array((string)($raw['fare_mode']??'on_request'),['on_request','hide'],true)||
    !in_array((string)($raw['catalog_sync']??'manual'),['manual','daily'],true))
     return new WP_Error('ground_provider_settings_invalid');
  if($kind==='not_configured')$value='';
  elseif(!preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,4})?$/D',$value)||
       (float)$value<=0 ||($kind!=='fixed_profit'&&(float)$value>300)||
       ($kind==='target_margin_percent'&&(float)$value>=99))
    return new WP_Error('ground_provider_pricing_invalid');
  // Credential sets are environment-specific. Switching environments requires
  // a fresh complete identity + fresh secrets, never reuse Sandbox in Production.
  if($id==='egyptlimo' && $mode!==$p['mode']){
    foreach(['merchant_slug','client_id','merchant_id','key_id','client_secret','signing_secret'] as $required)
      if(trim((string)($raw[$required]??''))==='')
        return new WP_Error('ground_egyptlimo_environment_requires_new_credentials');
    $p['secrets']=[];
  }
  $p['enabled']=!empty($raw['enabled']);
  $p['mode']=$mode;$p['fare_mode']=$raw['fare_mode'];
  $p['catalog_sync']=$raw['catalog_sync'];
  $p['pricing_rule_kind']=$kind;$p['pricing_rule_value']=$value;
  if($id==='egyptlimo'){
   foreach(['merchant_slug','client_id','merchant_id','key_id'] as $name){
    $value=sanitize_text_field(trim((string)($raw[$name]??'')));
    if(strlen($value)>100 ||($value!==''&&!preg_match('/^[A-Za-z0-9_-]+$/D',$value)))
      return new WP_Error('ground_provider_identity_invalid');
    $p[$name]=$value;
   }
   if($p['merchant_slug']!==''&&is_wp_error(self::base_url($p)))
    return new WP_Error('ground_provider_merchant_hostname_invalid');
   foreach(['client_secret','signing_secret'] as $key){
    $value=trim((string)($raw[$key]??''));
    if($value!==''){
     if(strlen($value)<12||strlen($value)>400)return new WP_Error('ground_provider_secret_invalid');
     $sealed=self::encrypt($value);
     if(is_wp_error($sealed))return $sealed;
     $p['secrets'][$key]=$sealed;
    }
   }
  }
  // Settings never authorize publication or create WooCommerce orders.
  $p['allow_order_creation']=false;
  $p['allow_rate_publication']=false;
  $p['catalog_import_status']='draft_review_only';
  $profiles=self::profiles();$profiles[$id]=$p;
  update_option(self::OPTION,$profiles,false);
  if(self::profile($id)!==$p)return new WP_Error('ground_provider_settings_readback_mismatch');
  return ['status'=>'saved','provider_id'=>$id,'connection'=>self::ready($id)];
 }
 static function access_token(string $scope='catalog:read') {
  if(!in_array($scope,['catalog:read','quotes:read'],true))
    return new WP_Error('ground_egyptlimo_scope_denied');
  $p=self::profile('egyptlimo');
  if(!self::ready('egyptlimo')['credentialed'])return new WP_Error('ground_egyptlimo_credentials_missing');
  $base=self::base_url($p);if(is_wp_error($base))return $base;
  $payload=wp_json_encode(['grant_type'=>'client_credentials','client_id'=>$p['client_id'],
   'client_secret'=>self::secret('egyptlimo','client_secret'),'scope'=>$scope]);
  $res=wp_safe_remote_post($base.'/integration/oauth2/token',[
   'headers'=>['Content-Type'=>'application/json','Accept'=>'application/json'],
   'body'=>$payload,'timeout'=>15,'redirection'=>0,'limit_response_size'=>16384,'sslverify'=>true]);
  if(is_wp_error($res))return new WP_Error('ground_egyptlimo_token_transport_failed');
  if((int)wp_remote_retrieve_response_code($res)!==200)return new WP_Error('ground_egyptlimo_token_denied');
  $body=json_decode((string)wp_remote_retrieve_body($res),true);
  if(!is_array($body)||!is_string($body['access_token']??null)||
     !in_array(($body['token_type']??''),['Bearer','bearer'],true))
     return new WP_Error('ground_egyptlimo_token_invalid');
  return $body['access_token'];
 }
 static function egyptlimo_get(string $path) {
  $allowed=['/integration/capabilities','/integration/vehicle-classes'];
  if(!in_array($path,$allowed,true))return new WP_Error('ground_egyptlimo_catalog_path_forbidden');
  $p=self::profile('egyptlimo');
  if(empty($p['enabled']))return new WP_Error('ground_provider_disabled');
  $base=self::base_url($p);if(is_wp_error($base))return $base;
  $token=self::access_token();if(is_wp_error($token))return $token;
  $stamp=gmdate('Y-m-d\TH:i:s\Z');$nonce=wp_generate_uuid4();
  $canonical=implode("\n",['GET','/api/v1'.$path,'',hash('sha256',''),$stamp,$nonce,$p['merchant_id']]);
  $signature=hash_hmac('sha256',$canonical,self::secret('egyptlimo','signing_secret'));
  $resp=wp_safe_remote_get($base.$path,[
    'timeout'=>16,'redirection'=>0,'limit_response_size'=>524288,'sslverify'=>true,
    'headers'=>['Authorization'=>'Bearer '.$token,
      'Accept'=>'application/json','X-Merchant-Id'=>$p['merchant_id'],
      'X-Key-Id'=>$p['key_id'],'X-Timestamp'=>$stamp,
      'X-Nonce'=>$nonce,'X-Signature'=>$signature]]);
  if(is_wp_error($resp))return new WP_Error('ground_egyptlimo_catalog_transport_failed');
  if((int)wp_remote_retrieve_response_code($resp)!==200)
     return new WP_Error('ground_egyptlimo_catalog_access_denied');
  $body=json_decode((string)wp_remote_retrieve_body($resp),true);
  if(!is_array($body))return new WP_Error('ground_egyptlimo_catalog_json_invalid');
  return $body;
 }
 static function vehicle_term(string $type):string {
  return match(strtolower(trim($type))){
    'sedan','car'=>'private-car','van'=>'private-van',
    'minibus'=>'private-minibus','coach','bus'=>'private-coach-bus',
    'suv'=>'luxury-suv','limousine'=>'limousine',default=>''
  };
 }
 static function normalize_vehicle(array $raw) {
  $id=(string)($raw['code']??'');
  $title=trim(wp_strip_all_tags((string)($raw['name']??'')));
  $type=trim((string)($raw['type']??''));
  $capacity=$raw['passenger_capacity']??null;
  $bags=$raw['bag_capacity']??null;
  if(!preg_match('/^tvc_[A-Za-z0-9_-]{3,100}$/D',$id)||$title===''||strlen($title)>180||
     !is_numeric($capacity)||(int)$capacity<1||(int)$capacity>100||
     (string)(int)$capacity!==(string)$capacity)
    return new WP_Error('ground_vehicle_catalog_entry_invalid');
  if(!is_numeric($bags)||(int)$bags<0||(int)$bags>100||
      (string)(int)$bags!==(string)$bags)
    return new WP_Error('ground_vehicle_luggage_unverified');
  $term=self::vehicle_term($type);
  return [
    'supplier_vehicle_code'=>$id,'name'=>$title,'type'=>$type,
    'vehicle_class'=>sanitize_text_field((string)($raw['class']??'')),
    'passenger_capacity'=>(int)$capacity,'bag_capacity'=>(int)$bags,
    'vehicle_term'=>$term,
    'source_sha256'=>hash('sha256',wp_json_encode([
        $id,$title,$type,(int)$capacity,(int)$bags,
        (string)($raw['class']??'')
    ],JSON_UNESCAPED_SLASHES))];
 }
 private static function import_catalog(string $provider,array $api){
  if(!self::can_manage())return new WP_Error('ground_provider_sync_not_authorized');
  if($provider!=='egyptlimo'||!isset($api['items'])||!is_array($api['items'])||
     count($api['items'])>100)return new WP_Error('ground_provider_catalog_shape_invalid');
  $p=self::profile($provider);if(empty($p['enabled']))return new WP_Error('ground_provider_disabled');
  $report=['provider_id'=>$provider,'source'=>'authenticated_partner_api',
   'records_received'=>count($api['items']),'created_drafts'=>0,
   'updated_drafts'=>0,'unchanged'=>0,'review_needed'=>0,
   'errors'=>[],'published'=>0,'rates_created'=>0,'woo_products_created'=>0,
   'editor'=>'JetEngine native transportations'];
  $seen=[];
  foreach($api['items'] as $row){
    if(!is_array($row)){ $report['errors'][]='ground_vehicle_record_invalid';continue; }
    $v=self::normalize_vehicle($row);
    if(is_wp_error($v)){
      $report['errors'][]=$v->get_error_code();continue;
    }
    $identity=hash('sha256',$provider.'|'.$p['mode'].'|'.$p['client_id'].'|'.$v['supplier_vehicle_code']);
    if(isset($seen[$identity])){ $report['errors'][]='ground_catalog_duplicate_identity';continue; }
    $seen[$identity]=true;
    $found=get_posts(['post_type'=>'transportations','post_status'=>'any',
      'posts_per_page'=>2,'fields'=>'ids','suppress_filters'=>true,
      'meta_key'=>'_mad4b_ground_provider_identity','meta_value'=>$identity]);
    if(count($found)>1){$report['errors'][]='ground_catalog_identity_collision';continue;}
    $old=$found?(int)$found[0]:0;
    if($old && get_post_status($old)!=='draft'){
      $report['review_needed']++;continue;
    }
    if($old && get_post_meta($old,'_mad4b_ground_catalog_source_sha256',true)===$v['source_sha256']){
      $report['unchanged']++;continue;
    }
    $title='EgyptLimo · '.$v['name'];
    if(!$old){
      $id=wp_insert_post(['post_type'=>'transportations',
        'post_status'=>'draft','post_title'=>$title,'post_author'=>get_current_user_id(),
        'post_content'=>'Supplier vehicle-class catalogue. Route, price and availability require a separately verified quote.'],true);
      if(is_wp_error($id)||!$id){$report['errors'][]='ground_jetengine_post_insert_failed';continue;}
    }else{
      $id=$old;
      $priorImportedTitle=(string)get_post_meta($id,'_mad4b_ground_imported_title',true);
      // Preserve editorial titles instead of overwriting manually edited JetEngine records.
      if($priorImportedTitle!=='' && get_the_title($id)===$priorImportedTitle)
        wp_update_post(['ID'=>$id,'post_title'=>$title]);
    }
    $fields=[
      '_mad4b_ground_provider_id'=>$provider,
      '_mad4b_ground_provider_identity'=>$identity,
      '_mad4b_ground_catalog_source_sha256'=>$v['source_sha256'],
      '_mad4b_ground_vehicle_code'=>$v['supplier_vehicle_code'],
      '_mad4b_ground_vehicle_name'=>$v['name'],
      '_mad4b_ground_provider_mode'=>$p['mode'],
      '_mad4b_ground_provider_vehicle_class'=>$v['vehicle_class'],
      '_mad4b_ground_source_type'=>$v['type'],
      '_mad4b_ground_imported_at'=>gmdate('c'),
      '_mad4b_ground_imported_title'=>$title,
      '_mad4b_ground_content_state'=>'AWAITING_ROUTE_PRICE_AVAILABILITY_VERIFICATION',
      '_mad4b_ground_content_alerts'=>[
        'route_not_configured','supplier_price_not_quoted',
        'availability_not_verified','operator_merchant_review_required'],
      'trans_no_passengers'=>$v['passenger_capacity'],
      'trans_luggage'=>$v['bag_capacity'],
    ];
    foreach($fields as $k=>$value){
      // JetEngine editor has final say on capacity and luggage after initial sync.
      if($old && in_array($k,['trans_no_passengers','trans_luggage'],true)){
        $last=(string)get_post_meta($id,'_mad4b_ground_last_synced_'.$k,true);
        $existing=(string)get_post_meta($id,$k,true);
        if($last!=='' && $existing!==$last){
          $report['review_needed']++;
          continue;
        }
      }
      update_post_meta($id,$k,$value);
      if(in_array($k,['trans_no_passengers','trans_luggage'],true))
        update_post_meta($id,'_mad4b_ground_last_synced_'.$k,(string)$value);
    }
    if($v['vehicle_term']!=='' && (!$old||!get_post_meta($id,'_mad4b_ground_terms_editor_locked',true))){
      $term=get_term_by('slug',$v['vehicle_term'],'transportation-types');
      if($term)wp_set_object_terms($id,[(int)$term->term_id],'transportation-types',false);
    }
    // A vehicle class is not a bookable route, a supplier net fare, or a seat inventory.
    if(get_post_type($id)!=='transportations'||get_post_status($id)!=='draft'||
       get_post_meta($id,'_mad4b_ground_provider_identity',true)!==$identity)
      $report['errors'][]='ground_jetengine_catalog_readback_failed';
    else $report[$old?'updated_drafts':'created_drafts']++;
  }
  $ledger=(array)get_option(self::LEDGER,[]);
  $ledger[]=array_merge($report,['at'=>gmdate('c'),'actor'=>get_current_user_id()]);
  update_option(self::LEDGER,array_slice($ledger,-30),false);
  return $report;
 }
 static function refresh(string $provider){
  if(!self::can_manage())return new WP_Error('ground_provider_sync_forbidden');
  if(!isset(self::definitions()[$provider]))return new WP_Error('ground_provider_unknown');
  $p=self::profile($provider);
  $state=self::ready($provider);
  if(empty($p['enabled'])||($p['catalog_sync']??'manual')!=='daily'||
      empty($state['connected']))
   return ['provider_id'=>$provider,'status'=>'SKIPPED_NOT_CONNECTED_OR_NOT_SCHEDULED',
      'jetengine_posts_created'=>0,'rates_created'=>0];
  return self::sync($provider);
 }
 static function sync(string $provider){
  if(!self::can_manage())return new WP_Error('ground_provider_sync_forbidden');
  if($provider!=='egyptlimo')return new WP_Error('ground_provider_no_documented_catalog_api');
  $cap=self::egyptlimo_get('/integration/capabilities');
  if(is_wp_error($cap))return $cap;
  $catalog=self::egyptlimo_get('/integration/vehicle-classes');
  if(is_wp_error($catalog))return $catalog;
  return self::import_catalog($provider,$catalog);
 }
}
if(defined('WP_CLI')&&WP_CLI){
 WP_CLI::add_command('mad4b ground-providers',static function($args){
  $op=$args[0]??'status';$id=$args[1]??'egyptlimo';
  if($op==='status')$r=MAD4B_Ground_Provider_Core_V1::public();
  elseif($op==='sync')$r=MAD4B_Ground_Provider_Core_V1::sync($id);
  elseif($op==='refresh')$r=MAD4B_Ground_Provider_Core_V1::refresh($id);
  else WP_CLI::error('Use status, sync or refresh <provider>');
  if(is_wp_error($r))WP_CLI::error($r->get_error_code());
  WP_CLI::line(wp_json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
 });
}

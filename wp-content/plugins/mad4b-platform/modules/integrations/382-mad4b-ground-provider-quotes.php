<?php
/**
 * Plugin Name: MAD4B Ground Transport Live Quote Intake
 * Version: 0.1.0
 * Description: Authenticated EgyptLimo quote observations attached to native JetEngine Transportation drafts; no sale or supplier booking.
 */
defined('ABSPATH') || exit;

final class MAD4B_Ground_Provider_Quotes_V1 {
 const POST_ACTION='mad4b_ground_quote_request';
 const META='_mad4b_ground_supplier_quote_v1';
 const ALERTS='mad4b_ground_provider_content_alerts_v1';
 const RECEIPT='mad4b_ground_provider_quote_last_result_v1';
 const CONTRACT='mad4b.ground-transport-quote.v1';
 static function authorized():bool {
  return class_exists('MAD4B_Ground_Provider_Core_V1') &&
    MAD4B_Ground_Provider_Core_V1::can_manage();
 }
 static function check_post($id){
  $id=absint($id);
  if(!$id||get_post_type($id)!=='transportations'||
     get_post_status($id)!=='draft'||
     get_post_meta($id,'_mad4b_ground_provider_id',true)!=='egyptlimo')
     return new WP_Error('ground_quote_native_vehicle_draft_required');
  $p=MAD4B_Ground_Provider_Core_V1::profile('egyptlimo');
  $code=(string)get_post_meta($id,'_mad4b_ground_vehicle_code',true);
  if(!preg_match('/^tvc_[A-Za-z0-9_-]{3,100}$/D',$code))
     return new WP_Error('ground_quote_vehicle_code_invalid');
  $identity=hash('sha256','egyptlimo|'.$p['mode'].'|'.$p['client_id'].'|'.$code);
  if(get_post_meta($id,'_mad4b_ground_provider_identity',true)!==$identity)
     return new WP_Error('ground_quote_vehicle_client_identity_changed');
  $vehicleName=(string)get_post_meta($id,'_mad4b_ground_vehicle_name',true);
  if($vehicleName===''||strlen($vehicleName)>180)
    return new WP_Error('ground_quote_vehicle_label_not_imported');
  return ['post_id'=>$id,'code'=>$code,'vehicle_name'=>$vehicleName,
    'capacity'=>absint(get_post_meta($id,'trans_no_passengers',true)),
    'client_id'=>(string)$p['client_id'],'mode'=>(string)$p['mode']];
 }
 static function normalize_request(array $request){
  $fields=['post_id','pickup_address','pickup_lat','pickup_lng',
    'dropoff_address','dropoff_lat','dropoff_lng','pickup_datetime','passengers'];
  if(array_diff(array_keys($request),$fields))
     return new WP_Error('ground_quote_unknown_fields');
  $post=self::check_post($request['post_id']??0);
  if(is_wp_error($post))return $post;
  $location=[];
  foreach(['pickup','dropoff'] as $side){
   $lat=$request[$side.'_lat']??null;
   $lng=$request[$side.'_lng']??null;
   $address=trim(sanitize_text_field((string)($request[$side.'_address']??'')));
   if(!is_scalar($lat)||!is_scalar($lng)||!is_numeric($lat)||!is_numeric($lng)||
     !is_finite((float)$lat)||!is_finite((float)$lng)||
     abs((float)$lat)>90||abs((float)$lng)>180||
     strlen($address)<4||strlen($address)>180)
     return new WP_Error('ground_quote_location_invalid');
   if($side==='pickup')$location['pickup_location']=[
     'lat'=>(float)$lat,'lng'=>(float)$lng,'address'=>$address];
   else $location['dropoff_location']=[
     'lat'=>(float)$lat,'lng'=>(float)$lng,'address'=>$address];
  }
  if($location['pickup_location']['lat']===$location['dropoff_location']['lat']&&
     $location['pickup_location']['lng']===$location['dropoff_location']['lng'])
     return new WP_Error('ground_quote_identical_locations');
  $pass=filter_var($request['passengers']??null,FILTER_VALIDATE_INT,[
    'options'=>['min_range'=>1,'max_range'=>70]]);
  if($pass===false||!$post['capacity']||$pass>$post['capacity'])
     return new WP_Error('ground_quote_vehicle_capacity_exceeded');
  $raw=(string)($request['pickup_datetime']??'');
  if(!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D',$raw))
     return new WP_Error('ground_quote_datetime_invalid');
  $tz=new DateTimeZone('Africa/Cairo');
  $dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$raw,$tz);
  if(!$dt||$dt->format('Y-m-d\TH:i')!==$raw||
     $dt->getTimestamp()<time()+600||
     $dt->getTimestamp()>time()+366*86400)
     return new WP_Error('ground_quote_datetime_outside_validity');
  return [
    'post'=>$post,
    'route'=>$location,
    'pickup_datetime'=>$dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
    'passengers'=>$pass,
  ];
 }
 static function supplier_quote_request(array $validated){
  $p=MAD4B_Ground_Provider_Core_V1::profile('egyptlimo');
  if(empty($p['enabled']))return new WP_Error('ground_quote_provider_disabled');
  if(empty(MAD4B_Ground_Provider_Core_V1::ready('egyptlimo')['credentialed']))
    return new WP_Error('ground_quote_credentials_missing');
  $base=MAD4B_Ground_Provider_Core_V1::base_url($p);
  if(is_wp_error($base))return $base;
  $token=MAD4B_Ground_Provider_Core_V1::access_token('quotes:read');
  if(is_wp_error($token))return $token;
  $body=[
    'pickup_location'=>$validated['route']['pickup_location'],
    'dropoff_location'=>$validated['route']['dropoff_location'],
    'pickup_datetime'=>$validated['pickup_datetime'],
    'passengers'=>$validated['passengers'],
    'partner_reference'=>'MAD4B-QUOTE-'.wp_generate_uuid4(),
  ];
  $payload=wp_json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  if(!is_string($payload))return new WP_Error('ground_quote_json_failed');
  $path='/api/v1/integration/quotes';
  $timestamp=gmdate('Y-m-d\TH:i:s\Z');
  $nonce=wp_generate_uuid4();
  $string=implode("\n",['POST',$path,'',hash('sha256',$payload),
    $timestamp,$nonce,$p['merchant_id']]);
  $signature=hash_hmac('sha256',$string,
    MAD4B_Ground_Provider_Core_V1::secret('egyptlimo','signing_secret'));
  $response=wp_safe_remote_post($base.'/integration/quotes',[
    'timeout'=>18,'redirection'=>0,'sslverify'=>true,
    'limit_response_size'=>131072,
    'headers'=>[
      'Authorization'=>'Bearer '.$token,
      'Accept'=>'application/json','Content-Type'=>'application/json',
      'X-Merchant-Id'=>$p['merchant_id'],'X-Key-Id'=>$p['key_id'],
      'X-Timestamp'=>$timestamp,'X-Nonce'=>$nonce,
      'X-Signature'=>$signature,
      'Idempotency-Key'=>wp_generate_uuid4(),
      'X-Correlation-Id'=>'mad4b-'.wp_generate_uuid4(),
    ],
    'body'=>$payload,
  ]);
  if(is_wp_error($response))return new WP_Error('ground_quote_network_or_tls_failed');
  if(!in_array((int)wp_remote_retrieve_response_code($response),[200,201],true))
     return new WP_Error('ground_quote_supplier_http_rejected');
  $result=json_decode((string)wp_remote_retrieve_body($response),true);
  if(!is_array($result))return new WP_Error('ground_quote_supplier_json_invalid');
  return $result;
 }
 static function normalize_receipt(array $quote,array $request) {
  $quoteId=(string)($quote['quote_id']??'');
  $currency=(string)($quote['currency']??'');
  $expires=(string)($quote['expires_at']??'');
  $expiry=strtotime($expires);
  $options=$quote['options']??null;
  if(!preg_match('/^[A-Za-z0-9_-]{5,128}$/D',$quoteId)||
    !preg_match('/^[A-Z]{3}$/D',$currency)||
    $expiry===false||$expiry<=time()||$expiry>time()+7200||
    !is_array($options)||!$options||count($options)>40)
     return new WP_Error('ground_quote_supplier_receipt_invalid');
  $summary=[];
  foreach($options as $option){
    if(!is_array($option))return new WP_Error('ground_quote_supplier_option_invalid');
    $vehicle=(string)($option['vehicle_class']??'');
    if($vehicle===''||strlen($vehicle)>140)
      return new WP_Error('ground_quote_vehicle_class_missing');
    $amount=null;$field='';
    foreach(['price','total','total_amount','amount'] as $candidate){
      $value=$option[$candidate]??null;
      if((is_float($value)||is_int($value)||is_string($value))&&
         preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,4})?$/D',(string)$value)&&
         (float)$value>0){
        $amount=number_format((float)$value,2,'.','');
        $field=$candidate;
        break;
      }
    }
    if($vehicle!==$request['post']['vehicle_name'])continue;
    $summary[]=[
      'vehicle_class'=>sanitize_text_field($vehicle),
      'supplier_quote_amount'=>$amount,
      'source_field'=>$field,
      'amount_type'=>'source_customer_facing_quote_not_supplier_net_cost',
      'currency'=>$currency,
    ];
  }
  if(!$summary)return new WP_Error('ground_quote_vehicle_option_not_in_supplier_quote');
  return [
    'contract'=>self::CONTRACT,'provider'=>'egyptlimo',
    'quote_id'=>$quoteId,'quote_status'=>sanitize_key((string)($quote['status']??'observed')),
    'currency'=>$currency,'expires_at'=>gmdate('c',$expiry),
    'observed_at'=>gmdate('c'),
    'price_basis'=>'supplier_customer_quote_not_verified_net_cost',
    'options'=>$summary,
    'vehicle_post_id'=>$request['post']['post_id'],
    'vehicle_code'=>$request['post']['code'],
    'passengers'=>$request['passengers'],
    'pickup_datetime'=>$request['pickup_datetime'],
    'route'=>[
      'pickup'=>$request['route']['pickup_location']['address'],
      'dropoff'=>$request['route']['dropoff_location']['address'],
    ],
    'mode'=>$request['post']['mode'],
    'client_id_sha256'=>hash('sha256',$request['post']['client_id']),
    'supplier_inventory_guaranteed'=>false,
    'supplier_net_cost_verified'=>false,
    'rate_creation_authorized'=>false,
    'woocommerce_order_created'=>false,
  ];
 }
 static function request(array $data){
  if(!self::authorized())return new WP_Error('ground_quote_admin_permission_denied');
  $r=self::normalize_request($data);
  if(is_wp_error($r))return $r;
  $response=self::supplier_quote_request($r);
  if(is_wp_error($response))return $response;
  $receipt=self::normalize_receipt($response,$r);
  if(is_wp_error($receipt))return $receipt;
  $id=$r['post']['post_id'];
  update_post_meta($id,self::META,$receipt);
  $stored=get_post_meta($id,self::META,true);
  if(!is_array($stored)||($stored['quote_id']??'')!==$receipt['quote_id'])
     return new WP_Error('ground_quote_jetengine_readback_failed');
  $issue=[
    'type'=>'ground_api_quote_not_supplier_net_cost',
    'object_id'=>$id,
    'priority'=>75,
    'status'=>'open',
    'supplier'=>'egyptlimo',
    'reason'=>'Authenticated supplier customer quote is not a validated net supplier cost, financial margin or confirmed inventory. Do not create commercial Rate or Woo Order.',
    'source_quote_id'=>$receipt['quote_id'],
    'quote_expires_at'=>$receipt['expires_at'],
    'last_observed_at'=>gmdate('c'),
    'needs_content_job_review'=>true,
    'booking_authorized'=>false,
  ];
  $alerts=(array)get_option(self::ALERTS,[]);
  $alerts['egyptlimo:'.$id.':quote']=$issue;
  update_option(self::ALERTS,$alerts,false);
  return [
    'state'=>'QUOTE_OBSERVED_IN_JETENGINE_DRAFT',
    'post_id'=>$id,'quote_id'=>$receipt['quote_id'],
    'expires_at'=>$receipt['expires_at'],
    'options_count'=>count($receipt['options']),
    'currency'=>$receipt['currency'],
    'commercial_rates_created'=>0,'woo_orders_created'=>0,
  ];
 }
 static function render_form(){
  if(!self::authorized())return;
  $p=MAD4B_Ground_Provider_Core_V1::profile('egyptlimo');
  $ready=MAD4B_Ground_Provider_Core_V1::ready('egyptlimo');
  echo '<hr><h3>Live Transfer Quote → JetEngine Draft</h3>';
  echo '<p>This is an authenticated request for exact vehicle options, route and date. The response is saved on an existing <code>transportations</code> draft; it never publishes a <code>transport-rates</code> or creates a WooCommerce Order.</p>';
  $ids=get_posts(['post_type'=>'transportations','post_status'=>'draft',
    'posts_per_page'=>100,'fields'=>'ids','suppress_filters'=>true,
    'meta_key'=>'_mad4b_ground_provider_id','meta_value'=>'egyptlimo']);
  if(!$ids){
    echo '<p>No EgyptLimo vehicle-class drafts yet. Save partner credentials, test the API, then import its catalogue first.</p>';
    return;
  }
  if(empty($ready['credentialed'])||empty($p['enabled'])){
    echo '<p>Connect and enable the partner API before requesting a quote.</p>';
    return;
  }
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field(self::POST_ACTION);
  echo '<input type="hidden" name="action" value="'.esc_attr(self::POST_ACTION).'">';
  echo '<table class="form-table"><tbody><tr><th>JetEngine vehicle draft</th><td><select name="post_id">';
  foreach($ids as $id)echo '<option value="'.(int)$id.'">'.esc_html(get_the_title($id)).' ('.(int)$id.')</option>';
  echo '</select></td></tr>';
  foreach(['pickup'=>'Pickup','dropoff'=>'Drop-off'] as $side=>$label){
    echo '<tr><th>'.esc_html($label).' address</th><td><input class="regular-text" required maxlength="180" name="'.esc_attr($side.'_address').'" placeholder="Place name"></td></tr>';
    foreach(['lat'=>'latitude','lng'=>'longitude'] as $k=>$name){
      echo '<tr><th>'.esc_html($label.' '.$name).'</th><td><input type="number" required min="'.($k==='lat'?-90:-180).'" max="'.($k==='lat'?90:180).'" step="any" name="'.esc_attr($side.'_'.$k).'"></td></tr>';
    }
  }
  echo '<tr><th>Pickup time (Africa/Cairo)</th><td><input required type="datetime-local" name="pickup_datetime"></td></tr>';
  echo '<tr><th>Passengers</th><td><input required type="number" min="1" max="70" name="passengers" value="2"></td></tr>';
  echo '</tbody></table>';
  submit_button('Request signed supplier quote (no booking)');
  echo '</form>';
  $last=(array)get_option(self::RECEIPT,[]);
  if($last){
   echo '<p><strong>Last quote request:</strong> '.esc_html((string)($last['state']??'unknown')).
    ' · '.esc_html((string)($last['at']??'')).
    ' · '.esc_html((string)($last['code']??'')).'</p>';
  }
 }
 static function post(){
  if(!self::authorized())wp_die('Provider administrator access is required',403);
  check_admin_referer(self::POST_ACTION);
  $keys=['post_id','pickup_address','pickup_lat','pickup_lng',
    'dropoff_address','dropoff_lat','dropoff_lng','pickup_datetime','passengers'];
  $data=[];
  foreach($keys as $key) $data[$key]=isset($_POST[$key])&&is_scalar($_POST[$key])?
    sanitize_text_field(wp_unslash((string)$_POST[$key])):'';
  $result=self::request($data);
  $ok=!is_wp_error($result);
  update_option(self::RECEIPT,[
     'state'=>$ok?'QUOTE_RECEIVED':'HOLD',
     'code'=>$ok?'quote_saved_to_jetengine':$result->get_error_code(),
     'at'=>gmdate('c'),'post_id'=>absint($data['post_id']),
     'expires_at'=>$ok?($result['expires_at']??null):null,
   ],false);
  wp_safe_redirect(add_query_arg([
    'page'=>'mad4b-ground-transport-providers','provider'=>'egyptlimo',
    'result'=>$ok?'success':'review'
  ],admin_url('admin.php')));
  exit;
 }
 static function metabox(){
  if(!current_user_can('manage_woocommerce'))return;
  add_meta_box('mad4b-ground-quote','MAD4B Supplier Quote Evidence',
    [__CLASS__,'render_metabox'],'transportations','side','default');
 }
 static function render_metabox($post){
  if(!current_user_can('manage_woocommerce'))return;
  $row=get_post_meta($post->ID,self::META,true);
  if(!is_array($row)){
    echo '<p>No authenticated supplier quote linked to this vehicle.</p>';return;
  }
  echo '<p><strong>Provider:</strong> '.esc_html($row['provider']??'').'</p>';
  echo '<p><strong>Expires:</strong> '.esc_html($row['expires_at']??'').'</p>';
  echo '<p><strong>Quote state:</strong> '.esc_html(strtotime((string)($row['expires_at']??''))>time()?
     'Valid for research only':'Expired').'</p>';
  echo '<p>Quote does not prove net cost, inventory, price approval or a Woo order.</p>';
 }
}
add_action('admin_post_'.MAD4B_Ground_Provider_Quotes_V1::POST_ACTION,
 ['MAD4B_Ground_Provider_Quotes_V1','post']);
add_action('add_meta_boxes',['MAD4B_Ground_Provider_Quotes_V1','metabox']);

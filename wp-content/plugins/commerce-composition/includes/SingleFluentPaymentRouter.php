<?php
declare(strict_types=1);
namespace Commerce\Composition;
require_once __DIR__.'/LifecycleAccess.php';
/** Opt-in single Fluent form -> server-verified rate reference -> existing Woo advance checkout. */
final class SingleFluentPaymentRouter {
 const OPTION='commerce_single_fluent_payment_routing_v1';
 public static function config():array {
  $o=get_option(self::OPTION,[]);
  return is_array($o)?array_replace([
   'enabled'=>'no','form_id'=>0,'source_post_type'=>'',
   'rate_post_type'=>'','parent_meta'=>'related_tour_id','date_field'=>'datetime',
   'adult_field'=>'no_of_adults','child_field'=>'no_of_children',
   'infant_field'=>'no_of_infants','allow_live'=>'no'
  ],$o):[];
 }
 public static function boot():void {
  add_action('fluentform/form_element_start',[self::class,'render'],30,1);
  add_filter('fluentform/filter_insert_data',[self::class,'capture'],65,1);
  add_action('commerce_composition_request_advance_created',[self::class,'annotate'],5,3);
  add_action('woocommerce_before_pay_action',[self::class,'validatePayment'],12,1);
  add_filter('woocommerce_coming_soon_exclude',[self::class,'allowCheckout'],70,1);
 }
 public static function active(int $source,int $form):bool {
  if(!LifecycleAccess::available())return false;
  $c=self::config();
  if(($c['enabled']??'no')!=='yes'||(int)($c['form_id']??0)!==$form||$source<1||
   get_post_type($source)!==($c['source_post_type']??'')||
   get_post_status($source)!=='publish')return false;
  $route=FormRouter::templateIdentity($source);
  if(is_wp_error($route)||($route['booking_form_id']??null)!==$form)return false;
  $p=RequestAdvancePayments::policy($source);
  if(($p['enabled']??'no')!=='yes' || !in_array(($p['mode']??''),['percent','fixed','full'],true)||
     trim((string)($p['terms']??''))===''||
     ($p['form_ids']&&!in_array($form,array_map('intval',$p['form_ids']),true)))return false;
  $s=get_option('woocommerce_stripe_settings',[]);
  return ($s['enabled']??'no')==='yes' &&
    (($s['testmode']??'no')==='yes'||($c['allow_live']??'no')==='yes');
 }
 private static function siblings(int $source):array {
  $ids=[$source];$type=get_post_type($source);global $wpdb;
  if(!is_string($type))return $ids;
  $trid=$wpdb->get_var($wpdb->prepare(
   "SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id=%d",
   'post_'.$type,$source));
  if($trid)foreach((array)$wpdb->get_col($wpdb->prepare(
   "SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE trid=%d AND element_type=%s",
   (int)$trid,'post_'.$type)) as $id)
   if((int)$id>0 && get_post_status((int)$id)==='publish')$ids[]=(int)$id;
  return array_values(array_unique($ids));
 }
 private static function sourceLanguage(int $id):?string {
  global $wpdb;$type=get_post_type($id);
  if(!is_string($type))return null;
  $code=$wpdb->get_var($wpdb->prepare(
   "SELECT language_code FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type=%s LIMIT 1",
   $id,'post_'.$type));
  return is_string($code)&&$code!==''?$code:null;
 }
 public static function rates(int $source):array {
  $c=self::config();$siblings=self::siblings($source);
  $language=self::sourceLanguage($source);
  $posts=get_posts(['post_type'=>$c['rate_post_type'],'post_status'=>'publish',
   'posts_per_page'=>75,'suppress_filters'=>true,
   'meta_query'=>[['key'=>$c['parent_meta'],'value'=>$siblings,'compare'=>'IN']]]);
  $rows=[];
  foreach($posts as $rate){
   if($rate->post_password!=='')continue;
   // A multilingual company must not offer the same rate twice in different
   // languages, or inadvertently quote a sibling's market-specific amount.
   if($language!==null&&self::sourceLanguage((int)$rate->ID)!==$language)continue;
   $parent=(int)get_post_meta($rate->ID,$c['parent_meta'],true);
   if(!in_array($parent,$siblings,true)||is_wp_error(RateProvenance::verify($parent,$rate->ID)))continue;
   if(in_array(strtolower((string)get_post_meta($rate->ID,'stop_sale',true)),['1','yes','true'],true)
      ||(string)get_post_meta($rate->ID,'avilabile_status',true)==='0')continue;
   $end=(int)get_post_meta($rate->ID,'tour_rate_end_date',true);
   if($end>0 && $end<time())continue;
   $rows[]=$rate;
  }
  return $rows;
 }
 /**
  * Public, non-transactional eligibility probe for an unbound Direct source.
  * It does not promise a particular date/amount or create checkout authority.
  * Each real Form #6 submission must still pass quoteDirect() and HMAC proof.
  */
 public static function directSourceCandidate(int $source):bool {
  if($source<1||get_post_type($source)!==(self::config()['source_post_type']??'')
      ||get_post_status($source)!=='publish'
      ||get_post_field('post_password',$source)!==''
      ||get_post_meta($source,'_booking_experience_qa_pricing_fixture',true)!==''
      ||get_post_meta($source,'_shared_tour_price_entry_mode_v1',true)==='inquiry'
      ||in_array(strtolower((string)get_post_meta($source,'stop_sale',true)),['1','yes','true'],true)
      ||(class_exists('MAD4B_Component_Selling_Basis_V1',false)
        ? \MAD4B_Component_Selling_Basis_V1::unit($source)
        : (string)get_post_meta($source,'tour_price_unit',true))!=='per_person'
      ||!function_exists('shared_product_resolve_price_sources'))
    return false;
  $amount=get_post_meta($source,'start_price',true);
  $currency=class_exists('MAD4B_Component_Selling_Basis_V1',false)
   ?\MAD4B_Component_Selling_Basis_V1::currency($source)
   :(string)get_post_meta($source,'tour_base_currency',true);
  if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0
      ||!preg_match('/^[A-Z]{3}$/D',$currency)
      ||!array_key_exists($currency,get_woocommerce_currencies()))
    return false;
  // This is the exact original shared source-weight resolver, not a second
  // threshold or a forced Direct choice. Rates with priority remain preferred.
  $resolved=shared_product_resolve_price_sources(
    ['direct'=>['amount'=>(float)$amount,'currency'=>$currency]],
    (string)get_post_meta($source,'tour_mode',true));
  if(is_wp_error($resolved)||($resolved['source']??null)!=='direct')
    return false;
  $policy=$resolved['policy']??null;
  if(!is_array($policy))return false;
  $ratePreferred=$policy['rate_weight']>$policy['direct_weight']
       ||($policy['rate_weight']===$policy['direct_weight']&&$policy['tie_break']==='rate');
  if($ratePreferred && self::rates($source))return false;
  return true;
 }
 public static function render($form):void {
  if(!is_object($form)||!is_numeric($form->id??null))return;
  $source=(int)get_the_ID();$fid=(int)$form->id;
  if(!self::active($source,$fid))return;
  // Shared Form #6 owns the Rate + pricing occupancy controls. Do not
  // render a second pair once the approved shared projection is enqueued.
  // The same original field names are mirrored by that presentation adapter;
  // capture() still recomputes/validates all amounts on the server.
  if($fid===6 && function_exists('wp_script_is') &&
     wp_script_is('shared-presentation-fluent-quote-v1','enqueued'))return;
  $rates=self::rates($source);if(!$rates)return;
  $es=apply_filters('wpml_current_language','en')==='es';
  echo '<fieldset class="mad4b-form-payment-routing"><legend>'.
   esc_html($es?'Anticipo de la reserva':'Booking advance').'</legend>';
  echo '<label>'.esc_html($es?'Tarifa del viaje':'Tour rate').
    '<select name="mad4b_route_rate_id"><option value="">'.
    esc_html($es?'Enviar solicitud sin pagar':'Send inquiry without payment').'</option>';
  foreach($rates as $rate){
   $season=wp_get_post_terms($rate->ID,'season',['fields'=>'names']);
   $seasonText=!is_wp_error($season)&&$season?implode(', ',$season):'';
   $start=(int)get_post_meta($rate->ID,'tour_rate_start_date',true);
   $end=(int)get_post_meta($rate->ID,'tour_rate_end_date',true);
   $esMonths=['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
   $labelMonth=static function(int $ts)use($es,$esMonths):string {
    return $es?$esMonths[(int)gmdate('n',$ts)-1].' '.gmdate('Y',$ts)
      :wp_date('M Y',$ts,wp_timezone());
   };
   $period=$start&&$end?$labelMonth($start).' – '.$labelMonth($end):'';
   $currency=(string)get_post_meta($rate->ID,'base_currency',true);
   $double=get_post_meta($rate->ID,'double_price',true);
   $reference=is_numeric($double)&&$double>0
       ?$currency.' '.number_format((float)$double,0).($es?' / adulto (doble)':' / adult (double)'):'';
   $label=trim(implode(' · ',array_filter([$seasonText,$period,$reference])));
   if($label==='')$label=preg_replace('/^Rate\s*#\d+\s*·\s*/iu','',$rate->post_title);
   echo '<option value="'.esc_attr((string)$rate->ID).'">'.esc_html($label).'</option>';
  }
  echo '</select></label><label>'.esc_html($es?'Ocupación':'Pricing occupancy').
    '<select name="mad4b_route_occupancy"><option value="">'.
    esc_html($es?'Seleccionar':'Select occupancy').'</option>';
  $occupancyLabels=$es
   ?['single'=>'Individual','double'=>'Doble','triple'=>'Triple']
   :['single'=>'Single','double'=>'Double','triple'=>'Triple'];
  foreach($occupancyLabels as $k=>$name)
   echo '<option value="'.esc_attr($k).'">'.esc_html($name).'</option>';
  echo '</select></label><small>'.
    esc_html($es?'Se vuelve a calcular en el servidor; el anticipo no confirma la reserva.':
    'The server recalculates the rate. A paid advance does not confirm the booking.').
    '</small></fieldset>';
 }
 /** Resolve eligibility against canonical deposit/balance policy and approved short-notice rules.
  * Does not charge, create an order, or infer a balance from the preview.
  */
 /**
  * One booking-date authority for both Rate and Direct amount certificates.
  * Uses the original Unified Date Intent (company/site option + WP timezone).
  * Do NOT replace with Woo's independent 30-day PAYMENT schedule.
  */
 public static function verifiedTravelDate(string $travelDate,int $tourId=0):array|\WP_Error {
  if(!class_exists('\MAD4B_Unified_Date_Intent_V1') ||
     !function_exists('mad4b_unified_date_enabled_v1') ||
     !mad4b_unified_date_enabled_v1())
   return new \WP_Error('router_unified_date_policy_unavailable');
  $result=\MAD4B_Unified_Date_Intent_V1::normalize(
    ['mad4b_date_mode'=>'fixed','mad4b_date_from'=>$travelDate],true,$tourId);
  if(is_wp_error($result))return new \WP_Error(
    'router_travel_date_outside_booking_window',
    'The selected travel date is outside the allowed booking window.',
    ['policy_reason'=>$result->get_error_code()]);
  if(!is_array($result)||($result['date_from']??'')!==$travelDate ||
     ($result['mode']??'')!=='fixed')
   return new \WP_Error('router_travel_date_policy_invalid');
  return $result;
 }
 /**
  * The only weekday/departure decision for signed Fluent Form #6/Woo Order.
  * Original Program Scheduling owns data, time zones, exceptions and Rate.
  */
 public static function verifiedOperatingSchedule(int $tour,string $travelDate,
     string $source,int $rate=0):array|\WP_Error {
  if(!class_exists('\MAD4B_Signed_Schedule_Bridge_V1',false))
    return new \WP_Error('router_program_schedule_bridge_unavailable');
  return \MAD4B_Signed_Schedule_Bridge_V1::verify($tour,$travelDate,$source,$rate);
 }
 public static function paymentWindow(string $travelDate,array $policy,?array $legal=null):array {
  $arrival=\DateTimeImmutable::createFromFormat('!Y-m-d',$travelDate,wp_timezone());
  $today=new \DateTimeImmutable('today',wp_timezone());
  if(!$arrival||$arrival->format('Y-m-d')!==$travelDate||$arrival<=$today)
   return ['allowed'=>false,'schedule'=>'none','reason'=>'arrival_not_future'];
  $lead=(int)$today->diff($arrival)->format('%r%a');
  $dueMode=(string)($policy['balance_due_mode']??'manual');
  $deadline=$dueMode==='days_before_arrival' ? max(0,(int)($policy['balance_due_days']??0)) : 0;
  if($deadline===0 || $lead>$deadline)
   return ['allowed'=>true,'schedule'=>'standard','reason'=>'standard_policy','lead_days'=>$lead];
  $legal=$legal??(class_exists('\MAD4B_Company_Legal_Rules')?\MAD4B_Company_Legal_Rules::snapshot():[]);
  // Never silently treat "review" or "case_by_case" as permission to collect.
  // A company-approved on-confirmation alternative is explicitly selectable.
  if(($legal['short_notice_mode']??'review')==='full_on_confirmation' &&
     trim((string)($legal['revision']??''))!=='')
   return ['allowed'=>true,'schedule'=>'full_due_now',
     'reason'=>'short_notice_full_payment_due','lead_days'=>$lead,'due_percent'=>100];
  return ['allowed'=>false,'schedule'=>'review_required',
    'reason'=>'short_notice_written_schedule_required','lead_days'=>$lead];
 }
 /** Read-only presentation reason from an already verified saved submission. */
 public static function submissionWindow(array $context):array|\WP_Error {
  $data=$context['data']??null;
  $source=(int)($context['source_id']??0);
  if(!is_array($data)||$source<1)return new \WP_Error('router_submission_window_unavailable');
  $config=self::config();
  $when=self::date($data[$config['date_field']]??null);
  if(!$when)return new \WP_Error('router_submission_date_unavailable');
  return self::paymentWindow($when,RequestAdvancePayments::policy($source,(int)($context['rate_id']??0)));
 }
 private static function date($raw):?string {
  if(!is_string($raw)||strlen($raw)>35)return null;
  foreach(['Y-m-d','d-M-y','d-M-Y','d/m/Y','m/d/Y'] as $fmt){
   $dt=\DateTimeImmutable::createFromFormat('!'.$fmt,trim($raw),wp_timezone());
   if($dt&&$dt->format($fmt)===trim($raw))return $dt->format('Y-m-d');
  }
  return null;
 }
 private static function mount(array $fields,int $fid):int {
  $raw=$fields['commerce_request_mount']??null;
  if(!is_string($raw)||strlen($raw)>1024)return 0;
  $m=json_decode($raw,true);
  if(!is_array($m)||array_diff(array_keys($m),['form_id','source_id','seal'])||
     (int)($m['form_id']??0)!==$fid||!is_int($m['source_id']??null)||
     !is_string($m['seal']??null))return 0;
  $id=$m['source_id'];
  $expected=hash_hmac('sha256',wp_json_encode(['fluent-native-mount:v1',home_url('/'),$fid,$id]),wp_salt('secure_auth'));
  return hash_equals($expected,$m['seal'])?$id:0;
 }
 public static function quote(int $source,int $rate,string $date,string $occupancy,int $adults,string $currency):array|\WP_Error {
  $validDate=self::verifiedTravelDate($date,$source);
  if(is_wp_error($validDate))return $validDate;
  $c=self::config();$parent=(int)get_post_meta($rate,$c['parent_meta'],true);
  if(get_post_type($source)!==($c['source_post_type']??'')||
     get_post_type($rate)!==($c['rate_post_type']??'')||
     get_post_status($source)!=='publish'||get_post_status($rate)!=='publish'||
     !in_array($parent,self::siblings($source),true)||
     is_wp_error(RateProvenance::verify($parent,$rate)))return new \WP_Error('router_rate_not_verified');
  $scheduled=self::verifiedOperatingSchedule($source,$date,'rate',$rate);
  if(is_wp_error($scheduled))return $scheduled;
  if(in_array(strtolower((string)get_post_meta($rate,'stop_sale',true)),['1','yes','true'],true)||
     (string)get_post_meta($rate,'avilabile_status',true)==='0'||
     !function_exists('shared_product_booking_v2_rate_covers_date')||
     !shared_product_booking_v2_rate_covers_date($rate,$date))return new \WP_Error('router_rate_not_eligible');
  if(!function_exists('shared_product_quote_snapshot'))return new \WP_Error('router_quote_engine_missing');
  $p=shared_product_quote_snapshot($rate,'general',$occupancy,$currency);
  if(is_wp_error($p))return $p;
  if(($p['display_currency']??'')!==$currency||!is_numeric($p['display_amount']??null))
   return new \WP_Error('router_currency_mismatch');
  $total=round((float)$p['display_amount']*$adults,2);
  if(!is_finite($total)||$total<=0||$total>999999999)return new \WP_Error('router_total_invalid');
  return ['total'=>number_format($total,2,'.',''),'currency'=>$currency];
 }
 /**
  * Direct-price payment reference delegates to the SAME original quote owner
  * as Fluent Form #6's public preview. No second price or policy evaluator.
  * Units requiring missing certified quantities remain review-only.
  */
 public static function quoteDirect(int $source,string $date,string $occupancy,int $adults,string $currency):array|\WP_Error {
  $validDate=self::verifiedTravelDate($date,$source);
  if(is_wp_error($validDate))return $validDate;
  if($source<1||$adults<1||$adults>60||!in_array($occupancy,['single','double','triple'],true)
      ||get_post_type($source)!==(self::config()['source_post_type']??'')
      ||get_post_status($source)!=='publish')
   return new \WP_Error('router_direct_input_invalid');
  $scheduled=self::verifiedOperatingSchedule($source,$date,'direct',0);
  if(is_wp_error($scheduled))return $scheduled;
  $payload=['tour_id'=>$source,'source'=>'direct','rate_id'=>null,
    'travel_date'=>$date,'adults'=>$adults,'children'=>0,'infants'=>0,
    'occupancy'=>$occupancy,'extras'=>[]];
  if(class_exists('\MAD4B_Form6_Readonly_Price_Preview_V1',false)
     && !empty(\MAD4B_Form6_Readonly_Price_Preview_V1::status()['enabled']))
    $q=\MAD4B_Form6_Readonly_Price_Preview_V1::quote($payload);
  elseif(function_exists('shared_presentation_form6_price_quote'))
    $q=shared_presentation_form6_price_quote($payload);
  else return new \WP_Error('router_direct_quote_owner_unavailable');
  if(is_wp_error($q))return $q;
  if(!is_array($q)||($q['status']??null)!=='price_preview_only'
     ||($q['source']??null)!=='direct'||(int)($q['rate_id']??0)!==0
     ||($q['tour_id']??null)!==$source||($q['currency']??null)!==$currency
     ||!is_numeric($q['total']??null)||!is_finite((float)$q['total'])
     ||(float)$q['total']<=0||(float)$q['total']>999999999
     ||!empty($q['extras_review_required'])||!empty($q['stay_review_required'])
     ||($q['tax_state']??null)==='requires_review')
    return new \WP_Error('router_direct_reference_not_verified');
  return ['total'=>number_format((float)$q['total'],2,'.',''),
    'currency'=>$currency,'pricing_source'=>'direct',
    'price_policy'=>shared_product_price_source_policy((string)get_post_meta($source,'tour_mode',true))];
 }
 public static function capture($insert) {
  if(!is_array($insert))return $insert;
  $fid=(int)($insert['form_id']??0);$c=self::config();
  if(($c['enabled']??'no')!=='yes'||$fid!==(int)($c['form_id']??0))return $insert;
  $data=json_decode((string)($insert['response']??''),true);
  if(!is_array($data)||isset($data['_shared_tour_booking_intent_v2']))return $insert;
  $fields=[];
  if(is_string($_POST['data']??null))parse_str(wp_unslash($_POST['data']),$fields);
  foreach(['commerce_request_mount','mad4b_route_rate_id','mad4b_route_occupancy'] as $key)
   if(!isset($fields[$key])&&is_scalar($_POST[$key]??null))$fields[$key]=wp_unslash($_POST[$key]);
  $source=self::mount($fields,$fid);
  if(!$source||!self::active($source,$fid))return $insert;
  $ctx=$data['_request_advance_context']??null;
  if(!is_array($ctx)||($ctx['source_id']??null)!==$source||
     ($ctx['form_id']??null)!==$fid||!is_string($ctx['seal']??null))return $insert;
  $seal=$ctx['seal'];unset($ctx['seal']);
  if(!hash_equals(hash_hmac('sha256',wp_json_encode($ctx),wp_salt('secure_auth')),$seal))return $insert;
  // Browser selection is UNTRUSTED. It only indicates the source requested;
  // source identity is the original server-signed Fluent mount above.
  $json=$fields['shared_tour_selection_v2']??null;
  if(!is_string($json)||strlen($json)>4096)return $insert;
  $selection=json_decode($json,true);
  if(!is_array($selection)||array_diff(array_keys($selection),
     ['tour_id','source','rate_id','travel_date','adults','children','infants',
      'occupancy','extras','stay_stages','child_ages','infant_ages'])||
      (int)($selection['tour_id']??0)!==$source ||
      !in_array($selection['source']??null,['rate','direct'],true) ||
      !is_array($selection['extras']??null) || !empty($selection['extras'])||
      !empty($selection['stay_stages']) ||
      !empty($selection['child_ages']) || !empty($selection['infant_ages']))
      return $insert;
  $pricingSource=(string)$selection['source'];
  $rid=$fields['mad4b_route_rate_id']??null;
  $occ=$fields['mad4b_route_occupancy']??null;
  $selectedOcc=$selection['occupancy']??null;
  if(!is_string($selectedOcc)||!in_array($selectedOcc,['single','double','triple'],true))
      return $insert;
  if($pricingSource==='rate') {
    if(!is_scalar($rid)||!preg_match('/^[1-9]\d{0,9}$/D',(string)$rid)||
       !is_string($occ)||$occ!==$selectedOcc)
      return $insert;
    $rate=(int)$rid;
    if((int)($selection['rate_id']??0)!==$rate ||
       !in_array($rate,array_map(static fn($r)=>(int)$r->ID,self::rates($source)),true))
      return $insert;
  }else{
    // Missing Rate ID is legitimate ONLY for a source-policy-verified direct
    // amount. A stray stale Rate field cannot silently change the source.
    if(!in_array((string)($rid??''),['','0'],true)||
       !in_array((string)($occ??''),['','single'],true)||
       !in_array((string)($selection['rate_id']??''),['','0'],true))
      return $insert;
    $rate=0;$occ=$selectedOcc;
  }
  $policy=RequestAdvancePayments::policy($source,$rate);
  $when=self::date($data[$c['date_field']]??null);
  if(!$when||($selection['travel_date']??null)!==$when)return $insert;
  // Defend the signed intent even when a client bypasses the date widgets.
  if(is_wp_error(self::verifiedTravelDate($when,$source)))return $insert;
  $scheduled=self::verifiedOperatingSchedule($source,$when,$pricingSource,$rate);
  if(is_wp_error($scheduled))return $insert;
  $window=self::paymentWindow($when,$policy);
  if(!$window['allowed'])return $insert;
  $counts=[];
  foreach(['adults'=>$c['adult_field'],'children'=>$c['child_field'],'infants'=>$c['infant_field']] as $key=>$name){
   $v=$data[$name]??null;
   if(!is_scalar($v)||!preg_match('/^(?:0|[1-9]\d{0,2})$/D',(string)$v))return $insert;
   $counts[$key]=(int)$v;
  }
  $adult=$counts['adults'];
  foreach($counts as $key=>$value)
    if(!isset($selection[$key])||(string)$selection[$key]!== (string)$value)return $insert;
  if(!$adult||$adult>60||$counts['children']||$counts['infants']||
    $adult%(['single'=>1,'double'=>2,'triple'=>3][$occ])!==0)return $insert;
  $ref=$pricingSource==='direct'
     ?self::quoteDirect($source,$when,$occ,$adult,(string)$policy['currency'])
     :self::quote($source,$rate,$when,$occ,$adult,(string)$policy['currency']);
  if(is_wp_error($ref))return $insert;
  $intent=['version'=>2,'source'=>$pricingSource,'state'=>'server_price_preview',
   'source_adapter'=>'mad4b_single_fluent_router_v1','tour_id'=>$source,'rate_id'=>$rate,
   'travel_date'=>$when,'adults'=>$adult,'children'=>0,'infants'=>0,'occupancy'=>$occ,
   'currency'=>$ref['currency'],'total'=>$ref['total'],'availability'=>'not_checked',
   'checkout_ready'=>false,'order_created'=>false,
   'payment_schedule'=>$window['schedule'],'quoted_at_utc'=>gmdate('c')];
  $data['_shared_tour_booking_intent_v2']=RequestAdvancePayments::sealIntent($intent,$fid);
  $insert['response']=wp_json_encode($data,JSON_UNESCAPED_UNICODE);
  return $insert;
 }
 private static function intent(int $fid,int $sid):?array {
  global $wpdb;
  $json=$wpdb->get_var($wpdb->prepare("SELECT response FROM {$wpdb->prefix}fluentform_submissions WHERE id=%d AND form_id=%d",$sid,$fid));
  $data=is_string($json)?json_decode($json,true):null;
  $i=$data['_shared_tour_booking_intent_v2']??null;
  if(!is_array($i)||!is_string($i['_request_advance_seal']??null))return null;
  $seal=$i['_request_advance_seal'];unset($i['_request_advance_seal']);
  if(!hash_equals(RequestAdvancePayments::sealIntent($i,$fid)['_request_advance_seal'],$seal))return null;
  return ($i['source_adapter']??'')==='mad4b_single_fluent_router_v1'?$i:null;
 }
 public static function annotate($orderId,$fid,$sid):void {
  $i=self::intent((int)$fid,(int)$sid);if(!$i)return;
  $o=wc_get_order((int)$orderId);
  if(!$o||!RequestAdvancePayments::isAdvance($o)||
     (int)$o->get_meta('_request_source_id')!==(int)$i['tour_id']||
     (int)$o->get_meta('_request_rate_id')!==(int)$i['rate_id'])return;
  $o->update_meta_data('_mad4b_single_fluent_reference_v1',[
   'form_id'=>(int)$fid,'source'=>(int)$i['tour_id'],'rate'=>(int)$i['rate_id'],
   'price_source'=>$i['source']??'rate',
   'date'=>$i['travel_date'],'occupancy'=>$i['occupancy'],'adults'=>$i['adults'],
   'currency'=>$i['currency'],'total'=>$i['total'],
   'payment_schedule'=>$i['payment_schedule']??'standard',
   'adapter'=>($i['source']??'rate')==='direct'?'server_direct_v1':'server_rate_v1']);
  $o->save();
 }
 public static function validatePayment($order):void {
  if(!$order instanceof \WC_Order||!RequestAdvancePayments::isAdvance($order))return;
  $m=$order->get_meta('_mad4b_single_fluent_reference_v1');
  if(!is_array($m)||!in_array($m['adapter']??null,['server_rate_v1','server_direct_v1'],true))return;
  $src=(int)$m['source'];$fid=(int)$m['form_id'];$date=self::date($m['date']??null);
  if(!self::active($src,$fid)||!$date ||
     is_wp_error(self::verifiedTravelDate($date,$src)))
   throw new \Exception('This booking advance is no longer available.');
  $p=RequestAdvancePayments::policy($src,(int)$m['rate']);
  $window=self::paymentWindow($date,$p);
  // Changing the company short-notice rule invalidates a stale checkout link.
  if(!$window['allowed']||($m['payment_schedule']??'standard')!==$window['schedule'])
   throw new \Exception('Please request a revised payment schedule for this arrival date.');
  $isDirect=($m['adapter']??'')==='server_direct_v1';
  if($isDirect && ((int)($m['rate']??0)!==0||($m['price_source']??'')!=='direct'))
    throw new \Exception('The price source changed. Request a new payment reference.');
  $r=$isDirect
    ?self::quoteDirect($src,$date,(string)$m['occupancy'],(int)$m['adults'],(string)$m['currency'])
    :self::quote($src,(int)$m['rate'],$date,(string)$m['occupancy'],(int)$m['adults'],(string)$m['currency']);
  $money=$order->get_meta('_request_advance_money');
  if(is_wp_error($r)||$r['total']!==(string)$m['total']||
    !is_array($money)||abs((float)($money['reference_amount']??0)-(float)$r['total'])>0.005)
    throw new \Exception('The verified estimated price changed. Request a new payment reference.');
 }
 public static function allowCheckout($existing):bool {
  if($existing)return true;
  if(!function_exists('is_wc_endpoint_url')||!is_wc_endpoint_url('order-pay'))return false;
  $o=wc_get_order(absint(get_query_var('order-pay')));
  if(!$o||!$o->needs_payment()||!RequestAdvancePayments::isAdvance($o))return false;
  $m=$o->get_meta('_mad4b_single_fluent_reference_v1');
  if(!is_array($m)||!in_array($m['adapter']??null,
     ['server_rate_v1','server_direct_v1'],true)||
     !self::active((int)$m['source'],(int)$m['form_id']))return false;
  if(($m['adapter']??null)==='server_direct_v1' &&
     ((int)($m['rate']??0)!==0||($m['price_source']??null)!=='direct'))
    return false;
  $key=is_string($_GET['key']??null)?sanitize_text_field(wp_unslash($_GET['key'])):'';
  if(!$key||!hash_equals((string)$o->get_order_key(),$key))return false;
  if(!headers_sent())nocache_headers();
  return true;
 }
}

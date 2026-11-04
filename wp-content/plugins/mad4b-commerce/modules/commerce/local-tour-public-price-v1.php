<?php
/**
 * Local Lab only — unified indicative tour price for single and listing views.
 * Never updates stored prices, currency settings, Rates or orders.
 */
defined('ABSPATH') || exit;
// The public indicative price badge is a read-only presentation provider.
// Do not load financial booking/supplier mutation adapters for tenant sites.
$mad4b_public_price_commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
$mad4b_public_price_presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
if (!$mad4b_public_price_commerce && !$mad4b_public_price_presentation) return;


function shared_lab_tour_price_resolve(int $tour_id, ?string $day = null): array {
 // The source resolver is read-only for a fixed tour/date/currency/language.
 // Memoization is request-local; no persisted prices, market rates or Orders.
 // Never memoize POST or REST write flows.
 if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET')
  return shared_lab_tour_price_resolve_uncached($tour_id,$day);
 static $memo=[];
 $effective_day=$day?:current_time('Y-m-d');
 $currency=function_exists('pricing_display_currency')?pricing_display_currency():get_woocommerce_currency();
 $language=(string)apply_filters('wpml_current_language',null);
 $key=hash('sha256',serialize([$tour_id,$effective_day,$currency,$language]));
 if(array_key_exists($key,$memo))return $memo[$key];
 return $memo[$key]=shared_lab_tour_price_resolve_uncached($tour_id,$effective_day);
}
function shared_lab_tour_price_resolve_uncached(int $tour_id, ?string $day = null): array {
 if(get_post_type($tour_id)!=='tours-and-activities'||get_post_status($tour_id)!=='publish')
  return ['status'=>'unavailable','reason'=>'invalid_tour'];
 $day=$day?:current_time('Y-m-d');
 $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
 if(!$dt||$dt->format('Y-m-d')!==$day)return ['status'=>'unavailable','reason'=>'date_invalid'];
 if(function_exists('pricing_scope_resolve')){
  $scope=pricing_scope_resolve($tour_id,0,[]);
  if(!empty($scope['values']['stop_sale_global']))
   return ['status'=>'unavailable','reason'=>'stop_sale_global'];
 }
 $authority=ETG_Tour_Rate_Display::resolve($tour_id,$day);
 if(is_wp_error($authority)&&in_array($authority->get_error_code(),['etg_price_unavailable','etg_no_valid_tour_rate'],true)){
  $amount=get_post_meta($tour_id,'start_price',true);
  if(is_numeric($amount)&&(float)$amount>0){
   $own=strtoupper((string)get_post_meta($tour_id,'tour_base_currency',true));
   if(array_key_exists($own,get_woocommerce_currencies()))
    $authority=['source'=>'simple','rates'=>[$own=>['price'=>(float)$amount,'currency'=>$own]]];
   if(is_wp_error($authority))foreach(ETG_Tour_Rate_Display::language_ids($tour_id) as $translation){
    $lang=apply_filters('wpml_post_language_details',null,$translation);
    if(!is_array($lang)||($lang['language_code']??'')!=='en')continue;
    $original=get_post_meta($translation,'start_price',true);
    $currency=strtoupper((string)get_post_meta($translation,'tour_base_currency',true));
    if(is_numeric($original)&&(float)$original===(float)$amount&&
      array_key_exists($currency,get_woocommerce_currencies())){
     $authority=['source'=>'simple','rates'=>[$currency=>['price'=>(float)$amount,'currency'=>$currency]]];break;
    }
   }
  }
 }
 if(is_wp_error($authority))return ['status'=>'unavailable','reason'=>$authority->get_error_code()];
 $target=function_exists('pricing_display_currency')?pricing_display_currency():get_woocommerce_currency();
 $source=$authority['source']??'';
 if($source==='simple'){
  $row=reset($authority['rates']);$from=(string)($row['currency']??'');
  $amount=pricing_currency_convert($row['price']??null,$from,$target);
  if($amount===null)return ['status'=>'unavailable','reason'=>'fx_unavailable'];
  return ['status'=>'available','source'=>'simple','period'=>'fallback','currency'=>$target,
    'best'=>['rate_id'=>0,'regular'=>$amount,'final'=>$amount,'currency'=>$target,'source_currency'=>$from],
    'rate_count'=>0];
 }
 if($source!=='tour-rates')return ['status'=>'unavailable','reason'=>'unknown_source'];
 $ids=ETG_Tour_Rate_Display::language_ids($tour_id);
 global $wpdb;
 // The catalog range adapter can supply a request-local relationship map
 // from the same postmeta authority. Single tours keep their original query.
 $map=$GLOBALS['mad4b_catalog_rate_lookup_v1']??null;
 if(is_array($map)){
  $rate_ids=[];
  foreach($ids as $source_id)
   foreach((array)($map[(string)$source_id]??[]) as $rid)$rate_ids[(int)$rid]=(int)$rid;
  $rate_ids=array_values($rate_ids);
 }else{
  $placeholders=implode(',',array_fill(0,count($ids),'%s'));
  $sql="SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key='related_tour_id' AND meta_value IN ($placeholders)";
  $rate_ids=array_map('intval',$wpdb->get_col($wpdb->prepare($sql,...array_map('strval',$ids))));
 }
 $today=$dt->getTimestamp();$eligible=[];
 foreach($rate_ids as $rid){
  if(get_post_type($rid)!=='tour-rates'||get_post_status($rid)!=='publish')continue;
  if(in_array(strtolower((string)get_post_meta($rid,'stop_sale',true)),['1','true','yes'],true)||
    (string)get_post_meta($rid,'avilabile_status',true)==='0')continue;
  $start=(int)get_post_meta($rid,'tour_rate_start_date',true);
  $end=(int)get_post_meta($rid,'tour_rate_end_date',true);
  if(!$start||!$end||$start>$end||$end<$today)continue;
  $terms=wp_get_object_terms($rid,'season',['fields'=>'ids']);
  if(is_wp_error($terms)||count($terms)!==1)continue;
  $season=ETG_Season_Calendar::get_config((int)$terms[0]);
  if(is_wp_error($season)||is_wp_error(ETG_Season_Calendar::validate($season))||
    !in_array((string)($season['season_enabled']??''),['1','true','on'],true))continue;
  if(ETG_Season_Calendar::contains((int)$terms[0],gmdate('Y-m-d',$start))!==true||
     ETG_Season_Calendar::contains((int)$terms[0],gmdate('Y-m-d',$end))!==true)continue;
  $currency=(string)get_post_meta($rid,'base_currency',true);
  if(!array_key_exists($currency,get_woocommerce_currencies()))continue;
  foreach(['single','double','triple'] as $occupancy){
   $raw=get_post_meta($rid,$occupancy.'_price',true);
   if(!is_numeric($raw)||(float)$raw<=0)continue;
   $regular=(float)$raw;$final=$regular;
   $sale=get_post_meta($rid,$occupancy.'_sale_price',true);
   if((string)get_post_meta($rid,'is_on_sale',true)==='1'&&is_numeric($sale)&&
     (float)$sale>0&&(float)$sale<$regular)$final=(float)$sale;
   $eligible[]=['rate_id'=>$rid,'start'=>$start,'end'=>$end,'currency'=>$currency,
     'regular'=>$regular,'final'=>$final,'occupancy'=>$occupancy];
  }
 }
 if(!$eligible)return ['status'=>'unavailable','reason'=>'rate_unavailable'];
 $current=array_values(array_filter($eligible,static fn($r)=>$r['start']<=$today));
 $period='current';
 if(!$current){
  $period='upcoming';$nearest=min(array_column($eligible,'start'));
  $current=array_values(array_filter($eligible,static fn($r)=>$r['start']===$nearest));
 }
 // A WPML translation is another representation of one commercial Rate.
 $lang=apply_filters('wpml_current_language',null);$groups=[];
 foreach($current as $row){
  $gid=(string)(apply_filters('wpml_element_trid',null,$row['rate_id'],'post_tour-rates')?:$row['rate_id']);
  $details=apply_filters('wpml_post_language_details',null,$row['rate_id']);
  $code=is_array($details)?($details['language_code']??''):'';
  $key=$gid.':'.$row['occupancy'];
  if(!isset($groups[$key])||($code===$lang&&$groups[$key]['lang']!==$lang))
   $groups[$key]=['row'=>$row,'lang'=>$code];
 }
 $compare=[];$missing_fx=false;
 foreach($groups as $group){
  $row=$group['row'];
  $regular=pricing_currency_convert($row['regular'],$row['currency'],$target);
  $final=pricing_currency_convert($row['final'],$row['currency'],$target);
  if($regular===null||$final===null){$missing_fx=true;continue;}
  $row['regular']=$regular;$row['final']=$final;
  $row['source_currency']=$row['currency'];$row['currency']=$target;
  $compare[]=$row;
 }
 if($missing_fx)return ['status'=>'unavailable','reason'=>'fx_unavailable'];
 if(!$compare)return ['status'=>'unavailable','reason'=>'rate_unavailable'];
 usort($compare,static fn($a,$b)=>($a['final']<=>$b['final'])?:($a['start']<=>$b['start'])?:($a['rate_id']<=>$b['rate_id']));
 return ['status'=>'available','source'=>'tour-rates','period'=>$period,
   'currency'=>$target,'best'=>$compare[0],'rate_count'=>count($compare)];
}
add_action('init',static function(){
 add_shortcode('etg_dynamic_tour_price',static function($atts){
  $data=shortcode_atts(['tour_id'=>get_the_ID(),'date'=>''],$atts,'etg_dynamic_tour_price');
  $tour=absint($data['tour_id']);
  $r=shared_lab_tour_price_resolve($tour,$data['date']?:null);
  if(($r['status']??'')!=='available')return '';
  $b=$r['best'];
  $icon='<svg width="22" height="22" viewBox="0 0 25 24" fill="none" aria-hidden="true" focusable="false"><path d="M12.5 17C12.5 19.7614 14.7386 22 17.5 22C20.2614 22 22.5 19.7614 22.5 17C22.5 14.2386 20.2614 12 17.5 12C14.7386 12 12.5 14.2386 12.5 17ZM12.5 17C12.5 15.8742 12.8721 14.8353 13.5 13.9995V5M12.5 17C12.5 17.8254 12.7 18.604 13.0541 19.2901C12.2117 20.0018 10.2658 20.5 8 20.5C4.96243 20.5 2.5 19.6046 2.5 18.5V5M13.5 5C13.5 6.10457 11.0376 7 8 7C4.96243 7 2.5 6.10457 2.5 5M13.5 5C13.5 3.89543 11.0376 3 8 3C4.96243 3 2.5 3.89543 2.5 5M2.5 14C2.5 15.1046 4.96243 16 8 16C10.189 16 12.0793 15.535 12.9646 14.8618M13.5 9.5C13.5 10.6046 11.0376 11.5 8 11.5C4.96243 11.5 2.5 10.6046 2.5 9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  $label=$r['period']==='upcoming'?__('Upcoming from','woocommerce'):__('From','woocommerce');
  return '<span class="etg-tour-price etg-price-native" data-price-source="'.esc_attr($r['source']).'" data-price-period="'.esc_attr($r['period']).'" data-rate-id="'.esc_attr($b['rate_id']).'">'
   .'<span class="etg-price-icon">'.$icon.'</span><span class="etg-price-prefix">'.esc_html($label).'</span>'
   .'<strong class="etg-price-number">'.esc_html(number_format_i18n($b['final'],2)).'</strong>'
   .'<span class="etg-price-code">'.esc_html($r['currency']).'</span></span>';
 });
},70);
add_action('wp_head',static function(){
 if(is_admin())return;
 ?>
<style id="lab-tour-price-presentation-v1">
/* Shared indicative retail price, dark booking heading and archive listings. */
.etg-price-native{display:inline-flex;align-items:center;gap:5px;max-width:100%;
 flex-wrap:wrap;font-size:12px;line-height:1.25;color:#f4f8fc;
 font-weight:500;white-space:normal;background:transparent!important}
.etg-price-native .etg-price-icon{display:inline-flex;color:#d5ae75;flex:0 0 auto}
.etg-price-native .etg-price-icon svg{width:22px;height:22px;display:block;
 stroke:currentColor!important;fill:none!important}
.etg-price-native .etg-price-prefix{font-size:10px;opacity:.78}
.etg-price-native .etg-price-number{font-size:17px;line-height:1.1;
 font-weight:700;color:#fff;font-variant-numeric:tabular-nums}
.etg-price-native .etg-price-code{font-size:11px;color:#e2c18d;font-weight:600}
.etg-price-migrated .etg-price-native,
body.single-tours-and-activities .elementor-location-single .etg-price-native{color:#edf5fc}
.jet-listing-grid__item .tour-component-price{
 display:inline-flex;align-items:baseline;flex-wrap:wrap;gap:3px;
 font-variant-numeric:tabular-nums;line-height:1.4}
.jet-listing-grid__item .tour-component-price .woocommerce-Price-amount,
.jet-listing-grid__item .tour-component-price .price-current{font-weight:650}
.jet-listing-grid__item .tour-component-price .price-unit{font-size:.75em;opacity:.85}
@media(max-width:600px){
 .etg-price-native .etg-price-number{font-size:15px}
 .etg-price-native .etg-price-icon svg{width:19px;height:19px}
 .etg-price-native .etg-price-prefix{font-size:9px}
}
</style>
 <?php
},45);
add_action('wp_head',static function(){
 if(is_admin())return;
 echo '<style id="lab-tour-price-card-contrast">.jet-listing-grid__item .etg-price-native{color:#294d6f}.jet-listing-grid__item .etg-price-native .etg-price-number{color:#225e91}.jet-listing-grid__item .etg-price-native .etg-price-code{color:#587188}.jet-listing-grid__item .etg-price-native .etg-price-icon{color:#315e86}</style>';
},46);

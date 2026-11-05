<?php
/**
 * Plugin Name: MAD4B Property Market Pricing
 * Description: Rate-specific eligible market prices for rooms and cabins; native accommodation preview only.
 * Version: 0.1.1
 */
defined('ABSPATH') || exit;
final class MAD4B_Property_Market_Pricing {
 const META='_mad4b_property_market_rates_v1';
 const CONTRACT='mad4b.property-market-pricing.v1';
 public static function rows($rate){
  $v=get_post_meta(absint($rate),self::META,true);
  return is_array($v)?$v:[];
 }
 private static function date($date){
  if(!is_string($date)||!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$date))return false;
  $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
  return $parsed && $parsed->format('Y-m-d')===$date;
 }
 public static function validate($entries,$rate,$persist=false){
  if(!is_array($entries)||count($entries)>60)return new WP_Error('property_market_rows_invalid');
  if(get_post_type($rate)!=='property-rates')return new WP_Error('property_market_rate_invalid');
  $out=[];$seen=[];
  foreach($entries as $row){
   if(!is_array($row))return new WP_Error('property_market_row_invalid');
   $id=sanitize_key((string)($row['profile_id']??''));
   if($id==='')continue;
   $mode=sanitize_key((string)($row['mode']??'inherit'));
   $basis=sanitize_key((string)($row['eligibility_basis']??'approved_market'));
   if(!in_array($mode,['inherit','percent','fixed_unit'],true)||
      !in_array($basis,['approved_market','nationality','residence','contract'],true))
     return new WP_Error('property_market_mode_invalid');
   $enabled=in_array($row['enabled']??false,[true,1,'1','on','yes'],true);
   $nationality=strtoupper(trim((string)($row['nationality']??'')));
   $residence=strtoupper(trim((string)($row['residence_country']??'')));
   if(($basis==='nationality')!==($nationality!=='')||
      ($basis==='residence')!==($residence!==''))return new WP_Error('property_market_eligibility_fields_invalid');
   if(($nationality!==''&&!preg_match('/^[A-Z]{2}$/D',$nationality))||
      ($residence!==''&&!preg_match('/^[A-Z]{2}$/D',$residence)))
     return new WP_Error('property_market_country_invalid');
   $from=trim((string)($row['valid_from']??''));$to=trim((string)($row['valid_to']??''));
   if(($from!==''&&!self::date($from))||($to!==''&&!self::date($to))||
      ($from!==''&&$to!==''&&$from>$to))return new WP_Error('property_market_dates_invalid');
   $amount=null;$currency='';
   if($mode!=='inherit'){
     $str=(string)($row['amount']??'');
     if(!preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D',$str)||
       (float)$str<=0)return new WP_Error('property_market_amount_invalid');
     $amount=(float)$str;
     if($mode==='percent'&&$amount>300)return new WP_Error('property_market_percent_invalid');
     if($mode==='fixed_unit'){
       $currency=strtoupper(trim((string)($row['currency']??'')));
       if(!preg_match('/^[A-Z]{3}$/D',$currency))return new WP_Error('property_market_currency_invalid');
     }elseif(trim((string)($row['currency']??''))!=='')return new WP_Error('property_market_percent_currency_unexpected');
   }
   $approved=$enabled&&class_exists('MAD4B_SCP_Market_Profiles')?
     MAD4B_SCP_Market_Profiles::approved($id):null;
   if($enabled&&(!is_array($approved)||!isset($approved['revision'],$approved['site_uuid'])))
     return new WP_Error('property_market_profile_not_approved');
   $revision=$enabled?$approved['revision']:($row['profile_revision']??null);
   if(!$persist&&$enabled&&isset($row['profile_revision'])&&$row['profile_revision']!==$revision)
     return new WP_Error('property_market_profile_revision_changed');
   $key=implode('|',[$id,$basis,$nationality,$residence]);
   if(isset($seen[$key]))return new WP_Error('property_market_duplicate_rule');
   $seen[$key]=true;
   $out[]=['enabled'=>$enabled,'profile_id'=>$id,'profile_revision'=>$revision,
     'mode'=>$mode,'eligibility_basis'=>$basis,'nationality'=>$nationality,
     'residence_country'=>$residence,'amount'=>$amount,'currency'=>$currency,
     'valid_from'=>$from,'valid_to'=>$to,
     'evidence_note'=>sanitize_text_field((string)($row['evidence_note']??''))];
  }
  return $out;
 }
 private static function eligible($row,$context,$count){
  switch($row['eligibility_basis']){
   case 'approved_market':return true;
   case 'nationality':
    $src=$context['nationality_source']??'none';
    return $count===1?
      in_array($src,['self_declared_passenger_list','self_declared_passenger_list_all_same'],true)&&
      ($context['nationality']??'')===$row['nationality']:
      $count>1&&$src==='self_declared_passenger_list_all_same'&&
      ($context['nationality']??'')===$row['nationality'];
   case 'residence':
    return ($context['residence_source']??'')==='verified_stay_eligibility_evidence'&&
      ($context['residence_country']??'')===$row['residence_country'];
   case 'contract':
    return ($context['contract_entitlement']??'')==='verified_property_rate_contract';
  }
  return false;
 }
 public static function resolve($previous,$params){
  if($previous!==null)return $previous;
  if(!is_array($params))return null;
  $rate=absint($params['rate_id']??0);
  // Public integration requires the native rate to be published; a draft
  // can be evaluated only with isolated read-only test inputs below.
  $qa=defined('WP_CLI')&&WP_CLI&&home_url()==='https://tours.dev.mad4b.com'&&
    apply_filters('abk/qa/allow_draft_fixture',false,$rate,'rate')===true;
  if(get_post_type($rate)!=='property-rates'||
     (get_post_status($rate)!=='publish'&&!$qa))return null;
  return self::evaluate_source_verified(self::rows($rate),$params);
 }
 /** Pure, nonfinancial rate evaluation, used after native source verification.
  * QA can call this without publishing any draft Property Rate.
  */
 public static function evaluate_source_verified($rows,$params){
  if(!is_array($rows)||!is_array($params))return new WP_Error('property_market_qa_inputs_invalid');
  $rate=absint($params['rate_id']??0);
  if(get_post_type($rate)!=='property-rates')return new WP_Error('property_market_rate_type_invalid');
  if(!$rows)return null;
  $ref=$params['price_reference']??[];
  $context=$params['market_context']??[];
  if(!is_array($ref)||!is_array($context))return null;
  $base=$params['base_amount']??null;$currency=$params['currency']??'';
  $unit=$ref['unit_rate_amount']??null;$basis=$ref['price_unit']??'';
  $nights=(int)($ref['nights']??0);$rooms=(int)($ref['units']??0);
  $checkin=(string)($ref['checkin']??'');$checkout=(string)($ref['checkout']??'');
  if(!in_array($basis,['per_room_night','per_cabin_night'],true)||
     !is_numeric($unit)||!is_numeric($base)||(float)$unit<=0||
     $nights<1||$rooms<1||$nights>365||$rooms>99||
     !self::date($checkin)||!self::date($checkout)||$checkin>=$checkout||
     !preg_match('/^[A-Z]{3}$/D',(string)$currency)||
     abs((float)$unit*$nights*$rooms-(float)$base)>0.02||
     abs((float)get_post_meta($rate,'base_amount',true)-(float)$unit)>0.001||
     (string)get_post_meta($rate,'currency',true)!==$currency||
     (string)get_post_meta($rate,'prop_price_unit',true)!==$basis)
    return new WP_Error('property_market_reference_invalid');
  $matches=[];
  foreach($rows as $raw){
   if(empty($raw['enabled'])||($raw['profile_id']??'')!==($context['profile_id']??''))continue;
   $verified=self::validate([$raw],$rate);
   if(is_wp_error($verified))continue;
   $r=$verified[0]??[];
   if(!$r||$r['profile_revision']!==($context['profile_revision']??null))continue;
   $last=DateTimeImmutable::createFromFormat('!Y-m-d',$checkout,new DateTimeZone('UTC'));
   $last=$last->modify('-1 day')->format('Y-m-d');
   if(($r['valid_from']!==''&&$checkin<$r['valid_from'])||
      ($r['valid_to']!==''&&$last>$r['valid_to']))continue;
   if(self::eligible($r,$context,(int)($params['traveller_count']??0)))$matches[]=$r;
  }
  if(!$matches)return null;
  $priorities=['contract'=>4,'residence'=>3,'nationality'=>2,'approved_market'=>1];
  usort($matches,static fn($a,$b)=>$priorities[$b['eligibility_basis']]<=>$priorities[$a['eligibility_basis']]);
  if(count($matches)>1 && $priorities[$matches[0]['eligibility_basis']]===
      $priorities[$matches[1]['eligibility_basis']])return new WP_Error('property_market_ambiguous');
  $r=$matches[0];$final=(float)$base;
  $fxReceipt=null;
  if($r['mode']==='percent')$final=round($final*$r['amount']/100,2);
  if($r['mode']==='fixed_unit'){
   if(!function_exists('pricing_currency_convert')||
      !function_exists('pricing_public_fx_rate'))
     return new WP_Error('property_market_fx_missing');
   $rateFx=pricing_public_fx_rate($r['currency'],$currency);
   if(!is_numeric($rateFx)||!is_finite((float)$rateFx)||(float)$rateFx<=0)
     return new WP_Error('property_market_fx_unavailable');
   $original=(float)$r['amount']*$rooms*$nights;
   $converted=pricing_currency_convert($original,$r['currency'],$currency);
   if(!is_numeric($converted)||!is_finite((float)$converted)||(float)$converted<=0)
     return new WP_Error('property_market_fx_unavailable');
   $final=round((float)$converted,2);
   $fxReceipt=['provider'=>'shared-product-core/pricing_public_fx_rate',
     'source_currency'=>$r['currency'],'target_currency'=>$currency,
     'source_total'=>round($original,2),'rate'=>(float)$rateFx,
     'converted_total'=>$final];
   if(function_exists('pricing_public_fx_snapshot')){
     $snapshot=pricing_public_fx_snapshot();
     if(is_array($snapshot)){
       $fxReceipt['snapshot_fetched_at']=$snapshot['fetched_at']??null;
       $fxReceipt['snapshot_sha256']=hash('sha256',wp_json_encode($snapshot));
     }
   }
  }
  if($final<=0)return new WP_Error('property_market_amount_unavailable');
  $receipt=['contract'=>self::CONTRACT,'rate_id'=>$rate,'profile_id'=>$r['profile_id'],
    'revision'=>$r['profile_revision'],'eligibility_basis'=>$r['eligibility_basis'],
    'mode'=>$r['mode'],'unit_price_source'=>(float)$unit,'units'=>$rooms,'nights'=>$nights,
    'price_unit'=>$basis,'market_price'=>$r['amount'],'market_currency'=>$r['currency'],
    'source_base'=>(float)$base,'source_currency'=>$currency,'adjusted_base'=>$final,
    'fx'=>$fxReceipt,'checkout_ready'=>false,'status'=>'nonbinding_market_preview'];
  $receipt['sha256']=hash('sha256',wp_json_encode($receipt));
  return ['status'=>'nonbinding_property_market_preview','amount'=>$final,
    'applied'=>$r['mode']!=='inherit','receipt'=>$receipt,'checkout_ready'=>false];
 }
 public static function age($preview,$input,$source,$market){
  if(!is_array($preview)||!is_array($source))return $preview;
  if((int)($source['children']??0)>0){
   $preview['family_total_certified']=false;
   $preview['age_preview']=['status'=>'minor_supplements_need_verified_property_policy',
      'price_source'=>'market_resolved_first','family_total'=>null,'checkout_ready'=>false];
  }
  return $preview;
 }
 public static function fields(){
  add_meta_box('mad4b_property_market','Market Pricing · Property Rate',
    [__CLASS__,'box'],'property-rates','normal','default');
 }
 public static function box($post){
  if(!current_user_can('edit_post',$post->ID))return;
  wp_nonce_field('mad4b_property_market_save','mad4b_property_market_nonce');
  echo '<p>Approved markets only. Native Property Rate, room/cabin and source currency remain unchanged. Residence and contract require verified entitlement, not IP or passport switcher.</p>';
  echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
  foreach(['Enabled','Approved profile ID','Eligibility','Nationality','Residence','Pricing','Price / %','Currency','From','To','Evidence'] as $h)
    echo '<th>'.esc_html($h).'</th>';
  echo '</tr></thead><tbody>';
  $rows=self::rows($post->ID);$rows[]=[];
  foreach($rows as $i=>$r){
   $name='mad4b_property_market_rows['.$i.']';
   echo '<tr><td><input type="checkbox" name="'.esc_attr($name.'[enabled]').'" value="1" '.checked(!empty($r['enabled']),true,false).'></td>';
   echo '<td><input style="width:100px" name="'.esc_attr($name.'[profile_id]').'" value="'.esc_attr($r['profile_id']??'').'"></td>';
   foreach(['eligibility_basis'=>['approved_market'=>'Approved market','nationality'=>'Verified nationality','residence'=>'Verified residence','contract'=>'Verified contract'],
     'mode'=>['inherit'=>'Inherit native price','percent'=>'Percentage of native base','fixed_unit'=>'Fixed per room/cabin night']] as $key=>$options){
     if($key==='mode'){
      foreach(['nationality','residence_country'] as $field)
       echo '<td><input style="width:75px" name="'.esc_attr($name.'['.$field.']').'" value="'.esc_attr($r[$field]??'').'"></td>';
     }
     echo '<td><select name="'.esc_attr($name.'['.$key.']').'">';
     foreach($options as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected($r[$key]??'', $k,false).'>'.esc_html($v).'</option>';
     echo '</select></td>';
   }
   foreach(['amount'=>'number','currency'=>'text','valid_from'=>'date','valid_to'=>'date','evidence_note'=>'text'] as $field=>$type)
    echo '<td><input type="'.esc_attr($type).'" style="width:100px" step="0.01" name="'.esc_attr($name.'['.$field.']').'" value="'.esc_attr($r[$field]??'').'"></td>';
   echo '</tr>';
  }
  echo '</tbody></table></div>';
  echo '<p><button type="button" class="button" id="mad4b-property-market-add">Add Market Rate</button></p>';
  echo '<p>Save approved market rules; blank profile rows are ignored. Draft/disabled rates never create client-facing prices.</p>';
  echo '<script>(function(){const btn=document.getElementById("mad4b-property-market-add");if(!btn)return;const tbody=btn.closest(".postbox").querySelector("tbody");let n=tbody.children.length;btn.addEventListener("click",()=>{if(n>=60)return;const last=tbody.lastElementChild.cloneNode(true);last.querySelectorAll("input,select").forEach(el=>{el.name=el.name.replace(/\[\d+\]/,"["+n+"]");if(el.type==="checkbox")el.checked=false;else if(el.tagName==="SELECT")el.selectedIndex=0;else el.value="";});tbody.appendChild(last);n++;});})();</script>';
 }
 public static function save($id){
  if(!isset($_POST['mad4b_property_market_nonce']) ||
    !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_property_market_nonce'])),'mad4b_property_market_save')||
    !current_user_can('edit_post',$id)||!current_user_can('manage_woocommerce')||
    wp_is_post_revision($id)||wp_is_post_autosave($id))return;
  $input=isset($_POST['mad4b_property_market_rows'])&&is_array($_POST['mad4b_property_market_rows'])?
    wp_unslash($_POST['mad4b_property_market_rows']):[];
  $v=self::validate($input,$id,true);
  if(is_wp_error($v)){set_transient('mad4b_market_error_'.get_current_user_id(),$v->get_error_code(),60);return;}
  update_post_meta($id,self::META,$v);
 }
 public static function notice(){
  if(!current_user_can('manage_woocommerce'))return;
  $key='mad4b_market_error_'.get_current_user_id();
  $code=get_transient($key);if(!$code)return;
  delete_transient($key);
  echo '<div class="notice notice-error"><p>Property Market Pricing not saved: '.esc_html($code).'</p></div>';
 }
 public static function boot(){
  // Rate publication, market eligibility and WordPress permissions—not the environment name—govern this provider.
  add_filter('shared_product_market/accommodation_override',[__CLASS__,'resolve'],20,2);
  add_filter('booking_experience/accommodation/age_pricing_preview',[__CLASS__,'age'],20,4);
  add_action('add_meta_boxes',[__CLASS__,'fields']);
  add_action('save_post_property-rates',[__CLASS__,'save'],50);
  add_action('admin_notices',[__CLASS__,'notice']);
 }
}
MAD4B_Property_Market_Pricing::boot();

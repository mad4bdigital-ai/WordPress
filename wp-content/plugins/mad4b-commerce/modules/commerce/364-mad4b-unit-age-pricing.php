<?php
/**
 * Plugin Name: MAD4B Unit-aware Age Pricing Policy
 * Description: Optional per-unit age-pricing rules extending the native Age Occupancy Registry and server source pricing. Non-financial price previews only.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Unit_Age_Pricing {
 const CONTRACT='mad4b.unit-age-pricing.v1';
 const OPTION='mad4b_unit_age_pricing_rules_v1';
 const KINDS=['half_day','day','activity','extra_trip','package','cruise','other'];
 const UNITS=['per_person','per_trip','per_vehicle','per_room','per_cabin','per_day','table'];
 const MODES=['percent','fixed','free','on_request'];
 public static function rules(){ $r=get_option(self::OPTION,[]);return is_array($r)?$r:[]; }
 public static function normalize($input){
   if(!is_array($input)||count($input)>100)return new WP_Error('unit_age_rule_count_invalid');
   $out=[];$seen=[];
   foreach($input as $r){
     if(!is_array($r))return new WP_Error('unit_age_rule_invalid');
     $scope=sanitize_key((string)($r['scope']??''));
     if(!$scope)continue;
     $kind=sanitize_key((string)($r['kind']??''));
     $unit=sanitize_key((string)($r['unit']??''));
     $band=sanitize_key((string)($r['band']??''));
     $mode=sanitize_key((string)($r['mode']??''));
     $id=absint($r['target_id']??0);
     if(!in_array($scope,['unit','product_type','product','rate'],true)||
        !in_array($unit,self::UNITS,true)||!in_array($mode,self::MODES,true)||
        !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$band))
       return new WP_Error('unit_age_rule_schema_invalid');
     if($scope==='product_type'&&!in_array($kind,self::KINDS,true))
       return new WP_Error('unit_age_rule_type_invalid');
     if(in_array($scope,['product','rate'],true)&&
        (!$id||get_post_type($id)!==($scope==='rate'?'tour-rates':'tours-and-activities')))
       return new WP_Error('unit_age_rule_entity_invalid');
     $value=$r['value']??'';
     if(in_array($mode,['percent','fixed'],true)){
       if(!is_scalar($value)||!preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/D',(string)$value))
         return new WP_Error('unit_age_rule_value_invalid');
       $value=(float)$value;
       if($value<0||$value>($mode==='percent'?200:1000000))
         return new WP_Error('unit_age_rule_value_out_of_range');
     }else $value=null;
     $key=implode('|',[$scope,$scope==='product_type'?$kind:($scope==='unit'?'*':$id),$unit,$band]);
     if(isset($seen[$key]))return new WP_Error('unit_age_rule_duplicate');
     $seen[$key]=true;
     $out[]=['scope'=>$scope,'target_id'=>$id,'kind'=>$scope==='product_type'?$kind:'',
        'unit'=>$unit,'band'=>$band,'mode'=>$mode,'value'=>$value];
   }
   return $out;
 }
 public static function find_rule($tour,$rate,$unit,$band){
   $type=class_exists('Shared_Tour_Pricing_Entry_v1')
     ?Shared_Tour_Pricing_Entry_v1::classify($tour)
     :(string)get_post_meta($tour,'tour_mode',true);
   foreach(['rate','product','product_type','unit'] as $scope){
     foreach(self::rules() as $r){
       if(!is_array($r)||($r['scope']??'')!==$scope||($r['unit']??'')!==$unit||
          ($r['band']??'')!==$band)continue;
       if($scope==='rate'&&(!$rate||(int)($r['target_id']??0)!==(int)$rate))continue;
       if($scope==='product'&&(int)($r['target_id']??0)!==(int)$tour)continue;
       if($scope==='product_type'&&($r['kind']??'')!==$type)continue;
       return $r+['source_level'=>$scope,'resolved_product_type'=>$type];
     }
   }
   return null;
 }
 public static function calculation($raw,$result,$tour,$rate,$source,$adults,$children,$infants){
   $notReady=static function($code){return new WP_Error($code);};
   if(!$children&&!$infants)return $notReady('unit_age_no_minors');
   if(!class_exists('MAD4B_Age_Occupancy_Registry'))
     return $notReady('unit_age_registry_missing');
   $unit=(string)get_post_meta($tour,'tour_price_unit',true);
   // A group/room/vehicle price is NOT a per-person price. It requires a
   // separate verified capacity/allocation rule before child charges are inferred.
   if($source!=='direct'||$unit!=='per_person'||$rate)
     return $notReady('unit_age_source_or_unit_requires_independent_capacity_quote');
   if(!empty($raw['extras']))return $notReady('unit_age_extra_pricing_not_certified');
   $childAges=$raw['child_ages']??null;$infantAges=$raw['infant_ages']??null;
   if(!is_array($childAges)||!array_is_list($childAges)||
      !is_array($infantAges)||!array_is_list($infantAges)||
      count($childAges)!==$children||count($infantAges)!==$infants)
     return $notReady('unit_age_child_age_required');
   $policy=MAD4B_Age_Occupancy_Registry::policy(['domain'=>'tour',
     'product_id'=>$tour,'rate_id'=>$rate]);
   if(is_wp_error($policy))return $policy;
   if(($policy['policy']['age_pricing_enabled']??false)!==true)
     return $notReady('unit_age_not_enabled_in_existing_registry');
   $bands=$policy['policy']['bands']??[];
   if(!is_array($bands)||!$bands)return $notReady('unit_age_age_bands_missing');
   if(!class_exists('MAD4B_Age_Fluent_Policy'))return $notReady('unit_age_fluent_age_policy_missing');
   // Browser form fields are strings. Normalize through the existing
   // canonical Age Registry before the strict native category validator.
   $parsedChildren=[];$parsedInfants=[];
   foreach($childAges as $age){
     $v=MAD4B_Age_Occupancy_Registry::normalize_age($age);
     if(is_wp_error($v))return $v;
     $parsedChildren[]=$v;
   }
   foreach($infantAges as $age){
     $v=MAD4B_Age_Occupancy_Registry::normalize_age($age);
     if(is_wp_error($v))return $v;
     $parsedInfants[]=$v;
   }
   $ageCheck=MAD4B_Age_Fluent_Policy::validate(['domain'=>'tour',
     'product_id'=>$tour,'rate_id'=>$rate],$parsedChildren,$parsedInfants);
   if(is_wp_error($ageCheck))return $ageCheck;
   $adultTotal=(float)($result['total']??0);
   $currency=(string)($result['currency']??'');
   if($adultTotal<=0||$adults<1||!preg_match('/^[A-Z]{3}$/D',$currency))
     return $notReady('unit_age_adult_quote_missing');
   $adultUnit=$adultTotal/$adults;
   $rows=[];$minorTotal=0;
   foreach(array_merge(array_map(fn($a)=>['age'=>$a,'group'=>'child'],$childAges),
     array_map(fn($a)=>['age'=>$a,'group'=>'infant'],$infantAges)) as $item){
     $age=MAD4B_Age_Occupancy_Registry::normalize_age($item['age']);
     if(is_wp_error($age))return $age;
     $cents=MAD4B_Age_Occupancy_Registry::age_cents($age);
     $band=null;
     foreach($bands as $b){
       $lo=MAD4B_Age_Occupancy_Registry::age_cents($b['min_age']??'');
       $hi=MAD4B_Age_Occupancy_Registry::age_cents($b['max_age']??'');
       if(is_wp_error($lo)||is_wp_error($hi))return $notReady('unit_age_invalid_registry_bands');
       if($cents>=$lo&&$cents<=$hi){$band=(string)$b['id'];break;}
     }
     if($band===null)return $notReady('unit_age_band_not_found');
     $rule=self::find_rule($tour,$rate,$unit,$band);
     if(!$rule||$rule['mode']==='on_request')
       return $notReady('unit_age_rule_missing_or_on_request');
     if($rule['mode']==='percent')$charge=round($adultUnit*(float)$rule['value']/100,2);
     elseif($rule['mode']==='free')$charge=0.0;
     else{
       // Fixed prices belong to the product's native price currency and
       // must use the existing FX provider (no local exchange rate guess).
       $native=(string)get_post_meta($tour,'tour_base_currency',true);
       if(!preg_match('/^[A-Z]{3}$/D',$native)||
          !function_exists('pricing_currency_convert'))
         return $notReady('unit_age_fixed_currency_unavailable');
       $converted=pricing_currency_convert((float)$rule['value'],$native,$currency);
       if(!is_numeric($converted)||!is_finite((float)$converted)||$converted<0)
         return $notReady('unit_age_fixed_currency_conversion_failed');
       $charge=round((float)$converted,2);
     }
     $minorTotal+=$charge;
     $rows[]=['group'=>$item['group'],'age'=>$age,'band'=>$band,
       'mode'=>$rule['mode'],'value'=>$rule['value'],
       'charge'=>$charge,'rule_source'=>$rule['source_level']];
   }
   $total=round($adultTotal+$minorTotal,2);
   $proof=['contract'=>self::CONTRACT,'tour_id'=>$tour,'rate_id'=>$rate?:null,
     'source'=>$source,'unit'=>$unit,'unit_price_source'=>'existing_server_quoted_adult_unit',
     'age_registry'=>MAD4B_Age_Occupancy_Registry::VERSION,
     'age_policy_sha256'=>hash('sha256',wp_json_encode($policy['policy'])),
     'rule_set_sha256'=>hash('sha256',wp_json_encode(self::rules())),
     'adult_reference'=>$adultTotal,'minor_total'=>round($minorTotal,2),
     'items'=>$rows,'currency'=>$currency,
     'checkout_ready'=>false,'supplier_booking_authorized'=>false];
   $proof['sha256']=hash('sha256',wp_json_encode($proof));
   return ['status'=>'age_adjusted_price_preview_only','display_kind'=>'age_adjusted_nonbinding_estimate',
      'display_amount'=>$total,'total'=>$total,'adult_reference'=>$adultTotal,
      'minor_total'=>round($minorTotal,2),'children_count'=>$children,'infants_count'=>$infants,
      'currency'=>$currency,'age_pricing_receipt'=>$proof,'lines'=>$result['lines']??[],
      'price_unit'=>$unit,'pricing_scope'=>'adult_and_minor_source_verified_retail_preview',
      'availability'=>'not_checked','checkout_ready'=>false,'order_created'=>false,
      'booking_authorized'=>false,'child_price_state'=>'configured_retail_policy_preview'];
 }
 public static function extend_form_quote($preview,$raw,$result,$tour,$rate,$source,$adults,$children,$infants){
   if(!is_array($preview)||!$children&&!$infants)return $preview;
   $calc=self::calculation($raw,$result,$tour,$rate,$source,$adults,$children,$infants);
   if(is_wp_error($calc)){
     // Keep the original conservative adult-only price and explain why
     // a full child-adjusted preview cannot be shown.
     $preview['age_price_state']='requires_separate_pricing';
     $preview['age_price_reason']=$calc->get_error_code();
     return $preview;
   }
   return $calc;
 }
 public static function menu(){
   add_submenu_page('woocommerce','Unit-aware Age Pricing','Age Pricing · Units',
      'manage_woocommerce','mad4b-unit-age-pricing',[__CLASS__,'render']);
 }
 public static function render(){
   if(!current_user_can('manage_woocommerce'))return;
   $rows=self::rules();
   echo '<div class="wrap"><h1>Unit-aware Age Pricing</h1><p>This extends the existing <a href="'.
    esc_url(admin_url('admin.php?page=mad4b-age-occupancy')).'">Age & Occupancy Registry</a>. '
    .'The registry owns age bands and age-pricing enablement; these rules only set retail minor prices. '
    .'No rule is enabled implicitly. Existing Tour Rates, supplier costs and prices remain untouched.</p>';
   if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Rules validated and saved.</p></div>';
   echo '<p><strong>Preview scope:</strong> direct per-person product prices only. Per-trip, per-vehicle, room, cabin and per-day require verified capacity and allocation semantics; their rules never authorize an invented checkout total.</p>';
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
   wp_nonce_field('mad4b_unit_age_rules_save');
   echo '<input type="hidden" name="action" value="mad4b_unit_age_rules_save"/><table class="widefat striped"><thead><tr>';
   foreach(['Scope','Entity ID','Product kind','Unit','Age band ID','Price rule','Value'] as $h)echo '<th>'.esc_html($h).'</th>';
   echo '</tr></thead><tbody>';
   for($i=0;$i<max(5,count($rows)+2);$i++){
     $r=$rows[$i]??[];
     echo '<tr><td><select name="rules['.$i.'][scope]">';
     foreach([''=>'Unused','unit'=>'Unit default','product_type'=>'Product type + unit','product'=>'Tour/product override','rate'=>'Rate override'] as $k=>$v)
       echo '<option value="'.esc_attr($k).'" '.selected($r['scope']??'',$k,false).'>'.esc_html($v).'</option>';
     echo '</select></td><td><input type="number" min="0" style="width:100px" name="rules['.$i.'][target_id]" value="'.esc_attr($r['target_id']??0).'"/></td><td><select name="rules['.$i.'][kind]">';
     foreach(array_merge([''=>'—'],array_combine(self::KINDS,self::KINDS)) as $k=>$v)
       echo '<option value="'.esc_attr($k).'" '.selected($r['kind']??'',$k,false).'>'.esc_html($v).'</option>';
     echo '</select></td><td><select name="rules['.$i.'][unit]">';
     foreach(self::UNITS as $v)echo '<option value="'.esc_attr($v).'" '.selected($r['unit']??'per_person',$v,false).'>'.esc_html($v).'</option>';
     echo '</select></td><td><input type="text" maxlength="64" style="width:115px" placeholder="child / infant" name="rules['.$i.'][band]" value="'.esc_attr($r['band']??'').'"/></td><td><select name="rules['.$i.'][mode]">';
     foreach(['percent'=>'% of source unit price','fixed'=>'Fixed in product base currency','free'=>'Free','on_request'=>'On request'] as $k=>$v)
       echo '<option value="'.esc_attr($k).'" '.selected($r['mode']??'on_request',$k,false).'>'.esc_html($v).'</option>';
     echo '</select></td><td><input type="number" min="0" max="1000000" step="0.01" style="width:100px" name="rules['.$i.'][value]" value="'.esc_attr($r['value']??'').'"/></td></tr>';
   }
   echo '</tbody></table><p><button class="button button-primary" type="submit">Validate and save rules</button></p></form>';
   echo '<p>Precedence: Rate → Product → Product type + unit → Unit. Missing/ambiguous bands or currency conversion fail closed. All public preview amounts are nonbinding; never create a WooCommerce order.</p></div>';
 }
 public static function save(){
   if(!current_user_can('manage_woocommerce'))wp_die('Forbidden',403);
   check_admin_referer('mad4b_unit_age_rules_save');
   $input=isset($_POST['rules'])&&is_array($_POST['rules'])
      ?wp_unslash($_POST['rules']):[];
   $validated=self::normalize($input);
   if(is_wp_error($validated))wp_die(esc_html($validated->get_error_code()),400);
   update_option(self::OPTION,$validated,false);
   wp_safe_redirect(add_query_arg(['page'=>'mad4b-unit-age-pricing','saved'=>1],admin_url('admin.php')));exit;
 }
 public static function boot(){
   add_filter('mad4b/form6_minor_price_preview',[__CLASS__,'extend_form_quote'],20,9);
   add_action('admin_menu',[__CLASS__,'menu'],60);
   add_action('admin_post_mad4b_unit_age_rules_save',[__CLASS__,'save']);
 }
}
MAD4B_Unit_Age_Pricing::boot();

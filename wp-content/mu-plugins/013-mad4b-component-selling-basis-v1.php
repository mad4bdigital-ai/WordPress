<?php
/**
 * Plugin Name: MAD4B Component Selling Basis Inheritance V1
 * Version: 1.1.0
 * Description: Shared, read-only currency and sales-unit inheritance across Tour, Stay, Transport, Guide and Woo components.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Component_Selling_Basis_V1',false)) {
final class MAD4B_Component_Selling_Basis_V1 {
 const PAGE='etg-dynamic-pricing-controls';
 const CONTRACT='mad4b.component-selling-basis.v1';
 const UNITS=[
  'tour'=>['per_person','per_room','per_cabin','table','per_trip','per_day'],
  'accommodation'=>['per_person','per_room_night','per_cabin_night','per_person_night','per_stay'],
  'transport'=>['per_day','per_trip','per_person','per_cabin','per_vehicle'],
  'guide'=>['per_guide_day'],
  'woocommerce'=>['per_item']
 ];
 const TYPE_MAP=[
  'tours-and-activities'=>['tour','tour_base_currency','tour_price_unit'],
  'tour-rates'=>['tour','base_currency','unit_type'],
  'properties'=>['accommodation','',''],
  'units'=>['accommodation','',''],
  'property-rates'=>['accommodation','currency','prop_price_unit'],
  'transportations'=>['transport','trans_base_currency','trans_unit_type'],
  'transport-rates'=>['transport','base_currency','pricing_type'],
  'guides'=>['guide','',''],
  'product'=>['woocommerce','',''],
  'product_variation'=>['woocommerce','','']
 ];
 const BASELINES=[
  'tour'=>'per_person',
  'accommodation'=>'per_room_night',
  'transport'=>null, // never invent whether seat, day, car, or trip
  'guide'=>'per_guide_day',
  'woocommerce'=>'per_item'
 ];
 public static function mode(int $id,string $kind):string {
  if($kind!=='tour')return $kind;
  if(get_post_type($id)==='tour-rates')$id=(int)get_post_meta($id,'related_tour_id',true);
  $mode=(string)get_post_meta($id,'tour_mode',true);
  return in_array($mode,['tour','cruise_tour','activity','extra_trip'],true)?$mode:'tour';
 }
 private static function allowed_currency($v):string {
  $s=is_scalar($v)?strtoupper(trim((string)$v)):'';
  return preg_match('/^[A-Z]{3}$/D',$s)&&
    function_exists('get_woocommerce_currencies')&&
    array_key_exists($s,(array)get_woocommerce_currencies())?$s:'';
 }
 private static function from_meta(int $id,string $key):string {
  if($id<1||$key==='')return '';
  $v=get_post_meta($id,$key,true);
  return is_scalar($v)?trim((string)$v):'';
 }
 private static function parent(int $id,string $type):int {
  if($type==='tour-rates')$field='related_tour_id';
  elseif($type==='property-rates')$field='prop_related_property_id';
  elseif($type==='transport-rates')$field='source_post_id';
  elseif($type==='units')$field='related_property_id';
  elseif($type==='product_variation')return (int)wp_get_post_parent_id($id);
  else return 0;
  $p=(int)get_post_meta($id,$field,true);
  $expected=['tour-rates'=>'tours-and-activities','property-rates'=>'properties',
             'transport-rates'=>'transportations','units'=>'properties'][$type]??'';
  return $p>0&&get_post_type($p)===$expected?$p:0;
 }
 private static function kind_info(int $id,string $type):array|WP_Error {
  if(isset(self::TYPE_MAP[$type]))return self::TYPE_MAP[$type];
  return new WP_Error('component_basis_post_type_unsupported');
 }
 private static function accommodation_default(int $id,string $type,int $parent):string {
  // A Nile cruise sells cabin-nights, not hotel room-nights.
  if($type==='units') {
   $unitKind=(string)get_post_meta($id,'unit_kind',true);
   if($unitKind==='cabin')return 'per_cabin_night';
   if($unitKind==='room')return 'per_room_night';
  }
  if($type==='property-rates') {
   $unit=(int)get_post_meta($id,'prop_related_unit_id',true);
   if($unit>0 && get_post_type($unit)==='units') {
    $kind=(string)get_post_meta($unit,'unit_kind',true);
    if($kind==='cabin')return 'per_cabin_night';
    if($kind==='room')return 'per_room_night';
   }
  }
  $property=$type==='properties'?$id:$parent;
  if($property>0 && get_post_type($property)==='properties') {
   if(has_term(['cruise','nile-cruise'],'property-types',$property))
    return 'per_cabin_night';
  }
  return 'per_room_night';
 }
 public static function resolve(int $id,string $scope='sale') {
  if($scope!=='sale'&&$scope!=='supplier_cost')
   return new WP_Error('component_basis_scope_invalid');
  $type=(string)get_post_type($id);
  if(!isset(self::TYPE_MAP[$type]))
   return new WP_Error('component_basis_type_invalid');
  [$kind,$currencyKey,$unitKey]=self::TYPE_MAP[$type];
  $mode=self::mode($id,$kind);
  $parent=self::parent($id,$type);
  $currencyRaw=self::from_meta($id,$currencyKey);
  $unitRaw=self::from_meta($id,$unitKey);
  $canonicalTour=0;
  if($type==='tours-and-activities' && has_filter('wpml_object_id')){
   $default=(string)apply_filters('wpml_default_language','en');
   $canonical=(int)apply_filters('wpml_object_id',$id,'tours-and-activities',false,$default);
   if($canonical>0 && $canonical!==$id &&
      get_post_type($canonical)==='tours-and-activities' &&
      get_post_status($canonical)==='publish')$canonicalTour=$canonical;
  }
  $currencySource='object_override';
  if($scope==='sale' && $currencyRaw==='' && $canonicalTour){
    $sourceCurrency=self::from_meta($canonicalTour,$currencyKey);
    if($sourceCurrency!==''){$currencyRaw=$sourceCurrency;$currencySource='wpml_source_tour';}
  }
  $unitSource='object_override';
  if($scope==='sale' && $unitRaw==='' && $canonicalTour){
    $sourceUnit=self::from_meta($canonicalTour,$unitKey);
    if($sourceUnit!==''){$unitRaw=$sourceUnit;$unitSource='wpml_source_tour';}
  }
  if($scope==='supplier_cost'){
   // Original supplier invoice currency cannot be inferred from a Woo storefront.
   // Even an EGP company baseline is not evidence of an actual supplier invoice.
   if($type==='guides'){$currencyRaw='';}
   if($currencyRaw==='')return [
     'contract'=>self::CONTRACT,'id'=>$id,'type'=>$type,'kind'=>$kind,
     'scope'=>$scope,'currency'=>null,'currency_source'=>'supplier_document_required',
     'unit'=>null,'unit_source'=>'supplier_document_required',
     'status'=>'supplier_cost_currency_unverified',
     'quote_ready'=>false,'checkout_authorized'=>false,'mutation_performed'=>false
   ];
  }else{
   if($currencyRaw===''&&$parent){
    $parentType=(string)get_post_type($parent);
    $parentKey=self::TYPE_MAP[$parentType][1]??'';
    $currencyRaw=self::from_meta($parent,$parentKey);
    if($currencyRaw!=='')$currencySource='parent_override';
   }
   if($currencyRaw===''){
    // JetEngine Dynamic Pricing is the explicit company selling-currency policy.
    // It is NOT a WCML display currency or an FX conversion instruction.
    $candidate=get_option(self::PAGE.'_default_currency','');
    if(!is_scalar($candidate))return new WP_Error('component_basis_dynamic_currency_policy_invalid');
    $candidate=trim((string)$candidate);
    if($candidate!==''){
     $currencyRaw=$candidate;
     $currencySource='dynamic_pricing_controls';
    }
   }
   if($currencyRaw===''){
    // No explicit pricing currency: inherit WooCommerce's native store base.
    $currencyRaw=(string)get_option('woocommerce_currency','');
    $currencySource='woocommerce_store_base';
   }
  }
  $currency=self::allowed_currency($currencyRaw);
  if($currency==='')return new WP_Error(
   $currencySource==='woocommerce_store_base'?'component_basis_store_currency_invalid':'component_basis_explicit_currency_invalid');
  if($unitRaw===''&&$parent){
   $parentType=(string)get_post_type($parent);
   $parentKey=self::TYPE_MAP[$parentType][2]??'';
   $unitRaw=self::from_meta($parent,$parentKey);
   if($unitRaw!=='')$unitSource='parent_override';
  }
  if($unitRaw===''){
   // No globally forced per-person unit: each component inherits its mode.
   $candidate=get_option(self::PAGE.'_component_sale_unit_'.$mode,'');
   if(!is_scalar($candidate))return new WP_Error('component_basis_mode_policy_invalid');
   $candidate=trim((string)$candidate);
   if($candidate!==''){$unitRaw=$candidate;$unitSource='dynamic_pricing_mode';}
   else{
    $candidate=get_option(self::PAGE.'_component_sale_unit_'.$kind,'');
    if(!is_scalar($candidate))return new WP_Error('component_basis_kind_policy_invalid');
    $candidate=trim((string)$candidate);
    if($candidate!==''){$unitRaw=$candidate;$unitSource='dynamic_pricing_component';}
    else{
      $candidate=get_option(self::PAGE.'_component_sale_unit_default','');
      // A company-wide unit cannot override the domain's own glossary
      // unless that unit actually belongs to its pricing kind.
      $candidate=is_scalar($candidate)?trim((string)$candidate):'';
      if($candidate!==''&&in_array($candidate,self::UNITS[$kind],true)){
       $unitRaw=$candidate;$unitSource='dynamic_pricing_company';
      }else{
       $unitRaw=$kind==='accommodation'
        ?self::accommodation_default($id,$type,$parent)
        :(string)(self::BASELINES[$kind]??'');
       $unitSource=$unitRaw!==''?'engine_by_component_type':'requires_mode_unit_policy';
      }
    }
   }
  }
  if($unitRaw!==''&&!in_array($unitRaw,self::UNITS[$kind],true))
   return new WP_Error('component_basis_unit_invalid_for_kind');
  $isComplete=$unitRaw!=='';
  $label=$isComplete?ucwords(str_replace('_',' ',$unitRaw)):'Not classified';
  return [
   'contract'=>self::CONTRACT,
   'id'=>$id,'name'=>html_entity_decode(wp_strip_all_tags(get_the_title($id)),ENT_QUOTES|ENT_HTML5,'UTF-8'),
   'type'=>$type,'kind'=>$kind,
   'tour_mode'=>$mode,'parent_id'=>$parent?:null,'scope'=>$scope,
   'currency'=>$currency,'currency_source'=>$currencySource,
   'unit'=>$isComplete?$unitRaw:null,'unit_label'=>$label,
   'unit_source'=>$unitSource,'status'=>$isComplete?'resolved':'classification_required',
   'quantity_model'=>$isComplete?self::quantity_model($unitRaw):null,
   'direct_form6_per_person_supported'=>$kind==='tour'&&$unitRaw==='per_person',
   'quote_ready'=>false,'checkout_authorized'=>false,
   'read_only'=>true,'mutation_performed'=>false
  ];
 }
 public static function quantity_model(string $unit):string {
  return [
   'per_person'=>'travelers',
   'per_room'=>'rooms','per_cabin'=>'cabins','per_trip'=>'trips',
   'per_day'=>'days','per_vehicle'=>'vehicles','per_item'=>'items',
   'per_room_night'=>'room_nights','per_cabin_night'=>'cabin_nights',
   'per_person_night'=>'person_nights','per_stay'=>'stays',
   'per_guide_day'=>'guide_days','table'=>'tables'
  ][$unit]??'not_inferred';
 }
 public static function currency(int $id):string {
  $r=self::resolve($id,'sale');
  return is_wp_error($r)?'':(string)$r['currency'];
 }
 public static function unit(int $id):string {
  $r=self::resolve($id,'sale');
  return is_wp_error($r)?'':(string)($r['unit']??'');
 }
 /**
  * CCT/transport-rate and API payloads also inherit the same company policy.
  * This is a proposal/preview only; the source rate and its approvals remain
  * authoritative. A missing transport mode does not invent a quantity basis.
  */
 public static function resolve_external(string $kind,array $raw,string $scope='sale'):array|WP_Error {
  if(!isset(self::UNITS[$kind])||!in_array($scope,['sale','supplier_cost'],true))
   return new WP_Error('component_basis_external_kind_invalid');
  $amount=$raw['amount']??null;
  $explicitCurrency=is_scalar($raw['currency']??null)?trim((string)$raw['currency']):'';
  $explicitUnit=is_scalar($raw['unit']??null)?trim((string)$raw['unit']):'';
  $source=$explicitCurrency!==''?'explicit_source':'woocommerce_store_base';
  if($scope==='supplier_cost'&&$explicitCurrency==='')return [
   'contract'=>self::CONTRACT,'kind'=>$kind,'scope'=>$scope,
   'currency'=>null,'unit'=>null,'currency_source'=>'supplier_document_required',
   'unit_source'=>'supplier_document_required','status'=>'supplier_cost_currency_unverified',
   'quote_ready'=>false,'checkout_authorized'=>false,'mutation_performed'=>false
  ];
  $currency=self::allowed_currency($explicitCurrency!==''?$explicitCurrency:
    get_option('woocommerce_currency',''));
  if($currency==='')return new WP_Error('component_basis_external_currency_invalid');
  $mode=is_scalar($raw['mode']??null)?sanitize_key((string)$raw['mode']):$kind;
  $unit=$explicitUnit;
  $unitOrigin=$unit!==''?'explicit_source':'';
  if($unit===''){
   $x=get_option(self::PAGE.'_component_sale_unit_'.$mode,'');
   $unit=is_scalar($x)?trim((string)$x):'';
   if($unit!=='')$unitOrigin='dynamic_pricing_mode';
  }
  if($unit===''){
   $x=get_option(self::PAGE.'_component_sale_unit_'.$kind,'');
   $unit=is_scalar($x)?trim((string)$x):'';
   if($unit!=='')$unitOrigin='dynamic_pricing_component';
  }
  if($unit===''){
   $x=get_option(self::PAGE.'_component_sale_unit_default','');
   $x=is_scalar($x)?trim((string)$x):'';
   if(in_array($x,self::UNITS[$kind],true)){$unit=$x;$unitOrigin='dynamic_pricing_company';}
  }
  if($unit===''){
   $unit=$kind==='accommodation'&&($raw['unit_kind']??null)==='cabin'
    ?'per_cabin_night':(string)(self::BASELINES[$kind]??'');
   $unitOrigin=$unit!==''?'engine_by_component_type':'requires_mode_unit_policy';
  }
  if($unit!==''&&!in_array($unit,self::UNITS[$kind],true))
   return new WP_Error('component_basis_external_unit_invalid_for_kind');
  return [
   'contract'=>self::CONTRACT,'id'=>isset($raw['id'])?absint($raw['id']):null,
   'kind'=>$kind,'scope'=>$scope,'mode'=>$mode,
   'currency'=>$currency,'currency_source'=>$source,
   'unit'=>$unit!==''?$unit:null,'unit_source'=>$unitOrigin,
   'quantity_model'=>$unit!==''?self::quantity_model($unit):null,
   'amount_present'=>is_numeric($amount)&&(float)$amount>0,
   'status'=>$unit!==''?'resolved':'classification_required',
   'quote_ready'=>false,'checkout_authorized'=>false,
   'read_only'=>true,'mutation_performed'=>false
  ];
 }
 public static function guide_language(int $term):array|WP_Error {
  $t=get_term($term,'guide-languages');
  if(!$t instanceof WP_Term)return new WP_Error('component_guide_term_not_found');
  $unit=(string)get_term_meta($term,'mad4b_guide_cost_unit',true);
  $currency=(string)get_term_meta($term,'mad4b_guide_cost_currency',true);
  $proposal=$unit?:'per_guide_day';
  if($unit!==''&&$unit!=='per_guide_day')
   return new WP_Error('component_guide_unit_invalid');
  return [
   'contract'=>self::CONTRACT,'id'=>$term,'name'=>$t->name,
   'type'=>'guide-languages','kind'=>'guide','scope'=>'supplier_cost',
   'cost_currency'=>$currency!==''?self::allowed_currency($currency):null,
   'cost_currency_source'=>$currency!==''?'supplier_explicit':'supplier_document_required',
   'unit'=>$proposal,'unit_source'=>$unit!==''?'term_override':'guide_engine_default',
   'store_currency_for_sale'=>self::allowed_currency(get_option('woocommerce_currency','')),
   'cost_approval_inferred'=>false,'checkout_authorized'=>false,'mutation_performed'=>false
  ];
 }
 public static function admin_fields($fields,$page) {
  if(!is_array($fields)||($page->slug??'')!==self::PAGE)return $fields;
  $defined=array_column($fields,'name');
  $groups=[
    'default'=>['Company generic fallback',array_unique(array_merge(...array_values(self::UNITS)))],
    'tour'=>['Tour & Package',self::UNITS['tour']],
    'cruise_tour'=>['Cruise Tour',self::UNITS['tour']],
    'activity'=>['Activities / Extras',self::UNITS['tour']],
    'extra_trip'=>['Optional Excursions',self::UNITS['tour']],
    'accommodation'=>['Hotel / Cruise Stay / Units',self::UNITS['accommodation']],
    'transport'=>['Transfer / Transport',self::UNITS['transport']],
    'guide'=>['Guide Service',self::UNITS['guide']]
  ];
  foreach($groups as $key=>[$label,$units]){
   $name='component_sale_unit_'.$key;
   if(in_array($name,$defined,true))continue;
   $opts=[['key'=>'','value'=>'Inherit next policy']];
   foreach($units as $unit)$opts[]=['key'=>$unit,'value'=>ucwords(str_replace('_',' ',$unit))];
   $fields[]=[
    'name'=>$name,'title'=>'Component sale unit — '.$label,
    'description'=>'Only used when the component has no explicit unit. Unit controls quantity semantics, never authorizes a price or payment. Supplier original cost currencies must remain evidenced.',
    'type'=>'select','object_type'=>'field','options_source'=>'manual',
    'options'=>$opts
   ];
  }
  return $fields;
 }
 public static function tour_editor_box():void {
  if(!current_user_can('edit_posts'))return;
  foreach(['tours-and-activities','tour-rates','properties','units',
           'property-rates','transportations','transport-rates','guides','product'] as $type){
  if(!post_type_exists($type))continue;
  add_meta_box('mad4b-component-basis-v1',
   'Selling basis · inherited currency and unit',
   static function($post){
    $costOnly=$post->post_type==='property-rates' &&
      (string)get_post_meta($post->ID,'mad4b_rate_price_kind_v1',true)==='cost';
    $r=self::resolve((int)$post->ID,$costOnly?'supplier_cost':'sale');
    if(is_wp_error($r)){echo '<p>'.esc_html($r->get_error_code()).'</p>';return;}
    echo '<p>Scope: <strong>'.esc_html($costOnly?'Original supplier cost':'Selling price / reference').'</strong></p>';
    echo '<p>Currency: <strong>'.esc_html($r['currency']??'Supplier document required').'</strong> <small>('.
      esc_html($r['currency_source']).')</small></p>';
    echo '<p>Unit: <strong>'.esc_html($r['unit']??'Policy required').'</strong> <small>('.
      esc_html($r['unit_source']).')</small></p>';
    echo '<p>Missing price or supplier receipt still blocks financial admission. Inherited unit or currency never confirms booking, Rate, supplier cost or deposit.</p>';
    echo '<p><a href="'.esc_url(admin_url('admin.php?page=etg-dynamic-pricing-controls')).'">Edit Dynamic Pricing policy</a></p>';
   },$type,'side','default');
  }
 }
 public static function boot():void {
  add_filter('jet-engine/options-pages/raw-fields',[self::class,'admin_fields'],45,2);
  add_action('add_meta_boxes',[self::class,'tour_editor_box'],55);
 }
}
add_action('init',['MAD4B_Component_Selling_Basis_V1','boot'],18);
}


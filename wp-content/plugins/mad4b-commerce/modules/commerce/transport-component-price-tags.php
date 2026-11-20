<?php
/** Engine-backed native Elementor transport card prices. Read-only indicative display. */
defined('ABSPATH') || exit;
function transport_component_price_current_id(): int {
 $object=function_exists('jet_engine')?jet_engine()->listings->data->get_current_object():null;
 if($object instanceof WP_Post)return $object->post_type==='transportations'?(int)$object->ID:0;
 $id=get_the_ID();return get_post_type($id)==='transportations'?(int)$id:0;
}
function transport_component_price_evidence($id,array $context=[]){
 $fail=static fn($code)=>new WP_Error($code,'Transport price unavailable');
 $post=get_post($id);
 if(!$post||$post->post_type!=='transportations'||$post->post_status!=='publish')return $fail('transport_component_not_public');
 if(!current_user_can('manage_woocommerce')&&($post->post_password!==''||get_post_meta($id,'_booking_experience_qa_pricing_fixture',true)!==''))return $fail('transport_component_protected');
 foreach(['BookingExperience\\unified_transport_preview','BookingExperience\\transport_rate_candidates','BookingExperience\\transport_rate_eligible','pricing_display_currency','pricing_currency_convert']as$f)if(!function_exists($f))return $fail('transport_component_provider_missing');
 $scope=$context['context']??null;if(!in_array($scope,['standalone','tour_component'],true))return $fail('transport_component_context_required');
 $date=$context['travel_date']??null;$parsed=is_string($date)?DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC')):false;
 if(!$parsed||$parsed->format('Y-m-d')!==$date)return $fail('transport_component_date_required');
 // Passport is not a pricing tier. The canonical quote provider below applies only approved market rules.
 $rid=$context['rate_id']??0;
 if(!is_int($rid)&&!(is_string($rid)&&preg_match('/^(?:0|[1-9][0-9]*)$/D',$rid)))return $fail('transport_component_rate_invalid');$rid=(int)$rid;
 $reference=($context['quantity_mode']??'context')==='unit_reference';
 $q=$context['quantities']??null;
 if($reference){
  $eligible=[];
  foreach(BookingExperience\transport_rate_candidates((int)$id)as$rate){
   if($rid&&$rate->ID!==$rid)continue;
   if(!current_user_can('manage_woocommerce')&&($rate->post_password!==''||get_post_meta($rate->ID,'_booking_experience_qa_pricing_fixture',true)!==''))continue;
   $source=BookingExperience\transport_rate_eligible($rate->ID,$date,$scope);if(!is_wp_error($source))$eligible[]=$source;
  }
  if(count($eligible)!==1)return $fail(count($eligible)>1?'transport_component_rate_ambiguous':'transport_component_no_eligible_rate');
  $rid=(int)$eligible[0]['data']['rate_id'];$unit=$eligible[0]['data']['unit'];
  $keys=['per_person'=>'persons','per_trip'=>'trips','per_day'=>'days','per_room'=>'rooms','per_vehicle'=>'vehicles','per_cabin'=>'cabins','table'=>'tables'];
  if(!isset($keys[$unit]))return $fail('transport_component_unit_unsupported');$q=[$keys[$unit]=>1];
 }
 if(!is_array($q)||!$q||array_diff(array_keys($q),['persons','rooms','cabins','vehicles','days','trips','tables']))return $fail('transport_component_quantities_required');
 foreach($q as$n)if((!is_int($n)&&!(is_string($n)&&preg_match('/^[1-9][0-9]*$/D',$n)))||(int)$n<1||(int)$n>999)return $fail('transport_component_quantities_invalid');
 // The engine owns source eligibility, quantities, market and preset adjustments.
 $quote=BookingExperience\unified_transport_preview((int)$id,array_map('intval',$q),$rid,$date,$scope);if(is_wp_error($quote))return $quote;
 $source_post=get_post((int)($quote['rate_id']??0));
 if(!$source_post||$source_post->post_type!=='transport-rates'||$source_post->post_status!=='publish')return $fail('transport_component_source_invalid');
 if(!current_user_can('manage_woocommerce')&&($source_post->post_password!==''||get_post_meta($source_post->ID,'_booking_experience_qa_pricing_fixture',true)!==''))return $fail('transport_component_rate_protected');
 $source=$quote['currency']??null;$raw=$quote['preview_price']??null;$unit=$quote['price_unit']??null;
 if(!is_string($source)||!preg_match('/^[A-Z]{3}$/D',$source)||!is_numeric($raw)||!is_finite((float)$raw)||(float)$raw<0||!is_string($unit))return $fail('transport_component_quote_invalid');
 $target=pricing_display_currency();$amount=pricing_currency_convert($raw,$source,$target);if($amount===null)return $fail('transport_component_fx_unavailable');
 $chosen=BookingExperience\transport_rate_source((int)$quote['rate_id']);
 if(is_wp_error($chosen))return $chosen;
 return ['is_on_sale'=>(bool)$chosen['data']['is_on_sale'],'transport_id'=>(int)$id,'rate_id'=>(int)$quote['rate_id'],'source'=>'transport_rate_cpt','amount'=>(float)$amount,'currency'=>$target,'unit'=>$unit,'quantity'=>(int)$quote['quantity'],'reference_unit'=>$reference,'travel_date'=>$date,'context'=>$scope,'status'=>'indicative_not_booking_authority'];
}
function transport_component_price_part($e,$part='formatted',$role='current',$mode='code',$show_label=true): string {
 if(!in_array($role,['current','previous','sale'],true))return '';
 // No verified previous final quote exists after market/preset adjustments.
 if($role!=='current')return '';
 // State is derived from the same approved, eligible final quote used by the price.
 if($part==='state')return is_wp_error($e)||!is_array($e)?'unavailable':(!empty($e['is_on_sale'])?'sale':'regular');
 if(is_wp_error($e)||!is_array($e))return $part==='formatted'?__('Price on request','woocommerce'):'';
 $labels=['per_person'=>__('per person','woocommerce'),'per_trip'=>__('per trip','woocommerce'),'per_day'=>__('per day','woocommerce'),'per_room'=>__('per room','woocommerce'),'per_vehicle'=>__('per vehicle','woocommerce'),'per_cabin'=>__('per cabin','woocommerce'),'table'=>__('per table','woocommerce')];
 if($part==='amount')return number_format($e['amount'],wc_get_price_decimals(),wc_get_price_decimal_separator(),wc_get_price_thousand_separator());
 if($part==='currency')return $mode==='symbol'?html_entity_decode(get_woocommerce_currency_symbol($e['currency']),ENT_QUOTES|ENT_HTML5,'UTF-8'):$e['currency'];
 if($part==='unit')return !empty($e['reference_unit'])?($labels[$e['unit']]??''):'';
 if($part==='label')return !empty($e['reference_unit'])?__('From','woocommerce'):__('Estimated total','woocommerce');
 if($part!=='formatted')return '';
 $price=trim(preg_replace('/\s+/u',' ',html_entity_decode(wp_strip_all_tags(wc_price($e['amount'],['currency'=>$e['currency']])),ENT_QUOTES|ENT_HTML5,'UTF-8')));
 return ($show_label?transport_component_price_part($e,'label').' ':'').$price.(!empty($e['reference_unit'])?' / '.($labels[$e['unit']]??''):'');
}
function transport_component_price_tag_context(array $settings): array {
 $ctx=apply_filters('transport_component_price_context',[],transport_component_price_current_id());$ctx=is_array($ctx)?$ctx:[];
 $scope=$settings['context']??'auto';if($scope==='auto')$scope=$ctx['context']??(get_post_type(get_queried_object_id())==='tours-and-activities'?'tour_component':'standalone');
 $date_mode=$settings['date_mode']??'current_day';
 $date=$date_mode==='fixed'?($settings['travel_date']??''):($date_mode==='context'?($ctx['travel_date']??''):($ctx['travel_date']??wp_date('Y-m-d')));
 $quantities=$ctx['quantities']??null;$json=$settings['quantities_json']??'';
 if(is_string($json)&&trim($json)!=='')$quantities=json_decode($json,true);
 $mode=$settings['quantity_mode']??'unit_reference';
 return ['context'=>$scope,'travel_date'=>$date,'rate_id'=>(string)($settings['rate_id']??'')!==''?($settings['rate_id']??0):($ctx['rate_id']??0),'quantity_mode'=>$mode,'quantities'=>$quantities];
}
add_action('elementor/dynamic_tags/register',static function($manager){
 if(!class_exists('Elementor\\Core\\DynamicTags\\Data_Tag'))return;
 if(!class_exists('Transport_Component_Price_Tag')){
  class Transport_Component_Price_Tag extends Elementor\Core\DynamicTags\Data_Tag {
   protected $part='formatted';
   public function get_name(){return $this->part==='formatted'?'transport-component-price':'transport-component-price-'.$this->part;}
   public function get_title(){return 'Shared Pricing · Transport '.ucfirst($this->part);}
   public function get_group(){return 'shared_pricing';}
   public function get_categories(){return [Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
   protected function register_controls(){
    $this->add_control('context',['label'=>'Service context','type'=>Elementor\Controls_Manager::SELECT,'options'=>['auto'=>'Auto from page/context','standalone'=>'Standalone','tour_component'=>'Tour component'],'default'=>'auto']);
    $this->add_control('date_mode',['label'=>'Price date','type'=>Elementor\Controls_Manager::SELECT,'options'=>['current_day'=>'Current day / inherited date','context'=>'Inherited context only','fixed'=>'Fixed date'],'default'=>'current_day']);
    $this->add_control('travel_date',['label'=>'Fixed date (YYYY-MM-DD)','type'=>Elementor\Controls_Manager::TEXT,'condition'=>['date_mode'=>'fixed']]);
    $this->add_control('rate_id',['label'=>'Explicit Transport Rate ID (optional)','type'=>Elementor\Controls_Manager::TEXT]);
    $this->add_control('quantity_mode',['label'=>'Quantity basis','type'=>Elementor\Controls_Manager::SELECT,'options'=>['unit_reference'=>'One engine pricing unit (indicative)','context'=>'Explicit inherited quantities'],'default'=>'unit_reference']);
    $this->add_control('quantities_json',['label'=>'Explicit quantities JSON (optional)','type'=>Elementor\Controls_Manager::TEXT,'condition'=>['quantity_mode'=>'context']]);
    $this->add_control('role',['label'=>'Price role','type'=>Elementor\Controls_Manager::SELECT,'options'=>['current'=>'Current indicative','previous'=>'Previous (only verified)','sale'=>'Sale (only verified)'],'default'=>'current']);
    if($this->part==='formatted')$this->add_control('show_label',['label'=>'Show price label','type'=>Elementor\Controls_Manager::SWITCHER,'default'=>'yes']);
    if($this->part==='currency')$this->add_control('mode',['label'=>'Currency representation','type'=>Elementor\Controls_Manager::SELECT,'options'=>['code'=>'Code','symbol'=>'Symbol'],'default'=>'code']);
   }
   public function get_value(array $options=[]){
    $e=transport_component_price_evidence(transport_component_price_current_id(),transport_component_price_tag_context((array)$this->get_settings()));
    return transport_component_price_part($e,$this->part,(string)($this->get_settings('role')?:'current'),(string)($this->get_settings('mode')?:'code'),$this->get_settings('show_label')==='yes');
   }
  }
  class Transport_Component_Amount_Tag extends Transport_Component_Price_Tag {protected $part='amount';}
  class Transport_Component_Currency_Tag extends Transport_Component_Price_Tag {protected $part='currency';}
  class Transport_Component_Unit_Tag extends Transport_Component_Price_Tag {protected $part='unit';}
  class Transport_Component_Label_Tag extends Transport_Component_Price_Tag {protected $part='label';}
  class Transport_Component_State_Tag extends Transport_Component_Price_Tag {protected $part='state';}
 }
 $manager->register_group('shared_pricing',['title'=>__('Shared Pricing','woocommerce')]);
 $manager->register(new Transport_Component_Price_Tag());
 foreach(['amount','currency','unit','label','state']as$part){$class='Transport_Component_'.ucfirst($part).'_Tag';$manager->register(new $class());}
},21);

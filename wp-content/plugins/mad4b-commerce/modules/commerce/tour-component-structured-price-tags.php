<?php
/**
 * Plugin Name: Tour / Extra Structured Engine Price Tags
 * Description: Editor-owned price parts from one existing indicative pricing evidence contract.
 */
defined('ABSPATH') || exit;

/**
 * Return only a requested display part. Never read source-price or currency meta here.
 * The existing engine owns source selection, eligibility, units and FX conversion.
 */
function tour_component_structured_price_value(string $part,int $id=0,string $audience='auto',string $date_mode='inherited',array $options=[]):string {
 if(!in_array($part,['amount','currency','unit','label','text'],true))return '';
 // The current evidence contract exposes a final indicative price, not a regular/sale pair.
 // Keep unsupported roles empty, including currency/unit, rather than invent a strike-through.
 $role=$options['role']??'current';
 if($role!=='current')return '';
 if(!function_exists('tour_component_price_evidence')||!function_exists('tour_component_price_current_id'))return '';
 $unavailable=in_array($part,['amount','text'],true)?__('Price on request','woocommerce'):'';
 $id=$id?:tour_component_price_current_id();if($id<=0)return $unavailable;
 $e=tour_component_price_evidence($id,$audience,$date_mode);
 if(is_wp_error($e)||!is_array($e)||!isset($e['amount'],$e['currency'],$e['unit'])||
    !is_numeric($e['amount'])||!is_finite((float)$e['amount'])||(float)$e['amount']<0||
    !is_string($e['currency'])||!preg_match('/^[A-Z]{3}$/D',$e['currency']))return $unavailable;
 if($part==='text'){
  $labels=['per_person'=>__('per person','woocommerce'),'per_trip'=>__('per trip','woocommerce'),'per_day'=>__('per day','woocommerce'),'per_room'=>__('per room','woocommerce'),'per_vehicle'=>__('per vehicle','woocommerce'),'per_cabin'=>__('per cabin','woocommerce'),'table'=>__('per table','woocommerce')];
  if(!isset($labels[$e['unit']]))return $unavailable;
  $currency=($options['currency_mode']??'code')==='symbol'&&function_exists('get_woocommerce_currency_symbol')?html_entity_decode(get_woocommerce_currency_symbol($e['currency']),ENT_QUOTES,'UTF-8'):$e['currency'];
  $amount=number_format_i18n((float)$e['amount'],function_exists('wc_get_price_decimals')?wc_get_price_decimals():2);
  $label=($options['show_label']??'yes')==='yes'?(!empty($e['upcoming'])?__('Upcoming from','woocommerce'):__('From','woocommerce')).' ':'';
  $unit=($options['show_unit']??'yes')==='yes'?' / '.$labels[$e['unit']]:'';
  return $label.$currency.' '.$amount.$unit;
 }
 if($part==='amount'){
  $prefix=is_scalar($options['label_prefix']??'')?trim(sanitize_text_field((string)($options['label_prefix']??''))):'';
  $label=$prefix!==''?$prefix.' ':(($options['show_label']??'no')==='yes'?(!empty($e['upcoming'])?__('Upcoming from','woocommerce'):__('From','woocommerce')).' ':'');
  return $label.number_format_i18n((float)$e['amount'],function_exists('wc_get_price_decimals')?wc_get_price_decimals():2);
 }
 if($part==='currency'){
  $mode=$options['mode']??'code';
  if($mode==='code')return $e['currency'];
  if($mode==='symbol'&&function_exists('get_woocommerce_currency_symbol'))
   return html_entity_decode(get_woocommerce_currency_symbol($e['currency']),ENT_QUOTES,'UTF-8');
  return '';
 }
 if($part==='label')return !empty($e['upcoming'])?__('Upcoming from','woocommerce'):__('From','woocommerce');
 $labels=['per_person'=>__('per person','woocommerce'),'per_trip'=>__('per trip','woocommerce'),
  'per_day'=>__('per day','woocommerce'),'per_room'=>__('per room','woocommerce'),
  'per_vehicle'=>__('per vehicle','woocommerce'),'per_cabin'=>__('per cabin','woocommerce'),
  'table'=>__('per table','woocommerce')];
 if(!isset($labels[$e['unit']]))return '';
 $mode=$options['mode']??'label';return $mode==='code'?$e['unit']:($mode==='label'?$labels[$e['unit']]:'');
}

// Source: https://developers.elementor.com/docs/dynamic-tags/
// Data_Tag keeps number/currency/unit in native editor controls rather than injecting a replacement card.
add_action('elementor/dynamic_tags/register',static function($manager){
 if(!class_exists('Elementor\Core\DynamicTags\Data_Tag'))return;
 if(!class_exists('Tour_Component_Structured_Price_Tag')){
  class Tour_Component_Structured_Price_Tag extends Elementor\Core\DynamicTags\Data_Tag {
   protected string $part='amount';
   public function get_name(){return 'tour-component-price-'.$this->part;}
   public function get_title(){
    $titles=['amount'=>'Tour / Extra · Engine Amount','currency'=>'Tour / Extra · Engine Currency',
     'unit'=>'Tour / Extra · Engine Unit','label'=>'Tour / Extra · Engine Price Label','text'=>'Tour / Extra · Engine Price Text'];
    return __($titles[$this->part],'woocommerce');
   }
   public function get_group(){return 'shared_pricing';}
   public function get_categories(){return [Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
   protected function register_controls(){
    $this->add_control('audience',['label'=>__('Audience','woocommerce'),'type'=>Elementor\Controls_Manager::SELECT,
     'options'=>['auto'=>'Current nationality profile','general'=>'General','egyptians'=>'Egyptian'],'default'=>'auto']);
    $this->add_control('date_mode',['label'=>__('Card date policy','woocommerce'),'type'=>Elementor\Controls_Manager::SELECT,
     'options'=>['inherited'=>'Inherit shared policy','active_today'=>'Active today','upcoming'=>'Include upcoming indicative Rates'],'default'=>'inherited']);
    $this->add_control('role',['label'=>__('Price role','woocommerce'),'type'=>Elementor\Controls_Manager::SELECT,
     'options'=>['current'=>'Current indicative price','previous'=>'Previous — unavailable in this evidence contract','sale'=>'Sale — unavailable in this evidence contract'],'default'=>'current']);
    if($this->part==='amount'){
     $this->add_control('show_label',['label'=>__('Show price label','woocommerce'),'type'=>Elementor\Controls_Manager::SWITCHER,'default'=>'']);
     $this->add_control('label_prefix',['label'=>__('Authored price prefix','woocommerce'),'type'=>Elementor\Controls_Manager::TEXT,'default'=>'']);
    }
    if($this->part==='text'){
     $this->add_control('show_label',['label'=>__('Show price label','woocommerce'),'type'=>Elementor\Controls_Manager::SWITCHER,'default'=>'yes']);
     $this->add_control('show_unit',['label'=>__('Show unit','woocommerce'),'type'=>Elementor\Controls_Manager::SWITCHER,'default'=>'yes']);
     $this->add_control('currency_mode',['label'=>__('Currency display','woocommerce'),'type'=>Elementor\Controls_Manager::SELECT,'options'=>['code'=>'Currency code','symbol'=>'Currency symbol'],'default'=>'code']);
    }
    if($this->part==='currency')$this->add_control('mode',['label'=>__('Currency display','woocommerce'),'type'=>Elementor\Controls_Manager::SELECT,'options'=>['code'=>'Currency code','symbol'=>'Currency symbol'],'default'=>'code']);
    if($this->part==='unit')$this->add_control('mode',['label'=>__('Unit display','woocommerce'),'type'=>Elementor\Controls_Manager::SELECT,'options'=>['label'=>'Localized label','code'=>'Engine unit code'],'default'=>'label']);
   }
   public function get_value(array $options=[]){
    return tour_component_structured_price_value($this->part,0,(string)($this->get_settings('audience')?:'auto'),
     (string)($this->get_settings('date_mode')?:'inherited'),
     ['role'=>(string)($this->get_settings('role')?:'current'),
      'mode'=>(string)($this->get_settings('mode')?:($this->part==='currency'?'code':'label')),
      'show_label'=>(string)$this->get_settings('show_label'),'label_prefix'=>(string)$this->get_settings('label_prefix'),'show_unit'=>(string)$this->get_settings('show_unit'),
      'currency_mode'=>(string)($this->get_settings('currency_mode')?:'code')]);
   }
  }
 }
 if(!class_exists('Tour_Component_Price_Amount_Tag')){
 class Tour_Component_Price_Amount_Tag extends Tour_Component_Structured_Price_Tag {protected string $part='amount';}
 class Tour_Component_Price_Currency_Tag extends Tour_Component_Structured_Price_Tag {protected string $part='currency';}
 class Tour_Component_Price_Unit_Tag extends Tour_Component_Structured_Price_Tag {protected string $part='unit';}
 class Tour_Component_Price_Label_Tag extends Tour_Component_Structured_Price_Tag {protected string $part='label';}
 class Tour_Component_Price_Text_Tag extends Tour_Component_Structured_Price_Tag {protected string $part='text';}
 }
 $manager->register_group('shared_pricing',['title'=>__('Shared Pricing','woocommerce')]);
 foreach(['Tour_Component_Price_Amount_Tag','Tour_Component_Price_Currency_Tag','Tour_Component_Price_Unit_Tag','Tour_Component_Price_Label_Tag','Tour_Component_Price_Text_Tag']as$class)$manager->register(new $class());
},21);

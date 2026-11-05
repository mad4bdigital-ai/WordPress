<?php
/** Local-only Elementor-native display parts; quote authority stays inside
 * the existing Pricing Registry and WCML display-currency resolver. */
defined('ABSPATH') || exit;
if(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)return;
function tour_rate_structured_quote($rate_id,$occupancy,$audience,$role){
 if(!function_exists('pricing_audience_quote')||!function_exists('pricing_currency_convert')
  ||!function_exists('pricing_display_currency')||!function_exists('pricing_audience_display'))return null;
 if(!in_array($occupancy,['single','double','triple'],true)
   ||!in_array($audience,['general','egyptians'],true)
   ||!in_array($role,['current','sale','previous','final'],true))return null;
 $quote=pricing_audience_quote(absint($rate_id),$audience,$occupancy);
 if(($quote['status']??null)!=='available')return null;
 if(($role==='current'&&!empty($quote['discounted']))
  ||(in_array($role,['sale','previous'],true)&&empty($quote['discounted'])))return null;
 $currency=pricing_display_currency();
 $priceRole=$role==='sale'?'sale':($role==='final'?'final':'regular');
 if(pricing_audience_display($quote,$priceRole,$currency)==='')return null;
 $original=$role==='sale'?$quote['sale']:($role==='final'?$quote['final']:$quote['regular']);
 $converted=pricing_currency_convert($original,$quote['source_currency'],$currency);
 if($converted===null || !is_numeric($converted) || (float)$converted<=0)return null;
 return ['amount'=>(float)$converted,'currency'=>$currency];
}
function tour_rate_structured_value($rate_id,$occupancy='single',$audience='general',$role='current',$part='amount',$mode='code'){
 $quote=tour_rate_structured_quote($rate_id,$occupancy,$audience,$role);
 if(!$quote)return '';
 if($part==='currency')return $mode==='symbol'?html_entity_decode(get_woocommerce_currency_symbol($quote['currency']),ENT_QUOTES|ENT_HTML5,'UTF-8'):$quote['currency'];
 return number_format($quote['amount'],wc_get_price_decimals(),wc_get_price_decimal_separator(),wc_get_price_thousand_separator());
}
add_action('elementor/dynamic_tags/register',static function($manager){
 if(!class_exists('Elementor\\Core\\DynamicTags\\Data_Tag'))return;
 if(!class_exists('Tour_Rate_Structured_Amount_Tag')){
  abstract class Tour_Rate_Structured_Base_Tag extends Elementor\Core\DynamicTags\Data_Tag {
   public function get_group(){return 'tour_rate_native_display';}
   public function get_categories(){return [Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
   protected function register_controls(){
    $this->add_control('occupancy',['label'=>'Occupancy','type'=>Elementor\Controls_Manager::SELECT,'options'=>['single'=>'Single','double'=>'Double','triple'=>'Triple'],'default'=>'single']);
    $this->add_control('audience',['label'=>'Audience','type'=>Elementor\Controls_Manager::SELECT,'options'=>['general'=>'General','egyptians'=>'Egyptian'],'default'=>'general']);
    $this->add_control('role',['label'=>'Price role','type'=>Elementor\Controls_Manager::SELECT,'options'=>['current'=>'Regular','sale'=>'Sale','previous'=>'Previous','final'=>'Final price'],'default'=>'current']);
    if($this->get_name()==='tour-rate-structured-currency')
     $this->add_control('mode',['label'=>'Representation','type'=>Elementor\Controls_Manager::SELECT,'options'=>['code'=>'USD / EUR','symbol'=>'$ / €'],'default'=>'code']);
   }
   public function get_value(array $options=[]){
    return tour_rate_structured_value(tour_rate_native_card_rate_id(),
     (string)($this->get_settings('occupancy')?:'single'),
     (string)($this->get_settings('audience')?:'general'),
     (string)($this->get_settings('role')?:'current'),
     $this->get_name()==='tour-rate-structured-currency'?'currency':'amount',
     (string)($this->get_settings('mode')?:'code'));
   }
  }
  class Tour_Rate_Structured_Amount_Tag extends Tour_Rate_Structured_Base_Tag {
   public function get_name(){return 'tour-rate-structured-amount';}
   public function get_title(){return 'Tour Rate · Amount';}
  }
  class Tour_Rate_Structured_Currency_Tag extends Tour_Rate_Structured_Base_Tag {
   public function get_name(){return 'tour-rate-structured-currency';}
   public function get_title(){return 'Tour Rate · Currency';}
  }
  class Tour_Rate_Structured_Discount_Tag extends Elementor\Core\DynamicTags\Data_Tag {
   public function get_name(){return 'tour-rate-structured-discount';}
   public function get_title(){return 'Tour Rate · Verified Discount';}
   public function get_group(){return 'tour_rate_native_display';}
   public function get_categories(){return [Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
   protected function register_controls(){
    $this->add_control('occupancy',['label'=>'Occupancy','type'=>Elementor\Controls_Manager::SELECT,
      'options'=>['single'=>'Single','double'=>'Double','triple'=>'Triple'],'default'=>'single']);
    $this->add_control('audience',['label'=>'Audience','type'=>Elementor\Controls_Manager::SELECT,
      'options'=>['general'=>'General','egyptians'=>'Egyptian'],'default'=>'general']);
   }
   public function get_value(array $options=[]){
    $rate=tour_rate_native_card_rate_id();
    if(!$rate)return '';
    $occ=(string)($this->get_settings('occupancy')?:'single');
    $audience=(string)($this->get_settings('audience')?:'general');
    if(!in_array($occ,['single','double','triple'],true)||
      !in_array($audience,['general','egyptians'],true))return '';
    $quote=pricing_audience_quote($rate,$audience,$occ);
    if(($quote['status']??'')!=='available'||empty($quote['discounted'])||
       ($quote['regular']??0)<=0||($quote['final']??0)<=0)return '';
    $percent=(int)round((($quote['regular']-$quote['final'])/$quote['regular'])*100);
    return $percent>0?sprintf('%d%% OFF',$percent):'';
   }
  }
 }
 $manager->register(new Tour_Rate_Structured_Amount_Tag());
 $manager->register(new Tour_Rate_Structured_Currency_Tag());
 $manager->register(new Tour_Rate_Structured_Discount_Tag());
},18);

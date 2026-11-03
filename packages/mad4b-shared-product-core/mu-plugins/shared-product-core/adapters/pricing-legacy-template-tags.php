<?php
/**
 * Shared Pricing bridge for the existing Elementor / JetEngine price layouts.
 * Read-only: a tag resolves the current Rate; it never changes or stores prices.
 */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;

function shared_product_legacy_rate_tag($rate_id=0,$audience='general',$occupancy='double',$role='final') {
 $rate_id=absint($rate_id);
 if(!$rate_id){
  // JetEngine listing context takes precedence over the single Tour page.
  if(function_exists('jet_engine') && isset(jet_engine()->listings->data) &&
     method_exists(jet_engine()->listings->data,'get_current_object_id')){
    $candidate=absint(jet_engine()->listings->data->get_current_object_id());
    if(get_post_type($candidate)==='tour-rates')$rate_id=$candidate;
  }
  if(!$rate_id)$rate_id=absint(get_the_ID());
 }
 if(get_post_type($rate_id)!=='tour-rates'||get_post_status($rate_id)!=='publish')return '';
 if(!in_array($audience,['general','egyptians'],true)||
    !in_array($occupancy,['single','double','triple'],true)||
    !in_array($role,['final','current','sale','previous'],true))return '';
 if(!function_exists('pricing_audience_quote')||!function_exists('pricing_audience_display'))return '';
 $quote=pricing_audience_quote($rate_id,$audience,$occupancy);
 if(($quote['status']??'')!=='available')return '';
 if($role!=='final' && function_exists('pricing_rate_field_html')){
  $field=($audience==='egyptians'?'egyptians_':'').$occupancy.'_price';
  return pricing_rate_field_html($rate_id,$field,$role);
 }
 $value=pricing_audience_display($quote,'final');
 return $value===''?'':'<span class="pricing-field-amount">'.$value.'</span>';
}

// Native Elementor Shortcode widget remains supported independently from
// Elementor Pro dynamic-tag availability. The shortcode is optional and does
// not replace any existing template.
add_shortcode('shared_price_legacy',static function($atts){
 $a=shortcode_atts(['rate_id'=>0,'audience'=>'general','occupancy'=>'double','role'=>'final'],$atts);
 return wp_kses_post(shared_product_legacy_rate_tag(
  absint($a['rate_id']),sanitize_key($a['audience']),
  sanitize_key($a['occupancy']),sanitize_key($a['role'])));
});

// JetEngine Dynamic Function option, using the same quote and formatting.
add_filter('jet-engine/dynamic-functions/functions-list',static function($functions){
 $functions['shared_price_rate']=[
  'label'=>__('Rate Price · Shared Pricing','woocommerce'),
  'type'=>'raw',
  'cb'=>static function($args){
   $args=is_array($args)?$args:[];
   return shared_product_legacy_rate_tag(
    absint($args['rate_id']??$args['post_id']??0),
    sanitize_key($args['audience']??'general'),
    sanitize_key($args['occupancy']??'double'),
    sanitize_key($args['role']??'final')
   );
  },
  'custom_settings'=>[
   'occupancy'=>['label'=>'Occupancy','type'=>'select',
      'options'=>['single'=>'Single','double'=>'Double','triple'=>'Triple'],
      'default'=>'double'],
   'audience'=>['label'=>'Audience','type'=>'select',
      'options'=>['general'=>'General','egyptians'=>'Egyptian'],
      'default'=>'general'],
   'role'=>['label'=>'Value','type'=>'select',
      'options'=>['final'=>'Final','current'=>'Regular only','sale'=>'Sale only','previous'=>'Previous strikethrough'],
      'default'=>'final']
  ]
 ];
 return $functions;
});

add_action('elementor/dynamic_tags/register',static function($manager){
 if(!class_exists('\Elementor\Core\DynamicTags\Data_Tag')||!class_exists('\Elementor\Modules\DynamicTags\Module'))return;
 if(!class_exists('Shared_Product_Pricing_Tag')){
  class Shared_Product_Pricing_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
   public function get_name(){return 'shared-rate-price';}
   public function get_title(){return esc_html__('Shared Pricing · Rate Price','woocommerce');}
   public function get_group(){return 'shared_pricing';}
   public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
   protected function register_controls(){
    $this->add_control('occupancy',['label'=>'Occupancy','type'=>\Elementor\Controls_Manager::SELECT,
       'options'=>['single'=>'Single','double'=>'Double','triple'=>'Triple'],'default'=>'double']);
    $this->add_control('audience',['label'=>'Audience','type'=>\Elementor\Controls_Manager::SELECT,
       'options'=>['general'=>'General','egyptians'=>'Egyptian'],'default'=>'general']);
    $this->add_control('role',['label'=>'Price display','type'=>\Elementor\Controls_Manager::SELECT,
       'options'=>['final'=>'Final','current'=>'Current non-sale','sale'=>'Sale only','previous'=>'Previous (strikethrough)'],
       'default'=>'final']);
   }
   public function get_value(array $options=[]){
    return shared_product_legacy_rate_tag(0,
      (string)($this->get_settings('audience')?:'general'),
      (string)($this->get_settings('occupancy')?:'double'),
      (string)($this->get_settings('role')?:'final'));
   }
  }
 }
 $manager->register_group('shared_pricing',['title'=>__('Shared Pricing','woocommerce')]);
 $manager->register(new Shared_Product_Pricing_Tag());
},15);

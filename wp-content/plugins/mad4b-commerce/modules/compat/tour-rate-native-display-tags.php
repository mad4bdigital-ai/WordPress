<?php
/** Native Elementor dynamic tags for Tour Rate presentation, Local Lab only.
 * Read-only; uses the existing Pricing Registry rather than shortcode HTML.
 */
defined('ABSPATH') || exit;
if ((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)) return;

function tour_rate_native_card_rate_id(): int {
 if(function_exists('jet_engine') && isset(jet_engine()->listings->data)
    && method_exists(jet_engine()->listings->data,'get_current_object_id')){
  $id=absint(jet_engine()->listings->data->get_current_object_id());
  if(get_post_type($id)==='tour-rates')return $id;
 }
 $id=absint(get_the_ID());
 return get_post_type($id)==='tour-rates'?$id:0;
}

function tour_rate_native_card_price_value($rate_id,$occupancy='single',$audience='general',$role='current'): string {
 $rate_id=absint($rate_id);
 if(!$rate_id||!in_array($occupancy,['single','double','triple'],true)
   ||!in_array($audience,['general','egyptians'],true)
   ||!in_array($role,['current','sale','previous'],true)
   || !function_exists('pricing_audience_quote') || !function_exists('pricing_audience_display'))return '';
 $quote=pricing_audience_quote($rate_id,$audience,$occupancy);
 if(($quote['status']??'')==='available'){
  if($role==='current'&&!empty($quote['discounted']))return '';
  if($role!=='current'&&empty($quote['discounted']))return '';
  $raw=pricing_audience_display($quote,$role==='sale'?'sale':'regular');
  if($raw==='')return $role==='current'?__('Price on request','woocommerce'):'';
  // This is a TEXT-category Elementor tag. It must not inject HTML.
  return trim(preg_replace('/\s+/u',' ',html_entity_decode(wp_strip_all_tags($raw),ENT_QUOTES|ENT_HTML5,'UTF-8')));
 }
 // The Tour Rate listing query already excludes unqualified Rates.
 // A stale/embedded view must not show a label or a future-price message
 // when the underlying Pricing Registry rejects the Rate.
 return '';
}
add_action('elementor/dynamic_tags/register',static function($manager){
 if(!class_exists('\Elementor\Core\DynamicTags\Data_Tag')
   ||!class_exists('\Elementor\Modules\DynamicTags\Module'))return;
 if(!class_exists('Tour_Rate_Native_Card_Price_Tag')){
  class Tour_Rate_Native_Card_Price_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
   public function get_name(){return 'tour-rate-native-card-price';}
   public function get_title(){return __('Tour Rate · Native Price','woocommerce');}
   public function get_group(){return 'tour_rate_native_display';}
   public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
   protected function register_controls(){
    $this->add_control('occupancy',['label'=>'Occupancy','type'=>\Elementor\Controls_Manager::SELECT,
     'options'=>['single'=>'Single','double'=>'Double','triple'=>'Triple'],'default'=>'single']);
    $this->add_control('audience',['label'=>'Audience','type'=>\Elementor\Controls_Manager::SELECT,
     'options'=>['general'=>'General','egyptians'=>'Egyptian'],'default'=>'general']);
    $this->add_control('role',['label'=>'Display slot','type'=>\Elementor\Controls_Manager::SELECT,
     'options'=>['current'=>'Regular or availability reason','sale'=>'Sale','previous'=>'Previous regular'],
     'default'=>'current']);
   }
   public function get_value(array $options=[]){
    return tour_rate_native_card_price_value(tour_rate_native_card_rate_id(),
     (string)($this->get_settings('occupancy')?:'single'),
     (string)($this->get_settings('audience')?:'general'),
     (string)($this->get_settings('role')?:'current'));
   }
  }
 }
 $manager->register_group('tour_rate_native_display',['title'=>'Tour Rate Presentation']);
 $manager->register(new Tour_Rate_Native_Card_Price_Tag());
},16);
add_action('wp_enqueue_scripts',static function(){
 if(!is_singular('tours-and-activities'))return;
 $file=WPMU_PLUGIN_DIR.'/assets/tour-rate-native-display.css';
 wp_enqueue_style('tour-rate-native-display',
  content_url('mu-plugins/assets/tour-rate-native-display.css'),
  [],(string)filemtime($file));
},20);
/** Native Elementor action Widget: preserves the existing guarded
 * renderer and delegated JS rate-selection events without a shortcode.
 */
add_action('elementor/widgets/register',static function($widgets){
 if(!class_exists('Elementor\Widget_Base')
    ||!function_exists('shared_product_rate_selection_actions_markup'))return;
 if(!class_exists('Tour_Rate_Selection_Actions_Widget')){
  class Tour_Rate_Selection_Actions_Widget extends Elementor\Widget_Base {
   public function get_name(){return 'tour-rate-select-actions';}
   public function get_title(){return __('Tour Rate · Select Actions','woocommerce');}
   public function get_icon(){return 'eicon-button';}
   public function get_categories(){return ['general'];}
   protected function render(){echo shared_product_rate_selection_actions_markup();}
  }
 }
 $widgets->register(new Tour_Rate_Selection_Actions_Widget());
},20);
/** The second authored Tour Rate section is for an explicit price unit
 * mode. Without that metadata, it showed unsupported blank/duplicate
 * prices and orphaned term words. Mark only the unsupported section.
 */
add_action('elementor/frontend/container/before_render',static function($element){
 if($element->get_id()!=='669f492')return;
 $rate=tour_rate_native_card_rate_id();
 if($rate && trim((string)get_post_meta($rate,'package_1_prices_unit_type',true))===''){
   $element->add_render_attribute('_wrapper','class','tour-native-extra-pricing-unassigned');
 }
},14);

/** Restore package-specific Elementor styling from the canonical
 * Package Level term metadata, never from the translated badge/title.
 */
add_action('elementor/frontend/container/before_render',static function($element){
 $id=$element->get_id();
 if(!in_array($id,['958db7a','12c9aeb'],true))return;
 $rate=tour_rate_native_card_rate_id();
 if(!$rate)return;
 if($id==='12c9aeb'){
  if((string)get_post_meta($rate,'has_egyptians_price',true)!=='1')
    $element->add_render_attribute('_wrapper','class','tour-rate-egyptians-unconfigured');
  return;
 }
 $term_id=absint(get_post_meta($rate,'related_package_term_id',true));
 $term=$term_id?get_term($term_id,'package_levels'):null;
 if(!$term instanceof WP_Term)return;
 $accent=(string)get_term_meta($term_id,'package_color',true);
 if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$accent))return;
 $badge=trim((string)get_term_meta($term_id,'package_badge',true));
 $element->add_render_attribute('_wrapper','class','tour-native-package-tier');
 $hasEgyptians=(string)get_post_meta($rate,'has_egyptians_price',true)==='1';
 $element->add_render_attribute('_wrapper','data-tour-has-egyptians',$hasEgyptians?'1':'0');
 $anyEligible=false;
 foreach(['single','double','triple'] as $unit){
  $q=pricing_audience_quote($rate,'general',$unit);
  if(($q['status']??'')==='available'&&pricing_audience_display($q,'final')!==''){
    $anyEligible=true;break;
  }
 }
 if(!$anyEligible)$element->add_render_attribute('_wrapper','class','tour-rate-native-ineligible');
 $element->add_render_attribute('_wrapper','data-tour-package',sanitize_title($term->slug));
 $element->add_render_attribute('_wrapper','style','--tour-rate-package-accent:'.$accent.';');
 if($badge!=='')$element->add_render_attribute('_wrapper','data-package-badge',sanitize_text_field($badge));
},22);

// Companion native Elementor data tags / widget for multi-part pricing and package info.
require_once __DIR__.'/assets/tour-rate-structured-tags.inc.php';
require_once __DIR__.'/assets/tour-rate-package-disclosure.inc.php';
add_action('wp_enqueue_scripts',static function(){
 if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)||!is_singular('tours-and-activities'))return;
 $file=dirname(__DIR__,2).'/assets/tour-rate-centered-popover.js';
 wp_enqueue_script('tour-rate-centered-popover',
   plugins_url('assets/tour-rate-centered-popover.js',dirname(__DIR__,2).'/mad4b-commerce.php'),
   [],(string)filemtime($file),true);
},22);


// Editable native Elementor widget using the exact original price SVG path.
require_once __DIR__.'/assets/tour-rate-original-price-svg.inc.php';

// Matching native occupancy labels for each Tour Rate pricing row.
require_once __DIR__.'/assets/tour-rate-occupancy-label.inc.php';

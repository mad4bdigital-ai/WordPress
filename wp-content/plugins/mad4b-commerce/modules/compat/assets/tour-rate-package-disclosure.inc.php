<?php
/** Native editable Elementor package disclosure: only existing term metadata. */
defined('ABSPATH')||exit;
if(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)return;
add_action('elementor/widgets/register',static function($widgets){
 if(!class_exists('Elementor\\Widget_Base'))return;
 if(!class_exists('Tour_Rate_Term_Details_Widget')){
  class Tour_Rate_Term_Details_Widget extends Elementor\Widget_Base {
   public function get_name(){return 'tour-rate-term-details';}
   public function get_title(){return 'Tour Rate · Package info';}
   public function get_icon(){return 'eicon-info-circle';}
   public function get_categories(){return ['general'];}
   protected function register_controls(){
    $this->add_control('trigger_symbol',['label'=>'Info symbol','type'=>Elementor\Controls_Manager::TEXT,'default'=>'!']);
   }
   protected function render(){
    $rate=tour_rate_native_card_rate_id();
    if(!$rate)return;
    $term_id=absint(get_post_meta($rate,'related_package_term_id',true));
    if(!$term_id)return;
    $lang=(string)apply_filters('wpml_current_language','en');
    // The stored relationship is canonical. Resolve the localized term + metadata
    // for display only; never update the Rate's package ID.
    if($lang && $lang!=='en'){
      $translated=(int)apply_filters('wpml_object_id',$term_id,'package_levels',false,$lang);
      if($translated>0)$term_id=$translated;
    }
    $term=get_term($term_id,'package_levels');
    if(!$term instanceof WP_Term)return;
    $badge=trim((string)get_term_meta($term_id,'package_badge',true));
    $subtitle=trim((string)get_term_meta($term_id,'package_sub_title',true));
    $description=trim(wp_strip_all_tags((string)$term->description));
    $notes=(string)get_term_meta($term_id,'package_notes',true);
    $lines=array_values(array_filter(array_map('trim',preg_split('/\\r\\n|\\r|\\n/u',$notes)?:[])));
    if($badge===''&&$subtitle===''&&$description===''&&!$lines)return;
    $name=sprintf(__('Show %s package details','woocommerce'),$term->name);
    $symbol=trim(wp_strip_all_tags((string)$this->get_settings('trigger_symbol')));
    if(!$symbol)$symbol='!';
    echo '<details class="tour-rate-term-details" data-term-id="'.esc_attr($term_id).'">';
    echo '<summary class="tour-rate-term-details__button" aria-label="'.esc_attr($name).'" title="'.esc_attr($name).'"><span aria-hidden="true">'.esc_html($symbol).'</span></summary>';
    echo '<div class="tour-rate-term-details__content">';
    echo '<strong>'.esc_html($term->name).'</strong>';
    if($badge!=='')echo '<span class="tour-rate-term-details__badge">'.esc_html($badge).'</span>';
    if($subtitle!=='')echo '<p>'.esc_html($subtitle).'</p>';
    if($description!=='')echo '<p>'.esc_html($description).'</p>';
    if($lines){echo '<ul>';foreach($lines as$line)echo '<li>'.esc_html($line).'</li>';echo '</ul>';}
    echo '</div></details>';
   }
  }
 }
 $widgets->register(new Tour_Rate_Term_Details_Widget());
},21);

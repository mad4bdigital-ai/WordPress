<?php
/** Original Tour Rate price icon, as supplied by the original JetEngine
 * Listing Dynamic Field. Its responsive dimensions/color are controlled
 * from the native Elementor Style panel. No PNG/icon replacement.
 */
defined('ABSPATH') || exit;
if(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)return;
add_action('elementor/widgets/register',static function($widgets){
 if(!class_exists('Elementor\Widget_Base'))return;
 if(!class_exists('Tour_Rate_Original_Price_Icon')){
  class Tour_Rate_Original_Price_Icon extends Elementor\Widget_Base{
   public function get_name(){return 'tour-rate-original-price-icon';}
   public function get_title(){return 'Tour Rate · Original SVG Price Icon';}
   public function get_icon(){return 'eicon-icon-box';}
   public function get_categories(){return ['general'];}
   protected function register_controls(){
    $this->start_controls_section('icon_style',['label'=>'SVG Appearance','tab'=>Elementor\Controls_Manager::TAB_STYLE]);
    $this->add_responsive_control('svg_size',[
      'label'=>'SVG width','type'=>Elementor\Controls_Manager::SLIDER,
      'size_units'=>['px'],'range'=>['px'=>['min'=>12,'max'=>80,'step'=>1]],
      'default'=>['size'=>25,'unit'=>'px'],
      'selectors'=>['{{WRAPPER}} .tour-rate-original-price-svg svg'=>'width:{{SIZE}}{{UNIT}};height:auto;']
    ]);
    $this->add_control('svg_stroke_color',[
      'label'=>'Stroke color','type'=>Elementor\Controls_Manager::COLOR,
      'default'=>'#0A2B4B',
      'selectors'=>['{{WRAPPER}} .tour-rate-original-price-svg'=>'color:{{VALUE}};']
    ]);
    $this->end_controls_section();
   }
   protected function render(){
    echo '<span class="tour-rate-original-price-svg" aria-hidden="true">';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="25" height="24" viewBox="0 0 25 24" fill="none" focusable="false">';
    echo '<path d="M12.5 17C12.5 19.7614 14.7386 22 17.5 22C20.2614 22 22.5 19.7614 22.5 17C22.5 14.2386 20.2614 12 17.5 12C14.7386 12 12.5 14.2386 12.5 17ZM12.5 17C12.5 15.8742 12.8721 14.8353 13.5 13.9995V5M12.5 17C12.5 17.8254 12.7 18.604 13.0541 19.2901C12.2117 20.0018 10.2658 20.5 8 20.5C4.96243 20.5 2.5 19.6046 2.5 18.5V5M13.5 5C13.5 6.10457 11.0376 7 8 7C4.96243 7 2.5 6.10457 2.5 5M13.5 5C13.5 3.89543 11.0376 3 8 3C4.96243 3 2.5 3.89543 2.5 5M2.5 14C2.5 15.1046 4.96243 16 8 16C10.189 16 12.0793 15.535 12.9646 14.8618M13.5 9.5C13.5 10.6046 11.0376 11.5 8 11.5C4.96243 11.5 2.5 10.6046 2.5 9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>';
    echo '</svg></span>';
   }
  }
 }
 $widgets->register(new Tour_Rate_Original_Price_Icon());
},21);

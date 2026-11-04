<?php
/** Local Lab native Elementor dynamic occupancy label, sourced from the
 * same eligible Tour Rate quote used by the adjacent pricing components.
 */
defined('ABSPATH') || exit;
if(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)return;
function tour_rate_dynamic_occupancy_label(int $rate_id,string $occupancy,string $audience='general'): string {
 if(!in_array($occupancy,['triple','double','single'],true)||!in_array($audience,['general','egyptians'],true))return '';
 if(get_post_type($rate_id)!=='tour-rates'||!function_exists('pricing_audience_quote'))return '';
 $quote=pricing_audience_quote($rate_id,$audience,$occupancy);
 if(($quote['status']??null)!=='available'||!function_exists('pricing_audience_display')||pricing_audience_display($quote,'final')==='')return '';
 $title=ucfirst($occupancy);
 $unit=trim((string)get_post_meta($rate_id,'unit_type',true));
 if($unit==='')$unit=trim((string)get_post_meta($rate_id,'package_1_prices_unit_type',true));
 switch($unit){
 case 'per_person':return sprintf(__('Per person in %s','woocommerce'),$title);
 case 'per_room':return sprintf(__('Per room — %s occupancy','woocommerce'),$title);
 case 'per_cabin':return sprintf(__('Per cabin — %s occupancy','woocommerce'),$title);
 case 'per_unit':return sprintf(__('Per unit — %s occupancy','woocommerce'),$title);
 default:return sprintf(__('%s occupancy','woocommerce'),$title);
 }
}
add_action('elementor/dynamic_tags/register',static function($manager){
 if(!class_exists('Elementor\Core\DynamicTags\Data_Tag'))return;
 if(!class_exists('Tour_Rate_Occupancy_Label_Tag')){
  class Tour_Rate_Occupancy_Label_Tag extends Elementor\Core\DynamicTags\Data_Tag{
   public function get_name(){return 'tour-rate-structured-occupancy-label';}
   public function get_title(){return 'Tour Rate · Occupancy label';}
   public function get_group(){return 'tour_rate_native_display';}
   public function get_categories(){return [Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
   protected function register_controls(){
    $this->add_control('occupancy',['label'=>'Occupancy','type'=>Elementor\Controls_Manager::SELECT,
     'options'=>['triple'=>'Triple','double'=>'Double','single'=>'Single'],'default'=>'single']);
    $this->add_control('audience',['label'=>'Audience','type'=>Elementor\Controls_Manager::SELECT,
     'options'=>['general'=>'General','egyptians'=>'Egyptian'],'default'=>'general']);
   }
   public function get_value(array $options=[]){
    return tour_rate_dynamic_occupancy_label(tour_rate_native_card_rate_id(),
      (string)($this->get_settings('occupancy')?:'single'),
      (string)($this->get_settings('audience')?:'general'));
   }
  }
 }
 $manager->register(new Tour_Rate_Occupancy_Label_Tag());
},19);

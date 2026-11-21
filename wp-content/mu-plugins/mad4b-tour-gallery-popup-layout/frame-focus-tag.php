<?php
/** Native Elementor Dynamic Tag: bound JetEngine per-tour photo position / fit. */
defined('ABSPATH')||exit;
final class MAD4B_JetEngine_Photo_Focus_Dynamic_Tag extends \Elementor\Core\DynamicTags\Tag {
 public function get_name(){return 'mad4b-jetengine-frame-focus';}
 public function get_title(){return 'MAD4B · JetEngine photo focal / fit';}
 public function get_group(){return 'mad4b-jetengine-media';}
 public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
 protected function register_controls(){
  $options=[];foreach(MAD4B_JetEngine_Elementor_Frame_Focus_V2::slots() as $slot)
   $options[$slot]=str_replace('_',' ',ucwords($slot,'_'));
  $this->add_control('slot',['label'=>'Image slot from Elementor','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$options]);
  $this->add_control('property',['label'=>'Dynamic property','type'=>\Elementor\Controls_Manager::SELECT,
   'options'=>['position'=>'Background position (X/Y)','fit'=>'Background size (cover/contain)'],'default'=>'position']);
 }
 public function get_value(array $options=[]){
  return MAD4B_JetEngine_Elementor_Frame_Focus_V2::value((string)$this->get_settings('slot'),(string)$this->get_settings('property'));
 }
 public function render(){echo esc_html($this->get_value());}
}

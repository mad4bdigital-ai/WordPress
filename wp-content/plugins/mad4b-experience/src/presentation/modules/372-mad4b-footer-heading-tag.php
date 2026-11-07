<?php
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Footer_Heading_Dynamic_Tag',false)) {
    class MAD4B_Footer_Heading_Dynamic_Tag extends \Elementor\Core\DynamicTags\Tag {
        public function get_name(){return 'mad4b-footer-heading';}
        public function get_title(){return 'MAD4B · Footer heading (JetEngine/WPML)';}
        public function get_group(){return 'mad4b-jetengine-options';}
        public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
        protected function register_controls(){
            $this->add_control('field',['label'=>'Footer heading','type'=>\Elementor\Controls_Manager::SELECT,
              'options'=>['legal'=>'Legal','payments'=>'Payments','languages'=>'Languages']]);
        }
        public function get_value(array $options=[]){return MAD4B_Footer_LookFeel::heading((string)$this->get_settings('field'));}
        public function render(){echo esc_html($this->get_value());}
    }
}

<?php
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Trust_Support_Text_Tag',false)) {
    class MAD4B_Trust_Support_Text_Tag extends \Elementor\Core\DynamicTags\Tag {
        public function get_name(){return 'mad4b-ux-lookfeel-text';}
        public function get_title(){return 'MAD4B · Trust & Support · localized JetEngine option';}
        public function get_group(){return 'mad4b-jetengine-options';}
        public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
        protected function register_controls(){
            $this->add_control('field',[
                'label'=>'Trust / Support field',
                'type'=>\Elementor\Controls_Manager::SELECT,
                'options'=>MAD4B_Trust_Support_Dynamic::names()
            ]);
        }
        public function get_value(array $options=[]){
            return MAD4B_Trust_Support_Dynamic::text((string)$this->get_settings('field'));
        }
        public function render(){echo wp_kses_post($this->get_value());}
    }
}

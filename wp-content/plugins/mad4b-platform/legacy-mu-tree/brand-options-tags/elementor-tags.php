<?php
/** Loaded after Elementor Dynamic Tags start. */
defined('ABSPATH')||exit;

 class MAD4B_JetEngine_Option_Text_Tag extends \Elementor\Core\DynamicTags\Tag {
    public function get_name(){return 'mad4b-jetengine-option-text';}
    public function get_title(){return 'MAD4B · JetEngine Option / Translated Copy';}
    public function get_group(){return 'mad4b-jetengine-options';}
    public function get_categories(){return [
        \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,
        \Elementor\Modules\DynamicTags\Module::URL_CATEGORY,
        \Elementor\Modules\DynamicTags\Module::COLOR_CATEGORY
    ];}
    protected function register_controls(){
        $this->add_control('option_ref',[
            'label'=>'JetEngine Options Page → Field',
            'type'=>\Elementor\Controls_Manager::SELECT,
            'groups'=>MAD4B_JetEngine_Brand_Dynamic_Tag_Bridge::choices('plain')
        ]);
    }
    public function get_value(array $options=[]){
        return MAD4B_JetEngine_Brand_Dynamic_Tag_Bridge::resolve(
            (string)$this->get_settings('option_ref'),'plain'
        );
    }
    public function render(){echo wp_kses_post((string)$this->get_value());}
 }
 class MAD4B_JetEngine_Option_Image_Tag extends \Elementor\Core\DynamicTags\Tag {
    public function get_name(){return 'mad4b-jetengine-option-image';}
    public function get_title(){return 'MAD4B · JetEngine Option Image';}
    public function get_group(){return 'mad4b-jetengine-options';}
    public function get_categories(){return [
        \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY
    ];}
    protected function register_controls(){
        $this->add_control('option_ref',[
            'label'=>'JetEngine Options Page → Media field',
            'type'=>\Elementor\Controls_Manager::SELECT,
            'groups'=>MAD4B_JetEngine_Brand_Dynamic_Tag_Bridge::choices('media')
        ]);
    }
    public function get_value(array $options=[]){
        return MAD4B_JetEngine_Brand_Dynamic_Tag_Bridge::resolve(
            (string)$this->get_settings('option_ref'),'media'
        );
    }
 }

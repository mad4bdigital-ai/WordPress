<?php
defined('ABSPATH')||exit;
if(!class_exists('\Elementor\Core\DynamicTags\Tag'))return;
final class MAD4B_Tenant_Elementor_Text_Tag extends \Elementor\Core\DynamicTags\Tag {
    public function get_name(){return 'mad4b-effective-content';}
    public function get_title(){return 'MAD4B Effective Content';}
    public function get_group(){return 'post';}
    public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
    protected function register_controls(){
        $this->add_control('mad4b_field',[
            'label'=>'Effective Field','type'=>\Elementor\Controls_Manager::SELECT,
            'default'=>'title','options'=>[
                'title'=>'Title','excerpt'=>'Excerpt',
                'seo_title'=>'SEO Title','seo_description'=>'SEO Description',
                'seo_focus_keyword'=>'SEO Focus Keyword',
                'presentation_variant'=>'Card / Accordion layout'
            ]
        ]);
    }
    public function render(){
        $id=(int)get_the_ID();
        $field=(string)$this->get_settings('mad4b_field');
        $v=MAD4B_Tenant_Consumers::field($id,$field,false);
        if($v!==null)echo esc_html($v);
    }
}

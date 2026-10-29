<?php
namespace DynamicFilterSEOBridge\Elementor\DynamicTags;

use DynamicFilterSEOBridge\Context\ContextValueResolver;
use DynamicFilterSEOBridge\Runtime\BootGuard;

/** Elementor IMAGE_CATEGORY source backed by the same context as shortcodes. */
final class ContextImageTag extends \Elementor\Core\DynamicTags\Data_Tag {
    public function get_name(){return 'dfsb-context-image';}
    public function get_title(){return 'Context Source Image';}
    public function get_group(){return 'dfsb-context';}
    public function get_categories(){return array(\Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY);}
    protected function register_controls(){
        $this->add_control('preview_scope',array('label'=>'Editor Preview Source',
            'type'=>\Elementor\Controls_Manager::SELECT,
            'options'=>array('current'=>'Current Page','post'=>'Published Post / CPT',
                'taxonomy'=>'Taxonomy Term','archive'=>'CPT Archive',
                'posts_home'=>'Blog Home','shop'=>'Woo Shop'),
            'default'=>'current'));
        $this->add_control('preview_id',array('label'=>'Editor Preview Object ID',
            'type'=>\Elementor\Controls_Manager::NUMBER,'default'=>0));
        $this->add_control('preview_type',array('label'=>'CPT / Taxonomy key',
            'type'=>\Elementor\Controls_Manager::TEXT,'default'=>''));
    }
    public function get_value(array $options=array()){
        $empty=array('id'=>0,'url'=>'');
        $editor=(is_admin() || wp_doing_ajax()) && current_user_can('manage_options');
        if(BootGuard::shouldHold() && !$editor)return $empty;
        $scope=$editor?sanitize_key((string)$this->get_settings('preview_scope')):'current';
        $id=$editor?absint($this->get_settings('preview_id')):0;
        $type=$editor?sanitize_key((string)$this->get_settings('preview_type')):'';
        return ContextValueResolver::image(ContextValueResolver::context($scope?:'current',$id,$type));
    }
}

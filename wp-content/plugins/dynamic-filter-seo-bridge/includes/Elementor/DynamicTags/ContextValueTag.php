<?php
namespace DynamicFilterSEOBridge\Elementor\DynamicTags;

use DynamicFilterSEOBridge\Context\ContextValueResolver;
use DynamicFilterSEOBridge\Runtime\BootGuard;

/** Same source contract as [dfsb_context_value] and admin REST preview. */
final class ContextValueTag extends \Elementor\Core\DynamicTags\Tag {
    public function get_name(){return 'dfsb-context-value';}
    public function get_title(){return 'Context Source Value';}
    public function get_group(){return 'dfsb-context';}
    public function get_categories(){return array('text','url');}
    protected function register_controls(){
        $options=array();
        foreach(ContextValueResolver::fields() as $field){$options[$field]=ucwords(str_replace('_',' ',$field));}
        $this->add_control('field',array('label'=>'Source Field',
            'type'=>\Elementor\Controls_Manager::SELECT,
            'options'=>$options,'default'=>'title'));
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
        // An editor preview target never becomes a persisted frontend source.
        // Under Safe Boot, even a privileged frontend visitor receives no value.
        $editor=(is_admin() || wp_doing_ajax()) && current_user_can('manage_options');
        if(BootGuard::shouldHold() && !$editor)return '';
        $field=sanitize_key((string)$this->get_settings('field'));
        $scope=$editor?sanitize_key((string)$this->get_settings('preview_scope')):'current';
        $id=$editor?absint($this->get_settings('preview_id')):0;
        $type=$editor?sanitize_key((string)$this->get_settings('preview_type')):'';
        $context=ContextValueResolver::context($scope?:'current',$id,$type);
        return ContextValueResolver::value($context,$field);
    }
    public function render(){
        $field=sanitize_key((string)$this->get_settings('field'));
        $value=(string)$this->get_value();
        echo $field==='image_url'?esc_url($value):esc_html($value);
    }
}

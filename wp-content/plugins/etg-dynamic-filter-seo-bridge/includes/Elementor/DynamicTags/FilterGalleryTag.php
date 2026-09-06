<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class FilterGalleryTag extends \Elementor\Core\DynamicTags\Data_Tag {
    use PreviewContextTrait;

    public function get_name(){ return 'etg-filter-gallery'; }
    public function get_title(){ return 'ETG Filter Gallery'; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ return array(\Elementor\Modules\DynamicTags\Module::GALLERY_CATEGORY); }

    protected function register_controls(){
        $roles = DynamicTagRuntime::roleOptions();
        $modes = array('combined'=>'Combined','priority'=>'Profile Priority');
        foreach ($roles as $role => $label) { $modes[$role] = $label . ' only'; }
        $this->add_control('mode', array('label'=>'Gallery Source','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$modes,'default'=>'combined'));
        $this->add_control('limit', array('label'=>'Maximum Images','type'=>\Elementor\Controls_Manager::NUMBER,'min'=>1,'max'=>30,'default'=>9));
        $this->etgRegisterPreviewControl();
    }

    public function get_value(array $options = array()) {
        $resolver = DynamicTagRuntime::resolver();
        return $resolver ? $resolver->gallery((string) $this->get_settings('mode'), $this->etgPreviewContext(), (int) $this->get_settings('limit')) : array();
    }
}

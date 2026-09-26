<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class FilterImageUrlTag extends \Elementor\Core\DynamicTags\Tag {
    use PreviewContextTrait;

    public function get_name(){ return 'etg-filter-image-url'; }
    public function get_title(){ return 'ETG Filter Image URL'; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ return array('url','text'); }

    protected function register_controls(){
        $roles = DynamicTagRuntime::roleOptions();
        $modes = array('priority'=>'Profile Priority');
        foreach ($roles as $role => $label) { $modes[$role] = $label . ' only'; }
        $this->add_control('mode', array('label'=>'Image Source','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$modes,'default'=>'priority'));
        $this->etgRegisterPreviewControl();
    }

    public function get_value(array $options = array()) {
        $resolver = DynamicTagRuntime::resolver();
        if (!$resolver) { return ''; }
        $image = $resolver->image((string) $this->get_settings('mode'), $this->etgPreviewContext());
        return (string) ($image['url'] ?? '');
    }

    public function render(){ echo esc_url((string) $this->get_value()); }
}

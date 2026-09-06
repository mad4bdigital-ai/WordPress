<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class FilterImageTag extends \Elementor\Core\DynamicTags\Data_Tag {
    use PreviewContextTrait;

    public function get_name(){ return 'etg-filter-image'; }
    public function get_title(){ return 'ETG Filter Image'; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ return array(\Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY); }

    protected function register_controls(){
        $roles = DynamicTagRuntime::roleOptions();
        $modes = array('priority'=>'Profile Priority');
        foreach ($roles as $role => $label) { $modes[$role] = $label . ' only'; }
        $this->add_control('mode', array('label'=>'Image Source','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$modes,'default'=>'priority','description'=>'Priority follows governed profile gallery priority. Role-specific modes use only the selected filter role.'));
        $this->etgRegisterPreviewControl();
    }

    public function get_value(array $options = array()) {
        $resolver = DynamicTagRuntime::resolver();
        return $resolver ? $resolver->image((string) $this->get_settings('mode'), $this->etgPreviewContext()) : array('id'=>0,'url'=>'');
    }
}

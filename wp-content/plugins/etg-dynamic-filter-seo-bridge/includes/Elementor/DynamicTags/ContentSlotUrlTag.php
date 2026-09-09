<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class ContentSlotUrlTag extends \Elementor\Core\DynamicTags\Tag {
    use PreviewContextTrait;

    public function get_name(){ return 'etg-content-slot-url'; }
    public function get_title(){ return 'ETG Content Slot URL'; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ return array('url'); }

    protected function register_controls(){
        $options = array(''=>'— Select URL Slot —') + DynamicTagRuntime::slotOptionsByTypes(array('url'));
        $this->add_control('slot_id', array('label'=>'URL Content Slot','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$options,'default'=>'','description'=>'URL slots resolve at Elementor render time. Live AJAX host-attribute mutation is intentionally not implied.'));
        $this->etgRegisterPreviewControl();
    }

    public function get_value(array $options = array()) {
        $id=sanitize_key((string)$this->get_settings('slot_id'));if(''===$id){return'';}
        $resolver=DynamicTagRuntime::resolver();return$resolver?(string)$resolver->slot($id,$this->etgPreviewContext()):'';
    }

    public function render(){echo esc_url((string)$this->get_value());}
}

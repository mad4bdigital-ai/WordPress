<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class InventoryUrlTag extends \Elementor\Core\DynamicTags\Tag {
    use PreviewContextTrait;

    public function get_name(){ return 'etg-inventory-url'; }
    public function get_title(){ return 'ETG Inventory URL'; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ return array('url'); }

    protected function register_controls(){
        $options=DynamicTagRuntime::tokenOptionsByTypes(array('url'));
        $this->add_control('token',array('label'=>'Inventory URL Token','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$options,'default'=>$options?(string)key($options):'','description'=>'URL tokens resolve at Elementor render time. Live AJAX URL mutation is not advertised by this tag.'));
        $this->etgRegisterPreviewControl();
    }

    public function get_value(array $options = array()) {
        $token=(string)$this->get_settings('token');if(''===$token){return'';}
        $resolver=DynamicTagRuntime::resolver();return$resolver?(string)$resolver->value($token,$this->etgPreviewContext()):'';
    }

    public function render(){echo esc_url((string)$this->get_value());}
}

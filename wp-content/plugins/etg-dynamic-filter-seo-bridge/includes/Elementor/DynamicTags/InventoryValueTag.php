<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class InventoryValueTag extends \Elementor\Core\DynamicTags\Tag {
    use PreviewContextTrait;

    public function get_name(){ return 'etg-inventory-value'; }
    public function get_title(){ return 'ETG Inventory Value'; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ return array('text','url'); }

    protected function register_controls(){
        $options = DynamicTagRuntime::tokenOptions();
        $this->add_control('token', array(
            'label'=>'Inventory Token',
            'type'=>\Elementor\Controls_Manager::SELECT,
            'options'=>$options,
            'default'=>isset($options['title'])?'title':(string) key($options),
        ));
        $this->etgRegisterPreviewControl();
    }

    public function get_value(array $options = array()) {
        $resolver = DynamicTagRuntime::resolver();
        return $resolver ? $resolver->value((string) $this->get_settings('token'), $this->etgPreviewContext()) : '';
    }

    public function render(){
        $token = (string) $this->get_settings('token');
        $value = $this->get_value();
        $types = DynamicTagRuntime::tokenTypes();
        $type = (string) ($types[$token] ?? 'text');
        if ('url' === $type || 'image' === $type) { echo esc_html((string) $value); return; }
        $open = '<span class="etg-dfsb-live-value" data-etg-dfsb-token="' . esc_attr($token) . '">';
        if ('html' === $type) { echo $open . wp_kses_post((string) $value) . '</span>'; }
        else { echo $open . esc_html((string) $value) . '</span>'; }
    }
}

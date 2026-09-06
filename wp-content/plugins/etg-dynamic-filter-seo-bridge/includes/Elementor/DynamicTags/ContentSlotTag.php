<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class ContentSlotTag extends \Elementor\Core\DynamicTags\Tag {
    use PreviewContextTrait;
    use LiveBindingTrait;

    public function get_name(){ return 'etg-dynamic-content-slot'; }
    public function get_title(){ return 'ETG Dynamic Content Slot'; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ return array('text','url'); }

    protected function register_controls(){
        $options = DynamicTagRuntime::slotOptions();
        $this->add_control('slot_id', array(
            'label'=>'Content Slot',
            'type'=>\Elementor\Controls_Manager::SELECT,
            'options'=>$options,
            'default'=>isset($options['hero_title'])?'hero_title':($options?(string) key($options):''),
            'description'=>'Built-in slots are available immediately. Custom slots are managed under Settings → ETG Dynamic Content.',
        ));
        $this->etgRegisterPreviewControl();
        $this->etgRegisterLiveBindingControls();
    }

    public function get_value(array $options = array()) {
        $resolver = DynamicTagRuntime::resolver();
        return $resolver ? $resolver->slot((string) $this->get_settings('slot_id'), $this->etgPreviewContext()) : '';
    }

    public function render(){
        $id = (string) $this->get_settings('slot_id');
        $resolver = DynamicTagRuntime::resolver();
        if (!$resolver) { return; }
        $value = $resolver->slot($id, $this->etgPreviewContext());
        $type = $resolver->slotType($id);
        if ('url' === $type || 'image' === $type) { echo esc_html((string) $value); return; }
        $display=$this->etgValueOrFallback($value);
        if(!$this->etgLiveEnabled()){echo 'html'===$type?wp_kses_post($display):esc_html($display);return;}
        $open = '<span class="etg-dfsb-live-slot"' . $this->etgBindingAttributes('slot',$id,(string)$this->get_settings('fallback')) . '>';
        if ('html' === $type) { echo $open . wp_kses_post($display) . '</span>'; }
        else { echo $open . esc_html($display) . '</span>'; }
    }
}

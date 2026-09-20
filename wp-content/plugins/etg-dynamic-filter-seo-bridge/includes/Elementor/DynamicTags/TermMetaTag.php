<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class TermMetaTag extends \Elementor\Core\DynamicTags\Tag {
    use PreviewContextTrait;
    use LiveBindingTrait;

    public function get_name(){ return 'etg-filter-term-meta'; }
    public function get_title(){ return 'ETG Term Meta Value'; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ return array('text','url'); }

    protected function register_controls(){
        $options=DynamicTagRuntime::termMetaOptions();
        $this->add_control('token',array(
            'label'=>'Discovered Term Meta',
            'type'=>\Elementor\Controls_Manager::SELECT,
            'options'=>$options,
            'default'=>$options?(string)key($options):'',
            'description'=>'Fetched from the current Runtime Inventory. Keys remain exact/case-sensitive. Sensitive and non-scalar Meta are excluded automatically.',
        ));
        $this->etgRegisterPreviewControl();
        $this->etgRegisterLiveBindingControls();
    }

    public function get_value(array$options=array()){
        $token=(string)$this->get_settings('token');
        if(''===$token||0!==strpos($token,'termmeta:')){return'';}
        $resolver=DynamicTagRuntime::resolver();
        return$resolver?$resolver->value($token,$this->etgPreviewContext()):'';
    }

    public function render(){
        $token=(string)$this->get_settings('token');
        if(''===$token||0!==strpos($token,'termmeta:')){return;}
        $value=$this->get_value();
        $display=$this->etgValueOrFallback($value);
        if(!$this->etgLiveEnabled()){echo esc_html($display);return;}
        echo '<span class="etg-dfsb-live-value"'.$this->etgBindingAttributes('token',$token,(string)$this->get_settings('fallback')).'>'.esc_html($display).'</span>';
    }
}

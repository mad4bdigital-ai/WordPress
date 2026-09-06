<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

abstract class FilterValueTag extends \Elementor\Core\DynamicTags\Tag {
    use PreviewContextTrait;
    use LiveBindingTrait;

    abstract protected function etgDefinition(): array;

    public function get_name(){ $d=$this->etgDefinition(); return (string) $d[0]; }
    public function get_title(){ $d=$this->etgDefinition(); return (string) $d[1]; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ $d=$this->etgDefinition(); return array((string) $d[3]); }

    protected function register_controls(){
        $this->etgRegisterPreviewControl();
        $this->etgRegisterLiveBindingControls();
    }

    public function get_value(array $options = array()) {
        $resolver = DynamicTagRuntime::resolver();
        if (!$resolver) { return ''; }
        $d = $this->etgDefinition();
        return $resolver->value((string) $d[2], $this->etgPreviewContext());
    }

    public function render(){
        $d = $this->etgDefinition();
        $value = $this->get_value();
        $token = (string) $d[2];
        $type = (string) $d[3];
        if ('url' === $type) { echo esc_url((string) $value); return; }

        $display = $this->etgValueOrFallback($value);
        if (!$this->etgLiveEnabled()) {
            if ('intro' === $token) { echo wp_kses_post($display); }
            else { echo esc_html($display); }
            return;
        }

        $attrs = $this->etgBindingAttributes('token', $token, (string) $this->get_settings('fallback'));
        $open = '<span class="etg-dfsb-live-value"' . $attrs . '>';
        if ('intro' === $token) { echo $open . wp_kses_post($display) . '</span>'; }
        else { echo $open . esc_html($display) . '</span>'; }
    }
}

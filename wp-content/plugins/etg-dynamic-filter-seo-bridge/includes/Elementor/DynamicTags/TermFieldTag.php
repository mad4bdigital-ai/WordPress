<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class TermFieldTag extends \Elementor\Core\DynamicTags\Tag {
    use PreviewContextTrait;
    use LiveBindingTrait;

    public function get_name(){ return 'etg-filter-term-field'; }
    public function get_title(){ return 'ETG Filter Term Field'; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ return array('text','url'); }

    protected function register_controls(){
        $roles = DynamicTagRuntime::roleOptions();
        $fields = array(
            'name'=>'Name', 'description'=>'Description', 'short_description'=>'Short Description',
            'slug'=>'Slug', 'seo_title'=>'SEO Title', 'meta_description'=>'Meta Description',
            'focus_keyword'=>'Focus Keyword', 'count'=>'Term Count', 'location_level'=>'Location Level',
            'image_url'=>'Image URL', 'image_id'=>'Image ID',
        );
        $this->add_control('role', array('label'=>'Role','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$roles,'default'=>isset($roles['location'])?'location':(string) key($roles)));
        $this->add_control('field', array('label'=>'Field','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$fields,'default'=>'description'));
        $this->etgRegisterPreviewControl();
        $this->etgRegisterLiveBindingControls();
    }

    private function token(): string {
        return 'term:' . sanitize_key((string) $this->get_settings('role')) . ':' . sanitize_key((string) $this->get_settings('field'));
    }

    public function get_value(array $options = array()) {
        $resolver = DynamicTagRuntime::resolver();
        return $resolver ? $resolver->value($this->token(), $this->etgPreviewContext()) : '';
    }

    public function render(){
        $token = $this->token();
        $field = sanitize_key((string) $this->get_settings('field'));
        $value = $this->get_value();
        if ('image_url' === $field) { echo esc_url((string) $value); return; }
        $display=$this->etgValueOrFallback($value);
        $html=in_array($field,array('description','short_description'),true);
        if(!$this->etgLiveEnabled()){echo $html?wp_kses_post($display):esc_html($display);return;}
        $open = '<span class="etg-dfsb-live-value"' . $this->etgBindingAttributes('token',$token,(string)$this->get_settings('fallback')) . '>';
        if ($html) { echo $open . wp_kses_post($display) . '</span>'; }
        else { echo $open . esc_html($display) . '</span>'; }
    }
}

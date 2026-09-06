<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class TermSectionTag extends \Elementor\Core\DynamicTags\Tag {
    use PreviewContextTrait;

    public function get_name(){ return 'etg-filter-term-section'; }
    public function get_title(){ return 'ETG Filter Term Section'; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ return array('text'); }

    protected function register_controls(){
        $roles = DynamicTagRuntime::roleOptions();
        $this->add_control('role', array('label'=>'Role','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$roles,'default'=>isset($roles['location'])?'location':(string) key($roles)));
        $this->add_control('field', array('label'=>'Content Field','type'=>\Elementor\Controls_Manager::SELECT,'options'=>array('description'=>'Description','short_description'=>'Short Description'),'default'=>'description'));
        $this->add_control('show_heading', array('label'=>'Show term name as heading','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes'));
        $this->add_control('heading_level', array('label'=>'Heading Level','type'=>\Elementor\Controls_Manager::SELECT,'options'=>array('h2'=>'H2','h3'=>'H3','h4'=>'H4','h5'=>'H5','h6'=>'H6'),'default'=>'h2','condition'=>array('show_heading'=>'yes')));
        $this->etgRegisterPreviewControl();
    }

    public function get_value(array $options = array()) {
        $resolver = DynamicTagRuntime::resolver();
        if (!$resolver) { return ''; }
        $role = sanitize_key((string) $this->get_settings('role'));
        $field = sanitize_key((string) $this->get_settings('field'));
        return $resolver->value('term:' . $role . ':' . $field, $this->etgPreviewContext());
    }

    public function render(){
        $resolver = DynamicTagRuntime::resolver();
        if (!$resolver) { return; }
        $role = sanitize_key((string) $this->get_settings('role'));
        $field = sanitize_key((string) $this->get_settings('field'));
        $context = $this->etgPreviewContext();
        $contentToken = 'term:' . $role . ':' . $field;
        $content = $resolver->value($contentToken, $context);
        $nameToken = 'term:' . $role . ':name';
        $name = $resolver->value($nameToken, $context);
        if ('' === trim(wp_strip_all_tags((string) $content)) && '' === trim((string) $name)) { return; }
        $level = strtolower((string) $this->get_settings('heading_level'));
        if (!in_array($level, array('h2','h3','h4','h5','h6'), true)) { $level = 'h2'; }
        echo '<section class="etg-filter-term-section etg-filter-term-section--' . esc_attr($role) . '">';
        if ('yes' === (string) $this->get_settings('show_heading') && '' !== trim((string) $name)) {
            echo '<' . esc_attr($level) . '><span class="etg-dfsb-live-value" data-etg-dfsb-token="' . esc_attr($nameToken) . '">' . esc_html((string) $name) . '</span></' . esc_attr($level) . '>';
        }
        echo '<div class="etg-dfsb-live-value" data-etg-dfsb-token="' . esc_attr($contentToken) . '">' . wp_kses_post((string) $content) . '</div>';
        echo '</section>';
    }
}

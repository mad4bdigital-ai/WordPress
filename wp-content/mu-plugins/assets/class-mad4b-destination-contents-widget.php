<?php
defined('ABSPATH') || exit;
class MAD4B_Destination_Contents_Widget extends \Elementor\Widget_Base {
    public function get_name(): string { return 'mad4b-destination-contents'; }
    public function get_title(): string { return 'MAD4B · Dynamic Destination Contents'; }
    public function get_icon(): string { return 'eicon-accordion'; }
    public function get_categories(): array { return ['general']; }
    public function get_style_depends(): array { return ['mad4b-destination-contents-native']; }
    public function get_script_depends(): array { return ['mad4b-destination-contents-native']; }
    protected function is_dynamic_content(): bool { return true; }
    protected function register_controls(): void {
        $this->start_controls_section('section_content',['label'=>'Dynamic Destination Contents']);
        $this->add_control('title',['label'=>'Title override (blank = translated automatically)','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'']);
        $this->add_control('open_default',['label'=>'Open on mobile by default','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'']);
        $this->add_control('show_count',['label'=>'Show section count','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        // Editor-owned ordering policy; WPML copies the setting with the
        // Elementor widget. Never re-order sections without an explicit choice.
        $this->add_control('group_mode',[
            'label'=>'Section ordering',
            'type'=>\Elementor\Controls_Manager::SELECT,
            'options'=>[
                'source'=>'Follow Elementor section order (recommended)',
                'topic'=>'Group by topic (legacy ordering)'
            ],
            'default'=>'source'
        ]);
        $this->end_controls_section();
        $this->start_controls_section('section_style',['label'=>'Appearance','tab'=>\Elementor\Controls_Manager::TAB_STYLE]);
        foreach([
            ['surface','Panel background','--mad4b-toc-surface'],
            ['ink','Text color','--mad4b-toc-ink'],
            ['accent','Accent color','--mad4b-toc-accent']
        ] as $control) {
            $this->add_control($control[0],['label'=>$control[1],'type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .mad4b-destination-contents-native'=>$control[2].': {{VALUE}};']]);
        }
        $this->add_responsive_control('radius',['label'=>'Corner radius','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>0,'max'=>36]],'selectors'=>['{{WRAPPER}} .mad4b-tour-toc__drawer'=>'border-radius: {{SIZE}}{{UNIT}};']]);
        $this->add_responsive_control('link_gap',['label'=>'Link padding','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>0,'max'=>30]],'selectors'=>['{{WRAPPER}} .mad4b-tour-toc__group a'=>'padding-block: {{SIZE}}{{UNIT}};']]);
        $this->end_controls_section();
    }
    protected function render(): void {
        $editing=class_exists('\Elementor\Plugin') && \Elementor\Plugin::instance()->editor->is_edit_mode();
        if (!is_tax('destinations') && !$editing) return;
        $s=$this->get_settings_for_display();
        $l=MAD4B_Destination_Contents_Loader::language();
        $labels=MAD4B_Destination_Contents_Loader::labels()[$l];
        $title=trim((string)($s['title']??''));
        if (!$title) $title=$labels[0];
        $term=get_queried_object();
        $termId=$term instanceof \WP_Term ? (string)$term->term_id : '0';
        echo '<nav class="mad4b-destination-contents-native mad4b-tour-toc mad4b-tour-toc-native" ';
        echo 'data-mad4b-destination-contents="1" data-destination-id="'.esc_attr($termId).'" ';
        echo 'data-show-count="'.esc_attr(($s['show_count']??'yes')==='yes'?'yes':'no').'" ';
        echo 'data-group-mode="'.esc_attr(($s['group_mode']??'source')==='topic'?'topic':'source').'" ';
        echo 'data-group-labels="'.esc_attr(wp_json_encode($labels,JSON_UNESCAPED_UNICODE)).'" ';
        echo 'aria-label="'.esc_attr($title).'">';
        echo '<details class="mad4b-tour-toc__drawer"'.(($s['open_default']??'')==='yes'?' open':'').'>';
        echo '<summary><span class="mad4b-tour-toc__heading">'.esc_html($title).'</span>';
        if (($s['show_count']??'yes')==='yes') echo '<span class="mad4b-tour-toc__count" aria-live="polite">0</span>';
        echo '<span class="mad4b-tour-toc__chevron" aria-hidden="true"></span></summary>';
        echo '<div class="mad4b-tour-toc__panels" data-mad4b-destination-panel="1"></div>';
        echo '</details></nav>';
    }
}

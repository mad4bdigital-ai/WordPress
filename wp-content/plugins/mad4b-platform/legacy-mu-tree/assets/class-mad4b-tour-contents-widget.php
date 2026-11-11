<?php
// Dynamically loaded after Elementor's Widget_Base is defined.
defined('ABSPATH') || exit;
            class MAD4B_Tour_Contents_Widget_V1 extends \Elementor\Widget_Base {
                public function get_name(): string {return 'mad4b-tour-contents';}
                public function get_title(): string {return 'MAD4B · Dynamic Tour Contents';}
                public function get_icon(): string {return 'eicon-accordion';}
                public function get_categories(): array {return ['general'];}
                public function get_style_depends(): array {return ['mad4b-tour-toc-native'];}
                public function get_script_depends(): array {return ['mad4b-tour-toc-native'];}
                protected function is_dynamic_content(): bool {return true;}
                protected function register_controls(): void {
                    $this->start_controls_section('section_content',['label'=>'Dynamic Tour Contents']);
                    $this->add_control('title',['label'=>'Title','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Tour contents']);
                    $this->add_control('open_default',['label'=>'Open contents by default','type'=>\Elementor\Controls_Manager::SWITCHER,'label_on'=>'Yes','label_off'=>'No','return_value'=>'yes','default'=>'']);
                    $this->add_control('show_count',['label'=>'Show section count','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
                    $this->end_controls_section();
                    $this->start_controls_section('section_appearance',['label'=>'Appearance','tab'=>\Elementor\Controls_Manager::TAB_STYLE]);
                    foreach ([
                        ['surface','Panel background','--mad4b-toc-surface'],
                        ['accent','Accent color','--mad4b-toc-accent'],
                        ['ink','Text color','--mad4b-toc-ink'],
                    ] as $a) {
                        $this->add_control($a[0],['label'=>$a[1],'type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .mad4b-tour-toc-native'=>''.$a[2].': {{VALUE}};']]);
                    }
                    $this->add_responsive_control('link_gap',['label'=>'Link spacing','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>0,'max'=>32]],'selectors'=>['{{WRAPPER}} .mad4b-tour-toc__group a'=>'padding-block: {{SIZE}}{{UNIT}};']]);
                    $this->add_responsive_control('radius',['label'=>'Corner radius','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>0,'max'=>36]],'selectors'=>['{{WRAPPER}} .mad4b-tour-toc__drawer'=>'border-radius: {{SIZE}}{{UNIT}};']]);
                    $this->end_controls_section();
                }
                protected function render(): void {
                    if (!is_singular('tours-and-activities')) return;
                    $tour=(int)get_queried_object_id();
                    if ($tour<1) return;
                    $lang=MAD4B_Tour_Contents_Widget_Loader::language();
                    $labels=MAD4B_Tour_Contents_Widget_Loader::labels()[$lang];
                    $groups=MAD4B_Tour_Contents_Widget_Loader::groups($tour,$lang);
                    if (!$groups) return;
                    $settings=$this->get_settings_for_display();
                    $title=trim((string)($settings['title']??''));
                    if ($title==='') $title=$labels[0];
                    $total=array_sum(array_map('count',$groups));
                    echo '<nav class="mad4b-tour-toc mad4b-tour-toc-native" aria-label="'.esc_attr($title).'" data-mad4b-native-toc="1" data-mad4b-tour-id="'.esc_attr((string)$tour).'">';
                    echo '<details class="mad4b-tour-toc__drawer"'.(($settings['open_default']??'')==='yes'?' open':'').'>';
                    echo '<summary><span class="mad4b-tour-toc__heading">'.esc_html($title).'</span>';
                    if (($settings['show_count']??'yes')==='yes')echo '<span class="mad4b-tour-toc__count">'.esc_html((string)$total).'</span>';
                    echo '<span class="mad4b-tour-toc__chevron" aria-hidden="true"></span></summary>';
                    echo '<div class="mad4b-tour-toc__panels">';
                    foreach ($groups as $index=>$items) {
                        if (!$items)continue;
                        echo '<details class="mad4b-tour-toc__group"><summary>'.esc_html($labels[$index+1]).'</summary><ul>';
                        foreach ($items as $item) {
                            $anchor='mad4b-tour-section-'.$item['id'];
                            echo '<li data-mad4b-link-id="'.esc_attr($item['id']).'"><a href="#'.esc_attr($anchor).'">'.esc_html($item['title']).'</a></li>';
                        }
                        echo '</ul></details>';
                    }
                    echo '</div></details></nav>';
                }
            }

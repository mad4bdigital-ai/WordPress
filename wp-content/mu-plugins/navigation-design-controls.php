<?php
/**
 * Plugin Name: Native Navigation Design Controls
 * Description: Opt-in presentation controls for Elementor Nested Tabs and inner navigation containers.
 */
defined('ABSPATH') || exit;

// Elementor native control API: https://developers.elementor.com/docs/controls/
add_action('elementor/element/nested-tabs/section_tabs_style/after_section_end',static function($element){
 if(!class_exists('Elementor\\Controls_Manager'))return;
 $c='Elementor\\Controls_Manager';
 $element->start_controls_section('nav_design_section',['label'=>__('Navigation Design','default'),'tab'=>$c::TAB_STYLE]);
 $element->add_control('nav_design_enabled',['label'=>__('Enable navigation design','default'),'type'=>$c::SWITCHER,'return_value'=>'yes','default'=>'','prefix_class'=>'nav-design-enabled-','render_type'=>'template']);
 $condition=['nav_design_enabled'=>'yes'];
 $element->add_control('nav_design_native_guide',['type'=>$c::RAW_HTML,'raw'=>__('Horizontal / Vertical: Content → Tabs → Direction. Use the device icon for responsive settings. Content → Additional Settings controls horizontal scrolling and the mobile accordion breakpoint. Saved country tabs keep editable inner containers. Generated deeper destination tabs inherit the shared nested design controls. Current/native uses Elementor’s original Tabs and Titles style controls. Preset colors and borders appear when this level or a deeper level uses a preset.','default'),'content_classes'=>'elementor-panel-alert elementor-panel-alert-info','condition'=>$condition]);
 $element->add_control('nav_design_variant',['label'=>__('Tab style','default'),'type'=>$c::SELECT,'options'=>['inherit'=>__('Current / native','default'),'pills'=>'Pills','outline'=>'Outline','underline'=>'Underline','cards'=>'Cards'],'default'=>'inherit','prefix_class'=>'nav-design-variant-','render_type'=>'template','condition'=>$condition]);
 $element->add_control('nav_design_nested_variant',['label'=>__('Deeper level tab style','default'),'type'=>$c::SELECT,'options'=>['inherit'=>'Same as parent','pills'=>'Pills','outline'=>'Outline','underline'=>'Underline','cards'=>'Cards'],'default'=>'inherit','frontend_available'=>true,'condition'=>$condition]);
 $element->add_responsive_control('nav_design_nested_direction',['label'=>__('Deeper level direction','default'),'type'=>$c::SELECT,'options'=>['inherit'=>'Same as parent','block-start'=>'Horizontal · above','block-end'=>'Horizontal · below','inline-start'=>'Vertical · before','inline-end'=>'Vertical · after'],'default'=>'inherit','selectors_dictionary'=>[
 'inherit'=>'',
 'block-start'=>'--n-tabs-direction:column;--n-tabs-heading-direction:row;--n-tabs-heading-width:initial;--n-tabs-title-flex-basis:content;--n-tabs-title-flex-shrink:0;',
 'block-end'=>'--n-tabs-direction:column-reverse;--n-tabs-heading-direction:row;--n-tabs-heading-width:initial;--n-tabs-title-flex-basis:content;--n-tabs-title-flex-shrink:0;',
 'inline-start'=>'--n-tabs-direction:row;--n-tabs-heading-direction:column;--n-tabs-heading-width:240px;--n-tabs-title-flex-basis:initial;--n-tabs-title-flex-shrink:initial;',
 'inline-end'=>'--n-tabs-direction:row-reverse;--n-tabs-heading-direction:column;--n-tabs-heading-width:240px;--n-tabs-title-flex-basis:initial;--n-tabs-title-flex-shrink:initial;'
 ],'selectors'=>['{{WRAPPER}}.native-destination-deep-tabs'=>'{{VALUE}}'],'frontend_available'=>true,'condition'=>$condition]);
 $preset_conditions=['relation'=>'and','terms'=>[
 ['name'=>'nav_design_enabled','operator'=>'===','value'=>'yes'],
 ['relation'=>'or','terms'=>[
  ['name'=>'nav_design_variant','operator'=>'!==','value'=>'inherit'],
  ['name'=>'nav_design_nested_variant','operator'=>'!==','value'=>'inherit']
 ]]
 ]];
 $title='{{WRAPPER}}.nav-design-enabled-yes > .e-n-tabs > .e-n-tabs-heading > .e-n-tab-title, {{WRAPPER}}.nav-design-enabled-yes > .elementor-widget-container > .e-n-tabs > .e-n-tabs-heading > .e-n-tab-title';
 $element->add_responsive_control('nav_design_density',['label'=>__('Density','default'),'type'=>$c::SELECT,'options'=>[''=>'Native spacing','compact'=>'Compact','comfortable'=>'Comfortable','spacious'=>'Spacious'],'default'=>'','selectors_dictionary'=>['compact'=>'padding:6px 10px;min-height:32px;line-height:20px;','comfortable'=>'padding:9px 14px;min-height:40px;line-height:20px;','spacious'=>'padding:13px 18px;min-height:48px;line-height:20px;'],'selectors'=>[$title=>'{{VALUE}}'],'condition'=>$condition]);
 $element->add_responsive_control('nav_design_radius',['label'=>__('Tab corner radius','default'),'type'=>$c::SLIDER,'size_units'=>['px'],'range'=>['px'=>['min'=>0,'max'=>40]],'selectors'=>['{{WRAPPER}}.nav-design-enabled-yes'=>'--nav-tab-radius:{{SIZE}}{{UNIT}};'],'conditions'=>$preset_conditions]);
 $element->add_control('nav_design_border_width',['label'=>__('Tab border width','default'),'type'=>$c::SLIDER,'size_units'=>['px'],'range'=>['px'=>['min'=>0,'max'=>5]],'selectors'=>['{{WRAPPER}}.nav-design-enabled-yes'=>'--nav-tab-border-width:{{SIZE}}{{UNIT}};'],'conditions'=>$preset_conditions]);
 foreach(['normal'=>'Normal','hover'=>'Hover','active'=>'Active']as$state=>$label){
  foreach(['bg'=>'Background','text'=>'Text','border'=>'Border']as$part=>$part_label){
   $element->add_control('nav_design_'.$part.'_'.$state,['label'=>$label.' · '.$part_label,'type'=>$c::COLOR,'selectors'=>['{{WRAPPER}}.nav-design-enabled-yes'=>'--nav-tab-'.$part.'-'.$state.':{{VALUE}};'],'conditions'=>$preset_conditions]);
  }
 }
 $element->end_controls_section();
});

add_action('elementor/element/container/section_layout_container/after_section_end',static function($element){
 if(!class_exists('Elementor\\Controls_Manager'))return;
 $c='Elementor\\Controls_Manager';
 $element->start_controls_section('nav_design_inner_section',['label'=>__('Navigation Inner Container','default'),'tab'=>$c::TAB_LAYOUT]);
 $element->add_control('nav_design_inner_enabled',['label'=>__('Enable navigation content controls','default'),'type'=>$c::SWITCHER,'return_value'=>'yes','default'=>'','prefix_class'=>'nav-inner-enabled-','render_type'=>'template']);
 $condition=['nav_design_inner_enabled'=>'yes'];
 $element->add_control('nav_design_inner_guide',['type'=>$c::RAW_HTML,'raw'=>__('Keep native Container Flexbox/Grid controls for content direction, width, alignment and sidebars. These options affect only navigation cards inside this container; nested containers can override them independently.','default'),'content_classes'=>'elementor-panel-alert elementor-panel-alert-info','condition'=>$condition]);
 $element->add_responsive_control('nav_design_inner_columns',['label'=>__('Card columns','default'),'type'=>$c::NUMBER,'min'=>1,'max'=>6,'step'=>1,'selectors'=>['{{WRAPPER}}.nav-inner-enabled-yes .navigation-editor-grid .jet-listing-grid__items:not(.swiper-wrapper)'=>'--columns:{{VALUE}};'],'condition'=>$condition]);
 $element->add_responsive_control('nav_design_inner_gap',['label'=>__('Card gap','default'),'type'=>$c::SLIDER,'size_units'=>['px'],'range'=>['px'=>['min'=>0,'max'=>50]],'selectors'=>['{{WRAPPER}}.nav-inner-enabled-yes .navigation-editor-grid .jet-listing-grid__items:not(.swiper-wrapper)'=>'--column-gap:{{SIZE}}{{UNIT}};'],'condition'=>$condition]);
 $element->add_control('nav_design_inner_format',['label'=>__('Card content','default'),'type'=>$c::SELECT,'options'=>['inherit'=>'Current / native','all'=>'Image, title and description','no_description'=>'Image and title','title_only'=>'Title only'],'default'=>'inherit','prefix_class'=>'nav-inner-format-','render_type'=>'template','condition'=>$condition]);
 $element->add_control('nav_design_inner_cta_variant',['label'=>__('See all / CTA style','default'),'type'=>$c::SELECT,'options'=>['inherit'=>'Current / native','solid'=>'Solid','outline'=>'Outline','text'=>'Text link'],'default'=>'inherit','prefix_class'=>'nav-inner-cta-','render_type'=>'template','condition'=>$condition]);
 $element->add_control('nav_design_inner_cta_color',['label'=>__('CTA accent color','default'),'type'=>$c::COLOR,'selectors'=>['{{WRAPPER}}.nav-inner-enabled-yes'=>'--nav-cta-accent:{{VALUE}};'],'condition'=>['nav_design_inner_enabled'=>'yes','nav_design_inner_cta_variant!'=>'inherit']]);
 $element->add_control('nav_design_inner_cta_text',['label'=>__('CTA text color','default'),'type'=>$c::COLOR,'selectors'=>['{{WRAPPER}}.nav-inner-enabled-yes'=>'--nav-cta-text:{{VALUE}};'],'condition'=>['nav_design_inner_enabled'=>'yes','nav_design_inner_cta_variant!'=>'inherit']]);
 $element->add_control('nav_design_card_hover',['label'=>__('Card hover background','default'),'type'=>$c::COLOR,'selectors'=>['{{WRAPPER}}.nav-inner-enabled-yes .navigation-editor-card:hover'=>'background-color:{{VALUE}};'],'condition'=>$condition]);
 $element->add_control('nav_design_card_radius',['label'=>__('Card corner radius','default'),'type'=>$c::SLIDER,'size_units'=>['px'],'range'=>['px'=>['min'=>0,'max'=>40]],'selectors'=>['{{WRAPPER}}.nav-inner-enabled-yes .navigation-editor-card'=>'border-radius:{{SIZE}}{{UNIT}};'],'condition'=>$condition]);
 $element->add_responsive_control('nav_design_card_padding',['label'=>__('Card padding','default'),'type'=>$c::DIMENSIONS,'size_units'=>['px'],'selectors'=>['{{WRAPPER}}.nav-inner-enabled-yes .navigation-editor-card'=>'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],'condition'=>$condition]);
 $element->end_controls_section();
});

add_action('wp_enqueue_scripts',static function(){
 $file=__DIR__.'/navigation-design-controls.css';
 wp_enqueue_style('native-navigation-design-controls',plugins_url('navigation-design-controls.css',__FILE__),[],(string)filemtime($file));
},100);

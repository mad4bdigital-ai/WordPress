<?php
/** Plugin Name: MAD4B Destination Card Native Interaction
 * Decorative overlays never own the pointer; existing native anchors own navigation.
 */
defined('ABSPATH')||exit;
add_action('wp_enqueue_scripts',static function(){
 wp_register_style('mad4b-destination-card-interaction',false,[], '1.0.0');wp_enqueue_style('mad4b-destination-card-interaction');
 wp_add_inline_style('mad4b-destination-card-interaction', '
.elementor-45929 .elementor-element-85bc6bc{position:relative;isolation:isolate;cursor:pointer}
.elementor-45929 .elementor-element-85bc6bc::before,
.elementor-45929 .elementor-element-85bc6bc::after{pointer-events:none!important}
.elementor-45929 .elementor-element-85bc6bc .elementor-widget-heading,
.elementor-45929 .elementor-element-85bc6bc .elementor-widget-heading>.elementor-widget-container,
.elementor-45929 .elementor-element-85bc6bc .elementor-heading-title,
.elementor-45929 .elementor-element-85bc6bc .elementor-heading-title>a{position:static}
.elementor-45929 .elementor-element-85bc6bc .elementor-heading-title>a::after{content:"";position:absolute;inset:0;z-index:3;border-radius:inherit}
.elementor-45929 .elementor-element-85bc6bc:focus-within{outline:2px solid currentColor;outline-offset:3px}
.elementor-element-e8501fc .elementor-cta::before,
.elementor-element-e8501fc .elementor-cta::after,
.elementor-element-e8501fc .elementor-cta__bg-wrapper,
.elementor-element-e8501fc .elementor-cta__bg-overlay{pointer-events:none!important}
.elementor-element-e8501fc .elementor-cta__content{position:relative;z-index:2}
.elementor-element-e8501fc .elementor-cta__button{position:relative;z-index:3;pointer-events:auto!important}
');
},99);

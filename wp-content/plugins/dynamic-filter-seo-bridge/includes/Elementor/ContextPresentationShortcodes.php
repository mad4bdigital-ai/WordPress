<?php
namespace DynamicFilterSEOBridge\Elementor;

use DynamicFilterSEOBridge\Context\PageContextResolver;
use DynamicFilterSEOBridge\Context\ContextValueResolver;
use DynamicFilterSEOBridge\Context\PresentationSlotPolicy;
use DynamicFilterSEOBridge\SEO\SiteSeoSources;

/** Optional, native-slot-safe copy for Elementor Shortcode widgets. */
final class ContextPresentationShortcodes {
    public function register(): void {
        add_shortcode('dfsb_context_editorial',array($this,'editorial'));
        add_shortcode('dfsb_context_heading',array($this,'heading'));
        add_shortcode('dfsb_context_value',array($this,'value'));
        add_shortcode('dfsb_context_image',array($this,'image'));
    }
    public function image($attrs=array()): string {
        if(\DynamicFilterSEOBridge\Runtime\BootGuard::shouldHold() || is_admin() || wp_doing_ajax())return '';
        $ctx=$this->content();
        $media=ContextValueResolver::image($ctx);
        if(empty($media['url']))return '';
        $title=ContextValueResolver::value($ctx,'title');
        if(!empty($media['id'])){
            $html=wp_get_attachment_image((int)$media['id'],'large',false,array(
                'alt'=>$title,'loading'=>'lazy'));
            if(is_string($html)&&$html!=='')return $html;
        }
        return '<img src="'.esc_url($media['url']).'" alt="'.esc_attr($title).'" loading="lazy" />';
    }
    /** Shared value contract with Elementor Dynamic Tag and REST previews. */
    public function value($attrs=array()): string {
        if(\DynamicFilterSEOBridge\Runtime\BootGuard::shouldHold() || wp_doing_ajax())return '';
        $attrs=shortcode_atts(array('field'=>'title'),is_array($attrs)?$attrs:array(),'dfsb_context_value');
        $field=sanitize_key((string)$attrs['field']);
        $text=ContextValueResolver::value($this->content(),$field);
        return $field==='image_url'?esc_url($text):esc_html($text);
    }
    private function content(): array {
        return PageContextResolver::fromQuery();
    }
    public function heading($attrs=array()): string {
        if(is_admin()||wp_doing_ajax()||\DynamicFilterSEOBridge\Runtime\BootGuard::shouldHold())return '';
        $ctx=$this->content();
        $slots=PresentationSlotPolicy::forContext($ctx);
        if($slots['placement']!=='after_listing')return '';
        $title=ContextValueResolver::value($ctx,'title');
        if($title==='')return '';
        // An optional H2 (never H1) avoids replacing existing main heading.
        return '<h2 class="dfsb-context-heading">'.esc_html($title).'</h2>';
    }
    public function editorial($attrs=array()): string {
        if(is_admin()||wp_doing_ajax()||\DynamicFilterSEOBridge\Runtime\BootGuard::shouldHold())return '';
        $ctx=$this->content();
        $slots=PresentationSlotPolicy::forContext($ctx);
        if($slots['placement']!=='after_listing')return '';
        // Only display unique native term or archive descriptions. A generic
        // site tagline is not a useful editorial panel and must not duplicate.
        $copy=ContextValueResolver::value($ctx,'description');
        if($copy==='')return '';
        $title=ContextValueResolver::value($ctx,'title');
        return '<section class="dfsb-context-editorial" aria-label="'.esc_attr($title).'">'
            .'<div class="dfsb-context-editorial__copy">'.esc_html($copy).'</div></section>';
    }
}

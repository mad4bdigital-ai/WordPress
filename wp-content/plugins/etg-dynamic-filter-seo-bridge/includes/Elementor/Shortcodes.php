<?php
namespace ETG\DynamicFilterSEOBridge\Elementor;

use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;

final class Shortcodes {
	private $contextProvider; private $content; private $gallery;
	public function __construct( callable $contextProvider, ContentComposer $content, GalleryComposer $gallery ) {
		$this->contextProvider = $contextProvider; $this->content = $content; $this->gallery = $gallery;
	}
	public function register(): void {
		add_shortcode( 'etg_filter_h1', array( $this, 'h1' ) );
		add_shortcode( 'etg_filter_intro', array( $this, 'intro' ) );
		add_shortcode( 'etg_filter_sections', array( $this, 'sections' ) );
		add_shortcode( 'etg_filter_gallery', array( $this, 'gallery' ) );
		add_shortcode( 'etg_filter_keyword', array( $this, 'keyword' ) );
		add_shortcode( 'etg_filter_breadcrumb_context', array( $this, 'breadcrumb' ) );
		add_shortcode( 'etg_filter_term', array( $this, 'term' ) );
		add_shortcode( 'etg_filter_term_section', array( $this, 'termSection' ) );
	}
	public function h1(): string { $c = $this->context(); return $this->renderable( $c ) ? esc_html( $this->content->title( $c ) ) : ''; }
	public function intro(): string {
		$c = $this->context(); if ( ! $this->renderable( $c ) ) { return ''; }
		$intro = $this->content->intro( $c ); return '' !== trim( wp_strip_all_tags( $intro ) ) ? wpautop( wp_kses_post( $intro ) ) : '';
	}
	public function sections(): string { $c = $this->context(); return $this->renderable( $c ) ? wp_kses_post( $this->content->sections( $c ) ) : ''; }
	public function gallery( $atts = array() ): string {
		$c = $this->context(); if ( ! $this->renderable( $c ) ) { return ''; }
		$atts = shortcode_atts( array( 'mode' => 'combined', 'limit' => '9', 'size' => 'large' ), (array) $atts, 'etg_filter_gallery' );
		return $this->gallery->render( $c, $atts );
	}
	public function keyword(): string { $c = $this->context(); return $this->renderable( $c ) ? esc_html( $this->content->keyword( $c ) ) : ''; }
	public function breadcrumb(): string {
		$c = $this->context(); if ( ! $this->renderable( $c ) ) { return ''; }
		$names=array();foreach($this->content->breadcrumbLabels($c) as $name){$names[]=esc_html((string)$name);}
		return implode( ' &rsaquo; ', $names );
	}

	/**
	 * Elementor Theme Builder can place this shortcode in any widget to render
	 * the same resolved Term data used by the SEO metadata engine.
	 * Example: [etg_filter_term role="location" field="description" autop="1"]
	 */
	public function term( $atts = array() ): string {
		$c=$this->context();if(!$this->renderable($c)){return '';}
		$atts=shortcode_atts(array('role'=>'','taxonomy'=>'','field'=>'description','autop'=>'1','size'=>'full'),(array)$atts,'etg_filter_term');
		$term=$this->termForAttributes($c,$atts);if(!$term){return '';}
		$field=sanitize_key((string)$atts['field']);
		$allowed=array('name','slug','description','short_description','seo_title','meta_description','focus_keyword','image_url','image_id','count','location_level');
		if(!in_array($field,$allowed,true)){return '';}
		if('image_url'===$field){
			$id=(int)($term['image_id']??0);if(!$id||!function_exists('wp_get_attachment_image_url')){return '';}
			$url=wp_get_attachment_image_url($id,sanitize_key((string)$atts['size'])?:'full');return $url?esc_url($url):'';
		}
		$value=$term[$field]??'';
		if(is_array($value)||is_object($value)){return '';}
		$value=(string)$value;
		if(in_array($field,array('description','short_description'),true)){
			$value=wp_kses_post($value);
			return $this->truthy($atts['autop'])&&''!==trim(wp_strip_all_tags($value))?wpautop($value):$value;
		}
		return esc_html($value);
	}

	/**
	 * Render one resolved filter Term as a semantic content section so archive
	 * templates can control placement while keeping crawlable server-side HTML.
	 */
	public function termSection( $atts = array() ): string {
		$c=$this->context();if(!$this->renderable($c)){return '';}
		$atts=shortcode_atts(array('role'=>'','taxonomy'=>'','field'=>'description','heading'=>'1','heading_level'=>'2','class'=>''),(array)$atts,'etg_filter_term_section');
		$term=$this->termForAttributes($c,$atts);if(!$term){return '';}
		$field=sanitize_key((string)$atts['field']);if(!in_array($field,array('description','short_description'),true)){return '';}
		$body=(string)($term[$field]??'');if(''===trim(wp_strip_all_tags($body))){return '';}
		$level=max(2,min(6,(int)$atts['heading_level']));
		$class='etg-filter-term-section';$extra=sanitize_html_class((string)$atts['class']);if($extra){$class.=' '.$extra;}
		$html='<section class="'.esc_attr($class).'">';
		if($this->truthy($atts['heading'])&&!empty($term['name'])){$html.='<h'.$level.'>'.esc_html((string)$term['name']).'</h'.$level.'>';}
		$html.='<div class="etg-filter-term-section__content">'.wpautop(wp_kses_post($body)).'</div></section>';
		return $html;
	}

	private function termForAttributes( array $context, array $atts ): array {
		$role=sanitize_key((string)($atts['role']??''));
		$taxonomy=sanitize_key((string)($atts['taxonomy']??''));
		if(''===$role&&$taxonomy){$role=sanitize_key((string)($context['taxonomy_roles'][$taxonomy]??''));}
		if(''===$role){return array();}
		$term=(array)($context['terms'][$role]??array());
		return $term;
	}
	private function truthy( $value ): bool {return in_array(strtolower(trim((string)$value)),array('1','true','yes','on'),true);}
	private function context(): array { $c = call_user_func( $this->contextProvider ); return is_array( $c ) ? $c : array(); }
	private function renderable( array $c ): bool {
		if(empty($c['active'])||empty($c['in_scope'])||empty($c['runtime_ready'])||empty($c['filters'])){return false;}
		if(isset($c['scope_valid'])&&empty($c['scope_valid'])){return false;}
		if(isset($c['provider_observation_matches_url'])&&empty($c['provider_observation_matches_url'])){return false;}
		$profile=(array)($c['profile']??array());$binding=(array)($c['post_type_binding']??array());
		if(!empty($profile['require_post_type_binding'])&&(empty($binding['observed'])||empty($binding['matches_profile']))){return false;}
		return empty($c['unknown_filters'])&&empty($c['malformed'])&&empty($c['missing_terms'])&&empty($c['translation_fallback'])&&!empty($c['terms']);
	}
}

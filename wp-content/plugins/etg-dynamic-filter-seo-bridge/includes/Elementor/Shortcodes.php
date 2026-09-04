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

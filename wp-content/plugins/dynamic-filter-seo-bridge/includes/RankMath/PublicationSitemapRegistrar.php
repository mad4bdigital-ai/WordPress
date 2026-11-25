<?php
namespace DynamicFilterSEOBridge\RankMath;

use DynamicFilterSEOBridge\SEO\PublicationRegistry;
use DynamicFilterSEOBridge\Config\Configuration;
use DynamicFilterSEOBridge\SEO\NativeFeaturePriority;

final class PublicationSitemapRegistrar {
	// Preserve the existing public route and its native cache namespace.
	public const SITEMAP_TYPE = 'etg-filter-seo';
	private $publication;
	public function __construct( PublicationRegistry $publication ) { $this->publication=$publication; }
	public function register(): void {
		if (!NativeFeaturePriority::bridgeSeoFallbackAllowed()) { return; }
		add_filter('rank_math/sitemap/providers',array($this,'providers'));
		foreach(array('save_post','deleted_post','set_object_terms','edited_term','created_term','delete_term','added_term_meta','updated_term_meta','deleted_term_meta') as $hook){add_action($hook,array($this,'invalidate'),20,10);}
		add_action('update_option_'.Configuration::OPTION_NAME,array($this,'invalidate'),20,3);
	}
	public function providers( $providers ) {$providers=is_array($providers)?$providers:array();if(!NativeFeaturePriority::bridgeSeoFallbackAllowed()){return $providers;}if(interface_exists('\\RankMath\\Sitemap\\Providers\\Provider')){$providers[self::SITEMAP_TYPE]=new PublicationSitemapProvider($this->publication);}return $providers;}
	public function invalidate( ...$ignored ): void {$this->publication->invalidateCache();$class='\\RankMath\\Sitemap\\Cache';if(class_exists($class)&&method_exists($class,'invalidate_storage')){try{$class::invalidate_storage(self::SITEMAP_TYPE);}catch(\Throwable $e){}}}
}

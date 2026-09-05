<?php
namespace ETG\DynamicFilterSEOBridge\RankMath;

use ETG\DynamicFilterSEOBridge\SEO\PublicationRegistry;

final class PublicationSitemapRegistrar {
	private $publication;
	public function __construct( PublicationRegistry $publication ) { $this->publication=$publication; }

	public function register(): void {
		add_filter('rank_math/sitemap/providers',array($this,'providers'));
		foreach(array('save_post','deleted_post','set_object_terms','edited_term','created_term','delete_term') as $hook){
			add_action($hook,array($this,'invalidate'),20,10);
		}
		add_action('updated_option_etg_dfsb_settings',array($this,'invalidate'),20,3);
	}

	public function providers( $providers ) {
		$providers=is_array($providers)?$providers:array();
		if(interface_exists('\\RankMath\\Sitemap\\Providers\\Provider')){
			$providers['etg-filter-seo']=new PublicationSitemapProvider($this->publication);
		}
		return $providers;
	}

	public function invalidate( ...$ignored ): void {
		$class='\\RankMath\\Sitemap\\Cache';
		if(class_exists($class)&&method_exists($class,'invalidate_storage')){
			try{$class::invalidate_storage('etg-filter-seo');}catch(\Throwable $e){}
		}
	}
}

<?php
namespace ETG\DynamicFilterSEOBridge\RankMath;

use ETG\DynamicFilterSEOBridge\SEO\PublicationRegistry;

final class PublicationSitemapProvider implements \RankMath\Sitemap\Providers\Provider {
	private $publication;
	public function __construct( PublicationRegistry $publication ) { $this->publication=$publication; }

	public function handles_type( $type ) {
		return 'etg-filter-seo' === (string) $type;
	}

	public function get_index_links( $max_entries ) {
		if(!$this->publication->published(1)){return array();}
		$loc=class_exists('\\RankMath\\Sitemap\\Router')&&method_exists('\\RankMath\\Sitemap\\Router','get_base_url')
			? \RankMath\Sitemap\Router::get_base_url('etg-filter-seo-sitemap.xml')
			: home_url('/etg-filter-seo-sitemap.xml');
		return array(array('loc'=>$loc,'lastmod'=>''));
	}

	public function get_sitemap_links( $type, $max_entries, $current_page ) {
		if(!$this->handles_type($type)){return array();}
		return $this->publication->sitemapLinks((int)$max_entries,(int)$current_page);
	}
}

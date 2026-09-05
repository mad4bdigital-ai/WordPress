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
		$max=max(1,min(1000,(int)$max_entries));
		$published=$this->publication->published();
		if(!$published){return array();}
		$pageCount=(int)ceil(count($published)/$max);
		$links=array();
		for($page=1;$page<=$pageCount;$page++){
			$suffix=$pageCount>1?(string)$page:'';
			$file='etg-filter-seo-sitemap'.$suffix.'.xml';
			$loc=class_exists('\\RankMath\\Sitemap\\Router')&&method_exists('\\RankMath\\Sitemap\\Router','get_base_url')
				? \RankMath\Sitemap\Router::get_base_url($file)
				: home_url('/'.$file);
			$links[]=array('loc'=>$loc,'lastmod'=>'');
		}
		return $links;
	}

	public function get_sitemap_links( $type, $max_entries, $current_page ) {
		if(!$this->handles_type($type)){return array();}
		return $this->publication->sitemapLinks((int)$max_entries,(int)$current_page);
	}
}

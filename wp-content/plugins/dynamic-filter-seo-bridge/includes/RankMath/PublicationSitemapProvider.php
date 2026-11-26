<?php
namespace DynamicFilterSEOBridge\RankMath;

use DynamicFilterSEOBridge\SEO\PublicationRegistry;

final class PublicationSitemapProvider implements \RankMath\Sitemap\Providers\Provider {
	// Existing public sitemap identity is a compatibility contract, not tenant branding.
	public const SITEMAP_TYPE = PublicationSitemapRegistrar::SITEMAP_TYPE;
	private $publication;
	public function __construct( PublicationRegistry $publication ) { $this->publication=$publication; }

	public function handles_type( $type ) {
		return self::SITEMAP_TYPE === (string) $type;
	}

	public function get_index_links( $max_entries ) {
		$max=max(1,min(1000,(int)$max_entries));
		$count=$this->publication->publishedCount();
		if($count<1){return array();}
		$pageCount=(int)ceil($count/$max);
		$links=array();
		for($page=1;$page<=$pageCount;$page++){
			$suffix=$pageCount>1?(string)$page:'';
			$file=self::SITEMAP_TYPE.'-sitemap'.$suffix.'.xml';
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

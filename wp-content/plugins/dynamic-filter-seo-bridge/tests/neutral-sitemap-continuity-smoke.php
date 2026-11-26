<?php
namespace RankMath\Sitemap\Providers { interface Provider {} }
namespace RankMath\Sitemap { class Cache { public static $types=[];public static function invalidate_storage($type){self::$types[]=$type;} } }
namespace DynamicFilterSEOBridge\Config { class Configuration { const OPTION_NAME='etg_dfsb_settings'; } }
namespace DynamicFilterSEOBridge\SEO {
 class NativeFeaturePriority { public static $allowed=true;public static function bridgeSeoFallbackAllowed(){return self::$allowed;} }
 class PublicationRegistry {public $invalidations=0;public function invalidateCache(){$this->invalidations++;}public function publishedCount(){return 3;}public function sitemapLinks($max,$page){return [$max,$page];}}
}
namespace {
 function add_action($name,$callback,$priority=10,$args=1){$GLOBALS['actions'][$name]=$callback;}
 function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['filters'][$name]=$callback;}
 function home_url($path){return 'https://example.test'.$path;}
 function verify($ok,$message){if(!$ok)throw new \RuntimeException($message);}
 require dirname(__DIR__).'/includes/RankMath/PublicationSitemapRegistrar.php';
 require dirname(__DIR__).'/includes/RankMath/PublicationSitemapProvider.php';
 $registry=new \DynamicFilterSEOBridge\SEO\PublicationRegistry();
 $registrar=new \DynamicFilterSEOBridge\RankMath\PublicationSitemapRegistrar($registry);
 \DynamicFilterSEOBridge\SEO\NativeFeaturePriority::$allowed=false;$registrar->register();verify(empty($GLOBALS['actions'])&&empty($GLOBALS['filters']),'native ownership unchanged');
 \DynamicFilterSEOBridge\SEO\NativeFeaturePriority::$allowed=true;$registrar->register();
 $hook='update_option_'.\DynamicFilterSEOBridge\Config\Configuration::OPTION_NAME;verify(isset($GLOBALS['actions'][$hook]),'authoritative settings hook');
 $GLOBALS['actions'][$hook]();verify($registry->invalidations===1&&\RankMath\Sitemap\Cache::$types===['etg-filter-seo'],'same native cache key');
 $providers=$registrar->providers(['existing'=>'keep']);verify(count($providers)===2&&$providers['existing']==='keep','one existing DFSB provider');
 $provider=$providers['etg-filter-seo'];verify($provider->handles_type('etg-filter-seo')&&!$provider->handles_type('dfsb'),'no new published route');
 verify(array_column($provider->get_index_links(2),'loc')===['https://example.test/etg-filter-seo-sitemap1.xml','https://example.test/etg-filter-seo-sitemap2.xml'],'existing sitemap pagination');
 verify($provider->get_sitemap_links('etg-filter-seo',2,1)===[2,1],'native publication result');
 echo 'DFSB_SITEMAP_CONTINUITY: PASS'.PHP_EOL;
}

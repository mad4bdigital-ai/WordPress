<?php
namespace ETG\DynamicFilterSEOBridge\SEO;
use ETG\DynamicFilterSEOBridge\Config\Configuration;
final class PublicationCache {
	private const EPOCH_OPTION='etg_dfsb_publication_cache_epoch';private const KEY_PREFIX='etg_dfsb_pub_';private $config;private $hits=0;private $misses=0;private $writes=0;public function __construct(Configuration $config){$this->config=$config;}
	public function get(string $identity){if(!function_exists('get_transient')){$this->misses++;return null;}$value=get_transient($this->key($identity));if(!is_array($value)||(string)($value['contract']??'')!=='etg.dfsb.publication-cache-entry.v1'||!isset($value['candidate'])||!is_array($value['candidate'])){$this->misses++;return null;}$this->hits++;return $value['candidate'];}
	public function set(string $identity,array $candidate):void{if(!function_exists('set_transient')){return;}$ttl=max(300,min(86400,(int)$this->config->get('publication_cache_ttl',21600)));set_transient($this->key($identity),array('contract'=>'etg.dfsb.publication-cache-entry.v1','plugin_version'=>defined('ETG_DFSB_VERSION')?(string)ETG_DFSB_VERSION:'','configuration_revision'=>$this->config->revision(),'cached_at_gmt'=>gmdate('c'),'candidate'=>$candidate),$ttl);$this->writes++;}
	public function invalidate():void{if(!function_exists('update_option')){return;}update_option(self::EPOCH_OPTION,$this->epoch()+1,false);}
	public function stats():array{return array('contract'=>'etg.dfsb.publication-cache-stats.v1','hits'=>$this->hits,'misses'=>$this->misses,'writes'=>$this->writes,'epoch'=>$this->epoch(),'ttl_seconds'=>max(300,min(86400,(int)$this->config->get('publication_cache_ttl',21600))));}
	private function key(string $identity):string{$version=defined('ETG_DFSB_VERSION')?(string)ETG_DFSB_VERSION:'unknown';return self::KEY_PREFIX.substr(hash('sha256',$version.'|'.$this->epoch().'|'.$this->config->revision().'|'.$identity),0,32);}private function epoch():int{if(!function_exists('get_option')){return 1;}$value=get_option(self::EPOCH_OPTION,1);return is_numeric($value)?max(1,(int)$value):1;}
}

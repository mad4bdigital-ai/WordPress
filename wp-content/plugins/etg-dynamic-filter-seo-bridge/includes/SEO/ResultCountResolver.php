<?php
namespace ETG\DynamicFilterSEOBridge\SEO;

use ETG\DynamicFilterSEOBridge\Config\Configuration;

final class ResultCountResolver {
	private $config; private $adapter;
	public function __construct(Configuration $config,?JetEngineResultCountAdapter $adapter=null){$this->config=$config;$this->adapter=$adapter?:new JetEngineResultCountAdapter();}
	public function resolve(array $context):array{
		$authority=function_exists('apply_filters')?apply_filters('etg_filter_seo_result_count_authority',null,$context):null;
		$normalized=$this->normalizeExternalAuthority($authority);
		if(null!==$normalized['count']&&!empty($normalized['authoritative'])){return $normalized;}
		if($this->config->get('enable_jet_engine_result_count_adapter',true)){$adapted=$this->adapter->resolve($context);if(null!==$adapted['count']&&!empty($adapted['authoritative'])){return $adapted;}}
		$legacy=function_exists('apply_filters')?apply_filters('etg_filter_seo_result_count',null,$context):null;
		if(is_numeric($legacy)){return array('count'=>max(0,(int)$legacy),'source'=>'legacy_filter','authoritative'=>(bool)$this->config->get('trust_legacy_result_count',false),'detail'=>'legacy_numeric');}
		return array('count'=>null,'source'=>'unavailable','authoritative'=>false,'detail'=>(string)($normalized['detail']??'unavailable'));
	}
	private function normalizeExternalAuthority($value):array{
		if(!is_array($value)||!isset($value['count'])||!is_numeric($value['count'])){return $this->unavailable('structured_authority_unavailable');}
		$contract=(string)($value['contract']??'');$source=sanitize_key((string)($value['source']??''));$trusted=(array)$this->config->get('trusted_result_count_authority_sources',array());$trusted=array_values(array_unique(array_filter(array_map('sanitize_key',$trusted))));
		if('etg.dfsb.result-count-authority.v1'!==$contract){return $this->unavailable('external_authority_contract_invalid');}
		if(''===$source||!in_array($source,$trusted,true)){return $this->unavailable('external_authority_source_untrusted');}
		if(empty($value['authoritative'])){return $this->unavailable('external_authority_not_authoritative');}
		return array('count'=>max(0,(int)$value['count']),'source'=>$source,'authoritative'=>true,'detail'=>'trusted_external_authority');
	}
	private function unavailable(string $detail):array{return array('count'=>null,'source'=>'unavailable','authoritative'=>false,'detail'=>$detail);}
}

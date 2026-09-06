<?php
namespace ETG\DynamicFilterSEOBridge\Diagnostics;
use ETG\DynamicFilterSEOBridge\Config\Configuration; use ETG\DynamicFilterSEOBridge\SEO\IndexingPolicy;
final class DecisionLogger {
	private $contextProvider; private $policy; private $config;
	public function __construct(callable $contextProvider,IndexingPolicy $policy,Configuration $config){$this->contextProvider=$contextProvider;$this->policy=$policy;$this->config=$config;}
	public function register():void{add_action('wp',array($this,'capture'),99);}
	public function capture():void{
		if(!$this->config->get('diagnostics_enabled',true)){return;} $context=call_user_func($this->contextProvider); if(!is_array($context)||empty($context['active'])){return;}
		$decision=$this->policy->decide($context); $trace=array(
			'contract'=>'etg.dfsb.decision-trace.v3','request_path'=>(string)($context['request_path']??''),'archive'=>(string)($context['archive']??''),'provider'=>(string)($context['provider']??''),'query_id'=>(string)($context['query_id']??''),'filters'=>(array)($context['filters']??array()),'language'=>(string)($context['language']??''),'in_scope'=>(bool)($context['in_scope']??false),'scope_reason'=>(string)($context['scope']['reason']??''),'profile_id'=>(string)($context['profile_id']??''),'scope_valid'=>(bool)($context['scope_valid']??false),'post_type_binding'=>(array)($context['post_type_binding']??array()),'runtime_ready'=>(bool)($context['runtime_ready']??false),'provider_observation'=>(array)($context['jet_smart_filters_provider']??array()),'provider_match'=>(bool)($context['provider_observation_matches_url']??false),'unsupported_query_params'=>(array)($context['unsupported_query_params']??array()),'combination_authority'=>(array)($context['combination_authority']??array()),'content_readiness'=>(array)($context['content_readiness']??array()),'result_count'=>$context['result_count']??null,'result_count_source'=>(string)($context['result_count_source']??''),'result_count_authoritative'=>(bool)($context['result_count_authoritative']??false),'result_count_detail'=>(string)($context['result_count_detail']??''),'indexing'=>$decision,
		); do_action('etg_filter_seo_decision_trace',$trace,$context);
		if($this->config->get('log_decisions',false)){$encoded=function_exists('wp_json_encode')?wp_json_encode($trace):json_encode($trace);error_log('[ETG_DFSB] '.(string)$encoded);}
	}
}

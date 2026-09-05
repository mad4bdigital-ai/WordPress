<?php
namespace ETG\DynamicFilterSEOBridge\Simulation;

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\SEO\CombinationRegistry;
use ETG\DynamicFilterSEOBridge\SEO\IndexingPolicy;

final class ScenarioSimulator {
	private $config;
	private $profiles;
	private $combinations;
	private $policy;
	public function __construct( Configuration $config, ProfileRegistry $profiles, CombinationRegistry $combinations, IndexingPolicy $policy ) { $this->config=$config; $this->profiles=$profiles; $this->combinations=$combinations; $this->policy=$policy; }

	public function run( array $scenario ): array {
		$profileId=sanitize_key((string)($scenario['profile_id']??''));
		$profile=$this->profiles->get($profileId);
		if(!$profile){return array('contract'=>'etg.dfsb.simulation.v1','synthetic'=>true,'error'=>'profile_not_found','profile_id'=>$profileId);}
		/* Synthetic scenarios default this presentation prerequisite to verified so
		 * existing policy objections can exercise deeper gates. Set it false
		 * explicitly to challenge the Elementor publication gate itself. */
		if(!isset($profile['publication'])||!is_array($profile['publication'])){$profile['publication']=array();}
		$profile['publication']['elementor_content_verified']=array_key_exists('elementor_content_verified',$scenario)?(bool)$scenario['elementor_content_verified']:true;
		$filters=array();
		foreach((array)($scenario['filters']??array()) as $taxonomy=>$slug){$taxonomy=sanitize_key((string)$taxonomy);$slug=sanitize_title((string)$slug);if(''!==$taxonomy&&''!==$slug){$filters[$taxonomy]=$slug;}}
		$terms=array();
		$termMeta=is_array($scenario['term_meta']??null)?$scenario['term_meta']:array();
		foreach($filters as $taxonomy=>$slug){
			$rule=(array)($profile['taxonomy_rules'][$taxonomy]??array());
			$role=sanitize_key((string)($rule['role']??$taxonomy));
			$profileMeta=is_array($termMeta[$taxonomy]??null)?$termMeta[$taxonomy]:array();
			$terms[$role]=array('name'=>$slug,'slug'=>$slug,'taxonomy'=>$taxonomy,'description'=>'Synthetic scenario content for '.$slug,'short_description'=>'Synthetic scenario content for '.$slug,'profile_meta'=>$profileMeta);
		}
		$postType=sanitize_key((string)($scenario['post_type']??''));
		$postObserved=''!==$postType;
		$postMatch=!$profile['require_post_type_binding']||($postObserved&&in_array($postType,(array)$profile['post_types'],true));
		$authority=sanitize_key((string)($profile['post_type_authority']??'query_builder'));
		$postBinding=array('contract'=>'etg.dfsb.post-type-binding.v2','required'=>!empty($profile['require_post_type_binding']),'authority'=>$authority,'observed'=>$postObserved,'matches_profile'=>$postMatch,'reason'=>$postObserved?($postMatch?'matched':'post_type_mismatch'):'synthetic_unobserved','post_types'=>$postObserved?array($postType):array(),'allowed_post_types'=>(array)($profile['post_types']??array()),'source'=>'synthetic','sources'=>array());
		$context=array(
			'active'=>true,'in_scope'=>true,'scope_valid'=>true,
			'profile_id'=>$profileId,'profile'=>$profile,
			'scope'=>array('in_scope'=>true,'scope_valid'=>true,'reason'=>'synthetic_profile','profile_id'=>$profileId,'profile'=>$profile,'configuration_revision'=>$this->config->revision()),
			'runtime_ready'=>array_key_exists('runtime_ready',$scenario)?(bool)$scenario['runtime_ready']:true,
			'provider_observation_matches_url'=>array_key_exists('provider_match',$scenario)?(bool)$scenario['provider_match']:true,
			'post_type_binding'=>$postBinding,
			'post_type_observation'=>array('observed'=>$postObserved,'post_types'=>$postObserved?array($postType):array(),'source'=>'synthetic'),
			'post_type_observation_matches_profile'=>$postMatch,
			'unsupported_query_params'=>(array)($scenario['unsupported_query_params']??array()),
			'unknown_filters'=>(array)($scenario['unknown_filters']??array()),
			'malformed'=>(array)($scenario['malformed']??array()),
			'missing_terms'=>(array)($scenario['missing_terms']??array()),
			'translation_fallback'=>!empty($scenario['translation_fallback']),
			'language'=>sanitize_key((string)($scenario['language']??'en')),
			'filters'=>$filters,
			'terms'=>$terms,
			'result_count'=>array_key_exists('result_count',$scenario)&&is_numeric($scenario['result_count'])?(int)$scenario['result_count']:10,
			'result_count_source'=>'synthetic_simulator',
			'result_count_authoritative'=>array_key_exists('result_count_authoritative',$scenario)?(bool)$scenario['result_count_authoritative']:true,
			'content_readiness'=>array('required'=>true,'ready'=>array_key_exists('content_ready',$scenario)?(bool)$scenario['content_ready']:true),
		);
		$context['combination_authority']=$this->combinations->evaluate($context);
		$decision=$this->policy->decide($context);
		return array(
			'contract'=>'etg.dfsb.simulation.v1','synthetic'=>true,'configuration_revision'=>$this->config->revision(),
			'profile_id'=>$profileId,'taxonomy_set'=>ProfileRegistry::taxonomySetSignature($filters),'scenario'=>$this->safeScenario($scenario),
			'combination_authority'=>$context['combination_authority'],'decision'=>$decision,
		);
	}

	private function safeScenario( array $scenario ): array {
		$allowed=array('profile_id','post_type','language','filters','term_meta','result_count','result_count_authoritative','runtime_ready','provider_match','content_ready','elementor_content_verified','translation_fallback','unsupported_query_params','unknown_filters','malformed','missing_terms');
		return array_intersect_key($scenario,array_flip($allowed));
	}
}

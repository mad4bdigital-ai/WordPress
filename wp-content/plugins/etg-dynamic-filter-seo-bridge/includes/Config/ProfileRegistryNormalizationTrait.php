<?php
namespace ETG\DynamicFilterSEOBridge\Config;

trait ProfileRegistryNormalizationTrait {
	private function normalizeProfile( array $profile ): array {
		$id = sanitize_key( (string) ( $profile['id'] ?? '' ) );
		if ( count( (array) ( $profile['routes'] ?? array() ) ) > self::MAX_ROUTES ) { $this->normalizationErrors[] = 'profile:' . $id . ':route_limit_exceeded'; }
		if ( count( (array) ( $profile['taxonomy_rules'] ?? array() ) ) > self::MAX_TAXONOMY_RULES ) { $this->normalizationErrors[] = 'profile:' . $id . ':taxonomy_rule_limit_exceeded'; }
		if ( count( (array) ( $profile['indexable_combinations'] ?? array() ) ) > self::MAX_COMBINATIONS ) { $this->normalizationErrors[] = 'profile:' . $id . ':combination_registry_limit_exceeded'; }
		$out = array(
			'id'=>$id,'enabled'=>$this->boolValue($profile['enabled']??false),'inherit_global_defaults'=>$this->boolValue($profile['inherit_global_defaults']??false),
			'post_types'=>$this->listValue($profile['post_types']??array(),'sanitize_key'),'require_post_type_binding'=>$this->boolValue($profile['require_post_type_binding']??false),'post_type_authority'=>$this->enumValue($profile['post_type_authority']??'query_builder',array('query_builder','main_query','either','both'),'query_builder'),
			'require_provider_observation_for_index'=>$this->boolValue($profile['require_provider_observation_for_index']??true),
			'archive_slugs'=>$this->listValue($profile['archive_slugs']??array(),'sanitize_title'),'archive_paths'=>$this->pathList($profile['archive_paths']??array()),'providers'=>$this->listValue($profile['providers']??array(),'sanitize_key'),'query_ids'=>$this->listValue($profile['query_ids']??array(),'sanitize_key'),'routes'=>$this->routesValue($profile['routes']??array()),
			'max_filters'=>$this->boundedInt($profile['max_filters']??3,1,10),'composition_mode'=>$this->enumValue($profile['composition_mode']??'generic',array('generic','travel'),'generic'),'canonical_mode'=>$this->enumValue($profile['canonical_mode']??'filtered',array('filtered','archive'),'filtered'),
			'require_exact_combination_approval'=>$this->boolValue($profile['require_exact_combination_approval']??true),'require_exact_for_single'=>$this->boolValue($profile['require_exact_for_single']??false),'indexable_combinations'=>array_slice($this->lineList($profile['indexable_combinations']??array()),0,self::MAX_COMBINATIONS),
			'allowed_taxonomy_sets'=>array(),'min_results_by_depth'=>array(),'taxonomy_rules'=>array(),'content'=>array(),'publication'=>array(),
		);
		foreach ( array_slice( (array) ( $profile['taxonomy_rules'] ?? array() ), 0, self::MAX_TAXONOMY_RULES, true ) as $taxonomy => $rule ) {
			$taxonomy=sanitize_key((string)$taxonomy); if(''===$taxonomy||!is_array($rule)){continue;}
			$requiredMetaValues=$this->listValue($rule['required_meta_values']??array(),'sanitize_title');$scope=sanitize_key((string)($rule['meta_constraint_scope']??'single'));
			$out['taxonomy_rules'][$taxonomy]=array('role'=>sanitize_key((string)($rule['role']??$taxonomy))?:$taxonomy,'priority'=>$this->boundedInt($rule['priority']??100,0,10000),'gallery_priority'=>$this->boundedInt($rule['gallery_priority']??($rule['priority']??100),0,10000),'index_single'=>$this->boolValue($rule['index_single']??false),'min_results'=>$this->boundedInt($rule['min_results']??1,1,1000000),'required_meta_key'=>sanitize_key((string)($rule['required_meta_key']??'')),'required_meta_values'=>$requiredMetaValues,'meta_constraint_scope'=>in_array($scope,array('single','always'),true)?$scope:'single','field_map'=>$this->fieldMapValue($rule['field_map']??array()));
		}
		foreach((array)($profile['allowed_taxonomy_sets']??array()) as $set){$signature=$this->normalizeTaxonomySet($set);if(''!==$signature){$out['allowed_taxonomy_sets'][]=$signature;}}
		$out['allowed_taxonomy_sets']=array_values(array_unique($out['allowed_taxonomy_sets']));
		foreach((array)($profile['min_results_by_depth']??array()) as $depth=>$minimum){$depth=is_numeric($depth)?(int)$depth:0;if($depth<1||$depth>10){continue;}$out['min_results_by_depth'][(string)$depth]=$this->boundedInt($minimum,1,1000000);}
		$content=is_array($profile['content']??null)?$profile['content']:array();
		$out['content']=array('required'=>$this->boolValue($content['required']??true),'require_meta_description'=>$this->boolValue($content['require_meta_description']??true),'min_chars'=>$this->boundedInt($content['min_chars']??250,0,10000),'min_chars_by_depth'=>$this->depthIntMap($content['min_chars_by_depth']??array(),0,10000),'min_unique_segments_by_depth'=>$this->depthIntMap($content['min_unique_segments_by_depth']??array(),0,50));
		$publication=is_array($profile['publication']??null)?$profile['publication']:array();
		$out['publication']=array('sitemap'=>$this->boolValue($publication['sitemap']??true),'hreflang'=>$this->boolValue($publication['hreflang']??true),'schema'=>$this->boolValue($publication['schema']??true),'social'=>$this->boolValue($publication['social']??true),'include_images_in_sitemap'=>$this->boolValue($publication['include_images_in_sitemap']??true),'require_elementor_content'=>$this->boolValue($publication['require_elementor_content']??true),'elementor_render_when_global_off'=>$this->boolValue($publication['elementor_render_when_global_off']??false),'elementor_content_verified'=>$this->boolValue($publication['elementor_content_verified']??false),'elementor_verification_evidence_id'=>$this->evidenceId($publication['elementor_verification_evidence_id']??''),'elementor_verification_authority_fingerprint'=>$this->fingerprintValue($publication['elementor_verification_authority_fingerprint']??''),'provider_observation_verified'=>$this->boolValue($publication['provider_observation_verified']??false),'provider_observation_evidence_id'=>$this->evidenceId($publication['provider_observation_evidence_id']??''),'provider_observation_authority_fingerprint'=>$this->fingerprintValue($publication['provider_observation_authority_fingerprint']??''),'require_result_count_parity_for_publication'=>$this->boolValue($publication['require_result_count_parity_for_publication']??true),'result_count_parity_verified'=>$this->boolValue($publication['result_count_parity_verified']??false),'result_count_parity_evidence_id'=>$this->evidenceId($publication['result_count_parity_evidence_id']??''),'result_count_parity_authority_fingerprint'=>$this->fingerprintValue($publication['result_count_parity_authority_fingerprint']??''),'max_preview_urls'=>$this->boundedInt($publication['max_preview_urls']??25,1,100),'max_publication_urls'=>$this->boundedInt($publication['max_publication_urls']??10,1,500));
		if(!empty($out['inherit_global_defaults'])){$out=$this->applyGlobalDefaults($out);}
		$out=$this->filterAllowedTaxonomySets($out);
		$fingerprint=ProfileRegistry::authorityFingerprint($out);$out['authority_fingerprint']=$fingerprint;
		$out['publication']['elementor_evidence_current']=$this->evidenceCurrent($out['publication']['elementor_content_verified'],$out['publication']['elementor_verification_evidence_id'],$out['publication']['elementor_verification_authority_fingerprint'],$fingerprint);
		$out['publication']['provider_observation_evidence_current']=$this->evidenceCurrent($out['publication']['provider_observation_verified'],$out['publication']['provider_observation_evidence_id'],$out['publication']['provider_observation_authority_fingerprint'],$fingerprint);
		$out['publication']['result_count_parity_evidence_current']=$this->evidenceCurrent($out['publication']['result_count_parity_verified'],$out['publication']['result_count_parity_evidence_id'],$out['publication']['result_count_parity_authority_fingerprint'],$fingerprint);
		return $out;
	}

	private function applyGlobalDefaults( array $profile ): array {
		$profile['max_filters']=(int)$this->config->get('max_filters',$profile['max_filters']);
		$profile['require_provider_observation_for_index']=(bool)$this->config->get('require_provider_observation_for_index',$profile['require_provider_observation_for_index']);
		if(isset($profile['taxonomy_rules']['location_jet'])){$profile['taxonomy_rules']['location_jet']['min_results']=(int)$this->config->get('min_results_location',$profile['taxonomy_rules']['location_jet']['min_results']??1);$profile['taxonomy_rules']['location_jet']['required_meta_values']=(array)$this->config->get('indexable_location_levels',$profile['taxonomy_rules']['location_jet']['required_meta_values']??array());}
		if(isset($profile['taxonomy_rules']['tour-types_jet'])){$profile['taxonomy_rules']['tour-types_jet']['index_single']=(bool)$this->config->get('index_single_tour_type',$profile['taxonomy_rules']['tour-types_jet']['index_single']??false);}
		$profile['min_results_by_depth']['1']=(int)$this->config->get('min_results_location',$profile['min_results_by_depth']['1']??1);$profile['min_results_by_depth']['2']=(int)$this->config->get('min_results_pair',$profile['min_results_by_depth']['2']??3);$profile['min_results_by_depth']['3']=(int)$this->config->get('min_results_triple',$profile['min_results_by_depth']['3']??3);
		$profile['require_exact_combination_approval']=(bool)$this->config->get('require_exact_combination_approval',$profile['require_exact_combination_approval']);
		$profile['content']['required']=(bool)$this->config->get('require_content_readiness',$profile['content']['required']??true);$profile['content']['require_meta_description']=(bool)$this->config->get('require_meta_description',$profile['content']['require_meta_description']??true);$profile['content']['min_chars']=(int)$this->config->get('min_content_chars',$profile['content']['min_chars']??250);
		$profile['canonical_mode']=(string)$this->config->get('canonical_mode',$profile['canonical_mode']);
		$profile['publication']['max_publication_urls']=min((int)$profile['publication']['max_publication_urls'],(int)$this->config->get('publication_max_urls',10));return $profile;
	}

	private function filterAllowedTaxonomySets(array $profile):array{$rules=array_fill_keys(array_keys((array)($profile['taxonomy_rules']??array())),true);$sets=array();foreach((array)($profile['allowed_taxonomy_sets']??array()) as $set){$signature=$this->normalizeTaxonomySet($set);if(''===$signature){continue;}$valid=true;foreach(explode('+',$signature) as $taxonomy){if(!isset($rules[$taxonomy])){$valid=false;break;}}if($valid){$sets[]=$signature;}}$profile['allowed_taxonomy_sets']=array_values(array_unique($sets));return $profile;}
	private function depthIntMap($value,int $min,int $max):array{$out=array();foreach((array)$value as $depth=>$number){$depth=is_numeric($depth)?(int)$depth:0;if($depth<1||$depth>10){continue;}$out[(string)$depth]=$this->boundedInt($number,$min,$max);}return $out;}
	private function evidenceId($value):string{$value=trim((string)$value);if(strlen($value)>160){$value=substr($value,0,160);}$value=preg_replace('/[^A-Za-z0-9:_\-.]/','',$value);return is_string($value)?$value:'';}
	private function fingerprintValue($value):string{$value=strtolower(trim((string)$value));return preg_match('/^[a-f0-9]{64}$/',$value)?$value:'';}
	private function evidenceCurrent(bool $verified,string $id,string $bound,string $current):bool{return $verified&&$this->evidenceIdValid($id)&&''!==$bound&&hash_equals($current,$bound);}
	private function evidenceIdValid(string $id):bool{return (bool)preg_match('/^sha256:[a-fA-F0-9]{64}$/',$id);}
}

<?php
namespace ETG\DynamicFilterSEOBridge\Config;

final class Configuration {
	public const OPTION_NAME = 'etg_dfsb_settings';

	public function defaults(): array {
		$base = $this->defaultsWithoutFilters();
		if ( ! function_exists( 'apply_filters' ) ) { return $base; }
		$proposal = apply_filters( 'etg_filter_seo_configuration_defaults', $base );
		if ( ! is_array( $proposal ) ) { return $base; }
		return $this->narrowConfiguration( $base, $this->sanitize( $proposal ) );
	}

	public function all(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION_NAME, array() ) : array();
		$stored = is_array( $stored ) ? $stored : array();
		$base = $this->sanitize( array_merge( $this->defaults(), $stored ) );
		if ( ! function_exists( 'apply_filters' ) ) { return $base; }
		$proposal = apply_filters( 'etg_filter_seo_configuration', $base );
		if ( ! is_array( $proposal ) ) { return $base; }
		return $this->narrowConfiguration( $base, $this->sanitize( $proposal ) );
	}

	public function get( string $key, $default = null ) { $config=$this->all(); return array_key_exists($key,$config)?$config[$key]:$default; }
	public function enabled(): bool { return (bool) $this->get( 'enabled', false ); }
	public function revision(): string { $encoded=function_exists('wp_json_encode')?wp_json_encode($this->all()):json_encode($this->all()); return substr(hash('sha256',(string)$encoded),0,16); }

	public function sanitize( $input ): array {
		$input=is_array($input)?$input:array();$d=$this->defaultsWithoutFilters();$out=array();
		$out['enabled']=$this->boolValue($input,'enabled',$d['enabled']);
		$out['archive_slugs']=$this->slugList($input['archive_slugs']??$d['archive_slugs']);
		$out['providers']=$this->keyList($input['providers']??$d['providers']);
		$out['query_ids']=$this->keyList($input['query_ids']??$d['query_ids']);
		$out['allowed_taxonomies']=$this->keyList($input['allowed_taxonomies']??$d['allowed_taxonomies']);
		$out['max_filters']=$this->boundedInt($input['max_filters']??$d['max_filters'],1,10);
		$out['allowed_query_params']=$this->keyList($input['allowed_query_params']??$d['allowed_query_params']);
		$out['tracking_query_params']=$this->keyList($input['tracking_query_params']??$d['tracking_query_params']);
		$out['enable_jet_engine_result_count_adapter']=$this->boolValue($input,'enable_jet_engine_result_count_adapter',$d['enable_jet_engine_result_count_adapter']);
		$out['trust_legacy_result_count']=$this->boolValue($input,'trust_legacy_result_count',$d['trust_legacy_result_count']);
		$out['trusted_result_count_authority_sources']=$this->keyList($input['trusted_result_count_authority_sources']??$d['trusted_result_count_authority_sources']);
		$out['require_result_count_for_index']=$this->boolValue($input,'require_result_count_for_index',$d['require_result_count_for_index']);
		$out['require_provider_observation_for_index']=$this->boolValue($input,'require_provider_observation_for_index',$d['require_provider_observation_for_index']);
		$out['min_results_location']=$this->boundedInt($input['min_results_location']??$d['min_results_location'],1,1000000);
		$out['min_results_pair']=$this->boundedInt($input['min_results_pair']??$d['min_results_pair'],1,1000000);
		$out['min_results_triple']=$this->boundedInt($input['min_results_triple']??$d['min_results_triple'],1,1000000);
		$out['index_single_tour_type']=$this->boolValue($input,'index_single_tour_type',$d['index_single_tour_type']);
		$out['indexable_location_levels']=$this->keyList($input['indexable_location_levels']??$d['indexable_location_levels']);
		$out['require_exact_combination_approval']=$this->boolValue($input,'require_exact_combination_approval',$d['require_exact_combination_approval']);
		$out['indexable_combinations']=$this->lineList($input['indexable_combinations']??$d['indexable_combinations']);
		$out['require_content_readiness']=$this->boolValue($input,'require_content_readiness',$d['require_content_readiness']);
		$out['require_meta_description']=$this->boolValue($input,'require_meta_description',$d['require_meta_description']);
		$out['min_content_chars']=$this->boundedInt($input['min_content_chars']??$d['min_content_chars'],0,10000);
		$canonical=sanitize_key((string)($input['canonical_mode']??$d['canonical_mode']));$out['canonical_mode']=in_array($canonical,array('filtered','archive'),true)?$canonical:'filtered';
		$out['publication_max_urls']=$this->boundedInt($input['publication_max_urls']??$d['publication_max_urls'],1,500);
		$out['publication_candidate_evaluation_budget']=$this->boundedInt($input['publication_candidate_evaluation_budget']??$d['publication_candidate_evaluation_budget'],1,500);
		$out['publication_cache_ttl']=$this->boundedInt($input['publication_cache_ttl']??$d['publication_cache_ttl'],300,86400);
		$out['diagnostics_enabled']=$this->boolValue($input,'diagnostics_enabled',$d['diagnostics_enabled']);
		$out['log_decisions']=$this->boolValue($input,'log_decisions',$d['log_decisions']);
		$out['profiles_json']=$this->profilesJson($input['profiles_json']??$d['profiles_json']);
		return $out;
	}

	public function validationErrors(): array {$config=$this->all();$raw=(string)($config['profiles_json']??'');if(''===$raw){return array('profiles_json_invalid');}$decoded=json_decode($raw,true);if(JSON_ERROR_NONE!==json_last_error()||!is_array($decoded)||empty($decoded)){return array('profiles_json_invalid');}return array();}

	private function defaultsWithoutFilters(): array {
		return array(
			'enabled'=>false,'archive_slugs'=>array('tours-and-activities'),'providers'=>array('jet-engine'),'query_ids'=>array('tours_query_archive'),'allowed_taxonomies'=>array('location_jet','tour-types_jet','tour-styles_jet'),'max_filters'=>3,
			'allowed_query_params'=>array(),'tracking_query_params'=>array('gclid','fbclid','msclkid'),'enable_jet_engine_result_count_adapter'=>true,'trust_legacy_result_count'=>false,'trusted_result_count_authority_sources'=>array(),'require_result_count_for_index'=>true,'require_provider_observation_for_index'=>true,
			'min_results_location'=>1,'min_results_pair'=>3,'min_results_triple'=>3,'index_single_tour_type'=>false,'indexable_location_levels'=>array('city','landmark'),'require_exact_combination_approval'=>true,'indexable_combinations'=>array(),
			'require_content_readiness'=>true,'require_meta_description'=>true,'min_content_chars'=>250,'canonical_mode'=>'filtered','publication_max_urls'=>10,'publication_candidate_evaluation_budget'=>50,'publication_cache_ttl'=>21600,'diagnostics_enabled'=>true,'log_decisions'=>false,'profiles_json'=>$this->defaultProfilesJson(),
		);
	}

	private function defaultProfilesJson(): string {
		$profiles=array(array(
			'id'=>'tours','enabled'=>false,'inherit_global_defaults'=>true,'post_types'=>array(),'require_post_type_binding'=>false,'post_type_authority'=>'query_builder','require_provider_observation_for_index'=>true,
			'archive_slugs'=>array('tours-and-activities'),'archive_paths'=>array('/tours-and-activities/'),'providers'=>array('jet-engine'),'query_ids'=>array('tours_query_archive'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'tours_query_archive')),
			'max_filters'=>3,'composition_mode'=>'travel','canonical_mode'=>'filtered','require_exact_combination_approval'=>true,'require_exact_for_single'=>false,'allowed_taxonomy_sets'=>array('location_jet','location_jet+tour-types_jet','location_jet+tour-types_jet+tour-styles_jet'),'min_results_by_depth'=>array('1'=>1,'2'=>3,'3'=>3),
			'taxonomy_rules'=>array('location_jet'=>array('role'=>'location','priority'=>10,'gallery_priority'=>20,'index_single'=>true,'min_results'=>1,'required_meta_key'=>'location_level','required_meta_values'=>array('city','landmark'),'meta_constraint_scope'=>'single'),'tour-types_jet'=>array('role'=>'tour_type','priority'=>20,'gallery_priority'=>30,'index_single'=>false,'min_results'=>3),'tour-styles_jet'=>array('role'=>'style','priority'=>30,'gallery_priority'=>10,'index_single'=>false,'min_results'=>3)),
			'indexable_combinations'=>array(),'content'=>array('required'=>true,'require_meta_description'=>true,'min_chars'=>250,'min_chars_by_depth'=>array('1'=>250,'2'=>400,'3'=>500),'min_unique_segments_by_depth'=>array('1'=>1,'2'=>2,'3'=>2)),
			'publication'=>array('sitemap'=>true,'hreflang'=>true,'schema'=>true,'social'=>true,'include_images_in_sitemap'=>true,'require_elementor_content'=>true,'elementor_render_when_global_off'=>false,'elementor_content_verified'=>false,'elementor_verification_evidence_id'=>'','elementor_verification_authority_fingerprint'=>'','provider_observation_verified'=>false,'provider_observation_evidence_id'=>'','provider_observation_authority_fingerprint'=>'','require_result_count_parity_for_publication'=>true,'result_count_parity_verified'=>false,'result_count_parity_evidence_id'=>'','result_count_parity_authority_fingerprint'=>'','max_preview_urls'=>25,'max_publication_urls'=>10),
		));
		return (string)json_encode($profiles,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
	}

	private function narrowConfiguration(array $base,array $proposal):array{
		$out=$base;$intersect=function($a,$b){return array_values(array_intersect((array)$a,(array)$b));};
		$out['enabled']=!empty($base['enabled'])&&!empty($proposal['enabled']);
		foreach(array('archive_slugs','providers','query_ids','allowed_taxonomies','allowed_query_params','tracking_query_params','indexable_location_levels','indexable_combinations','trusted_result_count_authority_sources') as $key){$out[$key]=$intersect($base[$key]??array(),$proposal[$key]??array());}
		foreach(array('enable_jet_engine_result_count_adapter','trust_legacy_result_count','index_single_tour_type','diagnostics_enabled','log_decisions') as $key){$out[$key]=!empty($base[$key])&&!empty($proposal[$key]);}
		foreach(array('require_result_count_for_index','require_provider_observation_for_index','require_exact_combination_approval','require_content_readiness','require_meta_description') as $key){$out[$key]=!empty($base[$key])||!empty($proposal[$key]);}
		$out['max_filters']=min((int)$base['max_filters'],(int)$proposal['max_filters']);
		foreach(array('min_results_location','min_results_pair','min_results_triple','min_content_chars') as $key){$out[$key]=max((int)$base[$key],(int)$proposal[$key]);}
		$out['canonical_mode']=(string)$base['canonical_mode'];$out['publication_max_urls']=min((int)$base['publication_max_urls'],(int)$proposal['publication_max_urls']);$out['publication_candidate_evaluation_budget']=min((int)$base['publication_candidate_evaluation_budget'],(int)$proposal['publication_candidate_evaluation_budget']);$out['publication_cache_ttl']=min((int)$base['publication_cache_ttl'],(int)$proposal['publication_cache_ttl']);$out['profiles_json']=(string)$base['profiles_json'];return $out;
	}

	private function profilesJson($value):string{$valid=true;if(is_array($value)){$decoded=$value;}else{$value=trim((string)$value);if(''===$value||strlen($value)>1000000){$valid=false;$decoded=array();}else{$decoded=json_decode($value,true);if(JSON_ERROR_NONE!==json_last_error()||!is_array($decoded)){$valid=false;$decoded=array();}}}if(!$valid){if(function_exists('add_settings_error')){add_settings_error('etg_dfsb','profiles_json_invalid','Surface Profiles JSON is invalid; the previous valid profile snapshot was preserved.','error');}return $this->previousProfilesJson();}if(count($decoded)>50){if(function_exists('add_settings_error')){add_settings_error('etg_dfsb','profiles_limit','Surface Profiles JSON exceeds the 50-profile Alpha limit; the previous valid snapshot was preserved.','error');}return $this->previousProfilesJson();}$encoded=json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return is_string($encoded)?$encoded:$this->previousProfilesJson();}
	private function previousProfilesJson():string{if(function_exists('get_option')){$stored=get_option(self::OPTION_NAME,array());$previous=is_array($stored)?(string)($stored['profiles_json']??''):'';if(''!==$previous){$decoded=json_decode($previous,true);if(JSON_ERROR_NONE===json_last_error()&&is_array($decoded)){return $previous;}}}return $this->defaultProfilesJson();}
	private function boolValue(array $input,string $key,bool $default):bool{if(!array_key_exists($key,$input)){return $default;}$value=$input[$key];if(is_string($value)){return in_array(strtolower($value),array('1','true','yes','on'),true);}return(bool)$value;}
	private function keyList($value):array{return $this->normalizeList($value,'sanitize_key');}private function slugList($value):array{return $this->normalizeList($value,'sanitize_title');}
	private function lineList($value):array{if(is_string($value)){$value=preg_split('/[\r\n]+/',$value);}$out=array();foreach(is_array($value)?$value:array() as $line){$line=strtolower(trim((string)$line));if(''!==$line){$out[]=$line;}}return array_values(array_unique($out));}
	private function normalizeList($value,string $sanitizer):array{if(is_string($value)){$value=preg_split('/[\r\n,]+/',$value);}$out=array();foreach(is_array($value)?$value:array() as $item){$item=call_user_func($sanitizer,(string)$item);if(''!==$item){$out[]=$item;}}return array_values(array_unique($out));}
	private function boundedInt($value,int $min,int $max):int{$value=is_numeric($value)?(int)$value:$min;return max($min,min($max,$value));}
}

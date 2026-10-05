<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Approval_Impact_Binding {
	const CONTRACT = 'mad4b.approval-impact-binding.v1';
	const MAX_DEPTH = 8;

	public static function build( $ability_name, $provider, $target_fingerprint, $input ) {
		$ability_name = trim( (string) $ability_name );
		$provider = sanitize_key( (string) $provider );
		if ( '' === $provider ) $provider = 'core';
		$input = is_array( $input ) ? $input : array();

		$resource_set = class_exists( 'MAD4B_SCP_Resource_Constraint_Set' )
			? MAD4B_SCP_Resource_Constraint_Set::compile( $ability_name, $provider, $input ) : array();
		if ( is_wp_error( $resource_set ) ) return $resource_set;

		$classification = class_exists( 'MAD4B_SCP_Impact_Policy' )
			? MAD4B_SCP_Impact_Policy::classify( $ability_name, $provider, $input )
			: array( 'impact'=>'high','risk_tier'=>'high','side_effect_scope'=>'governed','mutation_kind'=>'mutate','approval_required'=>true,'rollback_evidence_required'=>true,'classification_sha256'=>'' );
		if ( is_wp_error( $classification ) ) return $classification;

		$descriptor = class_exists( 'MAD4B_SCP_Capability_Descriptor_Registry' )
			? MAD4B_SCP_Capability_Descriptor_Registry::describe( $ability_name ) : array();
		if ( is_wp_error( $descriptor ) ) return $descriptor;

		$provider_generation = self::provider_generation( $provider );
		if ( is_wp_error( $provider_generation ) ) return $provider_generation;
		$dependency_impact = self::dependency_impact( $provider, $input );
		if ( is_wp_error( $dependency_impact ) ) return $dependency_impact;

		$input_sha = self::digest( 'mad4b.approval-input.v1', $input );
		if ( is_wp_error( $input_sha ) ) return $input_sha;
		$generation_sha = self::digest( 'mad4b.approval-dependency-generation.v1', array(
			'capability_descriptor_sha256'=>isset($descriptor['descriptor_sha256'])?(string)$descriptor['descriptor_sha256']:'',
			'capability_generation_roots'=>isset($descriptor['generation_roots'])&&is_array($descriptor['generation_roots'])?$descriptor['generation_roots']:array(),
			'provider_generation'=>$provider_generation,
		) );
		if ( is_wp_error( $generation_sha ) ) return $generation_sha;

		$reasons = isset($dependency_impact['impact_reasons'])&&is_array($dependency_impact['impact_reasons'])?array_values($dependency_impact['impact_reasons']):array();
		sort($reasons,SORT_STRING);
		$blast = array(
			'impact'=>isset($classification['impact'])?sanitize_key((string)$classification['impact']):'high',
			'risk_tier'=>isset($classification['risk_tier'])?sanitize_key((string)$classification['risk_tier']):'high',
			'side_effect_scope'=>isset($classification['side_effect_scope'])?sanitize_key((string)$classification['side_effect_scope']):'governed',
			'mutation_kind'=>isset($classification['mutation_kind'])?sanitize_key((string)$classification['mutation_kind']):'mutate',
			'approval_required'=>!empty($classification['approval_required']),
			'rollback_evidence_required'=>!empty($classification['rollback_evidence_required']),
			'target_fingerprint'=>(string)$target_fingerprint,
			'resource_set_sha256'=>isset($resource_set['resource_set_sha256'])?(string)$resource_set['resource_set_sha256']:'',
			'wordpress_dependents_count'=>isset($dependency_impact['wordpress_dependents'])&&is_array($dependency_impact['wordpress_dependents'])?count($dependency_impact['wordpress_dependents']):0,
			'affected_addons_count'=>isset($dependency_impact['affected_addons'])&&is_array($dependency_impact['affected_addons'])?count($dependency_impact['affected_addons']):0,
			'certification_revalidation_required'=>!empty($dependency_impact['certification_revalidation_required']),
			'impact_reasons'=>$reasons,
		);
		$impact_sha = self::digest( 'mad4b.approval-impact.v1', array(
			'classification_sha256'=>isset($classification['classification_sha256'])?(string)$classification['classification_sha256']:'',
			'blast_radius'=>$blast,
		) );
		if ( is_wp_error( $impact_sha ) ) return $impact_sha;

		$out = array(
			'contract'=>self::CONTRACT,'ability'=>$ability_name,'provider'=>$provider,
			'exact_input_sha256'=>$input_sha,
			'resource_set_sha256'=>isset($resource_set['resource_set_sha256'])?(string)$resource_set['resource_set_sha256']:'',
			'dependency_generation_sha256'=>$generation_sha,
			'impact_sha256'=>$impact_sha,
			'classification_sha256'=>isset($classification['classification_sha256'])?(string)$classification['classification_sha256']:'',
			'blast_radius'=>$blast,'authorizing'=>false,
		);
		$binding_sha = self::digest( self::CONTRACT, $out );
		if ( is_wp_error( $binding_sha ) ) return $binding_sha;
		$out['binding_sha256']=$binding_sha;
		return $out;
	}

	private static function provider_generation( $provider ) {
		if ( 'core' === $provider || ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) {
			return array( 'provider'=>$provider,'status'=>'core_or_unavailable','generation_sha256'=>hash('sha256','provider|'.$provider.'|core_or_unavailable') );
		}
		$status=MAD4B_SCP_Provider_Contracts::runtime_status($provider);
		if(!is_array($status))return new WP_Error('mad4b_approval_provider_generation_unavailable','Provider generation evidence is unavailable.');
		$stable=array(
			'provider'=>$provider,'status'=>isset($status['status'])?(string)$status['status']:'',
			'certified_version'=>isset($status['certified_version'])?(string)$status['certified_version']:'',
			'installed_version'=>isset($status['installed_version'])?(string)$status['installed_version']:'',
			'certification_authority'=>isset($status['certification_authority'])?(string)$status['certification_authority']:'',
			'contract_mode'=>isset($status['contract_mode'])?(string)$status['contract_mode']:'',
			'runtime_contract_ok'=>!empty($status['runtime_contract_ok']),
		);
		$stable['generation_sha256']=self::digest('mad4b.provider-generation.v1',$stable);
		return $stable;
	}

	private static function dependency_impact( $provider, array $input ) {
		if(!class_exists('MAD4B_SCP_Dependency_Impact_Graph'))return array('wordpress_dependents'=>array(),'affected_addons'=>array(),'certification_revalidation_required'=>false,'impact_reasons'=>array());
		$plugin=isset($input['plugin'])?(string)$input['plugin']:(isset($input['plugin_file'])?(string)$input['plugin_file']:'');
		if(''===$plugin&&'core'===$provider)return array('wordpress_dependents'=>array(),'affected_addons'=>array(),'certification_revalidation_required'=>false,'impact_reasons'=>array());
		$impact=MAD4B_SCP_Dependency_Impact_Graph::inspect(array('plugin'=>$plugin,'provider_id'=>$provider));
		return is_wp_error($impact)?$impact:(is_array($impact)?$impact:array());
	}

	private static function digest( $domain, $value ) {
		$canonical=self::canonicalize($value,0); if(is_wp_error($canonical))return $canonical;
		$json=function_exists('wp_json_encode')?wp_json_encode(array('domain'=>(string)$domain,'value'=>$canonical),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):json_encode(array('domain'=>(string)$domain,'value'=>$canonical),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		return is_string($json)?hash('sha256',$json):new WP_Error('mad4b_approval_binding_encode_failed','Approval impact binding could not be canonically encoded.');
	}
	private static function canonicalize($value,$depth){
		if($depth>self::MAX_DEPTH)return new WP_Error('mad4b_approval_binding_depth_exceeded','Approval impact binding exceeds the canonical depth limit.');
		if(is_array($value)){ $keys=array_keys($value);$list=empty($value)||$keys===range(0,count($value)-1); if($list){$o=array();foreach($value as $v){$n=self::canonicalize($v,$depth+1);if(is_wp_error($n))return $n;$o[]=$n;}return $o;} ksort($value,SORT_STRING);$o=array();foreach($value as $k=>$v){$n=self::canonicalize($v,$depth+1);if(is_wp_error($n))return $n;$o[(string)$k]=$n;}return $o;}
		if(is_object($value)||is_resource($value))return new WP_Error('mad4b_approval_binding_value_invalid','Approval impact binding contains an unsupported value.');
		if(is_float($value)&&(is_nan($value)||is_infinite($value)))return new WP_Error('mad4b_approval_binding_float_invalid','Approval impact binding contains a non-finite number.');
		return $value;
	}
}

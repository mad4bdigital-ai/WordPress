<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Dynamic_Provider_Contract {
	const CONTRACT='mad4b.dynamic-provider-manifest.v1';
	const MAX_PROVIDERS=64;

	public static function manifests(){
		$raw=apply_filters('mad4b_scp_dynamic_provider_manifests',array());
		$out=array();
		foreach(array_slice(is_array($raw)?$raw:array(),0,self::MAX_PROVIDERS) as $manifest){
			$n=self::normalize($manifest);
			if(!is_wp_error($n)) $out[$n['provider_id']]=$n;
		}
		ksort($out,SORT_STRING);
		return array_values($out);
	}

	public static function normalize($manifest){
		if(!is_array($manifest)) return new WP_Error('mad4b_dynamic_provider_manifest_invalid','Provider manifest must be an array.');
		$id=isset($manifest['provider_id'])?sanitize_key((string)$manifest['provider_id']):'';
		$registry=isset($manifest['existing_registry_identity'])?sanitize_key((string)$manifest['existing_registry_identity']):'';
		if($id===''||$registry==='') return new WP_Error('mad4b_dynamic_provider_identity_missing','Provider manifest requires provider_id and existing_registry_identity.');
		$effects=array();
		foreach(isset($manifest['side_effects'])&&is_array($manifest['side_effects'])?array_slice($manifest['side_effects'],0,100):array() as $effect){
			if(!is_array($effect)) continue;
			$effects[]=array(
				'effect_scope'=>isset($effect['effect_scope'])?sanitize_key((string)$effect['effect_scope']):'unknown',
				'ownership'=>self::enum($effect,'ownership',array('core','provider','external','shared'),'external'),
				'reversibility'=>self::enum($effect,'reversibility',array('reversible','irreversible','unknown'),'unknown'),
				'compensation_support'=>self::enum($effect,'compensation_support',array('none','best_effort','verified'),'none'),
				'externality'=>self::enum($effect,'externality',array('local','remote','external_system'),'external_system'),
			);
		}
		return array(
			'contract'=>self::CONTRACT,
			'provider_id'=>$id,
			'existing_registry_identity'=>$registry,
			'implementation_version'=>isset($manifest['implementation_version'])?substr(sanitize_text_field((string)$manifest['implementation_version']),0,64):'',
			'supports'=>isset($manifest['supports'])&&is_array($manifest['supports'])?array_values(array_unique(array_map('sanitize_key',array_slice($manifest['supports'],0,100)))):array(),
			'side_effects'=>$effects,
			'soft_elapsed_budget_ms'=>isset($manifest['soft_elapsed_budget_ms'])?max(1,min(30000,absint($manifest['soft_elapsed_budget_ms']))):30000,
			'enforceable_deadline_supported'=>!empty($manifest['enforceable_deadline_supported']),
			'manifest_presence_authorizes'=>false,
		);
	}
	private static function enum(array $a,$key,array $allowed,$fallback){ $v=isset($a[$key])?sanitize_key((string)$a[$key]):''; return in_array($v,$allowed,true)?$v:$fallback; }
}

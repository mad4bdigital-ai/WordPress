<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Dynamic_TTL_Policy {
	const CONTRACT='mad4b.dynamic-ttl-policy.v1';

	public static function decide( $purpose, array $impact, array $context=array() ) {
		$purpose=sanitize_key((string)$purpose);
		if(!in_array($purpose,array('acceptance','mutation_lock'),true)) return new WP_Error('mad4b_dynamic_ttl_purpose_invalid','TTL purpose is invalid.');
		$level=isset($impact['impact_level'])?sanitize_key((string)$impact['impact_level']):'medium';
		if(!in_array($level,array('low','medium','high'),true)) $level='high';
		$flags=isset($impact['impact_flags'])&&is_array($impact['impact_flags'])?array_values(array_unique(array_map('sanitize_key',$impact['impact_flags']))):array();
		$tier='standard';
		if('high'===$level||array_intersect($flags,array('publication','schema_change','external_side_effect','irreversible','recovery'))) $tier='short';
		elseif('low'===$level&&empty($flags)) $tier='long';
		$seconds=self::seconds($purpose,$tier);
		$hard=max($seconds,'mutation_lock'===$purpose?$seconds*2:$seconds);
		$refresh='mutation_lock'===$purpose?max(30,(int)floor($seconds*0.6)):null;
		$policy=array('purpose'=>$purpose,'impact_level'=>$level,'impact_flags'=>$flags,'tier'=>$tier,'selected_seconds'=>$seconds,'refresh_threshold_seconds'=>$refresh,'hard_deadline_seconds'=>$hard);
		$sha=class_exists('MAD4B_SCP_Canonicalization')?MAD4B_SCP_Canonicalization::digest('dynamic-ttl-policy:v1',$policy):'';
		if(is_wp_error($sha)) return $sha;
		$policy['contract']=self::CONTRACT;
		$policy['policy_sha256']=$sha;
		return $policy;
	}

	private static function seconds($purpose,$tier){
		$map=array(
			'acceptance'=>array('short'=>900,'standard'=>3600,'long'=>86400),
			'mutation_lock'=>array('short'=>300,'standard'=>900,'long'=>1800),
		);
		return $map[$purpose][$tier];
	}
}

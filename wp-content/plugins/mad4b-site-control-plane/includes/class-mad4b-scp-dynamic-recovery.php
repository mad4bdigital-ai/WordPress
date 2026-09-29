<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Dynamic_Recovery {
	const INSPECT_CONTRACT='mad4b.dynamic-recovery-inspect.v1';
	const PLAN_CONTRACT='mad4b.dynamic-recovery-plan.v1';
	const PLAN_TTL=900;

	public static function inspect($operation_id){
		if(!class_exists('MAD4B_SCP_Operation_Journal')) return new WP_Error('mad4b_recovery_journal_unavailable','Operation journal is unavailable.');
		$status=MAD4B_SCP_Operation_Journal::status($operation_id);
		if(is_wp_error($status)) return $status;
		$trace=MAD4B_SCP_Operation_Journal::trace($operation_id,1000);
		if(is_wp_error($trace)) return $trace;
		$blockers=array();
		if(empty($trace['chain_valid'])) $blockers[]='journal_chain_invalid';
		if(empty($trace['complete'])) $blockers[]='journal_trace_incomplete';
		$outcome=isset($status['terminal_outcome'])?(string)$status['terminal_outcome']:'';
		$state=isset($status['lifecycle_state'])?(string)$status['lifecycle_state']:'';
		$needs_recovery='recovery_required'===$outcome||!empty($status['orphan_candidate'])||'recovering'===$state;
		if('success'===$outcome&&'completed'===$state) $blockers[]='operation_already_completed';
		if('compensated'===$outcome) $blockers[]='operation_already_compensated';
		return array(
			'contract'=>self::INSPECT_CONTRACT,
			'operation_id'=>(string)$status['operation_id'],
			'operation_key'=>(string)$status['operation_key'],
			'operation_binding_sha256'=>(string)$status['operation_binding_sha256'],
			'journal_head_sha256'=>(string)$status['journal_head_sha256'],
			'lifecycle_state'=>$state,
			'terminal_outcome'=>$outcome,
			'needs_recovery'=>$needs_recovery,
			'blockers'=>$blockers,
			'ready'=>$needs_recovery&&empty($blockers),
			'status'=>$status,
			'trace_summary'=>array('count'=>$trace['count'],'chain_valid'=>$trace['chain_valid'],'complete'=>$trace['complete']),
			'read_only'=>true,'mutation_performed'=>false
		);
	}

	public static function plan(array $input){
		$operation_id=isset($input['operation_id'])?(string)$input['operation_id']:'';
		$inspect=self::inspect($operation_id);
		if(is_wp_error($inspect)) return $inspect;
		$required=array('current_state_sha256','provider_state_digest','pipeline_settings_sha256','policy_sha256','environment');
		foreach($required as $key){
			if(!isset($input[$key])||''===trim((string)$input[$key])) return new WP_Error('mad4b_recovery_plan_evidence_missing','Recovery plan requires exact current evidence.',array('missing'=>$key));
		}
		foreach(array('current_state_sha256','provider_state_digest','pipeline_settings_sha256','policy_sha256') as $key){
			if(1!==preg_match('/^[a-f0-9]{64}$/',strtolower((string)$input[$key]))) return new WP_Error('mad4b_recovery_plan_digest_invalid','Recovery plan digest is invalid.',array('field'=>$key));
		}
		$now=time();
		$basis=array(
			'operation_id'=>$inspect['operation_id'],
			'operation_key'=>$inspect['operation_key'],
			'operation_binding_sha256'=>$inspect['operation_binding_sha256'],
			'journal_head_sha256'=>$inspect['journal_head_sha256'],
			'current_state_sha256'=>strtolower((string)$input['current_state_sha256']),
			'provider_state_digest'=>strtolower((string)$input['provider_state_digest']),
			'pipeline_settings_sha256'=>strtolower((string)$input['pipeline_settings_sha256']),
			'policy_sha256'=>strtolower((string)$input['policy_sha256']),
			'environment'=>sanitize_key((string)$input['environment']),
			'generated_at'=>gmdate('c',$now),
			'expires_at'=>gmdate('c',$now+self::PLAN_TTL),
			'action'=>'reconcile_owned_dynamic_content_state',
		);
		$sha=MAD4B_SCP_Canonicalization::digest('dynamic-recovery-plan:v1',$basis);
		if(is_wp_error($sha)) return $sha;
		$blockers=isset($inspect['blockers'])?(array)$inspect['blockers']:array();
		if(empty($inspect['needs_recovery'])) $blockers[]='recovery_not_required';
		return array(
			'contract'=>self::PLAN_CONTRACT,
			'ready'=>empty($blockers),
			'blockers'=>array_values(array_unique($blockers)),
			'plan_sha256'=>$sha,
			'plan'=>$basis,
			'approval_requirement'=>array('impact_level'=>'high','impact_flags'=>array('recovery'),'ai_approval_allowed'=>false),
			'read_only'=>true,'mutation_performed'=>false
		);
	}
}

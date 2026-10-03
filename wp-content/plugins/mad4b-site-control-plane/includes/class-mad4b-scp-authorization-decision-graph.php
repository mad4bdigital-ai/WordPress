<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class MAD4B_SCP_Authorization_Decision_Graph {
	const CONTRACT='mad4b.authorization-decision-graph.v1';
	public static function decorate($result,$ability,$server,$provider){
		$code=is_wp_error($result)?sanitize_key((string)$result->get_error_code()):'';
		$failed=self::stage_for_error($code);$after=false;$steps=array();
		foreach(self::stages() as $stage=>$pass){
			$status='PASS';$reason=$pass;
			if(''!==$code){
				if($stage===$failed){$status='FAIL';$reason=$code;$after=true;}
				elseif($after||''===$failed){$status='NOT_EVALUATED';$reason='blocked_by_prior_stage';}
			}
			$steps[]=array('stage'=>$stage,'status'=>$status,'reason_code'=>$reason,'policy_version'=>'authorization.v1','evidence_version'=>'v1','evidence_refs'=>self::refs($stage,$result));
		}
		$g=array('contract'=>self::CONTRACT,'ability'=>(string)$ability,'server_id'=>sanitize_key((string)$server),'provider'=>sanitize_key((string)$provider),'decision'=>is_wp_error($result)?'FAIL':'PASS','reason_code'=>is_wp_error($result)?$code:(isset($result['reason_code'])?(string)$result['reason_code']:'preflight_allowed'),'steps'=>$steps,'redacted'=>true,'authorizing'=>false);
		$g['decision_sha256']=self::digest($g);
		if(is_wp_error($result)){ $d=$result->get_error_data();if(!is_array($d))$d=array();$d['authorization_decision_graph']=$g;$result->add_data($d);return $result; }
		if(is_array($result))$result['authorization_decision_graph']=$g;
		return $result;
	}
	private static function stages(){return array('request_scope'=>'request_scope_admitted','identity'=>'identity_bound','grant'=>'exact_grant_current','capability_descriptor'=>'descriptor_current','resource_constraints'=>'resource_constraints_satisfied','impact_approval'=>'impact_and_approval_satisfied','policy_resolution'=>'policy_resolution_allowed','budget'=>'budget_preflight_satisfied');}
	private static function stage_for_error($c){if(false!==strpos($c,'request')||false!==strpos($c,'transport'))return'request_scope';if(false!==strpos($c,'identity')||false!==strpos($c,'subject')||false!==strpos($c,'agent'))return'identity';if(false!==strpos($c,'grant')||false!==strpos($c,'authority'))return'grant';if(false!==strpos($c,'descriptor')||false!==strpos($c,'capability')||false!==strpos($c,'provider'))return'capability_descriptor';if(false!==strpos($c,'resource')||false!==strpos($c,'target'))return'resource_constraints';if(false!==strpos($c,'approval')||false!==strpos($c,'impact'))return'impact_approval';if(false!==strpos($c,'policy'))return'policy_resolution';if(false!==strpos($c,'budget'))return'budget';return'';}
	private static function refs($stage,$result){if(is_wp_error($result)||!is_array($result))return array();$m=array('request_scope'=>array('request_id'),'identity'=>array('subject_fingerprint'),'grant'=>array('grant_id'),'capability_descriptor'=>array('capability_descriptor_sha256'),'resource_constraints'=>array('resource_set_sha256','target_fingerprint'),'impact_approval'=>array('approval_ticket_id','approval_impact_binding_sha256'),'policy_resolution'=>array('policy_decision_sha256'),'budget'=>array());$o=array();foreach($m[$stage]??array() as $k){if(!isset($result[$k])||''===(string)$result[$k])continue;$v=(string)$result[$k];$o[]=array('type'=>$k,'sha256'=>preg_match('/^[a-f0-9]{64}$/D',strtolower($v))?strtolower($v):hash('sha256',$v));}return$o;}
	private static function digest($v){$j=function_exists('wp_json_encode')?wp_json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return is_string($j)?hash('sha256',$j):'';}
}

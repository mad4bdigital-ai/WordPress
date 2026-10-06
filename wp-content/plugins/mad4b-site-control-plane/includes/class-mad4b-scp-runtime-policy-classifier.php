<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deterministic, non-authorizing policy proposal classifier.
 *
 * Confidence, names, HTTP methods, schema shape and annotations are evidence
 * only. They never create grants, mounts, certification or execution authority.
 */
final class MAD4B_SCP_Runtime_Policy_Classifier {
	const CONTRACT = 'mad4b.runtime-policy-classifier.v1';
	const PROPOSAL_CONTRACT = 'mad4b.runtime-policy-proposal.v1';
	const CLASSIFIER_VERSION = '1.0.0';

	public static function boot() {
		if ( function_exists( 'add_action' ) ) add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 40 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists('wp_has_ability') && wp_has_ability('mad4b/runtime-policy-proposals') ) ) return;
		wp_register_ability('mad4b/runtime-policy-proposals',array(
			'label'=>'Runtime Policy Proposals',
			'description'=>'Deterministic evidence-derived policy proposals. Proposals and confidence are non-authorizing and never alter grants or mounts.',
			'category'=>'mad4b-read',
			'execute_callback'=>array(__CLASS__,'proposals'),
			'permission_callback'=>array('MAD4B_SCP_Policy','can_read'),
			'input_schema'=>array(
				'type'=>'object',
				'properties'=>array(
					'ability_name'=>array('type'=>'string','maxLength'=>191),
				),
				'additionalProperties'=>false,
			),
			'output_schema'=>array('type'=>'object','additionalProperties'=>true),
			'meta'=>array(
				'public'=>false,'show_in_rest'=>false,
				'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
				'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true),
			),
		));
	}

	public static function proposals( $input = array() ) {
		$input=is_array($input)?$input:array();
		if(!class_exists('MAD4B_SCP_Runtime_Evidence_Graph')) return new WP_Error('mad4b_runtime_evidence_graph_unavailable','Runtime evidence graph is unavailable.');
		$graph=MAD4B_SCP_Runtime_Evidence_Graph::snapshot();
		if(is_wp_error($graph)) return $graph;
		$filter=isset($input['ability_name'])?trim((string)$input['ability_name']):'';
		$overlay=apply_filters('mad4b_scp_reviewed_policy_overlay',array());
		$overlay=is_array($overlay)?$overlay:array();
		$rows=array();
		foreach(isset($graph['nodes']['abilities'])&&is_array($graph['nodes']['abilities'])?$graph['nodes']['abilities']:array() as $node){
			if(!is_array($node)||empty($node['id'])) continue;
			$name=(string)$node['id'];
			if(''!==$filter&&$filter!==$name) continue;
			$conformance=apply_filters('mad4b_scp_runtime_policy_conformance',array(),$name,$node,$graph);
			$conformance=is_array($conformance)?$conformance:array();
			$features=self::features_from_node($node,$conformance);
			$proposal=self::classify_features($features);
			$proposal['ability_name']=$name;
			$proposal['graph_generation_sha256']=(string)$graph['generation_sha256'];
			$proposal['evidence_refs']=array_values(array_filter(array(
				isset($node['descriptor_generation_sha256'])?(string)$node['descriptor_generation_sha256']:'',
				isset($node['classification_sha256'])?(string)$node['classification_sha256']:'',
				isset($conformance['evidence_sha256'])?(string)$conformance['evidence_sha256']:'',
			)));
			$proposal['reviewed_overlay']=self::overlay_projection($name,$overlay,$proposal,$graph['generation_sha256']);
			$proposal['proposal_sha256']=self::digest(self::PROPOSAL_CONTRACT,$proposal);
			$rows[]=$proposal;
		}
		return array(
			'contract'=>self::CONTRACT,
			'proposal_contract'=>self::PROPOSAL_CONTRACT,
			'classifier_version'=>self::CLASSIFIER_VERSION,
			'graph_generation_sha256'=>$graph['generation_sha256'],
			'proposals'=>$rows,
			'confidence_is_safety_proof'=>false,
			'operation_names_create_authority'=>false,
			'schema_infers_privilege'=>false,
			'annotations_create_authority'=>false,
			'grants_changed'=>false,
			'mounts_changed'=>false,
			'authorizing'=>false,
			'mutation_performed'=>false,
		);
	}

	private static function features_from_node( array $node, array $conformance ) {
		$annotations=isset($node['annotations'])&&is_array($node['annotations'])?$node['annotations']:array();
		$actual=isset($conformance['result'])?(string)$conformance['result']:'';
		$actual_ok='zero_effect_read_verified'===$actual
			&& !empty($conformance['evidence_sha256'])
			&& preg_match('/^[a-f0-9]{64}$/',(string)$conformance['evidence_sha256']);
		return array(
			'namespace'=>isset($node['namespace'])?(string)$node['namespace']:'',
			'action'=>isset($node['action'])?(string)$node['action']:'',
			'schema_sha256'=>isset($node['input_schema_sha256'])?(string)$node['input_schema_sha256']:'',
			'readonly_annotation'=>array_key_exists('readonly',$annotations)?$annotations['readonly']:null,
			'destructive_annotation'=>array_key_exists('destructive',$annotations)?$annotations['destructive']:null,
			'idempotent_annotation'=>array_key_exists('idempotent',$annotations)?$annotations['idempotent']:null,
			'execution_lane'=>isset($node['execution_lane'])?(string)$node['execution_lane']:'',
			'execution_provider'=>isset($node['execution_provider'])?(string)$node['execution_provider']:'',
			'execution_eligible'=>!empty($node['execution_eligible']),
			'execution_boundary_verified'=>!empty($node['execution_boundary_verified']),
			'breakglass'=>!empty($node['breakglass']),
			'reversible_contract'=>isset($node['reversible_contract'])?(string)$node['reversible_contract']:'',
			'effect_class'=>isset($node['effect_class'])?(string)$node['effect_class']:'unknown',
			'schema_secret_bearing'=>!empty($node['schema_secret_bearing']),
			'actual_conformance_verified'=>(bool)$actual_ok,
			'actual_conformance_result'=>$actual,
			'http_method'=>isset($node['http_method'])?(string)$node['http_method']:'',
			'declared_capability'=>isset($node['required_capability'])?(string)$node['required_capability']:'',
			'requested_risk'=>isset($node['requested_risk'])?(string)$node['requested_risk']:'',
		);
	}

	public static function classify_features( array $features ) {
		$missing=array(); $contradictions=array();
		$lane=isset($features['execution_lane'])?sanitize_key((string)$features['execution_lane']):'';
		$readonly=array_key_exists('readonly_annotation',$features)&&is_bool($features['readonly_annotation'])?$features['readonly_annotation']:null;
		$effect=isset($features['effect_class'])?sanitize_key((string)$features['effect_class']):'unknown';
		$secret=!empty($features['schema_secret_bearing']);
		$conformance=!empty($features['actual_conformance_verified']);
		$breakglass=!empty($features['breakglass']);
		$method=strtoupper(isset($features['http_method'])?(string)$features['http_method']:'');
		$action=strtolower(isset($features['action'])?(string)$features['action']:'');
		$requested_risk=sanitize_key(isset($features['requested_risk'])?(string)$features['requested_risk']:'');
		if(null===$readonly) $missing[]='readonly_annotation_missing';
		if(''===$lane||'none'===$lane) $missing[]='execution_lane_missing_or_blocked';
		if(empty($features['schema_sha256'])) $missing[]='schema_digest_missing';
		if(!$conformance) $missing[]='actual_conformance_missing';
		if(true===$readonly&&'read'!==$lane) $contradictions[]='readonly_annotation_conflicts_with_execution_lane';
		if(true===$readonly&&'declared_read_only'!==$effect) $contradictions[]='readonly_annotation_conflicts_with_effect';
		if(preg_match('/^(get|list|read|inspect|status|describe|discover)/',$action)&&true!==$readonly) $contradictions[]='operation_name_cannot_prove_read_safety';
		if('GET'===$method&&('read'!==$lane||'declared_read_only'!==$effect)) $contradictions[]='http_get_cannot_prove_read_safety';
		if($secret&&$conformance) $contradictions[]='secret_schema_blocks_zero_effect_auto_classification';
		$high=in_array($lane,array('write','admin','content','developer','developer-breakglass','breakglass','enrollment'),true)||$breakglass||'mutation_or_unknown'===$effect;
		$risk=$high?'high':(('read'===$lane&&!$secret)?'low':'unknown');
		if('low'===$requested_risk&&'low'!==$risk) $contradictions[]='risk_downgrade_rejected';
		$eligible=true===$readonly
			&& 'read'===$lane
			&& 'declared_read_only'===$effect
			&& !$secret
			&& $conformance
			&& !empty($features['execution_eligible'])
			&& !$breakglass
			&& empty($contradictions);
		$confidence=0;
		foreach(array('namespace','action','schema_sha256','execution_lane','effect_class') as $key) if(!empty($features[$key])) $confidence+=10;
		if(null!==$readonly) $confidence+=10;
		if($conformance) $confidence+=20;
		if(!empty($features['execution_provider'])||'read'===$lane) $confidence+=10;
		if(!empty($features['reversible_contract'])) $confidence+=5;
		$confidence=min(95,$confidence);
		$classification=$eligible?'zero_effect_read_candidate':($high?'high_risk_review':'unknown_review');
		return array(
			'contract'=>self::PROPOSAL_CONTRACT,
			'classifier_version'=>self::CLASSIFIER_VERSION,
			'classification'=>$classification,
			'risk'=>$risk,
			'confidence'=>$confidence,
			'confidence_authority_effect'=>'none',
			'auto_classification_eligible'=>$eligible,
			'owner_review_required'=>!$eligible,
			'missing_evidence'=>array_values(array_unique($missing)),
			'contradictory_evidence'=>array_values(array_unique($contradictions)),
			'features'=>self::public_features($features),
			'authority_delta'=>array('grants'=>0,'mounts'=>0,'scopes'=>0,'certifications'=>0),
			'authorizing'=>false,
		);
	}

	private static function overlay_projection( $name, array $overlay, array $proposal, $generation ) {
		$row=isset($overlay[$name])&&is_array($overlay[$name])?$overlay[$name]:array();
		if(!$row) return array('state'=>'not_reviewed','authorizing'=>false);
		$bound=isset($row['graph_generation_sha256'])?(string)$row['graph_generation_sha256']:'';
		$stale=''===$bound||!hash_equals((string)$generation,$bound);
		$reviewed_class=isset($row['classification'])?sanitize_key((string)$row['classification']):'';
		$reviewed_risk=isset($row['risk'])?sanitize_key((string)$row['risk']):'';
		$diff=array();
		if($reviewed_class!==$proposal['classification']) $diff[]='classification_changed';
		if($reviewed_risk!==$proposal['risk']) $diff[]='risk_changed';
		if(!empty($row['requested_scope_change'])) $diff[]='scope_change_requires_external_consent';
		return array(
			'state'=>$stale?'stale_review':'reviewed_evidence_only',
			'graph_generation_sha256'=>$bound,
			'reviewed_classification'=>$reviewed_class,
			'reviewed_risk'=>$reviewed_risk,
			'differences'=>$diff,
			'creates_grant'=>false,'creates_mount'=>false,'creates_scope'=>false,'authorizing'=>false,
		);
	}

	private static function public_features( array $features ) {
		$allowed=array('namespace','action','schema_sha256','readonly_annotation','destructive_annotation','idempotent_annotation','execution_lane','execution_provider','execution_eligible','execution_boundary_verified','breakglass','reversible_contract','effect_class','schema_secret_bearing','actual_conformance_verified','actual_conformance_result','http_method','declared_capability','requested_risk');
		$out=array();
		foreach($allowed as $key) if(array_key_exists($key,$features)) $out[$key]=$features[$key];
		return $out;
	}

	private static function digest( $contract, $value ) {
		if(class_exists('MAD4B_SCP_Ability_Contract_Inspector')){
			$d=MAD4B_SCP_Ability_Contract_Inspector::digest($contract,$value);
			if(!is_wp_error($d)) return $d;
		}
		return hash('sha256',wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
	}
}

MAD4B_SCP_Runtime_Policy_Classifier::boot();

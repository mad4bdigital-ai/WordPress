<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Semantic_Intent_Router {
	const CONTRACT = 'mad4b.semantic-intent-routing.v1';

	public static function boot() {
		if ( function_exists( 'add_action' ) ) add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 34 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b/semantic-intent-route' ) ) ) return;
		wp_register_ability( 'mad4b/semantic-intent-route', array(
			'label' => 'Semantic Intent Route',
			'description' => 'Non-authorizing semantic intent routing across currently certified and admissible provider capabilities.',
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, 'route' ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => array(
				'type'=>'object',
				'properties'=>array(
					'intent'=>array('type'=>'string','maxLength'=>96),
					'target_kind'=>array('type'=>'string','maxLength'=>64),
					'required_traits'=>array('type'=>'object','additionalProperties'=>true),
					'preferred_provider'=>array('type'=>'string','maxLength'=>64),
					'release_ring'=>array('type'=>'string','maxLength'=>32),
					'require_certified'=>array('type'=>'boolean'),
					'declared_server_id'=>array('type'=>'string','maxLength'=>64),
					'execution_input'=>array('type'=>'object','additionalProperties'=>true),
				),
				'required'=>array('intent'),
				'additionalProperties'=>false,
			),
			'output_schema'=>array('type'=>'object','additionalProperties'=>true),
			'meta'=>array(
				'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
				'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true),
			),
		) );
	}

	public static function route( $input = array() ) {
		$input=is_array($input)?$input:array();
		$intent=strtolower(trim(isset($input['intent'])?(string)$input['intent']:''));
		$intent=preg_replace('/[^a-z0-9._-]/','',$intent);
		if(''===$intent)return new WP_Error('mad4b_semantic_intent_required','Semantic intent is required.');
		$target=sanitize_key(isset($input['target_kind'])?(string)$input['target_kind']:'');
		$required=isset($input['required_traits'])&&is_array($input['required_traits'])?$input['required_traits']:array();
		$preferred=sanitize_key(isset($input['preferred_provider'])?(string)$input['preferred_provider']:'');
		$ring=sanitize_key(isset($input['release_ring'])?(string)$input['release_ring']:'');
		$cert=!array_key_exists('require_certified',$input)||!empty($input['require_certified']);
		$declared=sanitize_key(isset($input['declared_server_id'])?(string)$input['declared_server_id']:'');
		$execution_input=isset($input['execution_input'])&&is_array($input['execution_input'])?$input['execution_input']:array();

		if(!class_exists('MAD4B_SCP_Operation_Registry'))return new WP_Error('mad4b_semantic_operation_registry_unavailable','Operation Registry is unavailable.');
		$status=MAD4B_SCP_Operation_Registry::status();
		if(is_wp_error($status))return$status;
		$matches=array();
		foreach(isset($status['operations'])&&is_array($status['operations'])?$status['operations']:array() as $row){
			if(!is_array($row))continue;
			$id=isset($row['id'])?strtolower((string)$row['id']):'';
			$kind=isset($row['target_kind'])?sanitize_key((string)$row['target_kind']):'';
			$supports=isset($row['supports'])&&is_array($row['supports'])?array_map('sanitize_key',$row['supports']):array();
			if($id!==$intent&&!in_array(sanitize_key($intent),$supports,true))continue;
			if(''!==$target&&''!==$kind&&$target!==$kind)continue;
			$matches[]=$row;
		}

		$index=self::index();
		$candidates=array();
		foreach($matches as $row){
			$executor=isset($row['executor'])?(string)$row['executor']:'';
			$pairs='exact_executor_from_plan'===$executor?array():(isset($index[$executor])?$index[$executor]:array());
			if(empty($pairs)){
				$candidates[]=self::candidate($row,$executor,'','',false,false,0.25,'exact_capability_resolution_deferred_or_unmapped',true,array(),array());
				continue;
			}
			foreach($pairs as $pair){
				$traits=class_exists('MAD4B_SCP_Capability_Traits')
					? MAD4B_SCP_Capability_Traits::resolve(array(
						'capability_id'=>$pair['capability_id'],'required_traits'=>$required,'release_ring'=>$ring,
						'require_certified'=>$cert,'preferred_provider'=>''!==$preferred?$preferred:$pair['provider_id'],
					))
					: array('eligible'=>array());
				$trait_ok=false;$evidence=array();
				if(is_array($traits))foreach(isset($traits['eligible'])&&is_array($traits['eligible'])?$traits['eligible']:array() as $eligible){
					if((string)$eligible['provider_id']===(string)$pair['provider_id']){
						$trait_ok=true;
						$evidence[]=array(
							'profile_fingerprint'=>(string)$eligible['profile_fingerprint'],
							'certification_fingerprint'=>(string)$eligible['certification_fingerprint'],
							'descriptor_generation_sha256'=>isset($eligible['descriptor_generation_sha256'])?(string)$eligible['descriptor_generation_sha256']:'',
						);
						break;
					}
				}
				$authority=$trait_ok
					? self::candidate_authority($executor,$pair['provider_id'],$execution_input,$declared)
					: array('eligible'=>false,'server_id'=>'','reason_code'=>'semantic_traits_or_certification_rejected','evidence_refs'=>array());
				$eligible=$trait_ok&&!empty($authority['eligible']);
				$reason=$eligible?'semantic_traits_certification_and_authority_match':($trait_ok?(string)$authority['reason_code']:'semantic_traits_or_certification_rejected');
				$candidates[]=self::candidate(
					$row,$executor,$pair['provider_id'],$pair['capability_id'],$trait_ok,$eligible,
					$eligible?0.95:($trait_ok?0.50:0.0),$reason,!$eligible,
					array_merge($evidence,isset($authority['evidence_refs'])&&is_array($authority['evidence_refs'])?$authority['evidence_refs']:array()),
					$authority
				);
			}
		}
		$eligible=array_values(array_filter($candidates,static function($row){return!empty($row['eligible']);}));
		$selected=1===count($eligible)?$eligible[0]:array();
		$ambiguous=count($eligible)>1;
		return array(
			'contract'=>self::CONTRACT,'intent'=>$intent,'target_kind'=>$target,'required_traits'=>$required,
			'candidates'=>$candidates,'eligible_count'=>count($eligible),'selected'=>$selected,'ambiguous'=>$ambiguous,
			'human_review_required'=>$ambiguous||empty($selected),'confidence'=>!empty($selected)?(float)$selected['confidence']:0.0,
			'mutation_performed'=>false,'authority_created'=>false,'authorizing'=>false,
			'next_action'=>!empty($selected)?'execute_exact_planner_then_revalidate_authority':'human_review_or_exact_operation_required',
		);
	}

	private static function candidate_authority( $ability, $provider, array $execution_input, $declared_server ) {
		if(empty($execution_input))return array('eligible'=>false,'server_id'=>'','reason_code'=>'semantic_exact_execution_input_required','evidence_refs'=>array(),'authorizing'=>false);
		if(!class_exists('MAD4B_SCP_Servers')||!class_exists('MAD4B_SCP_Authorization')||!method_exists('MAD4B_SCP_Authorization','probe_mutation')){
			return array('eligible'=>false,'server_id'=>'','reason_code'=>'semantic_authority_probe_unavailable','evidence_refs'=>array(),'authorizing'=>false);
		}
		$provider=sanitize_key((string)$provider);
		$allowed_surfaces=array('mad4b-write','mad4b-admin','mad4b-content','mad4b-developer','mad4b-developer-breakglass','mad4b-breakglass');
		$servers=array();
		if(''!==$declared_server){
			if(in_array($declared_server,$allowed_surfaces,true)&&$provider===sanitize_key((string)MAD4B_SCP_Servers::provider_for_ability($declared_server,$ability)))$servers[]=$declared_server;
		}else{
			foreach($allowed_surfaces as $server){
				if($provider===sanitize_key((string)MAD4B_SCP_Servers::provider_for_ability($server,$ability)))$servers[]=$server;
			}
		}
		$servers=array_values(array_unique($servers));
		if(empty($servers))return array('eligible'=>false,'server_id'=>'','reason_code'=>'semantic_executor_not_mounted_on_governed_surface','evidence_refs'=>array(),'authorizing'=>false);
		if(count($servers)>1){
			if(in_array('mad4b-write',$servers,true))$servers=array('mad4b-write');
			else return array('eligible'=>false,'server_id'=>'','reason_code'=>'semantic_executor_server_binding_ambiguous','evidence_refs'=>array(),'authorizing'=>false);
		}
		$server=$servers[0];
		$probe=MAD4B_SCP_Authorization::probe_mutation($ability,$server,$provider,$execution_input);
		if(is_wp_error($probe)){
			$data=$probe->get_error_data();
			$graph=is_array($data)&&isset($data['authorization_decision_graph'])&&is_array($data['authorization_decision_graph'])?$data['authorization_decision_graph']:array();
			return array(
				'eligible'=>false,'server_id'=>$server,'reason_code'=>sanitize_key((string)$probe->get_error_code()),
				'decision_sha256'=>isset($graph['decision_sha256'])?(string)$graph['decision_sha256']:'',
				'evidence_refs'=>self::authority_refs($graph,array()),'authorizing'=>false,
			);
		}
		if(!is_array($probe)||empty($probe['allowed']))return array('eligible'=>false,'server_id'=>$server,'reason_code'=>'semantic_authority_probe_not_allowed','evidence_refs'=>array(),'authorizing'=>false);
		$graph=isset($probe['authorization_decision_graph'])&&is_array($probe['authorization_decision_graph'])?$probe['authorization_decision_graph']:array();
		return array(
			'eligible'=>true,'server_id'=>$server,'reason_code'=>'semantic_current_authority_admitted',
			'approval_required'=>!empty($probe['approval_required']),
			'resource_set_sha256'=>isset($probe['resource_set_sha256'])?(string)$probe['resource_set_sha256']:'',
			'policy_decision_sha256'=>isset($probe['policy_decision_sha256'])?(string)$probe['policy_decision_sha256']:'',
			'target_fingerprint'=>isset($probe['target_fingerprint'])?(string)$probe['target_fingerprint']:'',
			'decision_sha256'=>isset($graph['decision_sha256'])?(string)$graph['decision_sha256']:'',
			'evidence_refs'=>self::authority_refs($graph,$probe),'authorizing'=>false,
		);
	}

	private static function authority_refs( array $graph, array $probe ) {
		$out=array();
		foreach(array('decision_sha256'=>isset($graph['decision_sha256'])?$graph['decision_sha256']:'','resource_set_sha256'=>isset($probe['resource_set_sha256'])?$probe['resource_set_sha256']:'','policy_decision_sha256'=>isset($probe['policy_decision_sha256'])?$probe['policy_decision_sha256']:'','target_fingerprint'=>isset($probe['target_fingerprint'])?$probe['target_fingerprint']:'') as $type=>$value){
			$value=(string)$value;if(''===$value)continue;
			$out[]=array('type'=>$type,'sha256'=>1===preg_match('/^[a-f0-9]{64}$/D',strtolower($value))?strtolower($value):hash('sha256',$value),'redacted'=>true);
		}
		return$out;
	}

	private static function candidate( array $row, $executor, $provider, $capability, $trait_ok, $eligible, $confidence, $reason, $review, array $evidence, array $authority ) {
		return array(
			'operation_id'=>isset($row['id'])?(string)$row['id']:'',
			'planner'=>isset($row['planner'])?(string)$row['planner']:'',
			'executor'=>(string)$executor,'provider_id'=>(string)$provider,'capability_id'=>(string)$capability,
			'trait_certification_eligible'=>(bool)$trait_ok,'authority_eligible'=>(bool)(isset($authority['eligible'])?$authority['eligible']:false),
			'server_id'=>isset($authority['server_id'])?(string)$authority['server_id']:'',
			'eligible'=>(bool)$eligible,'confidence'=>(float)$confidence,'reason_code'=>(string)$reason,
			'human_review_required'=>(bool)$review,'evidence_refs'=>$evidence,'authorizing'=>false,
		);
	}

	private static function index() {
		$path=defined('MAD4B_SCP_DIR')?MAD4B_SCP_DIR.'config/provider-capability-contracts.json':'';
		if(''===$path||!is_readable($path))return array();
		$data=json_decode((string)file_get_contents($path),true);
		$out=array();
		foreach(isset($data['providers'])&&is_array($data['providers'])?$data['providers']:array() as $provider=>$profile){
			foreach(isset($profile['capabilities'])&&is_array($profile['capabilities'])?$profile['capabilities']:array() as $capability_id=>$capability){
				foreach(isset($capability['abilities'])&&is_array($capability['abilities'])?$capability['abilities']:array() as $ability){
					$ability=(string)$ability;
					if(''!==$ability)$out[$ability][]=array('provider_id'=>sanitize_key((string)$provider),'capability_id'=>(string)$capability_id);
				}
			}
		}
		return$out;
	}
}
MAD4B_SCP_Semantic_Intent_Router::boot();

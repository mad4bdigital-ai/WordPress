<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Immutable signed data-pack registry for runtime policy/certification material.
 *
 * Packs never contain executable PHP or arbitrary transport targets and never
 * manufacture authority. Automatic activation is limited to restrictive/equal
 * non-authorizing refreshes. Authority/risk/grant changes remain review-only.
 */
final class MAD4B_SCP_Certification_Pack_Registry {
	const CONTRACT = 'mad4b.certification-pack.v1';
	const REGISTRY_CONTRACT = 'mad4b.certification-pack-registry.v1';
	const INTERPRETER = 'mad4b.certification-pack-interpreter.v1';
	const OPTION = 'mad4b_scp_certification_pack_registry_v1';
	const MAX_PAYLOAD_BYTES = 262144;
	const MAX_ACTIVE_PACKS = 128;
	const MAX_REVOKED_PACKS = 256;
	const MAX_LINEAGE = 256;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 37 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		$category = 'mad4b-admin';
		foreach ( array(
			'mad4b/certification-pack-preview'=>array('Preview Certification Pack','preview'),
			'mad4b/certification-pack-registry-status'=>array('Certification Pack Registry Status','status'),
		) as $name=>$spec ) {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
			wp_register_ability( $name, array(
				'label'=>$spec[0],
				'description'=>$spec[0] . '; immutable signed data only, non-authorizing.',
				'category'=>$category,
				'input_schema'=>array('type'=>'object','additionalProperties'=>true),
				'output_schema'=>array('type'=>'object','additionalProperties'=>true),
				'execute_callback'=>array(__CLASS__,$spec[1]),
				'permission_callback'=>array(__CLASS__,'can_manage'),
				'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'admin'),'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true),'mad4b_contract'=>self::REGISTRY_CONTRACT),
			) );
		}
	}

	public static function pack_types() {
		return array( 'policy_overlay','adapter_manifest','acceptance_recipe','rollback_recipe','skill_profile','provider_evidence' );
	}

	public static function can_manage( $input = null ) {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	public static function preview( $input = array() ) {
		$pack = isset( $input['pack'] ) && is_array( $input['pack'] ) ? $input['pack'] : $input;
		$verified = self::verify_pack( $pack );
		if ( is_wp_error( $verified ) ) return $verified;
		$registry = self::read_registry();
		if ( is_wp_error( $registry ) ) return $registry;
		$slot = self::slot( $verified );
		$current = isset( $registry['active'][ $slot ] ) && is_array( $registry['active'][ $slot ] ) ? $registry['active'][ $slot ] : array();
		$transition = self::transition_policy( $verified, $current );
		return array(
			'contract'=>'mad4b.certification-pack-preview.v1',
			'pack_id'=>$verified['pack_id'],
			'pack_sha256'=>$verified['pack_sha256'],
			'pack_type'=>$verified['pack_type'],
			'slot'=>$slot,
			'current_pack_sha256'=>isset($current['pack_sha256'])?(string)$current['pack_sha256']:'',
			'change_class'=>$verified['change_class'],
			'automatic_activation_eligible'=>true===$transition,
			'governed_review_required'=>is_wp_error($transition)&&'mad4b_certification_pack_governed_review_required'===$transition->get_error_code(),
			'authorizing'=>false,
			'mutation_performed'=>false,
		);
	}

	public static function status( $input = array() ) {
		$registry = self::read_registry();
		if ( is_wp_error( $registry ) ) return $registry;
		$active_meta=array();foreach($registry['active'] as$slot=>$row)if(is_array($row))$active_meta[$slot]=self::status_record($row);
		return array(
			'contract'=>self::REGISTRY_CONTRACT,
			'revision'=>(int)$registry['revision'],
			'active_pack_count'=>count($registry['active']),
			'revoked_pack_count'=>count($registry['revoked']),
			'restore_epoch'=>(int)$registry['restore_epoch'],
			'active'=>$active_meta,
			'authorizing'=>false,
			'mutation_performed'=>false,
		);
	}

	public static function activate_restrictive( array $pack, $expected_revision ) {
		$verified = self::verify_pack( $pack );
		if ( is_wp_error( $verified ) ) return $verified;
		$lock = self::lock_name();
		if ( is_wp_error( $lock ) ) return $lock;
		$acquired = MAD4B_SCP_Distributed_Lock::acquire( $lock );
		if ( is_wp_error( $acquired ) ) return $acquired;
		try {
			$registry = self::read_registry( true );
			if ( is_wp_error( $registry ) ) return $registry;
			if ( (int)$expected_revision !== (int)$registry['revision'] ) return new WP_Error( 'mad4b_certification_pack_registry_revision_conflict', 'Certification pack registry changed; reread before retrying.' );
			$slot = self::slot( $verified );
			$current = isset( $registry['active'][$slot] ) && is_array( $registry['active'][$slot] ) ? $registry['active'][$slot] : array();
			$transition = self::transition_policy( $verified, $current );
			if ( is_wp_error( $transition ) ) return $transition;
			if ( isset( $registry['revoked'][$verified['pack_sha256']] ) ) return new WP_Error( 'mad4b_certification_pack_revoked', 'Revoked certification pack cannot be activated.' );
			if ( ! empty( $current ) ) {
				$current_sha = isset($current['pack_sha256'])?(string)$current['pack_sha256']:'';
				if ( ! hash_equals( $current_sha, (string)$verified['previous_pack_sha256'] ) ) return new WP_Error( 'mad4b_certification_pack_chain_mismatch', 'Pack chain does not extend the currently active pack.' );
				if ( (int)$verified['sequence'] <= (int)(isset($current['sequence'])?$current['sequence']:0) ) return new WP_Error( 'mad4b_certification_pack_replay_or_rollback', 'Pack sequence would replay or roll back an active slot.' );
			} elseif ( '' !== (string)$verified['previous_pack_sha256'] ) {
				return new WP_Error( 'mad4b_certification_pack_chain_mismatch', 'Initial pack cannot claim an unknown predecessor.' );
			}
			if ( count($registry['active']) >= self::MAX_ACTIVE_PACKS && empty($current) ) return new WP_Error( 'mad4b_certification_pack_registry_full', 'Certification pack registry active-slot bound is exhausted.' );
			if ( count($registry['lineage']) >= self::MAX_LINEAGE ) return new WP_Error( 'mad4b_certification_pack_lineage_full', 'Lineage is full; security history cannot be evicted automatically.' );
			$generation = MAD4B_SCP_Runtime_Generation_Fence::assert_current( $verified['runtime_generation'] );
			if ( is_wp_error( $generation ) ) return $generation;
			$record = self::stored_record( $verified );
			$registry['active'][$slot] = $record;
			$registry['lineage'][$verified['pack_sha256']] = (string)$verified['previous_pack_sha256'];
			$registry['revision'] = (int)$registry['revision'] + 1;
			$registry['updated_at'] = self::now_epoch();
			$ok = update_option( self::OPTION, $registry, false );
			$readback = self::read_registry( true );
			if ( is_wp_error( $readback ) || ( false === $ok && (int)(is_array($readback)?$readback['revision']:-1)!==(int)$registry['revision'] ) ) return new WP_Error( 'mad4b_certification_pack_atomic_activation_failed', 'Certification pack registry activation could not be committed atomically.' );
			$stored = isset($readback['active'][$slot])?$readback['active'][$slot]:array();
			if ( ! is_array($stored) || ! hash_equals((string)$verified['pack_sha256'],(string)(isset($stored['pack_sha256'])?$stored['pack_sha256']:'')) ) return new WP_Error( 'mad4b_certification_pack_activation_readback_failed', 'Certification pack activation readback mismatch.' );
			return array('contract'=>self::REGISTRY_CONTRACT,'activated'=>true,'slot'=>$slot,'revision'=>(int)$readback['revision'],'pack_sha256'=>$verified['pack_sha256'],'authorizing'=>false);
		} finally {
			MAD4B_SCP_Distributed_Lock::release( $lock );
		}
	}

	public static function revoke( $pack_sha256, $expected_revision, $reason = 'governed_revocation' ) {
		$sha = strtolower(trim((string)$pack_sha256));
		if ( 1 !== preg_match('/^[a-f0-9]{64}$/',$sha) ) return new WP_Error('mad4b_certification_pack_revocation_digest_invalid','Revocation requires an exact pack digest.');
		$lock=self::lock_name();if(is_wp_error($lock))return$lock;
		$acquired=MAD4B_SCP_Distributed_Lock::acquire($lock);if(is_wp_error($acquired))return$acquired;
		try{
			$registry=self::read_registry(true);if(is_wp_error($registry))return$registry;
			if((int)$expected_revision!==(int)$registry['revision'])return new WP_Error('mad4b_certification_pack_registry_revision_conflict','Certification pack registry changed; reread before retrying.');
			$to_revoke=array($sha=>true);$changed=true;while($changed){$changed=false;foreach((array)$registry['lineage'] as$child=>$previous)if(isset($to_revoke[(string)$previous])&&!isset($to_revoke[(string)$child])){$to_revoke[(string)$child]=true;$changed=true;}}
			foreach(array_keys($to_revoke)as$revoked_sha)$registry['revoked'][$revoked_sha]=array('revoked_at'=>self::now_epoch(),'reason'=>substr(sanitize_text_field((string)$reason),0,160),'chain_root'=>$sha);
			foreach($registry['active'] as$slot=>$row)if(is_array($row)&&isset($row['pack_sha256'])&&isset($to_revoke[(string)$row['pack_sha256']]))unset($registry['active'][$slot]);
			if(count($registry['revoked'])>self::MAX_REVOKED_PACKS)return new WP_Error('mad4b_certification_pack_revocation_capacity','Revocation capacity is exhausted; existing revocation records must remain intact.');
			$registry['revision']++;$registry['updated_at']=self::now_epoch();
			update_option(self::OPTION,$registry,false);$readback=self::read_registry(true);
			if(is_wp_error($readback)||!isset($readback['revoked'][$sha]))return new WP_Error('mad4b_certification_pack_revocation_readback_failed','Pack revocation was not durably observed.');
			return array('contract'=>self::REGISTRY_CONTRACT,'revoked'=>true,'pack_sha256'=>$sha,'revision'=>(int)$readback['revision'],'authorizing'=>false);
		}finally{MAD4B_SCP_Distributed_Lock::release($lock);}
	}

	public static function verify_pack( array $pack ) {
		if ( self::CONTRACT !== (string)(isset($pack['contract'])?$pack['contract']:'') ) return new WP_Error('mad4b_certification_pack_contract_invalid','Certification pack contract is invalid.');
		if ( self::INTERPRETER !== (string)(isset($pack['interpreter_contract'])?$pack['interpreter_contract']:'') ) return new WP_Error('mad4b_certification_pack_interpreter_incompatible','Certification pack requires an incompatible interpreter.');
		$type=sanitize_key(isset($pack['pack_type'])?(string)$pack['pack_type']:'');
		if(!in_array($type,self::pack_types(),true))return new WP_Error('mad4b_certification_pack_type_invalid','Certification pack type is unsupported.');
		$id=sanitize_key(isset($pack['pack_id'])?(string)$pack['pack_id']:'');
		$version=trim((string)(isset($pack['pack_version'])?$pack['pack_version']:''));
		$sequence=(int)(isset($pack['sequence'])?$pack['sequence']:0);
		if(''===$id||1!==preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/',$version)||$sequence<1)return new WP_Error('mad4b_certification_pack_identity_invalid','Certification pack id/version/sequence is invalid.');

		$site=class_exists('MAD4B_SCP_Site_Profile')?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
		$env=class_exists('MAD4B_SCP_Site_Profile')?sanitize_key((string)MAD4B_SCP_Site_Profile::current_environment()):'';
		if(''===$site||!hash_equals($site,(string)(isset($pack['site_uuid'])?$pack['site_uuid']:''))||''===$env||!hash_equals($env,sanitize_key((string)(isset($pack['environment'])?$pack['environment']:''))))return new WP_Error('mad4b_certification_pack_foreign_binding','Certification pack belongs to a different site or environment.');

		$restore=class_exists('MAD4B_SCP_Restore_Epoch')?MAD4B_SCP_Restore_Epoch::material():new WP_Error('mad4b_certification_pack_restore_epoch_unavailable','Restore epoch unavailable.');
		if(is_wp_error($restore))return$restore;
		if((int)(isset($pack['restore_epoch'])?$pack['restore_epoch']:0)!==(int)(isset($restore['epoch'])?$restore['epoch']:0))return new WP_Error('mad4b_certification_pack_restore_replay','Certification pack restore epoch is stale.');

		$generation=isset($pack['runtime_generation'])&&is_array($pack['runtime_generation'])?$pack['runtime_generation']:array();
		if(!class_exists('MAD4B_SCP_Runtime_Generation_Fence'))return new WP_Error('mad4b_certification_pack_generation_unavailable','Runtime generation fence unavailable.');
		$g=MAD4B_SCP_Runtime_Generation_Fence::assert_current($generation);if(is_wp_error($g))return$g;

		$provider=sanitize_key(isset($pack['provider_id'])?(string)$pack['provider_id']:'');
		$capability=strtolower(trim((string)(isset($pack['capability_id'])?$pack['capability_id']:'')));
		$schema_id=trim((string)(isset($pack['schema_id'])?$pack['schema_id']:''));
		$schemas=array('policy_overlay'=>array('mad4b.policy-overlay.v1'),'adapter_manifest'=>array('mad4b.declarative-adapter-manifest.v1'),'acceptance_recipe'=>array('mad4b.provider-shadow-recipe.v1','mad4b.reversible-canary-recipe.v1'),'rollback_recipe'=>array('mad4b.rollback-recipe.v1'),'skill_profile'=>array('mad4b.skill-profile.v1'),'provider_evidence'=>array('mad4b.provider-evidence.v1'));
		if(!in_array($schema_id,$schemas[$type],true))return new WP_Error('mad4b_certification_pack_schema_invalid','Certification pack requires a registered compatible data schema id.');
		$artifact=strtolower(trim((string)(isset($pack['artifact_sha256'])?$pack['artifact_sha256']:'')));
		if(''!==$provider){
			if(!class_exists('MAD4B_SCP_Provider_Compatibility_Certification'))return new WP_Error('mad4b_certification_pack_provider_certification_unavailable','Provider certification unavailable.');
			$a=MAD4B_SCP_Provider_Compatibility_Certification::assess_provider($provider);
			if(is_wp_error($a)||!is_array($a))return new WP_Error('mad4b_certification_pack_provider_assessment_invalid','Provider artifact assessment is unavailable.');
			$current=strtolower(trim((string)(isset($a['artifact']['runtime_artifact_fingerprint'])?$a['artifact']['runtime_artifact_fingerprint']:'')));
			if(1!==preg_match('/^[a-f0-9]{64}$/',$artifact)||1!==preg_match('/^[a-f0-9]{64}$/',$current)||!hash_equals($artifact,$current))return new WP_Error('mad4b_certification_pack_stale_artifact','Certification pack artifact binding is stale.');
			if(''===$capability)return new WP_Error('mad4b_certification_pack_capability_required','Provider certification pack requires an exact capability id.');
		}

		$payload=isset($pack['payload'])&&is_array($pack['payload'])?$pack['payload']:null;
		if(!is_array($payload))return new WP_Error('mad4b_certification_pack_payload_invalid','Certification pack payload must be immutable data.');
		$json=self::stable_json($payload);if(''===$json||strlen($json)>self::MAX_PAYLOAD_BYTES)return new WP_Error('mad4b_certification_pack_payload_bounds_invalid','Certification pack payload exceeds immutable data bounds.');
		if(self::contains_executable_primitive($payload))return new WP_Error('mad4b_certification_pack_executable_payload_denied','Certification packs cannot carry executable code, arbitrary HTTP, shell or raw SQL primitives.');
		if(class_exists('MAD4B_SCP_Structural_Redaction')){$privacy=MAD4B_SCP_Structural_Redaction::classify($payload,'certification_pack');if(is_array($privacy)&&'sensitive_redacted'===(string)(isset($privacy['classification'])?$privacy['classification']:''))return new WP_Error('mad4b_certification_pack_secret_payload_denied','Certification pack payload may not contain secret material.');}
		$payload_sha=hash('sha256',$json);
		if(!hash_equals($payload_sha,strtolower(trim((string)(isset($pack['payload_sha256'])?$pack['payload_sha256']:'')))))return new WP_Error('mad4b_certification_pack_payload_digest_mismatch','Certification pack payload digest mismatch.');

		$issued=(int)(isset($pack['issued_at'])?$pack['issued_at']:0);$expires=(int)(isset($pack['expires_at'])?$pack['expires_at']:0);
		if($issued<1||$expires<=$issued||$expires<=self::now_epoch())return new WP_Error('mad4b_certification_pack_expired','Certification pack is expired or has an invalid lifetime.');
		$digest=self::payload_pack_sha256($pack);$declared=strtolower(trim((string)(isset($pack['pack_sha256'])?$pack['pack_sha256']:'')));
		if(1!==preg_match('/^[a-f0-9]{64}$/',$declared)||!hash_equals($digest,$declared))return new WP_Error('mad4b_certification_pack_digest_mismatch','Certification pack digest mismatch.');
		if(!class_exists('MAD4B_SCP_Crypto_Profile'))return new WP_Error('mad4b_certification_pack_crypto_unavailable','Certification pack signature verifier unavailable.');
		$sig=isset($pack['signature'])&&is_array($pack['signature'])?$pack['signature']:array();
		$verified=MAD4B_SCP_Crypto_Profile::verify_digest_for_purpose($sig,$digest,'certification_pack');if(is_wp_error($verified))return$verified;

		$registry=self::read_registry();if(is_wp_error($registry))return$registry;
		if(isset($registry['revoked'][$digest]))return new WP_Error('mad4b_certification_pack_revoked','Certification pack has been revoked.');
		$change=sanitize_key(isset($pack['change_class'])?(string)$pack['change_class']:'');
		if(!in_array($change,array('restrictive','equal','risk_downgrade','new_authority','grant_change','activation_eligibility'),true))return new WP_Error('mad4b_certification_pack_change_class_invalid','Certification pack change class is invalid.');

		$out=$pack;$out['pack_id']=$id;$out['schema_id']=$schema_id;$out['pack_version']=$version;$out['sequence']=$sequence;$out['pack_type']=$type;$out['provider_id']=$provider;$out['capability_id']=$capability;$out['artifact_sha256']=$artifact;$out['payload_sha256']=$payload_sha;$out['pack_sha256']=$digest;$out['change_class']=$change;$out['previous_pack_sha256']=strtolower(trim((string)(isset($pack['previous_pack_sha256'])?$pack['previous_pack_sha256']:'')));
		if(''!==$out['previous_pack_sha256']&&1!==preg_match('/^[a-f0-9]{64}$/',$out['previous_pack_sha256']))return new WP_Error('mad4b_certification_pack_previous_digest_invalid','Certification pack predecessor digest is invalid.');
		return$out;
	}

	public static function payload_pack_sha256( array $pack ){unset($pack['pack_sha256'],$pack['signature']);return self::stable_digest($pack);}

	private static function transition_policy(array$pack,array$current){
		$payload=$pack['payload'];
		$old=isset($current['payload'])&&is_array($current['payload'])?$current['payload']:array();
		$ranks=array('blocked'=>0,'read'=>1);
		if(in_array($pack['change_class'],array('restrictive','equal'),true)&&'policy_overlay'===$pack['pack_type']
			&&empty(array_diff(array_keys($payload),array('max_risk','activation')))
			&&'unchanged'===($payload['activation']??'')&&isset($ranks[$payload['max_risk']??''])
			&&isset($ranks[$old['max_risk']??'read'])&&$ranks[$payload['max_risk']]<=$ranks[$old['max_risk']??'read'])return true;
		return new WP_Error('mad4b_certification_pack_governed_review_required','Risk downgrade, new authority, grants or activation eligibility require governed review before registry activation.',array('change_class'=>$pack['change_class'],'automatic_activation_allowed'=>false));
	}
	private static function slot(array$pack){return implode(':',array($pack['pack_type'],''!==$pack['provider_id']?$pack['provider_id']:'global',''!==$pack['capability_id']?$pack['capability_id']:'global',$pack['pack_id']));}
	private static function stored_record(array$pack){return array('pack_id'=>$pack['pack_id'],'pack_version'=>$pack['pack_version'],'pack_type'=>$pack['pack_type'],'sequence'=>$pack['sequence'],'pack_sha256'=>$pack['pack_sha256'],'previous_pack_sha256'=>$pack['previous_pack_sha256'],'provider_id'=>$pack['provider_id'],'capability_id'=>$pack['capability_id'],'artifact_sha256'=>$pack['artifact_sha256'],'site_uuid'=>$pack['site_uuid'],'environment'=>$pack['environment'],'restore_epoch'=>(int)$pack['restore_epoch'],'runtime_generation_sha256'=>(string)$pack['runtime_generation']['generation_sha256'],'payload_sha256'=>$pack['payload_sha256'],'payload'=>$pack['payload'],'signature'=>$pack['signature'],'schema_id'=>$pack['schema_id'],'change_class'=>$pack['change_class'],'expires_at'=>(int)$pack['expires_at'],'authorizing'=>false);}
	private static function status_record(array$row){$copy=$row;unset($copy['payload'],$copy['signature']);return$copy;}
	private static function read_registry($refresh=false){
		if($refresh&&function_exists('wp_cache_delete'))wp_cache_delete(self::OPTION,'options');
		$restore=class_exists('MAD4B_SCP_Restore_Epoch')?MAD4B_SCP_Restore_Epoch::material():new WP_Error('mad4b_certification_pack_restore_epoch_unavailable','Restore epoch unavailable.');if(is_wp_error($restore))return$restore;$epoch=(int)$restore['epoch'];
		$r=get_option(self::OPTION,array());if(!is_array($r)||empty($r))return array('contract'=>self::REGISTRY_CONTRACT,'revision'=>0,'restore_epoch'=>$epoch,'active'=>array(),'revoked'=>array(),'lineage'=>array(),'updated_at'=>0);
		if(self::REGISTRY_CONTRACT!==(string)(isset($r['contract'])?$r['contract']:'')||!isset($r['revision'],$r['restore_epoch'],$r['active'],$r['revoked'])||!is_array($r['active'])||!is_array($r['revoked']))return new WP_Error('mad4b_certification_pack_registry_invalid','Certification pack registry storage is invalid.');
		if(!isset($r['lineage'])||!is_array($r['lineage']))$r['lineage']=array();
		if((int)$r['restore_epoch']!==$epoch)return new WP_Error('mad4b_certification_pack_registry_restore_epoch_mismatch','Registry was restored from an older authority epoch; activation is quarantined.');
		return$r;
	}
	private static function lock_name(){if(!class_exists('MAD4B_SCP_Distributed_Lock'))return new WP_Error('mad4b_certification_pack_lock_unavailable','Distributed lock is required for atomic pack activation.');return MAD4B_SCP_Distributed_Lock::catalog_name('certification-pack-registry');}
	private static function contains_executable_primitive($v,$depth=0){if($depth>12)return true;if(is_array($v)){foreach($v as$k=>$x){if(self::forbidden((string)$k)||self::contains_executable_primitive($x,$depth+1))return true;}return false;}if(is_object($v))return true;return is_string($v)&&self::forbidden($v);}
	private static function forbidden($v){$s=strtolower(preg_replace('/\s+/','',(string)$v));foreach(array('e'.'val(','shell_exec','proc_open','wp_remote_','curl_','http://','https://','<?php','raw_sql','call_user_func','route:','class:','function:')as$n)if(false!==strpos($s,$n))return true;return false;}
	private static function stable_digest($v){$j=self::stable_json($v);return''===$j?'':hash('sha256',$j);}
	private static function stable_json($v){$v=self::canon($v);$j=function_exists('wp_json_encode')?wp_json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return is_string($j)?$j:'';}
	private static function canon($v){if(is_array($v)){$keys=array_keys($v);$list=array()===$v||$keys===range(0,count($v)-1);if($list)return array_map(array(__CLASS__,'canon'),$v);sort($keys,SORT_STRING);$o=array();foreach($keys as$k)$o[(string)$k]=self::canon($v[$k]);return$o;}if(is_object($v))return self::canon(get_object_vars($v));return$v;}
	private static function now_epoch(){return class_exists('MAD4B_SCP_Time_Policy')&&method_exists('MAD4B_SCP_Time_Policy','now_epoch')?(int)MAD4B_SCP_Time_Policy::now_epoch():time();}
}

MAD4B_SCP_Certification_Pack_Registry::boot();

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Dynamic_Recovery_Adapter extends MAD4B_SCP_Adapter_Base {
	const INSPECT='mad4b/content-recovery-inspect';
	const PLAN='mad4b/content-recovery-plan';

	public function id(){ return 'dynamic-recovery'; }
	public function label(){ return 'Dynamic Content Recovery'; }
	public function is_available(){ return class_exists('MAD4B_SCP_Dynamic_Recovery') && class_exists('MAD4B_SCP_Operation_Journal'); }
	protected function certified_provider_key(){ return 'core'; }
	protected function mutation_requires_certification(){ return false; }
	protected function detect_plugin_version(){ return defined('MAD4B_SCP_VERSION')?(string)MAD4B_SCP_VERSION:''; }
	public function ability_names(){ return array('read'=>array(self::INSPECT,self::PLAN),'content'=>array(),'admin'=>array(),'write'=>array()); }

	public function register_abilities(){
		if(!wp_has_ability(self::INSPECT)) $this->add_ability(
			self::INSPECT,
			'Dynamic Content Recovery Inspect',
			'inspect',
			array('MAD4B_SCP_Policy','can_read'),
			$this->schema(array(
				'operation_id'=>array('type'=>'string','minLength'=>36,'maxLength'=>36,'pattern'=>'^[A-Fa-f0-9-]{36}$')
			),array('operation_id')),
			'read',true,false,true
		);
		if(!wp_has_ability(self::PLAN)) $this->add_ability(
			self::PLAN,
			'Dynamic Content Recovery Plan',
			'plan',
			array('MAD4B_SCP_Policy','can_read'),
			$this->schema(array(
				'operation_id'=>array('type'=>'string','minLength'=>36,'maxLength'=>36,'pattern'=>'^[A-Fa-f0-9-]{36}$'),
				'current_state_sha256'=>array('type'=>'string','pattern'=>'^[A-Fa-f0-9]{64}$'),
				'provider_state_digest'=>array('type'=>'string','pattern'=>'^[A-Fa-f0-9]{64}$'),
				'pipeline_settings_sha256'=>array('type'=>'string','pattern'=>'^[A-Fa-f0-9]{64}$'),
				'policy_sha256'=>array('type'=>'string','pattern'=>'^[A-Fa-f0-9]{64}$'),
				'environment'=>array('type'=>'string','minLength'=>1,'maxLength'=>32)
			),array('operation_id','current_state_sha256','provider_state_digest','pipeline_settings_sha256','policy_sha256','environment')),
			'read',true,false,true
		);
	}

	public function inspect($input=array()){
		if(!$this->is_available()) return $this->unavailable_error();
		$input=is_array($input)?$input:array();
		return MAD4B_SCP_Dynamic_Recovery::inspect(isset($input['operation_id'])?(string)$input['operation_id']:'');
	}

	public function plan($input=array()){
		if(!$this->is_available()) return $this->unavailable_error();
		return MAD4B_SCP_Dynamic_Recovery::plan(is_array($input)?$input:array());
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Dynamic_Recovery_Adapter extends MAD4B_SCP_Adapter_Base {
	const METRICS='mad4b/content-operation-metrics';
	const STATUS='mad4b/content-operation-status';
	const TRACE='mad4b/content-operation-trace';
	const INSPECT='mad4b/content-recovery-inspect';
	const PLAN='mad4b/content-recovery-plan';

	public function id(){ return 'dynamic-recovery'; }
	public function label(){ return 'Dynamic Content Recovery'; }
	public function is_available(){ return class_exists('MAD4B_SCP_Dynamic_Recovery') && class_exists('MAD4B_SCP_Operation_Journal'); }
	protected function certified_provider_key(){ return 'core'; }
	protected function mutation_requires_certification(){ return false; }
	protected function detect_plugin_version(){ return defined('MAD4B_SCP_VERSION')?(string)MAD4B_SCP_VERSION:''; }
	public function ability_names(){ return array('read'=>array(self::METRICS,self::STATUS,self::TRACE,self::INSPECT,self::PLAN),'content'=>array(),'admin'=>array(),'write'=>array()); }

	private function operation_id_schema(){
		return array('type'=>'string','minLength'=>36,'maxLength'=>36,'pattern'=>'^[A-Fa-f0-9-]{36}$');
	}

	public function register_abilities(){
		if(!wp_has_ability(self::METRICS)) $this->add_ability(
			self::METRICS,'Dynamic Content Operation Metrics','metrics_read',
			array('MAD4B_SCP_Policy','can_read'),
			$this->schema(array(
				'names'=>array('type'=>'array','maxItems'=>50,'items'=>array('type'=>'string','minLength'=>1,'maxLength'=>96)),
				'hours'=>array('type'=>'integer','minimum'=>1,'maximum'=>720,'default'=>24)
			)),
			'read',true,false,true
		);

		if(!wp_has_ability(self::STATUS)) $this->add_ability(
			self::STATUS,'Dynamic Content Operation Status','status_read',
			array('MAD4B_SCP_Policy','can_read'),
			$this->schema(array('operation_id'=>$this->operation_id_schema()),array('operation_id')),
			'read',true,false,true
		);

		if(!wp_has_ability(self::TRACE)) $this->add_ability(
			self::TRACE,'Dynamic Content Operation Trace','trace_read',
			array('MAD4B_SCP_Policy','can_read'),
			$this->schema(array(
				'operation_id'=>$this->operation_id_schema(),
				'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>1000,'default'=>200)
			),array('operation_id')),
			'read',true,false,true
		);

		if(!wp_has_ability(self::INSPECT)) $this->add_ability(
			self::INSPECT,'Dynamic Content Recovery Inspect','inspect',
			array('MAD4B_SCP_Policy','can_read'),
			$this->schema(array('operation_id'=>$this->operation_id_schema()),array('operation_id')),
			'read',true,false,true
		);

		if(!wp_has_ability(self::PLAN)) $this->add_ability(
			self::PLAN,'Dynamic Content Recovery Plan','plan',
			array('MAD4B_SCP_Policy','can_read'),
			$this->schema(array(
				'operation_id'=>$this->operation_id_schema(),
				'current_state_sha256'=>array('type'=>'string','pattern'=>'^[A-Fa-f0-9]{64}$'),
				'provider_state_digest'=>array('type'=>'string','pattern'=>'^[A-Fa-f0-9]{64}$'),
				'pipeline_settings_sha256'=>array('type'=>'string','pattern'=>'^[A-Fa-f0-9]{64}$'),
				'policy_sha256'=>array('type'=>'string','pattern'=>'^[A-Fa-f0-9]{64}$'),
				'environment'=>array('type'=>'string','minLength'=>1,'maxLength'=>32)
			),array('operation_id','current_state_sha256','provider_state_digest','pipeline_settings_sha256','policy_sha256','environment')),
			'read',true,false,true
		);
	}

	public function metrics_read($input=array()){
		if(!class_exists('MAD4B_SCP_Runtime_Metrics')) return new WP_Error('mad4b_runtime_metrics_unavailable','Runtime metrics are unavailable.');
		$input=is_array($input)?$input:array();
		return MAD4B_SCP_Runtime_Metrics::summary(isset($input['names'])&&is_array($input['names'])?$input['names']:array(),isset($input['hours'])?absint($input['hours']):24);
	}
	public function status_read($input=array()){
		if(!$this->is_available()) return $this->unavailable_error();
		$input=is_array($input)?$input:array();
		return MAD4B_SCP_Operation_Journal::status(isset($input['operation_id'])?(string)$input['operation_id']:'');
	}
	public function trace_read($input=array()){
		if(!$this->is_available()) return $this->unavailable_error();
		$input=is_array($input)?$input:array();
		return MAD4B_SCP_Operation_Journal::trace(isset($input['operation_id'])?(string)$input['operation_id']:'',isset($input['limit'])?absint($input['limit']):200);
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

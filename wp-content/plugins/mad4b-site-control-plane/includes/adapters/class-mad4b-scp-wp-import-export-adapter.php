<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed WP All Import / WP All Export integration.
 * Read/inspect/validate/plan are exposed; execution stays unmounted until a
 * secret-safe direct provider contract is certified. Import additionally
 * requires a run-level reversible contract.
 */
final class MAD4B_SCP_WP_Import_Export_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.wp-import-export-governed-adapter.v2';
	const MAX_ITEMS = 100;
	const MAX_HASH_BYTES = 67108864;
	const IMPORT_ROLLBACK_CONTRACT = 'mad4b.rollback.wp-all-import-run.v1';

	public function id() { return 'wp-import-export'; }
	public function label() { return 'WP All Import / Export'; }
	public function is_available() {
		return self::import_runtime_available() || self::export_runtime_available() ||
			( class_exists( 'MAD4B_SCP_Repository_Artifact_Catalog' ) && ! empty( MAD4B_SCP_Repository_Artifact_Catalog::runtime_plugins_for_family( $this->id() ) ) );
	}
	public function ability_names() {
		return array(
			'read' => array(
				'wp-import-export/status','wp-import-export/list-imports','wp-import-export/get-import',
				'wp-import-export/validate-import','wp-import-export/plan-import-run',
				'wp-import-export/list-exports','wp-import-export/get-export',
				'wp-import-export/get-export-file-metadata','wp-import-export/validate-export',
				'wp-import-export/plan-export-run','wp-import-export/execution-readiness',
			),
			'content' => array(), 'admin' => array(),
		);
	}
	protected function mutation_requires_certification() { return false; }
	protected function provider_certification( $available ) { return null; }

	public function register_abilities() {
		$read = array( 'MAD4B_SCP_Policy', 'can_read' );
		$id = $this->schema( array( 'id' => array( 'type'=>'integer','minimum'=>1 ) ), array( 'id' ) );
		$list = $this->schema( array( 'limit' => array( 'type'=>'integer','minimum'=>1,'maximum'=>self::MAX_ITEMS ) ) );
		$this->add_ability('wp-import-export/status','Read WP All Import / Export Governed Status','status',$read);
		$this->add_ability('wp-import-export/list-imports','List WP All Import Jobs','list_imports',$read,$list);
		$this->add_ability('wp-import-export/get-import','Read WP All Import Job','get_import',$read,$id);
		$this->add_ability('wp-import-export/validate-import','Validate WP All Import Job Readiness','validate_import',$read,$id);
		$this->add_ability('wp-import-export/plan-import-run','Plan Governed WP All Import Run','plan_import_run',$read,$id);
		$this->add_ability('wp-import-export/list-exports','List WP All Export Jobs','list_exports',$read,$list);
		$this->add_ability('wp-import-export/get-export','Read WP All Export Job','get_export',$read,$id);
		$this->add_ability('wp-import-export/get-export-file-metadata','Read WP All Export File Metadata','get_export_file_metadata',$read,$id);
		$this->add_ability('wp-import-export/validate-export','Validate WP All Export Job Readiness','validate_export',$read,$id);
		$this->add_ability('wp-import-export/plan-export-run','Plan Governed WP All Export Run','plan_export_run',$read,$id);
		$this->add_ability('wp-import-export/execution-readiness','Read WP All Import / Export Execution Readiness','execution_readiness',$read);
	}

	public function status( $input = array() ) {
		$runtime = class_exists('MAD4B_SCP_Repository_Artifact_Catalog') ? MAD4B_SCP_Repository_Artifact_Catalog::runtime_plugins_for_family($this->id()) : array();
		$versions=array();
		foreach($runtime as $plugin){
			$file=isset($plugin['plugin_file'])?(string)$plugin['plugin_file']:'';
			$version=isset($plugin['version'])?sanitize_text_field((string)$plugin['version']):'';
			if(false!==strpos($file,'wp-all-import'))$versions['import']=$version;
			if(false!==strpos($file,'wp-all-export')||false!==strpos($file,'wpae-'))$versions['export']=$version;
		}
		return array(
			'contract'=>self::CONTRACT,'id'=>$this->id(),'label'=>$this->label(),'available'=>$this->is_available(),
			'authority_mode'=>'governed_read_plan_execution_unmounted','abilities'=>$this->ability_names(),
			'component_versions'=>$versions,'import_runtime_available'=>self::import_runtime_available(),
			'export_runtime_available'=>self::export_runtime_available(),'execution'=>$this->execution_readiness(),
			'caller_supplied_secret_allowed'=>false,'secret_material_exposed'=>false,'cron_url_execution_allowed'=>false,
			'import_run_rollback_contract'=>self::IMPORT_ROLLBACK_CONTRACT,'import_run_rollback_certified'=>false,
			'mutation_exposed'=>false,'reversible_contracts'=>array(),'runtime_plugins'=>$runtime,
		);
	}

	public function execution_readiness( $input = array() ) {
		$import_secret=self::provider_option_present('PMXI_Plugin','cron_job_key');
		$export_secret=self::provider_option_present('PMXE_Plugin','cron_job_key');
		return array(
			'contract'=>'mad4b.wp-import-export-execution-readiness.v1',
			'import'=>array(
				'provider_available'=>self::import_runtime_available(),'server_secret_configured'=>$import_secret,
				'direct_execution_contract_certified'=>false,'run_level_rollback_certified'=>false,
				'rollback_contract'=>self::IMPORT_ROLLBACK_CONTRACT,'mounted'=>false,
				'blockers'=>array_values(array_filter(array(
					self::import_runtime_available()?'':'wp_all_import_runtime_unavailable',
					$import_secret?'':'wp_all_import_server_secret_missing',
					'mad4b_wp_all_import_direct_execution_contract_unverified',
					'mad4b_wp_all_import_run_rollback_not_certified',
				))),
			),
			'export'=>array(
				'provider_available'=>self::export_runtime_available(),'server_secret_configured'=>$export_secret,
				'direct_execution_contract_certified'=>false,'mounted'=>false,
				'blockers'=>array_values(array_filter(array(
					self::export_runtime_available()?'':'wp_all_export_runtime_unavailable',
					$export_secret?'':'wp_all_export_server_secret_missing',
					'mad4b_wp_all_export_direct_execution_contract_unverified',
				))),
			),
			'desired_execution_abilities'=>array(
				'wp-import-export/trigger-import','wp-import-export/process-import','wp-import-export/cancel-import',
				'wp-import-export/trigger-export','wp-import-export/process-export','wp-import-export/cancel-export',
			),
			'mounted_execution_abilities'=>array(),'caller_supplied_secret_allowed'=>false,
			'secret_material_exposed'=>false,'cron_url_execution_allowed'=>false,
		);
	}

	public function list_imports( $input=array() ) { return $this->list_records('import',$this->limit($input)); }
	public function list_exports( $input=array() ) { return $this->list_records('export',$this->limit($input)); }
	public function get_import( $input ) {
		$r=$this->record('import',$this->input_id($input)); if(is_wp_error($r))return $r;
		return array('contract'=>'mad4b.wp-all-import-job.v1','job'=>$this->summary('import',$r),'secrets_exposed'=>false);
	}
	public function get_export( $input ) {
		$r=$this->record('export',$this->input_id($input)); if(is_wp_error($r))return $r;
		return array('contract'=>'mad4b.wp-all-export-job.v1','job'=>$this->summary('export',$r),'secrets_exposed'=>false);
	}
	public function validate_import( $input ) {
		$r=$this->record('import',$this->input_id($input)); if(is_wp_error($r))return $r;
		$job=$this->summary('import',$r); $ready=$this->execution_readiness(); $blockers=$ready['import']['blockers'];
		if(!empty($job['executing'])||!empty($job['processing']))$blockers[]='wp_all_import_job_already_running';
		return array(
			'contract'=>'mad4b.wp-all-import-validation.v1','job'=>$job,'candidate_sha256'=>hash('sha256',wp_json_encode($job)),
			'potential_effects'=>array('create_records','update_records','delete_or_trash_records','taxonomy_changes','media_changes','custom_field_changes'),
			'human_approval_required'=>true,'run_level_rollback_required'=>true,'ready_for_execution'=>false,
			'blockers'=>array_values(array_unique($blockers)),
		);
	}
	public function validate_export( $input ) {
		$r=$this->record('export',$this->input_id($input)); if(is_wp_error($r))return $r;
		$job=$this->summary('export',$r); $ready=$this->execution_readiness(); $blockers=$ready['export']['blockers'];
		if(!empty($job['executing'])||!empty($job['processing']))$blockers[]='wp_all_export_job_already_running';
		return array(
			'contract'=>'mad4b.wp-all-export-validation.v1','job'=>$job,'candidate_sha256'=>hash('sha256',wp_json_encode($job)),
			'content_mutation_expected'=>false,'human_approval_required'=>true,'ready_for_execution'=>false,
			'blockers'=>array_values(array_unique($blockers)),
		);
	}
	public function plan_import_run( $input ) {
		$v=$this->validate_import($input); if(is_wp_error($v))return $v;
		return array(
			'contract'=>'mad4b.wp-all-import-run-plan.v1','operation'=>'import_run','job_id'=>$v['job']['id'],
			'candidate_sha256'=>$v['candidate_sha256'],'impact'=>'high','human_approval_required'=>true,
			'approval_can_be_created'=>false,'write_ability_mounted'=>false,
			'required_rollback_contract'=>self::IMPORT_ROLLBACK_CONTRACT,'blockers'=>$v['blockers'],
			'next_action'=>'certify_run_level_rollback_and_secret_safe_direct_provider_execution_before_mount',
		);
	}
	public function plan_export_run( $input ) {
		$v=$this->validate_export($input); if(is_wp_error($v))return $v;
		return array(
			'contract'=>'mad4b.wp-all-export-run-plan.v1','operation'=>'export_run','job_id'=>$v['job']['id'],
			'candidate_sha256'=>$v['candidate_sha256'],'impact'=>'consequential_non_content_mutating',
			'human_approval_required'=>true,'approval_can_be_created'=>false,'write_ability_mounted'=>false,
			'blockers'=>$v['blockers'],'next_action'=>'certify_secret_safe_direct_provider_execution_before_mount',
		);
	}
	public function get_export_file_metadata( $input ) {
		$r=$this->record('export',$this->input_id($input)); if(is_wp_error($r))return $r;
		$path=''; $secure=false;
		try {
			if(class_exists('PMXE_Plugin')&&method_exists('PMXE_Plugin','getInstance')){
				$p=PMXE_Plugin::getInstance(); if(is_object($p)&&method_exists($p,'getOption'))$secure=(bool)$p->getOption('secure');
			}
			$attachment_id=(int)self::record_value($r,'attch_id',0); $options=self::record_options($r);
			if(!$secure&&$attachment_id>0&&function_exists('get_attached_file'))$path=(string)get_attached_file($attachment_id);
			if((''===$path||!is_readable($path))&&isset($options['filepath'])&&function_exists('wp_all_export_get_absolute_path'))$path=(string)wp_all_export_get_absolute_path((string)$options['filepath']);
		} catch(Throwable $e){$path='';}
		$available=''!==$path&&is_file($path)&&is_readable($path); $size=$available?(int)filesize($path):0; $sha=''; $reason='';
		if($available&&$size<=self::MAX_HASH_BYTES)$sha=(string)hash_file('sha256',$path); elseif($available)$reason='file_exceeds_bounded_hash_limit';
		return array(
			'contract'=>'mad4b.wp-all-export-file-metadata.v1','export_id'=>(int)self::record_value($r,'id',0),
			'available'=>$available,'filename'=>$available?sanitize_file_name(basename($path)):'',
			'size_bytes'=>$size,'sha256'=>$sha,'hash_skipped_reason'=>$reason,
			'modified_gmt'=>$available?gmdate('c',(int)filemtime($path)):'',
			'secure_mode'=>$secure,'filesystem_path_exposed'=>false,'public_url_exposed'=>false,'secret_material_exposed'=>false,
		);
	}

	private function list_records( $kind, $limit ) {
		$is_import='import'===$kind;
		if($is_import&&!self::import_runtime_available())return new WP_Error('mad4b_wp_all_import_unavailable','WP All Import runtime is unavailable.');
		if(!$is_import&&!self::export_runtime_available())return new WP_Error('mad4b_wp_all_export_unavailable','WP All Export runtime is unavailable.');
		$class=$is_import?'PMXI_Import_List':'PMXE_Export_List';
		try {
			$list=new $class();
			foreach(array('setColumns','getBy','convertRecords') as $method)if(!method_exists($list,$method))return new WP_Error('mad4b_wp_import_export_list_contract_unavailable','Provider list API is not compatible with the governed reader.');
			$table=method_exists($list,'getTable')?$list->getTable():''; $where=$is_import?array('parent_import_id'=>0):array();
			$list->setColumns(''!==$table?$table.'.*':'*')->getBy($where,'id DESC',1,$limit);
			$items=array(); foreach($list->convertRecords() as $record)$items[]=$this->summary($kind,$record);
			return array('contract'=>$is_import?'mad4b.wp-all-import-list.v1':'mad4b.wp-all-export-list.v1','items'=>$items,'count'=>count($items),'limit'=>$limit,'secrets_exposed'=>false);
		} catch(Throwable $e){return new WP_Error('mad4b_wp_import_export_list_failed','Provider jobs could not be read through the reviewed API.');}
	}
	private function record( $kind, $id ) {
		if($id<1)return new WP_Error('mad4b_wp_import_export_id_invalid','A positive job ID is required.');
		$is_import='import'===$kind;
		if($is_import&&!self::import_runtime_available())return new WP_Error('mad4b_wp_all_import_unavailable','WP All Import runtime is unavailable.');
		if(!$is_import&&!self::export_runtime_available())return new WP_Error('mad4b_wp_all_export_unavailable','WP All Export runtime is unavailable.');
		$class=$is_import?'PMXI_Import_Record':'PMXE_Export_Record';
		try {
			$r=new $class(); if(!method_exists($r,'getById'))return new WP_Error('mad4b_wp_import_export_record_contract_unavailable','Provider record API is unavailable.');
			$r->getById($id); if(method_exists($r,'isEmpty')&&$r->isEmpty())return new WP_Error('mad4b_wp_import_export_not_found','The requested provider job does not exist.'); return $r;
		} catch(Throwable $e){return new WP_Error('mad4b_wp_import_export_read_failed','The provider job could not be read through the reviewed API.');}
	}
	private function summary( $kind, $r ) {
		$options=self::record_options($r);
		$out=array(
			'id'=>(int)self::record_value($r,'id',0),
			'name'=>sanitize_text_field((string)self::record_value($r,'friendly_name',self::record_value($r,'name',''))),
			'triggered'=>(bool)self::record_value($r,'triggered',false),'processing'=>(bool)self::record_value($r,'processing',false),
			'executing'=>(bool)self::record_value($r,'executing',false),'canceled'=>(bool)self::record_value($r,'canceled',false),
			'registered_on'=>sanitize_text_field((string)self::record_value($r,'registered_on','')),
			'last_activity'=>sanitize_text_field((string)self::record_value($r,'last_activity','')),'raw_options_exposed'=>false,
		);
		if('import'===$kind){
			$out['type']=sanitize_key((string)self::record_value($r,'type',''));
			$out['target_type']=isset($options['custom_type'])&&is_scalar($options['custom_type'])?sanitize_key((string)$options['custom_type']):'';
			foreach(array('count','created','updated','skipped','deleted') as $k)$out[$k]=(int)self::record_value($r,$k,0);
			$out['failed']=(bool)self::record_value($r,'failed',false); $out['source_path_exposed']=false;
		} else {
			$cpt=isset($options['cpt'])?$options['cpt']:array(); if(!is_array($cpt))$cpt=array($cpt);
			$out['target_types']=array_values(array_filter(array_map('sanitize_key',$cpt))); $out['exported']=(int)self::record_value($r,'exported',0);
		}
		return $out;
	}
	private function input_id( $input ){ $input=is_array($input)?$input:array(); return isset($input['id'])?absint($input['id']):0; }
	private function limit( $input ){ $input=is_array($input)?$input:array(); $n=isset($input['limit'])?absint($input['limit']):25; return min(self::MAX_ITEMS,max(1,$n)); }
	private static function record_options( $r ){ $v=self::record_value($r,'options',array()); return is_array($v)?$v:array(); }
	private static function record_value( $r,$key,$default=null ){ if(!is_object($r))return $default; try{return isset($r->$key)?$r->$key:$default;}catch(Throwable $e){return $default;} }
	private static function import_runtime_available(){return class_exists('PMXI_Import_Record')&&class_exists('PMXI_Import_List')&&class_exists('PMXI_Plugin');}
	private static function export_runtime_available(){return class_exists('PMXE_Export_Record')&&class_exists('PMXE_Export_List')&&class_exists('PMXE_Plugin');}
	private static function provider_option_present( $class,$option ){
		if(!class_exists($class)||!method_exists($class,'getInstance'))return false;
		try{$p=call_user_func(array($class,'getInstance'));if(!is_object($p)||!method_exists($p,'getOption'))return false;$v=$p->getOption($option);return is_scalar($v)&&''!==trim((string)$v);}catch(Throwable $e){return false;}
	}
}

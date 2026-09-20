<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed WP All Import / WP All Export integration.
 * Read/inspect/validate/plan are exposed; execution stays unmounted until a
 * secret-safe direct provider contract is certified. Import additionally
 * requires a run-level reversible contract.
 */
final class MAD4B_SCP_WP_Import_Export_Adapter extends MAD4B_SCP_Adapter_Base {
	const CONTRACT = 'mad4b.wp-import-export-governed-adapter.v3';
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
				'wp-import-export/inspect-import-contract','wp-import-export/validate-import','wp-import-export/plan-import-run',
				'wp-import-export/list-exports','wp-import-export/get-export',
				'wp-import-export/inspect-export-contract','wp-import-export/get-export-file-metadata','wp-import-export/validate-export',
				'wp-import-export/plan-export-run','wp-import-export/execution-readiness',
			),
			'content' => array(), 'admin' => array(),
		);
	}

	public function register_abilities() {
		$read = array( 'MAD4B_SCP_Policy', 'can_read' );
		$id = $this->schema( array( 'id' => array( 'type'=>'integer','minimum'=>1 ) ), array( 'id' ) );
		$list = $this->schema( array( 'limit' => array( 'type'=>'integer','minimum'=>1,'maximum'=>self::MAX_ITEMS ) ) );
		$export_plan = $this->schema( array(
			'id' => array( 'type'=>'integer','minimum'=>1 ),
			'data_classification' => array( 'type'=>'string','enum'=>array('public','internal','sensitive','restricted') ),
			'retention_hours' => array( 'type'=>'integer','minimum'=>1,'maximum'=>720 ),
		), array( 'id','data_classification','retention_hours' ) );
		$this->add_ability('wp-import-export/status','Read WP All Import / Export Governed Status','status',$read);
		$this->add_ability('wp-import-export/list-imports','List WP All Import Jobs','list_imports',$read,$list);
		$this->add_ability('wp-import-export/get-import','Read WP All Import Job','get_import',$read,$id);
		$this->add_ability('wp-import-export/inspect-import-contract','Inspect WP All Import Exact Contract','inspect_import_contract',$read,$id);
		$this->add_ability('wp-import-export/validate-import','Validate WP All Import Job Readiness','validate_import',$read,$id);
		$this->add_ability('wp-import-export/plan-import-run','Plan Governed WP All Import Run','plan_import_run',$read,$id);
		$this->add_ability('wp-import-export/list-exports','List WP All Export Jobs','list_exports',$read,$list);
		$this->add_ability('wp-import-export/get-export','Read WP All Export Job','get_export',$read,$id);
		$this->add_ability('wp-import-export/inspect-export-contract','Inspect WP All Export Exact Contract','inspect_export_contract',$read,$id);
		$this->add_ability('wp-import-export/get-export-file-metadata','Read WP All Export File Metadata','get_export_file_metadata',$read,$id);
		$this->add_ability('wp-import-export/validate-export','Validate WP All Export Job Readiness','validate_export',$read,$id);
		$this->add_ability('wp-import-export/plan-export-run','Plan Governed WP All Export Run','plan_export_run',$read,$export_plan);
		$this->add_ability('wp-import-export/execution-readiness','Read WP All Import / Export Execution Readiness','execution_readiness',$read);
	}

	public function status( $input = array() ) {
		$runtime = class_exists('MAD4B_SCP_Repository_Artifact_Catalog') ? MAD4B_SCP_Repository_Artifact_Catalog::runtime_plugins_for_family($this->id()) : array();
		$certification=$this->provider_certification($this->is_available());
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
			'mutation_requires_certification'=>true,
			'provider_certification'=>is_array($certification)?$certification:null,
			'mutation_exposed'=>false,'reversible_contracts'=>array(),'runtime_plugins'=>$runtime,
		);
	}

	public function execution_readiness( $input = array() ) {
		$import_secret=self::provider_option_present('PMXI_Plugin','cron_job_key');
		$export_secret=self::provider_option_present('PMXE_Plugin','cron_job_key');
		$certification=$this->provider_certification($this->is_available());
		$exact_composite=!empty($certification['runtime_contract_ok'])&&'certified'===(isset($certification['status'])?(string)$certification['status']:'');
		$contract=class_exists('MAD4B_SCP_Provider_Contracts')?MAD4B_SCP_Provider_Contracts::get($this->id()):array();
		$components=isset($contract['components'])&&is_array($contract['components'])?$contract['components']:array();
		$import_verified=isset($components['import']['verified_contracts'])&&is_array($components['import']['verified_contracts'])?$components['import']['verified_contracts']:array();
		$export_verified=isset($components['export']['verified_contracts'])&&is_array($components['export']['verified_contracts'])?$components['export']['verified_contracts']:array();
		$import_cli=in_array('WP_CLI_all_import_present',$import_verified,true);
		$export_record=isset($components['export']['critical_files']['models/export/record.php']);
		return array(
			'contract'=>'mad4b.wp-import-export-execution-readiness.v3',
			'provider_certification_required'=>true,
			'exact_composite_artifact_certified'=>$exact_composite,
			'import'=>array(
				'provider_available'=>self::import_runtime_available(),
				'server_secret_configured'=>$import_secret,'server_secret_required'=>false,
				'execution_transport_candidate'=>$import_cli?'server_local_wp_cli':'unresolved',
				'package_transport_surface_observed'=>$import_cli,
				'direct_execution_contract_certified'=>false,'dry_run_diff_certified'=>false,
				'run_level_rollback_certified'=>false,'composite_receipt_certified'=>false,
				'rollback_contract'=>self::IMPORT_ROLLBACK_CONTRACT,'mounted'=>false,
				'blockers'=>array_values(array_filter(array(
					self::import_runtime_available()?'':'wp_all_import_runtime_unavailable',
					$exact_composite?'':'mad4b_wp_import_export_exact_composite_artifact_not_certified',
					$import_cli?'':'mad4b_wp_all_import_server_local_transport_unverified',
					'mad4b_wp_all_import_direct_execution_contract_unverified',
					'mad4b_wp_all_import_dry_run_diff_not_certified',
					'mad4b_wp_all_import_run_rollback_not_certified',
					'mad4b_bulk_content_io_operation_receipt_not_certified',
				))),
			),
			'export'=>array(
				'provider_available'=>self::export_runtime_available(),
				'server_secret_configured'=>$export_secret,'server_secret_required'=>false,
				'execution_transport_candidate'=>$export_record?'server_local_provider_record_execute':'unresolved',
				'package_transport_surface_observed'=>$export_record,
				'direct_execution_contract_certified'=>false,'artifact_registry_ingest_certified'=>false,
				'composite_receipt_certified'=>false,'mounted'=>false,
				'blockers'=>array_values(array_filter(array(
					self::export_runtime_available()?'':'wp_all_export_runtime_unavailable',
					$exact_composite?'':'mad4b_wp_import_export_exact_composite_artifact_not_certified',
					$export_record?'':'mad4b_wp_all_export_server_local_transport_unverified',
					'mad4b_wp_all_export_direct_execution_contract_unverified',
					'mad4b_wp_all_export_artifact_registry_ingest_not_certified',
					'mad4b_bulk_content_io_operation_receipt_not_certified',
				))),
			),
			'desired_execution_abilities'=>array(
				'wp-import-export/run-import','wp-import-export/run-export',
			),
			'internal_provider_primitives'=>array(
				'trigger-import','process-import','cancel-import','trigger-export','process-export','cancel-export',
			),
			'mounted_execution_abilities'=>array(),'caller_supplied_secret_allowed'=>false,
			'secret_material_exposed'=>false,'cron_url_execution_allowed'=>false,
			'operation_contract'=>'mad4b.bulk-content-io-operation.v1',
			'ledger_contract'=>'mad4b.content-operations-ledger.v1',
			'reconciliation_contract'=>'mad4b.bulk-content-io-reconciliation.v1',
			'receipt_contract'=>'mad4b.bulk-content-io-receipt.v1',
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
	public function inspect_import_contract( $input ) {
		$r=$this->record('import',$this->input_id($input)); if(is_wp_error($r))return $r;
		return $this->import_contract($r);
	}
	public function inspect_export_contract( $input ) {
		$r=$this->record('export',$this->input_id($input)); if(is_wp_error($r))return $r;
		return $this->export_contract($r);
	}
	public function validate_import( $input ) {
		$r=$this->record('import',$this->input_id($input)); if(is_wp_error($r))return $r;
		$job=$this->summary('import',$r); $contract=$this->import_contract($r); $ready=$this->execution_readiness(); $blockers=$ready['import']['blockers'];
		if(!empty($job['executing'])||!empty($job['processing']))$blockers[]='wp_all_import_job_already_running';
		foreach((array)$contract['blockers'] as $blocker)$blockers[]=$blocker;
		return array(
			'contract'=>'mad4b.wp-all-import-validation.v2','job'=>$job,
			'candidate_sha256'=>$contract['candidate_sha256'],'configuration_sha256'=>$contract['configuration_sha256'],
			'source_artifact'=>$contract['source_artifact'],'identity_strategy'=>$contract['identity_strategy'],
			'deletion_policy'=>$contract['deletion_policy'],'field_effect_policy'=>$contract['field_effect_policy'],
			'target_content_schema'=>$contract['target_content_schema'],'dry_run'=>$contract['dry_run'],
			'potential_effects'=>array('create_records','update_records','delete_or_trash_records','taxonomy_changes','media_changes','custom_field_changes'),
			'human_approval_required'=>true,'run_level_rollback_required'=>true,'ready_for_execution'=>false,
			'blockers'=>array_values(array_unique(array_filter($blockers))),
		);
	}
	public function validate_export( $input ) {
		$r=$this->record('export',$this->input_id($input)); if(is_wp_error($r))return $r;
		$job=$this->summary('export',$r); $contract=$this->export_contract($r); $ready=$this->execution_readiness(); $blockers=$ready['export']['blockers'];
		if(!empty($job['executing'])||!empty($job['processing']))$blockers[]='wp_all_export_job_already_running';
		return array(
			'contract'=>'mad4b.wp-all-export-validation.v2','job'=>$job,
			'candidate_sha256'=>$contract['candidate_sha256'],'configuration_sha256'=>$contract['configuration_sha256'],
			'field_selection_sha256'=>$contract['field_selection_sha256'],'filter_sha256'=>$contract['filter_sha256'],
			'content_mutation_expected'=>false,'human_approval_required'=>true,'ready_for_execution'=>false,
			'data_classification_required'=>true,'artifact_registry_required'=>true,
			'blockers'=>array_values(array_unique(array_filter($blockers))),
		);
	}
	public function plan_import_run( $input ) {
		$v=$this->validate_import($input); if(is_wp_error($v))return $v;
		$seed=array(
			'job_id'=>$v['job']['id'],'configuration_sha256'=>$v['configuration_sha256'],
			'source_sha256'=>isset($v['source_artifact']['sha256'])?$v['source_artifact']['sha256']:'',
			'identity_sha256'=>isset($v['identity_strategy']['identity_sha256'])?$v['identity_strategy']['identity_sha256']:'',
			'deletion_policy'=>$v['deletion_policy'],
		);
		$operation_id='bulk-import-'.substr(hash('sha256',wp_json_encode($seed)),0,24);
		return array(
			'contract'=>'mad4b.bulk-import-plan.v1','operation_contract'=>'mad4b.bulk-content-io-operation.v1',
			'operation_id'=>$operation_id,'operation'=>'import_run','job_id'=>$v['job']['id'],
			'candidate_sha256'=>$v['candidate_sha256'],'configuration_sha256'=>$v['configuration_sha256'],
			'source_artifact'=>$v['source_artifact'],'identity_strategy'=>$v['identity_strategy'],
			'deletion_policy'=>$v['deletion_policy'],'field_effect_policy'=>$v['field_effect_policy'],
			'target_content_schema'=>isset($v['target_content_schema'])?$v['target_content_schema']:array(),'dry_run'=>$v['dry_run'],
			'exact_preconditions'=>array(
				'expected_configuration_sha256'=>$v['configuration_sha256'],
				'expected_source_sha256'=>isset($v['source_artifact']['sha256'])?$v['source_artifact']['sha256']:'',
				'expected_candidate_sha256'=>$v['candidate_sha256'],
				'expected_target_schema_sha256'=>isset($v['target_content_schema']['schema_sha256'])?$v['target_content_schema']['schema_sha256']:'',
			),
			'impact'=>!empty($v['deletion_policy']['destructive_mode_detected'])?'critical':'high',
			'human_approval_required'=>true,
			'separate_destructive_approval_required'=>!empty($v['deletion_policy']['destructive_mode_detected']),
			'approval_can_be_created'=>false,'write_ability_mounted'=>false,
			'required_rollback_contract'=>self::IMPORT_ROLLBACK_CONTRACT,
			'required_receipt_contract'=>'mad4b.bulk-content-io-receipt.v1',
			'ledger_contract'=>'mad4b.content-operations-ledger.v1',
			'reconciliation_contract'=>'mad4b.bulk-content-io-reconciliation.v1',
			'context_receipt_required_before_execution'=>true,
			'blockers'=>$v['blockers'],
			'next_action'=>'close_exact_dry_run_rollback_receipt_and_secret_safe_provider_execution_contracts_before_mount',
		);
	}
	public function plan_export_run( $input ) {
		$v=$this->validate_export($input); if(is_wp_error($v))return $v;
		$classification=isset($input['data_classification'])?sanitize_key((string)$input['data_classification']):'';
		$retention=isset($input['retention_hours'])?absint($input['retention_hours']):0;
		if(!in_array($classification,array('public','internal','sensitive','restricted'),true))return new WP_Error('mad4b_wp_all_export_classification_required','A governed export requires public, internal, sensitive, or restricted classification.');
		if($retention<1||$retention>720)return new WP_Error('mad4b_wp_all_export_retention_invalid','Export retention must be between 1 and 720 hours.');
		$seed=array('job_id'=>$v['job']['id'],'candidate_sha256'=>$v['candidate_sha256'],'classification'=>$classification,'retention_hours'=>$retention);
		$operation_id='bulk-export-'.substr(hash('sha256',wp_json_encode($seed)),0,24);
		return array(
			'contract'=>'mad4b.bulk-export-plan.v1','operation_contract'=>'mad4b.bulk-content-io-operation.v1',
			'operation_id'=>$operation_id,'operation'=>'export_run','job_id'=>$v['job']['id'],
			'candidate_sha256'=>$v['candidate_sha256'],'configuration_sha256'=>$v['configuration_sha256'],
			'field_selection_sha256'=>$v['field_selection_sha256'],'filter_sha256'=>$v['filter_sha256'],
			'data_classification'=>$classification,'retention_hours'=>$retention,
			'destination'=>'mad4b_artifact_registry','artifact_registry_required'=>true,
			'artifact_contract'=>'mad4b.bulk-export-artifact.v1','required_receipt_contract'=>'mad4b.bulk-content-io-receipt.v1',
			'ledger_contract'=>'mad4b.content-operations-ledger.v1','reconciliation_contract'=>'mad4b.bulk-content-io-reconciliation.v1',
			'impact'=>'consequential_non_content_mutating','human_approval_required'=>true,
			'approval_can_be_created'=>false,'write_ability_mounted'=>false,
			'exact_preconditions'=>array('expected_candidate_sha256'=>$v['candidate_sha256'],'expected_configuration_sha256'=>$v['configuration_sha256']),
			'blockers'=>$v['blockers'],'next_action'=>'certify_secret_safe_provider_execution_artifact_registry_ingest_and_receipt_before_mount',
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

	private function import_contract( $r ) {
		$job=$this->summary('import',$r); $options=self::record_options($r);
		$config_sha=self::canonical_hash($options);
		$source=$this->source_artifact($r,$options);
		$identity=$this->identity_strategy($options);
		$deletion=$this->deletion_policy($options);
		$effects=$this->field_effect_policy($options);
		$schema=$this->target_schema_binding(isset($job['target_type'])?$job['target_type']:'');
		$blockers=array('mad4b_wp_all_import_dry_run_diff_not_certified');
		if(empty($identity['configured']))$blockers[]='wp_all_import_identity_strategy_unknown';
		if(empty($deletion['known']))$blockers[]='wp_all_import_delete_policy_unknown';
		if(empty($schema['bound']))$blockers[]='wp_all_import_target_content_schema_unknown';
		if(empty($source['sha256']))$blockers[]='wp_all_import_source_artifact_not_hash_bound';
		if(!empty($deletion['destructive_mode_detected']))$blockers[]='wp_all_import_destructive_delete_requires_separate_approval';
		$dry=array(
			'contract'=>'mad4b.bulk-import-dry-run.v1','certified'=>false,
			'create'=>null,'update'=>null,'unchanged'=>null,'invalid'=>null,'delete'=>null,
			'taxonomy_changes'=>null,'media_changes'=>null,
			'reason'=>'provider_exact_diff_simulation_not_yet_certified',
		);
		$candidate=self::canonical_hash(array(
			'job'=>$job,'provider_versions'=>$this->provider_versions(),'configuration_sha256'=>$config_sha,'source_sha256'=>$source['sha256'],
			'identity_sha256'=>$identity['identity_sha256'],'deletion_policy'=>$deletion,'field_effect_policy'=>$effects,
			'target_schema_sha256'=>isset($schema['schema_sha256'])?$schema['schema_sha256']:'',
		));
		return array(
			'contract'=>'mad4b.wp-all-import-exact-contract.v1','job_id'=>$job['id'],
			'configuration_sha256'=>$config_sha,'provider_versions'=>$this->provider_versions(),
			'target_content_type'=>$job['target_type'],'content_schema_binding_required'=>true,
			'target_content_schema'=>$schema,
			'source_artifact'=>$source,'identity_strategy'=>$identity,
			'deletion_policy'=>$deletion,'field_effect_policy'=>$effects,'dry_run'=>$dry,
			'candidate_sha256'=>$candidate,'blockers'=>array_values(array_unique($blockers)),
			'raw_options_exposed'=>false,'source_path_exposed'=>false,'secret_material_exposed'=>false,
		);
	}
	private function export_contract( $r ) {
		$job=$this->summary('export',$r); $options=self::record_options($r);
		$config_sha=self::canonical_hash($options);
		$field_material=array();
		$filter_material=array();
		foreach($options as $key=>$value){
			$k=strtolower((string)$key);
			if(false!==strpos($k,'field')||false!==strpos($k,'column'))$field_material[$key]=$value;
			if(false!==strpos($k,'filter')||false!==strpos($k,'where')||false!==strpos($k,'query'))$filter_material[$key]=$value;
		}
		$field_sha=self::canonical_hash($field_material);
		$filter_sha=self::canonical_hash($filter_material);
		$candidate=self::canonical_hash(array('job'=>$job,'provider_versions'=>$this->provider_versions(),'configuration_sha256'=>$config_sha,'field_selection_sha256'=>$field_sha,'filter_sha256'=>$filter_sha));
		return array(
			'contract'=>'mad4b.wp-all-export-exact-contract.v1','job_id'=>$job['id'],
			'provider_versions'=>$this->provider_versions(),
			'configuration_sha256'=>$config_sha,'field_selection_sha256'=>$field_sha,'filter_sha256'=>$filter_sha,
			'candidate_sha256'=>$candidate,'data_classification_required'=>true,
			'artifact_registry_required'=>true,'raw_options_exposed'=>false,'secret_material_exposed'=>false,
		);
	}
	private function target_schema_binding( $post_type ) {
		$post_type=sanitize_key((string)$post_type);
		if(''===$post_type||!function_exists('post_type_exists')||!post_type_exists($post_type)){
			return array(
				'contract'=>'mad4b.bulk-target-content-schema.v1','post_type'=>$post_type,
				'bound'=>false,'schema_sha256'=>'','reason'=>'post_type_unavailable',
			);
		}
		$obj=get_post_type_object($post_type);
		if(!is_object($obj)){
			return array(
				'contract'=>'mad4b.bulk-target-content-schema.v1','post_type'=>$post_type,
				'bound'=>false,'schema_sha256'=>'','reason'=>'post_type_object_unavailable',
			);
		}
		$supports=function_exists('get_all_post_type_supports')?(array)get_all_post_type_supports($post_type):array();
		$support_names=array_keys($supports); sort($support_names,SORT_STRING);
		$taxonomies=function_exists('get_object_taxonomies')?(array)get_object_taxonomies($post_type,'names'):array();
		$taxonomies=array_values(array_unique(array_map('sanitize_key',$taxonomies))); sort($taxonomies,SORT_STRING);
		$material=array(
			'post_type'=>$post_type,
			'public'=>!empty($obj->public),
			'hierarchical'=>!empty($obj->hierarchical),
			'show_ui'=>!empty($obj->show_ui),
			'supports'=>$support_names,
			'taxonomies'=>$taxonomies,
		);
		return array(
			'contract'=>'mad4b.bulk-target-content-schema.v1','post_type'=>$post_type,
			'bound'=>true,'schema_sha256'=>self::canonical_hash($material),
			'public'=>$material['public'],'hierarchical'=>$material['hierarchical'],'show_ui'=>$material['show_ui'],
			'supports'=>$support_names,'taxonomies'=>$taxonomies,
			'raw_capabilities_exposed'=>false,
		);
	}
	private function provider_versions() {
		$runtime=class_exists('MAD4B_SCP_Repository_Artifact_Catalog')?MAD4B_SCP_Repository_Artifact_Catalog::runtime_plugins_for_family($this->id()):array();
		$out=array();
		foreach((array)$runtime as $plugin){
			$file=isset($plugin['plugin_file'])?(string)$plugin['plugin_file']:'';
			$version=isset($plugin['version'])?sanitize_text_field((string)$plugin['version']):'';
			if(false!==strpos($file,'wp-all-import'))$out['import']=$version;
			if(false!==strpos($file,'wp-all-export')||false!==strpos($file,'wpae-'))$out['export']=$version;
		}
		ksort($out,SORT_STRING); return $out;
	}
	private function source_artifact( $r, array $options ) {
		$candidates=array();
		foreach(array('path','file','source_file','filepath') as $key){
			$value=self::record_value($r,$key,''); if(is_scalar($value)&&''!==trim((string)$value))$candidates[]=(string)$value;
			if(isset($options[$key])&&is_scalar($options[$key])&&''!==trim((string)$options[$key]))$candidates[]=(string)$options[$key];
		}
		$path='';
		foreach($candidates as $candidate){
			if(preg_match('#^https?://#i',$candidate))continue;
			if(is_file($candidate)&&is_readable($candidate)){$path=$candidate;break;}
		}
		$available=''!==$path; $size=$available?(int)filesize($path):0; $sha=''; $reason='';
		if($available&&$size<=self::MAX_HASH_BYTES)$sha=(string)hash_file('sha256',$path);
		elseif($available)$reason='file_exceeds_bounded_hash_limit';
		else $reason='local_source_artifact_unavailable_or_remote';
		return array(
			'contract'=>'mad4b.bulk-source-artifact.v1','available'=>$available,
			'filename'=>$available?sanitize_file_name(basename($path)):'',
			'size_bytes'=>$size,'sha256'=>$sha,'hash_skipped_reason'=>$reason,
			'modified_gmt'=>$available?gmdate('c',(int)filemtime($path)):'',
			'filesystem_path_exposed'=>false,'source_url_exposed'=>false,
		);
	}
	private function identity_strategy( array $options ) {
		$material=array();
		foreach(array('unique_key','unique_key_xpath','custom_unique_key','record_matching_by') as $key)if(array_key_exists($key,$options))$material[$key]=$options[$key];
		$configured=false;
		foreach($material as $value){
			if(is_scalar($value)&&''!==trim((string)$value)){$configured=true;break;}
			if(is_array($value)&&!empty($value)){$configured=true;break;}
		}
		return array(
			'contract'=>'mad4b.bulk-identity-strategy.v1','configured'=>$configured,
			'strategy'=>$configured?'provider_unique_key':'unknown',
			'identity_sha256'=>self::canonical_hash($material),
			'cardinality_policy'=>'0_create_1_update_gt1_fail_closed',
			'raw_identity_expression_exposed'=>false,
		);
	}
	private function deletion_policy( array $options ) {
		$known=false; $destructive=false; $evidence=array();
		if(array_key_exists('is_keep_former_posts',$options)){
			$known=true; $keep=self::truthy($options['is_keep_former_posts']); $destructive=!$keep; $evidence['is_keep_former_posts']=$keep;
		}
		foreach(array('is_delete_missing','delete_missing','remove_missing','is_delete_posts') as $key){
			if(array_key_exists($key,$options)){ $known=true; $enabled=self::truthy($options[$key]); $destructive=$destructive||$enabled; $evidence[$key]=$enabled; }
		}
		return array(
			'contract'=>'mad4b.bulk-delete-policy.v1','known'=>$known,
			'destructive_mode_detected'=>$destructive,'default_allow_delete'=>false,
			'separate_high_risk_approval_required'=>$destructive,
			'policy_sha256'=>self::canonical_hash($evidence),'raw_provider_options_exposed'=>false,
		);
	}
	private function field_effect_policy( array $options ) {
		$taxonomy=false; $media=false; $custom=false;
		foreach($options as $key=>$value){
			$k=strtolower((string)$key);
			if(false!==strpos($k,'tax')||false!==strpos($k,'term'))$taxonomy=true;
			if(false!==strpos($k,'image')||false!==strpos($k,'media')||false!==strpos($k,'attach'))$media=true;
			if(false!==strpos($k,'custom')||false!==strpos($k,'meta'))$custom=true;
		}
		return array('contract'=>'mad4b.bulk-field-effect-policy.v1','taxonomy_may_change'=>$taxonomy,'media_may_change'=>$media,'custom_fields_may_change'=>$custom);
	}
	private static function canonical_hash( $value ) {
		$value=self::canonicalize($value);
		$json=wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
		return hash('sha256',false===$json?'null':$json);
	}
	private static function canonicalize( $value ) {
		if(is_array($value)){
			$is_list=array_keys($value)===range(0,count($value)-1);
			if(!$is_list)ksort($value,SORT_STRING);
			foreach($value as $key=>$item)$value[$key]=self::canonicalize($item);
		}
		return $value;
	}
	private static function truthy( $value ) {
		if(is_bool($value))return $value;
		if(is_numeric($value))return 0!==(int)$value;
		return in_array(strtolower(trim((string)$value)),array('1','true','yes','on','enabled'),true);
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

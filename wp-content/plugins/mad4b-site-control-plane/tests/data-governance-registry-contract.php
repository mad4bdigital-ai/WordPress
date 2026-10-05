<?php
define( 'ABSPATH', __DIR__ . '/' );
function add_action($h,$c,$p=10){}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',strtolower(trim((string)$v))));}
function sanitize_text_field($v){return trim((string)$v);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $c;public function __construct($c,$m='',$d=null){$this->c=$c;}public function get_error_code(){return $this->c;}}

final class MAD4B_SCP_Provider_Execution_Binding {
	public static function bind($input=array()){
		return array(
			'contract'=>'mad4b.provider-execution-binding.v1',
			'provider_id'=>(string)$input['provider_id'],
			'capability_id'=>(string)$input['capability_id'],
			'binding_sha256'=>str_repeat('a',64),
			'provider_profile_fingerprint'=>str_repeat('b',64),
			'capability_certification_fingerprint'=>str_repeat('c',64),
			'descriptor_generation_sha256'=>str_repeat('d',64),
			'provider_release_ring'=>'shadow',
		);
	}
	public static function revalidate($input=array()){return array('valid'=>true);}
}

final class MAD4B_SCP_Artifacts {
	public static $rows=array();
	public static $links=array();
	public static $invalidated=array();
	public static function get_artifact($input){
		$id=$input['artifact_id'];
		return isset(self::$rows[$id])?array('artifact'=>self::$rows[$id]):new WP_Error('missing','missing');
	}
	public static function append_artifact($input){
		$id=sprintf('00000000-0000-4000-8000-%012d',count(self::$rows)+1);
		$row=array(
			'artifact_id'=>$id,'job_id'=>$input['job_id'],'artifact_type'=>$input['artifact_type'],
			'payload'=>$input['payload'],'metadata'=>$input['metadata'],'status'=>'active',
			'content_sha256'=>hash('sha256',json_encode($input['payload'])),
		);
		self::$rows[$id]=$row;
		return array('artifact'=>$row);
	}
	public static function link_artifacts($input){self::$links[]=$input;return array('mutation_performed'=>true);}
	public static function invalidate_artifact($input){
		self::$invalidated[]=$input['artifact_id'];
		if(isset(self::$rows[$input['artifact_id']])) self::$rows[$input['artifact_id']]['status']='stale';
		return array('artifact_id'=>$input['artifact_id'],'descendant_count'=>0,'mutation_performed'=>true);
	}
}

final class MAD4B_SCP_Data_Governance {
	public static function record_decision($input){
		return array(
			'artifact_id'=>'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
			'decision'=>'ALLOW',
			'decision_fingerprint'=>str_repeat('e',64),
			'mutation_performed'=>true,
			'provider_call_performed'=>false,
			'authority_created'=>false,
		);
	}
}

require dirname(__DIR__).'/includes/class-mad4b-scp-data-governance-registry.php';
$fail=static function($m){fwrite(STDERR,"FAIL data-governance-registry-contract: $m\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};

$job='11111111-2222-4333-8444-555555555555';
$source='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
MAD4B_SCP_Artifacts::$rows[$source]=array(
	'artifact_id'=>$source,'job_id'=>$job,'artifact_type'=>'scraped_page','payload'=>array(),
	'status'=>'active','content_sha256'=>str_repeat('f',64),
);

$rightsPlan=MAD4B_SCP_Data_Governance_Registry::rights_plan(array(
	'job_id'=>$job,'source_artifact_id'=>$source,'rights_class'=>'LICENSED','review_status'=>'approved',
	'license_id'=>'license-1','commercial_use_allowed'=>true,'policy_revision'=>'7',
));
$check(is_array($rightsPlan) && 1===preg_match('/^[a-f0-9]{64}$/',$rightsPlan['plan_sha256']),'rights plan invalid');
$check(false===$rightsPlan['raw_license_document_persisted'] && false===$rightsPlan['raw_source_text_persisted'],'raw rights material marked persistent');

$rights=MAD4B_SCP_Data_Governance_Registry::rights_apply(array('plan'=>$rightsPlan));
$check(is_array($rights) && 'rights_record'===$rights['artifact']['artifact_type'],'rights registry artifact missing');
$rightsId=$rights['artifact']['artifact_id'];

$profilePlan=MAD4B_SCP_Data_Governance_Registry::profile_plan(array(
	'job_id'=>$job,'provider_id'=>'provider-1','capability_id'=>'search.observe',
	'processor_type'=>'research_external','allowed_data_classes'=>array('public_content'),
	'region'=>'eu','training_use'=>false,'retention_days'=>7,'policy_revision'=>'7',
));
$check(is_array($profilePlan) && isset($profilePlan['provider_binding']['binding_sha256']),'processing profile not provider-bound');
$profile=MAD4B_SCP_Data_Governance_Registry::profile_apply(array('plan'=>$profilePlan));
$check(is_array($profile) && 'data_processing_profile'===$profile['artifact']['artifact_type'],'processing profile artifact missing');
$check(false===$profile['provider_call_performed'] && false===$profile['production_authorized'],'processing profile widened provider/Production authority');

$decision=MAD4B_SCP_Data_Governance_Registry::bound_decision_record(array(
	'job_id'=>$job,
	'processing_profile_artifact_id'=>$profile['artifact']['artifact_id'],
	'rights_record_artifact_ids'=>array($rightsId),
	'purpose'=>'research','data_classes'=>array('public_content'),
	'site_policy'=>array('policy_revision'=>'7'),'reason'=>'registry-bound decision',
));
$check(is_array($decision) && true===$decision['registry_bound'],'bound decision did not consume durable registries');
$check(1===preg_match('/^[a-f0-9]{64}$/',$decision['registry_binding_sha256']),'registry binding digest invalid');

$takedownPlan=MAD4B_SCP_Data_Governance_Registry::takedown_plan(array(
	'job_id'=>$job,'rights_record_artifact_id'=>$rightsId,'target_artifact_ids'=>array($source),'reason_code'=>'rights_takedown',
));
$check(false===$takedownPlan['public_content_delete_performed'],'takedown plan implies public deletion');
$takedown=MAD4B_SCP_Data_Governance_Registry::takedown_apply(array('plan'=>$takedownPlan));
$check(is_array($takedown) && 1===count(MAD4B_SCP_Artifacts::$invalidated),'takedown did not invalidate exact artifact lineage');
$check(false===$takedown['provider_call_performed'] && false===$takedown['production_authorized'],'takedown widened provider/Production authority');

$sourceCode=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-data-governance-registry.php');
foreach(array('wp_remote_get(','wp_remote_post(','curl_exec(','shell_exec(','proc_open(') as $forbidden){
	$check(false===strpos($sourceCode,$forbidden),'registry contains outbound/provider primitive: '.$forbidden);
}
echo "mad4b.data-governance-registry.v1: PASS\n";

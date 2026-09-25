<?php

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code; private $message; private $data;
	public function __construct($code,$message='',$data=null){$this->code=$code;$this->message=$message;$this->data=$data;}
	public function get_error_code(){return $this->code;}
	public function get_error_message(){return $this->message;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function add_action($h,$c,$p=10){}
function wp_register_ability($n,$a){}
function wp_has_ability($n){return false;}

final class MAD4B_SCP_Policy {
	public static function can_mutate(){ return true; }
}

final class MAD4B_SCP_Artifacts {
	public static $rows = array();
	public static $links = array();
	private static $counter = 1;

	public static function append_artifact($input){
		$id=sprintf('00000000-0000-4000-8000-%012d', self::$counter++);
		$row=array(
			'artifact_id'=>$id,
			'job_id'=>$input['job_id'],
			'artifact_type'=>$input['artifact_type'],
			'status'=>'active',
			'version'=>1,
			'payload'=>$input['payload'],
			'metadata'=>$input['metadata'],
			'producer_stage'=>$input['producer_stage'],
		);
		self::$rows[$id]=$row;
		return array('contract'=>'mad4b.artifact-registry.v1','artifact'=>$row,'mutation_performed'=>true);
	}
	public static function get_artifact($input){
		$id=$input['artifact_id'];
		return isset(self::$rows[$id])
			? array('contract'=>'mad4b.artifact-registry.v1','artifact'=>self::$rows[$id],'mutation_performed'=>false)
			: new WP_Error('mad4b_artifact_missing','missing');
	}
	public static function link_artifacts($input){
		self::$links[]=array(
			'from'=>$input['from_artifact_id'],
			'to'=>$input['to_artifact_id'],
			'relation'=>$input['relation'],
		);
		return array('contract'=>'mad4b.artifact-edge.v1','mutation_performed'=>true);
	}
}

require dirname(__DIR__) . '/includes/class-mad4b-scp-content-intelligence-pipeline.php';

$fail=static function($m){fwrite(STDERR,"FAIL content-intelligence-pipeline-contract: $m\n");exit(1);};
$check=static function($c,$m) use($fail){if(!$c)$fail($m);};
$job='11111111-2222-4333-8444-555555555555';

$bad_context=MAD4B_SCP_Content_Intelligence_Pipeline::build_context_pack(array(
	'job_id'=>$job,
	'sources'=>array(array(
		'source_id'=>'brand-1','asset_id'=>'asset-1','version'=>'1',
		'authority_state'=>'unknown','review_state'=>'pending',
		'fingerprint'=>str_repeat('a',64),'bytes'=>100,
	)),
));
$check(is_wp_error($bad_context) && 'mad4b_context_source_ineligible'===$bad_context->get_error_code(),'unreviewed context was accepted');

$context=MAD4B_SCP_Content_Intelligence_Pipeline::build_context_pack(array(
	'job_id'=>$job,
	'sources'=>array(
		array('source_id'=>'z-source','asset_id'=>'asset-2','version'=>'2','authority_state'=>'approved','review_state'=>'reviewed','fingerprint'=>str_repeat('b',64),'bytes'=>120),
		array('source_id'=>'a-source','asset_id'=>'asset-1','version'=>'1','authority_state'=>'authoritative','review_state'=>'approved','fingerprint'=>str_repeat('a',64),'bytes'=>100),
	),
));
$check(is_array($context) && 'context_pack'===$context['artifact']['artifact_type'],'ContextPack append failed');
$context_id=$context['artifact']['artifact_id'];
$check('a-source'===$context['artifact']['payload']['sources'][0]['source_id'],'ContextPack source order is not deterministic');
$check(220===$context['artifact']['payload']['total_declared_bytes'],'ContextPack bounded bytes incorrect');

$research=MAD4B_SCP_Content_Intelligence_Pipeline::append_research(array(
	'job_id'=>$job,
	'research_type'=>'serp_research',
	'provider_id'=>'provider-ci',
	'collected_at'=>'2026-09-25T00:00:00Z',
	'request_fingerprint'=>str_repeat('c',64),
	'source_refs'=>array('serp:1','serp:2'),
	'normalized_data'=>array('queries'=>array('alpha'),'results'=>array(array('rank'=>1,'url'=>'https://example.test/'))),
	'usage'=>array('requests'=>1),
	'cost'=>array('currency'=>'USD','amount'=>'0.01'),
	'freshness'=>'fresh',
));
$check(is_array($research) && 'serp_research'===$research['artifact']['artifact_type'],'Research append failed');
$research_id=$research['artifact']['artifact_id'];

$no_research=MAD4B_SCP_Content_Intelligence_Pipeline::build_blueprint(array(
	'job_id'=>$job,
	'context_artifact_id'=>$context_id,
	'research_artifact_ids'=>array(),
	'search_intent'=>'informational','audience'=>'traveler','goals'=>array('help'),'outline'=>array('intro'),'section_objectives'=>array('intro'=>'explain'),'evidence_requirements'=>array('source-backed'),
));
$check(is_wp_error($no_research) && 'mad4b_can_plan_research_missing'===$no_research->get_error_code(),'CAN_PLAN allowed missing research');

$blueprint=MAD4B_SCP_Content_Intelligence_Pipeline::build_blueprint(array(
	'job_id'=>$job,
	'context_artifact_id'=>$context_id,
	'research_artifact_ids'=>array($research_id),
	'search_intent'=>'informational',
	'audience'=>'traveler',
	'goals'=>array('answer intent'),
	'outline'=>array('intro','details'),
	'section_objectives'=>array('intro'=>'orient','details'=>'explain'),
	'evidence_requirements'=>array('source-backed claims'),
	'internal_linking_intent'=>array('relevant only'),
	'cta_intent'=>array('soft'),
	'structured_data_intent'=>array('Article'),
	'media_needs'=>array('hero'),
));
$check(is_array($blueprint) && true===$blueprint['artifact']['payload']['can_plan'],'Blueprint did not pass CAN_PLAN');
$blueprint_id=$blueprint['artifact']['artifact_id'];

$blocked_qa=MAD4B_SCP_Content_Intelligence_Pipeline::blueprint_qa(array(
	'job_id'=>$job,'blueprint_artifact_id'=>$blueprint_id,
	'hard_blockers'=>array('missing_required_evidence'),'warnings'=>array('minor'),
));
$check(is_array($blocked_qa) && false===$blocked_qa['artifact']['payload']['can_write'],'Blueprint hard blocker did not block CAN_WRITE');
$blocked_qa_id=$blocked_qa['artifact']['artifact_id'];

$draft_denied=MAD4B_SCP_Content_Intelligence_Pipeline::append_draft(array(
	'job_id'=>$job,'blueprint_artifact_id'=>$blueprint_id,'blueprint_qa_artifact_id'=>$blocked_qa_id,
	'context_artifact_id'=>$context_id,'content'=>'Draft body',
));
$check(is_wp_error($draft_denied) && 'mad4b_can_write_blocked'===$draft_denied->get_error_code(),'Draft bypassed failed Blueprint QA');

$pass_qa=MAD4B_SCP_Content_Intelligence_Pipeline::blueprint_qa(array(
	'job_id'=>$job,'blueprint_artifact_id'=>$blueprint_id,
	'hard_blockers'=>array(),'warnings'=>array(),
));
$pass_qa_id=$pass_qa['artifact']['artifact_id'];
$draft=MAD4B_SCP_Content_Intelligence_Pipeline::append_draft(array(
	'job_id'=>$job,'blueprint_artifact_id'=>$blueprint_id,'blueprint_qa_artifact_id'=>$pass_qa_id,
	'context_artifact_id'=>$context_id,'writer_profile_id'=>'writer-1','writer_profile_version'=>'3',
	'content'=>'Evidence-backed article draft.','section_count'=>2,
));
$check(is_array($draft) && true===$draft['artifact']['payload']['can_write'],'Approved draft append failed');
$draft_id=$draft['artifact']['artifact_id'];

$qa=MAD4B_SCP_Content_Intelligence_Pipeline::append_qa_bundle(array(
	'job_id'=>$job,'draft_artifact_id'=>$draft_id,
	'fact_ledger'=>array('claims'=>array(),'hard_blockers'=>array('unsupported_claim')),
	'editorial_qa'=>array('findings'=>array(),'hard_blockers'=>array()),
	'seo_qa'=>array('findings'=>array(),'hard_blockers'=>array()),
));
$check(is_array($qa) && false===$qa['pass'],'FinalQA averaged away a hard blocker');
$check(false===$qa['can_publish'] && false===$qa['publication_authorized'],'QA bundle created publish authority');
$final=MAD4B_SCP_Artifacts::$rows[$qa['final_qa_artifact_id']];
$check(false===$final['payload']['pass'],'FinalQA artifact pass mismatch');
$check(false===$final['payload']['can_publish'],'FinalQA artifact unexpectedly publishable');

// Stale upstream evidence invalidates future planning.
MAD4B_SCP_Artifacts::$rows[$context_id]['status']='stale';
$stale=MAD4B_SCP_Content_Intelligence_Pipeline::build_blueprint(array(
	'job_id'=>$job,'context_artifact_id'=>$context_id,'research_artifact_ids'=>array($research_id),
	'search_intent'=>'informational','audience'=>'traveler','goals'=>array('x'),
	'outline'=>array('x'),'section_objectives'=>array('x'),'evidence_requirements'=>array('x'),
));
$check(is_wp_error($stale) && 'mad4b_artifact_stale'===$stale->get_error_code(),'stale ContextPack did not invalidate CAN_PLAN');

// No direct provider SDK, WordPress publication or shell execution exists in semantic pipeline.
$source=file_get_contents(dirname(__DIR__) . '/includes/class-mad4b-scp-content-intelligence-pipeline.php');
foreach(array('wp_insert_post(','wp_update_post(','wp_publish_post(','shell_exec(','proc_open(','curl_exec(','OpenAI','Anthropic') as $forbidden){
	$check(false===strpos($source,$forbidden),'forbidden side effect/provider coupling present: '.$forbidden);
}
$check(count(MAD4B_SCP_Artifacts::$links)>=8,'artifact lineage links were not recorded');

echo "mad4b.content-intelligence-pipeline.v1: PASS\n";

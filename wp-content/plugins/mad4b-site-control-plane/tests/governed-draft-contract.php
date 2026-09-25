<?php

define('ABSPATH', __DIR__ . '/');

class WP_Error {
	private $code; private $message;
	public function __construct($code,$message=''){ $this->code=$code; $this->message=$message; }
	public function get_error_code(){ return $this->code; }
	public function get_error_message(){ return $this->message; }
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v)); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function add_action($h,$c,$p=10){}
function wp_register_ability($n,$a){}
function wp_has_ability($n){ return false; }
function absint($v){ return abs((int)$v); }
function wp_slash($v){ return $v; }
function current_user_can($cap,$id=null){ return true; }

$GLOBALS['draft_posts']=array();
$GLOBALS['draft_next_id']=10;

function get_post_type_object($type){
	if('post'!==$type) return null;
	$o=new stdClass(); $o->cap=new stdClass(); $o->cap->create_posts='edit_posts'; return $o;
}
function get_post($id){ return isset($GLOBALS['draft_posts'][$id]) ? clone $GLOBALS['draft_posts'][$id] : null; }
function wp_insert_post($data,$wp_error=false){
	$id=$GLOBALS['draft_next_id']++;
	$p=new stdClass();
	$p->ID=$id; $p->post_type=$data['post_type']; $p->post_status=$data['post_status'];
	$p->post_title=$data['post_title']; $p->post_content=$data['post_content']; $p->post_excerpt=$data['post_excerpt'];
	$p->post_modified_gmt='2026-09-25 01:00:00';
	$GLOBALS['draft_posts'][$id]=$p;
	return $id;
}
function wp_update_post($data,$wp_error=false){
	$id=(int)$data['ID'];
	if(!isset($GLOBALS['draft_posts'][$id])) return new WP_Error('missing','missing');
	$p=$GLOBALS['draft_posts'][$id];
	foreach(array('post_title','post_content','post_excerpt','post_status') as $k) if(array_key_exists($k,$data)) $p->$k=$data[$k];
	$p->post_modified_gmt='2026-09-25 01:00:01';
	$GLOBALS['draft_posts'][$id]=$p;
	return $id;
}

final class MAD4B_SCP_Policy {
	public static function can_read(){return true;}
	public static function can_mutate(){return true;}
}
final class MAD4B_SCP_Artifacts {
	public static $rows=array();
	public static function get_artifact($input){
		$id=$input['artifact_id'];
		return isset(self::$rows[$id])
			? array('artifact'=>self::$rows[$id],'mutation_performed'=>false)
			: new WP_Error('missing','missing');
	}
}

require dirname(__DIR__) . '/includes/class-mad4b-scp-governed-draft.php';

$fail=static function($m){fwrite(STDERR,"FAIL governed-draft-contract: $m\n");exit(1);};
$check=static function($c,$m) use($fail){if(!$c)$fail($m);};
$job='11111111-2222-4333-8444-555555555555';
$draft_id='22222222-3333-4444-8555-666666666666';
$qa_id='33333333-4444-4555-8666-777777777777';

MAD4B_SCP_Artifacts::$rows[$draft_id]=array(
	'artifact_id'=>$draft_id,'job_id'=>$job,'artifact_type'=>'draft','status'=>'active',
	'payload'=>array('contract'=>'mad4b.article-draft.v1','content'=>'Approved draft content.'),
);
MAD4B_SCP_Artifacts::$rows[$qa_id]=array(
	'artifact_id'=>$qa_id,'job_id'=>$job,'artifact_type'=>'final_qa','status'=>'active',
	'payload'=>array('contract'=>'mad4b.final-qa.v1','pass'=>false,'hard_blockers'=>array('unsupported_claim'),'can_publish'=>false,'publication_authorized'=>false),
);

$blocked=MAD4B_SCP_Governed_Draft::plan(array(
	'job_id'=>$job,'draft_artifact_id'=>$draft_id,'final_qa_artifact_id'=>$qa_id,
	'post_type'=>'post','post_title'=>'Draft title',
));
$check(is_wp_error($blocked) && 'mad4b_draft_final_qa_blocked'===$blocked->get_error_code(),'FinalQA blocker did not block draft');

MAD4B_SCP_Artifacts::$rows[$qa_id]['payload']=array(
	'contract'=>'mad4b.final-qa.v1','pass'=>true,'hard_blockers'=>array(),'can_publish'=>false,'publication_authorized'=>false,
);

$plan=MAD4B_SCP_Governed_Draft::plan(array(
	'job_id'=>$job,'draft_artifact_id'=>$draft_id,'final_qa_artifact_id'=>$qa_id,
	'post_type'=>'post','post_title'=>'Draft title','post_excerpt'=>'Draft excerpt',
));
$check(is_array($plan) && 'draft'===$plan['intended_status'],'draft plan failed');
$check(false===$plan['can_publish'] && false===$plan['publication_authorized'],'plan widened publish authority');
$check(false===$plan['mutation_performed'],'plan mutated');

$tampered=$plan;
$tampered['publication_authorized']=true;
$denied=MAD4B_SCP_Governed_Draft::apply(array('plan'=>$tampered));
$check(is_wp_error($denied) && 'mad4b_draft_plan_authority_invalid'===$denied->get_error_code(),'tampered publish authority was accepted');

$applied=MAD4B_SCP_Governed_Draft::apply(array('plan'=>$plan));
$check(is_array($applied) && 'draft'===$applied['post_status'],'draft create failed');
$check('PASS'===$applied['origin_readback'],'draft origin readback failed');
$check('NOT_CHECKED'===$applied['public_edge_verdict'],'draft falsely verified public edge');
$check(false===$applied['can_publish'] && false===$applied['publication_authorized'],'draft apply widened publish authority');
$post_id=$applied['post_id'];

$verify=MAD4B_SCP_Governed_Draft::verify(array(
	'post_id'=>$post_id,'expected_post_fingerprint'=>$applied['post_fingerprint'],
));
$check('PASS'===$verify['origin_verdict'] && true===$verify['origin_match'],'draft verify failed');
$check('NOT_INFERRED'===$verify['third_party_indexing_verdict'],'draft inferred indexing');

// Plan update, then simulate concurrent drift before apply.
$update_plan=MAD4B_SCP_Governed_Draft::plan(array(
	'job_id'=>$job,'draft_artifact_id'=>$draft_id,'final_qa_artifact_id'=>$qa_id,
	'post_id'=>$post_id,'post_type'=>'post','post_title'=>'Updated draft','post_excerpt'=>'',
));
$check(is_array($update_plan) && $post_id===$update_plan['post_id'],'update plan failed');
$GLOBALS['draft_posts'][$post_id]->post_title='external drift';
$GLOBALS['draft_posts'][$post_id]->post_modified_gmt='2026-09-25 01:00:02';
$stale=MAD4B_SCP_Governed_Draft::apply(array('plan'=>$update_plan));
$check(is_wp_error($stale) && 'mad4b_draft_target_stale'===$stale->get_error_code(),'stale target update was accepted');

// QA artifact itself may never claim publish authority.
MAD4B_SCP_Artifacts::$rows[$qa_id]['payload']['publication_authorized']=true;
$bad_qa=MAD4B_SCP_Governed_Draft::plan(array(
	'job_id'=>$job,'draft_artifact_id'=>$draft_id,'final_qa_artifact_id'=>$qa_id,
	'post_type'=>'post','post_title'=>'Bad QA',
));
$check(is_wp_error($bad_qa) && 'mad4b_draft_qa_authority_invalid'===$bad_qa->get_error_code(),'QA-created publish authority was accepted');

$source=file_get_contents(dirname(__DIR__) . '/includes/class-mad4b-scp-governed-draft.php');
foreach(array('wp_publish_post(','post_status\' => \'publish','post_status" => "publish','transition_post_status') as $forbidden){
	$check(false===strpos($source,$forbidden),'public publish primitive present: '.$forbidden);
}

echo "mad4b.governed-draft.v1: PASS\n";

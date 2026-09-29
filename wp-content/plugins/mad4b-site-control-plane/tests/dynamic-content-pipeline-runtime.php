<?php
define('ABSPATH', __DIR__ . '/');

$GLOBALS['mad4b_test_options'] = array();
$GLOBALS['mad4b_test_filters'] = array();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct($code='', $message='', $data=null){ $this->code=$code; $this->message=$message; $this->data=$data; }
	public function get_error_code(){ return $this->code; }
	public function get_error_message(){ return $this->message; }
	public function get_error_data(){ return $this->data; }
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function get_option($key,$default=array()){ return array_key_exists($key,$GLOBALS['mad4b_test_options']) ? $GLOBALS['mad4b_test_options'][$key] : $default; }
function update_option($key,$value,$autoload=false){ $GLOBALS['mad4b_test_options'][$key]=$value; return true; }
function add_option($key,$value,$deprecated='',$autoload='yes'){ if(array_key_exists($key,$GLOBALS['mad4b_test_options'])) return false; $GLOBALS['mad4b_test_options'][$key]=$value; return true; }
function delete_option($key){ if(!array_key_exists($key,$GLOBALS['mad4b_test_options'])) return false; unset($GLOBALS['mad4b_test_options'][$key]); return true; }
function wp_generate_uuid4(){ static $i=0; $i++; return sprintf('00000000-0000-4000-8000-%012d',$i); }
function current_user_can($cap){ return true; }
function absint($v){ return abs((int)$v); }
function sanitize_key($v){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v)); }
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function add_filter($tag,$callback,$priority=10,$accepted_args=1){ $GLOBALS['mad4b_test_filters'][$tag][]=$callback; return true; }
function has_filter($tag){ return !empty($GLOBALS['mad4b_test_filters'][$tag]); }
function apply_filters($tag,$value){
	$args=func_get_args();
	if(empty($GLOBALS['mad4b_test_filters'][$tag])) return $value;
	foreach($GLOBALS['mad4b_test_filters'][$tag] as $cb){
		$args[1]=$value;
		$value=call_user_func_array($cb,array_slice($args,1));
	}
	return $value;
}

require_once dirname(__DIR__) . '/includes/class-mad4b-scp-dynamic-content-pipeline.php';

function check($condition,$message){
	if(!$condition){ fwrite(STDERR,"FAIL: {$message}\n"); exit(1); }
}

final class MAD4B_Test_Dynamic_Adapter {
	public $repair_calls=0;
	public function structural_findings($state,$input,$iteration){
		return isset($input['force_finding']) && $input['force_finding']
			? array(array('code'=>'forced','repairable'=>true))
			: array();
	}
	public function repair_desired_state($post_id,$input){
		$this->repair_calls++;
		if(!empty($input['repair_error'])) return new WP_Error('repair_failed','repair failed');
		return true;
	}
}

$defaults=MAD4B_SCP_Dynamic_Content_Pipeline::defaults();
check($defaults['max_iterations']===3,'default max_iterations');
check(count($defaults['stages'])>=7,'advanced default stage catalog');
check($defaults['stages'][0]['id']==='structural' && $defaults['stages'][0]['order']===0,'structural is mandatory first validate stage');
check($defaults['stages'][1]['id']==='source_fidelity','source fidelity registered in defaults');
check($defaults['stages'][count($defaults['stages'])-1]['id']==='acceptance' && $defaults['stages'][count($defaults['stages'])-1]['order']===10000,'acceptance is mandatory terminal stage');

$conditions=MAD4B_SCP_Dynamic_Content_Pipeline::condition_registry();
foreach(array('mode','environment','post_type','post_status','has_meta','has_taxonomy','finding_code') as $id){
	check(isset($conditions[$id]),"condition {$id} registered");
}

$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'max_iterations'=>99,
	'no_progress_limit'=>99,
	'stages'=>array(
		array('id'=>'acceptance','enabled'=>true,'order'=>900,'phase'=>'accept','conditions'=>array(),'policy'=>array('required'=>true,'on_error'=>'stop')),
		array('id'=>'structural','enabled'=>true,'order'=>10,'phase'=>'validate','conditions'=>array('mode'=>array('create')),'policy'=>array('required'=>true,'on_error'=>'stop')),
	)
);
$effective=MAD4B_SCP_Dynamic_Content_Pipeline::effective();
check($effective['max_iterations']===5,'max_iterations clamped');
check($effective['no_progress_limit']===3,'no_progress_limit clamped');
check($effective['stages'][0]['id']==='structural' && $effective['stages'][0]['order']===0,'effective config restores structural safety position');
check(empty($effective['stages'][0]['conditions']),'effective config strips structural conditions');
$effective_last=$effective['stages'][count($effective['stages'])-1];
check($effective_last['id']==='acceptance' && $effective_last['order']===10000,'effective config restores terminal acceptance position');

$adapter=new MAD4B_Test_Dynamic_Adapter();
$context=array(
	'adapter'=>$adapter,
	'post_id'=>10,
	'mode'=>'update',
	'environment'=>'staging',
	'post_type'=>'post',
	'post_status'=>'draft',
	'input'=>array('force_finding'=>true,'meta'=>array(),'taxonomies'=>array()),
	'state'=>array(),
	'iteration'=>1,
	'findings'=>array(),
	'repair_allowed'=>true,
);
$result=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$context);
check(!is_wp_error($result),'mandatory structural validation remains executable');
check(count($result['findings'])===1 && $result['findings'][0]['code']==='forced','configured conditions cannot bypass mandatory structural validation');


$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'stages'=>array(
		array('id'=>'source_fidelity','enabled'=>true,'order'=>140,'phase'=>'validate','conditions'=>array('missing_condition'=>array('x')),'policy'=>array('required'=>true,'on_error'=>'stop')),
	)
);
$result=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$context);
check(is_wp_error($result) && $result->get_error_code()==='mad4b_dynamic_pipeline_condition_unavailable','required stage fails closed when configured condition provider disappears');

$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'stages'=>array(
		array('id'=>'source_fidelity','enabled'=>true,'order'=>140,'phase'=>'validate','conditions'=>array('missing_condition'=>array('x')),'policy'=>array('required'=>false,'on_error'=>'stop')),
	)
);
$result=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$context);
check(!is_wp_error($result),'optional stage may skip unavailable condition');
check(!empty($result['stage_results'][0]['status']) && $result['stage_results'][0]['status']==='skipped_condition_unavailable','optional unavailable condition is explicit, not silently removed');

$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'stages'=>array(
		array('id'=>'missing_stage','enabled'=>true,'order'=>1,'phase'=>'validate','conditions'=>array(),'policy'=>array('required'=>true,'on_error'=>'stop')),
	)
);
$result=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$context);
check(is_wp_error($result) && $result->get_error_code()==='mad4b_dynamic_pipeline_required_stage_unavailable','required missing stage fails closed');

$clean_context=array_merge($context,array(
	'input'=>array('force_finding'=>false,'meta'=>array(),'taxonomies'=>array()),
	'findings'=>array(),
));

add_filter('mad4b_scp_dynamic_content_pipeline_registry',function($registry){
	$registry['failing_validator']=array(
		'phase'=>'validate',
		'callback'=>function(){ return new WP_Error('validator_boom','boom'); },
		'description'=>'test validator',
	);
	$registry['failing_repair']=array(
		'phase'=>'repair',
		'callback'=>function(){ return new WP_Error('repair_boom','boom'); },
		'description'=>'test repair',
	);
	$registry['too_many_findings']=array(
		'phase'=>'validate',
		'callback'=>function($context){ $context['findings']=array(array('code'=>'one'),array('code'=>'two')); return $context; },
		'description'=>'budget findings validator',
	);
	$registry['slow_validator']=array(
		'phase'=>'validate',
		'callback'=>function($context){ usleep(5000); return $context; },
		'description'=>'budget time validator',
	);
	return $registry;
},10,1);

$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'stages'=>array(
		array('id'=>'failing_validator','enabled'=>true,'order'=>1,'phase'=>'validate','conditions'=>array(),'policy'=>array('required'=>false,'on_error'=>'finding')),
	)
);
$result=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$clean_context);
check(!is_wp_error($result),'validator on_error=finding continues');
check(count($result['findings'])===1 && $result['findings'][0]['code']==='pipeline_stage_error','validator error converted to finding');


$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'stages'=>array(
		array('id'=>'failing_validator','enabled'=>true,'order'=>1,'phase'=>'validate','conditions'=>array(),'policy'=>array('required'=>true,'on_error'=>'skip')),
	)
);
$result=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$clean_context);
check(is_wp_error($result) && $result->get_error_code()==='validator_boom','required validator errors cannot be bypassed with on_error=skip');

$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'stages'=>array(
		array('id'=>'source_fidelity','enabled'=>true,'order'=>1,'phase'=>'validate','conditions'=>array(),'policy'=>array('required'=>false,'on_error'=>'finding')),
	)
);
$result=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$clean_context);
check(!is_wp_error($result),'optional missing validator follows configured on_error policy');
check(
	count($result['findings'])===1
	&& $result['findings'][0]['code']==='pipeline_stage_error'
	&& $result['findings'][0]['error_code']==='mad4b_dynamic_pipeline_validator_unavailable',
	'enabled optional validator absence is explicit finding, not silent success'
);

$heartbeat_calls=0;
$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'stages'=>array(
		array('id'=>'structural','enabled'=>true,'order'=>1,'phase'=>'validate','conditions'=>array(),'policy'=>array('required'=>true,'on_error'=>'stop')),
	)
);
$heartbeat_context=array_merge($context,array(
	'mode'=>'create',
	'input'=>array('force_finding'=>false,'meta'=>array(),'taxonomies'=>array()),
	'heartbeat'=>function($phase,$stage,$ctx) use (&$heartbeat_calls){ $heartbeat_calls++; return true; },
));
$result=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$heartbeat_context);
check(!is_wp_error($result) && $heartbeat_calls===1,'request-local heartbeat runs before enabled pipeline stage');

$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'stages'=>array(
		array('id'=>'failing_repair','enabled'=>true,'order'=>1,'phase'=>'repair','conditions'=>array(),'policy'=>array('required'=>false,'on_error'=>'skip')),
	)
);
$result=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('repair',$context);
check(is_wp_error($result) && $result->get_error_code()==='repair_boom','repair error remains fail closed despite on_error=skip');

$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'stages'=>array(
		array('id'=>'acceptance','enabled'=>false,'order'=>1,'phase'=>'validate','conditions'=>array('mode'=>array('never')),'policy'=>array('required'=>false,'on_error'=>'skip')),
	)
);
$accept=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('accept',array_merge($context,array('findings'=>array())));
check(!is_wp_error($accept) && !empty($accept['accepted']) && $accept['acceptance_status']==='accepted','effective config restores non-bypassable terminal acceptance');



$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'stages'=>array(
		array('id'=>'too_many_findings','enabled'=>true,'order'=>1,'phase'=>'validate','conditions'=>array(),'policy'=>array('required'=>true,'on_error'=>'stop','max_findings'=>1)),
	)
);
$result=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$context);
check(is_wp_error($result) && $result->get_error_code()==='mad4b_dynamic_pipeline_findings_budget_exceeded','stage findings budget enforced');

$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'stages'=>array(
		array('id'=>'slow_validator','enabled'=>true,'order'=>1,'phase'=>'validate','conditions'=>array(),'policy'=>array('required'=>true,'on_error'=>'stop','max_elapsed_ms'=>1)),
	)
);
$result=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$context);
check(is_wp_error($result) && $result->get_error_code()==='mad4b_dynamic_pipeline_stage_time_budget_exceeded','stage soft time budget enforced');

$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'contract'=>MAD4B_SCP_Dynamic_Content_Pipeline::CONTRACT,
	'revision'=>4,
	'updated_at'=>'2026-09-28T00:00:00Z',
	'max_iterations'=>3,
	'stop_on_no_progress'=>true,
	'no_progress_limit'=>1,
	'default_repair_mode'=>'safe_only',
	'stages'=>MAD4B_SCP_Dynamic_Content_Pipeline::defaults()['stages'],
);
$missing_revision=MAD4B_SCP_Dynamic_Content_Pipeline::persist(array('max_iterations'=>4));
check(is_wp_error($missing_revision) && $missing_revision->get_error_code()==='mad4b_dynamic_pipeline_expected_revision_required','persist requires expected_revision');

$stale=MAD4B_SCP_Dynamic_Content_Pipeline::persist(array('expected_revision'=>3,'max_iterations'=>4));
check(is_wp_error($stale) && $stale->get_error_code()==='mad4b_dynamic_pipeline_stale','stale pipeline settings update rejected');

$unsafe_stages=MAD4B_SCP_Dynamic_Content_Pipeline::defaults()['stages'];
$unsafe_stages[0]['enabled']=false;
$unsafe=MAD4B_SCP_Dynamic_Content_Pipeline::persist(array('expected_revision'=>4,'stages'=>$unsafe_stages));
check(is_wp_error($unsafe) && $unsafe->get_error_code()==='mad4b_dynamic_pipeline_mandatory_stage_disabled','persist rejects disabling mandatory structural validation');

$saved=MAD4B_SCP_Dynamic_Content_Pipeline::persist(array('expected_revision'=>4,'max_iterations'=>4));
check(!is_wp_error($saved),'matching revision pipeline settings update succeeds');
check($saved['revision']===5,'pipeline revision increments exactly once');
check($saved['max_iterations']===4,'pipeline setting persisted');
check(isset($GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]['revision']) && $GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]['revision']===5,'stored revision exact readback');


$pin_base=MAD4B_SCP_Dynamic_Content_Pipeline::effective();
$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::OPTION]=array(
	'contract'=>MAD4B_SCP_Dynamic_Content_Pipeline::CONTRACT,
	'revision'=>20,
	'max_iterations'=>3,
	'stages'=>array(
		array('id'=>'acceptance','enabled'=>true,'order'=>1,'phase'=>'accept','conditions'=>array(),'policy'=>array('required'=>true,'on_error'=>'stop')),
	),
);
$pinned_context=array_merge($context,array('mode'=>'create','findings'=>array(),'pipeline_config'=>$pin_base));
$pinned_result=MAD4B_SCP_Dynamic_Content_Pipeline::run_phase('validate',$pinned_context);
check(!is_wp_error($pinned_result),'pinned pipeline config remains executable after persisted settings change');
check(!empty($pinned_result['stage_results']) && $pinned_result['stage_results'][0]['stage_id']==='structural','run_phase uses pinned pipeline config instead of reloading changed settings');

$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::LOCK_OPTION]=array('token'=>'other','acquired_at'=>time());
$busy=MAD4B_SCP_Dynamic_Content_Pipeline::persist(array('expected_revision'=>20,'max_iterations'=>4));
check(is_wp_error($busy) && $busy->get_error_code()==='mad4b_dynamic_pipeline_busy','fresh settings lock serializes concurrent pipeline updates');
unset($GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::LOCK_OPTION]);

$GLOBALS['mad4b_test_options'][MAD4B_SCP_Dynamic_Content_Pipeline::LOCK_OPTION]=array('token'=>'stale','acquired_at'=>time()-MAD4B_SCP_Dynamic_Content_Pipeline::LOCK_TTL-1);
$stale_lock_save=MAD4B_SCP_Dynamic_Content_Pipeline::persist(array('expected_revision'=>20,'max_iterations'=>4));
check(!is_wp_error($stale_lock_save),'stale settings lock is safely reclaimed');
check($stale_lock_save['revision']===21,'stale lock recovery still preserves revision CAS');

echo "mad4b.dynamic-content-pipeline.runtime.v1: PASS\n";

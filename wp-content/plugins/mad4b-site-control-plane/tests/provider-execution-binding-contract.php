<?php
define( 'ABSPATH', __DIR__ . '/' );
function add_action($h,$c,$p=10){}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',trim((string)$v))));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $c;public function __construct($c,$m='',$d=null){$this->c=$c;}public function get_error_code(){return $this->c;}}

final class MAD4B_SCP_Capability_Traits {
	public static $drift=false;
	public static function resolve($input=array()){
		$provider=isset($input['preferred_provider'])?(string)$input['preferred_provider']:'';
		$row=array(
			'provider_id'=>$provider,
			'capability_id'=>(string)$input['capability_id'],
			'profile_fingerprint'=>self::$drift?str_repeat('9',64):str_repeat('a',64),
			'certification_fingerprint'=>str_repeat('b',64),
			'descriptor_generation_sha256'=>str_repeat('c',64),
			'certification_level'=>'READ_COMPATIBLE',
			'activation_stage'=>'active',
		);
		return array(
			'selected_provider'=>$provider,
			'eligible'=>array($row),
			'resolution_fingerprint'=>str_repeat('d',64),
			'ambiguous'=>false,
			'implicit_tie_breaking'=>false,
		);
	}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-provider-execution-binding.php';

$fail=static function($m){fwrite(STDERR,"FAIL provider-execution-binding-contract: $m\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};

$binding=MAD4B_SCP_Provider_Execution_Binding::bind(array(
	'plan_family'=>'research_provider',
	'provider_id'=>'p1',
	'capability_id'=>'search.observe',
	'required_traits'=>array('local_or_remote'=>'remote_provider'),
	'release_ring'=>'active',
	'require_certified'=>true,
));
$check(is_array($binding),'binding failed');
$check('mad4b.provider-execution-binding.v1'===$binding['contract'],'binding contract mismatch');
$check('p1'===$binding['provider_id'] && 'search.observe'===$binding['capability_id'],'identity not exact-bound');
$check(1===preg_match('/^[a-f0-9]{64}$/',$binding['binding_sha256']),'binding digest invalid');
$check(false===$binding['authorizing'] && false===$binding['provider_execution_performed'] && false===$binding['mutation_performed'],'binding widened execution/authority');

$stable=MAD4B_SCP_Provider_Execution_Binding::revalidate(array('binding'=>$binding));
$check(is_array($stable) && true===$stable['valid'],'stable binding did not revalidate');

MAD4B_SCP_Capability_Traits::$drift=true;
$drift=MAD4B_SCP_Provider_Execution_Binding::revalidate(array('binding'=>$binding));
$check(is_wp_error($drift) && 'mad4b_provider_binding_drift'===$drift->get_error_code(),'trait/profile drift was not fenced');

$bad=MAD4B_SCP_Provider_Execution_Binding::bind(array('plan_family'=>'research_provider','provider_id'=>'p1','capability_id'=>'','release_ring'=>'active'));
$check(is_wp_error($bad) && 'mad4b_provider_binding_capability_invalid'===$bad->get_error_code(),'missing capability did not fail closed');

$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-provider-execution-binding.php');
foreach(array('wp_remote_get(','wp_remote_post(','curl_exec(','shell_exec(','proc_open(','wp_insert_post(') as $forbidden){
	$check(false===strpos($source,$forbidden),'binding contains provider/mutation primitive: '.$forbidden);
}
echo "mad4b.provider-execution-binding.v1: PASS\n";

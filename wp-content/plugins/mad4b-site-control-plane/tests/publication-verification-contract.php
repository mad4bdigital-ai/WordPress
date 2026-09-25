<?php

define('ABSPATH', __DIR__ . '/');

class WP_Error {
	private $code; private $message;
	public function __construct($code,$message=''){ $this->code=$code; $this->message=$message; }
	public function get_error_code(){ return $this->code; }
	public function get_error_message(){ return $this->message; }
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function sanitize_key($v){ $v=strtolower((string)$v); return preg_replace('/[^a-z0-9_\-]/','',$v); }
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }
function add_action($h,$c,$p=10){}
function wp_register_ability($n,$a){}
function wp_has_ability($n){ return false; }

final class MAD4B_SCP_Policy { public static function can_read(){ return true; } }

require dirname(__DIR__) . '/includes/class-mad4b-scp-publication-verification.php';

$fail=static function($m){fwrite(STDERR,"FAIL publication-verification-contract: $m\n");exit(1);};
$check=static function($c,$m) use($fail){if(!$c)$fail($m);};

$shaA=str_repeat('a',64);
$shaB=str_repeat('b',64);
$required=array('content','canonical','robots','structured_data','language','media','links','sitemap');

$obs=static function($surface,$shaA,$shaB,$edgeMismatch=false){
	$dims=array();
	foreach(array('content','canonical','robots','structured_data','language','media','links','sitemap') as $d){
		$expected='content'===$d ? $shaA : $shaB;
		$observed=$expected;
		if($edgeMismatch && 'canonical'===$d) $observed=str_repeat('c',64);
		$dims[$d]=array('verdict'=>'PASS','expected_sha256'=>$expected,'observed_sha256'=>$observed);
	}
	return array(
		'observed_at'=>'2026-09-25T01:00:00Z',
		'observer_ref'=>'ci:'.$surface,
		'dimensions'=>$dims,
	);
};

$pass=MAD4B_SCP_Publication_Verification::evaluate(array(
	'required_dimensions'=>$required,
	'origin'=>$obs('origin',$shaA,$shaB,false),
	'public_edge'=>$obs('edge',$shaA,$shaB,false),
	'verification_started_at'=>'2026-09-25T01:00:00Z',
	'evaluated_at'=>'2026-09-25T01:01:00Z',
	'propagation_timeout_seconds'=>300,
));
$check(is_array($pass) && 'PASS'===$pass['verdict'],'matching origin/edge did not PASS');
$check(false===$pass['authorizing'] && false===$pass['mutation_performed'],'verifier became authorizing/mutating');
$check(false===$pass['cache_purge_performed'] && false===$pass['publication_mutation_performed'],'verifier performed side effect');
$check(false===$pass['third_party_indexing']['inferred_from_publication'],'indexing was inferred from publication');

$pending=MAD4B_SCP_Publication_Verification::evaluate(array(
	'required_dimensions'=>$required,
	'origin'=>$obs('origin',$shaA,$shaB,false),
	'public_edge'=>$obs('edge',$shaA,$shaB,true),
	'verification_started_at'=>'2026-09-25T01:00:00Z',
	'evaluated_at'=>'2026-09-25T01:02:00Z',
	'propagation_timeout_seconds'=>300,
));
$check('PENDING_PROPAGATION'===$pending['verdict'],'edge mismatch inside window did not become PENDING_PROPAGATION');
$check(in_array('canonical',$pending['public_edge_failures'],true),'pending mismatch dimension missing');

$timeout=MAD4B_SCP_Publication_Verification::evaluate(array(
	'required_dimensions'=>$required,
	'origin'=>$obs('origin',$shaA,$shaB,false),
	'public_edge'=>$obs('edge',$shaA,$shaB,true),
	'verification_started_at'=>'2026-09-25T01:00:00Z',
	'evaluated_at'=>'2026-09-25T01:10:00Z',
	'propagation_timeout_seconds'=>300,
));
$check('FAIL'===$timeout['verdict'] && 'public_edge_propagation_timeout'===$timeout['reason_code'],'propagation timeout did not FAIL');

$originBad=$obs('origin',$shaA,$shaB,false);
$originBad['dimensions']['robots']['observed_sha256']=str_repeat('d',64);
$originFail=MAD4B_SCP_Publication_Verification::evaluate(array(
	'required_dimensions'=>$required,
	'origin'=>$originBad,
	'public_edge'=>$obs('edge',$shaA,$shaB,false),
	'verification_started_at'=>'2026-09-25T01:00:00Z',
	'evaluated_at'=>'2026-09-25T01:00:10Z',
	'propagation_timeout_seconds'=>300,
));
$check('FAIL'===$originFail['verdict'] && 'origin_mismatch'===$originFail['reason_code'],'origin mismatch did not fail immediately');
$check(in_array('robots',$originFail['origin_failures'],true),'origin mismatch dimension missing');

$missing=$obs('origin',$shaA,$shaB,false);
unset($missing['dimensions']['sitemap']);
$missingResult=MAD4B_SCP_Publication_Verification::evaluate(array(
	'required_dimensions'=>$required,
	'origin'=>$missing,
	'public_edge'=>$obs('edge',$shaA,$shaB,false),
	'verification_started_at'=>'2026-09-25T01:00:00Z',
	'evaluated_at'=>'2026-09-25T01:00:10Z',
	'propagation_timeout_seconds'=>300,
));
$check('FAIL'===$missingResult['verdict'],'missing required origin dimension did not fail');
$check(in_array('sitemap',$missingResult['origin_failures'],true),'missing dimension not reported');

$observedIndex=MAD4B_SCP_Publication_Verification::evaluate(array(
	'required_dimensions'=>$required,
	'origin'=>$obs('origin',$shaA,$shaB,false),
	'public_edge'=>$obs('edge',$shaA,$shaB,false),
	'verification_started_at'=>'2026-09-25T01:00:00Z',
	'evaluated_at'=>'2026-09-25T01:00:10Z',
	'propagation_timeout_seconds'=>300,
	'third_party_indexing_observation'=>array('verdict'=>'observed_indexed'),
));
$check(true===$observedIndex['third_party_indexing']['observed'],'explicit indexing observation lost');
$check(false===$observedIndex['third_party_indexing']['inferred_from_publication'],'explicit indexing path still inferred publication');

$source=file_get_contents(dirname(__DIR__) . '/includes/class-mad4b-scp-publication-verification.php');
$servers=file_get_contents(dirname(__DIR__) . '/includes/class-mad4b-scp-servers.php');
$main=file_get_contents(dirname(__DIR__) . '/mad4b-site-control-plane.php');
$check(false!==strpos($servers,"'mad4b/publication-verification-evaluate'"),'verification evaluator is not mounted on content read plane');
$check(false!==strpos($main,'class-mad4b-scp-publication-verification.php'),'verification reducer runtime is not loaded');
foreach(array('wp_insert_post(','wp_update_post(','wp_publish_post(','wp_remote_get(','wp_remote_post(','shell_exec(','proc_open(') as $forbidden){
	$check(false===strpos($source,$forbidden),'verification reducer contains forbidden side effect: '.$forbidden);
}

echo "mad4b.publication-verification.v1: PASS\n";

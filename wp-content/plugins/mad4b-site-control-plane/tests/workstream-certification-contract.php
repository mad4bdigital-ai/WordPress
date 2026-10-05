<?php
define( 'ABSPATH', __DIR__ . '/' );
function add_action($h,$c,$p=10){}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',strtolower(trim((string)$v))));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{
 private $c;private $d;
 public function __construct($c,$m='',$d=null){$this->c=$c;$this->d=$d;}
 public function get_error_code(){return $this->c;}
 public function get_error_data(){return $this->d;}
}
final class MAD4B_SCP_Live_Acceptance_Observer{
 public static function build_provenance_identity_status(){
  return array(
   'identity_ready'=>true,
   'source_commit_sha'=>str_repeat('a',40),
   'build_fingerprint'=>str_repeat('b',64),
   'package_manifest_digest'=>str_repeat('c',64),
   'artifact_identity'=>'sha256:'.str_repeat('d',64),
  );
 }
}
final class MAD4B_SCP_Production_Certification{
 public static function execute($input=array()){
  return array(
   'ready'=>true,
   'blockers'=>array(),
   'producer_evidence_sha256'=>str_repeat('e',64),
  );
 }
}

require dirname(__DIR__).'/includes/class-mad4b-scp-workstream-certification.php';

$fail=static function($m){fwrite(STDERR,"FAIL workstream-certification-contract: $m\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};

$all=MAD4B_SCP_Workstream_Certification::status(array());
$check(is_array($all),'status did not return array');
$check('mad4b.feature007-workstream-certification-status.v1'===$all['contract'],'status contract mismatch');
$check(23===$all['counts']['total'],'expected exactly 23 governed PARTIAL workstreams');
$check(false===$all['caller_live_evidence_accepted'],'caller live evidence unexpectedly accepted');
$check(false===$all['repository_structure_is_live_certification'],'repository structure incorrectly treated as live certification');
$check(false===$all['production_ready_implied'] && false===$all['production_authorized'],'status widened Production semantics');

foreach($all['items'] as $row){
 $check(true===$row['repository']['ready'],'declared repository evidence missing for '.$row['id']);
 $check(false===$row['repository']['live_certification'],'repository evidence marked live for '.$row['id']);
}

$stageOnly=MAD4B_SCP_Workstream_Certification::status(array('workstream_id'=>'provider_side_channel'));
$check(1===$stageOnly['counts']['total'],'single workstream filter failed');
$check('READY'===$stageOnly['items'][0]['state'],'trusted stage-only producer did not satisfy its live gate in fixture');

$external=MAD4B_SCP_Workstream_Certification::status(array('workstream_id'=>'capability_traits'));
$check('EXTERNAL_EVIDENCE_REQUIRED'===$external['items'][0]['state'],'external requirement was silently self-certified');
$check(!empty($external['items'][0]['external_requirements']),'external requirements missing');

$spoofed=MAD4B_SCP_Workstream_Certification::status(array(
 'workstream_id'=>'capability_traits',
 'live_evidence'=>array('trusted_runtime_limits_measured'=>true,'live_provider_trait_evidence'=>true),
));
$check('EXTERNAL_EVIDENCE_REQUIRED'===$spoofed['items'][0]['state'],'caller supplied evidence altered live state');

$unknown=MAD4B_SCP_Workstream_Certification::status(array('workstream_id'=>'not_a_registered_workstream'));
$check(is_wp_error($unknown) && 'mad4b_feature007_workstream_unknown'===$unknown->get_error_code(),'unknown workstream did not fail closed');

$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-workstream-certification.php');
foreach(array('update_option(','add_option(','delete_option(','wp_remote_get(','wp_remote_post(','shell_exec(','proc_open(') as $forbidden){
 $check(false===strpos($source,$forbidden),'read-only certification evaluator contains forbidden primitive: '.$forbidden);
}

echo "mad4b.feature007-workstream-certification-status.v1: PASS\n";

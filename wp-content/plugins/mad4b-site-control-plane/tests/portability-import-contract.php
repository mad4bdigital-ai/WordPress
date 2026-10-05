<?php
define('ABSPATH',__DIR__.'/');
$GLOBALS['opts']=array();$GLOBALS['env']='staging';$GLOBALS['uuid_counter']=0;
function add_action($h,$c,$p=10){}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',str_replace('.','_',strtolower(trim((string)$v))));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_get_environment_type(){return $GLOBALS['env'];}
function wp_generate_uuid4(){$GLOBALS['uuid_counter']++;return sprintf('00000000-0000-4000-8000-%012d',$GLOBALS['uuid_counter']);}
function get_option($n,$d=false){return array_key_exists($n,$GLOBALS['opts'])?$GLOBALS['opts'][$n]:$d;}
function update_option($n,$v,$a=false){$GLOBALS['opts'][$n]=$v;return true;}
function add_option($n,$v,$d='',$a=false){if(array_key_exists($n,$GLOBALS['opts']))return false;$GLOBALS['opts'][$n]=$v;return true;}
function delete_option($n){if(!array_key_exists($n,$GLOBALS['opts']))return false;unset($GLOBALS['opts'][$n]);return true;}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{private $c;private $d;public function __construct($c,$m='',$d=null){$this->c=$c;$this->d=$d;}public function get_error_code(){return $this->c;}public function get_error_data(){return $this->d;}}
final class MAD4B_SCP_Site_Profile{public static function site_uuid(){return 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';}}

require dirname(__DIR__).'/includes/class-mad4b-scp-decommission-portability.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-portability-import.php';

$fail=static function($m){fwrite(STDERR,"FAIL portability-import-contract: $m\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};

$source='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$target='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$bundle=MAD4B_SCP_Decommission_Portability::build_export_bundle(array(
 'scope'=>'site',
 'source_identity'=>array('source_commit_sha'=>str_repeat('a',40),'build_fingerprint'=>str_repeat('b',64),'site_uuid'=>$source),
 'snapshot'=>array(
  'schema_contracts'=>array('mad4b.artifact-registry.v1'),
  'jobs_events'=>array(array('job_id'=>'j1','event'=>'created')),
  'artifact_metadata'=>array(array('artifact_id'=>'a1','type'=>'context_pack')),
  'lineage'=>array(array('from'=>'a1','to'=>'a2')),
  'intent_registry'=>array(array('intent'=>'egypt tours')),
 ),
));
$check(is_array($bundle)&&1===preg_match('/^[a-f0-9]{64}$/',$bundle['bundle_sha256']),'export fixture bundle invalid');
$targetSpec=array('site_uuid'=>$target,'site_uuid_remap'=>$source,'collisions'=>array(),'missing_external_refs'=>array());

$plan=MAD4B_SCP_Portability_Import::plan(array(
 'bundle'=>$bundle,'target'=>$targetSpec,'rollback_snapshot_sha256'=>str_repeat('c',64),
));
$check(is_array($plan)&&'mad4b.portability-import-quarantine-plan.v1'===$plan['contract'],'import plan failed');
$check('quarantine_reconciliation_only'===$plan['import_mode'],'import is not quarantine-first');
$check(false===$plan['direct_publication_allowed']&&false===$plan['authority_material_allowed']&&false===$plan['credentials_allowed'],'import plan widened content/authority');
$check(1===preg_match('/^[a-f0-9]{64}$/',$plan['plan_sha256']),'import plan digest invalid');

$apply=MAD4B_SCP_Portability_Import::apply(array('plan'=>$plan,'bundle'=>$bundle,'target'=>$targetSpec));
$check(is_array($apply)&&'quarantined'===$apply['state'],'import quarantine apply failed');
$record=$apply['record'];
$check('QUARANTINED_RECONCILIATION_REQUIRED'===$record['status'],'import bypassed reconciliation');
$check(false===$record['raw_bundle_payload_persisted']&&false===$record['credentials_imported']&&false===$record['grants_imported']&&false===$record['production_authority_imported']&&false===$record['public_content_mutated'],'quarantine record widened state');
$check(!array_key_exists('payload',$record),'raw bundle payload leaked into quarantine record');

$again=MAD4B_SCP_Portability_Import::apply(array('plan'=>$plan,'bundle'=>$bundle,'target'=>$targetSpec));
$check(is_array($again)&&'already_quarantined'===$again['state']&&false===$again['mutation_performed'],'same bundle import not idempotent');
$status=MAD4B_SCP_Portability_Import::status();
$check(1===$status['count'],'quarantine status ledger count invalid');

$tampered=$plan;$tampered['target_site_uuid']='cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$bad=MAD4B_SCP_Portability_Import::apply(array('plan'=>$tampered,'bundle'=>$bundle,'target'=>$targetSpec));
$check(is_wp_error($bad)&&'mad4b_portability_import_plan_digest_invalid'===$bad->get_error_code(),'tampered plan was accepted');

$GLOBALS['env']='production';
$prod=MAD4B_SCP_Portability_Import::plan(array('bundle'=>$bundle,'target'=>$targetSpec,'rollback_snapshot_sha256'=>str_repeat('c',64)));
$check(is_wp_error($prod)&&'mad4b_portability_import_production_denied'===$prod->get_error_code(),'Production import quarantine was not denied');

$sourceCode=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-portability-import.php');
foreach(array('wp_insert_post(','wp_delete_post(','wp_remote_get(','wp_remote_post(','shell_exec(','proc_open(') as $forbidden){
 $check(false===strpos($sourceCode,$forbidden),'portability import contains direct content/provider primitive: '.$forbidden);
}
echo "mad4b.portability-import-quarantine.v1: PASS\n";

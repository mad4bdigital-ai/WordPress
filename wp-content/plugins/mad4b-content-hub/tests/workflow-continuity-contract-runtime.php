<?php
/** No external network or WordPress boot: bounded idempotency/identity contract. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=array()){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function current_user_can($cap){return true;}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}
class MAD4B_Hub_Control_Ingestion {
    public static function allowed(){return true;}
}
class MAD4B_SCP_Operational_Integrity {
    public static function capture(){return array('scope'=>array(
        'site_uuid'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa','brand_ref'=>'brand-01'));}
}
class MAD4B_Hub_Canonical_Store {
    public static function run_key($site,$brand,$key){return hash('sha256',"$site|$brand|$key");}
    public static function find_run($key) {
        return array('run_key'=>$key,'site_uuid'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'brand_id'=>'brand-01','state'=>'persisted',
            'record_key'=>'record-01','job_id'=>'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');
    }
    public static function read_record($key) {
        return array('record_key'=>$key,
            'site_uuid'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'brand_id'=>'brand-01','job_id'=>'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'state'=>'research_candidate','source_url'=>'https://example.test/',
            'source_sha256'=>str_repeat('a',64),'version_sha256'=>str_repeat('b',64),
            'provenance'=>array('source_sha256'=>str_repeat('a',64),
                'source_url'=>'https://example.test/',
                'job_id'=>'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                'research_record_uid'=>'real-research-ref'),
            'normalized'=>array('description'=>'First party source evidence (not QA evaluated)'));
    }
}
class MAD4B_SCP_Content_Jobs {
    public static $job=array('site_uuid'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'brand_id'=>'brand-01','job_id'=>'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        'state'=>'RUNNING','stage'=>'SCRAPING','job_revision'=>3);
    public static $transitions=0;
    public static function get_job($input){return array('job'=>self::$job);}
    public static function transition_job($input){
        if($input['expected_revision']!==self::$job['job_revision'])
            return new WP_Error('revision_conflict');
        self::$job['stage']=$input['stage'];self::$job['job_revision']++;
        self::$transitions++;
        return array('job'=>self::$job);
    }
}
class MAD4B_SCP_Artifacts {
    public static $items=array();
    public static $appends=0;
    public static function list_artifacts($input) {
        return array('items'=>self::$items);
    }
    public static function append_artifact($input){
        self::$appends++;
        $row=array('artifact_id'=>'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'status'=>'active','producer_ref'=>$input['producer_ref']);
        self::$items[]=$row;
        return array('artifact'=>$row);
    }
}
class FakeDB {
    public function prepare($sql,$value){return $sql;}
    public function get_var($sql){return '1';}
}
$GLOBALS['wpdb']=new FakeDB;
require dirname(__DIR__).'/includes/class-mad4b-hub-workflow-continuity.php';
function assert_same($expected,$actual,$name) {
    if($expected!==$actual)throw new RuntimeException("FAILED $name");
}
$input=array('operation_key'=>'fixed-key-for-contract-20261030');
$before=MAD4B_Hub_Workflow_Continuity::status($input);
assert_same(true,$before['needs_reconciliation'],'before_pending');
$first=MAD4B_Hub_Workflow_Continuity::resume($input);
if(is_wp_error($first))throw new RuntimeException($first->get_error_code());
assert_same(false,$first['reused_artifact'],'first_new');
assert_same('COMPETITOR_ANALYSIS',$first['job_stage'],'stage_advanced');
assert_same('mad4b/research-coverage-build',$first['next_ability'],'next_ability');
$repeat=MAD4B_Hub_Workflow_Continuity::resume($input);
if(is_wp_error($repeat))throw new RuntimeException($repeat->get_error_code());
assert_same(true,$repeat['reused_artifact'],'retry_reuses');
assert_same($first['artifact_id'],$repeat['artifact_id'],'same_artifact');
assert_same(1,MAD4B_SCP_Artifacts::$appends,'one_append');
assert_same(1,MAD4B_SCP_Content_Jobs::$transitions,'one_transition');
$after=MAD4B_Hub_Workflow_Continuity::status($input);
assert_same(false,$after['needs_reconciliation'],'after_recovered');
MAD4B_SCP_Content_Jobs::$job['state']='CANCELLED';
$cancel=MAD4B_Hub_Workflow_Continuity::resume($input);
assert_same('mad4b_hub_continuity_terminal_job',$cancel->get_error_code(),'terminal_denied');
echo "HUB_WORKFLOW_CONTINUITY_CONTRACT=PASS; append=1 transition=1 replay=same\n";

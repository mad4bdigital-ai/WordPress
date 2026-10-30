<?php
/** Source Sync Job unit contract — isolated read/queue/claim/finish tests. */
define('ABSPATH','/test/');
define('WP_CLI',true);
define('MAD4B_HUB_SOURCE_HOST_RUNNER',true);
$GLOBALS['opts']=[];
$GLOBALS['admin']=true;
$GLOBALS['writes']=0;
class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=[]) {$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
class MAD4B_Central_Company_REST_V1 {
    const COMPANIES=['tourism-almallahgroup'=>['host'=>'tourism.almallahgroup-mg.com']];
}
class MAD4B_Hub_Source_Import_V1 {const RECEIPT='receipt';}
class MAD4B_Hub_Auto_Distribute_V1 {
    public static function settings(){return [
        'source_mode'=>'import_to_hub','source_transport'=>'wp_cli_cross_ssh',
        'source_post_types'=>['tours-and-activities','properties','transportations'],
        'consumers'=>[], 'enabled'=>false
    ];}
}
class MAD4B_Hub_Source_Providers_V1 {
    const LEDGER='ledger';
    public static function authorize($company,$provider,$dataset,$transport,$types,$host) {
        return ($company==='tourism-almallahgroup' && $provider==='tourism_wp' &&
            $dataset==='tourism_main' && $transport==='wp_cli_cross_ssh' &&
            $host==='tourism.almallahgroup-mg.com')?true:new WP_Error('forbidden');
    }
}
$wpdb=new class {
    public function get_var($sql){
        if(strpos($sql,'GET_LOCK')!==false)return '1';
        if(strpos($sql,'RELEASE_LOCK')!==false)return '1';
        return null;
    }
};
function wp_get_environment_type(){return 'staging';}
function home_url($path='/'){return 'https://tours.dev.mad4b.com/';}
function wp_parse_url($url,$part){return parse_url($url,$part);}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function get_option($key,$default=false){return $GLOBALS['opts'][$key]??$default;}
function update_option($key,$val,$autoload=false){$GLOBALS['opts'][$key]=$val;$GLOBALS['writes']++;return true;}
function current_user_can($cap){return $GLOBALS['admin'] && $cap==='manage_options';}
function add_action(...$args){}
function wp_generate_uuid4(){static $n=0;$n++;return sprintf('12345678-1234-4234-a234-%012d',$n);}
function check($pass,$label){static $n=0;$n++;if(!$pass){fwrite(STDERR,"FAILED $n: $label\n");exit(1);}$GLOBALS['tests']=$n;}
require dirname(__DIR__).'/central/276-mad4b-hub-source-sync-jobs.php';
$K='MAD4B_Hub_Source_Sync_Jobs_V1';
$input=['company_id'=>'tourism-almallahgroup','provider_id'=>'tourism_wp',
    'dataset_id'=>'tourism_main','transport'=>'wp_cli_cross_ssh',
    'request_key'=>'tourism-source-test-01'];
$plan=$K::plan();
check($plan['ready'] && !$plan['queued_execution_automatic'],'Read-only plan is ready');
check($GLOBALS['writes']===0,'Plan does not write');
$GLOBALS['admin']=false;
$rejected=$K::request($input);
check(is_wp_error($rejected)&&$rejected->get_error_code()==='administrator_required','Unauthorized queue request denied');
$GLOBALS['admin']=true;
$wrong=$input;$wrong['company_id']='another_company';
check(is_wp_error($K::request($wrong)),'Wrong company denied');
$wrong=$input;$wrong['provider_id']='foreign_wp';
check(is_wp_error($K::request($wrong)),'Unknown provider denied');
$wrong=$input;$wrong['dataset_id']='other_set';
check(is_wp_error($K::request($wrong)),'Foreign dataset denied');
$wrong=$input;$wrong['transport']='wp_rest_application_password';
check(is_wp_error($K::request($wrong)),'Other transport denied');
$wrong=$input;$wrong['request_key']='bad';
check(is_wp_error($K::request($wrong)),'Noncompliant request key denied');
$first=$K::request($input);
check(is_array($first)&&$first['status']==='queued','Queue one source sync request');
check($K::request($input)['job_id']===$first['job_id'],'Idempotent request key');
$governed=$input;
$governed['_mad4b_approval_ticket_id']='12345678-1234-4234-a234-123456789abc';
$governed['_mad4b_context_receipt']=['contract'=>'mad4b.context-receipt.v1'];
$replayed=$K::request($governed);
check($replayed['job_id']===$first['job_id'],
    'Governed Write fields accepted without recreating job');
check(!array_key_exists('_mad4b_approval_ticket_id',$replayed) &&
    !array_key_exists('_mad4b_context_receipt',$replayed),
    'One-time authorization and context not persisted');
$badEnvelope=$governed;$badEnvelope['_mad4b_approval_ticket_id']='fake-ticket';
check(is_wp_error($K::request($badEnvelope)),
    'Forged ticket-shaped values rejected at the boundary');
$badEnvelope=$input;$badEnvelope['unexpected']='should-not-pass';
check(is_wp_error($K::request($badEnvelope)),
    'Unknown Write envelope keys rejected');
$badEnvelope=$input;$badEnvelope['_mad4b_context_receipt']=str_repeat('x',600);
check(is_wp_error($K::request($badEnvelope)),
    'Context receipt must be a structured object');

$second=$input;$second['request_key']='tourism-source-test-02';
check(is_wp_error($K::request($second)),'One active job per source');
$claimed=$K::claim();
check($claimed['status']==='running' && $claimed['job_id']===$first['job_id'],'VPS host claims exact job');
check(is_wp_error($K::claim()),'Claim cannot rerun one job');
$r=['source_company'=>'tourism-almallahgroup','source_provider'=>'tourism_wp',
    'source_dataset'=>'tourism_main','transport'=>'wp_cli_cross_ssh',
    'snapshot_sha256'=>str_repeat('f',64),'imported'=>827,'created'=>0,'updated'=>0];
$GLOBALS['opts'][MAD4B_Hub_Source_Import_V1::RECEIPT]=$r;
$GLOBALS['opts'][MAD4B_Hub_Source_Providers_V1::LEDGER]=[
    ['snapshot_sha256'=>str_repeat('f',64),'imported'=>827,'at'=>gmdate('c')]
];
$finished=$K::finish($first['job_id'],true);
check($finished['status']==='completed' && $finished['imported']===827,'Verified importer receipt completes job');
check($K::status(['job_id'=>$first['job_id']])['jobs'][0]['status']==='completed','Status readback');
check(is_wp_error($K::finish($first['job_id'],true)),'Cannot complete twice');
$next=$K::request($second);
check($next['status']==='queued','New request allowed after previous completion');
$claimed=$K::claim();
check($claimed['job_id']===$next['job_id'],'Second job claimed');
$GLOBALS['opts'][MAD4B_Hub_Source_Import_V1::RECEIPT]['snapshot_sha256']=str_repeat('a',64);
$bad=$K::finish($next['job_id'],true);
check($bad['status']==='failed' && $bad['error_code']==='receipt_mismatch','Mismatching receipt fails closed');
check(count($K::status()['jobs'])===2,'Both jobs retained in bounded status');
check($K::plan()['existing_active_jobs']===[],'Plan shows no active job');
echo "PASS ".$GLOBALS['tests']." source sync lifecycle and policy tests\n";

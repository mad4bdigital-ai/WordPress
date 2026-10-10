<?php
/** Synthetic DB fault-injection for ContentJob transaction failure and scope fences.
 * php tests/content-job-transaction-fault-runtime.php
 * Not an actual MySQL, real provider or Staging acceptance result.
 */
define('ABSPATH', '/'); define('ARRAY_A', 'ARRAY_A');
class WP_Error {
    private $code,$data;
    public function __construct($code,$message='',$data=null){$this->code=$code;$this->data=$data;}
    public function get_error_code(){return $this->code;}
    public function get_error_data(){return $this->data;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action(){}
function absint($n){return abs((int)$n);}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$s));}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function wp_strip_all_tags($s){return strip_tags((string)$s);}
function wp_json_encode($v,$opts=0){return json_encode($v,$opts);}
function get_current_blog_id(){return $GLOBALS['test_blog']??1;}
function get_current_network_id(){return 1;}
function get_current_user_id(){return $GLOBALS['test_actor']??7;}
function wp_generate_uuid4(){static $n=0;$n++;return sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d',$n);}
function assert_pass($p,$why){if(!$p){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}}
class MAD4B_SCP_Identifiers{public static function job_id($id){return preg_match('/^[a-f0-9-]{36}$/',(string)$id)?$id:'';}}
class MAD4B_SCP_Schema {
    public static function critical_ready(){return true;}
    public static function transactional_storage_status($keys=array(),$refresh=false){return array('ready'=>!empty($GLOBALS['transactional_ok'])||!array_key_exists('transactional_ok',$GLOBALS),'read_your_writes'=>true);}
    public static function tables(){return ['content_jobs'=>'wp_jobs','content_job_events'=>'wp_events'];}
}
class MAD4B_SCP_Deployment_Mode_Resolver {
    public static function resolve(){return $GLOBALS['test_resolution'];}
}
class MAD4B_SCP_Policy {
    public static function can_mutate(){return $GLOBALS['mutation_enabled']??true;}
}
class MAD4B_SCP_Audit {
    public static $committed=0,$rolled_back=0;
    public static function record($ability,$summary,$status='ok',$join=false){
        if(!empty($GLOBALS['test_revoke_at_audit']))$GLOBALS['mutation_enabled']=false;
        if(!empty($GLOBALS['test_revise_at_audit']))$GLOBALS['test_resolution']['dependency_revision']['brand_profile']++;
        return true;
    }
    public static function transaction_rolled_back(){self::$rolled_back++;}
    public static function transaction_committed(){self::$committed++;}
}
class Mock_Fault_DB {
    public $last_error='',$inserts=[],$queries=[],$fail_begin=false,$fail_commit=false,$fail_rollback=false;
    public function query($sql){
        $this->queries[]=$sql;
        if($sql==='START TRANSACTION' && $this->fail_begin)return false;
        if($sql==='COMMIT' && $this->fail_commit)return false;
        if($sql==='ROLLBACK' && $this->fail_rollback)return false;
        return 0;
    }
    public function insert($table,$data){$this->inserts[]=[$table,$data];return 1;}
    public function prepare($sql,...$args){return $sql;}
    public function get_var($sql){return '';}
    public function get_row($sql,$mode){
        foreach($this->inserts as [$table,$row])if($table==='wp_jobs')return $row;
        return null;
    }
}
$GLOBALS['test_resolution']=[
    'status'=>'RESOLVED_FOR_REVIEW_ONLY',
    'scope'=>[
        'site_uuid'=>'11111111-2222-4333-8444-555555555555',
        'tenant_ref'=>'wp-site:11111111-2222-4333-8444-555555555555',
        'brand_ref'=>str_repeat('a',32),'blog_id'=>1,'network_id'=>1,
        'environment'=>'staging','deployment_mode'=>'wordpress_dedicated'],
    'dependency_revision'=>['site_profile'=>2,'brand_profile'=>3]
];
$input=['brand_id'=>str_repeat('a',32),'subject'=>'Scope bound draft','language'=>'en',
    'country'=>'eg','content_type'=>'article','reason'=>'Synthetic failure check'];
require_once dirname(__DIR__).'/includes/class-mad4b-scp-content-jobs.php';
function fresh_db(){$GLOBALS['mutation_enabled']=true;$GLOBALS['test_revoke_at_audit']=false;
    $GLOBALS['test_revise_at_audit']=false;
    $GLOBALS['test_resolution']['dependency_revision']['brand_profile']=3;
    return new Mock_Fault_DB();
}
// MyISAM / read-your-writes unsafe topology refuses the operation before SQL.
$wpdb=fresh_db();$GLOBALS['transactional_ok']=false;
$r=MAD4B_SCP_Content_Jobs::create_job($input);
assert_pass(is_wp_error($r)&&$r->get_error_code()==='mad4b_content_job_transactional_storage_blocked','nontransactional engine denied');
assert_pass(count($wpdb->queries)===0&&count($wpdb->inserts)===0,'nontransactional storage starts no write');
$GLOBALS['transactional_ok']=true;

$wpdb=fresh_db();$wpdb->fail_begin=true;
$r=MAD4B_SCP_Content_Jobs::create_job($input);
assert_pass(is_wp_error($r)&&$r->get_error_code()==='mad4b_content_job_transaction_unavailable','missing BEGIN denied');
assert_pass(count($wpdb->inserts)===0,'no side effects without BEGIN');

$wpdb=fresh_db();$wpdb->fail_commit=true;
$r=MAD4B_SCP_Content_Jobs::create_job($input);
assert_pass(is_wp_error($r)&&$r->get_error_code()==='mad4b_content_job_commit_uncertain','missing COMMIT ack unknown');
$data=$r->get_error_data();
assert_pass(is_array($data)&&!$data['blind_retry_allowed']&&$data['reconciliation_required'],'blind retry prohibited');
assert_pass($data['job_id']===$wpdb->inserts[0][1]['job_id'],'reconciliation identifies original job');
assert_pass(!in_array('ROLLBACK',$wpdb->queries,true),'unknown commit cannot claim successful rollback');

$wpdb=fresh_db();$GLOBALS['test_revoke_at_audit']=true;
$r=MAD4B_SCP_Content_Jobs::create_job($input);
assert_pass(is_wp_error($r)&&$r->get_error_code()==='mad4b_content_job_create_failed','revocation rolls back');
assert_pass(in_array('ROLLBACK',$wpdb->queries,true)&&!in_array('COMMIT',$wpdb->queries,true),'revoked policy prevents commit');
$GLOBALS['test_revoke_at_audit']=false;

// ROLLBACK acknowledgement loss is also outcome-uncertain, even after deny.
$wpdb=fresh_db();$wpdb->fail_rollback=true;$GLOBALS['test_revoke_at_audit']=true;
$r=MAD4B_SCP_Content_Jobs::create_job($input);
assert_pass(is_wp_error($r)&&$r->get_error_code()==='mad4b_content_job_rollback_uncertain','unknown rollback quarantined');
assert_pass($r->get_error_data()['reconciliation_required']===true&&$r->get_error_data()['blind_retry_allowed']===false,'unknown rollback forbids retry');
$GLOBALS['test_revoke_at_audit']=false;

$wpdb=fresh_db();$GLOBALS['test_revise_at_audit']=true;
$r=MAD4B_SCP_Content_Jobs::create_job($input);
assert_pass(is_wp_error($r)&&in_array('ROLLBACK',$wpdb->queries,true),'brand revision drift rolls back before commit');
$GLOBALS['test_revise_at_audit']=false;

$wpdb=fresh_db();
$r=MAD4B_SCP_Content_Jobs::create_job($input);
assert_pass(!is_wp_error($r)&&$r['job']['tenant_id']===$GLOBALS['test_resolution']['scope']['tenant_ref'],'healthy job remains tenant-bound');
assert_pass(in_array('COMMIT',$wpdb->queries,true),'healthy job commits');
echo "PASS: synthetic ContentJob BEGIN/COMMIT/revocation/brand-revision fault matrix\n";

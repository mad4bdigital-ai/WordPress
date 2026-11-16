<?php
/**
 * Review-only source fixture for original MAD4B authority-inspect and
 * Firecrawl crawl-poll Cron owners. No WordPress, DB or external network.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['cron_registered']=array();
$GLOBALS['cron_events']=array();
$GLOBALS['cron_single_events']=array();
$GLOBALS['cron_options']=array();
$GLOBALS['cron_mutations']=0;
$GLOBALS['cron_actions']=0;
function add_action($name,$handler,$priority=10,$args=1) {
    $GLOBALS['cron_registered'][$name]= $handler;
}
function add_filter($name,$handler) { return true; }
function wp_next_scheduled($hook) {
    foreach($GLOBALS['cron_events'] as $event)
        if($event['hook']===$hook)return true;
    return false;
}
function wp_schedule_event($when,$recurrence,$hook) {
    $GLOBALS['cron_events'][]=array('hook'=>$hook,'recurrence'=>$recurrence);
    return true;
}
function wp_schedule_single_event($when,$hook,$args=array()) {
    $GLOBALS['cron_single_events'][]=$hook;
    return true;
}
function get_option($key,$default=array()) {
    return array_key_exists($key,$GLOBALS['cron_options'])
        ? $GLOBALS['cron_options'][$key] : $default;
}
function update_option($key,$value,$autoload=false) {
    ++$GLOBALS['cron_mutations'];
    $GLOBALS['cron_options'][$key]=$value;
    return true;
}
function do_action($event,$payload=array()) { ++$GLOBALS['cron_actions']; }
function wp_parse_url($url,$component=null) { return parse_url($url,$component); }
function home_url() { return 'https://example.invalid/'; }
class WP_Error {
    private $error;
    public function __construct($code,$message='') {$this->error=$code;}
    public function get_error_code() {return $this->error;}
}
function is_wp_error($value) {return $value instanceof WP_Error;}
function assert_native_cron($condition,$description) {
    if(!$condition)throw new RuntimeException('FAIL NATIVE CRON: '.$description);
}
class MAD4B_SCP_Site_Profile {
    public static $environment='production';
    public static $status=array();
    public static function current_environment(){return self::$environment;}
    public static function configured(){return false;}
    public static function status(){return self::$status;}
}
class MAD4B_SCP_Firecrawl_Provider {
    public static $calls=array();
    public static function owned_crawl_status($id,$internal=false){
        self::$calls[]=array($id,$internal);
        return array('state'=>'completed');
    }
}
require dirname(__DIR__).
    '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-authority-auto-reconciliation.php';
require dirname(__DIR__).
    '/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-firecrawl-crawl-integration-v1.php';

assert_native_cron(isset($GLOBALS['cron_registered']['mad4b_authority_auto_reconciliation_inspect']),
    'Original authority inspection callback absent');
MAD4B_SCP_Authority_Auto_Reconciliation::schedule();
assert_native_cron(!$GLOBALS['cron_events'],
    'Production must not schedule privileged authority inspection');
MAD4B_SCP_Site_Profile::$environment='staging';
MAD4B_SCP_Authority_Auto_Reconciliation::schedule();
assert_native_cron(count($GLOBALS['cron_events'])===1
    && $GLOBALS['cron_events'][0]['hook']==='mad4b_authority_auto_reconciliation_inspect'
    && $GLOBALS['cron_events'][0]['recurrence']==='hourly',
    'Staging authority check must use its original hourly hook');
MAD4B_SCP_Authority_Auto_Reconciliation::scheduled_inspect();
assert_native_cron($GLOBALS['cron_mutations']===0 && $GLOBALS['cron_actions']===0,
    'Incomplete authority source cannot publish or grant via Cron');

MAD4B_SCP_Firecrawl_Crawl_Integration_V1::boot();
assert_native_cron(isset($GLOBALS['cron_registered']['mad4b_firecrawl_crawl_poll_v1']),
    'Original Firecrawl crawl owner missing');
MAD4B_SCP_Firecrawl_Crawl_Integration_V1::ensure_schedule();
assert_native_cron(count($GLOBALS['cron_events'])===1,
    'Non-enrolled crawl target was scheduled');
MAD4B_SCP_Site_Profile::$status=array(
    'configured'=>true, 'origin_match'=>true,
    'deployment_binding_match'=>true,
    'environment_match'=>true,'write_enabled'=>false,
);
MAD4B_SCP_Firecrawl_Crawl_Integration_V1::ensure_schedule();
assert_native_cron(count($GLOBALS['cron_events'])===1,
    'Crawl scheduled with write-disabled Site Profile');
MAD4B_SCP_Site_Profile::$status['write_enabled']=true;
MAD4B_SCP_Firecrawl_Crawl_Integration_V1::ensure_schedule();
assert_native_cron(count($GLOBALS['cron_events'])===2
    && $GLOBALS['cron_events'][1]['hook']==='mad4b_firecrawl_crawl_poll_v1'
    && $GLOBALS['cron_events'][1]['recurrence']==='mad4b_firecrawl_five_minutes_v1',
    'Original bounded crawl hook and schedule must be preserved');

// Only explicitly owner-started, review-only, same-origin crawls can be read.
$ids=array(
    str_repeat('a',16),str_repeat('b',16),
    str_repeat('c',16),str_repeat('d',16),
);
$runs=array();
foreach($ids as $id)
    $runs[$id]=array('review_only'=>true,'source_url'=>home_url().'existing-page',
        'state'=>'pending','detached_test'=>false);
$runs['not_valid_id']=array('review_only'=>true,'source_url'=>home_url());
$runs[str_repeat('e',16)]=array('review_only'=>false,'source_url'=>home_url());
$runs[str_repeat('f',16)]=array('review_only'=>true,'source_url'=>'https://foreign.invalid/');
$GLOBALS['cron_options']['mad4b_firecrawl_owned_crawl_runs_v1']=$runs;
MAD4B_SCP_Firecrawl_Crawl_Integration_V1::poll();
assert_native_cron(count(MAD4B_SCP_Firecrawl_Provider::$calls)===3,
    'Crawl poll must cap provider status reads to three');
foreach(MAD4B_SCP_Firecrawl_Provider::$calls as $row)
    assert_native_cron($row[1]===true && in_array($row[0],array_slice($ids,0,3),true),
        'Crawl polling touched a foreign, unreviewed or detached run');
assert_native_cron($GLOBALS['cron_mutations']===0 && $GLOBALS['cron_actions']===0,
    'Cron test unexpectedly mutated a grant/content or published');
echo "MAD4B Native Cron ownership: PASS authority inspect only / bounded Firecrawl read\n";// Audited positive exit marker; all denial checks must complete first.
echo "NATIVE_CRON_OWNERSHIP: PASS\n";

<?php
/**
 * Read-only company-scoped provider registry regression.
 * No live data, no network, no WordPress instance or credentials.
 */
define('ABSPATH','/test/');
$GLOBALS['options']=[];
$GLOBALS['option_writes']=0;
class WP_Error {
    private $code;
    public function __construct($code,$message='') {$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
class MAD4B_Central_Company_REST_V1 {
    const COMPANIES=[
        'tourism-almallahgroup'=>['host'=>'tourism.almallahgroup-mg.com'],
        'all-royal-egypt'=>['host'=>'www.allroyalegypt.com'],
    ];
}
function add_action($name,$callback,$priority=10) {}
function wp_get_environment_type(){return 'staging';}
function home_url($path='/'){return 'https://tours.dev.mad4b.com/';}
function wp_parse_url($url,$part){return parse_url($url,$part);}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;$GLOBALS['option_writes']++;return true;}
function is_wp_error($value){return $value instanceof WP_Error;}
function assert_case($ok,$reason) {
    static $n=0;$n++;
    if(!$ok){fwrite(STDERR,"FAIL $n: $reason\n");exit(1);}
    $GLOBALS['tests']=$n;
}
require dirname(__DIR__).'/central/275-mad4b-hub-source-provider-registry.php';
$R='MAD4B_Hub_Source_Providers_V1';
$R::defaults();
$info=$R::inspect();
assert_case($info['contract']==='mad4b.hub.source-providers.v1','Contract name');
assert_case(count($info['providers'])===1,'Registered Tourism source');
assert_case($info['providers'][0]['auto_fusion_allowed']===false,'No auto fusion');
assert_case($R::authorize('tourism-almallahgroup','tourism_wp','tourism_main',
    'wp_cli_cross_ssh',['tours-and-activities','properties','transportations'],
    'tourism.almallahgroup-mg.com')===true,'Authorized scoped import');
assert_case(is_wp_error($R::authorize('all-royal-egypt','tourism_wp','tourism_main',
    'wp_cli_cross_ssh',['properties'],'tourism.almallahgroup-mg.com')),'Cross-company denied');
assert_case(is_wp_error($R::authorize('tourism-almallahgroup','tourism_wp','wrong_dataset',
    'wp_cli_cross_ssh',['properties'],'tourism.almallahgroup-mg.com')),'Dataset denied');
assert_case(is_wp_error($R::authorize('tourism-almallahgroup','tourism_wp','tourism_main',
    'wp_cli_cross_ssh',['orders'],'tourism.almallahgroup-mg.com')),'Unknown type denied');
assert_case(is_wp_error($R::authorize('tourism-almallahgroup','tourism_wp','tourism_main',
    'wp_cli_cross_ssh',['properties'],'attacker.invalid')),'Host mismatch denied');
assert_case(!$R::register('tourism_wp',[
    'company_id'=>'tourism-almallahgroup','dataset_id'=>'tourism_main',
    'canonical_host'=>'tourism.almallahgroup-mg.com',
    'transports'=>['wp_cli_cross_ssh'],'source_types'=>['properties'],
]),'No silent provider overwrite');
assert_case($R::register('royal_source',[
    'company_id'=>'all-royal-egypt','dataset_id'=>'royal_main',
    'canonical_host'=>'www.allroyalegypt.com',
    'transports'=>['wp_rest_application_password'],
    'source_types'=>['properties','custom-cpt'],
]),'Registered custom provider');
assert_case(count($R::inspect()['providers'])===2,'Discover custom provider');
assert_case($R::authorize('all-royal-egypt','royal_source','royal_main',
    'wp_rest_application_password',['custom-cpt'],'www.allroyalegypt.com')===true,
    'Custom source type scoped to registered provider');
assert_case(!$R::register('bad_source',[
    'company_id'=>'unknown-company','dataset_id'=>'unknown_main',
    'canonical_host'=>'random.invalid',
    'transports'=>['wp_cli_cross_ssh'],'source_types'=>['properties'],
]),'Company Controls registry is authoritative');
assert_case($GLOBALS['option_writes']===0,'Discovery and authorization do not mutate state');
$receipt=['snapshot_sha256'=>str_repeat('a',64),'imported'=>827,
    'created'=>0,'updated'=>0];
assert_case($R::record_import($receipt,'wp_cli_cross_ssh'),'Committed import receipt recorded');
assert_case(count(get_option($R::LEDGER,[]))===1,'Ledger persisted');
$bad=['snapshot_sha256'=>'broken','imported'=>827];
assert_case(!$R::record_import($bad,'wp_cli_cross_ssh'),'Invalid receipt rejected');
assert_case(count(get_option($R::LEDGER,[]))===1,'Failed receipt is not written');
echo "PASS ".$GLOBALS['tests']." provider-registry and company-policy tests\n";

<?php
/**
 * Self-contained integration contract for Staging reference-only Auto Distribute.
 * Run: php ops/central-company-rest/tests/test-auto-distribute.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$GLOBALS['hub_cron']=false;
$GLOBALS['hub_posts']=[];
$GLOBALS['hub_meta']=[];
$GLOBALS['hub_post_count']=0;
$GLOBALS['hub_delivery_count']=0;
function post_type_exists($name) { return $name==='mad4b_hub_nile'; }
function get_posts($args) { $GLOBALS['hub_post_count']++; return array_keys($GLOBALS['hub_posts']); }
function get_post_meta($id,$key,$single=true) { return $GLOBALS['hub_meta'][$id][$key]??''; }
function wp_json_encode($value,$flags=0) { return json_encode($value,$flags); }
function sanitize_text_field($s) { return strip_tags((string)$s); }
function sanitize_textarea_field($s) { return strip_tags((string)$s); }
function mb_substr($s,$start,$length=null) { return substr($s,$start,$length); }
function wp_next_scheduled($hook) { return $GLOBALS['hub_cron']; }
function wp_schedule_event($when,$recurrence,$hook) { $GLOBALS['hub_cron']=$when; return true; }
function wp_clear_scheduled_hook($hook) { $GLOBALS['hub_cron']=false; return true; }
function add_filter() {}
function wp_safe_remote_post($url,$args) {
    if ($url!=='https://www.allroyalegypt.com/wp-json/mad4b-company/v1/hub-reference-inbox' ||
        empty($args['headers']['Authorization']) || $args['redirection']!==0 ||
        $args['sslverify']!==true) return ['status'=>403,'body'=>'{}'];
    $GLOBALS['hub_delivery_count']++;
    $former=$GLOBALS['site_url'];$oldUser=$GLOBALS['user_id'];
    $GLOBALS['site_url']='https://www.allroyalegypt.com';$GLOBALS['user_id']=9;
    $GLOBALS['opts']['mad4b_simple_service_user_id_v1']=9;
    MAD4B_Company_Simple_Identity_V1::mark_app_password_auth((object)['ID'=>9]);
    $request=new HubBodyRequest('',[],'POST','/mad4b-company/v1/hub-reference-inbox',$args['body']);
    $result=MAD4B_Company_Hub_Inbox_V1::accept($request);
    $GLOBALS['site_url']=$former;$GLOBALS['user_id']=$oldUser;
    return is_wp_error($result) ? ['status'=>403,'body'=>'{}'] :
        ['status'=>$result->status,'body'=>json_encode($result->data)];
}
class HubBodyRequest extends WP_REST_Request {
    private $raw;
    public function __construct($company, $params, $method, $route, $raw) {
        parent::__construct($company,$params,$method,$route);$this->raw=$raw;
    }
    public function get_body() { return $this->raw; }
    public function get_param($name) { return $this->params[$name]??null; }
}
require __DIR__.'/test-simple-connections.php';
$GLOBALS['site_url']='https://tours.dev.mad4b.com';
$GLOBALS['user_id']=1;
require dirname(__DIR__).'/central/250-mad4b-hub-auto-distribute.php';
require dirname(__DIR__).'/remote/250-mad4b-company-private-hub-inbox.php';
check(MAD4B_Hub_Auto_Distribute_V1::enabled(),'Central hub only');
$bad=MAD4B_Hub_Auto_Distribute_V1::normalize([
    'enabled'=>true,'auto_include_new'=>true,'consumers'=>['tourism-almallahgroup'],
    'kinds'=>['nile_tour'],'languages'=>['en'],'selected_source_ids'=>[]
]);
check(is_wp_error($bad),'Cannot send back to source as consumer');
$bad=MAD4B_Hub_Auto_Distribute_V1::normalize([
    'enabled'=>true,'auto_include_new'=>true,'consumers'=>['all-royal-egypt'],
    'kinds'=>['historical_rate'],'languages'=>['en'],'selected_source_ids'=>[]
]);
check(is_wp_error($bad),'Historical rates excluded');
$bad=MAD4B_Hub_Auto_Distribute_V1::normalize([
    'enabled'=>true,'auto_include_new'=>false,'consumers'=>['all-royal-egypt'],
    'kinds'=>['nile_tour'],'languages'=>['en'],'selected_source_ids'=>[]
]);
check(is_wp_error($bad),'Reject empty enabled policy');
$all = MAD4B_Hub_Auto_Distribute_V1::normalize([
    'enabled'=>true,'auto_include_new'=>true,'consumers'=>['all-royal-egypt'],
    'kinds'=>['nile_tour','nile_category'],'languages'=>['en','ar'],'selected_source_ids'=>[]
]);
check(is_array($all) && $all['enabled'],'Valid policy normalized');
$GLOBALS['opts'][MAD4B_Hub_Auto_Distribute_V1::OPTIONS]=$all;

$source=[
    ['id'=>7001,'kind'=>'nile_tour','source_id'=>24900,'locale'=>'en','name'=>'Nile One','summary'=>'A safe preview'],
    ['id'=>7002,'kind'=>'nile_tour','source_id'=>41909,'locale'=>'ar','name'=>'Nile Two','summary'=>'Tour summary'],
    ['id'=>7003,'kind'=>'nile_category','source_id'=>133,'locale'=>'en','name'=>'Nile cruises','summary'=>''],
    ['id'=>7004,'kind'=>'historical_rate','source_id'=>500,'locale'=>'en','name'=>'Rate','summary'=>'123 USD'],
];
foreach($source as $s) {
    $payload=['source_company'=>'tourism-almallahgroup'];
    if($s['kind']==='nile_tour') {
        $payload['source_tour_id']=$s['source_id'];
        $payload['title']=$s['name'];
        $payload['summary']=$s['summary'];
        $payload['translation_source']=['language_code'=>$s['locale']];
    } elseif($s['kind']==='nile_category') {
        $payload['source_term_id']=$s['source_id'];
        $payload['name']=$s['name'];
        $payload['source_language_code']=$s['locale'];
    }
    $json=json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $GLOBALS['hub_posts'][$s['id']]=1;
    $GLOBALS['hub_meta'][$s['id']]=[
        '_mad4b_hub_source_kind'=>$s['kind'],
        '_mad4b_hub_source_company'=>'tourism-almallahgroup',
        '_mad4b_hub_content_owned_by'=>'tourism-almallahgroup',
        '_mad4b_hub_publication_state'=>'published_content_hub',
        '_mad4b_hub_source_payload_json'=>$json,
        '_mad4b_hub_source_sha256'=>hash('sha256',$json),
        '_mad4b_hub_source_id'=>$s['source_id']
    ];
}
$empty=MAD4B_Hub_Auto_Distribute_V1::distribute();
check(is_array($empty) && $empty['status']==='failed' &&
    $GLOBALS['hub_delivery_count']===0,'Unconnected consumer fails closed');
$creds=['username'=>'mad4b-service','application_password'=>'abcd efgh ijkl mnop qrst uvwx'];
$connect=MAD4B_Central_Simple_Connections_V1::connect(new WP_REST_Request(
    'tourism-almallahgroup',$creds
));
check($connect instanceof WP_REST_Response,'Source connection valid');
class MAD4B_Test_Company_Registry_Backfill {}
// Add recipient to existing test registry only in this isolated CLI process.
// Create a second auth envelope using encrypted connection via a dedicated
// fixture class; source and consumer registry names match production constants.
$registry=MAD4B_Central_Company_REST_V1::COMPANIES;
check(!isset($registry['all-royal-egypt']),'Test harness intentionally has only one original company');
echo "PREPARED reference-only Auto Distribute boundary cases\n";

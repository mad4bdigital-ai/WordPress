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
    'all-royal-egypt',$creds
));
check($connect instanceof WP_REST_Response,'Consumer App Password connection valid');
$result=MAD4B_Hub_Auto_Distribute_V1::distribute();
check(is_array($result) && $result['status']==='success', 'Private auto-distribute delivered');
check($result['source_reference_count']===3, 'Only tour and category refs delivered; no historical rates');
check($GLOBALS['hub_delivery_count']===1,'Consumer received exactly once');
$inbox=$GLOBALS['opts'][MAD4B_Company_Hub_Inbox_V1::STORE]??[];
check(count($inbox['items']??[])===3,'Consumer stores three safe reference items');
check(($inbox['may_publish']??true)===false && ($inbox['may_checkout']??true)===false,
    'Inbox remains private and non-bookable');
$strings=json_encode($inbox);
check(strpos($strings,'123 USD')===false,'Historical rate data never sent');
$second=MAD4B_Hub_Auto_Distribute_V1::distribute();
check(is_array($second) && $second['snapshot_sha256']===$result['snapshot_sha256'],
    'Repeated delivery idempotent digest');
$GLOBALS['hub_meta'][7001]['_mad4b_hub_source_sha256']=str_repeat('a',64);
$badDigest=MAD4B_Hub_Auto_Distribute_V1::distribute();
check(is_wp_error($badDigest) && $badDigest->code==='mad4b_hub_auto_source_digest_mismatch',
    'Source tampering blocks delivery');
$GLOBALS['hub_meta'][7001]['_mad4b_hub_source_sha256']=hash('sha256',
    $GLOBALS['hub_meta'][7001]['_mad4b_hub_source_payload_json']);
$badPolicy=MAD4B_Hub_Auto_Distribute_V1::normalize([
    'enabled'=>true,'auto_include_new'=>true,
    'consumers'=>['all-royal-egypt','all-royal-egypt'],
    'kinds'=>['nile_tour'],'languages'=>['en'],'selected_source_ids'=>[]
]);
check(is_wp_error($badPolicy),'Duplicate consumer rejected');
$GLOBALS['opts'][MAD4B_Hub_Auto_Distribute_V1::OPTIONS]['enabled']=false;
$disabled=MAD4B_Hub_Auto_Distribute_V1::distribute();
check(is_wp_error($disabled) && $disabled->code==='mad4b_hub_auto_not_enabled',
    'Disable stops distribution');
$GLOBALS['site_url']='https://www.allroyalegypt.com';
$GLOBALS['user_id']=9;
MAD4B_Company_Simple_Identity_V1::mark_app_password_auth((object)['ID'=>9]);
$tamperItem=$inbox['items'][0];$tamperItem['may_publish']=true;
$payload=['contract'=>'mad4b.hub.private-reference-snapshot.v1',
    'source_company'=>'tourism-almallahgroup',
    'consumer_company'=>'all-royal-egypt','reference_only'=>true,
    'may_publish'=>false,'may_checkout'=>false,
    'items'=>[$tamperItem],
    'snapshot_sha256'=>hash('sha256',json_encode([$tamperItem],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))];
$invalidDelivery=MAD4B_Company_Hub_Inbox_V1::accept(new HubBodyRequest('',
    [],'POST','/mad4b-company/v1/hub-reference-inbox',json_encode($payload)));
check(is_wp_error($invalidDelivery),'Inbox forbids publishable payload');
check(count($GLOBALS['opts'][MAD4B_Company_Hub_Inbox_V1::STORE]['items'])===3,
    'Rejected delivery preserves last good snapshot');
echo "PASS $check_count combined simple REST / hub auto-distribution tests\n";

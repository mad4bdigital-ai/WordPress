<?php
/**
 * Offline source -> Content Hub contract tests. No WordPress instance,
 * credentials, network, customer records or Production mutations.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH','/test/');
$GLOBALS['options']=[];
$GLOBALS['hub_posts']=[];
$GLOBALS['verified_nile']=[];
$GLOBALS['hub_meta']=[];
$GLOBALS['next_post_id']=9000;
$GLOBALS['feed_rows']=[];
$GLOBALS['source_rows']=[];
$GLOBALS['fail_feed']=false;
$GLOBALS['registered_types']=[];
$GLOBALS['changes']=0;
class WP_Error {
    public $code; public $data;
    public function __construct($code,$message,$data=[]) {$this->code=$code;$this->data=$data;}
}
class WP_REST_Server { const READABLE='GET'; const CREATABLE='POST'; }
class WP_REST_Response {
    public $data;public $status;
    public function __construct($data,$status=200) {$this->data=$data;$this->status=$status;}
    public function header($key,$value) {}
}
class WP_REST_Request {
    public $params;
    public function __construct($params=[]) {$this->params=$params;}
    public function get_param($key) {return $this->params[$key]??null;}
    public function get_json_params() {return $this->params;}
}
function add_action() {}
function add_filter() {}
function current_user_can($cap) { return $cap==='manage_options'; }
function wp_next_scheduled($hook) { return false; }
function wp_schedule_event() { return true; }
function wp_clear_scheduled_hook() { return true; }
function is_wp_error($x) {return $x instanceof WP_Error;}
function get_option($key,$default=false) {return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false) {$GLOBALS['options'][$key]=$value;return true;}
function register_post_type($slug,$args) {$GLOBALS['registered_types'][$slug]=$args;return true;}
function current_user_can($cap) {return $cap==='manage_options';}
function post_type_exists($slug) {return $slug==='mad4b_hub_nile' || isset($GLOBALS['registered_types'][$slug]) ||
    in_array($slug,['tours-and-activities','properties','transportations'],true);}
function get_posts($query) {
    if($query['post_type']==='mad4b_hub_nile') return array_keys($GLOBALS['verified_nile']);
    return array_keys($GLOBALS['hub_posts']);
}
function get_post($id) {return $GLOBALS['hub_posts'][$id]??$GLOBALS['source_rows'][$id]??null;}
function get_post_meta($id,$key,$single=true) {return $GLOBALS['hub_meta'][$id][$key]??'';}
function update_post_meta($id,$key,$value) {$GLOBALS['hub_meta'][$id][$key]=$value;return true;}
function wp_insert_post($data,$wp_error=false) {
    $id=++$GLOBALS['next_post_id'];
    $GLOBALS['hub_posts'][$id]=(object)array_merge($data,['ID'=>$id]);
    $GLOBALS['changes']++;return $id;
}
function wp_update_post($data,$wp_error=false) {
    $id=(int)$data['ID'];if(!isset($GLOBALS['hub_posts'][$id]))return new WP_Error('not_found','No post');
    $GLOBALS['hub_posts'][$id]=(object)array_merge((array)$GLOBALS['hub_posts'][$id],$data);
    $GLOBALS['changes']++;return $id;
}
function sanitize_text_field($s) {return trim(strip_tags($s));}
function sanitize_textarea_field($s) {return trim(strip_tags($s));}
function wp_strip_all_tags($s) {return strip_tags($s);}
function wp_json_encode($v,$flags=0) {return json_encode($v,$flags);}
function apply_filters($filter,$default,$id) {return ['language_code'=>$GLOBALS['source_rows'][$id]->language];}
function mb_substr_safe($s,$start,$len) {return substr($s,$start,$len);}
class WP_Query {
    public $posts=[];public $found_posts=0;
    public function __construct($args) {
        $rows=array_filter($GLOBALS['source_rows'],function($p) use($args) {
            return $p->post_type===$args['post_type'] && $p->post_status==='publish';
        });
        $this->found_posts=count($rows);
        $this->posts=array_slice(array_keys($rows),($args['paged']-1)*20,20);
    }
}
class MAD4B_Central_Simple_Connections_V1 {
    public static function enabled() {return true;}
    public static function deliver_references($consumer,array $snapshot) {
        $GLOBALS['last_distributed_snapshot']=$snapshot;
        if($consumer!=='all-royal-egypt' || ($snapshot['may_publish']??true)!==false ||
            ($snapshot['may_checkout']??true)!==false)return new WP_Error('invalid_delivery','Denied');
        return ['stored_items'=>count($snapshot['items'])];
    }
    public static function fetch_published_page($company,$type,$page) {
        if($GLOBALS['fail_feed'])return new WP_Error('unavailable','Source offline');
        $rows=$GLOBALS['feed_rows'][$type]??[];
        $total=count($rows);
        return [
            'contract'=>'mad4b.company.published-content-page.v1',
            'company_id'=>$company,'source_post_type'=>$type,
            'page'=>$page,'per_page'=>20,'total'=>$total,
            'items'=>array_slice($rows,($page-1)*20,20),
            'reference_only'=>true,'may_publish'=>false,'may_checkout'=>false,
        ];
    }
}
class MAD4B_Central_Company_REST_V1 {
    const COMPANIES=['tourism-almallahgroup'=>['name'=>'Tourism'],'all-royal-egypt'=>['name'=>'ARE']];
}
class MAD4B_Company_Simple_Identity_V1 {
    public static function identity_for_host() {return ['company_id'=>'tourism-almallahgroup',
        'canonical_host'=>'tourism.almallahgroup-mg.com'];}
    public static function authorized_for($request,$method,$route) {return true;}
}
$tests=0;
function check($condition,$what) {
    global $tests;$tests++;
    if(!$condition){fwrite(STDERR,"FAIL: $what\n");exit(1);}
}
require dirname(__DIR__).'/remote/260-mad4b-company-simple-content-feed.php';
require dirname(__DIR__).'/central/260-mad4b-hub-source-import.php';
require dirname(__DIR__).'/central/250-mad4b-hub-auto-distribute.php';
MAD4B_Hub_Source_Import_V1::register();
check(post_type_exists('mad4b_hub_content'),'Hub private type registered');
check($GLOBALS['registered_types']['mad4b_hub_content']['public']===false,'Hub not public');
check($GLOBALS['registered_types']['mad4b_hub_content']['show_in_rest']===false,'No public REST CPT');
check(MAD4B_Hub_Auto_Distribute_V1::settings()['source_mode']==='import_to_hub','Import mode default');
check(MAD4B_Hub_Auto_Distribute_V1::settings()['enabled']===false,'Automation default disabled');

function feed($id,$type,$language,$title) {
    $kind=MAD4B_Hub_Source_Import_V1::TYPES[$type];
    return [
        'source_company'=>'tourism-almallahgroup','source_post_type'=>$type,
        'kind'=>$kind,'source_id'=>$id,'language'=>$language,'title'=>$title,
        'summary'=>'Short summary','body'=>'Full text of '.$title,
        'modified_gmt'=>'2026-10-30 09:00:00',
        'reference_only'=>true,'may_publish'=>false,'may_checkout'=>false,
    ];
}
$GLOBALS['feed_rows']=[
    'tours-and-activities'=>[feed(11,'tours-and-activities','en','Tour EN'),
                            feed(12,'tours-and-activities','ar','Tour AR')],
    'properties'=>[feed(21,'properties','en','Hotel')],
];
$invalid=MAD4B_Hub_Source_Import_V1::collect('not-registered',['tours-and-activities']);
check(is_wp_error($invalid),'Unknown source denied');
$invalid=MAD4B_Hub_Source_Import_V1::collect('tourism-almallahgroup',['private-orders']);
check(is_wp_error($invalid),'Unknown type denied');
$bad=feed(11,'tours-and-activities','en','Tour');$bad['may_checkout']=true;
check(is_wp_error(MAD4B_Hub_Source_Import_V1::validate_item($bad,'tourism-almallahgroup','tours-and-activities')),
    'Bookable source claims rejected');
$bad=feed(11,'tours-and-activities','en','Tour');$bad['source_company']='other';
check(is_wp_error(MAD4B_Hub_Source_Import_V1::validate_item($bad,'tourism-almallahgroup','tours-and-activities')),
    'Cross-company record rejected');
$GLOBALS['fail_feed']=true;
$offline=MAD4B_Hub_Source_Import_V1::import('tourism-almallahgroup',['tours-and-activities']);
check(is_wp_error($offline) && empty($GLOBALS['hub_posts']),'Offline source makes no changes');
$GLOBALS['fail_feed']=false;
$first=MAD4B_Hub_Source_Import_V1::import('tourism-almallahgroup',['tours-and-activities','properties']);
check(is_array($first)&&$first['imported']===3&&$first['created']===3,
    'Three published records imported into Hub');
check(count($GLOBALS['hub_posts'])===3,'Private Hub has three real posts');
foreach($GLOBALS['hub_posts'] as $p)check($p->post_status==='private','Every import private');
$active=MAD4B_Hub_Source_Import_V1::active_items(
    'tourism-almallahgroup',['tour','property'],['en','ar'],[],true);
check(is_array($active)&&count($active)===3,'Active imported references are available privately');
foreach($active as $row)check(!$row['may_checkout']&&!$row['may_publish'],
    'Imported references cannot publish or checkout');
$count=$GLOBALS['changes'];
$second=MAD4B_Hub_Source_Import_V1::import('tourism-almallahgroup',['tours-and-activities','properties']);
check($second['created']===0&&$second['updated']===0&&$GLOBALS['changes']===$count,
    'Repeat import creates no duplicates or unnecessary writes');
$GLOBALS['feed_rows']['tours-and-activities'][0]['body']='Updated source body';
$third=MAD4B_Hub_Source_Import_V1::import('tourism-almallahgroup',['tours-and-activities','properties']);
check($third['updated']===1 && count($GLOBALS['hub_posts'])===3,'Updated source updates original Hub post');
$firstPostId=0;
foreach($GLOBALS['hub_meta'] as $id=>$meta) {
    if(($meta['_mad4b_hub_import_source_id']??null)===11) $firstPostId=$id;
}
check($firstPostId>0,'Source record 11 mapped to Hub post');
check(strpos($GLOBALS['hub_posts'][$firstPostId]->post_content,'Updated source body')!==false,
    'Updated source content present');
array_pop($GLOBALS['feed_rows']['tours-and-activities']);
$fourth=MAD4B_Hub_Source_Import_V1::import('tourism-almallahgroup',['tours-and-activities','properties']);
check($fourth['imported']===2,'Removed source excluded from active collection');
$active=MAD4B_Hub_Source_Import_V1::active_items('tourism-almallahgroup',['tour','property'],['en','ar'],[],true);
check(count($active)===2 && count($GLOBALS['hub_posts'])===3,
    'Removed source archived privately but not redistributed');
$policy=[
    'enabled'=>true,'auto_include_new'=>false,'consumers'=>[],
    'kinds'=>['tour','property'],'languages'=>['en','ar'],
    'selected_source_ids'=>[],
    'source_mode'=>'import_to_hub',
    'source_post_types'=>['tours-and-activities','properties'],
];
$normalized=MAD4B_Hub_Auto_Distribute_V1::normalize($policy);
check(is_array($normalized)&&$normalized['source_mode']==='import_to_hub',
    'Import-only policy accepted without consumers');
$policy['source_mode']='not-a-mode';
check(is_wp_error(MAD4B_Hub_Auto_Distribute_V1::normalize($policy)),'Unknown mode denied');
$policy['source_mode']='import_to_hub';
$GLOBALS['options'][MAD4B_Hub_Auto_Distribute_V1::OPTIONS]=$policy;
$run=MAD4B_Hub_Auto_Distribute_V1::distribute();
check(is_array($run) && $run['status']==='imported_only','Automatic import-only run completes');
$policy['consumers']=['all-royal-egypt'];
$policy['auto_include_new']=true;
$GLOBALS['options'][MAD4B_Hub_Auto_Distribute_V1::OPTIONS]=$policy;
$autoRun=MAD4B_Hub_Auto_Distribute_V1::distribute();
check(is_array($autoRun) && $autoRun['status']==='success','Default import then distribute completes');
$sent=$GLOBALS['last_distributed_snapshot']??[];
check(($sent['source_company']??'')==='tourism-almallahgroup' && count($sent['items']??[])===2,
    'Only current imported Hub records distributed');
check(($sent['may_publish']??true)===false && ($sent['may_checkout']??true)===false,
    'Imported content never gains commerce permissions');


// Remote endpoint only exposes published post fields; no private orders.
$GLOBALS['source_rows']=[
    11=>(object)['ID'=>11,'post_type'=>'tours-and-activities','post_status'=>'publish',
        'language'=>'en','post_title'=>'Public tour','post_excerpt'=>'Public',
        'post_content'=>'Hello <strong>World</strong>','post_modified_gmt'=>'2026-10-30 09:00:00'],
    40=>(object)['ID'=>40,'post_type'=>'properties','post_status'=>'private',
        'language'=>'en','post_title'=>'Private property','post_excerpt'=>'Private',
        'post_content'=>'Secret','post_modified_gmt'=>'2026-10-30 09:00:00'],
];
$response=MAD4B_Company_Simple_Content_Feed_V1::page(new WP_REST_Request([
    'post_type'=>'tours-and-activities','page'=>1
]));
check($response instanceof WP_REST_Response && $response->data['total']===1,'Only published post appears');
check($response->data['items'][0]['body']==='Hello World','Source body sanitized');
check($response->data['may_checkout']===false,'Source feed prohibits checkout');
$invalid=MAD4B_Company_Simple_Content_Feed_V1::page(new WP_REST_Request([
    'post_type'=>'shop_order','page'=>1
]));
check(is_wp_error($invalid),'Orders not exposable through source feed');

$proof=[
    'source_tour_id'=>5551,'source_company'=>'tourism-almallahgroup',
    'title'=>'Previously authenticated Nile tour',
    'summary'=>'Verified Tourism summary',
    'source_content_text'=>'Verified published source content',
    'translation_source'=>['language_code'=>'en'],
    'publish_allowed'=>false,
];
$proofJson=json_encode($proof, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$GLOBALS['verified_nile'][67515]=1;
$GLOBALS['hub_meta'][67515]=[
    '_mad4b_hub_source_company'=>'tourism-almallahgroup',
    '_mad4b_hub_content_owned_by'=>'tourism-almallahgroup',
    '_mad4b_hub_publication_state'=>'published_content_hub',
    '_mad4b_hub_commerce_approved'=>'no',
    '_mad4b_hub_bookable'=>'no',
    '_mad4b_hub_source_payload_json'=>$proofJson,
    '_mad4b_hub_source_sha256'=>hash('sha256',$proofJson),
    '_mad4b_hub_source_id'=>5551,
];
$GLOBALS['hub_meta'][67515]['_mad4b_hub_source_sha256']=str_repeat('0',64);
$before=count($GLOBALS['hub_posts']);
$rejected=MAD4B_Hub_Source_Import_V1::bootstrap_verified_nile_tours();
check(is_wp_error($rejected) && count($GLOBALS['hub_posts'])===$before,
    'Tampered historical proof blocks bootstrap without Hub writes');
$GLOBALS['hub_meta'][67515]['_mad4b_hub_source_sha256']=hash('sha256',$proofJson);
$boot=MAD4B_Hub_Source_Import_V1::bootstrap_verified_nile_tours();
check(is_array($boot) && $boot['imported']===1 &&
    $boot['fresh_source_fetch']===false,'Bootstrap verified Staging source reference into private Hub');
$active=get_option(MAD4B_Hub_Source_Import_V1::ACTIVE,[]);
check(count($active['keys'])===1 && $active['record_count']===1,
    'Old source records excluded from the currently active index');
$before=$GLOBALS['changes'];
$again=MAD4B_Hub_Source_Import_V1::bootstrap_verified_nile_tours();
check($again['created']===0 && $again['updated']===0 && $GLOBALS['changes']===$before,
    'Repeat bootstrap does not duplicate or rewrite prior Hub records');

echo "PASS $tests native source pull and default Content Hub import tests\n";

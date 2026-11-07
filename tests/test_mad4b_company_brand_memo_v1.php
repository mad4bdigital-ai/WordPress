<?php
define('ABSPATH','/');
define('MAD4B_COMMERCE_RUNTIME_ENABLED',true);
define('DAY_IN_SECONDS',86400);
$GLOBALS['hooks']=[];
$GLOBALS['brand_calls']=['look-and-feel'=>0,'company_brand_asset_bundles'=>0];
$GLOBALS['brand_mode']='on';
$GLOBALS['is_admin']=false;
$GLOBALS['lang']='en';
$GLOBALS['blog']=1;
$GLOBALS['options']=[
 1=>['home'=>'https://tours.dev.mad4b.com','look-and-feel'=>['company_brand_profile'=>'inherit','company_brand_name'=>'Tours.dev','sample_stable_payload'=>str_repeat('x',40000)],'company_brand_asset_bundles'=>[]],
 2=>['home'=>'https://second-company.example','look-and-feel'=>['company_brand_profile'=>'inherit','company_brand_name'=>'Company B'],'company_brand_asset_bundles'=>[]]
];
function add_filter($name,$cb,$priority=10,$accepted=1){$GLOBALS['hooks'][$name][]=$cb;}
function add_action($name,$cb,$priority=10,$accepted=1){$GLOBALS['hooks'][$name][]=$cb;}
function fire_hook($name,...$params){foreach($GLOBALS['hooks'][$name]??[] as $cb)$cb(...$params);}
function get_option($name,$default=null){
 if($name==='mad4b_brand_request_memo_v1')return $GLOBALS['brand_mode'];
 if(isset($GLOBALS['brand_calls'][$name]))$GLOBALS['brand_calls'][$name]++;
 return $GLOBALS['options'][$GLOBALS['blog']][$name]??$default;
}
function get_current_blog_id(){return $GLOBALS['blog'];}
function wp_get_environment_type(){return 'staging';}
function is_admin(){return $GLOBALS['is_admin'];}
function is_user_logged_in(){return false;}
function wp_doing_ajax(){return false;}
function wp_json_encode($value){return json_encode($value,JSON_UNESCAPED_UNICODE);}
function apply_filters($name,$value,...$args){if($name==='wpml_current_language')return $GLOBALS['lang'];return $value;}
function wp_unslash($value){return $value;}
function get_post_meta($id,$key,$single){return [];}
function get_post_type($id){return 'post';}
function get_bloginfo($field){return 'Tours.dev';}
function sanitize_text_field($value){return trim((string)$value);}
function wp_parse_url($value,$part=-1){return parse_url($value,$part);}
function ok($label,$condition){if(!$condition)throw new RuntimeException("FAILED ".$label);echo "PASS ".$label."\n";}
$_SERVER['REQUEST_METHOD']='GET';$_GET=[];
$source=getenv('MAD4B_BRAND_SOURCE');if(!$source||!is_file($source))throw new RuntimeException('missing source');
require $source;
$a=company_brand_context();
$firstCalls=$GLOBALS['brand_calls'];
$b=company_brand_context();
ok('context same in consecutive public render requests',$a===$b);
ok('expensive options not re-read on repeat',
 $GLOBALS['brand_calls']===$firstCalls && $firstCalls['look-and-feel']===1);
$GLOBALS['lang']='fr';
$c=company_brand_context();
ok('change WPML language uses separate context', $GLOBALS['brand_calls']['look-and-feel']===2);
$GLOBALS['blog']=2;
$d=company_brand_context();
ok('switch company does not reuse other brand',($d['name']??'')==='Company B');
$GLOBALS['blog']=1;$GLOBALS['lang']='en';
$first=$GLOBALS['brand_calls']['look-and-feel'];
$e=company_brand_context();
ok('switch back reuses original site/locale cache',$GLOBALS['brand_calls']['look-and-feel']===$first);
$GLOBALS['options'][1]['look-and-feel']['company_brand_name']='New name';
fire_hook('updated_option','look-and-feel');
$f=company_brand_context();
ok('in-request option update invalidates memo',($f['name']??'')==='New name' && $GLOBALS['brand_calls']['look-and-feel']===$first+1);
$prev=$GLOBALS['brand_calls']['look-and-feel'];
$_GET['company_brand_preview']='wayfa';
company_brand_context();company_brand_context();
ok('signed preview requests skip public memo',$GLOBALS['brand_calls']['look-and-feel']===$prev+2);
$_GET=[];
$GLOBALS['is_admin']=true;$prev=$GLOBALS['brand_calls']['look-and-feel'];
company_brand_context();company_brand_context();
ok('admin uses original full settings hash',$GLOBALS['brand_calls']['look-and-feel']===$prev+2);
echo "PASS PUBLIC BRAND MEMO REGRESSION\n";

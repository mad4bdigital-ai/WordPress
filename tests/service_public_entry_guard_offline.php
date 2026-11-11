<?php
/** Site-neutral deny-only ingress guard with no database/network dependencies. */
define('ABSPATH','/');
$GLOBALS['hooks']=[];
$GLOBALS['rules']=['tours'=>true,'properties'=>false,'transportations'=>false,'visas'=>false];
function add_filter($name,$fn,$priority=10,$args=1){$GLOBALS['hooks'][$name][]=$fn;}
function add_action($name,$fn,$priority=10,$args=1){$GLOBALS['hooks'][$name][]=$fn;}
function current_user_can($cap){return (bool)($GLOBALS['editor']??false);}
function service_channel_allowed($family,$channel){
 return $channel==='standalone' && ($GLOBALS['rules'][$family]??false);
}
class WP_Post {public function __construct(public string $post_type,public string $post_name){}}
class WP_Error {
 public function __construct(private string $code,private string $message,private array $data){}
 public function get_error_code(){return $this->code;}
 public function get_error_data(){return $this->data;}
}
class WP_REST_Request {
 public function __construct(private string $method,private string $route,private array $params=[]){}
 public function get_method(){return $this->method;}
 public function get_route(){return $this->route;}
 public function get_param($key){return $this->params[$key]??null;}
}
function get_post_types($args,$output){
 return ['tours-and-activities'=>(object)['rest_base'=>'tours-and-activities','rest_namespace'=>'wp/v2'],
  'tour-rates'=>(object)['rest_base'=>'tour-rates','rest_namespace'=>'wp/v2'],
  'properties'=>(object)['rest_base'=>'properties','rest_namespace'=>'wp/v2'],
  'units'=>(object)['rest_base'=>'units','rest_namespace'=>'wp/v2'],
  'transportations'=>(object)['rest_base'=>'transportations','rest_namespace'=>'wp/v2'],
  'page'=>(object)['rest_base'=>'pages','rest_namespace'=>'wp/v2'],
  'post'=>(object)['rest_base'=>'posts','rest_namespace'=>'wp/v2']];
}
function get_post($id){return $id===44?new WP_Post('page','properties'):new WP_Post('page','contact');}
function get_permalink($post){return 'https://example.test/'.($post->post_name??'').'/';}
function wp_parse_url($url,$part){return parse_url($url,$part);}
function t($condition,$label){if(!$condition){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);exit(2);}}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-service-public-entry-guard-v1.php';
$c=MAD4B_Service_Public_Entry_Guard_V1::class;
t($c::publiclyRestricted('tour-rates'),'Tour rate always internal');
t($c::publiclyRestricted('property-rates'),'Property rate always internal');
t($c::publiclyRestricted('transport-rates'),'Transport rate always internal');
t($c::publiclyRestricted('properties'),'Property standalone denied');
t($c::publiclyRestricted('transportations'),'Transport standalone denied');
t(!$c::publiclyRestricted('tours-and-activities'),'Tours allowed');
t(!$c::publiclyRestricted('post'),'General post untouched');
t($c::servicePageRestricted(new WP_Post('page','transportations')),'Transport landing denied');
t($c::servicePageRestricted(new WP_Post('page','properties')),'Property landing denied');
t(!$c::servicePageRestricted(new WP_Post('page','about')),'About page allowed');
$req=fn($route,$method='GET',$params=[])=>new WP_REST_Request($method,$route,$params);
t($c::restGuard(null,null,$req('/wp/v2/tour-rates')) instanceof WP_Error,'Rate REST list 404');
t($c::restGuard(null,null,$req('/wp/v2/tour-rates/171')) instanceof WP_Error,'Rate REST item 404');
t($c::restGuard(null,null,$req('/wp/v2/properties')) instanceof WP_Error,'Property REST denied');
t($c::restGuard(null,null,$req('/wp/v2/transportations')) instanceof WP_Error,'Transport REST denied');
t($c::restGuard(null,null,$req('/wp/v2/pages/44')) instanceof WP_Error,'Property page REST denied');
t($c::restGuard(null,null,$req('/wp/v2/search','GET',['subtype'=>'tour-rates'])) instanceof WP_Error,'Search subtype denied');
t($c::restGuard(null,null,$req('/wp/v2/tours-and-activities'))===null,'Tours REST allowed');
t($c::restGuard(null,null,$req('/wp/v2/posts'))===null,'Posts REST allowed');
t($c::restGuard(null,null,$req('/wp/v2/tour-rates','POST'))===null,'POST left to existing auth');
$GLOBALS['editor']=true;
t($c::restGuard(null,null,$req('/wp/v2/tour-rates'))===null,'Authenticated editors preserved');
$GLOBALS['editor']=false;
$GLOBALS['rules']['properties']=true;
t(!$c::publiclyRestricted('properties'),'Standalone properties dynamically allowed');
t(!$c::servicePageRestricted(new WP_Post('page','properties')),'Property landing dynamically allowed');
t($c::publiclyRestricted('tour-rates'),'Internal rate still restricted after site activation');
t($c::rankmathType(false,'tour-rates'),'RankMath rate hidden');
t($c::rankmathEntry(['loc'=>'/'], 'post',new WP_Post('tour-rates','sample'))===false,'RankMath entry hidden');
echo json_encode(['state'=>'PASS','checks'=>25,'reads_only'=>true,'no_settings_changed'=>true]).PHP_EOL;

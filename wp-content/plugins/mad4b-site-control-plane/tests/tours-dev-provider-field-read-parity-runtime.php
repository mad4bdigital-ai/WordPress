<?php
/** Provider fields are redacted-key observations; REST route is admin only. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');
class WP_Error {private $c;public function __construct($c,$msg=''){$this->c=$c;}public function get_error_code(){return $this->c;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action($hook,$cb){$GLOBALS['hooks'][$hook][]=$cb;}
function register_rest_route($namespace,$route,$config){$GLOBALS['routes'][$namespace.$route]=$config;}
function current_user_can($cap){return $GLOBALS['can_admin']&&$cap==='manage_options';}
function post_type_exists($name){return in_array($name,['post','tours-and-activities'],true);}
function taxonomy_exists($name){return $name==='destinations';}
function get_registered_meta_keys($type,$sub){return ['hero_image'=>['type'=>'image','show_in_rest'=>true],'api_token'=>['type'=>'string'],'rank_math_title'=>['type'=>'string']];}
function get_option($name,$default=[]){return [];}
function apply_filters($hook,$value,...$rest){return $value;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function absint($value){return abs((int)$value);}
function rest_ensure_response($v){return $v;}
class StubDB {public $postmeta='wp_postmeta';public $posts='wp_posts';public $termmeta='wp_termmeta';public $term_taxonomy='wp_term_taxonomy';public $last_error='';public function prepare($query,...$args){return $query;}public function get_results($query,$format){return [['meta_key'=>'hero_image','c'=>5],['meta_key'=>'private_token','c'=>2]];}}
$GLOBALS['wpdb']=new StubDB();$GLOBALS['can_admin']=false;
class MAD4B_SCP_Site_Profile {static $ready=false;public static function status(){return ['site_uuid'=>self::$ready?'fixture-site':'','origin_match'=>self::$ready,'environment_match'=>self::$ready,'deployment_binding_match'=>self::$ready,'environment'=>'staging'];}}
function expect($ok,$label){if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-provider-field-intelligence.php';
$c='MAD4B_SCP_Provider_Field_Intelligence';
expect(isset($GLOBALS['hooks']['rest_api_init']),'Read-only GET route registration hook exists');
$c::rest_route();$route=$GLOBALS['routes']['mad4b/v1/provider-fields'];
expect($route['methods']==='GET','REST route must be GET only');
expect(!$route['permission_callback'](),'Anonymous may not enumerate provider keys');
$x=$c::discover(['arbitrary'=>'x']);expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_provider_fields_invalid_input','Reject input overreach');
$x=$c::discover(['subtype'=>'unknown']);expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_provider_fields_site_unverified','Do not reveal foreign CPT existence to unbound callers');
$x=$c::discover();expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_provider_fields_site_unverified','Site binding mandatory');
MAD4B_SCP_Site_Profile::$ready=true;$GLOBALS['can_admin']=true;
$x=$c::discover(['subtype'=>'unknown']);expect(is_wp_error($x)&&$x->get_error_code()==='mad4b_provider_fields_unknown_object','Reject foreign source post type after site identity validation');
expect($route['permission_callback'](),'Only current managing admin may query field inventory');
$x=$c::discover(['only_media'=>true,'limit'=>2]);
expect($x['subtype']==='post','Unspecified subtype uses site-neutral built-in post, not company tours CPT');
expect(is_array($x)&&$x['discovery_read_only']&&!$x['mutations_performed']&&!$x['seo_changes_authorized'],'Read-only inventory cannot edit SEO/media');
$names=array_column($x['fields'],'key');expect(in_array('hero_image',$names,true),'Registered media field visible');
expect(!in_array('api_token',$names,true)&&!in_array('private_token',$names,true),'Secret keys omitted');
expect(count($x['fields'])<=2,'Caller limit bounded');
$GLOBALS['can_admin']=false;expect(!$route['permission_callback'](),'Permission revoked means route denied');
echo "PASS: provider field GET-only, exact Site Profile, capped discovery, secret-key redaction and admin permission\n";

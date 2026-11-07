<?php
define('ABSPATH','/');
define('WP_PLUGIN_DIR',(string)(getenv('MAD4B_TEST_WP_PLUGIN_DIR') ?: '/var/www/html/wp-content/plugins'));
define('WPMU_PLUGIN_DIR',(string)(getenv('MAD4B_TEST_MU_DIR') ?: '/tmp/mad4b-jetblog-fixture/mu-plugins'));
$GLOBALS['env']='staging';$GLOBALS['active']=true;$GLOBALS['admin']=false;
$GLOBALS['logged']=false;$GLOBALS['mobile']=true;$GLOBALS['ajax']=false;
$GLOBALS['option']=null;$GLOBALS['hooks']=[];
$_SERVER['REQUEST_METHOD']='GET';$_GET=[];
class MAD4B_Experience_Site_Scope_V1 {public static function bound():bool {return $GLOBALS['active'];}}
function wp_get_environment_type(){return $GLOBALS['env'];}
function is_admin(){return $GLOBALS['admin'];}
function is_user_logged_in(){return $GLOBALS['logged'];}
function wp_is_mobile(){return $GLOBALS['mobile'];}
function wp_doing_ajax(){return $GLOBALS['ajax'];}
function get_option($key,$fallback=null){return $GLOBALS['option']??$fallback;}
function content_url($path){return 'https://example.com/wp-content'.(string)$path;}
function wp_scripts(){return $GLOBALS['scripts'];}
function add_action($hook,$fn,$priority=10,$accepted=1){$GLOBALS['hooks'][$hook][]=$fn;}
function add_filter($hook,$fn,$priority=10,$accepted=1){$GLOBALS['hooks'][$hook][]=$fn;}
function ok($name,$condition){if(!$condition)throw new RuntimeException('FAIL: '.$name);echo "PASS ".$name."\n";}
$source=getenv('MAD4B_JETBLOG_PORTABLE_SOURCE');
if(!$source||!is_file($source))throw new RuntimeException('missing source');
require $source;
$class='MAD4B_JetBlog_Mobile_Lazy_Canary';
ok('staging company default works',$class::enabled());
$GLOBALS['env']='production';
ok('production default off',$class::enabled()===false);
$_GET['mad4b_jetblog_lazy']='1';
ok('public query cannot enable production feature',$class::enabled()===false);
$GLOBALS['option']='on';
ok('production company can opt in',$class::enabled());
$GLOBALS['active']=false;
ok('unbound company denied',$class::enabled()===false);
$GLOBALS['active']=true;$GLOBALS['logged']=true;
ok('logged in denied',$class::enabled()===false);
$GLOBALS['logged']=false;$GLOBALS['mobile']=false;
ok('desktop denied',$class::enabled()===false);
$GLOBALS['mobile']=true;$GLOBALS['ajax']=true;
ok('AJAX denied',$class::enabled()===false);
$GLOBALS['ajax']=false;$_SERVER['REQUEST_METHOD']='POST';
ok('POST denied',$class::enabled()===false);
$_SERVER['REQUEST_METHOD']='GET';$GLOBALS['option']='off';
ok('company emergency off',$class::enabled()===false);
$GLOBALS['option']='on';$GLOBALS['env']='production';
$vendor='https://example.com/wp-content/plugins/jet-blog/assets/js/jet-blog.js';
$GLOBALS['scripts']=(object)['registered'=>['jet-blog'=>(object)['src'=>$vendor,'deps'=>['jquery','elementor-frontend'],'ver'=>'2.4.0','extra'=>['data'=>'signed-original']]]];
$class::enqueue();
$s=$GLOBALS['scripts']->registered['jet-blog'];
$vendorFile=WP_PLUGIN_DIR.'/jet-blog/assets/js/jet-blog.js';
$vendorPresent=is_file($vendorFile) &&
  hash_file('sha256',$vendorFile)==='1c03c3f87299c68376daea342e33131d237b54b76470e155accfd03a4a4c5ee4';
$assetFile=WPMU_PLUGIN_DIR.'/assets/mad4b-jetblog-lazy-mobile.js';
$assetPresent=is_file($assetFile) &&
 hash_file('sha256',$assetFile)==='b91aed8a63acd42fdd7cc289c30583a3a19f2092c17901b7ef6bbe046d127013';
if($vendorPresent && $assetPresent) {
 ok('pinned vendor and asset selected',str_contains($s->src,'/mu-plugins/assets/mad4b-jetblog-lazy-mobile.js'));
 ok('original script excluded from optimization',str_contains($class::script_tag('<script src="x"></script>','jet-blog',$s->src),'data-no-optimize'));
} else {
 ok('missing or modified vendor fails closed',$s->src===$vendor);
}
ok('original dependencies preserved',$s->deps===['jquery','elementor-frontend']);
ok('original localization preserved',$s->extra['data']==='signed-original');
ok('other script handle untouched',$class::script_tag('<script src="x"></script>','other',$s->src)==='<script src="x"></script>');
echo 'PASS_PORTABLE_JETBLOG_COMPANY_POLICY\n';

<?php
/** Hermetic guard and payload tests for MAD4B native-navigation fragment cache. */
define('ABSPATH', '/tmp/');
$hooks=[];$cache=[];$js=[];$css=[];$uid=0;$session='';$mode='tour';$callbackCalls=0;
$_SERVER=['REQUEST_METHOD'=>'GET','HTTP_HOST'=>'tours.dev.mad4b.com','REMOTE_ADDR'=>'127.0.0.1','HTTP_COOKIE'=>''];$_COOKIE=[];$_GET=[];$_POST=[];
class WP_Scripts {public $queue=[];}
class WP_Styles {public $queue=[];}
$wp_scripts=new WP_Scripts();$wp_styles=new WP_Styles();
function add_filter($name,$cb,$pri=10,$argc=1){$GLOBALS['hooks'][$name]=$cb;}
function is_admin(){return $GLOBALS['mode']==='admin';}
function wp_doing_ajax(){return $GLOBALS['mode']==='ajax';}
function is_preview(){return $GLOBALS['mode']==='preview';}
function is_singular($type){return $GLOBALS['mode']!=='page' && $type==='tours-and-activities';}
$GLOBALS['nav_epochs']=['posts'=>'p1','terms'=>'t1'];
function wp_get_environment_type(){return 'staging';}
function wp_cache_get_last_changed($group){return $GLOBALS['nav_epochs'][$group];}
function get_option($key){return $key==='_elementor_element_cache_unique_id'?'fixed-key':'';}
function get_current_user_id(){return $GLOBALS['uid'];}
function wp_get_session_token(){return $GLOBALS['session'];}
function home_url($p){return 'https://tours.dev.mad4b.com'.$p;}
function get_stylesheet(){return 'astra';}
function get_queried_object_id(){return 60623;}
function get_locale(){return 'en_US';}
function apply_filters($hook,...$params){return $hook==='wpml_current_language'?'en':$params[0]??null;}
function wp_is_mobile(){return false;}
function wp_json_encode($data){return json_encode($data);}
function wp_cache_get($key,$group){return $GLOBALS['cache'][$group.':'.$key]??false;}
function wp_cache_set($key,$v,$group,$ttl){$GLOBALS['cache'][$group.':'.$key]=$v;$GLOBALS['lastTTL']=$ttl;return true;}
function wp_enqueue_script($key){$GLOBALS['wp_scripts']->queue[]=$key;}
function wp_enqueue_style($key){$GLOBALS['wp_styles']->queue[]=$key;}
$sentinel=str_repeat('x',50000).'navigation-editor-grid';
$shortcode_tags=['elementor-element'=>function($attrs)use(&$callbackCalls,$sentinel){$callbackCalls++;
   wp_enqueue_script('jet-engine-frontend');wp_enqueue_style('widget-nested-tabs');
   return ($GLOBALS['dataKind']??'normal')==='sensitive'
       ? $sentinel.'nonce' : $sentinel;
}];
require getenv('MAD4B_NAV_TEST_FILE')?:dirname(__DIR__,2).'/wp-content/mu-plugins/449-mad4b-native-nav-fragment-cache.php';
function check($ok,$name){if(!$ok)throw new RuntimeException('FAILED '.$name);echo 'PASS '.$name."\n";}
$cb=$GLOBALS['hooks']['pre_do_shortcode_tag']??null;
check(is_callable($cb),'hook installed');
$d=['id'=>'6072b3f','widgetType'=>'mega-menu','settings'=>['_css_classes'=>'product-native-navigation navigation-editor-menu']];
$a=['k'=>'fixed-key','data'=>base64_encode(json_encode($d))];
$send=static function()use($cb,$a){return $cb(false,'elementor-element',$a,['']);};
$out=$send();
check($GLOBALS['callbackCalls']===1 && strlen($out)>40000,'first render stores content');
check($GLOBALS['lastTTL']===60,'anonymous TTL capped');
$GLOBALS['wp_scripts']->queue=[];$GLOBALS['wp_styles']->queue=[];
$out2=$send();
check($GLOBALS['callbackCalls']===1 && $out===$out2,'identical context hit');
check(in_array('jet-engine-frontend',$GLOBALS['wp_scripts']->queue,true) &&
 in_array('widget-nested-tabs',$GLOBALS['wp_styles']->queue,true),'replays JS and CSS handles');
$_COOKIE['market_country']='EG';$send();check($GLOBALS['callbackCalls']===2,'WP cookie context isolation');
$_COOKIE=[];$_SERVER['HTTP_CF_IPCOUNTRY']='DE';$send();check($GLOBALS['callbackCalls']===3,'GeoIP context isolation');
unset($_SERVER['HTTP_CF_IPCOUNTRY']);
$GLOBALS['uid']=5;$GLOBALS['session']='user-session-A';$send();
check($GLOBALS['callbackCalls']===4 && $GLOBALS['lastTTL']===15,'authenticated session isolated and short TTL');
$GLOBALS['session']='user-session-B';$send();check($GLOBALS['callbackCalls']===5,'different sessions isolated');
$GLOBALS['session']='';check($send()===false && $GLOBALS['callbackCalls']===5,'unknown auth session denied');
$GLOBALS['uid']=0;
foreach (['admin','ajax','preview','page'] as $mode){$GLOBALS['mode']=$mode;check($send()===false,"$mode excluded");}
$GLOBALS['mode']='tour';
$_SERVER['REQUEST_METHOD']='POST';check($send()===false,'POST excluded');$_SERVER['REQUEST_METHOD']='GET';
$_GET=['travel_date'=>'2027-03-01'];check($send()===false,'date query excluded');$_GET=[];
$_SERVER['HTTP_AUTHORIZATION']='Bearer test';check($send()===false,'authorization excluded');unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['HTTP_X_WP_NONCE']='test';check($send()===false,'REST nonce excluded');unset($_SERVER['HTTP_X_WP_NONCE']);
$bad=$a;$bad['k']='invalid';check($cb(false,'elementor-element',$bad,[''])===false,'invalid Elementor shortcode signature excluded');
$bad=$a;$bad['data']=base64_encode(json_encode(['id'=>'6072b3f','widgetType'=>'template']));
check($cb(false,'elementor-element',$bad,[''])===false,'non-navigation widget excluded');
$GLOBALS['dataKind']='sensitive';$_COOKIE=['canary'=>'sensitive'];$GLOBALS['callbackCalls']=0;
$send();$send();check($GLOBALS['callbackCalls']===2,'sensitive nonce HTML never cached');
$GLOBALS['dataKind']='normal';$_COOKIE=[];$GLOBALS['nav_epochs']['terms']='t2';$GLOBALS['callbackCalls']=0;
$send();check($GLOBALS['callbackCalls']===1,'term revision invalidates legacy replay');
$send();check($GLOBALS['callbackCalls']===1,'new revision reuses native replay');
echo 'ALL_GUARDS_PASS'.PHP_EOL;

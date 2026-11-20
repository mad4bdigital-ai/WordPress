<?php
/** Regression: CSS source stability is offered to public WPML posts, never private commerce. */
define('ABSPATH','/');
class WP_Post {public string $post_type='post';public string $post_status='publish';}
class MAD4B_Experience_Site_Scope_V1 {public static function bound():bool{return true;}}
$GLOBALS['is_post']=false;$GLOBALS['is_home']=false;$GLOBALS['is_page']=false;$GLOBALS['locale']='en';$GLOBALS['logged']=false;
$GLOBALS['post']=new WP_Post();$GLOBALS['filters']=[];
$_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/a-post/'];
function get_current_blog_id(){return 1;}
function apply_filters($key,$value){return $key==='wpml_current_language'?$GLOBALS['locale']:$value;}
function get_option($key,$default=null){return match($key){'mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],'litespeed.conf.optm-css_comb'=>'1','litespeed.conf.optm-css_comb_ext_inl'=>'1','home'=>'https://wayfatours.com',default=>$default};}
function is_front_page(){return $GLOBALS['front']??false;}
function is_page(){return $GLOBALS['is_page'];}
function is_singular($type=''){return $type==='post'&&$GLOBALS['is_post'];}
function is_home(){return $GLOBALS['is_home'];}
function is_preview(){return false;}
function is_feed(){return false;}
function is_404(){return false;}
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function is_user_logged_in(){return $GLOBALS['logged'];}
function get_queried_object_id(){return 311;}
function absint($value){return abs((int)$value);}
function get_post($id){return $id===311?$GLOBALS['post']:null;}
function is_post_publicly_viewable($post){return $post->post_status==='publish';}
function post_password_required($post){return false;}
function get_permalink($post){return 'https://wayfatours.com'.($GLOBALS['permalink']??'/a-post/');}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function wp_get_environment_type(){return 'production';}
function add_action(){}
function add_filter($hook,$callback,$priority=10,$args=1){$GLOBALS['filters'][$hook][]=$callback;}
require (getenv('MAD4B_406_SOURCE') ?: __DIR__.'/../../../wp-content/mu-plugins/406-mad4b-shared-performance-parity.php');
function verify($name,$expected){static $n=0;if(!$expected){fwrite(STDERR,"FAIL $name\n");exit(3);} $n++;echo "PASS $name\n";}
function active(){ $GLOBALS['filters']=[];MAD4B_Shared_First_Paint_Performance::stable_css_combination();return !empty($GLOBALS['filters']['litespeed_optimize_css_excludes']);}
verify('non-home and non-post contexts do not gain CSS exclusion',!active());
$GLOBALS['front']=true;verify('existing homepage CSS protection retained',active());$GLOBALS['front']=false;
$GLOBALS['is_post']=true;
verify('public article retains dynamic CSS outside static bundle',active());
verify('LiteSpeed exclusion specific to JetEngine dynamic item selectors',in_array('elementor-dcss-',($GLOBALS['filters']['litespeed_optimize_css_excludes'][0])(['site.css']),true));
$GLOBALS['post']->post_status='draft';verify('draft article blocked',!active());$GLOBALS['post']->post_status='publish';
$_SERVER['REQUEST_URI']='/other/';verify('noncanonical route blocked',!active());$_SERVER['REQUEST_URI']='/a-post/';
$_SERVER['QUERY_STRING']='preview=1';verify('untrusted query blocked',!active());unset($_SERVER['QUERY_STRING']);
$_SERVER['HTTP_X_MAD4B_CURRENCY']='EUR';verify('private currency override blocked',!active());unset($_SERVER['HTTP_X_MAD4B_CURRENCY']);
$_SERVER['HTTP_COOKIE']='woocommerce_cart_hash=test';verify('commerce cookie blocked',!active());unset($_SERVER['HTTP_COOKIE']);
$GLOBALS['logged']=true;verify('logged-in user blocked',!active());$GLOBALS['logged']=false;
$GLOBALS['is_home']=true;verify('WordPress posts index blocked',!active());$GLOBALS['is_home']=false;
$_SERVER['REQUEST_METHOD']='POST';verify('non-read HTTP method blocked',!active());

// Additional public Contact hero image contract: only public canonical pages,
// one dedicated image class; footer, gallery and private request remain lazy.
$GLOBALS['is_post']=false;$GLOBALS['is_page']=true;$GLOBALS['post']->post_type='page';$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REQUEST_URI']='/es/contacto/';$GLOBALS['permalink']='/es/contacto/';$GLOBALS['locale']='es';
function lazyhero(){ $GLOBALS['filters']=[];MAD4B_Shared_First_Paint_Performance::contact_hero_image_priority();return $GLOBALS['filters']['litespeed_media_lazy_img_cls_excludes'][0]??null; }
$f=lazyhero();
verify('canonical WPML Contact page registers bounded hero exception',is_callable($f));
verify('only hero class excluded; other images remain lazy',
  $f(['unrelated-image'])===['unrelated-image','mad4b-contact-hero-photo']);
$_SERVER['REQUEST_URI']='/es/unauthorized/';verify('noncanonical page retains normal lazy loading',lazyhero()===null);$_SERVER['REQUEST_URI']='/es/contacto/';
$GLOBALS['post']->post_status='draft';verify('unpublished page cannot register image preload exception',lazyhero()===null);$GLOBALS['post']->post_status='publish';
$_SERVER['QUERY_STRING']='preview=1';verify('query context does not change lazy image policy',lazyhero()===null);unset($_SERVER['QUERY_STRING']);
$_SERVER['REQUEST_METHOD']='POST';verify('POST does not change lazy image policy',lazyhero()===null);$_SERVER['REQUEST_METHOD']='GET';
$GLOBALS['logged']=true;verify('logged-in page does not change lazy policy',lazyhero()===null);$GLOBALS['logged']=false;
$GLOBALS['is_page']=false;verify('non-page archive remains untouched',lazyhero()===null);

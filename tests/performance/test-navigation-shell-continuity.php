<?php
define('ABSPATH','/');
$GLOBALS['env']='staging';$GLOBALS['options']=[];$GLOBALS['cache']=[];$GLOBALS['epochs']=['posts'=>'p1','terms'=>'t1'];
function add_filter(...$x){}
function native_destinations_eligible_children($parent,$language){return $parent===665&&$language==='en'?[666,667]:[];}
function mad4b_nav_grid_requires_native_ssr($s){return str_contains($s['_css_classes']??'','navigation-editor-grid');}
 function wp_get_environment_type(){return $GLOBALS['env'];}
function wp_using_ext_object_cache(){return true;}function is_user_logged_in(){return $GLOBALS['user']??false;}
function apply_filters($name,$v){return 'en';}
function mad4b_render_query_market_identity(){return ['country'=>'DE','currency'=>'USD'];}
function mad4b_render_query_has_untrusted_market_header(){return $GLOBALS['untrusted']??false;}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function wp_cache_get_last_changed($g){return $GLOBALS['epochs'][$g];}
function wp_cache_get($k,$g){return $GLOBALS['cache'][$k]??false;}
function wp_cache_set($k,$v,$g,$ttl){$GLOBALS['cache'][$k]=$v;return true;}
class MAD4B_Experience_Site_Scope_V1 {public static function bound(){return true;}}
function ok($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
require $argv[1];
$c='MAD4B_Navigation_Shell_Continuity_V2';
ok(!$c::canary(),'ordinary traffic stays on original route');
$_SERVER['HTTP_X_MAD4B_NAV_CONTINUITY']='observe-v2';$_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']='1';
ok($c::eligible(),'explicit anonymous staging canary');
$GLOBALS['env']='production';ok(!$c::eligible(),'production cannot activate canary');
$GLOBALS['env']='staging';$_COOKIE=['unknown'=>'1'];ok(!$c::eligible(),'unknown cookie excluded');$_COOKIE=[];
$GLOBALS['user']=true;ok(!$c::eligible(),'logged in excluded');$GLOBALS['user']=false;
$GLOBALS['untrusted']=true;ok(!$c::eligible(),'untrusted market excluded');$GLOBALS['untrusted']=false;
$root=['widgetType'=>'mega-menu','elements'=>[['widgetType'=>'jet-listing-grid','settings'=>['lisitng_id'=>123,'product_navigation_service'=>'tours','_css_classes'=>'navigation-editor-grid']]]];
ok($c::listing_ids([$root])===[123],'native navigation listing discovered');
$bad=$root;$bad['elements'][]=['widgetType'=>'fluent-form-widget'];ok($c::listing_ids([$bad])===null,'transactional widget excluded');
$bad=$root;$bad['elements'][0]['settings']['custom_query']='1';ok($c::listing_ids([$bad])===null,'unknown query excluded');
$h='<div class="navigation-editor-grid">'.str_repeat('<span>Destination</span>',1900).'</div>';
ok($c::safe_html($h),'public menu accepted');
foreach(['<form></form>','<div data-session="x"></div>','<div data-price="2"></div>'] as $bad)ok(!$c::safe_html($h.$bad),'sensitive HTML rejected');
$r=$c::revision();$GLOBALS['epochs']['terms']='t2';ok($r!==$c::revision(),'term revision invalidates key');
$r=$c::revision();$GLOBALS['options']['service-activation']=['tours'=>true];ok($r!==$c::revision(),'service activation invalidates key');
$r=$c::revision();$GLOBALS['options']['product_navigation_sources']=['tours'=>['query_id'=>5]];ok($r!==$c::revision(),'navigation source changes invalidate key');
$e=['html'=>$h,'sha'=>hash('sha256',$h),'digest'=>hash('sha256',$h),'js'=>['j'],'css'=>['c']];
ok($c::witness('a',$e)==='V2_FIRST_WITNESS'&&!isset($GLOBALS['cache']['a']),'first witness cannot store replay');
ok($c::witness('a',$e)==='V2_AWAIT_SECOND','same request cannot qualify twice');
$GLOBALS['cache']['witness_b']=$GLOBALS['cache']['witness_a'];$GLOBALS['cache']['witness_b']['at']=microtime(true)-1;
ok($c::witness('b',$e)==='V2_SECOND_WITNESS_STORED'&&$GLOBALS['cache']['b']['qualified'],'independent prior matching witness stores bounded replay');
$GLOBALS['cache']['witness_c']=$GLOBALS['cache']['witness_a'];$GLOBALS['cache']['witness_c']['at']=microtime(true)-1;$e['js']=['changed'];
ok($c::witness('c',$e)==='V2_FIRST_WITNESS'&&!isset($GLOBALS['cache']['c']),'asset mismatch requires new witnesses');
$clone=$root;$clone['elements'][0]['settings']=['lisitng_id'=>67125,'terms_parent'=>'665','_css_classes'=>'navigation-editor-grid','lazy_load'=>'yes'];
ok($c::listing_ids([$clone])===[67125],'native projected branch with live parent authority admitted');
$clone['elements'][0]['settings']['terms_parent']='999';ok($c::listing_ids([$clone])===null,'unapproved parent denied');
$clone['elements'][0]['settings']['terms_parent']='665';$clone['elements'][0]['settings']['lisitng_id']=67124;
ok($c::listing_ids([$clone])===null,'service-less wrong listing denied');
$clone['elements'][0]['settings']['lisitng_id']=67125;
$clone['elements']=[['widgetType'=>'nested-tabs','elements'=>[$clone['elements'][0]]]];
ok($c::listing_ids([$clone])===[67125],'native nested tabs admitted');
echo "PASS 24/24 navigation shell invariants\n";

<?php
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function add_action($hook,$callback,$priority,$args){$GLOBALS['hooks'][$hook]=$callback;}
function wp_using_ext_object_cache(){return $GLOBALS['external'];}function is_admin(){return $GLOBALS['admin'];}function wp_doing_ajax(){return $GLOBALS['ajax'];}function is_user_logged_in(){return $GLOBALS['logged'];}
function apply_filters($n,$v){return $GLOBALS['locale'];}function get_locale(){return 'en';}function home_url($p){return $GLOBALS['site'];}function get_current_blog_id(){return $GLOBALS['blog'];}function wp_json_encode($v){return json_encode($v);}
function get_taxonomies(){return $GLOBALS['taxonomies'];}function get_post_types(){return ['post','page'];}
function wp_cache_get_last_changed($g){return (string)$GLOBALS['epoch'];}function wp_cache_set_last_changed($g){$GLOBALS['epoch']++;}function wp_cache_get($k,$g,$f,&$found){$found=isset($GLOBALS['cache'][$k]);return $GLOBALS['cache'][$k]??false;}function wp_cache_set($k,$v,$g,$ttl){$GLOBALS['cache'][$k]=$v;$GLOBALS['writes']++;if($ttl!==120)throw new RuntimeException('ttl');}
function check($v,$m){if(!$v)throw new RuntimeException($m);}
$root=dirname(__DIR__);require $root.'/includes/Presentation/InventoryContentCatalog.php';require $root.'/includes/Presentation/PublicCatalogCache.php';
use DynamicFilterSEOBridge\Presentation\{PublicCatalogCache,InventoryContentCatalog};
$external=true;$admin=$ajax=$logged=false;$locale='en';$site='https://example.test/';$blog=1;$epoch=1;$taxonomies=['category'];$cache=[];$writes=$builds=0;$_GET=[];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/archive/';
$complete=true;$builder=function()use(&$builds,&$complete){$builds++;return ['evidence_complete'=>$complete,'snapshot_fingerprint'=>str_repeat('a',64),'inventory'=>[]];};$catalog=new InventoryContentCatalog();$p=['p'=>['id'=>'p']];$c=new PublicCatalogCache();$c->registerInvalidationHooks();
$first=$c->get($p,$builder,$catalog);check($builds===1&&$writes===1,'cold');check($c->get($p,$builder,$catalog)===$first&&$builds===1,'memory hit');$other=new PublicCatalogCache();check($other->get($p,$builder,$catalog)===$first&&$builds===1,'persistent hit');
$locale='es';$other->get($p,$builder,$catalog);check($builds===2,'locale isolation');$blog=2;$other->get($p,$builder,$catalog);check($builds===3,'blog isolation');$p['p']['enabled']=true;$other->get($p,$builder,$catalog);check($builds===4,'profile fingerprint');
$hooks['updated_term_meta']();$other->get($p,$builder,$catalog);check($builds===5,'term invalidation');$hooks['updated_option']('jet_engine_settings');$other->get($p,$builder,$catalog);check($builds===6,'vendor option invalidation');$hooks['updated_option']('_transient_example');$other->get($p,$builder,$catalog);check($builds===6,'transient ignored');
$before=$writes;foreach(['admin','ajax','logged'] as $flag){$GLOBALS[$flag]=true;$other->get($p,$builder,$catalog);$GLOBALS[$flag]=false;}check($writes===$before&&$builds===9,'private bypass');$_GET=['preview'=>'1'];$other->get($p,$builder,$catalog);$_GET=[];check($writes===$before,'query bypass');
$hooks['clean_post_cache']();$complete=false;$other->get($p,$builder,$catalog);$other->get($p,$builder,$catalog);check($writes===$before&&$builds===12,'incomplete evidence not persisted');
check(!isset($hooks['registered_taxonomy'])&&!isset($hooks['registered_post_type']),'registration does not invalidate every request');
$complete=true;$racing=function()use($builder,$c){$value=$builder();$c->invalidate();return $value;};$other->get($p,$racing,$catalog);check($writes===$before,'invalidation during build not persisted');
$other->get($p,$builder,$catalog);$previous=$builds;$taxonomies[]='destinations';$other->get($p,$builder,$catalog);check($builds===$previous+1,'registered taxonomy fingerprint');
echo "PASS: public metadata cache, scoped keys, event invalidation, private/incomplete bypass, race guard\n";

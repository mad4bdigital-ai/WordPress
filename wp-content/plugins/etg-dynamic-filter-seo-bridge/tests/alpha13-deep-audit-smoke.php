<?php
declare(strict_types=1);

function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_title($v){return trim(preg_replace('/[^a-z0-9\-_]+/','-',strtolower((string)$v)),'-');}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function get_term($id,$taxonomy){$map=array(11=>'cairo',12=>'giza',21=>'day-tour');if(!isset($map[(int)$id])){return null;}$o=new stdClass();$o->term_id=(int)$id;$o->slug=$map[(int)$id];$o->taxonomy=$taxonomy;return$o;}
function get_term_by($field,$value,$taxonomy){return null;}
function etg_expect($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function etg_same($expected,$actual,$message){if($expected!==$actual){fwrite(STDERR,"FAIL: $message\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}
function etg_has($needle,$haystack,$message){etg_expect(false!==strpos($haystack,$needle),$message);}

$root=dirname(__DIR__);
require_once $root.'/includes/JetSmartFilters/AjaxFilterStateParser.php';
require_once $root.'/includes/Elementor/DynamicTags/DynamicTagRuntime.php';
require_once $root.'/includes/Identifiers/QueryId.php';
require_once $root.'/includes/Config/Configuration.php';
use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\DynamicTagRuntime;
use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;
use ETG\DynamicFilterSEOBridge\JetSmartFilters\AjaxFilterStateParser;

$parser=new AjaxFilterStateParser(array('location_jet','tour-types_jet'));
$native=$parser->parse(array(
    'provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/',
    'current_query'=>array('_tax_query_location_jet'=>array(11,12,'operator_AND')),
));
etg_same(true,$native['active'],'native JetSmartFilters currentQuery is active');
etg_same(true,$native['filtered_query_complete'],'supported native taxonomy query is complete');
etg_same(array('cairo','giza'),$native['filter_values']['location_jet'],'native term IDs normalize to slugs');
etg_same('AND',$native['filtered_query']['tax_query'][0]['operator'],'native operator marker is preserved safely');

$mixedCase=$parser->parse(array('provider'=>'jet-engine','query_id'=>'myGrid','archive_path'=>'/tours-and-activities/','current_query'=>array('_tax_query_location_jet'=>array(11))));
etg_same('myGrid',$mixedCase['query_id'],'AJAX parser preserves case-sensitive Query ID identity');
etg_same(true,$mixedCase['filtered_query_complete'],'valid mixed-case Query ID remains a complete supported state');
etg_same('jet-engine/myGrid',DynamicTagRuntime::normalizeGroup('jet-engine/myGrid'),'Elementor AJAX group preserves mixed-case Query ID');
etg_same('myGrid',QueryId::normalize('myGrid'),'shared Query ID normalizer preserves admin/runtime case');
etg_expect(QueryId::tokenKey('myGrid')!==QueryId::tokenKey('mygrid'),'token-safe Query ID representation prevents case-only collisions');
$config=new Configuration();
$sanitized=$config->sanitize(array('query_ids'=>array('myGrid','mygrid','bad/id')));
etg_same(array('myGrid','mygrid'),$sanitized['query_ids'],'legacy configuration preserves case-distinct Query IDs and rejects malformed IDs');

$invalidQueryId=$parser->parse(array('provider'=>'jet-engine','query_id'=>'my/Grid','archive_path'=>'/tours-and-activities/','current_query'=>array('_tax_query_location_jet'=>array(11))));
etg_same('',$invalidQueryId['query_id'],'invalid Query ID is rejected instead of rewritten');
etg_same(false,$invalidQueryId['filtered_query_complete'],'invalid Query ID fails closed');
etg_expect(in_array('query_id_malformed',$invalidQueryId['malformed'],true),'invalid Query ID exposes an explicit blocking reason');
etg_same('',DynamicTagRuntime::normalizeGroup('jet-engine/my/Grid'),'invalid Elementor group Query ID fails closed');

$nativeSlug=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('_tax_query_location_jet'=>'africa-containent')));
etg_same(array('africa-containent'),$nativeSlug['filter_values']['location_jet'],'native permalink slug value is supported');

$nativeUnsupported=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('_tax_query_location_jet'=>array(11),'_meta_query_price'=>'100')));
etg_same(false,$nativeUnsupported['filtered_query_complete'],'native meta query fails closed');
etg_expect(in_array('native_meta_query',$nativeUnsupported['unsupported_filter_props'],true),'native meta reason explicit');

$unknownProp=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('_tax_query_location_jet'=>array(11),'future_filter_shape'=>'x')));
etg_same(false,$unknownProp['filtered_query_complete'],'unknown future query shapes fail closed');
etg_expect(in_array('unknown_query_prop_future_filter_shape',$unknownProp['unsupported_filter_props'],true),'unknown query property surfaced');

$tooMany=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('tax_query'=>array(array('taxonomy'=>'location_jet','field'=>'slug','terms'=>range(1,25))))));
etg_same(false,$tooMany['filtered_query_complete'],'term cap cannot silently truncate an authoritative presentation query');
etg_expect(in_array('terms_limit_exceeded:location_jet',$tooMany['malformed'],true),'term cap reason explicit');

$deep=array('taxonomy'=>'location_jet','field'=>'term_id','terms'=>array(11));for($i=0;$i<12;$i++){$deep=array('relation'=>'AND',0=>$deep);}
$deepState=$parser->parse(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/','current_query'=>array('tax_query'=>$deep)));
etg_same(false,$deepState['filtered_query_complete'],'deep query cannot be silently dropped');
etg_expect(in_array('query_depth_limit_exceeded',$deepState['malformed'],true),'depth cap reason explicit');

$js=file_get_contents($root.'/assets/js/ajax-filter-state.js');
$endpoint=file_get_contents($root.'/includes/Presentation/AjaxPresentationEndpoint.php');
$endpointCompact=preg_replace('/\s+/','',$endpoint);
$runtime=file_get_contents($root.'/includes/Elementor/DynamicTags/DynamicTagRuntime.php');
$contextBuilder=file_get_contents($root.'/includes/Context/FilterContextBuilder.php');
$imageTag=file_get_contents($root.'/includes/Elementor/DynamicTags/FilterImageTag.php');
$queryIdSource=file_get_contents($root.'/includes/Identifiers/QueryId.php');
$configSource=file_get_contents($root.'/includes/Config/Configuration.php');
$bootstrap=file_get_contents($root.'/includes/Bootstrap.php');
$section=file_get_contents($root.'/includes/Elementor/DynamicTags/TermSectionTag.php');
$publicationPage=file_get_contents($root.'/includes/Admin/PublicationPage.php');
$publicationRegistry=file_get_contents($root.'/includes/SEO/PublicationRegistry.php');
$catalog=file_get_contents($root.'/includes/Presentation/InventoryContentCatalog.php');
$presentation=file_get_contents($root.'/includes/Presentation/PresentationResolver.php');
$preview=file_get_contents($root.'/includes/Elementor/DynamicTags/PreviewContextTrait.php');
foreach(array('boundGroups','restoreAttribute','syncAutoBindings','scheduleKey','data-etg-dfsb-live-section') as $needle){etg_has($needle,$js,'stale/reset hardening: '.$needle);}
etg_has("el.removeAttribute(name)",$js,'href/src created during AJAX is removed on reset');
etg_has('catalogTokenMeta',$endpoint,'REST response types are catalog-driven');
etg_has('short_description|descriptions|short_descriptions',$endpoint,'short-description HTML fallback typing is explicit');
etg_has('catalogCache',$runtime,'Dynamic Tag runtime caches catalog per request');
etg_has('QueryId::normalize($parts[1])',$runtime,'Elementor group uses case-preserving Query ID normalizer');
etg_has('QueryId::normalize( $queryIdRaw )',file_get_contents($root.'/includes/JetSmartFilters/AjaxFilterStateParser.php'),'AJAX parser uses case-preserving Query ID normalizer');
etg_has('QueryId::normalize($queryId)',$contextBuilder,'runtime provider observation uses case-preserving Query ID normalizer');
etg_has("\$out['query_ids'] = \$this->queryIdList",$configSource,'legacy configuration routes query_ids through case-preserving normalizer');
etg_expect(false===strpos($configSource,"\$out['query_ids'] = \$this->keyList"),'legacy query_ids must never use sanitize_key/keyList');
etg_has('QueryId::normalize( $item )',$configSource,'legacy query_ids use shared QueryId contract');
etg_has('MAX_LENGTH = 80',$queryIdSource,'Query ID normalizer remains bounded');
etg_has("'/\\A[A-Za-z0-9_-]+\\z/'",$queryIdSource,'Query ID normalizer rejects unsafe characters instead of rewriting identity');
etg_has('tokenKey',$queryIdSource,'Query ID token representation is collision-safe for case-sensitive identities');
etg_has('QueryId::normalize($route[\'provider_query_id\']??($route[\'query_id\']??\'\'))',$catalog,'presentation catalog preserves provider Query ID case');
etg_has('QueryId::tokenKey($observed)',$presentation,'topology presentation resolves token-safe mixed-case identity');
etg_has("'fallback_image'",$imageTag,'ETG Filter Image exposes a fallback control');
etg_has('Controls_Manager::MEDIA',$imageTag,'ETG Filter Image fallback is an Elementor media control');
etg_has('get_settings_for_display',$imageTag,'dynamic Elementor fallback values are resolved for display');
etg_has('enterImageFallback',$imageTag,'dynamic image fallback has recursion protection');
etg_has('$catalogInventory=new RuntimeInventory',$bootstrap,'presentation catalog uses separate cached topology inventory');
etg_has('$topology->discover(false)',$bootstrap,'presentation catalog does not force Elementor topology rescan');
etg_has('data-etg-dfsb-live-section',$section,'live term section has hidden-state wrapper');
etg_has('hidden="hidden"',$section,'empty live term section starts hidden');
etg_has("QueryId::normalize(wp_unslash((string)\$_POST['route_query_id']))",$publicationPage,'Structured Profile Manager preserves case-sensitive Query IDs');
etg_expect(false===strpos($publicationPage,"route_query_id'])?sanitize_key"),'Structured Profile Manager must never slug-normalize Query IDs');
etg_has("'provider_query_id'=>\$queryId",$publicationPage,'Structured Profile Manager persists explicit provider Query ID identity');
etg_has("QueryId::normalize((\$route['provider_query_id']??'')?:(\$route['query_id']??''))",$publicationRegistry,'publication route resolution preserves case-sensitive Query IDs');
etg_expect(false===strpos($publicationRegistry,"\$queryId=sanitize_key((string)(\$route['query_id']??''))"),'publication singleRoute must not lowercase Query IDs');
etg_expect(false===strpos($publicationRegistry,"':'.sanitize_key((string)\$route['query_id'])"),'publication URL builder must not lowercase Query IDs');
etg_has("':'.\$queryId.'/tax/'",$publicationRegistry,'publication URL builder emits preserved Query ID');
etg_has("return'external_waf_required'",$endpointCompact,'REST rate protection declares external WAF requirement without persistent cache');
etg_has("'rateLimitMode'=>\$this->rateLimitMode()",$endpointCompact,'client receives the active rate-protection boundary');
etg_expect(false===strpos($endpoint,'get_transient(')&&false===strpos($endpoint,'set_transient('),'anonymous REST presentation path must not write per-visitor transients/DB state');
etg_has('wp_using_ext_object_cache',$endpoint,'in-process burst limiter only runs with persistent object cache');
etg_has("null === \$uri ? 'live_request' : 'synthetic_uri'",$contextBuilder,'live server request and synthetic editor evidence are distinguishable');
etg_has("in_array(\$evidenceOrigin,array('live_request','live_ajax'),true)",$contextBuilder,'automatic dark presentation is limited to live request origins');
etg_has("empty(\$profile['enabled'])",$contextBuilder,'automatic dark presentation requires disabled profile');
etg_has("empty(\$scope['global_enabled'])",$contextBuilder,'automatic dark presentation requires Global OFF');
etg_has("'dark_presentation_allowed'=>\$darkPresentationAllowed",$contextBuilder,'dark presentation decision is explicit context evidence');
etg_has("empty(\$e['dark_presentation_allowed'])",$presentation,'server-side resolver consumes derived dark presentation policy');
etg_has("empty(\$context['dark_presentation_allowed'])",$endpointCompact,'AJAX endpoint consumes derived dark presentation policy');
etg_has("'dark_presentation_source'",$endpoint,'AJAX response exposes dark presentation decision source');
etg_has('Editor preview is synthetic',$preview,'Elementor warns that editor preview is not live evidence');
etg_expect(false===strpos($contextBuilder,'update_option(')&&false===strpos($presentation,'update_option(')&&false===strpos($endpoint,'update_option('),'dark presentation policy cannot mutate stored configuration');
etg_expect(false===strpos($js,'history.pushState')&&false===strpos($js,'history.replaceState'),'ETG bridge still does not mutate browser history');

echo "Alpha13 deep-audit regression smoke tests passed.\n";

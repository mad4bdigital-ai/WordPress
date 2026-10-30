<?php
/** Read-only editorial and graph test; never activates SEO. */
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('CLI only');
$base=dirname(__DIR__);
foreach([
 'class-dynamic-qualification.php'=>'DynamicQualification',
 'class-smart-seo-policy-registry.php'=>'SmartSeoPolicyRegistry',
 'class-smart-seo-native-store.php'=>'SmartSeoNativeStore',
 'class-smart-seo-rules.php'=>'SmartSeoRules',
 'class-smart-seo-combinations.php'=>'SmartSeoCombinations',
 'class-smart-seo-content-graph.php'=>'SmartSeoContentGraph',
 'class-smart-seo-link-crawler.php'=>'SmartSeoLinkCrawler',
 'class-smart-seo-content-graph-rest.php'=>'SmartSeoContentGraphRest'
] as $f=>$name)
 if(!class_exists('MAD4B\\DFSB\\NativePresentation\\'.$name,false))
   require_once $base.'/includes/'.$f;
use MAD4B\DFSB\NativePresentation\SmartSeoContentGraph as G;
use MAD4B\DFSB\NativePresentation\SmartSeoLinkCrawler as Crawler;
use MAD4B\DFSB\NativePresentation\SmartSeoCombinations as C;
use MAD4B\DFSB\NativePresentation\SmartSeoContentGraphRest as API;
$checks=[];
$cairo=G::term(123,'destinations');
$aswan=G::term(122,'destinations');
$weekday=G::term(232,'schudle-days-of-weak');
$season=G::term(2736,'season');
$checks['cairo_editorial_hub']=!is_wp_error($cairo)&&$cairo['description_characters']>=180;
$checks['cairo_hierarchy']=!is_wp_error($cairo)&&count($cairo['parents'])>=1;
$checks['aswan_editorial_hub']=!is_wp_error($aswan)&&$aswan['classification']==='editorial_hub_candidate';
$checks['weekday_thin_not_editorial_hub']=!is_wp_error($weekday)&&$weekday['classification']==='thin_term';
$checks['season_private_not_indexable']=!is_wp_error($season)&&$season['classification']==='private_taxonomy';
$src=C::source('jet-engine','tours_query_archive');
$positive=C::inspect($src,[['filter_id'=>15035,'term_id'=>192],['filter_id'=>16204,'term_id'=>123],['filter_id'=>15903,'term_id'=>232],['filter_id'=>15904,'term_id'=>235]],'en');
$negative=C::inspect($src,[['filter_id'=>15035,'term_id'=>193],['filter_id'=>16204,'term_id'=>122],['filter_id'=>66031,'term_id'=>2736],['filter_id'=>15903,'term_id'=>229]],'en');
$graph1=G::combination(['id'=>$positive['id'],'selections'=>$positive['selections'],'result_evidence'=>$positive]);
$graph2=G::combination(['id'=>$negative['id'],'selections'=>$negative['selections'],'result_evidence'=>$negative]);
$checks['positive_commercial_classification']=$graph1['classification']==='commercial_results';
$checks['positive_has_editorial_links']=count($graph1['editorial_hubs'])>0;
$checks['zero_results_points_to_editorial']=$graph2['classification']==='empty_search_with_editorial_hubs';
$checks['empty_commercial_not_indexed']=$graph2['empty_search_indexing']===false;
$crawl=Crawler::crawl(home_url('/'),2,1);
$checks['crawl_first_party_bounded']=count($crawl['pages'])<=2&&$crawl['max_depth']===1;
$checks['crawl_external_blocked']=Crawler::key('https://example.org/')==='';
$nativeBefore=get_option('jet-smart-filters-settings',[]);
rest_get_server();API::register();
wp_set_current_user(0);
$resp=rest_do_request(new WP_REST_Request('GET','/mad4b/v1/seo-intelligence/content-graph/term?id=123'));
$checks['anonymous_graph_blocked']=in_array($resp->get_status(),[401,403],true);
$checks['native_seo_unmodified']=$nativeBefore===get_option('jet-smart-filters-settings',[]);
foreach($checks as $name=>$ok)echo($ok?'PASS ':'FAIL ').$name.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
echo 'CAIRO_EDIT_CHARACTERS='.$cairo['description_characters'].PHP_EOL;
echo 'CAIRO_HIERARCHY_DEPTH='.$cairo['hierarchy_depth'].PHP_EOL;
echo 'CRAWLED_PAGES='.count($crawl['pages']).PHP_EOL;
if(in_array(false,$checks,true))exit(1);

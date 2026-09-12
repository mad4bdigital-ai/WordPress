<?php
declare(strict_types=1);
$GLOBALS['etg_options']=array();
function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['etg_options'])?$GLOBALS['etg_options'][$key]:$default;}
function sanitize_key($key){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key));}
function sanitize_title($title){$title=preg_replace('/[^a-z0-9_\-]+/','-',strtolower(trim((string)$title)));return trim($title,'-');}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function apply_filters($hook,$value){if('wpml_active_languages'===$hook){return array('en'=>array(),'it'=>array(),'ar'=>array());}return $value;}
function expect_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAILED: {$message}\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");exit(1);}}

$base=dirname(__DIR__);
foreach(array('Config/Configuration.php','Config/ProfileRegistry.php','Runtime/RequestScope.php','SEO/CombinationRegistry.php','SEO/IndexingPolicy.php','Simulation/ScenarioSimulator.php') as $file){require_once $base.'/includes/'.$file;}

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Runtime\RequestScope;
use ETG\DynamicFilterSEOBridge\SEO\CombinationRegistry;
use ETG\DynamicFilterSEOBridge\SEO\IndexingPolicy;
use ETG\DynamicFilterSEOBridge\Simulation\ScenarioSimulator;

$profiles=array(
 array(
  'id'=>'properties','enabled'=>true,'post_types'=>array('property'),'require_post_type_binding'=>true,
  'archive_slugs'=>array('properties'),'archive_paths'=>array('/properties/'),'providers'=>array('jet-engine'),'query_ids'=>array('properties_archive'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'properties_archive')),'post_type_authority'=>'query_builder','max_filters'=>3,'composition_mode'=>'generic',
  'allowed_taxonomy_sets'=>array('property_city','property_city+property_type','property_city+property_type+property_feature'),
  'min_results_by_depth'=>array('1'=>5,'2'=>3,'3'=>2),'require_exact_combination_approval'=>true,
  'taxonomy_rules'=>array(
   'property_city'=>array('role'=>'city','priority'=>10,'index_single'=>true,'min_results'=>5,'required_meta_key'=>'market_status','required_meta_values'=>array('active'),'meta_constraint_scope'=>'always'),
   'property_type'=>array('role'=>'type','priority'=>20,'index_single'=>false,'min_results'=>5),
   'property_feature'=>array('role'=>'feature','priority'=>30,'index_single'=>false,'min_results'=>8),
  ),
  'indexable_combinations'=>array('properties|en|property_city=cairo|property_type=apartment'),
  'content'=>array('required'=>true,'require_meta_description'=>true,'min_chars'=>120),
 ),
 array(
  'id'=>'catalog','enabled'=>true,'post_types'=>array('product'),'require_post_type_binding'=>true,
  'archive_slugs'=>array('shop-products'),'archive_paths'=>array('/shop-products/'),'providers'=>array('jet-engine'),'query_ids'=>array('catalog_archive'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'catalog_archive')),'post_type_authority'=>'query_builder','max_filters'=>3,'composition_mode'=>'generic',
  'allowed_taxonomy_sets'=>array('product_cat','brand+product_cat','brand+pa_color+product_cat'),'min_results_by_depth'=>array('1'=>8,'2'=>5,'3'=>4),'require_exact_combination_approval'=>true,
  'taxonomy_rules'=>array('product_cat'=>array('role'=>'category','priority'=>10,'index_single'=>true,'min_results'=>8),'brand'=>array('role'=>'brand','priority'=>20,'index_single'=>false,'min_results'=>10),'pa_color'=>array('role'=>'color','priority'=>30,'index_single'=>false,'min_results'=>10)),
  'indexable_combinations'=>array('catalog|en|brand=sony|product_cat=cameras'),
 ),
 array(
  'id'=>'knowledge','enabled'=>false,'post_types'=>array('article'),'require_post_type_binding'=>true,
  'archive_slugs'=>array('resources'),'archive_paths'=>array('/resources/'),'providers'=>array('jet-engine'),'query_ids'=>array('resources_archive'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'resources_archive')),'post_type_authority'=>'query_builder','max_filters'=>2,'composition_mode'=>'generic',
  'allowed_taxonomy_sets'=>array('topic','audience+topic'),'taxonomy_rules'=>array('topic'=>array('role'=>'topic','priority'=>10,'index_single'=>true,'min_results'=>3),'audience'=>array('role'=>'audience','priority'=>20,'index_single'=>false,'min_results'=>3)),
  'min_results_by_depth'=>array('1'=>3,'2'=>3),'require_exact_combination_approval'=>true,'indexable_combinations'=>array(),
 ),
);
$GLOBALS['etg_options'][Configuration::OPTION_NAME]=array('enabled'=>true,'profiles_json'=>json_encode($profiles),'require_result_count_for_index'=>true);

$config=new Configuration();$registry=new ProfileRegistry($config);$scope=new RequestScope($config,$registry);$combos=new CombinationRegistry($config);$policy=new IndexingPolicy($config);$sim=new ScenarioSimulator($config,$registry,$combos,$policy);
expect_same(false,in_array('brand+property_city',$registry->get('properties')['allowed_taxonomy_sets'],true),'cross-profile taxonomy set cannot survive profile normalization');
$nested=$scope->evaluate(array('active'=>true,'archive'=>'properties','archive_path'=>'/foo/properties/','provider'=>'jet-engine','query_id'=>'properties_archive','filters'=>array('property_city'=>'cairo')));
expect_same(false,$nested['in_scope'],'arbitrary path suffix cannot impersonate an exact archive path');
expect_same('archive_not_profiled',$nested['reason'],'nested suffix collision is outside profile authority');
$translated=$scope->evaluate(array('active'=>true,'archive'=>'properties','archive_path'=>'/it/properties/','provider'=>'jet-engine','query_id'=>'properties_archive','filters'=>array('property_city'=>'cairo')));
expect_same(true,$translated['scope_valid'],'known WPML language prefix may wrap an exact archive authority');
$unicodeProfiles=array(array('id'=>'arabic-books','enabled'=>true,'post_types'=>array('book'),'require_post_type_binding'=>true,'post_type_authority'=>'query_builder','archive_paths'=>array('/ar/كتب/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'books_archive')),'taxonomy_rules'=>array('genre'=>array('role'=>'genre','index_single'=>true,'min_results'=>2)),'allowed_taxonomy_sets'=>array('genre')));
$GLOBALS['etg_options'][Configuration::OPTION_NAME]['profiles_json']=json_encode($unicodeProfiles,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$unicodeConfig=new Configuration();$unicodeRegistry=new ProfileRegistry($unicodeConfig);$unicodeScope=new RequestScope($unicodeConfig,$unicodeRegistry);
$unicodeDecision=$unicodeScope->evaluate(array('active'=>true,'archive'=>'','archive_path'=>'/ar/كتب/','provider'=>'jet-engine','query_id'=>'books_archive','filters'=>array('genre'=>'seo')));
expect_same(true,$unicodeDecision['scope_valid'],'Unicode archive paths remain valid profile authorities');
$GLOBALS['etg_options'][Configuration::OPTION_NAME]=array('enabled'=>true,'profiles_json'=>json_encode($profiles),'require_result_count_for_index'=>true);
$config=new Configuration();$registry=new ProfileRegistry($config);$scope=new RequestScope($config,$registry);$combos=new CombinationRegistry($config);$policy=new IndexingPolicy($config);$sim=new ScenarioSimulator($config,$registry,$combos,$policy);

expect_same(array('brand','pa_color','product_cat','property_city','property_feature','property_type','audience','topic'),array_values(array_unique(array_merge(array_intersect(array('brand','pa_color','product_cat','property_city','property_feature','property_type','audience','topic'),$registry->allowedTaxonomies())))),'registry exposes configured taxonomy growth surface');

$cross=$scope->evaluate(array('active'=>true,'archive'=>'properties','archive_path'=>'/properties/','provider'=>'jet-engine','query_id'=>'catalog_archive','filters'=>array('property_city'=>'cairo')));
expect_same(true,$cross['in_scope'],'known archive stays governed on wrong query');
expect_same(false,$cross['scope_valid'],'cross-profile query mismatch is invalid');
expect_same('query_not_profiled',$cross['reason'],'cross-profile query mismatch reason');

$foreignTax=$scope->evaluate(array('active'=>true,'archive'=>'properties','archive_path'=>'/properties/','provider'=>'jet-engine','query_id'=>'properties_archive','filters'=>array('brand'=>'sony')));
expect_same(true,$foreignTax['in_scope'],'foreign taxonomy on known profile remains governed');
expect_same(false,$foreignTax['scope_valid'],'foreign taxonomy is rejected');
expect_same('taxonomy_not_profiled',$foreignTax['reason'],'foreign taxonomy rejection reason');

$approved=$sim->run(array('profile_id'=>'properties','post_type'=>'property','language'=>'en','filters'=>array('property_city'=>'cairo','property_type'=>'apartment'),'term_meta'=>array('property_city'=>array('market_status'=>'active')),'result_count'=>4,'content_ready'=>true));
expect_same(true,$approved['decision']['index'],'approved real-estate pair indexes above profile threshold');
expect_same('properties',$approved['decision']['profile_id'],'decision remains profile-bound');
expect_same('property_city+property_type',$approved['decision']['taxonomy_set'],'taxonomy set is explicit');

$unapproved=$sim->run(array('profile_id'=>'properties','post_type'=>'property','language'=>'en','filters'=>array('property_city'=>'giza','property_type'=>'villa'),'term_meta'=>array('property_city'=>array('market_status'=>'active')),'result_count'=>20));
expect_same('combination_not_approved',$unapproved['decision']['reason'],'unregistered real-estate combination fails closed');

$wrongPostType=$sim->run(array('profile_id'=>'properties','post_type'=>'product','language'=>'en','filters'=>array('property_city'=>'cairo'),'term_meta'=>array('property_city'=>array('market_status'=>'active')),'result_count'=>20));
expect_same('post_type_mismatch',$wrongPostType['decision']['reason'],'post type cannot bleed across profiles');

$missingPostType=$sim->run(array('profile_id'=>'properties','language'=>'en','filters'=>array('property_city'=>'cairo'),'term_meta'=>array('property_city'=>array('market_status'=>'active')),'result_count'=>20));
expect_same('post_type_unobserved',$missingPostType['decision']['reason'],'required post type binding is fail closed when unobserved');

$badMeta=$sim->run(array('profile_id'=>'properties','post_type'=>'property','language'=>'en','filters'=>array('property_city'=>'cairo'),'term_meta'=>array('property_city'=>array('market_status'=>'paused')),'result_count'=>20));
expect_same('taxonomy_meta_constraint_failed',$badMeta['decision']['reason'],'taxonomy meta constraint blocks inactive market');

$zero=$sim->run(array('profile_id'=>'properties','post_type'=>'property','language'=>'en','filters'=>array('property_city'=>'cairo'),'term_meta'=>array('property_city'=>array('market_status'=>'active')),'result_count'=>0));
expect_same('zero_results',$zero['decision']['reason'],'zero-result page cannot index');

$translation=$sim->run(array('profile_id'=>'properties','post_type'=>'property','language'=>'it','filters'=>array('property_city'=>'cairo'),'term_meta'=>array('property_city'=>array('market_status'=>'active')),'result_count'=>20,'translation_fallback'=>true));
expect_same('translation_fallback',$translation['decision']['reason'],'translation fallback remains hard deny');

$brandOnly=$sim->run(array('profile_id'=>'catalog','post_type'=>'product','language'=>'en','filters'=>array('brand'=>'sony'),'result_count'=>100));
expect_same('taxonomy_set_not_allowlisted',$brandOnly['decision']['reason'],'catalog brand-only shape is structurally denied');

$disabled=$scope->evaluate(array('active'=>true,'archive'=>'resources','archive_path'=>'/resources/','provider'=>'jet-engine','query_id'=>'resources_archive','filters'=>array('topic'=>'seo')));
expect_same(false,$disabled['in_scope'],'disabled growth profile preserves vendor behavior');
expect_same('profile_disabled',$disabled['reason'],'disabled profile is explicit');

$profiles[2]['enabled']=true;$profiles[2]['taxonomy_rules']['format']=array('role'=>'format','priority'=>30,'index_single'=>false,'min_results'=>3);$profiles[2]['allowed_taxonomy_sets'][]='format+topic';$profiles[2]['indexable_combinations'][]='knowledge|en|format=guide|topic=seo';
$GLOBALS['etg_options'][Configuration::OPTION_NAME]['profiles_json']=json_encode($profiles);
$config2=new Configuration();$registry2=new ProfileRegistry($config2);$scope2=new RequestScope($config2,$registry2);
expect_same(true,in_array('format',$registry2->allowedTaxonomies(),true),'new taxonomy becomes discoverable only after explicit profile configuration');
$grown=$scope2->evaluate(array('active'=>true,'archive'=>'resources','archive_path'=>'/resources/','provider'=>'jet-engine','query_id'=>'resources_archive','filters'=>array('topic'=>'seo','format'=>'guide')));
expect_same(true,$grown['in_scope'],'new profile can grow without code changes');
expect_same(true,$grown['scope_valid'],'new taxonomy combination is valid after explicit profile update');

$safeNew=array(array('id'=>'new-surface','post_types'=>array('book'),'require_post_type_binding'=>true,'post_type_authority'=>'query_builder','archive_paths'=>array('/books/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'books_archive')),'taxonomy_rules'=>array('genre'=>array('role'=>'genre','index_single'=>true,'min_results'=>2)),'allowed_taxonomy_sets'=>array('genre')));
$GLOBALS['etg_options'][Configuration::OPTION_NAME]['profiles_json']=json_encode($safeNew);
$registry3=new ProfileRegistry(new Configuration());
expect_same(false,$registry3->get('new-surface')['enabled'],'new profiles default disabled when enabled is omitted');

$duplicate=$safeNew;
$duplicate[0]['enabled']=true;
$duplicate[]=$duplicate[0];
$GLOBALS['etg_options'][Configuration::OPTION_NAME]['profiles_json']=json_encode($duplicate);
$registry4=new ProfileRegistry(new Configuration());
$registry4->all();
expect_same(true,in_array('duplicate_profile_id:new-surface',$registry4->validationErrors(),true),'duplicate profile ids are configuration errors, not silent replacement');

$ambiguous=array(
 array('id'=>'base-props','enabled'=>true,'post_types'=>array('property'),'require_post_type_binding'=>true,'post_type_authority'=>'query_builder','archive_paths'=>array('/properties/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'properties_archive')),'taxonomy_rules'=>array('property_city'=>array('role'=>'city','index_single'=>true,'min_results'=>2)),'allowed_taxonomy_sets'=>array('property_city')),
 array('id'=>'it-props','enabled'=>true,'post_types'=>array('property'),'require_post_type_binding'=>true,'post_type_authority'=>'query_builder','archive_paths'=>array('/it/properties/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'properties_archive')),'taxonomy_rules'=>array('property_city'=>array('role'=>'city','index_single'=>true,'min_results'=>2)),'allowed_taxonomy_sets'=>array('property_city'))
);
$GLOBALS['etg_options'][Configuration::OPTION_NAME]['profiles_json']=json_encode($ambiguous);
$registryAmb=new ProfileRegistry(new Configuration());$errorsAmb=$registryAmb->validationErrors();
$ambFound=false;foreach($errorsAmb as $error){if(0===strpos($error,'ambiguous_profile_match:path:/properties/|jet-engine|properties_archive')){$ambFound=true;break;}}
expect_same(true,$ambFound,'language-prefixed and base archive authorities are detected as ambiguous for the same route');

$legacyLike=array(array('id'=>'unsafe-new','enabled'=>true,'post_types'=>array('book'),'require_post_type_binding'=>true,'archive_slugs'=>array('books'),'providers'=>array('jet-engine'),'query_ids'=>array('books_archive'),'taxonomy_rules'=>array('genre'=>array('role'=>'genre','index_single'=>true,'min_results'=>2)),'allowed_taxonomy_sets'=>array('genre')));
$GLOBALS['etg_options'][Configuration::OPTION_NAME]['profiles_json']=json_encode($legacyLike);
$config5=new Configuration();$registry5=new ProfileRegistry($config5);$errors5=$registry5->validationErrors();
expect_same(true,in_array('profile:unsafe-new:exact_archive_paths_required',$errors5,true),'new profiles require exact archive path authority');
expect_same(true,in_array('profile:unsafe-new:exact_routes_required',$errors5,true),'new profiles require exact route pairs instead of Cartesian fallback');
$scope5=new RequestScope($config5,$registry5);$legacyRoute=$scope5->evaluate(array('active'=>true,'archive'=>'books','archive_path'=>'/books/','provider'=>'jet-engine','query_id'=>'books_archive','filters'=>array('genre'=>'seo')));
expect_same(false,$legacyRoute['in_scope'],'non-inherited profile cannot gain authority from legacy slug/provider/query arrays');

fwrite(STDOUT,"Generic profile runtime simulations passed.
");

<?php
declare(strict_types=1);
$GLOBALS['etg_options']=array();$GLOBALS['etg_force_soft_index']=null;$GLOBALS['etg_content_filter']=null;
function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['etg_options'])?$GLOBALS['etg_options'][$key]:$default;}
function sanitize_key($key){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key));}
function sanitize_title($title){$title=preg_replace('/[^a-z0-9_\-]+/','-',strtolower(trim((string)$title)));return trim($title,'-');}
function sanitize_text_field($text){return trim(strip_tags((string)$text));}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_strip_all_tags($text){return strip_tags((string)$text);}
function wp_kses_post($text){return (string)$text;}
function esc_attr($text){return (string)$text;} function esc_html($text){return (string)$text;}
function absint($value){return abs((int)$value);} function get_bloginfo($key){return 'ETG';}
function home_url($path=''){return 'https://example.com'.$path;} function user_trailingslashit($path){return rtrim($path,'/').'/';}
function add_query_arg($args,$url){return $url.($args?'?'.http_build_query($args):'');} function esc_url_raw($url){return (string)$url;}
function apply_filters($hook,$value){
	if('wpml_active_languages'===$hook){return array('en'=>array(),'it'=>array(),'ar'=>array());}
	if('etg_filter_seo_should_index'===$hook&&null!==$GLOBALS['etg_force_soft_index']){return(bool)$GLOBALS['etg_force_soft_index'];}
	if('etg_filter_seo_content_ready'===$hook&&null!==$GLOBALS['etg_content_filter']){return(bool)$GLOBALS['etg_content_filter'];}
	return $value;
}
function expect_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAILED: {$message}\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");exit(1);}}

$base=dirname(__DIR__);
foreach(array('Config/Configuration.php','Config/ProfileRegistry.php','Runtime/RequestScope.php','SEO/CombinationRegistry.php','SEO/ContentReadiness.php','SEO/IndexingPolicy.php','SEO/CanonicalBuilder.php','Content/GalleryComposer.php','Content/ContentComposer.php','Simulation/ScenarioSimulator.php') as $file){require_once $base.'/includes/'.$file;}

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Runtime\RequestScope;
use ETG\DynamicFilterSEOBridge\SEO\CombinationRegistry;
use ETG\DynamicFilterSEOBridge\SEO\ContentReadiness;
use ETG\DynamicFilterSEOBridge\SEO\IndexingPolicy;
use ETG\DynamicFilterSEOBridge\SEO\CanonicalBuilder;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Simulation\ScenarioSimulator;

$config=new Configuration();
expect_same(false,$config->enabled(),'safe global default is disabled');
$registry=new ProfileRegistry($config);
expect_same(true,(bool)$registry->get('tours'),'default migration profile exists');
expect_same(false,$registry->get('tours')['enabled'],'default migration profile is non-authorizing and disabled');
expect_same('travel',$registry->get('tours')['composition_mode'],'default tours profile preserves travel composition');
expect_same(250,$registry->get('tours')['content']['min_chars'],'default content floor is hardened');
$sanitized=$config->sanitize(array('enabled'=>'0','profiles_json'=>'not-json','diagnostics_enabled'=>'0'));
expect_same(false,$sanitized['enabled'],'unchecked enabled persists false');
expect_same($config->get('profiles_json'),$sanitized['profiles_json'],'invalid profiles JSON preserves last safe/default authority snapshot');

/* Explicitly enable a copied profile for synthetic live-policy tests. */
$migrationProfile=$registry->get('tours');$migrationProfile['enabled']=true;
$GLOBALS['etg_options'][Configuration::OPTION_NAME]=array(
	'enabled'=>true,
	'profiles_json'=>json_encode(array($migrationProfile)),
	'indexable_combinations'=>array('en|location_jet=cairo|tour-types_jet=day-tours'),
	'require_result_count_for_index'=>true
);
$config=new Configuration();$registry=new ProfileRegistry($config);$scope=new RequestScope($config,$registry);
$parsed=array('active'=>true,'archive'=>'tours-and-activities','archive_path'=>'/tours-and-activities/','provider'=>'jet-engine','query_id'=>'tours_query_archive','filters'=>array('location_jet'=>'cairo','tour-types_jet'=>'day-tours'));
$scopeDecision=$scope->evaluate($parsed);
expect_same(true,$scopeDecision['in_scope'],'explicitly enabled configured surface in scope');
expect_same(true,$scopeDecision['scope_valid'],'configured surface profile valid');
expect_same('tours',$scopeDecision['profile_id'],'legacy settings bind to tours profile');

$combos=new CombinationRegistry($config);$profile=$registry->get('tours');
$comboContext=array('profile_id'=>'tours','profile'=>$profile,'language'=>'en','filters'=>$parsed['filters']);
expect_same(false,$combos->evaluate($comboContext)['approved'],'legacy global exact pair cannot synthesize profile-local combination authority');
$profile['indexable_combinations']=array('tours|en|location_jet=cairo|tour-types_jet=day-tours');
$GLOBALS['etg_options'][Configuration::OPTION_NAME]['profiles_json']=json_encode(array($profile));
$config=new Configuration();$registry=new ProfileRegistry($config);$combos=new CombinationRegistry($config);$profile=$registry->get('tours');
$comboContext['profile']=$profile;
expect_same(true,$combos->evaluate($comboContext)['approved'],'explicit profile-local exact pair is approved');

$GLOBALS['etg_options'][Configuration::OPTION_NAME]['index_single_tour_type']=true;
$singleConfig=new Configuration();$singleRegistry=new ProfileRegistry($singleConfig);$singleProfile=$singleRegistry->get('tours');
expect_same(true,$singleProfile['taxonomy_rules']['tour-types_jet']['index_single'],'legacy single tour-type opt-in migrates into taxonomy rule');
expect_same(false,in_array('tour-types_jet',$singleProfile['allowed_taxonomy_sets'],true),'legacy single tour-type opt-in cannot synthesize structural authority');
$explicitSingleProfile=$singleProfile;$explicitSingleProfile['inherit_global_defaults']=false;$explicitSingleProfile['allowed_taxonomy_sets'][]='tour-types_jet';
$GLOBALS['etg_options'][Configuration::OPTION_NAME]['profiles_json']=json_encode(array($explicitSingleProfile));
$explicitSingleRegistry=new ProfileRegistry(new Configuration());
expect_same(true,in_array('tour-types_jet',$explicitSingleRegistry->get('tours')['allowed_taxonomy_sets'],true),'explicit profile taxonomy set grants structural authority');

$GLOBALS['etg_options'][Configuration::OPTION_NAME]['profiles_json']=json_encode(array($profile));
$GLOBALS['etg_options'][Configuration::OPTION_NAME]['index_single_tour_type']=false;
$config=new Configuration();$registry=new ProfileRegistry($config);$scope=new RequestScope($config,$registry);$combos=new CombinationRegistry($config);$profile=$registry->get('tours');

$content=new ContentComposer(new GalleryComposer());
$neutral=array('language'=>'it','profile'=>array('composition_mode'=>'travel'),'terms'=>array('location'=>array('name'=>'Roma'),'tour_type'=>array('name'=>'Tour Giornalieri'),'style'=>array('name'=>'Lusso')));
expect_same('Roma — Tour Giornalieri — Lusso',$content->title($neutral),'non ar/en travel title remains neutral');
$generic=array('language'=>'en','profile'=>array('composition_mode'=>'generic','taxonomy_rules'=>array('brand'=>array('role'=>'brand','priority'=>20),'product_cat'=>array('role'=>'category','priority'=>10))),'terms'=>array('brand'=>array('name'=>'Sony'),'category'=>array('name'=>'Cameras')));
expect_same('Cameras — Sony',$content->title($generic),'generic title follows taxonomy priority');

$archiveProfile=$profile;$archiveProfile['canonical_mode']='archive';
$canonical=(new CanonicalBuilder($config))->build(array('in_scope'=>true,'profile'=>$archiveProfile,'request_path'=>'/it/tours-and-activities/jsf/x/tax/y/','archive_path'=>'/it/tours-and-activities/'));
expect_same('https://example.com/it/tours-and-activities/',$canonical,'archive canonical preserves language-aware path');

$policy=new IndexingPolicy($config);$sim=new ScenarioSimulator($config,$registry,$combos,$policy);
$baseScenario=array('profile_id'=>'tours','language'=>'en','filters'=>array('location_jet'=>'cairo','tour-types_jet'=>'day-tours'),'result_count'=>3,'result_count_authoritative'=>true,'runtime_ready'=>true,'provider_observed'=>true,'provider_match'=>true,'content_ready'=>true);
$decision=$sim->run($baseScenario)['decision'];expect_same(true,$decision['index'],'approved ready tours pair at threshold indexes');
$unobserved=$baseScenario;$unobserved['provider_observed']=false;$decision=$sim->run($unobserved)['decision'];
expect_same('provider_query_unobserved',$decision['reason'],'missing live provider observation fails closed');
$GLOBALS['etg_force_soft_index']=true;$low=$baseScenario;$low['result_count']=2;$decision=$sim->run($low)['decision'];expect_same(false,$decision['index'],'soft filter cannot promote a below-threshold decision');expect_same(false,$decision['base_index'],'below-threshold base decision remains visible');expect_same(false,$decision['override_applied'],'blocked soft promotion does not become authority');
$GLOBALS['etg_force_soft_index']=false;$decision=$sim->run($baseScenario)['decision'];expect_same(false,$decision['index'],'soft filter may veto an otherwise indexable decision');expect_same(true,$decision['base_index'],'veto preserves the original positive base decision');expect_same(true,$decision['override_applied'],'soft veto is explicit');
$hard=$baseScenario;$hard['unknown_filters']=array('bad'=>'x');$decision=$sim->run($hard)['decision'];expect_same(false,$decision['index'],'hard deny cannot be overridden');expect_same('hard',$decision['policy_class'],'hard classification remains explicit');
$GLOBALS['etg_force_soft_index']=null;

$contentGate=new ContentReadiness($config);
$profileContent=$profile;$profileContent['content']=array('required'=>true,'require_meta_description'=>true,'min_chars'=>40);
$ready=$contentGate->evaluate(array('profile'=>$profileContent,'combo'=>array('title'=>'Cairo Tours','meta_description'=>'Useful Cairo tours description.','intro'=>str_repeat('Useful content ',5)),'terms'=>array()));
expect_same(true,$ready['ready'],'profile-specific content readiness passes complete copy');
$thin=$contentGate->evaluate(array('profile'=>$profileContent,'combo'=>array('title'=>'Cairo','meta_description'=>'','intro'=>'short'),'terms'=>array()));
expect_same(false,$thin['ready'],'profile-specific thin content fails readiness');
$dedupeProfile=$profileContent;$dedupeProfile['content']['min_chars']=50;
$repeated='Same repeated term text only';
$deduped=$contentGate->evaluate(array('profile'=>$dedupeProfile,'combo'=>array('title'=>'Cairo','meta_description'=>'Meta present','intro'=>$repeated),'terms'=>array('location'=>array('short_description'=>$repeated,'description'=>$repeated))));
expect_same(false,$deduped['ready'],'duplicate term copy is counted once');
expect_same(1,$deduped['unique_segments'],'readiness reports unique content segments');
$GLOBALS['etg_content_filter']=true;
$promoteAttempt=$contentGate->evaluate(array('profile'=>$profileContent,'combo'=>array('title'=>'Cairo','meta_description'=>'','intro'=>'short'),'terms'=>array()));
expect_same(false,$promoteAttempt['ready'],'content filter cannot promote base failure');
expect_same('attempted_promote_blocked',$promoteAttempt['override_direction'],'blocked promotion is explicit');
$GLOBALS['etg_content_filter']=false;
$veto=$contentGate->evaluate(array('profile'=>$profileContent,'combo'=>array('title'=>'Cairo','meta_description'=>'Meta present','intro'=>str_repeat('Useful content ',5)),'terms'=>array()));
expect_same(false,$veto['ready'],'content filter may veto ready page');
expect_same('veto',$veto['override_direction'],'content veto direction is explicit');
$GLOBALS['etg_content_filter']=null;

$depthProfile=$profile;
$depthProfile['content']=array('required'=>true,'require_meta_description'=>false,'min_chars'=>10,'min_chars_by_depth'=>array('2'=>20),'min_unique_segments_by_depth'=>array('2'=>2));
$depth=$contentGate->evaluate(array('profile'=>$depthProfile,'filters'=>array('a'=>'x','b'=>'y'),'combo'=>array('title'=>'Pair'),'terms'=>array('a'=>array('description'=>'first sufficient segment'),'b'=>array('description'=>'second sufficient segment'))));
expect_same(true,$depth['ready'],'depth-aware unique-section gate passes with two distinct sections');
expect_same(2,$depth['minimum_unique_segments'],'depth-aware section minimum is visible');

fwrite(STDOUT,"Operational profile hardening smoke tests passed.\n");

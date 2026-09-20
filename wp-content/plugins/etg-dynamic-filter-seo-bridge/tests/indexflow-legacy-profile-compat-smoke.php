<?php
declare(strict_types=1);
$GLOBALS['etg_options']=array();
function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['etg_options'])?$GLOBALS['etg_options'][$key]:$default;}
function sanitize_key($key){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key));}
function sanitize_title($title){$title=preg_replace('/[^a-z0-9_\-]+/','-',strtolower(trim((string)$title)));return trim($title,'-');}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function apply_filters($hook,$value){if('etg_filter_seo_language_codes'===$hook){return array('en','it','ar');}return $value;}
function need($c,$m){if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$base=dirname(__DIR__);
require_once $base.'/includes/Config/Configuration.php';
require_once $base.'/includes/Config/ProfileRegistry.php';
require_once $base.'/includes/Runtime/RequestScope.php';
use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Runtime\RequestScope;
$profiles=array(array(
 'id'=>'properties','enabled'=>true,'post_types'=>array('property'),'require_post_type_binding'=>true,'post_type_authority'=>'query_builder',
 'archive_paths'=>array('/properties/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'properties_archive')),
 'taxonomy_rules'=>array('property_city'=>array('role'=>'city','index_single'=>true,'min_results'=>2)),
 'allowed_taxonomy_sets'=>array('property_city'),'required_capabilities'=>array(),
));
$GLOBALS['etg_options'][Configuration::OPTION_NAME]=array('enabled'=>true,'profiles_json'=>json_encode($profiles));
$config=new Configuration();
need($config->get('compatibility_profile','')==='','generic legacy must not inherit ETG fingerprint');
$registry=new ProfileRegistry($config);
need($registry->get('properties')['enabled']===true,'generic profile preserved');
$scope=new RequestScope($config,$registry);
$ok=$scope->evaluate(array('active'=>true,'archive'=>'properties','archive_path'=>'/properties/','provider'=>'jet-engine','query_id'=>'properties_archive','filters'=>array('property_city'=>'cairo')));
need($ok['scope_valid']===true,'generic exact route remains valid');
$translated=$scope->evaluate(array('active'=>true,'archive'=>'properties','archive_path'=>'/it/properties/','provider'=>'jet-engine','query_id'=>'properties_archive','filters'=>array('property_city'=>'cairo')));
need($translated['scope_valid']===true,'known language prefix may wrap exact authority');
$nested=$scope->evaluate(array('active'=>true,'archive'=>'properties','archive_path'=>'/foo/properties/','provider'=>'jet-engine','query_id'=>'properties_archive','filters'=>array('property_city'=>'cairo')));
need($nested['in_scope']===false,'arbitrary prefix must not impersonate authority');
echo "INDEXFLOW_LEGACY_PROFILE_COMPAT=PASS\n";

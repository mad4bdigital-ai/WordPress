<?php
declare(strict_types=1);
$GLOBALS['etg_options']=array();
function sanitize_key($key){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key));}
function sanitize_title($title){$title=preg_replace('/[^a-z0-9_\-]+/','-',strtolower(trim((string)$title)));return trim($title,'-');}
function get_option($key,$default=false){return $GLOBALS['etg_options'][$key]??$default;}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function apply_filters($hook,$value){return $value;}
function get_post_types($args=array(),$output='names'){return array('property'=>(object)array('label'=>'Properties'),'product'=>(object)array('label'=>'Products'));}
function get_taxonomies($args=array(),$output='names'){return array(
 'property_city'=>(object)array('label'=>'Cities','object_type'=>array('property')),
 'property_type'=>(object)array('label'=>'Property Types','object_type'=>array('property')),
 'brand'=>(object)array('label'=>'Brands','object_type'=>array('product')),
);}
function expect_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAILED: {$message}\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");exit(1);}}
$base=dirname(__DIR__);require_once $base.'/includes/Config/Configuration.php';require_once $base.'/includes/Config/ProfileRegistry.php';
use ETG\DynamicFilterSEOBridge\Config\Configuration;use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
$registry=new ProfileRegistry(new Configuration());
$draft=$registry->blueprint('property',array('property_city','property_type','brand'));
expect_same(true,$draft['synthetic'],'blueprint is synthetic');
expect_same(false,$draft['authorizing'],'blueprint cannot authorize indexing');
expect_same(false,$draft['profile']['enabled'],'generated profile starts disabled');
expect_same('query_builder',$draft['profile']['post_type_authority'],'safe query-builder post-type authority is scaffolded');
expect_same(array('property_city','property_type'),array_keys($draft['profile']['taxonomy_rules']),'only taxonomies attached to chosen Post Type are scaffolded');
expect_same(true,in_array('taxonomy_not_attached:brand',$draft['warnings'],true),'foreign taxonomy is warned and omitted');
expect_same(array(),$draft['profile']['routes'],'route authority remains intentionally empty');
expect_same(array(),$draft['profile']['archive_paths'],'archive authority remains intentionally empty');
expect_same(array(),$draft['profile']['allowed_taxonomy_sets'],'taxonomy shape authority remains intentionally empty');
fwrite(STDOUT,"Disabled profile blueprint smoke tests passed.\n");

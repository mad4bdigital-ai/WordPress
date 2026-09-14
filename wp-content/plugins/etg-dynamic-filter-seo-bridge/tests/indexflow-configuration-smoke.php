<?php
$GLOBALS['opt']=array();
$GLOBALS['filter_mutator']=null;
function get_option($name,$default=array()){return ConfigurationOptionStore($default);}
function ConfigurationOptionStore($default){return is_array($GLOBALS['opt'])?$GLOBALS['opt']:$default;}
function apply_filters($tag,$value){if('etg_filter_seo_configuration'===$tag && is_callable($GLOBALS['filter_mutator']))return call_user_func($GLOBALS['filter_mutator'],$value);return $value;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower(trim((string)$v)))?:'';}
function sanitize_title($v){$v=strtolower(trim((string)$v));$v=preg_replace('/[^a-z0-9_\-]+/','-',$v);return trim($v,'-');}
require dirname(__DIR__) . '/includes/Config/Configuration.php';
use ETG\DynamicFilterSEOBridge\Config\Configuration;
function need($cond,$msg){if(!$cond){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
function setopt($v){$GLOBALS['opt']=$v;}
$c=new Configuration();

setopt([]);$a=$c->all();need($a['enabled']===false,'fresh off');need($a['profiles_json']==='[]','fresh profiles empty');need($a['compatibility_profile']==='','fresh no compat');need($a['providers']===[]&&$a['query_ids']===[],'fresh no vendor authority');need($c->validationErrors()===[],'fresh valid inert');

setopt(['schema_version'=>1,'migration_state'=>'current','enabled'=>true,'profiles_json'=>'[]']);need(in_array('profiles_empty_or_invalid',$c->validationErrors(),true),'global on zero profiles fail closed');

setopt(['enabled'=>false,'archive_slugs'=>['tours-and-activities'],'providers'=>['jet-engine'],'query_ids'=>['tours_query_archive'],'allowed_taxonomies'=>['location_jet','tour-types_jet','tour-styles_jet']]);$a=$c->all();need($a['compatibility_profile']==='alpha13','recognized etg compat');need(strpos($a['profiles_json'],'"id":"tours"')!==false,'recognized etg tours projection');

$generic='[{"id":"catalog","enabled":false,"composition_mode":"generic","providers":[],"query_ids":[],"taxonomy_rules":{}}]';
setopt(['enabled'=>false,'profiles_json'=>$generic]);$a=$c->all();need($a['compatibility_profile']==='','generic legacy no etg fingerprint');need(strpos($a['profiles_json'],'catalog')!==false,'generic legacy preserved');

setopt(['enabled'=>true,'diagnostics_enabled'=>true]);$a=$c->all();need($a['enabled']===false,'ambiguous forced off');need($a['migration_state']==='legacy_configuration_review_required','ambiguous review');need($a['compatibility_profile']==='','ambiguous no etg fingerprint');
$stored=$c->sanitizeForStorage(['enabled'=>false,'profiles_json'=>$generic,'migration_resolution'=>'preserve_generic']);need($stored['enabled']===false,'resolution supports global off');need($stored['migration_state']==='current'&&$stored['compatibility_profile']==='','generic explicit resolution');need(strpos($stored['profiles_json'],'catalog')!==false,'resolved generic profile retained');
$etg=$c->sanitizeForStorage(['enabled'=>false,'migration_resolution'=>'use_alpha13']);need($etg['migration_state']==='current'&&$etg['compatibility_profile']==='alpha13','explicit etg resolution');need(strpos($etg['profiles_json'],'"id":"tours"')!==false,'explicit etg tours restored');

$future=['schema_version'=>99,'migration_state'=>'current','compatibility_profile'=>'','data_retention'=>'delete_on_uninstall','enabled'=>true,'profiles_json'=>$generic,'custom_future_key'=>'keep'];setopt($future);$a=$c->all();need($a['schema_version']===99,'future schema retained at runtime');need($a['enabled']===false&&$a['migration_state']==='future_schema_unsupported','future runtime fail closed');$saved=$c->sanitizeForStorage(['enabled'=>false,'profiles_json'=>'[]']);need($saved===$future,'future storage immutable');
$GLOBALS['filter_mutator']=static function($cfg){$cfg['schema_version']=1;$cfg['migration_state']='current';$cfg['compatibility_profile']='alpha13';$cfg['data_retention']='preserve';$cfg['enabled']=true;return $cfg;};$a=$c->all();need($a['schema_version']===99&&$a['migration_state']==='future_schema_unsupported'&&$a['enabled']===false,'filter cannot downgrade future lifecycle');need($a['data_retention']==='delete_on_uninstall','filter cannot change retention lifecycle');

echo "CONFIG_MIGRATION_MATRIX=PASS\n";

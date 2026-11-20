<?php
/** First-party Skill Provider reconciliation source admission; no managed file writes. */
define('ABSPATH',__DIR__.'/');
define('MAD4B_SCP_DIR',dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/');
class WP_Error{function __construct($code,$msg='',$data=[]){}}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function apply_filters($n,$v){return $v;}
function get_option($key,$default=[]){return ['discovery_version'=>3,'state'=>'ready'];}
function skill_check($ok,$reason){if(!$ok){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}$GLOBALS['n']=($GLOBALS['n']??0)+1;}
class MAD4B_SCP_Site_Profile {
 static $origin=true;
 static function origin_enrolled(){return self::$origin;}
}
class MAD4B_SCP_Skill_Registry {
 static $calls=0,$enabled=false;
 static function catalog_enabled(){self::$calls++;return self::$enabled;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-skill-provider-discovery.php';
$c='MAD4B_SCP_Skill_Provider_Discovery';
$status=$c::status();
skill_check(is_array($status)&&$status['state']==='pending'
 &&$status['current_request_observed']===false
 &&$status['previous_persisted_ready']===true,
 'Persisted ready cannot become current request Skill discovery authority');
$catalog=$c::catalog();
skill_check(is_array($catalog)&&isset($catalog['packs']),
 'Original provider Skill catalog loads bounded source only');
foreach([new WP_Error('origin_unknown'),1,'true',null,false] as $bad){
 MAD4B_SCP_Site_Profile::$origin=$bad;
 MAD4B_SCP_Skill_Registry::$calls=0;
 $result=$c::reconcile();
 skill_check(is_array($result)&&$result['state']==='site_profile_not_enrolled'
  &&MAD4B_SCP_Skill_Registry::$calls===0,
  'Unknown site enrollment must not access Skill catalog or create a managed Skill');
}
MAD4B_SCP_Site_Profile::$origin=true;
MAD4B_SCP_Skill_Registry::$calls=0;
$denied=$c::reconcile();
skill_check(is_array($denied)&&$denied['state']==='company_skill_catalog_not_enrolled'
 &&MAD4B_SCP_Skill_Registry::$calls===1,
 'Actual explicit original company Skill catalog opt-out remains respected');
echo 'PASS: SKILL_PROVIDER_SOURCE_ORIGINAL '.($GLOBALS['n']??0).' catalog/readiness and zero-write enrollment admission cases'.PHP_EOL;

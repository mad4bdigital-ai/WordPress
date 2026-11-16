<?php
/**
 * Source-owned Skill seed scheduler test. Never reads/writes Skill files or DB.
 * Proves deduplication and deny-first exact enrollment at the worker boundary.
 */
define('ABSPATH',__DIR__.'/');
define('MAD4B_SCP_DIR',dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/');
$GLOBALS['skill_catalog_enabled']=false;
$GLOBALS['skill_host_enrolled']=false;
$GLOBALS['skill_site_urls_valid']=false;
$GLOBALS['skill_scheduled']=array();
$GLOBALS['skill_seed_file_writes']=0;
function add_action($name,$callback,$priority=10,$args=1){}
function wp_next_scheduled($name) {return isset($GLOBALS['skill_scheduled'][$name]);}
function wp_schedule_single_event($when,$hook,$args=array()){
 if(isset($GLOBALS['skill_scheduled'][$hook]))throw new RuntimeException('Duplicate skill worker');
 $GLOBALS['skill_scheduled'][$hook]=$when;
 return true;
}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value));}
class MAD4B_SCP_Skill_Registry {
 public static function catalog_enabled(){return $GLOBALS['skill_catalog_enabled'];}
}
class MAD4B_SCP_Site_Profile {
 public static function origin_enrolled(){return $GLOBALS['skill_host_enrolled'];}
 public static function site_urls_match_enrollment(){return $GLOBALS['skill_site_urls_valid'];}
}
function skill_assert($cond,$why){
 if(!$cond)throw new RuntimeException('FAIL SKILL SEED CRON: '.$why);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-skill-seeder.php';
$c='MAD4B_SCP_Skill_Seeder';
$c::schedule_after_profile_saved();
skill_assert(!$GLOBALS['skill_scheduled'],'Catalog-disabled company must not schedule seed');
$GLOBALS['skill_catalog_enabled']=true;
$c::schedule_after_profile_saved();
$c::schedule_after_profile_saved();
skill_assert(count($GLOBALS['skill_scheduled'])===1
 && isset($GLOBALS['skill_scheduled']['mad4b_scp_auto_skill_catalog_reconcile']),
 'Exact source-owned worker must be scheduled only once');
$denied=$c::auto_reconcile_enrolled();
skill_assert(is_array($denied)
 && $denied['state']==='site_identity_not_enrolled'
 && $denied['production_auto_seed']===false
 && $denied['overwrites_user_owned']===false,
 'Worker without enrolled exact Site Profile must deny before Skills I/O');
$GLOBALS['skill_host_enrolled']=true;
$wrong=$c::auto_reconcile_enrolled();
skill_assert(is_array($wrong)
 && $wrong['state']==='site_identity_not_enrolled',
 'Enrolled UUID alone cannot bypass source origin mismatch');
skill_assert($GLOBALS['skill_seed_file_writes']===0,
 'No write allowed before verified same-origin Site Profile');
echo "NATIVE_SKILL_SEED_WORKER_GUARD: PASS deduplicated Cron, exact enrollment gate\n";
// Exact terminal assertion marker after every negative enrollment check.
echo "NATIVE_SKILL_SEED_WORKER_GUARD: PASS\n";

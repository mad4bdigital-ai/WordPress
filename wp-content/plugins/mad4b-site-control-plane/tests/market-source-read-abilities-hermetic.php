<?php
/** Network-free, nonauthorizing read Ability regression for Site Market Profiles + Source Hub. */
define('ABSPATH', __DIR__.'/');
$GLOBALS['fake_env']='local';
$GLOBALS['fake_abilities']=[];
$GLOBALS['fake_writes']=0;
$GLOBALS['fake_options']=[
 'icl_sitepress_settings'=>[],
 'mad4b_scp_market_profiles_v1'=>[
   'eg-en'=>['contract'=>'mad4b.market-profiles.v1','profile_id'=>'eg-en','site_uuid'=>'123e4567-e89b-12d3-a456-426614174000','environment'=>'local','label'=>'Egypt English','country'=>'EG','language'=>'en','status'=>'approved','revision'=>2,'approved_revision'=>2,'approved_at'=>'2026-10-25'],
   'us-en-bad'=>['contract'=>'mad4b.market-profiles.v1','profile_id'=>'us-en-bad','site_uuid'=>'bad-site','environment'=>'local','label'=>'Foreign','country'=>'US','language'=>'en','status'=>'approved','revision'=>1,'approved_revision'=>1,'approved_at'=>'2026-10-25']],
 'mad4b_scp_source_hub_sources_v1'=>['a'=>['state'=>'draft'], 'b'=>['state'=>'approved']],
 'mad4b_scp_source_hub_items_v1'=>[['id'=>'pending']],
];
class WP_Error {private $code;function __construct($c,$msg=''){$this->code=$c;}function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_option($k,$fallback=[]){return $GLOBALS['fake_options'][$k]??$fallback;}
function update_option($k,$value,$autoload=false){$GLOBALS['fake_writes']++;return false;}
function wp_get_environment_type(){return $GLOBALS['fake_env'];}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function get_locale(){return 'en_US';}
function wp_next_scheduled($name){return false;}
function wp_has_ability($name){return isset($GLOBALS['fake_abilities'][$name]);}
function wp_register_ability($name,$args){$GLOBALS['fake_abilities'][$name]=$args;return true;}
class MAD4B_SCP_Policy{static function can_read(){return false;}}
class MAD4B_SCP_Site_Profile {
 static function status(){return ['configured'=>true,'wordpress_profile_mismatch'=>false];}
 static function profile(){return ['site_uuid'=>'123e4567-e89b-12d3-a456-426614174000','environment'=>'local'];}
}
class MAD4B_SCP_Context_Authority {static function status(){return ['state'=>'review_required'];}}
require dirname(__DIR__).'/includes/class-mad4b-scp-market-profiles.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-source-hub.php';
$n=0;
function check($what,$ok){global $n;if(!$ok){fwrite(STDERR,'FAIL '.$what.PHP_EOL);exit(1);}$n++;}
MAD4B_SCP_Market_Profiles::register_ability();
MAD4B_SCP_Source_Hub::register_ability();
check('both abilities registered',count($GLOBALS['fake_abilities'])===2);
foreach (['mad4b/market-profiles-read'=>'MAD4B_SCP_Market_Profiles','mad4b/source-hub-status'=>'MAD4B_SCP_Source_Hub'] as $name=>$class) {
 $def=$GLOBALS['fake_abilities'][$name];
 check($name.' original callback', $def['execute_callback'][0]===$class);
 check($name.' original Policy denial', $def['permission_callback']===['MAD4B_SCP_Policy','can_read'] && !call_user_func($def['permission_callback']));
 check($name.' no public MCP exposure', $def['meta']['public']===false && $def['meta']['show_in_rest']===false && $def['meta']['mcp']['public']===false && $def['meta']['mcp']['surface']==='read');
 check($name.' strictly readonly annotation', $def['meta']['annotations']['readonly']===true && $def['meta']['annotations']['destructive']===false);
}
MAD4B_SCP_Market_Profiles::register_ability();
MAD4B_SCP_Source_Hub::register_ability();
check('duplicate ability registrations skipped',count($GLOBALS['fake_abilities'])===2);
$read=MAD4B_SCP_Market_Profiles::read_ability(['country'=>'EG','language'=>'en']);
check('market only scoped approved',is_array($read) && count($read['profiles'])===1 && $read['profiles'][0]['profile_id']==='eg-en');
check('market source read only', $read['read_only']===true && $read['site_bound']===true);
check('foreign site profile rejected',count(MAD4B_SCP_Market_Profiles::approved_list())===1);
check('invalid ISO country rejected',is_wp_error(MAD4B_SCP_Market_Profiles::read_ability(['country'=>'E1'])) );
check('unknown active language rejected',is_wp_error(MAD4B_SCP_Market_Profiles::read_ability(['language'=>'xx'])));
check('language scoped filtering', count(MAD4B_SCP_Market_Profiles::read_ability(['language'=>'en'])['profiles'])===1);
check('country mismatch empty',count(MAD4B_SCP_Market_Profiles::read_ability(['country'=>'US'])['profiles'])===0);
$hub=call_user_func($GLOBALS['fake_abilities']['mad4b/source-hub-status']['execute_callback']);
check('hub safe counters', $hub['sources']===2 && $hub['approved_sources']===1 && $hub['pending_items']===1);
check('hub no automatic approvals/publish', $hub['automatic_approval']===false && $hub['auto_publish']===false);
check('hub transport boundary', $hub['worker_transport']==='site_bound_private_host_spool');
$GLOBALS['fake_env']='production';
check('cross-env profile fails closed', MAD4B_SCP_Market_Profiles::site_uuid()==='' && MAD4B_SCP_Market_Profiles::approved_list()===[]);
$other=MAD4B_SCP_Source_Hub::status();
check('cross-env source registry not bound', $other['site_bound']===false);
check('no option mutations or network operations',$GLOBALS['fake_writes']===0);
echo 'PASS: '.$n.' original-owner read-ability/security checks'.PHP_EOL;

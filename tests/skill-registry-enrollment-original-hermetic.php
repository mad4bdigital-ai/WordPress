<?php
/** Original first-party Skill Registry enrollment and editor gate; never writes disk. */
define('ABSPATH',__DIR__.'/');
class WP_Error{function __construct($code,$msg='',$data=[]){}}
class MAD4B_SCP_Site_Profile{
 static $enrolled=true,$urls=true;
 static function origin_enrolled(){return self::$enrolled;}
 static function site_urls_match_enrollment(){return self::$urls;}
}
function skills_check($yes,$why){if(!$yes){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-skill-registry.php';
$c='MAD4B_SCP_Skill_Registry';
skills_check($c::catalog_enabled()===true,
 'Enrolled first-party WordPress site retains read-only Company Skill catalog');
foreach(['enrolled','urls'] as $field){
 foreach([new WP_Error('site_unknown'),1,'true',null,false] as $bad){
  MAD4B_SCP_Site_Profile::$$field=$bad;
  skills_check($c::catalog_enabled()===false,
   'Error-shaped Site Profile enrollment cannot enable company Skill catalog');
 }
 MAD4B_SCP_Site_Profile::$$field=true;
}
skills_check($c::editor_enabled()===false&&$c::scripts_enabled()===false,
 'Skill Editor and execution scripts do not start from catalog enrollment');
skills_check($c::levels()===['site','connection','provider','adapter','workflow'],
 'Existing native skill ownership hierarchy is preserved');
echo 'PASS: SKILL_REGISTRY_ENROLLMENT_ORIGINAL '.($GLOBALS['checks']??0).' source enrollment/editor/script conditions'.PHP_EOL;

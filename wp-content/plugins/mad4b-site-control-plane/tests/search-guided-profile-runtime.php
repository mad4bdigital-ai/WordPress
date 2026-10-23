<?php
/** Execute guided market/location save against isolated fake Feature 007 services.
 * This tests real admin action dispatch without a live WordPress mutation.
 */
define('ABSPATH', '/');
class WP_Error { private $code; function __construct($c,$m=''){ $this->code=$c; } function get_error_code(){return $this->code;} }
function is_wp_error($x){return $x instanceof WP_Error;}
class MAD4B_SCP_Search_Contracts {
 static function error($code,$message=''){return new WP_Error($code,$message);}
 static function id($x){return is_string($x)&&preg_match('/^[a-zA-Z0-9_-]{1,96}$/D',$x);}
}
class MAD4B_SCP_Search_Providers {
 static function adapters(){return array('serpapi'=>new stdClass(),'dataforseo'=>new stdClass());}
}
class MAD4B_SCP_Search_Context {
 static $profile=array();
 static function profile($id){return $id==='are-search-main'?self::$profile:new WP_Error('not_found');}
 static function policy(){return array('profile_fields'=>array('profile_id','revision','profile_sha256','enabled','markets','provider_policy','language_policy','objective','brand_id'));}
}
class MAD4B_SCP_Search_Runtime {
 static $last=null;
 static function can_configure(){return true;}
 static function profile_plan($args){
  self::$last=$args;
  $p=$args['profile'];$p['profile_sha256']=hash('sha256',serialize($p));return array('profile'=>$p,'plan_sha256'=>hash('sha256',serialize($p)));
 }
 static function profile_apply($args){$p=self::$last['profile'];$p['profile_sha256']=hash('sha256',serialize($p));self::$last['saved']=$p;return array('profile'=>$p);}
 static function profile_verify($args){return array('valid'=>true,'profile_sha256'=>self::$last['saved']['profile_sha256']);}
}
class MAD4B_SCP_Admin_Experience {}
require dirname(__DIR__).'/includes/search/class-mad4b-scp-search-profile-admin.php';
function base_profile(){
 return array('profile_id'=>'are-search-main','revision'=>1,'profile_sha256'=>str_repeat('a',64),
  'enabled'=>false,'markets'=>array(array('id'=>'market-us','country'=>'US')),
  'provider_policy'=>array('allowed'=>array('serpapi'),'freeze_spend'=>true),
  'language_policy'=>array('desired'=>array('en')),'objective'=>'coverage','brand_id'=>'');
}
function execute_form($operation,$changes=array()){
 return MAD4B_SCP_Search_Profile_Admin::save(array_merge(array(
  'operation'=>$operation,'profile_id'=>'are-search-main','expected_revision'=>'1'),$changes));
}
function expect_error($got,$code){if(!is_wp_error($got)||$got->get_error_code()!==$code){fwrite(STDERR,"Expected $code\n");exit(1);}}
MAD4B_SCP_Search_Context::$profile=base_profile();
$ok=execute_form('map_provider_location',array('market_id'=>'market-us','provider_id'=>'serpapi','location_id'=>'United States','location_precision'=>'country'));
expect_error($ok,'provider_location_invalid');
$bad=execute_form('map_provider_location',array('market_id'=>'market-us','provider_id'=>'dataforseo','location_id'=>'United States','location_precision'=>'country'));
expect_error($bad,'provider_location_invalid');
$ok=execute_form('map_provider_location',array('market_id'=>'market-us','provider_id'=>'fake','location_id'=>'2840','location_precision'=>'country'));
expect_error($ok,'provider_location_invalid');
$ok=execute_form('map_provider_location',array('market_id'=>'market-unknown','provider_id'=>'serpapi','location_id'=>'2840','location_precision'=>'country'));
expect_error($ok,'market_missing');
$ok=execute_form('map_provider_location',array('market_id'=>'market-us','provider_id'=>'dataforseo','location_id'=>'2840','location_precision'=>'country'));
if(is_wp_error($ok)||!isset(MAD4B_SCP_Search_Runtime::$last['saved']['markets'][0]['provider_locations']['dataforseo']['id'])||MAD4B_SCP_Search_Runtime::$last['saved']['markets'][0]['provider_locations']['dataforseo']['id']!=='2840'){fwrite(STDERR,"Expected successful location mapping\n");exit(1);}
MAD4B_SCP_Search_Context::$profile['enabled']=true;
expect_error(execute_form('map_provider_location',array('market_id'=>'market-us','provider_id'=>'serpapi','location_id'=>'us','location_precision'=>'country')),'profile_targeting_requires_pause_and_spend_freeze');
MAD4B_SCP_Search_Context::$profile=base_profile();
$added=execute_form('add_market',array('market_country'=>'GB'));
if(is_wp_error($added)||count(MAD4B_SCP_Search_Runtime::$last['saved']['markets'])!==2){fwrite(STDERR,"Expected safe second market\n");exit(1);}
expect_error(execute_form('add_market',array('market_country'=>'US')),'market_duplicate_country');
echo "PASS: guided location and market actions verified against fake runtime, including invalid IDs and spend freeze\n";

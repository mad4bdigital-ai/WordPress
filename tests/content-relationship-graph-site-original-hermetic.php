<?php
/** Original WPML ContentJob site identity contract; no WPDB read or write. */
define('ABSPATH',__DIR__.'/');
class WP_Error{function __construct($code,$msg='',$data=[]){}}
function wp_get_environment_type(){return 'production';}
class MAD4B_SCP_Site_Profile {
 static $status=['site_uuid'=>'11111111-1111-4111-8111-111111111111',
  'origin_match'=>true,'authority_ready'=>true,'deployment_binding_match'=>true,
  'environment'=>'staging'];
 static function status(){return self::$status;}
}
function graph_check($ok,$why){if(!$ok){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-relationship-graph.php';
$c='MAD4B_SCP_Content_Relationship_Graph';
$site=new ReflectionMethod($c,'site');if(PHP_VERSION_ID<80100)$site->setAccessible(true);
$live=$site->invoke(null);
graph_check(($live['site_uuid']??null)==='11111111-1111-4111-8111-111111111111'
 &&($live['environment']??null)==='staging',
 'Native Site Profile effective staging must not be overwritten by implicit WordPress production');
foreach(['site_uuid','origin_match','authority_ready','deployment_binding_match','environment'] as $field){
 foreach([new WP_Error('site_unverified'),1,'true',null,false] as $bad){
  MAD4B_SCP_Site_Profile::$status=['site_uuid'=>'11111111-1111-4111-8111-111111111111',
   'origin_match'=>true,'authority_ready'=>true,'deployment_binding_match'=>true,
   'environment'=>'staging'];
  MAD4B_SCP_Site_Profile::$status[$field]=$bad;
  graph_check($site->invoke(null)===[],
   'Unverified Site Profile element cannot create translated group source identity');
 }
}
echo 'PASS: CONTENT_RELATIONSHIP_GRAPH_SITE_ORIGINAL '.($GLOBALS['checks']??0).
 ' original WPML source and effective environment denial cases'.PHP_EOL;

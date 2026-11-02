<?php
declare(strict_types=1);
define('ABSPATH','/');
define('WP_CLI',false);
$GLOBALS['env']='staging';
$GLOBALS['origin']='https://example-company.test';
$GLOBALS['uuid']='aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$GLOBALS['company']='example-company';
$GLOBALS['foreign']=false;
$GLOBALS['has_binding']=true;
function wp_get_environment_type(){return $GLOBALS['env'];}
function home_url($x='/'){return $GLOBALS['origin'].'/';}
function untrailingslashit($v){return rtrim($v,'/');}
function wp_parse_url($v,$x){return parse_url($v,$x);}
function wp_is_uuid($s){return is_string($s)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$s);}
function add_filter($name,$fn,$priority=10,$args=1){}
function add_action($name,$fn){}
function get_option($name,$default=null){
 if($name==='mad4b_scp_site_profile_v2')return ['site_uuid'=>$GLOBALS['uuid']];
 if($name==='mad4b_company_site_identity_v1')
  return $GLOBALS['has_binding']?['company_code'=>$GLOBALS['company'],'site_uuid'=>$GLOBALS['uuid']]:[];
 return $default;
}
class MAD4B_SCP_Site_Profile {
 static function status(){return ['site_uuid'=>$GLOBALS['uuid'],
 'canonical_origin'=>$GLOBALS['origin'],'environment'=>$GLOBALS['env'],
 'origin_match'=>true,'environment_match'=>true,
 'deployment_binding_match'=>true,'deployment_binding_bound'=>true,
 'profile_environment_authoritative'=>true,'foreign_profile_detected'=>$GLOBALS['foreign'],
 'profile_digest'=>str_repeat('a',64)];}
}
require __DIR__.'/../mad4b-universal-site-signed-v4/mad4b-universal-site-signed-v4.php';
function ok($v,$message){if(!$v){fwrite(STDERR,'FAIL '.$message."\n");exit(2);}echo 'PASS '.$message."\n";}
$p='MAD4B_Universal_Site_Signed_V4';
ok(($p::binding()['company']??null)==='example-company','generic staging enrolled');
$GLOBALS['env']='production';
ok(($p::binding()['environment']??null)==='production','generic production enrolled');
$GLOBALS['foreign']=true;
ok($p::binding()===[],'foreign deployment rejected');
$GLOBALS['foreign']=false;
$GLOBALS['has_binding']=false;
ok($p::binding()===[],'missing original company binding rejected');
$GLOBALS['has_binding']=true;
$GLOBALS['company']='different-company';
ok(($p::binding()['company']??null)==='different-company','company sourced from enrolled site, not hostname');
ok($p::material([])===[],'no private key means no signed identity');
ok($p::central_public()!=='','central pinned P256 public certified');
$GLOBALS['env']='test';
ok($p::binding()===[],'unsupported environment rejected');
echo "UNIVERSAL_SITE_SIGNED_V4_OFFLINE_PASS\n";

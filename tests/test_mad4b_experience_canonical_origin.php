<?php
define('ABSPATH','/');
$env=getenv('MAD4B_SCOPE_ENV');
if(!in_array($env,['staging','production'],true))throw new RuntimeException('bad env');
$origin=$env==='production'?'https://wayfatours.com':'https://tours.dev.mad4b.com';
define('MAD4B_TENANT_ORIGIN',$origin);
define('MAD4B_TENANT_ENVIRONMENT',$env);
define('MAD4B_TENANT_SITE_UUID','123e4567-e89b-12d3-a456-426614174000');
$GLOBALS['current_env']=$env;$GLOBALS['blog']=1;$GLOBALS['wpml']='es';
$GLOBALS['opts']=[
 1=>['home'=>$origin,'mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>MAD4B_TENANT_SITE_UUID]],
 2=>['home'=>'https://other-company.example','mad4b_scp_brand_context_profile_v1'=>['site_uuid'=>MAD4B_TENANT_SITE_UUID]]
];
$GLOBALS['profile_uuid']=MAD4B_TENANT_SITE_UUID;$GLOBALS['home_calls']=0;
function get_option($key,$default=null){return $GLOBALS['opts'][$GLOBALS['blog']][$key]??$default;}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function wp_get_environment_type(){return $GLOBALS['current_env'];}
function wp_is_uuid($uuid){return (bool)preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',(string)$uuid);}
function home_url($path='/'){$GLOBALS['home_calls']++;return rtrim((string)get_option('home'),'/').'/'.$GLOBALS['wpml'].'/';}
class MAD4B_SCP_Site_Profile {public static function site_uuid(){return $GLOBALS['profile_uuid'];}}
function pass($name,$got){if(!$got)throw new RuntimeException('FAIL '.$name);echo "PASS ".$name."\n";}
$src=getenv('MAD4B_SCOPE_SOURCE');
if(!is_file($src))throw new RuntimeException('Missing source');
require $src;
pass('canonical site accepted in '.$env,MAD4B_Experience_Site_Scope_V1::bound());
pass('no WPML-localized home_url called',$GLOBALS['home_calls']===0);
$GLOBALS['wpml']='fr';
pass('French locale does not change authority',MAD4B_Experience_Site_Scope_V1::bound());
$GLOBALS['blog']=2;
pass('switch_to_blog foreign site denied, no cached authorization',!MAD4B_Experience_Site_Scope_V1::bound());
$GLOBALS['blog']=1;
pass('return to own company reauthorizes',MAD4B_Experience_Site_Scope_V1::bound());
$GLOBALS['opts'][1]['home']='https://evil-company.example';
pass('cross-company spoof rejected',!MAD4B_Experience_Site_Scope_V1::bound());
$GLOBALS['opts'][1]['home']=$origin.'?x=1';
pass('query-bearing canonical scope rejected',!MAD4B_Experience_Site_Scope_V1::bound());
$GLOBALS['opts'][1]['home']=$origin.'#frag';
pass('fragment-bearing canonical scope rejected',!MAD4B_Experience_Site_Scope_V1::bound());
$GLOBALS['opts'][1]['home']=$origin;
$GLOBALS['current_env']=$env==='production'?'staging':'production';
pass('environment mismatch rejected',!MAD4B_Experience_Site_Scope_V1::bound());
$GLOBALS['current_env']=$env;
$GLOBALS['opts'][1]['mad4b_scp_brand_context_profile_v1']['site_uuid']='not-user';
pass('company profile UUID mismatch rejected',!MAD4B_Experience_Site_Scope_V1::bound());
$GLOBALS['opts'][1]['mad4b_scp_brand_context_profile_v1']['site_uuid']=MAD4B_TENANT_SITE_UUID;
$GLOBALS['profile_uuid']='123e4567-e89b-12d3-a456-426614174001';
pass('registry site profile UUID mismatch rejected',!MAD4B_Experience_Site_Scope_V1::bound());
$GLOBALS['profile_uuid']=MAD4B_TENANT_SITE_UUID;
$GLOBALS['opts'][1]['home']='';
pass('empty stored canonical home fails closed',!MAD4B_Experience_Site_Scope_V1::bound());
if($env==='production') {
  $GLOBALS['opts'][1]['home']='http://wayfatours.com';
  pass('Production plain HTTP rejected',!MAD4B_Experience_Site_Scope_V1::bound());
}
echo "PASS_RUNTIME_SCOPE_".$env."\n";

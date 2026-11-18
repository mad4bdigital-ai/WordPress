<?php
declare(strict_types=1);
define('ABSPATH','/');
function add_action($hook,$callback,$priority=10){return true;}
function wp_json_encode($a,$flags=0){return json_encode($a,$flags);}
function wp_salt($context){return 'test-only-first-party-salt';}
final class MAD4B_SCP_Market_Profiles{
 public static function site_uuid(){return 'test-first-party-tenant';}
}
final class MAD4B_SCP_Switcher_Sources{
 public static function valid_country($code){return in_array($code,['US','DE','EG'],true)?$code:'';}
 public static function countries(){return ['US'=>'United States','DE'=>'Germany','EG'=>'Egypt'];}
}
require_once '/var/www/html/wp-content/plugins/mad4b-country-switcher/mad4b-country-switcher.php';
function insist($label,$ok){if(!$ok){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(2);}echo 'PASS '.$label.PHP_EOL;}
$issuer='MAD4B_Country_Switcher';
$one=$issuer::canonical_cookie('US');$repeat=$issuer::canonical_cookie('US');
insist('two selections of same verified country yield byte-identical cookie',hash_equals($one,$repeat));
insist('different ISO2 markets have different signed cache identities',
  !hash_equals($one,$issuer::canonical_cookie('DE')));
insist('invalid country is not issued',$issuer::canonical_cookie('XX')==='');
[$payload,$signature]=explode('.',$one,2);
insist('HMAC remains tenant-authenticated',hash_equals($signature,hash_hmac('sha256',$payload,wp_salt('auth'))));
$data=json_decode(base64_decode(strtr($payload,'-_','+/'),true),true);
insist('site_uuid binds signing to enrolled tenant',($data['site_uuid']??'')==='test-first-party-tenant');
insist('no time-based cache key churn',($data['time']??null)===0);
$old=['contract'=>$issuer::CONTRACT,'site_uuid'=>'test-first-party-tenant','country'=>'US','time'=>time()];
$encoded=rtrim(strtr(base64_encode(wp_json_encode($old)),'+/','-_'),'=');
$oldRaw=$encoded.'.'.hash_hmac('sha256',$encoded,wp_salt('auth'));
$_COOKIE['mad4b_country_selected_v1']=$oldRaw;
insist('valid historical signed country remains readable',$issuer::saved_country()==='US');
$_COOKIE['mad4b_country_selected_v1']=$one;
insist('new canonical country remains readable',$issuer::saved_country()==='US');
$_COOKIE['mad4b_country_selected_v1']=$encoded.'.'.str_repeat('f',64);
insist('forged country signature remains untrusted',$issuer::saved_country()==='');
echo 'STABLE_MARKET_COUNTRY_CONTRACT 9'.PHP_EOL;

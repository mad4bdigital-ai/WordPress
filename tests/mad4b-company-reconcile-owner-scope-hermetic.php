<?php
/**
 * Exact, content-addressed company reconciliation owner-review regression.
 * Emulates logged-in owner POST and validates real production class methods.
 * No WordPress, database, broker, or external network is touched.
 */
declare(strict_types=1);
define('ABSPATH','/');
define('LOGGED_IN_COOKIE','fake-owner-login');
$_COOKIE[LOGGED_IN_COOKIE]='valid';
$GLOBALS['options']=[];
$GLOBALS['actor']=42;
$GLOBALS['updates']=[];
$GLOBALS['checks']=0;
$GLOBALS['cap_allowed']=true;
$GLOBALS['menu_caps']=[];
class Denied extends RuntimeException {}
class RedirectExpected extends RuntimeException {}
function add_action(...$args):void {}
function add_submenu_page($parent,$title,$text,$cap,$slug,$callback):void{$GLOBALS['menu_caps'][]=$cap;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;$GLOBALS['updates'][]=$key;return true;}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function is_user_logged_in():bool{return true;}
function get_current_user_id():int{return $GLOBALS['actor'];}
function wp_get_session_token():string{return 'valid';}
function wp_validate_auth_cookie($cookie,$scheme):int{return $cookie==='valid'?get_current_user_id():0;}
function current_user_can($cap):bool{return ($GLOBALS['cap_allowed']??true)&&$cap==='mad4b_manage_company_operations';}
function wp_is_uuid($v):bool{return preg_match('/^[a-f0-9-]{36}$/D',$v)===1;}
function wp_parse_url($url,$piece):string|false{return parse_url($url,$piece);}
function sanitize_key($value):string{return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$value));}
function sanitize_text_field($value):string{return trim((string)$value);}
function wp_unslash($value){return $value;}
function check_admin_referer($action):void{$GLOBALS['checks']++;}
function wp_die($message,$code=403):never{throw new Denied((string)$message,(int)$code);}
function admin_url($path):string{return 'https://source.test/wp-admin/'.$path;}
function add_query_arg(...$args):string{return 'https://source.test/wp-admin/reconcile';}
function wp_safe_redirect($url,$code=302):never{throw new RedirectExpected((string)$url,(int)$code);}
final class MAD4B_Company_Portfolio_Manager_V1 {public static function staging():bool{return true;}}
final class MAD4B_Company_Environment_Registry_V2 {
 public static function discover():array{
  return ['wayfatours'=>['company_name'=>'Wayfatours','environments'=>['production'=>[
   'canonical_origin'=>'https://wayfatours.com','site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c']]]];
 }
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-platform/modules/operations/296b-mad4b-company-reconcile-owner-actions-v1.php';
$class=MAD4B_Company_Reconcile_Owner_Actions_V1::class;
$targets=$class::targets();
if(!isset($targets['wayfatours:production']))throw new RuntimeException('Master Admin company operations capability cannot inspect enrolled targets');
$class::menu();
if($GLOBALS['menu_caps']!==['mad4b_manage_company_operations','mad4b_manage_company_operations'])
 throw new RuntimeException('Wrong company reconciliation menu capability');
$GLOBALS['cap_allowed']=false;
if($class::targets()!==[])throw new RuntimeException('Unprivileged editor can enumerate owner scope');
try{$class::post();throw new RuntimeException('Unprivileged editor submitted owner review');}
catch(Denied $e){if($e->getCode()!==403)throw new RuntimeException('Unexpected owner denial');}
$GLOBALS['cap_allowed']=true;
$target=['company_code'=>'wayfatours','environment'=>'production',
 'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c','origin'=>'https://wayfatours.com'];
$source=str_repeat('a',64);$dest=str_repeat('b',64);$request=str_repeat('c',64);
$path='wp-content/plugins/mad4b-commerce/modules/presentation/company-top-cities-eligibility.php';
$asset=['path'=>$path,'source_sha256'=>$source,'target_sha256'=>$dest,'needs_sync'=>true];
$inventory=['contract'=>'mad4b.company-reconcile-asset-inventory.v1','company_code'=>$target['company_code'],
 'environment'=>$target['environment'],'site_uuid'=>$target['site_uuid'],'origin'=>$target['origin'],
 'exact_original_request_sha256'=>$request,'observed_epoch'=>time(),'items'=>[$asset]];
$assert=function(bool $true,string $name)use(&$passed):void{if(!$true)throw new RuntimeException('FAILED '.$name);$passed++;};
$passed=0;
$assert($class::inventory_fresh($inventory),'fresh accepted');
$assert($class::scope_manifest_digest($target,$inventory)!==null,'valid original scope');
$noDiff=$inventory;$noDiff['items'][0]['target_sha256']=$source;$noDiff['items'][0]['needs_sync']=false;
$assert($class::scope_manifest_digest($target,$noDiff)===null,'no-change not authorizable');
$duplicate=$inventory;$duplicate['items'][]=$asset;
$assert($class::scope_manifest_digest($target,$duplicate)===null,'duplicate path forbidden');
$future=$inventory;$future['observed_epoch']=time()+90;
$assert(!$class::inventory_fresh($future),'future scan rejected');
$stale=$inventory;$stale['observed_epoch']=time()-3601;
$assert(!$class::inventory_fresh($stale),'stale scan rejected');
$wrong=$inventory;$wrong['origin']='https://other.example';
$assert($class::scope_manifest_digest($target,$wrong)===null,'wrong site identity rejected');
$GLOBALS['options'][$class::INVENTORY]=['wayfatours:production'=>$inventory];
$_POST=['company'=>'wayfatours','environment'=>'production',
 'site_uuid'=>$target['site_uuid'],'review_action'=>'confirm'];
try{$class::post();throw new RuntimeException('POST confirmation did not redirect');}
catch(RedirectExpected $e){}
$review=$GLOBALS['options'][$class::REVIEWS]['wayfatours:production']??null;
$assert(is_array($review)&&preg_match('/^[a-f0-9]{64}$/D',(string)($review['scope_digest_sha256']??''))===1,'scope fingerprint stored');
$assert($class::review_matches_inventory($target,$inventory,$review,42),'same exact reviewed scope accepted');
$assert(!$class::review_matches_inventory($target,$inventory,$review,77),'different human actor rejected');
$refreshed=$inventory;$refreshed['observed_epoch']=time()-10;
$assert($class::review_matches_inventory($target,$refreshed,$review,42),'same source/target bytes unchanged across rescan');
$newBytes=$inventory;$newBytes['items'][0]['source_sha256']=str_repeat('d',64);
$assert(!$class::review_matches_inventory($target,$newBytes,$review,42),'changed source bytes invalidate review');
$newDest=$inventory;$newDest['items'][0]['target_sha256']=str_repeat('d',64);
$assert(!$class::review_matches_inventory($target,$newDest,$review,42),'changed target bytes invalidate review');
$newManifest=$inventory;$newManifest['exact_original_request_sha256']=str_repeat('e',64);
$assert(!$class::review_matches_inventory($target,$newManifest,$review,42),'changed Original305 request invalidates review');
$old=$review;unset($old['scope_digest_sha256']);
$assert(!$class::review_matches_inventory($target,$inventory,$old,42),'legacy unbound review invalidated');
$wrongTarget=$target;$wrongTarget['site_uuid']=str_repeat('f',36);
$assert(!$class::review_matches_inventory($wrongTarget,$inventory,$review,42),'changed site_uuid invalidates review');
// Try submitting a changed manifest using the original POST endpoint. It must
// fail before any broker, REST dispatch or independent 305 Job can be created.
$GLOBALS['options'][$class::INVENTORY]['wayfatours:production']=$newBytes;
$_POST=['company'=>'wayfatours','environment'=>'production',
 'site_uuid'=>$target['site_uuid'],'request_sha256'=>$request];
try{$class::submit_original_305();throw new RuntimeException('Changed manifest escaped');}
catch(Denied $e){$assert($e->getCode()===409,'new scope denied before original command admission');}
$assert(!array_key_exists($class::ORIGINAL_JOBS,$GLOBALS['options']),'no Original 305 Job created');
// A fresh owner re-review of the new exact file scope rebinds the digest.
$_POST=['company'=>'wayfatours','environment'=>'production',
 'site_uuid'=>$target['site_uuid'],'review_action'=>'confirm'];
try{$class::post();throw new RuntimeException('Re-review did not redirect');}
catch(RedirectExpected $e){}
$review2=$GLOBALS['options'][$class::REVIEWS]['wayfatours:production'];
$assert($class::review_matches_inventory($target,$newBytes,$review2,42),'fresh changed scope must be re-reviewed');
// Stale scan cannot be owner confirmed, even with the same company/site.
$GLOBALS['options'][$class::INVENTORY]['wayfatours:production']=$stale;
try{$class::post();throw new RuntimeException('Stale scan confirmation escaped');}
catch(Denied $e){$assert($e->getCode()===409,'stale scan confirmation blocked');}
// Withdrawal is always possible; it never grants authority.
$_POST['review_action']='withdraw';
try{$class::post();throw new RuntimeException('withdraw did not redirect');}
catch(RedirectExpected $e){}
$assert(!isset($GLOBALS['options'][$class::REVIEWS]['wayfatours:production']),'withdraw succeeds without scan');
$assert($GLOBALS['checks']>=4,'nonce checks are exercised');
echo json_encode(['contract'=>'mad4b.company-reconcile.exact-owner-scope.v1','passed'=>$passed,
 'original_jobs_created'=>0,'site_mutated'=>false,'payment_affected'=>false]),"\n";

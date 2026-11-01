<?php
if (!defined('ABSPATH')) exit;
/** Core extension *discovery*, not an authorization or credential minting API.
 * Actual upstream service authenticators and interactive browser runners live
 * in optional independently certified plugins. No unverified plugin may return
 * an authenticated MAD4B subject through this descriptor registry.
 */
final class MAD4B_SCP_Unified_Approval_Extensions {
 const CONTRACT='mad4b.unified-approval-extensions.v1';
 private static $items=array();
 public static function register($kind,$id,$descriptor){
  if(!in_array($kind,array('service_identity','browser_authorization'),true)
   ||!preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/D',(string)$id)
   ||!is_array($descriptor)||array_diff(array_keys($descriptor),array('title','certification_sha256','supported_methods','capabilities','plugin_file')))
    return new WP_Error('mad4b_approval_extension_descriptor_invalid','Invalid extension descriptor.');
  $pin=strtolower((string)($descriptor['certification_sha256']??''));
  if(!preg_match('/^[a-f0-9]{64}$/D',$pin) || !defined('MAD4B_APPROVAL_CERTIFIED_EXTENSIONS'))
   return new WP_Error('mad4b_approval_extension_not_certified','No original owner certification.');
  $certs=MAD4B_APPROVAL_CERTIFIED_EXTENSIONS;
  $file=realpath((string)($descriptor['plugin_file']??''));
  $plugin_root=defined('WP_PLUGIN_DIR')?realpath(WP_PLUGIN_DIR):false;
  $actual=$file&&is_file($file)?hash_file('sha256',$file):false;
  if(!is_array($certs)||($certs[$kind][$id]??'')!==$pin
   ||!$file||!$plugin_root||strpos($file,$plugin_root.DIRECTORY_SEPARATOR)!==0
   ||!is_string($actual)||!hash_equals($pin,$actual))
   return new WP_Error('mad4b_approval_extension_pin_invalid','Exact certified plugin source required.');
  $key=$kind.':'.$id;
  if(isset(self::$items[$key]))return new WP_Error('mad4b_approval_extension_duplicate','Duplicate extension provider.');
  // Descriptors are advisory only. No authentication callback is registered.
  self::$items[$key]=array('kind'=>$kind,'provider'=>$id,
    'title'=>sanitize_text_field((string)($descriptor['title']??$id)),
    'certification_sha256'=>$pin,'status'=>'described_only',
    'authentication_authorized'=>false,'browser_execution_authorized'=>false);
  return true;
 }
 public static function inventory(){return array('contract'=>self::CONTRACT,
   'supported_by_core'=>array('oauth2_bearer','wp_application_password','mad4b_passwordless_bearer'),
   'additional_providers'=>array_values(self::$items),
   'unverified_api_key_and_mtls_disabled'=>true,
   'browser_human_step_up_required'=>true,
   'credentials_exposed'=>false,'mutation_authorized'=>false,'read_only'=>true);
 }
}

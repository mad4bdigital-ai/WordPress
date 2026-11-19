<?php
if (!defined('ABSPATH')) exit;
/** Site-local transparent-at-rest connector options; not a runtime isolation boundary. */
final class MAD4B_SCP_Core_Connector_Vault {
 const CONTRACT='mad4b.core-connector-vault.v1';
 const PREFIX='MAD4B$X1$';
 const KEY_PATH='/run/mad4b-connector-key';
 private static $registered=array(),$key=null;
 public static function enabled(){return defined('MAD4B_CORE_CONNECTOR_VAULT') && true===constant('MAD4B_CORE_CONNECTOR_VAULT');}
 public static function boot(){
  foreach(array('connectors_ai_google_api_key','connectors_ai_openai_api_key','connectors_ai_anthropic_api_key',
   'connectors_mad4b_gemini_api_key','connectors_mad4b_openrouter_api_key','connectors_mad4b_custom_api_key')as$n)self::register($n);
  add_action('wp_connectors_init',array(__CLASS__,'discover'),PHP_INT_MAX,0);
 }
 public static function discover(){
  if(!function_exists('wp_get_connectors'))return;
  foreach(wp_get_connectors() as $c){
   if(($c['authentication']['method']??'')!=='api_key')continue;
   self::register((string)($c['authentication']['setting_name']??''));
  }
 }
 private static function valid_name($name){return is_string($name)&&strlen($name)<=110
  &&preg_match('/^connectors_[a-z0-9_]+_api_key$/D',$name);}
 public static function register($name){
  if(!self::valid_name($name)||isset(self::$registered[$name]))return;
  self::$registered[$name]=true;
  add_filter('pre_update_option_'.$name,static function($value,$old,$option){
   return MAD4B_SCP_Core_Connector_Vault::encode_option($option,$value,$old);
  },PHP_INT_MAX,3);
  add_filter('option_'.$name,static function($value,$option){
   return MAD4B_SCP_Core_Connector_Vault::decode_option($option,$value);
  },1,2);
 }
 private static function key(){
  if(null!==self::$key)return self::$key;
  if(!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') || !is_readable(self::KEY_PATH))return false;
  $hex=trim((string)@file_get_contents(self::KEY_PATH));
  if(!preg_match('/^[a-f0-9]{64}$/D',$hex))return false;
  self::$key=hex2bin($hex);return self::$key;
 }
 private static function aad($option){return 'mad4b.core-connector:'.get_current_blog_id().':'.$option;}
 public static function seal($option,$plain){
  $key=self::key();if(false===$key||!self::valid_name($option)||!is_string($plain))return false;
  $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
  $data=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain,self::aad($option),$nonce,$key);
  return self::PREFIX.base64_encode($nonce.$data);
 }
 public static function unseal($option,$wrapped){
  $key=self::key();if(false===$key||!self::valid_name($option)||!is_string($wrapped)||!str_starts_with($wrapped,self::PREFIX))return false;
  $raw=base64_decode(substr($wrapped,strlen(self::PREFIX)),true);
  $n=SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
  if(false===$raw||strlen($raw)<$n+16)return false;
  return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw,$n),self::aad($option),substr($raw,0,$n),$key);
 }
 public static function encode_option($option,$value,$old){
  if(!self::enabled()||!self::valid_name($option)||!is_string($value)||$value==='')return $value;
  if(str_starts_with($value,self::PREFIX))return $value;
  // REST echo of a masked key is not a key-rotation request.
  if(str_contains($value,"\u{2022}"))return $old;
  $cipher=self::seal($option,$value);
  return false!==$cipher?$cipher:$old; // no plaintext persistence if vault unavailable.
 }
 public static function decode_option($option,$value){
  if(!is_string($value)||!str_starts_with($value,self::PREFIX))return $value;
  $plain=self::unseal($option,$value);return false===$plain?'':$plain;
 }
 public static function migrate($name){
  if(!self::enabled()||false===self::key()||!isset(self::$registered[$name]))return false;
  $current=get_option($name,'');if(!is_string($current)||$current==='')return 'empty';
  // Rewriting equal plaintext is intentional; pre_update_option seals it.
  update_option($name,$current,false);
  global $wpdb;
  $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$name));
  return is_string($raw)&&str_starts_with($raw,self::PREFIX)&&hash_equals($current,(string)self::unseal($name,$raw));
 }
 public static function status(){
  return array('contract'=>self::CONTRACT,'enabled'=>self::enabled(),'key_available'=>false!==self::key(),
   'registered_count'=>count(self::$registered),'storage'=>'native_wp_options_ciphertext',
   'algorithm'=>'xchacha20poly1305','db_only_exposure_mitigated'=>self::enabled()&&false!==self::key(),
   'runtime_php_can_read_key'=>false!==self::key(),'rollback_requires_original_key'=>true,
   'unmanaged_direct_add_option_possible'=>true,'secret_value_exposed'=>false);
 }
}

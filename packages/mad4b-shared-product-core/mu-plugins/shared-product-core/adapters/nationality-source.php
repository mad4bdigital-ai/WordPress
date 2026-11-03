<?php
/** Generic nationality provider; legacy site-specific class is isolated here. */
if(!defined('ABSPATH')||wp_get_environment_type()!=='local'||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
final class Shared_Product_Nationality_Source {
 private static function provider(){
  $provider=apply_filters('shared_product_nationality_source',null);
  if($provider && is_callable([$provider,'validate_list']) && is_callable([$provider,'selected']) && is_callable([$provider,'valid']))return $provider;
  // Temporary compatibility adapter; core Booking v2 no longer depends on the site-specific symbol.
  $legacy='ETG_Traveller_Nationality_Switcher';
  if(class_exists($legacy))return $legacy;
  return null;
 }
 public static function available(): bool {return self::provider()!==null;}
 public static function validate_list($values){$provider=self::provider();return $provider?$provider::validate_list($values):new WP_Error('nationality_source_unavailable');}
 public static function selected(){$provider=self::provider();return $provider?$provider::selected():null;}
 public static function valid($code){$provider=self::provider();return $provider?$provider::valid($code):new WP_Error('nationality_source_unavailable');}
}

<?php
/**
 * Plugin Name: MAD4B Legacy CSS Priority Compatibility
 * Description: Inert compatibility facade; exclusive CSS runtime is the manifest-backed MU335 via MU336.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Page_CSS_Frame_Priority_V1 {
 public static function safe_cookie_header(string $raw,string $locale): bool {
  return class_exists('MAD4B_LSC_Local_Provider_V1',false)
   && MAD4B_LSC_Local_Provider_V1::safe_cookie_header($raw,$locale);
 }
 public static function valid_route(string $path,string $lang):bool {return false;}
 public static function selected(string $path):array {return [];}
 public static function activate():void {}
}

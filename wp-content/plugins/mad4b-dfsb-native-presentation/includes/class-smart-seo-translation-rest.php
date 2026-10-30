<?php
namespace MAD4B\DFSB\NativePresentation;
if(!defined('ABSPATH'))exit;
final class SmartSeoTranslationRest {
 const NS='mad4b/v1';
 public static function allowed():bool{return current_user_can('manage_options');}
 public static function register():void {
  foreach([
   ['/seo-intelligence/translations/inspect','GET','inspect'],
   ['/seo-intelligence/translations/tasks','GET','tasks'],
   ['/seo-intelligence/translations/tasks','POST','prepare'],
   ['/seo-intelligence/translations/tasks/(?P<id>[a-f0-9]{64})','GET','detail'],
   ['/seo-intelligence/translations/tasks/(?P<id>[a-f0-9]{64})/submit','PUT','submit'],
   ['/seo-intelligence/translations/tasks/(?P<id>[a-f0-9]{64})/review','POST','review'],
   ['/seo-intelligence/translations/tasks/(?P<id>[a-f0-9]{64})/apply','POST','apply'],
   ['/seo-intelligence/translations/tasks/(?P<id>[a-f0-9]{64})/rollback','POST','rollback']
  ]as $route)register_rest_route(self::NS,$route[0],[
   'methods'=>$route[1],'permission_callback'=>[__CLASS__,'allowed'],
   'callback'=>[__CLASS__,$route[2]]]);
 }
 public static function input(\WP_REST_Request $r):array {
  $d=$r->get_json_params();return is_array($d)?$d:[];
 }
 public static function locked(\WP_REST_Request $r) {
  $old=SmartSeoTranslation::get((string)$r['id']);
  if(is_wp_error($old))return $old;
  if(!hash_equals(SmartSeoTranslation::revision($old),
     (string)(self::input($r)['expected_revision']??'')))
   return new \WP_Error('revision_stale','Reload task',['status'=>409]);
  return $old;
 }
 public static function save(array $old,array $next) {
  $out=SmartSeoTranslation::write($old['id'],SmartSeoTranslation::revision($old),$next);
  return is_wp_error($out)?$out:rest_ensure_response($out);
 }
}

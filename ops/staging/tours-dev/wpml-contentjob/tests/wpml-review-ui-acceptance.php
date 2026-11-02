<?php
/** Staging-only, nonmutating WP-CLI UI acceptance: wp eval-file <this> --user=<site-operator>. */
defined('ABSPATH') || exit(1);
final class MAD4B_WPML_Review_UI_Test {
 private static $assertions = 0;
 private static function ensure($test,$description){
  if(!$test)WP_CLI::error('wpml_review_ui_failure: '.$description);
  ++self::$assertions;
 }
 public static function run(){
  $class='MAD4B_Control_Plane_WPML_Review_UI_V1';
  self::ensure(class_exists($class,false),'MU plugin must be loaded');
  self::ensure($class::ready()&&$class::permitted(),'staging and read authority');
  self::ensure(has_action('admin_menu',[$class,'menu'])!==false,'admin menu registration');
  self::ensure(has_action('admin_notices',[$class,'readiness_notice'])!==false,'Content Readiness link hook');
  $data=$class::data();
  self::ensure(is_array($data)&&($data['contract']??'')==='mad4b.wpml.review-dashboard.v1','live contract');
  $totals=$data['totals']??[];
  $draft=(int)($totals['draft_pending_review']??0);
  $published=(int)($totals['review_with_published_target']??0);
  $other=(int)($totals['needs_translation_or_other']??0);
  $all=array_values(array_filter($data['rows']??[], 'is_array'));
  self::ensure(count($all)<=$draft+$published+$other,'view <= authoritative totals');
  self::ensure(count($all)<=100,'bounded dashboard output');
  foreach($all as $row){
   self::ensure(in_array($row['native_state'],['WAITING_REVIEW','RUNNING','QUEUED','FAILED'],true),'native state bounded');
   if($row['target_post_status']==='draft')
    self::ensure(!($row['publicly_visible_translation']??true),'draft never shown as published');
  }
  $old=(array)get_option('mad4b_wpml_content_job_map_v1',[]);
  ob_start();$class::render();$markup=ob_get_clean();
  self::ensure(str_contains($markup,'WPML Translation Reviews'),'page HTML');
  self::ensure(str_contains($markup,'No auto-approval or automatic completion'),'no auto-approval statement');
  self::ensure(str_contains($markup,'WPML draft')||$draft===0,'draft blocker notice');
  self::ensure(str_contains($markup,'name="locale"')&&str_contains($markup,'name="view"'),'accessible GET filters');
  $_GET['locale']='zz';$_GET['view']='bad';
  ob_start();$class::render();$invalid=ob_get_clean();
  self::ensure(str_contains($invalid,'value="all" selected'),'invalid filters fall back');
  $_GET['page']='mad4b-content-readiness-alerts';
  ob_start();$class::readiness_notice();$notice=ob_get_clean();
  self::ensure($draft===0||str_contains($notice,'page=mad4b-wpml-review-dashboard'),'Readiness cross-link');
  self::ensure((array)get_option('mad4b_wpml_content_job_map_v1',[])===$old,'read-only test leaves native map untouched');
  wp_set_current_user(0);
  $denied=$class::data();
  self::ensure(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_wpml_review_forbidden','anonymous access denied after caching');
  echo wp_json_encode(['contract'=>'mad4b.wpml.review-ui.acceptance.v1','assertions'=>self::$assertions,
    'drafts'=>$draft,'published_review'=>$published,'other_pending'=>$other,
    'rows'=>count($all),'read_only'=>true],JSON_UNESCAPED_SLASHES)."\n";
 }
}
MAD4B_WPML_Review_UI_Test::run();

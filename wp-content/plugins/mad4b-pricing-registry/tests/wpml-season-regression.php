<?php
$original=apply_filters('wpml_current_language',null);
$known=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
$checks=[];$errors=[];
try {
 foreach(array_keys((array)$known) as $language){
  do_action('wpml_switch_language',$language);
  $season=MAD4B_Package_Seasonal_Assignment_V1::seasons();
  $s=MAD4B_Package_Seasonal_Assignment_V1::seasonOn('2026-09-30');
  $w=MAD4B_Package_Seasonal_Assignment_V1::seasonOn('2027-01-15');
  $quote=MAD4B_Supplier_Sheet_Reference_V1::catalog('hotels','winter_2026_27');
  $pass=count($season)===2&&$s==='summer'&&$w==='winter'&&
    is_array($quote)&&
    ($quote['base_season']['window_start']??'')==='2026-10-01'&&
    ($quote['base_season']['window_end']??'')==='2027-04-30'&&
    ($quote['base_season']['policy_term_id']??0)===(int)$season['winter']['policy_term_id'];
  $checks[$language]=['pass'=>$pass,'term_ids'=>array_column($season,'term_id','slug'),
   'policy_terms'=>array_column($season,'policy_term_id','slug'),
   'winter'=>$w,'quote_window'=>is_array($quote)?($quote['base_season']['window_end']??null):
   (is_wp_error($quote)?$quote->get_error_code():'invalid')];
  if(!$pass)$errors[]=$language;
 }
} finally {do_action('wpml_switch_language',$original);}
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.season-wpml-inheritance-regression.v1',
 'locale_count'=>count($checks),'passed'=>count($checks)-count($errors),
 'failed'=>$errors,'checks'=>$checks],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
if($errors&&class_exists('WP_CLI'))WP_CLI::halt(1);


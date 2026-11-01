<?php
/** Reversible Local Lab test: deprecated identifiers read, all fresh writes neutral. */
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local')exit(20);
$tm='Travel_Booking_Traveller_Management';
$n='Travel_Booking_Traveller_Nationality_Switcher';
$test=static function($name,$yes)use(&$fail){echo $name.'='.($yes?'PASS':'FAIL').PHP_EOL;if(!$yes)$fail++;};
$fail=0;$tour=66133;
$canonical=$tm::OPTION;$legacy='etg_traveller_management_v1';
$sentinel='__absent__';
$oldCanonical=get_option($canonical,$sentinel);
$oldLegacy=get_option($legacy,$sentinel);
$metaNames=[
 '_travel_booking_group_booking_minimum','_travel_booking_departure_max_capacity',
 '_etg_departure_max_capacity','_etg_group_booking_minimum',
];
$oldMeta=[];
foreach($metaNames as $key){
 $oldMeta[$key]=['exists'=>metadata_exists('post',$tour,$key),'value'=>get_post_meta($tour,$key,true)];
}
$cookieName='etg_nationality_preference_v1';
$oldCookie=$_COOKIE[$cookieName]??null;
try {
 delete_option($canonical);
 update_option($legacy,['revision'=>0,'default'=>'tour','custom'=>[],'overrides'=>[]],false);
 $test('LEGACY_OPTION_READ',$tm::state()['default']==='tour');
 $write=$tm::mutate('global','automatic',[],0);
 $test('NEW_OPTION_WRITE_ONLY',!is_wp_error($write) &&
  get_option($canonical,$sentinel)!==$sentinel && get_option($legacy)['default']==='tour');
 delete_post_meta($tour,'_travel_booking_departure_max_capacity');
 update_post_meta($tour,'_etg_departure_max_capacity',30);
 $test('LEGACY_CAPACITY_META_READ',$tm::meta_value($tour,'_travel_booking_departure_max_capacity')==30);
 update_post_meta($tour,'_travel_booking_departure_max_capacity',45);
 $test('CANONICAL_META_OVERRIDES_LEGACY',$tm::meta_value($tour,'_travel_booking_departure_max_capacity')==45);
 update_post_meta($tour,'_travel_booking_departure_max_capacity','');
 $test('INTENTIONALLY_BLANK_CANONICAL_SUPPRESSES_OLD',$tm::meta_value($tour,'_travel_booking_departure_max_capacity')==='');
 $test('LEGACY_CLASS_ALIAS',class_exists('ETG_Traveller_Management') &&
  is_a('ETG_Traveller_Management',$tm,true));
 $test('LEGACY_SHORTCODE_COMPAT',shortcode_exists('etg_nationality_switcher') &&
  shortcode_exists('travel_booking_nationality_switcher'));
 $payload=['contract'=>'etg.traveller-nationality.v1','site_uuid'=>hash('sha256',home_url('/')),
   'country'=>'EG','saved_at'=>time()];
 $encoded=rtrim(strtr(base64_encode(wp_json_encode($payload)),'+/','-_'),'=');
 $_COOKIE[$cookieName]=$encoded.'.'.hash_hmac('sha256',$encoded,wp_salt('auth'));
 $test('LEGACY_SIGNED_COOKIE_COMPAT',$n::selected()==='EG');
 $_COOKIE[$cookieName]=$encoded.'.tampered';
 $test('LEGACY_COOKIE_TAMPER_DENIED',$n::selected()==='');
 $test('NEW_SESSION_NAME_IS_NEUTRAL',$n::COOKIE==='travel_booking_nationality_preference_v1');
} finally {
 foreach([[$canonical,$oldCanonical],[$legacy,$oldLegacy]] as [$key,$value]){
  if($value===$sentinel)delete_option($key);else update_option($key,$value,false);
 }
 foreach($oldMeta as $key=>$previous){
  if(!$previous['exists'])delete_post_meta($tour,$key);
  else update_post_meta($tour,$key,$previous['value']);
 }
 if($oldCookie===null)unset($_COOKIE[$cookieName]);else $_COOKIE[$cookieName]=$oldCookie;
}
$test('LEGACY_OPTIONS_RESTORED',get_option($legacy,$sentinel)===$oldLegacy);
$test('META_RESTORED',!metadata_exists('post',$tour,'_travel_booking_group_booking_minimum')==!$oldMeta['_travel_booking_group_booking_minimum']['exists']);
echo "NEUTRAL_COMPAT_FAILURES=$fail\n";
exit($fail?1:0);

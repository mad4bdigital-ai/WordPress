<?php
/** MAD4B public-country holiday sources: no Google OAuth, no cookies, no API keys. */
if(!defined('ABSPATH'))exit;
final class MAD4B_SCP_Public_Holidays {
 const CONTRACT='mad4b.public-holidays.v1';
 private static $booted=false;
 public static function boot(){
  if(self::$booted)return;self::$booted=true;
  add_action('wp_abilities_api_init',[__CLASS__,'register_ability'],9);
 }
 public static function register_ability(){
  if(!function_exists('wp_register_ability')||!class_exists('MAD4B_SCP_Policy'))return;
  if(function_exists('wp_has_ability')&&wp_has_ability('mad4b/season-public-preview'))return;
  wp_register_ability('mad4b/season-public-preview',[
   'label'=>'MAD4B Public Holiday Preview',
   'description'=>'Read public holidays for a selected country, enabled site language, and year; never stores or approves seasons.',
   'category'=>'mad4b-read','execute_callback'=>[__CLASS__,'preview_ability'],
   'permission_callback'=>['MAD4B_SCP_Policy','can_read'],
   'input_schema'=>['type'=>'object','properties'=>['country'=>['type'=>'string'],'language'=>['type'=>'string'],'year'=>['type'=>'integer']],
     'required'=>['country','language','year'],'additionalProperties'=>false],
   'output_schema'=>['type'=>'object','additionalProperties'=>true],
   'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
    'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]],
  ]);
 }
 public static function preview_ability($args){
  $args=is_array($args)?$args:[];
  return self::preview($args['country']??'',$args['language']??'',(int)($args['year']??0));
 }
 private static function validate($country,$language,$year){
  if(!preg_match('/^[A-Z]{2}$/D',$country)||!preg_match('/^[a-z]{2,8}$/D',$language)||$year<(int)gmdate('Y')-1||$year>(int)gmdate('Y')+5)
   return new WP_Error('mad4b_public_holiday_input_invalid','Expected ISO country, site language and bounded calendar year.');
  if(!class_exists('MAD4B_SCP_Market_Profiles')||!isset(MAD4B_SCP_Market_Profiles::enabled_languages()[$language]))
   return new WP_Error('mad4b_public_holiday_language_hidden','Site language is unavailable or hidden by WPML.');
  if(!isset(MAD4B_SCP_Market_Profiles::suggestions($language)[$language.'-'.$country]))
   return new WP_Error('mad4b_public_holiday_country_not_suggested','Country/language is not among the visible market candidates.');
  return true;
 }
 /** Exact, static Google holiday feeds verified accessible; no user-supplied URLs or redirects. */
 private static function verified_google_feed($country,$language){
  if($country!=='EG')return '';
  return ($language==='ar'?'ar.eg':'en.eg').'#holiday@group.v.calendar.google.com';
 }
 private static function get_body($url,$content_type){
  $response=wp_safe_remote_get($url,['timeout'=>16,'redirection'=>0,'limit_response_size'=>2000000,'headers'=>['Accept'=>$content_type],
   'user-agent'=>'MAD4B-PublicHolidayReader/1.0']);
  if(is_wp_error($response))return $response;
  if((int)wp_remote_retrieve_response_code($response)!==200)return new WP_Error('mad4b_public_holiday_http_error','Public holiday provider returned a non-200 HTTP status.');
  $body=(string)wp_remote_retrieve_body($response);
  if($body===''||strlen($body)>2000000)return new WP_Error('mad4b_public_holiday_response_invalid','Holiday feed empty or too large.');
  return $body;
 }
 private static function decode_ical_string($text){
  return str_replace(['\\n','\\N','\\,','\\;','\\\\'],["\n","\n",',',';','\\'],(string)$text);
 }
 public static function parse_google_ics($body,$year){
  if(!is_string($body)||strlen($body)>2000000||strpos($body,'BEGIN:VCALENDAR')===false)return new WP_Error('mad4b_public_ics_invalid','Invalid Google iCalendar document.');
  $lines=preg_split('/\\r\\n|\\r|\\n/', $body);
  $unfolded=[];
  foreach($lines as $line){if($line!==''&&($line[0]===' '||$line[0]==="\t")&&$unfolded){$unfolded[count($unfolded)-1].=substr($line,1);}else $unfolded[]=$line;}
  $entries=[];$current=null;$has_unexpanded=false;
  foreach($unfolded as $line){
   if($line==='BEGIN:VEVENT'){$current=[];continue;}
   if($line==='END:VEVENT'){
    if(!is_array($current))continue;
    if(isset($current['RRULE'])||isset($current['RECURRENCE-ID'])){$has_unexpanded=true;$current=null;continue;}
    $start=(string)($current['DTSTART']??'');$end=(string)($current['DTEND']??'');
    $uid=(string)($current['UID']??'');$title=self::decode_ical_string($current['SUMMARY']??'');
    if(preg_match('/^\\d{8}$/D',$start)&&preg_match('/^\\d{8}$/D',$end)&&substr($start,0,4)===(string)$year&&$uid&&$title&&($current['STATUS']??'CONFIRMED')!=='CANCELLED'){
     $start=substr($start,0,4).'-'.substr($start,4,2).'-'.substr($start,6,2);
     $end=substr($end,0,4).'-'.substr($end,4,2).'-'.substr($end,6,2);
     if(strtotime($end.' UTC')>strtotime($start.' UTC'))$entries[]=['id'=>hash('sha256',$uid),'summary'=>$title,'description'=>self::decode_ical_string($current['DESCRIPTION']??''),
       'status'=>'confirmed','start'=>['date'=>$start],'end'=>['date'=>$end]];
    }
    $current=null;continue;
   }
   if($current===null||strpos($line,':')===false)continue;
   [$key,$value]=explode(':',$line,2);
   $key=strtoupper(explode(';',$key,2)[0]);
   if(in_array($key,['RRULE','RECURRENCE-ID','DTSTART','DTEND','UID','SUMMARY','DESCRIPTION','STATUS'],true))$current[$key]=$value;
  }
  if($has_unexpanded)return new WP_Error('mad4b_public_ics_recurrence_unexpanded','This iCalendar feed contains recurrence rules requiring expansion. No partial import.');
  if(count($entries)>500)return new WP_Error('mad4b_public_ics_event_limit','More than 500 public events in this calendar year.');
  return $entries;
 }
 public static function google_public_events($country,$language,$year){
  $id=self::verified_google_feed($country,$language);
  if(!$id)return new WP_Error('mad4b_public_google_feed_not_verified','No verified Google public holiday feed for country.');
  $url='https://calendar.google.com/calendar/ical/'.rawurlencode($id).'/public/basic.ics';
  $body=self::get_body($url,'text/calendar');if(is_wp_error($body))return $body;
  $items=self::parse_google_ics($body,$year);if(is_wp_error($items))return $items;
  return ['provider'=>'google_public_ics','calendar_id'=>$id,'events'=>$items,'year'=>$year];
 }
 public static function nager_public_events($country,$year){
  $url='https://nagerholidays.com/api/v4/Holidays/'.$country.'/'.$year;
  $body=self::get_body($url,'application/json');if(is_wp_error($body))return $body;
  $rows=json_decode($body,true);if(!is_array($rows))return new WP_Error('mad4b_nager_response_invalid','Public holiday JSON was invalid.');
  if(count($rows)>500)return new WP_Error('mad4b_nager_event_limit','Too many country holidays.');
  $events=[];
  foreach($rows as $row){
   if(!is_array($row)||strtoupper((string)($row['countryCode']??''))!==$country||empty($row['nationalHoliday'])||!in_array('Public',(array)($row['holidayTypes']??[]),true))continue;
   $date=(string)($row['date']??'');if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$date)||substr($date,0,4)!==(string)$year)continue;
   $stamp=strtotime($date.' UTC');if(!$stamp)continue;
   $name=sanitize_text_field((string)($row['name']??''));if(!$name)continue;
   $events[]=['id'=>hash('sha256',$country.'|'.$date.'|'.$name),'summary'=>$name,'description'=>'Nager.Date public national holiday; review before any commercial use.',
      'status'=>'confirmed','start'=>['date'=>$date],'end'=>['date'=>gmdate('Y-m-d',$stamp+DAY_IN_SECONDS)]];
  }
  return ['provider'=>'nager_public_v4','calendar_id'=>'public-nager-v4:'.$country,'events'=>$events,'year'=>$year];
 }
 public static function preview($country,$language,$year,$source='auto'){
  $country=strtoupper((string)$country);$language=sanitize_key((string)$language);$year=(int)$year;
  $valid=self::validate($country,$language,$year);if(is_wp_error($valid))return $valid;
  if(!in_array($source,['auto','google_public_ics','nager_public_v4'],true))return new WP_Error('mad4b_public_source_invalid','Unknown public source.');
  $can_google=self::verified_google_feed($country,$language)!=='';
  if($source==='google_public_ics'&&!$can_google)return new WP_Error('mad4b_public_google_not_verified','Google feed not verified for country.');
  $result=($source==='google_public_ics'||($source==='auto'&&$can_google))?self::google_public_events($country,$language,$year):self::nager_public_events($country,$year);
  if(is_wp_error($result)&&$source==='auto'&&$can_google)$result=self::nager_public_events($country,$year);
  if(is_wp_error($result))return $result;
  return ['contract'=>self::CONTRACT,'country'=>$country,'language'=>$language,'year'=>$year,
   'provider'=>$result['provider'],'source_calendar_id'=>$result['calendar_id'],
   'events'=>$result['events'],'count'=>count($result['events']),'read_only'=>true,
   'market_requires_explicit_approval'=>true,'season_terms_created'=>0,'offer_templates_activated'=>0];
 }
 /** A public provider may prepare proposals only after the country-language market is approved. */
 public static function reviewed_market_plan($country,$language,$year){
  $preview=self::preview($country,$language,$year);
  if(is_wp_error($preview))return $preview;
  $market=MAD4B_SCP_Market_Profiles::by_country($preview['country'],$preview['language']);
  if(!$market)return new WP_Error('mad4b_public_market_not_approved','Public dates may be previewed, but storage requires an approved Market Profile.');
  $calendar=$preview['source_calendar_id'];
  $sources=[$calendar=>['calendar_id'=>$calendar,'country'=>$preview['country'],'provider'=>$preview['provider'],'read_only'=>true]];
  return MAD4B_SCP_Season_Calendar::plan($preview['country'],$preview['language'],$calendar,$preview['events'],$sources,
   $year.'-01-01',$year.'-12-31');
 }
}

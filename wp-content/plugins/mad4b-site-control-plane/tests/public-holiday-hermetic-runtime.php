<?php
/** Hermetic public holiday reader checks; no WordPress DB/network or production mutation. */
define('ABSPATH','/');
define('DAY_IN_SECONDS',86400);
$GLOBALS['checks']=0;
$GLOBALS['calls']=[];
$GLOBALS['transport_body']='';
$GLOBALS['status_code']=200;
class WP_Error {
 private $code;
 public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($x){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$x));}
function sanitize_text_field($x){return trim(strip_tags((string)$x));}
function add_action($hook,$cb,$priority=10){$GLOBALS['actions'][$hook][]=$cb;}
function wp_has_ability($name){return false;}
function wp_register_ability($name,$config){$GLOBALS['registered_ability']=[$name,$config];return true;}
class MAD4B_SCP_Policy {public static function can_read(){return true;}}
class MAD4B_SCP_Market_Profiles {
 public static function enabled_languages(){return ['ar'=>'Arabic','en'=>'English'];}
 public static function suggestions($lang){return ['ar-EG'=>['country'=>'EG'],'en-EG'=>['country'=>'EG'],'en-US'=>['country'=>'US']];}
 public static function by_country($country,$language){return [];}
}
function wp_safe_remote_get($url,$args){
 $GLOBALS['calls'][]=['url'=>$url,'args'=>$args];
 if(strpos($url,'https://calendar.google.com/calendar/ical/')!==0
    && strpos($url,'https://nagerholidays.com/api/v4/Holidays/')!==0)
    return new WP_Error('unapproved_transport');
 return ['body'=>$GLOBALS['transport_body'],'response'=>['code'=>$GLOBALS['status_code']]];
}
function wp_remote_retrieve_response_code($r){return $r['response']['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function check($name,$ok){
 ++$GLOBALS['checks'];
 if(!$ok){fwrite(STDERR,"FAIL ".$name.PHP_EOL);exit(1);}
 echo "PASS ".$name.PHP_EOL;
}
require __DIR__.'/../includes/class-mad4b-scp-public-holidays.php';
$class='MAD4B_SCP_Public_Holidays';
$class::boot();
check('only_one_ability_boot_action',count($GLOBALS['actions']['wp_abilities_api_init'])===1);
$class::register_ability();
list($name,$config)=$GLOBALS['registered_ability'];
check('ability_name_and_strict_permission',$name==='mad4b/season-public-preview'&&$config['permission_callback']===['MAD4B_SCP_Policy','can_read']);
check('ability_nonpublic_read_only',($config['meta']['public']??true)===false&&($config['meta']['mcp']['surface']??'')==='read'&&($config['meta']['annotations']['readonly']??false)===true);
check('ability_schema_no_extra_properties',$config['input_schema']['additionalProperties']===false);
$ics="BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:e-1\nDTSTART;VALUE=DATE:20270125\nDTEND;VALUE=DATE:20270126\nSUMMARY:Holiday\\, Egypt\nSTATUS:CONFIRMED\nEND:VEVENT\nBEGIN:VEVENT\nUID:e-2\nDTSTART;VALUE=DATE:20270202\nDTEND;VALUE=DATE:20270203\nSUMMARY:Cancelled\nSTATUS:CANCELLED\nEND:VEVENT\nEND:VCALENDAR";
$items=$class::parse_google_ics($ics,2027);
check('ics_parse_nonrecurring',is_array($items)&&count($items)===1&&$items[0]['summary']==='Holiday, Egypt');
check('ics_recurrence_fail_closed',is_wp_error($class::parse_google_ics(str_replace('UID:e-1','RRULE:FREQ=YEARLY'."\n".'UID:e-1',$ics),2027)));
check('ics_oversize_fail_closed',is_wp_error($class::parse_google_ics(str_repeat('x',2000001),2027)));
$GLOBALS['transport_body']=$ics;
$result=$class::preview('EG','ar',2027,'google_public_ics');
check('google_preview_readonly',is_array($result)&&$result['count']===1&&$result['read_only']===true&&$result['market_requires_explicit_approval']===true&&$result['season_terms_created']===0&&$result['offer_templates_activated']===0);
check('google_provider_fixed_ics_url',strpos($GLOBALS['calls'][0]['url'],'https://calendar.google.com/calendar/ical/')===0&&strpos($GLOBALS['calls'][0]['url'],'ar.eg%23holiday%40group.v.calendar.google.com')!==false);
check('safe_http_no_redirect_limit',$GLOBALS['calls'][0]['args']['redirection']===0&&$GLOBALS['calls'][0]['args']['limit_response_size']===2000000);
$bad=$class::preview('US','en',2027,'google_public_ics');
check('unverified_google_country_denied',is_wp_error($bad)&&$bad->get_error_code()==='mad4b_public_google_not_verified');
check('hidden_language_denied',is_wp_error($class::preview('EG','fr',2027)));
check('invalid_country_denied',is_wp_error($class::preview('XXZ','en',2027)));
check('invalid_year_denied',is_wp_error($class::preview('EG','ar',1900)));
check('invalid_provider_denied',is_wp_error($class::preview('EG','ar',2027,'https://evil.example')));
$GLOBALS['transport_body']=json_encode([
 ['countryCode'=>'US','date'=>'2027-07-04','name'=>'Independence <b>Day</b>','nationalHoliday'=>true,'holidayTypes'=>['Public']],
 ['countryCode'=>'CA','date'=>'2027-07-01','name'=>'Wrong country','nationalHoliday'=>true,'holidayTypes'=>['Public']],
 ['countryCode'=>'US','date'=>'2027-11-01','name'=>'Not national','nationalHoliday'=>false,'holidayTypes'=>['Public']]
]);
$us=$class::preview('US','en',2027,'nager_public_v4');
check('nager_country_type_filter',is_array($us)&&$us['count']===1&&$us['events'][0]['summary']==='Independence Day');
check('nager_url_allowlisted',strpos(end($GLOBALS['calls'])['url'],'https://nagerholidays.com/api/v4/Holidays/US/2027')===0);
check('unapproved_market_blocks_storage',is_wp_error($class::reviewed_market_plan('US','en',2027)));
$GLOBALS['status_code']=301;
check('redirect_response_refused',is_wp_error($class::preview('US','en',2027,'nager_public_v4')));
echo 'SUMMARY pass='.$GLOBALS['checks'].' fail=0'.PHP_EOL;

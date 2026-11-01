<?php
/** Hermetic scheduled Source Hub / Season Calendar: no network or live site writes. */
define('ABSPATH',__DIR__.'/');
define('HOUR_IN_SECONDS',3600);
$GLOBALS['env']='local';
$GLOBALS['site_enabled']=true;
$GLOBALS['admin']=true;
$GLOBALS['options']=[];
$GLOBALS['scheduled']=[];
$GLOBALS['writes']=[];
$GLOBALS['calendar_fetches']=0;
function wp_get_environment_type(){return $GLOBALS['env'];}
function current_user_can($cap){return $cap==='manage_options' && $GLOBALS['admin'];}
function get_current_user_id(){return 42;}
function current_time($format,$utc=false){return '2026-10-25 12:00:00';}
function get_option($name,$default=[]){return $GLOBALS['options'][$name]??$default;}
function update_option($name,$value,$autoload=false){$GLOBALS['options'][$name]=$value;$GLOBALS['writes'][]=$name;return true;}
function wp_next_scheduled($name){return $GLOBALS['scheduled'][$name]??false;}
function wp_schedule_event($when,$recurrence,$hook){$GLOBALS['scheduled'][$hook]=$when;return true;}
function sanitize_text_field($v){return (string)$v;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error {private $code;function __construct($code,$msg=''){$this->code=$code;}function get_error_code(){return $this->code;}}
class MAD4B_SCP_Market_Profiles {
 static function site_uuid(){return $GLOBALS['site_enabled']?'123e4567-e89b-12d3-a456-426614174000':'';}
 static function approved($id){
  return self::site_uuid() && $id==='eg-ar'?['profile_id'=>'eg-ar','country'=>'EG','language'=>'ar']:false;
 }
 static function by_country($country,$language){return ($country==='EG'&&$language==='ar')?self::approved('eg-ar'):false;}
 static function status(){return ['site_bound'=>(bool)self::site_uuid(),'approved_count'=>1];}
}
class MAD4B_SCP_Google_Drive_Context {
 static function calendar_read_request($kind,$args=[]){$GLOBALS['calendar_fetches']++;return new WP_Error('calendar_provider_offline','No provider in hermetic test');}
 static function connection_status(){return ['health_state'=>'disconnected'];}
}
class MAD4B_SCP_Context_Authority {static function status(){return ['state'=>'pending'];}}
require dirname(__DIR__).'/includes/class-mad4b-scp-source-hub.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-season-calendar.php';
$count=0;
function check($label,$pass){global $count;if(!$pass){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);exit(1);}$count++;}
$source='MAD4B_SCP_Source_Hub';$season='MAD4B_SCP_Season_Calendar';
$sourcehook='mad4b_scp_source_hub_import';$seasonhook='mad4b_scp_season_calendar_sync';
$GLOBALS['env']='production';
check('prod source schedule denied',is_wp_error($source::enable_hourly_import()));
check('prod source worker zero writes',($source::poll_ingest()===null)&&count($GLOBALS['writes'])===0);
check('prod season binding denied',is_wp_error($season::enable_scheduled_binding([])));
check('prod season sync no network',($season::run_scheduled_sync()===null)&&$GLOBALS['calendar_fetches']===0);
check('prod season plan cannot write',is_wp_error($season::store_plan([])));
$GLOBALS['env']='local';$GLOBALS['admin']=false;
check('non-admin source schedule denied',is_wp_error($source::enable_hourly_import()));
check('non-admin season binding denied',is_wp_error($season::enable_scheduled_binding([])));
$GLOBALS['admin']=true;$GLOBALS['site_enabled']=false;
check('no site source schedule denied',is_wp_error($source::enable_hourly_import()));
check('no site source worker no writes',($source::poll_ingest()===null)&&count($GLOBALS['writes'])===0);
check('no site season worker no writes',($season::run_scheduled_sync()===null)&&count($GLOBALS['writes'])===0);
$GLOBALS['site_enabled']=true;
$GLOBALS['options'][$source::SOURCES]=['unreviewed'=>['state'=>'draft']];
$res=$source::enable_hourly_import();
check('approved local source schedule activates',is_array($res)&&$res['enabled']===true&&$res['publishes_content']===false);
$first=$GLOBALS['scheduled'][$sourcehook];
$source::enable_hourly_import();
check('repeated source schedule no duplicate',count($GLOBALS['scheduled'])===1 && $GLOBALS['scheduled'][$sourcehook]===$first);
$source::poll_ingest();
check('draft source ignored no ingestion writes',count($GLOBALS['writes'])===0);
$bad=$season::enable_scheduled_binding(['market_profile_id'=>'missing','source_calendar_id'=>'ar.eg#holiday@group.v.calendar.google.com']);
check('unapproved market denied',is_wp_error($bad));
$valid=['market_profile_id'=>'eg-ar','source_calendar_id'=>'ar.eg#holiday@group.v.calendar.google.com','country'=>'EG','language'=>'ar'];
$wrong=$valid;$wrong['source_calendar_id']='wrong.example';
check('unapproved calendar id denied',is_wp_error($season::enable_scheduled_binding($wrong)));
$ok=$season::enable_scheduled_binding($valid);
check('explicit local admin binding schedules daily',is_array($ok)&&$ok['scheduled']===true&&isset($GLOBALS['scheduled'][$seasonhook]));
check('single reviewed site-specific calendar bound',count($season::scheduled_bindings())===1&&count($GLOBALS['writes'])===1);
$season::run_scheduled_sync();
$report=get_option($season::LAST_RUN,[]);
check('google provider denied without exception',($report['checked']??-1)===1&&($report['fetched']??-1)===0&&($report['errors']??[])===['calendar_provider_offline']);
check('no silent seasons/campaign publication',($report['season_terms_published']??-1)===0&&($report['financial_offers_changed']??-1)===0);
check('only approved calendar attempted provider', $GLOBALS['calendar_fetches']===1);
check('no Season terms or proposals created',get_option($season::OPTION,[])===[]);
$sourceStatus=$source::status();$seasonStatus=$season::status();
check('both Cron owners deny publication',($sourceStatus['auto_publish']??true)===false && ($seasonStatus['auto_approvals']??true)===false && ($seasonStatus['auto_publication']??true)===false);
echo 'PASS: '.$count.' bounded source/calendar cron authorization cases'.PHP_EOL;

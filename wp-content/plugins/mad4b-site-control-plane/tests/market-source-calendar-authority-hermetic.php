<?php
/** Network-isolated original owner market/source/calendar authority integration. */
define('ABSPATH',__DIR__.'/');
define('DAY_IN_SECONDS',86400);
$GLOBALS['env']='local'; $GLOBALS['admin']=true; $GLOBALS['site']=true;
$GLOBALS['options']=['icl_sitepress_settings'=>[]];
$GLOBALS['writes']=[]; $GLOBALS['terms_created']=0; $GLOBALS['http_requests']=0;
function wp_get_environment_type(){return $GLOBALS['env'];}
function get_option($k,$v=[]){return $GLOBALS['options'][$k]??$v;}
function update_option($k,$v,$autoload=false){$GLOBALS['options'][$k]=$v;$GLOBALS['writes'][]=$k;return true;}
function current_user_can($c){return $GLOBALS['admin'] && $c==='manage_options';}
function get_current_user_id(){return 17;}
function current_time($format,$utc=false){return '2026-10-25 18:00:00';}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function get_locale(){return 'ar_EG';}
function wp_parse_url($v,$component=-1){return parse_url($v,$component);}
function esc_url_raw($v){return $v;}
function wp_json_encode($v){return json_encode($v);}
function wp_mkdir_p($v){return false;}
function taxonomy_exists($v){return false;}
function wp_insert_term($term,$tax,$args=[]){$GLOBALS['terms_created']++;return new WP_Error('must_not_create','test denied');}
function doing_action($v){return false;}
function wp_next_scheduled($hook){return false;}
function wp_schedule_event($time,$frequency,$hook){return true;}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error {private $code;function __construct($c,$message=''){$this->code=$c;}function get_error_code(){return $this->code;}}
class MAD4B_SCP_Site_Profile {
 static function status(){return ['configured'=>$GLOBALS['site'],'wordpress_profile_mismatch'=>false];}
 static function profile(){return ['site_uuid'=>'123e4567-e89b-12d3-a456-426614174000','environment'=>$GLOBALS['env']];}
}
class MAD4B_SCP_Google_Drive_Context {static function connection_status(){return ['health_state'=>'disconnected'];}}
require dirname(__DIR__).'/includes/class-mad4b-scp-market-profiles.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-source-hub.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-season-calendar.php';
$n=0;
function assert_check($msg,$yes){global $n;if(!$yes){fwrite(STDERR,'FAIL '.$msg.PHP_EOL);exit(1);}$n++;}
$market='MAD4B_SCP_Market_Profiles';$hub='MAD4B_SCP_Source_Hub';$season='MAD4B_SCP_Season_Calendar';
assert_check('suggestions advisory only',isset($market::suggestions('ar')['ar-EG']) && count($market::records())===0);
$GLOBALS['env']='production';
assert_check('market prod writes forbidden',is_wp_error($market::save_draft('egypt-ar','Egypt Arabic','EG','ar',0)));
assert_check('hub prod writes forbidden',is_wp_error($hub::save_draft('news','rss_atom','https://news.un.org/','EG','ar','News',0)));
$GLOBALS['env']='local';$GLOBALS['admin']=false;
assert_check('market admin required',is_wp_error($market::save_draft('egypt-ar','Egypt Arabic','EG','ar',0)));
assert_check('source admin required',is_wp_error($hub::save_draft('news','rss_atom','https://news.un.org/','EG','ar','News',0)));
$GLOBALS['admin']=true;$GLOBALS['site']=false;
assert_check('unbound site denied',is_wp_error($market::save_draft('egypt-ar','Egypt Arabic','EG','ar',0)));
$GLOBALS['site']=true;
assert_check('no arbitrary markets',is_wp_error($market::save_draft('xx-zz','Other','ZZ','ar',0)));
$created=$market::save_draft('egypt-ar','Egypt Arabic','EG','ar',0);
assert_check('market draft, site and CAS',is_array($created) && $created['revision']===1 && $created['status']==='draft' && $created['site_uuid']===$market::site_uuid());
assert_check('market not auto approved',$market::approved_list()===[]);
assert_check('market stale revision rejected',is_wp_error($market::save_draft('egypt-ar','new','EG','ar',0)));
assert_check('market stale approval rejected',is_wp_error($market::approve('egypt-ar',0)));
$approved=$market::approve('egypt-ar',1);
assert_check('market approved after separate action',is_array($approved) && $approved['country']==='EG' && $approved['language']==='ar');
assert_check('market audit persisted',count(get_option($market::AUDIT,[]))===2);
assert_check('market duplicate country language prevented',
 is_wp_error($market::save_draft('egypt-duplicate','dup','EG','ar',0))===false
 && is_wp_error($market::approve('egypt-duplicate',1)));
$GLOBALS['env']='production';
assert_check('approved market not transferred to Production',empty($market::approved_list()));
$GLOBALS['env']='local';
assert_check('hub blocks alternate host',is_wp_error($hub::save_draft('bad','rss_atom','https://127.0.0.1/feed','EG','ar','News',0)));
assert_check('hub blocks nonhttps',is_wp_error($hub::save_draft('bad','rss_atom','http://news.un.org/feed','EG','ar','News',0)));
assert_check('hub blocks userinfo',is_wp_error($hub::save_draft('bad','rss_atom','https://evil@news.un.org/feed','EG','ar','News',0)));
assert_check('hub blocks unregistered adapter',is_wp_error($hub::save_draft('bad','unknown','https://news.un.org/feed','EG','ar','News',0)));
$src=$hub::save_draft('travel','rss_atom','https://news.un.org/feed','EG','ar','Travel reference',0);
assert_check('approved host source starts draft',is_array($src)&&$src['state']==='draft'&&$src['revision']===1);
assert_check('source CAS stale draft rejected',is_wp_error($hub::save_draft('travel','rss_atom','https://news.un.org/feed','EG','ar','Travel reference',0)));
assert_check('source approval CAS required',is_wp_error($hub::approve('travel',0)));
$approvedSource=$hub::approve('travel',1);
assert_check('operator approved source, not content',is_array($approvedSource) && $approvedSource['state']==='approved' && $hub::status()['automatic_approval']===false && $hub::status()['auto_publish']===false);
assert_check('source audit write separation',count(get_option($hub::AUDIT,[]))===2);
$GLOBALS['env']='production';
assert_check('approved source cannot cross site environment',empty($hub::approved('travel')));
$GLOBALS['env']='local';
$cal='ar.eg#holiday@group.v.calendar.google.com';
$subscribed=[$cal=>['calendar_id'=>$cal,'country'=>'EG','language'=>'ar']];
$events=[['id'=>'event1','summary'=>'Confirmed holiday','start'=>['date'=>'2027-01-25'],'end'=>['date'=>'2027-01-26']],
 ['id'=>'event2','summary'=>'Tentative may change','start'=>['date'=>'2027-01-27'],'end'=>['date'=>'2027-01-28']]];
assert_check('wrong country calendar denied',is_wp_error($season::plan('US','ar',$cal,$events,$subscribed)));
$plan=$season::plan('EG','ar',$cal,$events,$subscribed);
assert_check('calendar plans require approved market',is_array($plan)&&$plan['count']===2&&$plan['term_created']===0);
assert_check('calendar tentative row retained for review',count(array_filter($plan['records'],static function($v){return $v['tentative'];}))===1);
$written=$season::store_plan($plan);
assert_check('calendar records pending only',is_array($written)&&count($season::proposals())===2&&$written['offers_modified']===0);
assert_check('calendar does not create taxonomies',$GLOBALS['terms_created']===0);
$rows=array_values($season::proposals());
$tentative=array_filter($season::proposals(),static function($v){return $v['tentative'];});$tentKey=array_key_first($tentative);
assert_check('tentative season cannot be approved',is_wp_error($season::approve_schedule($tentKey,1)));
$GLOBALS['env']='production';
assert_check('calendar cannot write on Production',is_wp_error($season::store_plan($plan)));
assert_check('still no network nor published content',$GLOBALS['http_requests']===0 && $GLOBALS['terms_created']===0);
echo 'PASS: '.$n.' original market/source/calendar owner lifecycle checks'.PHP_EOL;

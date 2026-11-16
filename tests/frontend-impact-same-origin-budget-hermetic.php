<?php
/**
 * Original Frontend Impact Priority: port-safe origin checks, safe HTTP
 * sampling and bounded 2-language evidence-only snapshots. No network or
 * WordPress content, Commerce, WPML, registration, media or grant mutations.
 */
define('ABSPATH',__DIR__.'/');
define('HOUR_IN_SECONDS',3600);
$GLOBALS['frontend_options']=[
 'mad4b_wpml_frontend_visibility_v1'=>['pages'=>[
  'en'=>[
   ['kind'=>'archive','path'=>'/en/tours-and-activities/'],
   ['kind'=>'linked_page','path'=>'/en/tours-and-activities/nile-cruise/']
  ],
  'es'=>[
   ['kind'=>'archive','path'=>'/es/tours-and-activities/'],
   ['kind'=>'linked_page','path'=>'/es/tours-and-activities/nile-cruise/']
  ]
 ]],
 'mad4b_content_readiness_issues_v1'=>[]
];
$GLOBALS['frontend_writes']=0;
$GLOBALS['frontend_safe_requests']=[];
$GLOBALS['frontend_force_http_error']=false;
$GLOBALS['frontend_one_language']=false;
$GLOBALS['frontend_policy_can_read']=true;
$GLOBALS['frontend_registered_routes']=[];
class WP_Error{
 private $code;public function __construct($code){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action(...$args){}
function add_filter(...$args){}
function wp_next_scheduled($key){return true;}
function wp_schedule_event(){throw new RuntimeException('Cron event must not be created in this test');}
function get_current_blog_id(){return 1;}
function get_locale(){return 'en_US';}
function current_user_can($cap){return false;}
function doing_action($name){return $name==='mad4b/frontend_impact_hourly_scan';}
function apply_filters($name,$value,...$more){
 if($name==='wpml_active_languages')return $GLOBALS['frontend_one_language']
  ? ['en'=>['url'=>'https://tours.example.invalid/en/']]
  : ['en'=>['url'=>'https://tours.example.invalid/en/'],
     'es'=>['url'=>'https://tours.example.invalid/es/']];
 if($name==='wpml_default_language')return 'en';
 return $value;
}
function home_url($path=''){return 'https://tours.example.invalid'.$path;}
function trailingslashit($s){return rtrim((string)$s,'/').'/';}
function wp_parse_url($s){return parse_url((string)$s);}
function get_option($n,$default=[]){return $GLOBALS['frontend_options'][$n]??$default;}
function update_option($n,$v,$autoload=false){++$GLOBALS['frontend_writes'];$GLOBALS['frontend_options'][$n]=$v;return true;}
function get_post_type_archive_link($type){return 'https://tours.example.invalid/en/tours-and-activities/';}
function get_posts($args){return [];}
function wp_remote_get($url,$args){throw new RuntimeException('Unsafe direct wp_remote_get must never sample public URLs');}
function wp_safe_remote_get($url,$args){
 $GLOBALS['frontend_safe_requests'][]=['url'=>$url,'args'=>$args];
 if($GLOBALS['frontend_force_http_error'])
  return new WP_Error('test_http_unavailable');
 if(!isset($args['redirection'])||$args['redirection']!==0||empty($args['sslverify'])||
   ($args['limit_response_size']??0)>1400000 || 0!==strpos($url,'https://'))
  throw new RuntimeException('Unsafe HTTP request options');
 $body='<html><body><nav class="elementor-widget-n-menu">Luxor Aswan</nav>'
 .'<div class="jet-listing-grid"><div data-post-id="77" class="elementor-element" data-id="one"></div></div>'
 .'<form id="fluentform_6" class="fluentform"></form>'
 .str_repeat('<p>Public destination content.</p>',75).'</body></html>';
 return ['http'=>200,'content_type'=>'text/html','body'=>$body];
}
function wp_remote_retrieve_response_code($r){return $r['http'];}
function wp_remote_retrieve_header($r,$n){return $n==='content-type'?$r['content_type']:'';}
function wp_remote_retrieve_body($r){return $r['body'];}
function register_rest_route($ns,$route,$cfg){$GLOBALS['frontend_registered_routes'][$ns.$route]=$cfg;}
class MAD4B_SCP_Policy{public static function can_read(){return $GLOBALS['frontend_policy_can_read'];}}
function impact_check($yes,$reason){if(!$yes)throw new RuntimeException('FAIL FRONTEND IMPACT: '.$reason);}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-frontend-impact-priority.php';
$c='MAD4B_SCP_Frontend_Impact_Priority';
$origin=new ReflectionMethod($c,'same_origin');
if(PHP_VERSION_ID<80100)$origin->setAccessible(true);
$valid='https://tours.example.invalid/en/tours-and-activities/';
impact_check($origin->invoke(null,$valid)===true,'Exact owned HTTPS URL should be admitted');
foreach([
 'https://other.example.invalid/en/',
 'http://tours.example.invalid/en/',
 'https://tours.example.invalid:8443/en/',
 'https://admin:pass@tours.example.invalid/en/',
 'https://tours.example.invalid/wp-admin/edit.php',
 'https://tours.example.invalid/en/?private=1',
 'https://tours.example.invalid/en/#token',
] as $bad){
 impact_check($origin->invoke(null,$bad)===false,
  'Nonorigin/admin/query URL was admitted for scheduled crawling: '.$bad);
}
$target=new ReflectionMethod($c,'targets');
if(PHP_VERSION_ID<80100)$target->setAccessible(true);
$selected=$target->invoke(null,3);
impact_check(count($selected)===2 && count($selected['en'])===3
 && count($selected['es'])===3,
 'Exact existing WPML home/archive/tour target choice must remain bounded');
$c::routes();
$route=$GLOBALS['frontend_registered_routes']['mad4b-frontend-impact/v1/summary']??null;
impact_check(is_array($route) && $route['methods']==='GET',
 'Native endpoint must remain a GET read-only report');
$out=$c::scan(1,true);
impact_check(is_array($out)&&$out['status']==='SCANNED'
 && $out['pages']===2&&count($GLOBALS['frontend_safe_requests'])===2
 && $GLOBALS['frontend_writes']===1,
 'Two-language home scan must complete with only bounded safe HTTP and one snapshot write');
$report=$c::report(20);
impact_check($report['status']==='FRESH'
 && $report['read_only_priority_overlay']===true
 && $report['source_jobs_unchanged']===true
 && $out['issues_ranked']===0
 && $out['surface_parity_candidates']===0,
 'Frontend readback cannot manufacture ContentJobs, published content or WPML links');
$GLOBALS['frontend_policy_can_read']=false;
$before_denial=count($GLOBALS['frontend_safe_requests']);
$not_operator=$c::scan(1,false);
impact_check(is_wp_error($not_operator)
 && $GLOBALS['frontend_writes']===1
 && count($GLOBALS['frontend_safe_requests'])===$before_denial,
 'A manual scan without any permitted read actor cannot send outbound HTTP');
$GLOBALS['frontend_policy_can_read']=true;
// When the proxy or DNS fails for every selected locale, preserve the last
// known good snapshot, avoid scoring missing pages as translations and never
// generate a new ContentJob based on absence of public HTML.
$prior=$GLOBALS['frontend_options'][$c::SNAPSHOT];
$GLOBALS['frontend_force_http_error']=true;
$failed=$c::scan(1,true);
impact_check(is_wp_error($failed)
 && $GLOBALS['frontend_writes']===1
 && $GLOBALS['frontend_options'][$c::SNAPSHOT]===$prior
 && $c::report(10)['status']==='FRESH',
 'Partial/failed network crawl cannot replace the last verified snapshot');
// One declared locale with only one homepage is a complete valid sample,
// not a two-page minimum. Re-enabling multi-locale scans must still obey the
// same original proportional coverage rule.
$GLOBALS['frontend_one_language']=true;
$GLOBALS['frontend_force_http_error']=false;
$single=$c::scan(1,true);
impact_check(is_array($single)&&$single['status']==='SCANNED'
 && $single['pages']===1 && $single['languages']===1
 && $GLOBALS['frontend_writes']===2,
 'A single home-only locale must not be incorrectly rejected for missing a second page');
echo "PASS: FRONTEND_IMPACT_NATIVE exact HTTPS origin/port, bounded WPML scans and preserved snapshots\n";

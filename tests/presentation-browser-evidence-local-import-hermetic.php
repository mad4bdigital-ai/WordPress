<?php
/**
 * Actual first-party Presentation Browser Evidence source, PHP 7.4+.
 * Runs without browser, real WordPress or network. Creates only temporary CI
 * files beneath a process-scoped fake WP_CONTENT_DIR, removed on exit.
 */
define('ABSPATH',__DIR__.'/');
define('WP_CLI',true);
define('WP_CONTENT_DIR',sys_get_temp_dir().'/mad4b-presentation-hermetic-'.getmypid());
$GLOBALS['pres_browser_options']=[];
$GLOBALS['pres_browser_writes']=0;
$GLOBALS['pres_browser_stdout']=[];
class WP_CLI {
 public static function add_command($name,$callback){}
 public static function error($message){throw new RuntimeException('WPCLI_DENIED: '.$message);}
 public static function line($message){$GLOBALS['pres_browser_stdout'][]=$message;}
}
function get_current_blog_id(){return 1;}
function home_url($path='/'){return 'https://tours.example.invalid'.$path;}
function get_locale(){return 'en_US';}
function wp_parse_url($url,$part){return parse_url($url,$part);}
function get_option($name,$default=[]){return $GLOBALS['pres_browser_options'][$name]??$default;}
function update_option($name,$value,$autoload=false){
 ++$GLOBALS['pres_browser_writes'];
 $GLOBALS['pres_browser_options'][$name]=$value;
 return true;
}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function evidence_check($yes,$msg){if(!$yes)throw new RuntimeException('BROWSER_EVIDENCE: '.$msg);}
$folder=WP_CONTENT_DIR.'/mad4b-private/presentation-audits';
if(!mkdir($folder,0700,true)&&!is_dir($folder))throw new RuntimeException('Cannot prepare test folder');
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-presentation-browser-evidence.php';
$c='MAD4B_SCP_Presentation_Browser_Evidence_V1';
$data=[
 'contract'=>$c::CONTRACT,'site_host'=>'tours.example.invalid',
 'complete'=>true,'generated_at'=>gmdate('c'),'locale_coverage'=>['en'],
 'links'=>['complete'=>true,'summary'=>['links_examined'=>15,'untranslated_fallback_links'=>1],
   'pages'=>[['locale'=>'en','actual_language'=>'en','mode'=>'mobile']],
   'issues'=>[['code'=>'broken_target','language'=>'en','widget_id'=>'abc',
     'occurrences'=>2,'examples'=>[['label'=>'Nile cruise','href'=>'/not-found']]]]],
 'contrast'=>['complete'=>true,'sampled'=>12,
  'issues'=>[['viewport'=>'mobile','threshold'=>4.5,'worst_ratio_p20'=>2.8,
    'widget_id'=>'a1','page'=>'/tours/','occurrences'=>1,'worst_text'=>'Luxury cruise',
    'examples'=>[['page'=>'/tours/']]]]]
];
$file=$folder.'/current.json';
file_put_contents($file,json_encode($data));
try {
 evidence_check($c::fresh()===false && $c::issues()===[],
  'Absent immutable local browser observation cannot imply visual coverage');
 $c::cli_import([$file],[]);
 evidence_check($GLOBALS['pres_browser_writes']===1 && $c::fresh()===true,
  'Only valid local site and locale browser evidence is admitted');
 $summary=$c::summary();
 evidence_check($summary['fresh']===true && $summary['link_groups']===1
  && $summary['contrast_groups']===1 && $summary['read_only']===true,
  'Valid original browser evidence should have bounded read-only summary');
 $issues=$c::issues();
 evidence_check(count($issues)===2 && $issues[0]['severity']==='high'
  && $issues[1]['severity']==='high'
  && strpos($issues[0]['recommendation'],'do not auto-override')!==false,
  'Observed link and image contrast issues are review candidates, not CSS authorizations');
 // A link to a valid JSON inside the same allowed parent remains a symlink.
 // Do not let realpath erase the link identity before the file check.
 $symlink=$folder.'/linked.json';
 symlink($file,$symlink);
 $before=$GLOBALS['pres_browser_writes'];
 $denied=false;
 try {$c::cli_import([$symlink],[]);}catch(RuntimeException $e){$denied=true;}
 evidence_check($denied && $GLOBALS['pres_browser_writes']===$before,
  'Symbolic link aliases must be refused BEFORE resolving realpath');
 unlink($symlink);
 $foreign=$data;$foreign['site_host']='another-company.invalid';
 $other=$folder.'/foreign.json';file_put_contents($other,json_encode($foreign));
 $denied=false;
 try {$c::cli_import([$other],[]);}catch(RuntimeException $e){$denied=true;}
 evidence_check($denied && $GLOBALS['pres_browser_writes']===$before,
  'Foreign company browser observation is not valid for this WordPress origin');
 $bad=$data;$bad['generated_at']=gmdate('c',time()-90000);
 $stale=$folder.'/stale.json';file_put_contents($stale,json_encode($bad));
 $denied=false;
 try {$c::cli_import([$stale],[]);}catch(RuntimeException $e){$denied=true;}
 evidence_check($denied && $GLOBALS['pres_browser_writes']===$before,
  'Expired local report cannot replace a good browser snapshot');
 $bad=$data;$bad['locale_coverage']=['fr'];
 $incomplete=$folder.'/incomplete.json';file_put_contents($incomplete,json_encode($bad));
 $denied=false;
 try {$c::cli_import([$incomplete],[]);}catch(RuntimeException $e){$denied=true;}
 evidence_check($denied && $GLOBALS['pres_browser_writes']===$before,
  'Partial WPML language evidence cannot be represented as full visual coverage');
 evidence_check($c::fresh()===true,'Last valid snapshot is preserved after failures');
 echo "PRESENTATION_BROWSER_EVIDENCE: PASS exact-site readback, link/contrast review, local path and no symlink\n";
} finally {
 foreach(glob($folder.'/*.json') as $entry)unlink($entry);
 if(is_link($folder.'/linked.json'))unlink($folder.'/linked.json');
 rmdir($folder);rmdir(dirname($folder));rmdir(WP_CONTENT_DIR);
}
echo "PRESENTATION_BROWSER_EVIDENCE: PASS\n";

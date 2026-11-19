<?php
declare(strict_types=1);
define('ABSPATH','/');
$opts=[];$host='site-a.example';$env='staging';$writes=0;
function get_option($k,$d=null){global $opts;return $opts[$k]??$d;}
function update_option($k,$v,$a=false){global $opts,$writes;$writes++;$opts[$k]=$v;return true;}
function home_url($path='/'){global $host;return 'https://'.$host.$path;}
function wp_parse_url($url,$part){return parse_url($url,$part);}
function wp_get_environment_type(){global $env;return $env;}
function add_action(...$args){}
function add_filter(...$args){}
$source=getenv('MAD4B_CSS_DISPATCH_PLUGIN');
if(!$source || !is_file($source))throw new RuntimeException('MISSING_SOURCE');
require $source;
$cls=MAD4B_CCSS_WPCron_Dispatch_V1::class;
$n=0;
function check($label,$good){global $n;$n++;if(!$good)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
check('unknown site not automatically enrolled',$cls::site()===null && $cls::tasks()===[]);
$a=['site_id'=>'source','site_host'=>'site-a.example','environment'=>'staging',
 'tasks'=>['public'=>1800,'first_fold'=>900,'families'=>1800,'visual'=>86400,'audit'=>86400,'retention'=>604800]];
$opts[$cls::PROFILE]=$a;
check('source original interval mapping preserved',$cls::site()==='source' && $cls::tasks()===$a['tasks']);
$state=$cls::snapshot();
check('original WordPress queue state contract preserved',$state['site']==='source' && $state['pending']===[]);
$host='site-b.example';
check('foreign domain rejected',$cls::site()===null);
$host='site-a.example';$env='production';
check('environment mismatch rejected',$cls::site()===null);
$env='staging';
$opts[$cls::PROFILE]['tasks']['public']=120;
check('unapproved interval rejected',$cls::site()===null);
$opts[$cls::PROFILE]=$a;
$opts[$cls::PROFILE]['tasks']['unknown_task']=1800;
check('unknown task name rejected',$cls::site()===null);
$host='site-b.example';$env='production';
$b=['site_id'=>'wayfa','site_host'=>'site-b.example','environment'=>'production',
 'tasks'=>['public'=>1800,'first_fold'=>3600]];
$opts[$cls::PROFILE]=$b;
check('second tenant task policy isolated',$cls::site()==='wayfa' && $cls::tasks()===$b['tasks']);
$host='site-c.example';
$c=['site_id'=>'company_three','site_host'=>'site-c.example','environment'=>'production','tasks'=>['public'=>3600]];
$opts[$cls::PROFILE]=$c;
check('third tenant scheduler can be configured without code edits',$cls::site()==='company_three' && $cls::tasks()['public']===3600);
check('read-only policy tests caused no option writes',$writes===0);
echo "SITE_SCOPED_CSS_SCHEDULER_TESTS ".$n." PASS".PHP_EOL;

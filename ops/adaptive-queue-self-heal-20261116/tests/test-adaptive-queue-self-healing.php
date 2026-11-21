<?php
declare(strict_types=1);
define('ABSPATH','/mock/');
define('WP_CONTENT_DIR','/mock/wp-content');
define('WP_CLI',true);
$GLOBALS['options']=[
 'mad4b_scp_brand_context_profile_v1'=>[
  'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c','brand_id'=>str_repeat('a',32)],
 'home'=>'https://tours.dev.mad4b.com',
 'siteurl'=>'https://tours.dev.mad4b.com',
 'mad4b_css_ai_epoch_v1'=>123,
 'mad4b_adaptive_asset_job_queue_v1'=>[]
];
$GLOBALS['posts']=[
 176=>['type'=>'tour-rates','status'=>'publish','tour'=>102],
 102=>['type'=>'tours-and-activities','status'=>'publish'],
 151=>['type'=>'transportations','status'=>'publish']
];
function wp_get_environment_type(){return 'staging';}
function home_url($path='/'){return 'https://tours.dev.mad4b.com'.$path;}
function site_url($path='/'){return home_url($path);}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['options'][$key]=$value;return true;}
function sanitize_key($str){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$str));}
function sanitize_text_field($str){return strip_tags((string)$str);}
function wp_is_post_revision($id){return false;}
function wp_is_post_autosave($id){return false;}
function get_post_type($id){return $GLOBALS['posts'][$id]['type']??null;}
function get_post_status($id){return $GLOBALS['posts'][$id]['status']??null;}
function get_post_type_object($type){return (object)['public'=>true];}
function get_post_meta($id,$key,$single=true){return $key==='related_tour_id'?($GLOBALS['posts'][$id]['tour']??0):0;}
function get_permalink($id){$type=get_post_type($id);return 'https://tours.dev.mad4b.com/'.$type.'/'.$id.'/';}
function do_action(...$args){}
function add_action(...$args){}
function add_filter(...$args){}
function wp_doing_ajax(){return false;}
class WP_Post{public string $post_status='publish';public string $post_type='tour-rates';}
class MAD4B_Cache_Lifecycle_V1{public static function home_impact_preview(array $ids):array{return ['purge'=>false];}}
require $argv[1];
function chk($expr,$name){if(!$expr){fwrite(STDERR,"FAIL $name\n");exit(1);}echo "PASS $name\n";}
$c='MAD4B_SCP_Adaptive_Asset_Intelligence';
$r=$c::mark_dirty('wp_content_saved','/tours-and-activities/102/',false);
chk($r['queued']===true,'route queued');
chk(count($c::due_worker_jobs())===1,'pending worker sees job');
$oldJob=$c::due_worker_jobs()[0];
$oldToken=$oldJob['source_token'];
chk((bool)preg_match('/^[a-f0-9]{32}$/D',$oldToken),'immutable worker source token provided');
$r=$c::acknowledge_worker('/tours-and-activities/102/','hold','parity failed',$oldToken);
chk($r['retry_scheduled']===true && $r['attempts']===1,'failed browser keeps job for retry');
chk(count($c::queue())===1 && $c::queue()[0]['state']==='hold','HOLD does not erase job');
chk($c::due_worker_jobs()===[],'retry backoff prevents hot loop');
$r=$c::acknowledge_worker('/tours-and-activities/102/','failed','timeout');
chk($r['retry_scheduled']===true && $r['attempts']===2,'second failure increases backoff');
$r=$c::mark_dirty('wp_content_saved','/tours-and-activities/102/',false);
chk($r['deduplicated']===true,'new edit retains single queued job');
chk($c::queue()[0]['state']==='pending' && ($c::queue()[0]['attempts']??99)===0,'new source resets expired proof backoff');
chk(count($c::due_worker_jobs())===1,'new update triggers immediate eligibility');
$stale=$c::acknowledge_worker('/tours-and-activities/102/','certified','old browser success',$oldToken);
chk(($stale['error']??'')==='source_revision_changed','stale browser proof cannot retire freshly edited source');
chk(count($c::queue())===1,'new job survives outdated QA response');
$newToken=$c::due_worker_jobs()[0]['source_token'];
chk($newToken!==$oldToken,'source version token rotates on new content');
$r=$c::acknowledge_worker('/tours-and-activities/102/','native','safe fallback',$newToken);
chk($r['matched']===1 && $c::queue()===[],'verified native resolution retires job');
$post=new WP_Post();$post->post_type='tour-rates';
$c::post_saved(176,$post,true);
$j=$c::queue();
chk(count($j)===1,'tour rate creates one related render work item');
chk($j[0]['path']==='/tours-and-activities/102/','internal rate routes canonical linked tour');
chk($j[0]['home_css_dependency']===false,'rate inherits proven unrelated homepage impact');
$c::acknowledge_worker('/tours-and-activities/102/','native','verified fallback');
$c::post_saved(176,$post,false);
chk(count($c::queue())===1 && $c::queue()[0]['path']==='/tours-and-activities/102/',
    'newly published rate also queues canonical parent');
$unlinked=new WP_Post();$unlinked->post_type='tour-rates';
$GLOBALS['posts'][177]=['type'=>'tour-rates','status'=>'publish'];
$c::post_saved(177,$unlinked,true);
chk(count($c::queue())===2,'unlinked internal source triggers canonical discovery');
chk($c::queue()[0]['path']==='/' && $c::queue()[0]['home_css_dependency']===true,'unknown rate never fabricated as public URL');
echo "PASS 20/20 adaptive queue/source reconciliation assertions\n";

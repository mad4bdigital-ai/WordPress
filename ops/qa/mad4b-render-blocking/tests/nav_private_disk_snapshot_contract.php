<?php
/** Self-contained safety regression for a bounded, Staging-only private nav fragment disk snapshot. */
namespace {
define('ABSPATH',__DIR__);
function add_action(...$args){}
class WP_Term {
 public $term_id=105, $term_taxonomy_id=205, $taxonomy='destinations';
 public $name='Luxor', $slug='luxor', $description='', $parent=0, $count=7;
}
function wp_json_encode($x){return json_encode($x);}
function wp_is_mobile(){return false;}
function get_term_meta($id,$key='',$single=false){
 if($key==='featured_image')return 'https://tours.dev.mad4b.com/wp-content/uploads/luxor.webp';
 return ['featured_image'=>['https://tours.dev.mad4b.com/wp-content/uploads/luxor.webp']];
}
function get_term_link($term){return $GLOBALS['term_link']??'https://tours.dev.mad4b.com/destination/luxor/';}
function get_option($name){return match($name){'elementor_active_kit'=>60050,'permalink_structure'=>'/%postname%/','icl_sitepress_settings'=>['languages_order'=>['en','ar']],default=>''};}
function absint($x){return abs((int)$x);}
function home_url($p=''){return 'https://tours.dev.mad4b.com'.$p;}
function get_post_modified_time($format,$gmt,$id){return '1700000000';}
function get_post_meta($id,$key,$single){return ['color'=>'black'];}
function wp_get_theme(){return new class{function get($key){return '1.0.0';}};}
function must($bool,$what){if(!$bool){fwrite(STDERR,"FAIL $what\n");exit(1);}echo "PASS $what\n";}
$source=__DIR__.'/../../../../wp-content/mu-plugins/323-mad4b-nav-term-fragment-cache.php';
if(!is_file($source))$source='/tmp/mad4b-nav-cache.php';
require $source;
$klass='MAD4B_Nav_Term_Fragment_Cache_V1';
$sandbox = sys_get_temp_dir().'/m4-nav-snapshot-contract-'.getmypid();
if(!is_dir($sandbox))mkdir($sandbox,0700,true);
$root=new ReflectionProperty($klass,'snapshot_dir');
$root->setAccessible(true);
$root->setValue(null,$sandbox);
$get=new ReflectionMethod($klass,'snapshot_key');
$write=new ReflectionMethod($klass,'write_snapshot');
$read=new ReflectionMethod($klass,'read_snapshot');
$path=new ReflectionMethod($klass,'snapshot_path');
$term=new WP_Term();
$key=$get->invoke(null,hash('sha256','test-'.getmypid()),$term);
must(is_string($key)&&strlen($key)===64,'fingerprinted stable key');
must($get->invoke(null,hash('sha256','test-'.getmypid()),$term)===$key,'same public source same snapshot key');
$GLOBALS['term_link']='https://tours.dev.mad4b.com/destination/luxor-updated/';
must($get->invoke(null,hash('sha256','test-'.getmypid()),$term)!==$key,'public permalink change invalidates source');
unset($GLOBALS['term_link']);
must($get->invoke(null,hash('sha256','different-source'),$term)!==$key,'term metadata or source signature change invalidates snapshot');
$file=$path->invoke(null,$key);
must(str_starts_with($file,$sandbox.'/term-'),'only designated private directory is used');
must(str_contains(file_get_contents($source),'/run/mad4b-oauth/mad4b-public-nav-term-v1'),'production storage root remains private and pinned');
$html='<div class="elementor-element">Public destination link for Luxor <a href="/destination/luxor/">Luxor</a></div>';
try{
 $write->invoke(null,$key,$html);
 must(is_file($file),'snapshot written by native renderer');
 must((fileperms($file)&0777)===0600,'private snapshot file is mode 0600');
 $dir=dirname($file);
 must((fileperms($dir)&0777)===0700,'private snapshot directory is mode 0700');
 must($read->invoke(null,$key)===$html,'verified SHA-256 snapshot reloaded exactly');
 file_put_contents($file,"bad checksum\n".$html);
 must($read->invoke(null,$key)===null,'corrupted or truncated snapshot fails closed');
 $write->invoke(null,$key,$html);
 touch($file,time()-605000);
 must($read->invoke(null,$key)===null,'expired snapshot falls back to original JetEngine renderer');
 unlink($file);
 $write->invoke(null,$key,'<script>alert("x")</script>'.$html);
 must(!is_file($file),'script payload is never persisted as public term fragment');
 $write->invoke(null,$key,'<form method="POST">'.$html.'</form>');
 must(!is_file($file),'form payload is never persisted as public term fragment');
 must($path->invoke(null,'../../etc/passwd')===null,'snapshot names reject path traversal');
 symlink('/etc/passwd',$file);
 must($read->invoke(null,$key)===null,'symbolic link cannot be loaded');
}finally{
 if(is_link($file)||is_file($file))unlink($file);
 if(is_dir($sandbox))rmdir($sandbox);
}
$code=file_get_contents($source);
foreach(['self::SNAPSHOT_MAX_BYTES','self::SNAPSHOT_MAX_AGE',"self::JSON_SHA256",
 'get_term_link($term)','get_option(\'icl_sitepress_settings\')','_wp_attachment_metadata',
 'is_link($dir)','realpath($dir)','get_post_modified_time',
 'HTTP_X_MAD4B_NAV_CACHE_CONTROL','HTTP_COOKIE','is_post_type_archive'] as $needle){
 must(str_contains($code,$needle),'source guard '.substr($needle,0,50));
}
must(!str_contains($code,'update_option(')&&!str_contains($code,'set_transient('),'no persistent WordPress option mutation');
}

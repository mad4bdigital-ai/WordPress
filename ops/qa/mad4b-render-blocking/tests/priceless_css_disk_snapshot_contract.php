<?php
/** Staging CSS-only snapshot safety; no WordPress bootstrap and no live-site mutation. */
define('ABSPATH',__DIR__);
function add_action(...$args){}
$source_file=__DIR__.'/../../../../wp-content/mu-plugins/324-mad4b-priceless-dynamic-css-cache.php';
if(!is_file($source_file))$source_file='/tmp/mad4b-css-disk-final.php';
require $source_file;
function must($result,$label){if(!$result){fwrite(STDERR,"FAIL $label\n");exit(2);}echo "PASS $label\n";}
$cls='MAD4B_Priceless_CSS_Cache_V1';
$dir=sys_get_temp_dir().'/mad4b-dcss-test-'.getmypid();
if(!is_dir($dir))mkdir($dir,0700,true);
$r=new ReflectionProperty($cls,'disk_dir');$r->setAccessible(true);$r->setValue(null,$dir);
$path=new ReflectionMethod($cls,'disk_path');
$write=new ReflectionMethod($cls,'write_css_snapshot');
$read=new ReflectionMethod($cls,'read_css_snapshot');
$key=hash('sha256','test-source-'.getmypid());
$uid='1382370036719597';
$prefix='<style type="text/css">.elementor-dcss-'.$uid.' .elementor-element {color:#123456;}</style>';
$canonical=$cls::prepare_css_cache($prefix,$uid);
must(is_string($canonical)&&str_contains($canonical,'__MAD4B_CURRENT_DCSS_UNIQUE_ID__'),'CSS markers normalized');
$dynamic='<div class="tour-price">Live USD 135</div>';
$rendered=$cls::serve_cached_css($canonical,'1382374910633621',$dynamic);
must(str_contains($rendered,'1382374910633621')&&str_ends_with($rendered,$dynamic),'fresh dynamic card HTML appended and current selector restored');
$file=$path->invoke(null,$key);
try{
 $write->invoke(null,$key,$canonical);
 must(is_file($file),'bounded private snapshot created');
 must((fileperms($file)&0777)===0600,'snapshot mode 0600');
 must((fileperms($dir)&0777)===0700,'private directory mode 0700');
 must($read->invoke(null,$key)===$canonical,'exact cached CSS read');
 file_put_contents($file,"wrong".str_repeat("x",61)."\n".$canonical);
 must($read->invoke(null,$key)===false,'corruption forces native renderer');
 $write->invoke(null,$key,$canonical);
 touch($file,time()-260000);
 must($read->invoke(null,$key)===false,'expired CSS never read');
 unlink($file);
 $write->invoke(null,$key,'<style type="text/css"><script>alert(1)</script>__MAD4B_CURRENT_DCSS_UNIQUE_ID__</style>');
 must(!file_exists($file),'script payload never persisted');
 must($path->invoke(null,'../../etc/passwd')===null,'traversal is rejected');
 symlink('/etc/passwd',$file);
 must($read->invoke(null,$key)===false,'symbolic link cannot be read');
}finally{
 if(is_file($file)||is_link($file))unlink($file);
 if(is_dir($dir))rmdir($dir);
}
$source=file_get_contents($source_file);
foreach(['get_post_meta($post_id)','post_modified_gmt','prepare_css_cache',
 'get_woocommerce_currency()','HTTP_COOKIE','wp_get_environment_type()',
 'mad4b_css_private_snapshot_prune_v1','DISK_DIR','DISK_MAX_BYTES'] as $needle) {
 must(str_contains($source,$needle),'source guard '.substr($needle,0,42));
}
must(!str_contains($source,'update_option('),'no option changes');

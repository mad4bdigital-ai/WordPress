<?php
/** Off-request, fail-closed graph compiler. Run via: wp eval-file PATH 41878 */
if(!defined('WP_CLI') || !WP_CLI || PHP_SAPI!=='cli')throw new RuntimeException('CLI_WORDPRESS_ONLY');
$popup=(int)($args[0]??0);
if($popup<1 || get_post_status($popup)!=='publish')throw new RuntimeException('PUBLISHED_POPUP_REQUIRED');
$root=(int)get_option('page_on_front');
$langs=['en','es'];$home=[];
foreach($langs as $lang){
    $id=(int)apply_filters('wpml_object_id',$root,'page',false,$lang);
    if(!$id || get_post_status($id)!=='publish')throw new RuntimeException('MISSING_HOME_'.$lang);
    $home[$lang]=$id;
}
$max=250;$depthMax=20;$seen=[];$errors=[];$uael=[];
$scan=function(int $id,int $depth)use(&$scan,&$seen,&$errors,&$uael,$max,$depthMax):void{
    if(isset($seen[$id]))return;
    if($depth>$depthMax || count($seen)>=$max){$errors[]='GRAPH_BUDGET_EXCEEDED';return;}
    $post=get_post($id);
    if(!$post || $post->post_status!=='publish'
       || !in_array($post->post_type,['page','elementor_library','elementor-hf'],true)){
        $errors[]='INVALID_TEMPLATE_ID:'.$id;return;
    }
    $raw=get_post_meta($id,'_elementor_data',true);
    $tree=is_string($raw)?json_decode($raw,true):$raw;
    if(!is_array($tree)){$errors[]='INVALID_ELEMENTOR_DATA:'.$id;return;}
    $seen[$id]=['id'=>$id,'modified'=>$post->post_modified_gmt,'bytes'=>strlen((string)$raw),'sha256'=>hash('sha256',(string)$raw),'refs'=>[]];
    $refs=[];$badShortcodes=[];
    $walk=function($els)use(&$walk,&$refs,&$uael,&$errors,$id,&$badShortcodes):void{
        foreach((array)$els as $el){
            if(!is_array($el))continue;
            $widget=(string)($el['widgetType']??'');
            $settings=(array)($el['settings']??[]);
            if($widget==='template'){
                $next=(int)($settings['template_id']??0);
                if($next>0)$refs[]=$next;
                else $errors[]='UNRESOLVED_TEMPLATE:'.$id;
            } elseif($widget==='shortcode'){
                $str=(string)($settings['shortcode']??'');
                preg_match_all('/(?:elementor-template|template)\s+id\s*=\s*[\x22\x27]?([0-9]{1,8})/i',$str,$m);
                foreach($m[1] as $ref)$refs[]=(int)$ref;
                if(preg_match('/\[\s*(?:elementor-template|template)\b/i',$str) && !$m[1]){
                    $errors[]='UNRESOLVED_NESTED_SHORTCODE:'.$id;
                }
            }
            if(preg_match('/^(?:uael|uae)(?:-|_)/i',$widget)){
                $blocked=true;
                // We only permit this known subscriber-only gallery in
                // anonymous home; all authenticated requests keep full UAEL.
                if($widget==='uael-image-gallery'){
                    $conditions=(array)($settings['display_condition_list']??[]);
                    $blocked=!(count($conditions)>0 && count(array_filter($conditions,
                        static fn($c)=>is_array($c)&&($c['display_condition_login_status']??'')==='subscriber'))>0);
                }
                $uael[]=['post_id'=>$id,'element_id'=>$el['id']??null,'widget'=>$widget,'subscriber_only'=>$blocked===false];
                if($blocked)$errors[]='UNSAFE_UAEL_WIDGET:'.$id.':'.$widget;
            }
            foreach($settings as $key=>$val){
                if(preg_match('/^(?:uael|uae)[_-]|_uael_|uael_particle/i',(string)$key)
                   && $val!=='' && $val!=='no' && $val!=='none' && $val!=='default'){
                    $errors[]='UNVERIFIED_UAEL_SETTING:'.$id.':'.$key;
                }
            }
            if(!empty($el['elements']))$walk($el['elements']);
        }
    };
    $walk($tree);
    $refs=array_values(array_unique(array_filter(array_map('intval',$refs))));
    $seen[$id]['refs']=$refs;
    foreach($refs as $next)$scan($next,$depth+1);
};
foreach(array_values($home) as $id)$scan($id,0);
// Include published global Elementor headers and footers along with
// their WPML translations, even when their library ID is not in page JSON.
$shared=[];
$global=get_posts([
 'post_type'=>'elementor_library','post_status'=>'publish',
 'posts_per_page'=>1000,'fields'=>'ids','suppress_filters'=>true,
 'meta_query'=>[['key'=>'_elementor_template_type','value'=>['header','footer'],'compare'=>'IN']]
]);
if(count($global)>=1000)$errors[]='GLOBAL_TEMPLATE_BUDGET_EXCEEDED';
foreach($global as $candidate){
    if(!in_array('include/general',(array)get_post_meta((int)$candidate,'_elementor_conditions',true),true))continue;
    foreach($langs as $lang){
        $localized=(int)apply_filters('wpml_object_id',(int)$candidate,'elementor_library',false,$lang);
        if($localized>0)$shared[$localized]=true;
        else $errors[]='MISSING_SHARED_TRANSLATION:'.$candidate.':'.$lang;
    }
}
foreach(array_keys($shared) as $id)$scan((int)$id,0);
$scan($popup,0);
$css=WP_PLUGIN_DIR.'/ultimate-elementor/assets/min-css/uael-frontend.min.css';
$st=@stat($css);
if(!$st || !defined('UAEL_VER'))$errors[]='UAEL_CSS_OR_VERSION_UNAVAILABLE';
if(!$seen)$errors[]='EMPTY_GRAPH';
$manifest=[
  'contract'=>'mad4b.uael-home.manifest.v1',
  'site_home'=>(string)get_option('home'),
  'generated_at'=>gmdate('c'),
  'epoch'=>(string)get_option('mad4b_uael_home_tree_epoch_v1','0'),
  'uael_version'=>defined('UAEL_VER')?UAEL_VER:'',
  'uael_css_size'=>$st['size']??-1,
  'uael_css_mtime'=>$st['mtime']??-1,
  'home_ids'=>$home,
  'popup_id'=>$popup,
  'shared_templates'=>array_values(array_map('intval',array_keys($shared))),
  'checked_templates'=>count($seen),
  'uael_widgets'=>$uael,
  'ready'=>count($errors)===0,
  'issues'=>$errors,
  'tree'=>array_values($seen)
];
$candidate_mode=(getenv('MAD4B_UAEL_MANIFEST_CANDIDATE')==='1');
$dir=WP_CONTENT_DIR.'/mad4b-uael-home-v1';
if(!is_dir($dir) && !wp_mkdir_p($dir))throw new RuntimeException('CANNOT_CREATE_MANIFEST_DIR');
$target=$dir.'/'.($candidate_mode?'candidate.json':'manifest.json');
$bytes=wp_json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$old=is_file($target)?file_get_contents($target):null;
$prior=is_string($old)?json_decode($old,true):null;
if(is_array($prior)){
    $prevComparable=$prior;$nextComparable=$manifest;
    unset($prevComparable['generated_at'],$nextComparable['generated_at']);
    // Keep a current immutable proof byte-for-byte. Refresh its timestamp
    // *before* the 7-day runtime TTL expires, not after the proof has lapsed.
    // A changed Elementor graph, widget version or epoch never inherits time.
    $priorTime=strtotime((string)($prior['generated_at']??''));
    if($prevComparable===$nextComparable && $priorTime!==false
       && $priorTime<=time()+300 && time()-$priorTime<5*DAY_IN_SECONDS){
        $manifest['generated_at']=$prior['generated_at'];
        $bytes=$old;
    }
}
$dry_run=(getenv('MAD4B_UAEL_MANIFEST_DRY_RUN')==='1');
$changed=$old!==$bytes;
if(!$dry_run && $changed){
    $temp=tempnam($dir,'.uael-manifest-');
    if($temp===false)throw new RuntimeException('MANIFEST_TEMP_FAIL');
    try{
        if(file_put_contents($temp,$bytes)!==strlen($bytes))throw new RuntimeException('MANIFEST_WRITE_FAIL');
        chmod($temp,0644);
        if(!rename($temp,$target))throw new RuntimeException('MANIFEST_ATOMIC_PUBLISH_FAIL');
    }finally{if(is_file($temp))unlink($temp);}
}
// Builder is off-request. Invalidate two public homepaths only when the
// decision changes; never Purge All or touch the visitor's HTML-render path.
if(!$dry_run && $changed && !$candidate_mode && get_option('mad4b_uael_home_selective_v1','off')==='on'){
    $paths=['/','/es/'];
    if(class_exists('MAD4B_Cache_Control_V1'))
        MAD4B_Cache_Control_V1::socket_purge($paths);
    $base=rtrim((string)get_option('home'),'/');
    foreach($paths as $path)do_action('litespeed_purge_url',$base.$path);
}
echo wp_json_encode(['ready'=>$manifest['ready'],'checked_templates'=>count($seen),
  'uael_widgets'=>$uael,'errors'=>$errors,'home_ids'=>$home,
  'css_bytes'=>$manifest['uael_css_size'],'epoch'=>$manifest['epoch'],
  'manifest'=>$target,'dry_run'=>$dry_run,'candidate_only'=>$candidate_mode,
  'shared_templates'=>$manifest['shared_templates']],JSON_UNESCAPED_SLASHES),PHP_EOL;
if(!$manifest['ready'])WP_CLI::error('HOME_UAEL_GRAPH_NOT_READY');


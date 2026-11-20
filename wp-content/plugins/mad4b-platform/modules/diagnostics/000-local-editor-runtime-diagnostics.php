<?php
/**
 * Plugin Name: Local Lab Elementor Editor Runtime Diagnostics
 * Description: Limited, authenticated, non-mutating error/boot telemetry for 40299/45152.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;

add_action('wp_ajax_lab_elementor_editor_probe', static function ():void {
    $id = absint($_POST['document'] ?? 0);
    if (!in_array($id, [40299,45152], true) ||
        !current_user_can('edit_post', $id) ||
        !check_ajax_referer('lab_elementor_editor_probe_' . $id, 'nonce', false)) {
        wp_send_json_error(['accepted'=>false], 403);
    }
    $type = sanitize_key(wp_unslash($_POST['type'] ?? ''));
    if (!in_array($type,['js_error','promise_rejection','boot','resource_error'],true))
        wp_send_json_error(['accepted'=>false],400);

    $safe = static function($str, $len = 180) {
       $s = sanitize_text_field(wp_unslash((string)$str));
       $s = preg_replace('~https?://\S+~i','[url]',$s);
       $s = preg_replace('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i','[email]',$s);
       $s = preg_replace('/[a-zA-Z0-9_-]{32,}/','[value]',$s);
       return substr($s,0,$len);
    };
    $source = $safe($_POST['source']??'',160);
    $message = $safe($_POST['message']??'',180);
    $state = $safe($_POST['state']??'',220);
    $entry=['utc'=>gmdate('Y-m-d\TH:i:s\Z'),'document'=>$id,
        'event'=>$type,'source'=>$source,'message'=>$message,'state'=>$state];
    $path=sys_get_temp_dir().'/lab-elementor-editor-diagnostics-20261027.jsonl';
    if (is_file($path) && filesize($path)>180000)wp_send_json_success(['accepted'=>false]);
    file_put_contents($path,wp_json_encode($entry,JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
    @chmod($path,0600);
    wp_send_json_success(['accepted'=>true]);
},1);

add_action('elementor/editor/before_enqueue_scripts',static function():void {
    $id=absint($_GET['post']??0);
    // Detailed telemetry is opt-in only; posting multiple diagnostics while
    // the heavy editor boots otherwise doubles WP auth/proxy work.
    if (($_GET['lab_editor_probe'] ?? '') !== '1') return;
    if(!in_array($id,[40299,45152],true)||!current_user_can('edit_post',$id))return;
    wp_register_script('lab-elementor-runtime-probe',false,[],null,false);
    wp_enqueue_script('lab-elementor-runtime-probe');
    $nonce=wp_create_nonce('lab_elementor_editor_probe_'.$id);
    $config=wp_json_encode(['id'=>$id,'nonce'=>$nonce,'url'=>admin_url('admin-ajax.php')]);
    $script=<<<'JS'
(function () {
'use strict';
var cfg=__CFG__,sent=0;
function send(type,message,source,state){
 if(sent++>24)return;
 var body=new URLSearchParams({action:'lab_elementor_editor_probe',
  document:String(cfg.id),nonce:cfg.nonce,type:type,
  message:String(message||'').slice(0,180),
  source:String(source||'').slice(0,160),
  state:String(state||'').slice(0,220)});
 fetch(cfg.url,{method:'POST',credentials:'same-origin',
  headers:{'Content-Type':'application/x-www-form-urlencoded'},
  body:body.toString(),keepalive:true}).catch(function(){});
}
function snapshot(){
 return JSON.stringify({
  editor:typeof window.elementor,
  v2:typeof window.elementorV2,
  editorConfig:typeof window.ElementorConfig,
  iframe:!!document.querySelector('#elementor-preview-iframe'),
  loading:!!document.querySelector('#elementor-loading'),
  stage:document.readyState
 });
}
window.addEventListener('error',function(e){
 var src='';
 try{src=new URL(e.filename,location.href).pathname;}catch(_){}
 if(e.target && e.target!==window &&
    (e.target.tagName==='SCRIPT'||e.target.tagName==='LINK')){
  var candidate=e.target.src||e.target.href||'';
  try{src=new URL(candidate,location.href).pathname;}catch(_){}
  send('resource_error','failed resource',src,snapshot());return;
 }
 send('js_error',e.message,src,snapshot());
},true);
window.addEventListener('unhandledrejection',function(e){
 var reason=e.reason,err=reason&&reason.message?reason.message:String(reason||'unknown');
 send('promise_rejection',err,'',snapshot());
});
send('boot','hook installed','',snapshot());
document.addEventListener('DOMContentLoaded',function(){send('boot','dom_ready','',snapshot())},{once:true});
window.addEventListener('elementor/init',function(){send('boot','elementor_init','',snapshot())},{once:true});
window.addEventListener('load',function(){send('boot','window_load','',snapshot())},{once:true});
setTimeout(function(){send('boot','after_5s','',snapshot())},5000);
setTimeout(function(){send('boot','after_15s','',snapshot())},15000);
})();
JS;
    wp_add_inline_script('lab-elementor-runtime-probe',str_replace('__CFG__',$config,$script),'after');
},1);

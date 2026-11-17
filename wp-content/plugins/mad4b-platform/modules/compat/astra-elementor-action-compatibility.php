<?php
if(!defined('ABSPATH'))exit;

/** Opt-in Astra / Elementor hash compatibility with safe ordinary anchors. */
add_action('wp_enqueue_scripts', static function () {
 if (!defined('ASTRA_ELEMENTOR_ACTION_COMPAT_ENABLED') || !ASTRA_ELEMENTOR_ACTION_COMPAT_ENABLED) return;
 if (!wp_script_is('astra-theme-js', 'registered')) return;
 $js = <<<'JS'
(function(){
 if(window.__etgAstraHashCompat)return;
 window.__etgAstraHashCompat=true;
 if(window.astra)window.astra.is_scroll_to_id='';
 document.addEventListener('click',function(e){
  if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
  var a=e.target && e.target.closest && e.target.closest('a[href]');if(!a)return;
  var raw=a.getAttribute('href')||'';
  if(/elementor-action(?:%3a|:)/i.test(raw))return;
  var hash=a.hash;if(!hash||hash==='#'||hash==='#0')return;
  if(a.origin!==location.origin||a.pathname!==location.pathname||a.search!==location.search)return;
  var id;try{id=decodeURIComponent(hash.slice(1));}catch(_){return;}
  if(!id||/[^\w\-:.]/.test(id))return;
  var target=document.getElementById(id);if(!target)return;
  e.preventDefault();target.scrollIntoView({behavior:window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});
  if(location.hash!==hash)history.pushState(null,'',hash);
 },false);
})();
JS;
 wp_add_inline_script('astra-theme-js', $js, 'before');
},1000);

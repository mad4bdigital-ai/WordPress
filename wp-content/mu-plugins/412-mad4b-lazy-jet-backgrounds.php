<?php
/**
 * Plugin Name: MAD4B — Visible Listing Background Loading
 * Version: 1.0.0
 * Description: Tenant-aware lazy loading for noncritical JetEngine image backgrounds on public homepages.
 */
defined('ABSPATH') || exit;
final class MAD4B_Visible_Listing_Backgrounds_V1 {
    public static function enabled(): bool {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || is_user_logged_in()) return false;
        // Tenant-local option and verified MAD4B first-paint context determine eligibility.
        // Never assume one tenant's hostname, term IDs, language list, or homepage slug.
        if (!class_exists('MAD4B_Shared_First_Paint_Performance', false)) return false;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') return false;
        $probe = isset($_GET['mad4b_lazy_card_probe']) ? (string)$_GET['mad4b_lazy_card_probe'] : null;
        if ($probe === '0') return false; // Temporary A/B comparison, never turns off for other pages.
        if ($probe !== '1' && get_option('mad4b_perf_lazy_jet_backgrounds_v1','off') !== 'on') return false;

        return MAD4B_Shared_First_Paint_Performance::home_view();
    }
    public static function boot():void {
        if (!self::enabled())return;
        echo <<<'HTML'
<script id="mad4b-card-bg-lazy-init" data-no-optimize="1">
(function(){
  var root=document.documentElement;
  if(!('IntersectionObserver' in window))return;
  root.classList.add('mad4b-card-bg-lazy');
  function init(){
    try {
      var cards=document.querySelectorAll('.jet-listing-grid__item');
      var stats={total:cards.length,activated:0};
      window.mad4bCardBgStats=stats;
      var observer=new IntersectionObserver(function(entries){
        for(var i=0;i<entries.length;i++){
          var e=entries[i];
          if(e.isIntersecting){
            if(!e.target.hasAttribute('data-mad4b-card-bg-ready')){
              e.target.setAttribute('data-mad4b-card-bg-ready','1');stats.activated++;
            }
            observer.unobserve(e.target);
          }
        }
      },{rootMargin:'350px 0px',threshold:0.01});
      var seen=new WeakSet();
      for(var j=0;j<cards.length;j++){seen.add(cards[j]);observer.observe(cards[j]);}
      // JetEngine AJAX may append listing cards after DOMContentLoaded.
      // Observe only later DOM additions: never activate cards during parser layout.
      if('MutationObserver' in window && document.body){
        var mo=new MutationObserver(function(records){
          function register(card){
            if(seen.has(card))return;
            seen.add(card);stats.total++;
            observer.observe(card);
          }
          for(var a=0;a<records.length;a++){
            var nodes=records[a].addedNodes;
            for(var b=0;b<nodes.length;b++){
              var node=nodes[b];
              if(node.nodeType!==1)continue;
              if(node.matches('.jet-listing-grid__item'))register(node);
              if(node.childElementCount){
                var added=node.querySelectorAll('.jet-listing-grid__item');
                for(var c=0;c<added.length;c++)register(added[c]);
              }
            }
          }
        });
        mo.observe(document.body,{childList:true,subtree:true});
      }
      if(!cards.length)root.classList.remove('mad4b-card-bg-lazy');
    }catch(err){root.classList.remove('mad4b-card-bg-lazy');}
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
  else init();
})();
</script>
<style id="mad4b-card-bg-lazy-css" data-no-optimize="1">
html.mad4b-card-bg-lazy .jet-listing-grid__item:not([data-mad4b-card-bg-ready]) :is(.elementor-element-035c060,.elementor-element-46370df3,.elementor-element-1bc9269,.elementor-element-85bc6bc):not([data-mad4b-media-owned]),
html.mad4b-card-bg-lazy .jet-listing-grid__item:not([data-mad4b-card-bg-ready]) :is(.elementor-element-035c060,.elementor-element-46370df3,.elementor-element-1bc9269,.elementor-element-85bc6bc):not([data-mad4b-media-owned]) > .elementor-motion-effects-container > .elementor-motion-effects-layer
{background-image:none!important}
</style>
HTML;
    }
}
add_action('wp_head',['MAD4B_Visible_Listing_Backgrounds_V1','boot'],0);

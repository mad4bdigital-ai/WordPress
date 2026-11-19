<?php
/**
 * Plugin Name: MAD4B Elementor Direct Menu Link Contract
 * Description: Restore the native Elementor n-menu "link-only" distinction when
 * a title links directly and has no dropdown. Never intercept the click.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

add_action('wp_head', static function (): void {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    echo <<<'HTML'
<script id="mad4b-direct-menu-link-contract-v1" data-no-optimize="1" data-no-defer="1">
(function(){
  "use strict";
  if (window.MAD4BDirectMenuLinkGuard) return;
  const state={contract:"mad4b.n-menu.direct-link.v1",marked:0,corrected:0};
  window.MAD4BDirectMenuLinkGuard=state;
  let trackedHeader=null, observer=null, pending=false;
  const scan=()=>{
    const header=document.querySelector(".elementor-location-header");
    if (!header) return;
    header.querySelectorAll(".elementor-widget-n-menu .e-n-menu-title").forEach(title=>{
      let link=null, dropdown=false;
      for (const child of title.children) {
        if (child.matches("a.e-n-menu-title-container[href]")) link=child;
        if (child.matches(".e-n-menu-dropdown-icon,[data-tab-index]")) dropdown=true;
      }
      const href=link?.getAttribute("href")||"";
      const direct=!!href && href!=="#" && !/^\s*javascript:/i.test(href) && !dropdown;
      if (direct) {
        if (!title.classList.contains("link-only")) {
          title.classList.add("link-only");
          title.dataset.mad4bDirectLink="1";
          state.marked++;
        }
      } else if (title.dataset.mad4bDirectLink==="1") {
        title.classList.remove("link-only");
        delete title.dataset.mad4bDirectLink;
        state.corrected++;
      }
    });
    if (header!==trackedHeader) {
      observer?.disconnect();
      trackedHeader=header;
      observer=new MutationObserver(schedule);
      observer.observe(header,{childList:true,subtree:true});
    }
  };
  function schedule(){
    if (pending) return;
    pending=true;
    requestAnimationFrame(()=>{pending=false;scan()});
  }
  if (document.readyState==="loading") {
    document.addEventListener("DOMContentLoaded",scan,{once:true});
  } else scan();
  window.addEventListener("pageshow",schedule,{passive:true});
  document.addEventListener("mad4b:shell:navigated",schedule);
})();
</script>
HTML;
}, 4);

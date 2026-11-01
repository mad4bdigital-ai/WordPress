/* JetSearch Chosen renders a UI anchor without href; add a genuine public
 * archive fallback while preserving its keyboard and dropdown interaction. */
(()=>{'use strict';
  const catalog='/tours-and-activities/';
  const selector='.jet-ajax-search__categories-select + .chosen-container a.chosen-single';
  function upgrade(root){
    const candidates=[];
    if(root.nodeType===1&&root.matches?.(selector))candidates.push(root);
    if(root.querySelectorAll)candidates.push(...root.querySelectorAll(selector));
    for(const a of candidates){
      if(a.hasAttribute('href'))continue;
      a.setAttribute('href',catalog);
      // Chosen operates on pointer/mousedown. Do not navigate away on a
      // real dropdown click; href remains a valid no-script navigation target.
      a.addEventListener('click',event=>event.preventDefault());
      if(!a.hasAttribute('aria-label')){
        const label=a.querySelector('span')?.textContent?.trim();
        if(label)a.setAttribute('aria-label',label);
      }
    }
  }
  function init(){upgrade(document.body);const o=new MutationObserver(records=>{
    for(const r of records)for(const node of r.addedNodes)upgrade(node);
  });o.observe(document.body,{childList:true,subtree:true});}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();

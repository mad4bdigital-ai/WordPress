/* Presentation-only headings: the original native form and all source panels stay intact. */
(()=>{
  'use strict';
  const cfg=window.MAD4BWorkspaceShell;
  const root=document.querySelector('.jet-engine-options-page-wrap');
  if(!cfg||!root)return;
  const nav=root.querySelector('.mad4b-ws-nav');
  if(!nav)return;
  root.classList.add('mad4b-ws-unified-shell');
  const label=(text,kind)=>{
    const el=document.createElement('div');
    el.className='mad4b-ws-nav-group-label';
    el.dataset.mad4bNavGroup=kind;
    el.setAttribute('role','heading');
    el.setAttribute('aria-level','3');
    el.textContent=text;
    return el;
  };
  const reconcile=()=>{
    const first=nav.querySelector('.mad4b-ws-nav-btn:not(.mad4b-ws-service-nav)');
    if(first&&!nav.querySelector('[data-mad4b-nav-group="company"]')){
      nav.insertBefore(label(cfg.pricing?'Company-wide settings':'Site settings','company'),first);
    }
    const firstService=nav.querySelector('.mad4b-ws-service-nav,.mad4b-guide-cost-nav');
    if(cfg.pricing&&firstService&&!nav.querySelector('[data-mad4b-nav-group="product"]')){
      nav.insertBefore(label('Products & supplier services','product'),firstService);
    }
  };
  reconcile();
  // Services are appended by the separately owned pricing composition
  // runtime, often after Functional Modes' initial load.
  if(cfg.pricing && !nav.querySelector('[data-mad4b-nav-group="product"]')){
    const obs=new MutationObserver(()=>{
      reconcile();
      if(nav.querySelector('[data-mad4b-nav-group="product"]'))obs.disconnect();
    });
    obs.observe(nav,{childList:true});
  }
})();

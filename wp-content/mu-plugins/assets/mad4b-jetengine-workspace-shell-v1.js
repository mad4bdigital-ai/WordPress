/* Presentation-only headings: the original native form and all source panels stay intact. */
(()=>{
  'use strict';
  const cfg=window.MAD4BWorkspaceShell;
  const root=document.querySelector('.jet-engine-options-page-wrap');
  if(!cfg||!root)return;
  const nav=root.querySelector('.mad4b-ws-nav');
  if(!nav)return;
  root.classList.add('mad4b-ws-unified-shell');
  // One header instead of a separate title card plus three dashboard cards.
  // Move only the existing metric strip, never vendor inputs or Save.
  const banner=root.querySelector('.mad4b-ws-banner');
  const metrics=root.querySelector('.mad4b-ws-summary');
  if(cfg.pricing && banner && metrics && metrics.parentNode!==banner){
    banner.append(metrics);
    root.classList.add('mad4b-ws-integrated-header');
  }
  // True structural composition. The COMPLETE original JetEngine section,
  // including its untouched form, nonce, original input names and Save button,
  // becomes a child of the shared content surface in Grouped mode.
  // Restore the *same* nodes to their original location in Native mode.
  // Do not copy, serialize, or create another form.
  const workspaceContent=root.querySelector('.mad4b-ws-content');
  // Put the original COMPANY field search beside the active section heading,
  // not above the shared navigation. Product sections have their own search
  // controls, while the navigation's start edge should never shift between
  // company and product tabs. Move the SAME input with its listeners intact.
  const fieldSearch=cfg.pricing?root.querySelector('.mad4b-ws-sidebar .mad4b-ws-search'):null;
  if(workspaceContent && fieldSearch &&
     !workspaceContent.querySelector('.mad4b-ws-find-settings')){
    const find=document.createElement('div');
    find.className='mad4b-ws-find-settings';
    find.setAttribute('role','search');
    find.setAttribute('aria-label','Find company pricing settings');
    find.append(fieldSearch);
    const title=workspaceContent.querySelector('.mad4b-ws-section-heading');
    if(title)title.insertAdjacentElement('afterend',find);
    else workspaceContent.prepend(find);
  }
  const original=[...root.children].find(el=>
    typeof el.classList?.contains==='function' &&
    el.classList.contains('mad4b-ws-original'));
  const ownedForm=original?.querySelector('form');
  const nativeEditors=(typeof root.querySelectorAll==='function'
    ?[...root.querySelectorAll('form')]:[]).filter(form=>
    form.querySelector('#settings_top') &&
    form.querySelector('button[type="submit"]'));
  const editorReady=Boolean(workspaceContent && original &&
    ownedForm && nativeEditors.length===1 &&
    nativeEditors[0]===ownedForm);
  if(editorReady){
    const anchor=document.createComment('mad4b-native-editor-original-position');
    root.insertBefore(anchor,original);
    const moveEditor=()=>{
      const grouped=root.classList.contains('mad4b-ws-visual');
      if(grouped && original.parentNode!==workspaceContent){
        workspaceContent.append(original);
      }else if(!grouped && original.parentNode!==root){
        root.insertBefore(original,anchor.nextSibling);
      }
      const composed=grouped && original.parentNode===workspaceContent;
      root.classList.toggle('mad4b-ws-editor-composed',composed);
      // Flat Settings Canvas is pricing-specific: the original JetEngine
      // editor remains fully native in non-grouped mode, and other JetEngine
      // option pages keep their own presentation contracts.
      root.classList.toggle('mad4b-ws-flat-canvas',Boolean(cfg.pricing && composed));
    };
    document.addEventListener('mad4b:jetengine:mode-changed',moveEditor);
    moveEditor();
  }
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
  // On mobile, keep section switching compact instead of forcing the full
  // company/products menu above every field. This operates on the ORIGINAL
  // navigation buttons; it never duplicates their actions or stored values.
  const sidebar=nav.parentElement;
  if(sidebar && typeof window.matchMedia==='function'){
    const small=window.matchMedia('(max-width:900px)');
    const sectionSwitch=document.createElement('button');
    sectionSwitch.type='button';
    sectionSwitch.className='mad4b-ws-mobile-section-toggle';
    sectionSwitch.textContent='Sections';
    if(!nav.id)nav.id='mad4b-ws-original-section-navigation';
    sectionSwitch.setAttribute('aria-controls',nav.id);
    sidebar.insertBefore(sectionSwitch,nav);
    let expanded=false;
    const refreshSections=()=>{
      const narrow=small.matches;
      sectionSwitch.hidden=!narrow;
      nav.hidden=narrow&&!expanded;
      const chosen=nav.querySelector('.mad4b-ws-nav-btn.is-active');
      const label=String(
        chosen?.querySelector(':scope > span')?.lastChild?.textContent ||
        chosen?.textContent || 'Choose a section'
      ).trim().replace(/\s+/g,' ');
      sectionSwitch.textContent='Sections · '+label;
      sectionSwitch.setAttribute('aria-expanded',String(!narrow||expanded));
    };
    sectionSwitch.addEventListener('click',()=>{
      expanded=!expanded;
      refreshSections();
    });
    nav.addEventListener('click',event=>{
      if(!small.matches||!event.target.closest('.mad4b-ws-nav-btn'))return;
      expanded=false;
      setTimeout(refreshSections,0);
      sectionSwitch.focus();
    });
    if(small.addEventListener)small.addEventListener('change',refreshSections);
    else if(small.addListener)small.addListener(refreshSections);
    refreshSections();
  }
  // One shared dimension policy belongs to the company. Product panels
  // inherit it; their service-specific settings stay with their real
  // original Cost/Rate editors. This callout never creates form inputs.
  const content=cfg.pricing?root.querySelector('.mad4b-ws-content'):null;
  if(content&&!content.querySelector('.mad4b-ws-product-context')){
    const context=document.createElement('section');
    context.className='mad4b-ws-product-context';
    context.hidden=true;
    context.setAttribute('aria-label','Product pricing configuration scope');
    const inner=document.createElement('div');
    const marker=document.createElement('span');
    marker.className='mad4b-ws-product-context__eyebrow';
    marker.textContent='Shared company targeting';
    const heading=document.createElement('strong');
    heading.className='mad4b-ws-product-context__title';
    const desc=document.createElement('p');
    desc.className='mad4b-ws-product-context__text';
    inner.append(marker,heading,desc);
    const action=document.createElement('button');
    action.className='button button-secondary mad4b-ws-product-context__action';
    action.type='button';
    action.textContent='Configure dimensions';
    action.addEventListener('click',()=>{
      const overview=nav.querySelector('.mad4b-ws-nav-btn[data-section="overview"]');
      if(!overview)return;
      overview.click();
      const configure=root.querySelector('.mad4b-ws-dimension-open');
      if(configure){
        if(configure.getAttribute('aria-expanded')!=='true')configure.click();
        configure.focus();
      }else overview.focus();
    });
    context.append(inner,action);
    // Outside JetEngine's original form; sibling of the field/navigation
    // summaries and existing supplier panels in the visual workspace.
    const headingElement=content.querySelector('.mad4b-ws-section-heading');
    if(headingElement?.nextSibling)
      content.insertBefore(context,headingElement.nextSibling);
    else content.append(context);
    const services={
      accommodation:{
        name:'Accommodation & Units',
        note:'Edit the Property, Unit and supplier-cost evidence below; targeting dimensions are inherited.'
      },
      transportation:{
        name:'Transportation',
        note:'Edit the original transfer/route costs below using Transport Save; targeting dimensions are inherited.'
      },
      guide:{
        name:'Guide Costs',
        note:'Edit guide-day coverage and Guide Language taxonomy costs in their original editors; targeting dimensions are inherited.'
      }
    };
    const activeProduct=()=>{
      const pane=String(root.dataset.mad4bPricingPane||'');
      if(services[pane])return pane;
      if(root.classList.contains('mad4b-guide-cost-tab-active'))return 'guide';
      return '';
    };
    const updateContext=()=>{
      const key=root.classList.contains('mad4b-ws-visual')?activeProduct():'';
      context.hidden=!key;
      if(!key)return;
      heading.textContent='Company-wide dimensions apply here';
      desc.textContent=services[key].name+': '+services[key].note;
      action.hidden=!root.querySelector('.mad4b-ws-dimension-open');
    };
    nav.addEventListener('click',updateContext);
    document.addEventListener('mad4b:jetengine:mode-changed',updateContext);
    // Guide/other modules may mount asynchronously and set their native
    // pane flag after our script. Restrict the observer to the root class
    // and product-pane attributes rather than watching the whole DOM.
    const viewObserver=new MutationObserver(updateContext);
    viewObserver.observe(root,{
      attributes:true,attributeFilter:['class','data-mad4b-pricing-pane']
    });
    updateContext();
  }
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

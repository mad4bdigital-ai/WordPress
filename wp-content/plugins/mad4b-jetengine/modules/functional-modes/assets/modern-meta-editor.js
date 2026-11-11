(() => {
  'use strict';
  const init=()=>{
    const wraps=[...document.querySelectorAll('.jet-engine-meta-wrap')]
      .filter(el=>!!el.querySelector('.cx-control, .cx-component'));
    if (!wraps.length || document.querySelector('#mad4b-jetengine-modern-toggle')) return;
    const toolbar=document.createElement('div');
    toolbar.id='mad4b-jetengine-modern-toggle';
    toolbar.className='mad4b-jetengine-modern-toolbar';
    const heading=document.createElement('div');
    heading.className='mad4b-jetengine-modern-toolbar-label';
    const title=document.createElement('strong');
    title.textContent='JetEngine · Modern Mode';
    const hint=document.createElement('small');
    hint.textContent='Same native fields and WordPress Save / Update';
    heading.append(title,hint);
    const toggle=document.createElement('button');
    toggle.type='button';
    toggle.className='button mad4b-jetengine-modern-switch';
    toggle.setAttribute('aria-pressed','true');
    let active=true;
    const update=()=>{
      document.body.classList.toggle('mad4b-jetengine-modern-editor',active);
      toggle.textContent=active?'Use classic fields':'Use Modern Mode';
      toggle.setAttribute('aria-pressed',String(active));
      document.dispatchEvent(new CustomEvent('mad4b:jetengine:mode-changed'));
    };
    toggle.addEventListener('click',()=>{active=!active;update();});
    toolbar.append(heading,toggle);
    const anchor=document.querySelector('#post-body-content')||
      document.querySelector('#poststuff')||wraps[0];
    anchor.prepend(toolbar);
    // Never append inputs to the vendor metabox, and never register a save
    // handler or a second Publish/Update button.
    update();
  };
  // Gutenberg/JetEngine may mount metaboxes after DOMContentLoaded.
  // Observe only until actual native metaboxes appear; never poll the server.
  const boot=()=>{
    init();
    if(document.querySelector('#mad4b-jetengine-modern-toggle'))return;
    if(!document.body||typeof MutationObserver!=='function')return;
    const observer=new MutationObserver(()=>{
      if(!document.querySelector('.jet-engine-meta-wrap .cx-control, .jet-engine-meta-wrap .cx-component'))return;
      observer.disconnect();
      init();
    });
    observer.observe(document.body,{childList:true,subtree:true});
    window.setTimeout(()=>observer.disconnect(),12000);
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});
  else boot();
})();

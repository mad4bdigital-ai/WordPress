/**
 * MAD4B JetEngine Recipe Registry — presentation-only.
 * Field values, names, inputs and native submission remain vendor-owned.
 */
(() => {
  'use strict';
  const cfg=window.MAD4BJetRecipeConfig;
  if(!cfg || !cfg.registry || !['options','cpt','form'].includes(cfg.interface))return;
  const r=cfg.registry;
  const allowed=new Set(['cards','compact','accordion']);
  const identifier=(value)=>String(value||'').replace(/[^A-Za-z0-9_-]/g,'').slice(0,110);
  const rules=(key)=>r[key] && typeof r[key]==='object'?r[key]:{};
  const resolve=(scope,target,group,field)=>{
    let mode=allowed.has(r.global)?r.global:'cards',source='global';
    for(const [level,candidate] of [
      ['interface',rules('interfaces')[scope]],
      ['target',rules('targets')[scope+':'+target]],
      ['group',rules('groups')[scope+':'+target+':'+group]],
      ['field',rules('fields')[scope+':'+target+':'+field]]
    ]) {
      if(allowed.has(candidate)){mode=candidate;source=level;}
    }
    return {mode,source};
  };
  const rootSelector=cfg.interface==='options'?
    '.jet-engine-options-page-wrap.mad4b-ws-visual #settings_top':
    cfg.interface==='cpt'?'body.mad4b-jetengine-modern-editor .jet-engine-meta-wrap':
      'body.mad4b-jetengine-form-recipe form.jet-form, body.mad4b-jetengine-form-recipe form.jet-form-builder';
  const groupOf=(node)=>{
    const explicit=node.closest('[data-mad4b-recipe-group]')?.getAttribute('data-mad4b-recipe-group');
    if(explicit)return identifier(explicit);
    if(cfg.interface==='options'){
      const panel=node.closest('.cx-settings__content[id]');
      return identifier(panel?.id||'');
    }
    const nativeGroup=node.closest('[data-group-id],.jet-form__section');
    return identifier(nativeGroup?.getAttribute('data-group-id')||nativeGroup?.id||'');
  };
  const fieldOf=(node)=>{
    const named=node.getAttribute('data-control-name')||node.getAttribute('data-field-name')||
      node.querySelector('[data-field-name]')?.getAttribute('data-field-name')||
      node.querySelector('[name]')?.getAttribute('name')||'';
    // If names use JetEngine bracket syntax, use only original field key
    // without values. Never derive selectors from a user-entered field value.
    const parts=named.match(/[A-Za-z_][A-Za-z0-9_-]*/g)||[];
    return identifier(parts.length?parts[parts.length-1]:'');
  };
  const attachAccordion=(node)=>{
    // Native repeaters, uploaders and other vendor-owned interactive controls
    // retain vendor state and never receive a second toggle.
    // Security and stateful vendor widgets cannot be collapsed by a generic
    // field toggle: captcha token, location map, multi-input phone, calendar
    // popover, conditional blocks, media, repeaters and WYSIWYG.
    if(node.matches('.mad4b-source-cards-mode,[data-control-name="shared_price_source_mode_rows"],.captcha-token-container,.field-type-captcha-container,.field-type-map-field,.field-type-phone-field,.field-type-advanced-date-field,.field-type-conditional-block') ||
       node.querySelector('.cx-ui-repeater-container,.jet-form-repeater__row,.jet-form-builder-repeater,.jet-form-builder-file-upload,.jet-fb-map-field,.phone-field-wrap,.captcha-token,.captcha-token-container,input[type=file],.wp-editor-wrap,[contenteditable=true]'))return;
    let header,body;
    if(node.matches('.jet-form-builder-row')){
      header=node.querySelector('.jet-form-builder__label');
      body=node.querySelector('.jet-form-builder__field-wrap');
    }else if(cfg.interface==='form'){
      header=node.querySelector('.jet-form__label');
      body=node.querySelector('.jet-form-row__field,.jet-form__field-container');
    }else{
      header=node.querySelector(':scope > .cx-control__info');
      body=node.querySelector(':scope > .cx-control__content');
    }
    if(!header||!body||header.contains(body)||header.querySelector('.mad4b-recipe-toggle'))return;
    const button=document.createElement('button');
    button.type='button';
    button.className='mad4b-recipe-toggle';
    button.textContent='Hide fields';
    button.setAttribute('aria-expanded','true');
    const targetId='mad4b-recipe-panel-'+(++attachAccordion.seq);
    if(!body.id)body.id=targetId;
    button.setAttribute('aria-controls',body.id);
    header.append(button);
    button.addEventListener('click',()=>{
      const closed=node.classList.toggle('mad4b-recipe-collapsed');
      button.setAttribute('aria-expanded',String(!closed));
      button.textContent=closed?'Show fields':'Hide fields';
    });
  };
  attachAccordion.seq=0;
  const nodes=()=>{
    const els=[];
    document.querySelectorAll(rootSelector).forEach(root=>{
      if(cfg.interface==='form' && !root.matches('form.jet-form,form.jet-form-builder'))return;
      const collection=cfg.interface==='form'?
        root.matches('form.jet-form-builder')?
          root.querySelectorAll('.jet-form-builder-row:not(.field-type-submit-field):not(.field-type-hidden-field)'):
          root.querySelectorAll('.jet-form-row:not(.jet-form-row--submit):not(.jet-form-row--hidden)'):
        root.querySelectorAll('.cx-control');
      collection.forEach(el=>{
        if(cfg.interface==='options' && !el.classList.contains('mad4b-ws-control'))return;
        if(cfg.interface==='cpt' && !el.closest('.jet-engine-meta-wrap'))return;
        if(el.closest('.cx-ui-repeater-item') && el.querySelector('.cx-ui-repeater-container'))return;
        els.push(el);
      });
    });
    return els;
  };
  const apply=()=>{
    for(const node of nodes()){
      const form=cfg.interface==='form'?node.closest('form.jet-form,form.jet-form-builder'):null;
      const interfaceType=form?.matches('.jet-form-builder')?'jetform':cfg.interface;
      const target=identifier(cfg.interface==='form'?
        form?.dataset.formId||form?.getAttribute('id')||'default':
        cfg.target||'default');
      const {mode,source}=resolve(interfaceType,target,groupOf(node),fieldOf(node));
      node.setAttribute('data-mad4b-recipe',mode);
      node.setAttribute('data-mad4b-recipe-source',source);
      if(mode==='accordion')attachAccordion(node);
      else {
        node.classList.remove('mad4b-recipe-collapsed');
        const btn=node.querySelector('.mad4b-recipe-toggle');
        if(btn)btn.remove();
      }
    }
  };
  let queued=false;
  const queue=()=>{
    if(queued)return;
    queued=true;
    Promise.resolve().then(()=>{queued=false;apply();});
  };
  const start=()=>{
    apply();
    // Layout adapters may mount after Elementor/JetEngine or change Mode.
    const observer=new MutationObserver(changes=>{
      if(changes.some(c=>[...c.addedNodes].some(n=>
        n.nodeType===1 && !n.classList?.contains('mad4b-recipe-toggle'))))queue();
    });
    observer.observe(document.body,{childList:true,subtree:true});
    document.addEventListener('jet-engine/forms/loaded',queue);
    document.addEventListener('mad4b:jetengine:mode-changed',queue);
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
  else start();
})();

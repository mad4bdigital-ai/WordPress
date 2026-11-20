/* MAD4B pricing rules: read-only, source-backed status in the real JetEngine dimension editor. */
(() => {
  'use strict';
  const begin=()=>{
    const cfg=window.MAD4BPricingRulesReadback;
    const root=document.querySelector('.jet-engine-options-page-wrap');
    if(!cfg||!root||root.querySelector('#mad4b-pricing-rules-status'))return;
    const launch=root.querySelector('.mad4b-ws-dimension-launcher');
    const dimensions=root.querySelector('.mad4b-ws-dimension-panel');
    if(!launch||!dimensions)return;

    const make=(tag,cls,content)=>{
      const node=document.createElement(tag);
      if(cls)node.className=cls;
      if(content!==undefined)node.textContent=String(content);
      return node;
    };
    const section=make('section','mad4b-pricing-rule-status');
    section.id='mad4b-pricing-rules-status';
    section.setAttribute('aria-label','Saved pricing rules and activation status');
    const heading=make('div','mad4b-pricing-rule-heading');
    heading.append(make('strong','','Saved pricing rules · current status'));
    const refresh=make('button','button button-small','Refresh');
    refresh.type='button';
    heading.append(refresh);
    const details=make('div','mad4b-pricing-rule-summary');
    details.setAttribute('role','status');
    details.setAttribute('aria-live','polite');
    details.textContent='Checking saved dimensions, drafts and native pricing assignments…';
    const links=make('p','mad4b-pricing-rule-links');
    const link=(text,url)=>{
      const a=make('a','',text);a.href=url;return a;
    };
    links.append(link('Pricing Lab',cfg.lab),document.createTextNode(' · '),
      link('Policy Bindings',cfg.bindings));
    section.append(heading,details,links);
    launch.insertAdjacentElement('afterend',section);

    const css=make('style');
    css.id='mad4b-pricing-rule-visibility-style';
    css.textContent=[
      '.mad4b-pricing-rule-status{margin:12px 0;padding:12px 14px;background:#fff;border:1px solid #dcdcde;border-radius:7px}',
      '.mad4b-pricing-rule-heading{display:flex;align-items:center;justify-content:space-between;gap:12px}',
      '.mad4b-pricing-rule-heading strong{font-size:14px}',
      '.mad4b-pricing-rule-summary{font-size:13px;margin-top:9px;line-height:1.5}',
      '.mad4b-pricing-rule-summary .mad4b-pricing-rule-metrics{display:flex;flex-wrap:wrap;gap:8px 16px;margin:8px 0}',
      '.mad4b-pricing-rule-summary .mad4b-pricing-rule-metric{font-size:12px}',
      '.mad4b-pricing-rule-summary .mad4b-pricing-rule-explain{margin:7px 0;color:#50575e}',
      '.mad4b-pricing-rule-links{font-size:12px;margin:8px 0 0}',
      '.mad4b-pricing-rule-inline{padding:9px 12px;margin:10px 0;border-left:3px solid #8c8f94;background:#f6f7f7;font-size:12px}',
      '.mad4b-pricing-rule-inline strong{display:block;font-weight:600;margin-bottom:3px}',
      '.mad4b-pricing-rule-inline p{margin:4px 0}',
      '.mad4b-pricing-rule-inline ul{margin:5px 0 0 17px;list-style:disc}',
      '.mad4b-pricing-rule-inline li{margin:3px 0}',
      '.mad4b-pricing-rule-inline a{display:inline-block;margin-top:5px}',
      '.mad4b-pricing-rule-inline-badge{font-size:11px;color:#50575e;font-weight:400;padding-left:8px}',
      '@media(max-width:782px){.mad4b-pricing-rule-status{padding:10px}.mad4b-pricing-rule-metrics{gap:6px 10px!important}}'
    ].join('\n');
    document.head.append(css);

    let snapshot=null,loading=false;
    const metric=(title,value)=>{
      const node=make('span','mad4b-pricing-rule-metric');
      node.append(make('strong','',value),document.createTextNode(' '+title));
      return node;
    };
    const stateWord=(value)=>value?'ON':'OFF';
    const explain=(snap)=>{
      if(!snap.engine_enabled)return 'Pricing Engine is OFF. Selecting dimensions does not activate it.';
      if(snap.strategy_count===0 && snap.native_assignments.enabled_approved===0)
        return 'The engine is ON, but no saved Pricing Lab strategy or approved-enabled native assignment is present. These selected dimensions are not price rules.';
      return 'Saved strategies and provider assignments are listed below. A saved Draft or binding alone does not prove a live Rate adjustment.';
    };
    const draw=()=>{
      if(!snapshot)return;
      const s=snapshot;
      details.replaceChildren();
      details.append(make('p','',
        'Pricing Engine: '+stateWord(s.engine_enabled)+
        ' · Booking: '+stateWord(s.booking_enabled)+
        ' · Dimension mode: '+(s.dimension_mode==='custom'?'Custom selection':'Auto-include all')));
      const metrics=make('div','mad4b-pricing-rule-metrics');
      const selected=s.dimension_mode==='custom'?s.selected_dimensions.length:'All discovered';
      metrics.append(
        metric('targeting dimensions',selected),
        metric('saved Lab strategies',s.strategy_count),
        metric('approved + enabled native rows',s.native_assignments.enabled_approved),
        metric('published Tour Rates',s.published_tour_rates)
      );
      details.append(metrics,make('p','mad4b-pricing-rule-explain',explain(s)));
      if(s.policy_bindings.global_refs || s.policy_bindings.term_records || s.policy_bindings.post_records){
        details.append(make('p','mad4b-pricing-rule-explain',
          'Policy Bindings: '+s.policy_bindings.global_refs+' global references, '+
          s.policy_bindings.term_records+' term records, '+
          s.policy_bindings.post_records+' post records · reference is not activation.'));
      }
      updateRows();
    };
    const updateRows=()=>{
      if(!snapshot)return;
      // Selected dimensions are NOT live pricing rules.
      for(const counter of dimensions.querySelectorAll('.mad4b-ws-dimension-group > summary small')){
        const str=counter.textContent||'';
        if(/^\d+\/\d+ active$/.test(str))
          counter.textContent=str.replace(/ active$/,' selected');
      }
      for(const row of dimensions.querySelectorAll('.mad4b-ws-dimension-row')){
        const id=row.querySelector('.mad4b-ws-dimension-item input[type=checkbox]')?.value;
        const editor=row.querySelector('.mad4b-ws-nested-policy');
        const container=editor?.querySelector('.mad4b-ws-nested-policy-content');
        const trigger=editor?.querySelector('summary');
        if(!id||!container||!trigger)continue;
        const linked=snapshot.by_dimension[id]||[];
        let info=container.querySelector('.mad4b-pricing-rule-inline');
        if(!info){
          info=make('aside','mad4b-pricing-rule-inline');
          info.setAttribute('aria-label','Saved rule status for this dimension');
          container.insertBefore(info,container.firstChild);
        }
        const enabled=snapshot.dimension_mode==='all' ||
          snapshot.selected_dimensions.includes(id);
        info.replaceChildren(
          make('strong','','Saved rule state · '+(enabled?'Eligible for targeting':'Not selected for targeting'))
        );
        if(linked.length){
          const list=make('ul');
          for(const rule of linked.slice(0,8)){
            list.append(make('li','',rule.title+' · '+rule.status.replaceAll('_',' ')+
              ' · '+rule.strategy+(rule.value!==null?' '+rule.value:'')+
              ' · Not a verified checkout adjustment'));
          }
          info.append(list);
        } else {
          info.append(make('p','',
            'No saved Pricing Lab strategy targets this dimension. The form below creates an inactive Draft; it is not an existing or enabled price rule.'));
        }
        info.append(link('Inspect all Policy Bindings',cfg.bindings));
        let badge=trigger.querySelector('.mad4b-pricing-rule-inline-badge');
        if(!badge){
          badge=make('small','mad4b-pricing-rule-inline-badge');
          trigger.append(badge);
        }
        badge.textContent=' · '+linked.length+' saved '+(linked.length===1?'strategy':'strategies');
      }
    };
    // JetEngine creates the nested rows on demand. The observer only annotates
    // new rows; its own nodes do not trigger endless re-annotation.
    const observer=new MutationObserver(mutations=>{
      if(!snapshot)return;
      if(mutations.some(m=>[...m.addedNodes].some(node=>
        node.nodeType===1 &&
        (node.matches?.('.mad4b-ws-dimension-row')||
         node.querySelector?.('.mad4b-ws-dimension-row'))))) updateRows();
    });
    observer.observe(dimensions,{childList:true,subtree:true});
    const syncVisibility=()=>{section.hidden=launch.hidden;};
    const visibilityObserver=new MutationObserver(syncVisibility);
    visibilityObserver.observe(launch,{attributes:true,attributeFilter:['hidden']});
    syncVisibility();
    const fetchStatus=async()=>{
      if(loading)return;
      loading=true;refresh.disabled=true;
      if(!snapshot)details.textContent='Loading saved pricing rules…';
      try{
        const body=new URLSearchParams({action:cfg.action,nonce:cfg.nonce});
        const response=await fetch(cfg.ajax,{
          method:'POST',credentials:'same-origin',cache:'no-store',
          headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body
        });
        const res=await response.json();
        if(!response.ok || !res?.success || res.data?.read_only!==true)
          throw Error(res?.data?.reason||'Readback unavailable');
        snapshot=res.data;
        draw();
      }catch(e){
        details.replaceChildren(make('p','',
          'Cannot display current rules: '+String(e.message)+'. No pricing change has been made.'));
      }finally{loading=false;refresh.disabled=false;}
    };
    refresh.addEventListener('click',fetchStatus);
    fetchStatus();
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',begin,{once:true});
  else begin();
})();

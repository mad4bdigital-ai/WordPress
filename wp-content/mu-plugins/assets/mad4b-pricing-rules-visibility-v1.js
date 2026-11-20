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

    // The native Pricing policies tab displays the same read-only registry.
    const content=root.querySelector('.mad4b-ws-content');
    const sidebar=root.querySelector('.mad4b-ws-sidebar');
    const policyPanel=make('section','mad4b-pricing-rule-status mad4b-pricing-policy-registry-tab');
    policyPanel.id='mad4b-pricing-unified-policy-list';
    policyPanel.setAttribute('aria-label','Unified pricing policy registry');
    policyPanel.hidden=true;
    if(content){
      const listing=content.querySelector('.mad4b-ws-listing');
      if(listing)content.insertBefore(policyPanel,listing);
      else content.append(policyPanel);
    }
    const syncPolicyTab=()=>{
      const active=root.querySelector('.mad4b-ws-nav-btn.is-active')?.dataset.section;
      policyPanel.hidden=active!=='policies';
    };
    sidebar?.addEventListener('click',()=>requestAnimationFrame(syncPolicyTab));
    syncPolicyTab();

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
      '.mad4b-pricing-unified-registry{margin-top:10px;border-top:1px solid #ddd;padding-top:10px}',
      '.mad4b-pricing-unified-registry summary{cursor:pointer;font-weight:600;padding:5px 0}',
      '.mad4b-pricing-rule-table-box{max-height:380px;overflow:auto;margin-top:8px}',
      '.mad4b-pricing-rule-table{font-size:12px;min-width:780px}',
      '.mad4b-pricing-rule-table td{white-space:normal;word-break:break-word}',
      '.mad4b-pricing-rule-table th{position:sticky;top:0;background:#f6f7f7}',
      '.mad4b-pricing-policy-registry-tab{margin-top:4px}',
      '.mad4b-pricing-policy-registry-tab[hidden]{display:none!important}',
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
      const reg=snap.unified_registry;
      const previews=reg?.status_counts?.preview_eligible||0;
      if(snap.strategy_count===0 && snap.native_assignments.enabled_approved===0 && previews>0)
        return 'The engine is ON; '+previews+' native Tour Type/Package Level preview rules exist, while Pricing Lab has no saved Strategies. Preview rules are not Checkout or Tour Rate authority.';
      if(snap.strategy_count===0 && snap.native_assignments.enabled_approved===0)
        return 'The engine is ON, but no native preview pricing rule or saved Pricing Lab Strategy is available. Selected dimensions are not price rules.';
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
      const registry=s.unified_registry;
      metrics.append(
        metric('selected targeting dimensions',selected),
        metric('native preview rules',registry?.status_counts?.preview_eligible||0),
        metric('Pricing Lab drafts',s.strategy_count),
        metric('approved + enabled native presets',s.native_assignments.enabled_approved),
        metric('published Tour Rates',s.published_tour_rates)
      );
      details.append(metrics,make('p','mad4b-pricing-rule-explain',explain(s)));
      if(registry && Array.isArray(registry.rows)){
        const wrapper=make('details','mad4b-pricing-unified-registry');
        const summary=make('summary','',
          'Unified policy registry · '+registry.rows.length+
          ' entries from '+registry.source_count+' provider families');
        wrapper.append(summary);
        const note=make('p','mad4b-pricing-rule-explain',
          'Read-only projection. Native JetEngine rules are the original source of truth; Pricing Lab is the Draft workspace. No rule is copied or activated by this panel.');
        wrapper.append(note);
        const tablebox=make('div','mad4b-pricing-rule-table-box');
        const table=make('table','widefat striped mad4b-pricing-rule-table');
        const head=make('thead'),hrow=make('tr');
        for(const label of ['Family','Rule','Value','Status','Authoritative source']){
          hrow.append(make('th','',label));
        }
        head.append(hrow);table.append(head);
        const tbody=make('tbody');
        const states={
          preview_eligible:'Preview eligible · not live Checkout',
          configured_native:'Configured native policy · not a verified sale',
          configured_component:'Component source configured',
          configured_supplier_cost:'Supplier cost source configured',
          inherited_supplier_cost:'Guide cost · inherited EGP fallback',
          approved_supplier_cost:'Guide cost · approved Term',
          reference_only_no_cost_authority:'Reference only · supplier cost blocked',
          blocked_term_gate:'Blocked · Taxonomy term gate',
          blocked_source_or_conflict:'Blocked · source invalid or conflict',
          draft:'Draft · no price effect',
          staged:'Staged · not active',
          approval_pending:'Pending approval',
          disabled:'Disabled'
        };
        const names={
          tour_type:'Tour Type',package_level:'Package Level',
          tour_mode:'Tour Mode',price_source:'Price Source',
          pricing_lab:'Pricing Lab',native_preset:'Native Preset',
          component:'Component Cost',guide_cost:'Guide Cost',
          transport_self:'Transport Self',group_tier:'Group Tier',
          promotion:'Promotion',payment_fee:'Payment Fee',
        };
        for(const entry of registry.rows){
          const tr=make('tr');
          for(const value of [
            names[entry.family]||entry.family,
            entry.name,entry.effect,
            states[entry.status]||entry.status,
            entry.source
          ])tr.append(make('td','',value));
          tbody.append(tr);
        }
        table.append(tbody);tablebox.append(table);wrapper.append(tablebox);
        details.append(wrapper);
        if(registry.warnings?.length)
          details.append(make('p','mad4b-pricing-rule-explain',
            'Source warnings: '+registry.warnings.join(', ')));
      }
      policyPanel.replaceChildren();
      if(registry && Array.isArray(registry.rows)){
        policyPanel.append(make('h3','','Unified policies · all source families'));
        policyPanel.append(make('p','mad4b-pricing-rule-explain',
          'Single read-only registry. Package Levels and Tour Type markups come from their native source, not Pricing Lab. Preview enabled does not mean a live selling rule.'));
        const counts=registry.status_counts||{};
        policyPanel.append(make('p','',
          (counts.preview_eligible||0)+' preview-eligible price rules · '+
          (counts.draft||0)+' Draft · '+
          (counts.staged||0)+' Staged · '+
          (counts.blocked_source_or_conflict||0)+' source conflicts · '+
          registry.source_count+' sources'));
        const scroll=make('div','mad4b-pricing-rule-table-box');
        const table=make('table','widefat striped mad4b-pricing-rule-table');
        const head=make('thead'),headRow=make('tr');
        for(const label of ['Family','Policy','Value','State','Original source'])
          headRow.append(make('th','',label));
        head.append(headRow);
        table.append(head);
        const body=make('tbody');
        for(const rule of registry.rows){
          const tr=make('tr');
          for(const v of [rule.family,rule.name,rule.effect,
            rule.status.replaceAll('_',' '),rule.source])
            tr.append(make('td','',v));
          body.append(tr);
        }
        table.append(body);scroll.append(table);policyPanel.append(scroll);
        policyPanel.append(make('p','mad4b-pricing-rule-explain',
          'To change a source policy, use its existing JetEngine editor and Save. This registry never saves a second copy or applies a new rate.'));
      }
      syncPolicyTab();
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
      const registry=snapshot.unified_registry;
      for(const row of dimensions.querySelectorAll('.mad4b-ws-dimension-row')){
        const id=row.querySelector('.mad4b-ws-dimension-item input[type=checkbox]')?.value;
        const editor=row.querySelector('.mad4b-ws-nested-policy');
        const container=editor?.querySelector('.mad4b-ws-nested-policy-content');
        const trigger=editor?.querySelector('summary');
        if(!id||!container||!trigger)continue;
        const linked=snapshot.by_dimension[id]||[];
        const nativeRules=(registry?.by_dimension?.[id]||[]).filter(
          item=>item.family!=='pricing_lab');
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
        if(nativeRules.length){
          const summary=make('p','',
            nativeRules.length+' native-source rule'+(nativeRules.length===1?'':'s')+
            ' found for this dimension. These are not automatically active checkout prices.');
          info.append(summary);
          const nativeList=make('ul');
          for(const rule of nativeRules.slice(0,15)){
            nativeList.append(make('li','',
              rule.name+' · '+rule.effect+' · '+rule.status.replaceAll('_',' ')+
              ' · source: '+rule.source));
          }
          info.append(nativeList);
        }
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
            'No saved Pricing Lab Strategy targets this dimension. The form below creates an inactive Draft; it does not edit the native preview rules listed above.'));
        }
        info.append(link('Inspect all Policy Bindings',cfg.bindings));
        let badge=trigger.querySelector('.mad4b-pricing-rule-inline-badge');
        if(!badge){
          badge=make('small','mad4b-pricing-rule-inline-badge');
          trigger.append(badge);
        }
        badge.textContent=' · '+nativeRules.length+' native / '+linked.length+' Lab drafts';
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

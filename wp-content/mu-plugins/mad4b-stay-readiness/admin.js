/* MAD4B Stay Readiness — admin-only diagnostics. Never writes to WP. */
(() => {
  'use strict';
  const cfg=window.MAD4BStayReadiness;
  const panel=document.querySelector('.mad4b-stay-panel__body');
  if(!cfg?.nonce||!cfg?.ajax_url||!cfg?.template||!panel||panel.querySelector('.mad4b-stay-readiness'))return;
  const wrap=document.createElement('section');
  wrap.className='mad4b-stay-readiness';
  wrap.innerHTML=cfg.template;
  panel.append(wrap);
  const el=name=>wrap.querySelector('[data-ready="'+name+'"]');
  const state={scope:'priority',search:'',page:1,pages:0,controller:null};
  const node=(name,className,value)=>{
    const n=document.createElement(name);
    if(className)n.className=className;
    if(value!==undefined)n.textContent=String(value);
    return n;
  };
  const safeHref=(value)=>{
    if(!value||typeof value!=='string')return null;
    try{const url=new URL(value,location.origin);return url.origin===location.origin?url.href:null;}
    catch{return null;}
  };
  const link=(url,title)=>{
    const href=safeHref(url);if(!href)return null;
    const a=node('a','button',title);a.href=href;return a;
  };
  const labels={
    property_relation_unverified:'Check Property relationship',
    parent_property_unpublished:'Approve the Property',
    qa_or_test_unit:'QA / Test — keep excluded',
    room_kind_needs_review:'Confirm Room / Cabin type',
    occupancy_not_verified:'Verify maximum occupancy',
    unit_images_missing:'Add verified room photos',
    physical_inventory_unverified:'Confirm physical inventory',
    unit_readiness_not_validated:'Review and validate the Unit',
    unit_unpublished:'Unit is still Draft',
    unit_availability_unconfirmed:'Availability not confirmed',
    direct_sell_price_not_configured:'Company selling price not configured',
    native_rate_or_booking_receipt_required:'Native booking quote required'
  };
  function metrics(summary){
    el('metrics').replaceChildren();
    const rows=[
      ['Total Units',summary.total],['Published',summary.published],
      ['Priority Draft',summary.draft_published_property],
      ['Draft Property',summary.draft_draft_property],
      ['QA / Test',summary.qa],
      ['Physical inventory set',summary.total-summary.missing_inventory],
      ['Selling price configured',summary.direct_sell_configured],
      ['Commercial ready',summary.commercially_ready]
    ];
    rows.forEach(([label,count])=>{
      const tile=node('div','mad4b-stay-readiness__metric');
      tile.append(node('strong','',count),node('small','',label));
      el('metrics').append(tile);
    });
  }
  function itemRow(item){
    const card=node('article','mad4b-stay-readiness__item');
    const head=node('div','mad4b-stay-readiness__item-head');
    const tag=node('span','mad4b-stay-readiness__category',
      item.qa_only?'QA · exclude':item.catalog_ready_for_review?'Review candidate':'Pending data');
    head.append(node('strong','',item.title),tag);
    card.append(head,node('small','',
      'Property: '+item.property_title+' · Unit '+item.unit_status+
      ' / Property '+item.property_status));
    const blockers=node('div','mad4b-stay-readiness__blockers');
    (item.catalog_blockers||[]).forEach(code=>blockers.append(
      node('span','mad4b-stay-readiness__blocker',labels[code]||code)));
    if(!item.catalog_blockers?.length)
      blockers.append(node('span','mad4b-stay-readiness__positive',
        'Editorial preflight passed · separate publication review still required'));
    card.append(blockers);
    const actions=node('nav','mad4b-stay-readiness__actions');
    [link(item.edit_unit_url,'Edit Unit'),
      link(item.edit_property_url,'Edit Property')].filter(Boolean)
      .forEach(anchor=>actions.append(anchor));
    if(item.unit_status==='publish' && item.property_status==='publish' && !item.qa_only){
      const button=node('button','button','Check native shadow quote');
      button.type='button';
      const status=node('p','mad4b-stay-readiness__status','');
      status.setAttribute('role','status');
      status.setAttribute('aria-live','polite');
      button.addEventListener('click',async()=>{
        const root=document.querySelector('.mad4b-stay-panel');
        const field=key=>root?.querySelector('[data-stay="'+key+'"]')?.value||'';
        if(!field('checkin')){
          status.textContent='Choose a check-in date in Price source preview first.';
          return;
        }
        button.disabled=true;
        status.textContent='Checking the native price-only engine…';
        const data=new URLSearchParams({
          action:cfg.action,nonce:cfg.nonce,mode:'handoff',
          property_id:String(item.property_id),unit_id:String(item.unit_id),
          checkin:field('checkin'),nights:field('nights')||'1',
          adults:field('adults')||'2',children:field('children')||'0',
          board_type:field('board')
        });
        try{
          const response=await fetch(cfg.ajax_url,{method:'POST',body:data,
            credentials:'same-origin',headers:{Accept:'application/json'}});
          const json=await response.json();
          if(!response.ok||!json?.success)
            throw new Error(json?.data?.code||'native_price_check_failed');
          const result=json.data;
          if(result.status==='native_shadow_preview')
            status.textContent='Native price-only total: '+result.base_total+' '+
              result.currency+' · Inventory: '+result.inventory_status+
              ' · Checkout remains disabled.';
          else
            status.textContent='Not certified: '+(result.reason||'unknown')+
              '. Checkout remains disabled.';
        }catch(error){
          status.textContent='Native price check unavailable: '+(error.message||'error');
        }finally{button.disabled=false;}
      });
      actions.append(button);
      card.append(actions,status);
    }else card.append(actions);
    return card;
  }
  let debounceTimer;
  async function load(){
    if(state.controller)state.controller.abort();
    const ctrl=new AbortController();state.controller=ctrl;
    el('status').textContent='Auditing existing Units…';
    const body=new URLSearchParams({action:cfg.action,nonce:cfg.nonce,
      mode:'audit',scope:state.scope,search:state.search,paged:String(state.page)});
    try{
      const response=await fetch(cfg.ajax_url,{method:'POST',credentials:'same-origin',
        signal:ctrl.signal,body,headers:{Accept:'application/json'}});
      const answer=await response.json();
      if(!response.ok||!answer?.success)
        throw new Error(answer?.data?.code||'audit_failed');
      if(state.controller!==ctrl)return;
      const data=answer.data;
      state.pages=data.pages;
      metrics(data.summary);
      if(data.supplier_policy_requires_review){
        el('status').textContent='Self fallback is not activated for commercial quotes.';
      }
      el('items').replaceChildren();
      data.items.forEach(item=>el('items').append(itemRow(item)));
      el('status').textContent=data.found+' Unit(s) match. No data modified.'+
        (data.supplier_policy_requires_review?
        '  Self fallback is inactive; confirm the existing supplier policy before commercial activation.':'');
      el('page').textContent=data.pages?'Page '+data.page+' / '+data.pages:'No results';
      el('previous').disabled=state.page<=1;
      el('next').disabled=state.page>=data.pages;
    }catch(error){
      if(error.name!=='AbortError')
        el('status').textContent='Audit unavailable: '+(error.message||'unknown');
    }finally{
      if(state.controller===ctrl)state.controller=null;
    }
  }
  el('scope').addEventListener('change',()=>{
    state.scope=el('scope').value;state.page=1;load();
  });
  el('search').addEventListener('input',()=>{
    clearTimeout(debounceTimer);
    debounceTimer=setTimeout(()=>{
      state.search=el('search').value.trim();state.page=1;load();
    },300);
  });
  el('refresh').addEventListener('click',load);
  el('previous').addEventListener('click',()=>{
    state.page=Math.max(1,state.page-1);load();
  });
  el('next').addEventListener('click',()=>{
    state.page=Math.min(state.pages,state.page+1);load();
  });
  const disclosure=wrap.querySelector('details');
  let initialized=false;
  disclosure.addEventListener('toggle',()=>{
    if(disclosure.open && !initialized){initialized=true;load();}
  });
})();

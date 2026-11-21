/* Native Tour Rate route proposal preview. No writes or checkout. */
(() => {
'use strict';
function ready(){
  document.querySelectorAll('[data-transport-route-advisor]').forEach(root => {
    const raw=root.querySelector('[data-tr="config"]');
    let cfg;
    try{cfg=JSON.parse(raw?.textContent||'');}catch{return;}
    if(!cfg?.route||!cfg?.nonce||!cfg?.postId)return;
    const controls=root.querySelector('[data-tr="controls"]');
    const result=root.querySelector('[data-tr="results"]');
    const add=root.querySelector('[data-tr="add"]');
    const preview=root.querySelector('[data-tr="preview"]');
    const h=(tag,text)=>{const e=document.createElement(tag);e.textContent=String(text);return e;};
    const select=(label,values,current)=>{
      const wrap=h('label',label);
      const e=document.createElement('select');
      values.forEach(([value,name])=>e.add(new Option(name,String(value))));
      if(current!==undefined&&values.some(x=>String(x[0])===String(current)))e.value=String(current);
      wrap.append(e);return [wrap,e];
    };
    const control=(label,type,value)=>{
      const wrap=h('label',label),e=document.createElement('input');
      e.type=type;
      if(type==='checkbox')e.checked=value===true||value==='1';
      else e.value=String(value??'');
      wrap.append(e);return [wrap,e];
    };
    const terms=[['','Choose destination term']].concat(
      (cfg.terms||[]).map(t=>[String(t.term_id),t.name+' ('+t.slug+')'])
    );
    const origins=[['','Select origin city']].concat(
      (cfg.terms||[]).filter(t=>['cairo','aswan-gov','luxor'].includes(t.slug))
        .map(t=>[String(t.term_id),t.name])
    );

    const costForm=root.querySelector('[data-tr="cost-form"]');
    const costSelected=root.querySelector('[data-tr="cost-selected"]');
    const costResults=root.querySelector('[data-tr="cost-results"]');
    const costAdd=root.querySelector('[data-tr="cost-add"]');
    const costPreview=root.querySelector('[data-tr="cost-preview"]');
    const costClear=root.querySelector('[data-tr="cost-clear"]');
    const costSave=root.querySelector('[data-tr="cost-save"]');
    let costAvailable=[],costLines=Array.isArray(cfg.savedSelection?.cost_inputs)?cfg.savedSelection.cost_inputs:[];
    costSave.disabled=cfg.savedSelection?.writable!==true;
    costSave.title=costSave.disabled?'Only unpublished Draft Tour Rates can be updated':'Save selection with stale-tab protection';
    const costGrid=document.createElement('div');costGrid.className='mad4b-tr-field-grid';
    const [costSourceLabel,costSource]=select('Matched Transportation source',[['','Run route candidates first']]);
    const [costVehicleLabel,costVehicle]=select('Vehicle profile',[
      ['limousine','Limousine'],['luxury_7_seats','Luxury 7 Seats'],['h1','H1'],
      ['hiace','Hiace'],['coaster','Coaster'],['bus','Bus']]);
    const [costRoadLabel,costRoad]=select('Road category',[
      ['highway','Highway'],['city','City'],['desert','Desert']]);
    const [costServiceLabel,costService]=select('Service',[
      ['transfer','Transfer'],['half_day','Half day'],['full_day','Full day'],
      ['sightseeing','Sightseeing'],['overnight','Overnight']]);
    const [kmLabel,costKm]=control('Route distance (km · operator estimate)','number','');
    costKm.step='.1';costKm.min='.5';costKm.max='5000';
    const [minutesLabel,costMinutes]=control('Driving minutes (operator estimate)','number','');
    costMinutes.min='1';costMinutes.max='20000';costMinutes.step='1';
    const [quantityLabel,costQty]=control('Number of vehicles','number',1);
    costQty.min='1';costQty.max='20';costQty.step='1';
    const [costModeLabel,costMode]=select('Link verification',[
      ['planning','Planning · not yet saved'],
      ['package','Verify existing saved Tour Rate link']],'planning');
    const [dateLabel,costDate]=control('Travel date (context only)','date','');
    const [waitLabel,costWait]=control('Waiting minutes','number',0);
    costWait.min='0';costWait.max='10080';
    const [tollsLabel,costTolls]=control('Tolls / parking (EGP)','number',0);
    costTolls.min='0';costTolls.max='200000';
    [costSourceLabel,costVehicleLabel,costRoadLabel,costServiceLabel,kmLabel,
      minutesLabel,quantityLabel,costModeLabel,dateLabel,waitLabel,tollsLabel]
      .forEach(x=>costGrid.append(x));
    costForm.append(costGrid);
    costSource.addEventListener('change',()=>{
      const chosen=costAvailable.find(x=>String(x.id)===costSource.value);
      if(!chosen)return;
      const vehicles=Object.keys(chosen.vehicleCosts||{});
      const previous=costVehicle.value;
      costVehicle.replaceChildren();
      const names={limousine:'Limousine',luxury_7_seats:'Luxury 7 Seats',
        h1:'H1',hiace:'Hiace',coaster:'Coaster',bus:'Bus'};
      vehicles.forEach(v=>costVehicle.add(new Option(
        (names[v]||v)+' · PDF cost '+Number(chosen.vehicleCosts[v]).toLocaleString('en-US')+' EGP',v)));
      if(vehicles.includes(previous))costVehicle.value=previous;
    });
    async function workflowApi(body){
      if(!cfg.workflow||!cfg.nonce)throw Error('Workflow endpoint unavailable');
      const resp=await fetch(cfg.workflow,{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce},
        body:JSON.stringify(body)});
      const parsed=await resp.json();
      if(!resp.ok||parsed?.code)throw Error(parsed?.code||'workflow_request_failed');
      return parsed;
    }
    function refreshCostSource(){
      costSource.replaceChildren(new Option(
        costAvailable.length?'Choose one stage/candidate':'No city-matched candidate yet',''));
      costAvailable.forEach(item=>costSource.add(new Option(
        item.stageLabel+' · '+item.sourceKey+' · #'+item.sourceId,
        String(item.id))));
      costAdd.disabled=costAvailable.length===0;
    }
    function renderCostSelected(){
      costSelected.replaceChildren();
      if(!costLines.length){costSelected.textContent='No selected components.';return;}
      const table=document.createElement('table');table.className='widefat striped';
      const heading=document.createElement('tr');
      for(const title of ['Stage','Transportation','Vehicle','Qty','']){
        const th=h('th',title);heading.append(th);
      }
      table.append(heading);
      costLines.forEach((item,index)=>{
        const tr=document.createElement('tr');
        for(const v of [item.stage_key,'#'+item.transportation_id,item.vehicle,item.quantity]){
          tr.append(h('td',v));
        }
        const action=h('td',''),btn=h('button','Remove');
        btn.type='button';btn.className='button';
        btn.addEventListener('click',()=>{
          costLines.splice(index,1);renderCostSelected();costResults.replaceChildren();
        });
        action.append(btn);tr.append(action);table.append(tr);
      });
      costSelected.append(table);
    }
    costAdd.addEventListener('click',()=>{
      try{
        if(costLines.length>=30)throw Error('Maximum 30 selected journeys');
        const found=costAvailable.find(x=>String(x.id)===costSource.value);
        if(!found)throw Error('Choose a candidate from the current route match');
        const kms=Number(costKm.value),mins=Number(costMinutes.value);
        const qty=Number(costQty.value);
        if(!Number.isFinite(kms)||kms<.5||kms>5000||
          !Number.isFinite(mins)||mins<1||mins>20000||
          !Number.isInteger(qty)||qty<1||qty>20)
          throw Error('Enter valid km, driving minutes and whole vehicle count');
        const key=found.join==='AND'?
          found.stageKey+'_'+found.sourceId:found.stageKey;
        if(costLines.some(x=>x.stage_key===key))
          throw Error('This OR stage already has a selected alternative, or this AND component is repeated');
        const wait=Number(costWait.value),tolls=Number(costTolls.value);
        if(!Number.isFinite(wait)||wait<0||wait>10080||!Number.isFinite(tolls)||tolls<0||tolls>200000)
          throw Error('Waiting minutes and expenses must be within allowed ranges');
        costLines.push({
          stage_key:key,transportation_id:found.sourceId,
          vehicle:costVehicle.value,service:costService.value,
          road:costRoad.value,km:kms,drive_min:mins,
          from_term_id:found.originId,to_term_id:found.destinationId,
          wait_min:wait,tolls:tolls,quantity:qty,night:false,peak:false
        });
        renderCostSelected();costResults.replaceChildren(
          h('p','Selected journey added. Nothing has been saved or approved.'));
      }catch(e){costResults.replaceChildren(h('p','Cannot add: '+e.message));}
    });
    costClear.addEventListener('click',()=>{
      costLines=[];renderCostSelected();costResults.replaceChildren();
    });
    costSave.addEventListener('click',async()=>{
      costSave.disabled=true;
      costResults.replaceChildren(h('p','Validating selected components and saving native JetEngine Draft…'));
      try{
        if(!costLines.length)throw Error('Choose and cost at least one route first');
        if(cfg.savedSelection?.writable!==true)
          throw Error('Published Rates are read-only; use an unpublished Draft Tour Rate');
        const rows=collect().map(stage=>{
          const chosen=costLines.filter(line=>line.stage_key===stage.key||
            line.stage_key.startsWith(stage.key+'_'));
          const ids=[...new Set(chosen.map(line=>line.transportation_id))];
          if(!ids.length)throw Error('Choose a route for stage '+stage.key);
          if(stage.join==='OR'&&ids.length>1)
            throw Error('OR stage may select only one source per saved package');
          return {...stage,transport_ids:ids};
        });
        const saved=await workflowApi({
          action:'save',tour_rate_id:cfg.postId,
          expected_hash:cfg.savedSelection?.hash,mode:mode.value,
          stages:rows,cost_lines:costLines
        });
        cfg.savedSelection=saved;
        costMode.value='package';
        costResults.replaceChildren(h('h4','Saved to native JetEngine Draft · '+saved.cost_inputs_saved_count+' selected journey(s)'),
          h('p','Readback verified · updated revision fingerprint '+saved.hash+
            ' · no Transport Rate or commercial price created.'));
      }catch(e){costResults.replaceChildren(h('p','Save blocked: '+e.message));}
      finally{costSave.disabled=cfg.savedSelection?.writable!==true;}
    });
    costPreview.addEventListener('click',async()=>{
      costPreview.disabled=true;
      costResults.replaceChildren(h('p','Calculating explicitly selected journeys…'));
      try{
        if(!costLines.length)throw Error('No selected Transportations');
        if(!cfg.costAjax||!cfg.costNonce||!cfg.costAction)
          throw Error('Cost bridge is unavailable');
        const payload={
          context:costMode.value,tour_rate_id:cfg.postId,
          travel_date:costDate.value,lines:costLines
        };
        const data=new URLSearchParams({action:cfg.costAction,mode:'bridge',
          nonce:cfg.costNonce,payload:JSON.stringify(payload)});
        const response=await fetch(cfg.costAjax,{
          method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},body:data.toString()});
        const parsed=await response.json();
        if(!response.ok||!parsed?.success)
          throw Error(parsed?.data?.code||'cost_bridge_failed');
        const value=parsed.data;
        costResults.replaceChildren(h('h4',
          'Original PDF SELF cost reference: '+Number(value.total_pdf_reference_company_cost_egp).toFixed(2)+' EGP'));
        const warning=h('p','Indicative scenario: '+Number(value.total_indicative_scenario_egp).toFixed(2)+
          ' EGP · Modeled distance/time cost: '+Number(value.total_modeled_operating_cost_egp).toFixed(2)+
          ' EGP · Not a travel-date-confirmed supplier cost, approved selling price or reservation.');
        warning.className='mad4b-tr-warning';costResults.append(warning);
        costResults.append(h('p',value.package_relation_verified?
          'Saved Tour Rate linkage verified. Native cost and markup inheritance applied.':
          'Planning only. Save native Transportation assignments before using verified mode.'));
        const table=document.createElement('table');table.className='widefat striped';
        const head=document.createElement('tr');
        for(const title of ['Stage','PDF SELF cost EGP','Modeled EGP','Indicative EGP','Markup / source'])
          head.append(h('th',title));
        table.append(head);
        for(const line of value.lines){
          const tr=document.createElement('tr');
          for(const cell of [line.stage_key,line.pdf_reference_line_cost_egp,
            line.modeled_line_cost_egp,line.indicative_line_scenario_egp,
            line.markup_pct+'% · '+line.markup_rule])tr.append(h('td',cell));
          table.append(tr);
        }
        costResults.append(table);
        costResults.append(h('small','Read-only receipt '+value.receipt_sha256+
          ' · Currency EGP · no Transport Rate automatically created · no checkout.'));
      }catch(e){costResults.replaceChildren(h('p','Cost calculation blocked: '+e.message));}
      finally{costPreview.disabled=false;}
    });

    const header=document.createElement('div');
    header.className='mad4b-tr-toolbar';
    const [modeLabel,mode]=select('Selection mode',[
      ['simple','Simple — OR alternatives'],
      ['stages','Stages — AND across required stages']
    ],cfg.mode||'simple');
    header.append(modeLabel);
    controls.append(header);
    const stageHost=document.createElement('div');
    stageHost.className='mad4b-tr-stage-host';
    controls.append(stageHost);
    let nextIndex=0;
    const stages=[];
    function stageRow(value={}){
      nextIndex++;
      const el=document.createElement('section');
      el.className='mad4b-tr-stage';
      el.dataset.stage=String(nextIndex);
      const heading=h('h4','Stage '+nextIndex);
      const fields=document.createElement('div');
      fields.className='mad4b-tr-field-grid';
      const key=control('Stable stage key','text',value.key||'stage-'+nextIndex);
      const label=control('Stage label','text',value.label||'Transportation stage '+nextIndex);
      const day=control('Day offset','number',value.day_offset??0);
      day[1].min='0';day[1].max='365';day[1].step='1';
      const origin=select('Origin',origins,value.origin_term_id||cfg.defaultOrigin||'');
      const destination=select('Destination',terms,value.destination_term_id||'');
      const kind=select('Service type',[
        ['any','All transport'],['transfer','Transfer'],
        ['facility','Airport / station'],['sightseeing','Sightseeing vehicle'],
        ['extra','Operational extra']
      ],value.service_kind||'any');
      const join=select('Within stage',[
        ['OR','OR — choose one'],['AND','AND — all required']
      ],value.join||'OR');
      const required=control('Required stage','checkbox',value.required??true);
      const roundtrip=control('Return to origin','checkbox',value.return_to_origin??false);
      const price=select('Rate policy',[
        ['inquiry','Inquiry'],['included','Included'],['additional','Additional']
      ],value.price_policy||'inquiry');
      const widgets=[key,label,day,origin,destination,kind,join,required,roundtrip,price];
      widgets.forEach(x=>fields.append(x[0]));
      el.append(heading,fields);
      const remove=document.createElement('button');
      remove.type='button';remove.className='button';
      remove.textContent='Remove preview stage';
      remove.addEventListener('click',()=>{
        if(stages.length<=1)return;
        const index=stages.findIndex(x=>x.el===el);
        if(index>=0)stages.splice(index,1);
        el.remove();updateMode();
      });
      el.append(remove);stageHost.append(el);
      stages.push({el,widgets:[key[1],label[1],day[1],origin[1],
        destination[1],kind[1],join[1],required[1],roundtrip[1],price[1]]});
      updateMode();return el;
    }
    function updateMode(){
      const simple=mode.value==='simple';
      add.disabled=simple;
      for(let i=0;i<stages.length;i++)
        stages[i].el.hidden=simple&&i>0;
    }
    mode.addEventListener('change',updateMode);
    add.addEventListener('click',()=>stageRow());
    const saved=Array.isArray(cfg.stages)?cfg.stages:[];
    if(saved.length){
      saved.slice(0,30).forEach(x=>stageRow(x));
      if(saved.length>1)mode.value='stages';
    }else stageRow();
    updateMode();
    renderCostSelected();
    function collect(){
      return stages.filter((v,i)=>mode.value==='stages'||i===0)
        .map(({widgets:w})=>({
          key:w[0].value.trim(),label:w[1].value.trim(),
          day_offset:Number(w[2].value),
          origin_term_id:Number(w[3].value),
          destination_term_id:Number(w[4].value||0),
          service_kind:w[5].value,join:w[6].value,
          required:w[7].checked,return_to_origin:w[8].checked,
          price_policy:w[9].value
        }));
    }
    function group(title,items){
      const wrap=document.createElement('section');wrap.className='mad4b-tr-result-group';
      wrap.append(h('h4',title+' · '+items.length));
      if(!items.length)wrap.append(h('p','No source candidates in this group.'));
      for(const item of items){
        const line=document.createElement('div');line.className='mad4b-tr-candidate';
        line.append(h('strong',item.source_key+' — '+item.title));
        line.append(h('span',item.origin_city+' · '+item.route_shape+
          ' · '+(item.post_status==='draft'?'Draft':'Published')));
        line.append(h('small',item.blocking_reasons.join(' · ')));
        if(item.available_vehicle_pdf_costs_egp){
          line.append(h('small','Reference SELF costs (EGP): '+Object.entries(item.available_vehicle_pdf_costs_egp)
            .map(([v,amount])=>v+': '+Number(amount).toLocaleString('en-US')).join(' · ')));
        }
        if(item.edit_url){
          try{
            const u=new URL(item.edit_url,location.origin);
            if(u.origin===location.origin && u.protocol==='https:'){
              const a=h('a','Edit transportation');a.href=u.href;line.append(a);
            }
          }catch{}
        }
        wrap.append(line);
      }
      return wrap;
    }
    preview.addEventListener('click',async()=>{
      if(preview.disabled)return;
      preview.disabled=true;
      result.replaceChildren(h('p','Checking selected destinations and Yes status…'));
      costAvailable=[];refreshCostSource();renderCostSelected();
      costResults.replaceChildren(h('p','Recheck complete route candidates before estimating.'));
      try{
        const data=await workflowApi({
          action:'composition',tour_rate_id:cfg.postId,lang:cfg.lang,
          mode:mode.value,stages:collect()
        });
        costAvailable=[];
        for(const row of data.stages||[]){
          for(const candidate of row.alternatives||[]){
            costAvailable.push({
              id:costAvailable.length+1,sourceId:Number(candidate.post_id),
              sourceKey:candidate.source_key,stageKey:row.stage.key,
              stageLabel:row.stage.label,join:row.join_operator,
              originId:Number(row.stage.origin_term_id||0),
              destinationId:Number(row.stage.destination_term_id||0),
              vehicleCosts:candidate.available_vehicle_pdf_costs_egp||{}
            });
          }
        }
        refreshCostSource();renderCostSelected();costResults.replaceChildren(
          h('p',costAvailable.length?
            'Choose one matched source per OR stage, then enter distance and duration.':
            'No city-matched sources found for costing.'));
        result.replaceChildren(h('h4',
          data.mode==='simple'?'Simple OR alternatives preview':
          'Multi-stage AND / OR preview'));
        const note=h('p','Internal proposal only: none of these services is automatically sellable, priced, stocked or published.');
        note.className='mad4b-tr-warning';
        result.append(note);
        for(const row of data.stages||[]){
          const section=document.createElement('section');
          section.className='mad4b-tr-stage-result';
          section.append(h('h4',row.stage.label+' · Day '+row.stage.day_offset+
            ' · '+row.join_operator));
          section.append(group('City-level PDF matches (Draft candidates)',row.alternatives));
          section.append(group('Needs route review',row.review_queue));
          if(row.required&&!row.candidate_count)
            section.append(h('p','Required stage has no route-confirmed candidate; review before generating a package.'));
          result.append(section);
        }
      }catch(e){
        result.replaceChildren(h('p','Could not match this route: '+e.message));
      }finally{preview.disabled=false;}
    });
  });
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready);
else ready();
})();

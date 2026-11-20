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
      try{
        const body={
          tour_rate_id:cfg.postId,lang:cfg.lang,
          mode:mode.value,stages:collect()
        };
        const response=await fetch(cfg.route,{
          method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce},
          body:JSON.stringify(body)
        });
        const data=await response.json();
        if(!response.ok||data?.code)throw Error(data?.code||'route_request_failed');
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

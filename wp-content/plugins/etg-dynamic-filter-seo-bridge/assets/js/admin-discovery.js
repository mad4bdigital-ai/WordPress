(function(){
'use strict';
var cfg=window.ETGDFSB_ADMIN_DISCOVERY||{};
var cache={};
var TERM_FIELDS={name:'Name',slug:'Slug',description:'Description',short_description:'Short Description',seo_title:'SEO Title',meta_description:'Meta Description',focus_keyword:'Focus Keyword',image_id:'Image ID',image_url:'Image URL',count:'Term Count',location_level:'Location Level'};

function qsa(selector,root){return Array.prototype.slice.call((root||document).querySelectorAll(selector));}
function text(value){return String(value==null?'':value);}
function lower(value){return text(value).toLowerCase();}
function esc(value){return text(value).replace(/[&<>"']/g,function(ch){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];});}
function uniqueExact(values){var out=[],seen={};(values||[]).forEach(function(value){value=text(value).trim();if(!value||seen[value]){return;}seen[value]=true;out.push(value);});return out;}
function parseKeyList(value){return uniqueExact(text(value).split(/[\r\n,]+/));}
function setStatus(el,message,state){if(!el){return;}el.textContent=message||'';el.className='etg-fetch-status'+(state?' is-'+state:'');}
function request(action,payload){
    if(!cfg.ajaxUrl||!cfg.nonce||!action){return Promise.reject(new Error('discovery_not_configured'));}
    var form=new FormData();form.append('action',action);form.append('nonce',cfg.nonce);
    Object.keys(payload||{}).forEach(function(key){form.append(key,text(payload[key]));});
    return fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',body:form,headers:{'Accept':'application/json'}}).then(function(response){
        return response.text().then(function(body){var json;try{json=JSON.parse(body);}catch(e){throw new Error('invalid_response');}if(!response.ok||!json||json.success!==true){var reason=json&&json.data&&json.data.reason?json.data.reason:'request_failed';throw new Error(reason);}return json.data||{};});
    });
}

function optionLabel(item){
    var bits=[text(item.label||item.key||item.value)];
    if(item.kind){bits.push(text(item.kind));}
    if(item.term_hits){bits.push(text(item.term_hits)+' terms');}
    if(item.taxonomies&&item.taxonomies.length){bits.push(item.taxonomies.join(', '));}
    else if(item.taxonomy){bits.push(text(item.taxonomy));}
    return bits.join(' — ');
}

function syncSelectToInput(select,input,value,suffix){
    input.value=value||'';var found=false;qsa('option',select).forEach(function(opt){opt.selected=opt.value===value;if(opt.value===value){found=true;}});
    if(value&&!found){var opt=document.createElement('option');opt.value=value;opt.textContent=value+(suffix||' — manual/current');opt.selected=true;select.appendChild(opt);}
}

function enhanceDynamicMetaPicker(row){
    var input=row.querySelector('[name="source_meta_key[]"]');if(!input||input.dataset.etgFetchEnhanced==='1'){return;}
    input.dataset.etgFetchEnhanced='1';
    var cell=input.closest('[data-etg-source-field="meta"]');if(!cell){return;}
    var current=input.value;
    var wrapper=document.createElement('div');wrapper.className='etg-fetched-meta-picker';
    var select=document.createElement('select');select.className='etg-fetched-meta-select';select.setAttribute('aria-label','Fetched metadata key');
    var placeholder=document.createElement('option');placeholder.value='';placeholder.textContent='Select fetched metadata…';select.appendChild(placeholder);
    if(current){var existing=document.createElement('option');existing.value=current;existing.textContent=current+' — current value';existing.selected=true;select.appendChild(existing);}
    var button=document.createElement('button');button.type='button';button.className='button button-small';button.textContent='Fetch Metadata';
    var status=document.createElement('span');status.className='etg-fetch-status';status.setAttribute('aria-live','polite');
    var actions=document.createElement('div');actions.className='etg-fetch-picker-actions';actions.appendChild(select);actions.appendChild(button);
    var details=document.createElement('details');details.className='etg-advanced-key-fallback';var summary=document.createElement('summary');summary.textContent='Advanced manual key';details.appendChild(summary);
    var manualWrap=document.createElement('div');manualWrap.className='etg-advanced-key-input';details.appendChild(manualWrap);manualWrap.appendChild(input);
    wrapper.appendChild(actions);wrapper.appendChild(status);wrapper.appendChild(details);cell.appendChild(wrapper);

    function sourceType(){var el=row.querySelector('[name="source_type[]"]');return el?el.value:'';}
    function role(){var el=row.querySelector('[name="source_role[]"]');return el?el.value:'';}
    function aggregate(){var el=row.querySelector('[name="source_aggregate[]"]');return el?el.value:'';}
    function cacheKey(){return[sourceType(),role(),'meta'].join('|');}
    function syncManual(value){syncSelectToInput(select,input,value,' — manual/current');}
    function render(items,data){
        var selected=input.value;select.innerHTML='';var p=document.createElement('option');p.value='';p.textContent='Select fetched metadata…';select.appendChild(p);
        (items||[]).forEach(function(item){var opt=document.createElement('option');opt.value=text(item.value||item.key);opt.textContent=optionLabel(item);opt.dataset.kind=text(item.kind);select.appendChild(opt);});
        if(selected){syncManual(selected);}else{
            var preferred=(items||[]).filter(function(item){if(!item.recommended){return false;}var agg=aggregate();if('gallery'===agg){return ['gallery','repeater','media','complex'].indexOf(item.kind)!==-1;}if('image'===agg){return ['media','gallery'].indexOf(item.kind)!==-1;}return true;});
            if(preferred.length===1){syncManual(text(preferred[0].value||preferred[0].key));setStatus(status,'1 high-confidence option selected automatically.','ready');return;}
        }
        var reason=data&&data.reason?data.reason:'ready';if(items&&items.length){setStatus(status,text(items.length)+' options fetched from '+((data.scopes||[]).join(', ')||'current context')+'.','ready');}
        else if(reason==='relation_meta_requires_runtime_edge_context'){setStatus(status,'Relation edge Meta cannot be safely enumerated without an active relation edge. Use Advanced manual key only for this case.','warn');details.open=true;}
        else{setStatus(status,'No fetchable metadata found in the current context. Advanced manual key remains available.','warn');details.open=true;}
    }
    function load(){
        var type=sourceType();if(['term_meta','listing_meta','repeater','relation_meta'].indexOf(type)===-1){setStatus(status,'This source type does not require a Meta key.','muted');return Promise.resolve();}
        var key=cacheKey();button.disabled=true;button.textContent='Fetching…';setStatus(status,'Reading safe metadata catalog…','loading');
        var promise=cache[key]?Promise.resolve(cache[key]):request(cfg.catalogAction,{source_type:type,role:role(),purpose:'meta'}).then(function(data){cache[key]=data;return data;});
        return promise.then(function(data){render(data.items||[],data);}).catch(function(error){setStatus(status,'Fetch failed: '+error.message+'. Manual fallback is still available.','error');details.open=true;}).then(function(){button.disabled=false;button.textContent='Fetch Metadata';});
    }
    button.addEventListener('click',load);
    select.addEventListener('change',function(){input.value=select.value;input.dispatchEvent(new Event('change',{bubbles:true}));});
    input.addEventListener('input',function(){syncManual(input.value);});
    ['source_type[]','source_role[]'].forEach(function(name){var el=row.querySelector('[name="'+name+'"]');if(el){el.addEventListener('change',function(){if(!input.value&&['term_meta','listing_meta','repeater'].indexOf(sourceType())!==-1){setTimeout(load,80);}});}});
    select.addEventListener('focus',function(){if(select.options.length<=2){load();}});
}

function enhanceDynamicFieldPicker(row){
    var input=row.querySelector('[name="source_field[]"]');if(!input||input.dataset.etgFieldFetchEnhanced==='1'){return;}input.dataset.etgFieldFetchEnhanced='1';
    var cell=input.closest('[data-etg-source-field="field"]');if(!cell){return;}
    var wrapper=document.createElement('div');wrapper.className='etg-fetched-meta-picker etg-fetched-field-picker';
    var select=document.createElement('select');select.className='etg-fetched-meta-select';select.setAttribute('aria-label','Fetched field or path');
    var button=document.createElement('button');button.type='button';button.className='button button-small';button.textContent='Fetch Fields';
    var status=document.createElement('span');status.className='etg-fetch-status';status.setAttribute('aria-live','polite');
    var actions=document.createElement('div');actions.className='etg-fetch-picker-actions';actions.appendChild(select);actions.appendChild(button);
    var details=document.createElement('details');details.className='etg-advanced-key-fallback';var summary=document.createElement('summary');summary.textContent='Advanced manual field/path';details.appendChild(summary);var manual=document.createElement('div');manual.className='etg-advanced-key-input';manual.appendChild(input);details.appendChild(manual);
    wrapper.appendChild(actions);wrapper.appendChild(status);wrapper.appendChild(details);cell.appendChild(wrapper);
    function sourceType(){var el=row.querySelector('[name="source_type[]"]');return el?el.value:'';}
    function refillPlaceholder(label){select.innerHTML='';var p=document.createElement('option');p.value='';p.textContent=label||'Select fetched field…';select.appendChild(p);}
    function sync(value){syncSelectToInput(select,input,value,' — manual/current');}
    function renderTermFields(){refillPlaceholder('Select Term field…');Object.keys(TERM_FIELDS).forEach(function(key){var opt=document.createElement('option');opt.value=key;opt.textContent=TERM_FIELDS[key]+' ['+key+']';select.appendChild(opt);});sync(input.value);button.hidden=true;select.hidden=false;details.open=false;setStatus(status,'Standard Term fields are available by label; no identifier is required.','ready');}
    function renderFetched(items,data){refillPlaceholder('Select fetched field…');(items||[]).forEach(function(item){var opt=document.createElement('option');opt.value=text(item.value||item.path||item.key);opt.textContent=optionLabel(item);select.appendChild(opt);});sync(input.value);if(items&&items.length){setStatus(status,text(items.length)+' fields fetched from '+((data.scopes||[]).join(', ')||'JetEngine runtime')+'.','ready');}else{setStatus(status,'No listing fields discovered in the current admin context. Manual field/path remains available.','warn');details.open=true;}}
    function load(){var type=sourceType();if(type!=='listing_field'){return Promise.resolve();}var key='listing_field||field';button.disabled=true;button.textContent='Fetching…';setStatus(status,'Reading JetEngine field catalog…','loading');var promise=cache[key]?Promise.resolve(cache[key]):request(cfg.catalogAction,{source_type:'listing_field',purpose:'field'}).then(function(data){cache[key]=data;return data;});return promise.then(function(data){renderFetched(data.items||[],data);}).catch(function(error){setStatus(status,'Field fetch failed: '+error.message+'. Manual field/path remains available.','error');details.open=true;}).then(function(){button.disabled=false;button.textContent='Fetch Fields';});}
    function configure(){var type=sourceType();if(type==='term_field'){renderTermFields();return;}if(type==='listing_field'){button.hidden=false;select.hidden=false;details.open=false;refillPlaceholder('Select fetched field…');sync(input.value);setStatus(status,'Fetch fields from JetEngine/current listing context.','muted');if(!input.value){setTimeout(load,80);}return;}button.hidden=true;select.hidden=true;details.open=true;setStatus(status,'This field/path is context-specific. Manual path stays explicit rather than guessing a Query, Relation or Repeater shape.','muted');}
    button.addEventListener('click',load);select.addEventListener('change',function(){input.value=select.value;input.dispatchEvent(new Event('change',{bubbles:true}));});input.addEventListener('input',function(){sync(input.value);});var typeEl=row.querySelector('[name="source_type[]"]');if(typeEl){typeEl.addEventListener('change',configure);}configure();
}

function enhanceDynamicContent(){
    var root=document.querySelector('.etg-dfsb-dynamic-content');if(!root){return;}
    qsa('#etg-source-builder .etg-source-row',root).forEach(function(row){enhanceDynamicMetaPicker(row);enhanceDynamicFieldPicker(row);});
    var type=document.getElementById('etg-slot-type');if(type&&cfg.mediaLabUrl){
        var note=document.createElement('p');note.className='description etg-media-mode-shortcut';note.innerHTML='Image/Gallery collection strategy is managed with fetched Media tools in <a href="'+esc(cfg.mediaLabUrl)+'">Media Lab → Collection Modes</a>.';
        var row=type.closest('td');if(row){row.appendChild(note);}
        function toggle(){note.hidden=['image','gallery'].indexOf(type.value)===-1;}type.addEventListener('change',toggle);toggle();
    }
    document.addEventListener('click',function(event){var preset=event.target.closest('[data-etg-source-preset]');if(!preset){return;}setTimeout(function(){qsa('#etg-source-builder .etg-source-row',root).forEach(function(row){var alias=row.querySelector('[name="source_alias[]"]');if(alias&&alias.value&&!row.hidden){enhanceDynamicMetaPicker(row);enhanceDynamicFieldPicker(row);var typeEl=row.querySelector('[name="source_type[]"]');var meta=row.querySelector('[name="source_meta_key[]"]');if(typeEl&&meta&&!meta.value&&['term_meta','listing_meta','repeater'].indexOf(typeEl.value)!==-1){var btn=row.querySelector('.etg-fetched-meta-picker button');if(btn){btn.click();}}}});},0);});
}

var mediaTargets={};
function enhanceRawKeyList(textarea){
    if(!textarea||textarea.dataset.etgFetchEnhanced==='1'){return;}textarea.dataset.etgFetchEnhanced='1';
    var name=textarea.name;mediaTargets[name]=textarea;
    var holder=document.createElement('div');holder.className='etg-key-chip-picker';holder.dataset.target=name;
    var chips=document.createElement('div');chips.className='etg-key-chips';holder.appendChild(chips);
    var hint=document.createElement('p');hint.className='description';hint.textContent='Choose from fetched Meta fields below. Exact case is preserved.';holder.appendChild(hint);
    var details=document.createElement('details');details.className='etg-advanced-key-fallback';var summary=document.createElement('summary');summary.textContent='Advanced raw key list';details.appendChild(summary);
    textarea.parentNode.insertBefore(holder,textarea);details.appendChild(textarea);holder.appendChild(details);
    function render(){chips.innerHTML='';parseKeyList(textarea.value).forEach(function(key){var chip=document.createElement('span');chip.className='etg-key-chip';chip.innerHTML='<code>'+esc(key)+'</code>';var remove=document.createElement('button');remove.type='button';remove.className='etg-key-chip-remove';remove.setAttribute('aria-label','Remove '+key);remove.textContent='×';remove.addEventListener('click',function(){setKeyList(name,parseKeyList(textarea.value).filter(function(value){return value!==key;}));});chip.appendChild(remove);chips.appendChild(chip);});if(!chips.children.length){var empty=document.createElement('span');empty.className='etg-fetch-status is-muted';empty.textContent='No keys selected.';chips.appendChild(empty);}}
    textarea.addEventListener('input',render);render();
}
function setKeyList(name,keys){var textarea=mediaTargets[name];if(!textarea){return;}textarea.value=uniqueExact(keys).join('\n');textarea.dispatchEvent(new Event('input',{bubbles:true}));}
function addKey(name,key){var textarea=mediaTargets[name];if(!textarea){return false;}var keys=parseKeyList(textarea.value);if(keys.indexOf(key)===-1){keys.push(key);setKeyList(name,keys);}return true;}

function buildCatalogPanel(lab,taxonomy){
    var old=document.getElementById('etg-live-metadata-catalog');if(old){return old;}
    var section=document.createElement('section');section.className='etg-panel';section.id='etg-live-metadata-catalog';
    section.innerHTML='<div class="etg-panel__head"><div><h2>Fetched Metadata Catalog</h2><p class="description">Live, bounded, case-preserving discovery. Safe scalar Meta is shown too; only verified image attachments can be mapped as Image/Gallery.</p></div><div class="etg-actions"><button type="button" class="button" data-etg-fetch-catalog>Fetch Metadata</button><button type="button" class="button" data-etg-add-recommended>Add Recommended Media</button></div></div><div class="etg-panel__body"><div class="etg-fetch-toolbar"><input type="search" class="regular-text" placeholder="Search Meta key, kind or sample…" data-etg-catalog-search><span class="etg-fetch-status" data-etg-catalog-status aria-live="polite"></span></div><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Meta Key</th><th>Kind</th><th>Observed</th><th>Samples</th><th>Verified Media</th><th>Map</th></tr></thead><tbody data-etg-catalog-body><tr><td colspan="6">Fetch metadata to populate this catalog.</td></tr></tbody></table></div></div>';
    var panels=qsa('.etg-panel',lab);if(panels.length){panels[0].parentNode.insertBefore(section,panels[0].nextSibling);}else{lab.appendChild(section);}section.dataset.taxonomy=taxonomy||'';return section;
}
function renderCatalog(section,data){
    var body=section.querySelector('[data-etg-catalog-body]');var status=section.querySelector('[data-etg-catalog-status]');body.innerHTML='';var items=data.items||[];
    if(!items.length){body.innerHTML='<tr><td colspan="6">No safe metadata detected in the bounded sample.</td></tr>';setStatus(status,'No metadata found.','warn');section._items=[];return;}
    items.forEach(function(item){var tr=document.createElement('tr');var samples=(item.sample_values||[]).join(' · ');var terms=(item.sample_terms||[]).join(', ');var media=(item.media_ids||[]);tr.dataset.search=lower([item.key,item.label,item.kind,samples,terms,(item.taxonomies||[]).join(' ')].join(' '));
        var map='';if(media.length){map='<div class="etg-map-buttons"><button type="button" class="button button-small" data-etg-map="taxonomy_image_keys" data-key="'+esc(item.key)+'">Tax Image</button><button type="button" class="button button-small" data-etg-map="taxonomy_gallery_keys" data-key="'+esc(item.key)+'">Tax Gallery</button><button type="button" class="button button-small" data-etg-map="global_image_keys" data-key="'+esc(item.key)+'">Global Image</button><button type="button" class="button button-small" data-etg-map="global_gallery_keys" data-key="'+esc(item.key)+'">Global Gallery</button></div>';}else{map='<span class="etg-fetch-status is-muted">Dynamic Content only</span>';}
        tr.innerHTML='<td><strong><code>'+esc(item.key)+'</code></strong><br><small>'+esc(item.label||'')+'</small></td><td><span class="etg-badge etg-badge--readonly">'+esc(item.kind||'scalar')+'</span><br><small>'+esc(item.confidence||'')+'</small></td><td>'+esc(text(item.term_hits||0))+' term(s)<br><small>'+esc(terms)+'</small></td><td>'+esc(samples||'—')+'</td><td>'+(media.length?'<code>'+esc(media.join(','))+'</code>':'—')+'</td><td>'+map+'</td>';body.appendChild(tr);
    });section._items=items;setStatus(status,text(items.length)+' safe Meta keys fetched from '+((data.scopes||[]).join(', ')||section.dataset.taxonomy)+'.','ready');
}
function fetchMediaCatalog(section,taxonomy){var status=section.querySelector('[data-etg-catalog-status]');var button=section.querySelector('[data-etg-fetch-catalog]');button.disabled=true;button.textContent='Fetching…';setStatus(status,'Scanning bounded Term Meta…','loading');return request(cfg.catalogAction,{source_type:'term_meta',taxonomy:taxonomy,purpose:'meta'}).then(function(data){renderCatalog(section,data);}).catch(function(error){setStatus(status,'Fetch failed: '+error.message,'error');}).then(function(){button.disabled=false;button.textContent='Fetch Metadata';});}

function enhanceMediaLab(){
    var lab=document.querySelector('.etg-media-lab');if(!lab){return;}
    ['global_image_keys','global_gallery_keys','taxonomy_image_keys','taxonomy_gallery_keys'].forEach(function(name){var area=lab.querySelector('textarea[name="'+name+'"]');if(area){enhanceRawKeyList(area);}});
    var saveTax=lab.querySelector('form[action*="admin-post"] input[name="taxonomy"]');var loadedTaxonomy=saveTax?saveTax.value:'';var selector=lab.querySelector('form[method="get"] select[name="taxonomy"]');if(!loadedTaxonomy&&selector){loadedTaxonomy=selector.value;}
    var tab='';try{tab=new URLSearchParams(window.location.search).get('tab')||'discovery';}catch(e){tab='discovery';}
    if('discovery'===tab&&loadedTaxonomy){
        var section=buildCatalogPanel(lab,loadedTaxonomy);var scanForm=selector?selector.closest('form'):null;
        if(scanForm){var submit=scanForm.querySelector('button,input[type="submit"]');if(submit){submit.textContent='Fetch / Open Taxonomy';}scanForm.addEventListener('submit',function(event){if(selector&&selector.value===loadedTaxonomy){event.preventDefault();fetchMediaCatalog(section,loadedTaxonomy);}});}
        section.querySelector('[data-etg-fetch-catalog]').addEventListener('click',function(){fetchMediaCatalog(section,loadedTaxonomy);});
        section.querySelector('[data-etg-catalog-search]').addEventListener('input',function(event){var needle=lower(event.target.value).trim();qsa('tbody tr',section).forEach(function(row){row.hidden=!!needle&&lower(row.dataset.search||row.textContent).indexOf(needle)===-1;});});
        section.addEventListener('click',function(event){var mapButton=event.target.closest('[data-etg-map]');if(mapButton){var target=mapButton.getAttribute('data-etg-map');var key=mapButton.getAttribute('data-key');if(addKey(target,key)){mapButton.textContent='Added';setTimeout(function(){mapButton.textContent=target.indexOf('global_')===0?(target.indexOf('image')!==-1?'Global Image':'Global Gallery'):(target.indexOf('image')!==-1?'Tax Image':'Tax Gallery');},900);}return;}var recommended=event.target.closest('[data-etg-add-recommended]');if(recommended){var count=0;(section._items||[]).forEach(function(item){if(!(item.media_ids||[]).length){return;}var target=item.kind==='gallery'?'taxonomy_gallery_keys':'taxonomy_image_keys';if(addKey(target,item.key)){count++;}});setStatus(section.querySelector('[data-etg-catalog-status]'),text(count)+' recommended media mappings staged. Click Save Media Discovery Mapping to persist.','ready');}});
        var legacy=document.getElementById('etg-media-field-table');if(legacy){var legacyPanel=legacy.closest('.etg-panel');if(legacyPanel){legacyPanel.hidden=true;}}
        setTimeout(function(){fetchMediaCatalog(section,loadedTaxonomy);},120);
    }
    if('collections'===tab){loadMediaSlots(lab);}
}

function loadMediaSlots(lab){
    request(cfg.mediaSlotsAction,{}).then(function(data){var panel=document.createElement('section');panel.className='etg-panel';panel.id='etg-media-slot-modes';
        panel.innerHTML='<div class="etg-panel__head"><div><h2>Media Slot Modes</h2><p class="description">Choose a collection strategy by label; no IDs or raw configuration are required.</p></div></div><div class="etg-panel__body"><div class="etg-table-scroll"><table class="widefat striped"><thead><tr><th>Slot</th><th>Type</th><th>Origin</th><th>Collection Mode</th><th></th></tr></thead><tbody></tbody></table></div><p class="description">Changing a built-in slot creates a normal presentation-only override. Use Dynamic Content → Slots → Reset to remove that override.</p></div>';
        var tbody=panel.querySelector('tbody');var modes=data.modes||{};(data.slots||[]).forEach(function(slot){var tr=document.createElement('tr');var options='<option value="">Select mode…</option>';Object.keys(modes).forEach(function(mode){options+='<option value="'+esc(mode)+'"'+(mode===slot.mode?' selected':'')+'>'+esc(modes[mode])+' ['+esc(mode)+']</option>';});tr.innerHTML='<td><strong>'+esc(slot.label)+'</strong><br><code>'+esc(slot.id)+'</code></td><td>'+esc(slot.type)+'</td><td>'+esc(slot.origin)+'</td><td><select data-etg-slot-mode>'+options+'</select></td><td><button type="button" class="button button-small" data-etg-save-slot-mode data-slot="'+esc(slot.id)+'">Save Mode</button> <span class="etg-fetch-status" aria-live="polite"></span></td>';tbody.appendChild(tr);});
        var first=lab.querySelector('.etg-panel');if(first){first.parentNode.insertBefore(panel,first.nextSibling);}else{lab.appendChild(panel);}panel.addEventListener('click',function(event){var button=event.target.closest('[data-etg-save-slot-mode]');if(!button){return;}var row=button.closest('tr');var select=row.querySelector('[data-etg-slot-mode]');var status=row.querySelector('.etg-fetch-status');if(!select.value){setStatus(status,'Choose a mode first.','warn');return;}button.disabled=true;button.textContent='Saving…';request(cfg.saveMediaModeAction,{slot_id:button.getAttribute('data-slot'),media_mode:select.value}).then(function(result){setStatus(status,'Saved: '+result.media_mode+'.','ready');}).catch(function(error){setStatus(status,'Save failed: '+error.message,'error');}).then(function(){button.disabled=false;button.textContent='Save Mode';});});
    }).catch(function(error){var notice=document.createElement('div');notice.className='notice notice-error inline';notice.innerHTML='<p>Media Slot fetch failed: '+esc(error.message)+'</p>';lab.appendChild(notice);});
}

document.addEventListener('DOMContentLoaded',function(){enhanceDynamicContent();enhanceMediaLab();});
})();

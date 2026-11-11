/* MAD4B Passport Country AJAX Search v1.3
 * Inline searchable list within Fluent Form #6 (all viewports).
 * The original Fluent Forms select remains the only submitted value.
 * The existing WordPress visitor-preferences:saved bridge owns header inheritance.
 */
(() => {
  'use strict';
  const cfg = window.MAD4BPassportSearchV1;
  if (!cfg || !/^https?:\/\//.test(cfg.endpoint || '')) return;
  const locale = (document.documentElement.lang || cfg.lang || 'en').slice(0, 2).toLowerCase();
  const lang = ['en','es','ar','de','fr','it'].includes(locale) ? locale : 'en';
  const COPY = {
    en:['Choose passport country','Search by country or nationality','No matching countries','Country search unavailable. Use the original list.','Use standard country list','Clear choice'],
    ar:['اختر دولة جواز السفر','ابحث باسم الدولة أو الجنسية','لا توجد نتائج','البحث غير متاح. استخدم القائمة الأصلية.','استخدم القائمة الأصلية','مسح الاختيار'],
    es:['Seleccionar país del pasaporte','Buscar país o nacionalidad','No hay resultados','Búsqueda no disponible. Usa la lista original.','Usar lista original','Borrar selección'],
    fr:['Choisir le pays du passeport','Rechercher un pays ou une nationalité','Aucun résultat','Recherche indisponible. Utilisez la liste d’origine.','Utiliser la liste d’origine','Effacer'],
    de:['Passland auswählen','Land oder Nationalität suchen','Keine Treffer','Suche nicht verfügbar. Originalauswahl verwenden.','Originalauswahl verwenden','Auswahl löschen'],
    it:['Scegli il paese del passaporto','Cerca paese o nazionalità','Nessun risultato','Ricerca non disponibile. Usa elenco originale.','Usa elenco originale','Cancella']
  };
  const text = COPY[lang];
  const cache = new Map();
  let counter = 0;
  const make = (tag, cls, value) => {
    const node=document.createElement(tag);
    if(cls)node.className=cls;
    if(value!==undefined)node.textContent=value;
    return node;
  };
  function setup(field) {
    if (field.dataset.mad4bPassportAjaxReady === 'v1.3' ||
        field.options.length !== 196 || !field.options[1]?.dataset.passportIso ||
        !field.closest('form.fluent_form_6')) return;
    const wrapper=field.parentElement;
    if (!wrapper) return;
    const originalOptions=[...field.options].slice(1);
    const localRows=originalOptions.map(option=>({
      value:option.value,
      label:(option.textContent||'').trim(),
      iso:option.dataset.passportIso||''
    }));
    if(localRows.length!==195 ||
      localRows.some(row=>!row.value||!row.label||!/^[A-Z]{2}$/.test(row.iso)))return;
    const id='mad4b-passport-inline-'+(++counter);
    const box=make('div','mad4b-passport-inline');
    const trigger=make('button','mad4b-passport-trigger');
    trigger.type='button';
    trigger.setAttribute('aria-expanded','false');
    trigger.setAttribute('aria-haspopup','listbox');
    trigger.setAttribute('aria-controls',id);
    const dropdown=make('div','mad4b-passport-dropdown');
    dropdown.id=id;
    dropdown.hidden=true;
    const search=make('input','mad4b-passport-search-input');
    search.type='search';
    search.placeholder=text[1];
    search.autocomplete='off';
    search.spellcheck=false;
    search.setAttribute('aria-label',text[1]);
    search.setAttribute('role','combobox');
    search.setAttribute('aria-autocomplete','list');
    search.setAttribute('aria-expanded','false');
    const results=make('div','mad4b-passport-results');
    results.id=id+'-results';
    results.setAttribute('role','listbox');
    search.setAttribute('aria-controls',results.id);
    const status=make('p','mad4b-passport-status');
    status.setAttribute('role','status');
    status.setAttribute('aria-live','polite');
    const actions=make('div','mad4b-passport-actions');
    const fallback=make('button','mad4b-passport-fallback',text[4]);
    const clear=make('button','mad4b-passport-clear',text[5]);
    fallback.type='button';
    clear.type='button';
    actions.append(fallback,clear);
    dropdown.append(search,results,status,actions);
    box.append(trigger,dropdown);
    field.insertAdjacentElement('afterend',box);
    field.dataset.mad4bPassportAjaxReady='v1.3';
    field.classList.add('mad4b-passport-native-hidden');
    field.setAttribute('aria-hidden','true');
    field.tabIndex=-1;
    let controller=null, seq=0, timer=null, focused=-1, hits=[];
    // All 195 original options already contain the correctly translated label
    // and the original saved value. Index once, then render searches instantly
    // without waiting for a 2-5 second WordPress REST bootstrap.
    const normalize=value=>String(value||'').normalize('NFD')
      .replace(/\p{M}/gu,'').toLocaleLowerCase(lang).trim();
    const priorities=['EG','US','GB','AE','FR','DE','ES','IT'];
    const localSearch=query=>{
      const needle=normalize(query);
      let matched=localRows.filter(row=>
        !needle||normalize(row.label+' '+row.value+' '+row.iso).includes(needle));
      if(!needle) {
        const top=priorities.map(iso=>matched.find(row=>row.iso===iso)).filter(Boolean);
        const important=new Set(top.map(row=>row.value));
        matched=top.concat(matched.filter(row=>!important.has(row.value)));
      }
      return matched.slice(0,14);
    };
    const selectedLabel=()=>field.value?field.selectedOptions[0]?.textContent?.trim()||text[0]:text[0];
    function updateButton() {
      const label=selectedLabel();
      trigger.textContent=label;
      trigger.setAttribute('aria-label',label);
      box.dataset.selected=field.value?'yes':'no';
    }
    function close(focus=false) {
      ++seq;
      controller?.abort();
      clearTimeout(timer);
      dropdown.hidden=true;
      trigger.setAttribute('aria-expanded','false');
      search.setAttribute('aria-expanded','false');
      search.removeAttribute('aria-activedescendant');
      if(focus)trigger.focus({preventScroll:true});
    }
    function pick(value) {
      const match=[...field.options].find(opt=>opt.value===value);
      if(!match)return;
      field.value=value;
      field.dispatchEvent(new Event('input',{bubbles:true}));
      field.dispatchEvent(new Event('change',{bubbles:true}));
      updateButton();
      close(true);
    }
    function focusResult(i) {
      const buttons=[...results.querySelectorAll('button[role="option"]')];
      if(!buttons.length)return;
      focused=Math.max(0,Math.min(buttons.length-1,i));
      buttons.forEach((button,index)=>button.setAttribute('aria-selected',String(index===focused)));
      search.setAttribute('aria-activedescendant',buttons[focused].id);
      buttons[focused].scrollIntoView({block:'nearest'});
    }
    function render(rows) {
      hits=rows;
      focused=-1;
      results.replaceChildren();
      search.removeAttribute('aria-activedescendant');
      rows.forEach((row,index)=>{
        const option=make('button','mad4b-passport-result',row.label);
        option.type='button';
        option.id=id+'-option-'+index;
        option.setAttribute('role','option');
        option.setAttribute('aria-selected','false');
        option.addEventListener('mousedown',event=>event.preventDefault());
        option.addEventListener('click',()=>pick(row.value));
        results.append(option);
      });
      status.textContent=rows.length?'':text[2];
    }
    async function searchServer() {
      controller?.abort();
      const request=++seq;
      const query=search.value.trim().slice(0,48);
      const key=lang+'|'+query.toLocaleLowerCase(lang);
      if(cache.has(key)){render(cache.get(key));return;}
      controller=new AbortController();
      status.textContent='…';
      const uri=new URL(cfg.endpoint);
      uri.searchParams.set('q',query);
      uri.searchParams.set('lang',lang);
      try {
        const r=await fetch(uri,{
          credentials:'same-origin',method:'GET',cache:'no-store',
          headers:{Accept:'application/json'},signal:controller.signal
        });
        if(!r.ok)throw new Error('http '+r.status);
        const payload=await r.json();
        if(request!==seq || dropdown.hidden || !Array.isArray(payload?.results))return;
        const available=new Set([...field.options].map(opt=>opt.value));
        const rows=payload.results.slice(0,14).filter(item=>
          item && typeof item.value==='string'&&typeof item.label==='string'&&
          item.label.length<=100&&available.has(item.value)
        );
        cache.set(key,rows);
        if(cache.size>45)cache.delete(cache.keys().next().value);
        render(rows);
      } catch(error) {
        if(error.name==='AbortError'||request!==seq)return;
        results.replaceChildren();
        status.textContent=text[3];
        fallback.hidden=false;
      }
    }
    function searchInstantly() {
      ++seq;
      controller?.abort();
      clearTimeout(timer);
      const query=search.value.trim().slice(0,48);
      const rows=localSearch(query);
      // Normal search uses the exact current Form #6 localized choices.
      // Only unfamiliar spellings require the legacy AJAX endpoint.
      if(rows.length||!query) {
        render(rows);
        return;
      }
      results.replaceChildren();
      status.textContent='…';
      timer=setTimeout(searchServer,90);
    }
    function open() {
      if(!dropdown.hidden) {close(true);return;}
      document.querySelectorAll('.mad4b-passport-inline').forEach(other=>{
        if(other!==box)other.dispatchEvent(new Event('mad4b-passport-close'));
      });
      dropdown.hidden=false;
      trigger.setAttribute('aria-expanded','true');
      search.setAttribute('aria-expanded','true');
      search.value='';
      searchInstantly();
      search.focus({preventScroll:true});
    }
    trigger.addEventListener('click',open);
    box.addEventListener('mad4b-passport-close',()=>close());
    search.addEventListener('input',searchInstantly);
    search.addEventListener('keydown',event=>{
      if(event.key==='Escape'){event.preventDefault();close(true);}
      else if(event.key==='ArrowDown'||event.key==='ArrowUp'){
        event.preventDefault();focusResult(focused+(event.key==='ArrowDown'?1:-1));
      }else if(event.key==='Enter'){
        event.preventDefault();
        if(focused>=0&&hits[focused])pick(hits[focused].value);
        else if(hits.length===1)pick(hits[0].value);
      }
    });
    fallback.addEventListener('click',()=>{
      close();field.classList.remove('mad4b-passport-native-hidden');
      field.removeAttribute('aria-hidden');field.tabIndex=0;
      box.hidden=true;field.focus();
    });
    clear.addEventListener('click',()=>pick(''));
    field.addEventListener('change',updateButton);
    field.addEventListener('invalid',event=>{
      if(field.value||!field.required)return;
      event.preventDefault();if(dropdown.hidden)open();
    });
    // Existing visitor-preferences:saved handler applies the header ISO to the
    // original field. React to its change, never override user-edited selection.
    document.addEventListener('pointerdown',event=>{
      if(!box.contains(event.target) && !dropdown.hidden)close();
    });
    document.addEventListener('keydown',event=>{
      if(event.key==='Escape'&&!dropdown.hidden){event.preventDefault();close(true);}
    });
    updateButton();
  }
  function mount() {
    document.querySelectorAll('form.fluent_form_6 select[name="dropdown_1"]').forEach(setup);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});
  else mount();
  let queued=false;
  new MutationObserver(()=>{
    if(queued)return;
    queued=true;
    queueMicrotask(()=>{queued=false;mount();});
  }).observe(document.body,{childList:true,subtree:true});
})();

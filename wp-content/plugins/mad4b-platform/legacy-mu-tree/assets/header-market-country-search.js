/* MAD4B Header — market country searchable combobox v1.9.
 * UI enhancement only. ISO catalogue and nonce-protected admin-post form
 * remain native MAD4B_Country_Switcher authority. No AJAX per keystroke. */
(function(){
 'use strict';
 let counter=0;
 const roots=()=>document.querySelectorAll('.elementor-location-header .header-context-panel form.mad4b-country-switcher');
 const locale=()=>((document.documentElement.lang||'en').slice(0,2)||'en').toLowerCase();
 const copy={
  en:['Search country','No countries found','Country'],
  es:['Buscar país','No se encontraron países','País'],
  fr:['Rechercher un pays','Aucun pays trouvé','Pays'],
  de:['Land suchen','Keine Länder gefunden','Land'],
  it:['Cerca un paese','Nessun paese trovato','Paese'],
  ar:['ابحث عن دولة','لم يتم العثور على دول','الدولة']
 };
 const labels=()=>copy[locale()]||copy.en;
 function enhance(form){
  const select=form.querySelector('select[name="market_country"]');
  const label=select?.closest('label');
  if(!select||!label||form.dataset.mad4bCountrySearch==='v1.9')return;
  form.dataset.mad4bCountrySearch='v1.9';
  const listId='market-country-options-'+(++counter);
  const box=document.createElement('div');box.className='mad4b-country-search';
  const input=document.createElement('input');input.type='search';input.className='mad4b-country-search__input';
  input.placeholder=labels()[0];input.setAttribute('aria-label',labels()[2]);
  input.setAttribute('role','combobox');input.setAttribute('aria-haspopup','listbox');
  input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-controls',listId);
  input.setAttribute('aria-expanded','false');input.autocomplete='off';input.spellcheck=false;
  const list=document.createElement('ul');list.id=listId;list.className='mad4b-country-search__results';
  list.setAttribute('role','listbox');list.hidden=true;
  const feedback=document.createElement('span');feedback.className='mad4b-country-search__feedback';
  feedback.setAttribute('role','status');feedback.setAttribute('aria-live','polite');feedback.hidden=true;
  box.append(input,list,feedback);select.insertAdjacentElement('afterend',box);
  select.classList.add('mad4b-country-search__native');
  select.tabIndex=-1;select.setAttribute('aria-hidden','true');
  let results=[],active=-1,shown=0,closing=0,composing=false;
  const names=()=>{
   let display=null;try{display=new Intl.DisplayNames([locale()],{type:'region'});}catch(e){}
   return [...select.options].filter(o=>/^[A-Z]{2}$/.test(o.value)).map(o=>{
    const original=o.textContent.trim();let translated='';
    try{translated=display?.of(o.value)||'';}catch(e){}
    return {id:o.value,name:translated&&translated!==o.value?translated:original,original};
   });
  };
  const normalize=s=>String(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase(locale()).trim();
  function selectedName(){const item=names().find(x=>x.id===select.value);return item?.name||'';}
  function sync(){if(document.activeElement===input&&!list.hidden)return;input.value=selectedName();}
  function close(){list.hidden=true;list.replaceChildren();input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');active=-1;shown=0;feedback.hidden=true;feedback.textContent='';}
  function highlight(){[...list.querySelectorAll('[role="option"]')].forEach((node,i)=>{
    const yes=i===active;node.setAttribute('aria-selected',String(yes));if(yes){input.setAttribute('aria-activedescendant',node.id);node.scrollIntoView({block:'nearest'});}
  });if(active<0)input.removeAttribute('aria-activedescendant');}
  function choose(index){const item=results[index];if(!item)return;
    const exists=[...select.options].some(o=>o.value===item.id);if(!exists)return;
    // A late public state read must not replace a user-confirmed choice.
    form.dataset.mad4bCountrySearchUserChoice='1';
    select.value=item.id;input.value=item.name;close();
    // Existing native header controller submits original form with its nonce.
    select.dispatchEvent(new Event('change',{bubbles:true}));
  }
  function appendPage(){const max=Math.min(shown+45,results.length);
    for(let i=shown;i<max;i++){
      const item=results[i],li=document.createElement('li');li.className='mad4b-country-search__option';
      li.setAttribute('role','option');li.id=listId+'-'+i;li.dataset.code=item.id;
      li.setAttribute('aria-selected',String(i===active));li.textContent=item.name;
      li.addEventListener('mousedown',e=>e.preventDefault());
      li.addEventListener('click',()=>choose(i));list.appendChild(li);
    }
    shown=max;
  }
  function render(){const q=normalize(input.value);results=names().filter(item=>!q||normalize(item.name).includes(q)||normalize(item.original).includes(q)||item.id.toLowerCase().includes(q));
    shown=0;active=-1;list.replaceChildren();
    if(!results.length){const li=document.createElement('li');li.className='mad4b-country-search__empty';li.setAttribute('role','presentation');li.textContent=labels()[1];list.appendChild(li);}
    else appendPage();list.hidden=false;input.setAttribute('aria-expanded','true');input.removeAttribute('aria-activedescendant');
  }
  input.addEventListener('focus',()=>{clearTimeout(closing);render()});
  input.addEventListener('compositionstart',()=>{composing=true});
  input.addEventListener('compositionend',()=>{composing=false;render()});
  input.addEventListener('input',()=>{if(!composing)render()});
  input.addEventListener('keydown',e=>{
    if(e.key==='Escape'){if(!list.hidden){e.preventDefault();e.stopPropagation();close();sync();}return;}
    if(e.key==='ArrowDown'||e.key==='ArrowUp'){
      e.preventDefault();if(list.hidden){render();return;}
      if(!results.length)return;
      const prev=active;active=e.key==='ArrowDown'?Math.min(active+1,results.length-1):Math.max(0,active-1);
      if(active>=shown){appendPage();}if(active!==prev)highlight();return;
    }
    if(e.key==='Enter'){e.preventDefault();if(!list.hidden){if(active>=0)choose(active);else if(results.length===1)choose(0);}return;}
  });
  input.addEventListener('blur',()=>{closing=setTimeout(()=>{close();sync()},150)});
  list.addEventListener('scroll',()=>{if(!list.hidden&&shown<results.length&&list.scrollTop+list.clientHeight>=list.scrollHeight-32)appendPage()});
  document.addEventListener('pointerdown',e=>{if(!box.contains(e.target)){close();sync()}});
  form.addEventListener('country-search:sync',sync);
  document.addEventListener('header-preferences:state',()=>{if(!form.isConnected)return;sync()});
  sync();
 }
 function boot(){roots().forEach(enhance)}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
})();

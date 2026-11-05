/* MAD4B native WP_List_Table UX. Never clone, delete, or update post data. */
(function () {
 'use strict';
 // Apply localized, accessible icon labels to the original WordPress
 // Screen Options / Help buttons. No replacement controls or click handlers.
 function decorateScreenMetaRail(){
  const rail=document.getElementById('screen-meta-links');
  if(!rail)return false;
  for(const id of ['show-settings-link','contextual-help-link']){
   const button=document.getElementById(id);
   if(!button)continue;
   const label=(button.textContent||'').trim();
   if(!label)continue;
   if(!button.dataset.mad4bRailLabel)button.dataset.mad4bRailLabel=label;
   if(!button.hasAttribute('aria-label'))button.setAttribute('aria-label',label);
   if(!button.title)button.title=label;
  }
  rail.dataset.mad4bFloatingRail='1';
  return true;
 }
 if(!decorateScreenMetaRail()){
  const railObserver=new MutationObserver(()=>{
   if(decorateScreenMetaRail())railObserver.disconnect();
  });
  railObserver.observe(document.documentElement,{childList:true,subtree:true});
  document.addEventListener('DOMContentLoaded',()=>{
   decorateScreenMetaRail();railObserver.disconnect();
  },{once:true});
 }
 // The file is loaded in <head>; initialize synchronously as soon as
 // WordPress inserts #wpbody-content, not at the slow footer script stage.
 function initialize(){
 const root=document.querySelector('#wpbody-content');
 if(!root)return false;
 if(root.dataset.mad4bTablesReady==='1')return true;
 root.dataset.mad4bTablesReady='1';
 const labels=Object.assign({cards:'Cards',table:'Table',records:'Records',
    scroll:'Scroll horizontally for more columns',showDetails:'More fields',hideDetails:'Fewer fields'},
    window.MAD4BCompanyAdminTableLabels||{});
 const forbidden='table.form-table,table.plugins,table.media,table#comments,table.acf-table,.dataTables_wrapper table,table.dt-table,.elementor-panel table,table[data-mad4b-table-native]';
 const seen=new WeakSet();let pending=false;
 const trim=x=>String(x||'').replace(/\s+/g,' ').trim().slice(0,95);
 const empty=x=>/^(?:null|undefined|\[null\]|["']?\s*["']?|0)$/i.test(trim(x));
 const rowSelector='tbody tr:not(.no-items):not(.inline-edit-row):not(.plugin-update-tr)';
 const allowed=table=>table instanceof HTMLTableElement &&
   !table.matches(forbidden) && !table.closest('.mad4b-admin-table-shell, .components-modal__content,.inline-edit-row') &&
   (table.matches('.wp-list-table,.widefat')||table.closest('[data-mad4b-admin-data-table]')) &&
   !!table.tHead && table.tBodies.length>0 && table.tHead.rows.length>0 &&
   table.tHead.rows[0].cells.length>=3 && table.tBodies[0].rows.length>0 &&
   !table.querySelector('tbody tr:not(.no-items) > td[colspan], tbody tr > th[colspan]');
 const headerText=header=>trim([...header.childNodes].filter(n=>n.nodeType===3 ||
   n.nodeType===1 && !(n.classList?.contains('screen-reader-text') || n.classList?.contains('sorting-indicators')))
   .map(n=>n.textContent).join(' '));
 function labelsFor(table){
  const headers=[...table.tHead.rows[0].cells].map(headerText);
  for(const row of table.querySelectorAll(rowSelector)){
   const cells=[...row.cells];
   for(const cell of cells){
    const attr=trim(cell.getAttribute('data-colname'));
    const heading=attr||headers[cell.cellIndex]||'';
    if(heading&&!cell.dataset.mad4bColTitle)cell.dataset.mad4bColTitle=heading;
   }
  }
 }
 function primaryCell(row){
  // WP_Posts_List_Table uses <th class="column-primary"> while JetEngine
  // can insert hidden technical columns before the title. Ignore cell order.
  const cells=[...row.cells];
  const primaryLink=c=>c.querySelector('a.row-title, strong a, a[href*="action=edit"]');
  const candidate=cells.find(c=>!c.classList.contains('hidden') &&
     c.matches('.column-primary,.column-title,.column-name,.column-post-title,.column-slug') && primaryLink(c));
  const cell=candidate || cells.find(c=>!c.classList.contains('hidden')&&primaryLink(c));
  const title=cell&&trim(primaryLink(cell)?.textContent||cell.querySelector('strong')?.textContent||'');
  return title && !empty(title)?cell:null;
 }
 // Use actual native <details> inside each secondary table cell, never
 // clones of WordPress or WPML controls. Unwrap before returning to Table mode.
 function languageColumn(cell){
   const name=(cell.className+' '+(cell.dataset.colname||'')+' '+
      (cell.dataset.mad4bColTitle||'')).toLowerCase();
   return /(?:^|[\s_-])(language|languages|wpml|icl_translations|translation)(?:[\s_-]|$)/i.test(name);
 }
 function nestedFields(row,cardMode){
   const fields=[...row.cells].filter(c=>c.dataset.mad4bCardSlot==='detail');
   const lang=fields.findIndex(languageColumn);
   // No WPML column: keep Author and Categories full-width, nest remaining.
   const split=lang>=0?lang:Math.min(1,fields.length-1);
   for(let i=0;i<fields.length;i++){
     const cell=fields[i];
     const original=cell.querySelector(':scope > details.mad4b-card-field-accordion');
     const nested=cardMode && i>split;
     if(!nested){
       if(original){
         const inner=original.querySelector(':scope > .mad4b-card-field-content');
         if(inner)while(inner.firstChild)cell.insertBefore(inner.firstChild,original);
         original.remove();
       }
       delete cell.dataset.mad4bCardNested;
       continue;
     }
     if(original)continue;
     const label=trim(cell.dataset.mad4bColTitle||cell.dataset.colname||'Details')||'Details';
     const box=document.createElement('details');box.className='mad4b-card-field-accordion';
     const summary=document.createElement('summary');summary.textContent=label;
     summary.className='mad4b-card-field-summary';
     const content=document.createElement('div');content.className='mad4b-card-field-content';
     while(cell.firstChild)content.append(cell.firstChild);
     box.append(summary,content);cell.append(box);
     cell.dataset.mad4bCardNested='1';
   }
 }
 function paintRow(table,row,hasCards){
  if(!hasCards||row.matches('.no-items,.inline-edit-row,.plugin-update-tr'))return;
  const primary=primaryCell(row);
  if(!primary)return;
  const wasReady=row.dataset.mad4bCardReady==='1';
  row.dataset.mad4bCardReady='1';
  if(!wasReady)row.dataset.mad4bCardExpanded='0';
  const checkbox=[...row.cells].find(c=>c.classList.contains('check-column'));
  primary.dataset.mad4bCardSlot='primary';
  if(checkbox)checkbox.dataset.mad4bCardSlot='checkbox';
  let detail=0;
  for(const cell of row.cells){
    if(cell===primary||cell===checkbox)continue;
    if(cell.classList.contains('hidden')||cell.hidden||cell.style.display==='none'){
      cell.dataset.mad4bCardSlot='hidden';continue;
    }
    const text=trim(cell.textContent);
    const hasAction=!!cell.querySelector('a[href],button,input,select,textarea');
    if(!hasAction&&empty(text)){
      cell.dataset.mad4bCardSlot='empty';continue;
    }
    // Secondary fields belong inside one accordion. Never show WordPress
    // absolute-positioned data-colname labels on the card summary.
    cell.dataset.mad4bCardSlot='detail';detail++;
  }

  let button=primary.querySelector(':scope > .mad4b-card-details-toggle');
  const sync=()=>{
    if(!button)return;
    const expanded=row.dataset.mad4bCardExpanded==='1';
    button.setAttribute('aria-expanded',String(expanded));
    button.setAttribute('aria-label',expanded?labels.hideDetails:labels.showDetails);
    button.textContent=expanded?labels.hideDetails:labels.showDetails;
  };
  const toggle=()=>{
    row.dataset.mad4bCardExpanded=row.dataset.mad4bCardExpanded==='1'?'0':'1';
    sync();
  };
  if(!button){
    button=document.createElement('button');button.type='button';
    button.className='mad4b-card-details-toggle';
    primary.append(button);
    button.addEventListener('click',e=>{e.stopPropagation();toggle();});
    // Keep edit links, row actions, checkboxes, and inline edits native.
    primary.addEventListener('click',e=>{
      if(e.target.closest('a,button,input,label,select,textarea,[role=button]'))return;
      toggle();
    });
  }
  sync();
  row.dataset.mad4bCardHasDetail=detail>0?'1':'0';
 }

 function markTable(table,shell){
  labelsFor(table);
  const rows=[...table.querySelectorAll(rowSelector)];
  const capable=table.matches('.wp-list-table')&&rows.length>0&&
     rows.every(row=>primaryCell(row) || row.classList.contains('hidden'));
  table.dataset.mad4bManagedPostList=capable?'1':'0';
  shell.dataset.mad4bCardsSupported=capable?'1':'0';
  for(const row of rows)paintRow(table,row,capable);
  return capable;
 }
 function wrap(table){
  if(seen.has(table)||!allowed(table))return;
  seen.add(table);
  const shell=document.createElement('div');shell.className='mad4b-admin-table-shell';
  const toolbar=document.createElement('div');toolbar.className='mad4b-admin-table-toolbar';
  const title=document.createElement('span');title.className='mad4b-admin-table-label';
  // WP aria-label can be "Table ordered by Date. Descending."; do not use
  // screen-reader sorting narration as the visible table title.
  title.textContent=labels.records;
  const views=document.createElement('div');views.className='mad4b-admin-table-views';
  const cards=document.createElement('button');cards.type='button';
  cards.className='button mad4b-table-view';cards.dataset.mad4bView='cards';cards.textContent=labels.cards;
  const native=document.createElement('button');native.type='button';
  native.className='button mad4b-table-view';native.dataset.mad4bView='native';native.textContent=labels.table;
  views.append(cards,native);toolbar.append(title,views);
  const scroll=document.createElement('div');scroll.className='mad4b-admin-table-scroll';
  scroll.tabIndex=0;scroll.setAttribute('role','region');
  scroll.setAttribute('aria-label',labels.records+' — '+labels.scroll);
  table.before(shell);shell.append(toolbar,scroll);scroll.append(table);
  let userSelected=false;
  const mobile=matchMedia('(max-width: 782px)');
  const supported=markTable(table,shell);
  cards.disabled=!supported;
  if(!supported)cards.title=labels.scroll;
  function set(mode){
   const value=supported&&mode==='cards'?'cards':'native';
   shell.dataset.mad4bTableView=value;
   shell.classList.toggle('mad4b-table-cards',value==='cards');
   for(const row of table.querySelectorAll(rowSelector))
     if(row.dataset.mad4bCardReady==='1')nestedFields(row,value==='cards');
   for(const button of [cards,native])button.setAttribute('aria-pressed',String(button.dataset.mad4bView===value));
  }
  cards.addEventListener('click',()=>{userSelected=true;set('cards');});
  native.addEventListener('click',()=>{userSelected=true;set('native');});
  set(mobile.matches?'cards':'native');
  mobile.addEventListener?.('change',()=>{if(!userSelected)set(mobile.matches?'cards':'native');});
  shell.__mad4bRefresh=()=>{
    const wasCard=shell.dataset.mad4bTableView==='cards';
    if(wasCard)for(const row of table.querySelectorAll(rowSelector))nestedFields(row,false);
    const ready=markTable(table,shell);cards.disabled=!ready;
    if(!ready)set('native');
    else if(wasCard)set('cards');
  };
 }
 function scan(){
  pending=false;
  for(const table of root.querySelectorAll('table.wp-list-table, table.widefat,[data-mad4b-admin-data-table] table'))wrap(table);
  const postsTable=root.querySelector('#posts-filter table.wp-list-table');
  if(postsTable?.closest('.mad4b-admin-table-shell'))
    document.documentElement.classList.remove('mad4b-admin-ui-boot');
  if(document.body.classList.contains('edit-php') && document.querySelector('#posts-filter .wp-list-table'))
    document.body.classList.add('mad4b-admin-list-intro-enhanced');
 }
 function schedule(){
  // Never initialize an incomplete parser-owned row. An early wrapped row
  // can temporarily force native Table and cause a visible Cards flicker.
  if(document.readyState==='loading')return;
  if(!pending){pending=true;requestAnimationFrame(scan);}
 }
 if(document.readyState==='loading'){
  document.addEventListener('DOMContentLoaded',scan,{once:true});
 } else scan();
 // Keep AJAX-edited rows as the original native nodes; repeat only labels and
 // attached toggles on new rows rather than replacing the table or listeners.
 const observer=new MutationObserver(records=>{
  let addedTable=false;
  const touched=new Set();
  for(const record of records)for(const node of record.addedNodes){
   if(!(node instanceof HTMLElement))continue;
   if(node.matches('table') || node.querySelector('table'))addedTable=true;
   const table=node.closest('table')||node.querySelector('tr')?.closest('table');
   if(table && !seen.has(table) && table.matches('.wp-list-table,.widefat'))addedTable=true;
   if(table && seen.has(table) && (node.matches('tr,td,th') || node.querySelector('tr'))
       && !node.matches('tr.inline-edit-row,tr.hidden'))touched.add(table);
  }
  for(const table of touched){
   const shell=table.closest('.mad4b-admin-table-shell');
   if(shell?.__mad4bRefresh)shell.__mad4bRefresh();
  }
  if(addedTable)schedule();
 });
 observer.observe(root,{childList:true,subtree:true});
 return true;
 }
 if(!initialize()){
   const bootstrapObserver=new MutationObserver(()=>{
     if(initialize())bootstrapObserver.disconnect();
   });
   bootstrapObserver.observe(document.documentElement,{childList:true,subtree:true});
   document.addEventListener('DOMContentLoaded',()=>{
     bootstrapObserver.disconnect();
     initialize();
     document.documentElement.classList.remove('mad4b-admin-ui-boot');
   },{once:true});
 } else if(document.readyState==='loading'){
   document.addEventListener('DOMContentLoaded',()=>{
     document.documentElement.classList.remove('mad4b-admin-ui-boot');
   },{once:true});
 }
})();

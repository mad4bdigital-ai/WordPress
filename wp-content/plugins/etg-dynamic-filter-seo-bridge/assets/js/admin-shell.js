(function(){
'use strict';
var pages={
    'etg-filter-seo':{label:'Control Center',description:'Configuration, discovery, Runtime Inventory, reconciliation, URL inspection and scenario diagnostics.'},
    'etg-dfsb-dynamic-content':{label:'Dynamic Content',description:'Reusable content combinations, source fallbacks and live presentation helpers.'},
    'etg-dfsb-jetengine-inspector':{label:'JetEngine Inspector',description:'Read-only Query Builder, Relations, CCT, field and listing-context diagnostics.'},
    'etg-dfsb-inventory-control':{label:'Inventory Control',description:'Fail-closed structural profile planning from current Runtime Inventory evidence.'},
    'etg-filter-seo-publication':{label:'SEO Publication',description:'Candidate, evidence and publication governance kept separate from dark validation.'},
    'etg-dfsb-usage-guide':{label:'Usage Guide',description:'In-product reference for ETG, Elementor, JetEngine and JetSmartFilters workflows.'}
};
function qsa(selector,root){return Array.prototype.slice.call((root||document).querySelectorAll(selector));}
function normalize(value){return String(value||'').toLowerCase().replace(/\s+/g,' ').trim();}
function currentPage(){try{return new URLSearchParams(window.location.search).get('page')||'';}catch(e){return '';}}
function pageUrl(slug){var url=new URL(window.location.href);url.search='';url.hash='';url.searchParams.set('page',slug);return url.toString();}
function productNav(active){var nav=document.createElement('nav');nav.className='etg-product-nav';nav.setAttribute('aria-label','ETG plugin pages');Object.keys(pages).forEach(function(slug){var a=document.createElement('a');a.className='etg-product-nav__item'+(slug===active?' is-active':'');a.href=pageUrl(slug);a.textContent=pages[slug].label;nav.appendChild(a);});return nav;}
function enhanceLegacyHeader(wrap,active){if(wrap.querySelector('.etg-page-head')){return;}var h1=wrap.querySelector(':scope > h1');if(!h1){return;}var head=document.createElement('div');head.className='etg-page-head';var copy=document.createElement('div');copy.className='etg-page-head__copy';var eyebrow=document.createElement('span');eyebrow.className='etg-eyebrow';eyebrow.textContent='ETG Dynamic Filter SEO Bridge';var p=document.createElement('p');p.textContent=(pages[active]&&pages[active].description)||'';copy.appendChild(eyebrow);copy.appendChild(h1.cloneNode(true));if(p.textContent){copy.appendChild(p);}head.appendChild(copy);h1.parentNode.insertBefore(head,h1);h1.parentNode.removeChild(h1);}
function enhanceLegacyShell(){var active=currentPage();if(!pages[active]){return;}var wrap=document.querySelector('.etg-dfsb-admin,.etg-publication-admin');if(!wrap){return;}wrap.classList.add('etg-dfsb-admin');enhanceLegacyHeader(wrap,active);if(!wrap.querySelector('.etg-product-nav')){var nav=productNav(active);var head=wrap.querySelector('.etg-page-head');if(head&&head.parentNode){head.parentNode.insertBefore(nav,head.nextSibling);}else{wrap.insertBefore(nav,wrap.firstChild);}}qsa('.nav-tab-wrapper',wrap).forEach(function(nav){nav.classList.add('etg-subtabs');});}
function installSearch(input){
    var selector=input.getAttribute('data-etg-table-search');
    if(!selector){return;}
    var table=document.querySelector(selector);if(!table){return;}
    var rows=qsa('tbody tr',table);
    input.addEventListener('input',function(){
        var needle=normalize(input.value);
        rows.forEach(function(row){var hay=normalize(row.getAttribute('data-etg-search')||row.textContent);row.hidden=needle!==''&&hay.indexOf(needle)===-1;});
    });
}
function ensureResponsiveTable(table){if(table.parentElement&&table.parentElement.classList.contains('etg-table-scroll')){return;}var wrapper=document.createElement('div');wrapper.className='etg-table-scroll';table.parentNode.insertBefore(wrapper,table);wrapper.appendChild(table);}
function installAutoTableTools(wrap){qsa('table.widefat,table.etg-pub-table',wrap).forEach(function(table,index){ensureResponsiveTable(table);var rows=qsa('tbody tr',table);if(rows.length<12||table.getAttribute('data-etg-auto-search')==='0'){return;}if(!table.id){table.id='etg-auto-table-'+index;}if(wrap.querySelector('[data-etg-table-search="#'+table.id+'"]')){return;}var tools=document.createElement('div');tools.className='etg-table-tools etg-table-tools--auto';var count=document.createElement('span');count.className='etg-table-tools__count';count.textContent=rows.length+' rows';var input=document.createElement('input');input.type='search';input.className='etg-table-search';input.placeholder='Search this table…';input.setAttribute('data-etg-table-search','#'+table.id);tools.appendChild(count);tools.appendChild(input);table.parentNode.parentNode.insertBefore(tools,table.parentNode);installSearch(input);});}
function installCollapsible(panel,index){
    if(panel.getAttribute('data-etg-collapsible')!=='1'){return;}
    var head=panel.querySelector('.etg-panel__head');var body=panel.querySelector('.etg-panel__body');if(!head||!body){return;}
    var id=panel.id||('panel-'+index);var key='etg-admin-collapsed:'+location.pathname+location.search.split('&')[0]+':'+id;
    var button=document.createElement('button');button.type='button';button.className='button button-small etg-panel__toggle';button.setAttribute('aria-expanded','true');button.textContent='Collapse';
    var actions=head.querySelector('.etg-actions');if(actions){actions.appendChild(button);}else{head.appendChild(button);}
    function setCollapsed(collapsed){panel.classList.toggle('is-collapsed',collapsed);body.hidden=collapsed;button.textContent=collapsed?'Expand':'Collapse';button.setAttribute('aria-expanded',collapsed?'false':'true');try{window.sessionStorage.setItem(key,collapsed?'1':'0');}catch(e){}}
    var initial=false;try{initial=window.sessionStorage.getItem(key)==='1';}catch(e){}setCollapsed(initial);button.addEventListener('click',function(){setCollapsed(!panel.classList.contains('is-collapsed'));});
}
function focusActiveNav(){qsa('.etg-product-nav .is-active,.etg-subtabs .nav-tab-active').forEach(function(el){if(el.scrollIntoView){try{el.scrollIntoView({block:'nearest',inline:'center'});}catch(e){}}});}
document.addEventListener('DOMContentLoaded',function(){enhanceLegacyShell();var wrap=document.querySelector('.etg-dfsb-admin,.etg-publication-admin');if(wrap){installAutoTableTools(wrap);}qsa('[data-etg-table-search]').forEach(function(input){if(!input.dataset.etgSearchReady){input.dataset.etgSearchReady='1';installSearch(input);}});qsa('.etg-panel[data-etg-collapsible="1"]').forEach(installCollapsible);focusActiveNav();});
})();

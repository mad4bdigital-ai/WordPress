(function () {
  'use strict';
  var config=window.DFSB_WPML_FILTER_SWITCHER||{};
  if(config.contract!=='dfsb.wpml-native-language-switch.v1'||!config.sourceLanguage||!config.map)return;
  var expected='/jsf/'+String(config.provider||'').replace('/' ,':')+'/'; // Native JSF provider is "jet-engine/tours_query_archive".
  // The pretty path expresses provider/query as "jet-engine:tours_query_archive".
  var marker='/jsf/'+String(config.provider||'').replace(/\//g,':');
  var originals=new WeakMap();
  var languages=['en','ar','de','es','fr','it'];
  var words={
    en:'Some selected filters are not translated into this language. Only available filters were applied.',
    es:'Algunos filtros seleccionados no están traducidos a este idioma. Solo se aplicaron los disponibles.',
    ar:'بعض الفلاتر المحددة غير مترجمة إلى هذه اللغة. تم تطبيق الفلاتر المتاحة فقط.',
    de:'Einige ausgewählte Filter sind in dieser Sprache nicht übersetzt. Nur verfügbare Filter wurden übernommen.',
    fr:'Certains filtres sélectionnés ne sont pas traduits dans cette langue. Seuls les filtres disponibles ont été appliqués.',
    it:'Alcuni filtri selezionati non sono tradotti in questa lingua. Sono stati applicati solo quelli disponibili.'
  };
  function cleanLang(value){return String(value||'').toLowerCase().replace('_','-').split('-')[0];}
  function findState(){
    var current=window.location.pathname||'/', i=current.indexOf('/jsf/');
    if(i<0)return {suffix:'',missing:0,hasFilters:false};
    var suffix=current.slice(i);
    if(suffix!==marker+'/' && !suffix.startsWith(marker+'/'))return null;
    return {suffix:suffix,missing:0,hasFilters:true};
  }
  function unique(items){return items.filter(function(v,i){return items.indexOf(v)===i;});}
  function translateTax(value,language){
    var parts=value.split(';'),out=[],missing=0,seen={};
    if(parts.length>12)return null;
    for(var i=0;i<parts.length;i++){
      var part=parts[i],colon=part.indexOf(':');
      if(colon<=0)return null;
      var tax=part.slice(0,colon),raw=part.slice(colon+1);
      if(!/^[a-z0-9_-]+$/.test(tax)||!raw||seen[tax])return null;
      seen[tax]=true;
      var all=raw.split(','),mode='',slugs=[];
      for(var j=0;j<all.length;j++){
        var token=all[j];
        if(/^operator_(?:AND|IN)$/.test(token)){mode=token;continue;}
        if(!/^[a-z0-9_-]{1,80}$/.test(token))return null;
        var record=config.map[tax]&&config.map[tax][token],translated=record&&record[language];
        if(translated && /^[a-z0-9_-]{1,80}$/.test(translated))slugs.push(translated);
        else missing++;
      }
      slugs=unique(slugs);
      if(slugs.length){
        var joined=tax+':'+slugs.join(',');
        if(slugs.length>1&&mode)joined+=','+mode;
        out.push(joined);
      }
    }
    return {value:out.join(';'),missing:missing};
  }
  function destination(sourceHref,language){
    var parsed;
    try{parsed=new URL(sourceHref,window.location.origin);}catch(e){return null;}
    if(parsed.origin!==window.location.origin || !parsed.pathname.match(/\/tours-and-activities\/$/))return null;
    var state=findState();if(!state)return null;
    if(!state.suffix)return {url:parsed.href,missing:0};
    var suffix=state.suffix;
    var taxPattern=/\/tax\/([^/]+)/;
    var match=suffix.match(taxPattern),missing=0;
    if(match){
      var translated=translateTax(match[1],language);
      if(!translated)return null;
      missing=translated.missing;
      suffix=suffix.replace(match[0],translated.value?'/tax/'+translated.value:'');
    }
    // The target language may have a different eligible result count. Never
    // carry a stale JSF pagination offset through a locale transition.
    suffix=suffix.replace(/\/pagenum\/[1-9][0-9]{0,3}(?=\/|$)/g,'');
    var result=new URL(parsed.href);
    result.pathname=result.pathname.replace(/\/$/,'')+suffix;
    if(missing)result.hash='dfsb-unavailable='+String(missing);
    return {url:result.href,missing:missing};
  }
  function languageLink(link){
    return link&&link.matches&&link.matches('a.wpml-ls-link[hreflang]');
  }
  function refreshLink(link){
    if(!languageLink(link))return;
    var original=originals.get(link)||link.href;
    if(!originals.has(link))originals.set(link,original);
    var target=cleanLang(link.getAttribute('hreflang'));
    var next=destination(original,target);
    if(next){link.href=next.url;link.dataset.dfsbFilterLocaleLink='1';}
    else{link.href=original;link.removeAttribute('data-dfsb-filter-locale-link');}
  }
  function refresh(){
    document.querySelectorAll('a.wpml-ls-link[hreflang]').forEach(refreshLink);
  }
  // Refresh on interaction, not by rewriting the native JetSmartFilters state.
  document.addEventListener('click',function(e){
    var a=e.target.closest&&e.target.closest('a.wpml-ls-link[hreflang]');
    if(a)refreshLink(a);
  },true);
  document.addEventListener('mouseover',function(e){
    var a=e.target.closest&&e.target.closest('a.wpml-ls-link[hreflang]');
    if(a)refreshLink(a);
  },true);
  document.addEventListener('focusin',function(e){
    var a=e.target.closest&&e.target.closest('a.wpml-ls-link[hreflang]');
    if(a)refreshLink(a);
  },true);
  // The pretty URL and native query are already current when JSF starts AJAX.
  // Do not wait for a slow Listing response to refresh WPML navigation.
  var eventsBound=false;
  function bindNativeEvents(){
    if(eventsBound)return;
    var jsf=window.JetSmartFilters;
    if(!jsf||!jsf.events||typeof jsf.events.subscribe!=='function')return;
    jsf.events.subscribe('ajaxFilters/start-loading',refresh);
    jsf.events.subscribe('ajaxFilters/updated',refresh);
    eventsBound=true;
  }
  bindNativeEvents();
  document.addEventListener('jet-smart-filters/inited',function(){
    bindNativeEvents();
    refresh();
  });
  window.addEventListener('popstate',refresh);
  document.addEventListener('DOMContentLoaded',refresh);
  if(document.readyState!=='loading')refresh();
  var noticeMatch=(window.location.hash||'').match(/^#dfsb-unavailable=([1-9][0-9]?)$/);
  if(noticeMatch){
    function showNotice(){
      if(document.getElementById('dfsb-unavailable-translation-notice'))return;
      var el=document.createElement('div'),count=Number(noticeMatch[1]);
      el.id='dfsb-unavailable-translation-notice';
      el.setAttribute('role','status');el.setAttribute('aria-live','polite');
      el.style.cssText='max-width:960px;margin:12px auto;padding:12px 16px;border:1px solid currentColor;border-radius:10px;font-size:14px;line-height:1.6;';
      el.textContent=(words[cleanLang(config.sourceLanguage)]||words.en)+' ('+count+')';
      var anchor=document.querySelector('[data-id="5e52b51"]')||document.querySelector('main')||document.body;
      anchor.insertBefore(el,anchor.firstChild);
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',showNotice);
    else showNotice();
  }
}());

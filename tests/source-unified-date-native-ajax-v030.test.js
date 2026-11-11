'use strict';
/* No browser, no network, no WordPress. Test the actual immutable JS bundle. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname,
  '../wp-content/plugins/mad4b-commerce/modules/presentation/assets/unified-date-v0.3.1.js'),'utf8');
function boot(hasDate, selectedCurrency='EUR') {
  const listeners={}, storage=new Map(), prefilters=[];
  const href='https://example.test/tours-and-activities/giza-pyramids-half-day-tour/?currency=EUR';
  const pageUrl=new URL(href);
  const anchor={href:'https://example.test/es/tours-and-activities/giza/?foo=1',
    getAttribute(){return this.href;},
    matches(sel){return sel.includes('wpml-ls');},className:'wpml-ls-link'};
  const document={
    readyState:'loading',
    documentElement:{lang:'en'},
    addEventListener(name,fn){listeners[name]=fn;},
    dispatchEvent(){return true;},
    querySelector(sel){return sel.includes('pricing-currency-select')
      ?{value:selectedCurrency}:null;},
    querySelectorAll(sel){return sel.includes('a.wpml-ls-link')?[anchor]:[];}
  };
  const location={
    href:pageUrl.href,origin:pageUrl.origin,pathname:pageUrl.pathname,
    assign(u){location.redirect=u;},replace(u){location.redirect=u;}
  };
  const sessionStorage={
    setItem(k,v){storage.set(k,v);},
    getItem(k){return storage.get(k)??null;},
    removeItem(k){storage.delete(k);}
  };
  const cfg={
    today:'2026-11-11',
    policy:{max_advance_days:730,min_lead_days:0,max_flex_days:120,min_flex_gap_days:0},
    context:'single',archiveUrl:'https://example.test/tours-and-activities/',
    request:hasDate?{status:'valid',mode:'fixed',from:'2027-02-18',to:''}:{status:'absent'}
  };
  const window={
    MAD4BUnifiedDateV1:cfg,
    flatpickr:function(){return null;},
    jQuery:{ajaxPrefilter(fn){prefilters.push(fn);}}
  };
  const env={
    window,document,location,URL,URLSearchParams,sessionStorage,
    Date,Intl,JSON,CustomEvent:class CustomEvent{constructor(){}},
    MutationObserver:class MutationObserver{observe(){return true;}},
    setTimeout(){return 0;},clearTimeout(){},
    history:{state:null,replaceState(){}}
  };
  vm.runInNewContext(source,env,{timeout:1000});
  assert.equal(prefilters.length,1);
  return {prefilter:prefilters[0],listeners,storage,window,anchor,location};
}
function call(h,extra={}) {
 const original={
   action:'jet_smart_filters',
   provider:'jet-engine/tour_rates_accordion',
   'query[package]':'premium',
   'query[season]':'winter',
   'query[mad4b_date_mode]':'flex',
   'query[mad4b_date_clear]':'1',
   ...extra
 };
 const data=new URLSearchParams(original);
 const opts={url:'/wp-admin/admin-ajax.php',data:data.toString()};
 h.prefilter(opts);
 return new URLSearchParams(opts.data);
}
let assertions=0;
function check(a,b,message){assert.deepEqual(a,b,message);assertions++;}
const selected=boot(true);
let q=call(selected);
check(q.get('query[mad4b_date_mode]'),'fixed','native AJAX receives selected date mode');
check(q.get('query[mad4b_date_from]'),'2027-02-18','native AJAX receives selected start date');
check(q.get('query[mad4b_date_to]'),null,'fixed date has no flexible end');
check(q.get('query[mad4b_date_clear]'),null,'applied date deletes stale clear marker');
check(q.get('query[mad4b_currency]'),'EUR',
  'native AJAX request uses current display currency only');
check(q.get('query[package]'),'premium','native package filter remains untouched');
check(q.get('query[season]'),'winter','native season filter remains untouched');
let unrelated={url:'/wp-admin/admin-ajax.php',
  data:new URLSearchParams({action:'jet_smart_filters',provider:'jet-engine/other'}).toString()};
const previous=unrelated.data;
selected.prefilter(unrelated);
check(unrelated.data,previous,'unrelated JetSmartFilters provider is unchanged');
unrelated={url:'/wp-admin/admin-ajax.php',
  data:new URLSearchParams({action:'woocommerce_payment','query[mad4b_date_mode]':'flex'}).toString()};
const previousPayment=unrelated.data;
selected.prefilter(unrelated);
check(unrelated.data,previousPayment,'non-JSF WooCommerce request is never altered');
const empty=boot(false);
q=call(empty);
check(q.get('query[mad4b_date_mode]'),null,'clearing removes stale date mode');
check(q.get('query[mad4b_date_clear]'),'1','native AJAX receives explicit clear sentinel');
check(q.get('query[package]'),'premium','native package stays selected when date is cleared');
check(q.get('query[mad4b_currency]'),'EUR',
  'cleared date does not lose display currency');
const invalidCurrency=boot(true,'BTC');
q=call(invalidCurrency);
check(q.get('query[mad4b_currency]'),null,
  'invalid display currency is not forwarded into the provider');
selected.listeners.click({target:{closest(){return selected.anchor;}}});
check(new URL(selected.anchor.href).searchParams.get('mad4b_date_from'),
  '2027-02-18','WPML in-site tour link retains date');
check(new URL(selected.anchor.href).searchParams.get('foo'),
  '1','unrelated WPML link query preserved');
selected.listeners.change({target:{closest(){return {};}}});
const saved=JSON.parse(selected.storage.get('mad4b:date:currency-handoff'));
check(saved.mode,'fixed','currency handoff retains the mode for this path only');
check(saved.from,'2027-02-18','currency handoff stores selected date, not pricing authority');
check(saved.path,selected.location.pathname,'currency handoff is path-bound');
selected.window.MAD4BTravelDate.clear();
selected.listeners.click({target:{closest(){return selected.anchor;}}});
check(new URL(selected.anchor.href).searchParams.get('mad4b_date_mode'),
  null,'WPML link cannot resurrect a date after user clears it');
check(new URL(selected.anchor.href).searchParams.get('foo'),
  '1','WPML link keeps unrelated parameters after date clear');
// Execute the original isolated formatter, not a manually copied imitation.
const start=source.indexOf('  function fluentFormat(isoDate) {');
const end=source.indexOf('\n  function fluent(',start);
assert(start>=0 && end>start);
const formatContext={};
vm.runInNewContext(source.slice(start,end)+'\nthis.formatDate=fluentFormat;',formatContext);
check(formatContext.formatDate(''),'','empty Fluent Forms date stays empty');
check(formatContext.formatDate('2027-02-18'),'18-Feb-27',
  'fixed date still formats as original Fluent Forms field');
console.log(JSON.stringify({state:'PASS',cases:assertions,
  current_date_provider:'native_jet_smart_filters',
  payment_request_untouched:true,live_browser_verified:false}));

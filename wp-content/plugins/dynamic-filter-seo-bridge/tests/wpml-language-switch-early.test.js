'use strict';
const assert = require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const source=fs.readFileSync(process.argv[2]||path.join(__dirname,'../assets/js/dfsb-wpml-filter-switcher.js'),'utf8');
const handlers={};
const links=[
  {href:'https://tours.dev.mad4b.com/tours-and-activities/',locale:'en',dataset:{},
   matches(s){return s==='a.wpml-ls-link[hreflang]';},
   getAttribute(n){return n==='hreflang'?this.locale:null;},
   removeAttribute(n){delete this.dataset[n];}},
  {href:'https://tours.dev.mad4b.com/ar/tours-and-activities/',locale:'ar',dataset:{},
   matches(s){return s==='a.wpml-ls-link[hreflang]';},
   getAttribute(n){return n==='hreflang'?this.locale:null;},
   removeAttribute(n){delete this.dataset[n];}}
];
const document={readyState:'complete',
  querySelectorAll(s){return s==='a.wpml-ls-link[hreflang]'?links:[];},
  addEventListener(){},
};
const window={location:{origin:'https://tours.dev.mad4b.com',
  pathname:'/es/tours-and-activities/',hash:''},
 DFSB_WPML_FILTER_SWITCHER:{
   contract:'dfsb.wpml-native-language-switch.v1',
   sourceLanguage:'es',provider:'jet-engine/tours_query_archive',
   map:{'tour-types':{
    'excursiones-de-un-dia':{es:'excursiones-de-un-dia',en:'day-tours',ar:'daytours'},
    'medio-dia':{es:'medio-dia',en:'half-day'}
   }}
 },
 JetSmartFilters:{events:{subscribe(name,handler){handlers[name]=handler;}}},
 addEventListener(){}
};
vm.runInNewContext(source,{window,document,URL},{timeout:1500});
assert.equal(typeof handlers['ajaxFilters/start-loading'],'function','Must update WPML on JSF start');
assert.equal(typeof handlers['ajaxFilters/updated'],'function','Must support JSF completion');
const initial=links[0].href;
window.location.pathname='/es/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/tour-types:excursiones-de-un-dia,medio-dia,operator_AND/';
handlers['ajaxFilters/start-loading']('jet-engine','tours_query_archive');
assert.equal(links[0].href,
 'https://tours.dev.mad4b.com/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/tour-types:day-tours,half-day,operator_AND/');
assert.equal(links[1].href,
 'https://tours.dev.mad4b.com/ar/tours-and-activities/jsf/jet-engine:tours_query_archive/tax/tour-types:daytours/#dfsb-unavailable=1');
window.location.pathname='/es/tours-and-activities/';
handlers['ajaxFilters/start-loading']('jet-engine','tours_query_archive');
assert.equal(links[0].href,initial,'Removing all filters must restore unfiltered language URL');
assert.equal(links[1].href,'https://tours.dev.mad4b.com/ar/tours-and-activities/','Reset clears unavailable marker');
console.log('PASS: immediate JSF-start translation, same-facet AND, missing locale, and reset');


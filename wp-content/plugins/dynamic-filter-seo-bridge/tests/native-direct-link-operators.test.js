// Exercise the live-derived native configuration before filter initialization.
'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../assets/js/ajax-filter-state.js'),'utf8');
const start=source.indexOf('    function configureNativeDirectLinkOperators('),end=source.indexOf('    var timers =',start);
function box(tax='destinations',provider='jet-engine',query='archive',operator='AND',values=['cairo','aswan']){const a={'data-query-var':tax,'data-content-provider':provider,'data-query-id':query,'data-relational-operator':operator};return {a,getAttribute:k=>a[k]??null,setAttribute:(k,v)=>a[k]=v,removeAttribute:k=>delete a[k],querySelectorAll:()=>values.map(v=>({getAttribute:()=>v}))};}
const matching=box(),wrongGroup=box('destinations','jet-engine','other'),wrongTax=box('style'),missing=box('destinations','jet-engine','archive','AND',['cairo']);
let state={valid:true,group:'jet-engine/archive',query:{_tax_query_destinations:'cairo,aswan,operator_IN'}};const events={};const context={cfg:{jsfVersion:'3.8.5.1',supportedJsfVersions:['3.8.5.1']},prettyPathState:()=>state,document:{querySelectorAll:()=>[matching,wrongGroup,wrongTax,missing],addEventListener:(name,fn,options)=>events[name]={fn,options}}};
vm.runInNewContext(source.slice(start,end),context);const hook=events['jet-smart-filters/before-init'];assert.ok(hook.options.capture&&hook.options.once);hook.fn();assert.equal(matching.a['data-relational-operator'],undefined);for(const b of [wrongGroup,wrongTax,missing])assert.equal(b.a['data-relational-operator'],'AND');
state.query._tax_query_destinations=['cairo','aswan','operator_AND'];hook.fn();assert.equal(matching.a['data-relational-operator'],'AND');
for(const invalid of ['cairo,cairo,operator_IN','cairo,operator_IN','cairo,operator_AND,operator_IN','cairo,unknown,operator_IN']){state.query._tax_query_destinations=invalid;hook.fn();assert.equal(matching.a['data-relational-operator'],'AND');}
state.query._tax_query_destinations='cairo,aswan,operator_IN';context.cfg.jsfVersion='unknown';hook.fn();assert.equal(matching.a['data-relational-operator'],'AND');
console.log('PASS: native pre-init AND/IN, provider/taxonomy/options isolation, malformed terms and unsupported version');

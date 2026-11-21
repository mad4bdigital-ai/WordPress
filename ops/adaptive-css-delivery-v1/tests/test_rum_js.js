'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const code=fs.readFileSync(process.argv[2],'utf8');
const base={contract:'mad4b.adaptive-css.rum.v1',mode:'observe',
 sampleBps:10000,vendor:'https://tours.dev.mad4b.com/wp-content/web-vitals.js',
 endpoint:'https://tours.dev.mad4b.com/wp-json/mad4b-css/v1/rum',
 proof:'sha256_signed_token'};
function trial(allowed,cfg={}){
 const appended=[];
 const doc={head:{appendChild(x){appended.push(x)}},
 createElement(){return {}},
 querySelectorAll(){return []},querySelector(){return null},getElementById(){return null}};
 const win={MAD4B_CSS_RUM:{...base,...cfg},
 crypto:{getRandomValues(x){x[0]=0}},
 navigator:{globalPrivacyControl:false,doNotTrack:'0'},
 MAD4BConsent:{choice(){return allowed?{a:true,m:false}:{a:false,m:false}}},
 location:{origin:'https://tours.dev.mad4b.com'},Uint32Array,
 addEventListener(){},setTimeout(){},fetch(){throw Error('bad early send')}};
 vm.runInNewContext(code,{window:win,document:doc,Uint32Array,Number,Object,URL,
  JSON,Math,Error});
 return {appended,win,doc};
}
assert.strictEqual(trial(false).appended.length,0);
assert.strictEqual(trial(true).appended.length,1);
assert.strictEqual(trial(true,{sampleBps:0}).appended.length,0);
assert.strictEqual(trial(true,{vendor:'https://other.example/evil.js'}).appended.length,0);
assert.strictEqual(trial(true,{endpoint:'http://tours.dev.mad4b.com/rum'}).appended.length,0);
assert.strictEqual(trial(true,{mode:'off'}).appended.length,0);
console.log('PASS 6 consent, probability and same-origin RUM JS invariants');

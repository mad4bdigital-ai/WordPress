'use strict';
const assert=require('node:assert/strict');
const {composeSample,evaluateSamples}=require('./live-768-readback-proof-v2');
const baseline={locale:'en',trial:1,width:768,status:200};
const good={lcpMs:4932,fcpMs:4720,restCssLoaded:true,criticalCssMedia:'(max-width:767px)',pageWidth:768,viewportWidth:768,cssRequestFailures:[],errorCount:0};
function test(label,fn){fn();console.log('PASS',label)}
test('response proof overrides stale metrics readyHeader',()=>{
 const r=composeSample(baseline,{'X-MAD4B-Mobile-CSS-Ready':'1','X-MAD4B-Mobile-CSS-Native-Complete':'0'},{...good,readyHeader:'',nativeHeader:'x',status:500});
 assert.equal(r.readyHeader,'1');assert.equal(r.nativeHeader,'0');assert.equal(r.status,200);
 assert.deepEqual(evaluateSamples([r]),{tested:1,invalid:false});
});
test('true absent response proof fails closed',()=>{
 const r=composeSample(baseline,{},good);
 assert.equal(r.readyHeader,'none');assert.equal(evaluateSamples([r]).invalid,true);
});
test('native-only fallback not misreported as certified',()=>{
 const r=composeSample(baseline,{'x-mad4b-mobile-css-ready':'0','x-mad4b-mobile-css-native-complete':'1'},good);
 assert.equal(evaluateSamples([r]).invalid,true);
});
test('responsive CSS media other than cap767 fails',()=>{
 const r=composeSample(baseline,{'x-mad4b-mobile-css-ready':'1'},{...good,criticalCssMedia:'(max-width:600px)'});
 assert.equal(evaluateSamples([r]).invalid,true);
});
test('horizontal overflow fails',()=>{
 const r=composeSample(baseline,{'x-mad4b-mobile-css-ready':'1'},{...good,pageWidth:800});
 assert.equal(evaluateSamples([r]).invalid,true);
});
test('CSS network failure fails',()=>{
 const r={...composeSample(baseline,{'x-mad4b-mobile-css-ready':'1'},good),cssRequestFailures:['lost.css'],errorCount:0};
 assert.equal(evaluateSamples([r]).invalid,true);
});
test('client-side field cannot override authoritative status or errors',()=>{
 const r=composeSample(baseline,{'x-mad4b-mobile-css-ready':'1'},{...good,status:403,cssRequestFailures:['false'],errorCount:99});
 assert.equal(r.status,200);assert.equal(r.errorCount,undefined);assert.equal(r.cssRequestFailures,undefined);
});
test('Spanish observed native fallback with no responsive style remains ready with actual header',()=>{
 const r=composeSample({...baseline,locale:'es'}, {'x-mad4b-mobile-css-ready':'1'}, {...good,criticalCssMedia:''});
 assert.equal(evaluateSamples([r]).invalid,false);
});

'use strict';
// Native-source replay permission is NEVER a pass. Only the original
// verifier's two fully passing fresh pairs may convert a 320px hold.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {needsReobservation,needsImageNetworkReplay}=require('../tools/lcp-observation-window.js');
// Representative values from original 21 Nov source QA, no private HTML.
const original={width:320,pass:false,lcp_reg_ms:4012,
 source:{lcp:5464,lcpTrace:[{resource:'same-tour-hero.webp'}],
   lcp_image_resource_timing:{requests:1,first_start_ms:3347,last_response_end_ms:5254}},
 critical:{lcp:9476,lcpTrace:[{resource:'same-tour-hero.webp'}],
   lcp_image_resource_timing:{requests:1,first_start_ms:1037,last_response_end_ms:9357}}};
const a=original.source,b=original.critical;
assert.equal(original.width,320);
assert.equal(original.pass,false);
assert.equal(original.lcp_reg_ms,4012);
assert.equal(needsImageNetworkReplay(a,b),true);
console.log('PASS actual 320px failed LCP receives bounded retest eligibility');
assert.equal(needsImageNetworkReplay(b,a),false);
assert.equal(needsImageNetworkReplay(a,{...b,lcpTrace:[{resource:'another-lcp.jpg'}]}),false);
assert.equal(needsImageNetworkReplay(a,{...b,lcp_image_resource_timing:{
 requests:2,first_start_ms:1037,last_response_end_ms:9357}}),false);
assert.equal(needsImageNetworkReplay(a,{...b,lcp:6010,
 lcp_image_resource_timing:{requests:1,first_start_ms:1037,last_response_end_ms:9357}}),false);
assert.equal(needsImageNetworkReplay(a,{...b,lcp_image_resource_timing:{
 requests:1,first_start_ms:4500,last_response_end_ms:9357}}),false);
assert.equal(needsImageNetworkReplay({...a,lcp_image_resource_timing:{
 requests:1,first_start_ms:3347,last_response_end_ms:null}},b),false);
console.log('PASS changed/ambiguous/uncorrelated LCP images and reverse lag cannot be replayed');
assert.equal(needsReobservation({observedUntilMs:1000},
 {observedUntilMs:4000,lcpTrace:[{t:2700}]}),true);
assert.equal(needsReobservation({observedUntilMs:1000},
 {observedUntilMs:4000,lcpTrace:[{t:999}]}),false);
console.log('PASS original late-LCP equal-horizon safeguard preserved');
const s=fs.readFileSync(path.join(__dirname,'../tools/verify-native-ordered-critical.js'),'utf8');
for(const expected of [
 'width===320 && !pass','needsImageNetworkReplay(a,b)',
 'const reverseVerdict=strict(reverseA,reverseB)',
 'const forwardVerdict=strict(forwardA,forwardB)',
 'const accepted=reverseVerdict.pass&&forwardVerdict.pass',
 "lcp_network_jitter_recheck:networkJitterRecheck",
 'fcp>=150&&lcp<=Math.max(400,original.lcp*.1)',
 'b.failed.length===0','b.errors.length===0',
 'needsReobservation(a,b)','needsReobservation(b,a)',
]){
 assert(s.includes(expected),'missing original source gate: '+expected);
}
console.log('PASS original source fail-closed LCP/FCP/CLS/layout/CSS/error gates and two fresh directions retained');
console.log('ORIGINAL_320_LCP_JITTER_REPLAY_TESTS_PASS');

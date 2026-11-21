'use strict';
const fs=require('fs'),assert=require('assert'),path=require('path');
const file=process.argv[2]||path.resolve(__dirname,'../tools/verify-native-ordered-critical.js');
const s=fs.readFileSync(file,'utf8');
// Purely additive first-party timing instrumentation. Never use network
// timings as a new certificate admission or source authority.
for(const marker of [
  'performance.setResourceTimingBufferSize(1200);',
  'lcp_image_resource_timing:imageTiming',
  'css_resource_timing:cssTiming',
  'lcp_hero_state:heroState',
  'first_start_ms:',
  'last_response_end_ms:',
  'getComputedStyle(hero)',
  "if(width===320 && pass){",
  'pass=pass&&reversePass;',
  'const unequalLateLcp=needsReobservation(a,b)||needsReobservation(b,a);',
  '&&lcpReg<=Math.max(400,a.lcp*.1);',
  'pass:results.length===5&&results.every(x=>x.pass)'
])assert(s.includes(marker),'missing '+marker);
assert(!/if\s*\(\s*imageTiming/.test(s),'measurements may not modify delivery eligibility');
assert(!/if\s*\(\s*cssTiming/.test(s),'measurements may not modify delivery eligibility');
assert(!/fetch\(.*(?:remote|rum|metric)/.test(s),'no telemetry uploaded from QA');
console.log('PASS 12 resource-timing observability and unchanged CSS admission invariants');

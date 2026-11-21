#!/usr/bin/env python3
"""Protect fair observation windows; do not weaken the LCP failure threshold."""
import pathlib,subprocess,sys
D=pathlib.Path(sys.argv[1]);src=(D/'tools/verify-native-ordered-critical.js').read_text()
checks=0
def ok(condition,message):
 global checks
 assert condition,message
 checks+=1;print('PASS',message)
ok('needsReobservation(a,b)' in src and 'needsReobservation(b,a)' in src,
   'both source and candidate can have a late LCP')
ok("await measure(browser,width,false,until)" in src and
   "await measure(browser,width,true,until)" in src,'replay only earlier window')
ok('const pass=!unequalLateLcp&&same' in src,
   'unresolved late LCP disqualifies certificate')
ok('lcp_trace:a.lcpTrace' in src and 'lcp_trace:b.lcpTrace' in src,
   'retain LCP element trace from both peers')
ok('lcpReg<=Math.max(400,a.lcp*.1)' in src,'retain original LCP regression threshold')
ok('b.errors.length===0' in src and 'b.failed.length===0' in src,
   'retain JS, CSS failure gates')
ok("'verify-native-ordered-critical.js'" in
   (D/'run-public-route.py').read_text(),
   'QA implementation change updates negative receipt source policy')
js=r'''const assert=require('node:assert/strict');
const {needsReobservation}=require('./tools/lcp-observation-window.js');
assert(needsReobservation({observedUntilMs:12500,lcpTrace:[{t:4700}]},
 {observedUntilMs:29100,lcpTrace:[{t:26076}]}));
assert(!needsReobservation({observedUntilMs:28700,lcpTrace:[{t:26684}]},
 {observedUntilMs:29100,lcpTrace:[{t:26076}]}));
assert(!needsReobservation({observedUntilMs:12500,lcpTrace:[{t:4700}]},
 {observedUntilMs:14500,lcpTrace:[{t:12600}]}));
console.log('PASS pure LCP comparison flags only late unequal window');'''
run=subprocess.run(['node','-e',js],cwd=D,capture_output=True,text=True,timeout=10)
if run.returncode:raise AssertionError(run.stderr)
ok('PASS pure LCP comparison' in run.stdout,
   'runtime LCP reobservation helper triggered by 21-second late event')
print('PASS',checks,'exact post-release LCP QA policy invariants')

#!/usr/bin/env python3
"""Post-release 320px QA cannot certify from one slow original-source peer."""
from pathlib import Path
import sys,subprocess
source=Path(sys.argv[1]) if len(sys.argv)>1 else Path(__file__).resolve().parents[1]/'tools/verify-native-ordered-critical.js'
js=source.read_text()
tests=0
def check(value,label):
 global tests
 assert value,label;tests+=1;print('PASS',label)
check('if(width===320 && pass)' in js,
      'extra browser work only for successful 320px QA candidate')
check(js.index('let reverseB=await measure(browser,width,true)')<
      js.index('let reverseA=await measure(browser,width,false)'),
      'critical-first confirmation counters original-first warming advantage')
check('needsReobservation(reverseA,reverseB)' in js and
      'needsReobservation(reverseB,reverseA)' in js,
      'reverse peers enforce the same bounded navigation-relative LCP horizon')
check('reverseA.lcp>0&&reverseB.lcp>0' in js,
      'missing image LCP evidence cannot impersonate a reverse pass')
check('reverseB.links===manifest.files.length' in js and
      'reverseB.linksRestored&&reverseFcpGain>=150' in js,
      'reverse experiment requires original full CSS restored and measured FCP gain')
check('const reverseSame=layout(reverseA,reverseB)' in js and
      'reverseLcpReg<=Math.max(400,reverseA.lcp*.1)' in js,
      'same geometry and exact original LCP bounds apply in opposite order')
check('pass=pass&&reversePass' in js and
      'peer_order_confirmation:peerOrderConfirmation' in js,
      'a single easy pass cannot mint a source certificate')
check('const report={' in js and 'pass:results.length===5&&results.every(x=>x.pass)' in js,
      'five-viewport original release-first certification policy retained')
# Real root-private Hurghada samples: original-first failed by +4100ms;
# reversed order alone passed by -580ms. Both must be accepted.
def performance_ok(original,candidate):
 return candidate-original<=max(400,original*.1)
ab0=performance_ok(5040,9140)
ba1=performance_ok(9684,9104)
check(not ab0 and ba1 and not(ab0 and ba1),
      'real AB/BA control cannot certify because AB 320px LCP failed')
check(performance_ok(5000,4650) and performance_ok(4900,4700),
      'genuinely faster candidate is eligible after both orders pass')
check(not(performance_ok(5000,5350) and performance_ok(5000,5900)),
      'one marginal pass cannot override a reverse hard fail')
p=subprocess.run(['node','--check',str(source)],capture_output=True,text=True)
check(p.returncode==0,'Node.js verifier remains syntactically valid')
print('PASS',tests,'post-release peer-order safeguards')

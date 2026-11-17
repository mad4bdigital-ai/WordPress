#!/usr/bin/env python3
"""Same first-party Chromium profile must own source, QA and readback."""
from pathlib import Path
import re,sys
root=Path(sys.argv[1])
probe=(root/'tools/probe-public.js').read_text()
capture=(root/'tools/capture.js').read_text()
verify=(root/'tools/verify.js').read_text()
runner=(root/'run-site.sh').read_text()
checks=[
 ('no foreign browser source', '/^https:\\/\\/(tours\\.dev\\.mad4b\\.com|wayfatours\\.com)$/' in probe),
 ('source and capture pin the same UA',re.search(r"const UA='([^']+)'",probe).group(1)==re.search(r"const mobileUA = '([^']+)'",capture).group(1)),
 ('source and verification pin same Accept header',
   re.search(r"const HTML_ACCEPT='([^']+)'",probe).group(1)==re.search(r"const HTML_ACCEPT='([^']+)'",verify).group(1)),
 ('source and capture pin same anonymous mobile viewport',
   'width:390,height:844' in probe and 'isMobile:true,hasTouch:true' in probe and 'deviceScaleFactor:2' in probe),
 ('real browser response becomes the HTML evidence',
   'await r.text()' in probe and 'r.allHeaders()' in probe and "lang+'.html'" in probe and "lang+'.headers'" in probe),
 ('no query or private cookie injected in source probing',
   'page.goto(site+uri,' in probe and 'extraHTTPHeaders:{Accept:HTML_ACCEPT}' in probe),
 ('source discovery calls Chrome before assessing CSS manifest',
   runner.index('probe_public "$LOCALES" observed') < runner.index('python3 "$TOOLS/assess.py"')),
 ('post-browser source fingerprint uses the exact Chrome profile',
   runner.index('probe_public "$ADMITTED_LANGS" recheck') < runner.index('verify-source-revision.py')),
 ('Wayfa cache repair also re-probes public browser HTML',
   'probe_public en,es observed' in runner),
 ('async full CSS is not forced artificially by QA',
   "await page.waitForFunction(()=>{" in verify
   and "el.media==='all'" in verify
   and 'some(x=>x.href?.includes' in verify),
 ('strict QA performance/CLS gates unchanged',
   'fcpGain >=' in verify and 'lcpReg<=Math.max' in verify and 'variant.cls <=' in verify
   and 'variant.styleLoaded&&variant.cssMedia' in verify),
]
for label,passed in checks:
 assert passed,label
 print('PASS',label)
print('PASS %d/%d Chromium CSS-negotiation contracts'%(len(checks),len(checks)))

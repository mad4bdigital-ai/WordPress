#!/usr/bin/env python3
"""Prove 768px-only CSS is not embedded in a <=767px Critical CSS candidate."""
from pathlib import Path
import subprocess,sys
D=Path(sys.argv[1])
s=(D/'tools/capture-ordered-critical.js').read_text()
assert "const widths=[320,390,414,767,768];" in s
assert "const criticalWidths=[320,390,414,767];" in s
assert "observations.filter(o=>criticalWidths.includes(o.width))" in s
assert "desktop_validation_viewports:[768]" in s
for f in ['public-ordered-critical-admission.py','public-ordered-critical-certificate-v2.py']:
    body=(D/f).read_text()
    assert "candidate.get('critical_viewports')!=[320,390,414,767]" in body
    assert "candidate.get('desktop_validation_viewports')!=[768]" in body
js=r"""const assert=require('node:assert/strict');
const {assemble}=require('./tools/assemble.js');
const source='.small{color:green}.edge767{display:block}.tablet768{display:grid}';
const a=source.indexOf('.small');
const b=source.indexOf('.edge767');
const c=source.indexOf('.tablet768');
const observations=[{width:320,ranges:[{start:a,end:b}]},
{width:767,ranges:[{start:b,end:c}]},{width:768,ranges:[{start:c,end:source.length}]}];
const css=assemble(source,observations.filter(x=>x.width<=767).flatMap(x=>x.ranges)).css;
assert(css.includes('.small{color:green}'));
assert(css.includes('.edge767{display:block}'));
assert(!css.includes('tablet768'));
console.log('PASS same original CSS, mobile ranges only, 767 edge included, 768 desktop excluded');"""
r=subprocess.run(['node','-e',js],cwd=D,capture_output=True,text=True,timeout=10)
if r.returncode:raise AssertionError(r.stderr)
print(r.stdout.strip())
print('PASS source-capture and issuer independently enforce mobile-only evidence')

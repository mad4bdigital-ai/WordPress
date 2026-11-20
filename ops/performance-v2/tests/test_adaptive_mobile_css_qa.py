"""No runtime or signed grants: QA-only verify tool guards its 767px boundary."""
from pathlib import Path
import hashlib
import json
import re
import unittest
source=Path(__file__).resolve().parents[2]/'mobile-critical-css-v1/verify.js'

class AdaptiveMediaQAContract(unittest.TestCase):
 def test_default_strategy_remains_native_existing(self):
  s=source.read_text()
  self.assertIn("const ADAPTIVE_MAX=Number(process.env.MAD4B_CSS_ADAPTIVE_MAX||0);",s)
  self.assertIn("const criticalAttr=ADAPTIVE_MAX?",s)
  self.assertIn("ADAPTIVE_MAX?\x27 media=",s)
  self.assertIn(":'print';",s)
  self.assertEqual(s.count("const DEFER_CITY="),1)
 def test_unsupported_breakpoint_fails_closed(self):
  s=source.read_text()
  self.assertIn('ADAPTIVE_MAX<480||ADAPTIVE_MAX>767',s)
  self.assertIn("throw Error('INVALID_ADAPTIVE_BREAKPOINT')",s)
 def test_adaptive_lcp_gate_kept(self):
  s=source.read_text()
  self.assertIn('lcpReg<=Math.max(400,(baseline.lcp||0)*0.10)',s)
  self.assertIn('&&variant.cls<=0.10',s)
  self.assertIn('&&(!baseline.popupOpened || variant.popupOpened)',s)
  self.assertIn('&&(!baseline.calendarOpened || variant.calendarOpened)',s)
  self.assertIn('adaptiveMaxWidth:ADAPTIVE_MAX',s)
 def test_real_boundary_receipt(self):
  evidence=Path('/opt/mad4b-company-provisioning/reports/css-critical-qa-renewal-20261116/adaptive-mobile-prototype/verification.json')
  if not evidence.exists():self.skipTest('No VPS real browser evidence in this workspace')
  obj=json.loads(evidence.read_text())
  self.assertEqual(obj['tested'],4)
  self.assertEqual({k:v['decision'] for k,v in obj['gates'].items()},dict.fromkeys(['en','es'],'passed_evidence'))
  self.assertEqual({v['adaptiveMaxWidth'] for v in obj['gates'].values()},{767})
  self.assertEqual({r['width'] for r in obj['results']},{767,768})
  self.assertTrue(all(r['pass'] for r in obj['results']))
if __name__=='__main__':unittest.main()

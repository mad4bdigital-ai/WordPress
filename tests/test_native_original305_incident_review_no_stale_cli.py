"""Do not expose historical V1 signature contract as live approval."""
from pathlib import Path
import unittest,subprocess

ROOT=Path(__file__).resolve().parents[1]
V1=ROOT/'ops/native-source-publisher-v1/original305_incident_signed_review_v1.py'
class OldReviewCLI(unittest.TestCase):
 def test_v1_cli_refuses_superseded_live_review(self):
  p=subprocess.run(['/usr/bin/python3',str(V1),'--plan'],capture_output=True,text=True,timeout=15)
  self.assertEqual(p.returncode,3)
  self.assertIn('ORIGINAL305_REVIEW_V1_SUPERSEDED_USE_V2',p.stdout)
  self.assertIn('"incident_closed": false',p.stdout)
if __name__=='__main__':unittest.main()

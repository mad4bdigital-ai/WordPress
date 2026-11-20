"""Native Source Review QA never needs live WordPress."""
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
RUNNER=ROOT/'tests/native-source-central-review/run-php-acceptance.sh'

class OfflineRunnerBoundary(unittest.TestCase):
 def test_runner_is_networkless_disposable(self):
  s=RUNNER.read_text()
  for expected in ('docker run --rm','--network none','--read-only',
                   '--tmpfs /var/www/html:','-v "$REPO":/work:ro',
                   'offline fixture, not deployed','| php'):
   self.assertIn(expected,s)
 def test_no_live_wordpress_execution(self):
  s=RUNNER.read_text()
  for forbidden in ('docker exec','php-wkgtkzta35wkkgouwt6ruljc',
    'php-guxhp9y6vgh7oouuw8plxrlr','--apply','wp --user=1'):
   self.assertNotIn(forbidden,s)
 def test_native_provider_remains_source_only(self):
  root=ROOT/'ops/native-source-publisher-v1'
  doc=(root/'catalog/publisher-enrollment-proposal.json').read_text()
  for text in ('"source_only": true','"publisher_enabled": false',
               '"production_writes_authorized": false'):
   self.assertIn(text,doc)
  self.assertIn('Status: HOLD. Not deployed.',
         (root/'catalog/ACTIVATION_GATES.md').read_text())
 def test_no_direct_release_cli(self):
  code=(ROOT/'ops/native-source-publisher-v1/native_source_publisher.py').read_text()
  self.assertIn('No operation, site, plugin, target file or environment appears',code)
  self.assertIn('There is no direct CLI apply',code)
if __name__=='__main__':unittest.main()

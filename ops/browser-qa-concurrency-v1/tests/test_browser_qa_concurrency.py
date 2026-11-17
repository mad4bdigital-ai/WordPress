#!/usr/bin/env python3
"""Hermetic static safety regression for original MAD4B browser QA coordinator."""
from pathlib import Path
import subprocess,unittest
ROOT=Path(__file__).resolve().parents[1]
class TestCoordinator(unittest.TestCase):
 def test_shell_parses(self):
  r=subprocess.run(['bash','-n',str(ROOT/'qa-coordinator.sh')],capture_output=True,text=True)
  self.assertEqual(r.returncode,0,r.stderr)
 def test_global_lock_and_no_fd_collision(self):
  s=(ROOT/'qa-coordinator.sh').read_text()
  self.assertIn('/run/lock/mad4b-native-lazy-browser-global.lock',s)
  self.assertIn('exec 199>',s)
  self.assertIn('flock -w "$wait_sec" 199',s)
  self.assertIn('MAD4B_BROWSER_QA_DEFERRED_LOCK',s)
  self.assertNotIn('docker stop',s)
  self.assertNotIn('docker rm -f',s)
 def test_preserved_original_jobs(self):
  s=(ROOT/'qa-coordinator.sh').read_text()
  for mode in ['private-mobile-wayfa','frontend-wayfa','presentation','tour-story','manual']:
   self.assertIn(mode+')',s)
  self.assertIn('mad4b-private-css-family-wayfa.lock',s)
  self.assertIn('mad4b-frontend-contract-browser-v1.py',s)
  self.assertIn('refresh-browser-audits.sh',s)
  self.assertIn('run-browser-qa.sh',s)
  self.assertIn('MANUAL_REQUIRES_TIMEOUT',s)
 def test_four_exact_systemd_overrides(self):
  for name in ['mad4b-private-mobile-css-family-wayfa','mad4b-frontend-contract-wayfa',
               'mad4b-presentation-browser-audit','mad4b-tour-story-browser-monitor']:
   p=ROOT/'systemd'/f'{name}.override.conf'
   s=p.read_text()
   self.assertIn('[Service]',s)
   self.assertIn('ExecStart=\n',s)
   self.assertIn('/ops/browser-qa-concurrency-v1/qa-coordinator.sh',s)
 def test_janitor_scoped_and_bounded(self):
  s=(ROOT/'systemd/mad4b-stale-playwright-qa-cleanup.service').read_text()
  self.assertIn('stale-playwright-qa-janitor-v1.py',s)
  self.assertIn('--min-age-minutes 60',s)
  self.assertIn('--max-stop 8',s)
  t=(ROOT/'systemd/mad4b-stale-playwright-qa-cleanup.timer').read_text()
  self.assertIn('OnUnitInactiveSec=30min',t)
  self.assertIn('Persistent=true',t)
 def test_hpanel_is_local_only_with_reaper(self):
  s=(ROOT/'hpanel/hpanel-worker-compose-init-v1.yaml').read_text()
  self.assertIn('    init: true',s)
  self.assertIn('127.0.0.1:13871:13871',s)
  self.assertIn('HPANEL_WORKER_PROFILE_DIR',s)
  self.assertIn('no-new-privileges:true',s)
 def test_no_site_runtime_or_secrets_in_bundled_source(self):
  script=(ROOT/'qa-coordinator.sh').read_text()
  self.assertNotIn('woocommerce_session',script)
  self.assertNotIn('wp-config.php',script)
  self.assertNotIn('nginx.conf',script)
if __name__=='__main__':unittest.main(verbosity=2)

#!/usr/bin/env python3
"""Prevent signed plugin.install capability from routinely expiring due to a late retry."""
import configparser
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
TIMER=ROOT/'systemd/mad4b-company-plugin-install-capability.timer'
HANDLER=ROOT.parents[2]/'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-native-plugin-install-handler.php'

class HeartbeatCadenceTests(unittest.TestCase):
 def test_cadence_keeps_retry_gap_smaller_than_signed_ttl(self):
  p=configparser.ConfigParser(interpolation=None)
  p.read(TIMER)
  timer=p['Timer']
  gap=int(timer['OnUnitInactiveSec'].removesuffix('s'))
  jitter=int(timer['RandomizedDelaySec'].removesuffix('s'))
  self.assertEqual(gap,300)
  self.assertLess(gap+jitter,600)
  self.assertTrue(timer['Unit'].endswith('capability.service'))
 def test_signature_and_age_guard_unchanged(self):
  source=HANDLER.read_text()
  self.assertIn('public const MAX_AGE_SECONDS=600',source)
  self.assertIn("$p['heartbeat_epoch']<$now-self::MAX_AGE_SECONDS",source)
  provider=ROOT.parents[2]/'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-native-plugin-install-provider.php'
  self.assertIn("$cap['heartbeat_epoch']<$now-$class::MAX_AGE_SECONDS",provider.read_text())
  self.assertIn('sodium_crypto_sign_verify_detached',source)

if __name__=='__main__': unittest.main(verbosity=2)

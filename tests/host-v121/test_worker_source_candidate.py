#!/usr/bin/env python3
"""v121 exact-source original306 claim/issuer recovery overlay tests."""
from __future__ import annotations
from pathlib import Path
import hashlib
import importlib.util
import unittest
ROOT=Path(__file__).resolve().parents[2]
P=ROOT/'ops/host-anchor-v121/patch_original306_first_anchor_runner.py'
SPEC=importlib.util.spec_from_file_location('_v121_worker_source_patch',P)
M=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(M)
LIVE=Path('/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py')

class OneShotWorkerSource(unittest.TestCase):
    def test_original306_worker_sha_pinned_and_unchanged_in_source(self):
        original=LIVE.read_text()
        self.assertEqual(hashlib.sha256(original.encode()).hexdigest(),M.CURRENT_SHA)
        rewritten=M.prepare_candidate(original)
        self.assertIn('firstparty_host_v121_preclaim_commissioned()',rewritten)
        self.assertIn('firstparty_host_v118_capture_original_queued(job)',rewritten)
        self.assertIn('firstparty_host_v120_capture_original_broker_row(job)',rewritten)
        self.assertIn('firstparty_host_v121_exact_issue(newly_claimed)',rewritten)
        self.assertIn('firstparty_host_v121_postwrite_receipt_recovery()',rewritten)
        self.assertEqual(original.count('return sign_existing_original_306_claim(job,entry)'),
                         rewritten.count('return sign_existing_original_306_claim(job,entry)'))
        a=rewritten.index('firstparty_host_v118_capture_original_queued(job)')
        b=rewritten.index('firstparty_host_v120_capture_original_broker_row(job)')
        c=rewritten.index('return sign_existing_original_306_claim(job,entry)')
        d=rewritten.index('firstparty_host_v121_exact_issue(newly_claimed)')
        guard=rewritten.index('firstparty_host_v121_preclaim_commissioned()')
        self.assertLess(guard,a);self.assertLess(a,b);self.assertLess(b,c);self.assertLess(c,d)
    def test_integrated_source_does_not_touch_registry_or_grants(self):
        rewritten=M.prepare_candidate(LIVE.read_text())
        self.assertIn('mod.root_gate()',rewritten)
        self.assertNotIn('original313.private_key',rewritten)
        self.assertNotIn('update_option(',M.HELPER)
        self.assertNotIn('subprocess.Popen(',M.HELPER)
        self.assertEqual(rewritten.count(M.MARKER),2)
        self.assertEqual(M.main(),2)
    def test_source_replay_and_unknown_worker_head_rejected(self):
        current=LIVE.read_text()
        new=M.prepare_candidate(current)
        with self.assertRaisesRegex(ValueError,'ALREADY_INSTALLED'):
            M.prepare_candidate(new)
        with self.assertRaisesRegex(ValueError,'UNEXPECTED_BASE_WORKER'):
            M.prepare_candidate(current+'\n# unreviewed edit')
    def test_exact_v121_sha_matches_candidate_reference(self):
        p=ROOT/'ops/host-anchor-v121/first_anchor_coordinator.py'
        self.assertEqual(hashlib.sha256(p.read_bytes()).hexdigest(),M.V121_MODULE_SHA)

if __name__=='__main__':
    unittest.main(verbosity=2)

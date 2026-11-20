#!/usr/bin/env python3
"""No live worker patch; inspect fixed v118->v120 candidate using exact SHA."""
from __future__ import annotations
import hashlib
import importlib.util
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[2]
P=ROOT/'ops/host-anchor-v120/patch_firstparty_worker.py'
spec=importlib.util.spec_from_file_location('_v120_root_candidate',P)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
LIVE=Path('/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py')
BASE=Path('/opt/mad4b-company-provisioning/reports/host-v121-staging-root-install-20261119/rollback/0-claimed-job-worker.py')
def original_source():
    candidate=LIVE if LIVE.is_file() and hashlib.sha256(LIVE.read_bytes()).hexdigest()==m.ORIGINAL_SHA else BASE
    if not candidate.is_file() or hashlib.sha256(candidate.read_bytes()).hexdigest()!=m.ORIGINAL_SHA:
        raise unittest.SkipTest("Original306 exact pre-v121 source unavailable")
    return candidate.read_text()


class BrokerWorkerCandidateTests(unittest.TestCase):
    def test_exact_current_root_source_accepted_only_as_candidate(self):
        src=original_source()
        self.assertEqual(hashlib.sha256(src.encode()).hexdigest(),m.ORIGINAL_SHA)
        result=m.prepare_candidate(src)
        self.assertIn('firstparty_host_v118_capture_original_queued(job)',result)
        self.assertIn('firstparty_host_v120_capture_original_broker_row(job)',result)
        self.assertTrue(result.index('firstparty_host_v118_capture_original_queued(job)')<
                        result.index('firstparty_host_v120_capture_original_broker_row(job)')<
                        result.index('return sign_existing_original_306_claim(job,entry)'))
        self.assertEqual(result.count('return sign_existing_original_306_claim(job,entry)'),
                         src.count('return sign_existing_original_306_claim(job,entry)'))
        self.assertEqual(result.count('HOST_V120_SIGNED_BROKER_ROW_HELD:'),1)
        self.assertEqual(result.count(m.MARKER),2)
        self.assertIn(m.V120_SHA,result)
        self.assertEqual(hashlib.sha256(
            (ROOT/'ops/host-anchor-v120/broker_row_witness.py').read_bytes()).hexdigest(),
            m.V120_SHA)
    def test_reapplying_candidate_cannot_patch_twice(self):
        result=m.prepare_candidate(original_source())
        with self.assertRaisesRegex(ValueError,'ALREADY_PRESENT'):
            m.prepare_candidate(result)
    def test_other_original306_source_head_rejected(self):
        with self.assertRaisesRegex(ValueError,'HEAD_DRIFT'):
            m.prepare_candidate(original_source()+' #sideloaded')
    def test_no_install_side_effects(self):
        self.assertEqual(m.main(),2)
        p=P.read_text()
        for x in ('os.replace(','subprocess.run(','os.system(','wp option update'):
            self.assertNotIn(x,p)

if __name__=='__main__':unittest.main(verbosity=2)

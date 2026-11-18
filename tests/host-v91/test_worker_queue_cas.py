"""Exact original306 live-first Worker queue observer; no signed Job issued."""
from pathlib import Path
import hashlib,importlib.util
import unittest

ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/'ops/host-anchor-v91/patch_original306_worker.py'
spec=importlib.util.spec_from_file_location('v91_original306_worker_CAS',SRC)
p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)
LIVE=Path('/opt/mad4b-company-transport/native-313-independent/claimed-job-worker.py')


class ExactWorkerCAS(unittest.TestCase):
    def test_original306_preimage_and_syntax(self):
        if not LIVE.is_file():self.skipTest('root Worker is not available in CI')
        b=LIVE.read_bytes()
        if hashlib.sha256(b).hexdigest()!=p.PREIMAGE_SHA256:
            self.skipTest('Worker CAS already migrated; old source unchanged')
        target=p.render(b)
        self.assertEqual(hashlib.sha256(target).hexdigest(),
                         '4157214d1615f85093bdd7a54246cea87a25e79df8b40f6a27f8d4f434c44f5b')
        self.assertEqual(target.count(b'firstparty_host_v2_pending_observation'),2)
        compile(target,'claimed-job-worker-v91.py','exec')

    def test_stale_preimage_blocked(self):
        with self.assertRaisesRegex(ValueError,'LIVE_PREIMAGE_CHANGED'):
            p.render(b'changed')

    def test_no_signed_issuer_in_observer(self):
        text=p.FUNCTION
        for unsafe in ('sign_existing_original_306_claim(','.sign(','key.sign(',
                       'update_option(', 'add_option('):
            self.assertNotIn(unsafe,text)
        self.assertIn("'eligible_to_claim':False",text)
        self.assertIn("'original313_certified':False",text)

    def test_typed_v2_intercept_does_not_change_generic_wpcli(self):
        self.assertIn("company.host-authority.anchor.issue",p.BRANCH)
        self.assertIn("company.host-authority.anchor.revoke",p.BRANCH)
        self.assertIn("if not (j.get('operation')=='company.remote.wpcli.full'",p.BRANCH)
        self.assertIn('   continue',p.BRANCH)
        self.assertNotIn("sign_existing_original_306_claim(",p.BRANCH)

    def test_root_observer_module_source_is_pinned(self):
        module=ROOT/'ops/host-anchor-v91/original306_queued_observer.py'
        self.assertEqual(hashlib.sha256(module.read_bytes()).hexdigest(),p.OBSERVER_SHA256)

if __name__=='__main__':unittest.main()

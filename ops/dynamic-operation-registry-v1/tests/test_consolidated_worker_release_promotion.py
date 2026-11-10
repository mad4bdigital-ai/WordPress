"""No-live-write acceptance for the consolidated 313 provider release copies."""
import importlib.util
from pathlib import Path
from types import SimpleNamespace
import tempfile
import unittest
from contextlib import contextmanager,ExitStack
from unittest.mock import patch

SOURCE=Path(__file__).resolve().parents[1]/'integrations/promote_consolidated_worker_release.py'
spec=importlib.util.spec_from_file_location('consolidated_release_promotion_test',SOURCE)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

@unittest.skipUnless(m.ACTIVE.is_file() and m.SOURCE.is_file(), 'Host-enrolled source not available')
class ConsolidatedReleaseTests(unittest.TestCase):
    @contextmanager
    def historical_first_party_worker(self):
        """Prove v1 promotion from its original SHA corpus, not today's active worker.

        The v1 source guard still protects the first release; newer v2
        installations must not make that historical acceptance suite fail.
        """
        with tempfile.TemporaryDirectory() as temp,ExitStack() as stack:
            root=Path(temp)
            active=root/'historic-active.py'
            active.write_bytes(m.SOURCE.read_bytes());active.chmod(0o600)
            historic={}
            for label,(_,old_sha) in m.TARGETS.items():
                old_path=m.BK/(label+'-'+old_sha+'.original.py')
                if not old_path.is_file() or m.exact(old_path)!=old_sha:
                    self.skipTest('Historical first-party release snapshot unavailable')
                f=root/(label+'.old-release.py')
                f.write_bytes(old_path.read_bytes());f.chmod(0o600)
                historic[label]=(f,old_sha)
            stack.enter_context(patch.object(m,'ACTIVE',active))
            stack.enter_context(patch.object(m,'TARGETS',historic))
            yield root,historic

    def test_reviewed_active_satisfies_superset_of_old_release_capabilities(self):
        with self.historical_first_party_worker():
            r=m.readiness()
        self.assertEqual(r['contract'],'mad4b.original313.consolidated-provider-release.v1')
        self.assertEqual(r['source_sha256'],m.NEW)
        self.assertEqual(set(r['providers']),{'mu','plugin'})
        self.assertFalse(r['production_mutated'])

    def test_unknown_release_sha_can_never_be_promoted(self):
        with self.historical_first_party_worker() as (root,_):
            p=root/'unknown.py'
            p.write_bytes(next(iter(m.TARGETS.values()))[0].read_bytes()+b'\n# unknown patch')
            p.chmod(0o700)
            targets={**m.TARGETS,'mu':(p,m.TARGETS['mu'][1])}
            with patch.object(m,'TARGETS',targets),self.assertRaisesRegex(m.Denied,'UNKNOWN_MU_RELEASE_HASH'):
                m.readiness()

    def test_upgrades_two_inactive_release_copies_only_and_restores_previous_bytes(self):
        with self.historical_first_party_worker() as (root,originals):
            pairs=originals
            backups=root/'backups'
            log=root/'receipt.json'
            with patch.object(m,'TARGETS',pairs),patch.object(m,'BK',backups),patch.object(m,'LOG',log),patch.object(
                m.subprocess,'run',return_value=SimpleNamespace(returncode=1)):
                result=m.commit()
                self.assertEqual(result['state'],'VERIFIED_CONSOLIDATED_RELEASE_INSTALLED')
                self.assertEqual(result['release_copies_promoted'],2)
                self.assertFalse(result['active_worker_modified'])
                self.assertFalse(result['production_mutated'])
                for label,(path,old_sha) in pairs.items():
                    self.assertEqual(m.exact(path),m.NEW)
                    self.assertEqual(m.exact(backups/(label+'-'+old_sha+'.original.py')),old_sha)
                self.assertEqual(m.commit()['state'],'ALREADY_CONSOLIDATED')

    def test_running_original313_worker_fails_closed(self):
        with self.historical_first_party_worker(),patch.object(
            m.subprocess,'run',return_value=SimpleNamespace(returncode=0)):
            with self.assertRaisesRegex(m.Denied,'ORIGINAL_313_WORKER_CURRENTLY_RUNNING'):
                m.commit()

if __name__=='__main__':unittest.main()

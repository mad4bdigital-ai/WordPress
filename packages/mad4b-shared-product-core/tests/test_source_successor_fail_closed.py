#!/usr/bin/env python3
"""Exercise the real source freeze against mutated copies, never production."""
from __future__ import annotations
import hashlib,json,shutil,subprocess,tempfile,unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

class ImmutableSuccessorTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='mad4b-shared-core-successor-')
        self.base=Path(self.tmp.name)
        for name in ('mu-plugins', 'docs/snapshots/0.1.0-dev.20261028', 'docs/snapshots/0.1.1-dev.20261113'):
            src=ROOT/name
            dst=self.base/name
            dst.parent.mkdir(parents=True,exist_ok=True)
            shutil.copytree(src,dst)
        for name in ('source-files.sha256','release-manifest.json','docs/snapshots/0.1.2-dev.20261117-source-review.json'):
            src=ROOT/name;dst=self.base/name;dst.parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(src,dst)
        (self.base/'tests').mkdir()
        shutil.copy2(ROOT/'tests/verify-snapshot.py',self.base/'tests/verify-snapshot.py')

    def tearDown(self):self.tmp.cleanup()

    def verify(self,expect_pass:bool,reason:str|None=None):
        p=subprocess.run(['python3',str(self.base/'tests/verify-snapshot.py')],capture_output=True,text=True,timeout=15)
        msg=p.stdout+p.stderr
        if expect_pass:self.assertEqual(p.returncode,0,msg)
        else:
            self.assertNotEqual(p.returncode,0,msg)
            self.assertIn(reason,msg)

    def manifest(self):return json.loads((self.base/'release-manifest.json').read_text())

    def save_manifest(self,m):
        (self.base/'release-manifest.json').write_text(json.dumps(m,indent=2)+'\n')

    def review(self):
        path=self.base/'docs/snapshots/0.1.2-dev.20261117-source-review.json'
        return json.loads(path.read_text())

    def save_review(self,review):
        path=self.base/'docs/snapshots/0.1.2-dev.20261117-source-review.json'
        path.write_text(json.dumps(review,indent=2,sort_keys=True)+'\n')
        m=self.manifest()
        m['source_successor_review']['sha256']=hashlib.sha256(path.read_bytes()).hexdigest()
        self.save_manifest(m)

    def test_exact_successor_source_only_passes(self):self.verify(True)

    def test_missing_successor_review_pointer_denied(self):
        manifest=self.manifest();manifest.pop('source_successor_review',None)
        self.save_manifest(manifest)
        self.verify(False,'SOURCE_SUCCESSOR_REVIEW_REQUIRED')

    def test_review_digest_mutation_denied(self):
        f=self.base/'docs/snapshots/0.1.2-dev.20261117-source-review.json'
        f.write_text(f.read_text().replace('source-only ETG Lab','unreviewed ETG Lab'))
        self.verify(False,'SOURCE_SUCCESSOR_REVIEW_DIGEST_DRIFT')

    def test_authority_claim_denied_even_when_hashes_refresh(self):
        review=self.review();review['production_release_authorized']=True
        self.save_review(review)
        self.verify(False,'SOURCE_SUCCESSOR_REVIEW_NOT_ADMITTED')

    def test_priced_source_mutation_denied_even_when_current_hashes_refresh(self):
        path='shared-product-core/adapters/presentation-form6-occupancy-policy-v1.php'
        source=self.base/'mu-plugins'/path
        source.write_bytes(source.read_bytes()+b'\n// injected future change\n')
        hashes={}
        for line in (self.base/'source-files.sha256').read_text().splitlines():
            digest,k=line.split('  ',1)
            hashes[k]=hashlib.sha256(source.read_bytes()).hexdigest() if k==path else digest
        new=''.join(f'{hashes[k]}  {k}\n' for k in sorted(hashes))
        (self.base/'source-files.sha256').write_text(new)
        manifest=self.manifest();manifest['source_manifest_sha256']=hashlib.sha256(new.encode()).hexdigest();self.save_manifest(manifest)
        self.verify(False,'SOURCE_SUCCESSOR_UNREVIEWED_CONTENT_DRIFT')

    def test_prior_manifest_byte_drift_denied(self):
        history=self.base/'docs/snapshots/0.1.1-dev.20261113/release-manifest.json'
        history.write_bytes(history.read_bytes()+b'\n')
        self.verify(False,'SOURCE_SUCCESSOR_PRIOR_MANIFEST_DRIFT')

    def test_historical_source_history_drift_denied(self):
        history=self.base/'docs/snapshots/0.1.1-dev.20261113/source-files.sha256'
        history.write_bytes(history.read_bytes()+b'fake  extra.php\n')
        self.verify(False,'HISTORICAL_SOURCE_LINEAGE_DRIFT')

    def test_source_only_release_fence_unchanged(self):
        manifest=self.manifest();manifest['deploy_automatically']=True
        self.save_manifest(manifest)
        self.verify(False,'CURRENT_SOURCE_ONLY_RELEASE_FENCE_OR_DIGEST_INVALID')

if __name__=='__main__':unittest.main()

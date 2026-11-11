"""Offline registry proof for the new signed receipt recovery preclaim module."""
import hashlib,importlib.util,json,shutil,tempfile,unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SRC=ROOT/'ops/dynamic-operation-registry-v1'
LIVE=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
MANIFEST=SRC/'providers/company.code.release.receipt.reconcile/manifest.json'

class SignedRecoveryManifestTests(unittest.TestCase):
    def test_signed_admission_exact_sha_pinned_in_manifest(self):
        m=json.loads(MANIFEST.read_text())
        path='receipt-reconcile-v1/signed_recovery_admission.py'
        self.assertEqual(m['operation_id'],'company.code.release.receipt.reconcile')
        deps={x['path']:x['sha256'] for x in m['dependencies']}
        self.assertEqual(deps[path],hashlib.sha256((SRC/path).read_bytes()).hexdigest())
        self.assertNotIn('apply',m['command']['prefix'])

    def test_exact_pinned_manifest_validated_by_original_registry_in_sandbox(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/'enrolled'
            shutil.copytree(LIVE,root)
            root.chmod(0o700)
            source=SRC/'receipt-reconcile-v1/signed_recovery_admission.py'
            target=root/'receipt-reconcile-v1/signed_recovery_admission.py'
            self.assertFalse(target.exists())
            target.write_bytes(source.read_bytes());target.chmod(0o600)
            p=root/'dynamic-operation-registry-v1/providers/company.code.release.receipt.reconcile/manifest.json'
            p.write_bytes(MANIFEST.read_bytes());p.chmod(0o600)
            ix=root/'index.json'
            manifest_hash=hashlib.sha256(MANIFEST.read_bytes()).hexdigest()
            doc=json.loads(ix.read_text())
            for entry in doc['providers']:
                if entry['manifest'].endswith('/company.code.release.receipt.reconcile/manifest.json'):
                    entry['sha256']=manifest_hash
            ix.write_text(json.dumps(doc,sort_keys=True,indent=2)+'\n');ix.chmod(0o600)
            spec=importlib.util.spec_from_file_location('first_party_offline_readonly_registry',root/'registry.py')
            lib=importlib.util.module_from_spec(spec);spec.loader.exec_module(lib)
            index=lib.Registry(root)
            resolved=index.resolve('company.code.release.receipt.reconcile')
            self.assertEqual(resolved['dependencies'][-1]['path'],
                             'receipt-reconcile-v1/signed_recovery_admission.py')
            target.write_text('forged bytes')
            with self.assertRaises(lib.RegistryDenied):lib.Registry(root)

if __name__=='__main__':unittest.main()

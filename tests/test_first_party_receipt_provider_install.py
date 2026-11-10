"""First-party add-only registry source promotion; fake registry is disposable."""
from pathlib import Path
from unittest.mock import patch
import importlib.util,json,shutil,tempfile,unittest
ROOT=Path(__file__).resolve().parents[1]
P=ROOT/'ops/dynamic-operation-registry-v1/receipt-reconcile-v1/install_first_party_receipt_provider.py'
sp=importlib.util.spec_from_file_location('first_party_receipt_addonly_test',P)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)

class OriginalRegistryInstallTests(unittest.TestCase):
    def test_original_first_party_source_commit_verified(self):
        result=m.install()
        self.assertEqual(result['state'],'FIRST_PARTY_PROVIDER_READY')
        self.assertFalse(result['grant_issued'])
        self.assertFalse(result['job_created'])
        self.assertFalse(result['original_313_certified'])

    def test_disposable_registry_add_only_and_idempotent(self):
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder)/'enrolled'
            shutil.copytree(m.INSTALLED,root)
            root.chmod(0o700)
            original=(root/'index.json').read_bytes()
            def sha(q):return m.sha(q.read_bytes())
            old_digest=sha(root/'company-code-release-v1/original_313_host_handler.py')
            with patch.object(m,'INSTALLED',root),patch.object(m,'BACKUP',Path(folder)/'original-index.json'):
                value=m.install(root=root,apply=True)
                self.assertEqual(value['state'],'FIRST_PARTY_RECEIPT_PROVIDER_SOURCE_ENROLLED')
                self.assertEqual(value['files_added'],len(m.FILES))
                self.assertEqual((Path(folder)/'original-index.json').read_bytes(),original)
                self.assertEqual(m.install(root=root,apply=True)['state'],'ALREADY_INSTALLED')
                reg=m.registry_module(root).Registry(root)
                self.assertEqual(set(reg.manifests),{
                  'company.code.release','plugin.license.activate','company.code.release.receipt.reconcile'})
                self.assertEqual(old_digest,sha(root/'company-code-release-v1/original_313_host_handler.py'))
                new=reg.resolve('company.code.release.receipt.reconcile')
                self.assertEqual(new['command']['prefix'],['mad4b','company-code-release','receipt-reconcile'])

    def test_unclean_or_unpinned_source_denied(self):
        with patch.object(m,'COMMIT','f'*40):
            with self.assertRaises(m.Denied):m.candidate()

if __name__=='__main__':unittest.main()

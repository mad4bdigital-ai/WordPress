"""Source-only first-party receipt-reconcile provider acceptance.

No user access claims: all Registry enrollment tests are in disposable private
filesystem roots and do not modify the installed dynamic index or jobs.
"""
import copy,hashlib,importlib.util,json,os,shutil,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
PKG=ROOT/'ops/dynamic-operation-registry-v1/receipt-reconcile-v1'
PROVIDER=ROOT/'ops/dynamic-operation-registry-v1/providers/company.code.release.receipt.reconcile'
INSTALLED=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
AUDIT=ROOT/'scripts/audit_original313_signed_historical_code_release.py'
JOB='a37629fc009a97afe2fb66cef34a48ca'
ORIGINAL='c07011d760b1e7c59ef432a1a38c49cc445a89a674738e58e42b323119179b3f'
sys.path.insert(0,str(PKG))
import recovery_plan,host_handler,independent_readback


def sha(raw):return hashlib.sha256(raw).hexdigest()

class ReceiptOnlyProviderTests(unittest.TestCase):
    def test_historical_audit_dependency_pinned_to_reviewed_source(self):
        self.assertEqual(AUDIT.read_bytes(),(PKG/'historical_audit.py').read_bytes())

    def test_original_writing_code_never_executed(self):
        src=(PKG/'host_handler.py').read_text()
        self.assertNotIn('apply_release(',src)
        self.assertNotIn('release.apply(',src)
        self.assertNotIn('dbDelta(',src)
        self.assertNotIn('get_option(',src)

    @unittest.skipUnless((INSTALLED/'registry.py').exists(),'VPS-only Original Registry needed')
    def test_new_manifest_eligible_in_isolated_first_party_registry(self):
        sp=importlib.util.spec_from_file_location('original_dynamic_registry_offline',INSTALLED/'registry.py')
        registry=importlib.util.module_from_spec(sp);sp.loader.exec_module(registry)
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/'enrolled';root.mkdir(mode=0o700)
            for f in ('host_handler.py','independent_readback.py','recovery_plan.py','historical_audit.py','signed_recovery_admission.py'):
                dest=root/'receipt-reconcile-v1'/f;dest.parent.mkdir(parents=True,exist_ok=True)
                dest.write_bytes((PKG/f).read_bytes());dest.chmod(0o600)
            new=root/'dynamic-operation-registry-v1/providers/company.code.release.receipt.reconcile'
            new.mkdir(parents=True)
            for f in ('manifest.json','evidence-schema.json'):
                (new/f).write_bytes((PROVIDER/f).read_bytes());(new/f).chmod(0o600)
            installed_index=json.loads((INSTALLED/'index.json').read_text())
            index={'contract':'mad4b.dynamic-operation-index.v1',
                   'installed_by_first_party':True,
                   'independent_verifier_public_key_b64':installed_index['independent_verifier_public_key_b64'],
                   'providers':[{'manifest':'dynamic-operation-registry-v1/providers/company.code.release.receipt.reconcile/manifest.json',
                                 'sha256':sha((new/'manifest.json').read_bytes())}]}
            (root/'index.json').write_text(json.dumps(index));(root/'index.json').chmod(0o600)
            client=registry.Registry(root)
            op='company.code.release.receipt.reconcile'
            self.assertEqual(client.resolve(op)['operation_id'],op)
            evidence=client.evidence_definition(op)
            self.assertEqual(evidence['fields']['production_mutation'],'boolean')
            self.assertEqual(evidence['success_state'],'CENTRAL_RECOVERY_RECEIPT_INDEPENDENTLY_CERTIFIED')
            self.assertNotIn(evidence['success_state'],(PKG/'host_handler.py').read_text())
            argv=client.command_argv(op,'b'*64)
            self.assertIn('receipt-reconcile',argv)
            self.assertNotIn('apply',argv)
            self.assertEqual(len(client.command_sha256(op,'b'*64)),64)
            # A source-only candidate can be tested both before and after
            # independent First-Party registration of its reviewed SHA.
            installed=registry.Registry(INSTALLED)
            self.assertIn('company.code.release',installed.manifests)
            self.assertIn('plugin.license.activate',installed.manifests)
            if op in installed.manifests:
                # The candidate extends first-party dependencies but must
                # retain the same command format and exact operation scope.
                self.assertEqual(installed.resolve(op)['command'],client.resolve(op)['command'])
                self.assertEqual(installed.command_sha256(op,'b'*64),
                                 client.command_sha256(op,'b'*64))
            # Tampering a pinned provider byte fails the authoritative loader.
            (root/'receipt-reconcile-v1/host_handler.py').write_text('tampered')
            with self.assertRaisesRegex(registry.RegistryDenied,'PROVIDER_COMPONENT_DRIFT'):
                registry.Registry(root)

    @unittest.skipUnless((INSTALLED/'registry.py').exists(),'VPS-only historic evidence unavailable')
    def test_exact_historical_source_produces_recovery_request_not_certification(self):
        p=recovery_plan.load_exact_plan(JOB,ORIGINAL)
        self.assertEqual(p['operation_id'],'company.code.release.receipt.reconcile')
        self.assertFalse(p['production_mutation_allowed'])
        self.assertFalse(p['original_313_certified'])
        self.assertEqual(p['plan_sha256'],sha(recovery_plan.canonical({k:v for k,v in p.items() if k!='plan_sha256'})))
        self.assertEqual(recovery_plan.require_exact(p),p)
        obs=host_handler.readback(p)
        self.assertFalse(obs['production_mutation'])
        verified=independent_readback.verify(p,obs)
        self.assertFalse(verified['original_313_certified'])
        self.assertEqual(host_handler.execute(p)['state'],'WAIT_CURRENT_SIGNED_RECOVERY_AUTHORITY')

    @unittest.skipUnless((INSTALLED/'registry.py').exists(),'VPS-only historic evidence unavailable')
    def test_untrusted_self_reported_authority_never_certifies(self):
        p=recovery_plan.load_exact_plan(JOB,ORIGINAL)
        v=host_handler.execute(p,authority={'contract':'mad4b.original-recovery-312-bound-authority.v1','verified':True})
        self.assertEqual(v['state'],'WAIT_INDEPENDENT_CENTRAL_RECOVERY_VERIFIER')
        self.assertFalse(v['original_313_certified'])
        self.assertFalse(v['new_recovery_grant_verified'])

    @unittest.skipUnless((INSTALLED/'registry.py').exists(),'VPS-only historic evidence unavailable')
    def test_cannot_spoof_postimage_grant_or_result(self):
        p=recovery_plan.load_exact_plan(JOB,ORIGINAL)
        obs=host_handler.readback(p)
        for patchvalue in (
           {'original_313_certified':True},
           {'production_mutation':True},
           {'new_recovery_grant_verified':True},
           {'readback_sha256':'f'*64},
           {'source_grant_sha256':'f'*64},
           {'original_job_id':'f'*32},
           {'file_count':9},
           {'source_finish_attempt_sha256':'f'*64},
           {'old_grant_reauthorized':True},
           {'current_job_execution_eligible':True},
           {'company':'another-company'}
        ):
            with self.subTest(patch=patchvalue),self.assertRaises(recovery_plan.Denied):
                independent_readback.verify(p,{**obs,**patchvalue})
        with self.assertRaises(recovery_plan.Denied):
            recovery_plan.require_exact({**p,'action':'apply'})
        with self.assertRaises(recovery_plan.Denied):
            recovery_plan.require_exact({**p,'original_job_id':'0'*32})

if __name__=='__main__':unittest.main()

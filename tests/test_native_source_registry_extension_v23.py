"""Registry extension remains source-only with no operation allowlist or issuer."""
from copy import deepcopy
import importlib.util
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
ROOT=Path(__file__).resolve().parents[1]
FILE=ROOT/'ops/native-source-publisher-v1/first_party_registry_extension_preflight.py'
spec=importlib.util.spec_from_file_location('mad4b_v23_source_reg',FILE)
reg=importlib.util.module_from_spec(spec);spec.loader.exec_module(reg)
OPS=ROOT/'ops'
BUILDER=reg.load_module('_mad4b_v23_catalog_fixture',OPS/'native-source-publisher-v1/build_enrollment_candidate.py')
MANIFEST=next(iter(BUILDER.build_all().values()))

class FirstPartyRegistryExtensionV23(unittest.TestCase):
    def setUp(self):
        t=tempfile.TemporaryDirectory(prefix='mad4b-v23-registry-extension-')
        self.addCleanup(t.cleanup)
        self.installed=Path(t.name)
        self.index={'contract':'mad4b.dynamic-operation-index.v1',
            'installed_by_first_party':True,'independent_verifier_public_key_b64':'PINNED_EXISTING_TRUST_ANCHOR',
            'providers':[{'manifest':'dynamic-operation-registry-v1/providers/legacy.dynamic.accept/manifest.json',
                          'sha256':'a'*64}]}
        self.manifest=deepcopy(MANIFEST)
        self.parts=reg.manifest_source_components(self.manifest,OPS,BUILDER.CATALOG)

    def plan(self,**changes):
        args={'worker_compatible':False,'association_anchor_ready':False,
              'policy_present':False,'word_press_ability_readback':False}
        args.update(changes)
        return reg.projection(self.index,self.manifest,self.parts,self.installed,**args)

    def test_first_party_registry_preclaim_candidate_requires_finalizer(self):
        result=self.plan()
        self.assertEqual(result['state'],'HOLD__FIRST_PARTY_EXTENSION_REVIEW_REQUIRED')
        self.assertEqual(result['current_manifest_count'],1)
        self.assertEqual(result['proposed_manifest_count'],2)
        self.assertEqual(result['operation_id'],self.manifest['operation_id'])
        self.assertNotIn('PROVIDER_ORIGINAL306_PRECLAIM_NOT_DECLARED',result['blockers'])
        self.assertNotIn('PROVIDER_ORIGINAL313_FINALIZER_NOT_DECLARED',result['blockers'])
        self.assertFalse(result['authorized_for_install'])
        self.assertFalse(result['private_key_read'])
        self.assertFalse(result['grant_created'])
        self.assertFalse(result['first_party_registry_changed'])
        self.assertEqual(result['collisions'],[])

    def test_every_operation_comes_from_manifest_not_static_allowlist(self):
        candidate=deepcopy(self.manifest)
        candidate['operation_id']='partner.dynamic.source.release'
        candidate['command']['bound_operation_id']=candidate['operation_id']
        self.manifest=candidate
        row=self.plan()
        self.assertEqual(row['operation_id'],'partner.dynamic.source.release')
        self.assertIn('FIRST_PARTY_SIGNED_EXTENSION_APPROVAL_REQUIRED',row['blockers'])

    def test_preexisting_registry_anchor_is_preserved_in_projection(self):
        a=self.plan()
        second=self.plan()
        self.assertEqual(a['candidate_index_sha256'],second['candidate_index_sha256'])
        self.assertTrue(a['existing_index_preserved'])
        self.assertEqual(self.index['providers'],[{'manifest':'dynamic-operation-registry-v1/providers/legacy.dynamic.accept/manifest.json','sha256':'a'*64}])
        self.assertEqual(self.index['independent_verifier_public_key_b64'],'PINNED_EXISTING_TRUST_ANCHOR')

    def test_existing_operation_cannot_be_duplicated(self):
        self.index['providers'].append({'manifest':'dynamic-operation-registry-v1/providers/'+self.manifest['operation_id']+'/manifest.json','sha256':'b'*64})
        with self.assertRaisesRegex(reg.ExtensionDenied,'ALREADY_REGISTERED'):
            self.plan()

    def test_existing_component_collision_holds_exact_upgrade(self):
        role=next(iter(self.parts))
        installed=self.installed/self.parts[role]['installed_relative']
        installed.parent.mkdir(parents=True)
        installed.write_bytes(b'different-vetted-runtime')
        result=self.plan()
        self.assertEqual(result['collisions'][0]['role'],role)
        self.assertIn('INSTALLED_COMPONENT_CAS_CONFLICT_REQUIRES_REVIEW',result['blockers'])
        self.assertEqual(installed.read_bytes(),b'different-vetted-runtime')

    def test_untrusted_paths_and_changed_file_shas_are_denied(self):
        broken=deepcopy(self.manifest)
        broken['handler']['path']='../etc/shadow'
        with self.assertRaises(reg.ExtensionDenied):
            reg.manifest_source_components(broken,OPS,BUILDER.CATALOG)
        broken=deepcopy(self.manifest)
        broken['handler']['sha256']='b'*64
        with self.assertRaisesRegex(reg.ExtensionDenied,'SHA_DRIFT'):
            reg.manifest_source_components(broken,OPS,BUILDER.CATALOG)

    def test_duplicate_component_path_is_denied(self):
        broken=deepcopy(self.manifest)
        broken['verifier']['path']=broken['handler']['path']
        with self.assertRaisesRegex(reg.ExtensionDenied,'NOT_EXCLUSIVE'):
            reg.manifest_source_components(broken,OPS,BUILDER.CATALOG)

    def test_preclaim_finalizer_and_valid_anchor_still_need_signed_review(self):
        result=self.plan(worker_compatible=True,association_anchor_ready=True,
                         policy_present=True,word_press_ability_readback=True)
        self.assertNotIn('ORIGINAL306_WORKER_SOURCE_NOT_INSTALLED',result['blockers'])
        self.assertNotIn('ROOT_OWNED_SOURCE_PUBLISHER_POLICY_NOT_ENROLLED',result['blockers'])
        self.assertIn('FIRST_PARTY_SIGNED_EXTENSION_APPROVAL_REQUIRED',result['blockers'])
        self.assertFalse(result['authorized_for_install'])

    def test_actual_first_party_registry_is_only_read(self):
        result=reg.read_only_plan()
        self.assertTrue(result['installed_first_party_registry_verified'])
        self.assertFalse(result['registry_updated'])
        self.assertFalse(result['jobs_issued'])
        self.assertFalse(result['wordpress_mutated'])
        self.assertGreaterEqual(result['candidate_count'],1)

if __name__=='__main__':unittest.main()

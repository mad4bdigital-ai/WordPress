"""Host source plans must be immutable CAS plus a separately signed operation."""
from copy import deepcopy
import importlib.util
from pathlib import Path
import sys
import unittest
from unittest import mock

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tests'))
import test_native_source_operation_association as contract
import test_native_source_publisher_v1 as fixture
source=fixture.lib

class VerifiedNativePlanV25(unittest.TestCase):
    def setUp(self):
        self.association=contract.AssociationContract(
            'test_two_providers_one_core_plan_selects_exactly_signed_operation')
        self.association.setUp()
        self.addCleanup(self.association.doCleanups)
        self.association.persist()
        self.original=self.association.core_plan
        self.registry=self.association.source.registry

    def signed(self,index,plan):
        trusted=contract.assoc.exclusive_operation(
            plan,index,self.association.store,self.association.public,
            now=self.association.now)
        return trusted['operation_id'],index.resolve(trusted['operation_id'])

    def test_real_signed_association_rehydrates_original_CAS_in_memory(self):
        source.core.confirm_plan(self.original,
            source.company_policy(self.association.source.policy),
            expect_preimage=True)
        reconstructed=source.verified_enriched_plan(
            self.original,registry=self.registry,
            verify_signed_operation=self.signed)
        self.assertNotIn('source_binding',self.original)
        self.assertEqual(source.core_plan(reconstructed),self.original)
        self.assertEqual(reconstructed,self.association.source.plan1)
        source.check_plan(reconstructed,self.association.source.policy,
                          registry=self.registry)

    def test_unsigned_original_plan_cannot_claim_an_operation(self):
        with self.assertRaises(source.Denied):
            source.verified_enriched_plan(self.original,registry=self.registry,
                verify_signed_operation=lambda i,p:('unknown.op',{}))
        self.assertNotIn('source_binding',self.original)

    def test_unsafe_embedded_operation_and_binding_are_rejected(self):
        for extra in ({'source_binding':{'operation_id':'source.code.release'}},
                      {'operation_id':'source.code.release'},
                      {'changed_count':5,'origin':'https://wrong.invalid'}):
            invalid={**self.original,**extra}
            with self.subTest(extra=extra),self.assertRaisesRegex(
                source.Denied,'SOURCE_ORIGINAL_CAS_PLAN_CHANGED'):
                source.verified_enriched_plan(invalid,registry=self.registry,
                    verify_signed_operation=self.signed)

    def test_tampered_signed_association_cannot_be_rehydrated(self):
        path=self.association.persist()
        original=path.read_bytes()
        try:
            row=__import__('json').loads(original)
            row['operation_id']=self.association.source.manifest2['operation_id']
            path.write_text(__import__('json').dumps(row))
            with self.assertRaises(contract.assoc.AssociationDenied):
                source.verified_enriched_plan(self.original,registry=self.registry,
                                             verify_signed_operation=self.signed)
        finally:
            path.write_bytes(original)

    def test_expired_independent_association_is_not_optional(self):
        def expired(index,plan):
            proof=contract.assoc.exclusive_operation(
                plan,index,self.association.store,self.association.public,
                now=self.association.now+300)
            return proof['operation_id'],index.resolve(proof['operation_id'])
        with self.assertRaisesRegex(contract.assoc.AssociationDenied,
                                     'ASSOCIATION_EXPIRED'):
            source.verified_enriched_plan(self.original,registry=self.registry,
                                         verify_signed_operation=expired)

    def test_no_root_registry_stays_fail_closed(self):
        with self.assertRaises(source.Denied):
            source.verified_enriched_plan(self.original,registry=self.registry)

    def test_host_handler_requires_rehydration_from_original(self):
        host=(ROOT/'ops/native-source-publisher-v1/original_313_host_handler.py').read_text()
        self.assertIn('plan=publisher.verified_enriched_plan(original)',host)
        self.assertIn('publisher.check_plan(plan,policy)',host)
        self.assertNotIn('plan=json.loads(raw_plan)',host)
        self.assertNotIn('raw_plan.replace(',host)

if __name__=='__main__':unittest.main()

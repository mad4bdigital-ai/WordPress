"""Original306 indexing candidate: one signed operation per immutable plan.

All file stores are temporary and root-private; no worker execution.
"""
from __future__ import annotations

from copy import deepcopy
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
SOURCE=ROOT/"ops/native-source-publisher-v1"
FIRST_PARTY=Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "dynamic-operation-registry-v1/enrolled"
)
sys.path.insert(0,str(SOURCE))
import associated_worker_index as candidate
import test_native_source_operation_association as association_fixture
from test_native_source_publisher_v1 import lib


def original_modules_isolated():
    """Never populate the generic global registry/admission module cache.

    Other MAD4B provenance tests import their own local Registry at runtime.
    Permanently importing installed registry.py here during unittest discovery
    would make those tests accidentally validate the wrong first-party code.
    """
    missing=object()
    names=("registry","admission","worker_adapter")
    previous={name:sys.modules.get(name,missing) for name in names}

    def private_import(alias,path):
        spec=importlib.util.spec_from_file_location(alias,path)
        if spec is None or spec.loader is None:
            raise RuntimeError("ORIGINAL_FIRST_PARTY_TEST_MODULE_MISSING")
        module=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    try:
        original_registry=private_import(
            "_mad4b_isolated_worker_registry",FIRST_PARTY/"registry.py")
        sys.modules["registry"]=original_registry
        original_admission=private_import(
            "_mad4b_isolated_worker_admission",FIRST_PARTY/"admission.py")
        sys.modules["admission"]=original_admission
        original_worker=private_import(
            "_mad4b_isolated_worker_adapter",FIRST_PARTY/"worker_adapter.py")
        return original_registry,original_admission,original_worker
    finally:
        for name,old in previous.items():
            if old is missing:
                sys.modules.pop(name,None)
            else:
                sys.modules[name]=old


class FixtureIndex:
    def __init__(self,manifests,original_registry):
        self.manifests=manifests
        self.original_registry=original_registry

    def resolve(self,operation):
        if operation not in self.manifests:
            raise self.original_registry.RegistryDenied("PROVIDER_NOT_ENROLLED")
        return self.manifests[operation]

    def command_argv(self,operation,sha):
        m=self.resolve(operation)
        return [*m["command"]["prefix"],m["command"]["flag"]+sha]

    def command_sha256(self,operation,sha):
        return self.original_registry.sha(json.dumps(
            self.command_argv(operation,sha),
            separators=(",",":"),ensure_ascii=False,
        ).encode())


class FirstPartyAssociatedIndex(unittest.TestCase):
    def setUp(self):
        (self.original_registry,self.original_admission,
         self.original_worker)=original_modules_isolated()
        self.fixture=association_fixture.AssociationContract(
            "test_two_providers_one_core_plan_selects_exactly_signed_operation"
        )
        self.fixture.setUp()
        self.addCleanup(self.fixture.doCleanups)
        self.tmp=tempfile.TemporaryDirectory(
            prefix="mad4b-306-associated-index-fixture-"
        )
        self.addCleanup(self.tmp.cleanup)
        self.plans=Path(self.tmp.name)/"plans"
        self.plans.mkdir(mode=0o700)
        self.manifests=deepcopy(self.fixture.source.registry.manifests)
        alternative=self.fixture.source.manifest2["operation_id"]
        self.manifests[alternative]["command"]["prefix"]=[
            "mad4b","partner-source-publisher","apply"
        ]
        self.registry=FixtureIndex(self.manifests,self.original_registry)
        self.plan=self.fixture.core_plan
        self.put(self.plan)

    def put(self,plan):
        path=self.plans/(plan["plan_sha256"]+".json")
        path.write_text(json.dumps(plan,sort_keys=True))
        path.chmod(0o600)
        return path

    def index(self,registry=None,now=None):
        return candidate.indexed_plans_with_association(
            registry or self.registry,self.plans,
            self.fixture.store,self.fixture.public,
            admission_module=self.original_admission,
            registry_module=self.original_registry,
            now=self.fixture.now if now is None else now,
        )

    def test_original_worker_maps_two_but_signed_adapter_only_one(self):
        original=self.original_worker.indexed_plans(self.registry,self.plans)
        self.assertEqual({v["operation"] for v in original.values()},
                         set(self.manifests))
        self.fixture.persist()
        selected=self.index()
        self.assertEqual(len(selected),1)
        only=next(iter(selected.values()))
        self.assertEqual(only["operation"],self.fixture.manifest["operation_id"])
        self.assertTrue(only["signed_association_verified"])
        self.assertFalse(only["job_claimed"])
        self.assertFalse(only["authority_issued"])
        self.assertEqual(only["plan"]["plan_sha256"],self.plan["plan_sha256"])

    def test_missing_association_makes_plan_non_dispatchable(self):
        self.assertEqual(self.index(), {})

    def test_expired_association_does_not_fallback_to_other_provider(self):
        self.fixture.persist()
        self.assertEqual(self.index(now=self.fixture.now+2000), {})

    def test_bad_signature_and_changed_scope_denied(self):
        path=self.fixture.persist()
        doc=json.loads(path.read_text())
        for key,val in (
            ("operation_id",self.fixture.source.manifest2["operation_id"]),
            ("origin","https://other.example.invalid"),
            ("signature","AQ=="),
        ):
            with self.subTest(field=key):
                row=dict(doc)
                row[key]=val
                path.write_text(json.dumps(row))
                with self.assertRaises(candidate.SourceIndexDenied):
                    self.index()
        path.write_text(json.dumps(doc))

    def test_selected_command_digest_is_original_registry_digest(self):
        self.fixture.persist()
        selected=self.index()
        expected=self.registry.command_sha256(
            self.fixture.manifest["operation_id"],self.plan["plan_sha256"]
        )
        self.assertEqual(list(selected),[expected])
        chosen=candidate.select_operation_for_digest(selected,expected)
        self.assertEqual(chosen["operation"],self.fixture.manifest["operation_id"])
        self.assertFalse(chosen["original_312_verified"])
        with self.assertRaises(candidate.SourceIndexDenied):
            candidate.select_operation_for_digest(selected,"0"*64)

    def test_legacy_provider_sharing_same_contract_is_not_fallback(self):
        source=deepcopy(self.manifests)
        legacy=deepcopy(self.fixture.source.manifest2)
        legacy["operation_id"]="legacy.generic.restore"
        legacy["provider"]="legacy-generic-provider"
        legacy["approval_ability"]="mad4b/legacy-generic-restore"
        legacy.pop("source_family")
        legacy["command"]["bound_operation_id"]=legacy["operation_id"]
        source[legacy["operation_id"]]=legacy
        index=FixtureIndex(source,self.original_registry)
        self.fixture.persist()
        selected=self.index(registry=index)
        self.assertEqual(len(selected),1)
        self.assertEqual(next(iter(selected.values()))["operation"],
                         self.fixture.manifest["operation_id"])
        self.fixture.persist().unlink()
        self.assertEqual(self.index(registry=index), {})

    def test_signed_legacy_provider_on_shared_contract_is_valid(self):
        legacy=deepcopy(self.fixture.source.manifest2)
        legacy["operation_id"]="legacy.approved.release"
        legacy["provider"]="approved-existing-provider"
        legacy["approval_ability"]="mad4b/approved-existing-release"
        legacy.pop("source_family",None)
        legacy["command"]["bound_operation_id"]=legacy["operation_id"]
        manifests={**self.manifests,legacy["operation_id"]:legacy}
        index=FixtureIndex(manifests,self.original_registry)
        signed=self.fixture.make_association(self.plan,legacy,self.fixture.now)
        self.fixture.persist(signed)
        rows=self.index(registry=index)
        self.assertEqual(len(rows),1)
        self.assertEqual(next(iter(rows.values()))["operation"],
                         legacy["operation_id"])
        self.assertTrue(next(iter(rows.values()))["signed_association_verified"])
        self.fixture.persist().unlink()
        self.assertEqual(self.index(registry=index), {})

    def test_legacy_only_contract_keeps_original_readonly_index_behavior(self):
        doc=deepcopy(self.plan)
        doc["contract"]="mad4b.other-legacy-plan.v1"
        payload={key:value for key,value in doc.items()
                 if key not in ("plan_sha256","changed_count")}
        doc["plan_sha256"]=lib.sha(lib.canonical(payload))
        legacy=deepcopy(self.fixture.manifest)
        legacy["operation_id"]="legacy.readonly.archive"
        legacy["provider"]="legacy-provider"
        legacy["plan_contract"]=doc["contract"]
        legacy["command"]["bound_operation_id"]=legacy["operation_id"]
        legacy.pop("source_family")
        self.put(doc)
        original_file=self.plans/(self.plan["plan_sha256"]+".json")
        original_file.unlink()
        result=self.index(registry=FixtureIndex({legacy["operation_id"]:legacy},self.original_registry))
        self.assertEqual(len(result),1)
        self.assertFalse(next(iter(result.values()))["signed_association_verified"])

    def test_plan_envelope_rejected_by_original_worker(self):
        self.fixture.persist()
        path=self.put(self.fixture.source.plan1)
        # The actual original CAS validator rejects extra envelope metadata.
        with self.assertRaises(self.original_registry.RegistryDenied):
            self.original_admission.checked_plan(self.plans,self.plan["plan_sha256"],
                                   self.fixture.manifest)
        self.put(self.plan)
        self.assertEqual(len(self.index()),1)

    def test_extra_unsigned_plan_does_not_block_signed_plan(self):
        self.fixture.persist()
        second=self.fixture.source.plan2
        self.put(lib.core_plan(second))
        result=self.index()
        self.assertEqual(len(result),1)
        self.assertEqual(next(iter(result.values()))["plan"]["plan_sha256"],
                         self.plan["plan_sha256"])

    def test_not_one_byte_of_installed_worker_is_modified(self):
        installed=(FIRST_PARTY/"worker_adapter.py").read_text()
        self.assertIn("def indexed_plans(",installed)
        self.assertNotIn("associated_worker_index",installed)
        self.assertNotIn("exclusive_operation",installed)


if __name__=="__main__":
    unittest.main()

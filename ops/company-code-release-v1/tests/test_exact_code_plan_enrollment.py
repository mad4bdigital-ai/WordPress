"""First-party enrollment: hermetic store tests plus live trust-boundary tests.

The historical 2026-11-10 signed-release Plan is not an immutable fixture:
its Capability Owner reconciliation becomes stale when WordPress changes.
Storage tests therefore run the real registration functions against a
synthetic, explicitly test-admitted Plan in temporary root-private dirs.
The production Original313 validator and protected stores are NOT patched
or changed outside these isolated tests; this suite issues no Grants.
"""
from __future__ import annotations

import hashlib
import importlib.util
import json
import os
from pathlib import Path
from tempfile import TemporaryDirectory
from types import SimpleNamespace
from unittest.mock import patch
import unittest

SCRIPT=Path(__file__).resolve().parents[1]/"integrations/enroll_exact_source_plan_v1.py"
sp=importlib.util.spec_from_file_location("first_party_exact_plan_enrollment_test",SCRIPT)
m=importlib.util.module_from_spec(sp)
sp.loader.exec_module(m)
HISTORICAL=Path("/opt/mad4b-company-provisioning/reports/company-execution-closeout-20261110/wayfa-tenant-workflow-source-exact-release-plan.json")


class SyntheticOriginalDenied(Exception):
    pass


class SyntheticOriginalEngine:
    """Test-only original Plan admission; not imported or used in production."""

    Denied=SyntheticOriginalDenied

    def __init__(self, exact_plan):
        self.exact_plan=exact_plan

    def trusted_config(self, path):
        if path != m.POLICY:
            raise SyntheticOriginalDenied("WRONG_PINNED_POLICY")
        return {"test_only":True}

    def confirm_plan(self, candidate, policy, *, expect_preimage):
        if not expect_preimage or candidate != self.exact_plan or not policy.get("test_only"):
            raise SyntheticOriginalDenied("SYNTHETIC_PLAN_IDENTITY_OR_SCOPE_DRIFT")

    @staticmethod
    def canonical(candidate):
        return json.dumps(candidate,sort_keys=True,separators=(",",":")).encode()


class SyntheticRegistry:
    """Only the operation and digest are simulated; store checks stay real."""

    def __init__(self, path):
        if path != m.REG:
            raise SyntheticOriginalDenied("REGISTRY_ROOT_DRIFT")

    def resolve(self, operation):
        if operation != "company.code.release":
            raise SyntheticOriginalDenied("OPERATION_NOT_FOUND")
        return {"operation_id":operation}

    def command_sha256(self, operation, plan_sha256):
        return hashlib.sha256((operation+"|"+plan_sha256).encode()).hexdigest()


@unittest.skipUnless(os.geteuid()==0,"Root needed for production-mode file permissions")
class ExactPlanTests(unittest.TestCase):
    def setUp(self):
        self.tmp=TemporaryDirectory(prefix="mad4b-hermetic-code-release-")
        self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)
        self.plan={
            "contract":"mad4b.company-code-release.plan.v1",
            "company":"synthetic-test-company",
            "environment":"production",
            "site_uuid":"00000000-0000-0000-0000-000000000001",
            "plan_sha256":hashlib.sha256(b"synthetic-local-test-plan").hexdigest(),
            "changed_count":1,
            "files":[{"source":"synthetic/test-only.php",
                      "target":"synthetic/test-only.php"}],
        }
        self.plan_file=self.root/"synthetic-plan.json"
        self.plan_file.write_text(json.dumps(self.plan,sort_keys=True))
        self.plan_file.chmod(0o600)
        self.stores=(self.root/"dynamic",self.root/"release")
        for path in self.stores:
            path.mkdir(mode=0o700)
        self.engine=SyntheticOriginalEngine(self.plan)
        self.registry=SimpleNamespace(Registry=SyntheticRegistry)

    def original_components(self,path,name):
        if path == m.ENGINE:
            return self.engine
        if path == m.REG/"registry.py":
            return self.registry
        raise SyntheticOriginalDenied("UNEXPECTED_ORIGINAL_COMPONENT")

    def enroll(self,install,source=None):
        with patch.object(m,"first_party",side_effect=self.original_components):
            return m.enroll(source or self.plan_file,install,stores=self.stores)

    def test_eligible_plan_check_never_mutates_any_store(self):
        result=self.enroll(False)
        self.assertEqual(result["state"],"EXACT_PLAN_ELIGIBLE_FOR_ENROLLMENT")
        self.assertEqual(result["changed_count"],1)
        self.assertFalse(result["jobs_created"])
        self.assertFalse(result["grants_created"])
        self.assertFalse(result["wp_files_modified"])
        self.assertEqual(list(self.stores[0].iterdir()),[])
        self.assertEqual(list(self.stores[1].iterdir()),[])

    def test_two_store_atomic_registration_and_idempotent_readback(self):
        out=self.enroll(True)
        self.assertEqual(out["state"],"EXACT_PLAN_REGISTERED_PENDING_SIGNED_BROKER")
        self.assertEqual(out["enrolled_store_count"],2)
        self.assertEqual(out["files_inserted"],2)
        self.assertEqual(len(list(self.stores[0].iterdir())),1)
        for store in self.stores:
            path=store/(self.plan["plan_sha256"]+".json")
            self.assertEqual(path.stat().st_mode & 0o777,0o600)
            self.assertEqual(path.read_bytes(),self.engine.canonical(self.plan)+b"\n")
        self.assertEqual(self.enroll(True)["files_inserted"],0)
        self.assertFalse(out["grants_created"])
        self.assertFalse(out["original_313_certified"])

    def test_identity_scope_and_plan_hash_tamper_fail_closed(self):
        for key,value in (
            ("company","other-company"),("environment","staging"),
            ("site_uuid","00000000-0000-0000-0000-000000000002"),
            ("plan_sha256","f"*64),("changed_count",0)
        ):
            with self.subTest(key=key):
                candidate=dict(self.plan,**{key:value})
                path=self.root/"untrusted-plan.json"
                path.write_text(json.dumps(candidate));path.chmod(0o600)
                with self.assertRaisesRegex(m.Denied,"SYNTHETIC_PLAN_IDENTITY_OR_SCOPE_DRIFT"):
                    self.enroll(True,path)
                self.assertEqual(list(self.stores[0].iterdir()),[])
                self.assertEqual(list(self.stores[1].iterdir()),[])

    def test_conflicting_root_plan_is_never_overwritten(self):
        path=self.stores[0]/(self.plan["plan_sha256"]+".json")
        foreign=b'{"untrusted":"modified"}\n'
        path.write_bytes(foreign);path.chmod(0o600)
        with self.assertRaisesRegex(m.Denied,"ENROLLED_PLAN_HAS_DIFFERENT_BYTES"):
            self.enroll(True)
        self.assertEqual(path.read_bytes(),foreign)
        self.assertEqual(list(self.stores[1].iterdir()),[])

    def test_store_permissions_fail_closed(self):
        self.stores[0].chmod(0o755)
        with self.assertRaisesRegex(m.Denied,"PLAN_STORE_NOT_ROOT_PRIVATE"):
            self.enroll(True)
        self.assertEqual(list(self.stores[0].iterdir()),[])
        self.assertEqual(list(self.stores[1].iterdir()),[])

    def test_symlinked_enrolled_plan_is_rejected(self):
        external=self.root/"external-not-ours"
        external.write_bytes(b"private-unchanged")
        link=self.stores[0]/(self.plan["plan_sha256"]+".json")
        link.symlink_to(external)
        with self.assertRaisesRegex(m.Denied,"ENROLLED_PLAN_SYMLINK_DENIED"):
            self.enroll(True)
        self.assertEqual(external.read_bytes(),b"private-unchanged")
        self.assertEqual(list(self.stores[1].iterdir()),[])

    def test_tampered_private_plan_file_stays_ineligible(self):
        self.plan_file.chmod(0o644)
        with self.assertRaisesRegex(m.Denied,"PLAN_SOURCE_ROOT_PRIVATE_REQUIRED"):
            self.enroll(True)

    @unittest.skipUnless(HISTORICAL.is_file(),"Host historical plan not mounted")
    def test_historical_plan_is_not_used_as_immutable_test_fixture(self):
        # Document its source schema without replaying any claim or Grant.
        historical=json.loads(HISTORICAL.read_text())
        self.assertEqual(historical["company"],"wayfatours")
        self.assertEqual(historical["environment"],"production")
        self.assertNotEqual(historical["plan_sha256"],self.plan["plan_sha256"])
        self.assertNotEqual(historical["git_commit"],self.plan.get("git_commit"))
        # The original trust check is intentionally NOT mocked: a historical
        # plan must not be registered as a substitute for a fresh release.
        with self.assertRaisesRegex(m.Denied,"ORIGINAL_RELEASE_PLAN_REJECTED"):
            m.enroll(HISTORICAL,False,stores=self.stores)
        self.assertEqual(list(self.stores[0].iterdir()),[])


if __name__=="__main__":
    unittest.main()

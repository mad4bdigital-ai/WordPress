import copy
import json
import pathlib
import sys
import tempfile
import unittest
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]))
import registry as r
import worker_adapter as w
import test_admission
import admission as a

class WorkerAdapterTests(unittest.TestCase):
    def setUp(self):
        self.fx=test_admission.DynamicClaimTests()
        self.fx.setUp()
        self.idx=self.fx.reg
        self.store=pathlib.Path(self.fx.fx.root)/"plans"
        self.store.mkdir(mode=0o700)
        self.path=self.store/(self.fx.plan["plan_sha256"]+".json")
        self.path.write_text(json.dumps(self.fx.plan)+"\n")

    def tearDown(self):self.fx.tearDown()

    def test_new_provider_resolves_per_plan_without_worker_digest_hardcode(self):
        mapping=w.digest_table(self.idx,self.store)
        self.assertEqual(mapping[self.fx.job["command_sha256"]],
                         "company.code.release")
        chosen=w.select(self.idx,self.store,self.fx.job,self.fx.pending)
        self.assertEqual(chosen["operation_id"],"company.code.release")
        self.assertFalse(chosen["execution_authorized"])

    def test_store_rejects_manifest_missing_enrollment(self):
        self.path.write_text("{}")
        self.assertEqual(w.digest_table(self.idx,self.store),{})
        with self.assertRaisesRegex(r.RegistryDenied,"NOT_REGISTERED"):
            w.select(self.idx,self.store,self.fx.job,self.fx.pending)

    def test_store_rejects_checksum_drift(self):
        self.fx.plan["git_commit"]="0"*40
        self.path.write_text(json.dumps(self.fx.plan))
        with self.assertRaisesRegex(r.RegistryDenied,"PLAN_CONTENT_CHANGED"):
            w.digest_table(self.idx,self.store)

    def test_unrelated_signed_command_not_routed(self):
        self.fx.job["command_sha256"]="f"*64
        with self.assertRaisesRegex(r.RegistryDenied,"NOT_REGISTERED"):
            w.select(self.idx,self.store,self.fx.job,self.fx.pending)

    def test_unknown_provider_is_not_routed(self):
        self.fx.fx.manifest["operation_id"]="renamed.provider"
        self.fx.fx.write_manifest()
        # Same immutable command SHA cannot be silently assigned to a
        # renamed operation id without a matching first-party binding.
        with self.assertRaisesRegex(r.RegistryDenied,"COMMAND_INVALID"):
            r.Registry(self.fx.fx.root)

    def test_unauthorized_direct_executor_cannot_run(self):
        # When the first-party registry is installed, a fixture Job still
        # cannot execute because no matching enrolled plan/digest exists.
        # Before installation it must reject even earlier.
        with self.assertRaisesRegex(r.RegistryDenied,"NOT_INSTALLED|NOT_REGISTERED"):
            w.execute_claimed(self.fx.job,self.fx.pending)

    def test_legacy_306_digest_is_never_overwritten(self):
        old_digest="0"*64
        table={old_digest:"read"}
        table.update(w.digest_table(self.idx,self.store))
        self.assertEqual(table[old_digest],"read")
        self.assertEqual(table[self.fx.job["command_sha256"]],
                         "company.code.release")

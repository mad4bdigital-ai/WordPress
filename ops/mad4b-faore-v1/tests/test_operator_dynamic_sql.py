import hashlib
import importlib.util
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
SPEC=importlib.util.spec_from_file_location(
    "faore_native_operator",ROOT/"integrations/vps-native-operator.py")
OP=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(OP)


class NativeOperatorIntegrationTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.command=Path(self.tmp.name)/"command.sql"
        self.readback=Path(self.tmp.name)/"readback.sql"
        self.command.write_bytes(b"SELECT 1;\n")
        self.readback.write_bytes(b"SELECT 1;\n")
        self.command.chmod(0o600)
        self.readback.chmod(0o600)
        self.args=types.SimpleNamespace(
            kind="sql",intent="read",company="wayfatours",
            environment="production",
            file=str(self.command),
            sha256=hashlib.sha256(self.command.read_bytes()).hexdigest(),
            readback_file=str(self.readback),
            expected_readback_sha256="a"*64,
            correlation_id="fake-correlation-0001",
        )
        self.policy={
            "allow_raw_sql":True,"allow_general_shell":True,
            "scopes":{"wayfatours:production":{
                "allow_operator":True,"allow_sql":True,
                "allow_mutation":True,"allow_production_mutation":True
            }}
        }
        self.target={
            "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
            "canonical_origin":"https://wayfatours.com",
            "coolify_service_uuid":"a"*24,
            "target_container_id":"b"*64,
            "database_container_id":"c"*64,
        }

    def tearDown(self):
        self.tmp.cleanup()

    def test_no_hardcoded_target_map(self):
        self.assertFalse(hasattr(OP,"TARGETS"))

    def test_read_plan_pins_dynamic_docker_id(self):
        with patch.object(OP,"scoped_sql_runtime",return_value=self.target):
            plan=OP.preflight(self.args,self.policy)
        self.assertEqual(plan["runtime_target"]["database_container_id"],"c"*64)
        self.assertFalse(plan["execution_performed"])
        self.assertFalse(plan["mutating"])

    def test_permission_denial_precedes_runtime_probe(self):
        policy={**self.policy,"scopes":{"wayfatours:production":{
            "allow_operator":False,"allow_sql":False}}}
        with patch.object(OP,"scoped_sql_runtime") as fn:
            with self.assertRaises(RuntimeError):
                OP.preflight(self.args,policy)
            fn.assert_not_called()

    def test_container_changed_between_plan_and_apply_denied_before_execution(self):
        with patch.object(OP,"scoped_sql_runtime",return_value=self.target):
            plan=OP.preflight(self.args,self.policy)
        stale={**self.target,"database_container_id":"d"*64}
        with patch.object(OP,"scoped_sql_runtime",return_value=stale):
            with self.assertRaisesRegex(RuntimeError,"RUNTIME_TARGET_CHANGED"):
                OP.apply(self.args,plan)


if __name__=="__main__":
    unittest.main()

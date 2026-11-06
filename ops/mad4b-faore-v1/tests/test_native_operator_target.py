import json
import sys
import tempfile
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from native_operator_target import target, CONTRACT

UUID="a3a7cc36-db7e-4a14-8456-b41857def05c"
ORIGIN="https://wayfatours.com"
SERVICE="b"*24


class NativeOperatorTargetTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.file=Path(self.tmp.name)/"targets.json"
        self.data={
            "contract":CONTRACT,
            "targets":{"wayfatours:production":{
                "site_uuid":UUID,"canonical_origin":ORIGIN,
                "target_container":"obsolete-php",
                "target_database":"PRIVATE_DB_NAME",
            }}}
        self.file.write_text(json.dumps(self.data))
        self.file.chmod(0o600)
        self.runtime=lambda *a,**kw:{
            "state":"TARGET_OBSERVED",
            "profile_verified":True,
            "coolify_service_uuid":SERVICE,
            "target_container":"php-dynamic",
            "container_id":"a"*64,
        }
        self.database=lambda *a,**kw:{
            "database_state":"DATABASE_OBSERVED",
            "database_container":"db-dynamic",
            "database_container_id":"b"*64,
        }

    def tearDown(self):
        self.tmp.cleanup()

    def invoke(self, **kwargs):
        return target("wayfatours","production",manifest=self.file,
                      verify_runtime=self.runtime,verify_database=self.database,
                      **kwargs)

    def test_dynamic_binding_not_static(self):
        row=self.invoke()
        self.assertEqual(row["target_container"],"php-dynamic")
        self.assertEqual(row["database_container"],"db-dynamic")
        self.assertEqual(row["site_uuid"],UUID)
        self.assertFalse(row["authorization_created"])
        self.assertFalse(row["native_execution_performed"])
        self.assertNotIn("PRIVATE_DB_NAME",json.dumps(row))

    def test_exact_company_and_policy(self):
        with self.assertRaises(ValueError):
            target("other","production",manifest=self.file,
                   verify_runtime=self.runtime,verify_database=self.database)
        self.assertEqual(self.invoke()["coolify_service_uuid"],SERVICE)

    def test_requires_live_verified_profile(self):
        self.runtime=lambda *a,**kw:{"state":"TARGET_OBSERVED",
                                     "profile_verified":False}
        with self.assertRaises(ValueError):
            self.invoke()

    def test_requires_same_resource_database(self):
        self.database=lambda *a,**kw:{"database_state":"BLOCKED"}
        with self.assertRaises(ValueError):
            self.invoke()

    def test_root_owned_file_and_permissions(self):
        self.file.chmod(0o644)
        with self.assertRaises(ValueError):
            self.invoke()
        self.file.chmod(0o600)
        alias=Path(self.tmp.name)/"alias"
        alias.symlink_to(self.file)
        with self.assertRaises(ValueError):
            target("wayfatours","production",manifest=alias,
                   verify_runtime=self.runtime,verify_database=self.database)


if __name__=="__main__":
    unittest.main()

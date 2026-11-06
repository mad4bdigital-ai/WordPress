import json
from pathlib import Path
import sys
import tempfile
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from native_target import inspect, CONTRACT

UUID = "a3a7cc36-db7e-4a14-8456-b41857def05c"
ORIGIN = "https://wayfatours.com"


class NativeTargetReadTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.p=Path(self.tmp.name)/"bindings.json"
        self.config={"contract":CONTRACT,"targets":{
            "wayfatours:production":{
                "site_uuid":UUID,"canonical_origin":ORIGIN,
                "target_container":"wayfa-rebuild-php",
                "target_database":"MUST_NOT_LEAK"}}}
        self.p.write_text(json.dumps(self.config))

    def tearDown(self):
        self.tmp.cleanup()

    def run_check(self, **overrides):
        kw=dict(binding_path=self.p,resolve_runtime=lambda *a,**k:{"state":"BLOCKED","reason":"NO_MATCHING_LIVE_COOLIFY_WORDPRESS"})
        kw.update(overrides)
        return inspect("wayfatours","production",UUID,ORIGIN,**kw)

    def test_missing_container_is_not_admission(self):
        report=self.run_check()
        self.assertEqual(report["reason"],"NO_MATCHING_LIVE_COOLIFY_WORDPRESS")
        self.assertFalse(report["native_313_verified"])
        self.assertFalse(report["write_authorized"])
        self.assertNotIn("MUST_NOT_LEAK",json.dumps(report))

    def test_live_container_only_observed(self):
        report=self.run_check(resolve_runtime=lambda *a,**k:{"state":"TARGET_OBSERVED","target_container":"php-dynamic","coolify_service_uuid":"a"*24})
        self.assertEqual(report["state"],"TARGET_OBSERVED")
        self.assertEqual(report["reason"],"FURTHER_NATIVE_ADMISSION_REQUIRED")
        self.assertFalse(report["native_313_verified"])

    def test_site_mismatch_and_missing_registration(self):
        report=inspect("wayfatours","production",
            "00000000-0000-4000-8000-000000000001",ORIGIN,
            binding_path=self.p,resolve_runtime=lambda *a,**k:{"state":"TARGET_OBSERVED","target_container":"php-dynamic","coolify_service_uuid":"a"*24})
        self.assertEqual(report["reason"],"TARGET_BINDING_MISMATCH")
        report=inspect("other","production",UUID,ORIGIN,
            binding_path=self.p,resolve_runtime=lambda *a,**k:{"state":"TARGET_OBSERVED","target_container":"php-dynamic","coolify_service_uuid":"a"*24})
        self.assertEqual(report["reason"],"TARGET_NOT_REGISTERED")

    def test_reject_symlink_and_unknown_contract(self):
        link=Path(self.tmp.name)/"alias"
        link.symlink_to(self.p)
        with self.assertRaises(ValueError):
            self.run_check(binding_path=link)
        self.config["contract"]="not-original"
        self.p.write_text(json.dumps(self.config))
        with self.assertRaises(ValueError):
            self.run_check()

    def test_reject_broken_site_uuid_and_company(self):
        with self.assertRaises(ValueError):
            inspect("wayfatours","production","broken",ORIGIN,binding_path=self.p)
        with self.assertRaises(ValueError):
            inspect("../wayfatours","production",UUID,ORIGIN,binding_path=self.p)


if __name__=="__main__":
    unittest.main()

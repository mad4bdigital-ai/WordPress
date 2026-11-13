"""Source-only runtime planning, no publisher authority or traffic mutations."""
import importlib.util
from pathlib import Path
import unittest

FILE = Path(__file__).resolve().parents[1] / "immutable_runtime.py"
spec = importlib.util.spec_from_file_location("mad4b_immutable_runtime", FILE)
code = importlib.util.module_from_spec(spec)
spec.loader.exec_module(code)


class RuntimePlanTests(unittest.TestCase):
    def artifact(self, slug):
        import hashlib
        import json
        manifest = {
            "contract": code.PACKAGE_CONTRACT,
            "state": "SOURCE_ARTIFACT_REVIEW_ONLY_NOT_DEPLOYABLE",
            "immutable_source_commit": "a"*40,
            "plugin": slug,
            "source_runtime_files": [{
                "path": "main.php", "size": 10, "sha256": "b"*64,
                "source_git_blob": "c"*40}],
            "production_writes": False,
            "source_publisher_enrolled_by_this_artifact": False,
            "original313_certified": False,
            "coherent_activation_proven": False,
        }
        manifest["manifest_sha256"] = code.sha(code.canonical(manifest))
        manifest["artifact_sha256"] = "d"*64
        manifest["artifact_bytes"] = 10240
        return manifest

    def plan(self, rows):
        return code.runtime_plan(
            company="wayfatours", environment="production",
            site_uuid="a3a7cc36-db7e-4a14-8456-b41857def05c",
            origin="https://wayfatours.com",
            image_base_id="sha256:"+"e"*64, plugin_manifests=rows)

    def test_multiple_dynamic_layers_and_order_independent_plan(self):
        a, b = self.artifact("mad4b-tenant-sync"), self.artifact("mad4b-commerce")
        p=self.plan([a,b])
        self.assertEqual(p["layers"][0]["plugin"], "mad4b-commerce")
        self.assertEqual(p["runtime_plan_sha256"],
                         self.plan([b,a])["runtime_plan_sha256"])
        code.verify_plan(p)
        self.assertFalse(p["source_publisher_authorized"])
        self.assertFalse(p["cutover_verified"])
        self.assertEqual(code.select_plugin_layer(p, "mad4b-tenant-sync")
                         ["artifact_sha256"], "d"*64)

    def test_manifest_tampering_denied(self):
        a=self.artifact("mad4b-tenant-sync")
        a["source_runtime_files"][0]["sha256"]="f"*64
        with self.assertRaisesRegex(code.RuntimeHold, "MANIFEST_TAMPERED"):
            self.plan([a])

    def test_duplicate_layers_denied(self):
        a=self.artifact("mad4b-tenant-sync")
        with self.assertRaisesRegex(code.RuntimeHold, "DUPLICATE_PLUGIN"):
            self.plan([a,a])

    def test_invalid_image_or_site_denied(self):
        with self.assertRaisesRegex(code.RuntimeHold, "BASE_IMAGE_UNPINNED"):
            code.runtime_plan(company="wayfatours", environment="production",
                site_uuid="a3a7cc36-db7e-4a14-8456-b41857def05c",
                origin="https://wayfatours.com",
                image_base_id="latest", plugin_manifests=[self.artifact("mad4b-tenant-sync")])

    def test_plan_cannot_self_certify(self):
        p=self.plan([self.artifact("mad4b-tenant-sync")])
        p["source_publisher_authorized"] = True
        p["runtime_plan_sha256"] = code.sha(
            code.canonical({k:v for k,v in p.items() if k!="runtime_plan_sha256"}))
        with self.assertRaisesRegex(code.RuntimeHold, "PRETEND_AUTHORIZATION"):
            code.verify_plan(p)


if __name__=="__main__":
    unittest.main()

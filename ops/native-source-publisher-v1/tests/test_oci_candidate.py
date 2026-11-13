import importlib.util
import io
import json
from pathlib import Path
import os
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "candidate_oci_test", ROOT/"oci_candidate.py")
lib = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lib)
model = lib.model


@unittest.skipUnless(os.geteuid() == 0, "Root-only candidate builder")
class CandidateBuilderTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.directory = Path(self.tmp.name)
        self.directory.chmod(0o700)
        body = b"<?php echo 'MAD4B';"
        payload = io.BytesIO()
        with tarfile.open(fileobj=payload, mode="w",
                          format=tarfile.USTAR_FORMAT) as tar:
            row = tarfile.TarInfo("test-plugin/index.php")
            row.size = len(body)
            row.uid = row.gid = row.mtime = 0
            tar.addfile(row, io.BytesIO(body))
        self.payload = payload.getvalue()
        self.doc = {
            "contract": model.PACKAGE_CONTRACT,
            "state": "SOURCE_ARTIFACT_REVIEW_ONLY_NOT_DEPLOYABLE",
            "immutable_source_commit": "b"*40,
            "plugin": "test-plugin",
            "source_runtime_files": [{
                "path": "index.php", "size": len(body),
                "sha256": model.sha(body),"source_git_blob":"a"*40}],
            "production_writes": False,
            "source_publisher_enrolled_by_this_artifact": False,
            "original313_certified": False,
            "coherent_activation_proven": False
        }
        self.doc["manifest_sha256"] = model.sha(model.canonical(self.doc))
        self.doc["artifact_sha256"] = model.sha(self.payload)
        self.doc["artifact_bytes"] = len(self.payload)
        self.plan = model.runtime_plan(
            company="tenant", environment="any-env",
            site_uuid="a3a7cc36-db7e-4a14-8456-b41857def05c",
            origin="https://wayfatours.com",
            image_base_id="sha256:"+"f"*64,
            plugin_manifests=[self.doc])
        self.manifest=self.directory/"input.json"
        self.archive=self.directory/"input.tar"
        self.manifest.write_text(json.dumps(self.doc));self.manifest.chmod(0o600)
        self.archive.write_bytes(self.payload);self.archive.chmod(0o600)

    def build(self):
        return lib.prepare_context(
            self.plan,{"test-plugin":(self.manifest,self.archive)},
            self.directory/"context")

    def test_prepare_exact_source_and_create_context_without_deploy(self):
        proof=self.build()
        self.assertEqual(proof["source_file_count"], 1)
        self.assertFalse(proof["traffic_switched"])
        self.assertEqual((self.directory/"context/plugins/test-plugin/index.php")
                         .read_bytes(),b"<?php echo 'MAD4B';")
        self.assertIn("COPY --chown=0:0 plugins/ /opt/mad4b/immutable-runtime/wp-content/plugins/",
                      (self.directory/"context/Dockerfile").read_text())
        self.assertIn("FROM mad4b-immutable-base:sha-",
                      (self.directory/"context/Dockerfile").read_text())
        lib.assert_local_base_pin(self.plan["base_image_id"],
             proof["base_image_local_alias"],lambda name:self.plan["base_image_id"])
        with self.assertRaisesRegex(model.RuntimeHold,"OCI_BASE_ALIAS_ID_CHANGED"):
            lib.assert_local_base_pin(self.plan["base_image_id"],
                 proof["base_image_local_alias"],lambda name:"sha256:"+"a"*64)
        with self.assertRaisesRegex(model.RuntimeHold,"CONTEXT_ALREADY_EXISTS"):
            self.build()

    def test_tamper_archive_rejected_before_context_created(self):
        self.archive.write_bytes(self.payload+b"corruption")
        with self.assertRaisesRegex(model.RuntimeHold,"PACKAGE_DIGEST_CHANGED"):
            self.build()
        self.assertFalse((self.directory/"context").exists())

    def test_source_world_readable_rejected(self):
        self.manifest.chmod(0o644)
        with self.assertRaisesRegex(model.RuntimeHold,"ARTIFACT_UNTRUSTED"):
            self.build()

    def test_root_private_parent_required(self):
        self.directory.chmod(0o755)
        with self.assertRaisesRegex(model.RuntimeHold,"CONTEXT_ROOT_NOT_PRIVATE"):
            self.build()


if __name__=="__main__":
    unittest.main()

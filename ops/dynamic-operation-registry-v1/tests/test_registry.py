import base64
import json
from pathlib import Path
import os
import tempfile
import unittest
import sys
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import registry as r


class RegistryTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.root=Path(self.tmp.name)
        self.verifier_key=Ed25519PrivateKey.generate()
        self.verifier_pub=base64.b64encode(
            self.verifier_key.public_key().public_bytes(
                serialization.Encoding.Raw, serialization.PublicFormat.Raw
            )).decode()
        (self.root/"code").mkdir()
        (self.root/"code"/"handler.py").write_text("print('registered handler fixture')\n")
        (self.root/"code"/"verifier.py").write_text("print('registered readback fixture')\n")
        (self.root/"code"/"schema.json").write_text(json.dumps({
            "contract":"mad4b.dynamic-evidence-schema.v1",
            "schema_id":"code_release.v2",
            "success_state":"HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313",
            "fields":{"file_count":"integer", "readback_sha256":"sha256",
                      "result_status":"string", "native_receipt_sha256":"sha256",
                      "output_sha256":"sha256", "expected_readback_sha256":"sha256",
                      "grant_id":"string", "transport_id":"string",
                      "production_mutation":"boolean"}
        })+"\n")
        self.manifest={
            "contract":r.MANIFEST_CONTRACT,
            "operation_id":"company.code.release",
            "provider":"mad4b-company-release",
            "version":1,
            "plan_contract":"mad4b.company-code-release.plan.v1",
            "evidence_schema":"code_release.v2",
            "evidence":{"path":"code/schema.json",
                        "sha256":r.sha((self.root/"code"/"schema.json").read_bytes())},
            "handler":{"path":"code/handler.py",
                       "sha256":r.sha((self.root/"code"/"handler.py").read_bytes())},
            "verifier":{"path":"code/verifier.py",
                        "sha256":r.sha((self.root/"code"/"verifier.py").read_bytes())},
            "command":{"format":"wpcli.argv.json.v1","mode":"plan_sha256",
                       "bound_operation_id":"company.code.release",
                       "prefix":["mad4b","company-code-release","apply"],
                       "flag":"--plan_sha256="},
        }
        self.write_manifest()

    def tearDown(self):self.tmp.cleanup()

    def write_manifest(self):
        path=self.root/"provider.json"
        path.write_text(json.dumps(self.manifest,sort_keys=True)+"\n")
        (self.root/"index.json").write_text(json.dumps({
            "contract":r.INDEX_CONTRACT,
            "installed_by_first_party":True,
            "independent_verifier_public_key_b64":self.verifier_pub,
            "providers":[{"manifest":"provider.json","sha256":r.sha(path.read_bytes())}]
        })+"\n")

    def test_dynamic_operation_without_worker_code_change(self):
        registry=r.Registry(self.root)
        self.assertEqual(registry.summary()["enrolled_operations"],["company.code.release"])
        digest=registry.command_sha256("company.code.release","1"*64)
        self.assertEqual(digest,r.sha(b'["mad4b","company-code-release","apply","--plan_sha256='+b"1"*64+b'"]'))
        self.assertNotEqual(digest,registry.command_sha256("company.code.release","2"*64))
        self.assertFalse(registry.summary()["grant_issued"])

    def test_readonly_discovery_not_authorization(self):
        registry=r.Registry(self.root)
        self.assertFalse(registry.summary()["execution_performed"])
        self.assertFalse(registry.summary()["central_313_receipt_issued"])

    def test_modify_manifest_denied(self):
        registry=r.Registry(self.root)
        (self.root/"provider.json").write_text("{}\n")
        with self.assertRaisesRegex(r.RegistryDenied,"MANIFEST_DRIFT"):
            r.Registry(self.root)

    def test_transitive_python_dependency_must_match_pin(self):
        path=self.root/"code"/"module.py"
        path.write_text("SAFE_DEPENDENCY=1\n")
        self.manifest["dependencies"]=[{
            "path":"code/module.py",
            "sha256":r.sha(path.read_bytes())}]
        self.write_manifest()
        r.Registry(self.root)
        path.write_text("SAFE_DEPENDENCY=2\n")
        with self.assertRaisesRegex(r.RegistryDenied,
                                    "PROVIDER_DEPENDENCY_DRIFT"):
            r.Registry(self.root)

    def test_dependency_path_traversal_denied(self):
        self.manifest["dependencies"]=[{"path":"../not-trusted.py",
                                        "sha256":"a"*64}]
        self.write_manifest()
        with self.assertRaisesRegex(r.RegistryDenied,"REGISTRY_PATH_TRAVERSAL"):
            r.Registry(self.root)

    def test_modify_handler_denied(self):
        (self.root/"code"/"handler.py").write_text("untrusted\n")
        with self.assertRaisesRegex(r.RegistryDenied,"COMPONENT_DRIFT"):
            r.Registry(self.root)

    def test_manifest_symlink_denied(self):
        (self.root/"provider.json").unlink()
        (self.root/"provider.json").symlink_to("/etc/hosts")
        with self.assertRaisesRegex(r.RegistryDenied,"SYMLINK"):
            r.Registry(self.root)

    def test_unenrolled_index_rejected(self):
        d=json.loads((self.root/"index.json").read_text())
        d["installed_by_first_party"]=False
        (self.root/"index.json").write_text(json.dumps(d))
        with self.assertRaisesRegex(r.RegistryDenied,"NOT_FIRST_PARTY_ENROLLED"):
            r.Registry(self.root)

    def test_injected_command_prefix_denied(self):
        self.manifest["command"]["prefix"].append(";rm -rf /")
        self.write_manifest()
        with self.assertRaisesRegex(r.RegistryDenied,"COMMAND_INVALID"):
            r.Registry(self.root)

    def test_unenrolled_operation_denied(self):
        with self.assertRaisesRegex(r.RegistryDenied,"NOT_ENROLLED"):
            r.Registry(self.root).resolve("other.provider")

    def test_no_plan_hash_injection(self):
        with self.assertRaisesRegex(r.RegistryDenied,"INVALID_JOB_PLAN_HASH"):
            r.Registry(self.root).command_sha256("company.code.release","abcdef;whoami")

    def test_world_writable_provider_directory_denied(self):
        (self.root/"code").chmod(0o777)
        with self.assertRaisesRegex(r.RegistryDenied,"ANCESTOR_NOT_TRUSTED"):
            r.Registry(self.root)

    def test_unlisted_component_denied(self):
        self.manifest["handler"]["path"]="../etc/passwd.py"
        self.write_manifest()
        with self.assertRaisesRegex(r.RegistryDenied,"REGISTRY_PATH_TRAVERSAL"):
            r.Registry(self.root)

    def test_duplicate_operation_denied(self):
        j=json.loads((self.root/"index.json").read_text())
        j["providers"]*=2
        (self.root/"index.json").write_text(json.dumps(j))
        with self.assertRaisesRegex(r.RegistryDenied,"DUPLICATE_OPERATION"):
            r.Registry(self.root)

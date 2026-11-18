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

    def _host_manifest(self):
        self.manifest.update({
            "operation_id":"host.source.release",
            "provider":"mad4b-host-source",
            "source_family":"mad4b.native-host-source-release.v1",
            "resource_kind":"host_root_file",
            "target_scope":"host",
            "job_type":"signed_vps_host_source",
        })
        self.manifest["command"].update({
            "format":"host.source.ref.v1",
            "bound_operation_id":"host.source.release",
        })
        self.write_manifest()

    def _set_host312_key(self, key):
        row=json.loads((self.root/"index.json").read_text())
        row["host_resource_312_public_key_b64"]=key
        (self.root/"index.json").write_text(json.dumps(row))

    def test_host312_distinct_original_signer_is_readonly_enrollment(self):
        self._host_manifest()
        other=Ed25519PrivateKey.generate().public_key().public_bytes(
            serialization.Encoding.Raw,serialization.PublicFormat.Raw)
        self._set_host312_key(base64.b64encode(other).decode())
        registry=r.Registry(self.root)
        self.assertEqual(registry.resolve("host.source.release")["command"]["format"],
                         "host.source.ref.v1")
        self.assertEqual(registry.host_resource_312_public_key,other)
        self.assertNotEqual(registry.host_resource_312_public_key,
                            registry.independent_verifier_public_key)
        self.assertFalse(registry.summary()["grant_issued"])
        self.assertFalse(registry.summary()["execution_performed"])

    def test_host312_provider_without_root_pin_denied(self):
        self._host_manifest()
        with self.assertRaisesRegex(r.RegistryDenied,"HOST312_ROOT_SIGNER_NOT_ENROLLED"):
            r.Registry(self.root)

    def test_host312_signer_must_not_reuse_original313_key(self):
        self._host_manifest()
        self._set_host312_key(self.verifier_pub)
        with self.assertRaisesRegex(r.RegistryDenied,"HOST312_SIGNER_MUST_BE_INDEPENDENT"):
            r.Registry(self.root)

    def test_host312_malformed_root_key_denied_before_manifest(self):
        self._host_manifest()
        self._set_host312_key("not-base64@@")
        with self.assertRaisesRegex(r.RegistryDenied,"HOST312_ROOT_PIN_INVALID"):
            r.Registry(self.root)

    def test_spoofed_wordpress_provider_as_host_denied(self):
        self.manifest["resource_kind"]="host_root_file"
        self.write_manifest()
        with self.assertRaisesRegex(r.RegistryDenied,"PROVIDER_COMMAND_RESOURCE_MISMATCH"):
            r.Registry(self.root)

    def test_host312_wrong_resource_scope_denied(self):
        self._host_manifest()
        self.manifest["target_scope"]="wordpress"
        self.write_manifest()
        other=Ed25519PrivateKey.generate().public_key().public_bytes(
            serialization.Encoding.Raw,serialization.PublicFormat.Raw)
        self._set_host312_key(base64.b64encode(other).decode())
        with self.assertRaisesRegex(r.RegistryDenied,"HOST_PROVIDER_RESOURCE_CONTRACT_INVALID"):
            r.Registry(self.root)

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


class HostAnchorV2TypedRegistryTests(RegistryTests):
    """No Host Anchor may inherit host.source or WordPress authority."""

    def _source(self, *, operation="company.host-authority.anchor.issue"):
        self.manifest.update({
            "operation_id": operation,
            "provider": "mad4b-host-anchor",
            "plan_contract": (
                "mad4b.host-authority.anchor-issue.plan.v2" if
                operation.endswith(".issue") else
                "mad4b.host-authority.anchor-revoke.plan.v2"
            ),
            "source_family": r.HOST_ANCHOR_FAMILY,
            "resource_kind": "host_anchor",
            "target_scope": "host",
            "job_type": "signed_remote_hostinger_wpcli_v2",
            "original312_contract": "mad4b.original312.host-principal.exact-job.v2",
            "independent313_contract":
                "mad4b.original313.host-anchor-operation-proof.v1",
            "execution_requires_signed_originals": True,
            "execution_enabled": False,
            "preclaim": self.manifest["handler"].copy(),
            "finalizer": self.manifest["verifier"].copy(),
        })
        # The registry rejects reuse of a handler/verifier file as a hook.
        (self.root/"code/preclaim.py").write_text("PASS = False\n")
        (self.root/"code/finalizer.py").write_text("PASS = False\n")
        self.manifest["preclaim"] = {
            "path": "code/preclaim.py",
            "sha256": r.sha((self.root/"code/preclaim.py").read_bytes())
        }
        self.manifest["finalizer"] = {
            "path": "code/finalizer.py",
            "sha256": r.sha((self.root/"code/finalizer.py").read_bytes())
        }
        self.manifest["command"].update({
            "format": r.HOST_ANCHOR_FORMAT,
            "prefix": ["mad4b", "host-anchor", "issue"
                       if operation.endswith(".issue") else "revoke"],
            "bound_operation_id": operation,
        })
        self.write_manifest()

    def _pin(self, *, identical_to_source=False, identical_to_313=False):
        signer = Ed25519PrivateKey.generate()
        signer_raw = signer.public_key().public_bytes(
            serialization.Encoding.Raw, serialization.PublicFormat.Raw)
        source_raw = Ed25519PrivateKey.generate().public_key().public_bytes(
            serialization.Encoding.Raw, serialization.PublicFormat.Raw)
        other = self.verifier_pub if identical_to_313 else base64.b64encode(
            source_raw if identical_to_source else signer_raw).decode()
        data = json.loads((self.root/"index.json").read_text())
        data["host_anchor_312_public_key_b64"] = other
        data["host_resource_312_public_key_b64"] = base64.b64encode(
            source_raw).decode()
        (self.root/"index.json").write_text(json.dumps(data)+"\n")
        return other

    def test_issue_exact_typed_root_pin_can_load_without_execution(self):
        self._source()
        self._pin()
        index = r.Registry(self.root)
        m = index.resolve("company.host-authority.anchor.issue")
        self.assertFalse(m["execution_enabled"])
        self.assertFalse(index.summary()["execution_performed"])
        self.assertFalse(index.summary()["grant_issued"])

    def test_revoke_exact_typed_root_pin_can_load_without_execution(self):
        self._source(operation="company.host-authority.anchor.revoke")
        self._pin()
        index = r.Registry(self.root)
        self.assertEqual(index.resolve(
            "company.host-authority.anchor.revoke")["resource_kind"],
            "host_anchor")

    def test_unpinned_original312_signer_rejected(self):
        self._source()
        with self.assertRaisesRegex(r.RegistryDenied,
                                   "HOST_ANCHOR_312_ROOT_SIGNER_NOT_ENROLLED"):
            r.Registry(self.root)

    def test_original313_key_cannot_issue(self):
        self._source()
        self._pin(identical_to_313=True)
        with self.assertRaisesRegex(r.RegistryDenied,
                                   "HOST_ANCHOR_312_SIGNER_NOT_DISTINCT"):
            r.Registry(self.root)

    def test_host_source_312_key_cannot_issue(self):
        self._source()
        self._pin(identical_to_source=True)
        with self.assertRaisesRegex(r.RegistryDenied,
                                   "HOST_ANCHOR_312_SIGNER_NOT_DISTINCT"):
            r.Registry(self.root)

    def test_wordpress_command_type_cannot_host_anchor(self):
        self._source()
        self._pin()
        self.manifest["command"]["format"] = "wpcli.argv.json.v1"
        self.write_manifest()
        self._pin()
        with self.assertRaisesRegex(r.RegistryDenied,
                                   "PROVIDER_COMMAND_RESOURCE_MISMATCH"):
            r.Registry(self.root)

    def test_host_source_command_type_cannot_host_anchor(self):
        self._source()
        self._pin()
        self.manifest["command"]["format"] = "host.source.ref.v1"
        self.write_manifest()
        self._pin()
        with self.assertRaisesRegex(r.RegistryDenied,
                                   "HOST_PROVIDER_RESOURCE_CONTRACT_INVALID"):
            r.Registry(self.root)

    def test_no_unverified_activation_in_manifest(self):
        self._source()
        self._pin()
        self.manifest["execution_enabled"] = True
        self.write_manifest()
        self._pin()
        with self.assertRaisesRegex(r.RegistryDenied,
                                   "HOST_ANCHOR_ORIGINAL_TYPED_PROVIDER_REQUIRED"):
            r.Registry(self.root)

    def test_manifest_requires_correct_operation(self):
        self._source()
        self._pin()
        self.manifest["operation_id"] = "company.host-authority.anchor.rotate"
        self.manifest["command"]["bound_operation_id"] = self.manifest["operation_id"]
        self.write_manifest()
        self._pin()
        with self.assertRaisesRegex(r.RegistryDenied,
                                   "HOST_ANCHOR_ORIGINAL_TYPED_PROVIDER_REQUIRED"):
            r.Registry(self.root)

    def test_provider_requires_preclaim_finalizer(self):
        self._source()
        self._pin()
        del self.manifest["preclaim"]
        self.write_manifest()
        self._pin()
        with self.assertRaisesRegex(r.RegistryDenied,
                                   "HOST_ANCHOR_ORIGINAL_TYPED_PROVIDER_REQUIRED"):
            r.Registry(self.root)

    def test_wrong_plan_contract_rejected(self):
        self._source()
        self._pin()
        self.manifest["plan_contract"] = "mad4b.host-authority.anchor-revoke.plan.v2"
        self.write_manifest()
        self._pin()
        with self.assertRaisesRegex(r.RegistryDenied,
                                   "HOST_ANCHOR_EXACT_PLAN_CONTRACT_REQUIRED"):
            r.Registry(self.root)

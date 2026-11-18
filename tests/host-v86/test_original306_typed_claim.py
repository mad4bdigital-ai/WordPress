#!/usr/bin/env python3
"""Fail-closed Original306 typed-claim lane; no real keys, jobs or mutations."""
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]
REG=ROOT/"ops/dynamic-operation-registry-v1"
sys.path.insert(0,str(REG))
V86=ROOT/"ops/host-anchor-v86/original306_typed_claim.py"
spec=importlib.util.spec_from_file_location("_original306_host_v86",V86)
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ProviderReadback(unittest.TestCase):
    def test_rejects_empty_without_executing(self):
        with self.assertRaisesRegex(ValueError,"ROOT_REGISTRY_REQUIRED"):
            module.inspect_claimed_original(None, {}, {}, {}, {})

    def test_rejects_fake_registry_with_loader(self):
        class FakeRegistry:
            def resolve(self,op):return {"resource_kind":"host_anchor"}
        with self.assertRaisesRegex(ValueError,"ROOT_REGISTRY_REQUIRED"):
            module.resolve_remote_host_v2_claim(FakeRegistry(), {}, {}, {}, {})

    def test_synthetic_root_registry_bootstrap_and_drift_refusal(self):
        import base64
        import hashlib
        import shutil
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
        from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
        import registry

        if os.geteuid()!=0:
            self.skipTest("Exact first-party root Registry must be owned by UID 0")
        with tempfile.TemporaryDirectory(prefix="original306-anchor-v2-") as tmp:
            root=Path(tmp)
            root.chmod(0o700)
            provider="company.host-authority.anchor.issue"
            host=ROOT/"ops/host-anchor-v2"
            d=root/"host-anchor-v2"
            d.mkdir(mode=0o700)
            for src in host.glob("*.py"):
                dest=d/src.name
                shutil.copyfile(src,dest)
                dest.chmod(0o600)
            rel="dynamic-operation-registry-v1/providers/"+provider
            (root/rel).mkdir(parents=True,mode=0o700)
            for name in ("manifest.json","evidence-schema.json"):
                f=root/rel/name
                shutil.copyfile(ROOT/"ops"/rel/name,f)
                f.chmod(0o600)
            public=[]
            for _ in range(3):
                k=Ed25519PrivateKey.generate()
                public.append(base64.b64encode(k.public_key().public_bytes(
                    Encoding.Raw,PublicFormat.Raw)).decode())
            m=root/rel/"manifest.json"
            index={
                "contract":"mad4b.dynamic-operation-index.v1",
                "installed_by_first_party":True,
                "independent_verifier_public_key_b64":public[0],
                "host_resource_312_public_key_b64":public[1],
                "host_anchor_312_public_key_b64":public[2],
                "providers":[{"manifest":rel+"/manifest.json",
                   "sha256":hashlib.sha256(m.read_bytes()).hexdigest()}],
            }
            (root/"index.json").write_text(json.dumps(index))
            (root/"index.json").chmod(0o600)
            idx=registry.Registry(root)
            path=root/"host-anchor-v2/operation_plan_contract.py"
            spec=importlib.util.spec_from_file_location("host_plan_test",path)
            gate=importlib.util.module_from_spec(spec)
            spec.loader.exec_module(gate)
            plan={
                "contract":"mad4b.host-authority.anchor-issue.plan.v2",
                "operation_id":provider,
                "authority_mode":"bootstrap",
                "company":"all-royal-egypt","environment":"staging",
                "site_uuid":"49c562d1-8f2f-456f-b454-26816c6ba4cb",
                "origin":"https://staging.allroyalegypt.com",
                "target_host_id":"hosting-2",
                "ssh_principal":"u948953904",
                "policy_sha256":"a"*64,"profile_digest":"b"*64,
                "host_key_sha256":"c"*64,"ssh_root_sha256":"d"*64,
                "registry_sha256":"e"*64,
                "grant_revision":0,"anchor_revision":0,
                "requested_anchor_revision":1,
                "enrollment_id":"1"*32,"enrollment_nonce":"2"*32,
            }
            plan["plan_sha256"]=hashlib.sha256(
                gate.canonical(plan)).hexdigest()
            job={
                "operation_id":provider,"operation":provider,
                "job_id":"3"*32,
                "job_type":"signed_remote_hostinger_wpcli_v2",
                "principal_type":"signed_host_principal_v2",
                "state":"BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
                "executed":False,
                "company":plan["company"],"environment":plan["environment"],
                "site_uuid":plan["site_uuid"],"origin":plan["origin"],
                "target_host_id":plan["target_host_id"],
                "target_ssh_principal":plan["ssh_principal"],
                "target_scope":"host","command_format":"host.anchor.ref.v2",
                "plan_sha256":plan["plan_sha256"],
                "command_sha256":idx.command_sha256(provider,plan["plan_sha256"]),
                "broker_host_id":"srv2030908",
                "claimed_epoch":1794999000,
            }
            claim={
                "contract":"mad4b.central-vps-broker.v1",
                "job_id":job["job_id"],
                "host_id":"srv2030908",
                "nonce":"4"*32,
                "claimed_epoch":job["claimed_epoch"],
                "state":"BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
                "production_mutation_performed":False,
            }
            row={k:job[k] for k in ("job_id","command_sha256","company",
                                    "environment","site_uuid","broker_host_id")}
            row["target_id"]=job["target_host_id"]
            result=module.resolve_remote_host_v2_claim(idx,job,claim,row,plan)
            self.assertTrue(result["original306_claim_matched"])
            self.assertFalse(result["execution_authorized"])
            self.assertFalse(result["issuer_commissioned"])
            self.assertFalse(result["original313_certified"])
            for field,change in (
                ("command_sha256","f"*64),
                ("site_uuid","0"*36),
                ("operation_id","company.host-authority.anchor.revoke"),
                ("job_type","signed_vps_wpcli"),
                ("command_format","wpcli.argv.json.v1"),
                ("target_scope","wordpress"),
                ("executed",True),
            ):
                altered=dict(job);altered[field]=change
                with self.subTest(field=field), self.assertRaises(ValueError):
                    module.resolve_remote_host_v2_claim(
                        idx,altered,claim,row,plan)
            for field in ("job_id","state","host_id","nonce","claimed_epoch",
                          "production_mutation_performed"):
                changed=dict(claim)
                changed[field]="x"
                with self.subTest(claim_field=field), self.assertRaises(ValueError):
                    module.resolve_remote_host_v2_claim(
                        idx,job,changed,row,plan)

    def test_no_private_keys_and_no_mutation_calls(self):
        text=V86.read_text()
        for forbidden in ("Ed25519PrivateKey","update_option(", "wp option update",
                          "subprocess.run(", "os.system(", "sign_existing_original_306_claim("):
            self.assertNotIn(forbidden,text)

    def test_readonly_state_guarantees(self):
        source=V86.read_text()
        self.assertIn('"execution_authorized": False',source)
        self.assertIn('"original313_certified": False',source)
        self.assertIn('"issuer_commissioned": False',source)


if __name__=="__main__":
    unittest.main()

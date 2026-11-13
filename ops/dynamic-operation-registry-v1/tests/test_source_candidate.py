import base64
import hashlib
import json
import pathlib
import shutil
import sys
import tempfile
import unittest
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]))
import registry as r

ROOT=pathlib.Path(__file__).resolve().parents[1]
OPS=ROOT.parent

class SourceCandidateTests(unittest.TestCase):
    def test_code_release_source_manifest_matches_original_command(self):
        preview=json.loads((ROOT/"index.preview-NOT-ENROLLED.json").read_text())
        self.assertFalse(preview["installed_by_first_party"])
        self.assertFalse(preview["production_mutated"])
        with tempfile.TemporaryDirectory() as temp:
            target=pathlib.Path(temp)
            mpath=OPS/preview["providers"][0]["manifest"]
            m=json.loads(mpath.read_text())
            for value in (m["handler"],m["verifier"],m["evidence"],
                          *m.get("dependencies",[])):
                dest=target/value["path"]
                dest.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(OPS/value["path"],dest)
            destination=target/preview["providers"][0]["manifest"]
            destination.parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(mpath,destination)
            preview["installed_by_first_party"]=True  # Test-only ephemeral install
            preview["independent_verifier_public_key_b64"]=base64.b64encode(
                Ed25519PrivateKey.generate().public_key().public_bytes(
                    serialization.Encoding.Raw, serialization.PublicFormat.Raw
                )
            ).decode()
            (target/"index.json").write_text(json.dumps(preview))
            index=r.Registry(target)
            digest="f"*64
            expected=r.sha(b'["mad4b","company-code-release","apply","--plan_sha256='+b"f"*64+b'"]')
            self.assertEqual(index.command_sha256("company.code.release",digest),expected)
            # Preview must follow the exact source-pinned provider revision;
            # a v3 assertion would incorrectly reject reviewed v4 capability
            # additions without testing command or evidence compatibility.
            self.assertGreaterEqual(m["version"],4)
            self.assertEqual(index.resolve("company.code.release")["version"],m["version"])
            self.assertEqual(index.evidence_fields("company.code.release")["file_count"],"integer")

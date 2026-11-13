"""Portable check for first-party byte-exact manifest-digest API."""
import importlib.util
from pathlib import Path
import unittest

SOURCE=Path(__file__).resolve().parents[2]/"dynamic-operation-registry-v1/registry.py"
spec=importlib.util.spec_from_file_location("registry_manifest_contract_test",SOURCE)
reg=importlib.util.module_from_spec(spec)
spec.loader.exec_module(reg)


class RegistryManifestContract(unittest.TestCase):
    def test_index_advertises_raw_verified_digest_not_reencoded_json(self):
        obj=reg.Registry.__new__(reg.Registry)
        obj.manifests={"dynamic.provider":{"operation_id":"dynamic.provider"}}
        obj.manifest_digests={"dynamic.provider":"a"*64}
        self.assertEqual(obj.manifest_sha256("dynamic.provider"),"a"*64)
        with self.assertRaisesRegex(reg.RegistryDenied,"NOT_ENROLLED"):
            obj.manifest_sha256("nonexisting.provider")


if __name__=="__main__":
    unittest.main()

import unittest
from compare_read_shadows import compare

def fixture(source, artifact):
    parts = ("profiles", "runtime_inventory", "content_catalog")
    return {
        "contract": "mad4b.provider-read-fingerprint.v1",
        "source_id": source,
        "read_only": True,
        "capture_authorized": True,
        "environment": "staging",
        "site_uuid": "sample-site",
        "site_origin": "https://staging.example.test",
        "site_profile_revision": "3",
        "locale": "en_US",
        "wpml_language": "en",
        "control_plane_runtime_sha256": "a"*64,
        "provider_package_sha256": artifact,
        "captured_at": "2026-10-29T12:00:00+00:00",
        "sections": {key: {"read_only":True,"canonicalization_contract":"mad4b.dfsb-semantic-read.v1","semantic_sha256":"d"*64} for key in parts}
    }

class ShadowTest(unittest.TestCase):
    def setUp(self):
        self.old = fixture("etg-dynamic-filter-seo-bridge","b"*64)
        self.new = fixture("dynamic-filter-seo-bridge","c"*64)

    def test_matching_read_is_non_authorizing(self):
        result=compare(self.old,self.new)
        self.assertTrue(result["matches"])
        self.assertFalse(result["cutover_authorized"])
        self.assertFalse(result["write_authorized"])
        self.assertFalse(result["publication_authorized"])

    def test_tenant_and_runtime_context(self):
        self.new["site_uuid"]="other-site"
        self.assertIn("context_mismatch:site_uuid",compare(self.old,self.new)["differences"])
        self.new["site_uuid"]="sample-site"
        self.new["control_plane_runtime_sha256"]="f"*64
        self.assertIn("context_mismatch:control_plane_runtime_sha256",compare(self.old,self.new)["differences"])

    def test_language_and_read_data(self):
        self.new["wpml_language"]="ar"
        self.assertFalse(compare(self.old,self.new)["matches"])
        self.new["wpml_language"]="en"
        self.new["sections"]["profiles"]["semantic_sha256"]="0"*64
        self.assertIn("semantic_read_mismatch:profiles",compare(self.old,self.new)["differences"])

    def test_time_and_sections(self):
        self.new["captured_at"]="2026-10-29T12:10:00+00:00"
        self.assertFalse(compare(self.old,self.new)["matches"])
        self.new["captured_at"]=self.old["captured_at"]
        self.new["sections"].pop("content_catalog")
        self.assertFalse(compare(self.old,self.new)["matches"])

    def test_identity_and_read_permissions(self):
        self.new["read_only"]=False
        self.assertFalse(compare(self.old,self.new)["matches"])
        self.new["read_only"]=True
        self.new["provider_package_sha256"]=self.old["provider_package_sha256"]
        self.assertFalse(compare(self.old,self.new)["matches"])

if __name__ == "__main__":
    unittest.main()

"""Fail-closed exact-byte proof for source-owned VPS-local Host identity extraction."""
import hashlib
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INC = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes"
METHOD_GIT_BLOB_SHA1 = "03cbb71ffd2587da6db54a730507a93edad8be01"
METHODS = ["vps_local_stdio_requested","vps_local_read_verified","vps_local_actor_verified","vps_local_identity_context","can_vps_local_write_transport","can_vps_local_transport"]

class VPSLocalIdentityParity(unittest.TestCase):
    def test_source_is_bound_and_byte_identical(self):
        facade = (INC / "class-mad4b-scp-servers.php").read_text(encoding="utf-8")
        trait = (INC / "trait-mad4b-scp-servers-vps-local-identity.php").read_text(encoding="utf-8")
        self.assertIn("require_once __DIR__ . '/trait-mad4b-scp-servers-vps-local-identity.php';", facade)
        self.assertIn("use MAD4B_SCP_Servers_VPS_Local_Identity_Trait;", facade)
        self.assertEqual(trait.count("trait MAD4B_SCP_Servers_VPS_Local_Identity_Trait"), 1)
        marker = "\t/**\n\t * Local-only Registered Operator."
        self.assertEqual(trait.count(marker), 1)
        self.assertTrue(trait.endswith("}\n"))
        body = trait[trait.index(marker):trait.rfind("}\n")]
        b = body.encode("utf-8")
        actual = hashlib.sha1(b"blob " + str(len(b)).encode("ascii") + b"\0" + b).hexdigest()
        self.assertEqual(actual, METHOD_GIT_BLOB_SHA1)
        for method in METHODS:
            self.assertEqual(len(re.findall(r"function\s+" + method + r"\s*\(", body)), 1)
        self.assertIn("vps_local_actor_verified()", body)
        self.assertIn("vps_local_read_verified()", body)
        self.assertIn("MAD4B_SCP_Site_Profile::site_uuid()", body)
        self.assertIn("MAD4B_SCP_Execution_Authority::resolve( 'apply' )", body)
        self.assertIn("0 === (int) ( $actor['wp_user_id'] ?? -1 )", body)
        self.assertNotIn("grant_ability(", body)
        self.assertNotIn("update_option(", body)
        self.assertLessEqual(len(facade.splitlines()), 1050)

if __name__ == "__main__":
    unittest.main()

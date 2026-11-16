"""CI-only byte-identity proof for the extracted first-party registration method."""
import hashlib
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INC = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes"
EXPECTED_BODY_GIT_BLOB_SHA1 = "5021c2a8fe944ff3a23959557bd0e80ceec244ac"
EXPECTED_ABILITIES = ["mad4b/site-info","mad4b/environment-journey","mad4b/list-post-types","mad4b/list-plugins","mad4b/abilities-inventory","mad4b/tool-discover","mad4b/tool-info","mad4b/read-execute","mad4b/write-discover","mad4b/write-info","mad4b/write-execute","mad4b/developer-discover","mad4b/developer-info","mad4b/developer-execute","mad4b/enrollment-discover","mad4b/enrollment-info","mad4b/enrollment-execute","mad4b/filesystem-list","mad4b/filesystem-read","mad4b/database-list-tables","mad4b/database-describe-table","mad4b/database-select","mad4b/diagnostics-health","mad4b/runtime-authority-status","mad4b/schema-status","mad4b/content-get-post","mad4b/content-update-post","mad4b/plugin-activate","mad4b/plugin-deactivate","mad4b/filesystem-write","mad4b/filesystem-patch","mad4b/database-update","mad4b/audit-tail","mad4b/database-raw-query"]

class RegistrationParity(unittest.TestCase):
    def setUp(self):
        self.facade = (INC / "class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
        self.trait = (INC / "trait-mad4b-scp-abilities-registration.php").read_text(encoding="utf-8")

    def normalized_body(self):
        marker = "\tprivate function register_source_abilities() {"
        self.assertEqual(self.trait.count(marker), 1)
        end = self.trait.rfind("\n}\n")
        start = self.trait.index(marker)
        self.assertGreater(end, start)
        body = self.trait[start:end]
        return body.replace(marker, "\tpublic function register_abilities() {", 1)

    def test_exact_original_method_bytes_and_all_34_abilities(self):
        body = self.normalized_body()
        b = body.encode("utf-8")
        git_blob = hashlib.sha1(b"blob " + str(len(b)).encode("ascii") + b"\0" + b).hexdigest()
        self.assertEqual(git_blob, EXPECTED_BODY_GIT_BLOB_SHA1)
        names = re.findall(r"\$this->add\(\s*'([^']+)'", body)
        self.assertEqual(names, EXPECTED_ABILITIES)
        self.assertEqual(len(set(names)), 34)

    def test_public_entrypoint_and_no_policy_bypass(self):
        self.assertIn("require_once __DIR__ . '/trait-mad4b-scp-abilities-registration.php';", self.facade)
        self.assertIn("use MAD4B_SCP_Abilities_Registration_Trait;", self.facade)
        self.assertIn("public function register_abilities() {", self.facade)
        self.assertIn("$this->register_source_abilities();", self.facade)
        self.assertIn("trait MAD4B_SCP_Abilities_Registration_Trait", self.trait)
        self.assertIn("do_action( 'mad4b_scp_abilities_registered', $this );", self.trait)
        self.assertIn("MAD4B_SCP_Authorization::authorize_mutation(", self.facade)
        self.assertLessEqual(len(self.facade.splitlines()), 1650)

if __name__ == "__main__":
    unittest.main()

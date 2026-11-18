"""Only the real installed Portfolio owner may supply v4 role migration code."""
import hashlib
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ASSET = ROOT / "ops/company-portfolio-role-v4"
META = json.loads((ASSET / "manifest.json").read_text())
PATCH = (ASSET / "0001-role-capability-drift.patch").read_text()

class PortfolioRolePatch(unittest.TestCase):
    def test_exact_bounded_change(self):
        self.assertEqual(META["contract"], "mad4b.company-portfolio-role-v4-source-patch.v1")
        self.assertEqual(META["target_environment"], "staging")
        self.assertEqual(META["owner_plugin"], "mad4b-company-portfolio-manager")
        self.assertEqual(META["role_mutation_on_patch"], False)
        self.assertEqual(META["production_write_authorized"], False)
        self.assertEqual(META["role_migration_requires_owner_browser_approval"], True)
        self.assertEqual(PATCH.count("@@"), 4)
        self.assertIn("+    const VERSION='20261117_v4';", PATCH)
        self.assertIn("'manage_woocommerce')as$cap)$master->remove_cap($cap);", PATCH)
        self.assertNotIn("new WP_User(", PATCH)
        self.assertNotIn("update_option(", PATCH)
        self.assertNotIn("browserOwnerAuthorized()))", PATCH) # no gate removal
        self.assertTrue(META["target_path"].endswith("/includes/class-mad4b-scp-company-master-admin.php"))
    def test_optional_external_staging_source(self):
        # Only run when the exact original source is supplied explicitly.
        import os
        path=os.environ.get("MAD4B_PORTFOLIO_ROLE_V3_FILE")
        if not path:
            self.skipTest("Original stage source is not part of source-only Git checkout")
        target=Path(path)
        data=target.read_bytes()
        self.assertEqual(hashlib.sha256(data).hexdigest(),META["preimage_sha256"])
        with tempfile.TemporaryDirectory(prefix="mad4b-role-v4-") as temp:
            tree=Path(temp)
            target_file=tree/META["target_path"]
            target_file.parent.mkdir(parents=True)
            target_file.write_bytes(data)
            result=subprocess.run(["patch","-s","-p1","-i",str(ASSET / "0001-role-capability-drift.patch")],cwd=tree,capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stdout+result.stderr)
            self.assertEqual(hashlib.sha256(target_file.read_bytes()).hexdigest(),META["patched_sha256"])
            content=target_file.read_text()
            self.assertIn("MAD4B_SCP_Company_Owner_Activation::browserOwnerAuthorized()",content)
            self.assertIn("if(!in_array(wp_get_environment_type(),['local','staging'],true))",content)
            self.assertIn("'commerce_accountant'",content)
            self.assertIn("'manage_woocommerce')as$cap)$master->remove_cap($cap);",content)
            self.assertIn("if(get_option(self::MIGRATION)==self::VERSION)return;",content)

if __name__=="__main__":
    unittest.main()

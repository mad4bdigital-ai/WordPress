"""Regression checks for the original native enrolled WP-CLI client scope.

Never imports or executes enrolment functions; tests only source and
embedded PHP syntax, preserving signer and original 305 contract.
"""
import ast
from pathlib import Path
import subprocess
import tempfile
import unittest

SOURCE=Path(__file__).resolve().parents[1] / "native-company-wpcli-enroll.py"

def script_and_scope():
    src=SOURCE.read_text(encoding="utf-8")
    tree=ast.parse(src)
    scope=None
    for entry in tree.body:
        if isinstance(entry,ast.Assign) and any(
            isinstance(x,ast.Name) and x.id=="SCOPE_PHP" for x in entry.targets
        ):
            scope=ast.literal_eval(entry.value)
            break
    if scope is None:
        raise AssertionError("Original PHP actor readback not present")
    return src,scope

class NativeScope(unittest.TestCase):
    def test_python_syntax(self):
        src,_=script_and_scope()
        compile(src,str(SOURCE),"exec")

    def test_embedded_php_syntax(self):
        _,scope=script_and_scope()
        with tempfile.TemporaryDirectory() as d:
            path=Path(d)/"scope.php"
            path.write_text("<?php\n"+scope,encoding="utf-8")
            r=subprocess.run(["php","-l",str(path)],capture_output=True,text=True,check=False)
        self.assertEqual(0,r.returncode,r.stderr)

    def test_staging_exact_environment_store(self):
        _,s=script_and_scope()
        self.assertIn("if($env==='staging')",s)
        self.assertIn("MAD4B_Company_Environment_Portfolio_Evidence_V1::ASSIGNMENTS",s)
        self.assertIn("$m.'|'.$c.'|staging'",s)
        self.assertIn("MAD4B_Staging_Manager_Admission_Bridge_V1::has_delegation",s)
        self.assertIn("EXACT_STAGING_MANAGER_BRIDGE_REQUIRED",s)

    def test_original_production_lane_still_separate(self):
        _,s=script_and_scope()
        self.assertIn("MAD4B_Company_Portfolio_Manager_V1::ASSIGNMENTS",s)
        self.assertIn("MAD4B_Company_Portfolio_Manager_V1::has_delegation",s)
        self.assertIn("$assignment_site_uuid=$a['company_site_uuid']??null;",s)
        self.assertIn("} else {",s)

    def test_full_owner_and_signed_principal_still_required(self):
        source,scope=script_and_scope()
        for required in (
            "current_original_central_principal_verified",
            "MAD4B_Native_VPS_Principal_V1::ledger",
            "eligible_actor_count",
            "assignment_active",
            "&& $approval_current",
            "MAD4B_Staging_Manager_Admission_Bridge_V1::has_delegation",
        ):
            self.assertIn(required,scope)
        for required in (
            "ORIGINAL_305_PERSISTENT_PAIR_ENROLLED",
            "Ed25519PrivateKey",
            "SIGNER_NOT_PINNED_BY_WORDPRESS",
            "HOST_POLICY_SITE_UUID_MISMATCH",
            "EXACT_COMPANY_HOST_SCOPE_NOT_AUTHORIZED",
            "MAD4B_SIGNED_WPCLI_COMPANY",
            "MAD4B_SIGNED_WPCLI_ENV",
        ):
            self.assertIn(required,source)

if __name__=="__main__":
    unittest.main(verbosity=2)

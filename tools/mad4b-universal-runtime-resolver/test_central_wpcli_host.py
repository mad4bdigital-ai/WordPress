"""Isolated checks for authenticated central WP-CLI host runner."""
import contextlib
import io
import json
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import patch

import central_wpcli_host as w

SITE="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
ORIGIN="https://tours.dev.mad4b.com"

class CentralHostCliTests(unittest.TestCase):
    def native(self,scope=None):
        policy={"scopes":{"tours-central:staging":{
            "site_uuid":SITE,"canonical_origin":ORIGIN,
            "site_environment":"staging","allow_operator":True,
            "allow_production_mutation":False}}}
        if scope is not None:policy["scopes"]["tours-central:staging"].update(scope)
        return SimpleNamespace(root_session=lambda:None,authority=lambda:policy,
                               journal=lambda *args:None)

    def test_exact_enrolled_host_and_wordpress_binding(self):
        identity={"site_uuid":SITE,"origin":ORIGIN,"environment":"staging",
                  "database":"etg_commerce_staging"}
        with patch.object(w,"run",return_value=json.dumps(identity)):
            out=w.trusted_identity(self.native())
        self.assertEqual(out["database"],"etg_commerce_staging")

    def test_production_permission_does_not_replace_staging_scope(self):
        with patch.object(w,"run",side_effect=AssertionError("wrong scope should never reach WP")):
            with self.assertRaisesRegex(RuntimeError,"HOST_OPERATOR_SCOPE"):
                w.trusted_identity(self.native({"allow_production_mutation":True}))

    def test_wrong_site_uuid_rejected(self):
        identity={"site_uuid":"c"*36,"origin":ORIGIN,"environment":"staging",
                  "database":"etg_commerce_staging"}
        with patch.object(w,"run",return_value=json.dumps(identity)):
            with self.assertRaisesRegex(RuntimeError,"SITE_BINDING_MISMATCH"):
                w.trusted_identity(self.native())

    def test_untrusted_wp_database_identifier_rejected(self):
        identity={"site_uuid":SITE,"origin":ORIGIN,"environment":"staging",
                  "database":"one; DROP TABLE"}
        with patch.object(w,"run",return_value=json.dumps(identity)):
            with self.assertRaisesRegex(RuntimeError,"SITE_BINDING_MISMATCH"):
                w.trusted_identity(self.native())

    def test_plan_is_only_readback_and_has_no_user_impersonation(self):
        identity={"site_uuid":SITE,"origin":ORIGIN,"environment":"staging",
                  "database":"etg_commerce_staging"}
        response={"contract":"mad4b.unified-full-authority.v1",
                  "site_uuid":SITE,"environment":"staging",
                  "mutation_performed":False,"ready_to_apply":False,
                  "blockers":["native_owner_plan_not_ready"]}
        calls=[]
        def fake_run(argv,timeout=0):
            calls.append(argv)
            return json.dumps(response)
        output=io.StringIO()
        with patch.object(w,"module",return_value=self.native()) as host:
            with patch.object(w,"trusted_identity",return_value=identity):
                with patch.object(w,"run",side_effect=fake_run):
                    with patch("sys.argv",["mad4b-central-wpcli","full-authority-plan"]):
                        with contextlib.redirect_stdout(output):
                            w.main()
        data=json.loads(output.getvalue())
        self.assertEqual(data["state"],"VERIFIED")
        self.assertFalse(data["result"]["ready_to_apply"])
        self.assertEqual(data["source"],"registered_root_ssh_plus_exact_host_scope")
        self.assertIn("--allow-root",calls[0])
        self.assertTrue(any(part.startswith("MAD4B_CLI_TARGET_SITE_UUID=") for part in calls[0]))
        self.assertFalse(any(part.startswith("--user") for part in calls[0]))

    def test_wrong_native_readback_contract_denied(self):
        identity={"site_uuid":SITE,"origin":ORIGIN,"environment":"staging",
                  "database":"etg_commerce_staging"}
        response={"contract":"arbitrary","site_uuid":SITE,
                  "environment":"staging","mutation_performed":False}
        with patch.object(w,"module",return_value=self.native()):
            with patch.object(w,"trusted_identity",return_value=identity):
                with patch.object(w,"run",return_value=json.dumps(response)):
                    with patch("sys.argv",["central-wpcli","full-authority-plan"]):
                        with self.assertRaisesRegex(RuntimeError,"WRONG_SITE_OR_CONTRACT"):
                            w.main()

if __name__=="__main__":
    unittest.main()

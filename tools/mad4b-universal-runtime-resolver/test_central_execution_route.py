"""Live central route cannot depend on expiring historic receipts."""
import json
import unittest
from unittest.mock import patch

import central_execution_route as r

SCOPE={"site_uuid":r.UUID,"canonical_origin":r.ORIGIN,
       "environment":"staging","central_execution_adapter":"root_owned_host_operator_v1"}

class LiveRouteTests(unittest.TestCase):
    def setUp(self):
        self.policy={"contract":"mad4b.native-vps-operator.v1",
          "revoked":False,"allow_general_shell":True,
          "scopes":{r.TARGET:{"allow_operator":True,"allow_mutation":True,
            "allow_production_mutation":False,
            "site_uuid":r.UUID,"canonical_origin":r.ORIGIN,
            "site_environment":"staging"}}}
        self.site={"site_uuid":r.UUID,"origin":r.ORIGIN,"environment":"staging",
                   "wordpress_environment":"staging","database":r.DATABASE}
    def check(self,scope=SCOPE):
        with patch.object(r,"secure_data_file",return_value=json.dumps(self.policy).encode()):
            with patch.object(r,"original_operator_present",return_value=True):
                with patch.object(r,"read_live_site",return_value=self.site):
                    return r.check(scope)
    def test_verified_without_historic_receipt(self):
        state,reason,ev=self.check()
        self.assertEqual(state,"verified")
        self.assertTrue(ev["fresh_site_readback"])
        self.assertFalse(ev["historic_receipt_is_authority"])
        self.assertFalse(ev["operation_executed"])
    def test_revocation_fails(self):
        self.policy["revoked"]=True
        self.assertEqual(self.check()[0],"blocked_authority")
    def test_scope_mismatch_fails(self):
        self.assertEqual(self.check({**SCOPE,"environment":"production"})[0],"review_required")
    def test_wrong_site_fails(self):
        self.site["site_uuid"]="another"
        self.assertEqual(self.check()[0],"review_required")
    def test_wrong_database_fails(self):
        self.site["database"]="wrong"
        self.assertEqual(self.check()[0],"review_required")
    def test_wrong_origin_fails(self):
        self.site["origin"]="https://wrong.example"
        self.assertEqual(self.check()[0],"review_required")
    def test_operator_missing_fails(self):
        with patch.object(r,"secure_data_file",return_value=json.dumps(self.policy).encode()):
            with patch.object(r,"original_operator_present",return_value=False):
                self.assertEqual(r.check(SCOPE)[0],"blocked_authority")
    def test_unavailable_live_readback_requires_retry(self):
        with patch.object(r,"secure_data_file",return_value=json.dumps(self.policy).encode()):
            with patch.object(r,"original_operator_present",return_value=True):
                with patch.object(r,"read_live_site",side_effect=RuntimeError("offline")):
                    self.assertEqual(r.check(SCOPE)[0],"retry")

if __name__=="__main__":
    unittest.main()

"""Central Host route cannot inherit Native Owner blockers or grant writes."""
import datetime as dt
import hashlib
import json
import unittest
from unittest.mock import patch
import central_execution_route as r

SITE="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
SCOPE={"site_uuid":SITE,"environment":"staging",
       "canonical_origin":"https://tours.dev.mad4b.com",
       "central_execution_adapter":"root_owned_host_operator_v1"}

class CentralExecutionRouteTests(unittest.TestCase):
    def setUp(self):
        self.command=b"printf 'READBACK\\n'\n"
        self.policy={"contract":"mad4b.native-vps-operator.v1",
             "revoked":False,"allow_general_shell":True,
             "scopes":{r.TARGET:{"allow_operator":True,"allow_mutation":True,
                 "site_uuid":SITE,"canonical_origin":SCOPE["canonical_origin"],
                 "site_environment":"staging"}}}
        digest=hashlib.sha256(self.command).hexdigest()
        self.receipt={"contract":"mad4b.native-vps-operator.v1",
             "state":"VERIFIED","company":"tours-central","environment":"staging",
             "lane":"vps_ssh","intent":"diagnostic","kind":"shell",
             "execution_performed":True,"mutating":False,"mutation_performed":False,
             "readback_matches_expected":True,"production_mutation_authorized":False,
             "command_sha256":digest,"readback_command_sha256":digest,
             "readback_sha256":digest,"expected_readback_sha256":digest,
             "finished_at":dt.datetime.now(dt.timezone.utc).isoformat()}
    def observe(self, scope=SCOPE):
        def source(path):
            if path==r.POLICY:return json.dumps(self.policy).encode()
            if path==r.RECEIPT:return json.dumps(self.receipt).encode()
            if path in (r.COMMAND,r.READBACK):return self.command
            raise AssertionError("unknown evidence source")
        with patch.object(r,"secure_data_file",side_effect=source):
            return r.check(scope)
    def test_original_host_authority_is_independent_of_native_owner(self):
        state,reason,evidence=self.observe()
        self.assertEqual(state,"verified")
        self.assertEqual(evidence["selected_lane"],"local_root_ssh_host_operator")
        self.assertTrue(evidence["native_owner_plan_is_not_central_blocker"])
        self.assertTrue(evidence["new_operation_requires_its_own_host_preflight"])
        self.assertFalse(evidence["wordpress_grants_created"])
    def test_host_grant_revocation_blocks_route(self):
        self.policy["revoked"]=True
        self.assertEqual(self.observe()[0],"blocked_authority")
    def test_receipt_command_digest_conflict_requires_review(self):
        self.receipt["command_sha256"]="a"*64
        self.assertEqual(self.observe()[0],"review_required")
    def test_expired_original_receipt_needs_new_readback(self):
        self.receipt["finished_at"]=(dt.datetime.now(dt.timezone.utc)-dt.timedelta(days=2)).isoformat()
        self.assertEqual(self.observe()[0],"awaiting_provider")
    def test_production_scope_cannot_reuse_staging_host_receipt(self):
        self.assertEqual(self.observe({**SCOPE,"environment":"production"})[0],"review_required")

if __name__=="__main__":
    unittest.main()

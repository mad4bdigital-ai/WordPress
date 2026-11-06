import json
import sys
import unittest
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from stdio_routing import resolve,tools_on_server,server_ids


class STDIOProviderRoutingTests(unittest.TestCase):
    def setUp(self):
        self.container="php-synthetic"
        self.actor=1
        self.tools={
            "mad4b-content":{"mad4b-content-job-list","mad4b-hub-executor-select-plan"},
            "mad4b-write":{"mad4b-content-job-create","mad4b-hub-queue-site-health-enqueue"},
        }

    def route(self,tool,effect="read"):
        return resolve(tool,effect,self.container,self.actor,
            list_servers=lambda c,a:list(self.tools),
            list_tools=lambda c,a,server:self.tools[server])

    def test_exact_enqueue_resolves_write_server_not_content_server(self):
        result=self.route("mad4b-hub-queue-site-health-enqueue","write")
        self.assertEqual(result["selected_server"],"mad4b-write")
        self.assertEqual(result["state"],"TOOL_DISCOVERED_REQUIRES_LOCAL_ADMISSION")
        self.assertFalse(result["authorization_granted"])
        self.assertFalse(result["write_admitted"])
        self.assertFalse(result["tools_invoked"])
        self.assertFalse(result["oauth_required"])

    def test_read_server_routes_dynamically(self):
        result=self.route("mad4b-content-job-list")
        self.assertEqual(result["selected_server"],"mad4b-content")
        self.assertEqual(result["transport"],"vps_mcp_stdio")

    def test_missing_or_ambiguous_server(self):
        self.assertEqual(self.route("mad4b-absent-tool")["state"],"TOOL_NOT_ADVERTISED_FOR_EFFECT")
        self.tools["mad4b-read"]={"mad4b-content-job-list"}
        self.assertEqual(self.route("mad4b-content-job-list")["state"],
                         "MULTIPLE_MCP_SERVERS_REQUIRE_EXPLICIT_POLICY")

    def test_malformed_identity_and_effect_rejected(self):
        for tool,effect in [
            ("../../command","read"),("mad4b-job-list","delete")
        ]:
            with self.assertRaises(ValueError):
                self.route(tool,effect)

    def test_listing_actual_server_ids_not_alias_guesses(self):
        rows=[{"ID":"mad4b-content","Tools":0},
              {"ID":"mad4b-write","Tools":0},
              {"ID":"malicious ../ server"}]
        result=server_ids(self.container,self.actor,
                runner=lambda args,**k:json.dumps(rows))
        self.assertEqual(result,["mad4b-content","mad4b-write"])

    def test_protocol_tool_list_and_pagination_guard(self):
        def fake(args,**kwargs):
            payload=kwargs.get("payload","")
            self.assertIn('"method":"tools/list"',payload)
            return json.dumps({"jsonrpc":"2.0","id":1,"result":{}})+"\n"+json.dumps({
                "jsonrpc":"2.0","id":2,
                "result":{"tools":[{"name":"mad4b-content-job-list"}]}
            })
        names=tools_on_server(self.container,self.actor,"mad4b-content",runner=fake)
        self.assertEqual(names,{"mad4b-content-job-list"})
        def paginated(args,**kwargs):
            return json.dumps({"jsonrpc":"2.0","id":2,
                "result":{"tools":[],"nextCursor":"next"}})
        with self.assertRaisesRegex(RuntimeError,"INVENTORY_INCOMPLETE"):
            tools_on_server(self.container,self.actor,"mad4b-content",runner=paginated)


if __name__=="__main__":
    unittest.main()

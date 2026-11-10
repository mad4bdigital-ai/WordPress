import sys
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import stdio_read_probe as probe

UUID="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
ORIGIN="https://tours.dev.mad4b.com"


class STDIOReadProbeTests(unittest.TestCase):
    def test_central_php_dynamic_and_exact(self):
        import json
        def fake(args,**kwargs):
            if args[1]=="ps":return "php-a\nphp-b\n"
            name=args[4]
            if args[-2:] == ["mad4b_scp_site_profile_v2","--format=json"]:
                return json.dumps({
                    "contract":"mad4b.site-profile.v2",
                    "site_uuid":UUID if name=="php-a" else "other",
                    "canonical_origin":ORIGIN,"environment":"staging"})
            if args[-1]=="siteurl":return ORIGIN
            raise RuntimeError("unexpected read")
        self.assertEqual(probe.php_target(UUID,ORIGIN,"staging",runner=fake),"php-a")

    def test_mismatched_or_ambiguous_site_denied(self):
        import json
        def fake(args,**kwargs):
            if args[1]=="ps":return "php-a\nphp-b\n"
            if args[-2:] == ["mad4b_scp_site_profile_v2","--format=json"]:
                return json.dumps({
                    "contract":"mad4b.site-profile.v2",
                    "site_uuid":UUID,"canonical_origin":ORIGIN,
                    "environment":"staging"})
            if args[-1]=="siteurl":return ORIGIN
            raise RuntimeError("unexpected")
        with self.assertRaisesRegex(RuntimeError,"NOT_UNIQUE"):
            probe.php_target(UUID,ORIGIN,"staging",runner=fake)
        with self.assertRaisesRegex(RuntimeError,"NOT_DISCOVERED"):
            probe.php_target("different",ORIGIN,"staging",runner=fake)

    def test_declared_readonly_canary_and_content_never_exported(self):
        requests=[]
        def fake_exchange(container,actor,server,method,args=None,**kwargs):
            requests.append(method)
            if method=="tools/list":
                return {"tools":[{"name":probe.CANARY,
                    "annotations":{"readOnlyHint":True,"destructiveHint":False}}]}
            return {"isError":False,"content":[{"type":"text","text":"SENSITIVE JOB LIST"}]}
        with patch.object(probe,"server_ids",return_value=["mad4b-content","mad4b-write"]):
            with patch.object(probe,"exchange",side_effect=fake_exchange):
                report=probe.read_probe("php-test",1)
        self.assertTrue(report["read_tool_verified"])
        self.assertEqual(report["tool_count"],1)
        self.assertEqual(requests,["tools/list","tools/call"])
        self.assertNotIn("SENSITIVE JOB LIST",str(report))

    def test_readonly_annotation_required(self):
        events=[]
        def fake(container,actor,server,method,args=None,**kwargs):
            events.append(method)
            return {"tools":[{"name":probe.CANARY,
                "annotations":{"readOnlyHint":False,"destructiveHint":False}}]}
        with patch.object(probe,"server_ids",return_value=["mad4b-content"]):
            with patch.object(probe,"exchange",side_effect=fake):
                with self.assertRaisesRegex(RuntimeError,"NOT_READONLY"):
                    probe.read_probe("php-test",1)
        self.assertEqual(events,["tools/list"])

    def test_run_once_site_scoped_and_no_authority(self):
        stored=[]
        result=probe.run_once(UUID,ORIGIN,"staging",
            target=lambda *args:"php-live",
            actor_resolver=lambda:5,
            probe=lambda container,actor:{
                "tool_count":90,"read_tool_name":probe.CANARY,
                "readback_sha256":"a"*64,"read_tool_verified":True},
            store=lambda container,value:stored.append((container,value)))
        self.assertEqual(result["state"],"READ_PROBE_RECORDED")
        self.assertFalse(result["authorization_granted"])
        self.assertEqual(stored[0][1]["site_uuid"],UUID)
        self.assertFalse(stored[0][1]["mutation_performed"])


if __name__=="__main__":
    unittest.main()

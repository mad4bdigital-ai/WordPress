"""Hermetic FAORE tests. All companies, receipts and results are illustrative."""
from pathlib import Path
import copy
import json
import sys
import tempfile
import unittest

sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from kernel import Company, FCPError, Kernel, check_message, sha


class KernelTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.path=Path(self.tmp.name)/"queue.db"
        self.k=Kernel(self.path)
        self.company=Company("company-a","staging",
            "00000000-0000-4000-8000-000000000001","https://a.example",
            "hybrid",("wpml.translation.audit",))
        self.k.enroll(self.company,lambda _: True)
        self.msg={
          "contract":"company.task.dispatch.v1",
          "company_id":"company-a","environment":"staging",
          "site_uuid":self.company.site_uuid,
          "message_id":"message-00001","delivery_id":"delivery-00001",
          "sent_at":"2026-11-05T01:00:00Z","sequence_number":1,
          "parent_operation_id":"parent-00001",
          "child_operation_id":"child-00001",
          "requested_capability":"wpml.translation.audit",
          "idempotency_key":"company-a:staging:child-00001:read",
          "payload":{"execution_mode":"hybrid","local_admission_required":True,
            "local_skill_resolution":True,"result_delivery":"durable_outbox",
            "requested_scope_digest":"sha256:"+"a"*64,"status":"PLANNED"}
        }
        self.auth=lambda m: {"verified":True,"company_id":m["company_id"],
           "environment":m["environment"],"site_uuid":m["site_uuid"]}
        self.allow=lambda m,b: {"decision":"ACCEPTED",
                  "scope_digest":m["payload"]["requested_scope_digest"]}
        self.deny=lambda m,b: {"decision":"REJECTED",
                  "reason_code":"LOCAL_WRITE_NOT_AUTHORIZED"}
        self.proof=lambda row,result,observed: {
            "verified":True,"broker_stage":313,
            "company_id":row["company_id"],"environment":row["environment"],
            "site_uuid":row["site_uuid"],"child_operation_id":row["child_id"],
            "result_digest":sha(result),"readback_digest":sha(observed),
            "receipt_reference":"313:verified-example-receipt"}

    def tearDown(self):
        self.k.close()
        self.tmp.cleanup()

    def raises(self,code,func):
        with self.assertRaises(FCPError) as ctx:func()
        self.assertEqual(ctx.exception.code,code)

    def test_dispatch_receipt_outbox_and_restart(self):
        decision=self.k.deliver(self.msg,self.auth,self.allow)
        self.assertEqual(decision["state"],"ADMITTED")
        observed={"rows":4,"locales":["en","es"]}
        result=self.k.execute("company-a","staging","child-00001",
           executor=lambda _:observed,readback=lambda _:observed,
           receipt_verify=self.proof)
        self.assertEqual(result["evidence"],"Verified")
        self.assertEqual(result["state"],"COMPLETED")
        self.assertEqual(self.k.fleet_report("parent-00001")["evidence"],"Verified")
        self.assertEqual(self.k.deliver(self.msg,self.auth,self.allow)["state"],"DUPLICATE_DELIVERY")
        retried=copy.deepcopy(self.msg)
        retried.update(delivery_id="delivery-00002",message_id="message-00002",sequence_number=2)
        self.assertEqual(self.k.deliver(retried,self.auth,self.allow)["state"],"REDELIVERED")
        self.raises("OPERATION_NOT_ADMITTED_OR_ALREADY_CLAIMED",
           lambda:self.k.execute("company-a","staging","child-00001",
             executor=lambda _:1,readback=lambda _:1,receipt_verify=lambda *args:"x"))
        due=self.k.due()
        self.assertEqual(len(due),2)
        self.raises("ACK_NOT_VERIFIED",
             lambda:self.k.acknowledge(due[0]["event_id"],lambda _:False))
        self.k.acknowledge(due[0]["event_id"],lambda _:True)
        self.k.close()
        self.k=Kernel(self.path)
        self.assertEqual(self.k.job("company-a","staging","child-00001")["state"],"COMPLETED")
        self.assertEqual(len(self.k.due()),1)

    def test_local_rejection_is_terminal(self):
        rejected=self.k.deliver(self.msg,self.auth,self.deny)
        self.assertEqual(rejected["state"],"REJECTED")
        self.assertEqual(rejected["reason_code"],"LOCAL_WRITE_NOT_AUTHORIZED")
        self.raises("OPERATION_NOT_ADMITTED_OR_ALREADY_CLAIMED",
          lambda:self.k.execute("company-a","staging","child-00001",
            executor=lambda _:True,readback=lambda _:True,
            receipt_verify=lambda *args:"313:example"))
        self.assertEqual(self.k.job("company-a","staging","child-00001")["state"],"REJECTED")

    def test_cross_tenant_and_authentication(self):
        bad=copy.deepcopy(self.msg);bad["site_uuid"]="00000000-0000-4000-8000-000000000099"
        self.raises("TRANSPORT_SCOPE_MISMATCH",
            lambda:self.k.deliver(bad,lambda m: dict(self.auth(self.msg)),self.allow))
        self.raises("TRANSPORT_UNAUTHENTICATED",
            lambda:self.k.deliver(self.msg,lambda _:{"verified":False},self.allow))
        self.raises("TRANSPORT_SCOPE_MISMATCH",
            lambda:self.k.deliver(self.msg,lambda _:{"verified":True,
              "company_id":"foreign","environment":"staging",
              "site_uuid":self.company.site_uuid},self.allow))
        self.assertEqual(self.k.db.execute("SELECT count(*) FROM job").fetchone()[0],0)

    def test_binding_and_local_only_mode(self):
        self.raises("REGISTRY_BINDING_UNVERIFIED",
            lambda:self.k.enroll(self.company,lambda _:False))
        foreign=Company("company-a","staging",
            "00000000-0000-4000-8000-000000000099","https://a.example",
            "hybrid",("wpml.translation.audit",))
        self.raises("REGISTRY_BINDING_CONFLICT",
            lambda:self.k.enroll(foreign,lambda _:True))
        local=Company("company-a","staging",self.company.site_uuid,
                      "https://a.example","local",("wpml.translation.audit",))
        self.k.enroll(local,lambda _:True)
        self.raises("CENTRAL_DISPATCH_DISABLED",
            lambda:self.k.deliver(self.msg,self.auth,self.allow))

    def test_replays_and_sequence(self):
        self.k.deliver(self.msg,self.auth,self.allow)
        replay=copy.deepcopy(self.msg)
        replay["payload"]["requested_scope_digest"]="sha256:"+"b"*64
        self.raises("DELIVERY_REPLAY_CONFLICT",
            lambda:self.k.deliver(replay,self.auth,self.allow))
        retry=copy.deepcopy(self.msg)
        retry["message_id"]="message-00002";retry["delivery_id"]="delivery-00002"
        retry["payload"]["requested_scope_digest"]="sha256:"+"b"*64
        self.raises("LOGICAL_OPERATION_CONFLICT",
            lambda:self.k.deliver(retry,self.auth,self.allow))
        retry["payload"]["requested_scope_digest"]=self.msg["payload"]["requested_scope_digest"]
        retry["message_id"]="message-00001"
        self.raises("MESSAGE_REPLAY_CONFLICT",
            lambda:self.k.deliver(retry,self.auth,self.allow))
        newer=copy.deepcopy(self.msg)
        newer.update(message_id="message-00003",delivery_id="delivery-00003",
                     child_operation_id="child-00002",idempotency_key="company-a:staging:child-00002:read")
        self.raises("MESSAGE_SEQUENCE_REPLAY",
            lambda:self.k.deliver(newer,self.auth,self.allow))

    def test_uncertain_effect_not_reexecuted(self):
        self.k.deliver(self.msg,self.auth,self.allow)
        calls=[]
        result=self.k.execute("company-a","staging","child-00001",
            executor=lambda row:(calls.append(1) or {"rows":1}),
            readback=lambda row:{"rows":2},
            receipt_verify=lambda *args:"313:synthetic")
        self.assertEqual(result["state"],"RECOVERY_REQUIRED")
        self.assertEqual(result["evidence"],"Unverified")
        self.assertEqual(len(calls),1)
        self.raises("OPERATION_NOT_ADMITTED_OR_ALREADY_CLAIMED",
           lambda:self.k.execute("company-a","staging","child-00001",
             executor=lambda row:calls.append(2),readback=lambda row:None,
             receipt_verify=lambda *args:"313:synthetic"))
        self.assertEqual(len(calls),1)
        recovery=self.k.recover_uncertain("company-a","staging","child-00001",
                                         lambda row:{"inspected":True})
        self.assertEqual(recovery["state"],"RECOVERY_REQUIRED")

    def test_retries_dead_letter_and_evidence_freshness(self):
        self.k.deliver(self.msg,self.auth,self.deny)
        eid=self.k.due()[0]["event_id"]
        for i in range(5):
            self.assertEqual(self.k.retry(eid,now=1000+i*100),"RETRY_WAIT")
        self.assertEqual(self.k.retry(eid,now=2000),"DEAD_LETTER")
        self.assertEqual(len(self.k.dead_letters()),1)
        self.assertEqual(len(self.k.due(now=999999)),0)
        summary=self.k.fleet_report("parent-00001",now=9999999999)
        self.assertEqual(summary["children"][0]["evidence"],"Stale")
        self.assertFalse(summary["customer_data_included"])

    def test_graph_and_contract(self):
        self.k.add_dependency("shared:pricing","core:rates","Runtime")
        self.k.add_dependency("core:rates","company:company-a","Schema")
        self.k.add_dependency("core:rates","company:company-b","Schema")
        self.k.add_dependency("shared:pricing","core:rates","Runtime")
        self.assertEqual(self.k.impacted_companies("shared:pricing"),
                         ["company-a","company-b"])
        self.raises("DEPENDENCY_IMPACT_INVALID",
                    lambda:self.k.add_dependency("a-b","c-d","Unknown"))
        check_message(self.msg)
        bad=copy.deepcopy(self.msg);bad["payload"]["local_admission_required"]=False
        self.raises("FCP_DISPATCH_SCOPE_INVALID",lambda:check_message(bad))

    def test_matching_readback_without_native_receipt_is_unverified(self):
        self.k.deliver(self.msg,self.auth,self.allow)
        outcome=self.k.execute("company-a","staging","child-00001",
              executor=lambda _:{"count":5},readback=lambda _:{"count":5},
              receipt_verify=lambda *args:"313:claimed-but-not-verified")
        self.assertEqual(outcome["state"],"RECOVERY_REQUIRED")
        self.assertEqual(outcome["evidence"],"Unverified")
        self.assertEqual(self.k.fleet_report("parent-00001")["evidence"],"Unverified")

    def test_scope_hash_and_payload_limits(self):
        invalid=copy.deepcopy(self.msg)
        invalid["payload"]["requested_scope_digest"]="invalid"
        self.raises("FCP_DISPATCH_SCOPE_INVALID",lambda:check_message(invalid))
        invalid=copy.deepcopy(self.msg)
        invalid["payload"]["untrusted_large_text"]="x"*70000
        self.raises("FCP_BODY_TOO_LARGE",lambda:check_message(invalid))

    def test_reason_code_redaction(self):
        def unsafe_reason(m,b):
            return {"decision":"REJECTED",
                    "reason_code":"Error: confidential customer payload"}
        decision=self.k.deliver(self.msg,self.auth,unsafe_reason)
        self.assertEqual(decision["reason_code"],"LOCAL_ADMISSION_DENIED")
        report=self.k.fleet_report("parent-00001")
        self.assertNotIn("confidential",str(report))
        self.assertNotIn("customer payload",str(report))

    def test_database_file_permissions_and_symlink(self):
        import os
        self.assertEqual(os.stat(self.path).st_mode & 0o777,0o600)
        fake=Path(self.tmp.name)/"database-link.db"
        fake.symlink_to(self.path)
        self.raises("STATE_PATH_SYMLINK_DENIED",lambda:Kernel(fake))

    def test_manifest_fixture_shapes(self):
        root=Path(__file__).resolve().parents[3]
        src=root/"architecture/faore/specs/001-federated-adaptive-operations"
        self.assertEqual(len(list((src/"contracts").glob("*.schema.json"))),6)
        self.assertEqual(len(list((src/"fixtures").glob("*.example.json"))),6)
        dispatch=json.loads((src/"fixtures/company.task.dispatch.v1.example.json").read_text())
        check_message(dispatch)


if __name__=="__main__":
    unittest.main()

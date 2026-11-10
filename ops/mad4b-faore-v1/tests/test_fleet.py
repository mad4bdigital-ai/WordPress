from pathlib import Path
import copy
import sys
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from kernel import Company, FCPError, sha
from fleet import plan_child, retry_delivery, verify_child_receipt, aggregate


class FleetTests(unittest.TestCase):
    def setUp(self):
        self.are=Company("are","staging",
              "00000000-0000-4000-8000-000000000010",
              "https://are.example","hybrid",("wpml.translation.audit",))
        self.wayfa=Company("wayfatours","staging",
              "00000000-0000-4000-8000-000000000011",
              "https://wayfa.example","central_managed",("wpml.translation.audit",))
        self.scope="sha256:"+"a"*64
        self.verify=lambda company:company in (self.are,self.wayfa)

    def test_language_specific_children_and_delivery_identity(self):
        are=plan_child("parent-001",self.are,"wpml.translation.audit",
                       self.scope,1,["en","ar","fr","de","es","it"],self.verify)
        wayfa=plan_child("parent-001",self.wayfa,"wpml.translation.audit",
                         self.scope,1,["en","es"],self.verify)
        self.assertNotEqual(are["dispatch"]["child_operation_id"],
                            wayfa["dispatch"]["child_operation_id"])
        self.assertEqual(are["dispatch"]["payload"]["locales"],
                         ["en","ar","fr","de","es","it"])
        self.assertEqual(wayfa["dispatch"]["payload"]["locales"],["en","es"])
        self.assertFalse(wayfa["actual_execution_verified"])
        with self.assertRaises(FCPError):
            retry_delivery(wayfa,2)
        retryable=copy.deepcopy(wayfa)
        retryable["execution_boundary"]={
            "state":"TRANSIENT_TRANSPORT_DELIVERY_FAILED_BEFORE_HOST",
            "host_journal_events":0,"operator_receipt_present":False,
        }
        attempt=retry_delivery(retryable,2)
        self.assertEqual(attempt["child_operation_id"],
                         wayfa["dispatch"]["child_operation_id"])
        self.assertEqual(attempt["idempotency_key"],
                         wayfa["dispatch"]["idempotency_key"])
        self.assertNotEqual(attempt["delivery_id"],
                            wayfa["dispatch"]["delivery_id"])
        with self.assertRaises(FCPError):
            retry_delivery(retryable,1)

    def test_delivery_retry_requires_resolved_execution_boundary(self):
        planned=plan_child("parent-001",self.wayfa,"wpml.translation.audit",
                           self.scope,1,["en","es"],self.verify)
        denied_states=(
            "PLATFORM_REFUSAL_RECORDED_NO_HOST_ATTEMPT",
            "INPUT_INTEGRITY_FAILED",
            "EXECUTION_EVIDENCE_PRESENT_RECONCILE_BEFORE_ACTION",
            "NO_HOST_EXECUTION_EVIDENCE_CAUSE_UNDETERMINED",
        )
        for state in denied_states:
            candidate=copy.deepcopy(planned)
            candidate["execution_boundary"]={
                "state":state,"host_journal_events":0,
                "operator_receipt_present":False,
            }
            with self.subTest(state=state),self.assertRaises(FCPError):
                retry_delivery(candidate,2)
        for evidence in (1,None,True):
            candidate=copy.deepcopy(planned)
            candidate["execution_boundary"]={
                "state":"TRANSIENT_TRANSPORT_DELIVERY_FAILED_BEFORE_HOST",
                "host_journal_events":evidence if isinstance(evidence,int) and not isinstance(evidence,bool) else 0,
                "operator_receipt_present":True if evidence is True else False,
            }
            if evidence is None:
                candidate["execution_boundary"]["host_journal_events"]=None
            with self.subTest(evidence=evidence),self.assertRaises(FCPError):
                retry_delivery(candidate,2)
        candidate=copy.deepcopy(planned)
        candidate["execution_boundary"]={
            "state":"TRANSIENT_TRANSPORT_DELIVERY_FAILED_BEFORE_HOST",
            "host_journal_events":0,
            "operator_receipt_present":False,
        }
        outcome=retry_delivery(candidate,2)
        self.assertEqual(outcome["idempotency_key"],planned["dispatch"]["idempotency_key"])
        self.assertEqual(outcome["child_operation_id"],planned["dispatch"]["child_operation_id"])
        self.assertNotEqual(outcome["delivery_id"],planned["dispatch"]["delivery_id"])

    def test_scope_policy_and_mode_denial(self):
        with self.assertRaises(FCPError):
            plan_child("parent-001",self.wayfa,"wpml.translation.audit",
                       self.scope,1,["en","es"],lambda _:False)
        with self.assertRaises(FCPError):
            plan_child("parent-001",self.wayfa,"wpml.translation.write",
                       self.scope,1,["en"],self.verify)
        local=Company("local","staging",
              "00000000-0000-4000-8000-000000000012",
              "https://local.example","local",("wpml.translation.audit",))
        with self.assertRaises(FCPError):
            plan_child("parent-001",local,"wpml.translation.audit",
                       self.scope,1,["en"],lambda _:True)
        with self.assertRaises(FCPError):
            plan_child("parent-001",self.wayfa,"wpml.translation.audit",
                       self.scope,1,["en","en"],self.verify)

    def test_receipt_exact_identity_and_unverified_without_native_stage(self):
        planned=plan_child("parent-001",self.wayfa,"wpml.translation.audit",
                           self.scope,1,["en","es"],self.verify)
        message=planned["dispatch"]
        result={"locales":["en","es"],"records":3}
        receipt={key:message[key] for key in
                 ("company_id","environment","site_uuid",
                  "parent_operation_id","child_operation_id")}
        receipt.update(broker_stage=313,evidence_state="Verified",
                       result_digest=sha(result),readback_digest=sha(result),
                       receipt_reference="313:sample")
        result_ok=verify_child_receipt(message,receipt,lambda *_:True)
        self.assertEqual(result_ok["evidence"],"Verified")
        self.assertTrue(result_ok["verified_receipt_check"])
        report=aggregate("parent-001",[result_ok])
        self.assertTrue(report["verified"])
        self.assertFalse(report["company_data_included"])
        stage=copy.deepcopy(receipt);stage["broker_stage"]=306
        self.assertEqual(verify_child_receipt(message,stage,lambda *_:True)["evidence"],
                         "Unverified")
        self.assertEqual(verify_child_receipt(message,receipt,lambda *_:False)["evidence"],
                         "Unverified")
        invalid=copy.deepcopy(receipt);invalid["site_uuid"]=self.are.site_uuid
        with self.assertRaises(FCPError):
            verify_child_receipt(message,invalid,lambda *_:True)

    def test_partial_fleet_and_fabricated_grade(self):
        result=aggregate("parent-002",[
            {"company_id":"are","environment":"staging",
             "child_operation_id":"child-are","status":"RUNNING",
             "evidence":"Observed"},
            {"company_id":"wayfatours","environment":"staging",
             "child_operation_id":"child-wayfa","status":"COMPLETED",
             "evidence":"Verified"}])
        self.assertFalse(result["complete"])
        self.assertFalse(result["verified"])
        fake=aggregate("parent-002",[
            {"company_id":"wayfatours","environment":"staging",
             "child_operation_id":"child-wayfa","status":"COMPLETED",
             "evidence":"Verified"}])
        self.assertFalse(fake["verified"])
        self.assertEqual(len(result["children"]),2)


if __name__=="__main__":
    unittest.main()

"""Redacted WordPress readback -> non-authorizing lifecycle preview."""
import copy
import importlib.util
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/"ops/native-source-publisher-v1/compact_job_readback_classifier.py"
sp=importlib.util.spec_from_file_location("mad4b_redacted_lifecycle_fixture",SCRIPT)
source=importlib.util.module_from_spec(sp)
sp.loader.exec_module(source)
NOW=1794490000

class RedactedLifecycle(unittest.TestCase):
 def setUp(self):
  self.digest="b"*64
  self.plan={
    "company":"tours-central","environment":"staging",
    "site_uuid":"12345678-1234-4123-8123-123456789abc",
    "origin":"https://tours.dev.mad4b.com",
  }
  self.record={
    "state":source.QUEUED,
    "job_expired":False,"job_executed":False,
    "claim_present":False,"claim_exact":False,"route_reserved":False
  }
  self.snapshot={
    "contract":source.READ_CONTRACT,
    "state":source.READ_STATE,
    "company":self.plan["company"],"environment":self.plan["environment"],
    "site_uuid":self.plan["site_uuid"],"origin":self.plan["origin"],
    "commands":[{"command_sha256":self.digest,
                 "job_count":1,"conflicting_scope_count":0,
                 "state":"HOLD__CURRENT_JOB_READ_ONLY",
                 "record":self.record}],
    "observed_epoch":NOW-4,
    "original312_eligible":False,
    "signed_original313_receipt_verified":False,
    "job_or_claim_mutated":False,
    "authority_created":False,
  }

 def run_preview(self,snapshot=None,now=NOW):
  return source.classify_redacted(
    self.plan,self.digest,
    self.snapshot if snapshot is None else snapshot,now_epoch=now
  )

 def assert_hold(self,state):
  v=self.run_preview()
  self.assertEqual(v["state"],state)
  for k in ("independent_readback_authenticity_verified",
            "original312_signed_eligibility_verified",
            "original313_independent_certification_verified",
            "association_issued","job_claimed_or_replayed",
            "execution_authorized"):
   self.assertFalse(v[k])
  self.assertNotIn("job_id",v)
  self.assertNotIn("grant_id",v)

 def test_queued_still_needs_signed_claim(self):
  self.assert_hold("HOLD__QUEUED_NOT_CLAIMED")

 def test_expired_queued_is_archived(self):
  self.record["job_expired"]=True
  self.assert_hold("HOLD__QUEUED_EXPIRED_ARCHIVE_ONLY")

 def test_queued_with_reservation_is_not_replayed(self):
  self.record["route_reserved"]=True
  self.assert_hold("HOLD__QUEUED_JOB_WITH_CLAIM_OR_RESERVATION")

 def test_claimed_current_needs_original312(self):
  self.record.update(state=source.CLAIMED,claim_present=True,
                     claim_exact=True,route_reserved=True)
  self.assert_hold("HOLD__CLAIMED_NEEDS_FRESH_ORIGINAL312")

 def test_claimed_expired_needs_receipt_reconciliation(self):
  self.record.update(state=source.CLAIMED,claim_present=True,
                     claim_exact=True,route_reserved=True,job_expired=True)
  self.assert_hold("HOLD__CLAIMED_EXPIRED_RECEIPT_RECONCILIATION_ONLY")

 def test_claimed_missing_correlation_denied(self):
  self.record.update(state=source.CLAIMED,claim_present=True,
                     claim_exact=False,route_reserved=True)
  self.assert_hold("HOLD__CLAIMED_BUT_CORRELATION_MISSING")

 def test_executed_uncertain_never_replayed(self):
  self.record["job_executed"]=True
  self.assert_hold("HOLD__JOB_ALREADY_EXECUTED_OR_RESULT_UNCERTAIN")

 def test_missing_expiry_is_unknown(self):
  self.record["job_expired"]=None
  self.assert_hold("HOLD__MISSING_JOB_EXPIRY")

 def test_no_job_is_hold(self):
  row=self.snapshot["commands"][0]
  row.update(job_count=0,state="HOLD__UNVERIFIED_OR_AMBIGUOUS",record=None)
  self.assert_hold("HOLD__NO_ORIGINAL_JOB_MATCH")

 def test_duplicate_jobs_are_hold(self):
  row=self.snapshot["commands"][0]
  row.update(job_count=2,state="HOLD__UNVERIFIED_OR_AMBIGUOUS",record=None)
  self.assert_hold("HOLD__MULTIPLE_JOBS_SHARE_COMMAND")

 def test_foreign_company_or_origin_collision(self):
  self.snapshot["commands"][0].update(
    job_count=0,conflicting_scope_count=1,
    state="HOLD__UNVERIFIED_OR_AMBIGUOUS",record=None
  )
  self.assert_hold("HOLD__COMMAND_SCOPE_COLLISION")

 def test_stale_or_future_observation_hold(self):
  self.snapshot["observed_epoch"]=NOW-121
  self.assert_hold("HOLD__STALE_OR_FUTURE_READBACK")
  self.snapshot["observed_epoch"]=NOW+11
  self.assert_hold("HOLD__STALE_OR_FUTURE_READBACK")

 def test_terminal_status_does_not_authorize(self):
  self.record["state"]="ORIGINAL_313_VERIFIED"
  self.assert_hold("HOLD__UNKNOWN_OR_TERMINAL_JOB_STATUS")

 def test_scope_changed_must_be_rejected(self):
  for field,bad in (
    ("company","foreign-company"),("environment","production"),
    ("site_uuid","00000000-0000-4000-8000-000000000000"),
    ("origin","https://foreign.invalid"),
  ):
   with self.subTest(field=field):
    d=copy.deepcopy(self.snapshot)
    d[field]=bad
    with self.assertRaises(source.RedactedReadbackDenied):
     self.run_preview(d)

 def test_caller_claim_of_new_authority_denied(self):
  for k in ("original312_eligible","signed_original313_receipt_verified",
            "job_or_claim_mutated","authority_created"):
   with self.subTest(field=k):
    d=copy.deepcopy(self.snapshot)
    d[k]=True
    with self.assertRaises(source.RedactedReadbackDenied):
     self.run_preview(d)

 def test_missing_or_duplicate_command_denied(self):
  d=copy.deepcopy(self.snapshot)
  d["commands"]=[]
  with self.assertRaises(source.RedactedReadbackDenied):
   self.run_preview(d)
  d=copy.deepcopy(self.snapshot)
  d["commands"]*=2
  with self.assertRaises(source.RedactedReadbackDenied):
   self.run_preview(d)

 def test_unexpected_secret_record_or_invalid_types_denied(self):
  d=copy.deepcopy(self.snapshot)
  d["commands"][0]["record"]["job_id"]="secret"
  with self.assertRaises(source.RedactedReadbackDenied):
   self.run_preview(d)
  for key in ("job_expired","job_executed","claim_exact"):
   with self.subTest(key=key):
    d=copy.deepcopy(self.snapshot)
    d["commands"][0]["record"][key]=1
    with self.assertRaises(source.RedactedReadbackDenied):
     self.run_preview(d)

 def test_disposition_distinguishes_readonly_reconcile_and_conflict(self):
  self.assertEqual(self.run_preview()["disposition"],"DESCRIBABLE_NOT_EXECUTABLE")
  self.record["job_executed"]=True
  self.assertEqual(self.run_preview()["disposition"],"RECONCILIATION_REQUIRED")
  self.record.update(job_executed=False,state=source.CLAIMED,claim_present=True,
                     claim_exact=False,route_reserved=True)
  self.assertEqual(self.run_preview()["disposition"],"CONFLICT_REJECTED")

 def batch_fixture(self):
  digests=[f"{i:064x}" for i in range(1,17)]
  snap=copy.deepcopy(self.snapshot)
  snap["commands"]=[dict(copy.deepcopy(snap["commands"][0]), command_sha256=digest)
                    for digest in digests]
  snap["job_store_scanned"]=16
  return digests,snap

 def test_all_sixteen_batch_readonly_descriptions(self):
  digests,snap=self.batch_fixture()
  result=source.classify_batch(self.plan,digests,snap,now_epoch=NOW)
  self.assertEqual(result["command_count"],16)
  self.assertEqual(result["disposition"],"DESCRIBABLE_NOT_EXECUTABLE")
  self.assertEqual(result["disposition_counts"]["DESCRIBABLE_NOT_EXECUTABLE"],16)
  self.assertEqual([x["command_sha256"] for x in result["commands"]],digests)
  for attr in ("execution_authorized","association_issued",
               "original312_signed_eligibility_verified","readback_transport_authenticated_by_classifier"):
   self.assertFalse(result[attr])

 def test_batch_mixed_requires_reconciliation_or_conflict_rejection(self):
  digests,snap=self.batch_fixture()
  snap["commands"][7]["record"]["job_expired"]=True
  result=source.classify_batch(self.plan,digests,snap,now_epoch=NOW)
  self.assertEqual(result["disposition"],"RECONCILIATION_REQUIRED")
  self.assertEqual(result["disposition_counts"]["RECONCILIATION_REQUIRED"],1)
  snap["commands"][8].update(job_count=2,state="HOLD__UNVERIFIED_OR_AMBIGUOUS",record=None)
  result=source.classify_batch(self.plan,digests,snap,now_epoch=NOW)
  self.assertEqual(result["disposition"],"CONFLICT_REJECTED")
  self.assertFalse(result["execution_authorized"])

 def test_batch_rejects_incomplete_duplicate_extra_and_unexpected_scope(self):
  digests,snap=self.batch_fixture()
  cases=[]
  missing=copy.deepcopy(snap);missing["commands"].pop();cases.append((digests,missing))
  duplicate=copy.deepcopy(snap);duplicate["commands"][1]["command_sha256"]=digests[0]
  cases.append((digests,duplicate))
  extra=copy.deepcopy(snap);extra["commands"].append(copy.deepcopy(extra["commands"][0]))
  cases.append((digests,extra))
  foreign=copy.deepcopy(snap);foreign["environment"]="production"
  cases.append((digests,foreign))
  bad_scan=copy.deepcopy(snap);bad_scan["job_store_scanned"]=1001
  cases.append((digests,bad_scan))
  bad_digest=copy.deepcopy(snap);bad_digest["commands"][0]["command_sha256"]=["bad"]
  cases.append((digests,bad_digest))
  secrets=copy.deepcopy(snap);secrets["commands"][0]["job_id"]="private"
  cases.append((digests,secrets))
  authority=copy.deepcopy(snap);authority["original312_eligible"]=True
  cases.append((digests,authority))
  for i,(expected,snapshot) in enumerate(cases):
   with self.subTest(case=i),self.assertRaises(source.RedactedReadbackDenied):
    source.classify_batch(self.plan,expected,snapshot,now_epoch=NOW)

 def test_source_has_no_issuer_or_executor(self):
  s=SCRIPT.read_text()
  for forbidden in ("Ed25519PrivateKey","get_option(","wp_remote_post(",
                    "execute_claimed(","issue_grant(","write_text("):
   self.assertNotIn(forbidden,s)
  self.assertNotIn("source.code.release",s)

if __name__=="__main__":
 unittest.main()

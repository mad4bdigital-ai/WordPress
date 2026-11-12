"""Only classify original Broker Job/Claim snapshots; never authorize writes."""
import importlib.util
from pathlib import Path
import unittest
from copy import deepcopy

ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/"ops/native-source-publisher-v1/original_job_lifecycle_preview.py"
sp=importlib.util.spec_from_file_location("mad4b_job_lifecycle_offline",SCRIPT)
module=importlib.util.module_from_spec(sp);sp.loader.exec_module(module)
NOW=1794490000

class JobLifecyclePreview(unittest.TestCase):
 def setUp(self):
  self.plan={
   "company":"tours-central","environment":"staging",
   "site_uuid":"12345678-1234-4123-8123-123456789abc",
   "origin":"https://tours.dev.mad4b.com"
  }
  self.digest="d"*64
  self.job={
   "job_id":"a"*32,
   "company":self.plan["company"],"environment":self.plan["environment"],
   "site_uuid":self.plan["site_uuid"],
   "canonical_origin":self.plan["origin"],
   "command_sha256":self.digest,
   "target_scope":"wordpress","broker_host_id":"srv2030908",
   "created_epoch":NOW-500,"expires_epoch":NOW+500,
   "state":module.QUEUED,"executed":False
  }
  self.claim={
   "contract":"mad4b.central-vps-broker.v1",
   "job_id":self.job["job_id"],"host_id":"srv2030908",
   "state":module.CLAIMED,"claimed_epoch":NOW-30,
   "production_mutation_performed":False
  }

 def check(self,jobs=None,claims=None,now=NOW):
  return module.classify(self.plan,self.digest,jobs if jobs is not None else [self.job],
                         claims if claims is not None else {},now_epoch=now)

 def ensure_holds(self,result):
  self.assertTrue(result["state"].startswith("HOLD__"))
  for field in ("execution_authorized","new_association_issued",
                "original312_signed_eligibility_verified",
                "original313_independent_certification_verified",
                "current_job_readback_authenticated_by_this_function",
                "job_claimed_or_replayed","existing_original_job_changed"):
   self.assertFalse(result[field])

 def test_empty_records_are_unknown(self):
  result=self.check(jobs=[])
  self.assertEqual(result["state"],"HOLD__NO_ORIGINAL_JOB_READBACK")
  self.ensure_holds(result)

 def test_queued_without_claim_does_not_authorize(self):
  result=self.check()
  self.assertEqual(result["state"],"HOLD__QUEUED__SIGNED_CLAIM_NOT_ISSUED")
  self.ensure_holds(result)

 def test_queued_expired_remains_historical(self):
  self.job["expires_epoch"]=NOW-1
  result=self.check()
  self.assertEqual(result["state"],"HOLD__QUEUED_EXPIRED__ARCHIVE_ONLY")
  self.ensure_holds(result)

 def test_queued_with_stale_claim_is_inconsistent(self):
  result=self.check(claims={self.job["job_id"]:self.claim})
  self.assertEqual(result["state"],"HOLD__QUEUED_BUT_PREVIOUS_CLAIM_OR_EXECUTION")

 def test_claimed_needs_fresh_original312(self):
  self.job["state"]=module.CLAIMED
  self.job["claimed_epoch"]=self.claim["claimed_epoch"]
  result=self.check(claims={self.job["job_id"]:self.claim})
  self.assertEqual(result["state"],"HOLD__CLAIMED__FRESH_ORIGINAL312_REQUIRED")
  self.ensure_holds(result)

 def test_claimed_expired_never_replays(self):
  self.job["state"]=module.CLAIMED
  self.job["claimed_epoch"]=self.claim["claimed_epoch"]
  self.job["expires_epoch"]=NOW-1
  result=self.check(claims={self.job["job_id"]:self.claim})
  self.assertEqual(result["state"],"HOLD__CLAIMED_EXPIRED__RECEIPT_ONLY_RECONCILE")
  self.ensure_holds(result)

 def test_claimed_uncertain_never_replays(self):
  self.job["state"]=module.CLAIMED
  self.job["claimed_epoch"]=self.claim["claimed_epoch"]
  self.job["executed"]=True
  result=self.check(claims={self.job["job_id"]:self.claim})
  self.assertEqual(result["state"],"HOLD__CLAIMED_EXECUTION_UNCERTAIN")
  self.ensure_holds(result)

 def test_missing_claim_or_mismatch_fails_closed(self):
  self.job["state"]=module.CLAIMED
  self.job["claimed_epoch"]=self.claim["claimed_epoch"]
  for override in ({},{self.job["job_id"]:dict(self.claim,host_id="untrusted")},
                   {self.job["job_id"]:dict(self.claim,claimed_epoch=NOW-10)},
                   {self.job["job_id"]:dict(self.claim,production_mutation_performed=True)}):
   with self.subTest(override=override):
    result=self.check(claims=override)
    self.assertEqual(result["state"],
                     "HOLD__ORIGINAL_SIGNED_CLAIM_RECORD_NOT_CORRELATED")

 def test_ambiguous_duplicate_original_job_not_attributed(self):
  extra=deepcopy(self.job)
  extra["job_id"]="b"*32
  result=self.check(jobs=[self.job,extra])
  self.assertEqual(result["state"],"HOLD__MULTIPLE_ORIGINAL_JOBS_SAME_COMMAND")
  self.ensure_holds(result)

 def test_command_scope_mismatch_blocked(self):
  extra=deepcopy(self.job)
  extra["site_uuid"]="00000000-0000-4000-8000-000000000000"
  result=self.check(jobs=[self.job,extra])
  self.assertEqual(result["state"],"HOLD__COMMAND_SCOPE_CONFLICT")
  self.ensure_holds(result)

 def test_no_matching_digest_does_not_assume_job(self):
  extra=deepcopy(self.job)
  extra["command_sha256"]="f"*64
  result=self.check(jobs=[extra])
  self.assertEqual(result["state"],"HOLD__NO_ORIGINAL_JOB_READBACK")

 def test_terminal_state_no_auto_migration(self):
  self.job["state"]="ORIGINAL_313_VERIFIED"
  result=self.check()
  self.assertEqual(result["state"],"HOLD__UNKNOWN_OR_TERMINAL_JOB_STATE__REVIEW")
  self.ensure_holds(result)

 def test_invalid_job_identity_is_hold(self):
  self.job["job_id"]="not-valid"
  result=self.check()
  self.assertEqual(result["state"],"HOLD__ORIGINAL_JOB_IDENTITY_INCOMPLETE")

 def test_input_validation_is_not_caller_authority(self):
  for sha in ("not-a-sha","a"*32):
   with self.assertRaises(module.LifecyclePreviewDenied):
    module.classify(self.plan,sha,[self.job],{},now_epoch=NOW)
  with self.assertRaises(module.LifecyclePreviewDenied):
   module.classify(self.plan,self.digest,[self.job],{},now_epoch=True)
  self.assertNotIn("original_312_verified",SCRIPT.read_text())
  self.assertNotIn("private_key",SCRIPT.read_text())
  self.assertNotIn("source.code.release",SCRIPT.read_text())

if __name__=="__main__":unittest.main()

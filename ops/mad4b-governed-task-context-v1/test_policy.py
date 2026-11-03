import unittest
from copy import deepcopy
import policy

BASE={
"job":{"job_id":"j1","site_uuid":"s1","tenant_id":"t1","brand_id":"b1","language":"en","market":"us","content_type":"article","revision":5,"stage":"INFORMATION_GAIN","state":"RUNNING"},
"task":{"stage":"INFORMATION_GAIN","operation":"stage_work","target_ref":"","bound_revision":5,"state":"WAITING_EXECUTOR","batch_state":"ACTIVE","attempts":0},
"input":{"semantic_sha256":"a"*64,"source_snapshot_sha256":"b"*64,"capability_sha256":"c"*64,"policy_snapshot_sha256":"d"*64,"output_contract":"keyword_research.v1","effect_type":"research_artifact"},
"provenance":{"authoritative_scope_verified":True,"origin_verified":True,"source_identity_verified":True,"source_version_sha256":"e"*64,"source_reference_sha256":"f"*64},
"dependencies":{"receipts_sha256":"9"*64,"all_predecessors_completed":True,"expected_artifact_contract":"resolver.v1"}}
CASES=[
("DIFFERENT_SCOPE","job","site_uuid","s2"),("DIFFERENT_SCOPE","job","tenant_id","t2"),
("DIFFERENT_SCOPE","job","brand_id","b2"),("DIFFERENT_SCOPE","job","language","ar"),
("DIFFERENT_SCOPE","job","market","eg"),("CROSS_JOB_REVIEW","job","job_id","j2"),
("DIFFERENT_WORK","task","target_ref","post:9"),("DIFFERENT_WORK","task","operation","publish"),
("STALE_JOB_REVISION","task","bound_revision",4),("STALE_JOB_REVISION","job","revision",6),
("HISTORICAL_RELATED","job","state","CANCELLED"),
("HISTORICAL_RELATED","task","state","CANCELLED"),
("HISTORICAL_RELATED","task","batch_state","CANCELLED"),
("NEW_SEMANTIC_ITERATION","input","source_snapshot_sha256","0"*64),
("NEW_SEMANTIC_ITERATION","input","policy_snapshot_sha256","0"*64),
("NEW_SEMANTIC_ITERATION","input","capability_sha256","0"*64),
("NEW_SEMANTIC_ITERATION","input","semantic_sha256","0"*64),
("NEW_SEMANTIC_ITERATION","input","effect_type","publish"),
("NEW_SEMANTIC_ITERATION","provenance","source_version_sha256","0"*64),
("NEW_SEMANTIC_ITERATION","dependencies","receipts_sha256","0"*64),
("NEEDS_CONTEXT","input","semantic_sha256",None),
("NEEDS_CONTEXT","input","semantic_sha256","bad"),
("NEEDS_CONTEXT","job","revision",None),
("NEEDS_CONTEXT","task","attempts",None),
("NEEDS_ORIGIN","provenance","origin_verified",False),
("NEEDS_ORIGIN","provenance","origin_verified",None),
("NEEDS_ORIGIN","provenance","source_version_sha256",None),
("NEEDS_DEPENDENCIES","dependencies","all_predecessors_completed",False),
("NEEDS_DEPENDENCIES","dependencies","receipts_sha256","bad"),
("EFFECT_RECONCILIATION","task","attempts",1),
("EFFECT_RECONCILIATION","task","state","NEEDS_RECONCILIATION"),
("EFFECT_RECONCILIATION","task","state","RUNNING"),
]
class PolicyTests(unittest.TestCase):
 def assert_safe(self, r, code):
  self.assertEqual(r["decision"],code)
  for x in ["safe_to_auto_merge","safe_to_cancel_task","executor_authorized","new_grant_issued"]:self.assertIs(r[x],False)
 def test_equal_semantic_with_different_trigger_never_auto_merges(self):
  a,b=deepcopy(BASE),deepcopy(BASE)
  a["provenance"]["trigger_source"]="event"
  b["provenance"]["trigger_source"]="manual"
  self.assert_safe(policy.compare(a,b),"SINGLE_FLIGHT_CANDIDATE")
 def test_scenarios(self):
  for expected,path,key,value in CASES:
   with self.subTest(path=path,key=key,value=value):
    a,b=deepcopy(BASE),deepcopy(BASE)
    b[path][key]=value
    self.assert_safe(policy.compare(a,b),expected)
 def test_high_impact_always_review(self):
  for stage in ["PRICING","PUBLISHING","CANONICAL_RELEASE","WPML_TRANSLATION","MEDIA_RIGHTS","COMPANY_DISTRIBUTION"]:
   with self.subTest(stage=stage):
    a,b=deepcopy(BASE),deepcopy(BASE)
    for x in [a,b]: x["task"]["stage"]=stage;x["job"]["stage"]=stage
    self.assert_safe(policy.compare(a,b),"HIGH_IMPACT_REVIEW")
 def test_missing_context_cannot_be_assumed(self):
  self.assert_safe(policy.compare({},BASE),"NEEDS_CONTEXT")
  self.assert_safe(policy.compare(BASE,None),"NEEDS_CONTEXT")
 def test_one_attempt_is_not_idempotent(self):
  a,b=deepcopy(BASE),deepcopy(BASE)
  b["task"]["attempts"]=1
  self.assert_safe(policy.compare(a,b),"EFFECT_RECONCILIATION")

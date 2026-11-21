import importlib.util,pathlib,sys,unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
spec=importlib.util.spec_from_file_location("enrollment_gate",ROOT/"original_313_enrollment_gate.py")
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
from test_original_313_contract import plan


class RegistrationGateTests(unittest.TestCase):
    def setUp(self):
        self.plan=plan()
        spec=m.code313.candidate_original_operation(self.plan)
        self.enrollment={
            "contract":m.CONTRACT,"state":"ENROLLED_ORIGINAL_FIRST_PARTY",
            "company":spec["company"],"environment":spec["environment"],
            "site_uuid":spec["site_uuid"],"origin":spec["origin"],
            "git_commit":spec["git_commit"],"plan_sha256":spec["plan_sha256"],
            "command_sha256":spec["command_sha256"],
            "operation":m.code313.OPERATION,
            "transport_operation":m.code313.TRANSPORT_OPERATION,
        }
        self.evidence={k:True for k in m.REQUIRED_EVIDENCE_KEYS}
    def test_source_only_not_enrolled(self):
        r=m.read_current_enrollment(self.plan)
        self.assertIsNone(r["eligible_worker_digest"])
        self.assertFalse(r["job_issued"])
        self.assertFalse(r["grant_issued"])
        self.assertFalse(r["original_313_certified"])
        self.assertEqual(r["state"],"HOLD_EXACT_ORIGINAL_OPERATION_ENROLLMENT")
    def test_self_reported_enrollment_evidence_cannot_install_worker_digest(self):
        r=m.project_candidate(self.plan,self.enrollment,self.evidence)
        self.assertEqual(r["candidate_worker_digest"],
                         self.enrollment["command_sha256"])
        self.assertIsNone(r["eligible_worker_digest"])
        self.assertEqual(r["state"],
                         "METADATA_READY_ORIGINAL_313_ENROLLMENT_NOT_PERFORMED")
        self.assertFalse(r["candidate_is_original_313_execution_handler"])
        self.assertFalse(r["original_313_certified"])
    def test_wrong_site_uuid_refused(self):
        self.enrollment["site_uuid"]="0"*36
        r=m.project_candidate(self.plan,self.enrollment,self.evidence)
        self.assertIsNone(r["eligible_worker_digest"])
    def test_wrong_plan_hash_refused(self):
        self.enrollment["plan_sha256"]="0"*64
        self.assertIsNone(m.project_candidate(self.plan,self.enrollment,self.evidence)["eligible_worker_digest"])
    def test_wrong_git_commit_refused(self):
        self.enrollment["git_commit"]="0"*40
        self.assertIsNone(m.project_candidate(self.plan,self.enrollment,self.evidence)["eligible_worker_digest"])
    def test_unregistered_independent_313_refused(self):
        self.evidence["original_313_independent_receipt_handler_enrolled"]=False
        out=m.project_candidate(self.plan,self.enrollment,self.evidence)
        self.assertIsNone(out["eligible_worker_digest"])
        self.assertIn("ORIGINAL_313_INDEPENDENT_RECEIPT_HANDLER_ENROLLED_MISSING",out["blocked_by"])
    def test_unregistered_original_handler_refused(self):
        self.evidence["original_313_release_handler_enrolled"]=False
        self.assertIsNone(m.project_candidate(self.plan,self.enrollment,self.evidence)["eligible_worker_digest"])
    def test_unregistered_dispatch_refused(self):
        self.evidence["company_release_dispatch_ability_registered"]=False
        self.assertIsNone(m.project_candidate(self.plan,self.enrollment,self.evidence)["eligible_worker_digest"])
    def test_unpinned_host_policy_refused(self):
        self.evidence["host_code_release_policy_pinned"]=False
        self.assertIsNone(m.project_candidate(self.plan,self.enrollment,self.evidence)["eligible_worker_digest"])
    def test_unenrolled_305_refused(self):
        self.evidence["original_305_company_verified"]=False
        self.assertIsNone(m.project_candidate(self.plan,self.enrollment,self.evidence)["eligible_worker_digest"])
    def test_not_enrolled_state_refused(self):
        self.enrollment["state"]="PROPOSED"
        self.assertIsNone(m.project_candidate(self.plan,self.enrollment,self.evidence)["eligible_worker_digest"])

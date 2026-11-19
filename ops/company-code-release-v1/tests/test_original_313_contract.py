"""Pure original 306/312/313 code-release admission tests; no WP writes."""
import importlib.util, pathlib, unittest, time, copy
ROOT=pathlib.Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location("original313_candidate",ROOT/"original_313_code_release_contract.py")
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)


def plan():
    p={
      "contract":"mad4b.company-code-release.plan.v1",
      "company":"wayfatours",
      "environment":"production",
      "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
      "origin":"https://wayfatours.com",
      "container":"php-example",
      "git_root":"/opt/example",
      "target_root":"/opt/example-site",
      "git_commit":"f"*40,
      "files":[{"source":"ops/foo.php","target":"wp-content/mu-plugins/foo.php",
                "source_sha256":"e"*64,"expected_before_sha256":None,
                "source_bytes":100,"source_mode":420}],
      "acceptance_checks":["wp_boot","copy_coverage_readiness"],
    }
    p["plan_sha256"]=m.sha256(m.canonical(p))
    p["changed_count"]=1
    return p


class Original313ContractTests(unittest.TestCase):
    def setUp(self):
        self.plan=plan()
        self.job_id="1"*32
        self.command=m.command_sha256(self.plan["plan_sha256"])
        self.job={"company":"wayfatours","environment":"production",
            "site_uuid":self.plan["site_uuid"],
            "operation":"company.remote.wpcli.full",
            "target_scope":"wordpress","command_format":"wpcli.argv.json.v1",
            "job_type":"signed_vps_wpcli",
            "command_sha256":self.command,
            "state":"QUEUED_FOR_VPS_BROKER_CLAIM"}
        self.pending={"company":"wayfatours","environment":"production",
            "site_uuid":self.plan["site_uuid"],
            "target_host_id":"srv2030908","broker_host_id":"srv2030908",
            "job_id":self.job_id,"expires_epoch":int(time.time())+420}
        self.authority={"contract":"mad4b.broker-exact-ssh-authority.v1",
            "verified":True,"eligible":True,"owner_enrolled_wpcli":True,
            "central_scope_only":True,"shell_executed":False,
            "job_id":self.job_id,"target_id":"srv2030908",
            "company":"wayfatours","environment":"production",
            "site_uuid":self.plan["site_uuid"],"origin":"https://wayfatours.com",
            "command_sha256":self.command,"operation":"company.remote.wpcli.full",
            "target_scope":"wordpress","expires_epoch":int(time.time())+800}

    def test_plan_command_hash_fixed_to_full_git_plan(self):
        candidate=m.candidate_original_operation(self.plan)
        self.assertEqual(candidate["command_sha256"], self.command)
        self.assertEqual(candidate["command_argv"][0:3],["mad4b","company-code-release","apply"])
        self.assertFalse(candidate["registrable"])
        self.assertFalse(candidate["write_authority_created"])
    def test_qa_environment_is_valid_for_exact_original_broker_plan(self):
        self.plan["environment"] = "qa"
        self.plan.pop("plan_sha256")
        self.plan.pop("changed_count")
        self.plan["plan_sha256"] = m.sha256(m.canonical(self.plan))
        self.plan["changed_count"] = 1
        candidate=m.candidate_original_operation(self.plan)
        self.assertEqual(candidate["environment"], "qa")
        self.assertFalse(candidate["registrable"])

    def test_malformed_environment_refused(self):
        self.plan["environment"] = "qa;command"
        self.plan.pop("plan_sha256")
        self.plan.pop("changed_count")
        self.plan["plan_sha256"] = m.sha256(m.canonical(self.plan))
        self.plan["changed_count"] = 1
        with self.assertRaisesRegex(m.GateDenied, "INVALID_COMPANY_ENVIRONMENT"):
            m.candidate_original_operation(self.plan)

    def test_plan_tamper_rejected(self):
        self.plan["company"]="other-company"
        with self.assertRaisesRegex(m.GateDenied,"RELEASE_PLAN_CHANGED"):
            m.candidate_original_operation(self.plan)
    def test_exact_original_306_job_can_match_without_claiming(self):
        self.assertIsNone(m.eligible_original_306_job(self.job,self.pending,self.plan))
        self.assertEqual(self.job["state"],"QUEUED_FOR_VPS_BROKER_CLAIM")
    def test_original_306_command_hash_change_rejected(self):
        self.job["command_sha256"]="0"*64
        with self.assertRaisesRegex(m.GateDenied,"COMMAND_HASH_MISMATCH"):
            m.eligible_original_306_job(self.job,self.pending,self.plan)
    def test_original_306_wrong_company_rejected(self):
        self.pending["company"]="another"
        with self.assertRaisesRegex(m.GateDenied,"IDENTITY_DRIFT"):
            m.eligible_original_306_job(self.job,self.pending,self.plan)
    def test_original_306_job_not_queued_rejected(self):
        self.job["state"]="DRAFT"
        with self.assertRaisesRegex(m.GateDenied,"NOT_QUEUED"):
            m.eligible_original_306_job(self.job,self.pending,self.plan)
    def test_original_306_expired_rejected(self):
        self.pending["expires_epoch"]=int(time.time())-1
        with self.assertRaisesRegex(m.GateDenied,"EXPIRED"):
            m.eligible_original_306_job(self.job,self.pending,self.plan)
    def test_exact_original_312_grant_verified(self):
        self.assertIsNone(m.eligible_original_312_claim(self.authority,self.plan,self.job_id))
    def test_original_312_wrong_site_rejected(self):
        self.authority["site_uuid"]="0"*36
        with self.assertRaisesRegex(m.GateDenied,"SCOPE_DRIFT"):
            m.eligible_original_312_claim(self.authority,self.plan,self.job_id)
    def test_original_312_other_operation_rejected(self):
        self.authority["operation"]="shell"
        with self.assertRaisesRegex(m.GateDenied,"SCOPE_DRIFT"):
            m.eligible_original_312_claim(self.authority,self.plan,self.job_id)
    def test_original_312_not_eligible_rejected(self):
        self.authority["eligible"]=False
        with self.assertRaisesRegex(m.GateDenied,"NOT_ELIGIBLE"):
            m.eligible_original_312_claim(self.authority,self.plan,self.job_id)
    def test_original_312_expired_rejected(self):
        self.authority["expires_epoch"]=int(time.time())+5
        with self.assertRaisesRegex(m.GateDenied,"WINDOW_TOO_SHORT"):
            m.eligible_original_312_claim(self.authority,self.plan,self.job_id)
    def test_original_313_independent_host_readback_only(self):
        receipt={"contract":"mad4b.company-code-release.receipt.v1",
                 "state":"APPLIED_VERIFIED","plan_sha256":self.plan["plan_sha256"],
                 "broker_grant_signature_verified":True,
                 "broker_receipt_verified":False,"original_313_receipt":False}
        expected={x["target"]:x["source_sha256"] for x in self.plan["files"]}
        evidence=m.independent_release_readback(self.plan,receipt,expected)
        self.assertEqual(evidence["state"],"HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313")
        self.assertFalse(evidence["original_313_certified"])
    def test_independent_313_readback_mismatch_rejected(self):
        receipt={"contract":"mad4b.company-code-release.receipt.v1",
                 "state":"APPLIED_VERIFIED","plan_sha256":self.plan["plan_sha256"],
                 "broker_grant_signature_verified":True,
                 "broker_receipt_verified":False,"original_313_receipt":False}
        with self.assertRaisesRegex(m.GateDenied,"READBACK_DRIFT"):
            m.independent_release_readback(self.plan,receipt,{"wrong":"digest"})
    def test_cannot_self_certify_313_receipt(self):
        receipt={"contract":"mad4b.company-code-release.receipt.v1",
                 "state":"APPLIED_VERIFIED","plan_sha256":self.plan["plan_sha256"],
                 "broker_grant_signature_verified":True,
                 "broker_receipt_verified":True,"original_313_receipt":True}
        with self.assertRaisesRegex(m.GateDenied,"RELEASE_RECEIPT_NOT_VERIFIED"):
            m.independent_release_readback(self.plan,receipt,{})

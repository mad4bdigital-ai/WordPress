import pathlib,sys,time,unittest,copy
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]))
import registry as r
import admission as a
import test_registry

class DynamicClaimTests(unittest.TestCase):
    def setUp(self):
        self.fx=test_registry.RegistryTests()
        self.fx.setUp()
        self.fx.manifest["plan_contract"]="mad4b.company-code-release.plan.v1"
        self.fx.write_manifest()
        self.reg=r.Registry(self.fx.root)
        self.plan={
            "contract":"mad4b.company-code-release.plan.v1",
            "company":"wayfatours","environment":"production",
            "site_uuid":"a3a7cc36-db7e-4a14-8456-b41857def05c",
            "origin":"https://wayfatours.com","files":[],
            "git_commit":"f"*40
        }
        self.plan["plan_sha256"]=r.sha(r.canonical(self.plan))
        self.job={
            "job_id":"c"*32,
            "company":self.plan["company"],"environment":self.plan["environment"],
            "site_uuid":self.plan["site_uuid"],"origin":self.plan["origin"],
            "operation":"company.remote.wpcli.full",
            "target_scope":"wordpress","job_type":"signed_vps_wpcli",
            "command_format":"wpcli.argv.json.v1",
            "command_sha256":self.reg.command_sha256("company.code.release",
                                                    self.plan["plan_sha256"]),
            "state":"BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
        }
        self.pending={
            "job_id":self.job["job_id"],
            "company":self.job["company"],
            "environment":self.job["environment"],
            "site_uuid":self.job["site_uuid"],
            "broker_host_id":"srv2030908",
            "target_host_id":"srv2030908",
            "expires_epoch":int(time.time())+700,
        }
        self.original={
            "contract":"mad4b.broker-exact-ssh-authority.v1",
            "verified":True,"eligible":True,"owner_enrolled_wpcli":True,
            "central_scope_only":True,"shell_executed":False,
            "job_id":self.job["job_id"],"target_id":"srv2030908",
            "operation":"company.remote.wpcli.full","target_scope":"wordpress",
            "command_sha256":self.job["command_sha256"],
            "company":self.plan["company"],"environment":self.plan["environment"],
            "site_uuid":self.plan["site_uuid"],"origin":self.plan["origin"],
            "expires_epoch":int(time.time())+700,
        }

    def tearDown(self): self.fx.tearDown()

    def candidate(self):
        return a.resolve_claimed(self.reg,"company.code.release",self.plan,
                                 self.job,self.pending)

    def test_dynamic_claim_selected_without_hardcoded_digest(self):
        x=self.candidate()
        self.assertEqual(x["state"],"CLAIM_METADATA_MATCHED_NEEDS_ORIGINAL_312")
        self.assertFalse(x["execution_authorized"])
        yes=a.verify_claimed_312(x,self.original)
        self.assertTrue(yes["original_312_verified"])
        self.assertFalse(yes["grant_issued"])
        self.assertFalse(yes["execution_authorized"])

    def test_another_plan_sha_is_another_admissible_command(self):
        self.plan["git_commit"]="e"*40
        self.plan["plan_sha256"]=r.sha(r.canonical({
            k:v for k,v in self.plan.items() if k!="plan_sha256"}))
        self.job["command_sha256"]=self.reg.command_sha256(
            "company.code.release",self.plan["plan_sha256"])
        x=self.candidate()
        self.assertEqual(x["plan_sha256"],self.plan["plan_sha256"])

    def test_queued_job_is_not_claimed(self):
        self.job["state"]="QUEUED_FOR_VPS_BROKER_CLAIM"
        with self.assertRaisesRegex(r.RegistryDenied,"NOT_CLAIMED"):
            self.candidate()

    def test_signature_scope_rejection(self):
        x=self.candidate()
        self.original["company"]="other"
        with self.assertRaisesRegex(r.RegistryDenied,"312_ADMISSION_DENIED"):
            a.verify_claimed_312(x,self.original)

    def test_312_expired_denied(self):
        x=self.candidate()
        self.original["expires_epoch"]=int(time.time())+10
        with self.assertRaisesRegex(r.RegistryDenied,"312_ADMISSION_DENIED"):
            a.verify_claimed_312(x,self.original)

    def test_306_scope_drift_rejected(self):
        self.pending["site_uuid"]="0"*36
        with self.assertRaisesRegex(r.RegistryDenied,"SCOPE_MISMATCH"):
            self.candidate()

    def test_306_command_drift_denied(self):
        self.job["command_sha256"]="0"*64
        with self.assertRaisesRegex(r.RegistryDenied,"NOT_CLAIMED"):
            self.candidate()

    def test_plan_digest_tamper_denied(self):
        self.plan["git_commit"]="0"*40
        with self.assertRaisesRegex(r.RegistryDenied,"PLAN_CONTENT_CHANGED"):
            self.candidate()

    def test_no_unenrolled_provider_route(self):
        with self.assertRaisesRegex(r.RegistryDenied,"NOT_ENROLLED"):
            a.resolve_claimed(self.reg,"untrusted.operation",self.plan,
                              self.job,self.pending)

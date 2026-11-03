#!/usr/bin/env python3
"""Hermetic remote owner delegation tests; no live WordPress writes."""
import copy
import unittest
import remote_delegation as d

SITE="aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa"
MANAGER="bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb"
PROOF="c"*64
COMPANY="egypt-tour-gates"

def source(company=COMPANY,site=SITE):
    return {"contract":"mad4b.full-host-authority-live-reconciliation.v1",
            "grants_created":False,"operations_executed":False,
            "production_mutated":False,
            "targets":[{"target":company+":staging","site_uuid":site,
                        "canonical_origin":"https://staging.egypttourgates.com",
                        "site_identity_registered":True}]}

class FakeOwnerRest:
    def __init__(self):
        self.env={"contract":"mad4b.environment-manager-assignment.v1",
                  "eligible":True,"company":COMPANY,"environment":"staging",
                  "site_uuid":SITE,"canonical_origin":"https://staging.egypttourgates.com",
                  "binding_sha256":PROOF,"revision":0,"assignment_state":"missing",
                  "proposal_sha256":None,"approved":False}
        self.pf={"contract":"mad4b.manager-company-assignment.v1",
                 "state":"not_assigned","revision":0}
        self.calls=[]
        self.allow=True
        self.tamper_env=False
        self.tamper_portfolio=False
        self.broker_fail=False
    def call(self,method,path,payload=None):
        self.calls.append((method,path,copy.deepcopy(payload)))
        if not self.allow and method=="POST":
            raise d.Refused("ORIGINAL_WP_ISSUER_DENIED")
        if path==d.env_path(d.Target(COMPANY,"staging",SITE,"https://staging.egypttourgates.com")):
            if method=="GET":
                return copy.deepcopy(self.env)
            action=payload["action"]
            before={"propose":"missing","approve":"proposed","activate":"approved"}[action]
            if self.env["assignment_state"]!=before or payload["expected_revision"]!=self.env["revision"] or payload["expected_binding_sha256"]!=PROOF:
                raise d.Refused("ORIGINAL_ENV_CAS_REJECTED")
            if action!="propose" and payload.get("expected_proposal_sha256")!=PROOF:
                raise d.Refused("ORIGINAL_ENV_PROPOSAL_REJECTED")
            self.env["revision"]+=1
            self.env["assignment_state"]={"propose":"proposed","approve":"approved","activate":"active"}[action]
            self.env["proposal_sha256"]=PROOF
            self.env["approved"]=action=="activate"
            res=copy.deepcopy(self.env)
            if self.tamper_env:self.env["revision"]+=1
            return res
        pathBase=d.portfolio_path(d.Target(COMPANY,"staging",SITE,"https://staging.egypttourgates.com"),MANAGER)
        if path in (pathBase,pathBase+"/transition"):
            if method=="GET":return copy.deepcopy(self.pf)
            action=("propose" if path==pathBase else payload["action"])
            prev={"propose":"not_assigned","approve":"proposed","activate":"approved"}[action]
            if self.pf["state"]!=prev or payload["expected_revision"]!=self.pf["revision"]:
                raise d.Refused("ORIGINAL_PORTFOLIO_CAS_REJECTED")
            if action!="propose" and payload["expected_proposal_sha256"]!=PROOF:
                raise d.Refused("ORIGINAL_PORTFOLIO_PROPOSAL_REJECTED")
            self.pf["state"]={"propose":"proposed","approve":"approved","activate":"active"}[action]
            self.pf["revision"]+=1
            self.pf["proposal_sha256"]=PROOF
            self.pf["company_site_uuid"]=SITE
            self.pf["binding_current"]=True
            self.pf["rights"]=["view_company","operations_manage"]
            if self.tamper_portfolio:self.pf["company_site_uuid"]="dddddddd-dddd-dddd-dddd-dddddddddddd"
            return copy.deepcopy(self.pf)
        raise d.Refused("UNEXPECTED_ROUTE")

class Tests(unittest.TestCase):
    def setUp(self):
        self.rest=FakeOwnerRest()
        self.broker_calls=[]
        self.broker_state="GRANT_ISSUED_CONFIRMED"
        self.grant_registered=True
        self.active=False
        self.orch=d.Orchestrator(self.rest,self.broker)
        self.src=source()
    def broker(self,action,t):
        self.broker_calls.append((action,t.company,t.site_uuid))
        return {"contract":"mad4b.remote-host-305-readback.v1",
                "target":t.company+":staging",
                "state":self.broker_state,"grant_registered":self.grant_registered,
                "grant_issued":self.broker_state=="GRANT_ISSUED_CONFIRMED",
                "grant_active":self.active,
                "blockers":["ORIGINAL_SCOPE_PENDING"]}
    def run_workflow(self,apply=True, reason="Original owner authorized company operations for Staging only"):
        return self.orch.reconcile(self.src,COMPANY,MANAGER,reason,apply)

    def test_full_remote_lifecycle_original_endpoints(self):
        value=self.run_workflow()
        self.assertEqual(value["state"],"GRANT_ISSUED_CONFIRMED")
        self.assertTrue(value["grant_registered"])
        self.assertTrue(value["grant_issued"])
        self.assertFalse(value["grant_active"])
        self.assertFalse(value["production_mutated"])
        self.assertEqual(len(self.rest.calls),14)
        self.assertEqual(self.broker_calls,[("reconcile305",COMPANY,SITE)])
        self.assertEqual(self.rest.env["revision"],3)
        self.assertEqual(self.rest.pf["revision"],3)

    def test_idempotent_reconcile_already_active(self):
        self.run_workflow()
        self.broker_state="GRANT_EXISTING_CONFIRMED"
        before=len(self.rest.calls)
        value=self.run_workflow()
        self.assertFalse(value["grant_issued"])
        self.assertTrue(value["grant_registered"])
        self.assertEqual(len(self.rest.calls),before+2)

    def test_planning_is_read_only(self):
        r=self.run_workflow(apply=False)
        self.assertEqual(r["state"],"PLAN_ONLY")
        self.assertEqual([a[0] for a in self.rest.calls],["GET","GET"])
        self.assertEqual(self.broker_calls,[])
        self.assertFalse(r["grant_issued"])

    def test_missing_auth_never_calls_broker(self):
        self.rest.allow=False
        with self.assertRaisesRegex(d.Refused,"ORIGINAL_WP_ISSUER_DENIED"):
            self.run_workflow()
        self.assertEqual(self.broker_calls,[])

    def test_environment_cas_drift_stops_dispatch(self):
        self.rest.tamper_env=True
        with self.assertRaisesRegex(d.Refused,"ORIGINAL_ENV_INDEPENDENT_READBACK_FAILED"):
            self.run_workflow()
        self.assertEqual(self.broker_calls,[])

    def test_portfolio_tamper_stops_dispatch(self):
        self.rest.tamper_portfolio=True
        with self.assertRaisesRegex(d.Refused,"ORIGINAL_MANAGER_BINDING_CHANGED"):
            self.run_workflow()
        self.assertEqual(self.broker_calls,[])

    def test_site_identity_mismatch_stops(self):
        self.src["targets"][0]["site_uuid"]="bad"
        with self.assertRaisesRegex(d.Refused,"SITE_UUID_REQUIRED"):
            self.run_workflow()
        self.assertEqual(self.rest.calls,[])

    def test_company_mismatch_blocks(self):
        with self.assertRaisesRegex(d.Refused,"EXACT_ORIGINAL_SITE_IDENTITY_REQUIRED"):
            self.orch.reconcile(self.src,"all-royal-egypt",MANAGER,"reason",True)
        self.assertEqual(self.rest.calls,[])

    def test_original_source_cannot_be_mutating(self):
        self.src["production_mutated"]=True
        with self.assertRaisesRegex(d.Refused,"ORIGINAL_SOURCE_UNTRUSTED"):
            self.run_workflow()

    def test_cross_tenant_duplicate_rejected(self):
        self.src["targets"].append(copy.deepcopy(self.src["targets"][0]))
        with self.assertRaisesRegex(d.Refused,"EXACT_ORIGINAL_SITE_IDENTITY_REQUIRED"):
            self.run_workflow()

    def test_invalid_manager_id_rejected(self):
        with self.assertRaisesRegex(d.Refused,"MANAGER_UUID_REQUIRED"):
            self.orch.reconcile(self.src,COMPANY,"not-a-manager","full reason valid",True)

    def test_invalid_owner_reason_fails_prior_portfolio(self):
        with self.assertRaisesRegex(d.Refused,"ORIGINAL_OWNER_AUDIT_REASON_REQUIRED"):
            self.run_workflow(reason="x")
        self.assertEqual(self.broker_calls,[])

    def test_broker_result_scope_must_match(self):
        def wrong(*args):
            return {"contract":"mad4b.remote-host-305-readback.v1","target":"other:staging","state":"GRANT_ISSUED_CONFIRMED","grant_registered":True}
        self.orch=d.Orchestrator(self.rest,wrong)
        with self.assertRaisesRegex(d.Refused,"BROKER_RECEIPT_UNTRUSTED"):
            self.run_workflow()

    def test_broker_blocked_is_not_issued(self):
        self.broker_state="BLOCKED"
        out=self.run_workflow()
        self.assertEqual(out["state"],"BROKER_BLOCKED")
        self.assertFalse(out["grant_issued"])
        self.assertEqual(out["blockers"],["ORIGINAL_SCOPE_PENDING"])

    def test_broker_unverified_grant_is_rejected(self):
        self.grant_registered=False
        with self.assertRaisesRegex(d.Refused,"ORIGINAL_305_PERSISTENCE_UNVERIFIED"):
            self.run_workflow()

    def test_active_grant_requires_broker_evidence(self):
        self.active=True
        r=self.run_workflow()
        self.assertTrue(r["grant_active"])

    def test_no_owner_headers_rejected(self):
        with self.assertRaisesRegex(d.Refused,"ORIGINAL_OWNER_SESSION_REQUIRED"):
            d.OriginalOwnerRest({"Accept":"application/json"})

    def test_owner_cookie_nonce_accepted(self):
        c=d.OriginalOwnerRest({"Cookie":"wordpress_logged_in=test","X-WP-Nonce":"opaque"})
        self.assertIn("Cookie",c.headers)

    def test_oauth_bearer_accepted_as_transport_only(self):
        c=d.OriginalOwnerRest({"Authorization":"Bearer opaque"})
        self.assertIn("Authorization",c.headers)

    def test_extra_authority_header_rejected(self):
        with self.assertRaisesRegex(d.Refused,"ORIGINAL_AUTH_HEADER_REJECTED"):
            d.OriginalOwnerRest({"Authorization":"Bearer opaque","X-Act-As-Owner":"true"})

    def test_redirecting_rest_url_rejected(self):
        auth=d.OriginalOwnerRest({"Authorization":"Bearer opaque"})
        with self.assertRaisesRegex(d.Refused,"ORIGINAL_WP_REST_PATH_INVALID"):
            auth.call("POST","/wp-json/../../../evil",{})

    def test_production_target_refused(self):
        with self.assertRaisesRegex(d.Refused,"EXACT_STAGING_COMPANY_REQUIRED"):
            d.Target(COMPANY,"production",SITE,"https://egypttourgates.com")

    def test_origin_userinfo_refused(self):
        with self.assertRaisesRegex(d.Refused,"CANONICAL_ORIGIN_INVALID"):
            d.Target(COMPANY,"staging",SITE,"https://admin:pwd@staging.egypttourgates.com")
if __name__=="__main__":unittest.main()


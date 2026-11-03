#!/usr/bin/env python3
"""MAD4B remote delegation: original owner REST lifecycle -> signed 305 Broker.

This is an adapter, not an owner/manager issuer. The original WordPress
REST permission callback approves all writes; SSH keys alone never approve.
"""
from __future__ import annotations
import dataclasses
import json
import re
import ssl
from urllib import request, error
from urllib.parse import urlsplit

CONTRACT = "mad4b.remote-company-delegation-orchestrator.v1"
ORIGIN = "https://tours.dev.mad4b.com"
COMPANY = re.compile(r"[a-z][a-z0-9-]{2,47}\Z")
UUID = re.compile(r"[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\Z")
SHA = re.compile(r"[a-f0-9]{64}\Z")

class Refused(RuntimeError):
    pass

@dataclasses.dataclass(frozen=True)
class Target:
    company: str
    environment: str
    site_uuid: str
    canonical_origin: str
    def __post_init__(self):
        if not isinstance(self.company,str) or not COMPANY.fullmatch(self.company) or self.environment!="staging":
            raise Refused("EXACT_STAGING_COMPANY_REQUIRED")
        if not isinstance(self.site_uuid,str) or not UUID.fullmatch(self.site_uuid):
            raise Refused("SITE_UUID_REQUIRED")
        if not isinstance(self.canonical_origin,str) or not self.canonical_origin.startswith("https://"):
            raise Refused("CANONICAL_ORIGIN_REQUIRED")
        p=urlsplit(self.canonical_origin)
        if not p.hostname or p.username or p.password or p.path not in ("","/") or p.query or p.fragment:
            raise Refused("CANONICAL_ORIGIN_INVALID")

def from_source(report:dict, company:str)->Target:
    if report.get("contract")!="mad4b.full-host-authority-live-reconciliation.v1" or any(
        report.get(k) is not False for k in ("grants_created","operations_executed","production_mutated")
    ):
        raise Refused("ORIGINAL_SOURCE_UNTRUSTED")
    if not isinstance(company,str) or not COMPANY.fullmatch(company):
        raise Refused("COMPANY_INVALID")
    rows=[r for r in report.get("targets",[]) if isinstance(r,dict) and r.get("target")==company+":staging"]
    if len(rows)!=1 or rows[0].get("site_identity_registered") is not True:
        raise Refused("EXACT_ORIGINAL_SITE_IDENTITY_REQUIRED")
    s=rows[0]
    return Target(company,"staging",s.get("site_uuid"),s.get("canonical_origin"))

def check_env(v:dict,t:Target)->dict:
    if v.get("contract")!="mad4b.environment-manager-assignment.v1" or v.get("eligible") is not True:
        raise Refused("ORIGINAL_ENV_ISSUER_NOT_ELIGIBLE")
    if any((v.get(k)!=expect for k,expect in (("company",t.company),("environment","staging"),("site_uuid",t.site_uuid),("canonical_origin",t.canonical_origin)))):
        raise Refused("ORIGINAL_ENV_SITE_BINDING_MISMATCH")
    if not isinstance(v.get("binding_sha256"),str) or not SHA.fullmatch(v["binding_sha256"]):
        raise Refused("ORIGINAL_ENV_BINDING_SHA_INVALID")
    if type(v.get("revision")) is not int or v["revision"]<0:
        raise Refused("ORIGINAL_ENV_REVISION_REQUIRED")
    return v

def check_portfolio(v:dict,t:Target,active=False)->dict:
    if v.get("contract")!="mad4b.manager-company-assignment.v1" or type(v.get("revision")) is not int:
        raise Refused("ORIGINAL_MANAGER_ASSIGNMENT_INVALID")
    if v.get("state") not in ("not_assigned","proposed","approved","active"):
        raise Refused("ORIGINAL_MANAGER_ASSIGNMENT_BLOCKED")
    if v["state"]!="not_assigned" and (v.get("company_site_uuid")!=t.site_uuid or v.get("binding_current") is not True):
        raise Refused("ORIGINAL_MANAGER_BINDING_CHANGED")
    if active and (v["state"]!="active" or "operations_manage" not in v.get("rights",[])):
        raise Refused("ORIGINAL_MANAGER_OPERATION_RIGHT_NOT_ACTIVE")
    return v

class OriginalOwnerRest:
    """Authenticated REST session supplied in memory by the owner-connected app."""
    def __init__(self, headers:dict):
        lower={k.lower():v for k,v in headers.items()}
        if not ((lower.get("cookie") and lower.get("x-wp-nonce")) or
                str(lower.get("authorization","")).startswith("Bearer ")):
            raise Refused("ORIGINAL_OWNER_SESSION_REQUIRED")
        if any(x not in ("cookie","x-wp-nonce","authorization","accept") for x in lower):
            raise Refused("ORIGINAL_AUTH_HEADER_REJECTED")
        self.headers=dict(headers)
    def call(self, method:str,path:str,payload:dict|None=None)->dict:
        if method not in ("GET","POST") or not path.startswith("/wp-json/") or ".." in path or "://" in path or "//" in path:
            raise Refused("ORIGINAL_WP_REST_PATH_INVALID")
        data=None if payload is None else json.dumps(payload,sort_keys=True,separators=(",",":")).encode()
        if len(data or b"")>4096:
            raise Refused("ORIGINAL_WP_REST_REQUEST_TOO_LARGE")
        headers={"Accept":"application/json",**self.headers}
        if data is not None:headers["Content-Type"]="application/json"
        req=request.Request(ORIGIN+path,headers=headers,data=data,method=method)
        class RejectRedirect(request.HTTPRedirectHandler):
            def redirect_request(self,*args,**kwargs):
                raise Refused("ORIGINAL_WP_REDIRECT_DENIED")
        opener=request.build_opener(request.HTTPSHandler(context=ssl.create_default_context()),RejectRedirect())
        try:
            with opener.open(req,timeout=18) as res:body=res.read(32769)
        except (error.HTTPError,error.URLError) as exc:
            raise Refused("ORIGINAL_WP_ISSUER_DENIED") from exc
        if len(body)>32768:raise Refused("ORIGINAL_WP_RESPONSE_TOO_LARGE")
        try:v=json.loads(body)
        except ValueError as exc:raise Refused("ORIGINAL_WP_RESPONSE_INVALID") from exc
        if not isinstance(v,dict) or ("code" in v and "message" in v):
            raise Refused("ORIGINAL_WP_DENIED")
        return v

def env_path(t:Target)->str:
    return "/wp-json/mad4b-central/v1/company-environments/"+t.company+"/staging/manager-assignment"

def portfolio_path(t:Target,manager:str)->str:
    if not UUID.fullmatch(manager):raise Refused("MANAGER_UUID_REQUIRED")
    return "/wp-json/mad4b/v1/company-managers/"+manager+"/companies/"+t.company+"/delegation"

class Orchestrator:
    def __init__(self,rest,broker):
        self.rest=rest
        self.broker=broker

    def environment(self,t:Target,apply=False):
        path=env_path(t)
        current=check_env(self.rest.call("GET",path),t)
        if not apply:return current
        for action,before,after in (("propose","missing","proposed"),("approve","proposed","approved"),("activate","approved","active")):
            if current.get("assignment_state")!=before:continue
            payload={"action":action,"expected_revision":current["revision"],"expected_binding_sha256":current["binding_sha256"]}
            if action!="propose":
                proof=current.get("proposal_sha256")
                if not isinstance(proof,str) or not SHA.fullmatch(proof):raise Refused("ORIGINAL_ENV_PROPOSAL_REQUIRED")
                payload["expected_proposal_sha256"]=proof
            written=self.rest.call("POST",path,payload)
            nextrow=check_env(self.rest.call("GET",path),t)
            if nextrow.get("revision")!=current["revision"]+1 or nextrow.get("assignment_state")!=after or written.get("revision")!=nextrow["revision"]:
                raise Refused("ORIGINAL_ENV_INDEPENDENT_READBACK_FAILED")
            current=nextrow
        if current.get("assignment_state")!="active" or current.get("approved") is not True:
            raise Refused("ORIGINAL_ENV_OWNER_AUTHORIZATION_PENDING")
        return current

    def portfolio(self,t:Target,manager:str,reason:str,apply=False):
        path=portfolio_path(t,manager)
        current=check_portfolio(self.rest.call("GET",path),t)
        if not apply:return current
        if not isinstance(reason,str) or not 12<=len(reason)<=1200:
            raise Refused("ORIGINAL_OWNER_AUDIT_REASON_REQUIRED")
        if current["state"]=="not_assigned":
            self.rest.call("POST",path,{"rights":["view_company","operations_manage"],"reason":reason,"expected_revision":0})
            newer=check_portfolio(self.rest.call("GET",path),t)
            if newer["state"]!="proposed" or newer["revision"]!=1:
                raise Refused("ORIGINAL_PORTFOLIO_PROPOSAL_READBACK_FAILED")
            current=newer
        for action,before,after in (("approve","proposed","approved"),("activate","approved","active")):
            if current["state"]!=before:continue
            proof=current.get("proposal_sha256")
            if not isinstance(proof,str) or not SHA.fullmatch(proof):
                raise Refused("ORIGINAL_PORTFOLIO_PROPOSAL_HASH_REQUIRED")
            self.rest.call("POST",path+"/transition",{"action":action,"expected_revision":current["revision"],"expected_proposal_sha256":proof})
            newer=check_portfolio(self.rest.call("GET",path),t)
            if newer["state"]!=after or newer["revision"]!=current["revision"]+1:
                raise Refused("ORIGINAL_PORTFOLIO_INDEPENDENT_READBACK_FAILED")
            current=newer
        return check_portfolio(current,t,active=True)

    def reconcile(self,report:dict,company:str,manager:str,reason:str,apply=False):
        t=from_source(report,company)
        env=self.environment(t,apply)
        assigned=self.portfolio(t,manager,reason,apply)
        result={"contract":CONTRACT,"target":company+":staging","site_uuid":t.site_uuid,
                "environment_assignment":env.get("assignment_state"),
                "portfolio_assignment":assigned.get("state"),
                "owner_identity_synthesized":False,"grant_issued":False,
                "grant_registered":False,"grant_active":False,"production_mutated":False}
        if not apply:return {**result,"state":"PLAN_ONLY"}
        if env.get("assignment_state")!="active" or assigned.get("state")!="active":
            raise Refused("ORIGINAL_OWNER_DELEGATION_NOT_ACTIVE")
        receipt=self.broker("reconcile305",t)
        if not isinstance(receipt,dict) or receipt.get("target")!=company+":staging" or receipt.get("contract")!="mad4b.remote-host-305-readback.v1":
            raise Refused("BROKER_RECEIPT_UNTRUSTED")
        if receipt.get("state") not in ("GRANT_ISSUED_CONFIRMED","GRANT_EXISTING_CONFIRMED"):
            return {**result,"state":"BROKER_BLOCKED","blockers":receipt.get("blockers",["ORIGINAL_305_PENDING"])}
        if receipt.get("grant_registered") is not True:raise Refused("ORIGINAL_305_PERSISTENCE_UNVERIFIED")
        return {**result,"state":receipt["state"],"grant_issued":receipt.get("grant_issued") is True,
                "grant_registered":True,"grant_active":receipt.get("grant_active") is True}


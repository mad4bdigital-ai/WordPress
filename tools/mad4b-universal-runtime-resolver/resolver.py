#!/usr/bin/env python3
"""MAD4B Universal Runtime Resolver: persistent, governed convergence kernel."""
import argparse
import datetime as dt
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess
import sys
import time
import uuid

HOME=Path("/opt/mad4b-company-transport/universal-runtime-v1")
DB=HOME/"runtime.sqlite3"
LOCK=HOME/"resolver.lock"
POLICY=HOME/"policy.json"
INBOX=HOME/"inbox"
CONTRACT="mad4b.universal-runtime-reconciler.v1"
EVENTS={"intake","optin","handoff","provider_change","package_registered",
        "site_identity","permission_change","workflow_failed","manual","scheduled","dispatch"}
DOMAINS=("transport","identity","authority","full_authority","agent","package","workflow")
DEPENDS={"transport":(),"identity":("transport",),"authority":("transport",),
         "full_authority":("identity",),
         "agent":("transport","identity"),"package":("authority",),
         "workflow":("agent",)}
TRANSIENT={"timeout","unavailable","connection","busy","temporary","network"}
SITE="all-royal-egypt:staging"
ROOT=Path("/opt/mad4b-company-transport")

def utc():
    return dt.datetime.now(dt.timezone.utc).isoformat()
def safe_json(value):
    return json.dumps(value,sort_keys=True,separators=(",",":"),ensure_ascii=False)
def command(argv,timeout=35):
    try:
        r=subprocess.run(argv,capture_output=True,text=True,timeout=timeout,check=False)
        return (r.returncode, r.stdout[:16000],r.stderr[-800:])
    except (OSError,subprocess.TimeoutExpired):
        return (75,"","command_unavailable_or_timeout")
def inventory():
    d=json.loads((ROOT/"environment-inventory-v1.json").read_text())
    if d.get("contract")!="mad4b.company-transport-inventory.v1":
        raise ValueError("inventory_contract_invalid")
    return d["targets"]
def policy():
    p=json.loads(POLICY.read_text())
    if p.get("contract")!=CONTRACT or not p.get("enabled") or p.get("default_production_mutation") is not False:
        raise ValueError("policy_invalid")
    return p
def db_open(path=DB):
    con=sqlite3.connect(path,timeout=8)
    con.row_factory=sqlite3.Row
    con.execute("PRAGMA journal_mode=WAL")
    con.execute("PRAGMA busy_timeout=8000")
    con.executescript("""
    CREATE TABLE IF NOT EXISTS events(
      event_id TEXT PRIMARY KEY, idem TEXT UNIQUE NOT NULL, target TEXT NOT NULL,
      trigger TEXT NOT NULL, source TEXT NOT NULL, state TEXT NOT NULL,
      at TEXT NOT NULL, processed_at TEXT);
    CREATE TABLE IF NOT EXISTS observations(
      target TEXT NOT NULL, domain TEXT NOT NULL, state TEXT NOT NULL,
      provider TEXT NOT NULL, reason TEXT NOT NULL, evidence_sha256 TEXT NOT NULL,
      evidence_json TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
      at TEXT NOT NULL, PRIMARY KEY(target,domain));
    CREATE TABLE IF NOT EXISTS receipts(
      receipt_id TEXT PRIMARY KEY, event_id TEXT NOT NULL, target TEXT NOT NULL,
      domain TEXT NOT NULL, previous_state TEXT NOT NULL, state TEXT NOT NULL,
      evidence_sha256 TEXT NOT NULL, at TEXT NOT NULL, production_mutation INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS reviews(
      target TEXT NOT NULL, domain TEXT NOT NULL, reason TEXT NOT NULL,
      state TEXT NOT NULL DEFAULT 'open', at TEXT NOT NULL,
      PRIMARY KEY(target,domain,reason));
    """)
    return con

def result(state,provider,reason="",evidence=None):
    return {"state":state,"provider":provider,"reason":reason,
            "evidence":evidence if isinstance(evidence,dict) else {}}

def inspect_transport(target,scope):
    binding=scope.get("transport_binding")
    if not isinstance(binding,str) or not re.fullmatch(r"[a-z0-9_-]{2,64}:staging",binding):
        return result("awaiting_provider","registry_ssh","site_transport_not_enrolled")
    binary=ROOT/"registry-remote-v1/registry_remote.py"
    if not binary.is_file():
        return result("blocked_dependency","registry_ssh","registry_remote_module_missing")
    rc,out,_=command(["/usr/bin/python3",str(binary),"probe","--target",binding])
    try:d=json.loads(out)
    except ValueError:d={}
    if rc==0 and d.get("state")=="SSH_AUTHENTICATED" and d.get("root")==scope.get("expected_root"):
        return result("verified","registry_ssh","verified_ssh_binding",
          {"site_uuid":scope["site_uuid"],"authority_source":"enrolled_registry_identity",
           "granted_operations":scope["operations"],"remote_root":d["root"]})
    return result("retry" if rc==75 else "blocked_dependency","registry_ssh",
                  d.get("state","ssh_unavailable"))

def inspect_identity(target,scope):
    if scope.get("identity_adapter")!="central_scoped_v4":
        return result("awaiting_provider","wpcli","site_identity_adapter_not_enrolled")
    args=["docker","exec","-u","www-data","etg-commerce-staging-php","wp",
          "mad4b-v4-scope","verify",target,"--user=1",
          "--path=/var/www/html","--skip-themes"]
    rc,out,_=command(args,timeout=40)
    try:d=json.loads(out)
    except ValueError:d={}
    if rc==0 and d.get("state")=="SCOPED_SIGNED_SITE_PROFILE_BOUND" and \
        d.get("site_uuid")==scope["site_uuid"] and \
        d.get("environment")=="staging" and d.get("mutual_signatures_verified") is True and \
        d.get("production_mutation") is False:
        return result("verified","signed_profile_v4","mutual_p256_signature_verified",
          {"site_uuid":d["site_uuid"],"profile_digest":d["profile_digest"],
           "identity_scope":"company_identity.read","remote_write_authorized_by_identity":False})
    return result("retry" if "unavailable" in out.lower() else "review_required",
                  "signed_profile_v4","signed_identity_inconsistent_or_unavailable",
                  {"exit_code":rc})

def inspect_authority(target,scope):
    # Identity alone never issues a grant. Authoritative transport enrollment
    # plus exact site-scoped operation policy can already permit an operation.
    ops=scope.get("operations",[])
    allowed={"remote_exec:site_root","remote_exec:file_sha256",
             "remote_file_transfer:verified_zip"}
    if not isinstance(ops,list) or not set(ops).issubset(allowed) or not ops:
        return result("review_required","capability_policy","operation_policy_conflict")
    return result("verified","capability_policy","existing_scoped_authority_resolved",
      {"operations":sorted(ops),"source":"registered_ssh_plus_exact_scope",
       "global_shell_granted":False,"unrestricted_sql_granted":False,
       "p256_grants_write":False})

def inspect_full_authority(target,scope):
    """Read status from the TARGET's native owner, never a central Hub plugin."""
    provider=scope.get("full_authority_provider")
    current=inventory().get(target,{})
    if not isinstance(provider,dict) or provider.get("mode")!="native_target_owner":
        return result("awaiting_provider","unified_full_authority",
                      "target_native_owner_provider_not_enrolled")
    if provider.get("target")!=target or provider.get("environment")!=current.get("environment") or provider.get("origin")!=scope.get("canonical_origin"):
        return result("review_required","unified_full_authority",
                      "target_native_owner_binding_mismatch")
    return result("awaiting_provider","unified_full_authority",
                  "target_native_owner_readback_adapter_not_connected")

def inspect_agent(target,scope):
    # A source-specific adapter is not generalized by merely copying its name.
    if scope.get("agent_adapter")!="are_signed_v4" or target!=SITE:
        return result("awaiting_provider","signed_v4","site_agent_not_enrolled")
    path=ROOT/"native_v4_readback.py"
    if not path.is_file():
        return result("blocked_dependency","signed_v4","agent_readback_adapter_missing")
    spec=importlib.util.spec_from_file_location("mad4b_native_v4_readback",path)
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    data=module.probe()
    if data.get("ready") is True and data.get("site_uuid")==scope["site_uuid"] and \
        data.get("state")=="SIGNED_V4_ALREADY_INSTALLED_AND_VERIFIED" and \
        data.get("external_write_performed") is False:
        return result("verified","signed_v4","agent_existing_component_verified",
          {"module_sha256":data["module_sha256"],"site_uuid":data["site_uuid"],
           "route_present":data["route_present"]})
    return result("blocked_dependency","signed_v4",data.get("state","agent_readback_failed"))

def inspect_package(target,scope):
    pkg=scope.get("package")
    binding=scope.get("transport_binding")
    if not isinstance(pkg,dict) or not isinstance(binding,str):
        return result("awaiting_provider","registry_transport","package_target_not_enrolled")
    filename=pkg.get("filename","")
    expected=pkg.get("sha256","")
    if not isinstance(filename,str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,127}[.]zip",filename) or not isinstance(expected,str) or not re.fullmatch(r"[a-f0-9]{64}",expected):
        return result("review_required","registry_transport","invalid_package_contract")
    path=ROOT/"registry-remote-v1/registry_remote.py"
    rc,out,_=command(["/usr/bin/python3",str(path),"readback",
        "--target",binding,"--filename",filename,"--sha256",expected],timeout=35)
    try:d=json.loads(out)
    except ValueError:d={}
    if rc==0 and d.get("status")=="READBACK_VERIFIED" and d.get("sha256")==expected:
        return result("verified","registry_transport","artifact_integrity_verified",
          {"sha256":expected,"artifact":filename,"site_uuid":scope["site_uuid"]})
    # Authorized artifact provisioning is a repairable dependency. This
    # operation moves only a digest-checked ZIP to enrolled Staging uploads;
    # it never installs or executes it, and it cannot overwrite conflicts.
    if "remote_file_transfer:verified_zip" not in scope.get("operations",[]):
        return result("blocked_dependency","registry_transport","transfer_not_authorized")
    rc2,out2,_=command(["/usr/bin/python3",str(path),"transfer",
        "--target",binding,"--filename",filename,"--sha256",expected],timeout=45)
    try:d2=json.loads(out2)
    except ValueError:d2={}
    if rc2==0 and d2.get("status")=="READBACK_VERIFIED" and d2.get("sha256")==expected:
        return result("verified","registry_transport","artifact_auto_reconciled",
          {"sha256":expected,"artifact":filename,"site_uuid":scope["site_uuid"],
           "repair_action":"verified_zip_transfer","installed":False})
    error=d2.get("reason",d.get("state","artifact_not_verified"))
    return result("review_required" if "mismatch" in error or "conflict" in error else "retry",
                  "registry_transport","artifact_transfer_"+error[:90])

def inspect_workflow(target,scope):
    operation=scope.get("workflow_operation")
    if not isinstance(operation,str) or not re.fullmatch(r"[a-z0-9_]{3,80}",operation):
        return result("awaiting_provider","dispatcher_v1","workflow_target_not_enrolled")
    rc,out,_=command(["/usr/local/bin/mad4b-dispatch","status"],timeout=15)
    try:d=json.loads(out)
    except ValueError:d={}
    rows=[x for x in d.get("recent",[]) if x.get("target")==target and
          x.get("operation")==operation]
    if rc==0 and rows and rows[0].get("state")=="succeeded" and \
       d.get("contract")=="mad4b.dispatch.v1":
        return result("verified","dispatcher_v1","existing_job_receipt_verified",
          {"job_id":rows[0]["job_id"],"job_state":"succeeded","new_job_created":False})
    return result("blocked_dependency","dispatcher_v1","job_not_yet_reconciled",
                  {"pending_authority_count":d.get("pending_authority_count")})

ADAPTERS={"transport":inspect_transport,"identity":inspect_identity,
          "authority":inspect_authority,"full_authority":inspect_full_authority,
          "agent":inspect_agent,"package":inspect_package,"workflow":inspect_workflow}
def enqueue(con,target,trigger,source,idem):
    if trigger not in EVENTS or not re.fullmatch(r"[a-z0-9_.:-]{3,100}",source):
        raise ValueError("unsupported_event")
    if target not in inventory():raise ValueError("unenrolled_target")
    if not re.fullmatch(r"[a-zA-Z0-9_.:-]{8,128}",idem):
        raise ValueError("invalid_event_idempotency")
    row=con.execute("SELECT event_id,state FROM events WHERE idem=?",(idem,)).fetchone()
    if row:return {"event_id":row["event_id"],"state":row["state"],"idempotent":True}
    event_id=str(uuid.uuid4())
    con.execute("INSERT INTO events VALUES(?,?,?,?,?,?,?,NULL)",
                (event_id,idem,target,trigger,source,"queued",utc()))
    con.commit()
    return {"event_id":event_id,"state":"queued","idempotent":False}

def prior(con,target,domain):
    return con.execute("SELECT state,attempts FROM observations WHERE target=? AND domain=?",
                       (target,domain)).fetchone()

def evaluate(con,target,domain,scopes,status):
    current=prior(con,target,domain)
    before=current["state"] if current else "unobserved"
    dependencies=DEPENDS[domain]
    missing=[d for d in dependencies if status.get(d)!="verified"]
    if missing:
        return before,result("blocked_dependency","resolver",",".join(missing)+"_not_verified")
    try:r=ADAPTERS[domain](target,scopes)
    except (KeyError,ValueError,OSError,sqlite3.Error) as exc:
        r=result("review_required","resolver","adapter_contract_error_"+type(exc).__name__)
    return before,r

def reconcile(con,event_id,p):
    event=con.execute("SELECT * FROM events WHERE event_id=?",(event_id,)).fetchone()
    if event is None:raise ValueError("event_unknown")
    if event["state"]=="completed":
        return {"event_id":event_id,"state":"completed","idempotent":True}
    target=event["target"]
    inv=inventory()[target]
    assigned=p.get("scopes",{}).get(target)
    if not isinstance(assigned,dict):
        out={d:result("awaiting_provider","resolver","target_scope_not_configured") for d in DOMAINS}
        scope={}
    else:
        if assigned.get("site_uuid")!=inv.get("site_uuid") or \
           assigned.get("canonical_origin")!=inv.get("canonical_origin"):
            raise ValueError("policy_inventory_identity_conflict")
        scope=assigned
        out={}
        for domain in DOMAINS:
            _,r=evaluate(con,target,domain,scope,{d:v["state"] for d,v in out.items()})
            out[domain]=r
    for domain,r in out.items():
        old=prior(con,target,domain)
        previous=old["state"] if old else "unobserved"
        evidence=safe_json(r["evidence"])
        digest=hashlib.sha256(evidence.encode()).hexdigest()
        attempts=(old["attempts"] if old else 0)+(1 if r["state"]=="retry" else 0)
        state=r["state"]
        if attempts>=3 and state=="retry":
            state="review_required"
            r["reason"]="retries_exhausted"
        con.execute("""INSERT INTO observations(target,domain,state,provider,reason,evidence_sha256,evidence_json,attempts,at)
                       VALUES(?,?,?,?,?,?,?,?,?)
                       ON CONFLICT(target,domain) DO UPDATE SET
                       state=excluded.state,provider=excluded.provider,reason=excluded.reason,
                       evidence_sha256=excluded.evidence_sha256,evidence_json=excluded.evidence_json,
                       attempts=excluded.attempts,at=excluded.at""",
                    (target,domain,state,r["provider"],r["reason"],digest,evidence,attempts,utc()))
        con.execute("INSERT INTO receipts VALUES(?,?,?,?,?,?,?,?,0)",
                    (str(uuid.uuid4()),event_id,target,domain,previous,state,digest,utc()))
        if state=="review_required":
            con.execute("INSERT OR IGNORE INTO reviews VALUES(?,?,?,?,?)",
                        (target,domain,r["reason"],"open",utc()))
        if state=="verified":
            con.execute("UPDATE reviews SET state='resolved' WHERE target=? AND domain=? AND state='open'",
                        (target,domain))
    con.execute("UPDATE events SET state='completed',processed_at=? WHERE event_id=?",(utc(),event_id))
    con.commit()
    return {"contract":CONTRACT,"event_id":event_id,"target":target,
            "trigger":event["trigger"],"state":"completed",
            "domains":{x:r["state"] for x,r in out.items()},
            "production_mutation":False,"external_write_performed":False}

def sweep(con,p,limit):
    # A burst may contain hundreds of intake/handoff/dispatch events for one
    # site. One fresh E2E readback satisfies the coalesced observation cycle.
    events=con.execute("SELECT event_id,target FROM events WHERE state='queued' ORDER BY at LIMIT ?",
                       (min(max(limit,1),100),)).fetchall()
    first={};duplicates=[]
    for event in events:
        if event["target"] in first:
            duplicates.append(event["event_id"])
        else:
            first[event["target"]]=event["event_id"]
    results=[reconcile(con,event_id,p) for event_id in first.values()]
    if duplicates:
        con.executemany("UPDATE events SET state='completed',processed_at=? WHERE event_id=?",
                        [(utc(),event_id) for event_id in duplicates])
        con.commit()
    return {"contract":CONTRACT,"processed":len(events),"readback_cycles":len(results),
            "coalesced":len(duplicates),"results":results,"production_mutation":False}
def show_status(con):
    rows=con.execute("SELECT target,domain,state,provider,reason,at FROM observations ORDER BY target,domain").fetchall()
    events=dict(con.execute("SELECT state,count(*) FROM events GROUP BY state").fetchall())
    reviews=con.execute("SELECT count(*) FROM reviews WHERE state='open'").fetchone()[0]
    return {"contract":CONTRACT,"observations":[dict(r) for r in rows],
            "events":events,"open_reviews":reviews,"production_mutation":False}
def consume_inbox(con,p):
    INBOX.mkdir(mode=0o700,parents=True,exist_ok=True)
    dead=HOME/"deadletter"
    dead.mkdir(mode=0o700,parents=True,exist_ok=True)
    accepted=[];rejected=0
    for path in sorted(INBOX.glob("*.json"))[:50]:
        try:
            if path.is_symlink() or not path.is_file() or path.stat().st_size>8192:
                raise ValueError("unsafe_event_file")
            event=json.loads(path.read_text())
            if not isinstance(event,dict) or event.get("contract")!="mad4b.universal-runtime-event.v1":
                raise ValueError("event_contract_invalid")
            row=enqueue(con,event["target"],event["trigger"],
                        event["source"],event["idempotency_key"])
            accepted.append(row["event_id"])
            path.unlink()
        except (OSError,ValueError,KeyError,TypeError):
            rejected+=1
            path.replace(dead/path.name)
    processed=sweep(con,p,50)
    return {"contract":CONTRACT,"accepted":len(accepted),
            "rejected":rejected,"result":processed,"production_mutation":False}

def main():
    os.umask(0o077)
    a=argparse.ArgumentParser()
    sub=a.add_subparsers(dest="action",required=True)
    emit=sub.add_parser("emit");emit.add_argument("--target",required=True)
    emit.add_argument("--trigger",choices=sorted(EVENTS),required=True)
    emit.add_argument("--source",default="operator")
    emit.add_argument("--idempotency-key",required=True)
    sub.add_parser("scheduled");sub.add_parser("process");sub.add_parser("consume");sub.add_parser("status")
    a.args=a.parse_args()
    if os.geteuid()!=0:raise ValueError("root_owned_runtime_required")
    p=policy()
    with LOCK.open("a+") as mutex:
        try:
            fcntl.flock(mutex.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            if a.args.action=="emit":
                con=db_open()
                try:
                    enqueued=enqueue(con,a.args.target,a.args.trigger,
                                     a.args.source,a.args.idempotency_key)
                    print(safe_json({"enqueued":enqueued,"state":"queued_for_busy_resolver"}))
                finally:con.close()
            else:
                print(safe_json({"contract":CONTRACT,"state":"RUN_ALREADY_ACTIVE",
                                "retry_on_next_cycle":True,"production_mutation":False}))
            return
        con=db_open()
        try:
            if a.args.action=="emit":
                event=enqueue(con,a.args.target,a.args.trigger,a.args.source,a.args.idempotency_key)
                print(safe_json({"enqueued":event,"result":reconcile(con,event["event_id"],p)}))
            elif a.args.action=="scheduled":
                cycle=int(time.time()//300)
                for target in inventory():
                    enqueue(con,target,"scheduled","systemd",f"scheduled:{target}:{cycle}")
                print(safe_json(sweep(con,p,100)))
            elif a.args.action=="process":
                print(safe_json(sweep(con,p,100)))
            elif a.args.action=="consume":
                print(safe_json(consume_inbox(con,p)))
            else:print(safe_json(show_status(con)))
        finally:con.close()
if __name__=="__main__":
    try:main()
    except (ValueError,RuntimeError,OSError,sqlite3.Error) as exc:
        print(safe_json({"contract":CONTRACT,"state":"FAIL_CLOSED",
           "reason":type(exc).__name__,"production_mutation":False}),file=sys.stderr)
        sys.exit(75)

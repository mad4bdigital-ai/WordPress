#!/usr/bin/env python3
"""MAD4B registered company stage executor: bounded MU provider transaction.

No generic command transport. This program does not enroll itself, authorize
production, change WordPress options, or publish content. Its 'apply' and
'rollback' require a central Ed25519-signed exact-stage ticket and a policy
that expressly permits portable MU artifacts. Local default policy denies MU.
"""
import argparse
import base64
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

CONTRACT = "mad4b.company-stage-job.v1"
ADAPTER = "mad4b.company-native-stage-executor.v1"
MUTABLE_ACTION = "company.mu-providers.converge"
SAFE_NAMES = {
    "product-navigation-tags.php",
    "transport-component-price-tags.php",
    "tour-component-structured-price-tags.php",
    "tour-rate-native-display-tags.php",
    "registry-destination-activity.php",
    "registry-destination-facets.php",
    "registry-destination-presentation-ui.php",
    "registry-taxonomy-archive-presentation.php",
    "assets/tour-rate-structured-tags.inc.php",
    "assets/tour-rate-occupancy-label.inc.php",
    "shared-product-core/pricing/audience-resolver.php",
    "shared-product-core-loader.php",
}
SHA = re.compile(r"^[a-f0-9]{64}$")
UUID = re.compile(r"^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$",re.I)

class Denied(Exception):
    pass

def digest(blob):
    return hashlib.sha256(blob).hexdigest()

def canonical(obj):
    return json.dumps(obj, sort_keys=True, separators=(",",":"),ensure_ascii=False).encode()

def load(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))

def guarded_file(root, name):
    if name not in SAFE_NAMES:
        raise Denied("ARTIFACT_NOT_IN_STATIC_SEMANTIC_ALLOWLIST")
    base = Path(root).resolve(strict=True)
    item = base.joinpath(*name.split("/"))
    if item.is_symlink() or any(p.is_symlink() for p in item.parents if p != base):
        raise Denied("SYMLINK_ARTIFACT_FORBIDDEN")
    if not item.resolve(strict=False).is_relative_to(base):
        raise Denied("ARTIFACT_ESCAPES_DECLARED_ROOT")
    return item

def validate_scope(manifest, policy):
    if manifest.get("contract") != CONTRACT or manifest.get("action") != MUTABLE_ACTION:
        raise Denied("UNKNOWN_OPERATION_CONTRACT")
    if manifest.get("mode") != "repair_existing" or manifest.get("preserve_existing") is not True:
        raise Denied("INVALID_REPAIR_MODE")
    if manifest.get("company_code") != policy.get("company") or manifest.get("site_uuid") != policy.get("site_uuid"):
        raise Denied("COMPANY_IDENTITY_MISMATCH")
    if manifest.get("origin") != policy.get("canonical_origin"):
        raise Denied("ORIGIN_MISMATCH")
    if not UUID.fullmatch(str(manifest.get("site_uuid",""))):
        raise Denied("SITE_UUID_INVALID")
    for key in ("run_id","plan_sha256","stage_revision"):
        if not SHA.fullmatch(str(manifest.get(key,""))):
            raise Denied("MISSING_EXACT_RUN_OR_PLAN")
    files=manifest.get("artifacts")
    if not isinstance(files,list) or not files or len(files)>len(SAFE_NAMES):
        raise Denied("INVALID_ARTIFACT_SET")
    names=[x.get("path") for x in files if isinstance(x,dict)]
    if len(names)!=len(files) or len(set(names))!=len(names):
        raise Denied("DUPLICATE_ARTIFACT_OR_SCHEMA")
    for item in files:
        if not SHA.fullmatch(str(item.get("sha256",""))):
            raise Denied("INVALID_SOURCE_CHECKSUM")
        guarded_file(policy["target_mu_root"],item["path"])
    return files

def validate(manifest, policy):
    files=validate_scope(manifest,policy)
    if not isinstance(policy.get("portable_mu_allowlist"),list):
        raise Denied("NO_PORTABLE_ARTIFACT_POLICY")
    if not {item["path"] for item in files}.issubset(set(policy["portable_mu_allowlist"])):
        raise Denied("UNAPPROVED_PORTABLE_ARTIFACT")
    if "mu-plugins" in policy.get("never_copy",[]):
        raise Denied("EXISTING_POLICY_FORBIDS_MU_COPY")
    for item in files:
        if not SHA.fullmatch(str(item.get("sha256",""))):
            raise Denied("INVALID_SOURCE_CHECKSUM")
        source=guarded_file(policy["source_mu_root"],item["path"])
        if not source.is_file() or digest(source.read_bytes())!=item["sha256"]:
            raise Denied("SOURCE_ARTIFACT_HASH_MISMATCH")
    return files

def verify_ticket(ticket, policy, manifest, purpose):
    if ticket.get("contract")!="mad4b.company-executor-ticket.v1" or ticket.get("purpose")!=purpose:
        raise Denied("INVALID_TICKET_CONTRACT")
    if ticket.get("key_id")!=policy.get("owner_public_key_id"):
        raise Denied("UNKNOWN_OWNER_AUTHORITY_KEY")
    scope={k:manifest.get(k) for k in ("company_code","site_uuid","origin","mode","action","run_id","plan_sha256","stage_revision")}
    if ticket.get("scope")!=scope:
        raise Denied("TICKET_NOT_BOUND_TO_EXACT_STAGE")
    now=dt.datetime.now(dt.timezone.utc)
    try:
        expiry=dt.datetime.fromisoformat(ticket["expires_at"].replace("Z","+00:00"))
        issued=dt.datetime.fromisoformat(ticket["issued_at"].replace("Z","+00:00"))
    except (ValueError,KeyError,AttributeError) as exc:
        raise Denied("TICKET_TIME_INVALID") from exc
    if not (issued<=now<=expiry and (expiry-issued).total_seconds()<=900):
        raise Denied("TICKET_EXPIRED_OR_INVALID_DURATION")
    if not SHA.fullmatch(str(ticket.get("bundle_sha256",""))):
        raise Denied("TICKET_BUNDLE_UNBOUND")
    if ticket["bundle_sha256"]!=digest(canonical(manifest.get("artifacts"))):
        raise Denied("TICKET_BUNDLE_MISMATCH")
    sig=ticket.get("signature")
    unsigned={k:v for k,v in ticket.items() if k!="signature"}
    if not isinstance(sig,str):
        raise Denied("MISSING_SIGNATURE")
    try:
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
        pub=base64.b64decode(policy["owner_ed25519_public_key"],validate=True)
        Ed25519PublicKey.from_public_bytes(pub).verify(base64.b64decode(sig,validate=True),canonical(unsigned))
    except Exception as exc:
        raise Denied("SIGNATURE_VERIFICATION_FAILED") from exc

def checks(manifest,policy):
    items=validate(manifest,policy)
    return {"contract":ADAPTER,"state":"PLAN_VALIDATED","company":manifest["company_code"],
        "run_id":manifest["run_id"],"stage_revision":manifest["stage_revision"],
        "artifacts":len(items),"source_bundle_sha256":digest(canonical(items)),
        "mutation_performed":False}

def php_lint(source):
    r=subprocess.run(["php","-l",str(source)],capture_output=True,text=True,timeout=30)
    if r.returncode!=0:raise Denied("ARTIFACT_PHP_SYNTAX_REJECTED")

def safe_write(path, data):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(dir=path.parent,prefix=".mad4b-converge-")
    try:
        with os.fdopen(fd,"wb") as file:file.write(data);file.flush();os.fsync(file.fileno())
        os.chmod(tmp,0o640)
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def inspect_live_wordpress(manifest,policy):
    container=policy.get("wordpress_container")
    if not isinstance(container,str) or not re.fullmatch(r"[a-z0-9_-]{4,80}",container):
        raise Denied("WORDPRESS_CONTAINER_NOT_ENROLLED")
    code='echo wp_json_encode(array("origin"=>home_url(),"company"=>get_option("mad4b_company_site_identity_v1",array())["company_code"]??null,"site_uuid"=>get_option("mad4b_company_site_identity_v1",array())["site_uuid"]??null,"database"=>DB_NAME));'
    cmd=["docker","exec","-u","33:33",container,"php","-d","memory_limit=1024M",
         "/usr/local/bin/wp","eval",code,"--path=/var/www/html","--skip-themes"]
    proc=subprocess.run(cmd,capture_output=True,text=True,timeout=90,check=False)
    if proc.returncode!=0:
        raise Denied("WORDPRESS_RUNTIME_IDENTITY_PROBE_FAILED")
    rows=[line for line in proc.stdout.splitlines() if line.startswith("{")]
    if len(rows)!=1:raise Denied("WORDPRESS_IDENTITY_PROBE_AMBIGUOUS")
    actual=json.loads(rows[0])
    if actual != {"origin":manifest["origin"],"company":manifest["company_code"],
                  "site_uuid":manifest["site_uuid"],"database":policy.get("wordpress_database")}:
        raise Denied("LIVE_WORDPRESS_COMPANY_IDENTITY_MISMATCH")

def verify_restorable_backup(policy):
    folder=Path(policy["backup_root"]).resolve(strict=True)
    if not folder.is_dir() or folder.is_symlink():
        raise Denied("RESTORE_BACKUP_NOT_ENROLLED")
    sums=folder/"SHA256SUMS"
    if not sums.is_file():raise Denied("BACKUP_HAS_NO_CHECKSUM_MANIFEST")
    lines=sums.read_text().strip().splitlines()
    expected={"wayfatours-db.sql.gz","wayfatours-site.tar.gz"}
    if len(lines)!=len(expected):raise Denied("BACKUP_SET_INCOMPLETE")
    found=set()
    for line in lines:
        match=re.fullmatch(r"([a-f0-9]{64})\\s+\\*?(wayfatours-db\\.sql\\.gz|wayfatours-site\\.tar\\.gz)",line)
        if not match:raise Denied("BACKUP_MANIFEST_INVALID")
        hexdigest,name=match.groups()
        if name in found:raise Denied("DUPLICATE_BACKUP_ENTRY")
        found.add(name)
        p=folder/name
        if not p.is_file() or p.is_symlink() or digest(p.read_bytes())!=hexdigest:
            raise Denied("BACKUP_SHA256_MISMATCH")
    if found!=expected:raise Denied("BACKUP_INCOMPLETE")

def transaction(manifest,policy,receipt_dir):
    items=validate(manifest,policy)
    if policy.get("executor_contract")!="mad4b.company-vps-executor.v1" or policy.get("mutation_enabled") is not True:
        raise Denied("MUTATION_NOT_ENROLLED")
    inspect_live_wordpress(manifest,policy)
    verify_restorable_backup(policy)
    root=Path(receipt_dir).resolve()
    root.mkdir(mode=0o700,parents=True,exist_ok=True)
    txn=root/manifest["run_id"]
    if txn.exists():raise Denied("RUN_ALREADY_HAS_TRANSACTION_RECONCILE_FIRST")
    txn.mkdir(mode=0o700)
    before={}
    written=[]
    try:
        for item in items:
            src=guarded_file(policy["source_mu_root"],item["path"])
            php_lint(src)
            target=guarded_file(policy["target_mu_root"],item["path"])
            before[item["path"]]=base64.b64encode(target.read_bytes()).decode() if target.exists() else None
        # Persist rollback contents before touching any tenant artifact.
        pre={"run_id":manifest["run_id"],"scope":{k:manifest[k] for k in
             ("company_code","site_uuid","origin","action","stage_revision")},
             "before":before,"after":{i["path"]:i["sha256"] for i in items}}
        safe_write(txn/"preimage.json",canonical(pre))
        for item in items:
            dest=guarded_file(policy["target_mu_root"],item["path"])
            payload=guarded_file(policy["source_mu_root"],item["path"]).read_bytes()
            if digest(payload)!=item["sha256"]:
                raise Denied("SOURCE_CHANGED_AFTER_SYNTAX_TEST")
            safe_write(dest,payload)
            written.append(item["path"])
        if any(digest(guarded_file(policy["target_mu_root"],i["path"]).read_bytes())!=i["sha256"] for i in items):
            raise Denied("INDEPENDENT_FILE_READBACK_MISMATCH")
        outcome={"contract":ADAPTER,"state":"APPLIED_FILES_VERIFIED",
            "company":manifest["company_code"],"run_id":manifest["run_id"],
            "stage_revision":manifest["stage_revision"],"independent_file_readback":True,
            "files":len(written),"requires_wordpress_runtime_readback":True,
            "commercial_distribution_authorized":False}
        safe_write(txn/"outcome.json",canonical(outcome))
        return outcome
    except Exception:
        for name in reversed(written):
            dest=guarded_file(policy["target_mu_root"],name)
            prior=before[name]
            if prior is None:dest.unlink(missing_ok=True)
            else:safe_write(dest,base64.b64decode(prior))
        safe_write(txn/"auto-rollback.json",canonical({"state":"ROLLED_BACK_AFTER_FAILURE","files":written}))
        raise

def rollback(manifest,policy,receipt_dir):
    txn=Path(receipt_dir).resolve()/manifest["run_id"]
    pre=load(txn/"preimage.json")
    scope={k:manifest[k] for k in ("company_code","site_uuid","origin","action","stage_revision")}
    if pre["run_id"]!=manifest["run_id"] or pre["scope"]!=scope:
        raise Denied("ROLLBACK_SCOPE_MISMATCH")
    for name,value in pre["before"].items():
        dest=guarded_file(policy["target_mu_root"],name)
        if value is None:dest.unlink(missing_ok=True)
        else:safe_write(dest,base64.b64decode(value))
    matches=all((not guarded_file(policy["target_mu_root"],name).exists()
        if data is None else digest(guarded_file(policy["target_mu_root"],name).read_bytes())==digest(base64.b64decode(data)))
        for name,data in pre["before"].items())
    result={"state":"ROLLED_BACK_VERIFIED" if matches else "ROLLBACK_READBACK_FAILED",
            "company":manifest["company_code"],"run_id":manifest["run_id"],"verified":matches}
    safe_write(txn/"rollback.json",canonical(result))
    if not matches:raise Denied("ROLLBACK_READBACK_FAILED")
    return result

def main(argv=None):
    ap=argparse.ArgumentParser()
    ap.add_argument("operation",choices=("plan","apply","rollback","readback"))
    ap.add_argument("--policy",required=True)
    ap.add_argument("--manifest",required=True)
    ap.add_argument("--ticket")
    ap.add_argument("--receipt-dir",required=True)
    args=ap.parse_args(argv)
    try:
        policy=load(args.policy)
        manifest=load(args.manifest)
        # Emergency rollback must remain possible when the source changes,
        # an enrolled artifact is revoked, or the copy policy is disabled.
        if args.operation=="rollback":
            validate_scope(manifest,policy)
            if not args.ticket:raise Denied("CENTRAL_SIGNED_TICKET_REQUIRED")
            verify_ticket(load(args.ticket),policy,manifest,"rollback")
            return rollback(manifest,policy,args.receipt_dir)
        result=checks(manifest,policy)
        if args.operation=="plan":return result
        if args.operation=="readback":
            return {"contract":ADAPTER,"state":"FILE_READBACK",
                "verified":all(digest(guarded_file(policy["target_mu_root"],i["path"]).read_bytes())==i["sha256"]
                    for i in manifest["artifacts"]),
                "not_a_commercial_readiness_receipt":True}
        if not args.ticket:raise Denied("CENTRAL_SIGNED_TICKET_REQUIRED")
        verify_ticket(load(args.ticket),policy,manifest,args.operation)
        return transaction(manifest,policy,args.receipt_dir)
    except (Denied,OSError,ValueError,KeyError,json.JSONDecodeError) as exc:
        return {"contract":ADAPTER,"state":"DENIED_OR_FAILED",
            "reason":str(exc)[:220],"mutation_complete":False}

if __name__=="__main__":
    outcome=main()
    print(json.dumps(outcome,sort_keys=True))
    sys.exit(0 if outcome.get("state") in ("PLAN_VALIDATED","APPLIED_FILES_VERIFIED","ROLLED_BACK_VERIFIED","FILE_READBACK")
        and outcome.get("verified",True) else 4)

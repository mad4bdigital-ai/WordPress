"""MAD4B operation authority: transport-neutral, source-backed readback.

Authentication is independent for OAuth/MCP, SSH/WP-CLI and Broker.
The original owner of each operation decides authorization. This module
does not create grants, impersonate WordPress users, or consume queued
arbitrary shell programs.
"""
import hashlib
import datetime as dt
import importlib.util
import os
import stat
import json
from pathlib import Path
import re
import subprocess

ROOT=Path("/opt/mad4b-company-transport")
NATIVE=ROOT/"vps-native-operator.py"
BROKER=ROOT/"broker-v1/vps-broker-exact-authority.py"
OPERATOR_POLICY=ROOT/"native-operator-v1/host-authority.json"
INVENTORY=ROOT/"environment-inventory-v1.json"
COMMAND=ROOT/"native-operator-v1/are-staging-shell-diagnostic.cmd"
READBACK=ROOT/"native-operator-v1/are-staging-shell-readback.cmd"
CANARY_RECEIPT=ROOT/"native-operator-v1/receipts/are-staging-general-shell-policy-driven-20261031.json"
CONTRACT="mad4b.multi-transport-operation-admission.v1"
STAGING="all-royal-egypt:staging"
ADMIN_CAPABILITY="maintenance.shell"
GRANT_CAPABILITY="governed.write"
# Each operation has ONE admission semantics, independent of the auth lane.
# General host maintenance is distinct from WordPress governed-write ability
# execution and cannot mint/replace WP Agent Grants.
POLICY={
    ADMIN_CAPABILITY:{"original_admission":"native_host_operator_scope",
                      "allowed_transports":("oauth_mcp","vps_ssh_wpcli","broker"),
                      "requires_exact_target":True,"readback_required":True},
    GRANT_CAPABILITY:{"original_admission":"wordpress_agent_registry_and_operation_policy",
                      "allowed_transports":("oauth_mcp","vps_ssh_wpcli","broker"),
                      "requires_exact_target":True,"readback_required":True},
}

def execute(argv, timeout=35):
    try:
        p=subprocess.run(argv,text=True,capture_output=True,timeout=timeout,check=False)
        if p.returncode:return None
        return json.loads(p.stdout.strip().splitlines()[-1])
    except (OSError,ValueError,subprocess.TimeoutExpired,IndexError):
        return None

def exact_binding(target, scope):
    try:
        inventory=json.loads(INVENTORY.read_text())["targets"]
        row=inventory[target]
    except (OSError,KeyError,ValueError,TypeError):
        return False
    return (row.get("canonical_origin")==scope.get("canonical_origin")
            and row.get("environment")==scope.get("environment")
            and row.get("site_uuid")==scope.get("site_uuid"))

def read_existing_receipt():
    try:
        st=CANARY_RECEIPT.lstat()
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode & 0o077:
            return None
        data=json.loads(CANARY_RECEIPT.read_text())
        when=dt.datetime.fromisoformat(data.get("finished_at",""))
        age=(dt.datetime.now(dt.timezone.utc)-when).total_seconds()
        if age<0 or age>86400:
            return None
        if (data.get("contract")!="mad4b.native-vps-operator.v1"
            or data.get("lane")!="registered_remote_ssh"
            or data.get("execution_performed") is not True
            or data.get("mutation_performed") is not False
            or data.get("mutating") is not False):
            return None
        return data
    except (OSError,ValueError,TypeError,KeyError):
        return None


def host_status(target,scope):
    if not exact_binding(target,scope):
        return {"state":"review_required","reason":"host_binding_not_enrolled"}
    if not NATIVE.is_file() or not OPERATOR_POLICY.is_file():
        return {"state":"awaiting_provider","reason":"native_host_operator_missing"}
    policy=json.loads(OPERATOR_POLICY.read_text())
    row=policy.get("scopes",{}).get(target,{})
    if policy.get("contract")!="mad4b.native-vps-operator.v1" or \
       policy.get("revoked") is not False or \
       policy.get("allow_general_shell") is not True or \
       row.get("allow_operator") is not True:
        return {"state":"blocked_authority","reason":"host_operator_scope_not_active"}
    if os.environ.get("SSH_CONNECTION"):
        status=execute(["python3",str(NATIVE),"status"])
        if not isinstance(status,dict) or status.get("state")!="INDEPENDENT_ROOT_SSH_PRINCIPAL_VERIFIED":
            return {"state":"awaiting_provider","reason":"authenticated_root_host_context_unavailable"}
        report=status.get("scopes",{}).get(target,{})
        if report.get("allow_operator") is not True:
            return {"state":"blocked_authority","reason":"host_report_scope_disagrees"}
        return {"state":"verified","reason":"source_host_operator_authenticated",
                "scope_readback":True,"allowed_remote_shell":report.get("allow_remote_shell") is True,
                "new_execution_requires_direct_root_ssh":False,"grants_minted":False}
    # Scheduled workers are NOT SSH login sessions. They may observe a recent
    # original execution receipt and pinned Registry target, but may NEVER
    # act as authenticated root operator for a NEW command.
    if target!=STAGING or not CANARY_RECEIPT.is_file():
        return {"state":"awaiting_provider","reason":"direct_root_ssh_session_not_active"}
    receipt=read_existing_receipt()
    if not receipt or receipt.get("state")!="VERIFIED" or receipt.get("readback_matches_expected") is not True:
        return {"state":"awaiting_provider","reason":"independent_ssh_operator_receipt_unavailable"}
    if receipt.get("company")!="all-royal-egypt" or receipt.get("environment")!="staging":
        return {"state":"review_required","reason":"previous_operator_target_mismatch"}
    if (row.get("registry_binding")!=scope.get("transport_binding") or
        row.get("site_uuid")!=scope.get("site_uuid") or
        row.get("canonical_origin")!=scope.get("canonical_origin") or
        row.get("expected_root")!=scope.get("expected_root")):
        return {"state":"review_required","reason":"host_registry_binding_conflict"}
    try:
        path=ROOT/"registry-remote-v1/registry_remote.py"
        sp=importlib.util.spec_from_file_location("mad4b_scheduled_registered_ssh",path)
        module=importlib.util.module_from_spec(sp)
        sp.loader.exec_module(module)
        if module.guard(row["registry_binding"])!=row["expected_root"]:
            return {"state":"review_required","reason":"registry_original_root_changed"}
    except (OSError,ValueError,KeyError,RuntimeError,AttributeError):
        return {"state":"awaiting_provider","reason":"registered_host_key_readback_unavailable"}
    return {"state":"verified","reason":"source_host_scope_and_prior_receipt_verified",
            "scope_readback":True,"allowed_remote_shell":row.get("allow_remote_shell") is True,
            "new_execution_requires_direct_root_ssh":True,"grants_minted":False}

def broker_status():
    # Signed central verification exists; job eligibility is ALWAYS separate.
    if not BROKER.is_file():
        return {"state":"awaiting_provider","reason":"broker_source_unavailable"}
    receipt=execute(["python3",str(BROKER),"status"],timeout=45)
    if not isinstance(receipt,dict) or \
       receipt.get("contract")!="mad4b.broker-exact-ssh-authority.v1" or \
       receipt.get("verifier_registered") is not True or \
       receipt.get("shell_executed") is not False:
        return {"state":"awaiting_provider","reason":"broker_verifier_unavailable"}
    # A signed round-trip proves that the broker really talks to the original
    # central verifier; an unknown job must be denied. A successful transport
    # round-trip alone never admits any pending execution.
    proof=execute(["python3",str(BROKER),"selftest"],timeout=55)
    if not isinstance(proof,dict) or proof.get("contract")!="mad4b.broker-exact-ssh-authority.v1" or        proof.get("selftest")!="PASS" or        proof.get("signed_central_preflight_round_trip") is not True or        proof.get("unknown_job_denied") is not True:
        return {"state":"awaiting_provider","reason":"signed_broker_round_trip_unverified"}
    return {"state":"verified","reason":"signed_central_broker_verifier_authenticated",
            "job_execution_eligible":False,"requires_exact_job_verification":True,
            "grants_minted":False}

def claimed_broker_jobs():
    '''Inspect the exact original signed claims without fetching command text.'''
    cmd=['docker','exec','-u','www-data','etg-commerce-staging-php','wp',
         'mad4b','broker-authority','claimed','--limit=5',
         '--user=1','--path=/var/www/html','--skip-themes']
    data=execute(cmd,timeout=40)
    if not (isinstance(data,dict)
            and data.get('contract')=='mad4b.broker-exact-ssh-authority.v1'
            and isinstance(data.get('claimed'),list)
            and len(data['claimed'])<=5):
        return {'state':'awaiting_provider',
                'reason':'original_claimed_job_source_unavailable'}
    results=[]
    for item in data['claimed']:
        if not isinstance(item,dict):
            return {'state':'review_required','reason':'invalid_broker_claim_contract'}
        verdict=broker_exact_job_preflight(item.get('job_id'),item.get('target_id'),
                                           item.get('command_sha256'))
        results.append({'job_id':item.get('job_id'),'state':verdict['state'],
                        'reason':verdict['reason']})
    return {'state':'verified','reason':'original_broker_claims_inspected',
            'claimed_job_count':len(results),'evaluated':results,
            'shell_executed':False,'grants_minted':False}


def oauth_status():
    # A local CLI process has no authenticated bearer request. A current
    # OAuth/MCP request is inspected by original WordPress Execution_Authority,
    # never by injecting a --user value or reading a stored token.
    return {"state":"request_context_required","reason":"verified_oauth_bearer_is_request_scoped",
            "grant_or_execution_issued":False}

def sources(target,scope):
    broker=broker_status()
    if broker['state']=='verified':
        broker['claimed_jobs']=claimed_broker_jobs()
    return {"contract":CONTRACT,"target":target,
            "oauth_mcp":oauth_status(),
            "vps_ssh_wpcli":host_status(target,scope),
            "broker":broker,
            "production_mutation":False}

def host_admit_existing_canary(target,scope):
    """Ask the native root Operator to PLAN exact scoped command, not execute."""
    base=host_status(target,scope)
    if base["state"]!="verified":
        return base
    if target!=STAGING or not base.get("allowed_remote_shell"):
        return {"state":"awaiting_provider","reason":"host_shell_operation_not_enrolled"}
    if not all(f.is_file() and not f.is_symlink() for f in (COMMAND,READBACK,CANARY_RECEIPT)):
        return {"state":"awaiting_provider","reason":"exact_host_receipt_or_program_missing"}
    receipt=read_existing_receipt()
    if not isinstance(receipt,dict):
        return {"state":"awaiting_provider","reason":"fresh_original_host_receipt_missing"}
    if (receipt.get("contract")!="mad4b.native-vps-operator.v1"
        or receipt.get("state")!="VERIFIED"
        or receipt.get("lane")!="registered_remote_ssh"
        or receipt.get("environment")!="staging"
        or receipt.get("company")!="all-royal-egypt"
        or receipt.get("readback_matches_expected") is not True
        or receipt.get("mutation_performed") is not False):
        return {"state":"review_required","reason":"original_host_readback_not_verified"}
    command_sha=hashlib.sha256(COMMAND.read_bytes()).hexdigest()
    readback_digest=receipt.get("expected_readback_sha256")
    if command_sha!=receipt.get("command_sha256") or \
        not isinstance(readback_digest,str) or not re.fullmatch(r"[a-f0-9]{64}",readback_digest):
        return {"state":"review_required","reason":"exact_command_or_readback_digest_conflict"}
    args=["python3",str(NATIVE),"plan","--kind","ssh",
          "--company","all-royal-egypt","--environment","staging",
          "--intent","diagnostic","--file",str(COMMAND),
          "--sha256",command_sha,"--readback-file",str(READBACK),
          "--expected-readback-sha256",readback_digest]
    if not os.environ.get("SSH_CONNECTION"):
        return {"state":"verified","reason":"original_exact_operator_receipt_observed",
                "exact_site":True,"original_policy_admission":True,
                "independent_receipt_sha256":receipt.get("readback_sha256"),
                "capability":ADMIN_CAPABILITY,"transport":"vps_ssh_wpcli",
                "new_execution_requires_direct_root_ssh":True,
                "grants_created":False,"command_executed_in_this_probe":False}
    plan=execute(args,timeout=55)
    if not isinstance(plan,dict) or plan.get("state")!="ADMITTED_REQUIRES_DIRECT_OPERATOR_APPLY" or \
       plan.get("contract")!="mad4b.native-vps-operator.v1" or \
       plan.get("lane")!="registered_remote_ssh" or \
       plan.get("command_sha256")!=command_sha or \
       plan.get("production_mutation_authorized") is not False or \
       plan.get("execution_performed") is not False:
        return {"state":"blocked_authority","reason":"native_host_operator_plan_not_admitted"}
    return {"state":"verified","reason":"original_host_operation_plan_admitted",
            "exact_site":True,"original_policy_admission":True,
            "independent_receipt_sha256":receipt.get("readback_sha256"),
            "capability":ADMIN_CAPABILITY,"transport":"vps_ssh_wpcli",
            "grants_created":False,"command_executed_in_this_probe":False}

def broker_exact_job_preflight(job_id,target_host,command_sha):
    """Ask original central verifier for one claimed job. Never run its shell."""
    if not isinstance(job_id,str) or not re.fullmatch(r"[a-f0-9]{32}",job_id) or        not isinstance(target_host,str) or target_host not in ("hosting-1","hosting-3") or        not isinstance(command_sha,str) or not re.fullmatch(r"[a-f0-9]{64}",command_sha):
        return {"state":"blocked_authority","reason":"exact_broker_job_identity_invalid"}
    info=execute(["python3",str(BROKER),"verify","--job",job_id,
                  "--target",target_host,"--command-sha256",command_sha],timeout=60)
    if not isinstance(info,dict) or info.get("contract")!="mad4b.broker-exact-ssh-authority.v1" or        info.get("verified") is not True or info.get("eligible") is not True or        info.get("job_id")!=job_id or info.get("target_id")!=target_host or        info.get("command_sha256")!=command_sha or        info.get("central_scope_only") is not True or        info.get("shell_executed") is not False:
        return {"state":"blocked_authority","reason":"original_broker_job_grant_or_claim_invalid"}
    # Central approval is a necessary precondition; the independent host proof
    # and execution receipt have yet to be verified.
    return {"state":"blocked_dependency","reason":"independent_host_execution_proof_required",
            "central_job_authority_verified":True,"shell_executed":False}


def decide(target,scope,capability,transport):
    """Read-only admission from the original source of an exact operation.

    Do not accept caller-supplied assertions/grant flags. OAuth must authorize
    inside its original bearer request; Broker must verify its claimed job
    and SSH host executes only through the registered native operator.
    """
    rule=POLICY.get(capability)
    if rule is None or transport not in rule["allowed_transports"]:
        return {"state":"blocked_authority","reason":"capability_transport_not_admitted"}
    if not exact_binding(target,scope):
        return {"state":"review_required","reason":"target_site_binding_mismatch"}
    if capability==ADMIN_CAPABILITY and transport=="vps_ssh_wpcli":
        return host_admit_existing_canary(target,scope)
    if transport=="oauth_mcp":
        return {"state":"awaiting_provider","reason":"original_oauth_request_admission_required"}
    if transport=="broker":
        return {"state":"awaiting_provider","reason":"original_broker_exact_job_admission_required"}
    return {"state":"awaiting_provider","reason":"original_wpcli_operation_grant_admission_required"}

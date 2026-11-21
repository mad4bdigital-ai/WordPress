#!/usr/bin/env python3
"""Generate additive patch for actual MAD4B original 306/313 Job worker.

No shell, no signatures, no job creation. The original worker must be backed up
and atomically swapped by authorized root after independent source/health tests.
"""
from __future__ import annotations
import argparse
from pathlib import Path

BEGIN = "# MAD4B_DYNAMIC_PROVIDER_V1_BEGIN"
END = "# MAD4B_DYNAMIC_PROVIDER_V1_END"

HELPERS = '''# MAD4B_DYNAMIC_PROVIDER_V1_BEGIN
# Live root-enrolled operations may be discovered without replacing legacy 306.
DYNAMIC_REGISTRY_ROOT=ROOT/'dynamic-operation-registry-v1'/'enrolled'
DYNAMIC_PLAN_STORE=Path('/var/lib/mad4b/dynamic-operation-registry/plans')

def dynamic_provider_registry():
    if not (DYNAMIC_REGISTRY_ROOT/'index.json').is_file():
        return None
    if str(DYNAMIC_REGISTRY_ROOT) not in sys.path:
        sys.path.insert(0,str(DYNAMIC_REGISTRY_ROOT))
    from registry import Registry
    from worker_adapter import digest_table,execute_claimed
    index=Registry(DYNAMIC_REGISTRY_ROOT)
    return index,digest_table,execute_claimed

def dynamic_operation_digests():
    provider=dynamic_provider_registry()
    if provider is None:return {}
    index,digests,_=provider
    return {d:'dynamic:'+operation for d,operation
            in digests(index,DYNAMIC_PLAN_STORE).items()}

def dynamic_313_original_central_ready(actor):
    # Must be independently loaded in the original WordPress 313 service.
    # A root-local registry flag can NEVER authorize dispatch.
    try:
        row=cli_json(['--user='+str(actor),'mad4b',
                      'dynamic-313-proof','status'],timeout=15)
        return (row.get('contract')=='mad4b.dynamic-313-wordpress.v1'
                and row.get('first_party_proof_enrolled') is True
                and row.get('original_313_finalizer_connected') is True
                and row.get('independent_verifier_ready') is True
                and row.get('dynamic_proof_ingest_ready') is True
                and row.get('operational_ready') is True)
    except Exception:
        return False

DYNAMIC_RELEASE_GRANTS=Path('/var/lib/mad4b/company-code-release/grants')
DYNAMIC_RELEASE_PLANS=Path('/var/lib/mad4b/company-code-release/plans')

def dynamic_release_grant_ready(job,command_sha):
    # A global Registry/313 status never substitutes for a job-specific,
    # independently signed Broker release grant. Check this BEFORE 306 claim
    # and BEFORE the one-time dynamic execution reservation.
    if (not isinstance(job,str) or not HEX32.fullmatch(job) or
        not isinstance(command_sha,str) or
        not re.fullmatch(r'[a-f0-9]{64}',command_sha)):
        return False
    if ((ROOT/'reserved'/(job+'.reserved')).exists() or
        (ROOT/'receipts'/(job+'.json')).exists()):
        return False
    grant_path=DYNAMIC_RELEASE_GRANTS/(job+'.json')
    if not grant_path.is_file():return False
    try:
        provider=dynamic_provider_registry()
        if provider is None:return False
        index,_,_=provider
        from worker_adapter import indexed_plans
        rows=indexed_plans(index,DYNAMIC_PLAN_STORE)
        entry=rows.get(command_sha)
        if not isinstance(entry,dict) or entry.get('operation')!='company.code.release':
            return False
        plan=entry['plan']
        root=index.root/'company-code-release-v1'
        if str(root) not in sys.path:sys.path.insert(0,str(root))
        import company_code_release as release
        import original_313_host_handler as host_handler
        # Prevent a previously loaded same-named module from a different
        # company or source worktree from becoming the execution authority.
        if (Path(getattr(release,'__file__','')) != root/'company_code_release.py'
            or Path(getattr(host_handler,'__file__','')) !=
                root/'original_313_host_handler.py'):
            return False
        m=index.resolve('company.code.release')
        if not (m.get('version',0)>=3 and
                len(m.get('dependencies',[]))>=3):
            return False
        pinned=DYNAMIC_RELEASE_PLANS/(plan['plan_sha256']+'.json')
        if host_handler.trusted_load(pinned)!=plan:return False
        policy=release.trusted_config(release.POLICY_PATH)
        release.confirm_plan(plan,policy,expect_preimage=True)
        release.assert_runtime_binding(plan,policy)
        release_grant=host_handler.trusted_load(grant_path)
        if release_grant.get('original_job_id')!=job:return False
        release.verify_grant(plan,policy,release_grant,'apply')
        return True
    except Exception as exc:
        # A failed/expired/missing grant can never reserve or claim a Job.
        print('DYNAMIC_ORIGINAL_305_312_SIGNED_GRANT_NOT_READY:'+
              safe_capability_error(exc),file=sys.stderr)
        return False

def dynamic_execute_original_claimed(job,actor):
    # Called ONLY for a real Central-claimed original 306/312 Job, never a
    # self-created request. All fields are fetched from original WP options.
    if not isinstance(job,str) or not HEX32.fullmatch(job):
        raise RuntimeError('DYNAMIC_JOB_ID_INVALID')
    provider=dynamic_provider_registry()
    if provider is None:raise RuntimeError('DYNAMIC_FIRST_PARTY_REGISTRY_MISSING')
    _index,_digest,execute_claimed=provider
    code=('$id="'+job+'";'
          '$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
          '$p=get_option(MAD4B_Broker_Remote_SSH_Receipt_V1::WPCLI_PREFIX.$id,false);'
          'echo wp_json_encode(["job"=>is_array($j)?$j:[],'
          '"pending"=>is_array($p)?$p:[]]);')
    original=cli_json(['--user='+str(actor),'eval',code],timeout=30)
    j=original.get('job')
    p=original.get('pending')
    if not isinstance(j,dict) or not isinstance(p,dict):
        raise RuntimeError('DYNAMIC_ORIGINAL_JOB_MISSING')
    j=dict(j);p=dict(p)
    j['job_id']=job
    p['job_id']=job
    # Do not synthesize missing identity, host or expiry fields: the
    # first-party original 306/312 authority must provide them.
    out=execute_claimed(j,p)
    if out.get('state')!='HOST_EXECUTED_PENDING_INDEPENDENT_ORIGINAL_313' or (
        out.get('original_313_certified') is not False):
        raise RuntimeError('DYNAMIC_ORIGINAL_313_READBACK_PENDING')
    return out
# MAD4B_DYNAMIC_PROVIDER_V1_END

'''

def transform(src:str)->str:
    if BEGIN in src or END in src:
        raise ValueError("WORKER_DYNAMIC_PATCH_ALREADY_INSTALLED")
    anchor="def exact_host_targets():\n"
    if src.count(anchor)!=1:
        raise ValueError("WORKER_TARGET_REGISTRY_ANCHOR_MISSING")
    src=src.replace(anchor,HELPERS+anchor,1)
    anchor=" return options\n\nclass TransientCentralReadBusy(RuntimeError):pass"
    if src.count(anchor)!=1:
        raise ValueError("WORKER_DIGEST_RETURN_ANCHOR_MISSING")
    src=src.replace(anchor,""" try:
  for digest,operation in dynamic_operation_digests().items():
   if digest in options:raise RuntimeError('DYNAMIC_COMMAND_HASH_COLLISION')
   options[digest]=operation
 except Exception as exc:
  print('DYNAMIC_REGISTRY_DISCOVERY_DEFERRED:'+safe_capability_error(exc),
        file=sys.stderr)
 return options

class TransientCentralReadBusy(RuntimeError):pass""",1)
    anchor="""  legacy=(j.get('command_sha256') in allowed and scope=='wayfatours:production')
  encrypted=(not legacy and isinstance(j.get('sealed_argv_sha256'),str)
             and re.fullmatch(r'[a-f0-9]{64}',j['sealed_argv_sha256'])
             and generic_preclaim(job))
  if not legacy and not encrypted:continue
"""
    if src.count(anchor)!=1:
        raise ValueError("WORKER_ORIGINAL_CLAIM_ANCHOR_MISSING")
    src=src.replace(anchor,"""  selected=allowed.get(j.get('command_sha256'))
  dynamic_candidate=(isinstance(selected,str) and
                     selected.startswith('dynamic:'))
  dynamic_ready=(dynamic_candidate and dynamic_313_original_central_ready(actor)
                 and dynamic_release_grant_ready(job,j.get('command_sha256')))
  legacy=(selected is not None and not dynamic_candidate
          and scope=='wayfatours:production')
  encrypted=(not legacy and not dynamic_candidate
             and isinstance(j.get('sealed_argv_sha256'),str)
             and re.fullmatch(r'[a-f0-9]{64}',j['sealed_argv_sha256'])
             and generic_preclaim(job))
  if not legacy and not encrypted and not dynamic_ready:continue
""",1)
    anchor="""  operation=allowed.get(row.get('command_sha256'))
  reserved=(ROOT/'reserved'/(job+'.reserved')).exists()
"""
    if src.count(anchor)!=1:
        raise ValueError("WORKER_CLAIMED_COMMAND_ANCHOR_MISSING")
    src=src.replace(anchor,"""  operation=allowed.get(row.get('command_sha256'))
  if (isinstance(operation,str) and operation.startswith('dynamic:')
      and not (dynamic_313_original_central_ready(actor) and
               dynamic_release_grant_ready(job,row.get('command_sha256')))):
   continue
  reserved=(ROOT/'reserved'/(job+'.reserved')).exists()
""",1)
    anchor=""" job,operation=work[0]
 executor=(FULL_RESUME if operation=='wpcli.option.update.resume'
"""
    if src.count(anchor)!=1:
        raise ValueError("WORKER_EXECUTOR_DISPATCH_ANCHOR_MISSING")
    src=src.replace(anchor,""" job,operation=work[0]
 if isinstance(operation,str) and operation.startswith('dynamic:'):
  output=dynamic_execute_original_claimed(job,actor)
  # Original Central 313 remains the only authority that can finalize.
  print(json.dumps(output))
  return
 executor=(FULL_RESUME if operation=='wpcli.option.update.resume'
""",1)
    return src

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("--source",required=True)
    parser.add_argument("--output",required=True)
    args=parser.parse_args()
    p=Path(args.source)
    result=transform(p.read_text())
    Path(args.output).write_text(result)
    print("ADDITIVE_ORIGINAL_WORKER_PATCH_GENERATED")

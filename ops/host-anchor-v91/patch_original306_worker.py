#!/usr/bin/env python3
"""Live-first CAS of the existing Original306 Worker; typed v2 queue HOLD.

Adds a read-only exact original306 queued Anchor observer and blocks treating
any typed Host Anchor v2 Job as generic WPCLI/Host Source. This patch NEVER
claims, signs or executes a Host Anchor Job. Retains existing Worker paths.
"""
from __future__ import annotations
import hashlib

PREIMAGE_SHA256="819d2509fc5f072189f9c23ddde7116739ba2b6442950bdadf8436a08f7c7120"
OBSERVER_SHA256="1867e73dab8e7c966461ec026ca7c8119d557e9369c56446c4fd5a7db5c264d3"
INSERT_FUNCTION="def claim_authorized_job(actor):"
INSERT_BRANCH="""  j=cli_json(['--user='+str(actor),'eval',code])
  if not (j.get('operation')=='company.remote.wpcli.full'"""


FUNCTION='''def firstparty_host_v2_pending_observation(actor, entry):
    """Root exact original306 queued Host v2 Job preview; NEVER claims."""
    jobid=entry.get('job_id')
    if not isinstance(jobid,str) or not HEX32.fullmatch(jobid):
        return {'state':'HOST_V2_PENDING_ID_INVALID','eligible_to_claim':False}
    try:
        import stat
        root=DYNAMIC_REGISTRY_ROOT/'host-anchor-v91'
        modulefile=root/'original306_queued_observer.py'
        st=modulefile.lstat()
        if (modulefile.is_symlink() or not stat.S_ISREG(st.st_mode)
            or st.st_uid!=0 or st.st_gid!=0 or st.st_nlink!=1
            or stat.S_IMODE(st.st_mode)!=0o600
            or hashlib.sha256(modulefile.read_bytes()).hexdigest()!=
               '1867e73dab8e7c966461ec026ca7c8119d557e9369c56446c4fd5a7db5c264d3'):
            raise RuntimeError('ORIGINAL306_V91_OBSERVER_SOURCE_UNTRUSTED')
        spec=importlib.util.spec_from_file_location(
            '_mad4b_original306_v91_queued',modulefile)
        if spec is None or spec.loader is None:
            raise RuntimeError('ORIGINAL306_V91_OBSERVER_NOT_INSTALLED')
        module=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        if str(DYNAMIC_REGISTRY_ROOT) not in sys.path:
            sys.path.insert(0,str(DYNAMIC_REGISTRY_ROOT))
        from registry import Registry,checked_json
        index=Registry(DYNAMIC_REGISTRY_ROOT)
        php=('$id="'+jobid+'";'
            '$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
            'echo wp_json_encode(is_array($j)?$j:[]);')
        job=cli_json(['--user='+str(actor),'eval',php],timeout=28)
        if job.get('operation_id') not in (
            'company.host-authority.anchor.issue',
            'company.host-authority.anchor.revoke'):
            raise RuntimeError('ORIGINAL306_V91_JOB_TYPE_MISMATCH')
        digest=job.get('plan_sha256')
        if not isinstance(digest,str) or not re.fullmatch(r'[0-9a-f]{64}',digest):
            raise RuntimeError('ORIGINAL306_V91_PLAN_SHA_INVALID')
        planfile=DYNAMIC_PLAN_STORE/(digest+'.json')
        plan=checked_json(planfile,DYNAMIC_PLAN_STORE)
        result=module.inspect(index,entry,job,plan)
        if (result.get('eligible_to_claim') is not False
            or result.get('execution_authorized') is not False
            or result.get('original313_certified') is not False):
            raise RuntimeError('ORIGINAL306_V91_OBSERVER_CHANGED_AUTHORITY')
        return result
    except Exception as exc:
        print('ORIGINAL306_V91_HOST_ANCHOR_PENDING_HELD:'
              +safe_capability_error(exc),file=sys.stderr)
        return {'state':'HOST_V2_PENDING_INDEPENDENT_SIGNER_HOLD',
                'eligible_to_claim':False,'execution_authorized':False,
                'anchor_issued':False,'original313_certified':False}


'''

BRANCH="""  j=cli_json(['--user='+str(actor),'eval',code])
  if (j.get('command_format')=='host.anchor.ref.v2'
      or j.get('operation') in (
          'company.host-authority.anchor.issue',
          'company.host-authority.anchor.revoke')):
   # Recognize the original typed Host Anchor operation, but never claim or
   # direct it to generic WPCLI/Host Source without a separately signed
   # issuer, signed Owner evidence and independent Original313 high-water.
   firstparty_host_v2_pending_observation(actor,entry)
   continue
  if not (j.get('operation')=='company.remote.wpcli.full'"""


def render(original:bytes)->bytes:
    digest=hashlib.sha256(original).hexdigest()
    if digest!=PREIMAGE_SHA256:
        raise ValueError('ORIGINAL306_V91_LIVE_PREIMAGE_CHANGED:'+digest)
    text=original.decode('utf-8')
    if text.count(INSERT_FUNCTION)!=1 or text.count(INSERT_BRANCH)!=1:
        raise ValueError('ORIGINAL306_V91_WORKER_ANCHORS_NOT_UNIQUE')
    text=text.replace(INSERT_FUNCTION,FUNCTION+INSERT_FUNCTION,1)
    text=text.replace(INSERT_BRANCH,BRANCH,1)
    result=text.encode()
    compile(result,'original306-worker-v91.py','exec')
    if result.count(b'firstparty_host_v2_pending_observation')!=2:
        raise ValueError('ORIGINAL306_V91_INSERTION_COUNT_INVALID')
    return result

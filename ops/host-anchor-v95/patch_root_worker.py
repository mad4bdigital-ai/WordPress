#!/usr/bin/env python3
"""Exact-CAS additive Original306 root Worker first-bootstrap *claim only*.

Never permit this module to execute Original312/313, sign Host Anchor options,
approve Owner scopes or change the Root execution_enabled manifests.
"""
from __future__ import annotations
import hashlib

PREIMAGE_SHA256="4157214d1615f85093bdd7a54246cea87a25e79df8b40f6a27f8d4f434c44f5b"
ROOT_MODULE_SHA256="ebb3fb1efe58a1506516b7d8b76f2ff4281b3f2b0c0ee8361b606dcfa3240b64"
POINT="def firstparty_host_v2_pending_observation(actor, entry):"
BEFORE="""   firstparty_host_v2_pending_observation(actor,entry)
   continue
  if not (j.get('operation')=='company.remote.wpcli.full'"""

FUNCTION='''def firstparty_host_v95_first_claim_only(actor,entry):
    """Only Original305 owner + Original328 + root plan gets claim reservation."""
    jid=entry.get('job_id')
    if not isinstance(jid,str) or not HEX32.fullmatch(jid):
        return False
    if entry.get('company')!='all-royal-egypt' or entry.get('environment')!='staging':
        return False
    try:
        import stat
        file=DYNAMIC_REGISTRY_ROOT/'host-anchor-v95/original306_claim_only.py'
        st=file.lstat()
        if (file.is_symlink() or not stat.S_ISREG(st.st_mode)
            or st.st_uid!=0 or st.st_gid!=0 or st.st_nlink!=1
            or stat.S_IMODE(st.st_mode)!=0o600
            or hashlib.sha256(file.read_bytes()).hexdigest()!=
              '__PIN_MODULE_SHA__'):
            raise RuntimeError('ORIGINAL306_V95_ROOT_VERIFIER_PIN_CHANGED')
        spec=importlib.util.spec_from_file_location('original306_v95_claim_only',file)
        if spec is None or spec.loader is None:
            raise RuntimeError('ORIGINAL306_V95_ROOT_VERIFIER_NOT_INSTALLED')
        module=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        if str(DYNAMIC_REGISTRY_ROOT) not in sys.path:
            sys.path.insert(0,str(DYNAMIC_REGISTRY_ROOT))
        from registry import Registry,checked_json
        # Existing WordPress Original305/306 and Original318, read-only.
        # Never use --user to impersonate an Owner's approval for a write.
        php=('$id="'+jid+'";'
          '$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
          '$r=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id,false);'
          '$g=is_array($r)?get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.($r["grant_id"]??""),false):false;'
          '$slot=get_option(MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT."all-royal-egypt_staging",false);'
          '$p=is_array($slot)?get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.($slot["grant_id"]??""),false):false;'
          '$t=is_array($slot)?get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.($slot["transport_id"]??""),false):false;'
          '$a=MAD4B_Native_Host_Exact_Grant_V1::current_anchor_readback_v2("all-royal-egypt","staging");'
          '$present=get_option(MAD4B_Native_Host_Exact_Grant_V1::DYNAMIC_ANCHOR_PREFIX."all-royal-egypt_staging",false)!==false;'
          '$active=is_array($p)&&is_array($t)&&MAD4B_Original_Remote_Hostinger_328_V1::active($t,$p);'
          'echo wp_json_encode(["job"=>$j,"request"=>$r,"grant"=>$g,'
          '"parent"=>$p,"transport"=>$t,'
          '"site"=>["original328_active"=>$active,'
          '"anchor_option_present"=>$present,"anchor_readback"=>$a]],'
          'JSON_UNESCAPED_SLASHES);')
        current=cli_json(['eval',php],timeout=30)
        if not isinstance(current,dict):
            raise RuntimeError('ORIGINAL306_V95_WP_READBACK_MISSING')
        job=current.get('job')
        if not isinstance(job,dict) or job.get('job_id')!=jid:
            raise RuntimeError('ORIGINAL306_V95_ORIGINAL_JOB_DRIFT')
        digest=job.get('plan_sha256')
        if not isinstance(digest,str) or not re.fullmatch(r'[0-9a-f]{64}',digest):
            raise RuntimeError('ORIGINAL306_V95_JOB_PLAN_INVALID')
        index=Registry(DYNAMIC_REGISTRY_ROOT)
        plan=checked_json(DYNAMIC_PLAN_STORE/(digest+'.json'),DYNAMIC_PLAN_STORE)
        approved=module.verify_against_originals(
          index,entry,job,plan,current.get('request'),current.get('grant'),
          current.get('parent'),current.get('transport'),current.get('site'))
        return (approved.get('state')==
          'VERIFIED_ORIGINAL305_OWNER_BOOTSTRAP__CLAIM_ONLY'
          and approved.get('non_authorizing_signed_broker_claim_allowed') is True
          and approved.get('execution_authorized') is False
          and approved.get('anchor_issued') is False
          and approved.get('original313_certified') is False)
    except Exception as exc:
        print('ORIGINAL306_V95_FIRST_BOOTSTRAP_CLAIM_HELD:'
              +safe_capability_error(exc),file=sys.stderr)
        return False


'''
AFTER="""   if firstparty_host_v95_first_claim_only(actor,entry):
    # This returns the ordinary signed Original306 *Claim*, not an Anchor.
    # Original312/313 execution remains separately disabled in the Registry.
    return sign_existing_original_306_claim(job,entry)
   firstparty_host_v2_pending_observation(actor,entry)
   continue
  if not (j.get('operation')=='company.remote.wpcli.full'"""

def render(before: bytes)->bytes:
    actual=hashlib.sha256(before).hexdigest()
    if actual!=PREIMAGE_SHA256:
        raise ValueError('ORIGINAL306_V95_ROOT_WORKER_PREIMAGE_CHANGED:'+actual)
    source=before.decode()
    if source.count(POINT)!=1 or source.count(BEFORE)!=1:
        raise ValueError('ORIGINAL306_V95_PATCH_POINT_UNAVAILABLE')
    source=source.replace(POINT,FUNCTION+POINT,1).replace(BEFORE,AFTER,1)
    source=source.replace('__PIN_MODULE_SHA__',ROOT_MODULE_SHA256)
    binary=source.encode()
    compile(binary,'original306-worker-v95.py','exec')
    if binary.count(b'firstparty_host_v95_first_claim_only')!=2:
        raise ValueError('ORIGINAL306_V95_PATCH_COUNT_INVALID')
    return binary

#!/usr/bin/env python3
"""One-shot claimed original 306 Job consumer for exact read-only 313 canaries.
No Jobs, Claims, Grants or target commands may be created by this worker.
"""
from pathlib import Path
import base64,hashlib,importlib.util,json,os,re,secrets,subprocess,sys,time
ROOT=Path('/opt/mad4b-company-transport/native-313-independent')
sys.path.insert(0, '/opt/mad4b-company-transport')
from central_wpcli_runtime import central_wp
WP=central_wp
HEX32=re.compile('^[a-f0-9]{32}$')
DIGEST=hashlib.sha256(b'["option","get","home"]').hexdigest()

CANDIDATE=Path('/opt/mad4b-company-transport/native-313-independent/plugin-install-v1')
SCHEMA_CANDIDATE=ROOT/'schema-readback-v1'
MU_CANDIDATE=Path('/opt/mad4b-company-transport/native-313-independent/mu-deploy-v1')
FULL_CANDIDATE=ROOT/'full-wpcli-v1'
FULL_WORKER=FULL_CANDIDATE/'original-option-wpcli-executor.py'
FULL_INDEPENDENT=FULL_CANDIDATE/'independent-option-readback.py'
FULL_RESUME=FULL_CANDIDATE/'resume-original-option-313.py'
PRIVATE_DRAFT_ROOT=ROOT/'private-draft-v1'
PRIVATE_DRAFT_EXECUTOR=PRIVATE_DRAFT_ROOT/'original-private-draft-executor.py'
PRIVATE_DRAFT_READER=PRIVATE_DRAFT_ROOT/'independent-private-draft-readback.py'
POSTMETA_V6_EXECUTOR=PRIVATE_DRAFT_ROOT/'original-postmeta-v6-executor.py'
POSTMETA_V6_READER=PRIVATE_DRAFT_ROOT/'independent-postmeta-313-v6.py'


BRIDGE_SCRIPTS=Path('/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/scripts')
HOST_REGISTRY=Path('/opt/mad4b-company-transport/native-operator-v1/target-runtime-bindings.v1.json')
# MAD4B_DYNAMIC_PROVIDER_V1_BEGIN
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

DYNAMIC_313_SIGNER=ROOT/'root-only-verifier-ed25519.key'
DYNAMIC_313_FINALIZER=BRIDGE_SCRIPTS/'original_dynamic_313_finalizer.py'
DYNAMIC_313_RESUMER=BRIDGE_SCRIPTS/'original_dynamic_313_resumer.py'
DYNAMIC_313_TIMERS=('mad4b-company-original-313-worker.timer',
                    'mad4b-original-dynamic-313-resumer.timer')

def dynamic_host_313_components_ready():
    # Host proof is independent of Central 313. Both must be available;
    # neither substitutes for the exact Job's signed 312 and release Grant.
    try:
        if os.geteuid()!=0:return False
        import stat
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
        from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
        provider=dynamic_provider_registry()
        if provider is None:return False
        index,_,_=provider
        m=index.resolve('company.code.release')
        if m.get('version',0)<3 or m.get('evidence_schema')!='code_release.v2':
            return False
        for path in (DYNAMIC_313_FINALIZER,DYNAMIC_313_RESUMER):
            info=path.lstat()
            if (not stat.S_ISREG(info.st_mode) or path.is_symlink() or
                info.st_uid!=0 or info.st_mode&0o022):
                return False
        keydata=DYNAMIC_313_SIGNER.lstat()
        if (not stat.S_ISREG(keydata.st_mode) or DYNAMIC_313_SIGNER.is_symlink()
            or keydata.st_uid!=0 or keydata.st_mode&0o077 or
            keydata.st_size!=32):
            return False
        key=Ed25519PrivateKey.from_private_bytes(DYNAMIC_313_SIGNER.read_bytes())
        if (key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)!=
            index.independent_verifier_public_key):
            return False
        for path in (DYNAMIC_RELEASE_PLANS,DYNAMIC_RELEASE_GRANTS,
                     ROOT/'receipts',ROOT/'reserved',DYNAMIC_PLAN_STORE):
            info=path.lstat()
            if (not stat.S_ISDIR(info.st_mode) or path.is_symlink()
                or info.st_uid!=0 or info.st_mode&0o077):
                return False
        policy=Path('/etc/mad4b/company-code-release-policy.json')
        info=policy.lstat()
        if (not stat.S_ISREG(info.st_mode) or policy.is_symlink()
            or info.st_uid!=0 or info.st_mode&0o077):
            return False
        for unit in DYNAMIC_313_TIMERS:
            status=subprocess.run(
                ['/usr/bin/systemctl','is-active',unit],
                capture_output=True,text=True,timeout=4,check=False)
            if status.returncode!=0 or status.stdout.strip()!='active':
                return False
        return True
    except Exception as exc:
        print('DYNAMIC_ORIGINAL_313_HOST_READINESS_UNAVAILABLE:'+
              type(exc).__name__,file=sys.stderr)
        return False

def dynamic_313_original_central_ready(actor):
    # Both independent Host signer and original Central v4 must be live.
    if not dynamic_host_313_components_ready():return False
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

def company_code_release_grant_ready_legacy(job,command_sha):
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

def dynamic_release_grant_ready(job,command_sha):
    """Registered, content-pinned, operation-specific preclaim admission.

    Still called BEFORE an original 306 signed claim and reservation. This
    candidate is not an executable 313 release. An operation lacking its
    own signed provider grant, first-party evidence and signed 312 readiness
    is denied. Existing code-release admission remains exactly unchanged.
    """
    if (not isinstance(job,str) or not HEX32.fullmatch(job) or
        not isinstance(command_sha,str) or not re.fullmatch(r'[a-f0-9]{64}',command_sha)):
        return False
    if ((ROOT/'reserved'/(job+'.reserved')).exists() or
        (ROOT/'receipts'/(job+'.json')).exists()):
        return False
    try:
        provider=dynamic_provider_registry()
        if provider is None:return False
        index,_,_=provider
        from worker_adapter import indexed_plans
        entry=indexed_plans(index,DYNAMIC_PLAN_STORE).get(command_sha)
        if not isinstance(entry,dict):return False
        operation=entry.get('operation')
        if operation=='company.code.release':
            return company_code_release_grant_ready_legacy(job,command_sha)
        if operation!='plugin.license.activate':return False
        manifest=index.resolve(operation)
        deps={x['path']:x['sha256'] for x in manifest.get('dependencies',[])}
        suffix='plugin-license-native-operation-v1/plugin_license_host_preclaim.py'
        digest=deps.get(suffix)
        if not digest:return False
        source=index.root/suffix
        # Must be pinned by the actual installed first-party registry.
        from registry import sha,protected_file
        if sha(protected_file(source,index.root,maximum=2_000_000))!=digest:
            return False
        directory=str(source.parent)
        if directory not in sys.path:sys.path.insert(0,directory)
        import plugin_license_host_preclaim as admission
        if Path(getattr(admission,'__file__',''))!=source:return False
        from license_operation_contract import validate_plan
        plan=validate_plan(entry['plan'])
        # This reads a root-protected signed, nonsecret 306 Broker grant and
        # issuer channel-intent. It does NOT read a license value.
        row=admission.verify_root_proofs(plan,job,command_sha)
        # The issuer's assertion of vendor entitlement is NOT an independent
        # vendor seat check; do not claim an Original 306 Job until a pinned
        # vendor-specific verifier is enrolled. This intentional block keeps
        # the existing production credential-channel restriction intact.
        return bool(row.get('vendor_entitlement_independently_verified',False))
    except Exception as exc:
        print('DYNAMIC_REGISTERED_PROVIDER_GRANT_NOT_READY:'+safe_capability_error(exc),
              file=sys.stderr)
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
          '$c=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false);'
          'echo wp_json_encode(["job"=>is_array($j)?$j:[],'
          '"claim"=>is_array($c)?$c:[]]);')
    original=cli_json(['--user='+str(actor),'eval',code],timeout=30)
    j=original.get('job')
    p=original.get('claim')
    if not isinstance(j,dict) or not isinstance(p,dict):
        raise RuntimeError('DYNAMIC_ORIGINAL_CLAIM_RECORD_MISSING')
    j=dict(j);p=dict(p)
    if j.get('job_id')!=job or p.get('job_id')!=job:
        raise RuntimeError('DYNAMIC_ORIGINAL_CLAIM_SCOPE_MISMATCH')
    j['origin']=j.get('canonical_origin')
    # Do not synthesize missing identity, host or expiry fields: the
    # first-party original 306/312 authority must provide them.
    out=execute_claimed(j,p)
    if out.get('state')!='HOST_EXECUTED_PENDING_INDEPENDENT_ORIGINAL_313' or (
        out.get('original_313_certified') is not False):
        raise RuntimeError('DYNAMIC_ORIGINAL_313_READBACK_PENDING')
    return out
# MAD4B_DYNAMIC_PROVIDER_V1_END

def exact_host_targets():
 st=HOST_REGISTRY.lstat()
 import stat
 if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or
     st.st_mode&0o077 or HOST_REGISTRY.is_symlink()):
  raise RuntimeError('ORIGINAL_HOST_TARGET_REGISTRY_UNTRUSTED')
 doc=json.loads(HOST_REGISTRY.read_text())
 if (doc.get('contract')!='mad4b.company-native-target-runtime-bindings.v1' or
     doc.get('host_id')!='srv2030908' or not isinstance(doc.get('targets'),dict)):
  raise RuntimeError('ORIGINAL_HOST_TARGET_CONTRACT_INVALID')
 return doc['targets']

def generic_provider_files_ready():
 return all(p.is_file() and not p.is_symlink() and p.stat().st_uid==0
            for p in (FULL_WORKER,FULL_INDEPENDENT,FULL_RESUME,
                      BRIDGE_SCRIPTS/'verify_queued_original_305_argv.py',
                      BRIDGE_SCRIPTS/'materialize_original_305_argv.py'))

def generic_preclaim(job):
 if not generic_provider_files_ready():return False
 f=BRIDGE_SCRIPTS/'verify_queued_original_305_argv.py'
 try:
  spec=importlib.util.spec_from_file_location('original_full_wpcli_queued_seal',f)
  module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
  row=module.preclaim(job)
  return row.get('state')=='ORIGINAL_305_ENCRYPTED_ARGV_READY_FOR_SIGNED_306_CLAIM' and row.get('provider')=='wpcli.option.update'
 except Exception as exc:
  print('FULL_WPCLI_QUEUED_SIGNED_SEAL_DEFERRED:'+safe_capability_error(exc),file=sys.stderr)
  return False

def generic_signed_receipt_resume_available(job,command_sha):
 if not generic_provider_files_ready() or not HEX32.fullmatch(job):return False
 receipt=ROOT/'receipts'/(job+'.json')
 reservation=ROOT/'reserved'/(job+'.reserved')
 try:
  import stat
  for p in (receipt,reservation):
   st=p.lstat()
   if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o077 or p.is_symlink():
    return False
  if reservation.read_bytes().strip()!=b'ORIGINAL_305_312_SIGNED_FULL_WPCLI_MUTATION_RESERVED':return False
  data=json.loads(receipt.read_text())
  return (data.get('job_id')==job and
          data.get('command_sha256')==command_sha and
          data.get('operation')=='wpcli.option.update' and
          data.get('job_mutating') is True and
          data.get('state')=='EXECUTED_PENDING_INDEPENDENT_READBACK')
 except (OSError,TypeError,ValueError):return False

def generic_claimed_job_ready(job):
 if not generic_provider_files_ready():return False
 try:
  f=BRIDGE_SCRIPTS/'materialize_original_305_argv.py'
  spec=importlib.util.spec_from_file_location('original_full_wpcli_claimed_materialize',f)
  module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
  sealed=FULL_CANDIDATE/'sealed'/(job+'.json')
  if not sealed.exists():
   result=module.publish(job)
   if result.get('state')!='ORIGINAL_305_306_312_EXACT_ARGV_MATERIALIZED':
    raise RuntimeError('ORIGINAL_312_FULL_ARGV_SIGNATURE_NOT_MATERIALIZED')
  verify=BRIDGE_SCRIPTS/'original_option_wpcli_executor.py'
  spec=importlib.util.spec_from_file_location('original_full_option_verify',verify)
  writer=importlib.util.module_from_spec(spec);spec.loader.exec_module(writer)
  from sys import path as ppaths
  sourcepath='/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/src'
  if sourcepath not in ppaths:ppaths.insert(0,sourcepath)
  from reconcile.full_wpcli_preflight import preflight,root_manifest
  from reconcile.signed_wpcli import validate_seal
  from reconcile.full_wpcli_preflight import PUB,AUTH,HOST,root_file
  seal=root_manifest(job)
  original=module.original_module(AUTH,'generic_confirm_current_original_312')
  a=original.verify(job,HOST,seal['command_sha256'])
  signer=base64.b64decode(root_file(PUB,128).strip(),validate=True)
  verified=validate_seal(seal,a,signer,now=int(time.time()))
  preflight(job)
  writer.check_option_signature(verified)
  return True
 except Exception as exc:
  print('FULL_WPCLI_CLAIMED_312_PROVIDER_DEFERRED:'+safe_capability_error(exc),file=sys.stderr)
  return False

def private_draft_original_313_v5_ready(actor):
    # Verify Central's loaded *original* 313 v5 provider, not a local
    # capability flag. No job is claimed if independent proof is missing.
    for path in (PRIVATE_DRAFT_EXECUTOR,PRIVATE_DRAFT_READER):
        try:
            st=path.lstat()
            import stat
            if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0
                or st.st_mode & 0o077 or path.is_symlink()):
                return False
        except OSError:
            return False
    code=(
        '$c="MAD4B_Native_WPCLI_Receipt_Verifier_V1";'
        '$ok=class_exists($c,false) && is_callable([$c,"supports_private_draft_v5"])'
        ' && $c::supports_private_draft_v5();'
        'echo wp_json_encode(["contract"=>"mad4b.private-draft-313-ready.v1",'
        '"ready"=>$ok]);'
    )
    try:
        row=cli_json(['--user='+str(actor),'eval',code],timeout=22)
        return row.get("contract")=="mad4b.private-draft-313-ready.v1" and row.get("ready") is True
    except Exception:
        return False


def postmeta_v6_ready(actor):
    # v6 is signed by the Original independent verifier, never inferred from
    # successful v5 option writes or a locally configured policy switch.
    for file in (POSTMETA_V6_EXECUTOR,POSTMETA_V6_READER):
        try:
            import stat
            st=file.lstat()
            if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0
                or st.st_mode & 0o022 or file.is_symlink()):
                return False
        except OSError:
            return False
    code=(
        '$c="MAD4B_Native_WPCLI_Receipt_Verifier_V1";'
        '$ready=class_exists($c,false) && is_callable([$c,"supports_draft_postmeta_v6"])'
        ' && $c::supports_draft_postmeta_v6();'
        'echo wp_json_encode(["contract"=>"mad4b.native313-v6-postmeta-ready.v1",'
        '"ready"=>$ready]);'
    )
    try:
        row=cli_json(['--user='+str(actor),'eval',code],timeout=22)
    except Exception:
        return False
    return (row.get("contract")=="mad4b.native313-v6-postmeta-ready.v1"
            and row.get("ready") is True)


def postmeta_queued_ready(job,actor):
    if (not HEX32.fullmatch(job)
        or not postmeta_v6_ready(actor)
        or (ROOT/'reserved'/(job+'.reserved')).exists()
        or (ROOT/'receipts'/(job+'.json')).exists()):
        return False
    try:
        spec=importlib.util.spec_from_file_location(
            'original_signed_postmeta_v6_preclaim',
            BRIDGE_SCRIPTS/'verify_queued_original_305_argv.py')
        m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
        row=m.preclaim(job)
        return (
            row.get("provider")=="wpcli.draft_postmeta.first_touch"
            and row.get("operation") in ("content.write","commerce.write")
            and row.get("state")==
              "ORIGINAL_TYPED_DRAFT_WITNESS_VERIFIED_312_313_NOT_ENROLLED"
            and row.get("eligible_to_claim") is False
            and row.get("eligible_to_execute") is False
        )
    except Exception as exc:
        print("ORIGINAL_305_POSTMETA_V6_PRECLAIM_DEFERRED:"
              +safe_capability_error(exc),file=sys.stderr)
        return False


def postmeta_claimed_ready(job,actor,digest):
    if (not HEX32.fullmatch(job) or not isinstance(digest,str)
        or not re.fullmatch(r"[a-f0-9]{64}",digest)
        or not postmeta_v6_ready(actor)
        or (ROOT/'reserved'/(job+'.reserved')).exists()
        or (ROOT/'receipts'/(job+'.json')).exists()):
        return False
    try:
        spec=importlib.util.spec_from_file_location(
            'original_postmeta_v6_materializer',
            BRIDGE_SCRIPTS/'materialize_original_305_argv.py')
        m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
        original=m.source_job(job)
        request=original.get("request")
        row=original.get("job")
        if (not isinstance(request,dict) or not isinstance(row,dict)
            or row.get("domain_intent") not in ("content.write","commerce.write")
            or row.get("job_id")!=job
            or row.get("state")!="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
            or row.get("command_sha256")!=digest
            or row.get("executed") is not False):
            return False
        manifest_path=FULL_CANDIDATE/'sealed'/(job+'.json')
        if not manifest_path.exists():
            made=m.publish(job)
            if made.get("state")!="ORIGINAL_305_306_312_EXACT_ARGV_MATERIALIZED":
                return False
        from reconcile.full_wpcli_preflight import (
            preflight,root_manifest,root_file,PUB,AUTH,HOST,
        )
        from reconcile.signed_wpcli import validate_seal
        manifest=root_manifest(job)
        if manifest.get("command_sha256")!=digest:
            return False
        signed=m.original_module(AUTH,"original_postmeta_v6_312_authority")
        original_auth=signed.verify(job,HOST,digest)
        pub=base64.b64decode(root_file(PUB,128).strip(),validate=True)
        seal=validate_seal(manifest,original_auth,pub,now=int(time.time()))
        argv=list(seal.argv)
        if (len(argv)!=7 or argv[:3]!=["post","meta","add"]
            or argv[6]!="--unique"
            or argv[4] not in (
                "_mad4b_content_draft_review","_mad4b_commerce_draft_policy")
            or not re.fullmatch(r"[1-9][0-9]{0,9}",argv[3])
            or int(argv[3])>2147483647
            or list(seal.readback_argv)!=[
                "post","meta","get",argv[3],argv[4]]
            or list(seal.rollback_argv)!=[
                "post","meta","delete",argv[3],argv[4]]):
            return False
        intent=("content.write" if argv[4]=="_mad4b_content_draft_review"
                else "commerce.write")
        scoped="postmeta:"+argv[3]+":"+argv[4]+":"+job
        if (row.get("domain_intent")!=intent
            or row.get("domain_resource_key")!=scoped
            or request.get("domain_resource_key")!=scoped
            or request.get("domain_intent")!=intent
            or row.get("domain_witness_sha256")!=request.get("domain_witness_sha256")):
            return False
        preflight(job)
        return True
    except Exception as exc:
        print("ORIGINAL_POSTMETA_V6_312_PRECLAIM_DEFERRED:"
              +safe_capability_error(exc),file=sys.stderr)
        return False


def private_draft_queued_ready(job,actor):
    if not HEX32.fullmatch(job) or not private_draft_original_313_v5_ready(actor):
        return False
    if ((ROOT/'reserved'/(job+'.reserved')).exists()
        or (ROOT/'receipts'/(job+'.json')).exists()):
        return False
    try:
        source=BRIDGE_SCRIPTS/'verify_queued_original_305_argv.py'
        spec=importlib.util.spec_from_file_location('original_typed_draft_preclaim',source)
        module=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        row=module.preclaim(job)
        return (row.get('state')=='ORIGINAL_TYPED_DRAFT_WITNESS_VERIFIED_312_313_NOT_ENROLLED'
                and row.get('operation') in ('strategy.write','operations.write')
                and row.get('eligible_to_claim') is False
                and row.get('eligible_to_execute') is False
                and row.get('provider')=='wpcli.private_draft.first_touch')
    except Exception as exc:
        print('ORIGINAL_PRIVATE_DRAFT_305_PRECLAIM_DEFERRED:'+safe_capability_error(exc),file=sys.stderr)
        return False


def private_draft_claimed_ready(job,actor,expected_sha):
    # Original 306 was claimed; only the original 312 may mint a signed
    # manifest for this precise Job and command digest. No target writes.
    if not HEX32.fullmatch(job) or not private_draft_original_313_v5_ready(actor):
        return False
    if ((ROOT/'reserved'/(job+'.reserved')).exists()
        or (ROOT/'receipts'/(job+'.json')).exists()):
        return False
    try:
        f=BRIDGE_SCRIPTS/'materialize_original_305_argv.py'
        spec=importlib.util.spec_from_file_location('original_private_claimed_materializer',f)
        module=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        source=module.source_job(job)
        j=source.get('job',{})
        if (j.get('domain_intent') not in ('strategy.write','operations.write')
            or j.get('command_sha256')!=expected_sha
            or j.get('state')!='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
            or j.get('executed') is not False):
            return False
        manifest=FULL_CANDIDATE/'sealed'/(job+'.json')
        if not manifest.exists():
            output=module.publish(job)
            if output.get('state')!='ORIGINAL_305_306_312_EXACT_ARGV_MATERIALIZED':
                return False
        from reconcile.full_wpcli_preflight import preflight,root_manifest
        from reconcile.signed_wpcli import validate_seal
        from reconcile.full_wpcli_preflight import PUB,AUTH,HOST,root_file
        source_manifest=root_manifest(job)
        authority_module=module.original_module(AUTH,'original_private_worker_312_verify')
        a=authority_module.verify(job,HOST,expected_sha)
        if source_manifest.get('command_sha256')!=expected_sha:
            return False
        signer=base64.b64decode(root_file(PUB,128).strip(),validate=True)
        seal=validate_seal(source_manifest,a,signer,now=int(time.time()))
        if not (len(seal.argv)==5
                and seal.argv[:2]==('option','add')
                and seal.argv[-1]=='--autoload=off'
                and (seal.argv[2]=='mad4b_strategy_draft_'+job
                    or seal.argv[2]=='mad4b_operations_draft_'+job)):
            return False
        preflight(job)
        return True
    except Exception as exc:
        print('ORIGINAL_PRIVATE_DRAFT_312_PRECLAIM_DEFERRED:'+safe_capability_error(exc),file=sys.stderr)
        return False


def operation_digests():
 options={DIGEST:'read'}
 # Exact read-only schema command; only an enrolled owner 305 Job is accepted.
 if (SCHEMA_CANDIDATE/'schema-contract.py').is_file() and (SCHEMA_CANDIDATE/'original-wpcli-executor.py').is_file() and (SCHEMA_CANDIDATE/'independent-readback.py').is_file():
  spec=importlib.util.spec_from_file_location('original_schema_313_readback',SCHEMA_CANDIDATE/'schema-contract.py')
  schema=importlib.util.module_from_spec(spec);spec.loader.exec_module(schema)
  options[schema.DIGEST]='schema.readback'
 try:
  spec=importlib.util.spec_from_file_location('candidate_plugin_install',CANDIDATE/'native_plugin_install_runtime.py')
  plugin=importlib.util.module_from_spec(spec);spec.loader.exec_module(plugin)
  options[plugin.command_hash()]='plugin.install'
 except Exception:
  pass # Optional operation cannot break the existing read-only worker.
 try:
  spec=importlib.util.spec_from_file_location('original_mu_deploy',
      MU_CANDIDATE/'native_mu_deploy_runtime.py')
  mu=importlib.util.module_from_spec(spec);spec.loader.exec_module(mu)
  options[mu.command_hash()]='mu-plugin.deploy'
 except Exception:
  pass # Not enrolled without a verified exact MU artifact + plan.
 try:
  for digest,operation in dynamic_operation_digests().items():
   if digest in options:raise RuntimeError('DYNAMIC_COMMAND_HASH_COLLISION')
   options[digest]=operation
 except Exception as exc:
  print('DYNAMIC_REGISTRY_DISCOVERY_DEFERRED:'+safe_capability_error(exc),
        file=sys.stderr)
 return options

class TransientCentralReadBusy(RuntimeError):pass

def is_retryable_central_capacity(exc):
    """Only exact central capacity codes, never authorization denials."""
    return isinstance(exc,TransientCentralReadBusy) or (
        isinstance(exc,RuntimeError) and str(exc) in (
            'CENTRAL_WORDPRESS_BUSY',
            'WPCLI_DYNAMIC_ACTOR_BUSY',
            'CENTRAL_WPCLI_SLOT_BUSY_DEFERRED'))


def unreserved_schema_preflight_busy(job,operation,returncode,stderr):
    """Only a provably unreserved read-only Schema preflight may defer.

    A host reservation or receipt may mean the one-shot target command ran;
    never convert such an error to a retryable execution failure.
    """
    if operation!='schema.readback' or returncode!=2 or not HEX32.fullmatch(job):
        return False
    if (ROOT/'reserved'/(job+'.reserved')).exists() or (ROOT/'receipts'/(job+'.json')).exists():
        return False
    return bool(re.search(
        r'(?:^|\n)ORIGINAL_NATIVE_313_STOPPED:'
        r'(?:CENTRAL_WORDPRESS_BUSY|WPCLI_DYNAMIC_ACTOR_BUSY|CENTRAL_WPCLI_SLOT_BUSY_DEFERRED)\s*$',
        str(stderr or '')))


def prioritize_claimed_work(work, claimed, now):
    """Finish already-executed Schema proof first only near expiry.

    This is ordering, not authority: all 312 checks, receipt equality and
    original expiry guards still run in the independently signed executor.
    """
    expiry={r.get('job_id'):r.get('expires_epoch') for r in claimed
            if isinstance(r,dict) and isinstance(r.get('expires_epoch'),int)}
    def priority(item):
        job,operation=item
        remaining=expiry.get(job,0)-now
        if operation in ('schema.readback.resume','wpcli.option.update.resume') and 190<remaining<=600:
            return (0,remaining)
        if operation not in ('schema.readback.resume','wpcli.option.update.resume'):
            return (1,0)
        return (2,0)
    return sorted(work,key=priority)


def safe_capability_error(exc):
    # Preserve bounded machine status only; never log signed payloads or secrets.
    code=str(exc)
    return code if re.fullmatch(r'[A-Z][A-Z0-9_]{3,95}',code) else type(exc).__name__

def safe_executor_failure(returncode, stderr):
 """Expose only a bounded machine error code, never a signed payload."""
 match=re.search(r'(ORIGINAL_NATIVE_313_STOPPED|ORIGINAL_SCHEMA_RECEIPT_ONLY_STOPPED|INDEPENDENT_313_READBACK_DENIED):([A-Z][A-Z0-9_]{3,96})',str(stderr or ''))
 reason=match.group(2) if match else 'NO_MACHINE_REASON_REPORTED'
 return 'ORIGINAL_EXECUTOR_EXIT_'+str(int(returncode))+'_RECEIPT_RECONCILIATION_REQUIRED:'+reason

def native_receipt_inventory_count():
 """Observational only: immutable host receipts, not 313 certificates."""
 path=ROOT/'receipts'
 if not path.is_dir() or path.is_symlink():return 0
 count=0
 for f in sorted(path.glob('*.json'))[:200]:
  try:
   if f.is_symlink() or f.stat().st_uid!=0 or f.stat().st_mode&0o077 or f.stat().st_size>16000:continue
   item=json.loads(f.read_text())
   if item.get('state')=='EXECUTED_PENDING_INDEPENDENT_READBACK' and item.get('job_id')==f.stem:
    count+=1
  except (OSError,ValueError,TypeError):continue
 return count

def cli_json(args,timeout=30):
 for attempt in range(3):
  try:
   p=subprocess.run(WP()+args,capture_output=True,text=True,timeout=timeout,check=False)
  except subprocess.TimeoutExpired as exc:
   # A read of Broker inventory timed out before the worker could claim.
   # Do not mark the Job failed or replay uncertain remote execution.
   raise TransientCentralReadBusy('ORIGINAL_CENTRAL_WPCLI_READ_TIMEOUT') from exc
  if p.returncode==0:
   return json.loads(p.stdout.strip().splitlines()[-1])
  if 'STAGING_WPCLI_BUSY' not in (p.stderr or ''):
   raise RuntimeError('ORIGINAL_BROKER_WPCLI_DENIED')
  time.sleep(attempt+1)
 raise TransientCentralReadBusy('ORIGINAL_CENTRAL_WPCLI_READ_SLOT_BUSY')

def firstparty_host_source_claim_preflight(actor,entry):
    """Resolve only original root-enrolled Host provider and current 305/306.

    Returns False if any FIRST-PARTY signed Host setup is absent. Does not
    claim a Job, sign Host312, publish a source file, or grant Host permission.
    The existing original306 signer remains the only claim issuer.
    """
    if (entry.get('company')!='tours-central' or
        entry.get('environment')!='staging' or
        entry.get('broker_host_id')!='srv2030908' or
        entry.get('target_host_id')!='srv2030908' or
        not isinstance(entry.get('job_id'),str) or
        not HEX32.fullmatch(entry['job_id'])):
        return False
    try:
        if str(DYNAMIC_REGISTRY_ROOT) not in sys.path:
            sys.path.insert(0,str(DYNAMIC_REGISTRY_ROOT))
        from registry import protected_file,checked_json,Registry
        index=Registry(DYNAMIC_REGISTRY_ROOT)
        # Avoid WPCLI traffic for Central Host until a separately pinned
        # genuine first-party Host provider + signer are both enrolled.
        if (not isinstance(getattr(index,'host_resource_312_public_key',None),bytes)
            or not any(m.get('source_family')==
                       'mad4b.native-host-source-release.v1'
                       for m in index.manifests.values())):
            return False
        job_id=entry['job_id']
        # The exact original WP Job chooses the registered provider;
        # don't maintain a static list of Host operation names.
        php=('$id="'+job_id+'";'
             '$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
             'echo wp_json_encode(is_array($j)?$j:[]);')
        job=cli_json(['--user='+str(actor),'eval',php],timeout=30)
        if not isinstance(job,dict):return False
        manifest=index.resolve(job.get('operation'))
        if (manifest.get('source_family')!='mad4b.native-host-source-release.v1'
            or manifest.get('target_scope')!='host' or
            manifest.get('job_type')!='signed_vps_host_source' or
            manifest.get('command',{}).get('format')!='host.source.ref.v1' or
            not isinstance(getattr(index,'host_resource_312_public_key',None),bytes)):
            return False
        # The original Registry has already checked all transitive source
        # SHA pins. Still require the exact Host worker admission dependency,
        # so a weaker WordPress-only Manifest never invokes this path.
        required={'dynamic-operation-registry-v1/host_admission.py',
                  'native-source-publisher-v1/host_original306_worker_lane.py',
                  'native-source-publisher-v1/native_source_publisher.py',
                  'native-source-publisher-v1/native_host_source_ownership.py'}
        pins={d.get('path'):d.get('sha256') for d in manifest.get('dependencies',[])
              if isinstance(d,dict)}
        if not required <= set(pins):return False
        import stat
        directory=DYNAMIC_REGISTRY_ROOT/'native-source-publisher-v1'
        for name in required:
            path=DYNAMIC_REGISTRY_ROOT/name
            if path.is_symlink() or not path.is_file() or path.stat().st_uid!=0:
                return False
            if hashlib.sha256(protected_file(path,DYNAMIC_REGISTRY_ROOT,maximum=2_000_000)).hexdigest()!=pins[name]:
                return False
        if str(directory) not in sys.path:sys.path.insert(0,str(directory))
        import native_host_source_ownership as owner
        import native_source_publisher as publisher
        import host_original306_worker_lane as lane
        # Even preclaim cannot happen if the genuine signed312 verifier isn't
        # installed and SHA-pinned in the original first-party registry.
        host312_source=DYNAMIC_REGISTRY_ROOT/'dynamic-operation-registry-v1'
        if str(host312_source) not in sys.path:
            sys.path.insert(0,str(host312_source))
        from host_admission import verify_first_party_host312
        if not callable(verify_first_party_host312):return False
        owner_catalog=owner.checked_host_catalog()
        policy=publisher.checked_policy()
        plan_sha=job.get('plan_sha256')
        if not isinstance(plan_sha,str) or not re.fullmatch(r'[a-f0-9]{64}',plan_sha):
            return False
        plan=checked_json(DYNAMIC_PLAN_STORE/(plan_sha+'.json'),DYNAMIC_PLAN_STORE)
        decision=lane.queued_host_claim(index,owner_catalog,policy,plan,
                                         job,entry)
        return (decision.get('state')==
            'EXACT_ENROLLED_HOST_JOB_ELIGIBLE_FOR_ORIGINAL306_SIGNED_CLAIM' and
            decision.get('publication_authorized') is False)
    except Exception as exc:
        print('ORIGINAL_HOST_SOURCE_SIGNED_PRECLAIM_DENIED:'+
              safe_capability_error(exc),file=sys.stderr)
        return False


def firstparty_host_v2_claimed_observation(actor, entry):
    """Read-only Original306 v2 dispatch inspection; NEVER an authority.

    SHA-pinned claim classifier is called only after an exact Root Registry
    Host Anchor plan/command match. Any v2 match is kept off the remote
    read-canary, WordPress and Host Source execution lanes. Current manifests
    are deliberately execution_enabled=false. No new Broker claim/Grant/Job
    is created; the independent Original312/313 process remains separate.
    """
    if (not isinstance(entry, dict)
        or entry.get('target_id') not in ('hosting-2', 'hosting-3')
        or not isinstance(entry.get('job_id'), str)
        or not HEX32.fullmatch(entry['job_id'])
        or not isinstance(entry.get('command_sha256'), str)
        or not re.fullmatch(r'[a-f0-9]{64}', entry['command_sha256'])):
        return None
    try:
        if str(DYNAMIC_REGISTRY_ROOT) not in sys.path:
            sys.path.insert(0, str(DYNAMIC_REGISTRY_ROOT))
        import registry
        index = registry.Registry(DYNAMIC_REGISTRY_ROOT)
        matching = []
        planstore = DYNAMIC_PLAN_STORE
        if not planstore.is_dir() or planstore.is_symlink():
            raise RuntimeError('ORIGINAL306_ANCHOR_ROOT_PLAN_STORE_UNAVAILABLE')
        plans = sorted(planstore.glob('*.json'))
        if len(plans) > 256:
            raise RuntimeError('ORIGINAL306_ANCHOR_PLAN_STORE_UNBOUNDED')
        for operation in ('company.host-authority.anchor.issue',
                          'company.host-authority.anchor.revoke'):
            manifest = index.resolve(operation)
            if (manifest.get('resource_kind') != 'host_anchor'
                or manifest.get('target_scope') != 'host'
                or manifest.get('job_type') !=
                   'signed_remote_hostinger_wpcli_v2'
                or manifest.get('command', {}).get('format') !=
                   'host.anchor.ref.v2'
                or manifest.get('execution_enabled') is not False):
                raise RuntimeError('ORIGINAL306_HOST_ANCHOR_PROVIDER_DRIFT')
            for file in plans:
                if not re.fullmatch(r'[a-f0-9]{64}', file.stem):
                    raise RuntimeError('ORIGINAL306_UNPINNED_ROOT_PLAN_NAME')
                plan = registry.checked_json(file, planstore)
                if plan.get('contract') != manifest.get('plan_contract'):
                    continue
                if plan.get('plan_sha256') != file.stem:
                    raise RuntimeError('ORIGINAL306_ROOT_ANCHOR_PLAN_DIGEST_DRIFT')
                if (index.command_sha256(operation, file.stem)
                    == entry['command_sha256']):
                    matching.append((operation, plan))
        if not matching:
            return None
        if len(matching) != 1:
            raise RuntimeError('ORIGINAL306_HOST_ANCHOR_PLAN_AMBIGUOUS')
        operation, plan = matching[0]
        from pathlib import Path
        import stat
        modulefile = (DYNAMIC_REGISTRY_ROOT / 'host-anchor-v86'
                      / 'original306_typed_claim.py')
        st = modulefile.lstat()
        if (modulefile.is_symlink() or not stat.S_ISREG(st.st_mode)
            or st.st_uid != 0 or st.st_gid != 0
            or st.st_mode & 0o077
            or hashlib.sha256(modulefile.read_bytes()).hexdigest() !=
               '84d209b40144fe04fe19d7db3ea869e7335c06694c76d286bf0b2f3e26d68b5f'):
            raise RuntimeError('ORIGINAL306_V86_SIGNED_CLASSIFIER_SOURCE_DRIFT')
        spec = importlib.util.spec_from_file_location(
            '_mad4b_original306_exact_host_v2_classifier', modulefile)
        if spec is None or spec.loader is None:
            raise RuntimeError('ORIGINAL306_HOST_V2_CLASSIFIER_NOT_FOUND')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        id = entry['job_id']
        php = ('$id="'+id+'";'
          '$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
          '$c=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false);'
          'echo wp_json_encode(["job"=>is_array($j)?$j:[],'
          '"claim"=>is_array($c)?$c:[]]);')
        original = cli_json(['--user='+str(actor), 'eval', php], timeout=30)
        result = module.resolve_remote_host_v2_claim(
            index, original.get('job',{}), original.get('claim',{}),
            entry, plan)
        if (result.get('operation_id') != operation
            or result.get('execution_authorized') is not False
            or result.get('original313_certified') is not False
            or result.get('issuer_commissioned') is not False):
            raise RuntimeError('ORIGINAL306_HOST_V2_CLASSIFIER_NOT_A_HOLD')
        return result
    except Exception as exc:
        print('ORIGINAL306_HOST_V2_CLAIM_HELD:'
              + safe_capability_error(exc), file=sys.stderr)
        # Keep a malformed v2 claim out of generic Host Source/WordPress
        # whenever its command was identified as a Host Anchor root plan.
        if 'matching' in locals() and matching:
            return {'state': 'HOST_ANCHOR_V2_HOLD',
                    'execution_authorized': False,
                    'anchor_issued': False,
                    'original313_certified': False}
        return None


def firstparty_host_source_claimed_execution(actor, entry, *, execute=False):
    """Read original signed Job + pinned Host release evidence, never fake it.

    Returns None if this is NOT a Host source Job. Missing independent
    signed proof files return a HOLD; only exact issued Master Admin/Broker
    signatures and real WordPress ticket can reach the transaction. No
    fixed WordPress actor is a Host owner; actor is a WP read-only context.
    """
    if (not isinstance(entry,dict) or entry.get('target_id')!='srv2030908'
        or not isinstance(entry.get('job_id'),str)
        or not HEX32.fullmatch(entry['job_id'])
        or not isinstance(entry.get('command_sha256'),str)
        or not re.fullmatch(r'[a-f0-9]{64}',entry['command_sha256'])):
        return None
    if str(DYNAMIC_REGISTRY_ROOT) not in sys.path:
        sys.path.insert(0,str(DYNAMIC_REGISTRY_ROOT))
    from registry import Registry,checked_json,protected_file
    index=Registry(DYNAMIC_REGISTRY_ROOT)
    matches=[]
    if not DYNAMIC_PLAN_STORE.is_dir() or DYNAMIC_PLAN_STORE.is_symlink():
        raise RuntimeError('HOST_SOURCE_ORIGINAL_PLAN_STORE_NOT_REGISTERED')
    plans=sorted(DYNAMIC_PLAN_STORE.glob('*.json'))
    if len(plans)>256:raise RuntimeError('HOST_SOURCE_TOO_MANY_ROOT_PLANS')
    for operation,manifest in index.manifests.items():
        if (manifest.get('source_family')!='mad4b.native-host-source-release.v1'
            or manifest.get('target_scope')!='host'
            or manifest.get('job_type')!='signed_vps_host_source'):
            continue
        for file in plans:
            if not re.fullmatch(r'[a-f0-9]{64}',file.stem):
                raise RuntimeError('HOST_SOURCE_ROOT_PLAN_FILENAME_NOT_IMMUTABLE')
            row=checked_json(file,DYNAMIC_PLAN_STORE)
            if row.get('contract')!=manifest.get('plan_contract'):
                continue
            if row.get('plan_sha256')!=file.stem:
                raise RuntimeError('HOST_SOURCE_PLAN_ROOT_DIGEST_MISMATCH')
            if index.command_sha256(operation,row['plan_sha256'])==entry['command_sha256']:
                matches.append((operation,manifest,row))
    if not matches:return None
    if len(matches)!=1:raise RuntimeError('HOST_SOURCE_MULTIPLE_MATCHING_ORIGINAL_PLANS')
    operation,manifest,plan=matches[0]
    jobid=entry['job_id']
    php=('$id="'+jobid+'";'
         '$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
         '$p=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false);'
         'echo wp_json_encode(["job"=>is_array($j)?$j:[],"claim"=>is_array($p)?$p:[]]);')
    original=cli_json(['--user='+str(actor),'eval',php],timeout=30)
    job=original.get('job') if isinstance(original,dict) else None
    claim=original.get('claim') if isinstance(original,dict) else None
    if (not isinstance(job,dict) or not isinstance(claim,dict)
        or job.get('job_id')!=jobid
        or job.get('company')!='tours-central'
        or job.get('environment')!='staging'
        or job.get('operation')!=operation
        or job.get('command_sha256')!=entry['command_sha256']
        or job.get('site_uuid')!=claim.get('site_uuid',job.get('site_uuid'))
        or job.get('canonical_origin')!='https://tours.dev.mad4b.com'):
        raise RuntimeError('HOST_SOURCE_ORIGINAL_WORDPRESS_JOB_SCOPE_CHANGED')
    source_root=DYNAMIC_REGISTRY_ROOT/'native-source-publisher-v1'
    if str(source_root) not in sys.path:sys.path.insert(0,str(source_root))
    host312_source=DYNAMIC_REGISTRY_ROOT/'dynamic-operation-registry-v1'
    if str(host312_source) not in sys.path:
        sys.path.insert(0,str(host312_source))
    from native_host_source_ownership import checked_host_catalog,verify_host_plan
    import native_host_source_transaction as transaction
    import native_source_publisher as publisher
    import original_313_host_source_handler as handler
    import original_313_host_source_finalizer as finalizer
    from host_admission import claimed_host_job
    owner_catalog=checked_host_catalog()
    verify_host_plan(plan,owner_catalog)
    verified=claimed_host_job(index,operation,plan,job,claim)
    if verified.get('execution_authorized') is not False:
        raise RuntimeError('HOST_SOURCE_CLAIM_CANNOT_SELF_AUTHORIZE')
    policy=publisher.checked_policy()
    root=Path(policy['receipt_dir'])
    transaction.private_receipts(root)
    # Only separate first-party authorities may place these root-owned,
    # per-Job, independently signed envelopes. This Worker never writes
    # such files or derives them from WordPress approval flags.
    paths={
      'host312':root/(jobid+'.host-312-signed.json'),
      'master':root/(jobid+'.host-master-signed.json'),
      'broker':root/(jobid+'.host-broker-signed.json'),
    }
    if any(not path.is_file() or path.is_symlink() for path in paths.values()):
        return {'state':'HOST_SOURCE_WAITING_INDEPENDENT_SIGNED_PROOFS',
                'job_id':jobid,'execution_performed':False,
                'original_313_signed_receipt':False,
                'publication_authorized':False}
    proofs={name:checked_json(path,root) for name,path in paths.items()}
    for name,document in proofs.items():
        if not isinstance(document,dict) or document.get('job_id')!=jobid:
            raise RuntimeError('HOST_SOURCE_SIGNED_RECORD_WRONG_JOB_'+name.upper())
    signed=publisher.verify_original_host312_proof(plan,job,claim,proofs['host312'])
    approved=publisher.verify_original_host_master_and_broker(
        plan,signed,proofs['master'],proofs['broker'])
    if (approved.get('master_approval_verified') is not True
        or approved.get('broker_release_grant_verified') is not True
        or approved.get('execution_authorized') is not False):
        raise RuntimeError('HOST_SOURCE_LIVE_MASTER_BROKER_NOT_AUTHORIZED')
    reservation=ROOT/'reserved'/(jobid+'.reserved')
    if reservation.exists() or reservation.is_symlink():
        if not execute:
            return {'state':'HOST_SOURCE_RESERVED_REQUIRES_INDEPENDENT_RECONCILIATION',
                    'job_id':jobid,'execution_performed':False,
                    'publication_authorized':False}
        raise RuntimeError('HOST_SOURCE_ORIGINAL_JOB_RESERVED_NO_BLIND_REPLAY')
    if not execute:
        return {'state':'HOST_SOURCE_FULLY_SIGNED_JOB_READY_TO_EXECUTE',
                'job_id':jobid,'execution_performed':False,
                'publication_authorized':False}
    if (claim.get('nonce')!=signed.get('host312_signed_nonce')
        or signed.get('job_id')!=jobid):
        raise RuntimeError('HOST_SOURCE_SIGNED_PROOF_NONCE_OR_JOB_CHANGED')
    fd=os.open(reservation,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'w') as record:
        record.write('host-source:'+jobid+' '+plan['plan_sha256']+'\n')
        record.flush();os.fsync(record.fileno())
    # Never delete the original reservation: an uncertain partial write
    # requires independent reconciliation, not automatic replay.
    outcome=handler.firstparty_execute_claimed(
        plan,original_job=job,original_pending=claim,
        signed_host312=proofs['host312'],
        signed_master_approval=proofs['master'],
        signed_broker_grant=proofs['broker'],nonce=claim['nonce'])
    if outcome.get('state')!='APPLIED_PENDING_INDEPENDENT_313':
        raise RuntimeError('HOST_SOURCE_TRANSACTION_READBACK_REQUIRES_RECONCILIATION')
    final=finalizer.finalize_signed_host_postimage(
        plan,original_job=job,original_pending=claim,
        original312_signed_proof=proofs['host312'],
        signed_master_approval=proofs['master'],
        signed_broker_grant=proofs['broker'],
        receipt_root=root,nonce=claim['nonce'])
    if (final.get('state')!='SIGNED_ORIGINAL313_POSTIMAGE_VERIFIED'
        or final.get('original_313_receipt_signature_verified') is not True):
        raise RuntimeError('HOST_SOURCE_INDEPENDENT_ORIGINAL313_NOT_CERTIFIED')
    return final


def sign_existing_original_306_claim(job,entry):
 """Same broker signer and original 306 Claim contract for every transport."""
 # Sign the original broker claim; this does NOT create a grant or a job.
 f='/opt/mad4b-company-transport/broker-v1/native-vps-client-principal.py'
 spec=importlib.util.spec_from_file_location('original_broker_signer_for_claim',f)
 m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
 signer=m.original_signer();m.check_original_wordpress_signer(signer)
 now=int(time.time());exp=now+40;nonce=secrets.token_hex(16)
 fields=['mad4b.central-vps-broker.v1','claim',job,entry['company'],
         entry['site_uuid'],entry['environment'],entry['broker_host_id'],nonce,str(now),str(exp)]
 signature=base64.b64encode(signer.sign('\n'.join(fields).encode())).decode()
 row=cli_json(['mad4b','broker','claim','--job='+job,'--nonce='+nonce,
   '--issued='+str(now),'--expires='+str(exp),'--signature='+signature])
 if row.get('job_id')!=job or row.get('state')!='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY':
  raise RuntimeError('ORIGINAL_SIGNED_306_CLAIM_NOT_ACCEPTED')
 return job

def firstparty_host_v95_first_claim_only(actor,entry):
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
              'ebb3fb1efe58a1506516b7d8b76f2ff4281b3f2b0c0ee8361b606dcfa3240b64'):
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


def firstparty_host_v2_pending_observation(actor, entry):
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



# FIRSTPARTY_HOST_V118_PRECLAIM_EVIDENCE: purpose-bound Staging-only
# capture. Installed first-party code and SHA must be pinned independently
# by Root, not accepted from WordPress, a request, a plugin or a user argument.
def firstparty_host_v118_capture_original_queued(job_id):
 try:
  import stat
  if (os.geteuid()!=0 or not isinstance(job_id,str) or
      not HEX32.fullmatch(job_id)):
   raise RuntimeError('HOST_V118_FIRST_PARTY_ROOT_AND_JOB_REQUIRED')
  path=ROOT/'host-authority-v118/queued_original_capture.py'
  st=path.lstat()
  if (path.is_symlink() or not stat.S_ISREG(st.st_mode) or
      st.st_uid!=0 or st.st_gid!=0 or st.st_nlink!=1 or
      stat.S_IMODE(st.st_mode)!=0o600 or
      hashlib.sha256(path.read_bytes()).hexdigest()!=
      'b76778a5ecc786c74a02b5bd0a9c42f9256a17a7f46b4089ff1bd72368b0ecb2'):
   raise RuntimeError('HOST_V118_PINNED_CAPTURE_SOURCE_CHANGED')
  spec=importlib.util.spec_from_file_location(
      '_host_v118_original306_preclaim_capture',path)
  if spec is None or spec.loader is None:
   raise RuntimeError('HOST_V118_CAPTURE_SOURCE_IMPORT_DENIED')
  mod=importlib.util.module_from_spec(spec)
  spec.loader.exec_module(mod)
  state=mod.capture_live(job_id)
  return state in (
      'ORIGINAL306_QUEUED_PREIMAGE_CAPTURED',
      'ORIGINAL306_QUEUED_PREIMAGE_ALREADY_STORED')
 except Exception as exc:
  # Never allow an unproven Staging Job into signing or generic execution.
  print('HOST_V118_QUEUED_CAPTURE_HELD:'+
        safe_capability_error(exc),file=sys.stderr)
  return False



# FIRSTPARTY_HOST_V120_BROKER_ROW_WITNESS: Root-only preclaim guard.
# Never derive a Broker Row from the claimed WordPress Job or old broker logs.
def firstparty_host_v120_capture_original_broker_row(job_id):
 try:
  import stat
  if (os.geteuid()!=0 or not isinstance(job_id,str)
      or not HEX32.fullmatch(job_id)):
   raise RuntimeError('HOST_V120_BROKER_SOURCE_ROOT_AND_ID_REQUIRED')
  path=ROOT/'host-authority-v120/broker_row_witness.py'
  st=path.lstat()
  if (path.is_symlink() or not stat.S_ISREG(st.st_mode)
      or st.st_uid!=0 or st.st_gid!=0 or st.st_nlink!=1
      or stat.S_IMODE(st.st_mode)!=0o600
      or hashlib.sha256(path.read_bytes()).hexdigest()!=
      '336ef367637ce1edbef4debbec4317b3b839d3d71e2e3135e0690eb40d96331f'):
   raise RuntimeError('HOST_V120_BROKER_WITNESS_SOURCE_PIN_CHANGED')
  spec=importlib.util.spec_from_file_location(
      '_host_v120_firstparty_signed_broker_row',path)
  if spec is None or spec.loader is None:
   raise RuntimeError('HOST_V120_BROKER_WITNESS_IMPORT_DENIED')
  mod=importlib.util.module_from_spec(spec)
  spec.loader.exec_module(mod)
  status=mod.capture_live_before_claim(job_id)
  return status in (
      'ORIGINAL_BROKER_QUEUED_WITNESS_SIGNED_AND_STORED',
      'ORIGINAL_BROKER_QUEUED_WITNESS_ALREADY_STORED')
 except Exception as exc:
  print('HOST_V120_SIGNED_BROKER_ROW_HELD:'+
        safe_capability_error(exc),file=sys.stderr)
  return False



# FIRSTPARTY_HOST_V121_EXACT_ISSUER: Purpose-bound first-Anchor runner.
# No generic registry execution flag and no Owner impersonation.
def firstparty_host_v121_module():
 import stat
 path=ROOT/'host-authority-v121/first_anchor_coordinator.py'
 st=path.lstat()
 if (path.is_symlink() or not stat.S_ISREG(st.st_mode)
     or st.st_uid!=0 or st.st_gid!=0 or st.st_nlink!=1
     or stat.S_IMODE(st.st_mode)!=0o600
     or hashlib.sha256(path.read_bytes()).hexdigest()!=
     'a4e7dad6638be30a492bf27202d99bb27d2b74a2cfc116fbe17269da4fc2a6b3'):
  raise RuntimeError('FIRSTPARTY_HOST_V121_ROOT_ISSUER_SOURCE_CHANGED')
 spec=importlib.util.spec_from_file_location(
     '_host_v121_exact_first_anchor_issuer',path)
 if spec is None or spec.loader is None:
  raise RuntimeError('FIRSTPARTY_HOST_V121_ROOT_ISSUER_UNAVAILABLE')
 mod=importlib.util.module_from_spec(spec)
 spec.loader.exec_module(mod)
 mod.root_gate()  # Only root-owned source and explicit activation permits.
 return mod

def firstparty_host_v121_preclaim_commissioned():
 try:
  firstparty_host_v121_module()
  return True
 except Exception as exc:
  print('FIRSTPARTY_HOST_V121_PRECLAIM_NOT_COMMISSIONED:'+
        safe_capability_error(exc),file=sys.stderr)
  return False

def firstparty_host_v121_has_exact_witness(job_id):
 try:
  if (os.geteuid()!=0 or not isinstance(job_id,str)
      or not HEX32.fullmatch(job_id)):
   return False
  # No WordPress write or root signer loaded at this probe.
  marker=ROOT/'host-authority-v120/broker_row_witness.py'
  witness=Path('/var/lib/mad4b/original-host-v2/broker-row-witness')/(job_id+'.json')
  queued=Path('/var/lib/mad4b/original-host-v2/queued-evidence')/(job_id+'.json')
  return (witness.is_file() and not witness.is_symlink() and
          queued.is_file() and not queued.is_symlink())
 except Exception:
  return False

def firstparty_host_v121_exact_issue(job_id):
 try:
  mod=firstparty_host_v121_module()
  result=mod.execute_locked(job_id,"issue")
  if result.get('state')!=(
      'FIRST_ANCHOR_REVISION_1_SIGNED_RECEIPT_AND_HIGHWATER_VERIFIED'):
   raise RuntimeError('HOST_V121_FIRST_ANCHOR_NOT_FINALIZED')
  print(json.dumps(result,sort_keys=True))
  return True
 except Exception as exc:
  print('FIRSTPARTY_HOST_V121_ISSUE_HELD:'+
        safe_capability_error(exc),file=sys.stderr)
  return False

def firstparty_host_v121_postwrite_receipt_recovery():
 """No automatic original318 write. Postwrite-only recovery from
 immutable signed documents and a previously armed exact send-intent.
 A genuine Original318 Revision 1 readback is mandatory.
 """
 try:
  root=Path('/var/lib/mad4b/original-host-v2/first-issue-send-intents')
  if not root.is_dir() or root.is_symlink():return
  plans=sorted(root.glob('*.json'))
  if len(plans)>1:
   raise RuntimeError('HOST_V121_UNEXPECTED_MULTIPLE_FIRST_ISSUE_INTENTS')
  if not plans:return
  jid=plans[0].stem
  if not HEX32.fullmatch(jid):raise RuntimeError('HOST_V121_BAD_RECOVERY_ID')
  # The signed Original313 receipt is immutable. No need to invoke slow
  # WP-CLI reads on every 60-second worker tick after it is completed.
  receipt=Path('/var/lib/mad4b/original-host-v2/receipts')/(jid+'.json')
  if receipt.is_symlink():raise RuntimeError('HOST_V121_SIGNED_RECEIPT_SYMLINK')
  if receipt.is_file():return
  mod=firstparty_host_v121_module()
  outcome=mod.execute_locked(jid,"resume")
  if (outcome.get('state')==
      'FIRST_ANCHOR_REVISION_1_SIGNED_RECEIPT_AND_HIGHWATER_VERIFIED'):
   print('HOST_V121_POSTWRITE_SIGNED_RECEIPT_VERIFIED:'+jid)
 except Exception as exc:
  print('FIRSTPARTY_HOST_V121_POSTWRITE_RECOVERY_HELD:'+
        safe_capability_error(exc),file=sys.stderr)


def claim_authorized_job(actor):
 # Original 305/306 has to exist already through an authenticated site owner.
 queued=cli_json(['--user='+str(actor),'mad4b','broker','pending','--limit=10'])
 if queued.get('contract')!='mad4b.central-vps-broker.v1':
  raise RuntimeError('ORIGINAL_306_PENDING_CONTRACT_MISMATCH')
 targets=exact_host_targets()
 allowed=operation_digests()
 for entry in queued.get('pending',[]):
  if not isinstance(entry,dict):continue
  scope=str(entry.get('company') or '')+':'+str(entry.get('environment') or '')
  # Central Host source is a distinct FIRST-PARTY native resource; it has
  # no tenant binding in the old WordPress target registry. Do not make a
  # fake WordPress tenant to make the old WPCLI path accept it.
  if entry.get('company')=='tours-central':
   if firstparty_host_source_claim_preflight(actor,entry):
    return sign_existing_original_306_claim(entry['job_id'],entry)
   continue
  target=targets.get(scope)
  if not isinstance(target,dict):continue
  remote=(target.get('transport_kind')=='hostinger_ssh_wpcli')
  expected_host=target.get('target_host_id') if remote else 'srv2030908'
  if entry.get('target_host_id')!=expected_host or entry.get('broker_host_id')!='srv2030908':continue
  # Original305 Host Anchor issue/bootstrap requests are 300s, while the
  # legacy remote WPCLI needs 360s. Before v98 the first Host Anchor could
  # NEVER reach its owner/328/plan verifier. Only All Royal Egypt Staging
  # uses a 120s pre-read window; standard remote WPCLI still needs 360s.
  first_anchor_scope=(remote and scope=='all-royal-egypt:staging'
      and entry.get('target_host_id')=='hosting-2')
  min_lifetime=(120 if first_anchor_scope else (360 if remote else 90))
  if entry.get('site_uuid')!=target.get('site_uuid') or int(entry.get('expires_epoch') or 0)<time.time()+min_lifetime:continue
  job=entry.get('job_id')
  if not isinstance(job,str) or not HEX32.fullmatch(job):continue
  # Read-only inspection of the original Job's command type and SHA.
  code=('$id="'+job+'";$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
    'echo wp_json_encode(is_array($j)?["operation"=>$j["operation"]??null,'
    '"target_scope"=>$j["target_scope"]??null,"command_format"=>$j["command_format"]??null,'
    '"job_type"=>$j["job_type"]??null,"command_sha256"=>$j["command_sha256"]??null,'
    '"site_uuid"=>$j["site_uuid"]??null,"domain_intent"=>$j["domain_intent"]??null,"state"=>$j["state"]??null,'
    '"sealed_argv_sha256"=>$j["sealed_argv_sha256"]??null]:[]);')
  j=cli_json(['--user='+str(actor),'eval',code])
  if (j.get('command_format')=='host.anchor.ref.v2'
      or j.get('operation') in (
          'company.host-authority.anchor.issue',
          'company.host-authority.anchor.revoke')):
   # Recognize the original typed Host Anchor operation, but never claim or
   # direct it to generic WPCLI/Host Source without a separately signed
   # issuer, signed Owner evidence and independent Original313 high-water.
   if first_anchor_scope and firstparty_host_v95_first_claim_only(actor,entry):
    # FIRSTPARTY_HOST_V118_PRECLAIM_EVIDENCE: preserve the genuine QUEUED
    # original BEFORE Original306 overwrites the WordPress Job with CLAIMED.
    # The historical evidence is not an issuance or execution approval.
    if not firstparty_host_v121_preclaim_commissioned():
     continue
    if not firstparty_host_v118_capture_original_queued(job):
     continue
    # FIRSTPARTY_HOST_V120_BROKER_ROW_WITNESS: separate Broker signature
    # over the original QUEUED record, before signed Original306 Claim.
    if not firstparty_host_v120_capture_original_broker_row(job):
     continue
    # This remains ONLY the separately signed Original306 Claim.
    return sign_existing_original_306_claim(job,entry)
   firstparty_host_v2_pending_observation(actor,entry)
   continue
  if not (j.get('operation')=='company.remote.wpcli.full'
    and j.get('target_scope')=='wordpress'
    and j.get('command_format')=='wpcli.argv.json.v1'
    and j.get('job_type')==('signed_remote_hostinger_wpcli' if remote else 'signed_vps_wpcli')
    and j.get('site_uuid')==entry['site_uuid']
    and j.get('state')=='QUEUED_FOR_VPS_BROKER_CLAIM'):
   continue
  # Remote lane only admits the existing exact fixed read-only canary.
  # Original 306 independently validates enrolled 305 and active remote 328
  # when it consumes this signed Claim. No speculative remote writes.
  if remote:
   # Preserve legacy remote WPCLI >360s admission even when the same
   # Staging scope had a 120s first-Anchor pre-read window.
   if int(entry.get('expires_epoch') or 0)<time.time()+360:continue
   if (j.get('command_sha256')!=DIGEST
       or not isinstance(j.get('sealed_argv_sha256'),str)
       or not re.fullmatch(r'[a-f0-9]{64}',j['sealed_argv_sha256'])):
    continue
   return sign_existing_original_306_claim(job,entry)
  selected=allowed.get(j.get('command_sha256'))
  dynamic_candidate=(isinstance(selected,str) and
                     selected.startswith('dynamic:'))
  dynamic_ready=(dynamic_candidate and dynamic_313_original_central_ready(actor)
                 and dynamic_release_grant_ready(job,j.get('command_sha256')))
  legacy=(selected is not None and not dynamic_candidate
          and scope=='wayfatours:production')
  private_candidate=(not legacy and not dynamic_candidate
      and isinstance(j.get('sealed_argv_sha256'),str)
      and re.fullmatch(r'[a-f0-9]{64}',j['sealed_argv_sha256'])
      and private_draft_queued_ready(job,actor))
  postmeta_candidate=(not legacy and not dynamic_candidate and not private_candidate
      and isinstance(j.get('sealed_argv_sha256'),str)
      and re.fullmatch(r'[a-f0-9]{64}',j['sealed_argv_sha256'])
      and postmeta_queued_ready(job,actor))
  encrypted=(not legacy and not dynamic_candidate and not private_candidate
             and not postmeta_candidate
             and isinstance(j.get('sealed_argv_sha256'),str)
             and re.fullmatch(r'[a-f0-9]{64}',j['sealed_argv_sha256'])
             and generic_preclaim(job))
  if not legacy and not encrypted and not dynamic_ready and not private_candidate and not postmeta_candidate:continue
  return sign_existing_original_306_claim(job,entry)
 return None

def main():
 if os.geteuid()!=0:raise RuntimeError('ROOT_ONLY_NATIVE_WORKER')
 sys.path.insert(0,'/opt/mad4b-company-transport')
 from wpcli_dynamic_actor import resolve_actor
 try:
  actor=resolve_actor()
 except RuntimeError as exc:
  if str(exc) in ('CENTRAL_WORDPRESS_BUSY','WPCLI_DYNAMIC_ACTOR_BUSY'):
   raise TransientCentralReadBusy('CENTRAL_SITE_PROFILE_READ_SLOT_BUSY') from exc
  raise
 newly_claimed=claim_authorized_job(actor)
 # FIRSTPARTY_HOST_V121_EXACT_ISSUER: only first Anchor jobs with immutable
 # v118 QUEUED preimage and separately Broker-signed v120 witness.
 if newly_claimed and firstparty_host_v121_has_exact_witness(newly_claimed):
  firstparty_host_v121_exact_issue(newly_claimed)
 # Never replay WordPress Original318 after any ambiguous write. The v121
 # independent postwrite resumer uses the stored exact signed intent only.
 firstparty_host_v121_postwrite_receipt_recovery()
 d=cli_json(['--user='+str(actor),'mad4b','broker-authority','claimed','--limit=10'],35)
 if d.get('contract')!='mad4b.broker-exact-ssh-authority.v1' or not isinstance(d.get('claimed'),list):
  raise RuntimeError('ORIGINAL_312_CLAIMED_RESPONSE_INVALID')
 work=[]
 allowed=operation_digests()
 for row in d['claimed']:
  if not isinstance(row,dict):continue
  target_id=row.get('target_id')
  if target_id not in ('srv2030908','hosting-2','hosting-3'):continue
  job=row.get('job_id')
  if not isinstance(job,str) or not HEX32.fullmatch(job):continue
  if target_id!='srv2030908':
   host_v2_observation=firstparty_host_v2_claimed_observation(actor,row)
   if host_v2_observation is not None:
    # Original312 + independent Original313 are not commissioned.
    # Never execute a generic remote canary or fallback for this claim.
    continue
   # Only existing preclaimed read Jobs are allowed through the Remote lane.
   # The actual 312 issuer and 313 executor verify every original record again.
   binding=exact_host_targets()
   pinned=next((v for v in binding.values() if isinstance(v,dict)
     and v.get('transport_kind')=='hostinger_ssh_wpcli'
     and v.get('target_host_id')==target_id),None)
   if (not pinned or row.get('command_sha256')!=DIGEST
       or int(row.get('expires_epoch') or 0)<time.time()+360
       or (ROOT/'reserved'/(job+'.reserved')).exists()):continue
   work.append((job,'read'))
   continue
  operation=allowed.get(row.get('command_sha256'))
  if operation is None and target_id=='srv2030908':
   host_result=firstparty_host_source_claimed_execution(actor,row,execute=False)
   if host_result is not None:
    if host_result.get('state')=='HOST_SOURCE_FULLY_SIGNED_JOB_READY_TO_EXECUTE':
     work.append((job,'native.host.source.verified'))
    # No WPCLI or Shell fallback, even when Host proofs are missing.
    continue
  if (isinstance(operation,str) and operation.startswith('dynamic:')
      and not (dynamic_313_original_central_ready(actor) and
               dynamic_release_grant_ready(job,row.get('command_sha256')))):
   continue
  reserved=(ROOT/'reserved'/(job+'.reserved')).exists()
  if reserved:
   if (operation=='schema.readback'
       and (ROOT/'receipts'/(job+'.json')).is_file()
       and int(row.get('expires_epoch') or 0)>time.time()+190):
    work.insert(0,(job,'schema.readback.resume'))
   elif (operation is None and int(row.get('expires_epoch') or 0)>time.time()+190
        and generic_signed_receipt_resume_available(job,row.get('command_sha256'))):
    work.insert(0,(job,'wpcli.option.update.resume'))
   # A completed or uncertain generic write NEVER runs twice; resume only
   # delivers the immutable existing signed 313 receipt.
   continue
  if operation is None:
   if int(row.get('expires_epoch') or 0)<time.time()+360:continue
   if private_draft_claimed_ready(job,actor,row.get('command_sha256')):
    operation='wpcli.private_draft.first_touch'
   elif postmeta_claimed_ready(job,actor,row.get('command_sha256')):
    operation='wpcli.draft_postmeta.first_touch'
   elif generic_claimed_job_ready(job):
    operation='wpcli.option.update'
   else:continue
  # Starting a new target operation needs a longer original authority window.
  if int(row.get('expires_epoch') or 0)<time.time()+360:continue
  work.append((job,operation))
 # A previously executed schema read is important, but cannot starve a
 # currently authorized one-shot operation. Only receipt delivery is retried;
 # never reexecute a reserved target command.
 work=prioritize_claimed_work(work,d['claimed'],time.time())
 if not work:
  # The signed plugin.install capability heartbeat is owned by the
  # independent root-only capability timer, NOT the original Job worker.
  # Keeping one owner avoids duplicate signatures/WordPress REST writes,
  # and a busy WP-CLI read never makes provider availability depend on 313.
  # The MU provider is separate; inability to attest it never disables
  # existing read-only or regular plugin.install operations.
  try:
   spec=importlib.util.spec_from_file_location('original_mu_capability',
       str(MU_CANDIDATE/'capability-heartbeat.py'))
   mu_heartbeat=importlib.util.module_from_spec(spec);spec.loader.exec_module(mu_heartbeat)
   mu_heartbeat.publish(actor)
  except Exception as exc:
   print('MU_DEPLOY_CAPABILITY_HEARTBEAT_DEFERRED:'+safe_capability_error(exc),file=sys.stderr)
  print(json.dumps({'state':'NO_CURRENT_EXACT_CLAIMED_WPCLI_JOB',
   'claimed_jobs_inspected':len(d['claimed']),'jobs_claimed':1 if newly_claimed else 0,'jobs_executed':0,
   'grants_created':0,'production_mutated':False,
   'immutable_host_execution_receipts_seen':native_receipt_inventory_count(),
   'host_receipts_are_not_original_313_certificates':True}));return
 job,operation=work[0]
 if operation=='native.host.source.verified':
  source=next((r for r in d['claimed'] if isinstance(r,dict)
               and r.get('job_id')==job),None)
  result=firstparty_host_source_claimed_execution(actor,source,execute=True)
  if not isinstance(result,dict) or result.get('state')!='SIGNED_ORIGINAL313_POSTIMAGE_VERIFIED':
   raise RuntimeError('HOST_SOURCE_REAL_ORIGINAL313_NOT_CERTIFIED')
  print(json.dumps(result));return
 if isinstance(operation,str) and operation.startswith('dynamic:'):
  output=dynamic_execute_original_claimed(job,actor)
  # Original Central 313 remains the only authority that can finalize.
  print(json.dumps(output))
  return
 executor=(POSTMETA_V6_EXECUTOR if operation=='wpcli.draft_postmeta.first_touch'
   else (PRIVATE_DRAFT_EXECUTOR if operation=='wpcli.private_draft.first_touch'
   else (FULL_RESUME if operation=='wpcli.option.update.resume'
   else (FULL_WORKER if operation=='wpcli.option.update'
   else (ROOT/'original-wpcli-executor.py' if operation=='read'
   else (SCHEMA_CANDIDATE/'original-wpcli-executor.py' if operation=='schema.readback'
     else (SCHEMA_CANDIDATE/'schema-receipt-resume.py' if operation=='schema.readback.resume'
     else (MU_CANDIDATE/'original-wpcli-executor.py' if operation=='mu-plugin.deploy'
      else CANDIDATE/'original-wpcli-executor.py'))))))))
 try:
  result=subprocess.run([sys.executable,str(executor),'--job',job,
    '--operation',operation] if operation not in ('read','schema.readback','schema.readback.resume','wpcli.option.update','wpcli.option.update.resume','wpcli.private_draft.first_touch',
    'wpcli.draft_postmeta.first_touch') else [sys.executable,str(executor),'--job',job],
    capture_output=True,text=True,timeout=320,check=False)
 except subprocess.TimeoutExpired as exc:
  # The exact 312 command may have succeeded. Never restart an uncertain Job.
  receipt_seen=(ROOT/'receipts'/(job+'.json')).is_file()
  raise RuntimeError('ORIGINAL_EXECUTOR_TIMEOUT_320S_RECEIPT_RECONCILIATION_REQUIRED:'
    +('HOST_312_RECEIPT_EXISTS' if receipt_seen else 'HOST_RECEIPT_NOT_OBSERVED')) from exc
 if result.returncode:
  if unreserved_schema_preflight_busy(job,operation,result.returncode,result.stderr):
   raise TransientCentralReadBusy('SCHEMA_PREFLIGHT_CENTRAL_WPCLI_BUSY')
  raise RuntimeError(safe_executor_failure(result.returncode,result.stderr))
 r=json.loads(result.stdout.strip().splitlines()[-1])
 if not(r.get('state')=='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED' and
   r.get('job_id')==job and r.get('original_313_certified') is True):
  raise RuntimeError('ORIGINAL_313_RESULT_NOT_CERTIFIED')
 print(json.dumps({'state':r['state'],'job_id':job,'processed':1,
  'original_313_certified':True,'production_mutated':r.get('production_mutation') is True}))

if __name__=='__main__':
 try:main()
 except Exception as exc:
  if is_retryable_central_capacity(exc):
   print(json.dumps({'state':'CENTRAL_WPCLI_BUSY_DEFERRED',
    'jobs_claimed':0,'jobs_executed':0,'grants_created':0,
    'production_mutated':False,'receipt_certified':False}))
   sys.exit(0)
  # All other errors remain explicit denials, never converted into busy.

  print('CLAIMED_NATIVE_313_WORKER_DENIED:'+str(exc),file=sys.stderr);sys.exit(2)

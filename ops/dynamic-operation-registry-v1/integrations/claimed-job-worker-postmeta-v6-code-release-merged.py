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
  target=targets.get(scope)
  if not isinstance(target,dict):continue
  remote=(target.get('transport_kind')=='hostinger_ssh_wpcli')
  expected_host=target.get('target_host_id') if remote else 'srv2030908'
  if entry.get('target_host_id')!=expected_host or entry.get('broker_host_id')!='srv2030908':continue
  min_lifetime=360 if remote else 90
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

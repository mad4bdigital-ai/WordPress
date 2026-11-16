#!/usr/bin/env python3
"""Root-owned scheduled broker readiness; no keys, grants, arbitrary commands.

All invocations use the SHA-pinned original issuer already enrolled in V6.
Ambiguous WordPress writes latch HOLD instead of allowing a future timer retry.
"""
from __future__ import annotations
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import time
import subprocess
import sys
import tempfile

ROOT=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
BASE=Path('/opt/mad4b-company-transport/native-313-independent/host-source-v1')
ISSUER='native-source-publisher-v1/original_broker_host_readiness_refresh_v8.py'
MANIFEST='dynamic-operation-registry-v1/providers/host.source.release/manifest.v8.json'
HOLD=BASE/'original305-readiness-ambiguous-write-HOLD.json'
CONTRACT='mad4b.original305-enrolled-readiness-supervisor.v6'

class Denied(RuntimeError):pass

def require(ok,code):
    if not ok:raise Denied(code)

def file_read(path:Path,mode: int=0o600)->bytes:
    require(path.is_file() and not path.is_symlink(),'FIRSTPARTY_FILE_MISSING_OR_SYMLINK')
    s=path.stat()
    require(s.st_uid==0 and s.st_gid==0 and s.st_nlink==1 and
            not s.st_mode&0o022 and stat.S_ISREG(s.st_mode),
            'FIRSTPARTY_ROOT_FILE_UNTRUSTED')
    data=path.read_bytes()
    require(0<len(data)<250000,'FIRSTPARTY_ROOT_FILE_SIZE_INVALID')
    return data

def pinned_issuer()->Path:
    require(os.geteuid()==0,'FIRSTPARTY_ROOT_REQUIRED')
    require(ROOT.is_dir() and not ROOT.is_symlink() and
            ROOT.stat().st_uid==0 and ROOT.stat().st_gid==0 and
            stat.S_IMODE(ROOT.stat().st_mode)==0o700,
            'FIRSTPARTY_ENROLLED_REGISTRY_UNTRUSTED')
    ix=json.loads(file_read(ROOT/'index.json'))
    require(ix.get('installed_by_first_party') is True,'NOT_FIRSTPARTY_ENROLLED')
    row=[x for x in ix.get('providers',[]) if x.get('manifest')==MANIFEST]
    require(len(row)==1,'ENROLLED_HOST_V8_NOT_CURRENT')
    manifest_file=file_read(ROOT/MANIFEST)
    require(hashlib.sha256(manifest_file).hexdigest()==row[0].get('sha256'),
            'ENROLLED_MANIFEST_INDEX_PIN_CHANGED')
    manifest=json.loads(manifest_file)
    require(manifest.get('version')==8 and manifest.get('operation_id')=='host.source.release',
            'ENROLLED_OPERATION_IDENTITY_CHANGED')
    deps=[d for d in manifest.get('dependencies',[]) if d.get('path')==ISSUER]
    require(len(deps)==1,'ENROLLED_BROKER_V8_DEPENDENCY_MISSING')
    issuer=ROOT/ISSUER
    require(hashlib.sha256(file_read(issuer)).hexdigest()==deps[0].get('sha256'),
            'ENROLLED_BROKER_V8_FILE_DRIFT')
    return issuer

def sticky_hold(reason:str):
    require(BASE.is_dir() and not BASE.is_symlink() and BASE.stat().st_uid==0 and
            stat.S_IMODE(BASE.stat().st_mode)==0o700,
            'ORIGINAL305_HOLD_DIR_NOT_ROOT_PRIVATE')
    # Do not overwrite an existing HOLD, which may have independent audit data.
    if HOLD.exists():return
    require(re.fullmatch('[A-Z][A-Z0-9_]{3,120}',reason) is not None,
            'INVALID_SAFE_HOLD_REASON')
    payload=json.dumps({'contract':CONTRACT,'state':'AMBIGUOUS_SIGNED_WRITE_HOLD',
                        'reason_code':reason,'automatic_retry_allowed':False},
                       sort_keys=True).encode()+b'\n'
    fd,name=tempfile.mkstemp(prefix='.original305-hold-',dir=BASE)
    try:
        os.fchmod(fd,0o600);os.fchown(fd,0,0)
        with os.fdopen(fd,'wb') as f:
            f.write(payload);f.flush();os.fsync(f.fileno())
        # Hard link exclusive: an existing marker cannot be replaced by a race.
        try:os.link(name,HOLD,follow_symlinks=False)
        except FileExistsError:pass
    finally:
        if os.path.exists(name):os.unlink(name)

def independent_signed_lease_since(started:int|None=None, *, min_remaining:int=35)->bool:
    """Read the actual signed WP option and verify its nonce against root.

    This method has NO signing, update or retry side effects. A successful
    verification means Host readiness is current; it does not attribute the
    signature to the process that just returned an ambiguous result.
    """
    try:
        import sys
        sys.path.insert(0,'/opt/mad4b-company-transport')
        from central_wpcli_runtime import central_wp
        php="""$v=get_option('mad4b_original_host_source_readiness_v1',false);
$ok=class_exists('MAD4B_Original_Native_Operation_Providers_V1',false)?
 MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted('host.source.release','host','host.source.ref.v1'):false;
echo wp_json_encode(['admitted'=>$ok,'issued_epoch'=>is_array($v)?($v['issued_epoch']??null):null,
 'expires_epoch'=>is_array($v)?($v['expires_epoch']??null):null,
 'nonce'=>is_array($v)?($v['nonce']??null):null]);"""
        p=subprocess.run(central_wp()+['eval',php],capture_output=True,
                         text=True,timeout=42,check=False)
        if p.returncode:return False
        rows=[json.loads(line) for line in p.stdout.splitlines() if line.startswith('{')]
        if len(rows)!=1 or not isinstance(rows[0],dict):return False
        v=rows[0];now=int(time.time())
        if (v.get('admitted') is not True or
            type(v.get('issued_epoch')) is not int or
            type(v.get('expires_epoch')) is not int or
            not isinstance(v.get('nonce'),str) or
            not re.fullmatch(r'[a-f0-9]{32}',v['nonce']) or
            (started is not None and not started-15<=v['issued_epoch']<=now+15) or
            v['expires_epoch']<=now+min_remaining or
            v['expires_epoch']>v['issued_epoch']+180):return False
        # Trust only the doctor already SHA pinned by the Original V6 manifest.
        manifest=json.loads(file_read(ROOT/MANIFEST))
        doctor='native-source-publisher-v1/host_source_activation_doctor_v4.py'
        matches=[x for x in manifest.get('dependencies',[]) if x.get('path')==doctor]
        if len(matches)!=1:return False
        location=ROOT/doctor
        if hashlib.sha256(file_read(location)).hexdigest()!=matches[0]['sha256']:
            return False
        # The installed doctor imports first-party owner registration from
        # its own enrolled package; never resolve this from the mutable Git tree.
        import sys
        sys.path.insert(0,str(ROOT/'native-source-publisher-v1'))
        spec=importlib.util.spec_from_file_location('mad4b_original305_doctor_v4',location)
        if spec is None or spec.loader is None:return False
        module=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module.actual_original305_host_provider_ready(
            expected_nonce=v['nonce']) is True
    except Exception:
        # Any broken read-only verification fails closed; never accept a lease
        # from partial WP data, dependency import failure or unexpected error.
        return False

def independently_confirm_ambiguous_delivery(*,sleeper=time.sleep)->bool:
    """Read-only retry to separate transient WP readback failure from lost write.

    Never invokes an issuer. A bounded readback still requires a current
    signed nonce and pinned original Host SHA proof on *each* accepted path.
    A failing check cannot be declared a success.
    """
    if independent_signed_lease_since(min_remaining=25):
        return True
    sleeper(2)
    return independent_signed_lease_since(min_remaining=25)

def verdict(state:str,code:str|None=None):
    out={'contract':CONTRACT,'state':state,'signed_readiness_accepted':False,
         'host_release_authorized':False}
    if code:out['error_code']=code
    return out

def dispatch(*,runner=subprocess.run):
    if HOLD.exists():
        data=file_read(HOLD)
        require(len(data)<4096,'AMBIGUOUS_HOLD_INVALID')
        return verdict('AMBIGUOUS_SIGNED_WRITE_HOLD_NO_AUTOMATIC_RETRY'),5
    issuer=pinned_issuer()
    # A valid recent lease is already the desired state. Do not compete with
    # another authorized issuer or burn extra WordPress slots unnecessarily.
    # This is a read-only, original public-key + nonce + root-digest check.
    if independent_signed_lease_since(min_remaining=90):
        return {'contract':CONTRACT,
                'state':'ORIGINAL305_CURRENT_SIGNED_LEASE_NO_REFRESH_NEEDED',
                'signed_readiness_accepted':True,
                'new_signature_issued':False,
                'host_release_authorized':False},0
    started=int(time.time())
    try:
        p=runner(['/usr/bin/python3',str(issuer),'--refresh'],
                 capture_output=True,text=True,timeout=175,check=False)
    except (subprocess.TimeoutExpired,OSError,subprocess.SubprocessError):
        if independently_confirm_ambiguous_delivery():
            return {'contract':CONTRACT,
                    'state':'ORIGINAL305_CURRENT_SIGNED_LEASE_INDEPENDENTLY_VERIFIED',
                    'signed_readiness_accepted':True,
                    'issuer_result_ambiguous':True,
                    'new_signature_issued':None,
                    'host_release_authorized':False},0
        sticky_hold('SIGNING_SUBPROCESS_RESULT_UNKNOWN')
        return verdict('AMBIGUOUS_SIGNED_WRITE_HOLD_NO_AUTOMATIC_RETRY',
                       'SIGNING_SUBPROCESS_RESULT_UNKNOWN'),5
    try:
        rows=[json.loads(x) for x in p.stdout.splitlines() if x.startswith('{')]
        require(len(rows)==1 and isinstance(rows[0],dict),'ISSUER_RESULT_INVALID')
        message=rows[0]
    except (ValueError,Denied):
        if independently_confirm_ambiguous_delivery():
            return {'contract':CONTRACT,
                    'state':'ORIGINAL305_CURRENT_SIGNED_LEASE_INDEPENDENTLY_VERIFIED',
                    'signed_readiness_accepted':True,
                    'issuer_result_ambiguous':True,
                    'new_signature_issued':None,
                    'host_release_authorized':False},0
        sticky_hold('ISSUER_RESULT_INVALID')
        return verdict('AMBIGUOUS_SIGNED_WRITE_HOLD_NO_AUTOMATIC_RETRY','ISSUER_RESULT_INVALID'),5
    if p.returncode==0 and message.get('state')=='ORIGINAL305_SIGNED_HOST_PROVIDER_READY' and message.get('original_broker_readiness_signature_verified') is True and message.get('host_release_authorized') is False:
        return {'contract':CONTRACT,'state':'ORIGINAL305_SIGNED_REFRESH_VERIFIED',
                'signed_readiness_accepted':True,'host_release_authorized':False},0
    code=message.get('error_code')
    safe=re.fullmatch('[A-Z][A-Z0-9_]{3,119}',code) if isinstance(code,str) else None
    if p.returncode==3 and code in {
         'ORIGINAL_BROKER_HOST_REFRESH_CONCURRENT_DEFER',
         'ORIGINAL_BROKER_PUBLIC_WPCLI_CAPACITY_DEFERRED',
         'ORIGINAL_BROKER_PUBLIC_WPCLI_TIMEOUT_DEFERRED',
         'HOST_READINESS_SOURCE_HANDOFF_NOT_FRESH',
         'HOST_READINESS_ORIGINAL_SOURCE_CHANGED_DURING_SIGNING',
         'ORIGINAL_WORDPRESS_SIGNED_WRITE_STALE_PREWRITE'}:
        if independent_signed_lease_since(min_remaining=35):
            return {'contract':CONTRACT,
                    'state':'ORIGINAL305_CURRENT_SIGNED_LEASE_INDEPENDENTLY_VERIFIED',
                    'signed_readiness_accepted':True,
                    'new_signature_issued':False,
                    'issuer_result_ambiguous':False,
                    'host_release_authorized':False},0
        return verdict('RETRYABLE_PREWRITE_HOLD',code),3
    # A failed post-write acknowledgement may still leave a valid signed
    # option. Verify nonce, freshness, signature and original Host digests;
    # never sign again merely to resolve an ambiguous result.
    if independently_confirm_ambiguous_delivery():
        return {'contract':CONTRACT,
                'state':'ORIGINAL305_CURRENT_SIGNED_LEASE_INDEPENDENTLY_VERIFIED',
                'signed_readiness_accepted':True,
                'issuer_result_ambiguous':True,
                'new_signature_issued':None,
                'host_release_authorized':False},0
    # No independent confirmation: permanently latch HOLD until an operator
    # performs readback and explicitly authorizes reconciliation.
    sticky_hold('ISSUER_SIGNED_RESULT_UNCONFIRMED')
    return verdict('AMBIGUOUS_SIGNED_WRITE_HOLD_NO_AUTOMATIC_RETRY',
                   code if safe else 'ISSUER_SIGNED_RESULT_UNCONFIRMED'),5

if __name__=='__main__':
    try:result,rc=dispatch()
    except (Denied,ValueError,OSError):
        result,rc=verdict('FIRSTPARTY_SUPERVISOR_SAFETY_HOLD','FIRSTPARTY_SUPERVISOR_CHECK_FAILED'),4
    print(json.dumps(result,sort_keys=True))
    raise SystemExit(rc)

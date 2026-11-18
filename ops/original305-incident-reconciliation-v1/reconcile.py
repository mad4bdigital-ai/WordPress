#!/usr/bin/env python3
"""One-time original305 ambiguous-readiness incident reconciliation.

Operator-only after independently verifying the expired, correctly signed
WordPress Host readiness option. Archives, never deletes, original HOLD.
No issuer is invoked by this script. No original305 grants or content writes.
"""
from __future__ import annotations
import datetime as dt,fcntl,hashlib,importlib.util,json,os,pathlib,stat,subprocess,tempfile
import argparse

BASE=pathlib.Path('/opt/mad4b-company-transport/native-313-independent/host-source-v1')
HOLD=BASE/'original305-readiness-ambiguous-write-HOLD.json'
SUPERVISOR=BASE/'original305_readiness_supervisor_v7.py'
SIGNER_LOCK=BASE/'original305-broker-readiness-refresh.lock'
REPORT=pathlib.Path('/opt/mad4b-company-provisioning/reports/original305-incident-reconcile-20261118')
RECONCILE_LOCK=pathlib.Path('/run/lock/mad4b-original305-manual-reconcile.lock')
POLICY_SIGNER=pathlib.Path('/opt/mad4b-company-transport/broker-v1/native-vps-policy-rotate.py')
EXPECTED_HOLD_SHA='21f299380f89b803e70e463541ff953fa5e67f6c3eef963fe6925210aa8c1695'
CONTRACT='mad4b.original305.manually-reviewed-signed-readiness-hold.v1'

def checksum(data):return hashlib.sha256(data).hexdigest()
def root_read(path,max_size=140_000):
    if path.is_symlink() or not path.is_file():raise RuntimeError('ORIGINAL305_FILE_SYMLINK_OR_MISSING')
    st=path.stat()
    if (st.st_uid!=0 or not stat.S_ISREG(st.st_mode) or
        st.st_mode & 0o077 or st.st_nlink!=1 or st.st_size>max_size):
        raise RuntimeError('ORIGINAL305_ROOT_ONLY_FILE_REQUIRED')
    return path.read_bytes()

def load_supervisor():
    data=root_read(SUPERVISOR)
    spec=importlib.util.spec_from_file_location('_original305_current_readiness_supervisor',SUPERVISOR)
    if spec is None or spec.loader is None:raise RuntimeError('ORIGINAL305_SUPERVISOR_NOT_LOADABLE')
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    issuer=module.pinned_issuer()
    if not issuer.is_file():raise RuntimeError('ORIGINAL305_ORIGINAL_ISSUER_NOT_PINNED')
    src=root_read(issuer)
    if (b"WP_OPTION='mad4b_original_host_source_readiness_v1'" not in src
        or src.count(b"update_option('mad4b_original_host_source_readiness_v1'")!=1):
        raise RuntimeError('ORIGINAL305_ISSUER_WRITES_NOT_BOUNDED')
    return module,checksum(src)

def live_signed_readback():
    php=r'''
$r=get_option('mad4b_original_host_source_readiness_v1',null);
$o=['exists'=>is_array($r),'signature_valid'=>false,'expired'=>false,
 'issuer_epoch'=>null,'expires_epoch'=>null,'nonce_shape_verified'=>false,
 'ready_claimed'=>false];
if(is_array($r)&&class_exists('MAD4B_Central_VPS_Broker_V1',false)){
 $key=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
 $sig=base64_decode((string)($r['signature']??''),true);
 $data=$r;unset($data['signature']);ksort($data,SORT_STRING);
 $encoded=wp_json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 $o['signature_valid']=is_string($key)&&strlen($key)===32&&is_string($sig)&&strlen($sig)===64&&is_string($encoded)
 &&sodium_crypto_sign_verify_detached($sig,'mad4b.original-host-source-readiness.v1'."\n".$encoded,$key);
 $o['expired']=is_int($r['expires_epoch']??null)&&$r['expires_epoch']<time()-30;
 $o['issuer_epoch']=$r['issued_epoch']??null;$o['expires_epoch']=$r['expires_epoch']??null;
 $o['nonce_shape_verified']=is_string($r['nonce']??null)&&preg_match('/^[a-f0-9]{32}$/D',$r['nonce'])===1;
 $o['ready_claimed']=($r['ready']??false)===true;
}
echo 'INCIDENT_SIGNED_READBACK:'.wp_json_encode($o)."\n";
'''
    p=subprocess.run(['docker','exec','-u','www-data','php-wkgtkzta35wkkgouwt6ruljc',
        'wp','--path=/var/www/html','--skip-themes','eval',php],
        capture_output=True,text=True,timeout=62,check=False)
    rows=[x.split('INCIDENT_SIGNED_READBACK:',1)[1] for x in p.stdout.splitlines()
          if x.startswith('INCIDENT_SIGNED_READBACK:')]
    if p.returncode or len(rows)!=1:raise RuntimeError('ORIGINAL305_SIGNED_WORDPRESS_READBACK_UNAVAILABLE')
    x=json.loads(rows[0])
    if (x.get('exists') is not True or x.get('signature_valid') is not True
        or x.get('expired') is not True or x.get('ready_claimed') is not True
        or x.get('nonce_shape_verified') is not True
        or type(x.get('issuer_epoch')) is not int
        or type(x.get('expires_epoch')) is not int
        or x['expires_epoch']-x['issuer_epoch']!=180):
        raise RuntimeError('ORIGINAL305_SIGNED_READINESS_NOT_INDEPENDENTLY_RESOLVED')
    return x

def current_host_ledger():
    p=subprocess.run(['/usr/bin/python3',str(POLICY_SIGNER),'plan'],
        capture_output=True,text=True,timeout=35,check=False)
    rows=[json.loads(x) for x in p.stdout.splitlines() if x.startswith('{')]
    if p.returncode or len(rows)!=1:raise RuntimeError('ORIGINAL305_PINNED_HOST_POLICY_UNAVAILABLE')
    r=rows[0]
    if (r.get('state')!='ORIGINAL_POLICY_ROTATION_NOT_REQUIRED'
        or r.get('original_pinned_signer_verified') is not True
        or r.get('original_principal_ledger_verified') is not True
        or r.get('host_key_verified') is not True
        or r.get('grant_issued') is not False
        or r.get('production_mutated') is not False):
        raise RuntimeError('ORIGINAL305_HOST_LEDGER_NOT_SIGNED_CURRENT')
    return True

def verified_evidence():
    if os.geteuid()!=0:raise RuntimeError('ORIGINAL305_MANUAL_ROOT_REQUIRED')
    raw=root_read(HOLD,4096)
    if checksum(raw)!=EXPECTED_HOLD_SHA:raise RuntimeError('ORIGINAL305_ORIGINAL_HOLD_PREIMAGE_CHANGED')
    doc=json.loads(raw)
    if (doc.get('state')!='AMBIGUOUS_SIGNED_WRITE_HOLD'
        or doc.get('reason_code')!='ISSUER_SIGNED_RESULT_UNCONFIRMED'
        or doc.get('automatic_retry_allowed') is not False):
        raise RuntimeError('ORIGINAL305_INCIDENT_CLASS_UNEXPECTED')
    sup,source_sha=load_supervisor()
    issued=live_signed_readback()
    # Check the exact signed-readback contract again. A future replacement
    # reader or accidental mock must never turn partial evidence into consent.
    if (not isinstance(issued,dict)
        or issued.get('exists') is not True
        or issued.get('signature_valid') is not True
        or issued.get('expired') is not True
        or issued.get('nonce_shape_verified') is not True
        or issued.get('ready_claimed') is not True
        or type(issued.get('issuer_epoch')) is not int
        or type(issued.get('expires_epoch')) is not int
        or issued['expires_epoch']-issued['issuer_epoch']!=180):
        raise RuntimeError('ORIGINAL305_SIGNED_READINESS_NOT_INDEPENDENTLY_RESOLVED')
    if issued['issuer_epoch']<int(HOLD.stat().st_mtime) or issued['expires_epoch']>=int(dt.datetime.now(dt.timezone.utc).timestamp()):
        raise RuntimeError('ORIGINAL305_RECONCILIATION_TIME_BINDING_MISMATCH')
    current_host_ledger()
    return {'hold_sha256':checksum(raw),
        'pinned_v8_issuer_sha256':source_sha,
        'old_signed_readiness_signature_valid':True,
        'old_signed_readiness_expired':True,
        'issued_epoch':issued['issuer_epoch'],
        'expires_epoch':issued['expires_epoch'],
        'original_host_policy_current':True,
        'target':'host.source.release',
        'site_write_grants_issued':0}

def atomic_json(path,data):
    fd,tmp=tempfile.mkstemp(dir=path.parent,prefix='.original305-incident-')
    try:
        with os.fdopen(fd,'w') as f:
            os.fchmod(f.fileno(),0o600);json.dump(data,f,indent=2,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chown(tmp,0,0);os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def run(mode):
    if os.geteuid()!=0:raise RuntimeError('ORIGINAL305_MANUAL_ROOT_REQUIRED')
    REPORT.mkdir(mode=0o700,exist_ok=True)
    if REPORT.is_symlink() or REPORT.stat().st_uid!=0 or REPORT.stat().st_mode&0o077:
        raise RuntimeError('ORIGINAL305_REPORT_DIR_NOT_TRUSTED')
    fd=os.open(RECONCILE_LOCK,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        if os.fstat(fd).st_uid!=0 or os.fstat(fd).st_mode&0o077:
            raise RuntimeError('ORIGINAL305_RECONCILE_LOCK_INVALID')
        fcntl.flock(fd,fcntl.LOCK_EX)
        lease_fd=os.open(SIGNER_LOCK,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
        try:
            if os.fstat(lease_fd).st_uid!=0 or os.fstat(lease_fd).st_mode&0o077:
                raise RuntimeError('ORIGINAL305_BROKER_LOCK_NOT_ROOT_ONLY')
            try:fcntl.flock(lease_fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            except BlockingIOError:raise RuntimeError('ORIGINAL305_SIGNER_ACTIVE_NO_RECONCILIATION')
            evidence=verified_evidence()
            result={'contract':CONTRACT,'observed_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
                'state':'ORIGINAL305_AMBIGUOUS_READINESS_PREIMAGE_VERIFIED' if mode=='plan'
                    else 'ORIGINAL305_AMBIGUOUS_READINESS_INCIDENT_ARCHIVED',
                'evidence':evidence,'original_hold_archived':mode=='apply',
                'new_signature_issued':False,'original305_grants_created':0,
                'production_content_mutated':False,'supervisor_timer_enabled':False}
            if mode=='plan':return result
            backup=REPORT/('original305-readiness-ambiguous-write-HOLD-'+EXPECTED_HOLD_SHA+'.json')
            receipt=REPORT/'reconciliation-receipt.json'
            if backup.exists() or receipt.exists():raise RuntimeError('ORIGINAL305_RECONCILIATION_ALREADY_APPLIED')
            if root_read(HOLD,4096)!=HOLD.read_bytes():raise RuntimeError('ORIGINAL305_HOLD_CHANGED')
            # Move the exact preimage into a root-only immutable audit folder,
            # never unlink and never create a false signed Grant.
            os.replace(HOLD,backup)
            if checksum(root_read(backup,4096))!=EXPECTED_HOLD_SHA:
                os.replace(backup,HOLD)
                raise RuntimeError('ORIGINAL305_HOLD_ARCHIVE_READBACK_MISMATCH')
            try:atomic_json(receipt,result)
            except Exception:
                os.replace(backup,HOLD)
                raise
            return result
        finally:os.close(lease_fd)
    finally:os.close(fd)

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('mode',choices=('plan','apply'));args=p.parse_args()
    try:print(json.dumps(run(args.mode),sort_keys=True))
    except (RuntimeError,OSError,ValueError,KeyError,TypeError,subprocess.SubprocessError) as exc:
        print(json.dumps({'state':'HOLD','reason':str(exc)[:150],
           'original305_grants_created':0,'production_content_mutated':False},sort_keys=True))
        raise SystemExit(2)

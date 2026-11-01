#!/usr/bin/env python3
"""Bounded original-Core evidence collection, one fixed WP-CLI read per call.

The host connector has a short invocation limit and each original WordPress
WP-CLI read revalidates a dynamic actor. Never weaken the user authorization:
collect five independent source reads in separate calls, then verify same
source, freshness and scope before classifying. All on-disk evidence is private
and non-authorizing; no host/command/grant supplied by caller.
"""
import importlib.util
import json
import os
import pathlib
import stat
import sys
import tempfile
import time

HOST_ROOT=pathlib.Path('/opt/mad4b-company-transport/broker-v1')
READER=HOST_ROOT/'original-core-channel-evidence.py'
STORE=HOST_ROOT/'original-core-channel-evidence-cache'
REGISTRATION=pathlib.Path('/opt/mad4b-company-provisioning/governed-execution/native-ssh-v1/registration.json')
CONTRACT='mad4b.original-core-channel-evidence-snapshot.v1'
SEGMENTS=('scope','registry','verifier','claims','receipt')
EXPECTED={
 'scope':'mad4b.original-host-ledger-scope.v1',
 'registry':'mad4b.broker-registry-ssh-status.v1',
 'verifier':'mad4b.broker-exact-ssh-authority.v1',
 'claims':'mad4b.broker-exact-ssh-authority.v1',
 'receipt':'mad4b.broker-remote-ssh-diagnostic.v1'
}
FRESH_SECONDS=145
MAX_SKEW_SECONDS=120

def require_root():
    if os.geteuid()!=0:
        raise RuntimeError('ROOT_STAGING_READER_REQUIRED')

def check_private_dir(directory,create=False):
    if create and not directory.exists():
        directory.mkdir(mode=0o700)
    s=directory.lstat()
    if not stat.S_ISDIR(s.st_mode) or s.st_uid!=0 or s.st_mode&0o077:
        raise RuntimeError('PRIVATE_SNAPSHOT_STORE_REQUIRED')
    return directory

def check_private_file(path):
    s=path.lstat()
    if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode&0o077:
        raise RuntimeError('ROOT_PROTECTED_ORIGINAL_EVIDENCE_REQUIRED')
    if s.st_size>65536:raise RuntimeError('OVERSIZE_ORIGINAL_EVIDENCE')
    return path

def reader():
    check_private_file(READER)
    spec=importlib.util.spec_from_file_location('mad4b_original_channel_evidence',READER)
    if spec is None or spec.loader is None:
        raise RuntimeError('ORIGINAL_READER_UNAVAILABLE')
    mod=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

def source_command(segment,mod):
    return {
      'scope':['eval',mod.SCOPE_SCRIPT],
      'registry':['mad4b','broker-registry-ssh','status'],
      'verifier':['mad4b','broker-authority','status'],
      'claims':['mad4b','broker-authority','claimed','--limit=2'],
      'receipt':['mad4b','broker-remote-ssh','status']
    }[segment]

def record(segment,mod):
    if segment not in SEGMENTS:raise RuntimeError('UNREGISTERED_EVIDENCE_SECTION')
    data=mod.read_fixed(source_command(segment,mod))
    if not isinstance(data,dict) or data.get('contract')!=EXPECTED[segment]:
        raise RuntimeError('ORIGINAL_CORE_EVIDENCE_CONTRACT_MISMATCH')
    return {'contract':CONTRACT,'section':segment,
            'source':'original_wordpress_core_wpcli',
            'collected_epoch':int(time.time()),'data':data}

def persist(section,record,store=STORE):
    check_private_dir(store,create=True)
    fd,tmp=tempfile.mkstemp(prefix='.original-core-evidence-',dir=store)
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,'w',encoding='utf-8') as f:
            json.dump(record,f,separators=(',',':'),sort_keys=True)
            f.write('\n');f.flush();os.fsync(f.fileno())
        os.replace(tmp,store/(section+'.json'))
    except BaseException:
        if os.path.exists(tmp):os.unlink(tmp)
        raise

def verify_records(rows,now):
    if set(rows)!=set(SEGMENTS):raise RuntimeError('INCOMPLETE_ORIGINAL_EVIDENCE')
    epochs=[]
    payload={}
    for section in SEGMENTS:
        record=rows[section]
        if not isinstance(record,dict) or record.get('contract')!=CONTRACT or (
           record.get('section')!=section or
           record.get('source')!='original_wordpress_core_wpcli'):
            raise RuntimeError('EVIDENCE_OR_SOURCE_CONTRACT_INVALID')
        ts=record.get('collected_epoch')
        if type(ts) is not int or ts>now+5 or now-ts>FRESH_SECONDS:
            raise RuntimeError('EVIDENCE_STALE_OR_FUTURE')
        data=record.get('data')
        if not isinstance(data,dict) or data.get('contract')!=EXPECTED[section]:
            raise RuntimeError('EVIDENCE_SECTION_CONTRACT_MISMATCH')
        epochs.append(ts);payload[section]=data
    if max(epochs)-min(epochs)>MAX_SKEW_SECONDS:
        raise RuntimeError('ORIGINAL_EVIDENCE_COLLECTION_SKEW')
    return payload

def load_all(store=STORE,now=None):
    check_private_dir(store)
    rows={}
    for section in SEGMENTS:
        p=check_private_file(store/(section+'.json'))
        rows[section]=json.loads(p.read_text(encoding='utf-8'))
    return verify_records(rows,int(time.time()) if now is None else now)

def read_registration():
    doc=json.loads(check_private_file(REGISTRATION).read_text())
    if doc.get('contract')!='mad4b.native-managed-ssh-installation.v1':
        raise RuntimeError('ORIGINAL_NATIVE_REGISTRATION_CONTRACT_INVALID')
    return doc

def status():
    mod=reader()
    rows=load_all()
    reg=read_registration()
    return mod.classify(rows['scope'],rows['registry'],rows['verifier'],
                        rows['claims'],rows['receipt'],reg,int(time.time()))

def selftest():
    import tempfile
    now=2000000000
    scope={section:{'contract':CONTRACT,'section':section,
       'source':'original_wordpress_core_wpcli','collected_epoch':now-10,
       'data':{'contract':EXPECTED[section]}} for section in SEGMENTS}
    def must_fail(rows,t,code):
        try:verify_records(rows,t)
        except RuntimeError as e:
            assert str(e)==code,(str(e),code)
        else:raise AssertionError('EXPECTED_FAILURE_'+code)
    assert len(verify_records(scope,now))==5
    must_fail({k:v for k,v in scope.items() if k!='claims'},now,
              'INCOMPLETE_ORIGINAL_EVIDENCE')
    must_fail({**scope,'scope':{**scope['scope'],'collected_epoch':now-150}},
              now,'EVIDENCE_STALE_OR_FUTURE')
    must_fail({**scope,'claims':{**scope['claims'],'collected_epoch':now+12}},
              now,'EVIDENCE_STALE_OR_FUTURE')
    must_fail({**scope,'receipt':{**scope['receipt'],'section':'claims'}},
              now,'EVIDENCE_OR_SOURCE_CONTRACT_INVALID')
    must_fail({**scope,'claims':{**scope['claims'],'source':'untrusted_provider'}},
              now,'EVIDENCE_OR_SOURCE_CONTRACT_INVALID')
    must_fail({**scope,'registry':{**scope['registry'],'data':{'contract':'forged'}}},
              now,'EVIDENCE_SECTION_CONTRACT_MISMATCH')
    must_fail({**scope,'scope':{**scope['scope'],'collected_epoch':now-130},
              'registry':{**scope['registry'],'collected_epoch':now}},
              now,'ORIGINAL_EVIDENCE_COLLECTION_SKEW')
    with tempfile.TemporaryDirectory() as tmp:
        store=pathlib.Path(tmp)/'evidence'
        for name,item in scope.items():
            persist(name,item,store)
        assert len(load_all(store,now))==5
        assert all((store/(name+'.json')).stat().st_mode&0o077==0
                   for name in SEGMENTS)
    print(json.dumps({'contract':CONTRACT,'selftest':'PASS',
      'assertions':10,'grants_created':0,'execution_performed':False}))

def main():
    if len(sys.argv)==2 and sys.argv[1]=='selftest':
        selftest();return
    require_root()
    if len(sys.argv)==3 and sys.argv[1]=='collect' and sys.argv[2] in SEGMENTS:
        name=sys.argv[2]
        item=record(name,reader())
        persist(name,item)
        print(json.dumps({'contract':CONTRACT,'section':name,
          'original_wpcli_verified':True,
          'collected_epoch':item['collected_epoch'],
          'execution_performed':False,'grants_created':0}))
    elif len(sys.argv)==2 and sys.argv[1]=='summary':
        try:print(json.dumps(status(),sort_keys=True,separators=(',',':')))
        except (RuntimeError,ValueError,OSError,KeyError,json.JSONDecodeError):
            print(json.dumps({'contract':'mad4b.original-core-channel-evidence-readback.v1',
              'state':'READBACK_UNAVAILABLE_FAIL_CLOSED',
              'blockers':['ORIGINAL_CORE_EVIDENCE_MISSING_STALE_OR_INVALID'],
              'native_execute_authorized':False,
              'provider_recovery_authorized':False,
              'new_job_created':False,'operation_executed':False,
              'production_mutation':False}))
    else:
        raise SystemExit('usage: original-core-channel-evidence-snapshot.py collect <section>|summary|selftest')

if __name__=='__main__':main()

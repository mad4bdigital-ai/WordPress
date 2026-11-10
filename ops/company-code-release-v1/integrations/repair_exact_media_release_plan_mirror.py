#!/usr/bin/env python3
"""One-time repair for a previously verified exact media plan enrollment."""
import json,os,stat,sys,hashlib
from pathlib import Path
BASE=Path('/opt/mad4b-company-transport/worktrees/company-code-release-20261108/ops/company-code-release-v1');sys.path.insert(0,str(BASE))
import company_code_release as release
SHA='e476da28eafac45bc79c0e89bc2a3446efe77958d8aa64ec7eb220fcd3a56852'
SRC=Path('/var/lib/mad4b/dynamic-operation-registry/plans')/(SHA+'.json')
DEST=Path('/var/lib/mad4b/company-code-release/plans')/(SHA+'.json')
AUDIT=Path('/opt/mad4b-company-provisioning/reports/p1-closure-20261109/media/wayfa-original-release-plan-mirror-repair-receipt.json')
if __name__=='__main__':
    assert os.geteuid()==0 and len(sys.argv)==2 and sys.argv[1] in ('--check','--apply')
    policy=release.trusted_config(release.POLICY_PATH)
    assert SRC.is_file() and SRC.stat().st_uid==0 and SRC.stat().st_mode&0o077==0
    plan=json.loads(SRC.read_text())
    assert plan['plan_sha256']==SHA and plan['site_uuid']=='a3a7cc36-db7e-4a14-8456-b41857def05c'
    assert len(plan['files'])==1 and plan['files'][0]['target']=='wp-content/mu-plugins/mad4b-media-library-discovery-v1.php'
    release.confirm_plan(plan,policy,expect_preimage=True)
    assert DEST.parent.stat().st_uid==0 and DEST.parent.stat().st_mode&0o077==0
    assert not DEST.exists() and not AUDIT.exists()
    record={'contract':'mad4b.company-code-release.mirror-repair.v1','site_uuid':plan['site_uuid'],'plan_sha256':SHA,'source_sha256':hashlib.sha256(SRC.read_bytes()).hexdigest(),'mirror_created':False,'broker_grant_created':False,'wordpress_modified':False,'original_305_job_created':False}
    if sys.argv[1]=='--apply':
        fd=os.open(DEST,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        try:
            with os.fdopen(fd,'wb') as f:f.write(SRC.read_bytes());f.flush();os.fsync(f.fileno())
            assert json.loads(DEST.read_text())==plan and DEST.stat().st_mode&0o077==0
            record['mirror_created']=True
            fd=os.open(AUDIT,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
            with os.fdopen(fd,'w') as f:json.dump(record,f,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
        except BaseException:
            DEST.unlink(missing_ok=True)
            raise
    print(json.dumps(record,sort_keys=True))

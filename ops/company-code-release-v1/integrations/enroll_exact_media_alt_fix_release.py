#!/usr/bin/env python3
"""One-scope audited first-party policy/plan enrollment. Does NOT grant or deploy."""
import argparse, fcntl, hashlib, json, os, stat, tempfile, sys, subprocess
from pathlib import Path
BASE=Path('/opt/mad4b-company-transport/worktrees/company-code-release-20261108/ops/company-code-release-v1')
sys.path.insert(0,str(BASE))
sys.path.insert(0,'/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
import company_code_release as release
from registry import Registry
POLICY=Path('/etc/mad4b/company-code-release-policy.json')
SOURCE=Path('/opt/mad4b-company-provisioning/reports/p1-closure-20261109/media/wayfa-media-alt-fix-release-plan.json')
PLANS=Path('/var/lib/mad4b/dynamic-operation-registry/plans')
RELEASE_PLANS=Path('/var/lib/mad4b/company-code-release/plans')
BACKUPS=Path('/opt/mad4b-company-provisioning/reports/p1-closure-20261109/media')
LOCK=Path('/run/lock/mad4b-wayfa-media-policy-enrollment.lock')
TARGET='wp-content/mu-plugins/mad4b-tour-media-governed-apply.php'
SITE='a3a7cc36-db7e-4a14-8456-b41857def05c'
COMMIT='1122fb65649c0f7c5a317d2d9bf0646d19e742ac'
SOURCE_SHA='5b03288e9c4907e5b5925f35721b4442eee58ee629fa99da0d10ca2903e7d012'
EXPECTED_PLAN='107c2b40d0b23c802a0ba30b1afb3294cf14d88d4f882c72be7a5677536f5809'

def require(x,reason):
    if not x:raise RuntimeError(reason)

def atomic(path, data, mode):
    fd,temp=tempfile.mkstemp(prefix='.enroll-',dir=path.parent)
    try:
        os.fchmod(fd,mode)
        with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
        os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def perform(apply):
    require(os.geteuid()==0,'FIRST_PARTY_ROOT_REQUIRED')
    p=release.trusted_config(POLICY)
    require(set(p['targets'])=={'wayfatours:production'},'UNEXPECTED_POLICY_SCOPE')
    plan=json.loads(SOURCE.read_text())
    require(plan['contract']==release.PLAN_CONTRACT and plan['company']=='wayfatours' and plan['environment']=='production' and plan['site_uuid']==SITE,'SCOPE_MISMATCH')
    require(plan['git_commit']==COMMIT and plan['plan_sha256']==EXPECTED_PLAN and plan['changed_count']==1,'UNEXPECTED_RELEASE')
    require(len(plan['files'])==1 and plan['files'][0]['source']==TARGET and plan['files'][0]['target']==TARGET and plan['files'][0]['source_sha256']==SOURCE_SHA and plan['files'][0]['expected_before_sha256']=='73a180d9cd33dc3c8a6efce6451595bb2f0bc63794da0f0b13d151eac9dc575f','UNEXPECTED_FILE_OR_PREIMAGE')
    current=p['targets']['wayfatours:production']['allowed_targets']
    require(len(current)==6 and TARGET not in current,'POLICY_ALREADY_CHANGED_OR_WRONG_BASELINE')
    require(hashlib.sha256((Path(plan['target_root'])/TARGET).read_bytes()).hexdigest()=='73a180d9cd33dc3c8a6efce6451595bb2f0bc63794da0f0b13d151eac9dc575f','TARGET_PREIMAGE_CHANGED')
    # Prove source is the immutable commit object, never trust working-tree content.
    require(hashlib.sha256(release.git_bytes(Path(p['source_git_root']),COMMIT,TARGET)).hexdigest()==SOURCE_SHA,'SOURCE_GIT_OBJECT_DRIFT')
    old=POLICY.read_bytes(); oldhash=hashlib.sha256(old).hexdigest()
    require(oldhash=='119a4042bfb6c9f7dcf658bf8451d5ed60f696969d6c1a73231d768be3421baf','POLICY_PREIMAGE_NOT_APPROVED')
    reg=Registry('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled');reg.resolve('company.code.release')
    st=PLANS.stat();require(st.st_uid==0 and stat.S_IMODE(st.st_mode)&0o077==0 and PLANS.is_dir(),'PLAN_DIR_NOT_PROTECTED')
    dest=PLANS/(EXPECTED_PLAN+'.json');require(not dest.exists(),'PLAN_ALREADY_INSTALLED')
    mirror=RELEASE_PLANS/(EXPECTED_PLAN+'.json');require(not mirror.exists(),'COMPANY_RELEASE_PLAN_ALREADY_INSTALLED')
    mst=RELEASE_PLANS.stat();require(mst.st_uid==0 and stat.S_IMODE(mst.st_mode)&0o077==0 and RELEASE_PLANS.is_dir(),'COMPANY_RELEASE_PLAN_DIR_NOT_PROTECTED')
    nextpolicy=json.loads(json.dumps(p));nextpolicy['targets']['wayfatours:production']['allowed_targets'].append(TARGET)
    rebuilt=release.make_plan({'contract':release.SPEC_CONTRACT,'company':'wayfatours','environment':'production','site_uuid':SITE,'git_commit':COMMIT,'files':[{'source':TARGET,'target':TARGET,'expected_before_sha256':'73a180d9cd33dc3c8a6efce6451595bb2f0bc63794da0f0b13d151eac9dc575f'}],'acceptance_checks':['wp_boot']},nextpolicy)
    require(rebuilt==plan,'REBUILT_PLAN_DIFFERENT')
    release.confirm_plan(plan,nextpolicy,expect_preimage=True)
    receipt={'contract':'mad4b.exact-media-alt-fix-plan-enrollment.v1','operation':'bounded_policy_and_immutable_plan_enrollment','site_uuid':SITE,'policy_before_sha256':oldhash,'plan_sha256':EXPECTED_PLAN,'target':TARGET,'source_git_sha256':SOURCE_SHA,'broker_grant_created':False,'wordpress_files_changed':False,'job_created':False,'apply':apply}
    if not apply:return receipt
    backup=BACKUPS/('wayfa-code-release-policy-before-'+oldhash+'.json')
    require(not backup.exists(),'BACKUP_ALREADY_EXISTS')
    fd=os.open(backup,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    with os.fdopen(fd,'wb') as f:f.write(old);f.flush();os.fsync(f.fileno())
    pending=release.canonical(plan)+b'\n'
    policybytes=release.canonical(nextpolicy)+b'\n'
    try:
        # Install registered immutable plan first; 306 cannot dispatch without original signed 305 request and a separate grant.
        fd=os.open(dest,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        with os.fdopen(fd,'wb') as f:f.write(pending);f.flush();os.fsync(f.fileno())
        fd=os.open(mirror,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        with os.fdopen(fd,'wb') as f:f.write(pending);f.flush();os.fsync(f.fileno())
        atomic(POLICY,policybytes,0o600)
        require(release.trusted_config(POLICY)==nextpolicy,'POLICY_READBACK_FAILED')
        release.confirm_plan(plan,nextpolicy,expect_preimage=True)
        import worker_adapter
        current=worker_adapter.digest_table(reg,PLANS)
        require(reg.command_sha256('company.code.release',EXPECTED_PLAN) in current,'DYNAMIC_306_COMMAND_NOT_INDEXED')
    except BaseException:
        atomic(POLICY,old,0o600)
        dest.unlink(missing_ok=True)
        mirror.unlink(missing_ok=True)
        raise
    receipt.update({'state':'PLAN_ENROLLED_PENDING_ORIGINAL_SIGNED_305_306_312_313','production_code_deployed':False,'plan_installed':True,'company_release_plan_mirror_installed':True,'policy_after_sha256':hashlib.sha256(policybytes).hexdigest(),'backup_path':str(backup)})
    return receipt

if __name__=='__main__':
    parser=argparse.ArgumentParser();x=parser.add_mutually_exclusive_group(required=True);x.add_argument('--check',action='store_true');x.add_argument('--apply',action='store_true');a=parser.parse_args()
    with open(LOCK,'a+') as f:
        fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)
        print(json.dumps(perform(a.apply),sort_keys=True))

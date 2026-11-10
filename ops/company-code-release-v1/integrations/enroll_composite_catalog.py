#!/usr/bin/env python3
"""Bounded First-party catalog enrollment: policy + immutable plans, no release grant.

Preserves the existing Git root and static allowlist so signed legacy plans remain
valid. Original Broker alone can issue 305/306/312 signed authority for Apply.
"""
from __future__ import annotations
import argparse,fcntl,hashlib,json,os,subprocess,sys
from pathlib import Path
BASE=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(BASE))
import company_code_release as release
REG=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
POLICY=Path('/etc/mad4b/company-code-release-policy.json')
PLANS=Path('/var/lib/mad4b/dynamic-operation-registry/plans')
RELEASE_PLANS=Path('/var/lib/mad4b/company-code-release/plans')
BACKUP=Path('/opt/mad4b-company-provisioning/reports/media-universal-20261110/composite-policy-backups')
LOCK=Path('/run/lock/mad4b-company-release-catalog.lock')
CATALOG='ops/company-code-release-v1/catalog/mad4b-release-components.json'
FILES=['wp-content/mu-plugins/mad4b-composite-media-patch.php',
       'wp-content/mu-plugins/mad4b-composite-media-adapter.php',
       'wp-content/mu-plugins/mad4b-media-composite-integrity.php',
       'wp-content/mu-plugins/mad4b-unified-media-apply.php']

def insist(what,why):
    if not what:raise RuntimeError(why)

def digest(data):return hashlib.sha256(data).hexdigest()

def write_new(path,data):
    insist(not path.exists() and not path.is_symlink(),'DESTINATION_EXISTS:'+str(path))
    fd=os.open(path,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
    with os.fdopen(fd,'wb') as out:out.write(data);out.flush();os.fsync(out.fileno())

def atomic(path,data):
    from tempfile import mkstemp
    fd,temp=mkstemp(prefix='.scoped-',dir=path.parent)
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,'wb') as out:out.write(data);out.flush();os.fsync(out.fileno())
        os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def check():
    insist(os.geteuid()==0,'ROOT_REQUIRED')
    old=POLICY.read_bytes(); policy=release.trusted_config(POLICY)
    insist(set(policy['targets'])=={'wayfatours:production'},'COMPANY_BINDING_CHANGED')
    site=policy['targets']['wayfatours:production']
    insist(site.get('release_catalog') is None,'CATALOG_ALREADY_ENROLLED')
    insist(site['site_uuid']=='a3a7cc36-db7e-4a14-8456-b41857def05c','SITE_IDENTITY_CHANGED')
    gitroot=Path(policy['source_git_root'])
    source=BASE.parent.parent
    insist(not subprocess.check_output(['git','-C',str(source),'status','--porcelain']).strip(),'SOURCE_UNCOMMITTED')
    commit=subprocess.check_output(['git','-C',str(source),'rev-parse','HEAD'],text=True).strip()
    release.git_bytes(gitroot,commit,CATALOG)
    import importlib.util
    modulepath=REG/'registry.py'
    spec=importlib.util.spec_from_file_location('first_party_registry_reader',modulepath)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    current=module.Registry(REG).resolve('company.code.release')
    dep=next(x for x in current['dependencies'] if x['path']=='company-code-release-v1/company_code_release.py')
    insist(dep['sha256']==digest((BASE/'company_code_release.py').read_bytes()),'RELEASE_EXECUTOR_NOT_REPINNED')
    upcoming=json.loads(json.dumps(policy))
    upcoming['targets']['wayfatours:production']['release_catalog']=CATALOG
    root=Path(site['root'])
    rows=[]
    for name in FILES:
        target=root/name
        before=digest(target.read_bytes()) if target.is_file() else None
        rows.append({'source':name,'target':name,'expected_before_sha256':before})
    request={'contract':release.SPEC_CONTRACT,'company':'wayfatours','environment':'production',
             'site_uuid':site['site_uuid'],'git_commit':commit,'files':rows,'acceptance_checks':['wp_boot']}
    plan=release.make_plan(request,upcoming)
    release.confirm_plan(plan,upcoming,expect_preimage=True)
    insist(plan['changed_count']==4,'EXPECTED_FOUR_CHANGED_FILES')
    for store in (PLANS,RELEASE_PLANS):
        insist(store.is_dir() and not store.is_symlink() and store.stat().st_uid==0 and store.stat().st_mode&0o077==0,'PLAN_STORE_NOT_PRIVATE')
        insist(not (store/(plan['plan_sha256']+'.json')).exists(),'PLAN_ALREADY_ENROLLED')
    return old,upcoming,plan

def run(install):
    old,upcoming,plan=check()
    report={'contract':'mad4b.composite-release-catalog-enrollment.v1','state':'CATALOG_PLAN_PREVIEW_VERIFIED',
            'git_commit':plan['git_commit'],'plan_sha256':plan['plan_sha256'],
            'company':plan['company'],'environment':plan['environment'],
            'source_git_root_preserved':True,'static_allowlist_preserved':True,
            'files':len(plan['files']),'grant_created':False,'wp_files_changed':False}
    if not install:return report
    BACKUP.mkdir(mode=0o700,parents=True,exist_ok=True)
    os.chmod(BACKUP,0o700)
    previous=digest(old)
    snapshot=BACKUP/('company-release-policy-before-'+previous+'.json')
    write_new(snapshot,old)
    plans=[s/(plan['plan_sha256']+'.json') for s in (PLANS,RELEASE_PLANS)]
    inserted=[]
    try:
        for target in plans:
            write_new(target,release.canonical(plan)+b'\n');inserted.append(target)
        atomic(POLICY,release.canonical(upcoming)+b'\n')
        reloaded=release.trusted_config(POLICY)
        insist(reloaded==upcoming,'POLICY_READBACK_FAILED')
        release.confirm_plan(plan,reloaded,expect_preimage=True)
    except BaseException:
        atomic(POLICY,old)
        for p in inserted:p.unlink(missing_ok=True)
        raise
    report.update(state='CATALOG_AND_EXACT_PLAN_ENROLLED_PENDING_SIGNED_BROKER',backup=str(snapshot),
                  policy_sha256=digest(POLICY.read_bytes()),plan_installed=True)
    return report

if __name__=='__main__':
    p=argparse.ArgumentParser();g=p.add_mutually_exclusive_group(required=True)
    g.add_argument('--check',action='store_true');g.add_argument('--install',action='store_true')
    args=p.parse_args()
    with open(LOCK,'a+') as fd:
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        print(json.dumps(run(args.install),sort_keys=True))

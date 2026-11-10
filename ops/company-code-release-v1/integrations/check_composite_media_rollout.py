#!/usr/bin/env python3
"""Read-only production rollout proof for the MAD4B composite media feature.

Reports original first-party registration and company binding gaps; creates
no grants, mutates no registry, plans, WordPress files or host policy.
"""
from __future__ import annotations
import argparse, hashlib, json, subprocess
from pathlib import Path

CATALOG = 'ops/company-code-release-v1/catalog/mad4b-release-components.json'
FILES = (
    'wp-content/mu-plugins/mad4b-composite-media-patch.php',
    'wp-content/mu-plugins/mad4b-composite-media-adapter.php',
    'wp-content/mu-plugins/mad4b-media-composite-integrity.php',
    'wp-content/mu-plugins/mad4b-unified-media-apply.php',
)

def sha(p: Path) -> str | None:
    return hashlib.sha256(p.read_bytes()).hexdigest() if p.is_file() else None

def read(source: Path, policy_file: Path, registry_root: Path) -> dict:
    import sys
    sys.path.insert(0,str(source/'ops/company-code-release-v1'))
    import company_code_release as release
    git_commit=subprocess.check_output(['git','-C',str(source),'rev-parse','HEAD'],text=True).strip()
    policy=json.loads(policy_file.read_text())
    target=policy.get('targets',{}).get('wayfatours:production',{})
    assert target.get('site_uuid') and target.get('root'), 'target not enrolled'
    manifest=json.loads(subprocess.check_output(['git','-C',str(source),'show',git_commit+':'+CATALOG]))
    components=[c for c in manifest['components'] if c.get('id')=='mad4b-composite-media']
    assert len(components)==1 and set(components[0]['files'])==set(FILES), 'catalog component mismatch'
    preview=json.loads(json.dumps(policy))
    # Keep the enrolled Git root: shared Git objects make new immutable commits
    # readable without invalidating older plans pinned to the same root.
    preview['targets']['wayfatours:production']['release_catalog']=CATALOG
    request={'contract':release.SPEC_CONTRACT,'company':'wayfatours','environment':'production',
             'site_uuid':target['site_uuid'],'git_commit':git_commit,
             'files':[{'source':name,'target':name,'expected_before_sha256':sha(Path(target['root'])/name)} for name in FILES],
             'acceptance_checks':['wp_boot']}
    plan=release.make_plan(request,preview)
    release.confirm_plan(plan,preview,expect_preimage=True)
    # Registry is content pinned: compare the installed first-party dependency
    # with the candidate; a source preview is never enough to authorize writing.
    import importlib.util
    spec=importlib.util.spec_from_file_location('registry_checker',registry_root/'registry.py')
    assert spec and spec.loader, 'original registry unavailable'
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    registration=module.Registry(registry_root).resolve('company.code.release')
    deps=registration.get('dependencies',[])
    pin=next((x['sha256'] for x in deps if x['path']=='company-code-release-v1/company_code_release.py'),None)
    installed=sha(registry_root/'company-code-release-v1/company_code_release.py')
    candidate=sha(source/'ops/company-code-release-v1/company_code_release.py')
    result={
        'contract':'mad4b.composite-media-rollout-readiness.v1',
        'candidate_commit':git_commit,
        'preview_plan_sha256':plan['plan_sha256'],
        'preview_plan_verified':True,
        'requested_files':len(FILES),
        'original_registry_operation_registered':True,
        'registry_release_executor_pinned':installed==pin,
        'registered_release_executor_matches_candidate':installed==candidate,
        'live_policy_catalog_enabled':target.get('release_catalog')==CATALOG,
        'live_policy_source_matches_candidate': all(
            release.digest(release.git_bytes(Path(policy['source_git_root']),git_commit,name))
            ==sha(source/name) for name in FILES),
        'deployment_done':all(sha(Path(target['root'])/name)==sha(source/name) for name in FILES),
        'no_changes_performed':True,
    }
    result['ready_for_signed_original313_release']=(
        result['registry_release_executor_pinned']
        and result['registered_release_executor_matches_candidate']
        and result['live_policy_catalog_enabled']
        and result['live_policy_source_matches_candidate']
    )
    result['required_next_actions']=[
        label for key,label in (
            ('registered_release_executor_matches_candidate','Enroll updated company-code-release executor through first-party dynamic registry'),
            ('live_policy_catalog_enabled','Enroll scoped release_catalog in protected company policy'),
            ('live_policy_source_matches_candidate','Register reviewed Git commit objects in the existing source root without replacing old plans'),
        ) if not result[key]
    ]
    return result

def main():
    p=argparse.ArgumentParser()
    p.add_argument('--source',required=True,type=Path)
    p.add_argument('--policy',default='/etc/mad4b/company-code-release-policy.json',type=Path)
    p.add_argument('--registry',default='/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled',type=Path)
    a=p.parse_args()
    print(json.dumps(read(a.source,a.policy,a.registry),indent=2))
if __name__=='__main__': main()

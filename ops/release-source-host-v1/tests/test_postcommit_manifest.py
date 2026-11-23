#!/usr/bin/env python3
"""Negative root-owned post-commit manifest admission fixtures. No site changes."""
import importlib.util, pathlib, tempfile, json, os, hashlib
P=pathlib.Path(__file__).resolve().parents[1]/'source_preflight.py'
spec=importlib.util.spec_from_file_location('admission_staging',P);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
count=0
def check(condition, name):
    global count
    assert condition,name
    count+=1;print('PASS',name)
def rejection(fn, code):
    try:fn()
    except m.Blocked as e:check(str(e)==code,code);return
    raise AssertionError('Fail-open: '+code)
with tempfile.TemporaryDirectory() as td:
    top=pathlib.Path(td);(top/'manifests').mkdir(mode=0o700)
    sha='a'*40;src={'type':'local_integration','immutable_git_sha':sha};cfg={'reports_dir':str(top)}
    data=b'<?php // test\n';files={'plugin/mad4b-site-control-plane.php':hashlib.sha256(data).hexdigest(),'plugin/tests/skip.php':'c'*64,'mu/00-mad4b-site-overlay.php':'d'*64}
    entry={'path':'mad4b-site-control-plane.php','bytes':len(data),'sha256':files['plugin/mad4b-site-control-plane.php']}
    digest=hashlib.sha256(f"{entry['path']}\0{entry['bytes']}\0{entry['sha256']}\n".encode()).hexdigest()
    proof=dict(contract='mad4b.local-git-candidate-provenance.v1',source_commit_sha=sha,package_files=[entry],package_manifest_digest=digest,
        package_file_count=1,certified_release=False,release_verdict_success=False,release_authorized=False,authorizing=False,requires_original_signed_post_build_receipt=True)
    path=top/'manifests'/(sha+'.json')
    def save(d):path.write_text(json.dumps(d));path.chmod(0o600)
    check(m.trusted_postcommit_candidate_manifest(cfg,src,files) is None,'missing signed source candidate stays missing')
    check(m.trusted_postcommit_candidate_manifest(cfg,{'type':'github_candidate','immutable_git_sha':sha},files) is None,'github candidate never inherits local root sidecar')
    save(proof);check(m.trusted_postcommit_candidate_manifest(cfg,src,files) is not None,'complete root-owned candidate manifest verified')
    for key,val,error in [('certified_release',True,'postcommit_manifest_nonauthorizing_contract_invalid'),
                          ('authorizing',True,'postcommit_manifest_nonauthorizing_contract_invalid'),
                          ('source_commit_sha','b'*40,'postcommit_manifest_nonauthorizing_contract_invalid'),
                          ('package_manifest_digest','c'*64,'postcommit_manifest_sha_or_content_mismatch'),
                          ('package_files',[],'postcommit_manifest_incomplete_shipping_inventory')]:
        bad=dict(proof);bad[key]=val;save(bad);rejection(lambda:m.trusted_postcommit_candidate_manifest(cfg,src,files),error)
    save(proof);path.chmod(0o644);rejection(lambda:m.trusted_postcommit_candidate_manifest(cfg,src,files),'postcommit_manifest_file_untrusted')
    path.unlink();elsewhere=top/'other.json';elsewhere.write_text(json.dumps(proof));elsewhere.chmod(0o600);path.symlink_to(elsewhere)
    rejection(lambda:m.trusted_postcommit_candidate_manifest(cfg,src,files),'postcommit_manifest_symlink_denied');path.unlink()
    check(m.trusted_postcommit_candidate_manifest(cfg,src,files) is None,'removed evidence cannot authorize')
print('POSTCOMMIT_SOURCE_CANDIDATE_PASS',count,'non-authorizing assertions')

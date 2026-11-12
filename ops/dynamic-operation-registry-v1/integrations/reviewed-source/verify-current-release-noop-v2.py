#!/usr/bin/env python3
"""Compute and validate a candidate *non-issuable* read-only release plan.
Reads Git HEAD and current company files with original make/confirm functions.
Never installs a plan, creates a grant or submits an Original 305 job.
"""
import importlib.util,json,hashlib,datetime as dt
from pathlib import Path
MODULE=Path('/opt/mad4b-company-transport/worktrees/company-code-release-20261108/ops/company-code-release-v1/company_code_release.py')
OLD=Path('/opt/mad4b-company-provisioning/reports/company-code-release-git-local-20261108/wayfa-plan-live-media-copy-v2.json')
OUT=Path('/opt/mad4b-company-provisioning/reports/original313-operation-parity-20261110/current-release-noop-readback.json')
spec=importlib.util.spec_from_file_location('original_release_plan_readonly',MODULE)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
policy=m.trusted_config(m.POLICY_PATH)
original=json.loads(OLD.read_text())
git_commit=m.run(['git','-C',original['git_root'],'rev-parse','HEAD']).decode().strip()
specification={'contract':m.SPEC_CONTRACT,'company':original['company'],'environment':original['environment'],
 'site_uuid':original['site_uuid'],'git_commit':git_commit,
 'acceptance_checks':original['acceptance_checks'],'files':[]}
target_root=Path(original['target_root'])
for row in original['files']:
    target=target_root/row['target']
    current=m.digest(target.read_bytes()) if target.is_file() else None
    specification['files'].append({'source':row['source'],'target':row['target'],
                                  'expected_before_sha256':current})
plan=m.make_plan(specification,policy)
m.confirm_plan(plan,policy,expect_preimage=True)
# expect_preimage=False is an assertion that the signed intended postimage
# is ALREADY installed, which is only valid when changed_count == 0.
# A newer Git source must be classified RELEASE_REVIEW_REQUIRED without
# mistaking an unapproved change for a no-op or an execution grant.
postimage_confirmed=plan['changed_count']==0
if postimage_confirmed:
    m.confirm_plan(plan,policy,expect_preimage=False)
result={'contract':'mad4b.original305.current-code-release-noop-preflight.v1',
 'observed_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
 'company':plan['company'],'environment':plan['environment'],
 'git_commit':git_commit,'plan_hash_sha256':plan['plan_sha256'],
 'source_equals_destination_count':sum(x['source_sha256']==x['expected_before_sha256'] for x in plan['files']),
 'target_count':len(plan['files']),'changed_count':plan['changed_count'],
 'original_plan_confirmed_as_safe':False,
 'candidate_preimage_confirmed':True,
 'candidate_postimage_confirmed':postimage_confirmed,
 'current_git_source_matches_company':postimage_confirmed,
 'release_required':plan['changed_count']>0,
 'candidate_registered_or_signed':False,'job_issued':False,
 'grant_issued':False,'production_modified':False,
 'decision':'ALREADY_RECONCILED_NO_RELEASE_NEEDED' if plan['changed_count']==0 else 'RELEASE_REVIEW_REQUIRED'}
OUT.write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,sort_keys=True))

#!/usr/bin/env python3
"""Audit source inclusion from local Git only, no merges or network.

Included requires real Git ancestor relation. Ignore ours merges,
patch hints, and uncommitted worktrees. This does NOT sign Original 318.
"""
from __future__ import annotations
import datetime as dt
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'docs/release-coverage/LOCAL_GIT_BRANCH_COVERAGE_CAS_20261112.json'

def git(*args, timeout=22, cwd=ROOT):
    return subprocess.run(['git','-C',str(cwd),*args],
        capture_output=True,text=True,timeout=timeout,check=False)

def capture():
    head=git('rev-parse','HEAD').stdout.strip()
    original='integration/full-wordpress-source-reconcile-20261111'
    names=git('for-each-ref','refs/heads','--format=%(refname:short)|%(objectname)').stdout.splitlines()
    local=git('branch','--show-current').stdout.strip()
    rows=[]
    for line in names:
        name,sha=line.split('|',1)
        if name==local:continue
        included=git('merge-base','--is-ancestor',sha,head).returncode==0
        rows.append({'branch':name,'commit':sha,
            'ancestor_of_integration':included,
            'resolution':'INCLUDED_GIT_ANCESTOR' if included else 'NOT_INTEGRATED'})
    missing=[r for r in rows if not r['ancestor_of_integration']]
    independent=set(git('merge-base','--independent',*[r['commit'] for r in missing],timeout=60).stdout.splitlines()) if missing else set()
    maximal=[]
    for row in missing:
        if row['commit'] not in independent:continue
        try:
            probe=git('merge-tree','--write-tree','HEAD',row['branch'],timeout=18)
            if probe.returncode==0:
                state='CLEAN_MERGE_CANDIDATE_NOT_TESTED'
            elif 'refusing to merge unrelated histories' in probe.stderr:
                state='UNRELATED_HISTORY'
            elif 'CONFLICT' in probe.stdout:
                state='CONFLICTS_REQUIRE_EXPLICIT_RESOLUTION'
            else:state='NOT_SAFE_TO_AUTOMERGE'
            count=sum(line.startswith('CONFLICT') for line in probe.stdout.splitlines())
        except subprocess.TimeoutExpired:
            state='MERGE_ANALYSIS_TIMED_OUT_HOLD';count=None
        maximal.append({'branch':row['branch'],'commit':row['commit'],
             'state':state,'git_merge_tree_conflict_count':count})
    raw=git('worktree','list','--porcelain').stdout
    paths=[x[9:] for x in raw.splitlines() if x.startswith('worktree ')]
    dirty=[]
    for name in paths:
        # The audit's own as-yet-uncommitted report is not an external
        # development branch. Verify target clean after committing this audit.
        if Path(name)==ROOT:
            continue
        try:r=git('status','--porcelain',timeout=10,cwd=Path(name))
        except subprocess.TimeoutExpired:
            dirty.append({'worktree':name,'state':'STATUS_TIMED_OUT_UNKNOWN'});continue
        if r.returncode:
            dirty.append({'worktree':name,'state':'STATUS_ERROR'});continue
        lines=r.stdout.splitlines()
        if lines:
            dirty.append({'worktree':name,
                'tracked_modified_count':sum(not l.startswith('??') for l in lines),
                'untracked_count':sum(l.startswith('??') for l in lines),
                'state':'WORKTREE_UNCOMMITTED_NOT_INCLUDED'})
    return {'contract':'mad4b.local-git-source-branch-coverage.v1',
      'audit_date_utc':dt.datetime.now(dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
      'source_kind':'LOCAL_GIT_ONLY_NO_REMOTE_FETCH',
      'source_head_before_audit_commit':head,
      'target_branch':local,
      'original_main_branch':original,
      'branch_refs_other_than_target':len(rows),
      'included_ancestor_branch_refs':sum(r['ancestor_of_integration'] for r in rows),
      'unmerged_branch_refs':len(missing),
      'independent_unmerged_heads':len(maximal),
      'clean_unmerged_heads':sum(x['state']=='CLEAN_MERGE_CANDIDATE_NOT_TESTED' for x in maximal),
      'unrelated_unmerged_heads':sum(x['state']=='UNRELATED_HISTORY' for x in maximal),
      'dirty_worktrees':len(dirty),
      'full_branch_coverage_verified':not missing and not dirty,
      'manual_conflict_resolutions_authorized':False,
      'production_original318_signed_source_approval':False,
      'production_original313_cas_certified':False,
      'local_git_only_source_review':True,
      'branches':rows,'maximal_unmerged_heads':maximal,
      'dirty_worktree_status':dirty}

if __name__=='__main__':
    data=capture()
    OUT.write_text(json.dumps(data,ensure_ascii=False,indent=2,sort_keys=True)+'\n')
    print(json.dumps({'state':'BRANCH_COVERAGE_COMPLETE' if data['full_branch_coverage_verified'] else 'PARTIAL_INTEGRATION_CONFLICTS_RECORDED',
      'target_head':data['source_head_before_audit_commit'],
      'included':data['included_ancestor_branch_refs'],
      'unmerged':data['unmerged_branch_refs'],
      'independent_head_conflicts':data['independent_unmerged_heads'],
      'unrelated_histories':data['unrelated_unmerged_heads'],
      'dirty_worktrees':data['dirty_worktrees'],
      'report':str(OUT)},ensure_ascii=False))

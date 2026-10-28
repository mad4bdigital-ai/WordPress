#!/usr/bin/env python3
"""QA31 hermetic positive and negative parity tests: never query tenant or release service."""
from pathlib import Path
from tempfile import TemporaryDirectory
import json,sys,hashlib
sys.path.insert(0,str(Path(__file__).resolve().parents[1] / 'tools'))
from qa31_release_source_gate import evaluate
def chk(ok,label):
    if not ok:raise AssertionError(label)
    print('PASS '+label)
def hashval(data):return hashlib.sha256(data).hexdigest()
def row(name,state,staging,github):
    return {'path':name,'state':state,'category':'active_source','staging_sha256':hashval(staging) if staging is not None else None,'github_sha256':hashval(github) if github is not None else None}
def fixture():
    return {'contract':'mad4b.qa31.source-parity-evidence.v1',
            'authorizing':False,'production_authorized':False,'live_production_evidence':False,
            'github_base_source_sha':'a'*40,'staging_build_source_sha':'b'*40,'staging_package_version':'0.4.0-rc.118',
            'source_decisions':[row('includes/new.php','STAGING_ONLY',b'new',None),
                row('includes/git.php','GITHUB_ONLY',None,b'git'),
                row('includes/diff.php','DIVERGENT',b'stage',b'gitold')]}
with TemporaryDirectory() as directory:
    p=Path(directory);(p/'includes').mkdir()
    (p/'mad4b-site-control-plane.php').write_text('<?php\n * Version: 0.4.0-rc.104\n')
    (p/'includes/git.php').write_bytes(b'git');(p/'includes/diff.php').write_bytes(b'gitold')
    e=fixture()
    status=evaluate(e,p)
    chk(status['decision']=='BLOCKED_SOURCE_RECONCILIATION','unresolved_differences_fail_closed')
    chk(status['pending_count']==3 and status['unexpected_count']==0,'all_three_classes_checked')
    (p/'includes/new.php').write_bytes(b'new')
    (p/'includes/diff.php').write_bytes(b'stage')
    status=evaluate(e,p)
    chk(status['merged_matching_staging_hash']==2 and status['pending_count']==1,'matching_imports_advance_but_not_certify')
    (p/'includes/git.php').write_bytes(b'altered')
    chk(evaluate(e,p)['unexpected_count']==1,'unexpected_git_change_is_blocked')
    (p/'includes/git.php').write_bytes(b'git')
    (p/'mad4b-site-control-plane.php').write_text('<?php\n * Version: 0.4.0-rc.118\n')
    chk(evaluate(e,p)['decision']=='BLOCKED_SOURCE_RECONCILIATION','github_only_requires_staging_compatibility')
    e['source_decisions'][0]['path']='../outside.php'
    try:evaluate(e,p)
    except ValueError:print('PASS path_traversal_rejected')
    else:raise AssertionError('unsafe path')
    e=fixture();e['production_authorized']=True
    try:evaluate(e,p)
    except ValueError:print('PASS unauthorized_evidence_rejected')
    else:raise AssertionError('unauthorized snapshot')
    e=fixture();e['source_decisions'][1]=e['source_decisions'][0]
    try:evaluate(e,p)
    except ValueError:print('PASS duplicate_source_records_rejected')
    else:raise AssertionError('duplicate source')
print('QA31_SOURCE_PARITY_NEGATIVE_REGRESSIONS: PASS')

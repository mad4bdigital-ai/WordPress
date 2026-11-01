#!/usr/bin/env python3
"""QA31 hermetic positive and negative parity tests: never query tenant or release service."""
from pathlib import Path
from tempfile import TemporaryDirectory
import json,sys,hashlib
sys.path.insert(0,str(Path(__file__).resolve().parents[1] / 'tools'))
from qa31_release_source_gate import evaluate,reviewed_bootstrap_successor,reviewed_ci_only_source_successor
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
# Current Git HEAD bootstrap may evolve under separate exact CI-only source
# review without rewriting the *historical* staging/GitHub SHA256 evidence.
# It remains PENDING, never a parity/installation/release approval.
repo=Path(__file__).resolve().parents[1]
plugin=repo/'wp-content/plugins/mad4b-site-control-plane'
ledger=json.loads((repo/'governance/release-parity/qa31-commerce-staging-source-parity.json').read_text(encoding='utf-8'))
current=(plugin/'mad4b-site-control-plane.php').read_bytes()
chk(reviewed_bootstrap_successor(plugin,'mad4b-site-control-plane.php',current),
    'exact_git_reviewed_bootstrap_accepted_ci_only')
reviewed_paths={
    'mad4b-site-control-plane.php',
    'config/cross-plugin-sources.json',
    'includes/class-mad4b-scp-server-write-catalog.php',
}
for path in sorted(reviewed_paths - {'mad4b-site-control-plane.php'}):
    content=(plugin/path).read_bytes()
    chk(reviewed_ci_only_source_successor(plugin,path,content),
        'reviewed_exact_ci_successor_'+path.rsplit('/',1)[-1])
    chk(not reviewed_ci_only_source_successor(plugin,path,content+b'\nmodified'),
        'tampered_ci_successor_denied_'+path.rsplit('/',1)[-1])
chk(not reviewed_ci_only_source_successor(plugin,'includes/unapproved.php',current),
    'unlisted_ci_successor_denied')
with TemporaryDirectory() as foreign:
    chk(not reviewed_ci_only_source_successor(Path(foreign),
        'config/cross-plugin-sources.json',
        (plugin/'config/cross-plugin-sources.json').read_bytes()),
        'foreign_ci_successor_root_denied')
checked=evaluate(ledger,plugin)
# Current source has additional unreviewed divergent paths; accepting a
# reviewed exact Git successor must never erase those blockers or imply parity.
chk(set(checked['reviewed_github_successor_paths'])==reviewed_paths
    and checked['unexpected_count']>0
    and 'includes/class-mad4b-scp-brand-context-builder.php' in checked['unexpected_paths']
    and checked['pending_count']>0
    and checked['decision']=='BLOCKED_SOURCE_RECONCILIATION',
    'reviewed_successor_does_not_erase_unreviewed_live_divergence')
chk(not reviewed_bootstrap_successor(plugin,'mad4b-site-control-plane.php',current+b'\nmodified'),
    'tampered_bootstrap_bytes_denied')
chk(not reviewed_bootstrap_successor(plugin,'includes/unapproved.php',current),
    'unlisted_successor_scope_denied')
with TemporaryDirectory() as foreign:
    chk(not reviewed_bootstrap_successor(Path(foreign),'mad4b-site-control-plane.php',current),
        'foreign_checkout_cannot_self_certify')

print('QA31_SOURCE_PARITY_NEGATIVE_REGRESSIONS: PASS')

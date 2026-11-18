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
repo=Path(__file__).resolve().parents[1]
plugin=repo/'wp-content/plugins/mad4b-site-control-plane'
ledger=json.loads((repo/'governance/release-parity/qa31-commerce-staging-source-parity.json').read_text())
# The original QA31 ledger is immutable. A *separate*, finite source-commit
# successor ledger pins rc129 Git blobs to SHA-specific, CI-only reviews.
# These are pending Source Parity entries; never Build Provenance or release.
import runpy
from unittest import mock
runpy.run_path(str(repo / 'tests/qa31_rc129_bootstrap_source_contract.py'),
               run_name='__main__')
review_path=repo/'governance/release-parity/qa31-rc129-reviewed-successors-20261118.json'
successors=json.loads(review_path.read_text())
chk(successors['contract']=='mad4b.qa31-rc129-reviewed-source-successors.v1'
    and successors['source_commit']=='ee6c57e870db6b4aada4de64b0471a953c8bdf0a'
    and successors['authorizing'] is False
    and successors['production_authorized'] is False
    and successors['is_build_provenance'] is False
    and successors['release_gate']=='BLOCKED_SOURCE_RECONCILIATION',
    'successor_ledger_never_authorizes_release')
entries=successors['reviewed_entries']
reviewed_paths={row['path'] for row in entries}
chk(len(entries)==65 and len(reviewed_paths)==65,
    'exact_finite_source_review_scope_65')
chk(sorted(reviewed_paths)==[x['path'] for x in entries],
    'ordered_unique_successor_paths')
current=(plugin/'mad4b-site-control-plane.php').read_bytes()
chk(reviewed_bootstrap_successor(plugin,'mad4b-site-control-plane.php',current),
    'original305_bootstrap_reviewed_exactly')
for item in entries:
    rel=item['path']
    content=(plugin/rel).read_bytes()
    chk(reviewed_ci_only_source_successor(plugin,rel,content),
        'exact_ci_reviewed_successor_'+rel)
    chk(not reviewed_ci_only_source_successor(plugin,rel,content+b'\nTAMPERED'),
        'tampered_ci_successor_denied_'+rel)
chk(not reviewed_ci_only_source_successor(plugin,'includes/unapproved.php',current),
    'unlisted_ci_successor_denied')
with TemporaryDirectory() as foreign:
    chk(not reviewed_ci_only_source_successor(Path(foreign),
        'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php',
        (plugin/'bootstrap/mad4b-mcp-adapter-mu-bootstrap.php').read_bytes()),
        'foreign_checkout_cannot_certify_bootstrap')

# Even a locally modified review ledger is not a new permission. Its exact
# frozen source SHA, historical ledger digest and non-authorizing flags hold.
original=Path.read_text
target=review_path.resolve()
for name,change in (
    ('unauthorized_release',{'production_authorized':True}),
    ('unknown_source_commit',{'source_commit':'a'*40}),
    ('forged_historical_ledger',{'frozen_legacy_ledger_sha256':'b'*64}),
    ('wrong_reviewed_count',{'total_reviewed_successors':1}),
    ('unsafe_release_gate',{'release_gate':'SOURCE_PARITY_CANDIDATE_UNCERTIFIED'}),
):
    forged=dict(successors)
    forged.update(change)
    def replaced(self,*args,**kwargs):
        if self.resolve()==target:
            return json.dumps(forged)
        return original(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',replaced):
        chk(not reviewed_ci_only_source_successor(plugin,
            'bootstrap/mad4b-runtime-cache-convergence-mu.php',
            (plugin/'bootstrap/mad4b-runtime-cache-convergence-mu.php').read_bytes()),
            'forged_review_ledger_denied_'+name)

# Bootstrap reviews are isolated from general plugin runtime review coverage.
# No bootstrap execution permission, control weakening or arbitrary scope can
# be smuggled into the versioned QA31 source manifest.
for case in ('unauthorized_bootstrap','missing_controls','wrong_review_path'):
    forged=json.loads(json.dumps(successors))
    row=next(x for x in forged['reviewed_entries']
             if x['path']=='bootstrap/mad4b-mcp-adapter-mu-bootstrap.php')
    review=row['independent_bootstrap_review']
    if case=='unauthorized_bootstrap':review['production_authorized']=True
    elif case=='missing_controls':review['control_ids']=[]
    else:row['required_regression']='tests/qa31_release_source_gate_contract.py'
    def replaced(self,*args,**kwargs):
        if self.resolve()==target:return json.dumps(forged)
        return original(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',replaced):
        chk(not reviewed_ci_only_source_successor(plugin,row['path'],
            (plugin/row['path']).read_bytes()),
            'bootstrap_scope_forgery_denied_'+case)

# GitHub Actions default checkout is shallow. The predecessor-blob check
# must fail closed in a full repository with a missing predecessor, while
# allowing a true shallow clone only with full current-HEAD/digest proofs.
import qa31_release_source_gate as qa31
from types import SimpleNamespace
real_run=qa31.subprocess.run
real_output=qa31.subprocess.check_output
def missing_parent(cmd,*args,**kwargs):
    if cmd[:3]==['git','cat-file','-e']:
        return SimpleNamespace(returncode=1)
    return real_run(cmd,*args,**kwargs)
sample='bootstrap/mad4b-runtime-cache-convergence-mu.php'
sample_data=(plugin/sample).read_bytes()
with mock.patch.object(qa31.subprocess,'run',side_effect=missing_parent):
    def full_depth_without_parent(cmd,*args,**kwargs):
        if cmd==['git','rev-parse','--is-shallow-repository']:return b'false\n'
        return real_output(cmd,*args,**kwargs)
    with mock.patch.object(qa31.subprocess,'check_output',side_effect=full_depth_without_parent):
        chk(not reviewed_ci_only_source_successor(plugin,sample,sample_data),
            'nonshallow_missing_reference_is_denied')
    def shallow_object(cmd,*args,**kwargs):
        if cmd==['git','rev-parse','--is-shallow-repository']:return b'true\n'
        return real_output(cmd,*args,**kwargs)
    with mock.patch.object(qa31.subprocess,'check_output',side_effect=shallow_object):
        chk(reviewed_ci_only_source_successor(plugin,sample,sample_data),
            'genuine_shallow_checkout_with_exact_digest_is_supported')
        chk(not reviewed_ci_only_source_successor(plugin,sample,sample_data+b'\ntampered'),
            'genuine_shallow_checkout_still_rejects_tampering')

# Changing the reviewed SHA for a particular file cannot be hidden behind
# another valid file, nor can a regression name be substituted.
for case in ('modified_sha','changed_test','changed_historical_state'):
    forged=json.loads(json.dumps(successors))
    item=next(x for x in forged['reviewed_entries']
              if x['path']=='bootstrap/mad4b-runtime-cache-convergence-mu.php')
    if case=='modified_sha':item['current_git_blob_sha']='f'*40
    elif case=='changed_test':item['required_regression']='tests/not-review-authorized.py'
    else:item['state']='GITHUB_ONLY'
    def replaced(self,*args,**kwargs):
        if self.resolve()==target:return json.dumps(forged)
        return original(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',replaced):
        chk(not reviewed_ci_only_source_successor(plugin,item['path'],
            (plugin/item['path']).read_bytes()),
            'forged_source_entry_denied_'+case)

checked=evaluate(ledger,plugin)
chk(set(checked['reviewed_github_successor_paths'])==reviewed_paths
    and checked['reviewed_github_successor_count']==65
    and checked['unexpected_count']==0
    and checked['pending_count']==68
    and checked['version_match'] is False
    and checked['decision']=='BLOCKED_SOURCE_RECONCILIATION'
    and checked['authorizing'] is False and checked['production_authorized'] is False,
    'zero_unexpected_but_historical_parity_still_blocked')
chk(not reviewed_bootstrap_successor(plugin,'mad4b-site-control-plane.php',
    current+b'\nmodified'), 'tampered_main_bootstrap_denied')
# New Host Agent code changes a second, bounded overlay. It does not
# repin or approve either the 65-source rc129 ledger or QA31 rc104 evidence.
host_manifest=repo/'governance/release-parity/qa31-rc129-host-agent-integration-successors-20261118.json'
host_data=json.loads(host_manifest.read_text())
chk(host_data['contract']=='mad4b.qa31-host-integration-exact-successors.v1'
    and host_data['base_source_commit']=='27883e019d7b9ddc744f6be33608b78c54ec411b'
    and len(host_data['source_entries'])==6
    and host_data['historical_qa31_overrides']==3
    and host_data['authorizing'] is False
    and host_data['production_authorized'] is False
    and host_data['source_promotion_approved'] is False
    and host_data['release_gate']=='BLOCKED_SOURCE_RECONCILIATION',
    'host_source_overlay_ci_only_and_exact_six_paths')
known=[e['path'] for e in host_data['source_entries']]
for rel in known:
    candidate=(plugin/rel).read_bytes()
    chk(reviewed_ci_only_source_successor(plugin,rel,candidate),
        'host_exact_blob_ci_reviewed_'+rel)
    chk(not reviewed_ci_only_source_successor(plugin,rel,candidate+b'\nforged'),
        'host_tampered_blob_denied_'+rel)
chk(set(known).issuperset({
    'mad4b-site-control-plane.php','includes/class-mad4b-scp-context-authority.php',
    'includes/class-mad4b-scp-content-jobs.php'}),
    'host_overlay_covers_all_qa31_historical_source_changes')

from qa31_rc129_host_bootstrap_overlay import strip_rc129_signed_host_overlay
signed_root=(plugin/'mad4b-site-control-plane.php').read_bytes()
restored=strip_rc129_signed_host_overlay(signed_root)
host_proof=json.loads((repo/'docs/release-coverage/pr814-rc129-host-agent-bootstrap-additive-20261118.json').read_text())
chk((len(restored),hashlib.sha256(restored).hexdigest())==(
    host_proof['base_main_bytes'],host_proof['base_main_sha256']),
    'host_overlay_reconstructs_exact_original305_parent')
try:strip_rc129_signed_host_overlay(signed_root.replace(b'signed Host WP-CLI transport',
                                                     b'unsigned Host transport'))
except AssertionError:print('PASS forged_host_bootstrap_rejected')
else:raise AssertionError('Modified Host bootstrap accepted')

original_read=Path.read_text
target_host=host_manifest.resolve()
for case,updates in (
    ('production_authority',{'production_authorized':True}),
    ('release_approved',{'release_gate':'SOURCE_PARITY_CANDIDATE_UNCERTIFIED'}),
    ('foreign_base',{'base_source_commit':'c'*40}),
    ('wrong_legacy_evidence',{'original_qa31_sha256':'d'*64}),
    ('wrong_count',{'exact_source_files':7}),
):
    forged=json.loads(json.dumps(host_data));forged.update(updates)
    def substituted(self,*args,**kwargs):
        if self.resolve()==target_host:return json.dumps(forged)
        return original_read(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',substituted):
        chk(not reviewed_ci_only_source_successor(plugin,
            'includes/class-mad4b-scp-context-authority.php',
            (plugin/'includes/class-mad4b-scp-context-authority.php').read_bytes()),
            'host_forged_manifest_denied_'+case)
for case in ('changed_current_blob','changed_required_regression',
             'changed_source_scope','changed_parent_blob','changed_parent_sha256'):
    forged=json.loads(json.dumps(host_data))
    row=next(e for e in forged['source_entries']
             if e['path']=='includes/class-mad4b-scp-context-authority.php')
    if case=='changed_current_blob':row['current_blob_sha']='f'*40
    elif case=='changed_required_regression':row['required_regression']='tests/qa31_rc129_bootstrap_source_contract.py'
    elif case=='changed_source_scope':row['source_record_scope']='new_or_separate_review'
    elif case=='changed_parent_blob':row['parent_blob_sha']='a'*40
    else:row['parent_sha256']='b'*64
    def substituted(self,*args,**kwargs):
        if self.resolve()==target_host:return json.dumps(forged)
        return original_read(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',substituted):
        chk(not reviewed_ci_only_source_successor(plugin,row['path'],
            (plugin/row['path']).read_bytes()),'host_forged_review_denied_'+case)

print('QA31_SOURCE_PARITY_NEGATIVE_REGRESSIONS: PASS (source reviewed; release blocked)')

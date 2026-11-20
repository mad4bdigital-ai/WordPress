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
chk(not reviewed_bootstrap_successor(plugin,'mad4b-site-control-plane.php',current+b'\nFORGED'),
    'pr942_bootstrap_extra_bytes_denied')
# Immutable historical evidence is checked by SHA, then the separate one-line
# successor review is checked by exact blob and no-production permissions.
source_review=repo/'docs/source-reconciliation/pr942-local-ollama-no-autoboot-20261119.json'
source_review_data=json.loads(source_review.read_text())
source_review_target=source_review.resolve()
for field,mutation in (
    ('production_authorized',True),
    ('owner_attestation_provided',True),
    ('prior',{'bytes':1,'sha256':'a'*64,'blob_sha':'b'*40}),
    ('reviewed_successor',{'bytes':1,'sha256':'c'*64,'blob_sha':'d'*40}),
    ('main_path','wp-content/plugins/mad4b-site-control-plane/includes/fake.php'),
):
    forged=dict(source_review_data);forged[field]=mutation
    original_read=Path.read_text
    def forged_source_review(self,*args,**kwargs):
        if self.resolve()==source_review_target:
            return json.dumps(forged)
        return original_read(self,*args,**kwargs)
    from unittest import mock as _source_mock
    with _source_mock.patch.object(Path,'read_text',forged_source_review):
        chk(not reviewed_bootstrap_successor(plugin,'mad4b-site-control-plane.php',current),
            'pr942_bootstrap_review_forgery_denied_'+field)
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
print('QA31_EXPECTED_REVIEW_COUNTS:',checked['reviewed_github_successor_count'],checked['pending_count'],checked['unexpected_count'])
# Preserve 65 historical successor reviews unchanged, plus the two formerly
# non-successor ChatbotX paths now reviewed by a separate exact manifest.
expected_reviewed_paths=reviewed_paths | {
    'includes/adapters/class-mad4b-scp-chatbotx-governed-writes.php',
    'includes/adapters/class-mad4b-scp-chatbotx-write-preflight.php',
    'includes/class-mad4b-scp-company-master-admin.php',
    'includes/class-mad4b-scp-oauth-resource-bridge.php',  # New signed scoped CIMD; previously outside this cohort.
}
chk(set(checked['reviewed_github_successor_paths'])==expected_reviewed_paths
    and checked['reviewed_github_successor_count']==len(expected_reviewed_paths)
    and checked['unexpected_count']==0
    # Two originally stage-identical ChatbotX files now have separately
    # reviewed, source-pinned successors; they remain pending as live parity.
    and checked['pending_count']==72
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
from cwv_site_admission_exact_overlay import strip_exact_site_admission
signed_root=strip_exact_site_admission((plugin/'mad4b-site-control-plane.php').read_bytes())
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

# The visual-media successor is a third, source-only layer. It can supersede
# exactly two older QA31 file identities without rewriting a historical review.
media_manifest=repo/'governance/release-parity/qa31-rc129-context-media-successors-20261118.json'
media_data=json.loads(media_manifest.read_text())
chk(media_data['contract']=='mad4b.qa31-rc129-context-media-successors.v1'
    and media_data['base_source_commit']=='1fe48421cbc1444bc6698f57bf589784ec4a982f'
    and media_data['exact_source_files']==3
    and media_data['legacy_qa31_overrides']==2
    and media_data['source_review_only'] is True
    and media_data['authorizing'] is False
    and media_data['production_authorized'] is False
    and media_data['source_promotion_approved'] is False
    and media_data['release_gate']=='BLOCKED_SOURCE_RECONCILIATION',
    'visual_media_source_only_review')
media_names={e['path'] for e in media_data['entries']}
chk(media_names=={
    'includes/class-mad4b-scp-brand-context-builder.php',
    'includes/class-mad4b-scp-drive-scan-continuation.php',
    'includes/class-mad4b-scp-google-drive-context.php'},'visual_media_exact_three_sources')
for name in media_names:
    source_bytes=(plugin/name).read_bytes()
    chk(reviewed_ci_only_source_successor(plugin,name,source_bytes),
        'visual_media_source_sha_accepted_'+name)
    chk(not reviewed_ci_only_source_successor(plugin,name,source_bytes+b'\nsvg-tainted'),
        'visual_media_source_modified_denied_'+name)
target_media=media_manifest.resolve()
for case,change in (
    ('forge_release',{'production_authorized':True}),
    ('approve_source',{'source_promotion_approved':True}),
    ('substitute_base',{'base_source_commit':'e'*40}),
    ('change_legacy',{'historical_qa31_sha256':'f'*64}),
    ('change_count',{'exact_source_files':4}),
    ('replace_review',{'host_integration_sha256':'d'*64}),
):
    forged=dict(media_data);forged.update(change)
    def corrupt(self,*args,**kwargs):
        if self.resolve()==target_media:return json.dumps(forged)
        return original_read(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',corrupt):
        chk(not reviewed_ci_only_source_successor(plugin,
            'includes/class-mad4b-scp-google-drive-context.php',
            (plugin/'includes/class-mad4b-scp-google-drive-context.php').read_bytes()),
            'visual_media_manifest_denied_'+case)
for case in ('forge_new_sha','forge_old_blob','forge_old_sha256','swap_test','forge_legacy_state'):
    forged=json.loads(json.dumps(media_data))
    item=next(i for i in forged['entries']
              if i['path']=='includes/class-mad4b-scp-google-drive-context.php')
    if case=='forge_new_sha':item['current_blob_sha']='e'*40
    elif case=='forge_old_blob':item['parent_blob_sha']='a'*40
    elif case=='forge_old_sha256':item['parent_sha256']='b'*64
    elif case=='swap_test':item['required_regression']='tests/qa31_release_source_gate_contract.py'
    else:item['legacy_qa31_state']='GITHUB_ONLY'
    def corrupt(self,*args,**kwargs):
        if self.resolve()==target_media:return json.dumps(forged)
        return original_read(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',corrupt):
        chk(not reviewed_ci_only_source_successor(plugin,item['path'],
            (plugin/item['path']).read_bytes()),
            'visual_media_source_entry_denied_'+case)


# Exact ChatGPT per-connector CIMD parity is independently pinned after
# Context Media; its source review is CI-only and never a permission grant.
cimd_manifest=repo/'governance/release-parity/qa31-rc129-cimd-source-successor-20261118.json'
cimd_data=json.loads(cimd_manifest.read_text())
cimd_rel='includes/class-mad4b-scp-local-oauth-server.php'
cimd_bytes=(plugin/cimd_rel).read_bytes()
chk(cimd_data['contract']=='mad4b.qa31-rc129-cimd-source-successor.v1'
    and cimd_data['base_source_commit']=='6ee165ef798c1e37bdc0e09513d57e4dfcb224af'
    and cimd_data['authorizing'] is False
    and cimd_data['production_authorized'] is False
    and cimd_data['source_promotion_approved'] is False
    and cimd_data['release_gate']=='BLOCKED_SOURCE_RECONCILIATION'
    and cimd_data['exact_source_files']==1,'cimd_review_is_ci_only')
chk(reviewed_ci_only_source_successor(plugin,cimd_rel,cimd_bytes),
    'exact_cimd_source_is_reviewed')
chk(not reviewed_ci_only_source_successor(plugin,cimd_rel,cimd_bytes+b'\nFAKE'),
    'tampered_cimd_source_is_denied')
target_cimd=cimd_manifest.resolve()
for case,change in (
    ('release_flag',{'production_authorized':True}),
    ('wrong_parent',{'base_source_commit':'a'*40}),
    ('wrong_media',{'context_media_manifest_sha256':'b'*64}),
    ('wrong_review',{'source_reviewed_blob_sha':'c'*40}),
):
    forged=dict(cimd_data);forged.update(change)
    def substitute(self,*args,**kwargs):
        if self.resolve()==target_cimd:return json.dumps(forged)
        return original_read(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',substitute):
        chk(not reviewed_ci_only_source_successor(plugin,cimd_rel,cimd_bytes),
            'cimd_manifest_forgery_denied_'+case)
for case,change in (
    ('current_blob',{'current_blob_sha':'e'*40}),
    ('parent_blob',{'parent_blob_sha':'d'*40}),
    ('substitute_test',{'required_regression':'tests/qa31_release_source_gate_contract.py'}),
):
    forged=json.loads(json.dumps(cimd_data));forged['entry'].update(change)
    def substitute(self,*args,**kwargs):
        if self.resolve()==target_cimd:return json.dumps(forged)
        return original_read(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',substitute):
        chk(not reviewed_ci_only_source_successor(plugin,cimd_rel,cimd_bytes),
            'cimd_entry_forgery_denied_'+case)


# Site-neutral CPT drafts are conditional per enrolled company, not three
# universal tourism types. Require exact source fingerprints and old tests.
chatbotx_manifest=repo/'governance/release-parity/qa31-rc129-chatbotx-cpt-successor-20261118.json'
chatbotx_data=json.loads(chatbotx_manifest.read_text())
chatbotx_paths=[x['path'] for x in chatbotx_data['entries']]
chk(chatbotx_data['contract']=='mad4b.qa31-rc129-chatbotx-site-neutral-cpt-successor.v1'
    and chatbotx_data['base_source_commit']=='924b2051144df7af2cf409e7fc617d96e23d95d8'
    and chatbotx_data['exact_source_files']==3
    and chatbotx_data['production_authorized'] is False
    and chatbotx_data['authorizing'] is False
    and chatbotx_data['release_gate']=='BLOCKED_SOURCE_RECONCILIATION'
    and chatbotx_paths==sorted(chatbotx_paths),'chatbotx_exact_review_non_authorizing')
for rel in chatbotx_paths:
    fresh=(plugin/rel).read_bytes()
    chk(reviewed_ci_only_source_successor(plugin,rel,fresh),
        'chatbotx_exact_cpt_source_review_'+rel)
    chk(not reviewed_ci_only_source_successor(plugin,rel,fresh+b'\nTAMPERED'),
        'chatbotx_cpt_tamper_denied_'+rel)
target_chatbotx=chatbotx_manifest.resolve()
for case,changed in (
    ('forged_authorization',{'production_authorized':True}),
    ('wrong_parent',{'base_source_commit':'a'*40}),
    ('wrong_review',{'source_review_reference':'https://example.invalid/fake'}),
    ('wrong_cimd_lineage',{'prior_cimd_sha256':'b'*64}),
):
    forged=dict(chatbotx_data);forged.update(changed)
    def substituted(self,*args,**kwargs):
        if self.resolve()==target_chatbotx:return json.dumps(forged)
        return original_read(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',substituted):
        rel=chatbotx_paths[0]
        chk(not reviewed_ci_only_source_successor(plugin,rel,(plugin/rel).read_bytes()),
            'chatbotx_forger_denied_'+case)
for case,field in (('source','current_blob_sha'),('parent','parent_blob_sha'),
                   ('digest','current_sha256')):
    forged=json.loads(json.dumps(chatbotx_data))
    forged['entries'][0][field]='f'*(64 if field.endswith('sha256') else 40)
    def substituted(self,*args,**kwargs):
        if self.resolve()==target_chatbotx:return json.dumps(forged)
        return original_read(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',substituted):
        rel=chatbotx_paths[0]
        chk(not reviewed_ci_only_source_successor(plugin,rel,(plugin/rel).read_bytes()),
            'chatbotx_forged_entry_denied_'+case)

# PR942 source reviews are independent extensions of frozen QA31. A forged
# release flag, SHA or parent source cannot pass, even if the current file is
# valid and prior QA31 source evidence remains unchanged.
for review_rel, rel, mutations in (
    ('docs/source-reconciliation/pr942-qa31-semantic-source-successors-20261119.json',
     'includes/class-mad4b-scp-content-readiness-alerts.php',
     (('production_authorized',True),('historic_source_manifest_sha256','0'*64))),
    ('docs/source-reconciliation/pr942-qa31-company-master-security-successor-20261119.json',
     'includes/class-mad4b-scp-company-master-admin.php',
     (('host_grant_issued',True),('historical_staging_sha256','0'*64))),
):
    document=repo/review_rel
    stable=json.loads(document.read_text())
    contents=(plugin/rel).read_bytes()
    chk(reviewed_ci_only_source_successor(plugin,rel,contents),
        'pr942_exact_reviewed_source_'+rel)
    for field,wrong in mutations:
        fake=json.loads(json.dumps(stable))
        fake[field]=wrong
        expected=document.resolve()
        def forged_pr942(self,*args,**kwargs):
            if self.resolve()==expected:return json.dumps(fake)
            return original_read(self,*args,**kwargs)
        with mock.patch.object(Path,'read_text',forged_pr942):
            chk(not reviewed_ci_only_source_successor(plugin,rel,contents),
                'pr942_source_review_forgery_denied_'+field)
    chk(not reviewed_ci_only_source_successor(plugin,rel,contents+b'\nFORGED'),
        'pr942_source_mutation_denied_'+rel)

# The scoped-CIMD restoration has an independent exact source chain.
# An injected URL, absent signed fingerprint, edited former source or forged
# Production approval cannot be marked as an Original305 release.
cimd_review_file=repo/'docs/source-reconciliation/pr942-cimd-scoped-source-successors-20261119.json'
cimd_review=json.loads(cimd_review_file.read_text())
cimd_target=cimd_review_file.resolve()
chk(cimd_review['contract']=='mad4b.pr942.cimd-scoped-source-successors.v1'
    and cimd_review['production_authorized'] is False
    and cimd_review['release_gate']=='BLOCKED_SOURCE_RECONCILIATION',
    'pr942_scoped_cimd_review_ci_only')
for item in cimd_review['entries']:
    source=(plugin/item['path']).read_bytes()
    chk(reviewed_ci_only_source_successor(plugin,item['path'],source),
        'pr942_scoped_cimd_exact_'+item['path'])
    chk(not reviewed_ci_only_source_successor(plugin,item['path'],source+b'\\nFORGED'),
        'pr942_scoped_cimd_modified_'+item['path'])
for field,value in (('production_authorized',True),
                    ('source_promotion_approved',True),
                    ('historical_qa31_sha256','f'*64)):
    fake=dict(cimd_review);fake[field]=value
    def forged_scoped(self,*args,**kwargs):
        if self.resolve()==cimd_target:return json.dumps(fake)
        return original_read(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',forged_scoped):
        rel=cimd_review['entries'][0]['path']
        chk(not reviewed_ci_only_source_successor(plugin,rel,(plugin/rel).read_bytes()),
            'pr942_scoped_cimd_manifest_forgery_denied_'+field)

# This standalone follow-up admits only a *read-only* Context reconciliation
# plan. It must never inherit Original305 or Host write authority from a source
# hash review. The parent’s QA31 record remains immutable.
context_review_file=repo/'docs/source-reconciliation/pr942-context-classification-readonly-preview-20261119.json'
context_review=json.loads(context_review_file.read_text(encoding='utf-8'))
context_rel='includes/class-mad4b-scp-context-authority.php'
context_content=(plugin/context_rel).read_bytes()
chk(reviewed_ci_only_source_successor(plugin,context_rel,context_content),
    'context_preview_exact_reviewed_source')
chk(not reviewed_ci_only_source_successor(plugin,context_rel,context_content+b'\nFORGED'),
    'context_preview_modified_source_denied')
chk(context_review['allows_application'] is False
    and context_review['runtime_activated'] is False
    and context_review['production_authorized'] is False,
    'context_preview_never_approves_apply_or_release')
target_context_review=context_review_file.resolve()
for field,value in (
    ('production_authorized',True),
    ('allows_application',True),
    ('write_grant_issued',True),
    ('parent_source_blob_sha','0'*40),
    ('reviewed_source_sha256','0'*64),
):
    forged=dict(context_review);forged[field]=value
    def forged_context(self,*args,**kwargs):
        if self.resolve()==target_context_review:return json.dumps(forged)
        return original_read(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',forged_context):
        chk(not reviewed_ci_only_source_successor(plugin,context_rel,context_content),
            'context_preview_forged_review_denied_'+field)

# Independently reviewed company-native-notification GET preview. Never infer
# Fluent Forms SettingsService writes or Production mail permission from it.
email_review_file=repo/'docs/source-reconciliation/pr961-company-email-native-notice-readonly-20261119.json'
email_review=json.loads(email_review_file.read_text(encoding='utf-8'))
email_rel='includes/class-mad4b-scp-company-email-routing.php'
email_source=(plugin/email_rel).read_bytes()
chk(reviewed_ci_only_source_successor(plugin,email_rel,email_source),
    'company_email_native_readonly_exact_review')
chk(not reviewed_ci_only_source_successor(plugin,email_rel,email_source+b'\nFORGED'),
    'company_email_native_readonly_source_tamper_denied')
chk(email_review['no_native_feed_write'] is True
    and email_review['no_mail_send'] is True
    and email_review['production_authorized'] is False,
    'company_email_native_preview_non_authorizing')
email_doc_target=email_review_file.resolve()
for field,wrong in (
    ('production_authorized',True),
    ('no_mail_send',False),
    ('no_native_feed_write',False),
    ('previous_blob_sha','0'*40),
    ('current_sha256','0'*64),
    ('historical_manifest_sha256','f'*64),
):
    fake=dict(email_review);fake[field]=wrong
    def forged_mail_doc(self,*args,**kwargs):
        if self.resolve()==email_doc_target:return json.dumps(fake)
        return original_read(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',forged_mail_doc):
        chk(not reviewed_ci_only_source_successor(plugin,email_rel,email_source),
            'company_native_email_review_forgery_denied_'+field)

print('QA31_SOURCE_PARITY_NEGATIVE_REGRESSIONS: PASS (source reviewed; release blocked)')

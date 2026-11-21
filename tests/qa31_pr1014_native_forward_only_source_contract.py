#!/usr/bin/env python3
"""Adversarial exact-source successor proving PR1014 did not rewrite PR990 review."""
import hashlib,json,subprocess
from pathlib import Path
from unittest import mock
import qa31_release_source_gate as qa
repo=Path(__file__).resolve().parents[1]
root=repo/'wp-content/plugins/mad4b-site-control-plane'
path='includes/class-mad4b-scp-self-update.php'
source=(root/path).read_bytes()
frozen=repo/'governance/release-parity/qa31-pr990-wayfa-selective-operator-selfupdate-source-20261121.json'
successor=repo/'governance/release-parity/qa31-pr1014-native-forward-only-source-review-20261121.json'
data=json.loads(successor.read_text())
good='tests/wordpress_native_archive_forward_only_contract.py'
assert hashlib.sha256(frozen.read_bytes()).hexdigest()=='f3a40adaa33ebd49a83d0876f995066070d9b0a6987b42ab192f1fbb8b35d6b4'
assert data['prior_review_sha256']==hashlib.sha256(frozen.read_bytes()).hexdigest()
assert qa.reviewed_pr1014_native_forward_only_source(repo,path,source)==good
assert qa.reviewed_ci_only_source_successor(root,path,source)
checks=['exact_successor_and_registry_reviewed']
for altered in (source+bytes([10])+b'// change without review',
                source.replace(b"'site_bound_certified_pair'",b"'legacy_unbound'"),
                source.replace(b"mad4b_forward_archive_regression_denied",b"unverified_regression"),
                source.replace(b"self::reviewed_archive_no_regression( $path );",b"true;")):
    assert altered!=source
    assert qa.reviewed_pr1014_native_forward_only_source(repo,path,altered) is None
    assert not qa.reviewed_ci_only_source_successor(root,path,altered)
    checks.append('candidate_tamper_denied')
assert qa.reviewed_pr1014_native_forward_only_source(repo,'includes/class-mad4b-scp-operator-grant-convergence.php',source) is None
checks.append('wrong_component_denied')
original_read=Path.read_text
for key,value in (
    ('contract','mad4b.unrelated'),
    ('parent_head_sha','0'*40),
    ('reviewed_source_sha256','0'*64),
    ('reviewed_source_blob_sha','0'*40),
    ('parent_source_sha256','0'*64),
    ('parent_source_blob_sha','0'*40),
    ('prior_review_sha256','0'*64),
    ('control_ids',[]),
    ('regression','tests/injected-source.py'),
    ('negative_regression','tests/injected-source.py'),
    ('authorizing',True),
    ('production_authorized',True),
    ('source_promotion_approved',True),
    ('archive_installed_by_ci',True),
    ('write_executed',True),
    ('host_grant_issued',True),
    ('historical_manifest_modified',True),
    ('source_only',False),
    ('reviewed_for_ci_only',False),
    ('live_evidence_required',False),
    ('release_gate','APPROVED'),
):
    forged=dict(data);forged[key]=value
    def patched(self,*args,**kwargs):
        if self.resolve()==successor.resolve():return json.dumps(forged)
        return original_read(self,*args,**kwargs)
    with mock.patch.object(Path,'read_text',patched):
        assert qa.reviewed_pr1014_native_forward_only_source(repo,path,source) is None,key
        assert not qa.reviewed_ci_only_source_successor(root,path,source),key
    checks.append('tampered_manifest_denied_'+key)
original_bytes=Path.read_bytes
def tamper_prior(self,*args,**kwargs):
    if self.resolve()==frozen.resolve():return b'FORGED HISTORICAL REVIEW'
    return original_bytes(self,*args,**kwargs)
with mock.patch.object(Path,'read_bytes',tamper_prior):
    assert qa.reviewed_pr1014_native_forward_only_source(repo,path,source) is None
checks.append('historical_review_tamper_denied')
# Head of this stacked PR MUST descend from pinned exact PR1000.
base='29fe79e3ae87bd10507186ae24704d2b331c5c3c'
assert subprocess.run(['git','merge-base','--is-ancestor',base,'HEAD'],
                      cwd=repo,stdout=subprocess.DEVNULL).returncode==0
assert hashlib.sha256(source).hexdigest()==data['reviewed_source_sha256']
print('PASS qa31_pr1014_exact_source_successor '+str(len(checks))+' source and manifest controls')
print(json.dumps({'contract':'mad4b.qa31-pr1014-native-forward-only-regression.v1',
                  'checks':len(checks),'historical_rewrite':False,
                  'release_authorized':False,'result':'PASS'},sort_keys=True))

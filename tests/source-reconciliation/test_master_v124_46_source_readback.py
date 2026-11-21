#!/usr/bin/env python3
"""Review-only source SHA, live-mount provenance and activation-denial guards."""
import copy
import hashlib
import importlib.util
import json
import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[2]
P=ROOT/'docs/source-reconciliation/master-v124-pr974-forty-six-source-readback-20261120.json'

def git_blob(raw):
    return hashlib.sha1(b'blob '+str(len(raw)).encode('ascii')+b'\0'+raw).hexdigest()

def exact_reviewed_brand_trait_reconstruction(root, item):
    # Historic 46-file inventory is immutable, including the source SHA and
    # the two live-mount observations. Only the full byte-for-byte inverse
    # of the reviewed 339-line Term Meta Trait may satisfy that old identity.
    original='9169abd2dbce76dfbe087337799dd475eced8a66'
    facade='wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-brand-integrity.php'
    trait='wp-content/plugins/mad4b-site-control-plane/includes/trait-mad4b-scp-brand-integrity-term-meta.php'
    if item['path']!=facade or item['candidate_git_blob']!=original:
        return False
    source=root/'tests/test_brand_integrity_term_trait_exact_source.py'
    if not source.is_file() or source.is_symlink():
        return False
    spec=importlib.util.spec_from_file_location('reviewed_brand_term_trait_exact',source)
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    try:
        prior=module.reconstruct((root/facade).read_bytes(),(root/trait).read_bytes())
    except (AssertionError,OSError,ValueError):
        return False
    return git_blob(prior)==original


def exact_reviewed_cp4_workflow_successor(root,item):
    # Frozen historical 46-path inventory and original live-mount readings do
    # not change. Only the specific original workflow-option successor reviewed
    # by the native signed-only CP4 regression is admissible.
    path='wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-readiness-workflow-options.php'
    if item['path']!=path or item['candidate_git_blob']!='b790da33c16496013ea408d5a142299ec5fd8724':
        return False
    actual=git_blob((root/path).read_bytes())
    if actual!='2b0b20927811e097b6dc19a3d84134205a8eb423':
        return False
    gov=root/'governance/production-readiness/capability-coverage.json'
    if git_blob(gov.read_bytes())!='2ad267654e478457dd7c61acb58950e16177a806':
        return False
    coverage=json.loads(gov.read_text())
    if coverage.get('authorizing') is not False or coverage.get('production_promotion_from_ci') is not False:
        return False
    rows=[r for r in coverage.get('reviewed_changes',[])
          if r.get('path')==path and r.get('source_blob_sha')==actual
          and r.get('status')=='reviewed_for_ci_only'
          and r.get('live_evidence_required') is True
          and r.get('production_authorized') is False
          and 'tests/cp4_content_readiness_source_regression.py' in r.get('regression_tests',[])]
    if len(rows)!=1:return False
    code=(root/path).read_text()
    return ("($issue['source']??'')==='mad4b-package-cost-readiness'" in code
       and "preg_match('/^cp4_cost_[a-f0-9]{12}$/D',$code)" in code
       and "($issue['source_evidence']['contract']??'')==='mad4b.cp4-cost-readiness-alert.v1'" in code
       and "return 'pricing';" in code
       and "return 'general';" in code)


def verify(record, root=ROOT):
    assert record['contract']=='mad4b.46-pr920-collisions-live-mount-source-review.non-authorizing.v1'
    assert record['human_functional_approval'] is False
    assert record['production_authorized'] is False
    assert record['staging_acceptance'] is False
    assert record['counts']['total']==46 and len(record['rows'])==46
    assert record['counts']['runtime_php']==36
    paths=set()
    for item in record['rows']:
        path=item['path']
        assert path not in paths and not path.startswith('/') and '..' not in pathlib.PurePosixPath(path).parts
        paths.add(path)
        assert item['pr920_git_blob']==item['candidate_git_blob']
        assert item['semantic_signoff'] is False and item['runtime_accepted'] is False
        current=git_blob((root/path).read_bytes())
        assert (current==item['candidate_git_blob']
                or exact_reviewed_brand_trait_reconstruction(root,item)
                or exact_reviewed_cp4_workflow_successor(root,item)),path
        for site in ('toursdev_staging','wayfatours_production'):
            state=item['mounted'][site]['state']
            assert state in ('exact','different','absent'),(path,state)
            assert state!='exact' or item['mounted'][site]['mounted_blob_sha1']==item['candidate_git_blob']
    # Security critical master v124 signed revision field must not regress to mutable WP option reservation.
    anchor=(root/'deploy/host-authority/v81/current_revision_gate.py').read_text()
    revoked=anchor.split('def verify_revoked_postcondition(',1)[1]
    assert 'ledger["event_id"]==job["job_id"]' in revoked
    assert 'wp.get("original_reserved_job_id")==job.get("job_id")' not in revoked
    assert git_blob((root/'deploy/host-authority/v81/current_revision_gate.py').read_bytes())=='e02834573bd061ddbb8bd10629b7de13d9ff8e30'
    # The transport additions made to master stay in the unified candidate.
    for path in (
        'wp-content/plugins/mad4b-pricing-registry/includes/transport-self-pricing-controls.php',
        'wp-content/plugins/mad4b-pricing-registry/includes/transport-readiness.php',
        'wp-content/plugins/mad4b-pricing-registry/data/transport-pdf-2026-localized.php',
        'wp-content/plugins/mad4b-tour-media-host-bridge/mad4b-tour-media-host-bridge.php',
    ):
        assert (root/path).is_file(),path
    # Preserve exact non-authorizing Content Readiness trait extraction contract.
    readiness=(root/'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-readiness-alerts.php').read_text()
    assert 'use MAD4B_SCP_Content_Readiness_Scanning_Trait;' in readiness
    assert 'use MAD4B_SCP_Content_Readiness_Reconciliation_Trait;' in readiness
    return True

class SourceSafety(unittest.TestCase):
    def setUp(self):self.obj=json.loads(P.read_text())
    def test_source_inventory(self):self.assertTrue(verify(self.obj))
    def test_reject_automated_approval(self):
        obj=copy.deepcopy(self.obj);obj['human_functional_approval']=True
        with self.assertRaises(AssertionError):verify(obj)
    def test_reject_truncated_collision_list(self):
        obj=copy.deepcopy(self.obj);obj['rows'].pop()
        with self.assertRaises(AssertionError):verify(obj)
    def test_reject_fake_signed_off_ledger_entry(self):
        obj=copy.deepcopy(self.obj);obj['rows'][0]['semantic_signoff']=True
        with self.assertRaises(AssertionError):verify(obj)
    def test_reject_changed_candidate_sha(self):
        obj=copy.deepcopy(self.obj);obj['rows'][0]['candidate_git_blob']='0'*40
        with self.assertRaises(AssertionError):verify(obj)

if __name__=='__main__':unittest.main()

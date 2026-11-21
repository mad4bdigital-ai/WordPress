#!/usr/bin/env python3
"""Exact non-authorizing QA31 source review of two jointly composed runtime files."""
import hashlib
import json
import subprocess
from pathlib import Path

repo = Path(__file__).resolve().parents[1]
manifest = repo / 'governance/release-parity/qa31-pr951-pr937-composite-source-gates-20261120.json'
review = json.loads(manifest.read_text())
pr951 = '1d8a9b4a491368c2f648a0f6e424f7972e20dce0'
pr937 = 'a044005e3b960c874ad492276a07814bf66e350d'
def gitblob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + bytes([0]) + data).hexdigest()
def digest(data):
    return hashlib.sha256(data).hexdigest()
expected = {
  'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-authorization.php': (
    '6283b4a2168dfbbb267d98e7600f896fed8a4f64',
    '0682640be7b6946ddc6c7627576083466079f798a566889f9ce655aa29e58c40',
    '4f6c83ce401008c3ae5335895d776511a4cc64fd',
    'a2ea7dd18c8dbe2326ef5c9d2f7c97bfc170f994',
    ['GOVERNANCE-02','MCP-01','SECURITY-01','EXECUTION-01','PLATFORM-01'],
    [
      'wp-content/plugins/mad4b-site-control-plane/tests/authorization-replay-audit-runtime.php',
      'wp-content/plugins/mad4b-site-control-plane/tests/deployment-binding-claim-context-runtime.php',
    ],
  ),
  'wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-self-update.php': (
    'f8985784fd510af6f4bf9682a0a5a3b8b3f72785',
    '12702ba5b93c474bc1776c8801de36dbc14a1a9a678ba2c29fb13f8e9ea67816',
    'bfc842bf90cda2ec07937337e6488b70a31d091d',
    '48bbd4ecbcb1905da1fa0269cd5575aa9c452d80',
    ['UPDATES-01','CROSSPLUGIN-01','RECOVERY-01','ROLLOUT-01','SECURITY-01','EXECUTION-01'],
    [
      'wp-content/plugins/mad4b-site-control-plane/tests/qa27-candidate-upload-plan-rc118-runtime.php',
      'wp-content/plugins/mad4b-site-control-plane/tests/self-update-dual-channel-contract.py',
      'tests/authority/test-release-source-admission-integration.py',
    ],
  ),
}
# Preserve the historical PR951/PR937 review against its reviewed Git blob.
# Newer SHA-pinned successor changes are checked by independent QA31 records.
historical_head='784ae71e5a088ee299fe4db20912f0d8fe21932e'
def historical_bytes(path):
    if path.endswith('includes/class-mad4b-scp-self-update.php'):
        return subprocess.check_output(
            ['git','show',historical_head+':'+path],cwd=repo)
    return (repo/path).read_bytes()

def check(info, override=None):
    if (info.get('contract') != 'mad4b.qa31-pr951-pr937-composite-source-gates.v1'
        or info.get('pr951_exact_head_sha') != pr951
        or info.get('pr937_exact_head_sha') != pr937
        or info.get('merge_parent_source_binding') != [pr951,pr937]
        or info.get('negative_review_contract') != 'tests/pr951_pr937_composite_source_gates_contract.py'
        or info.get('authorizing') is not False
        or info.get('production_authorized') is not False
        or info.get('source_promotion_approved') is not False
        or info.get('is_build_provenance') is not False
        or info.get('live_evidence_required') is not True
        or info.get('reviewed_for_ci_only') is not True
        or info.get('owner_release_attestation_present') is not False
        or info.get('signed_host_source_mutation_performed') is not False
        or info.get('release_gate') != 'BLOCKED_SOURCE_RECONCILIATION'
        or len(info.get('review_rationale','')) < 250):
        return False
    entries = info.get('source_entries')
    if not isinstance(entries,list) or len(entries) != 2:
        return False
    if [x.get('path') for x in entries] != list(expected):
        return False
    for row in entries:
        path = row['path']
        blob,sha,parent951,parent937,controls,tests=expected[path]
        data = override.get(path,historical_bytes(path)) if override else historical_bytes(path)
        if (gitblob(data)!=blob or digest(data)!=sha
            or row.get('source_blob_sha')!=blob or row.get('source_sha256')!=sha
            or row.get('pr951_parent_blob_sha')!=parent951
            or row.get('pr937_parent_blob_sha')!=parent937
            or row.get('control_ids')!=controls
            or row.get('regression_tests')!=tests
            or row.get('status')!='reviewed_for_ci_only'
            or row.get('production_authorized') is not False
            or row.get('live_evidence_required') is not True
            or len(row.get('review_rationale',''))<250):
            return False
        if any(not (repo/t).is_file() for t in tests):
            return False
        if gitblob(subprocess.check_output(['git','show',pr951+':'+path]))!=parent951:
            return False
        if gitblob(subprocess.check_output(['git','show',pr937+':'+path]))!=parent937:
            return False
        code = data.decode('utf-8')
        if path.endswith('class-mad4b-scp-self-update.php'):
            admission = 'MAD4B_Release_Source_Admission::require_for_release( $source_sha )'
            if (code.count(admission)!=1
                or "MAD4B_SCP_Site_Profile::environment_allowed( array( 'staging' ), 'write' )" not in code
                or 'signed_source_reconciliation_preflight_required' not in code
                or not (code.index('private static function apply_verified_archive')
                        < code.index(admission) < code.index('$lease_owner ='))):
                return False
        else:
            for marker in (
              'MAD4B_SCP_Authorization_Recovery_State_Trait',
              'MAD4B_SCP_Authorization_Claimed_Callback',
              'MAD4B_SCP_Authorization_Observation',
              'MAD4B_SCP_Authorization_Remote_Denial',
            ):
                if code.count(marker) < 1:
                    return False
    for ancestor in (pr951,pr937):
        if subprocess.run(['git','merge-base','--is-ancestor',ancestor,'HEAD'],
                          cwd=repo,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode!=0:
            return False
    # Exact original commits must be the ordered two parents of one REAL
    # merge object reachable from HEAD. Neither a copied tree nor metadata
    # claiming a bridge SHA can manufacture source authority.
    merges = subprocess.check_output(
        ['git','rev-list','--parents','--min-parents=2','HEAD'],cwd=repo,text=True)
    if not any(line.split()[1:] == [pr951,pr937] for line in merges.splitlines()):
        return False
    return True

assert check(review), 'Original SHA-pinned preview must qualify as CI-only'
for label,field,value in (
  ('release', 'release_gate', 'PRODUCTION_AUTHORIZED'),
  ('promote', 'production_authorized', True),
  ('widen', 'authorizing', True),
  ('source', 'source_promotion_approved', True),
  ('parent', 'pr937_exact_head_sha', '0'*40),
  ('merge', 'merge_parent_source_binding', [pr937,pr951]),
  ('attestation', 'owner_release_attestation_present', True),
):
    corrupted = dict(review)
    corrupted[field]=value
    assert not check(corrupted), label
for path in expected:
    tampered = historical_bytes(path)+b'\n# UNREVIEWED\n'
    assert not check(review,{path:tampered}),path
print('PR951_PR937_COMPOSITE_SOURCE_QA31: PASS (two exact SHA policies, denials, no Production release)')

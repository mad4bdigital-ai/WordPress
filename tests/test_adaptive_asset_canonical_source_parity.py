"""Read-only canonical source ownership and tenant drift contract."""
from pathlib import Path
import hashlib
import json
import unittest
import subprocess

ROOT=Path(__file__).resolve().parents[1]
META=ROOT/'ops/site-company-overlays/wayfatours/adaptive-runtime/source-parity-20261116.json'
SUCCESSOR=ROOT/'ops/site-company-overlays/wayfatours/adaptive-runtime/source-forward-port-20261116.json'
FOLLOWUP=ROOT/'ops/site-company-overlays/wayfatours/adaptive-runtime/source-followup-20261121.json'
ADAPTIVE='wp-content/plugins/mad4b-adaptive-asset-intelligence/includes/class-mad4b-scp-adaptive-asset-intelligence.php'
QUEUE_TEST=ROOT/'ops/adaptive-queue-self-heal-20261116/tests/test-adaptive-queue-self-healing.php'

def git_blob_sha1(contents):
 return hashlib.sha1(b'blob '+str(len(contents)).encode()+b'\0'+contents).hexdigest()

class AdaptiveSourceOwnership(unittest.TestCase):
 def test_canonical_master_source_and_wayfa_drift_not_silently_promoted(self):
  m=json.loads(META.read_text())
  successor=json.loads(SUCCESSOR.read_text())
  self.assertEqual(successor['contract'],'mad4b.adaptive-source-forward-port-ci-candidate.v1')
  self.assertEqual(git_blob_sha1(META.read_bytes()),successor['historical_observation_git_blob_sha1'])
  self.assertEqual(successor['source_delta_commit'],'2ed4a8c557bc2e92d832af957a36c387c97e8753')
  self.assertFalse(successor['live_source_reverified_after_delta'])
  self.assertFalse(successor['target_company_release_authorized'])
  self.assertFalse(successor['auto_promote_to_wayfatours'])
  self.assertFalse(successor['production_authorized'])
  self.assertEqual(set(successor['candidate_files']),{ADAPTIVE})
  self.assertEqual(m['contract'],'mad4b.adaptive-asset-site-source-reconciliation-readonly.v1')
  self.assertTrue(m['source_files_match_live_master'])
  self.assertEqual(m['observed_target_drift_count'],2)
  self.assertFalse(m['target_company_runtime_replaced'])
  self.assertFalse(m['target_company_release_authorized'])
  self.assertFalse(m['company_override_accepted_without_review'])
  self.assertTrue(m['legacy_shim_kept'])
  for row in m['files']:
   path=ROOT/row['relative_path']
   self.assertTrue(path.is_file())
   actual=path.read_bytes()
   expected_baseline=row['canonical_source_sha256']
   if row['relative_path'] in successor['candidate_files']:
    candidate=successor['candidate_files'][row['relative_path']]
    self.assertEqual(candidate['observed_live_master_sha256_at_capture'],expected_baseline)
    # Keep the 2026-11-16 source candidate immutable: newer reviewed source
    # must have its own explicit predecessor SHA/blob and current file pins.
    new=json.loads(FOLLOWUP.read_text())
    self.assertEqual(new['contract'],'mad4b.adaptive-asset-source-followup.v1')
    self.assertEqual(new['prior_candidate_sha256'],candidate['git_candidate_source_sha256'])
    self.assertEqual(new['prior_candidate_git_blob_sha1'],candidate['git_candidate_blob_sha1'])
    self.assertEqual(new['updated_source_file'],row['relative_path'])
    self.assertTrue(new['previous_historical_receipt_unchanged'])
    self.assertTrue(new['no_parallel_worker'] and new['no_parallel_queue'])
    self.assertTrue(new['no_css_certification_from_worker_ack'])
    self.assertTrue(new['no_auto_promotion'])
    self.assertEqual(hashlib.sha256(actual).hexdigest(),new['updated_source_sha256'])
    self.assertEqual(git_blob_sha1(actual),new['updated_source_git_blob_sha1'])
    self.assertFalse(candidate['production_authorized'])
    self.assertFalse(candidate['certified_for_target'])
   else:
    self.assertEqual(hashlib.sha256(actual).hexdigest(),expected_baseline)
   self.assertEqual(row['tours_dev_observed_sha256'],row['canonical_source_sha256'])
   self.assertFalse(row['auto_promote_to_wayfatours'])
  self.assertEqual(sum(row['wayfatours_parity']=='exact' for row in m['files']),2)

 def test_adaptive_regression_retains_home_dependency_and_source_gates(self):
  src=(ROOT/'wp-content/plugins/mad4b-adaptive-asset-intelligence/includes/class-mad4b-scp-adaptive-asset-intelligence.php').read_text()
  for needle in ('epochBumpedThisRequest','home_impact_preview','page_on_front',
                 'elementor_library','queue_refresh','source_package_updated',
                 'site_uuid','brand_id',
                 "'queued'=>(bool)($saved['ok']??false)",
                 "START TRANSACTION", "FOR UPDATE", "ROLLBACK",
                 'source_revision_changed','due_worker_jobs'):
   self.assertIn(needle,src)
  for blocked in ('wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-adaptive-asset-intelligence.php',):
   bridge=(ROOT/blocked).read_text()
   self.assertIn('Backwards compatibility bridge',bridge)
   self.assertIn('require_once $real',bridge)
   self.assertNotIn('final class MAD4B_SCP_Adaptive_Asset_Intelligence',bridge)
  # Execute the first-party no-network queue/CAS regression, rather than
  # accepting a SHA-only successor record as proof of safe functionality.
  result=subprocess.run(['php',str(QUEUE_TEST),str(ROOT/ADAPTIVE)],
      cwd=ROOT,capture_output=True,text=True,timeout=45,check=False)
  self.assertEqual(result.returncode,0,result.stdout+'\n'+result.stderr)
  self.assertIn('PASS 20/20 adaptive queue/source reconciliation assertions',result.stdout)
  readback=subprocess.run(['php',str(ROOT/'tests/test_css_native_worker_status.php')],
      cwd=ROOT,capture_output=True,text=True,timeout=30,check=False)
  self.assertEqual(readback.returncode,0,readback.stdout+'\n'+readback.stderr)
  self.assertIn('CSS_NATIVE_WORKER_STATUS_CONTRACT_PASS',readback.stdout)

if __name__=='__main__':unittest.main()

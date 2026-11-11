"""Guard source-level fixes of MCP protocol readiness and CLI site digest."""
import json,subprocess,hashlib
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
MCP='wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-mcp-protocol-profile.php'
CAT='wp-content/plugins/mad4b-site-control-plane/config/mcp-protocol-profiles.json'
CLI='wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-cli-readiness.php'
PRE='70dd63d866b84d7a5ef1e9a2cc0ff8fe37fa5f87'
class MCPCLI(unittest.TestCase):
 def test_previous_snapshot_immutable_and_current_newer(self):
  for rel in (MCP,CAT,CLI):
   old=subprocess.run(['git','-C',str(ROOT),'cat-file','blob',PRE+':'+rel],
                      capture_output=True,timeout=15)
   self.assertEqual(old.returncode,0)
   self.assertNotEqual(hashlib.sha256(old.stdout).hexdigest(),
                       hashlib.sha256((ROOT/rel).read_bytes()).hexdigest())
 def test_mcp_actual_runtime_evidence_defined(self):
  s=(ROOT/MCP).read_text()
  for code in ("$compatibility = self::adapter_transport_compatibility(",
               "$runtime_proof = self::runtime_protocol_support(",
               "$scope = self::request_scope_evidence();",
               "$protocol_ready = $protocol_ready &&",
               "$scoped_ready = ! $scope['canonical_mcp_request']",
               "'ready' => $protocol_ready",
               "'authorizing' => false",
               "mad4b_mcp_protocol_revision_unavailable_in_loaded_runtime"):
   self.assertIn(code,s)
  self.assertNotIn("in_array( $runtime_version, array( '0.7.1' ), true )",s)
  self.assertNotIn("$version_match",s)
 def test_profile_catalog_reference_not_runtime_version_gate(self):
  d=json.loads((ROOT/CAT).read_text())
  ref=d['certified_adapter']
  self.assertIs(ref['version_gate'],False)
  self.assertEqual(ref['version_source'],'certified_provider_profile_reference')
  runtime=json.loads((ROOT/'wp-content/plugins/mad4b-site-control-plane/config/runtime-release-policy.json').read_text())
  self.assertFalse(runtime['installed_adapter_version_gate'])
  self.assertNotIn('target_adapter_version',runtime)
  self.assertEqual(runtime['adapter_release_target_source'],'certified_runtime_release_set.mcp_adapter')
 def test_cli_digest_comes_from_current_profile(self):
  s=(ROOT/CLI).read_text()
  self.assertIn("MAD4B_SCP_Site_Profile::status()",s)
  self.assertIn("'profile_digest'=>(string)($profile['profile_digest']??'')",s)
  self.assertNotIn("'profile_digest'=>(string)($status['profile_digest']??'')",s)
  self.assertIn("in_array(false,$checks,true)",s)
  self.assertIn("plan_sha256",s)
  self.assertIn("MAD4B_SCP_Content_Readiness_Alerts::run_scheduled()",s)
if __name__=='__main__':unittest.main()

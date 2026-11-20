#!/usr/bin/env python3
"""Fail-closed source-only owner UI v127 expiration compatibility contracts."""
from pathlib import Path
import re
import unittest

ROOT=Path(__file__).resolve().parents[2]
BASE=ROOT/"deploy/host-authority/review/v127"
VERIFIER=(BASE/"signed-final-certificate.php").read_text()
CONSOLE=(BASE/"393-mad4b-host-authority-owner-console.php").read_text()
CLIENT=(BASE/"host-authority.js").read_text()
class SourceGuard(unittest.TestCase):
 def test_01_no_activating_plugin_path_changes_in_candidate(self):
  self.assertFalse((ROOT/"wp-content/mu-plugins/393-mad4b-host-authority-owner-console.php").exists())
  self.assertFalse((ROOT/"wp-content/mu-plugins/mad4b-host-authority-owner-ui/signed-final-certificate.php").exists())
 def test_02_original_first_job_and_cryptographic_public_key_pinned(self):
  self.assertIn("const JOB='5b21b7aae2de8e3ef9342ae2ceaa2802'",VERIFIER)
  self.assertIn("const INDEPENDENT_PUB_B64='4jwcLgOUf2d/j2uDYbVysCnkSMJW64cXnhMPaeix11Y='",VERIFIER)
  self.assertEqual(VERIFIER.count("sodium_crypto_sign_verify_detached("),1)
  self.assertEqual(VERIFIER.count("self::signed_body("),3)
  self.assertIn("hash_equals($independent,$broker)",VERIFIER)
 def test_03_expired_certificate_never_restores_authority(self):
  for guard in (
   "('ACTIVE','EXPIRED')","prior_grants_ineligible",
   "signed_anchor_active_in_option","current_anchor_signature_verified",
   "current_anchor_eligible","write_authorized",
   "'execution_authority_granted'=>false",
   "'anchor_currently_unexpired'=>!$expired",
   "'state'=>$expired?'EXPIRED':'ACTIVE'"):
   self.assertIn(guard,VERIFIER)
 def test_04_ui_has_distinct_exPIRED_historical_phase(self):
  self.assertIn("ANCHOR_EXPIRED_CERTIFIED_HISTORICAL",CONSOLE)
  self.assertIn("ANCHOR_EXPIRED_CERTIFIED_HISTORICAL",CLIENT)
  self.assertIn("Host Anchor issued — signed history verified; validity expired",CONSOLE)
  self.assertIn("no Host write or grant is currently authorized",CONSOLE)
  self.assertIn("Original expiry",CONSOLE)
  self.assertIn("Host authority'=>'No Host write authorized'",CONSOLE)
  self.assertIn("'certificate_expires_epoch'",CONSOLE)
 def test_05_historical_phase_has_no_issuance_action(self):
  self.assertIn("} elseif($issuanceVerified) {",CONSOLE)
  self.assertIn("} elseif($review) {",CONSOLE)
  self.assertIn("else {",CONSOLE)
  self.assertIn('$issuanceVerified=$certified||$historical;',CONSOLE)
  self.assertIn('phase !== "ANCHOR_EXPIRED_CERTIFIED_HISTORICAL"',CLIENT)
  self.assertIn('anchorRevision === 0 && anchorState === "MISSING_OR_INVALID"',CLIENT)
 def test_06_renewal_requires_original_owner_post_not_get(self):
  self.assertNotIn("rest_do_request(",CONSOLE.split("final class",1)[1])
  self.assertIn("'methods'=>'GET'",CONSOLE)
  self.assertIn("current_user_can('manage_options')",CONSOLE)
  self.assertIn("self::allowed_owner($u)",CONSOLE)
  self.assertIn("OWNER_RENEWAL_READY",CONSOLE)
 def test_07_original_certificate_metadata_does_not_include_signer_secrets(self):
  fields=CONSOLE.split("public static function read_state(",1)[1].split("$body['server_epoch']",1)[0]
  for token in ("'certificate_expires_epoch'","'certificate_verified'","'certificate_job_id'"):
   self.assertIn(token,fields)
  self.assertNotIn("'private_key'",fields)
  self.assertNotIn("'signature'",fields)
 def test_08_original_first_revision_and_company_only(self):
  self.assertIn("($receipt['job_id']??null)!==self::JOB",VERIFIER)
  self.assertIn("($ledger['target_host_id']??null)!=='hosting-2'",VERIFIER)
  self.assertIn("($row['company']??null)!=='all-royal-egypt'",VERIFIER)
  self.assertIn("($row['environment']??null)!=='staging'",VERIFIER)
  self.assertIn("($row['revision']??null)!==1",VERIFIER)
if __name__=="__main__":
 unittest.main(verbosity=2)

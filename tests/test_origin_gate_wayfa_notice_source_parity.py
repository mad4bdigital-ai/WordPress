"""Exact observed per-site source parity, no production activation."""
from pathlib import Path
import hashlib
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]
ORIGIN = ROOT / 'wp-content/mu-plugins/mad4b-general-public-page-origin-cache.php'
WAYFA = ROOT / 'ops/site-company-overlays/wayfatours/configuration-runtime/wp-content/mu-plugins/mad4b-configuration-notice-revalidation.php'
MANIFEST = ROOT / 'ops/site-company-overlays/wayfatours/configuration-runtime/manifest.json'
SHA_ORIGIN = '3fa6cffb25dfb315133caef1716f285427c2a2749c3e6c57d2a9c665e7bb73d7'
SHA_WAYFA = '184d0415fe8141ae7d669a5b3f1f85be5766183a743c82e577054653355193ef'

class SourceLiveParityNoAuthority(unittest.TestCase):
    def test_origin_matches_enrolled_source_and_never_bypasses_privacy(self):
        raw = ORIGIN.read_bytes()
        self.assertEqual(hashlib.sha256(raw).hexdigest(), SHA_ORIGIN)
        s = raw.decode('utf8')
        for witness in (
            'MAD4B_Experience_Site_Scope_V1::bound()',
            'MAD4B_Company_Browser_HTML_Policy::company_bound()',
            'MAD4B_Company_Browser_HTML_Policy::eligible()',
            "'mad4b_public_page_origin_cache_v1','canary'",
            'is_wc_endpoint_url()', 'woocommerce_checkout_page_id',
            'Set-Cookie', 'private|no-store|no-cache',
            "if ($mode!=='on')return;"):
            self.assertIn(witness,s)
        self.assertNotIn('tours.dev.mad4b.com',s)

    def test_wayfa_tenant_owner_notice_is_byte_pinned_and_non_authorizing(self):
        raw = WAYFA.read_bytes()
        self.assertEqual(hashlib.sha256(raw).hexdigest(), SHA_WAYFA)
        m = json.loads(MANIFEST.read_text())
        self.assertTrue(m['no_implicit_shared_source_activation'])
        self.assertTrue(m['manual_deployment_without_original313_signature'])
        self.assertTrue(m['original305_hold_preserved'])
        self.assertTrue(m['never_modify_stripe_settings'])
        self.assertEqual(m['source_file']['sha256'], SHA_WAYFA)
        self.assertEqual(m['source_file']['bytes'], len(raw))
        s = raw.decode('utf8')
        for witness in (
            "wayfatours.com", "woocommerce_stripe_settings",
            "active_plugins", 'independent_live_option_readback',
            "scan_incomplete", "ledger_busy",
            "admin_notice_revalidation_evidence",
            "state'] = 'resolved'", "state'] = 'open'",
        ):
            self.assertIn(witness,s)
        self.assertNotIn("update_option('woocommerce_stripe_settings'",s)
        self.assertNotIn('stripe_secret',s.lower())


    def test_historical_wayfa_notice_fingerprint_remains_git_immutable(self):
        import subprocess
        historical='13d8d4d3716d1ad5a1664d6d16c7aec189f9060f275a96eef1e15402eac46aff'
        previous='6b949bc4bf46fa6f3ae29765d57741c662088f53'
        p='ops/site-company-overlays/wayfatours/configuration-runtime/wp-content/mu-plugins/mad4b-configuration-notice-revalidation.php'
        stored=subprocess.run(['git','-C',str(ROOT),'show',previous+':'+p],
                              capture_output=True,check=True,timeout=20).stdout
        self.assertEqual(hashlib.sha256(stored).hexdigest(),historical)
        self.assertNotEqual(historical,SHA_WAYFA)

if __name__ == '__main__':
    unittest.main()

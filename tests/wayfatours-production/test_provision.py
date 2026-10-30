"""Static safety contract for a new, isolated Wayfatours production WordPress tenant."""
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
BOOT = (ROOT / "scripts/provision_wayfatours_production_v1.sh").read_text()
INIT = (ROOT / "scripts/wayfatours_post_install.php").read_text()
GATE = (ROOT / "scripts/gate_new_company_production.php").read_text()
CHECK = (ROOT / "scripts/check_wayfatours_public_https.sh").read_text()

class NewTenantProductionTests(unittest.TestCase):
    def test_isolated_production_and_secrets(self):
        self.assertIn("name: wayfatours-production", BOOT)
        self.assertIn("wayfatours_production", BOOT)
        self.assertIn("127.0.0.1:8107:8080", BOOT)
        self.assertIn("internal: true", BOOT)
        self.assertIn("openssl rand -hex 36", BOOT)
        self.assertIn("openssl rand -hex 32", BOOT)
        self.assertIn("chmod 600", BOOT)
        self.assertIn("WP_ENVIRONMENT_TYPE', 'production'", BOOT)
        self.assertIn("DISALLOW_FILE_EDIT', true", BOOT)

    def test_no_tenant_data_clone(self):
        self.assertNotIn("wp db import", BOOT)
        self.assertNotIn("wp search-replace", BOOT)
        self.assertNotIn('"$SOURCE/wp-content/uploads"', BOOT)
        self.assertNotIn('"$SOURCE/wp-config.php"', BOOT)
        self.assertNotIn("SELECT * FROM wp_users", BOOT)
        self.assertIn("wp-content/plugins/$plugin", BOOT)
        self.assertIn("mad4b-company-policy-library.php", BOOT)

    def test_limited_live_features_and_own_brand(self):
        self.assertIn("wp plugin activate elementor jet-engine", BOOT)
        self.assertIn("199-wayfatours-unconfigured-email.php", BOOT)
        self.assertIn("WP_HOME', 'https://wayfatours.mad4b.com'", BOOT)
        self.assertIn("WP_SITEURL', 'https://wayfatours.mad4b.com'", BOOT)
        self.assertIn("Wayfatours", INIT)
        self.assertIn("'blog_public','0'", INIT)
        self.assertIn("wp_generate_uuid4", INIT)
        self.assertIn("'internal_only_dns_tls_pending'", INIT)
        self.assertIn("mad4b_company_site_identity_v1", INIT)

    def test_mandatory_admission_after_kit_before_public(self):
        self.assertIn("mad4b_kit_integrity_admission()", BOOT)
        self.assertIn("gate_new_company_production.php", BOOT)
        self.assertIn("MAD4B_EXPECTED_DB=wayfatours_production", BOOT)
        self.assertIn("PRODUCTION_PRIVATE_ADMITTED_DNS_TLS_PENDING", GATE)
        self.assertIn("unreviewed_cross_tenant_media", GATE)
        self.assertIn("unexpected_tenant_commerce_records", GATE)
        self.assertIn("unreviewed_active_plugin", GATE)
        self.assertIn("site_uuid", GATE)

    def test_tls_mandatory_no_insecure_public_success(self):
        self.assertIn("traefik.http.routers.wayfatours-production.entrypoints=https", BOOT)
        self.assertIn("traefik.http.routers.wayfatours-production.tls.certresolver=letsencrypt", BOOT)
        self.assertIn("traefik.http.middlewares.wayfatours-production-redirect.redirectscheme.scheme=https", BOOT)
        self.assertIn("DNS_MISSING", CHECK)
        self.assertIn("TRUSTED_ORIGIN_TLS_FAILED", CHECK)
        self.assertIn("PUBLIC_HTTPS_FAILED", CHECK)
        self.assertNotIn("curl -k ", CHECK)
        self.assertNotIn("curl -sk", CHECK)

if __name__ == "__main__":
    unittest.main()

"""Source-only WordPress 305/306 readback candidate never grants authority."""
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
PLUGIN=ROOT/"wp-content/plugins/mad4b-site-control-plane"
SOURCE=PLUGIN/"includes/class-mad4b-scp-original-job-readback.php"
ENTRY=PLUGIN/"mad4b-site-control-plane.php"
BOOT=PLUGIN/"includes/class-mad4b-scp-plugin.php"
SERVER=PLUGIN/"includes/class-mad4b-scp-server-core-catalog.php"
FIXTURE=ROOT/"tests/native-original-job-readback/acceptance.php"

class ReadbackBridge(unittest.TestCase):
    def test_source_php_exists_but_is_not_live_bootstrapped(self):
        self.assertTrue(SOURCE.is_file())
        self.assertTrue(FIXTURE.is_file())
        for path in (ENTRY,BOOT,SERVER):
            self.assertNotIn("MAD4B_SCP_Original_Job_Readback",path.read_text())
            self.assertNotIn("class-mad4b-scp-original-job-readback.php",path.read_text())

    def test_bounded_dynamic_read_only_ability(self):
        php=SOURCE.read_text()
        for item in (
            "const ABILITY = 'mad4b/original-job-claim-readback'",
            "const MAX_COMMANDS = 24;",
            "const MAX_JOB_ROWS = 1000;",
            "current_user_can( 'manage_options' )",
            "'readonly'=>true",
            "'destructive'=>false",
            "'show_in_rest'=>false",
            "'authority_created'=>false",
            "'original312_eligible'=>false",
            "'signed_original313_receipt_verified'=>false",
            "'job_or_claim_mutated'=>false",
            "'target_scope'",
            "'signed_remote_hostinger_wpcli'",
            "self::CLAIM_PREFIX",
            "self::ROUTE_PREFIX",
        ):
            with self.subTest(item=item):
                self.assertIn(item,php)
        for bad in (
            "wp_insert_post(", "update_option(", "add_option(",
            "delete_option(", "wp_remote_post(", "file_put_contents(",
            "shell_exec(", "system(", "exec(", "passthru(",
            "wp_update_post(", "wp_create_nonce(",
        ):
            with self.subTest(forbidden=bad):
                self.assertNotIn(bad,php)

    def test_fail_closed_readback_contract_and_scope(self):
        php=SOURCE.read_text()
        for item in (
            "'site_scope_mismatch'",
            "'company_scope_invalid'",
            "'job_store_incomplete'",
            "'job_key_invalid'",
            "'digest_list_invalid'",
            "'READ_ONLY__ORIGINAL312_INDEPENDENT_SIGNATURE_REQUIRED'",
            "'conflicting_scope_count'",
            "'HOLD__UNVERIFIED_OR_AMBIGUOUS'",
            "count( $keys ) > self::MAX_JOB_ROWS",
            "'contract'",
            "get_option( $key, false )",
        ):
            with self.subTest(item=item):
                self.assertIn(item,php)
        self.assertNotIn("const OPERATION",php)
        self.assertNotIn("source.code.release",php)
        self.assertNotIn("tours.dev.mad4b.com",php)
        self.assertNotIn("wayfatours.com",php)

    def test_php_fixture_contains_negative_assertions(self):
        source=FIXTURE.read_text()
        for indicator in (
            "role denied","truncated DB reject","company-bound command scope",
            "foreign scope collision","invalid claim contract",
            "ambiguous job not disclosed","reject repeated digest",
            "non-wordpress job cannot satisfy plan readback",
        ):
            self.assertIn(indicator,source)

if __name__=="__main__":
    unittest.main()

"""Regression checks for original Broker/313 runtime rebinding."""
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

BASE=Path(__file__).resolve().parents[1]
sys.path[:0]=[str(BASE),str(BASE/'integrations')]
import central_wpcli_runtime as central
import rebind_original_313_callers as migration313
import rebind_original_broker_callers as migrationbroker


class RebindTests(unittest.TestCase):
    def test_broker_patch_one_enrolled_target(self):
        original=b"from wpcli_dynamic_actor import with_actor\n" + (
            b"WP=['/usr/bin/docker','exec','-u','www-data','etg-commerce-staging-php']\n")
        changed=migrationbroker.replace_once(original)
        self.assertIn(b'@mad4b-central-staging',changed)
        self.assertNotIn(b'etg-commerce-staging-php',changed)
        self.assertEqual(migrationbroker.replace_once(changed),changed)

    def test_broker_patch_refuses_foreign_script(self):
        with self.assertRaisesRegex(RuntimeError,'NOT_ENROLLED'):
            migrationbroker.replace_once(b"WP=['etg-commerce-staging-php']")

    def test_313_patch_preserves_execution_and_signatures(self):
        raw=("import sys\n"
             "WP=['/usr/bin/docker','exec','-u','www-data','etg-commerce-staging-php',\n"
             "    '/usr/local/bin/wp','--path=/var/www/html','--skip-themes']\n"
             "def signed():\n return 'signed-313-immutable'\n"
             "def cli():\n return WP+['mad4b','broker']\n")
        result=migration313.patch(raw)
        self.assertIn("sys.path.insert(0, '/opt/mad4b-company-transport')",result)
        self.assertIn('from central_wpcli_runtime import central_wp',result)
        self.assertIn("WP()+['mad4b','broker']",result)
        self.assertIn("'signed-313-immutable'",result)
        self.assertEqual(migration313.patch(result),result)

    def test_313_patch_rejects_changed_source(self):
        with self.assertRaisesRegex(RuntimeError,'PREFIX_UNEXPECTED'):
            migration313.patch("WP=['unknown']\n")

    def test_current_313_wpcli_exact_container(self):
        central.central_wp.cache_clear()
        facts={"container":"php-current-123",
               "site_uuid":"e5e64bca-ed62-48d2-b2b3-dff693cd702a",
               "environment":"staging"}
        with patch.object(central,'enrolled_target',return_value=facts):
            argv=central.central_wp()
            self.assertEqual(argv[4],facts['container'])
            self.assertEqual(argv[-1],'--skip-themes')
        central.central_wp.cache_clear()

    def test_313_rejects_nonstaging(self):
        central.central_wp.cache_clear()
        with patch.object(central,'enrolled_target',
                          return_value={"container":"php-prod",
                                        "environment":"production"}):
            with self.assertRaisesRegex(RuntimeError,'WRONG_ENVIRONMENT'):
                central.central_wp()
        central.central_wp.cache_clear()


if __name__=='__main__':
    unittest.main()

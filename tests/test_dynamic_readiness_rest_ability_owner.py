"""Discriminate real WordPress Abilities, REST namespaces and retired mentions.

Source-only; deliberately refuses to turn this code review into an owner or
release authorization.
"""
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
import production_readiness_registry as reg

HEAD = "072588249a26fe026ea3649778ebb1aeb87ac692"
BASE = "5c72e4893e36e992f056c90cfc75908b3a8f7785"
SOURCE = reg.PLUGIN + "includes/class-mad4b-scp-core-web-vitals.php"
OWNER = reg.PLUGIN + "includes/class-mad4b-scp-staging-write-grant-reconciliation-plan.php"
ABILITY = "ability:mad4b/staging-write-grant-reconciliation-plan"


class ReadinessSurfaceConvergence(unittest.TestCase):
    def test_real_core_web_vitals_rest_routes_not_fake_ability(self):
        abilities = reg.php_capabilities(HEAD, SOURCE)
        routes = reg.registered_surfaces(HEAD, SOURCE)
        self.assertNotIn("ability:mad4b/v1", abilities)
        self.assertIn("ability:mad4b/core-web-vitals-status", abilities)
        self.assertEqual(
            {r for r in routes if r.startswith("rest:")},
            {"rest:mad4b/v1/admin/core-web-vitals" + suffix for suffix in
             ("", "/lifecycle", "/settings", "/refresh")},
        )

    def test_real_existing_ability_owner_survives_both_immutable_sources(self):
        self.assertEqual(reg.registered_ability_owner(BASE, ABILITY), OWNER)
        self.assertEqual(reg.registered_ability_owner(HEAD, ABILITY), OWNER)
        source = reg.git_text(HEAD, OWNER)
        self.assertIn("wp_register_ability( self::ABILITY", source)
        self.assertIn("'mad4b/staging-write-grant-reconciliation-plan'", source)

    def test_rest_namespace_variable_can_not_hide_actual_ability(self):
        fixture = """<?php
            function register_abilities() {
                $ns = 'mad4b/v1'; $base = '/tests';
                register_rest_route($ns,$base,array());
                wp_register_ability($ns,array());
                wp_register_ability('mad4b/real-item',array());
            }
        """
        with mock.patch.object(reg, "git_text", return_value=fixture):
            abilities = reg.php_capabilities(HEAD, SOURCE)
            self.assertIn("ability:mad4b/v1", abilities)
            self.assertIn("ability:mad4b/real-item", abilities)
            self.assertIn("rest:mad4b/v1/tests", reg.registered_surfaces(HEAD, SOURCE))

    def test_ambiguous_reassignment_is_not_silently_resolved(self):
        fixture = """<?php
            function register_abilities() {
                $ns='mad4b/v1'; $ns='mad4b/v2'; $base='/tests';
                register_rest_route($ns,$base,array());
            }
        """
        with mock.patch.object(reg, "git_text", return_value=fixture):
            self.assertIn("ability:mad4b/v1", reg.php_capabilities(HEAD, SOURCE))
            self.assertIn("ability:mad4b/v2", reg.php_capabilities(HEAD, SOURCE))
            self.assertFalse({r for r in reg.registered_surfaces(HEAD, SOURCE)
                              if r.startswith("rest:")})

    def test_mention_is_never_registered_owner(self):
        marker = "wp-content/plugins/mad4b-site-control-plane/includes/example.php"
        mocked = SimpleNamespace(
            returncode=0,
            stdout=(HEAD + ":" + marker).encode("utf-8") + b"\n",
        )
        with mock.patch.object(reg, "git", return_value=mocked), \
             mock.patch.object(reg, "git_text", return_value="""
                <?php function register_ability(){
                  $guidance='mad4b/staging-write-grant-reconciliation-plan';
                  wp_register_ability('mad4b/something-else',array());
                }"""):
            self.assertIsNone(reg.registered_ability_owner(HEAD, ABILITY))

    def test_removed_real_owner_does_not_gain_false_lifecycle_exemption(self):
        mock_result = SimpleNamespace(returncode=1, stdout=b"")
        with mock.patch.object(reg, "git", return_value=mock_result):
            self.assertIsNone(reg.registered_ability_owner(HEAD, ABILITY))
        # Explicit source coverage is still mandatory for changed owner files.
        policy = reg.json_file(HEAD, reg.COVERAGE)
        self.assertTrue(policy['code_change_rules']['require_current_git_blob'])
        self.assertTrue(policy['code_change_rules']['require_same_pr_regression_test'])
        self.assertFalse(policy['production_promotion_from_ci'])


if __name__ == '__main__':
    unittest.main()

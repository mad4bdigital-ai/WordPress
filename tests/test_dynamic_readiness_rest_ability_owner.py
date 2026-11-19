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

    def test_original_wordpress_actions_are_not_phantom_abilities(self):
        actual = reg.php_capabilities(HEAD, SOURCE)
        self.assertNotIn("ability:mad4b/performance/release_verified", actual)
        self.assertIn("ability:mad4b/core-web-vitals-status", actual)
        source = """<?php
            function register_abilities() {
                add_action('mad4b/same-name', 'handler');
                add_filter('mad4b/filter', 'handler');
                wp_register_ability('mad4b/same-name', array());
            }
        """
        with mock.patch.object(reg, "git_text", return_value=source):
            found = reg.php_capabilities(HEAD, SOURCE)
            self.assertIn("ability:mad4b/same-name", found)
            self.assertNotIn("ability:mad4b/filter", found)

    def test_real_wordpress_cron_hooks_are_not_recurrence_labels(self):
        routes = reg.registered_surfaces(HEAD, SOURCE)
        self.assertEqual(
            {entry for entry in routes if entry.startswith("cron:")},
            {"cron:" + name for name in
             ("mad4b_core_web_vitals_daily",
              "mad4b_core_web_vitals_refresh_once",
              "mad4b_core_web_vitals_device_worker")},
        )
        self.assertNotIn("cron:daily", routes)

    def test_nested_cron_arguments_are_scanned_without_invention(self):
        fixture = """<?php
            class MAD4B_Test {
                const EVENT = 'mad4b_real_cron';
                function boot() {
                    wp_schedule_event(max(time(), intval($due)), 'daily', self::EVENT);
                    wp_schedule_single_event(time()+10, 'mad4b_oneshot', array($x,$y));
                    wp_schedule_event(time()+20, 'hourly', $untrusted_hook);
                }
            }
        """
        self.assertEqual(reg.cron_event_candidates(fixture),
                         {"cron:mad4b_real_cron", "cron:mad4b_oneshot"})

    def test_original_dynamic_content_ability_has_base_and_head_owner(self):
        # Dynamic Content calls inherited add_ability(self::APPLY), which
        # actually registers through Adapter_Base::add_ability. A mere
        # special-case in Adapter_Base is not itself a new Ability.
        target = reg.PLUGIN + "includes/adapters/class-mad4b-scp-dynamic-content-adapter.php"
        key = "ability:mad4b/content-apply-bundle"
        self.assertEqual(reg.registered_ability_owner(BASE, key), target)
        self.assertEqual(reg.registered_ability_owner("5498d89bfea0c4162aac1aa0b454fd9a251ccaab", key), target)
        source = reg.git_text(BASE, target)
        self.assertIn("const APPLY='mad4b/content-apply-bundle'", source)
        self.assertRegex(source, r"\$this->add_ability\(\s*self::APPLY")

    def test_canonical_first_party_registration_retained_across_trait_extraction(self):
        prior = "8845efdc700e3aac4688346312676f9a638d56e6"
        subsequent = "d1e1cefc6e997aef6b723a957c9d4bf6b290d9f8"
        facade = reg.PLUGIN + "includes/class-mad4b-scp-abilities.php"
        trait = reg.PLUGIN + "includes/trait-mad4b-scp-abilities-registration.php"
        for name in (
            "mad4b/site-info", "mad4b/content-get-post",
            "mad4b/write-execute", "mad4b/database-raw-query",
            "mad4b/runtime-authority-status", "mad4b/abilities-inventory",
        ):
            with self.subTest(name=name):
                self.assertEqual(reg.registered_ability_owner(prior, "ability:" + name), facade)
                self.assertEqual(reg.registered_ability_owner(subsequent, "ability:" + name), trait)
        self.assertIsNone(reg.registered_ability_owner(subsequent, "ability:mad4b/missing-ability"))

    def test_orphan_or_unloaded_trait_not_a_registered_ability(self):
        ref = "d1e1cefc6e997aef6b723a957c9d4bf6b290d9f8"
        facade_path = reg.PLUGIN + "includes/class-mad4b-scp-abilities.php"
        trait_path = reg.PLUGIN + "includes/trait-mad4b-scp-abilities-registration.php"
        facade = reg.git_text(ref, facade_path)
        trait = reg.git_text(ref, trait_path)
        ability = "mad4b/content-get-post"
        self.assertTrue(reg.registered_first_party_add_owner(ref, trait_path, trait, ability))
        for forbidden_facade in (
            facade.replace("use MAD4B_SCP_Abilities_Registration_Trait;", ""),
            facade.replace("$this->register_source_abilities();", ""),
            facade.replace("wp_register_ability( $name, $args );", ""),
        ):
            with mock.patch.object(reg, "git_text", return_value=forbidden_facade):
                self.assertFalse(reg.registered_first_party_add_owner(
                    ref, trait_path, trait, ability))
        self.assertFalse(reg.registered_first_party_add_owner(
            ref, trait_path, trait.replace(
                "$this->add( 'mad4b/content-get-post',",
                "$this->add( 'mad4b/unrelated-post',"), ability))
        self.assertFalse(reg.registered_first_party_add_owner(
            ref, facade_path, "<?php $note='mad4b/content-get-post';", ability))

    def test_persistent_review_keeps_old_regressions_with_new_pr_evidence(self):
        historical = "wp-content/plugins/mad4b-site-control-plane/tests/integration-ticket-brand-runtime-regressions.py"
        current = "tests/test_dynamic_readiness_rest_ability_owner.py"
        self.assertTrue(reg.same_pr_regression_coverage(
            [historical, current], {current}))
        self.assertFalse(reg.same_pr_regression_coverage(
            [historical, current], set()))
        self.assertFalse(reg.same_pr_regression_coverage(
            [historical], {current}))
        self.assertFalse(reg.same_pr_regression_coverage(
            [current, "tests/does-not-exist-source-review.py"], {current}))
        self.assertFalse(reg.same_pr_regression_coverage([], {current}))

    def test_adapter_constant_mention_without_registration_is_not_owner(self):
        marker = reg.PLUGIN + "includes/adapters/fixture.php"
        mocked = SimpleNamespace(returncode=0, stdout=(BASE + ":" + marker).encode() + b"\n")
        fixture = "<?php class Fixture { const APPLY='mad4b/content-apply-bundle'; function register_abilities() { $help=self::APPLY; } }"
        with mock.patch.object(reg, "git", return_value=mocked), mock.patch.object(reg, "git_text", return_value=fixture):
            self.assertIsNone(reg.registered_ability_owner(BASE, "ability:mad4b/content-apply-bundle"))

    def test_prior_reviewed_coverage_cannot_be_dropped_by_owner_discovery(self):
        source = reg.PLUGIN + "includes/adapters/fixture.php"
        ability = "ability:mad4b/retained-reviewed-ability"
        with mock.patch.object(reg, "catalog_snapshot", return_value={}), \
             mock.patch.object(reg, "php_capabilities", side_effect=[set(), {ability}]), \
             mock.patch.object(reg, "registered_surfaces", return_value=set()), \
             mock.patch.object(reg, "registered_ability_owner", return_value=source):
            _, _, added, _ = reg.new_source_ids(
                BASE, HEAD, [("M", source)], preserve_reviewed={ability})
        self.assertIn(ability, added)

    def test_unreviewed_reference_to_preexisting_owner_is_not_new_ability(self):
        source = reg.PLUGIN + "includes/adapters/fixture.php"
        ability = "ability:mad4b/existing-owner-reference"
        with mock.patch.object(reg, "catalog_snapshot", return_value={}), \
             mock.patch.object(reg, "php_capabilities", side_effect=[set(), {ability}]), \
             mock.patch.object(reg, "registered_surfaces", return_value=set()), \
             mock.patch.object(reg, "registered_ability_owner", return_value=source):
            _, _, added, _ = reg.new_source_ids(
                BASE, HEAD, [("M", source)], preserve_reviewed=set())
        self.assertNotIn(ability, added)

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

#!/usr/bin/env python3
"""Offline, no-CI safety and translation contract for the guided operator UX."""
from pathlib import Path
import gettext
import re
root = Path(__file__).resolve().parents[1]
guide = (root / 'includes/class-mad4b-scp-guided-operator-experience.php').read_text()
operator = (root / 'includes/class-mad4b-scp-operator-workspace.php').read_text()
bootstrap = (root / 'mad4b-site-control-plane.php').read_text()
css = (root / 'assets/admin-workspace.css').read_text()
approval = (root / 'includes/class-mad4b-scp-approval-decision-admin.php').read_text()
browser = (root / 'includes/class-mad4b-scp-browser-acceptance-admin-ui.php').read_text()
skills = (root / 'includes/class-mad4b-scp-skills-admin-ui.php').read_text()
environment = (root / 'includes/class-mad4b-scp-admin-experience.php').read_text()
for marker in (
    "mad4b.guided-operator-experience.v1",
    "public static function model( $operator, $profile, $skills, $browser )",
    "class_exists( 'MAD4B_SCP_Skill_Runtime_Certification', false )",
    "MAD4B_SCP_Skill_Runtime_Certification::persisted_status()",
    "array( 'mutation_state_uncertain', 'recovery_required' )",
    "'EVIDENCE_UNTRUSTED'",
    "'NOT_APPLICABLE'",
    "'NOT_CHECKED'",
    "'NEEDS_ACTION'",
    "'EXTERNAL_ACTION'",
    "'release_certified' => false",
    "catch ( Throwable $error )",
    'aria-current="step"',
    "MAD4B_SCP_Admin_Workspace::link( $step['route'], $step['query'] )",
):
    assert marker in guide, f'guided-model missing: {marker}'
for forbidden in (
    "update_option(", "delete_option(", "wp_register_ability(", "wp_remote_", "admin_post_",
    "grant_ability(", "wp_schedule_", "proc_open(", "shell_exec(", "$_POST", "$wpdb"
):
    assert forbidden not in guide, f'guided journey can never mutate or dispatch: {forbidden}'
assert "class-mad4b-scp-guided-operator-experience.php" in bootstrap
assert "MAD4B_SCP_Guided_Operator_Experience::render( $snapshot )" in operator
assert 'Advanced setup reference' in operator
assert '.mad4b-guided-step.is-next' in css and '@media (max-width:782px)' in css
assert 'Review exact decision boundary' in approval and 'Unknown impact is never assumed safe.' in approval
assert "A WordPress acceptance adapter is missing." in browser
assert "Saved browser preference needs correction." in browser
assert "Verify external browser execution and receipt." in browser
assert "Managed Skills need verification" in skills
assert "implicit_nonproduction_override_confirmed" in environment and "$confirmed_default" in environment
catalog = gettext.GNUTranslations((root / 'languages/mad4b-site-control-plane-ar.mo').open('rb'))
for key in (
    "Your next safe steps", "Restore Managed Skills", "Browser setup: your next step",
    "Managed Skills need verification", "Review exact decision boundary", "Advanced setup reference"
):
    translated = catalog.gettext(key)
    assert translated != key and re.search('[\\u0600-\\u06ff]', translated), key
print('mad4b.guided-operator-ux-static-contract.v1: PASS')

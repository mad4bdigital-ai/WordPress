#!/usr/bin/env python3
"""Independent source-level regression for Business Activity Ability identities.

The Full Governed Content workflow separately executes the actual PHP fixture.
This test is deliberately executable by the audited Python readiness runner.
"""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
DEFINITIONS = ROOT / "includes/trait-mad4b-scp-content-experience-ability-definitions.php"
INVENTORY = ROOT / "includes/class-mad4b-scp-content-experience-profiles.php"
LEGACY = "mad4b/business-activity-sync-plan"
CONFIGURED = "mad4b/business-activity-contract-sync-plan"


def verify(source, inventory):
    match = re.search(r"public static function ability_definitions\s*\(\s*\)", source)
    assert match, "Missing Ability definition function"
    body = source[match.end():]
    names = re.findall(r"'name'\s*=>\s*'([^']+)'", body)
    assert len(names) >= 35, "Incomplete Ability definitions"
    assert len(names) == len(set(names)), "Duplicate static Ability identifier"
    expected = {
        LEGACY: (
            "'MAD4B_SCP_Activity_Sync_Runtime', 'plan'",
            "array( __CLASS__, 'can_manage_profiles' )",
            "'profile_slug', 'entity_id'",
        ),
        CONFIGURED: (
            "'MAD4B_SCP_Business_Activity_Contracts', 'sync_plan'",
            "'permission' => $read",
            "'profile_slug', 'target_id', 'operation'",
        ),
    }
    for name, (callback, permission, fields) in expected.items():
        assert names.count(name) == 1, "Missing Ability identity: " + name
        start = body.index("'name' => '" + name + "'")
        # Bound assertions to one definition, not the whole source file.
        nxt = re.search(r"array\(\s*'name'\s*=>", body[start + len(name) + 12:])
        end = start + len(name) + 12 + nxt.start() if nxt else start + 900
        item = body[start:end]
        assert callback in item, "Ability callback drift: " + name
        assert permission in item, "Ability permission drift: " + name
        assert fields in item, "Ability schema drift: " + name
        assert "'surface' => 'read'" in item and "'readonly' => true" in item, \
            "Ability read-only scope drift: " + name
    im = re.search(
        r"public static function ability_names\s*\(\s*\$surface\s*\).*?"
        r"\$read\s*=\s*array\((.*?)\);\s*\$content\s*=\s*array\(",
        inventory, re.S,
    )
    assert im, "Read inventory missing"
    for name in expected:
        assert im.group(1).count("'" + name + "'") == 1, "Read inventory mismatch: " + name


def require_failure(source, inventory, marker):
    try:
        verify(source, inventory)
    except AssertionError as exc:
        assert marker in str(exc), str(exc)
    else:
        raise AssertionError("Regression guard failed to detect " + marker)


if __name__ == "__main__":
    source = DEFINITIONS.read_text(encoding="utf-8")
    inventory = INVENTORY.read_text(encoding="utf-8")
    verify(source, inventory)
    require_failure(
        source.replace("'name' => '" + CONFIGURED + "'", "'name' => '" + LEGACY + "'", 1),
        inventory, "Duplicate",
    )
    require_failure(
        source,
        inventory.replace("'" + CONFIGURED + "'", "'mad4b/invalid-name'", 1),
        "Read inventory",
    )
    print("PASS: unique Ability names, callbacks, input schemas, read permissions and inventory")

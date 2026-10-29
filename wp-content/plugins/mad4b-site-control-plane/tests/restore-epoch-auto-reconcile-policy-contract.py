#!/usr/bin/env python3
"""Static regression for restore identity fencing (no WordPress writes)."""
from pathlib import Path

source = (Path(__file__).resolve().parents[1] / "includes" / "class-mad4b-scp-restore-epoch.php").read_text()
assert "! $identity_changed && preg_match" in source
assert "'auto_reconcile_eligible' => (bool) $eligible" in source
assert "'identity_provenance_required' => (bool) $identity_changed" in source
method = source.split("public static function attempt_authorized_self_recovery()", 1)[1].split("public static function recover_from_database_binding", 1)[0]
for fence in ("identity_changed", "current_user_can( 'manage_options' )", "write_enabled()", "recover_from_database_binding( (string) $plan['previous_record_sha256'], false )"):
    assert fence in method, fence
assert "recover_from_database_binding( (string) $plan['previous_record_sha256'], true )" not in method
print("PASS: restore identity fence and same-site recovery policy")

cli = (Path(__file__).resolve().parents[1] / "includes" / "class-mad4b-scp-cli.php").read_text()
assert "mad4b diagnostics restore-epoch" in cli
assert "public static function diagnostics_restore_epoch" in cli
cli_method = cli.split("public static function diagnostics_restore_epoch",1)[1].split("public static function package_verify",1)[0]
assert "self::emit(" in cli_method
assert "recover_from_database_binding(" not in cli_method
print("PASS: read-only WP-CLI restore diagnostic contract")

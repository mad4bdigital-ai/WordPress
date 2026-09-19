#!/usr/bin/env python3
"""Guard the canonical WordPress dbDelta upgrade shape for MAD4B governance tables.

Fresh CREATE can succeed even when multiple field definitions are collapsed onto one
line, but WordPress dbDelta discovers existing-table upgrade candidates line-by-line.
This contract therefore models the field/key visibility rule that matters for an
installed site rather than merely checking that column names occur somewhere in SQL.
"""
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
SCHEMA = (ROOT / "includes/class-mad4b-scp-schema.php").read_text(encoding="utf-8")

EXPECTED_FIELDS = {
    "agents": (
        "id", "public_id", "slug", "label", "status", "wp_user_id", "environment",
        "revision", "created_by", "created_at", "updated_at",
    ),
    "subjects": (
        "id", "agent_id", "subject_type", "subject_fingerprint", "label", "status",
        "created_at", "updated_at",
    ),
    "grants": (
        "id", "agent_id", "effect", "server_id", "ability_name", "provider",
        "resource_schema_version", "resource_constraints", "environment", "created_by",
        "created_at", "updated_at",
    ),
    "approvals": (
        "id", "ticket_id", "ticket_class", "agent_id", "server_id", "ability_name",
        "provider", "target_fingerprint", "payload_sha256", "status", "reason",
        "approved_by", "approved_at", "expires_at", "used_at",
        "candidate_binding_contract", "candidate_sha", "build_fingerprint",
        "binding_environment", "binding_host", "site_uuid", "site_profile_revision",
        "site_profile_digest", "bound_at", "created_at",
    ),
    "mutations": (
        "id", "mutation_id", "request_id", "parent_mutation_id", "agent_id",
        "subject_type", "subject_fingerprint", "wp_user_id", "server_id", "ability_name",
        "provider", "provider_version", "target_type", "target_id", "approval_ticket_id",
        "impact", "status", "reversible", "before_sha256", "after_sha256",
        "rollback_payload", "rollback_payload_sha256", "undo_expires_at",
        "verification_code", "error_code", "created_at", "updated_at",
    ),
    "budgets": (
        "id", "agent_id", "budget_type", "window_seconds", "max_count", "enabled",
        "updated_by", "updated_at",
    ),
    "budget_windows": (
        "id", "agent_id", "budget_type", "window_start", "window_seconds", "used_count",
        "created_at", "updated_at",
    ),
    "audit_events": (
        "id", "chain_name", "sequence", "event_id", "occurred_at", "request_id",
        "user_id", "ability", "status", "summary_json", "previous_hash", "entry_hash",
        "created_at",
    ),
    "audit_heads": (
        "chain_name", "sequence", "entry_hash", "legacy_anchor_sha256",
        "legacy_chain_valid", "legacy_entry_count", "created_at", "updated_at",
    ),
}

EXPECTED_KEYS = {
    "agents": ("PRIMARY KEY", "UNIQUE KEY public_id", "UNIQUE KEY slug", "KEY status", "KEY wp_user_id"),
    "subjects": ("PRIMARY KEY", "UNIQUE KEY subject_binding", "KEY agent_status"),
    "grants": ("PRIMARY KEY", "UNIQUE KEY exact_grant", "KEY agent_ability", "KEY server_ability"),
    "approvals": (
        "PRIMARY KEY", "UNIQUE KEY ticket_id", "KEY agent_status_expiry", "KEY payload_status",
        "KEY decision_inbox", "KEY candidate_inbox", "KEY site_profile_inbox",
    ),
    "mutations": (
        "PRIMARY KEY", "UNIQUE KEY mutation_id", "KEY agent_created", "KEY ability_created",
        "KEY status_created", "KEY parent_mutation_id", "KEY request_id",
    ),
    "budgets": ("PRIMARY KEY", "UNIQUE KEY agent_budget"),
    "budget_windows": ("PRIMARY KEY", "UNIQUE KEY agent_budget_window", "KEY window_cleanup", "KEY agent_window"),
    "audit_events": (
        "PRIMARY KEY", "UNIQUE KEY chain_sequence", "UNIQUE KEY event_id", "KEY request_id",
        "KEY ability_sequence", "KEY entry_hash",
    ),
    "audit_heads": ("PRIMARY KEY",),
}

REQUIRED_APPROVAL_BINDINGS = (
    "candidate_binding_contract", "candidate_sha", "build_fingerprint",
    "binding_environment", "binding_host", "site_uuid", "site_profile_revision",
    "site_profile_digest", "bound_at",
)


def table_body(name: str) -> str:
    marker = f'$sql[] = "CREATE TABLE {{$t[\'{name}\']}} ('
    start = SCHEMA.find(marker)
    if start < 0:
        raise AssertionError(f"missing CREATE TABLE statement for {name}")
    start = SCHEMA.find("\n", start)
    end = SCHEMA.find('\n\t\t) $charset;";', start)
    if start < 0 or end < 0:
        raise AssertionError(f"non-canonical multiline CREATE TABLE statement for {name}")
    return SCHEMA[start + 1 : end]


def visible_dbdelta_tokens(body: str):
    """Approximate dbDelta's line-oriented candidate discovery.

    dbDelta trims each physical definition line and uses its first token/KEY form.
    This intentionally does not parse comma-separated SQL as independent fields.
    """
    fields = []
    keys = []
    for raw in body.splitlines():
        line = raw.strip().rstrip(",")
        if not line:
            continue
        upper = line.upper()
        if upper.startswith("PRIMARY KEY"):
            keys.append("PRIMARY KEY")
            continue
        if upper.startswith("UNIQUE KEY "):
            match = re.match(r"UNIQUE\s+KEY\s+([^\s(]+)", line, flags=re.I)
            if match:
                keys.append(f"UNIQUE KEY {match.group(1).strip('`')}")
            continue
        if upper.startswith("KEY "):
            match = re.match(r"KEY\s+([^\s(]+)", line, flags=re.I)
            if match:
                keys.append(f"KEY {match.group(1).strip('`')}")
            continue
        fields.append(line.split(None, 1)[0].strip("`"))
    return fields, keys


def main():
    for table, expected_fields in EXPECTED_FIELDS.items():
        body = table_body(table)
        fields, keys = visible_dbdelta_tokens(body)
        missing_fields = [field for field in expected_fields if field not in fields]
        if missing_fields:
            raise AssertionError(f"{table}: fields hidden from dbDelta line discovery: {','.join(missing_fields)}")
        missing_keys = [key for key in EXPECTED_KEYS[table] if key not in keys]
        if missing_keys:
            raise AssertionError(f"{table}: keys hidden from dbDelta line discovery: {','.join(missing_keys)}")
        if "PRIMARY KEY  (" not in body:
            raise AssertionError(f"{table}: PRIMARY KEY must use canonical dbDelta spacing")

    approvals = table_body("approvals")
    approval_fields, _ = visible_dbdelta_tokens(approvals)
    hidden_bindings = [field for field in REQUIRED_APPROVAL_BINDINGS if field not in approval_fields]
    if hidden_bindings:
        raise AssertionError("approval upgrade bindings are not dbDelta-visible: " + ",".join(hidden_bindings))

    # Reproduce the exact regression from the installed-site failure: these v6
    # fields must be independent ALTER candidates when upgrading an existing table.
    for field in ("site_uuid", "site_profile_revision", "site_profile_digest"):
        matches = re.findall(rf"(?m)^\s*{re.escape(field)}\s+", approvals)
        if len(matches) != 1:
            raise AssertionError(f"{field}: expected one standalone dbDelta-visible definition, got {len(matches)}")

    print("mad4b.schema-dbdelta-upgrade.v1: PASS")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"mad4b.schema-dbdelta-upgrade.v1: FAIL: {exc}", file=sys.stderr)
        raise

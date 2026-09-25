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
    "content_jobs": (
        "id", "job_id", "site_uuid", "brand_id", "state", "stage",
        "current_artifact_id", "job_revision", "created_at", "updated_at",
    ),
    "content_job_events": (
        "id", "event_id", "job_id", "sequence", "event_type", "plan_sha256",
        "artifact_id", "previous_entry_sha256", "entry_sha256", "created_at",
    ),
    "artifacts": (
        "id", "artifact_id", "site_uuid", "job_id", "artifact_type", "version", "status",
        "content_sha256", "payload_json", "metadata_json", "producer_stage", "producer_ref",
        "supersedes_artifact_id", "created_by_actor", "created_at",
    ),
    "artifact_edges": (
        "id", "edge_id", "site_uuid", "job_id", "from_artifact_id", "to_artifact_id",
        "relation", "invalidated", "reason_code", "created_at",
    ),
    "intent_relations": (
        "id", "relation_id", "site_uuid", "locale", "market", "intent_id", "content_id",
        "role", "confidence", "evidence_json", "analysis_signals_json", "source", "revision",
        "valid_from", "valid_to", "current_relation_key", "owner_scope_key",
        "relation_sha256", "created_at",
    ),
    "work_leases": (
        "id", "work_id", "aggregate_type", "aggregate_id", "worker_id", "lease_epoch",
        "expected_aggregate_revision", "status", "acquired_at", "heartbeat_at",
        "expires_at", "reconciliation_ref", "created_at", "updated_at",
    ),
    "idempotency": (
        "id", "scope_key", "idempotency_key", "request_sha256", "claim_epoch", "status",
        "result_json", "result_sha256", "reconciliation_ref", "expires_at",
        "created_at", "updated_at",
    ),
    "outbox": (
        "id", "outbox_id", "job_id", "expected_job_revision", "provider_id",
        "capability_id", "workflow_plan_sha256", "idempotency_key", "request_sha256",
        "payload_json", "status", "attempts", "provider_execution_ref",
        "last_error_class", "available_at", "created_at", "updated_at",
    ),
    "inbox": (
        "id", "provider_id", "provider_event_id", "job_id", "payload_sha256",
        "status", "provider_execution_ref", "result_ref", "received_at", "processed_at",
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
    "content_jobs": (
        "PRIMARY KEY", "UNIQUE KEY job_id", "KEY site_lifecycle", "KEY brand_market",
        "KEY target_post_id", "KEY updated_at",
    ),
    "content_job_events": (
        "PRIMARY KEY", "UNIQUE KEY event_id", "UNIQUE KEY job_sequence",
        "KEY correlation_id", "KEY created_at",
    ),
    "artifacts": (
        "PRIMARY KEY", "UNIQUE KEY artifact_id", "UNIQUE KEY job_type_version",
        "KEY job_created", "KEY site_type", "KEY content_sha256",
    ),
    "artifact_edges": (
        "PRIMARY KEY", "UNIQUE KEY edge_id", "UNIQUE KEY artifact_relation",
        "KEY job_relation", "KEY to_invalidated",
    ),
    "intent_relations": (
        "PRIMARY KEY", "UNIQUE KEY relation_revision", "UNIQUE KEY current_relation_key",
        "KEY owner_scope_key", "KEY scope_lookup", "KEY content_lookup", "KEY relation_sha256",
    ),
    "work_leases": (
        "PRIMARY KEY", "UNIQUE KEY work_id", "KEY aggregate_status", "KEY lease_expiry",
    ),
    "idempotency": (
        "PRIMARY KEY", "UNIQUE KEY scope_idempotency", "KEY expiry_status",
    ),
    "outbox": (
        "PRIMARY KEY", "UNIQUE KEY outbox_id", "UNIQUE KEY provider_idempotency",
        "KEY delivery_queue",
    ),
    "inbox": (
        "PRIMARY KEY", "UNIQUE KEY provider_event", "KEY job_status", "KEY received_at",
    ),
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

    # Feature 007 durable execution relies on these fields being upgrade-visible,
    # not merely present in fresh CREATE statements.
    durable_required = {
        "idempotency": ("claim_epoch", "reconciliation_ref", "expires_at"),
        "work_leases": ("lease_epoch", "expires_at", "reconciliation_ref"),
        "outbox": ("workflow_plan_sha256", "idempotency_key", "request_sha256"),
        "inbox": ("provider_event_id", "job_id", "payload_sha256"),
    }
    for table, required in durable_required.items():
        fields, _ = visible_dbdelta_tokens(table_body(table))
        hidden = [field for field in required if field not in fields]
        if hidden:
            raise AssertionError(
                f"{table}: durable execution fields hidden from dbDelta: {','.join(hidden)}"
            )

    if "const VERSION = 11;" not in SCHEMA:
        raise AssertionError("Intent Authority requires schema version 11")
    if "mad4b_scp_schema_integrity_v11" not in SCHEMA:
        raise AssertionError("Intent Authority schema integrity token was not versioned")
    intent_body = table_body("intent_relations")
    if "UNIQUE KEY current_owner_scope" in intent_body:
        raise AssertionError("Intent Authority must not encode false single-owner exclusivity")

    # The frozen Feature 007 schema-evolution contract requires migration identity,
    # preflight, additive/forward-fix semantics, post-verification evidence and
    # fail-closed persistence. dbDelta visibility alone is not enough.
    migration_markers = (
        "const MIGRATION_CONTRACT = 'mad4b.schema-migration.v1';",
        "const MIGRATION_ID = '20260925-feature007-intent-authority-v11';",
        "const MIGRATION_RECEIPT_OPTION = 'mad4b_scp_schema_migration_receipt_v11';",
        "'prerequisite_schema_versions' => array( 0, 6, 7, 8, 9, 10, 11 )",
        "'forward_operation' => 'dbdelta_additive_mad4b_tables_columns_and_indexes'",
        "'rollback_or_forward_fix' => 'forward_fix_only_preserve_additive_schema_old_code_ignores_new_surfaces'",
        "'destructive' => false",
        "'authority_widening' => false",
        "'partial_failure_recovery'",
        "'mixed_version_compatibility'",
        "public static function migration_preflight_status()",
        "'future_schema_downgrade_forbidden'",
        "'mad4b.schema-migration-receipt.v1'",
        "private static function migration_receipt_matches_contract",
        "private static function migration_receipt_valid",
        "private static function migration_origin_version",
        "private static function normalize_intent_relation_indexes",
        "DROP INDEX `current_owner_scope`",
        "$from_version = self::migration_origin_version( $installed_version );",
        "private static function persist_and_verify_option",
        "'mad4b_schema_migration_receipt_persist_failed'",
        "'mad4b_schema_migration_readiness_persist_failed'",
        "'mad4b_schema_migration_final_receipt_failed'",
    )
    for marker in migration_markers:
        if marker not in SCHEMA:
            raise AssertionError(f"Schema v11 migration contract marker missing: {marker}")

    physical_pos = SCHEMA.find("$physical = self::physical_integrity_status();")
    physical_guard_pos = SCHEMA.find("if ( empty( $physical['ready'] ) )", physical_pos)
    first_receipt_pos = SCHEMA.find("$physical_receipt = self::migration_receipt", physical_guard_pos)
    version_commit_pos = SCHEMA.find("persist_and_verify_option( self::OPTION, self::VERSION )", first_receipt_pos)
    integrity_commit_pos = SCHEMA.find("persist_and_verify_option( self::INTEGRITY_OPTION", version_commit_pos)
    final_receipt_pos = SCHEMA.find("$final_receipt = self::migration_receipt", integrity_commit_pos)
    ready_pos = SCHEMA.find("self::$critical_ready_cache = true;", final_receipt_pos)
    if min(physical_pos, physical_guard_pos, first_receipt_pos, version_commit_pos, integrity_commit_pos, final_receipt_pos, ready_pos) < 0:
        raise AssertionError("Schema v11 migration evidence ordering markers are incomplete")
    if not (physical_pos < physical_guard_pos < first_receipt_pos < version_commit_pos < integrity_commit_pos < final_receipt_pos < ready_pos):
        raise AssertionError("Schema v11 readiness may advance before deep verification/final receipt")

    is_ready_pos = SCHEMA.find("public static function is_ready()")
    critical_ready_pos = SCHEMA.find("public static function critical_ready()", is_ready_pos)
    is_ready_body = SCHEMA[is_ready_pos:critical_ready_pos]
    if "self::migration_receipt_valid()" not in is_ready_body:
        raise AssertionError("Schema v11 readiness must require a valid finalized migration receipt")

    print("mad4b.schema-dbdelta-upgrade.v3: PASS")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"mad4b.schema-dbdelta-upgrade.v3: FAIL: {exc}", file=sys.stderr)
        raise

#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
schema = (ROOT / 'includes/class-mad4b-scp-schema.php').read_text('utf-8')
audit = (ROOT / 'includes/class-mad4b-scp-audit.php').read_text('utf-8')
governance = (ROOT / 'includes/class-mad4b-scp-governance-abilities.php').read_text('utf-8')
integrity = (ROOT / 'includes/class-mad4b-scp-audit-integrity.php').read_text('utf-8')
budgets = (ROOT / 'includes/class-mad4b-scp-budgets.php').read_text('utf-8')
plugin = (ROOT / 'includes/class-mad4b-scp-plugin.php').read_text('utf-8')
audit_all = audit + '\n' + integrity


def require(text, needle, label):
    if needle not in text:
        raise SystemExit(f'FAIL {label}: missing {needle!r}')


def forbid(text, needle, label):
    if needle in text:
        raise SystemExit(f'FAIL {label}: forbidden {needle!r}')


version_match = re.search(r"const VERSION = (\d+);", schema)
if not version_match or int(version_match.group(1)) < 9:
    raise SystemExit('FAIL schema-durable-baseline: schema version must be >= 9')
schema_version = int(version_match.group(1))
require(schema, f"mad4b_scp_schema_integrity_v{schema_version}", 'schema-integrity-version-match')
for table in ('mad4b_scp_audit_events', 'mad4b_scp_audit_heads'):
    require(schema, table, 'audit-schema-table')
require(schema, 'UNIQUE KEY chain_sequence (chain_name,sequence)', 'audit-sequence-unique')
require(schema, 'legacy_anchor_sha256 char(64) NOT NULL', 'legacy-anchor-column')

require(audit, "const CONTRACT = 'mad4b.audit.v2';", 'audit-contract')
require(audit, "const LEGACY_OPTION = 'mad4b_scp_audit_log';", 'legacy-option-read')
require(audit, 'SUMMARY_MAX_BYTES = 65536', 'summary-bound')
require(audit, 'VERIFY_BATCH = 500', 'verify-bound')
require(audit, 'START TRANSACTION', 'own-transaction')
require(audit, 'database_transaction_open()', 'outer-transaction-detection')
require(audit, 'MAD4B_SCP_Budgets::transaction_is_open()', 'budget-owned-transaction-state')
require(audit, 'public static function ensure_head_initialized()', 'head-preinitialization-service')
require(audit, "'retention_contract'] = 'mad4b.audit-retention.v1'", 'audit-retention-contract')
require(audit, "'retention_mode'] = 'append_only_no_automatic_deletion'", 'audit-retention-mode')
require(audit, "'automatic_deletion_enabled'] = false", 'audit-no-auto-delete')
require(audit, "'redaction_contract'] = 'mad4b.audit-redaction.v1'", 'audit-redaction-contract')
require(audit, "'sensitive_key_redaction_enabled'] = true", 'audit-redaction-enabled')
require(audit, 'retention_review_threshold_events', 'audit-retention-review-threshold')
require(governance, "'mad4b/audit-storage-status'", 'audit-status-ability')
require(governance, 'public static function audit_storage_status', 'audit-status-handler')
require(governance, "'mutation_performed'] = false", 'audit-status-readonly')
require(plugin, 'MAD4B_SCP_Audit::ensure_head_initialized()', 'head-preinitialized-during-plugin-lifecycle')
require(audit, 'INSERT IGNORE INTO', 'race-safe-head-init')
require(audit, 'LIMIT 1 FOR UPDATE', 'head-lock')
require(audit, "$wpdb->insert(\n\t\t\t$t['audit_events']", 'append-event')
require(audit, "UPDATE {$t['audit_heads']}", 'head-advance')
require(audit, 'mad4b_audit_legacy_anchor_drift', 'legacy-anchor-drift')
require(integrity, 'public static function verify_chain()', 'chain-verifier')
require(integrity, 'private static function committed_snapshot(', 'single-statement-committed-snapshot')
require(integrity, 'AS event_count', 'snapshot-event-count')
require(integrity, 'AS last_sequence', 'snapshot-last-sequence')
require(integrity, "$target_sequence = (int) $status['head_sequence'];", 'fixed-verification-head')
require(integrity, 'AND sequence <= %d ORDER BY sequence ASC LIMIT %d', 'bounded-verification-prefix')
forbid(integrity, "$head = $tables_ready ? $wpdb->get_row(", 'no-split-head-status-read')
require(budgets, 'public static function transaction_is_open()', 'budget-transaction-state-service')
require(budgets, 'MAD4B_SCP_Audit::transaction_committed()', 'explicit-post-commit-dispatch')
require(budgets, 'MAD4B_SCP_Audit::transaction_rolled_back()', 'explicit-post-rollback-drop')
require(audit, "do_action( 'mad4b_scp_audit_committed', $entry )", 'external-sink-hook')
require(audit_all, 'hash_equals', 'constant-time-hash-compare')

for forbidden_probe in ('@@session.in_transaction', '@@in_transaction'):
    forbid(audit, forbidden_probe, 'no-nonportable-transaction-probe')
for forbidden_dispatch in ('register_shutdown_function', 'dispatch_pending_after_transaction'):
    forbid(audit, forbidden_dispatch, 'no-uncommitted-shutdown-dispatch')
for forbidden_writer in (
    'update_option(', 'add_option(', 'delete_option(',
    "UPDATE {$t['audit_events']}", "DELETE FROM {$t['audit_events']}"
):
    forbid(audit_all, forbidden_writer, 'append-only-event-storage')

print('mad4b.site-control-plane.audit-persistence-contract.v5: PASS')

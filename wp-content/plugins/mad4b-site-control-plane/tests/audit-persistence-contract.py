#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
schema = (ROOT / 'includes/class-mad4b-scp-schema.php').read_text('utf-8')
audit = (ROOT / 'includes/class-mad4b-scp-audit.php').read_text('utf-8')
integrity = (ROOT / 'includes/class-mad4b-scp-audit-integrity.php').read_text('utf-8')
audit_all = audit + '\n' + integrity

def require(text, needle, label):
    if needle not in text:
        raise SystemExit(f'FAIL {label}: missing {needle!r}')

def forbid(text, needle, label):
    if needle in text:
        raise SystemExit(f'FAIL {label}: forbidden {needle!r}')

require(schema, 'const VERSION = 4;', 'schema-v4')
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
require(audit, 'SELECT @@session.in_transaction', 'mysql-transaction-state')
require(audit, 'INSERT IGNORE INTO', 'race-safe-head-init')
require(audit, 'LIMIT 1 FOR UPDATE', 'head-lock')
require(audit, "$wpdb->insert(\n\t\t\t$t['audit_events']", 'append-event')
require(audit, "UPDATE {$t['audit_heads']}", 'head-advance')
require(audit, 'mad4b_audit_legacy_anchor_drift', 'legacy-anchor-drift')
require(integrity, 'public static function verify_chain()', 'chain-verifier')
require(audit, 'register_shutdown_function', 'joined-transaction-post-commit-dispatch')
require(audit, "SELECT event_id FROM {$t['audit_events']}", 'post-commit-existence-proof')
require(audit, "do_action( 'mad4b_scp_audit_committed', $entry )", 'external-sink-hook')
require(audit_all, 'hash_equals', 'constant-time-hash-compare')

for forbidden_writer in (
    'update_option(', 'add_option(', 'delete_option(',
    "UPDATE {$t['audit_events']}", "DELETE FROM {$t['audit_events']}"
):
    forbid(audit_all, forbidden_writer, 'append-only-event-storage')

print('mad4b.site-control-plane.audit-persistence-contract.v2: PASS')

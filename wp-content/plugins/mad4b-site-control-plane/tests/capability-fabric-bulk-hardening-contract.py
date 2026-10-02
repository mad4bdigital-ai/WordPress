#!/usr/bin/env python3
from pathlib import Path
import sys

ROOT=Path(__file__).resolve().parents[1]
catalog=(ROOT/"includes/class-mad4b-scp-ability-catalog-transport.php").read_text(encoding="utf-8")
schema=(ROOT/"includes/class-mad4b-scp-schema.php").read_text(encoding="utf-8")
journal=(ROOT/"includes/class-mad4b-scp-operation-journal.php").read_text(encoding="utf-8")
guard=(ROOT/"includes/class-mad4b-scp-database-transaction-guard.php").read_text(encoding="utf-8")
commit_guard=(ROOT/"includes/class-mad4b-scp-execution-commit-guard.php").read_text(encoding="utf-8")
main=(ROOT/"mad4b-site-control-plane.php").read_text(encoding="utf-8")
request_generation=(ROOT/"includes/class-mad4b-scp-request-generation.php").read_text(encoding="utf-8")
identity=(ROOT/"includes/class-mad4b-scp-identity-context.php").read_text(encoding="utf-8")
abilities=(ROOT/"includes/class-mad4b-scp-abilities.php").read_text(encoding="utf-8")
mcp_scope=(ROOT/"includes/class-mad4b-scp-mcp-request-scope.php").read_text(encoding="utf-8")

def need(cond,msg):
    if not cond:
        raise AssertionError(msg)

need("serialize( $definition )" not in catalog, "security-sensitive catalog fingerprint still has PHP serialize fallback")
need("mad4b.catalog-definition-unavailable.v1" in catalog and "$definition_failures" in catalog, "catalog canonical failure marker missing")
need("public static function transactional_storage_status" in schema, "transactional storage readiness contract missing")
need("'innodb' !== $engine" in schema, "transactional storage does not fail closed on non-InnoDB engine")
need("identity_comparison_policy" in schema, "database identity comparison policy missing")
need("SAVEPOINT " in guard and "RELEASE SAVEPOINT " in guard, "portable savepoint transaction-state probe missing")
need("savepoint.+does not exist" in guard, "savepoint no-transaction discriminator missing")
need("performance_schema.events_transactions_current" not in guard, "transaction ownership must not depend on Performance Schema privileges")
need("@@session.in_transaction" not in guard, "transaction ownership must not depend on MariaDB-only session variables")
need("mad4b_database_nested_transaction_denied" in guard, "nested transaction denial missing")
need("mad4b_database_transaction_commit_uncertain" in guard, "uncertain commit state is not explicit")
need("MAD4B_SCP_Database_Transaction_Guard::begin" in journal, "operation journal still owns raw transaction begin")
need("MAD4B_SCP_Database_Transaction_Guard::commit" in journal, "operation journal still owns raw transaction commit")
need("WHERE BINARY operation_id=BINARY %s" in journal, "operation journal identity lookup is not binary-exact")
need("'database_storage' => $database_storage" in commit_guard, "commit guard does not bind transactional storage")
need("'database_storage', 'kill_switch'" in commit_guard, "commit revalidation does not recheck database storage")
need("class-mad4b-scp-database-transaction-guard.php" in main, "transaction guard is not loaded by plugin bootstrap")
need("class-mad4b-scp-request-generation.php" in main, "request-generation fence is not loaded by plugin bootstrap")
need("mad4b.request-scope-generation.v1" in request_generation, "request-generation contract missing")
need("mad4b_request_scope_context_drift" in request_generation, "same-request context drift does not fail closed")
need("mad4b_request_scope_worker_recycle_required" in request_generation, "site/runtime generation change does not require worker recycle")
need("MAD4B_SCP_Database_Transaction_Guard::transaction_state" in request_generation, "request boundary ignores active database transactions")
need("reset_owners" in request_generation and "MAD4B_SCP_Agent_Registry" in request_generation and "MAD4B_SCP_Servers" in request_generation, "request-local cache ownership registry is incomplete")
need("request_scope_state" in identity and "reset_request_cache" in identity, "identity request-local reset contract missing")
need("request_scope_state" in abilities and "reset_request_cache" in abilities, "dispatcher governance-envelope reset contract missing")
need("request_scope_transition_safe" in mcp_scope, "MCP hook-lifecycle transition safety contract missing")

print("mad4b.capability-fabric.bulk-hardening.contract.v1: PASS")

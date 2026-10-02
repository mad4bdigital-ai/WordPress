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

def need(cond,msg):
    if not cond:
        raise AssertionError(msg)

need("serialize( $definition )" not in catalog, "security-sensitive catalog fingerprint still has PHP serialize fallback")
need("mad4b.catalog-definition-unavailable.v1" in catalog and "$definition_failures" in catalog, "catalog canonical failure marker missing")
need("public static function transactional_storage_status" in schema, "transactional storage readiness contract missing")
need("'innodb' !== $engine" in schema, "transactional storage does not fail closed on non-InnoDB engine")
need("identity_comparison_policy" in schema, "database identity comparison policy missing")
need("SELECT @@session.in_transaction" in guard, "transaction ownership state probe missing")
need("mad4b_database_nested_transaction_denied" in guard, "nested transaction denial missing")
need("mad4b_database_transaction_commit_uncertain" in guard, "uncertain commit state is not explicit")
need("MAD4B_SCP_Database_Transaction_Guard::begin" in journal, "operation journal still owns raw transaction begin")
need("MAD4B_SCP_Database_Transaction_Guard::commit" in journal, "operation journal still owns raw transaction commit")
need("WHERE BINARY operation_id=BINARY %s" in journal, "operation journal identity lookup is not binary-exact")
need("'database_storage' => $database_storage" in commit_guard, "commit guard does not bind transactional storage")
need("'database_storage', 'kill_switch'" in commit_guard, "commit revalidation does not recheck database storage")
need("class-mad4b-scp-database-transaction-guard.php" in main, "transaction guard is not loaded by plugin bootstrap")

print("mad4b.capability-fabric.bulk-hardening.contract.v1: PASS")

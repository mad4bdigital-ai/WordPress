#!/usr/bin/env python3
"""Static contract for Bit Flows durable execution correlation and fail-closed retry semantics."""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADAPTER = (ROOT / "includes/adapters/class-mad4b-scp-bitflows-adapter.php").read_text(encoding="utf-8")


def require(needle: str, label: str) -> None:
    if needle not in ADAPTER:
        raise SystemExit(f"FAIL {label}: missing {needle!r}")


def forbid(needle: str, label: str) -> None:
    if needle in ADAPTER:
        raise SystemExit(f"FAIL {label}: forbidden {needle!r}")


for marker, label in (
    ("const CORRELATION_CONTRACT = 'mad4b.bitflows-correlation.v1';", "correlation-contract"),
    ("const READBACK_CONTRACT = 'mad4b.bitflows-execution-readback.v1';", "readback-contract"),
    ("const CORRELATION_KEY = '__mad4b_execution';", "reserved-correlation-key"),
    ("'idempotency_key'", "idempotency-input"),
    ("MAD4B_SCP_Durable_Execution::begin_idempotency", "durable-claim"),
    ("MAD4B_SCP_Durable_Execution::complete_idempotency", "durable-completion"),
    ("MAD4B_SCP_Durable_Execution::complete_idempotency_from_reconciliation", "durable-reconciliation-completion"),
    ("array_key_exists( self::CORRELATION_KEY, $trigger_data )", "caller-correlation-key-denial"),
    ("$provider_trigger[ self::CORRELATION_KEY ] = array(", "provider-correlation-injection"),
    ("$executor::execute( $flow, $provider_trigger )", "provider-executes-correlated-trigger"),
    ("self::readback_by_correlation( $id, $correlation_token, $request_sha256 )", "provider-readback"),
    ("1 !== count( $matches )", "correlation-uniqueness"),
    ("mad4b_bitflows_execution_correlation_ambiguous", "ambiguous-correlation-denial"),
    ("hash_equals( $correlation_token", "correlation-token-match"),
    ("hash_equals( $request_sha256", "request-hash-match"),
    ("$history_class::findOne( array( 'id' => $history_id ) )", "flow-history-readback"),
    ("$flow_id !== absint", "flow-history-flow-binding"),
    ("mad4b_bitflows_execution_uncertain", "provider-throw-fail-closed"),
    ("mad4b_bitflows_execution_readback_missing", "missing-readback-fail-closed"),
    ("'retry_safe' => false", "unsafe-retry-evidence"),
    ("public static function verify_durable_reconciliation", "durable-reconciliation-verifier"),
    ("MAD4B_SCP_BitFlows_Adapter::boot();", "durable-hook-boot"),
):
    require(marker, label)

# Provider boolean return must never be promoted to an execution identity.
forbid("'provider_execution_ref' => (string) $executor_result", "executor-result-not-identity")
forbid("'provider_execution_ref' => $executor_result", "executor-result-not-identity")

# Idempotency is mandatory on the governed write surface.
require(
    "array( 'flow_id', 'expected_flow_sha256', 'expected_plan_sha256', 'idempotency_key', 'reason' )",
    "idempotency-required-schema",
)

print("PASS Bit Flows durable execution correlation contract")

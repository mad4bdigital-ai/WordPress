#!/usr/bin/env python3
"""IMP11 fail-closed concurrency, archival and mapping guards (static only)."""
from pathlib import Path
root = Path(__file__).resolve().parents[1]
read = lambda n: (root / "includes" / n).read_text(encoding="utf8")
batch = read("class-mad4b-scp-activity-import-batches.php")
mapping = read("class-mad4b-scp-import-mapping-evolution.php")
atomic = read("class-mad4b-scp-batch-atomic-mutex.php")
profiles = read("class-mad4b-scp-content-experience-profiles.php")
native_batch = (root/"tests"/"imp08-batch-review-runtime.php").read_text(encoding="utf8")
native_map = (root/"tests"/"imp10-mapping-evolution-runtime.php").read_text(encoding="utf8")
preflight = (root/"tests"/"feature007-manual-preflight.py").read_text(encoding="utf8")
def require(ok, msg):
    if not ok: raise AssertionError(msg)
for token in (
    "function locked_mutation(", "'begin', 'append', 'approve', 'archive'",
    "MAD4B_SCP_Batch_Atomic_Mutex::acquire(",
    "MAD4B_SCP_Batch_Atomic_Mutex::release(",
    "mad4b_batch_mutation_locked", "function mutation_status(",
    "automatic_lock_takeover_allowed' => false",
    "function archival_manifest(", "mad4b_batch_archive_manifest_invalid",
    "if ( 'archive' === $operation )", "self::archival_manifest( $slug, $id )",
    "audit_recorded", "archived_after_interrupted_cleanup",
    "mad4b_batch_archival_in_progress"
):
    require(token in batch, "Missing bounded batch safety guard: "+token)
for token in (
    "INSERT IGNORE INTO", "1 !== $inserted",
    "DELETE FROM", "BINARY option_value = BINARY %s",
    "1 !== $deleted", "get_var(", "wp_cache_delete(",
    "'begin', 'append', 'approve', 'archive', 'export'"
):
    require(token in atomic, "Missing real SQL mutex atomicity contract: "+token)
require("add_option( $lock_key" not in batch,
        "WordPress add_option UPSERT must not be trusted as a mutex")
for func in ("begin", "append", "approve", "archive"):
    require("public static function "+func+"(" in batch and
            "private static function "+func+"_unlocked(" in batch,
            "High-impact operation not protected by the Profile mutex: "+func)
require("delete_option( $lock_key )" in batch and
        "delete_option( $names['active'] )" in batch and
        "wp_insert_post(" not in batch and "wp_update_post(" not in batch,
        "Batch mutex altered WordPress business posts or lost cleanup")
for token in (
    "mad4b_mapping_source_identity_migration_denied",
    "mad4b_mapping_wpml_safety_downgrade_denied",
    "mad4b_mapping_required_language_downgrade_denied",
    "mad4b_mapping_relationship_downgrade_denied",
    "mad4b_mapping_period_safety_downgrade_denied",
    "mad4b_mapping_price_tier_safety_downgrade_denied",
    "mad4b_mapping_currency_removal_denied",
    "mad4b_mapping_required_field_removed",
    "alias_index", "candidate_profile_apply_authorized' => false"
):
    require(token in mapping, "Missing safe mapping maturation rejection: "+token)
require("business-activity-import-batch-mutation-status" in profiles,
        "Mutex status is not accessible via governed MCP")
for token in ("mad4b_batch_mutation_locked","mutation_lock_held",
              "imp02_enabled_modes","archived_after_interrupted_cleanup",
              "mad4b_batch_archival_in_progress"):
    require(token in native_batch, "Missing native mutation/archival regression: "+token)
for token in ("externalIdentityChange","weakLanguages","weakColumns","weakCurrency"):
    require(token in native_map, "Missing native mapping regression: "+token)
require("imp11-batch-mapping-source-contract.py" in preflight,
        "Exact-head manual preflight omitted IMP11")
print("PASS IMP11 source mutex, safe policy-drift cleanup and mapping no-downgrade contracts (STATIC ONLY)")

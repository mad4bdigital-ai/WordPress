#!/usr/bin/env python3
"""ETG Admin/MCP storage protocol and central journal source regression; not live authority."""
from pathlib import Path

root = Path(__file__).resolve().parents[4]
etg = root / "wp-content/plugins/etg-dynamic-filter-seo-bridge/includes"
mad = root / "wp-content/plugins/mad4b-site-control-plane/includes"

def source(path):
    text = path.read_text(encoding="utf-8")
    assert text.startswith("<?php"), str(path)
    return text

central = source(mad / "class-mad4b-scp-reversible-adapter-mutations.php")
writer = source(mad / "adapters/class-mad4b-scp-etg-dfsb-write-adapter.php")
gate = source(etg / "Runtime/OptionMutationGate.php")
ajax = source(etg / "Presentation/AjaxPresentationEndpoint.php")
for label, text in (
    ("reversible acquire", "acquire_reversible_scope"),
    ("undo acquire", "acquire_reversible_undo_scope"),
    ("finally release", "release_reversible_scope"),
    ("journal status transition", "if ( 1 !== self::update_record"),
    ("journal recovery", "journal_recovery_error"),
):
    assert text in central, "missing central " + label
for label, text in (
    ("governed journal identity", "current_mutation_context"),
    ("physical shared option lock", "OptionMutationGate::acquire"),
    ("same executor scope for undo", "acquire_reversible_undo_scope"),
    ("exact staged scope", "wp_get_environment_type"),
):
    assert text in writer, "missing writer " + label
assert "$wpdb->delete(" in gate, "lock release must perform an atomic token-constrained SQL delete"
assert "'option_name' => $key, 'option_value' => (string)$lease['token']" in gate
assert "delete_option($key)" not in gate, "get/delete race must not return"
assert "wp_cache_delete(" in gate
assert "error_get_last()" in gate, "fatal mutations must retain locks for reconciliation"
assert "certified_atomic_cache" in ajax and "backend_identity_sha256" in ajax
assert "rate_limit_backend_unavailable" in ajax
for path in (
    etg / "Admin/DynamicContentPage.php",
    etg / "Admin/InventoryControlPage.php",
    etg / "Admin/PublicationPage.php",
    etg / "Admin/OperationalPage.php",
):
    assert "OptionMutationGate::acquire(" in source(path), "ungoverned Admin writer " + str(path)
workflow = (root / ".github/workflows/mad4b-etg-dfsb-adapter.yml").read_text(encoding="utf-8")
assert "etg-option-lock-concurrency-worker.php" in workflow
assert "REAL_DB_CONCURRENT_ETG_MUTEX" in workflow
print("PASS ETG shared journal, Admin/MCP mutex, conditional SQL release and live CI wiring")

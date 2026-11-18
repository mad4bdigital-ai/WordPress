#!/usr/bin/env python3
"""Offline exact-version + guardrail check for native Form #6 rate/stay assets."""
from pathlib import Path
import hashlib
import json

root = Path(__file__).resolve().parents[3]
manifest = json.loads((Path(__file__).parent / "manifest.json").read_text())
assert manifest["contract"] == "mad4b.site-runtime.form6-native-rate-stay-parity.v1"
assert manifest["deployment_allowed_by_this_manifest"] is False
expected = manifest["files"]
assert len(expected) == 3, "Only three reviewed live assets may be shipped"
assert set(manifest["canonical_runtime_relative_paths"]) == set(expected)
for rel, digest in expected.items():
    assert rel.startswith("wp-content/mu-plugins/") and ".." not in Path(rel).parts
    actual = hashlib.sha256((root / rel).read_bytes()).hexdigest()
    assert actual == digest, f"RUNTIME_SOURCE_SHA_MISMATCH: {rel}"

options = (root / "wp-content/mu-plugins/listing-option-selection-assets/options.js").read_text()
quote = (root / "wp-content/mu-plugins/shared-product-core/assets/presentation-fluent-quote-v1.js").read_text()
css = (root / "wp-content/mu-plugins/shared-product-core/assets/presentation-pricing-basis-v1.css").read_text()

for part in (
    "function soleProperty", "function reconcileFromBroker",
    "function autoSelectSoleStages", "SharedPresentationSelectionV1",
    "booking-option-selection-change", "sole_property_rate_selection",
    "is-fixed-choice", "aria-checked"
):
    assert part in options, f"SINGLE_STAY_SELECTION_REGRESSION: {part}"
for part in (
    "renderOriginalPriceChoices", "nativeTierAvailable",
    "SharedPresentationSelectionV1", "shared-stay-source-selection",
    "sole_property_form_projection", "state.occupancy.value=''",
    "setVisible(row,values.length!==1)", "setVisible(unitLabel",
    "setVisible(boardLabel", "setVisible(roomsLabel",
    "stayOptionsEndpoint", "Number of rooms"
):
    assert part in quote, f"FORM_RATE_DYNAMIC_OPTIONS_REGRESSION: {part}"
for part in (
    ".shared-stay-source-selection,.shared-stay-row,.shared-stay-field",
    ")[hidden] {display:none !important;}",
    ".mad4b-native-tier-choice[aria-pressed=",
    ".mad4b-native-tier-choice:focus-visible"
):
    assert part in css, f"FORM_OPTION_VISIBILITY_REGRESSION: {part}"
assert all(Path(p).suffix in {".js", ".css"} for p in expected)
print("FORM6_NATIVE_STAY_SOURCE_CONTRACT_PASS", len(expected), "sha256-pinned assets")

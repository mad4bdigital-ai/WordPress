#!/usr/bin/env python3
"""Review-only DFSB current signed source pin and historical RC15 evidence, never install authorization."""
import json
from pathlib import Path

ROOT=Path(__file__).resolve().parents[4]
reg=json.loads((ROOT/"wp-content/plugins/mad4b-site-control-plane/config/cross-plugin-sources.json").read_text())
sources=reg["sources"]
assert reg["default_execution"]=="deny_until_exact_artifact_certified"
assert len(sources)==2 and len({s["id"] for s in sources})==2
legacy=next(s for s in sources if s["id"]=="etg-dynamic-filter-seo-bridge")
neutral=next(s for s in sources if s["id"]=="dynamic-filter-seo-bridge")
assert legacy["candidate_source_commit_sha"]=="76ef918d8485c42e92dd38b3725efd4de5471556"
assert legacy["candidate_artifact_version"]=="0.4.0-alpha.13"
assert legacy["provider_id"]=="etg-dynamic-filter-seo-bridge"
assert neutral["provider_id"]=="dynamic-filter-seo-bridge"
assert neutral["plugin_file"]=="dynamic-filter-seo-bridge/dynamic-filter-seo-bridge.php"
assert neutral["candidate_source_commit_sha"]=="2b76e2dfd11403c64f08e1a77f2c76620c1c47b0"
assert neutral["candidate_artifact_version"]=="0.4.16"
assert neutral["build_contract"]=="dfsb-release-review.v1"
assert neutral["candidate_archive_sha256"]=="39fd4231202bc33a3c9ebb3737743d47d8f366b09bcf66c307c703fa29cd3864"
assert neutral["candidate_review_run_id"]==45732288119
assert neutral["candidate_review_artifact_id"]==15077461477
assert neutral["candidate_state"]=="REVIEW_ONLY_SOURCE_PINNED"
assert neutral["review_state"]=="source_reviewed_artifact_pending"
assert neutral["package_certification"]=="pending"
assert all(neutral[x]=="" for x in
   ("source_commit_sha","artifact_sha256","artifact_version","production_promotion_attestation"))
assert sorted(neutral["environments"])==["production","staging"]
assert "release" not in neutral or neutral["release"] in ("",None)
provider=json.loads((ROOT/"wp-content/plugins/mad4b-site-control-plane/config/certified-providers.json").read_text())
assert "dynamic-filter-seo-bridge" not in provider.get("providers", {})
doc=(ROOT/"docs/dfsb/rc15-signed-review-intake-20261029.md").read_text()
for token in ("40826819043","bb7ab6bf995f477d075198e766ba7b41954a0b3f0eb5fb4bef906c5ee73d9b66",
  "provider_certified=false","package_certification: pending","Staging"):
 assert token in doc,token
print("DFSB_SIGNED_REVIEW_SOURCE_PIN_PENDING_AND_LEGACY_ISOLATED: PASS")

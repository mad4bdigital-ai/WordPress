#!/usr/bin/env python3
"""IMP13 negative source-only checks, not native PHP or Staging certification."""
from pathlib import Path
P=Path(__file__).resolve().parents[1]
inc=lambda n:(P/"includes"/n).read_text(encoding="utf8")
tst=lambda n:(P/"tests"/n).read_text(encoding="utf8")
batch=inc("class-mad4b-scp-activity-import-batches.php")
review=inc("class-mad4b-scp-activity-import-review.php")
ux=inc("class-mad4b-scp-activity-import-experience.php")
fixture=tst("imp13-commercial-source-safety-runtime.php")
old=tst("imp08-batch-review-runtime.php")
pre=tst("feature007-manual-preflight.py")
def require(ok,why):
    if not ok: raise AssertionError(why)
for token in (
    "mad4b_batch_archival_in_progress",
    "Only archive_unlocked may continue audited cleanup",
    "self::archival_manifest( $slug, $id )",
    "function locked_mutation(",
):
    require(token in batch,"Archived batch could be resurrected: "+token)
require("mad4b_batch_archival_in_progress" in old and
        "archiving->get_error_code()" in old,
        "Missing archival/racing append negative fixture")
for token in ("serialized_source_payload",
              "serialized_relation_requires_certified_driver",
              "preg_match( '/^(?:a|O|C):[0-9]+:/D'"):
    require(token in review,"Source commercial serialized data not blocked: "+token)
for token in ("ERU", "puplished", "related_properties_id",
              "serialized_relation_requires_certified_driver",
              "MAD4B_SCP_Activity_Import_Snapshot::approve"):
    require(token in fixture,"Commercial source fixture incomplete: "+token)
require("No Content Experience Profile is configured yet" in ux and
        "MAD4B_SCP_Import_Schema_Onboarding::plan( array() )" in ux,
        "Zero-Profile Staging wizard still a dead-end")
for name in ("imp13-commercial-source-safety-runtime.php",
             "imp13-archival-and-commercial-source-contract.py"):
    require(name in pre,"Manual exact-head preflight missing "+name)
require("unserialize(" not in review and
        "wp_insert_post(" not in batch,
        "Source review must not deserialize untrusted PHP or write business posts")
print("PASS IMP13 no source resurrection, commercial serialized blocker and first-run UX (STATIC ONLY)")

#!/usr/bin/env python3
"""Review and apply *context-only* call-site changes to existing Shared Product Core.

No pricing formulas, Last Minute adjustment, booking configuration, currency,
customer data, access policies, or plugin activation are modified.

Dry-run by default. --apply additionally requires --backup-dir and checks all
file snapshots before any write. Never copy Booking v2 between companies.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import sys
import tempfile

PATCHES = {
    "pricing/strategy-registry.php": [
        (
            "function shared_product_strategy_policy($mode){\n    $policies=shared_product_mode_policies();",
            "function shared_product_strategy_policy($mode,$cache_context=[]){\n    $policies=shared_product_mode_policies($cache_context);",
        ),
        (
            "$policy=shared_product_strategy_policy($context['tour_mode']);",
            "$policy=shared_product_strategy_policy($context['tour_mode'],$context);",
        ),
    ],
    "pricing/financial-inheritance.php": [
        (
            "$mode=$context['tour_mode'];$rows=shared_product_mode_policies();",
            "$mode=$context['tour_mode'];$rows=shared_product_mode_policies($context);",
        ),
    ],
    "pricing/booking-configuration-v2.php": [
        (
            "shared_product_price_source_policy((string)get_post_meta($c['tour_id'],'tour_mode',true));",
            "shared_product_price_source_policy((string)get_post_meta($c['tour_id'],'tour_mode',true),['tour_id'=>(int)$c['tour_id']]);",
        ),
        (
            "shared_product_resolve_price_sources(['direct'=>['amount'=>$extended['amount'],'currency'=>$source_currency]],(string)get_post_meta($c['tour_id'],'tour_mode',true));",
            "shared_product_resolve_price_sources(['direct'=>['amount'=>$extended['amount'],'currency'=>$source_currency]],(string)get_post_meta($c['tour_id'],'tour_mode',true),['tour_id'=>(int)$c['tour_id']]);",
        ),
    ],
    "pricing/rules-evaluators-v2.php": [
        (
            "$policy=shared_product_price_source_policy($mode);",
            "$policy=shared_product_price_source_policy($mode,['tour_id'=>(int)$c['tour_id'],'rate_id'=>($c['source']==='rate'?(int)($c['rate_id']??0):0)]);",
        ),
    ],
    "pricing/extra-source-quote.php": [
        (
            "shared_product_resolve_price_sources($candidates,(string)get_post_meta($tour,'tour_mode',true));",
            "shared_product_resolve_price_sources($candidates,(string)get_post_meta($tour,'tour_mode',true),['tour_id'=>(int)$tour,'rate_id'=>(int)($best['rate_id']??0)]);",
        ),
    ],
    "pricing/rate-planner.php": [
        (
            "shared_product_resolve_price_sources($candidates,$mode):new WP_Error",
            "shared_product_resolve_price_sources($candidates,$mode,['tour_id'=>(int)$tour,'rate_id'=>(int)($rate['id']??0)]):new WP_Error",
        ),
    ],
    "adapters/booking-preview-bridge.php": [
        (
            "shared_product_resolve_price_sources($candidates,(string)get_post_meta($eid,'tour_mode',true));",
            "shared_product_resolve_price_sources($candidates,(string)get_post_meta($eid,'tour_mode',true),['tour_id'=>(int)$eid,'rate_id'=>(int)($erate??0)]);",
        ),
    ],
    "adapters/cpt-product-price-compat.php": [
        (
            "shared_product_resolve_price_sources($candidates,(string)get_post_meta($id,'tour_mode',true));",
            "shared_product_resolve_price_sources($candidates,(string)get_post_meta($id,'tour_mode',true),['tour_id'=>(int)$id]);",
        ),
    ],
    "adapters/extra-selection-actions.php": [
        (
            "shared_product_price_source_policy((string)get_post_meta($tour,'tour_mode',true))",
            "shared_product_price_source_policy((string)get_post_meta($tour,'tour_mode',true),['tour_id'=>(int)$tour,'rate_id'=>(int)($line['rate_id']??0)])",
        ),
    ],
}

PREFIX = Path("mu-plugins/shared-product-core")


def transform(relative: str, original: str):
    """Accept exactly one original match, or an already-applied exact match."""
    if relative not in PATCHES:
        raise ValueError("unknown fixed Shared Product Core module")
    updated = original
    applied = 0
    for old, new in PATCHES[relative]:
        if updated.count(old) == 1 and updated.count(new) == 0:
            updated = updated.replace(old, new, 1)
            applied += 1
        elif updated.count(old) == 0 and updated.count(new) == 1:
            continue
        else:
            raise ValueError("context patch anchor changed or duplicated: " + relative)
    return updated, applied


def plan(wp_content: Path):
    if not wp_content.is_dir() or wp_content.name != "wp-content":
        raise ValueError("expected an existing WordPress wp-content root")
    base = wp_content / PREFIX
    results = {}
    for relative in PATCHES:
        path = base / relative
        if path.is_symlink() or not path.is_file():
            raise ValueError("unsupported site module or symlink: " + relative)
        original = path.read_bytes()
        try:
            old = original.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise ValueError("non-UTF8 module: " + relative) from exc
        updated, count = transform(relative, old)
        results[relative] = {
            "path": path,
            "before": original,
            "after": updated.encode("utf-8"),
            "replacements": count,
            "sha256_before": hashlib.sha256(original).hexdigest(),
            "sha256_after": hashlib.sha256(updated.encode("utf-8")).hexdigest(),
        }
    return results


def promote(results, backup_dir: Path):
    if backup_dir.exists() and any(backup_dir.iterdir()):
        raise ValueError("backup directory must be new and empty")
    backup_dir.mkdir(parents=True, exist_ok=True)
    # Preflight all inputs before any write. Abort if site is changing.
    for record in results.values():
        if record["path"].read_bytes() != record["before"]:
            raise ValueError("concurrent site change: " + record["path"].name)
    for relative, record in results.items():
        target = backup_dir / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(record["before"])
        os.chmod(target, 0o600)
    written = []
    try:
        for relative, record in results.items():
            if not record["replacements"]:
                continue
            path = record["path"]
            if path.read_bytes() != record["before"]:
                raise ValueError("changed during release: " + path.name)
            st = path.stat()
            fd, temp = tempfile.mkstemp(prefix=".mad4b-context-release-", dir=path.parent)
            try:
                with os.fdopen(fd, "wb") as out:
                    out.write(record["after"])
                    out.flush()
                    os.fsync(out.fileno())
                os.chmod(temp, stat.S_IMODE(st.st_mode))
                if os.geteuid() == 0:
                    os.chown(temp, st.st_uid, st.st_gid)
                os.replace(temp, path)
            finally:
                if os.path.exists(temp):
                    os.unlink(temp)
            written.append(relative)
    except Exception:
        # Restore the original exact bytes for every file this operation wrote.
        for relative in reversed(written):
            record = results[relative]
            path = record["path"]
            st = path.stat()
            fd, temp = tempfile.mkstemp(prefix=".mad4b-context-rollback-", dir=path.parent)
            with os.fdopen(fd, "wb") as out:
                out.write(record["before"])
            os.chmod(temp, stat.S_IMODE(st.st_mode))
            if os.geteuid() == 0:
                os.chown(temp, st.st_uid, st.st_gid)
            os.replace(temp, path)
        raise
    return written


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--wp-content", type=Path, required=True)
    p.add_argument("--backup-dir", type=Path)
    p.add_argument("--apply", action="store_true", help="explicitly write exact reviewed call-site diffs")
    args = p.parse_args()
    if args.apply and args.backup_dir is None:
        p.error("--apply requires --backup-dir")
    results = plan(args.wp_content)
    written = promote(results, args.backup_dir) if args.apply else []
    print(json.dumps({
        "contract": "mad4b.pricing-cache-callsite-context.v1",
        "mode": "applied" if args.apply else "dry-run",
        "files": len(results),
        "replacements_needed": sum(r["replacements"] for r in results.values()),
        "written": len(written),
        "booking_file_preserved": True,
        "changes": {name: {
            "replacements": r["replacements"],
            "before": r["sha256_before"],
            "after": r["sha256_after"],
        } for name, r in results.items()},
    }, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())

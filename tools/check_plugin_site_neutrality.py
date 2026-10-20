#!/usr/bin/env python3
"""Reject tenant URLs and business defaults in reusable runtime; no live requests."""
import argparse
import json
from pathlib import Path
import re
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "wp-content/plugins/mad4b-site-control-plane"
POLICY = PLUGIN / "tests/site-neutrality-policy.json"
URL = re.compile(r"https?://[A-Za-z0-9._-]+")
TENANT = re.compile(r"egypttourgates\.com|allroyalegypt\.com", re.I)
BUSINESS = re.compile(r"""['"](?:/?tours-and-activities/?|tour_type|tour_location|tour_style)['"]""")
NAMED_TAG = re.compile(r"""['"]etg-(?:filter-[a-z_-]+|dynamic-content-slot)['"]""")


def inspect(path, text, policy):
    errors = []
    if TENANT.search(text):
        errors.append("TENANT_DOMAIN")
    if BUSINESS.search(text):
        errors.append("FIXED_BUSINESS_MODEL")
    if "ETG_DFSB_EXTERNAL_WAF_RATE_LIMIT_VERIFIED" in text:
        errors.append("SITE_SPECIFIC_CORE_AUTHORITY")
    allowed = policy["reviewed_literal_endpoints"].get(path, {}).get("hosts", [])
    for url in URL.findall(text):
        host = urllib.parse.urlsplit(url).hostname
        if host not in allowed:
            errors.append("UNREVIEWED_LITERAL_HOST:" + str(host))
    if NAMED_TAG.search(text):
        if path not in policy["optional_named_tag_integrations"]:
            errors.append("NAMED_TAG_IN_GENERIC_RUNTIME")
        elif "ETG_DFSB_VERSION" not in text or "self::available()" not in text:
            errors.append("OPTIONAL_INTEGRATION_NOT_GATED")
    return sorted(set(errors))


def self_test(policy):
    generic = "includes/class-mad4b-scp-site-profile.php"
    integration = policy["optional_named_tag_integrations"][0]
    cases = [
        (generic, "'https://unrelated-new-client.biz'", "UNREVIEWED_LITERAL_HOST"),
        (generic, "'https://staging.egypttourgates.com'", "TENANT_DOMAIN"),
        (integration, "'https://staging.allroyalegypt.com'", "TENANT_DOMAIN"),
        (generic, "'tours-and-activities'", "FIXED_BUSINESS_MODEL"),
        (generic, "'tour_type'", "FIXED_BUSINESS_MODEL"),
        (generic, "'etg-filter-title'", "NAMED_TAG_IN_GENERIC_RUNTIME"),
        (generic, "ETG_DFSB_EXTERNAL_WAF_RATE_LIMIT_VERIFIED", "SITE_SPECIFIC_CORE_AUTHORITY"),
        (integration, "'etg-filter-title'", "OPTIONAL_INTEGRATION_NOT_GATED"),
    ]
    for path, source, expected in cases:
        assert any(e.startswith(expected) for e in inspect(path, source, policy)), expected
    path = next(iter(policy["reviewed_literal_endpoints"]))
    assert inspect(path, "'https://new-tenant.biz'", policy)
    assert not inspect(generic, "get_post_types(); home_url(); 'product-price';", policy)
    print("SITE_NEUTRALITY_SELF_TEST: PASS (tenant, unknown host, business model, optional boundary)")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    policy = json.loads(POLICY.read_text())
    assert policy["contract"] == "mad4b.site-neutrality-policy.v1" and policy["authorizing"] is False
    if args.self_test:
        self_test(policy)
    failures, scanned = [], 0
    for folder in ("includes", "config", "assets"):
        for path in sorted((PLUGIN / folder).rglob("*")):
            if path.suffix not in (".php", ".json", ".js", ".css"):
                continue
            scanned += 1
            relative = path.relative_to(PLUGIN).as_posix()
            failures += [relative + ":" + e for e in inspect(relative, path.read_text(errors="replace"), policy)]
    if failures:
        raise SystemExit("\n".join(failures))
    print(f"SITE_NEUTRALITY: PASS ({scanned} runtime files; explicit endpoint inventory; no tenant defaults)")


if __name__ == "__main__":
    main()

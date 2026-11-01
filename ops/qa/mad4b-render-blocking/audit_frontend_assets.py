#!/usr/bin/env python3
"""Read-only first-render asset inventory. No CSS reordering, no remote mutation."""
from __future__ import annotations
import argparse
import json
from collections import Counter
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlsplit


class AssetParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.head = False
        self.styles = []
        self.scripts = []
        self.inline_style_count = 0
        self.inline_script_count = 0
        self.external_css = []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "head":
            self.head = True
        if not self.head:
            return
        if tag == "style":
            self.inline_style_count += 1
        elif tag == "link" and "stylesheet" in (a.get("rel") or "").split():
            href = a.get("href", "")
            parsed = urlsplit(href)
            self.styles.append({
                "handle": a.get("id", ""),
                "host": parsed.hostname or "",
                "path": parsed.path,
                "media": a.get("media", "all"),
                "optimized": bool(a.get("data-optimized")),
                "blocking": a.get("media", "all") in ("all", "screen", ""),
            })
        elif tag == "script":
            if not a.get("src"):
                self.inline_script_count += 1
                return
            src = urlsplit(a["src"])
            self.scripts.append({
                "handle": a.get("id", ""),
                "host": src.hostname or "",
                "path": src.path,
                "async": "async" in a,
                "defer": "defer" in a,
                "module": a.get("type") == "module",
            })

    def handle_endtag(self, tag):
        if tag == "head":
            self.head = False


def audit(html: str, host: str) -> dict:
    parser = AssetParser()
    parser.feed(html)
    css = parser.styles
    scripts = parser.scripts
    by_origin = Counter(x["host"] for x in css)
    ls = [x for x in css if x["host"] == host and "/wp-content/litespeed/css/" in x["path"]]
    blocking = [x for x in css if x["blocking"]]
    return {
        "schema": "mad4b.frontend.asset-audit.v1",
        "target_host": host,
        "html_bytes": len(html.encode("utf-8")),
        "stylesheet_count": len(css),
        "render_blocking_stylesheet_count": len(blocking),
        "litespeed_separate_css_count": len(ls),
        "head_script_count": len(scripts),
        "head_scripts_not_deferred_count": sum(not (x["defer"] or x["async"] or x["module"]) for x in scripts),
        "inline_style_tag_count": parser.inline_style_count,
        "inline_script_tag_count": parser.inline_script_count,
        "stylesheet_hosts": dict(sorted(by_origin.items())),
        "stylesheet_handles_sample": [x["handle"] for x in css[:24]],
        "cache_combination_policy": "REPORT_ONLY_NOT_VALIDATED",
        "warnings": [
            {
                "code": "EXCESSIVE_RENDER_BLOCKING_STYLES",
                "count": len(blocking),
                "threshold": 30,
                "action": "Trial LiteSpeed CSS combine in isolated browser-supported canary; verify all six WPML locales and List/Grid first-paint parity before enabling.",
            }
        ] if len(blocking) > 30 else [],
    }


def main() -> None:
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument("--html", type=Path, required=True, help="Saved HTML response")
    cli.add_argument("--host", default="tours.dev.mad4b.com")
    cli.add_argument("--output", type=Path)
    args = cli.parse_args()
    result = audit(args.html.read_text(encoding="utf-8", errors="replace"), args.host)
    text = json.dumps(result, indent=2, ensure_ascii=False) + "\n"
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(text, encoding="utf-8")
    else:
        print(text, end="")


if __name__ == "__main__":
    main()

#!/usr/bin/env python3
"""Fail-closed guard for Elementor demo copy changes.

Only text values in existing, unchanged Elementor widgets can change. All node
identity, structure, settings, layout, responsive, dynamic bindings, source
widgets, and child order must remain identical. This script never writes files.

Usage: python3 scripts/elementor_demo_content_guard.py --before original.json --after candidate.json
"""
from __future__ import annotations

import argparse
import json
import sys
from html.parser import HTMLParser
from pathlib import Path
from typing import Any

ALLOWED = {
    "heading": frozenset({"title"}),
    "text-editor": frozenset({"editor"}),
    "button": frozenset({"text"}),
    "icon-box": frozenset({"title", "description"}),
    "testimonial": frozenset({"testimonial_content", "testimonial_name", "testimonial_job"}),
    "image-box": frozenset({"title", "description"}),
}

class ContentOnlyViolation(ValueError):
    """The proposed edit would change something other than authorized copy."""

class _HTMLShape(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=False)
        self.events: list[Any] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.events.append(("start", tag, tuple(attrs)))

    def handle_endtag(self, tag: str) -> None:
        self.events.append(("end", tag))

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.events.append(("startend", tag, tuple(attrs)))

    def handle_entityref(self, name: str) -> None:
        self.events.append(("entity", name))

    def handle_charref(self, name: str) -> None:
        self.events.append(("charref", name))


def _html_shape(source: str) -> list[Any]:
    parser = _HTMLShape()
    parser.feed(source)
    parser.close()
    return parser.events


def _assert_copy_only(before: dict[str, Any], after: dict[str, Any], path: str) -> int:
    if not isinstance(before, dict) or not isinstance(after, dict):
        raise ContentOnlyViolation(f"{path}: Elementor node must remain an object")
    if before.keys() != after.keys():
        raise ContentOnlyViolation(f"{path}: Elementor node properties were added or removed")
    for key in before:
        if key not in ("elements", "settings") and before[key] != after[key]:
            raise ContentOnlyViolation(f"{path}: structural node property changed: {key}")
    children_before, children_after = before.get("elements", []), after.get("elements", [])
    if not isinstance(children_before, list) or not isinstance(children_after, list) or len(children_before) != len(children_after):
        raise ContentOnlyViolation(f"{path}: child elements/count/order changed")
    source_settings, target_settings = before.get("settings", {}), after.get("settings", {})
    if not isinstance(source_settings, dict) or not isinstance(target_settings, dict) or source_settings.keys() != target_settings.keys():
        raise ContentOnlyViolation(f"{path}: widget settings schema changed")
    node_type = before.get("widgetType")
    updated = 0
    for key, value in source_settings.items():
        new_value = target_settings[key]
        if value == new_value:
            continue
        if key not in ALLOWED.get(node_type, frozenset()):
            raise ContentOnlyViolation(f"{path}: nontext settings changed: {key}")
        if not isinstance(value, str) or not isinstance(new_value, str):
            raise ContentOnlyViolation(f"{path}: copy must remain a string: {key}")
        if "[elementor-tag" in value or "[elementor-tag" in new_value:
            raise ContentOnlyViolation(f"{path}: dynamic source binding cannot be rewritten")
        if _html_shape(value) != _html_shape(new_value):
            raise ContentOnlyViolation(f"{path}: HTML markup/attributes changed: {key}")
        updated += 1
    for index, (old_child, new_child) in enumerate(zip(children_before, children_after)):
        updated += _assert_copy_only(old_child, new_child, f"{path}/elements[{index}]")
    return updated


def validate_content_only(original: Any, candidate: Any) -> int:
    if not isinstance(original, list) or not isinstance(candidate, list) or len(original) != len(candidate):
        raise ContentOnlyViolation("root: Elementor document node list changed")
    edited = 0
    for index, (before, after) in enumerate(zip(original, candidate)):
        edited += _assert_copy_only(before, after, f"root[{index}]")
    if not edited:
        raise ContentOnlyViolation("No content-only edits found")
    return edited


def main() -> int:
    parser = argparse.ArgumentParser(description="Verify Elementor demo placeholder edits do not change any design")
    parser.add_argument("--before", type=Path, required=True)
    parser.add_argument("--after", type=Path, required=True)
    args = parser.parse_args()
    try:
        edits = validate_content_only(
            json.loads(args.before.read_text(encoding="utf8")),
            json.loads(args.after.read_text(encoding="utf8")),
        )
    except (ValueError, OSError, json.JSONDecodeError) as error:
        print(f"BLOCKED: {error}", file=sys.stderr)
        return 2
    print(f"PASS: {edits} textual widget setting(s) changed; Elementor document design unchanged")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Conservative, read-only Elementor template portability audit.

Inputs: existing Elementor exported JSON (one export file or a directory).
Outputs: JSON with field-path categories, never values, tenant names or secrets.

No API, DB, WordPress, filesystem changes, or imports.
"""
import argparse
import json
from urllib.parse import unquote
from collections import Counter
from pathlib import Path
import re
import sys

HEX_COLOR = re.compile(r"(?<![A-Za-z0-9])#[0-9a-fA-F]{3,8}\b")
CSS_COLOR = re.compile(r"(?:rgb|rgba|hsl|hsla)\s*\(", re.I)
URL = re.compile(r"https?://|(?:\/\/)[\w.-]+\.[A-Za-z]{2,}", re.I)
EMAIL = re.compile(r"\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b")
GLOBAL = re.compile(r"(?:globals/(?:colors|typography)|--e-global-|__globals__)", re.I)

BRAND_KEYS = re.compile(
    r"(?:^|_)(?:site_?logo|custom_?logo|logo|favicon|brand|company_?name|"
    r"tagline|social_?profile|site_?identity|primary_?color|secondary_?color|"
    r"global_?color|color_?palette|global_?font|font_?family|custom_?font)(?:$|_)",
    re.I,
)
DESIGN_KEYS = re.compile(
    r"(^|_)(?:color|background_color|text_color|border_color|"
    r"typography_font_family|font_family|gradient|custom_css)(?:$|_)", re.I
)
SITE_KEYS = re.compile(
    r"(?:^|_)(?:image|attachment|media|form_id|popup_id|template_id|post_id|"
    r"term_id|query_id|menu_id|dynamic_tags|link|url|email|recipient|webhook|"
    r"integrations?|api_key|oauth|token|secret)(?:$|_)", re.I,
)
REFERENCE_VALUE = re.compile(r"(?:\[\w+\s+id=['\"]\d+['\"]|\bwp-image-\d+\b)", re.I)

def scan(obj, path="$", findings=None):
    if findings is None:
        findings=[]
    if isinstance(obj, dict):
        for key,value in obj.items():
            label=str(key)
            sub=path+"."+label.replace("\n","_")[:100]
            if label in ("__globals__", "elementor_active_kit", "theme_mods_astra",
                         "elementor_page_settings", "site_settings"):
                findings.append({"path":sub,"reason":"tenant_design_reference"})
            elif BRAND_KEYS.search(label):
                findings.append({"path":sub,"reason":"brand_identity_key"})
            elif SITE_KEYS.search(label):
                findings.append({"path":sub,"reason":"site_bound_key"})
            elif DESIGN_KEYS.search(label):
                # Static style settings are local until verified as target Kit references.
                if value not in (None,"",{}):
                    findings.append({"path":sub,"reason":"style_requires_target_kit_binding"})
            scan(value,sub,findings)
    elif isinstance(obj, list):
        for i,value in enumerate(obj):
            scan(value,f"{path}[{i}]",findings)
    elif isinstance(obj,str):
        text=obj[:30000]
        if HEX_COLOR.search(text) or CSS_COLOR.search(text):
            findings.append({"path":path,"reason":"hardcoded_color"})
        if GLOBAL.search(text):
            findings.append({"path":path,"reason":"source_global_token_requires_remapping"})
        if URL.search(text) or EMAIL.search(text):
            findings.append({"path":path,"reason":"external_or_identity_reference"})
        if REFERENCE_VALUE.search(text):
            findings.append({"path":path,"reason":"source_numeric_reference"})
        if "font-family:" in text.casefold():
            findings.append({"path":path,"reason":"hardcoded_typography"})
    return findings


# The layout shell is reusable; these are data bindings, not layout components.
# Deliberately never emit original image URLs, query IDs, business identity or tokens.
def dynamic_bindings(data):
    records = []
    def visit(value, path="$", depth=0):
        if depth > 32 or not isinstance(value, (list, dict)):
            return
        if isinstance(value, list):
            for i, sub in enumerate(value):
                visit(sub, f"{path}[{i}]", depth+1)
            return
        kind = str(value.get("widgetType", ""))
        if isinstance(value.get("settings"), dict):
            settings = value["settings"]
            tags = settings.get("__dynamic__", {})
            if isinstance(tags, dict):
                for target_field, tag in tags.items():
                    raw = str(tag)
                    tag_match = re.search(r'name\s*=\s*["\x27]([a-z0-9_-]+)["\x27]',raw,re.I)
                    tag_name = tag_match.group(1) if tag_match else "unknown_dynamic_tag"
                    key_hint = None
                    encoded = re.search(r'settings\s*=\s*["\x27]([^"\x27]+)["\x27]', raw, re.I)
                    if encoded:
                        try:
                            options = json.loads(unquote(encoded.group(1)))
                            candidate = options.get("option_field") if isinstance(options,dict) else None
                            if isinstance(candidate,str) and re.fullmatch(r"[A-Za-z0-9_.:/-]{1,128}",candidate):
                                key_hint = candidate
                        except (json.JSONDecodeError, ValueError):
                            pass
                    records.append({
                        "path":f"{path}.settings.__dynamic__.{target_field}",
                        "widget":kind,
                        "kind":"tenant_dynamic_source",
                        "provider":tag_name,
                        "tenant_option_field_key":key_hint,
                        "migration":"preserve_target_own_binding_or_remap_before_publication",
                        "copy_source_data":False
                    })
            for field in ("wp_gallery","gallery","image_gallery","gallery_images","images","image_list"):
                if field in settings and settings[field] not in (None,"",[],{}):
                    records.append({
                        "path":f"{path}.settings.{field}",
                        "widget":kind,
                        "kind":"tenant_gallery_media",
                        "migration":"use_target_media_library_only",
                        "copy_source_data":False
                    })
            if kind in ("jet-listing-grid","jet-engine-listing-grid"):
                for field in ("listing_id","lisitng_id","custom_query_id","custom_query","query_id","posts_query"):
                    if field in settings and settings[field] not in (None,"",[],{}):
                        records.append({
                            "path":f"{path}.settings.{field}",
                            "widget":kind,
                            "kind":"tenant_listing_or_query_reference",
                            "migration":"resolve_target_owned_listing_query_and_content",
                            "copy_source_data":False
                        })
        for key, sub in value.items():
            if isinstance(sub, (dict,list)):
                visit(sub, f"{path}.{str(key)[:80]}", depth+1)
    visit(data)
    return records

def audit(path,strict=False):
    if not path.exists() or path.is_symlink() or not path.is_file():
        raise ValueError("Input must be an existing regular JSON file")
    if path.stat().st_size > 20*1024*1024:
        raise ValueError("Refuse JSON over 20 MiB without separate inspection")
    data=json.loads(path.read_text(encoding="utf-8"))
    findings=scan(data)
    tenant_dynamic=dynamic_bindings(data)
    # Output includes only internal path keys; never any settings values.
    return {
        "contract":"mad4b.elementor-template-portability-audit.v1",
        "file":path.name,
        "status":"REVIEW_REQUIRED" if (findings or tenant_dynamic) else "STRUCTURAL_CANDIDATE_ONLY",
        "safe_to_import_automatically":False,
        "template_structure_portable_after_review":True,
        "dynamic_data_must_be_tenant_owned":True,
        "tenant_dynamic_binding_count":len(tenant_dynamic),
        "tenant_dynamic_bindings":tenant_dynamic[:250],
        "source_brand_values_exported":False,
        "reasons":dict(Counter(r["reason"] for r in findings)),
        "findings":findings[:500],
        "total_findings":len(findings),
        "truncated":len(findings)>500 or len(tenant_dynamic)>250
    }

def main():
    p=argparse.ArgumentParser()
    p.add_argument("path",type=Path)
    p.add_argument("--strict",action="store_true",help="Fail if any tenant-bound fields or missing review remain")
    p.add_argument("--summary-only",action="store_true",help="Omit paths, display counts only")
    args=p.parse_args()
    paths = sorted(args.path.glob("*.json")) if args.path.is_dir() else [args.path]
    if not paths: raise ValueError("No JSON exports found")
    reports=[]
    for path in paths:
        entry=audit(path)
        if args.summary_only:
            entry.pop("findings",None)
            entry.pop("tenant_dynamic_bindings",None)
        reports.append(entry)
    print(json.dumps({"reports":reports, "automatic_import_allowed":False},
                     ensure_ascii=False, indent=2))
    if args.strict and any(x["total_findings"] or x["tenant_dynamic_binding_count"] for x in reports):
        return 2
    return 0

if __name__=="__main__":
    try:
        sys.exit(main())
    except (ValueError, OSError, json.JSONDecodeError) as exc:
        print("Template audit failed closed: "+str(exc),file=sys.stderr)
        sys.exit(3)

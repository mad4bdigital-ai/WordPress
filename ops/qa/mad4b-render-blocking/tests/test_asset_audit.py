import importlib.util
from pathlib import Path

source = Path(__file__).resolve().parents[1] / "audit_frontend_assets.py"
spec = importlib.util.spec_from_file_location("asset_audit", source)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

markup = """<html><head>
<link rel="stylesheet" id="main-css" href="https://tours.dev.mad4b.com/wp-content/litespeed/css/a.css" />
<link rel="stylesheet" id="mobile-css" media="print" href="/wp-content/litespeed/css/b.css" />
<style>.price{color:blue}</style>
<script src="/wp-content/litespeed/js/one.js" defer></script>
<script src="/js/blocked.js"></script>
</head><body><link rel="stylesheet" href="/late.css"></body></html>"""
summary = module.audit(markup, "tours.dev.mad4b.com")
assert summary["stylesheet_count"] == 2
assert summary["render_blocking_stylesheet_count"] == 1
assert summary["litespeed_separate_css_count"] == 1
assert summary["head_script_count"] == 2
assert summary["head_scripts_not_deferred_count"] == 1
assert summary["inline_style_tag_count"] == 1
assert summary["cache_combination_policy"] == "REPORT_ONLY_NOT_VALIDATED"
assert summary["stylesheet_handles_sample"] == ["main-css", "mobile-css"]
print("PASS: first-render stylesheet/script audit, late assets excluded and optimization not silently activated")

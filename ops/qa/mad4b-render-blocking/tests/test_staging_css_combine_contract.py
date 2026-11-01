"""Contract: Staging-only LiteSpeed CSS aggregation never includes inline dynamic rules."""
from pathlib import Path

source = Path(__file__).resolve().parents[1] / "staging_external_css_combine.sh"
text = source.read_text()
required = [
    "https://tours.dev.mad4b.com", "wp_get_environment_type()",
    "e5e64bca-ed62-48d2-b2b3-dff693cd702a",
    "optm-css_comb", "optm-css_comb_ext_inl",
    "set_opt optm-css_comb_ext_inl 0", "set_opt optm-css_comb 1",
    "set_opt optm-css_comb_ext_inl 1", "set_opt optm-css_comb 0",
    "Unexpected starting state: refuse to mutate",
    "action=plan", "plan|apply|rollback|verify",
]
for token in required:
    assert token in text, token
assert not any(x in text for x in ["production-php", "wp option delete", "litespeed-purge all"])
print("PASS: site identity, explicit apply and rollback, external CSS only and no Production mutation")

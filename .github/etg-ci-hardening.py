from pathlib import Path

op = Path('.github/workflows/etg-dfsb-ci.yml')
text = op.read_text()
old = "            alpha13-ajax-state-smoke.php \\\n            alpha13-usage-guide-smoke.php \\\n"
new = "            alpha13-ajax-state-smoke.php \\\n            alpha13-filter-definition-drift-smoke.php \\\n            alpha13-live-evidence-hardening-smoke.php \\\n            alpha13-usage-guide-smoke.php \\\n"
if text.count(old) != 1:
    raise SystemExit(f'Operational test-list anchor expected once, got {text.count(old)}')
op.write_text(text.replace(old, new, 1))

jet = Path('.github/workflows/etg-dfsb-jetengine-ci.yml')
text = jet.read_text()
anchor = "      - '.github/workflows/etg-dfsb-jetengine-ci.yml'"
operational = "      - '.github/workflows/etg-dfsb-ci.yml'"
if text.count(anchor) != 2:
    raise SystemExit(f'JetEngine trigger anchor expected twice, got {text.count(anchor)}')
if operational in text:
    raise SystemExit('Operational workflow trigger already present in JetEngine CI')
text = text.replace(anchor, operational + '\n' + anchor)
jet.write_text(text)

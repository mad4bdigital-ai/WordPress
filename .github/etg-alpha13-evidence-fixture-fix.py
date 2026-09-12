from pathlib import Path

path = Path('wp-content/plugins/etg-dynamic-filter-seo-bridge/tests/alpha12-inventory-control-smoke.php')
text = path.read_text()
old_open = " )),\n 'provider_group_drift'=>array(array("
new_open = " ),\n 'provider_group_drift'=>array(array("
if text.count(old_open) != 1:
    raise SystemExit(f'fixture open: expected 1 match, got {text.count(old_open)}')
text = text.replace(old_open, new_open, 1)
old_close = " 'provider_group_drift_count'=>1,'provider_group_drift_truncated'=>false,\n));"
new_close = " 'provider_group_drift_count'=>1,'provider_group_drift_truncated'=>false,\n ),\n));"
if text.count(old_close) != 1:
    raise SystemExit(f'fixture close: expected 1 match, got {text.count(old_close)}')
text = text.replace(old_close, new_close, 1)
path.write_text(text)

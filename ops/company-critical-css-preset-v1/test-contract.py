import json
from pathlib import Path
p=Path(__file__).parent
config=json.loads((p/'preset.example.json').read_text())
assert config['contract']=='mad4b.company-critical-css-preset.v1'
assert config['enabled'] is False
assert config['environment']=='staging'
assert config['defer_webfontloader'] is False
assert config['handles']==['uael-frontend']
assert isinstance(config['routes'],list)
assert all(x['path']=='/' and x['language']=='en' for x in config['routes'])
text=(p/'company-critical-css-preset.php').read_text()
for token in ["'staging'",'uael-frontend','style_loader_tag','litespeed_optm_html_head','document_guard','onload=','<noscript>','get_queried_object_id()','UAEL_VER','HTTP_X_MAD4B_CRITICAL_CSS_AB','HTTP_X_MAD4B_WEBFONT_AB']:
    assert token in text,token
for forbidden in ['tours.dev.mad4b.com','35138','26212','25927','25917','2ecaa6442c68f19042c6435dbdd55473']:
    assert forbidden not in text,forbidden
print('critical-css-tenant-contract PASS')

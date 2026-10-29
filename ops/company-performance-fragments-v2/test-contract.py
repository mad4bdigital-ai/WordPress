import json
from pathlib import Path
root=Path(__file__).parent
config=json.loads((root/'preset.example.json').read_text())
assert config['contract']=='mad4b.company-performance-fragments.v1'
assert config['enabled'] is False
assert config['environment']=='staging'
assert config['page_paths']==['/']
assert config['fragment_ttl_seconds']<=60
for file in ['company-performance-fragment-preset.php','company-performance-menu-fragments.php','company-performance-attractions-cards.php']:
    text=(root/file).read_text()
    for forbidden in ['25927','26212','45938','78d62e0','6072b3f']:
        assert forbidden not in text, (file,forbidden)
    assert 'wp_get_environment_type()' in text, file
assert 'individual_attractions_cards' in config['cache_targets']
print('portable-preset-source-contract: PASS')

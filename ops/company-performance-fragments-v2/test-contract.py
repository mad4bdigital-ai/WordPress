import json
from pathlib import Path
root=Path(__file__).parent
config=json.loads((root/'preset.example.json').read_text())
assert config['contract']=='mad4b.company-performance-fragments.v1'
assert config['enabled'] is False
assert config['geo_policy']=='unverified'
assert config['environment']=='staging'
assert config['page_paths']==['/']
assert config['fragment_ttl_seconds']<=60
for file in ['company-performance-fragment-preset.php','company-performance-menu-fragments.php','company-performance-attractions-cards.php', 'company-performance-fragment-gc.php']:
    text=(root/file).read_text()
    for forbidden in ['25927','26212','45938','78d62e0','6072b3f']:
        assert forbidden not in text, (file,forbidden)
    assert 'wp_get_environment_type()' in text, file
assert 'individual_attractions_cards' in config['cache_targets']
assert (root/'test-gc.php').is_file()
print('portable-preset-source-contract: PASS')

# A new tenant cannot accidentally enable a geo-unverified fragment cache.
helper=(root/'company-performance-fragment-preset.php').read_text()
for check in ["'static_base_only'", "'unverified'", "'woocommerce_default_customer_address'", "'HTTP_CF_IPCOUNTRY'", "'HTTP_X_MAD4B_MARKET_CONTEXT'"]:
    assert check in helper, check

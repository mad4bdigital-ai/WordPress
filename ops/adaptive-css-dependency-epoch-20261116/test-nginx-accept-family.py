#!/usr/bin/env python3
import importlib.util,unittest
from pathlib import Path
module_path=Path(__file__).with_name('nginx-accept-family.py')
spec=importlib.util.spec_from_file_location('accept_key',module_path)
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
class Tests(unittest.TestCase):
    def fixture(self):
        x=mod.MARKET+'\n'+mod.PRIVATE+'\n'+mod.ANCHOR+'\n'
        for _ in range(5):
            x+='fastcgi_cache_key "$host|$request_uri";\n'+mod.VARY+'\n'
        return x
    def test_all_five_keys_and_idempotence(self):
        a=mod.apply(self.fixture())
        self.assertEqual(a.count('|accept=$mad4b_css_accept_family'),5)
        self.assertEqual(a.count(mod.ADDED),5)
        self.assertEqual(mod.apply(a),a)
    def test_missing_market_or_private(self):
        for guard in (mod.MARKET,mod.PRIVATE):
            with self.assertRaises(mod.TopologyError):
                mod.apply(self.fixture().replace(guard,''))
    def test_unexpected_keys(self):
        with self.assertRaises(mod.TopologyError):
            mod.apply(self.fixture().replace('fastcgi_cache_key "$host|$request_uri";','',1))
    def test_partial_existing_segment(self):
        with self.assertRaises(mod.TopologyError):
            mod.apply(self.fixture().replace('fastcgi_cache_key "$host|$request_uri";','fastcgi_cache_key "$host|$request_uri|accept=old";',1))
if __name__=='__main__':
    unittest.main()

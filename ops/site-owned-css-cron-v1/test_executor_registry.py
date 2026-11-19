#!/usr/bin/env python3
import importlib.util,json,pathlib,tempfile,os,unittest
import executor_registry as registry
class Contracts(unittest.TestCase):
    def test_original_css_sites_verified(self):
        items=registry.snapshot(pathlib.Path(__file__).with_name('site-executor-registry.example.json'))
        self.assertEqual(items['active'],2)
        self.assertEqual(items['held'],0)
        self.assertFalse(items['mutated'])
    def test_non_enrolled_site_cannot_start(self):
        e={'site_id':'unknown','site_dir':'/opt/unknown/operations/css-cron-v1',
           'cron_path':'/etc/cron.d/unknown','offset':0}
        self.assertEqual(registry.inspect(e)['status'],'hold')
    def test_existing_site_cannot_borrow_another_cron(self):
        e={'site_id':'wayfa','site_dir':'/opt/wayfa-rebuild-20261102/operations/css-cron-v1',
           'cron_path':'/etc/cron.d/mad4b-css-site-source','offset':2}
        self.assertEqual(registry.inspect(e)['reason'],'active_cron_mismatch')
    def test_duplicate_registry_rejected(self):
        data=json.loads(pathlib.Path(__file__).with_name('site-executor-registry.example.json').read_text())
        data['sites'].append(dict(data['sites'][0]))
        with tempfile.TemporaryDirectory() as td:
            p=pathlib.Path(td)/'reg.json';p.write_text(json.dumps(data))
            with self.assertRaises(ValueError):registry.snapshot(p)
    def test_unknown_fields_fail_closed(self):
        e={'site_id':'source','site_dir':'/tmp','cron_path':'/etc/cron.d/foo','offset':0,'shell':'echo ok'}
        with self.assertRaises(ValueError):registry.inspect(e)
if __name__=='__main__':unittest.main(verbosity=2)

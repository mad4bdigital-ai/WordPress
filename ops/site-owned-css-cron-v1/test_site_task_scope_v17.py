#!/usr/bin/env python3
"""Company-isolated task commands and receipts; never call a live site."""
import ast
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from test_site_worker import load_worker,sample

HERE=Path(__file__).resolve().parent

class SiteTaskScopeV17(unittest.TestCase):
    def test_renderer_v16_remains_exactly_rebuildable(self):
        from rebuild_renderer_v16 import build,tree_digest,MANIFEST
        with tempfile.TemporaryDirectory(prefix='v16-immutable-check-') as d:
            p=Path(d)/'v16';build(p)
            old=json.loads(MANIFEST.read_text())
            self.assertEqual(old['tree_sha256'],tree_digest(p))
            self.assertEqual(len(old['files']),131)

    def test_renderer_v17_is_immutable_and_diff_only_three_scoped_programs(self):
        from rebuild_renderer_v17 import build,tree_digest,MANIFEST
        from rebuild_renderer_v16 import build as build_old
        with tempfile.TemporaryDirectory(prefix='v17-delta-scope-') as d:
            old=Path(d)/'old';new=Path(d)/'new'
            build_old(old);build(new)
            changed={p.relative_to(new).as_posix() for p in new.rglob('*')
                     if p.is_file() and (old/p.relative_to(new)).read_bytes()!=p.read_bytes()}
            self.assertEqual(changed,{'refresh-source-families.py','post-policy-visual-watch.py','health-audit.py'})
            self.assertEqual(tree_digest(new),json.loads(MANIFEST.read_text())['tree_sha256'])
            self.assertNotEqual(tree_digest(new),
                                json.loads((HERE/'renderer-v16-manifest.json').read_text())['tree_sha256'])
            self.assertFalse(any(p.is_symlink() for p in new.rglob('*')))

    def test_scoped_script_selection_is_fail_closed(self):
        for name in ('refresh-source-families.py','post-policy-visual-watch.py'):
            text=(HERE/'renderer-v17-overrides'/name).read_text()
            tree=ast.parse(text)
            func=next(f for f in tree.body if isinstance(f,ast.FunctionDef) and f.name=='selected_sites')
            scope={}
            exec(compile(ast.Module(body=[func],type_ignores=[]),name,'exec'),scope)
            selector=scope['selected_sites']
            self.assertEqual(selector(['source']),('source',))
            self.assertEqual(selector(['wayfa']),('wayfa',))
            self.assertEqual(selector([]),('source','wayfa'))  # old source-only operator behavior
            for invalid in (['all'],['wayfa','source'],['other'],[''],['--site','wayfa']):
                with self.assertRaises(ValueError):selector(invalid)

    def test_each_worker_commands_only_its_bound_company(self):
        for site in ('source','wayfa'):
            m=load_worker(sample(site,tasks=['families','visual','audit']))
            commands={k:m.TASKS[k](site) for k in ('families','visual','audit')}
            for job,cmd in commands.items():
                self.assertEqual(cmd[-1],site)
                self.assertNotIn('all',cmd)
                self.assertNotIn('wayfa' if site=='source' else 'source',cmd)
                self.assertIn('renderer',cmd[1])
            self.assertIn('health-audit.py',commands['audit'][1])

    def test_family_receipt_is_exactly_site_bound(self):
        for site in ('source','wayfa'):
            m=load_worker(sample(site,tasks=['families']))
            other='wayfa' if site=='source' else 'source'
            good='SOURCE_FAMILY_REGISTRY_REFRESH '+site+' routes 100 families 12 qualified 0 pending 9\n'
            bad='SOURCE_FAMILY_REGISTRY_REFRESH '+other+' routes 100 families 12 qualified 0 pending 9\n'
            self.assertEqual(m.verified_task_outcome(site,'families',0,good,''),
                             (True,'verified_site_family_report'))
            self.assertNotEqual(m.verified_task_outcome(site,'families',0,bad,'')[0],True)
            self.assertEqual(m.verified_task_outcome(site,'families',0,good+bad,'')[1],
                             'cross_site_family_receipt_denied')

    def test_visual_receipt_is_exactly_site_bound(self):
        for site in ('source','wayfa'):
            m=load_worker(sample(site,tasks=['visual']))
            other='wayfa' if site=='source' else 'source'
            valid='POST_POLICY_VISUAL_OBSERVED '+site+' /sample/ alerts 0\nPOST_POLICY_VISUAL_MONITOR_NO_CERTIFICATE_OR_POLICY_MUTATION\n'
            invalid='POST_POLICY_VISUAL_OBSERVED '+other+' /sample/ alerts 0\nPOST_POLICY_VISUAL_MONITOR_NO_CERTIFICATE_OR_POLICY_MUTATION\n'
            self.assertEqual(m.verified_task_outcome(site,'visual',0,valid,''),
                             (True,'verified_site_visual_report'))
            self.assertNotEqual(m.verified_task_outcome(site,'visual',0,invalid,'')[0],True)

    def test_audit_receipt_requires_only_one_exact_company(self):
        for site in ('source','wayfa'):
            m=load_worker(sample(site,tasks=['audit']))
            other='wayfa' if site=='source' else 'source'
            good={'sites':{site:{'status':'healthy'}}}
            bad={'sites':{other:{'status':'healthy'}}}
            both={'sites':{site:{'status':'healthy'},other:{'status':'healthy'}}}
            make=lambda x:'PUBLIC_CCSS_INTEGRITY '+json.dumps(x)+'\n'
            self.assertEqual(m.verified_task_outcome(site,'audit',0,make(good),''),
                             (True,'verified_site_integrity'))
            self.assertFalse(m.verified_task_outcome(site,'audit',0,make(bad),'')[0])
            self.assertFalse(m.verified_task_outcome(site,'audit',0,make(both),'')[0])
            self.assertFalse(m.verified_task_outcome(site,'audit',0,make({'sites':{site:{'status':'error'}}}),'')[0])

    def test_global_retention_remains_single_source_owner(self):
        with self.assertRaises(ValueError):
            load_worker(sample('wayfa',tasks=['public','retention']))
        m=load_worker(sample('source',tasks=['public','retention']))
        self.assertEqual(m.SITE,'source')

    def test_site_audit_does_not_overwrite_legacy_aggregate(self):
        path=HERE/'renderer-v17-overrides/health-audit.py'
        source=path.read_text()
        self.assertIn("suffix=('' if sys.argv[1]=='all' else '-'+sys.argv[1])",source)
        self.assertIn("write(REPORT/('latest'+suffix+'.json'),state)",source)

if __name__=='__main__':
    unittest.main(verbosity=2)

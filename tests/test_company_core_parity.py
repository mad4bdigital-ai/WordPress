"""Standalone read-only parity-scanner tests."""
import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('parity', ROOT / 'tools/verify-company-core-parity.py')
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class ParityContract(unittest.TestCase):
    def test_match_drift_missing_and_extra(self):
        with tempfile.TemporaryDirectory() as root:
            base = Path(root)
            source, tenant = base / 'source', base / 'tenant'
            for p in (source / 'pricing', tenant / 'pricing'):
                p.mkdir(parents=True)
            (source / 'pricing/engine.php').write_text('<?php // canonical')
            (tenant / 'pricing/engine.php').write_text('<?php // canonical')
            result = mod.audit(source, ['tenant=' + str(tenant)])
            self.assertEqual(result['canonical_php_files'], 1)
            self.assertTrue(result['sites'][0]['passed'])
            (tenant / 'pricing/engine.php').write_text('<?php // stale')
            self.assertEqual(result['sites'][0]['matched'], 1)  # old snapshot, not a lazy view
            result = mod.audit(source, ['tenant=' + str(tenant)])
            self.assertEqual(result['sites'][0]['different'], ['pricing/engine.php'])
            (tenant / 'pricing/engine.php').unlink()
            result = mod.audit(source, ['tenant=' + str(tenant)])
            self.assertEqual(result['sites'][0]['missing'], ['pricing/engine.php'])
            (tenant / 'pricing/engine.php').write_text('<?php // canonical')
            (tenant / 'pricing/rogue.php').write_text('<?php // duplicate')
            result = mod.audit(source, ['tenant=' + str(tenant)])
            self.assertEqual(result['sites'][0]['extra'], ['pricing/rogue.php'])


if __name__ == '__main__':
    unittest.main()

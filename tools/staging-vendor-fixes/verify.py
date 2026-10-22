#!/usr/bin/env python3
"""Build and execute real vendor boundary fixtures in disposable local folders."""
import argparse
import importlib.util
import json
import re
from pathlib import Path
import subprocess
import tempfile
import zipfile

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('vendor_build', HERE / 'build.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


def verify(source, php):
    recipes = json.loads((HERE / 'recipes.json').read_text())
    with tempfile.TemporaryDirectory(prefix='mad4b-vendor-regression-') as scratch:
        base = Path(scratch)
        manifest = builder.build(source, base / 'candidate', recipes)
        payload_count = 0
        for plugin in manifest['plugins']:
            assert plugin['input_plugin_version'] and plugin['installed_source_digest_match_required'] is True
            assert plugin['installed_version_verified'] is False
            with zipfile.ZipFile(Path(source) / (plugin['plugin'] + '.zip')) as original, \
                 zipfile.ZipFile(base / 'candidate' / plugin['archive']) as candidate:
                candidate.extractall(base / 'patched')
                modified = {item['path'] for item in plugin['modified_entries']}
                prefix = '' if plugin['plugin'] + '/' + plugin['plugin'] + '.php' in original.namelist() else plugin['plugin'] + '/'
                for item in original.infolist():
                    if item.is_dir():
                        continue
                    path = prefix + item.filename
                    before = original.read(item)
                    after = candidate.read(path)
                    if path not in modified:
                        assert before == after, path
                    else:
                        target = base / 'original' / path
                        target.parent.mkdir(parents=True, exist_ok=True)
                        target.write_bytes(before)
                    payload_count += 1
                assert len(candidate.namelist()) == len([i for i in original.infolist() if not i.is_dir()])
            for item in plugin['modified_entries']:
                path = base / 'patched' / item['path']
                command = [php, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-l', str(path)] if path.suffix == '.php' else ['node', '--check', str(path)]
                lint = subprocess.run(command, capture_output=True, text=True, check=True)
                assert not lint.stderr and not re.search(r'Warning:|Deprecated:|Fatal error:', lint.stdout, re.I), lint.stderr + lint.stdout
                print(lint.stdout.strip())
        # Prove these fixtures catch the original boundary bugs rather than only
        # mirroring the patched implementation.
        checks = [([php, str(HERE / 'regression.php'), str(base / 'original')], 'PHP', r'Illegal offset type|Cannot access offset of type array|Using null as an array offset'),
                  ([php, str(HERE / 'regression-lottie.php'), str(base / 'original')], 'Lottie PHP 8.5 syntax', r'Deprecated case separator'),
                  (['node', str(HERE / 'regression.mjs'), str(base / 'original/jet-smart-filters/assets/js/public.js')], 'JS', r'AssertionError')]
        for command, label, expected_failure in checks:
            result = subprocess.run(command, capture_output=True, text=True)
            assert result.returncode != 0, 'unpatched ' + label + ' unexpectedly passed'
            assert re.search(expected_failure, result.stderr + result.stdout), 'unpatched ' + label + ' failed for an unrelated reason: ' + result.stderr[-500:]
            print('PASS original ' + label + ' regression reproduced')
        subprocess.run([php, str(HERE / 'regression.php'), str(base / 'patched')], check=True)
        subprocess.run([php, str(HERE / 'regression-lottie.php'), str(base / 'patched')], check=True)
        subprocess.run(['node', str(HERE / 'regression.mjs'), str(base / 'patched/jet-smart-filters/assets/js/public.js')], check=True)
        # Exact archive identity is checked before any output is written.
        invalid = json.loads(json.dumps(recipes))
        invalid['archives']['elementor'] = '0' * 64
        try:
            builder.build(source, base / 'invalid', invalid)
        except ValueError as error:
            assert 'input archive drift' in str(error) and not (base / 'invalid').exists()
        else:
            raise AssertionError('drifted package accepted')
        print('PASS archive drift rejected before output')
        again = builder.build(source, base / 'repeat', recipes)
        assert again == manifest, 'non-deterministic package result'
        print('PASS deterministic repeat build')
        modified_count = sum(len(p['modified_entries']) for p in manifest['plugins'])
        print('PASS payload scope:', payload_count, 'files checked,', modified_count, 'intended modified files')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', default='wp-content/plugins')
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    verify(args.source, args.php)

#!/usr/bin/env python3
"""Build review-only vendor repair candidates; never install or execute WordPress."""
import argparse
import hashlib
import json
import re
from pathlib import Path, PurePosixPath
import stat
import zipfile


def sha(data):
    return hashlib.sha256(data).hexdigest()


def build(source, output, recipes):
    if recipes.get('contract') != 'mad4b.staging-vendor-repair-recipes.v1':
        raise ValueError('unknown repair recipe contract')
    output = Path(output)
    if output.exists() and any(output.iterdir()):
        raise ValueError('output directory must be empty')
    pending = []
    for plugin, archive_pin in sorted(recipes['archives'].items()):
        path = Path(source) / (plugin + '.zip')
        if sha(path.read_bytes()) != archive_pin:
            raise ValueError('input archive drift: ' + plugin)
        with zipfile.ZipFile(path) as archive:
            infos = archive.infolist()
            if len(infos) > 25000 or sum(i.file_size for i in infos) > 512 * 1024 * 1024:
                raise ValueError('archive budget exceeded')
            names = [i.filename for i in infos]
            if len(set(names)) != len(names):
                raise ValueError('duplicate archive entry')
            for item in infos:
                name = PurePosixPath(item.filename)
                if name.is_absolute() or '..' in name.parts or '\\' in item.filename:
                    raise ValueError('unsafe archive path')
                if stat.S_ISLNK(item.external_attr >> 16):
                    raise ValueError('symlink in archive')
            entries = {i.filename: archive.read(i) for i in infos if not i.is_dir()}
        patches = [p for p in recipes['patches'] if p['plugin'] == plugin]
        # Validate all original entries before the first replacement in a shared file.
        for patch in patches:
            if sha(entries[patch['entry']]) != patch['input_entry_sha256']:
                raise ValueError('input source drift: ' + patch['entry'])
        for patch in patches:
            old, new = patch['old'].encode(), patch['new'].encode()
            if entries[patch['entry']].count(old) != 1:
                raise ValueError('patch anchor is not unique')
            entries[patch['entry']] = entries[patch['entry']].replace(old, new)
        # Both rootless vendor bundles and ordinary WP bundles are present in the repo.
        prefix = '' if plugin + '/' + plugin + '.php' in entries else plugin + '/'
        if not prefix and plugin + '/' + plugin + '.php' not in entries:
            raise ValueError('plugin header absent')
        if prefix and plugin + '.php' not in entries:
            raise ValueError('plugin header absent')
        header_name = plugin + '/' + plugin + '.php' if not prefix else plugin + '.php'
        versions = re.findall(rb'^\s*\*?\s*Version:\s*([^\s]+)', entries[header_name][:8192], re.M | re.I)
        if len(versions) != 1 or not re.fullmatch(rb'[A-Za-z0-9.+_-]{1,80}', versions[0]):
            raise ValueError('exact plugin version unavailable: ' + plugin)
        pending.append((plugin, entries, prefix, patches, archive_pin, versions[0].decode('ascii')))
    output.mkdir(parents=True, exist_ok=True)
    manifest = {'contract': 'mad4b.staging-vendor-repair-candidates.v1',
                'installed': False, 'production_promotion_granted': False, 'plugins': []}
    for plugin, entries, prefix, patches, archive_pin, version in pending:
        target = output / (plugin + '-mad4b-runtime-fix.zip')
        with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for name, data in sorted(entries.items()):
                info = zipfile.ZipInfo(prefix + name, date_time=(2026, 10, 19, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                archive.writestr(info, data)
        manifest['plugins'].append({'plugin': plugin, 'input_archive_sha256': archive_pin,
            'plugin_file': plugin + '/' + plugin + '.php', 'input_plugin_version': version,
            'installed_version_verified': False, 'installed_source_digest_match_required': True,
            'candidate_archive_sha256': sha(target.read_bytes()), 'archive': target.name,
            'modified_entries': [{'path': prefix + name,
                'before_sha256': next(p['input_entry_sha256'] for p in patches if p['entry'] == name),
                'after_sha256': sha(entries[name])} for name in sorted({p['entry'] for p in patches})]})
    (output / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    return manifest


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    config = json.loads(Path(__file__).with_name('recipes.json').read_text())
    print(json.dumps(build(args.source, args.output, config), indent=2))

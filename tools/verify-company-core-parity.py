#!/usr/bin/env python3
"""Read-only Source->tenant Shared Product Core PHP identity audit.

Only tests code fingerprints. It never enables Commerce, writes data, or claims
booking/payment readiness. Use alongside native runtime capability snapshots.
"""
import argparse
import hashlib
import json
from pathlib import Path


def file_sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def audit(canonical: Path, sites: list[str]) -> dict:
    originals = sorted(p.relative_to(canonical) for p in canonical.rglob('*.php') if p.is_file())
    if not originals:
        raise ValueError('canonical Shared Product Core PHP files missing')
    report = {'contract': 'mad4b.shared-product-core.code-parity.v1', 'canonical_php_files': len(originals), 'sites': []}
    for spec in sites:
        if '=' not in spec:
            raise ValueError('site format must be SITE=/absolute/path/shared-product-core')
        site, path = spec.split('=', 1)
        folder = Path(path)
        if not site or not folder.is_dir():
            raise ValueError(f'unknown site directory: {site}')
        missing = []
        different = []
        matched = 0
        for rel in originals:
            source_file, live_file = canonical / rel, folder / rel
            if not live_file.is_file() or live_file.is_symlink():
                missing.append(str(rel))
            elif file_sha(source_file) == file_sha(live_file):
                matched += 1
            else:
                different.append(str(rel))
        extras = sorted(str(p.relative_to(folder)) for p in folder.rglob('*.php') if p.is_file() and p.relative_to(folder) not in originals)
        report['sites'].append({'site': site, 'matched': matched, 'missing': missing, 'different': different, 'extra': extras,
                                'passed': not missing and not different and not extras})
    return report


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--canonical', type=Path, required=True)
    parser.add_argument('--site', action='append', required=True, help='name=/absolute/path/to/shared-product-core')
    parser.add_argument('--output', type=Path)
    parser.add_argument('--strict', action='store_true')
    args = parser.parse_args()
    result = audit(args.canonical, args.site)
    text = json.dumps(result, ensure_ascii=False, indent=2) + '\n'
    if args.output:
        args.output.write_text(text)
    print(text)
    return 2 if args.strict and not all(row['passed'] for row in result['sites']) else 0


if __name__ == '__main__':
    raise SystemExit(main())

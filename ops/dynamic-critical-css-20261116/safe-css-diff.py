#!/usr/bin/env python3
"""Bounded, linear-time SHA/content comparison of two local LiteSpeed CSS assets.

Replace SequenceMatcher on huge bytes, which can consume entire CPU cores for hours.
Read-only: no WordPress edits, cache operations, or certificate issuance.
"""
import argparse, hashlib, json, os, re, resource, stat, sys, time
from pathlib import Path

SITE_ROOTS = {
    'wayfa': Path('/opt/wayfa-rebuild-20261102/site-copy/wp-content/litespeed/css'),
    'source': Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/litespeed/css'),
}
MAX_BYTES = 12 * 1024 * 1024
NAME = re.compile(r'[0-9a-f]{32}[.]css\Z')


def secure_read(root, filename, max_bytes=MAX_BYTES):
    if not isinstance(filename, str) or not NAME.fullmatch(filename):
        raise ValueError('CSS_CONTENT_ADDRESS_NAME_REQUIRED')
    if root.is_symlink() or not root.is_dir():
        raise ValueError('CSS_ROOT_NOT_TRUSTED')
    fd = os.open(root / filename, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0))
    try:
        before = os.fstat(fd)
        if not stat.S_ISREG(before.st_mode) or before.st_size > max_bytes:
            raise ValueError('CSS_FILE_NOT_REGULAR_OR_EXCEEDS_BUDGET')
        with os.fdopen(fd, 'rb', closefd=False) as f:
            contents = f.read(max_bytes + 1)
        after = os.fstat(fd)
        if len(contents) > max_bytes or before.st_size != len(contents) or (
            before.st_mtime_ns, before.st_size, before.st_ino
        ) != (after.st_mtime_ns, after.st_size, after.st_ino):
            raise ValueError('CSS_CHANGED_DURING_DIAGNOSTIC')
        return contents
    finally:
        os.close(fd)


def compare(root, one, two):
    start = time.monotonic()
    a, b = secure_read(root, one), secure_read(root, two)
    common = min(len(a), len(b))
    prefix = 0
    while prefix < common and a[prefix] == b[prefix]:
        prefix += 1
    suffix = 0
    # Prefix/suffix must not overlap in identical-length input.
    while suffix < common - prefix and a[len(a) - suffix - 1] == b[len(b) - suffix - 1]:
        suffix += 1
    mismatched = sum(x != y for x, y in zip(a, b))
    return {
        'contract': 'mad4b.css-linear-diff.v1',
        'source_files': [one, two],
        'sizes_bytes': [len(a), len(b)],
        'sha256': [hashlib.sha256(a).hexdigest(), hashlib.sha256(b).hexdigest()],
        'identical': a == b,
        'common_prefix_bytes': prefix,
        'common_suffix_bytes': suffix,
        'first_difference_offset': prefix if a != b else None,
        'mismatched_overlap_bytes': mismatched,
        'extra_length_bytes': abs(len(a) - len(b)),
        'elapsed_seconds': round(time.monotonic() - start, 4),
        'mode': 'read_only_no_quadratic_diff',
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('site', choices=sorted(SITE_ROOTS))
    parser.add_argument('first', help='32-hex LiteSpeed stylesheet name')
    parser.add_argument('second', help='32-hex LiteSpeed stylesheet name')
    args = parser.parse_args()
    # Bound memory and CPU even when callers forget an outer timeout.
    resource.setrlimit(resource.RLIMIT_CPU, (4, 5))
    resource.setrlimit(resource.RLIMIT_AS, (128 * 1024 * 1024, 128 * 1024 * 1024))
    print(json.dumps(compare(SITE_ROOTS[args.site], args.first, args.second), sort_keys=True))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError) as exc:
        print('HOLD_CSS_DIFF ' + type(exc).__name__ + ': ' + str(exc)[:100], file=sys.stderr)
        sys.exit(4)

#!/usr/bin/env python3
"""Read-only review of an existing selective file inventory; never authorizes or deploys."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re

def digest(root, name):
    relative = PurePosixPath(name)
    if relative.is_absolute() or '..' in relative.parts or '\\' in name:
        raise ValueError('invalid relative file')
    root = root.resolve(strict=True)
    file = root.joinpath(*relative.parts)
    if not file.resolve(strict=True).is_relative_to(root) or file.is_symlink() or not file.is_file():
        raise ValueError('invalid file boundary')
    with file.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()

def review(inventory, source, target, candidate, rollback):
    if inventory.get('contract') != 'mad4b.selective-functional-reconciliation.review.v1':
        raise ValueError('unsupported inventory')
    rows = inventory.get('rows')
    if not isinstance(rows, list) or not 1 <= len(rows) <= 100:
        raise ValueError('invalid row count')
    seen = set()
    result = []
    for row in rows:
        name = row['path']
        if name in seen or not isinstance(name, str):
            raise ValueError('duplicate or invalid path')
        seen.add(name)
        expected_source, expected_target = row['source_sha256'], row['target_sha256']
        if not all(isinstance(x,str) and re.fullmatch(r'[a-f0-9]{64}',x) for x in (expected_source,expected_target)):
            raise ValueError('invalid recorded digest')
        checks = {}
        for key, root, expected in (
            ('source',source,expected_source),('target',target,expected_target),
            ('candidate',candidate,expected_source),('rollback',rollback,expected_target)):
            try:
                checks[key] = digest(root,name) == expected
            except (OSError, ValueError):
                checks[key] = False
        result.append({'path':name,'source_sha256':expected_source,
                       'target_sha256':expected_target,'checks':checks})
    verified = all(all(r['checks'].values()) for r in result)
    payload = {'contract':'mad4b.operation-request-review.v1',
               'source':inventory['source'],'target':inventory['target'],
               'scope':inventory['scope'],'rows':result,
               'file_evidence_verified':verified,
               'status':'awaiting_native_authority' if verified else 'evidence_mismatch',
               'authorizing':False,'approval_ticket_created':False,
               'execution_performed':False}
    payload['review_sha256'] = hashlib.sha256(json.dumps(payload,sort_keys=True,separators=(',',':')).encode()).hexdigest()
    return payload

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('inventory',type=Path)
    for k in ('source','target','candidate','rollback'):
        p.add_argument(k,type=Path)
    a=p.parse_args()
    result=review(json.loads(a.inventory.read_text()),a.source,a.target,a.candidate,a.rollback)
    print(json.dumps(result,indent=2))
    return 0 if result['file_evidence_verified'] else 1
if __name__=='__main__':
    raise SystemExit(main())

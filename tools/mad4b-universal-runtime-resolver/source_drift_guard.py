#!/usr/bin/env python3
"""Read-only, fail-closed MAD4B source drift preflight.

Actual release executors must hold the same site-scoped exclusive lock across
preflight, deployment and post-readback. This tool never authorizes mutations.
"""
import argparse
import hashlib
import json
import re
import stat
import sys
import zipfile
from pathlib import Path, PurePosixPath

CONTRACT = 'mad4b.release-source-drift-preflight.v1'
PREFIX = 'mad4b-site-control-plane/'
PROVENANCE = 'MAD4B-BUILD-PROVENANCE.json'
COMMIT = re.compile(r'^[0-9a-f]{40}$')

class DriftError(ValueError):
    pass

def sha(data):
    return hashlib.sha256(data).hexdigest()

def normalized_name(name):
    if not isinstance(name, str) or not name or '\\' in name or '\x00' in name:
        raise DriftError('UNSAFE_PACKAGE_PATH')
    path = PurePosixPath(name)
    if path.is_absolute() or str(path) != name or any(x in ('..', '.') for x in name.split('/')):
        raise DriftError('UNSAFE_PACKAGE_PATH')
    return name

def manifest_file_map(obj):
    rows = obj.get('package_files')
    if not isinstance(rows, list) or not rows:
        raise DriftError('INVALID_MANIFEST_FILES')
    out = {}
    for row in rows:
        if not isinstance(row, dict):
            raise DriftError('INVALID_MANIFEST_ROW')
        name = normalized_name(row.get('path'))
        if name == PROVENANCE or name in out or not isinstance(row.get('bytes'), int) or row['bytes'] < 0:
            raise DriftError('DUPLICATE_OR_INVALID_MANIFEST_ROW')
        digest = row.get('sha256')
        if not isinstance(digest, str) or not re.fullmatch('[0-9a-f]{64}', digest):
            raise DriftError('INVALID_FILE_DIGEST')
        out[name] = {'bytes': row['bytes'], 'sha256': digest}
    canonical = ''.join(n+'\x00'+str(out[n]['bytes'])+'\x00'+out[n]['sha256']+'\n' for n in sorted(out))
    if sha(canonical.encode()) != obj.get('package_manifest_digest'):
        raise DriftError('PACKAGE_MANIFEST_DIGEST_INVALID')
    if obj.get('manifest_file_count') != len(out) or obj.get('archive_file_count') != len(out)+1:
        raise DriftError('PACKAGE_FILE_COUNT_MISMATCH')
    return out

def read_root(root):
    root = root.resolve(strict=True)
    if not root.is_dir():
        raise DriftError('PLUGIN_ROOT_INVALID')
    out = {}
    def scan(folder):
        for path in folder.iterdir():
            mode = path.lstat().st_mode
            if stat.S_ISLNK(mode):
                raise DriftError('PLUGIN_SYMLINK_DENIED')
            if stat.S_ISDIR(mode):
                scan(path)
            elif stat.S_ISREG(mode):
                name = normalized_name(path.relative_to(root).as_posix())
                out[name] = path.read_bytes()
            else:
                raise DriftError('UNSUPPORTED_PLUGIN_FILE_TYPE')
    scan(root)
    return out

def read_archive(path):
    out = {}
    with zipfile.ZipFile(path) as archive:
        for entry in archive.infolist():
            if entry.is_dir():
                continue
            if not entry.filename.startswith(PREFIX):
                raise DriftError('INVALID_ARCHIVE_ROOT')
            name = normalized_name(entry.filename[len(PREFIX):])
            if name in out:
                raise DriftError('DUPLICATE_ARCHIVE_ENTRY')
            if (entry.external_attr >> 16) & 0o170000 == 0o120000:
                raise DriftError('ARCHIVE_SYMLINK_DENIED')
            out[name] = archive.read(entry)
    return out

def verify(files, expected_source):
    raw = files.get(PROVENANCE)
    if not raw:
        raise DriftError('BUILD_PROVENANCE_MISSING')
    try:
        obj = json.loads(raw)
    except (ValueError, UnicodeDecodeError) as exc:
        raise DriftError('BUILD_PROVENANCE_INVALID') from exc
    if not isinstance(obj, dict) or not COMMIT.fullmatch(expected_source):
        raise DriftError('EXPECTED_SOURCE_INVALID')
    if obj.get('source_commit_sha') != expected_source:
        raise DriftError('SOURCE_PIN_MISMATCH')
    listed = manifest_file_map(obj)
    if set(files)-{PROVENANCE} != set(listed):
        raise DriftError('RUNTIME_FILE_SET_DRIFT')
    for name, info in listed.items():
        if len(files[name]) != info['bytes'] or sha(files[name]) != info['sha256']:
            raise DriftError('RUNTIME_FILE_BYTES_DRIFT:'+name)
    return {'source':expected_source,'files':len(files),'manifest':obj['package_manifest_digest']}

def approved_delta(old, new, old_src, new_src, approval):
    if not isinstance(approval, dict) or approval.get('contract') != 'mad4b.staging-artifact-delta.v1':
        raise DriftError('REVIEWED_CHANGE_SET_REQUIRED')
    if approval.get('source_commit_sha') != old_src or approval.get('target_source_commit_sha') != new_src:
        raise DriftError('CHANGE_SET_RELEASE_PIN_MISMATCH')
    rows = approval.get('changes')
    if not isinstance(rows, list):
        raise DriftError('CHANGE_SET_INVALID')
    allowed = {}
    for row in rows:
        if not isinstance(row, dict):
            raise DriftError('CHANGE_ROW_INVALID')
        name = normalized_name(row.get('path'))
        if name in allowed:
            raise DriftError('CHANGE_ROW_DUPLICATE')
        allowed[name] = (row.get('previous_sha256'),row.get('target_sha256'))
    observed = {}
    for name in old.keys() | new.keys():
        before = sha(old[name]) if name in old else None
        after = sha(new[name]) if name in new else None
        if before != after:
            observed[name] = (before,after)
    if observed != allowed:
        raise DriftError('UNREVIEWED_SOURCE_DELTA')
    return len(observed)

def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--plugin-dir',type=Path,required=True)
    parser.add_argument('--expected-source',required=True)
    parser.add_argument('--candidate-zip',type=Path)
    parser.add_argument('--candidate-sha256')
    parser.add_argument('--candidate-source')
    parser.add_argument('--reviewed-changes',type=Path)
    args = parser.parse_args(argv)
    try:
        if bool(args.candidate_zip)!=bool(args.candidate_source) or bool(args.candidate_zip)!=bool(args.reviewed_changes):
            raise DriftError('CANDIDATE_ARGUMENTS_INCOMPLETE')
        live=read_root(args.plugin_dir)
        current=verify(live,args.expected_source)
        count=0
        if args.candidate_zip:
            if not re.fullmatch('[0-9a-f]{64}',args.candidate_sha256 or ''):
                raise DriftError('CANDIDATE_ARCHIVE_PIN_REQUIRED')
            if sha(args.candidate_zip.read_bytes())!=args.candidate_sha256:
                raise DriftError('CANDIDATE_ARCHIVE_DIGEST_MISMATCH')
            proposed=read_archive(args.candidate_zip)
            verify(proposed,args.candidate_source)
            approval=json.loads(args.reviewed_changes.read_text())
            count=approved_delta(live,proposed,args.expected_source,args.candidate_source,approval)
        print(json.dumps({'contract':CONTRACT,'state':'VERIFIED','source':current['source'],
              'file_count':current['files'],'approved_changes':count,
              'read_only':True,'production_mutation':False},sort_keys=True))
        return 0
    except (DriftError,OSError,ValueError,zipfile.BadZipFile) as exc:
        print(json.dumps({'contract':CONTRACT,'state':'BLOCKED','reason':str(exc),
                          'read_only':True,'production_mutation':False},sort_keys=True))
        return 75

if __name__=='__main__':
    sys.exit(main())

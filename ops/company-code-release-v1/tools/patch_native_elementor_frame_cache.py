#!/usr/bin/env python3
"""CAS-bind frame-scoped Elementor performance feature to the *existing* first paint owner.

No new MU runtime, no replacement of company-owned source. Default dry run;
--write requires byte-exact preimage and creates a rollback snapshot first.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import tempfile

MARKER = 'public static function elementor_device_mode_frame_cache(): void {'
METHOD = '''    /**
     * Cache Elementor's expensive pseudo-element device-mode probe only within
     * a browser animation frame. No breakpoint table, no duplicate handlers.
     * Elementor remains the native authority and every responsive change
     * invalidates the per-frame JS cache. Company opt-in and exact asset SHA.
     */
    public static function elementor_device_mode_frame_cache(): void {
        if (!self::homepage() || is_user_logged_in() || is_customize_preview()
            || (string)get_option('mad4b_perf_elementor_device_mode_frame_cache_v1', 'off') !== 'on') return;
        $path = __DIR__ . '/assets/mad4b-elementor-device-mode-frame-cache-v1.js';
        $hash = (string)get_option('mad4b_perf_elementor_device_mode_frame_cache_sha256_v1', '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)
            || is_link($path) || !is_file($path) || !is_readable($path)
            || filesize($path) < 500 || filesize($path) > 10000
            || !hash_equals($hash, (string)hash_file('sha256', $path))) return;
        $source = file_get_contents($path);
        if (!is_string($source) || str_contains(strtolower($source), '</script')) return;
        echo '<script id="mad4b-elementor-device-mode-frame-cache-v1" data-no-optimize="1">',
             $source, '</script>', PHP_EOL;
    }

'''
ANCHOR = '    public static function start(): void {'
HOOK = "        add_action('wp_head', [__CLASS__,'mobile_city_lcp'], 3);"
NEW_HOOK = "        add_action('wp_head', [__CLASS__,'elementor_device_mode_frame_cache'], 7);"

def sha(data):return hashlib.sha256(data).hexdigest()

def patch(data):
    src=data.decode('utf-8')
    if MARKER in src or NEW_HOOK in src:raise ValueError('Previously patched or partial owner drift')
    if src.count(ANCHOR)!=1 or src.count(HOOK)!=1 or 'class MAD4B_Shared_First_Paint_Performance {' not in src:
        raise ValueError('Native first-paint owner anchors drifted')
    updated=src.replace(ANCHOR,METHOD+ANCHOR,1).replace(HOOK,HOOK+'\n'+NEW_HOOK,1)
    if updated.count(MARKER)!=1 or updated.count(NEW_HOOK)!=1:raise ValueError('Patched content invariant')
    return updated.encode('utf-8')

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--file',type=Path,required=True)
    ap.add_argument('--expected-sha',required=True)
    ap.add_argument('--output',type=Path,required=True)
    ap.add_argument('--write',action='store_true',help='Install after explicit CAS and rollback snapshot')
    ap.add_argument('--backup',type=Path)
    args=ap.parse_args()
    if not __import__('re').fullmatch('[a-f0-9]{64}',args.expected_sha):ap.error('Bad preimage sha')
    if args.file.is_symlink() or not args.file.is_file():ap.error('Only real native PHP file allowed')
    original=args.file.read_bytes()
    if sha(original)!=args.expected_sha:ap.error('Native source changed: fail closed')
    result=patch(original)
    if args.output.is_symlink():ap.error('Output symlink refused')
    if args.output.exists() and args.output.read_bytes()!=result:ap.error('Output drift')
    args.output.parent.mkdir(parents=True,exist_ok=True)
    args.output.write_bytes(result)
    if args.write:
        if not args.backup or args.backup.exists() or args.backup.is_symlink():ap.error('Immutable backup path required')
        args.backup.parent.mkdir(parents=True,exist_ok=True)
        with args.backup.open('xb') as file:
            file.write(original);file.flush();os.fsync(file.fileno())
        if sha(args.backup.read_bytes())!=args.expected_sha:ap.error('Snapshot mismatch')
        fs=args.file.stat();fd,tmp=tempfile.mkstemp(dir=args.file.parent,prefix='.mad4b-elementor-frame-cas-')
        try:
            with os.fdopen(fd,'wb') as file:
                os.fchown(file.fileno(),fs.st_uid,fs.st_gid)
                os.fchmod(file.fileno(),stat.S_IMODE(fs.st_mode))
                file.write(result);file.flush();os.fsync(file.fileno())
            if sha(args.file.read_bytes())!=args.expected_sha:ap.error('CAS concurrent mutation')
            os.replace(tmp,args.file)
        finally:
            if os.path.exists(tmp):os.unlink(tmp)
        if sha(args.file.read_bytes())!=sha(result):ap.error('Post-write readback mismatch')
    print(json.dumps({'contract':'mad4b.native-elementor-mode.frame-cache-patch.v1','file':str(args.file),'before_sha256':args.expected_sha,'after_sha256':sha(result),'status':'INSTALLED_FEATURE_OFF' if args.write else 'DRY_RUN_VALID','backup':str(args.backup)if args.write else None}))
if __name__=='__main__':main()

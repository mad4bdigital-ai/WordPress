#!/usr/bin/env python3
"""Rebuild a QA-only Critical CSS candidate from exact native CSSOM evidence.

Consumes browser CSSOM data and an independently measured geometry reservation.
Outputs a *candidate*, never changes WordPress, the live CSS manifest or cache.
Fail closed on missing, stale or untrusted source evidence. No company-specific
selectors, URLs or viewports are embedded in this generator.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import sys


def sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def build(source: Path, source_sha: str, critical: Path, critical_sha: str,
          cssom: Path, width_min: int, width_max: int, reserve: dict) -> tuple[bytes, dict]:
    if not 320 <= width_min <= width_max <= 2560:
        raise ValueError('Invalid responsive width range')
    if not re.fullmatch('[a-f0-9]{64}', source_sha) or not re.fullmatch('[a-f0-9]{64}', critical_sha):
        raise ValueError('Invalid source hash')
    if source.is_symlink() or critical.is_symlink() or not source.is_file() or not critical.is_file():
        raise ValueError('CSS source must be a regular file')
    raw_source = source.read_bytes()
    raw_critical = critical.read_bytes()
    if sha(raw_source) != source_sha or sha(raw_critical) != critical_sha:
        raise ValueError('Source or Critical CSS SHA drift')
    if len(raw_source) > 6291456 or not 16000 < len(raw_critical) < 1048576:
        raise ValueError('Unexpected CSS source size')
    evidence = json.loads(cssom.read_text())
    if evidence.get('width') is None or not width_min <= evidence['width'] <= width_max:
        raise ValueError('CSSOM probe not in permitted viewport range')
    if not isinstance(evidence.get('criticalRules'), list) or not isinstance(evidence.get('rules'), list):
        raise ValueError('CSSOM browser evidence absent')
    normalize = lambda s: re.sub(r'\s+', '', s)
    prior = {('/'.join(z['parents']), normalize(z['css'])) for z in evidence['criticalRules']}
    missing = []
    for rule in evidence['rules']:
        parents, css = rule['parents'], rule['css']
        if not isinstance(parents, list) or not isinstance(css, str):
            raise ValueError('Invalid CSSOM rule')
        if not css.startswith(rule['selector']+' {') or not css.endswith('}'):
            raise ValueError('Rule selector/provenance invalid')
        if len(parents)>6 or any(not re.fullmatch(r'@(media|supports) [^{};]+', v) for v in parents):
            raise ValueError('Unsupported CSSOM nesting')
        key = ('/'.join(parents), normalize(css))
        if key in prior:
            continue
        if len(css) > 5000 or re.search(r'@import|@font-face|url\s*\(|</style|<script',css,re.I):
            raise ValueError('Unexpected external CSS rule')
        missing.append(''.join(v+'{' for v in parents)+css+'}'*len(parents))
    if not 1 <= len(missing) <= 250 or sum(map(len,missing)) > 60000:
        raise ValueError('Out-of-bounds missing-rule extraction')
    supplemental = ('\n/* MAD4B QA: exact CSSOM missing rules for 820px first viewport */\n'
                    f'@media (min-width:{width_min}px) and (max-width:{width_max}px){{\n'
                    + '\n'.join(missing)+'\n}\n')
    if reserve:
        selector = reserve['selector']
        min_v, max_v = int(reserve['min_width']), int(reserve['max_width'])
        actual_height = float(reserve['observed_final_height'])
        expected_height = int(reserve['reserved_height'])
        if not re.fullmatch(r'\.[a-zA-Z][a-zA-Z0-9_-]{4,120}', selector):
            raise ValueError('Reservation must be a single audited class selector')
        if not width_min <= min_v <= max_v <= width_max:
            raise ValueError('Invalid measured reservation range')
        if not 20 <= actual_height <= 200 or not actual_height <= expected_height <= actual_height+2:
            raise ValueError('Unmeasured reservation height')
        supplemental += ('\n/* QA-verified first-row geometry at the medium header breakpoint */\n'
                         f'@media (min-width:{min_v}px) and (max-width:{max_v}px)'
                         '{'+selector+'{min-height:'+str(expected_height)+'px!important}}\n')
    result = raw_critical + supplemental.encode('utf-8')
    if len(result) >= 1048576:
        raise ValueError('Oversized Critical CSS candidate')
    return result, {'contract':'mad4b.critical-css.cssom-midband-candidate.v1',
                    'status':'QA_ONLY__REQUIRES_INDEPENDENT_ADMISSION',
                    'css_source_sha256':source_sha,
                    'critical_preimage_sha256':critical_sha,
                    'cssom_sha256':sha(cssom.read_bytes()),
                    'candidate_sha256':sha(result),'candidate_bytes':len(result),
                    'additional_rules':len(missing),'width_min':width_min,'width_max':width_max,
                    'reservations_approved_for_qa':bool(reserve),
                    'original313_signed':False}


def main(argv=None):
    ap=argparse.ArgumentParser(description=__doc__)
    for arg in ['source','source-sha','critical','critical-sha','cssom','output']:
        ap.add_argument('--'+arg,required=True)
    ap.add_argument('--min-width',required=True,type=int)
    ap.add_argument('--max-width',required=True,type=int)
    ap.add_argument('--reserve-json',help='audited geometry reservation policy')
    opt=ap.parse_args(argv)
    reserved=json.loads(Path(opt.reserve_json).read_text()) if opt.reserve_json else {}
    result,proof=build(Path(opt.source),opt.source_sha,Path(opt.critical),opt.critical_sha,
                       Path(opt.cssom),opt.min_width,opt.max_width,reserved)
    path=Path(opt.output)
    if path.is_symlink():ap.error('No symlink output')
    if path.exists():
        if path.read_bytes()!=result:ap.error('Existing output mismatch; refuse overwrite')
    else:path.write_bytes(result)
    print(json.dumps(proof,indent=2))
    return 0

if __name__=='__main__':sys.exit(main())

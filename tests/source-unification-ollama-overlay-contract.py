#!/usr/bin/env python3
"""Exact, additive QA31/PR814 bootstrap provenance without changing historical SHA."""
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tests'))
from qa31_source_convergence_bootstrap_overlay import (
    strip_reviewed_pr942_ollama_delta,blob_sha,PROOF,MAIN
)
import json
proof=json.loads(PROOF.read_text(encoding='utf-8'))
current=(ROOT/MAIN).read_bytes()
old=strip_reviewed_pr942_ollama_delta(current)
assert blob_sha(old)==proof['prior']['blob_sha']
assert blob_sha(current)==proof['reviewed_successor']['blob_sha']
for malformed in (
    current+b'\n',
    current.replace(b'Local Ollama remains source-only',b'Local Ollama gains authority'),
    current.replace(b'::boot();',b'::boot(); /* forged */',1),
):
    try:strip_reviewed_pr942_ollama_delta(malformed)
    except AssertionError:continue
    raise AssertionError('Tampered bootstrap was trusted as exact historical successor')
assert proof['production_authorized'] is False and proof['owner_attestation_provided'] is False
print('PR942_OLLAMA_BOOTSTRAP_SOURCE_REVIEW: PASS')

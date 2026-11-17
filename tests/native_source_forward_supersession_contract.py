"""Shared historic-vs-forward Native Source pin checker. No manifest repinning."""
from __future__ import annotations
import hashlib,json,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
OPS=ROOT/'ops'
OLD_FILE='native-source-publisher-v1/native_source_publisher.py'
FORWARD=OPS/'native-source-publisher-v1/catalog/standing-delegation-forward-source-20261117.json'
CANDIDATE=OPS/'native-source-publisher-v1/catalog/candidates/source.code.release.standing.v2.UNSIGNED.json'

def sha(raw:bytes)->str:return hashlib.sha256(raw).hexdigest()

def verified_forward_source(test)->dict:
    report=json.loads(FORWARD.read_text());future=json.loads(CANDIDATE.read_text())
    test.assertEqual(report['contract'],'mad4b.native-source.standing-delegation-forward-source-not-enrolled.v1')
    test.assertEqual(len(report['baseline_git_commit']),40)
    historic=subprocess.run(['git','-C',str(ROOT),'show',
        report['baseline_git_commit']+':ops/'+OLD_FILE],capture_output=True,check=True).stdout
    test.assertEqual(sha(historic),report['historical_native_source_sha256'])
    test.assertEqual(report['historical_native_source_sha256'],
        '32656d20a0729fd0c165a65b6b7d2ad51014a87e69c636fa568c5bfff51b7eae')
    test.assertEqual(sha((OPS/OLD_FILE).read_bytes()),report['current_native_source_sha256'])
    future_deps={x['path']:x['sha256'] for x in future['dependencies']}
    test.assertEqual(future['version'],2)
    test.assertEqual(future_deps[OLD_FILE],report['current_native_source_sha256'])
    new_dep='native-source-publisher-v1/standing_release_delegation.py'
    test.assertEqual(future_deps[new_dep],report['new_standing_verifier_sha256'])
    test.assertEqual(future_deps[new_dep],sha((OPS/new_dep).read_bytes()))
    test.assertEqual(report['historical_generated_manifest_sha256'],
        sha((OPS/'native-source-publisher-v1/catalog/generated/source.code.release.manifest.json').read_bytes()))
    for key,expected in (
        ('historic_v8_provider_pins_not_rewritten',True),
        ('historic_v9_provider_pins_not_rewritten',True),
        ('current_native_source_released_to_production',False),
        ('standing_certificate_enrolled_and_signed',False),
        ('original313_signed_receipt_verified',False),
        ('broker_permission_created',False),
        ('prior_manual_approval_code_path_preserved',True),
        ('default_standing_delegation_enabled',False),
        ('runtime_code_auto_published',False)):
        test.assertIs(report[key],expected)
    test.assertFalse((OPS/'dynamic-operation-registry-v1/providers/source.code.release/manifest.json').exists())
    return report

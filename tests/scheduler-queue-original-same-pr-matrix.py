#!/usr/bin/env python3
"""Exact source-bound original scheduler/queue behavioral regression (CI-only).

Runs three existing first-party hermetic PHP tests on their actual sources.
The source blobs are pinned, and failures never grant authority or mark a
live queue/producer ready. This file is added in this exact PR.
"""
from pathlib import Path
import subprocess
ROOT=Path(__file__).resolve().parents[1]
PLUGIN="wp-content/plugins/mad4b-site-control-plane/"
CASES=(
 ('includes/class-mad4b-scp-scheduler-admission.php','592f6c26c91fa12b7e75ddd9a5c508c0841e64df','wp-content/plugins/mad4b-site-control-plane/tests/scheduler-admission-contract.php','mad4b.scheduler-admission.v1: PASS'),
 ('includes/class-mad4b-scp-scheduler-backlog.php','7731508c4110b161090af056b5a36fadf994aa0b','wp-content/plugins/mad4b-site-control-plane/tests/scheduler-backlog-contract.php','mad4b.scheduler-backlog.v1: PASS'),
 ('includes/class-mad4b-scp-remote-work-queue.php','7e0fa13944e7351173fe11f39623b564e1c779f4','wp-content/plugins/mad4b-site-control-plane/tests/remote-work-queue-cancellation-runtime.php','mad4b.remote-work-queue-cancellation.runtime.v1: PASS'),
 )
for source, blob, fixture, marker in CASES:
    source_file=ROOT/PLUGIN/source
    source_sha=subprocess.check_output(["git","hash-object",str(source_file)],
        cwd=ROOT,text=True).strip()
    assert source_sha==blob,(source,"new source review required")
    assert source_file.name in (ROOT/fixture).read_text(encoding="utf-8"),(source,"fixture does not load original source")
    run=subprocess.run(["php",str(ROOT/fixture)],cwd=ROOT,
        capture_output=True,text=True,timeout=75,check=False)
    output=run.stdout+"\\n"+run.stderr
    assert run.returncode==0 and marker in output,(source,output[-1500:])
    print("PASS ORIGINAL_SAME_PR_SOURCE",source,source_sha[:12])
print("PASS SCHEDULER_QUEUE_ORIGINAL_SAME_PR 3 source-bound runtime suites")

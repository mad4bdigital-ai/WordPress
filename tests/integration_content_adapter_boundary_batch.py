#!/usr/bin/env python3
"""Run directly tied Content, adapter, catalog and UI PHP runtime contracts."""
import os
import subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
plugin=root/"wp-content/plugins/mad4b-site-control-plane"
php=os.environ.get("MAD4B_PHP","php")
mapping={
"adapter-base":("includes/adapters/class-mad4b-scp-adapter-base.php","context-write-mount-runtime.php"),
"context-adapter":("includes/adapters/class-mad4b-scp-context-adapter.php","context-write-mount-runtime.php"),
"skills-adapter":("includes/adapters/class-mad4b-scp-skills-adapter.php","skill-task-abilities-runtime.php"),
"adapter-registry":("includes/class-mad4b-scp-adapter-registry.php","runtime-g1-evidence-graph.php"),
"adaptive-interaction-broker":("includes/class-mad4b-scp-adaptive-interaction-broker.php","qa31-w2a-readonly-runtime.php"),
"admin-navigation-manifest":("includes/class-mad4b-scp-admin-navigation-manifest.php","admin-parity-diagnostics-runtime.php"),
"content-jobs":("includes/class-mad4b-scp-content-jobs.php","trusted-brand-scope-runtime.php"),
"local-ai-governance":("includes/class-mad4b-scp-local-ai-governance.php","local-ollama-ability-runtime.php"),
"mcp-catalog-diagnostics":("includes/class-mad4b-scp-mcp-catalog-diagnostics.php","mcp-catalog-adaptive-budget.php"),
"content-intelligence-pipeline":("includes/class-mad4b-scp-content-intelligence-pipeline.php","content-qa-review-mode-runtime.php"),
}
for name,(path,test_name) in mapping.items():
    source=plugin/path
    test=plugin/"tests"/test_name
    assert source.is_file() and test.is_file(),name
    proc=subprocess.run([php,str(test)],cwd=root,capture_output=True,text=True,timeout=120)
    assert proc.returncode==0 and "PASS" in proc.stdout,(name,proc.returncode,proc.stdout[-400:],proc.stderr[-400:])
    print("CONTENT_ADAPTER_RUNTIME_PASS",name)
print("CONTENT_ADAPTER_RUNTIME_BATCH: PASS",len(mapping))

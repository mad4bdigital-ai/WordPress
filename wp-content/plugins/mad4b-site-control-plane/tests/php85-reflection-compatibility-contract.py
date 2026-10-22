#!/usr/bin/env python3
"""Catch PHP 8.5 ReflectionProperty::setAccessible deprecations.

All reflection accesses must preserve PHP <8.1 behavior and avoid deprecated
calls on PHP 8.5+, including dynamically registered MCP abilities.
"""
from pathlib import Path
import re
import subprocess

root = Path(__file__).resolve().parents[1]
hits = []
for path in (root / "includes").rglob("*.php"):
    contents = path.read_text(encoding="utf-8")
    for match in re.finditer(r"\$(?:property|ref)->setAccessible\( true \);", contents):
        line = contents.rfind("\n", 0, match.start()) + 1
        prefix = contents[line:match.start()]
        assert re.search(r"if\s*\(\s*PHP_VERSION_ID\s*<\s*80100\s*\)\s*$", prefix), (
            f"unguarded PHP 8.5 deprecated reflection access: {path}:{contents.count(chr(10), 0, match.start()) + 1}"
        )
        hits.append(str(path.relative_to(root)))
assert len(hits) == 9, f"expected nine guarded ReflectionProperty accesses; got {len(hits)}"
probe = r"""
<?php
class PrivateCallback { private $execute_callback = 'trusted'; }
$p = (new ReflectionObject(new PrivateCallback()))->getProperty('execute_callback');
if (PHP_VERSION_ID < 80100) $p->setAccessible(true);
if ($p->getValue(new PrivateCallback()) !== 'trusted') exit(3);
echo 'PHP_REFLECTION_COMPATIBILITY: PASS', PHP_EOL;
"""
out = subprocess.run(["php", "-d", "error_reporting=-1", "-d", "display_errors=1"],
                     input=probe, text=True, capture_output=True, timeout=20, check=True)
assert "PHP_REFLECTION_COMPATIBILITY: PASS" in out.stdout
assert "Deprecated" not in out.stdout + out.stderr
print("MAD4B_PHP85_REFLECTION_CONTRACT: PASS")

#!/usr/bin/env python3
"""Fail closed on previously unknown missing loader files.

This is a source audit, not authorization to deploy.
"""
from pathlib import Path
import importlib.util
import sys

here = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("assembly_gate", here / "assembly-gate.py")
module = importlib.util.module_from_spec(spec)
assert spec is not None and spec.loader is not None
spec.loader.exec_module(module)
root = here.parent
missing = set(module.inspect(root))
known = {"adapters/tour-program-source-contract-v1.php"}
unknown = sorted(missing - known)
if unknown:
    raise SystemExit("UNEXPECTED_LOADER_MISSING: " + ", ".join(unknown))
if missing:
    print("KNOWN_SOURCE_GAP: " + ", ".join(sorted(missing)))
    print("PRODUCT_RELEASE_BLOCKED")
else:
    print("LOADER_LITERAL_CLOSURE_OK: needs full tenant certification")
# A known blocker is allowed for an inert *source-only* branch; it may not
# be interpreted as a passing release certificate.
sys.exit(0)

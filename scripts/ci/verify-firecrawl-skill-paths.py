#!/usr/bin/env python3
"""Validate Firecrawl research Skill source paths and portable export naming."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
PREFIX = ROOT / "ops/integrations/firecrawl/skills"
EXPECTED = {
    ("provider","firecrawl","firecrawl-competitive-research"),
    ("workflow","research-intelligence","research-provider-routing"),
}
seen = set()
portable = set()
for level,target,name in sorted(EXPECTED):
    path = PREFIX / level / target / name / "SKILL.md"
    assert path.is_file() and not path.is_symlink(), f"Missing or symlink source: {path}"
    data = path.read_text(encoding="utf-8")
    assert data.startswith("---\n"), f"Missing YAML frontmatter: {path}"
    match = re.match(r"\A---\nname: ([a-z][a-z0-9-]+)\ndescription: ([^\n]+)\n---\n",data)
    assert match and match.group(1) == name, f"Wrong skill frontmatter: {path}"
    assert 100 < len(data.encode("utf-8")) < 262144
    assert name not in portable, "Portable skill name collides"
    portable.add(name)
    seen.add((level,target,name))
    runtime = f"wp-content/mad4b-skills/{level}/{target}/{name}/SKILL.md"
    portable_path = f"skills/{name}/SKILL.md"
    print(f"SOURCE={path.relative_to(ROOT)} RUNTIME={runtime} PORTABLE={portable_path}")
assert seen == EXPECTED
assert len(tuple(PREFIX.rglob("SKILL.md"))) == len(EXPECTED), "Unknown skill source path requires review"
print("firecrawl-skill-paths: PASS")

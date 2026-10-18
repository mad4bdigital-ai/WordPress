#!/usr/bin/env python3
"""Hermetic adversarial tests for GitHub issue inventory -> reviewed source coverage."""
import importlib.util
import json
from pathlib import Path
import tempfile

HERE = Path(__file__).resolve().parent
file = HERE / "open-issue-live-coverage.py"
spec = importlib.util.spec_from_file_location("issue_coverage", file)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

manifest = module.read_manifest(HERE / "open-issue-regressions.json")
mapped = manifest["issues"]
ledger = manifest["ledger_issues"]
assert mapped and ledger
def issue(number):
    return {"number": number, "title": "Issue", "html_url": "https://github.com/example/issues/" + str(number)}
all_open = [issue(row["issue"]) for row in mapped] + [issue(n) for n in ledger]
all_open += [dict(number=999999, pull_request={"url": "https://api.github.com/pulls/999999"})]
result = module.classify(all_open, manifest)
assert result["coverage_pass"] and result["mapped_open_defects"] == len(mapped)
assert result["governance_ledgers"] == sorted(ledger) and not result["unmapped_open_issues"]
assert not result["live_acceptance"] and not result["production_authorized"]
assert all(x["disposition"] != "CLOSED" for x in result["items"])
drift = module.classify(all_open + [issue(999998)], manifest)
assert not drift["coverage_pass"] and drift["unmapped_open_issues"] == [999998]
assert all(x["source_regressions"] == "NOT_EXECUTED" for x in result["items"] if x["issue"] not in ledger)
try:
    module.classify(all_open + [issue(mapped[0]["issue"])], manifest)
    raise AssertionError("Duplicate GitHub issue silently admitted")
except ValueError:
    pass
for bad in (dict(contract=manifest["contract"], authorizing=True, issues=[]),
            dict(contract=manifest["contract"], authorizing=False, issues=mapped,
                 ledger_issues=manifest["ledger_issues"] + [mapped[0]["issue"]])):
    with tempfile.TemporaryDirectory() as folder:
        target = Path(folder) / "m.json"
        target.write_text(json.dumps(bad))
        try:
            module.read_manifest(target)
            raise AssertionError("Unsafe/contradictory registry accepted")
        except ValueError:
            pass
report = {"contract": manifest["contract"], "authorizing": False,
          "live_acceptance": False,
          "issues": [{"issue": row["issue"], "source_regressions": "PASS"} for row in mapped]}
positive = module.classify(all_open, manifest, report)
assert positive["coverage_pass"]
report["issues"][0]["source_regressions"] = "FAIL"
negative = module.classify(all_open, manifest, report)
assert not negative["coverage_pass"] and negative["source_regression_failures"] == [mapped[0]["issue"]]
try:
    module.fetch_open_issues("../repo", "")
    raise AssertionError("Unsafe repo name accepted")
except ValueError:
    pass
print("OPEN_ISSUE_LIVE_COVERAGE_CONTRACT: PASS")

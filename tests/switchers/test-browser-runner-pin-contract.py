#!/usr/bin/env python3
"""Keep country/nationality actual browser acceptance runnable with a pinned Playwright runtime."""
from pathlib import Path
import re

root = Path(__file__).resolve().parents[2]
p = root / '.github/workflows/country-nationality-switchers.yml'
s = p.read_text(encoding='utf-8')
assert '  browser-smart-suggestions:\n' in s
section = s.split('  browser-smart-suggestions:\n', 1)[1]
assert re.search(r'(?m)^    runs-on: ubuntu-24\.04$', section), 'Chromium job runner must be explicit ubuntu-24.04'
assert not re.search(r'(?m)^    runs-on: ubuntu-latest$', section), 'Do not reinstall Playwright on unsupported future ubuntu-latest'
assert 'python -m pip install playwright==1.56.0' in section
assert 'image: mcr.microsoft.com/playwright/python:v1.56.0-noble' in section, 'Version-matched browser image with OS dependencies required'
assert 'python -m playwright install chromium' in section, 'Real pinned Chromium installation/availability remains required'
assert 'playwright install --with-deps' not in section, 'Do not invoke APT against runner repositories without required font packages'
assert 'run: python tests/switchers/test-blocks-ui-browser.py' in section
assert '  contract-and-package:\n    runs-on: ubuntu-latest' in s, 'Do not mutate the unrelated contract job'
assert "- 'tests/switchers/**'" in s, 'The new runner contract must trigger its own workflow'
print('PASS: Country/Nationality real Chromium runner runs on pinned official Playwright 1.56.0 image and keeps real browser assertions')
